<?php

declare(strict_types=1);

namespace Drift\Layout;

use Drift\Errors;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\AnimatedValue;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;

/**
 * 滚动视口：内容比视口长时，按偏移平移内容并裁剪。
 * ★ 坐标链纪律：滚动偏移进子盒的 offset，不是 paint 期额外 translate()——
 *   否则 hitTest()/absoluteOrigin() 坐标比画出的差一个滚动量 ⇒ 滚动后点击错位。
 *   本盒先按视口 size 判 (x,y)，视口外的点进不了子树，"裁掉的点不到"是结构保证。
 * 布局：内容按主轴无界才量得出长度；视口主轴取约束上限；
 *   maxScroll = max(0, 内容−视口)，偏移夹进 [0,maxScroll]（越界=内容飞走）。
 * 偏移构造时给初值、之后 setScrollOffset() 就地改（滚一格不重建整棵内容）；
 * 盒重建仍换新，State 是权威值；滚动条是纯视觉层（maxScroll==0 不画）。
 * @api
 */
final class RenderScrollView extends RenderProxyBox implements ScrollBarHost
{
    /** 滚动偏移（逻辑像素，≥ 0）—— setScrollOffset() 就地改 */
    private float $scrollOffset;
    /** 滚动条透明度（0..1）—— ScrollBarFade 每帧把当前值推过来 */
    private float $barOpacity;

    /** 本次布局算出的可滚动上限（供夹具与未来滚动条读） */
    private float $maxScroll = 0.0;

    /**
     * @param float $scrollOffset 已夹取的滚动偏移（逻辑像素，≥ 0）
     * @param Color $thumb        滚动条色（主题 Muted：两主题都清晰、语义"次要"）
     * @param bool  $vertical     true = 纵向滚动（内容沿 y 平移）
     * @param float|null $virtualExtent 完整内容的主轴长度（虚拟列表用）；
     *        null ⇒ 内容长度取子盒实测值（普通 ScrollView）。虚拟列表子盒只是一扇窗
     *        （只含可见行），实测长度远小于完整内容 ⇒ 不传则滚动条与上限算成"只有一屏"。
     * @param float $sliceOrigin 这扇窗起点在完整内容中的主轴坐标；
     *        同时是 maxScroll 的锚（scrollOffset 是完整内容偏移，非窗内偏移）
     *        和"窗该往哪挪"的量。
     * 参数序：必选在前、可选在后，否则 tpc 预处理器 Fatal（optional 不能在 required 前）。
     */
    public function __construct(
        float $scrollOffset,
        private readonly Color $thumb,
        private readonly bool $vertical = true,
        private readonly ?float $virtualExtent = null,
        private readonly float $sliceOrigin = 0.0,
        /**
         * 滚动条透明度（0..1）；由 ScrollBarFade（State 层）推，不是 readonly：
         * 盒每帧重造，淡出动画靠 setBarOpacity() 就地改，免得为动画每帧重建整棵内容。
         */
        float $barOpacity = 1.0,
    ) {
        $this->scrollOffset = $scrollOffset;
        $this->barOpacity = $barOpacity;
    }

    /**
     * 就地改偏移（见 ScrollBarHost::setScrollOffset()）。
     * 夹取用上一次布局的 maxScroll：滚动期间内容长度不变 ⇒ 仍有效；
     * 内容变短时下一帧会重建（build 里夹取写回），不留越界值。
     * ★ 还没排过（size() 为 null）⇒ 不夹、不应用、原样返回：那时 maxScroll 恒 0，
     *   夹一下会把首帧前设的偏移整个吞掉 —— 预览/测试里滚了 400 画出来还在顶部，不报错。
     *   （也不应用：没 size 就没有布局期算出的 sliceOrigin。）
     * 不标重排：偏移只改子盒 offset，不改任何盒 size ⇒ 由 applyScrollOffsets() 就地应用；
     * 早先借 markNeedsLayout() 让偏移生效，代价是每滚一格重排整棵树。paint 读子盒 offset，
     * 不重排也不会画面停在旧位置。
     * 但要标重绘：paint 现在有门控（paintEpoch），只靠"paint 会读它"不够，
     * 得有人告诉 Frame 去画（落点在 setOffset()：偏移真变了才标）。
     */
    public function setScrollOffset(Axis $axis, float $value): float
    {
        if ($axis !== $this->axis()) {
            return 0.0;   // 单轴视口：不认识的轴按接口纪律返回 0（同 appliedOffset）
        }

        $this->scrollOffset = $value;

        if (!$this->hasSize()) {
            return $value;
        }

        $this->applyScrollOffsets();

        return $this->applied();
    }

