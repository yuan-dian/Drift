<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Core\PipelineAccess;
use Drift\Geometry\Rect;
use Drift\Input\CursorShape;
use Drift\Rendering\AnimatedValue;
use Drift\Rendering\Canvas;

/**
 * 渲染树的节点（基类）。
 *
 * 布局是纯函数：不读 OS/时钟/随机源，且位置无关 —— 偏移是父定位子的结果，
 * 不是自身读来的。Render 层没有脏边界：`relayout()` 永远全树重排。
 *
 * @api
 */
abstract class RenderObject
{
    use PipelineAccess;

    protected ?RenderObject $parent = null;

    /** @internal null = 未声明光标形状，命中链向上找更近的声明者 */
    protected ?CursorShape $cursor = null;

    /**
     * 标"**像素变了**"的唯一入口（本层与子层就地写点都调它）。
     *
     * 它是"这一层要不要重录"的开关，不是"让帧跑"：`RenderBox` 覆写本方法去
     *   失效最近的重绘边界；只标帧不失效边界 ⇒ 命中缓存原样重放旧命令 ⇒ 不报错。
     *
     * 判据是"录进命令流里的东西变了吗"；合成槽（alpha/平移）走 `setCompose()`。
     */
    protected function markPaintChanged(): void
    {
        $this->pipeline()->bumpPaintEpoch();
    }

    /**
     * 悬停态与过渡进度（不属于描述的那部分状态）。
     *
     * 换盒时由 `inheritRuntimeState()` 移交；新增瞬态字段必须在那里补一行。
     */
    private bool $hovered = false;

    /**
     * 悬停态的**过渡进度**；`null` = 没有声明要用过渡。
     *
     * 起手值取切换前的状态 ⇒ 第一次 hover 也从 0 淡上去。
     */
    private ?AnimatedValue $hoverAnim = null;

    /**
     * 接收旧盒的瞬态（盒换代时由 `Element::syncBox()` 调）。
     *
     * ★ 新增瞬态字段必须在这里补一行，否则重建后状态静默归零、画面不更新。
     *   只允许同类型盒之间移交（调用方保证）。
     *
     * @internal
     */
    public function inheritRuntimeState(RenderObject $old): void
    {
        $this->hovered = $old->hovered;
        $this->hoverAnim = $old->hoverAnim;
    }

    /**
     * 本节点的父节点（`null` = 根）。
     */
    public function parent(): ?RenderObject
    {
        return $this->parent;
    }

    /**
     * 本节点**自己**声明的光标形状（`null` = 没声明）。
     *
     * 它不是最终生效的形状：生效值由命中链从命中的盒向上找最近的声明者。
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
     * 这个**位置**要什么光标（`null` = 不表态，回落 `cursor()` 的父链声明）。
     *
     * 坐标是本盒的局部坐标（与 `hitTest()` 同口径），调用方负责减 `absoluteOrigin()`。
     * 覆写的盒必须**快速返回**（每次 move 都会问一次）。
     *
     * @api
     */
    public function cursorAt(float $x, float $y): ?CursorShape
    {
        return null;
    }

    /**
     * 本盒作为**浮层面板**时的矩形（盒坐标系；`null` = 本盒不是面板）。
     *
     * 返回矩形而非布尔：调用方要的是含投影留白内缩 `elevation` 后的几何。默认 `null`。
     *
     * @api
     */
    public function panelRect(): ?Rect
    {
        return null;
    }

    /**
     * 这个盒接不接受"悬停态"（默认 `false`）。
     *
     * `FocusManager` 沿盒父链问本方法，第一个回答 `true` 的即"该变色的盒"。
     * 覆写者通常写成"声明了状态色才接"，否则收不到 `setHovered`。
     *
     * @api
     */
    public function acceptsHover(): bool
    {
        return false;
    }

    /** @internal `FocusManager::hoverTo()` 推状态（唯一写点） */
    public function setHovered(bool $hovered): void
    {
        // 同值早退：`WM_MOUSEMOVE` 每帧都在推，不早退会把已收口过渡反复打回"在跑"
        // ⇒ 帧时钟永不落闸（空闲空转）。
        if ($this->hovered === $hovered) {
            return;
        }

        // 槽按**切换前**的状态起手 ⇒ 第一次 hover 也从 0 淡上去。
        $anim = $this->hoverAnim;
        if ($anim === null) {
            $anim = new AnimatedValue($this->hovered ? 1.0 : 0.0);
            $this->hoverAnim = $anim;
        }

        $this->hovered = $hovered;
        $anim->toward($hovered ? 1.0 : 0.0);

        // ★ **必须标脏**：`paintEpoch` 是"像素变了"的唯一信号；帧时钟此刻还没上膛，
        //   只靠 `tickAnimations()` 标脏会死锁（不脏⇒不跑帧⇒不tick⇒永不脏）。
        $this->markPaintChanged();
    }

    /**
     * 本盒当前是否悬停（给覆写 `paintContent()` 的盒读）。
     *
     * 只有 `acceptsHover()` 为 `true` 的盒会收到 `true`，其余恒 `false`。
     */
    public function hovered(): bool
    {
        return $this->hovered;
    }

    /**
     * 悬停**过渡进度**（`0..1`）—— 与 `hovered()` 的差别只在这条斜坡上。
     *
     * 没起过过渡 ⇒ 直接给 0 或 1，调用方替换无行为突变。
     */
    public function hoverProgress(): float
    {
        $anim = $this->hoverAnim;
        if ($anim === null) {
            return $this->hovered ? 1.0 : 0.0;
        }

        return $anim->value();
    }

    /**
     * 推进本节点自己的动画，回答"**还要下一帧吗**"（`Application::tickSubtree` 调）。
     *
     * 没有槽 ⇒ `false`，一次判空就返回。
     */
    public function tickAnimations(float $timestampMs): bool
    {
        $anim = $this->hoverAnim;
        if ($anim === null) {
            return false;
        }

        $before = $anim->value();
        $more = $anim->tick($timestampMs);

        // 推进也必须标脏：hover 过渡住盒上、无 State，不标就是"鼠标移上去颜色不动"。
        // ★ 判"**值变了**"而非"`tick()` 返回 true"：收口那帧 `tick()` 返回 `false`，
        //   但那一帧的值恰是最后一步、必须画出来，否则画面停在 0.93。
        if ($anim->value() !== $before) {
            $this->markPaintChanged();
        }

        return $more;
    }

    /**
     * @internal 挂载子节点：把子节点的父指到本节点（唯一改父落点）。
     */
    public function adoptChild(RenderObject $child): void
    {
        $child->parent = $this;
    }

    /**
     * 遍历子渲染对象 —— 布局与绘制都走这个入口，不直接摸具体字段。
     * "单子盒"与"多子盒"对父层是同一个接口。
     */
    abstract public function visitChildren(callable $visitor): void;

    /** 两阶段之二：父级已 translate 到本盒原点 */
    abstract public function paint(Canvas $canvas): void;
}
