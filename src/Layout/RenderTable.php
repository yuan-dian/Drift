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
 * ## 双轴与两根条（阶段 5，2026-10-27）
 *
 * 横向在阶段 1 只留了通道（`scrollX` 进夹取、但 State 恒传 0）。阶段 5 接通之后
 * 本盒的"双向"才完整：表头吃 x 偏移、行区吃两个方向、两根条各自按溢出出现。
 *
 * ★ 阶段 1 的原判词是"只需 `TableState` 允许 `offsetX ≠ 0`，本盒一行不改"——
 *   **对了一半**：布局与绘制确实一行不改，但**滚动条的接口**当时是单轴的
 *   （`isVertical()` 定轴、`thumbRect()` 无参），一个盒表达不了"第二根条"。
 *   那条假设被 `ScrollBarHost` 的轴化推翻，是本阶段唯一的破坏性改动。
 *
 * ## 诚实边界
 *
 * - **行高定死**（`TableState` 给的是 `rowHeight × 行数`）：变长行高需要测量缓存
 *   与失效策略，与 `LazyColumn::itemExtent` 同源裁决。
 *
 * @internal 应用只认 `Table`
 */
final class RenderTable extends RenderBox implements ScrollBarHost
{
    /** 光标格描边的厚度（内描边，不入布局） */
    public const FOCUS_RING_W = 2.0;

    private ?RenderBox $header = null;

    private ?RenderBox $body = null;

    /** 滚动条透明度（`0..1`）—— `ScrollBarFade` 每帧把当前值推过来 */
    private float $barOpacity;

    /**
     * 两轴偏移（逻辑像素）—— `setScrollOffset()` 就地改。
     *
     * ★ 不 readonly 的理由与 `RenderScrollView::$scrollOffset` 同源：滚一格不该
     *   重建整棵行区（实测滚动帧里 `settle` 占 55%）。State 仍是权威值，
     *   盒是它的投影；换盒时新偏移由构造参数递进来。
     */
    private float $scrollX;

    private float $scrollY;

    /** 纵向可滚上限（布局后才有意义） */
    private float $maxScroll = 0.0;

    /** 横向可滚上限（内容宽 > 视口宽时非 0，阶段 5 起真的会出横条） */
    private float $maxScrollX = 0.0;

    /** 本次布局生效的表头高（可能被视口夹短；paint / hitTest 都用它） */
    private float $headerBoxH = 0.0;

    /**
     * 光标格（**行区内容坐标**；`null` = 不画）。
     *
     * ★ 由 `TableState` 算好递进来，本盒不做"第几列在哪 x"的算术 —— 列宽只有
     *   `resolveWidths()` 一处真相，在盒里再算一遍就是第二份（阶段 4 的
     *   "表头比行多 n−1 像素"正是这么来的）。
     */
    private ?Rect $focusRect;

    private Color $focusInk;

