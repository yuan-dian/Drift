<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Input\SemanticsRole;
use Drift\Layout\RenderToggle;

/**
 * 开关（§A.2 表单词汇）—— 与 `Checkbox` 语义相同、形状不同。
 *
 * ```php
 * Toggle::make('启用桌面通知')->checked($this->notify)
 *     ->onChanged(fn(bool $v) => $this->setState(fn() => $this->notify = $v));
 * ```
 *
 * ★★ **为什么叫 `Toggle` 而不是 `Switch`**：`switch` 是 PHP 保留字
 *   （`final class Switch` 直接语法错）。语义上也更准 —— 它就是一个开关；
 *   `Checkbox` / `Radio` 与它是**兄弟**，都继承 `ToggleBase`。
 *
 * ★ **为什么不是"另一个 Checkbox 变体"**：两者在表单里表达不同意图 ——
 *   复选框是"多选中的一项"（提交时才生效），开关是"立即生效的设置"。
 *   框架不替应用选，所以两个名字都留，共用一个渲染盒（`KIND_SWITCH`）。
 *
 * ★ **形状全在盒里算**：轨道是胶囊（`drawRect` + `radius = 高/2`），滑块是椭圆
 *   （`drawEllipse`）—— 都是 Core 层 op，软光栅后端同样画得出。
 *
 * @api
 */
final class Toggle extends ToggleBase
{
    public function __construct(string $label)
    {
        parent::__construct($label, RenderToggle::KIND_SWITCH, SemanticsRole::Switch);
    }

    public static function make(string $label): self
    {
        return new self($label);
    }
}
