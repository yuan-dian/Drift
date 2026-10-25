<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\Rect;
use Drift\Input\CursorShape;
use Drift\Rendering\Canvas;

/**
 * 渲染树的节点（§3.2 三树的第三棵）。
 *
 * ★ **布局是纯函数**（§14.1）：输入只有（子树、约束、主题、注入的度量），
 *   不读 OS、不读环境时钟、不读随机源；**位置无关** —— 从不读自身偏移来算布局，
 *   偏移是父定位子的结果（C1）。
 *
 * ★ Render 层**没有脏边界**（§13.3）：`relayout()` 永远全树。想要"只重排脏的
 *   那一块"，要等 M5 与脏矩形成对做 —— 单独做任一个，瓶颈只是搬到对面。
 *
 * @api
 */
abstract class RenderObject
{
    protected ?RenderObject $parent = null;

    /** @internal null = 本节点未声明光标形状（命中链会向上找更近的声明者，§9.1） */
    protected ?CursorShape $cursor = null;

    public function parent(): ?RenderObject
    {
        return $this->parent;
    }

    /**
     * 本节点**自己**声明的光标形状（`null` = 没声明，§9.1）。
     *
     * ★ 它**不是**最终生效的形状 —— 生效值由命中链从命中的盒**向上**找最近的
     *   声明者（`FocusManager::cursorOf()`）。放在 `RenderObject` 而不是
     *   `RenderBox` 上：§9.1 原文就是"`RenderObject` 声明 `cursor`"。
     *
     * @api
     */
    public function cursor(): ?CursorShape
    {
        return $this->cursor;
    }

    /**
     * @internal Element 造盒时把 Widget 的声明落上来（唯一写点：`Element::syncBox()`）
     */
    public function setCursor(?CursorShape $shape): void
    {
        $this->cursor = $shape;
    }

    /**
     * 这个**位置**要什么光标（`null` = 不表态，回落到 `cursor()` 的父链声明）。
     *
     * ★ 与 `cursor()` 的分工：`cursor()` 是"整个控件一个形状"，本方法是"同一个
     *   控件里按位置变"（富文本的链接只在几个字上是手型）。
     * ★ 坐标是**本盒的局部坐标**（与 `hitTest()` 同口径）—— 调用方负责减
     *   `absoluteOrigin()`，盒不自己沿父链换算（那是重复实现）。
     * ★ 默认 `null`：绝大多数盒没有按位置变的需求，声明式 `cursor()` 够用；
     *   覆写的盒必须**快速返回**（每次 move 都会问一次）。
     *
     * @api
     */
    public function cursorAt(float $x, float $y): ?CursorShape
    {
        return null;
    }

    /**
     * 本盒作为**浮层面板**时，面板自身的矩形（盒坐标系；`null` = 本盒不是面板）。
     *
     * ★ 存在的理由：`App` 层有两处要问"这是不是面板 / 面板多大"，以前靠
     *   `instanceof RenderPopupPanel` —— 框架核心认识具体盒类，与 `Activatable`
     *   同一条纪律（§4.3 依赖方向：`App` 不该认识 `Layout` 的具体类）。
     *   收成能力方法后，`hitsModalPanel()` 问 `panelRect() !== null`，
     *   `OverlayLayout` 问矩形本身，两处都不再需要 `use RenderPopupPanel`。
     *
     * ★ **为什么返回矩形而不是布尔**：`OverlayLayout` 要的是"含投影留白内缩
     *   `elevation` 之后的面板矩形"，布尔表达不了。一个方法同时满足两种问法 ——
     *   布尔用 `!== null`，几何用返回值。两个方法（`isPanel()` + `panelRect()`）
     *   会逼出"两者不一致"的可能。
     *
     * ★ 默认 `null`：绝大多数盒不是面板，不表态。
     *
     * @api
     */
    public function panelRect(): ?Rect
    {
        return null;
    }

    /**
     * @internal 挂载子节点：把 subordinate 的父指到本节点。
     *
     * Element 建树时用；做成方法而不是公开 `$parent =`，是为了让"改父"有唯一落点。
     */
    public function adoptChild(RenderObject $child): void
    {
        $child->parent = $this;
    }

    /**
     * 遍历子渲染对象 —— 布局与绘制都走这个入口，**不直接摸具体字段**。
     *
     * 于是"单子盒"与"多子盒"对父层是同一个接口（§3.3 的 flush 只认这里）。
     */
    abstract public function visitChildren(callable $visitor): void;

    /** 两阶段之二（§13.1）：父级已 translate 到本盒原点 */
    abstract public function paint(Canvas $canvas): void;
}
