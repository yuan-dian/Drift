<?php
declare(strict_types=1);

namespace Drift\Core;

use Drift\Errors;

/**
 * 派生值：把"手工缓存 + 记得复位"从纪律换成机制（`03` §4）。
 *
 * ★ **拉取式**：改依赖只置版本，不算；重算发生在**读**的那一刻 ⇒ 一次事务里
 *   改两个字段，中间态不会被任何人看到（推送式的 glitch 结构上不存在，
 *   `03` §4.2）。也不需要拓扑排序 —— `A.get()` → `B.get()` 的**调用栈天然是拓扑序**。
 *
 * ★ **无脏成本 = O(依赖数) 次 `get()`/`version()` + 一次比较**。依赖数上界很小
 *   （`UserStore::visible()` 只有一个依赖），所以是"一次加法"。`Computed` 型依赖
 *   会先被 `get()` 刷新（否则它的版本号不进，上游永远拿缓存）。
 *
 * ★ **粗依赖是有意的取舍**（`03` §4.6）：`[$this]` 会让不相干的字段变化也触发
 *   重算，换掉的是"漏复位 ⇒ 永久陈旧值"这一整类问题。代价上界写死：
 *   **派生值算一次超过 1ms 就不该用粗依赖** —— 那时该拆 Store。
 *
 * ★ **依赖漏写没有自动防线**（漏写 = 没依赖它 = 永不重算）。契约：`Computed`
 *   只在**同一文件内**用，构造与 `get()` 相隔不超过 50 行。
 *
 * @api
 */
final class Computed implements Versioned
{
    /** 派生链深度上限（`03` §4.7）：字段 → 过滤/聚合 → 格式化三层，留一倍余量 */
    private const MAX_DEPTH = 8;

    private mixed $value = null;
    private bool $hasValue = false;
    private int $version = 0;
    /** 上次求值时的依赖版本和；-1 = 从未求值过 */
    private int $seenSum = -1;
    /** 嵌套深度：1 + 依赖里 Computed 的最大深度（构造时自算，不需要调用方传） */
    private int $depth = 1;
    private bool $evaluating = false;

    /** @var callable(): mixed */
    private $fn;

    /** @var list<Versioned> */
    private array $deps;

    /**
     * @param callable(): mixed $fn 必须纯：不许写任何 `Versioned`（求值期写 ⇒ 嵌套置脏 ⇒ 可能死循环）
     * @param list<Versioned>   $deps 依赖。**漏写一个就永远不重算** —— 没有自动防线
     */
    public function __construct(callable $fn, array $deps)
    {
        $deepest = 0;
        foreach ($deps as $dep) {
            if ($dep instanceof self && $dep->depth > $deepest) {
                $deepest = $dep->depth;
            }
        }
        $this->depth = $deepest + 1;

        if ($this->depth > self::MAX_DEPTH) {
            Errors::report(sprintf(
                'Computed 依赖链 %d 层，超过上限 %d：这不是派生值，是没拆开的 Store（E-COMPUTED-DEPTH）',
                $this->depth,
                self::MAX_DEPTH,
            ));
        }

        $this->fn = $fn;
        $this->deps = $deps;
    }

    /**
     * 取值；依赖没变就直接给缓存。
     *
     * ★ `evaluating` 守卫与 `SubjectBuilder` 的"`select` 闭包不许有副作用"是同一条
     *   纪律，但**它现在是运行期守卫**（`00` §2 P6：逃生口要难看）。
     */
    public function get(): mixed
    {
        if ($this->evaluating) {
            Errors::report('Computed 闭包必须是纯函数：求值期间又触发了自己的求值（E-COMPUTED-CYCLE）');
        }

        $this->evaluating = true;
        try {
            $sum = 0;
            foreach ($this->deps as $dep) {
                if ($dep instanceof self) {
                    // ★ 先把**下游**算新：B 不重算就不会进版本，A 的版本和就看不出
                    //   C 变过 ⇒ A 会永远拿缓存。拉取式的调用栈天然是拓扑序，
                    //   所以"先问下游要新值"不违反无 glitch 性质（`03` §4.3）。
                    $dep->get();
                }
                $sum += $dep->version();     // O(依赖数)，上界很小
            }

            if ($this->hasValue && $sum === $this->seenSum) {
                return $this->value;         // 没变 ⇒ 直接给缓存（无脏成本 = 一次加法）
            }

            $this->value = ($this->fn)();
            $this->hasValue = true;
            $this->seenSum = $sum;
            $this->version++;                // 自己也实现 Versioned ⇒ 可以嵌套

            return $this->value;
        } finally {
            $this->evaluating = false;
        }
    }

    public function version(): int
    {
        return $this->version;
    }
}
