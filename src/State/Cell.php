<?php
declare(strict_types=1);

namespace Drift\State;

use Drift\Core\Versioned;
use Drift\Errors;
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

    /** @internal 标脏要靠它；由 `State::cell()` 绑上 */
    private ?State $owner = null;

    /**
     * @param mixed $value 初值
     *
     * @api
     */
    public function __construct(mixed $value)
    {
        $this->value = $value;
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

        $owner = $this->owner;
        if ($owner === null) {
            // 没有 Owner 就没有可标脏的 State ⇒ 这次写入没人会看见 ⇒ 静默。
            // G2：宁可现在就炸，不留一个"设了没用"的值到运行期。
            Errors::report('Cell 未绑定到 State：请用 State::cell() 创建（§11.3.1）');
        }

        $owner->markDirty();

        return true;
    }

    /** @internal 唯一的绑定入口（`State::cell()`） */
    public function bindTo(State $owner): void
    {
        $this->owner = $owner;
    }

    /** @inherit */
    public function version(): int
    {
        return $this->version;
    }
}
