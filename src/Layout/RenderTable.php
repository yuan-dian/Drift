<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;

/**
 * 表格盒：固定表头 + 双向平移 + 双向裁剪。
 *
 * 表头与行区共享同一个 x 偏移；行虚拟化在 `TableState::build()` 里算窗口，
 * 本盒只负责"固定表头 + 平移 + 裁剪 + 滚动条"。
 * ★ 偏移进子盒的 `offsetX/offsetY`，不是 paint 期的额外 `translate()`：
 *   否则 `hitTest()` 与 `absoluteOrigin()` 会比画出来的位置差一个滚动量（滚动后点击错位）。
 *
 * @internal 应用只认 `Table`
 */
final class RenderTable extends RenderBox implements ScrollBarHost
{
    /** 光标格描边的厚度（内描边，不入布局） */
    public const FOCUS_RING_W = 2.0;

    private ?RenderBox $header = null;

    private ?RenderBox $body = null;

    /** 滚动条透明度（0..1）—— `ScrollBarFade` 每帧推过来 */
    private float $barOpacity;

    /**
     * 两轴偏移（逻辑像素）—— `setScrollOffset()` 就地改。
     */
    private float $scrollX;

    private float $scrollY;

    /** 纵向可滚上限（布局后才有意义） */
    private float $maxScroll = 0.0;

    /** 横向可滚上限（内容宽 > 视口宽时非 0） */
    private float $maxScrollX = 0.0;

    /** 本次布局生效的表头高（可能被视口夹短；paint / hitTest 都用它） */
    private float $headerBoxH = 0.0;

    /**
     * 光标格（行区内容坐标；null = 不画）。
     *
     * 由 `TableState` 算好递进来：列宽只有 `resolveWidths()` 一处真相，本盒不重算。
     */
    private ?Rect $focusRect;

    /**
     * 表头光标格（**表头区坐标**；null = 不画）。
     *
     * 与 `$focusRect` 互斥，但**坐标系不同**：表头 y 从 0、不随 `scrollY` 动，
     * 所以不能合成一个字段 —— 那会让绘制端去猜"这个 rect 属于哪个裁剪区"。
     */
    private ?Rect $headerFocusRect;

    private Color $focusInk;

    /**
     * 构造：两轴偏移 + 完整内容尺寸 + 各色 + 光标格。
     *
     * @param float $scrollX       横向偏移（表头与行区共享的视口坐标）
     * @param float $scrollY       纵向偏移（行区坐标）
     * @param float $contentWidth  完整内容宽（= Σ列宽 + 间距）
     * @param float $headerHeight  表头期望高
     * @param float $contentHeight 完整内容高（= 表头 + 行数 × 行高）
     * @param float $sliceOrigin   行区窗起点在完整内容里的 y
     * @param Color $paneInk       行区背景
     * @param Color $headerInk     表头底
     * @param Color $borderInk     表头下方 1px 分隔线
     * @param Color $thumb         滚动条色
     * @param Color $focusInk      光标格描边色
     * @param ?Rect $focusRect     光标格（行区内容坐标；null = 失焦）
     * @param ?Rect $headerFocusRect 表头光标格（表头区坐标；null = 光标不在表头）
     */
    public function __construct(
        float $scrollX,
        float $scrollY,
        private readonly float $contentWidth,
        private readonly float $headerHeight,
        private readonly float $contentHeight,
        private readonly float $sliceOrigin,
        private readonly Color $paneInk,
        private readonly Color $headerInk,
        private readonly Color $borderInk,
        private readonly Color $thumb,
        Color $focusInk,
        ?Rect $focusRect,
        float $barOpacity = 1.0,
        ?Rect $headerFocusRect = null,
    ) {
        $this->scrollX = $scrollX;
        $this->scrollY = $scrollY;
        $this->barOpacity = $barOpacity;
        $this->focusInk = $focusInk;
        $this->focusRect = $focusRect;
        $this->headerFocusRect = $headerFocusRect;
    }

