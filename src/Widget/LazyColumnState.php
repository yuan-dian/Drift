<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Rect;
use Drift\Layout\RenderScrollView;

/**
 * `LazyColumn` 的 State：**持滚动偏移 + 决定这一帧造哪几行**。
 *
 * ★ 偏移放 `State` 而不是 Store：§12.3 的判据是"承载它的 Element 会不会被回收"。
 *   被回收的是**行**，本 State 挂在列表自己身上（滚动容器不随滚动消失）⇒ 放
 *   `State` 成立。反过来，**行内**的 UI 态会被回收，必须外置（`LazyColumn` 类注释）。
 *
 * ★★ **窗口在 build 期算，不在布局期算**：框架的 `build → settle → layout`
 *   顺序决定了布局期拿不到"还没造的 Widget"。于是视口高度取**上一帧**盒的
 *   实测高度（首帧还没有盒 ⇒ 用 `ctx->height`，即窗口高，它是任何嵌套视口的
 *   上界 ⇒ 首帧一定造多不少）。⇒ 窗口永远是可见范围的**超集**，不会白。
 *
 * ★ **不夹上界就不对**：`scrollBy()` 只夹下界（见 `Scrollable`），上界得在这里
 *   按"完整内容长度 − 视口"夹一次 —— 否则内容变短（过滤后）时窗口会算到列表
 *   之外，造出一片空白。
 *
 * @internal 应用只认 `LazyColumn`
 */
final class LazyColumnState extends State implements Scrollable
{
    private float $offset = 0.0;

    /** 当前偏移（逻辑像素，≥ 0，已按上一帧的内容长度夹过上界） */
    public function offset(): float
    {
        return $this->offset;
    }

    public function scrollBy(float $delta): void
    {
        $next = max(0.0, $this->offset + $delta);

        if ($next === $this->offset) {
            return;   // 已在边界：不标脏（省一次重建）
        }

        $this->setState(function () use ($next): void {
            $this->offset = $next;
        });
    }

    /**
     * 把内容坐标里的矩形滚进视口（`04` §6.3）。
     *
     * ★ 这里的"内容坐标"是**完整内容**的坐标（含 `insets`、含所有没造出来的
     *   行）—— 与 `$offset` 同一套坐标，所以换算不需要再补 `sliceOrigin`
     *   （窗的起点只是绘制期的平移量，不进内容坐标）。
     *
     * ★ 形状与 `ScrollViewState::ensureVisible()` 逐字同构：算术都在
     *   `RenderScrollView::revealDelta()`，本方法只"找盒 + 转交"。
     */
    public function ensureVisible(Rect $target, float $margin = 8.0): void
    {
        $box = $this->element()?->effectiveBox();
        if (!$box instanceof RenderScrollView) {
            return;
        }

        // ★ 基准传**自己待生效的偏移**（同 `ScrollViewState`，见 `revealDelta()`
        //   的 `$base` 说明）。
        $delta = $box->revealDelta($target, $margin, $this->offset);
        if ($delta !== 0.0) {
            $this->scrollBy($delta);
        }
    }

    public function build(BuildContext $ctx): Widget
    {
        $view = $this->view();
        $items = $view->itemsList();
        $count = count($items);
        $extent = $view->itemExtent();
        $insets = $view->insets();

        // 完整内容的主轴长度（含上下内边距）—— 滚动条与可滚上限都按它算
        $total = $insets->top + $count * $extent + $insets->bottom;

        $viewport = $this->viewportEstimate($ctx);
        $offset = min(max(0.0, $this->offset), max(0.0, $total - $viewport));

        // 窗口：完整内容坐标 [offset, offset+viewport) 换算成行号区间
        $overscan = $view->overscanPx();
        $first = (int) floor(max(0.0, $offset - $overscan - $insets->top) / $extent);
        $last = (int) ceil(max(0.0, $offset + $viewport + $overscan - $insets->top) / $extent);
        $first = max(0, min($first, $count));
        $last = max($first, min($last, $count));

        $rows = [];
        for ($i = $first; $i < $last; $i++) {
            // ★ key = **绝对行号**（不是窗内序号）：窗口滑动一格时，第 k 行仍是
            //   第 k 行 ⇒ diff 只增删两端各一个 Element，中间全部复用；用窗内
            //   序号做 key 则整窗 key 一起变 ⇒ 每滚一格重建全部行（State 全丢）。
            $rows[] = SizedBox::make($view->buildItem($items[$i]))
                ->height($extent)
                ->key($i);
        }

        $kid = Column::make()->children($rows);
        if ($insets->left > 0.0 || $insets->right > 0.0) {
            $kid = Padding::make($kid)->padding(
                EdgeInsets::only($insets->left, 0.0, $insets->right, 0.0)
            );
        }

        // 窗的起点 = 完整内容里"第 first 行"的主轴坐标
        return ScrollViewBox::make($offset, true, $kid, $total, $insets->top + $first * $extent);
    }

    /**
     * 视口主轴高度的估计。
     *
     * ★ 优先取**上一帧**盒的实测高度（精确）；首帧没有盒 ⇒ 退回窗口高
     *   （上界：任何嵌套视口都不会比窗口更高）。**不读环境时钟、不问平台**
     *   （G3：帧的输入只有约束与树）。
     */
    private function viewportEstimate(BuildContext $ctx): float
    {
        $box = $this->element()?->effectiveBox();
        if ($box instanceof RenderScrollView) {
            $size = $box->size();
            if ($size !== null && $size->height > 0.0) {
                $viewport = $size->height;

                // ★ `04` §5.5 的断言：这里是**帧间状态依赖**（读上一帧的盒
                //   尺寸，不在 performLayout 里 ⇒ 不违反布局纯函数，但同类隐患）。
                //   超过 2 倍窗口高 ⇒ 上一帧的盒尺寸是错的（如窗口刚从小变大）。
                //   逻辑不动（三条理由见 `04` §5.5），只加这条上界。
                if ($ctx->height > 0.0 && $viewport > $ctx->height * 2.0) {
                    \Drift\Errors::report(sprintf(
                        'LazyColumn 视口估计 %s 超过窗口高 %s 的 2 倍 —— 上一帧的盒尺寸可疑。'
                            . "\n" . '修法：确认窗口尺寸变化后盒已重排；必要时首帧用 ctx->height 兜底。',
                        (string) $viewport,
                        (string) $ctx->height,
                    ), \Drift\Errors::DIAGNOSTIC);
                }

                return $viewport;
            }
        }

        return max(0.0, $ctx->height);
    }

    private function view(): LazyColumn
    {
        $widget = $this->widget();
        if (!$widget instanceof LazyColumn) {
            // 框架不变量：StatefulElement 按 get_class 配对，配错不可能发生
            Errors::report('LazyColumnState 只能配 LazyColumn（框架内部不变量被破坏）');
        }

        return $widget;
    }
}