    /**
     * @param float $scrollX       横向偏移（**视口**坐标：表头与行区共享，见 `TableState`）
     * @param float $scrollY       纵向偏移（**行区**坐标，见 `TableState` 的 $bodyH 注释）
     * @param float $contentWidth  完整内容宽（= Σ列宽 + 间距，由 `TableState` 算好）
     * @param float $headerHeight  表头期望高
     * @param float $contentHeight 完整内容高（= 表头 + 行数 × 行高）
     * @param float $sliceOrigin   行区这扇窗的起点在**完整内容**里的 y
     * @param Color $paneInk       表格底（行区背景，斑马行画在它上面）
     * @param Color $headerInk     表头底
     * @param Color $borderInk     表头下方的 1px 分隔线
     * @param Color $thumb         滚动条色
     * @param Color $focusInk      光标格描边色
     * @param ?Rect $focusRect     光标格（行区内容坐标；`null` = 失焦或没布局）
     *
     * ★ 参数序：必选在前、可选在后（`04` §5.4：反过来 tpc 预处理器直接 Fatal）。
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
    ) {
        $this->scrollX = $scrollX;
        $this->scrollY = $scrollY;
        $this->barOpacity = $barOpacity;
        $this->focusInk = $focusInk;
        $this->focusRect = $focusRect;
    }

    /**
     * 就地改某一轴的偏移（见 `ScrollBarHost::setScrollOffset()`）。
     *
     * ★ 表格是**双轴**盒，所以这里按轴分派；单轴视口的同形方法在
     *   `RenderScrollView` 上。两个实现都只做"改字段 + 应用 + 回夹取值"。
     * ★★ 还没排过 ⇒ **不夹、不应用**（同 `RenderScrollView` 的理由：
     *   `maxScroll` 恒 0 时夹一下会把首帧之前设的偏移吞掉，且不报错）。
     * ★★ **不标重排**（2026-10-27 第二版）：偏移只改子盒 `offset`（表头吃横向、
     *   行区吃两向），不改任何盒的 `size` ⇒ 由 `applyScrollOffsets()` 就地应用。
     *   早先借 `markNeedsLayout()` 让偏移生效，代价是每滚一格 `Frame` 重排
     *   **整棵树**；paint 读的就是子盒 offset ⇒ 不重排也不会"画面停在旧位置"。
     * ★★ **重绘由 `setOffset()` 自己标**（2026-10-30，N1：paint 不再是无条件跑）。
     *   这条路径因此**不需要**额外汇总 —— 也正因为如此，"滚动还画不画"必须由
     *   测试守（`ScrollTest` 里那条"滚动帧必须真画"）。
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
     * 把两轴偏移应用到子盒 `offset`（**布局末尾与滚动期共用**）。
     *
     * ★★ 抽出来的理由与 `RenderScrollView::applyScrollOffsets()` 同源：
     *   这段原先只住 `performLayout()` 末尾 ⇒ "让偏移生效"与"重排整棵树"
     *   被绑成一件事。它只依赖上一次布局留下的 `maxScroll*` /
     *   `headerBoxH` / `sliceOrigin` ⇒ 可以在布局之外单独调。
     * ★ 表头只吃横向（纵向永远贴顶）；行区吃两向，且要减去窗起点
     *   （`sliceOrigin` 是这扇窗在完整内容里的 y，同 `RenderScrollView`）。
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
     * 行区**可见高**（= 视口高 − 表头高，上一次布局的结果）。
     *
     * ★ 存在的理由：`TableState` 要在**不重建**的前提下算"该建哪几行"，
     *   而它拿不到 `BuildContext`（`viewportEstimate()` 要 ctx 的度量）。
     *   盒有 `size()` 与 `headerBoxH`，是唯一在滚动期可靠的口径。
     *   ⇒ 窗口判断与布局同源，不会出现"State 以为可见 20 行、盒只给 19 行高"。
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
     * 表头占掉的高度 —— 行区内容坐标的原点（`y = 0` 是第 0 行顶部）。
     *
     * ★ 指针要反算"落在第几行"必须减它（`TableState::pointerRowAt()`）；让它与
     *   `bodyHeight()` 共用同一个 `$headerBoxH`，而不是让 State 用 build 期估的
     *   `headerHeightPx()` —— 后者与真实布局不是同一口径（表头可能被约束压过）。
     */
    public function headerHeight(): float
    {
        return $this->headerBoxH;
    }

    /** @internal `TableElement` 挂接期调（子盒由 Element 的事，不在 Widget 里挂） */
    public function setHeader(RenderBox $box): void
    {
        $this->header = $box;
        $this->adoptChild($box);
        // ★ 表头**不开**边界：它只有几个单元格，命令极少 ⇒ 重放的固定开销
        //   ≈ 省下的 `paint()`，bench 实测落在噪声里（1.744/1.849/1.739 三跑，
        //   与只开行区时无法区分）。边界只给**粗粒度**子树（行区 / 滚动容器内容）。
    }

    /** @internal 同上 */
    public function setBody(RenderBox $box): void
    {
        $this->body = $box;
        $this->adoptChild($box);

        // 行区是天然的重绘边界：偏移由**本类的 `translate`** 施加（见类注释），
        // 不在 body 的 `paint()` 里 ⇒ 缓存与偏移无关 ⇒ 缓冲带内滚动只重放。
        $box->setRepaintBoundary(true);
    }

    /** @internal 淡出动画每帧推值（不重建、不重排；**但必须标重绘**） */
    public function setBarOpacity(float $value): void
    {
        if ($this->barOpacity === $value) {
            return;
        }

        $this->barOpacity = $value;
        $this->markPaintChanged();   // 同 `RenderScrollView::setBarOpacity()`（N1）
    }

    /**
     * @internal 光标格（行区内容坐标；`null` = 不画）
     *
     * ★ 给断言与将来的命中用。**不**在内部二次换算成视口坐标：调用方多半是
     *   "格子在不在视口里"这类判断，内容坐标才是稳定参照（滚动不动它）。
     */
    public function focusRect(): ?Rect
    {
        return $this->focusRect;
    }

    /** @internal 见 `ScrollBarHost` —— 表格是双轴盒，两根条都可能出现 */
    public function scrollAxes(): array
    {
        // 顺序 = **绘制**顺序：先横后竖 ⇒ 竖条压在右下角上（与浏览器同观感）。
        // 命中侧按反序问（后画的在上层），于是角落那 6×6 归竖条 ——
        // 拖竖条不会把表横向拽走（见 `ScrollInputRouter::scrollThumbAt()`）。
        return [Axis::Horizontal, Axis::Vertical];
    }

    /** 该轴的可滚上限（布局后才有意义） */
    public function maxScroll(Axis $axis): float
    {
        return $axis === Axis::Vertical ? $this->maxScroll : $this->maxScrollX;
    }