    /**
     * 就地改某一轴的偏移（见 `ScrollBarHost::setScrollOffset()`）。
     *
     * ★ 还没排过（hasSize 为假）⇒ 不夹、不应用：maxScroll 恒 0 时夹一下会把首帧
     *   之前设的偏移吞掉，且不报错。
     */
    public function setScrollOffset(Axis $axis, float $value): float
    {
        if ($axis === Axis::Vertical) {
            $this->scrollY = $value;
        } else {
            $this->scrollX = $value;
        }

        if (!$this->hasSize()) {
            return $value;
        }

        $this->applyScrollOffsets();

        return $this->appliedOffset($axis);
    }

    /**
     * 把两轴偏移应用到子盒 offset（布局末尾与滚动期共用）。
     */
    private function applyScrollOffsets(): void
    {
        $header = $this->header;
        $body = $this->body;
        if ($header === null || $body === null) {
            return;
        }

        $offsetX = $this->appliedOffsetX();
        $offsetY = $this->appliedOffsetY();

        $header->setOffset(-$offsetX, 0.0);
        $body->setOffset(-$offsetX, $this->headerBoxH - ($offsetY - $this->sliceOrigin));
    }

    /**
     * 行区可见高（= 视口高 − 表头高，上一次布局的结果）。
     *
     * `TableState` 要在不重建前提下算"该建哪几行"，拿不到 BuildContext，
     * 盒的 size 与 headerBoxH 是唯一在滚动期可靠的口径。
     */
    public function bodyHeight(): float
    {
        $size = $this->size;
        if ($size === null) {
            return 0.0;
        }

        return max(0.0, $size->height - $this->headerBoxH);
    }

    /**
     * 表头占掉的高度 = 行区内容坐标原点（y = 0 是第 0 行顶部）。
     *
     * 指针反算"落在第几行"要减它；与 bodyHeight() 共用同一 headerBoxH，
     * 而非 build 期估的 headerHeightPx()（表头可能被约束压过，口径不一致）。
     */
    public function headerHeight(): float
    {
        return $this->headerBoxH;
    }

    /** @internal `TableElement` 挂接期调（子盒由 Element 挂，不在 Widget 里挂） */
    public function setHeader(RenderBox $box): void
    {
        $this->header = $box;
        $this->adoptChild($box);
        // 表头不开重绘边界：单元格少、命令少，开销落在噪声里。
    }

    /** @internal 同上 */
    public function setBody(RenderBox $box): void
    {
        $this->body = $box;
        $this->adoptChild($box);

        // 行区是天然重绘边界：偏移由本类施加，缓存与偏移无关。
        $box->setRepaintBoundary(true);
    }

    /** @internal 淡出动画每帧推值（不重建、不重排；但必须标重绘） */
    public function setBarOpacity(float $value): void
    {
        if ($this->barOpacity === $value) {
            return;
        }

        $this->barOpacity = $value;
        $this->markPaintChanged();   // 同 `RenderScrollView::setBarOpacity()`（N1）
    }

    /**
     * @internal 光标格（行区内容坐标；null = 不画）。
     *
     * 内容坐标才是稳定参照（滚动不动它），不在内部二次换算成视口坐标。
     */
    public function focusRect(): ?Rect
    {
        return $this->focusRect;
    }

    /** @internal 表头光标格（表头区坐标；null = 光标不在表头） */
    public function headerFocusRect(): ?Rect
    {
        return $this->headerFocusRect;
    }

    /** @internal 见 `ScrollBarHost` —— 表格是双轴盒，两根条都可能出现 */
    public function scrollAxes(): array
    {
        // 顺序 = 绘制顺序（先横后竖），命中按反序问 ⇒ 角落归竖条。
        return [Axis::Horizontal, Axis::Vertical];
    }

    /** 该轴的可滚上限（布局后才有意义） */
    public function maxScroll(Axis $axis): float
    {
        return $axis === Axis::Vertical ? $this->maxScroll : $this->maxScrollX;
    }

    /** 该轴实际生效的偏移（已按 maxScroll 夹取） */
    public function appliedOffset(Axis $axis): float
    {
        return $axis === Axis::Vertical ? $this->appliedOffsetY() : $this->appliedOffsetX();
    }

