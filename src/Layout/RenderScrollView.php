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
use Drift\Rendering\Paint;

/**
 * 滚动视口（§13.1 的新盒）—— **内容比视口长时，按偏移平移内容并裁剪**。
 *
 * ★★ **坐标链纪律（2026-10-17 padding 缺陷的同族）**：滚动偏移**进子盒的
 *   `offsetX/offsetY()`**，不是 paint 期的额外 `translate()`。否则
 *   `hitTest()` 与 `Application::absoluteOrigin()` 拿到的坐标会比画出来的
 *   位置差一个滚动量 —— 表现为"滚动后点击错位"（滚动越远偏得越多，且只在
 *   滚过之后才出现，是最难查的一类）。
 *   ⇒ 命中测试：本盒先按**视口** size 判 (x,y) ⇒ 视口外的点根本进不了子树，
 *     于是"裁掉的部分点不到"是结构保证，不需要 hitTest 认识裁剪。
 *
 * ★ 布局策略（M2 级，够用即止）：
 *   - 内容按**主轴无界**布局 ⇒ 才量得出"到底有多长"；
 *   - 视口主轴 = 约束上限（INF ⇒ 取内容长度，即"不滚、撑满"）；
 *   - `maxScroll = max(0, 内容主轴 − 视口主轴)`，偏移夹进 `[0, maxScroll]`
 *     ⇒ 滚不到头、也滚不出界（越界是"内容飞走"的静默形态）。
 *
 * ★ 偏移量**不是**构造参数里那个只读值：构造时给初值，之后由
 *   `setScrollOffset()` **就地**改（`ScrollBarHost` 的接口方法）。理由见那个方法 ——
 *   一句话：滚一格不该重建整棵内容（实测占滚动帧的 55%）。
 *   ★ 盒仍会在重建时**换新**（`Element::syncBox()` 的纪律不变）⇒ State 才是
 *   权威值，盒只是它的投影；换盒时新偏移由构造参数递进来。
 *
 * ★ **滚动条**（2026-10-17）：`paint()` 在内容之上画一条胶囊形 thumb
 *   （`maxScroll == 0` 不画）。纯视觉层 —— 不新增状态、不改夹取、不碰命中。
 *
 * @api
 */
final class RenderScrollView extends RenderProxyBox implements ScrollBarHost
{
    /** 滚动偏移（逻辑像素，≥ 0）—— `setScrollOffset()` 就地改 */
    private float $scrollOffset;
    /** 滚动条透明度（`0..1`）—— `ScrollBarFade` 每帧把当前值推过来 */
    private float $barOpacity;

    /** 本次布局算出的可滚动上限（供夹具与未来滚动条读） */
    private float $maxScroll = 0.0;

    /**
     * @param float $scrollOffset 已夹取的滚动偏移（逻辑像素，≥ 0）
     * @param Color $thumb        滚动条色（主题 Muted：两主题都够清晰、语义"次要"）
     * @param bool  $vertical     true = 纵向滚动（内容沿 y 平移）
     * @param float|null $virtualExtent 完整内容的**主轴长度**（虚拟列表用）。
     *        `null` ⇒ 内容长度就取子盒实测值（普通 `ScrollView`，逐字节同旧行为）。
     *        ★ 虚拟列表的子盒只是**一扇窗**（只含可见行），它的实测长度远小于
     *        完整内容 ⇒ 不传这个参数，滚动条长度和可滚上限都会算成"只有一屏"。
     * @param float $sliceOrigin 这扇窗的起点在**完整内容**中的主轴坐标。
     *        ★ 它同时是两个东西：`maxScroll` 的锚（`scrollOffset` 是完整内容的
     *        偏移，不是窗内偏移）和"窗该往哪挪"的量。
     *
     * ★ 参数序：**必选在前、可选在后** —— 反了 PHP 解释器只发 Deprecated，
     *   tpc 预处理器直接 Fatal（"optional parameter cannot be declared before
     *   required parameter"）⇒ 解释器三门全绿也过不了 codegen（2026-10-17 踩过）。
     */
    public function __construct(
        float $scrollOffset,
        private readonly Color $thumb,
        private readonly bool $vertical = true,
        private readonly ?float $virtualExtent = null,
        private readonly float $sliceOrigin = 0.0,
        /**
         * 滚动条透明度（`0..1`）。
         *
         * ★ 由 `ScrollBarFade`（住 State 层）推 —— **不是** readonly：盒每帧重造，
         *   淡出动画在两次重建之间靠 `setBarOpacity()` 就地改，免得为了动画
         *   每帧重建整棵滚动内容。
         */
        float $barOpacity = 1.0,
    ) {
        $this->scrollOffset = $scrollOffset;
        $this->barOpacity = $barOpacity;
    }

