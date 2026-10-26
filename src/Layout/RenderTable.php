<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;

/**
 * 表格盒：**固定表头 + 双向平移 + 双向裁剪**（2026-10-26，P2 Table 阶段 1）。
 *
 * ```
 * ┌──────────────────────────┐  ← 表头：不随竖向滚动，随横向平移
 * ├──────────────────────────┤
 * │ 行区（窗）：随两个方向平移  │
 * └──────────────────────────┘
 * ```
 *
 * ## 为什么必须是一个盒，而不是 `Column[表头, LazyColumn]`
 *
 * 三条，每条都足以否掉组合做法（完整论证见 `docs/DESIGN-TABLE-2026-10-26.md` §2）：
 *
 * 1. **表头与行区必须共享同一个 x 偏移**。两个独立滚动视口各持一份 x，迟早不同步
 *    （滚轮只喂最近的那个 ⇒ 一个轴永远滚不到，见 `ScrollInputRouter:80`：
 *    "嵌套滚动不做 delta 传递"）。
 * 2. **`LazyColumn` 嵌在滚动视口里被框架层明令禁止**（`RenderScrollView::performLayout()`
 *    沿父链找滚动视口，命中即 Fatal）：外层给它无界主轴 ⇒ 窗口永远只有一屏。
 * 3. 行虚拟化**不在本盒**（它在 `TableState::build()` 里算窗口），本盒只负责
 *    "固定表头 + 平移 + 裁剪 + 滚动条"。这条分工让本盒的职责小到可以一眼看完。
 *
 * ## 坐标链纪律（与 `RenderScrollView` 同一条）
 *
 * 偏移**进子盒的 `offsetX/offsetY`**，不是 paint 期的额外 `translate()`。否则
 * `hitTest()` 与 `Application::absoluteOrigin()` 拿到的坐标会比画出来的位置差
 * 一个滚动量 —— 表现为"滚动后点击错位"，只在滚过之后才出现。
 *
 * ## 两个裁剪区是**结构必需**，不是优化
 *
 * 表头区 `[0, headerH)` 与行区 `[headerH, H)` 各自裁剪。不裁行区的话，滚动到
 * 中间时第一行会画到表头**上面**（行区坐标上移到负数），而表头本该盖住它。
 * ⇒ 裁剪顺序也固定：先表头、再行区，最后滚动条（条永远在最上）。
 *
 * ## 诚实边界（阶段 1）
 *
 * - **横向通道在、但不驱动**：`offsetX` 参与夹取（`maxScrollX`），若内容比视口宽
 *   会被裁掉左侧对齐的部分。阶段 5 才接横向输入与横向条 —— 那时只需
 *   `TableState` 允许 `offsetX ≠ 0`，本盒一行不改。
 * - **行高定死**（`TableState` 给的是 `rowHeight × 行数`）：变长行高需要测量缓存
 *   与失效策略，与 `LazyColumn::itemExtent` 同源裁决。
 *
 * @internal 应用只认 `Table`
 */
final class RenderTable extends RenderBox implements ScrollBarHost
{
    private ?RenderBox $header = null;

    private ?RenderBox $body = null;

    /** 滚动条透明度（`0..1`）—— `ScrollBarFade` 每帧把当前值推过来 */
    private float $barOpacity;

    /** 纵向可滚上限（布局后才有意义） */
    private float $maxScroll = 0.0;

    /** 横向可滚上限（阶段 5 才驱动；阶段 1 恒 0 当且仅当内容不宽于视口） */
    private float $maxScrollX = 0.0;

    /** 本次布局生效的表头高（可能被视口夹短；paint / hitTest 都用它） */
    private float $headerBoxH = 0.0;

    /**
     * @param float $scrollX       横向偏移（阶段 1 恒 0；通道为阶段 5 留）
     * @param float $scrollY       纵向偏移（**行区**坐标，见 `TableState` 的 $bodyH 注释）
     * @param float $contentWidth  完整内容宽（= Σ列宽 + 间距，由 `TableState` 算好）
     * @param float $headerHeight  表头期望高
     * @param float $contentHeight 完整内容高（= 表头 + 行数 × 行高）
     * @param float $sliceOrigin   行区这扇窗的起点在**完整内容**里的 y
     * @param Color $paneInk       表格底（行区背景，斑马行画在它上面）
     * @param Color $headerInk     表头底
     * @param Color $borderInk     表头下方的 1px 分隔线
     * @param Color $thumb         滚动条色
     *
     * ★ 参数序：必选在前、可选在后（`04` §5.4：反过来 tpc 预处理器直接 Fatal）。
     */
    public function __construct(
        private readonly float $scrollX,
        private readonly float $scrollY,
        private readonly float $contentWidth,
        private readonly float $headerHeight,
        private readonly float $contentHeight,
        private readonly float $sliceOrigin,
        private readonly Color $paneInk,
        private readonly Color $headerInk,
        private readonly Color $borderInk,
        private readonly Color $thumb,
        float $barOpacity = 1.0,
    ) {
        $this->barOpacity = $barOpacity;
    }

