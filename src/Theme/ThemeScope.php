<?php
declare(strict_types=1);

namespace Drift\Theme;

use Drift\Widget\BuildContext;
use Drift\Widget\Element;
use Drift\Widget\ThemeScopeElement;
use Drift\Widget\Widget;

/**
 * 面覆盖：整棵子树换一份主题。
 *
 * 与点覆盖（`ThemeData::withColor()`，改一个 token）区分：面覆盖不改 token 表，
 * 改的是"这一片子树去哪张表里取值"。
 *
 * 嵌套时最内层赢，没有特异性权重：外层把 ThemeData 写进 ctx，内层再覆盖一次。
 * 结构节点，不绘制、不进盒树；`->background()` 这类修饰对它没有视觉效果。
 *
 * ```php
 * // 这一屏暗色，里面的按钮/文字自动换表，调用点一个字不改
 * ThemeScope::make(ThemeData::dark(), $page)
 * ```
 *
 * @api
 */
final class ThemeScope extends Widget
{
    public function __construct(
        private readonly ThemeData $data,
        private readonly Widget $child,
    ) {
        parent::__construct();
    }

    public static function make(ThemeData $data, Widget $child): self
    {
        return new self($data, $child);
    }

    public function data(): ThemeData
    {
        return $this->data;
    }

    public function child(): Widget
    {
        return $this->child;
    }

    /** 面覆盖的落点：把子层的 ctx 换成带这份主题的那份。 */
    public function childContext(BuildContext $context): BuildContext
    {
        return $context->withTheme($this->data);
    }

    /** 这个节点要自己的 Element：包住一棵子树，还要改子树的环境 */
    public function createElement(): Element
    {
        return new ThemeScopeElement($this);
    }
}