    /**
     * 就地改偏移（见 `ScrollBarHost::setScrollOffset()`）。
     *
     * ★ 夹取用**上一次布局**的 `maxScroll`：滚动期间内容长度不变 ⇒ 那个值仍然有效。
     *   内容真的变短时下一帧会重建（build 里有夹取写回），不会留下越界值。
     * ★★ **还没排过（`size()` 为 null）⇒ 不夹、原样返回**：那时 `maxScroll` 恒 0，
     *   夹一下会把"首帧之前设的偏移"整个吞掉 —— 现象是预览 / 测试里
     *   `render()` 的 prepare 阶段滚了 400，画出来还在顶部，**不报错**。
     */
    public function setScrollOffset(Axis $axis, float $value): float
    {
        if ($axis !== $this->axis()) {
            return 0.0;   // 单轴视口：不认识的轴按接口纪律返回 0（同 appliedOffset）
        }

        $this->scrollOffset = $value;
        RenderBox::markNeedsLayout();

        return $this->hasSize() ? $this->applied() : $value;
    }

    /** @internal `ScrollBarFade` 每帧把值推过来（不重建、不重排） */
    public function setBarOpacity(float $value): void
    {
        $this->barOpacity = $value;
    }

    /** 本视口的唯一轴（单轴视口 ⇒ `scrollAxes()` 只有一个元素） */
    private function axis(): Axis
    {
        return $this->vertical ? Axis::Vertical : Axis::Horizontal;
    }

    /** @internal 见 `ScrollBarHost` —— 单轴视口只报一根轴 */
    public function scrollAxes(): array
    {
        return [$this->axis()];
    }

    /**
     * 可滚动上限（布局后才有意义）。
     *
     * ★ 轴不匹配 ⇒ `0.0`（而不是报错）：调用方按 `scrollAxes()` 循环，不该先
     *   `if` 再问；报错会让"随便问一下"变成噪声。
     */
    public function maxScroll(Axis $axis): float
    {
        return $axis === $this->axis() ? $this->maxScroll : 0.0;
    }

    /** 实际生效的偏移（已按 `maxScroll` 夹取） */
    public function appliedOffset(Axis $axis): float
    {
        return $axis === $this->axis() ? $this->applied() : 0.0;
    }

    /** 本轴的实际偏移（内部用：`performLayout` 不该为了一个轴去走接口） */
    private function applied(): float
    {
        return min(max(0.0, $this->scrollOffset), $this->maxScroll);
    }