    private function appliedOffsetY(): float
    {
        return min(max(0.0, $this->scrollY), $this->maxScroll);
    }

    private function appliedOffsetX(): float
    {
        return min(max(0.0, $this->scrollX), $this->maxScrollX);
    }

    /**
     * 该轴的滚动条矩形；内容不溢出该轴 ⇒ null（画了就是撒谎）。
     */
    public function thumbRect(Axis $axis): ?Rect
    {
        $size = $this->size;
        if ($size === null) {
            return null;
        }

        return ScrollBarGeometry::thumbRect($size, $this->maxScroll($axis), $this->appliedOffset($axis), $axis);
    }

    /** @internal 事件命中用：thumb 在渲染树根坐标里的矩形 */
    public function thumbRectInRoot(Axis $axis): ?Rect
    {
        $bar = $this->thumbRect($axis);
        if ($bar === null) {
            return null;
        }

        [$originX, $originY] = RenderBox::absoluteOrigin($this);

        return new Rect($originX + $bar->left, $originY + $bar->top, $bar->width, $bar->height);
    }

    public function thumbTravel(Axis $axis): float
    {
        $size = $this->size;
        if ($size === null) {
            return 0.0;
        }

        return ScrollBarGeometry::travel(
            ScrollBarGeometry::extent($size, $axis),
            $this->maxScroll($axis),
        );
    }

    /**
     * 把行区内容坐标里的矩形滚进可见行区所需的偏移增量（0 = 已可见）。
     *
     * 可见高取「视口高 − 表头高」。返回增量而非绝对偏移：写入口只有
     * `scrollBy()`，夹取在那里与布局期各做一次；返回绝对值会绕过它。
     */
    public function revealDelta(Rect $target, float $margin, float $base): float
    {
        $size = $this->size;
        if ($size === null) {
            return 0.0;   // 还没布局：没有视口，"可见"无从谈起
        }

        $bodyH = $size->height - $this->headerBoxH;
        if ($bodyH <= 0.0) {
            return 0.0;
        }

        $visibleTop = $base + $margin;
        $visibleBottom = $base + $bodyH - $margin;
        $targetTop = $target->top;
        $targetBottom = $target->top + $target->height;

        // 目标比可见区还高时只保证上沿露出来（幂等：连调两次返回 0）。
        if ($target->height + $margin * 2.0 > $bodyH) {
            return $targetTop - $visibleTop;
        }

        if ($targetTop < $visibleTop) {
            return $targetTop - $visibleTop;
        }

        if ($targetBottom > $visibleBottom) {
            return $targetBottom - $visibleBottom;
        }

        return 0.0;
    }

    /** 横向的 `revealDelta()`：光标格右移时把它带进视口。 */
    public function revealDeltaX(Rect $target, float $margin, float $base): float
    {
        $size = $this->size;
        if ($size === null) {
            return 0.0;
        }

        $visibleW = $size->width;
        if ($visibleW <= 0.0) {
            return 0.0;
        }

        $visibleLeft = $base + $margin;
        $visibleRight = $base + $visibleW - $margin;
        $targetLeft = $target->left;
        $targetRight = $target->left + $target->width;

        if ($target->width + $margin * 2.0 > $visibleW) {
            return $targetLeft - $visibleLeft;
        }

        if ($targetLeft < $visibleLeft) {
            return $targetLeft - $visibleLeft;
        }

        if ($targetRight > $visibleRight) {
            return $targetRight - $visibleRight;
        }

        return 0.0;
    }

