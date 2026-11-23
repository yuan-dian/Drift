<?php
declare(strict_types=1);

namespace Drift\Core;

use Drift\Errors;

/**
 * 派生值：把"手工缓存 + 记得复位"从纪律换成机制。
 *
 * ★ **拉取式**：改依赖只置版本，不算；重算发生在**读**的那一刻 ⇒ 一次事务里
 *   改两个字段，中间态不会被任何人看到（推送式的 glitch 结构上不存在，
 *   `03` §4.2）。也不需要拓扑排序 —— `A.get()` → `B.get()` 的**调用栈天然是拓扑序**。
 *
 * ★ **无脏成本 = O(依赖数) 次 `get()`/`version()` + 一次比较**。依赖数上界很小
 *   （`UserStore::visible()` 只有一个依赖），所以是"一次加法"。`Computed` 型依赖
 *   会先被 `get()` 刷新（否则它的版本号不进，上游永远拿缓存）。
 *
 * ★ **依赖两种给法**（2026-11-21 起）：
 *   - `new Computed($fn, [$a, $b])`：显式。依赖是 `Versioned`（Store 也行），适合
 *     "派生值住业务层、依赖是整个 Store"的粗依赖形态；
 *   - `Computed::of($fn)`：**自动**。求值期间读了哪些 `Cell` / `Computed` 就依赖哪些
 *     —— 一并解决旧版"漏写依赖 ⇒ 永不重算、且没有防线"。
 *   自动模式在求值时**自带一帧采集**，所以它读的格子不会被算到外层读者头上。
 *
 * ★ **粗依赖是有意的取舍**：`[$this]` 会让不相干的字段变化也触发
 *   重算，换掉的是"漏复位 ⇒ 永久陈旧值"这一整类问题。代价上界写死：
 *   **派生值算一次超过 1ms 就不该用粗依赖** —— 那时该拆 Store。
 *
 * @api
 */
final class Computed implements Versioned, Readable
{
    /** 派生链深度上限：字段 → 过滤/聚合 → 格式化三层，留一倍余量 */
    private const int MAX_DEPTH = 8;

    private mixed $value = null;
    private bool $hasValue = false;
    private int $version = 0;
    /** 上次求值时的依赖版本和；-1 = 从未求值过 */
    private int $seenSum = -1;
    /** 嵌套深度：1 + 依赖里 Computed 的最大深度（求值时自算，不需要调用方传） */
    private int $depth = 1;
    private bool $evaluating = false;
    private bool $autoDeps = false;
    private int $runs = 0;

    /** @var callable(): mixed */
    private $fn;

    /** @var list<Versioned> */
    private array $deps;

    /**
     * @param callable(): mixed $fn 必须纯：不许写任何 `Versioned`（求值期写 ⇒ 嵌套置脏 ⇒ 可能死循环）
     * @param null|list<Versioned> $deps 依赖；`null`（默认）⇒ 求值期自动收集
     */
    public function __construct(callable $fn, ?array $deps = null)
    {
        $this->fn = $fn;
        $this->autoDeps = $deps === null;
        $this->deps = $deps ?? [];
        $this->depth = self::depthOf($this->deps);

        if ($this->depth > self::MAX_DEPTH) {
            $this->reportDepth();
        }
    }

    /**
     * 自动依赖版：求值期读了什么就依赖什么。
     *
     * @param callable(): mixed $fn
     *
     * @api
     */
    public static function of(callable $fn): self
    {
        return new self($fn);
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
            // ★ 刷新阶段本身不算"读"：否则下游 Computed 的 `get()` 会把自己记到
            //   外层读者头上（订阅表膨胀成整条链，白重建）。开一帧扔掉最省事。
            Tracker::enter();
            try {
                $sum = $this->refresh();
            } finally {
                Tracker::leave();
            }

            if ($this->hasValue && $sum === $this->seenSum) {
                Tracker::record($this);

                return $this->value;
            }

            Tracker::enter();
            try {
                $value = ($this->fn)();
            } finally {
                $read = Tracker::leave();
            }
            $this->runs++;

            if ($this->autoDeps) {
                $this->deps = \array_values($read);
                $this->depth = self::depthOf($this->deps);

                if ($this->depth > self::MAX_DEPTH) {
                    $this->reportDepth();
                }

                $sum = $this->sumVersions();
            }

            $this->value = $value;
            $this->hasValue = true;
            $this->seenSum = $sum;
            $this->version++;                // 自己也实现 Versioned ⇒ 可以嵌套

            Tracker::record($this);

            return $this->value;
        } finally {
            $this->evaluating = false;
        }
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * 展平成"可订阅的源"：嵌套 `Computed` 递归展开，其余原样。
     *
     * ★ 存在的理由：`Computed` 是**拉取式**、没有推送通道。控件要让"派生值变了"
     *   引起重建，只能订它的源；订到源之后重建会先 `get()` 派生值，等于多算一次
     *   —— 这是拉取式的固有代价（Compose 用 `derivedStateOf` 的相等门控补这一刀）。
     *
     * @return array<int, Versioned> 键 = 对象 id
     *
     * @api
     */
    public function sources(): array
    {
        $out = [];
        foreach ($this->deps as $dep) {
            if ($dep instanceof self) {
                foreach ($dep->sources() as $id => $leaf) {
                    $out[$id] = $leaf;
                }

                continue;
            }
            $out[\spl_object_id($dep)] = $dep;
        }

        return $out;
    }

    /** @internal 求值次数（诊断 / 测试用） */
    public function runs(): int
    {
        return $this->runs;
    }

    /** 先把下游 `Computed` 算新，再累加版本和 */
    private function refresh(): int
    {
        foreach ($this->deps as $dep) {
            if ($dep instanceof self) {
                $dep->get();
            }
        }

        return $this->sumVersions();
    }

    private function sumVersions(): int
    {
        $sum = 0;
        foreach ($this->deps as $dep) {
            $sum += $dep->version();
        }

        return $sum;
    }

    private static function depthOf(array $deps): int
    {
        $deepest = 0;
        foreach ($deps as $dep) {
            if ($dep instanceof self && $dep->depth > $deepest) {
                $deepest = $dep->depth;
            }
        }

        return $deepest + 1;
    }

    private function reportDepth(): void
    {
        Errors::report(sprintf(
            'Computed 依赖链 %d 层，超过上限 %d：这不是派生值，是没拆开的 Store（E-COMPUTED-DEPTH）',
            $this->depth,
            self::MAX_DEPTH,
        ));
    }
}