    /**
     * 把**内容坐标**里的矩形滚进视口所需的偏移**增量**（`0.0` = 已经可见）。
     *
     * ★ 几何只算这一处：两个 `Scrollable` 实现（`ScrollViewState` /
     *   `LazyListState`）都只是"找到自己的盒 → 把增量交给 `scrollBy()`"，
     *   不各算一遍 —— 各算一遍迟早会漂（一个按视口高、一个按窗口高之类）。
     *
     * ★ 返回**增量**不是绝对偏移：`scrollBy()` 是唯一的写入口，夹取在它
     *   （下界）与布局期（上界）各做一次。返回绝对值就等于绕过写入口。
     *
     * ★ `$base` 是**基准偏移**（"现在按哪个偏移算可见"），**必填**：State 侧传
     *   自己**待生效**的值 —— 同一帧里连按两次 Tab 时盒还没重排，用盒里的
     *   `appliedOffset()` 会把上一段增量再算一遍（8 之后再要 48 会滚到 56）。
     *   目标矩形是内容坐标 ⇒ 与基准解耦，换基准不改变正确性。
     *   ★ 不给默认值是为了避开 tpc 的"可选参数排在必选参数前"Fatal
     *   （2026-10-17 踩过）；`margin` 的默认值只留在 `ensureVisible()` 一处。
     *
     * ★ **目标比视口还大**（`extent + 2×margin > viewport`）⇒ 上下两个条件
     *   不可能同时满足，取"上沿对齐 `margin`"。这条是**幂等**的：连着调两次
     *   第二次返回 0 —— 否则大目标会在两端来回跳（每次调用都翻一次方向）。
     */
    public function revealDelta(Rect $target, float $margin, float $base): float
    {
        $size = $this->size();
        if ($size === null) {
            return 0.0;   // 还没布局：没有视口，"可见"无从谈起
        }

        // ★ 属性先落局部标量：分支里直接读属性在 AOT 侧会被推成 `Variant`。
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

        // ① 内容：主轴**无界** —— 有界的话内容会被压进视口，永远量不出溢出
        $content = $child->layout($this->vertical
            ? new BoxConstraints($constraints->minWidth, $constraints->maxWidth, 0.0, BoxConstraints::INFINITY)
            : new BoxConstraints(0.0, BoxConstraints::INFINITY, $constraints->minHeight, $constraints->maxHeight));

        $contentMain = $this->vertical ? $content->height : $content->width;
        $contentCross = $this->vertical ? $content->width : $content->height;

        // ② 视口：取约束上限；上界无限 ⇒ 视口跟着内容走（不滚动）
        $mainMax = $this->vertical ? $constraints->maxHeight : $constraints->maxWidth;
        $crossMax = $this->vertical ? $constraints->maxWidth : $constraints->maxHeight;

        // ★★ 虚拟列表遇上无界主轴：**先分清是哪种无界**。
        //   · `RenderFlex` 量自然尺寸的**第一遍**也会递无界主轴（`RenderFlexible`
        //     的注释原话：量自然高那遍拿无界主轴）。那是**合法的中间态** ——
        //     LazyColumn 的自然高就是"当前窗口"，松手让它跟着窗口走即可，
        //     第二遍（`tightMain`）才拿得到真视口。⇒ 不报错。
        //   · **外层还有一个滚动视口**才是真的用错：内容被外层的无界约束吞掉，
        //     窗口永远只有一屏，而滚动归外层管 ⇒ 看起来能跑、实际永远看不到第
        //     21 行以后。这是 G2 要消灭的静默形态 ⇒ 响亮失败。
        //   ★ 判别法：沿父链找 `RenderScrollView`（`Expanded` / `Column` / `Card`
        //     都不算）。测过的反例：把 LazyColumn 塞进外层 ScrollView ⇒ 命中这条。
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
        // ★ 虚拟列表：可滚上限按**完整内容**算，不是按这扇窗的实测长度。
        $virtualMain = $this->virtualExtent ?? $contentMain;
        $this->maxScroll = max(0.0, $virtualMain - $viewportMain);
        $offset = $this->applied();

        // ④ ★ 偏移进子盒 offset（不是 paint 期 translate）—— 见类注释
        // ★ 虚拟列表：窗只含 [sliceOrigin, sliceOrigin+窗长)，所以窗要挪的
        //   距离是"完整内容偏移 − 窗起点"。普通 ScrollView 的 sliceOrigin 恒 0
        //   ⇒ 与旧式 `-offset` 完全等价。
        $shift = $offset - $this->sliceOrigin;
        if ($this->vertical) {
            $child->setOffset(0.0, -$shift);
        } else {
            $child->setOffset(-$shift, 0.0);
        }

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

        // ★ 裁剪是**视口矩形**：内容平移到视口外的部分不该画出来。
        //   命中侧不需要它 —— 视口 size 判定已经把视口外的点拦在子树之外。
        $canvas->save();
        $canvas->pushClip(new Rect(0.0, 0.0, $size->width, $size->height));
        // ★★ 平移只包住内容：滚动条画在**视口坐标系**（restore 掉平移再画）。
        //   否则 thumb 跟着内容一起被偏移带走 —— offset=max 时整条飞出视口
        //   被裁干净（"该贴底时什么都没有"，2026-10-17 被 smoke 的 sv2 抓到：
        //   offset 小的用例有墨、offset=max 的没有 ⇒ 单看一条会以为是位置公式错）。
        $canvas->save();
        $canvas->translate($child->offsetX(), $child->offsetY());
        $child->paintComposed($canvas);
        $canvas->restore();
        // ★ 滚动条画在**内容之上**（后画即在上）、裁剪区之内 ⇒ 内容滚到
        //   边角时被它盖住一角，与浏览器/Flutter 的 Scrollbar 同行为。
        $this->paintScrollbar($canvas);
        $canvas->popClip();
        $canvas->restore();
    }