    /**
     * 把当前偏移应用到子盒的 offset（布局末尾与滚动期共用）。
     * 抽出来的理由：原先只住 performLayout()，"让偏移生效"与"重排整棵树"被绑成一件事；
     * 它只依赖上一次布局的结果 ⇒ 可在布局之外单独调。
     * 内容长度变化必然伴随 rebuild()（State 标脏）⇒ 走完整 layout ⇒ maxScroll/sliceOrigin 重算，
     * 滚动期沿用旧值安全。
     */
    private function applyScrollOffsets(): void
    {
        $child = $this->child;
        if ($child === null) {
            return;
        }

        $shift = $this->applied() - $this->sliceOrigin;
        if ($this->vertical) {
            $child->setOffset(0.0, -$shift);
        } else {
            $child->setOffset(-$shift, 0.0);
        }
    }

    /** @internal ScrollBarFade 每帧把值推过来（不重建、不重排；但必须标重绘） */
    public function setBarOpacity(float $value): void
    {
        if ($this->barOpacity === $value) {
            return;
        }

        $this->barOpacity = $value;

        // 淡出是"不重建不重排"通道 ⇒ settle/layout 两条判据都拦不住 ⇒ 不标就滚动条该消失却一直挂着（静默）。
        // 还得让重绘边界重录：thumb 的 alpha 录在命令流里，只标帧会被缓存重放旧值。
        $this->markPaintChanged();
    }

    /** 本视口的唯一轴（单轴视口 ⇒ scrollAxes() 只有一个元素） */
    private function axis(): Axis
    {
        return $this->vertical ? Axis::Vertical : Axis::Horizontal;
    }

    /** @internal 见 ScrollBarHost —— 单轴视口只报一根轴 */
    public function scrollAxes(): array
    {
        return [$this->axis()];
    }

    /**
     * 可滚动上限（布局后才有意义）。轴不匹配 ⇒ 0.0（而非报错）：
     * 调用方按 scrollAxes() 循环，不该先 if 再问。
     */
    public function maxScroll(Axis $axis): float
    {
        return $axis === $this->axis() ? $this->maxScroll : 0.0;
    }

    /** 实际生效的偏移（已按 maxScroll 夹取） */
    public function appliedOffset(Axis $axis): float
    {
        return $axis === $this->axis() ? $this->applied() : 0.0;
    }

    /** 本轴的实际偏移（内部用：performLayout 不该为了一个轴去走接口） */
    private function applied(): float
    {
        return min(max(0.0, $this->scrollOffset), $this->maxScroll);
    }