    /** @internal `TableElement` 挂接期调（子盒由 Element 的事，不在 Widget 里挂） */
    public function setHeader(RenderBox $box): void
    {
        $this->header = $box;
        $this->adoptChild($box);
    }

    /** @internal 同上 */
    public function setBody(RenderBox $box): void
    {
        $this->body = $box;
        $this->adoptChild($box);
    }

    /** @internal 淡出动画每帧推值（不重建、不重排） */
    public function setBarOpacity(float $value): void
    {
        $this->barOpacity = $value;
    }

    /** 纵向可滚上限（布局后才有意义） */
    public function maxScroll(): float
    {
        return $this->maxScroll;
    }

    /** 横向可滚上限（阶段 5 起才非 0） */
    public function maxScrollX(): float
    {
        return $this->maxScrollX;
    }

    /** 实际生效的纵向偏移（已按 `maxScroll` 夹取） */
    public function appliedOffset(): float
    {
        return min(max(0.0, $this->scrollY), $this->maxScroll);
    }

    /** 实际生效的横向偏移（阶段 1 恒 0） */
    public function appliedOffsetX(): float
    {
        return min(max(0.0, $this->scrollX), $this->maxScrollX);
    }

    /**
     * ★ 表格的条**只画纵向那根**（阶段 1）。
     *
     * 横向溢出时内容会被裁掉而不是出现横条 —— 这与"还没接横向输入"一致：
     * 画一条拖不动的横条比不画更容易误导（`ScrollBarGeometry::thumbRect()`
     * 对"内容不溢出"的裁决也是同一条：画了就是撒谎）。
     */
    public function isVertical(): bool
    {
        return true;
    }

    public function thumbRect(): ?Rect
    {
        $size = $this->size;
        if ($size === null) {
            return null;
        }

        return ScrollBarGeometry::thumbRect($size, $this->maxScroll, $this->appliedOffset(), true);
    }

    /** @internal 事件命中用：thumb 在渲染树根坐标里的矩形 */
    public function thumbRectInRoot(): ?Rect
    {
        $bar = $this->thumbRect();
        if ($bar === null) {
            return null;
        }

        [$originX, $originY] = RenderBox::absoluteOrigin($this);

        return new Rect($originX + $bar->left, $originY + $bar->top, $bar->width, $bar->height);
    }

    public function thumbTravel(): float
    {
        $size = $this->size;
        if ($size === null) {
            return 0.0;
        }

        return ScrollBarGeometry::travel($size->height, $this->maxScroll);
    }