    /**
     * 滚动条（2026-10-17 补齐的第一个滚动缺口：**纯视觉层**，不碰滚动机制）。
     *
     * 形体：胶囊形 thumb，贴视口右侧（纵向）/ 底侧（横向）。
     * - 长度 = 视口占比 × 轨道（最短 24，超长夹进轨道）⇒ 内容越长条越短；
     * - 位置 = 偏移 / maxScroll × 可走距离 ⇒ 条即"当前位置"的可视化；
     * - `maxScroll == 0`（内容不溢出）⇒ **不画**（浏览器同行为；画一条
     *   满轨 thumb 是撒谎——它暗示"可以滚"）。★ 这个早退还是**结构必需**：
     *   `appliedOffset() / max` 在 max=0 时是 0/0 ⇒ PHP 8 抛
     *   `DivisionByZeroError`（删掉它 smoke 当场 Fatal，见反例自检 ③）。
     */
    private function paintScrollbar(Canvas $canvas): void
    {
        $bar = $this->thumbRect($this->axis());
        if ($bar === null) {
            return;
        }

        // 透明度由 `ScrollBarFade` 推（滚动时 1、停手 ~900ms 后 0.35）
        $alpha = (int) \round($this->thumb->a * $this->barOpacity);
        if ($alpha <= 0) {
            return;
        }

        $canvas->drawRect($bar, Paint::fill($this->thumb->withAlpha($alpha)), ScrollBarGeometry::THICKNESS * 0.5);
    }

    /**
     * 滚动条 thumb 的矩形（**盒内**坐标；`null` = 内容不溢出 / 还没布局）。
     *
     * ★ 几何只算一处（`ScrollBarGeometry`）：`paintScrollbar()` 用它画，
     *   `Application` 的滚动条拖动用它命中 —— 两处各算一遍迟早会漂
     *   （条画在这里、抓在别处）。`RenderTable` 用的是同一份。
     */
    public function thumbRect(Axis $axis): ?Rect
    {
        $size = $this->size;
        if ($size === null || $axis !== $this->axis()) {
            return null;
        }

        return ScrollBarGeometry::thumbRect($size, $this->maxScroll, $this->applied(), $axis);
    }

    /** @internal 事件命中用：thumb 在渲染树根坐标里的矩形（见 `ScrollBarHost`） */
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
     * thumb 在轨道里**可走**的距离（拖动映射用：位移 × maxScroll / travel）。
     *
     * ★ 与 `thumbRect()` 分开是因为拖动只要这一个标量，拿整条矩形回来还得
     *   再抠一遍长度；两处口子都从同一组常量推，不引入第二个"条长"定义。
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
     * ★ 溢出策略是 **Scroll**（不是默认的 Clip）：本盒会超出视口，超出部分按
     *   滚动偏移平移 + 视口裁剪 —— 这是 `Overflow::Scroll` 的第一次真实实现
     *   （此前只有声明，行为靠 `RenderDock` 的 `Errors::report` 兜）。
     */
    #[\Override]
    public function overflow(): Overflow
    {
        return Overflow::Scroll;
    }
}