    /** 该轴实际生效的偏移（已按 `maxScroll` 夹取） */
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
     * ★ 表格的条**可以有两根**（阶段 5）：内容宽溢出 ⇒ 底部横条，行数溢出 ⇒
     *   右缘竖条，两者独立出现。谁不溢出谁就没有条（`ScrollBarGeometry` 的
     *   `thumbRect()` 对"不溢出"的裁决同一条：画了就是撒谎）。
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

    /**
     * 横向的 `revealDelta()`（阶段 2b：光标格向右移时把它带进视口）。
     *
     * ★ 是**镜像**不是复用：纵向的视口是行区（要减表头高），横向的视口是整宽
     *   （表头与行区共享 x）。把两者硬塞进一个函数，就得再多一个"要不要减表头"
     *   的布尔参数 —— 那正是 `ScrollBarHost::isVertical()` 被拆掉的形状。
     */
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

        // ④ 偏移进子盒 offset（不是 paint 期 translate）—— 见类注释
        // ★★ 抽成 `applyScrollOffsets()`（2026-10-27）：滚动期也要用同一段，
        //   而那时**不该重排**（见 `setScrollOffset()`）。
        $this->applyScrollOffsets();

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

            // ★ 光标框画在**行区内容之上**，且回到视口坐标（`translate` 之外）：
            //   内容坐标 → 屏幕 = 减偏移、加表头高。若跟着 translate 画，还得再补
            //   `sliceOrigin`（这扇窗的起点），那是第二处会算错的地方。
            $this->paintFocusRing($canvas, $hh, $this->appliedOffsetX(), $this->appliedOffsetY());

            $canvas->popClip();
            $canvas->restore();
        }

        // ③ 滚动条：视口坐标（不在任何一份的平移里），裁在盒内。
        //    ★ 按 `scrollAxes()` 的顺序画（先横后竖）—— 与命中判定同一个顺序，
        //      所以"看到压在角上的是竖条，抓到的也是竖条"。
        $canvas->save();
        $canvas->pushClip(new Rect(0.0, 0.0, $size->width, $size->height));
        foreach ($this->scrollAxes() as $axis) {
            $this->paintScrollbar($canvas, $axis);
        }
        $canvas->popClip();
        $canvas->restore();
    }

    /**
     * 光标格的 2px **内**描边（阶段 2b）。
     *
     * ★ 四条实心矩形而不是 `Paint::stroke()`：stroke 以路径为中心向两侧各画一半
     *   ⇒ 框会向相邻格溢出 1px，"内描边"就名不副实。四条 fill 全落在格子矩形
     *   内部，像素位置可控（smoke 按像素断言它，靠的就是这点）。
     * ★ 不走 `Modifier::border()`：那条路是"给盒加装饰"，而光标格不是一个盒
     *   （cell 里可能是按钮 / 任意 Widget），而且描边必须压在**行背景之上**。
     */
    private function paintFocusRing(Canvas $canvas, float $bodyTop, float $offsetX, float $offsetY): void
    {
        $rect = $this->focusRect;
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

        // 格子比描边还窄时按半宽收敛（否则左右两条会叠成一片实心，看起来像选中）
        $t = min(self::FOCUS_RING_W, $w / 2.0, $h / 2.0);
        $paint = Paint::fill($this->focusInk);

        $canvas->drawRect(new Rect($x, $y, $w, $t), $paint);
        $canvas->drawRect(new Rect($x, $y + $h - $t, $w, $t), $paint);
        $canvas->drawRect(new Rect($x, $y + $t, $t, $h - $t * 2.0), $paint);
        $canvas->drawRect(new Rect($x + $w - $t, $y + $t, $t, $h - $t * 2.0), $paint);
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

        // ★ 与基类同一条（`RenderBox::hitTest()` 的禁用段）：表格覆写了命中，
        //   禁用的表格得在这里也挡住，否则"表禁用但还能点行"—— 覆写点漏一处
        //   就是这种半禁用的怪状态。★ 返回 `$this` 而不是 `null`（返回 null 等于
        //   让上层继续问下一个兄弟 = 穿透）。
        if ($this->isDisabled()) {
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

    /** 滚动条（纯视觉；几何在 `ScrollBarGeometry`，与 `RenderScrollView` 同一份） */
    private function paintScrollbar(Canvas $canvas, Axis $axis): void
    {
        $bar = $this->thumbRect($axis);
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
     * ★ 溢出策略是 **Clip**：内容比视口宽（横滚到两端时）/ 行区超出时裁掉。
     *   声明它让基类在 `paint()` 里再裁一次盒矩形（双保险），也回答了 C5 的
     *   契约查询 —— 结构性"会超"的容器必须显式声明。
     */
    #[\Override]
    public function overflow(): Overflow
    {
        return Overflow::Clip;
    }
}
