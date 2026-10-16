<?php
declare(strict_types=1);

namespace Drift\Layout;

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
