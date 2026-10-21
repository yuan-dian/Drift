<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Input\SemanticsRole;
use Drift\Layout\RenderToggle;

/**
 * 复选框（§A.2 表单词汇）。
 *
 * ```php
 * Checkbox::make('我已阅读并同意条款')->checked($this->agreed)
 *     ->onChanged(fn(bool $v) => $this->setState(fn() => $this->agreed = $v));
 * ```
 *
 * ★ **受控**：勾选状态由应用给，点击只回调新值（见 `Toggle`）。
 * ★ **勾是两条线段**（Core 层 `drawLine`），不是路径 —— 软光栅后端也画得出，
 *   preview / golden / smoke 全走软光栅，用 Optional 层的 `drawPath` 会当场失败。
 *
 * @api
 */
final class Checkbox extends ToggleBase
{
    public function __construct(string $label)
    {
        parent::__construct($label, RenderToggle::KIND_CHECKBOX, SemanticsRole::Checkbox);
    }

    public static function make(string $label): self
    {
        return new self($label);
    }
}
