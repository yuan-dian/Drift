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

    /**
     * 锚点（`04` §7.3）：视口顶部那一行在**完整内容**里的位置 —— 行号 + 行内
     * 滚过的比例（`0..1`，保留"没对齐到行"的那点偏移）。
     *
     * ★ `anchorKey` 是这一行的**身份**（`LazyColumn::keyOf()` 给的）；没配
     *   `keyOf` 时恒 `null` ⇒ 内容变短只能按行号夹。
     */
    private int $anchorIndex = 0;
    private float $anchorFrac = 0.0;
    private ?string $anchorKey = null;

    /** 上一次见到的行数（没配 `keyOf` 时用它判断"内容变了没有"） */
    private int $lastCount = -1;

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
            $this->rememberAnchor();
        });
    }

    /**
     * 内容变了 ⇒ 按锚点还原偏移（`04` §7.3）。
     *
     * ★ **每次父重建都会走到这里**（应用 `setState()` 一次就重建一次），所以第一
     *   件事是判断"内容到底变没变"：配了 `keyOf` 就看**锚点那一行的 key 还在不在
     *   原位**（O(1)），没配就看行数。判不出来就什么都不做 —— 否则每帧把偏移重算
     *   一遍，浮点误差会慢慢把滚动位置磨掉。
     *
     * ★ **按 key 不按行号**：搜索把 10000 行筛成 30 行时，锚点行 500 不存在了 ——
     *   按行号夹会跳到列表末尾（比跳回顶部更糟）。找不到 key ⇒ 回顶部。
     *
     * ★ 这里的 `O(n)` 只跑一次、且**只在内容变时**：10000 行线性扫描 ≈ 0.1ms，
     *   而内容变本身已经是一次全量数据替换。
     */
    #[\Override]
    public function didUpdateWidget(Widget $widget): void
    {
        parent::didUpdateWidget($widget);

        if (!$widget instanceof LazyColumn) {
            return;
        }

        $items = $widget->itemsList();
        $count = count($items);
        $extent = $widget->itemExtent();
        $insets = $widget->insets();
        $insetTop = $insets->top;
        $changed = $count !== $this->lastCount;
        $this->lastCount = $count;

        // ★ 从没滚过（或正好在顶部）⇒ 锚点就是"顶部"，没有什么要还原。
        //   这条早退还顺手挡掉了"上内边距"：没滚时 offset 是 0，而还原会把
        //   offset 算成 insetTop ⇒ 一帧白跳。
        if ($this->offset <= 0.0) {
            return;
        }

        $keyOf = $widget->keyMapper();

        if ($keyOf === null) {
            if (!$changed) {
                return;   // 行数没变 ⇒ 按行号的锚点本来就在原位
            }

            // 无 keyOf ⇒ 按行号夹（行为可预测，允许跳）
            $index = min($this->anchorIndex, max(0, $count - 1));
            $this->anchorIndex = $index;
            $this->offset = $insetTop + ($index + $this->anchorFrac) * $extent;

            return;
        }

        // ★ O(1) 判据：锚点那一行的 key 还在原位 ⇒ 内容没动到锚点
        $here = $this->anchorIndex < $count ? (string) $keyOf($items[$this->anchorIndex]) : null;
        if ($here !== null && $here === $this->anchorKey) {
            return;
        }

        $found = -1;
        if ($this->anchorKey !== null) {
            for ($i = 0; $i < $count; $i++) {
                if ((string) $keyOf($items[$i]) === $this->anchorKey) {
                    $found = $i;

                    break;
                }
            }
        }

        if ($found < 0) {
            // 锚点行被筛掉了 ⇒ 回顶部（不夹到末尾：那比回顶部更糟）
            $this->offset = 0.0;
            $this->anchorIndex = 0;
            $this->anchorFrac = 0.0;
            $this->anchorKey = null;

            return;
        }

        $this->anchorIndex = $found;
        $this->offset = $insetTop + ($found + $this->anchorFrac) * $extent;
    }

    /**
     * 记下"视口顶部是哪一行的哪个位置"（`04` §7.3）。
     *
     * ★ 只在**偏移真的变了**时调（`scrollBy()` 里）—— 内容变时的还原走
     *   `didUpdateWidget()`，两者方向相反，混在一起会互相覆盖。
     *
     * ★ 上内边距要从偏移里扣掉：行从 `insets->top` 开始，`offset = 0` 时
     *   视口顶部是**内边距**而不是第 0 行。
     */
    private function rememberAnchor(): void
    {
        $view = $this->view();
        $extent = $view->itemExtent();
        if ($extent <= 0.0) {
            return;
        }

        $insets = $view->insets();
        $main = max(0.0, $this->offset - $insets->top);
        $index = (int) floor($main / $extent);

        $this->anchorIndex = $index;
        $this->anchorFrac = ($main - $index * $extent) / $extent;

        $keyOf = $view->keyMapper();
        if ($keyOf === null) {
            $this->anchorKey = null;

            return;
        }

        $items = $view->itemsList();
        $this->anchorKey = isset($items[$index]) ? (string) $keyOf($items[$index]) : null;
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