    /**
     * 把内容坐标里的矩形滚进视口所需的偏移增量（0.0 = 已经可见）。
     * 几何只算这一处：两个 Scrollable 实现都只是找到自己的盒 → 把增量交给 scrollBy()，
     * 不各算一遍（各算一遍迟早会漂）。
     * 返回增量而非绝对偏移：scrollBy() 是唯一写入口，夹取在它（下界）与布局期（上界）各做一次。
     * $base 是基准偏移（"现在按哪个偏移算可见"），必填：State 侧传自己待生效的值 ——
     * 同一帧连按两次 Tab 时盒还没重排，用盒里的 appliedOffset() 会把上一段增量再算一遍。
     * 不给默认值是为了避开 tpc 的"optional 在 required 前"Fatal；margin 默认值只留 ensureVisible()。
     * 目标比视口还大 ⇒ 上下两条件不可能同时满足，取"上沿对齐 margin"。这条幂等：连调两次
     * 第二次返回 0 —— 否则大目标会在两端来回跳。
     */
    public function revealDelta(Rect $target, float $margin, float $base): float
    {
        $size = $this->size();
        if ($size === null) {
            return 0.0;   // 还没布局：没有视口，"可见"无从谈起
        }

        // 属性先落局部标量：分支里直接读属性在 AOT 侧会被推成 Variant。
        $vertical = $this->vertical;
        $viewport = $vertical ? $size->height : $size->width;
        if ($viewport <= 0.0) {
            return 0.0;
        }

        $offset = $base;
        $start = $vertical ? $target->top : $target->left;
        $extent = $vertical ? $target->height : $target->width;

        if ($extent + $margin * 2.0 > $viewport) {
            return $start - $margin - $offset;
        }

        if ($start - $margin < $offset) {
            return $start - $margin - $offset;   // 在视口上方 ⇒ 负增量（往上滚）
        }

        $end = $start + $extent;
        if ($end + $margin > $offset + $viewport) {
            return $end + $margin - $offset - $viewport;   // 在下方 ⇒ 正增量
        }

        return 0.0;
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $child = $this->child;

        if ($child === null) {
            $this->maxScroll = 0.0;

            return $constraints->constrain(new Size(0.0, 0.0));
        }

        // 内容区是天然的重绘边界：偏移进的是子盒 offset（见类注释），
        // 不在内容自己的 paint() 里 ⇒ 缓存与偏移无关 ⇒ 滚动只重放。
        // setRepaintBoundary() 自己有"值不变则不动"的守卫，放这里不会每帧清缓存。
        $child->setRepaintBoundary(true);

        // ① 内容：主轴无界 —— 有界的话内容会被压进视口，永远量不出溢出
        $content = $child->layout($this->vertical
            ? new BoxConstraints($constraints->minWidth, $constraints->maxWidth, 0.0, BoxConstraints::INFINITY)
            : new BoxConstraints(0.0, BoxConstraints::INFINITY, $constraints->minHeight, $constraints->maxHeight));

        $contentMain = $this->vertical ? $content->height : $content->width;
        $contentCross = $this->vertical ? $content->width : $content->height;

        // ② 视口：取约束上限；上界无限 ⇒ 视口跟着内容走（不滚动）
        $mainMax = $this->vertical ? $constraints->maxHeight : $constraints->maxWidth;
        $crossMax = $this->vertical ? $constraints->maxWidth : $constraints->maxHeight;

        // 虚拟列表遇上无界主轴：先分清是哪种无界。
        // · RenderFlex 量自然尺寸的第一遍也递无界主轴，那是合法中间态 ——
        //   LazyColumn 的自然高就是当前窗口，松手让它跟窗口走，第二遍才拿真视口 ⇒ 不报错。
        // ★ 外层还有一个滚动视口才是真的用错：内容被外层无界约束吞掉，窗口永远只有一屏，
        //   滚动归外层管 ⇒ 看起来能跑、实际永远看不到第 21 行以后 ⇒ 响亮失败。
        // 判别法：沿父链找 RenderScrollView（Expanded/Column/Card 都不算）。
        if ($this->virtualExtent !== null && is_infinite($mainMax)) {
            for ($ancestor = $this->parent(); $ancestor !== null; $ancestor = $ancestor->parent()) {
                if ($ancestor instanceof self) {
                    Errors::report(
                        'LazyColumn 不能嵌在另一个滚动视口里 —— 外层视口给它的是无界主轴，'
                        . '窗口会永远只有一屏，而滚动归外层管（G2：不许静默失效）'
                    );

                    break;
                }
            }
        }

        $viewportMain = is_infinite($mainMax) ? $contentMain : $mainMax;
        $viewportCross = is_infinite($crossMax) ? $contentCross : $crossMax;

        // ③ 夹取（越界 = 内容飞走的静默形态 ⇒ 结构上不可能）
        // 虚拟列表：可滚上限按完整内容算，不是按这扇窗的实测长度。
        $virtualMain = $this->virtualExtent ?? $contentMain;
        $this->maxScroll = max(0.0, $virtualMain - $viewportMain);

        // ④ 偏移进子盒 offset（不是 paint 期 translate）—— 见类注释。
        // 抽成 applyScrollOffsets()：滚动期也要用同一段，而那时不该重排（见 setScrollOffset()）；
        // 这段只依赖 maxScroll/sliceOrigin/子盒，都是上一次布局留下的 ⇒ 可单独调。
        $this->applyScrollOffsets();

        return $this->vertical
            ? new Size($viewportCross, $viewportMain)
            : new Size($viewportMain, $viewportCross);
    }