    /**
     * 把**行区内容坐标**里的矩形滚进可见行区所需的偏移**增量**（`0.0` = 已可见）。
     *
     * ★ 与 `RenderScrollView::revealDelta()` 同形同义（它负责一根轴上的普通视口，
     *   这里负责"表头钉住"的那种视口），差别只有一处：**可见高不是视口高，而是
     *   `视口高 − 表头高`** —— 表头占掉的那一截永远看不见。
     *
     * ★ 坐标系是**行区**（`y = 0` 是第 0 行顶部），不是整表 —— 与 `TableState`
     *   的 `$offsetY` 同一套坐标。表头高只在这里减一次。
     *
     * ★ 返回**增量**而不是绝对偏移：`scrollBy()` 是唯一写入口，夹取在它（下界）
     *   与布局期（上界）各做一次。返回绝对值就等于绕过写入口。
     *
     * ★ `$base` 是基准偏移（"现在按哪个偏移算可见"），**必填** —— State 侧传
     *   自己**待生效**的值（同 `RenderScrollView` 的理由：同一帧里连按两次键时
     *   盒还没重排）。不给默认值是为了避开 tpc 的"可选参数排在必选参数前" Fatal。
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

        // ★ 目标比可见区还高时只保证"上沿露出来"（幂等：连着调两次第二次返回 0，
        //   否则大目标会在两端来回跳 —— 同 RenderScrollView 的裁决）。
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

    public function visitChildren(callable $visitor): void
    {
        // 顺序 = 绘制顺序：先表头后行区（行区后画 ⇒ 滚动时压在表头下方的部分之上，
        // 但两份裁剪各管一段，所以这个顺序只影响"同区域叠放"时的视觉，不影响正确性）
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

        // 视口：取约束上限。上界无限 ⇒ 视口跟着内容走（不滚，同 RenderScrollView）
        $maxW = $constraints->maxWidth;
        $maxH = $constraints->maxHeight;
        $viewportW = \is_infinite($maxW) ? $this->contentWidth : max(0.0, $maxW);
        $viewportH = \is_infinite($maxH) ? $this->contentHeight : max(0.0, $maxH);

        // ① 表头：宽度**铺满视口**（fixed 列加起来不满时，表头底色仍要铺满，
        //    否则右侧露出一条"什么都没有"的缝）。高度取指定值与视口的较小者
        //    （窗口比表头还矮时不能把表头画到盒外）。
        $fullW = max($this->contentWidth, $viewportW);
        $hh = min($this->headerHeight, $viewportH);
        $this->headerBoxH = $hh;
        $header->layout(new BoxConstraints($fullW, $fullW, $hh, $hh));

        // ② 行区：宽定死成**内容宽**（不是视口宽 —— 行是内容，超出部分靠裁剪），
        //    高**无界**：窗里的行加起来本来就可能比可见行区高一截（最后一行的
        //    下半部分在视口外）。给有界高会让 `Column` 撞 C5 报"放不下" ——
        //    而那正是虚拟化的正常形态（同 `RenderScrollView` 给内容无界主轴）。
        $body->layout(new BoxConstraints(
            $this->contentWidth,
            $this->contentWidth,
            0.0,
            BoxConstraints::INFINITY,
        ));

        // ③ 夹取
        $this->maxScroll = max(0.0, $this->contentHeight - $viewportH);
        $this->maxScrollX = max(0.0, $this->contentWidth - $viewportW);
        $offsetY = $this->appliedOffset();
        $offsetX = $this->appliedOffsetX();

        // ④ 偏移进子盒 offset（不是 paint 期 translate）—— 见类注释
        //    表头只吃横向偏移（纵向永远贴顶）；行区吃两个方向，且要减去窗起点
        //    （`sliceOrigin` 是这扇窗在完整内容里的 y，同 RenderScrollView）。
        $header->setOffset(-$offsetX, 0.0);
        $body->setOffset(-$offsetX, $hh - ($offsetY - $this->sliceOrigin));

        return $constraints->constrain(new Size($viewportW, $viewportH));
    }

    /**
     * 底色 / 表头底 / 分隔线 —— 在**裁剪之外**、`paintContent()` 之前（`paintOwnChrome`）。
     *
     * ★ 为什么走这个钩子而不是在 `paintContent()` 里画：表头下面那条 1px 线跨在
     *   两个裁剪区的边界上，画在裁剪内会被裁掉一半（`AppBar` 的底边线踩过同款，
     *   2026-10-23 真机丢了整行像素）。
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
        // 分隔线画在表头**内侧**最后一行像素上（不占额外空间 ⇒ 行区起点不偏移）
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
            $canvas->save();
            $canvas->translate($header->offsetX(), $header->offsetY());
            $header->paintComposed($canvas);
            $canvas->restore();
            $canvas->popClip();
            $canvas->restore();
        }

        // ② 行区：裁 [hh, H)
        if ($body !== null && $size->height > $hh) {
            $canvas->save();
            $canvas->pushClip(new Rect(0.0, $hh, $size->width, $size->height - $hh));
            $canvas->save();
            $canvas->translate($body->offsetX(), $body->offsetY());
            $body->paintComposed($canvas);
            $canvas->restore();
            $canvas->popClip();
            $canvas->restore();
        }

        // ③ 滚动条：视口坐标（不在任何一份的平移里），裁在盒内
        $canvas->save();
        $canvas->pushClip(new Rect(0.0, 0.0, $size->width, $size->height));
        $this->paintScrollbar($canvas);
        $canvas->popClip();
        $canvas->restore();
    }

    /**
     * 命中：**分区递坐标**（表头区 / 行区），不是简单的"逆序问子盒"。
     *
     * ★ 必须覆写。基类会把点同时递给两个子盒，副作用有两个：
     *   ① 行区子盒的矩形覆盖整个表盒（它从 `-shift` 开始），焦点落在表头上时会
     *      先命中**行区里被表头遮住的那部分行**；
     *   ② 表头下方的 1px 线不参与命中，判定会往行区漏。
     *   分区之后，"看到哪里就能点哪里"是结构保证。
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

    /** 滚动条（纯视觉；几何在 `ScrollBarGeometry`，与 `RenderScrollView` 同一份） */
    private function paintScrollbar(Canvas $canvas): void
    {
        $bar = $this->thumbRect();
        if ($bar === null) {
            return;
        }

        $alpha = (int) \round($this->thumb->a * $this->barOpacity);
        if ($alpha <= 0) {
            return;
        }

        $canvas->drawRect(
            $bar,
            Paint::fill($this->thumb->withAlpha($alpha)),
            ScrollBarGeometry::THICKNESS * 0.5,
        );
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
     * ★ 溢出策略是 **Clip**：内容比视口宽（阶段 1 没有横滚）或行区超出时裁掉。
     *   声明它让基类在 `paint()` 里再裁一次盒矩形（双保险），也回答了 C5 的
     *   契约查询 —— 结构性"会超"的容器必须显式声明。
     */
    #[\Override]
    public function overflow(): Overflow
    {
        return Overflow::Clip;
    }
}
