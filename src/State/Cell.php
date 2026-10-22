<?php
declare(strict_types=1);

namespace Drift\State;

use Drift\Core\Versioned;
use Drift\Widget\State;

/**
 * 单值单元，**只在值真的变了时才标脏**（§11.3.1 / RT-4）。
 *
 * ★ 与 `Subject` 的分工不是"大/小"而是**谁的门控**：`Subject` 是广播端、无门控
 *   （一次可能改 5 个字段）；`Cell` 是单值端、`===` 严格比较。
 *
 * ★ **住在 `State` 里，不住 `Store` 里**。典型场景是高频、可能重复设值的 UI 态
 *   （鼠标在列表行间移动会反复触发同一行的 hover）。
 *
 * ★ 实现 `Versioned`（`03` §4.4）：`Computed` 靠它判断"依赖变了没有"，
 *   而 `Computed` 住 `Core` ⇒ 版本号只说"变了没有"，不提 Cell。
 *
 * @api
 */
final class Cell implements Versioned
{
    private mixed $value;

    /** @internal 版本号：换一次值 +1（`Computed` 的依赖判据） */
    private int $version = 0;

    /**
     * @internal 标脏要靠它。
     *
     * ★ **非空**：读点不写 `?->`（人因收益，不是性能 —— M0-2 实测字段存储层
     *   `?State` 与 `State` 同形态，都走 PHP 属性表，读点各付一次比较；
     *   且非空字段上的属性访问仍是内联缓存读，不是静态偏移读。
     *   证据：docs/AOT-CONSTRAINTS.md §2.2）。
     */
    private State $owner;

    /**
     * @internal 恒为 `true`。
     *
     * ★ 看着多余，它留着是为了让"未绑定"这件事在类型系统里**有位置**：
     *   将来若有第二条创建路径（比如 `Store` 里要一个不标脏的 `Cell`），
     *   `bound = false` 就是那个开关，而不是又加一个可空属性。
     */
    private bool $bound = false;

    /**
     * ★ private：唯一入口是 `State::cell()` —— 让"错的写法"写不出来。
     *
     * @param mixed $initial 初值
     */
    private function __construct(mixed $initial)
    {
        $this->value = $initial;
    }

    /**
     * @internal 只有 `State::cell()` 调得到（构造函数私有 ⇒ 外部造不出未绑定的 Cell）
     */
    public static function makeFor(State $owner, mixed $initial): self
    {
        $cell = new self($initial);
        $cell->owner = $owner;
        $cell->bound = true;

        return $cell;
    }

    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * 设值；`===` 相等就什么都不做（§11.3.1）。
     *
     * @return bool 真的换了值吗（调用方一般不用管）
     *
     * @api
     */
    public function setIfChanged(mixed $value): bool
    {
        if ($value === $this->value) {
            return false;
        }

        $this->value = $value;
        $this->version++;

        $this->owner->markDirty();     // 非空引用 ⇒ 无 null 分支

        return true;
    }

    /** @inherit */
    public function version(): int
    {
        return $this->version;
    }
}