    public function visitChildren(callable $visitor): void
    {
        // 顺序 = 绘制顺序：先表头后行区。
        $header = $this->header;
        $body = $this->body;
        if ($header !== null) {
            $visitor($header);
        }
        if ($body !== null) {
            $visitor($body);
        }
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $header = $this->header;
        $body = $this->body;

        if ($header === null || $body === null) {
            $this->maxScroll = 0.0;
            $this->maxScrollX = 0.0;
            $this->headerBoxH = 0.0;

            return $constraints->constrain(Size::zero());
        }

        // 视口取约束上限；上界无限则跟随内容（不滚）。
        $maxW = $constraints->maxWidth;
        $maxH = $constraints->maxHeight;
        $viewportW = \is_infinite($maxW) ? $this->contentWidth : max(0.0, $maxW);
        $viewportH = \is_infinite($maxH) ? $this->contentHeight : max(0.0, $maxH);

        // 表头：宽度铺满视口（fixed 列不满时底色仍要铺满，否则右侧露缝），
        // 高度取指定值与视口的较小者（窗口比表头矮时不画到盒外）。
        $fullW = max($this->contentWidth, $viewportW);
        $hh = min($this->headerHeight, $viewportH);
        $this->headerBoxH = $hh;
        $header->layout(new BoxConstraints($fullW, $fullW, $hh, $hh));

        // 行区：宽定死成内容宽（行超出靠裁剪），高无界（虚拟化的正常形态，
        // 给有界高会让 Column 报放不下）。
        $body->layout(new BoxConstraints(
            $this->contentWidth,
            $this->contentWidth,
            0.0,
            BoxConstraints::INFINITY,
        ));

        // 夹取
        $this->maxScroll = max(0.0, $this->contentHeight - $viewportH);
        $this->maxScrollX = max(0.0, $this->contentWidth - $viewportW);

        // 偏移进子盒 offset（非 paint 期 translate），见类注释。
        $this->applyScrollOffsets();

        return $constraints->constrain(new Size($viewportW, $viewportH));
    }

    /**
     * 底色 / 表头底 / 分隔线，画在两个裁剪区之外、`paintContent()` 之前。
     *
     * 1px 分隔线跨在两个裁剪区的边界上，画在裁剪内会被裁掉一半。
     */
    #[\Override]
    protected function paintOwnChrome(Canvas $canvas): void
    {
        $size = $this->size;
        if ($size === null) {
            return;
        }

        $canvas->drawRect(
            new Rect(0.0, 0.0, $size->width, $size->height),
            Paint::fill($this->paneInk),
        );

        $hh = $this->headerBoxH;
        if ($hh <= 0.0) {
            return;
        }

        $canvas->drawRect(new Rect(0.0, 0.0, $size->width, $hh), Paint::fill($this->headerInk));
        // 分隔线画在表头内侧最后一行像素上（不占额外空间 ⇒ 行区起点不偏移）。
        $canvas->drawRect(
            new Rect(0.0, $hh - 1.0, $size->width, 1.0),
            Paint::fill($this->borderInk),
        );
    }

    #[\Override]
    protected function paintContent(Canvas $canvas): void
    {
        $size = $this->size;
        if ($size === null) {
            return;
        }

        $hh = $this->headerBoxH;
        $header = $this->header;
        $body = $this->body;

        // ① 表头区：裁 [0, hh)
        if ($header !== null && $hh > 0.0) {
            $canvas->save();
            $canvas->pushClip(new Rect(0.0, 0.0, $size->width, $hh));
            $this->paintChildAt($canvas, $header);
            // 表头光标框：同一裁剪区、同一段描边代码，只是基准 y 为 0、不随竖滚
            $this->paintFocusRing($canvas, $this->headerFocusRect, 0.0, $this->appliedOffsetX(), 0.0);
            $canvas->popClip();
            $canvas->restore();
        }

        // ② 行区：裁 [hh, H)
        if ($body !== null && $size->height > $hh) {
            $canvas->save();
            $canvas->pushClip(new Rect(0.0, $hh, $size->width, $size->height - $hh));
            $this->paintChildAt($canvas, $body);

            // 光标框画在行区内容之上、回到视口坐标：内容→屏幕 = 减偏移、加表头高。
            $this->paintFocusRing($canvas, $this->focusRect, $hh, $this->appliedOffsetX(), $this->appliedOffsetY());

            $canvas->popClip();
            $canvas->restore();
        }

        // ③ 滚动条：视口坐标，按 scrollAxes() 顺序画（与命中判定同序）。
        $canvas->save();
        $canvas->pushClip(new Rect(0.0, 0.0, $size->width, $size->height));
        foreach ($this->scrollAxes() as $axis) {
            $this->paintScrollbar($canvas, $axis);
        }
        $canvas->popClip();
        $canvas->restore();
    }

