<?php
declare(strict_types=1);

namespace Drift\Theme;

use Drift\Widget\BuildContext;
use Drift\Widget\Element;
use Drift\Widget\ThemeScopeElement;
use Drift\Widget\Widget;

/**
 * **面覆盖**：整棵子树换一份主题（§2446 / §3171）。
 *
 * 与 §15.7 的**点覆盖**（`ThemeData::withColor()`，改一个 token）区分：
 * 面覆盖不改 token 表，改的是"这一片子树去哪张表里取值"。
 *
 * ★ **嵌套时最内层赢，没有特异性权重**（§2446 原话"谁生效查父链"）。
 *   实现上天然如此：外层把自己的 `ThemeData` 写进 ctx，内层再覆盖一次，
 *   取到的就是最内层那份 —— 于是不存在 CSS 那套"谁更具体"的计算。
 *
 * ★ **结构节点**（继承裸 `Widget`，§15.7）：不绘制、不布局，只改环境。
 *   所以它拿不到 `->background()`，也进不了盒树。
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

    /**
     * 面覆盖的唯一落点：把子层的 ctx 换成带这份主题的那份。
     *
     * ★ 覆写点在 `Widget` 上而不是在 Element 上，是为了让 `Drift\Widget`
     *   不必认识 `Drift\Theme` —— §4.3 的协议例外至今只有
     *   `src/Widget/BuildContext.php` 一个文件，这里不加第二个。
     */
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