    #[\Override]
    protected function paintContent(Canvas $canvas): void
    {
        $child = $this->child;
        $size = $this->size();

        if ($child === null || $size === null) {
            return;
        }

        // 裁剪是视口矩形：内容平移到视口外的部分不该画出来；命中侧不需要它 ——
        // 视口 size 判定已把视口外的点拦在子树之外。
        // 平移只包住内容：滚动条画在视口坐标系（平移已收在 paintChildAt 内部并还原）。
        // 否则 thumb 跟着内容一起被偏移带走 —— offset=max 时整条飞出视口被裁干净。
        // 滚动条画在内容之上（后画即在上）、裁剪区之内 ⇒ 内容滚到边角时被盖住一角，
        // 与浏览器/Flutter 的 Scrollbar 同行为。
        $canvas->save();
        $canvas->pushClip(new Rect(0.0, 0.0, $size->width, $size->height));
        $this->paintChildAt($canvas, $child);
        $this->paintScrollbar($canvas);
        $canvas->popClip();
        $canvas->restore();
    }

    /**
     * 滚动条：纯视觉层，不碰滚动机制。形体：胶囊形 thumb，贴视口右侧（纵向）/ 底侧（横向）。
     * 长度 = 视口占比 × 轨道（最短 24，超长夹进轨道）⇒ 内容越长条越短；
     * 位置 = 偏移 / maxScroll × 可走距离 ⇒ 条即"当前位置"的可视化；
     * maxScroll == 0（内容不溢出）⇒ 不画（浏览器同行为；画满轨 thumb 是撒谎——暗示"可以滚"）。
     * 这个早退还是结构必需：appliedOffset()/max 在 max=0 时是 0/0 ⇒ PHP 8 抛 DivisionByZeroError。
     */
    private function paintScrollbar(Canvas $canvas): void
    {
        $bar = $this->thumbRect($this->axis());
        if ($bar === null) {
            return;
        }

        // 透明度由 ScrollBarFade 推（滚动时 1、停手 ~900ms 后 0.35）
        ScrollBarGeometry::paint($canvas, $bar, $this->thumb, $this->barOpacity);
    }

    /**
     * 滚动条 thumb 的矩形（盒内坐标；null = 内容不溢出 / 还没布局）。
     * 几何只算一处（ScrollBarGeometry）：paintScrollbar() 用它画，Application 的拖动命中也用它 ——
     * 两处各算一遍迟早会漂。RenderTable 用同一份。
     */
    public function thumbRect(Axis $axis): ?Rect
    {
        $size = $this->size;
        if ($size === null || $axis !== $this->axis()) {
            return null;
        }

        return ScrollBarGeometry::thumbRect($size, $this->maxScroll, $this->applied(), $axis);
    }

    /** @internal 事件命中用：thumb 在渲染树根坐标里的矩形（见 ScrollBarHost） */
    public function thumbRectInRoot(Axis $axis): ?Rect
    {
        $bar = $this->thumbRect($axis);
        if ($bar === null) {
            return null;
        }

        [$originX, $originY] = RenderBox::absoluteOrigin($this);

        return new Rect($originX + $bar->left, $originY + $bar->top, $bar->width, $bar->height);
    }

    /**
     * thumb 在轨道里可走的距离（拖动映射用：位移 × maxScroll / travel）。
     * 与 thumbRect() 分开是因为拖动只要这一个标量，拿整条矩形回来还得再抠一遍长度；
     * 两处口子都从同一组常量推，不引入第二个"条长"定义。
     */
    public function thumbTravel(Axis $axis): float
    {
        $size = $this->size;
        if ($size === null || $axis !== $this->axis()) {
            return 0.0;
        }

        return ScrollBarGeometry::travel(ScrollBarGeometry::extent($size, $axis), $this->maxScroll);
    }

    /**
     * 溢出策略是 Scroll（不是默认的 Clip）：本盒会超出视口，超出部分按滚动偏移平移 + 视口裁剪 ——
     * 这是 Overflow::Scroll 的第一次真实实现（此前只有声明，行为靠 RenderDock 的 Errors::report 兜）。
     */
    #[\Override]
    public function overflow(): Overflow
    {
        return Overflow::Scroll;
    }
}