    /**
     * 光标格的 2px 内描边：四条实心矩形，全落在格子矩形内部，像素位置可控。
     *
     * @param ?Rect $rect    要描的格子（坐标系由 `$bodyTop` 决定）
     * @param float $bodyTop 该坐标系原点在**屏幕 y** 上的位置：行区 = 表头高，表头 = 0
     */
    private function paintFocusRing(Canvas $canvas, ?Rect $rect, float $bodyTop, float $offsetX, float $offsetY): void
    {
        if ($rect === null) {
            return;
        }

        $x = $rect->left - $offsetX;
        $y = $bodyTop + $rect->top - $offsetY;
        $w = $rect->width;
        $h = $rect->height;
        if ($w <= 0.0 || $h <= 0.0) {
            return;
        }

        // 格子比描边还窄时按半宽收敛（否则左右两条叠成一片实心，像选中）。
        $t = min(self::FOCUS_RING_W, $w / 2.0, $h / 2.0);
        $paint = Paint::fill($this->focusInk);

        $canvas->drawRect(new Rect($x, $y, $w, $t), $paint);
        $canvas->drawRect(new Rect($x, $y + $h - $t, $w, $t), $paint);
        $canvas->drawRect(new Rect($x, $y + $t, $t, $h - $t * 2.0), $paint);
        $canvas->drawRect(new Rect($x + $w - $t, $y + $t, $t, $h - $t * 2.0), $paint);
    }

    /**
     * 命中：分区递坐标（表头区 / 行区），不是简单逆序问子盒。
     *
     * 基类会把点同时递给两个子盒 ⇒ 行区矩形覆盖整个表盒，焦点落表头时会先命中
     * 被遮住的行；分区之后"看到哪就能点哪"是结构保证。
     */
    #[\Override]
    public function hitTest(float $x, float $y): ?RenderBox
    {
        $size = $this->size;
        if ($size === null) {
            return null;
        }

        if ($x < 0.0 || $y < 0.0 || $x >= $size->width || $y >= $size->height) {
            return null;
        }

        if ($this->isDisabled()) {
            // ★ 禁用的表格也得挡住：覆写点漏一处 = 表禁用却能点行（半禁用怪状态）。
            return $this;
        }

        $padX = $this->contentOriginX();
        $padY = $this->contentOriginY();
        $hh = $this->headerBoxH;
        $header = $this->header;
        $body = $this->body;

        if ($y < $hh) {
            if ($header !== null) {
                $hit = $header->hitTest($x - $padX - $header->offsetX(), $y - $padY - $header->offsetY());
                if ($hit !== null) {
                    return $hit;
                }
            }

            return $this;   // 表头区域的空白：归表盒（吞掉，不穿透到行区）
        }

        if ($body !== null) {
            $hit = $body->hitTest($x - $padX - $body->offsetX(), $y - $padY - $body->offsetY());
            if ($hit !== null) {
                return $hit;
            }
        }

        return $this;
    }

    /** 滚动条（纯视觉；几何与绘制都在 `ScrollBarGeometry`，三个滚动盒同一份） */
    private function paintScrollbar(Canvas $canvas, Axis $axis): void
    {
        $bar = $this->thumbRect($axis);
        if ($bar === null) {
            return;
        }

        ScrollBarGeometry::paint($canvas, $bar, $this->thumb, $this->barOpacity);
    }

    protected function contentIntrinsicWidth(float $height): float
    {
        return $this->contentWidth + $height * 0.0;   // 显式读另一轴，防"顺手删参数"
    }

    protected function contentIntrinsicHeight(float $width): float
    {
        return $this->contentHeight + $width * 0.0;
    }

    /**
     * 溢出策略：Clip。声明后基类在 paint() 里再裁一次盒矩形（双保险），也回答 C5 契约。
     */
    #[\Override]
    public function overflow(): Overflow
    {
        return Overflow::Clip;
    }
}
