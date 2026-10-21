<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Input\SemanticsRole;
use Drift\Layout\RenderToggle;

/**
 * 单选钮（§A.2 表单词汇）—— **选中态由"值 == 组值"推出**，没有独立的 checked。
 *
 * ```php
 * Radio::make('中号')->value('m')->group($this->size)
 *     ->onChanged(fn(string $v) => $this->setState(fn() => $this->size = $v));
 * ```
 *
 * ★ **组是"同一个 `group()` 值"，不是一个容器控件**：单选组没有自己的盒子、
 *   没有自己的布局 —— 把它做成容器（`RadioGroup`）会逼出"组状态住哪"的第二次
 *   真相。三个 `Radio` 各自拿同一个 `group()`，行为与 HTML 的 `name` 完全一致。
 *
 * ★ **值是 `string`**：与 HTML radio 的 `value` 同形，表单序列化不需要再转一次。
 *   int id 由调用方 `(string)` 转换 —— 不为"看起来泛型"引入 `mixed`（§11 L1：
 *   热路径零 `mixed`）。
 *
 * ★ **已选中时再点不回调**（`Toggle::activate()`）：与 HTML / Win32 `BM_CLICK` 一致，
 *   否则"重复点已选项"会触发一次无意义的 setState。
 *
 * @api
 */
final class Radio extends ToggleBase
{
    public function __construct(string $label, string $value = '')
    {
        // ★ 先赋 radioValue 再调父构造：父构造里的 refreshSemantics() 要读它
        //   （语义 value = 选中时的自己的值）。
        $this->radioValue = $value;
        parent::__construct($label, RenderToggle::KIND_RADIO, SemanticsRole::Radio);
    }

    public static function make(string $label): self
    {
        return new self($label);
    }

    /** 本项代表的值 */
    public function value(string $value): static
    {
        $this->mutate();
        $this->radioValue = $value;
        $this->syncSelection();

        return $this;
    }

    /** 组值（同一个值 = 同一组；组内只有"值相等"的那一项是选中态） */
    public function group(string $group): static
    {
        $this->mutate();
        $this->radioGroup = $group;
        $this->syncSelection();

        return $this;
    }

    /**
     * 选中态 = 值 == 组值。
     *
     * ★ 空值不算选中：`Radio::make('A')` 没给 value 时，组值为空字符串也不该
     *   让所有没给值的单选项一起亮起来。
     */
    private function syncSelection(): void
    {
        $this->on = $this->radioValue !== '' && $this->radioValue === $this->radioGroup;
        $this->refreshSemantics();
    }
}
