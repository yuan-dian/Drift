<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\Axis;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Rect;
use Drift\Layout\RenderScrollView;

/**
 * `LazyList` 的 State：**持滚动偏移 + 决定这一帧造哪几格**（两个轴共用一份）。
 *
 * ★ **轴只有一个局部变量**：每处随轴而变的量都写成
 *   `$vertical ? <纵向量> : <横向量>`（主轴起点 / 主轴尺寸 / 视口主轴 / 子层容器）。
 *   于是"横向少改一处"这类缺陷在结构上很难发生 —— 全部 8 个随轴点都在本文件里，
 *   而它们各自的对照组（纵向）就在隔壁那半行。
 *
 * ★ 偏移放 `State` 而不是 Store：§12.3 的判据是"承载它的 Element 会不会被回收"。
 *   被回收的是**格子**，本 State 挂在列表自己身上（滚动容器不随滚动消失）⇒ 放
 *   `State` 成立。反过来，**格子内**的 UI 态会被回收，必须外置（`LazyList` 类注释）。
 *
 * ★★ **窗口在 build 期算，不在布局期算**：框架的 `build → settle → layout`
 *   顺序决定了布局期拿不到"还没造的 Widget"。于是视口主轴长度取**上一帧**盒的
 *   实测值（首帧还没有盒 ⇒ 用 `ctx->height` / `ctx->width`，即窗口尺寸，它是任何
 *   嵌套视口的上界 ⇒ 首帧一定造多不少）。⇒ 窗口永远是可见范围的**超集**，不会白。
 *
 * ★ **不夹上界就不对**：`scrollBy()` 只夹下界（见 `Scrollable`），上界得在这里
 *   按"完整内容长度 − 视口"夹一次 —— 否则内容变短（过滤后）时窗口会算到列表
 *   之外，造出一片空白。
 *
 * @internal 应用只认 `LazyColumn` / `LazyRow`
 */
final class LazyListState extends State implements Scrollable
{
    private float $offset = 0.0;

    /** 滚动条淡出节律（懒建；与 `ScrollViewState` 同一份实现） */
    private ?ScrollBarFade $barFade = null;

    /** 滚轮的动画节律（懒建；与 `ScrollViewState` / `TableState` 同一份实现） */
    private ?ScrollMotion $motion = null;

    /**
     * 锚点（`04` §7.3）：视口起点那一格在**完整内容**里的位置 —— 序号 + 格内
     * 滚过的比例（`0..1`，保留"没对齐到格"的那点偏移）。
     *
     * ★ `anchorKey` 是这一格的**身份**（`LazyList::keyOf()` 给的）；没配
     *   `keyOf` 时恒 `null` ⇒ 内容变短只能按序号夹。
     */
    private int $anchorIndex = 0;
    private float $anchorFrac = 0.0;
    private ?string $anchorKey = null;

    /** 上一次见到的项数（没配 `keyOf` 时用它判断"内容变了没有"） */
    private int $lastCount = -1;

    /**
     * 上一次 `build()` 造出来的窗口在**完整内容坐标**里的范围 `[start, end)`。
     *
     * ★ 存在的理由（2026-10-27，A 批第 1 项）：滚动不再 `setState()`，于是"窗还
     *   盖不盖得住视口"必须**在 build 之外**能判 —— 盖得住就只推偏移（0 重建），
     *   盖不住才标脏（重建窗口）。没有这两个数，滚出缓冲带之后下方就是一片空白
     *   （且不报错：盒照样画，只是没内容）。
     */
    private float $windowStart = 0.0;

    private float $windowEnd = 0.0;

    /**
     * 播种初始偏移（`LazyList::initialOffset()`）。
     *
     * ★ **必须同时播种锚点**：`didUpdateWidget()` 用"锚点那一格的 key 还在原位"判
     *   内容有没有动，锚点停留在初始值（序号 0、key `null`）时，只要 offset > 0
     *   就会被判成"锚点丢了" ⇒ 第二次重建直接跳回顶部。播种走的是与滚动同一条
     *   `rememberAnchor()`，两处口径不会分叉。
     */
    #[\Override]
    public function initState(): void
    {
        $px = $this->view()->initialOffsetPx();

        if ($px <= 0.0) {
            return;
        }

        $this->offset = $px;
        $this->rememberAnchor();
    }

    /** 当前偏移（逻辑像素，≥ 0，已按上一帧的内容长度夹过上界） */
    public function offset(): float
    {
        return $this->offset;
    }

    /**
     * 滚动 Δ（**逻辑像素**；正 = 内容上移，即"往下看"）。
     *
     * ★★ **能不重建就不重建**（2026-10-27，A 批第 1 项）：偏移只改两个数，
     *   不改"有哪些子"。窗口仍盖得住视口 ⇒ 推给盒（就地改 + 标重排）即可；
     *   只有滚出缓冲带（`overscanPx()` 决定的余量）才标脏重建。
     *   实测依据见 `ScrollViewState::scrollBy()` 的注释。
     *
     * ★ 回写**盒夹过的值**（不是 `$next`）：内容变短时 State 里那个"增量累加值"
     *   会长期大于上限，之后每笔增量都先消耗死区 —— 真机表现是"滚轮转了画面不动"。
     */
    public function scrollBy(float $delta): void
    {
        $next = max(0.0, $this->offset + $delta);

        if ($next === $this->offset) {
            return;   // 已在边界：不推、不重排（省一帧）
        }

        $this->bar()->noteScroll();

        /** @var RenderScrollView|null $box */
        $box = $this->element()?->effectiveBoxOf(RenderScrollView::class);
        if ($box === null) {
            $this->setState(function () use ($next): void {
                $this->offset = $next;
                $this->rememberAnchor();
            });

            return;
        }

        $vertical = $this->view()->isVertical();
        $this->offset = $box->setScrollOffset($vertical ? Axis::Vertical : Axis::Horizontal, $next);
        $this->rememberAnchor();

        // 窗口盖不住视口 ⇒ 必须重建（否则下方/上方是空白）
        $size = $box->size();
        $viewport = $size === null ? 0.0 : ($vertical ? $size->height : $size->width);
        if ($viewport <= 0.0 || !$this->windowCovers($viewport)) {
            $this->markDirty();
        }
    }

    /**
     * 上一帧造的窗口是否**完整盖住**当前视口 `[offset, offset+viewport)`。
     *
     * ★ 用 `offset`（盒已夹过）而不是调用方传的 `$next`：滚到边界时盒会把值夹回来，
     *   按未夹的值判会以为"还没滚到" ⇒ 多标一次脏。
     */
    private function windowCovers(float $viewport): bool
    {
        return $this->offset >= $this->windowStart && $this->offset + $viewport <= $this->windowEnd;
    }

    /**
     * 按轴滚动：**只有主轴接受**（`LazyRow` 的主轴是 x）。
     *
     * ★ 主轴来自 Widget（`LazyList::isVertical()`），不由调用方猜 —— 与
     *   `ScrollViewState` 同一个形态。
     */
    public function scrollByAxis(Axis $axis, float $delta): void
    {
        if (($axis === Axis::Vertical) !== $this->view()->isVertical()) {
            return;
        }

        $this->scrollBy($delta);
    }

    /**
     * 按轴滚动，**带动画**（滚轮专用；细节见 `Scrollable::glideByAxis()`）。
     *
     * ★ 与 `ScrollViewState` 逐字同构 —— 三份实现共用 `ScrollMotion`，
     *   只有"轴怎么判"不同（这里是 `isVertical()`，那边是 `vertical()`）。
     */
    public function glideByAxis(Axis $axis, float $delta): void
    {
        if (($axis === Axis::Vertical) !== $this->view()->isVertical()) {
            return;
        }

        $motion = $this->motion();
        if (!$motion->isAnimating()) {
            $motion->snapTo($this->offset);
        }
        $motion->glideBy($delta);

        $this->bar()->noteScroll();
    }

    /** 滚动的动画节律（懒建：没被滚轮滚过的列表不建） */
    private function motion(): ScrollMotion
    {
        $motion = $this->motion;
        if ($motion === null) {
            $motion = new ScrollMotion($this->offset);
            $this->motion = $motion;
        }

        return $motion;
    }

    /** 滚动条的显隐节律（懒建：没滚过的列表不建，滚动条恒亮） */
    private function bar(): ScrollBarFade
    {
        $bar = $this->barFade;
        if ($bar === null) {
            $bar = new ScrollBarFade();
            $this->barFade = $bar;
        }

        return $bar;
    }

    /**
     * 推进这一帧的**滚动动画**与**滚动条淡出**（先滚动、后淡出，见 `ScrollViewState`）。
     *
     * ★ 这里的 `scrollBy()` 会顺带做 `rememberAnchor()` 与"窗口盖不住就标脏"，
     *   所以动画期间每帧都走一次换窗判断 —— 那是**必要**的：一步 5px 也可能
     *   跨过缓冲带边界，跳过它就会出现"滑过去时下方露白"。
     */
    #[\Override]
    public function tickFrame(float $timestampMs): bool
    {
        $busy = false;

        $motion = $this->motion;
        if ($motion !== null) {
            $next = $motion->tick($timestampMs);
            if ($next !== null) {
                $before = $this->offset;
                $this->scrollBy($next - $before);

                // 推不动了（到边界 / 内容变短被夹）⇒ 就地收口；理由与实测帧数见
                // `ScrollViewState::tickFrame()`（三份实现同一契约，此处不重复展开）。
                if ($this->offset === $before) {
                    $motion->snapTo($before);
                }
                $busy = true;
            }
        }

        $bar = $this->barFade;
        if ($bar !== null && $bar->tickFor($this, $timestampMs)) {
            $busy = true;
        }

        return $busy;
    }

    /**
     * 内容变了 ⇒ 按锚点还原偏移（`04` §7.3）。
     *
     * ★ **每次父重建都会走到这里**（应用 `setState()` 一次就重建一次），所以第一
     *   件事是判断"内容到底变没变"：配了 `keyOf` 就看**锚点那一格的 key 还在不在
     *   原位**（O(1)），没配就看项数。判不出来就什么都不做 —— 否则每帧把偏移重算
     *   一遍，浮点误差会慢慢把滚动位置磨掉。
     *
     * ★ **按 key 不按序号**：搜索把 10000 项筛成 30 项时，锚点项 500 不存在了 ——
     *   按序号夹会跳到列表末尾（比跳回顶部更糟）。找不到 key ⇒ 回顶部。
     *
     * ★ 这里的 `O(n)` 只跑一次、且**只在内容变时**：10000 项线性扫描 ≈ 0.1ms，
     *   而内容变本身已经是一次全量数据替换。
     */
    #[\Override]
    public function didUpdateWidget(Widget $widget): void
    {
        parent::didUpdateWidget($widget);

        if (!$widget instanceof LazyList) {
            return;
        }

        $items = $widget->itemsList();
        $count = count($items);
        $extent = $widget->itemExtent();
        $insets = $widget->insets();
        $vertical = $widget->isVertical();
        // ★ 主轴起始内边距：纵向是上、横向是左（内容从哪儿开始）
        $insetStart = $vertical ? $insets->top : $insets->left;
        $changed = $count !== $this->lastCount;
        $this->lastCount = $count;

        // ★ 从没滚过（或正好在起点）⇒ 锚点就是"起点"，没有什么要还原。
        //   这条早退还顺手挡掉了"主轴起始内边距"：没滚时 offset 是 0，而还原会把
        //   offset 算成 insetStart ⇒ 一帧白跳。
        if ($this->offset <= 0.0) {
            return;
        }

        $keyOf = $widget->keyMapper();

        if ($keyOf === null) {
            if (!$changed) {
                return;   // 项数没变 ⇒ 按序号的锚点本来就在原位
            }

            // 无 keyOf ⇒ 按序号夹（行为可预测，允许跳）
            $index = min($this->anchorIndex, max(0, $count - 1));
            $this->anchorIndex = $index;
            $this->offset = $insetStart + ($index + $this->anchorFrac) * $extent;

            return;
        }

        // ★ O(1) 判据：锚点那一格的 key 还在原位 ⇒ 内容没动到锚点
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
            // 锚点项被筛掉了 ⇒ 回起点（不夹到末尾：那比回起点更糟）
            $this->offset = 0.0;
            $this->anchorIndex = 0;
            $this->anchorFrac = 0.0;
            $this->anchorKey = null;

            return;
        }

        $this->anchorIndex = $found;
        $this->offset = $insetStart + ($found + $this->anchorFrac) * $extent;
    }

    /**
     * 记下"视口起点是哪一格的哪个位置"（`04` §7.3）。
     *
     * ★ 只在**偏移真的变了**时调（`scrollBy()` 里）—— 内容变时的还原走
     *   `didUpdateWidget()`，两者方向相反，混在一起会互相覆盖。
     *
     * ★ 主轴起始内边距要从偏移里扣掉：格子从 `insets` 的起始边开始，
     *   `offset = 0` 时视口起点是**内边距**而不是第 0 格。
     */
    private function rememberAnchor(): void
    {
        $view = $this->view();
        $extent = $view->itemExtent();
        if ($extent <= 0.0) {
            return;
        }

        $insets = $view->insets();
        $vertical = $view->isVertical();
        $start = $vertical ? $insets->top : $insets->left;
        $main = max(0.0, $this->offset - $start);
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
     *   格子）—— 与 `$offset` 同一套坐标，所以换算不需要再补 `sliceOrigin`
     *   （窗的起点只是绘制期的平移量，不进内容坐标）。
     *
     * ★ 形状与 `ScrollViewState::ensureVisible()` 逐字同构：算术都在
     *   `RenderScrollView::revealDelta()`，本方法只"找盒 + 转交"。
     */
    public function ensureVisible(Rect $target, float $margin = 8.0): void
    {
        /** @var RenderScrollView|null $box */
        $box = $this->element()?->effectiveBoxOf(RenderScrollView::class);
        if ($box === null) {
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
        $vertical = $view->isVertical();
        $items = $view->itemsList();
        $count = count($items);
        $extent = $view->itemExtent();
        $insets = $view->insets();

        // 主轴两端的内边距属于**内容**（纵向是上下、横向是左右）
        $start = $vertical ? $insets->top : $insets->left;
        $end = $vertical ? $insets->bottom : $insets->right;

        // 完整内容的主轴长度（含主轴内边距）—— 滚动条与可滚上限都按它算
        $total = $start + $count * $extent + $end;

        $viewport = $this->viewportEstimate($ctx, $vertical);
        $offset = min(max(0.0, $this->offset), max(0.0, $total - $viewport));

        // 窗口：完整内容坐标 [offset, offset+viewport) 换算成序号区间
        $overscan = $view->overscanPx();
        $first = (int) floor(max(0.0, $offset - $overscan - $start) / $extent);
        $last = (int) ceil(max(0.0, $offset + $viewport + $overscan - $start) / $extent);
        $first = max(0, min($first, $count));
        $last = max($first, min($last, $count));

        // ★ 记下这扇窗的**内容坐标**范围 —— 滚动期靠它判"要不要重建窗口"
        //   （见 `$windowStart` 的注释）。写成 `$start + 序号×格长` 而不是
        //   "sliceOrigin + 窗长"：两者等价，但这个形式与下面的 `sliceOrigin`
        //   同源，改一处不会漂。
        $this->windowStart = $start + $first * $extent;
        $this->windowEnd = $start + $last * $extent;

        $cells = [];
        for ($i = $first; $i < $last; $i++) {
            // ★ key = **绝对序号**（不是窗内序号）：窗口滑动一格时，第 k 格仍是
            //   第 k 格 ⇒ diff 只增删两端各一个 Element，中间全部复用；用窗内
            //   序号做 key 则整窗 key 一起变 ⇒ 每滚一格重建全部格子（State 全丢）。
            $cells[] = $this->sizedCell($view->buildItem($items[$i]), $extent, $vertical, $i);
        }

        $kid = $this->stack($cells, $vertical);

        // 交叉轴内边距：每一格都要让开（纵向是左右、横向是上下）
        $crossStart = $vertical ? $insets->left : $insets->top;
        $crossEnd = $vertical ? $insets->right : $insets->bottom;
        if ($crossStart > 0.0 || $crossEnd > 0.0) {
            $kid = Padding::make($kid)->padding(
                $vertical
                    ? EdgeInsets::only($crossStart, 0.0, $crossEnd, 0.0)
                    : EdgeInsets::only(0.0, $crossStart, 0.0, $crossEnd)
            );
        }

        // 窗的起点 = 完整内容里"第 first 格"的主轴坐标
        $bar = $this->barFade;
        $opacity = 1.0;
        if ($bar !== null) {
            $opacity = $bar->opacity();
        }

        return ScrollViewBox::make($offset, $vertical, $kid, $total, $start + $first * $extent, $opacity);
    }

    /**
     * 一格：**主轴定长** + 绝对序号做 key。
     *
     * ★ 拆成两个 `return` 而不是 `$vertical ? ->height() : ->width()`：tpc 对
     *   "三元两支类型不同"会推成联合类型（`AOT-CONSTRAINTS` 的 `?:` 那条），
     *   而 `height()` / `width()` 在静态类型下各自返回 `static`。声明返回
     *   `Widget` 后两支都只是子类型 ⇒ 编译器只需要一次向上转换。
     */
    private function sizedCell(Widget $kid, float $extent, bool $vertical, int $index): Widget
    {
        if ($vertical) {
            return SizedBox::make($kid)->height($extent)->key($index);
        }

        return SizedBox::make($kid)->width($extent)->key($index);
    }

    /**
     * 子层容器：纵向 `Column`、横向 `Row`。
     *
     * ★ 同样拆两个 `return`（`Column` / `Row` 是两个具体类，三元会推联合）。
     */
    private function stack(array $cells, bool $vertical): Widget
    {
        if ($vertical) {
            return Column::make()->children($cells);
        }

        return Row::make()->children($cells);
    }

    /**
     * 视口主轴长度的估计。
     *
     * ★ 优先取**上一帧**盒的实测主轴长度（精确）；首帧没有盒 ⇒ 退回窗口的对应
     *   尺寸（上界：任何嵌套视口都不会比窗口更大）。**不读环境时钟、不问平台**
     *   （G3：帧的输入只有约束与树）。
     */
    private function viewportEstimate(BuildContext $ctx, bool $vertical): float
    {
        $window = $vertical ? $ctx->height : $ctx->width;

        /** @var RenderScrollView|null $box */
        $box = $this->element()?->effectiveBoxOf(RenderScrollView::class);
        if ($box !== null) {
            $size = $box->size();
            if ($size !== null) {
                $viewport = $vertical ? $size->height : $size->width;
                if ($viewport > 0.0) {
                    // ★ `04` §5.5 的断言：这里是**帧间状态依赖**（读上一帧的盒
                    //   尺寸，不在 performLayout 里 ⇒ 不违反布局纯函数，但同类隐患）。
                    //   超过 2 倍窗口主轴 ⇒ 上一帧的盒尺寸是错的（如窗口刚从小变大）。
                    //   逻辑不动（三条理由见 `04` §5.5），只加这条上界。
                    if ($window > 0.0 && $viewport > $window * 2.0) {
                        Errors::report(sprintf(
                            '%s 的视口估计 %s 超过窗口主轴 %s 的 2 倍 —— 上一帧的盒尺寸可疑。'
                                . "\n" . '修法：确认窗口尺寸变化后盒已重排；必要时首帧用 ctx 尺寸兜底。',
                            $this->view()->kindName(),
                            (string) $viewport,
                            (string) $window,
                        ), Errors::DIAGNOSTIC);
                    }

                    return $viewport;
                }
            }
        }

        // ★ ② 自己声明了视口 ⇒ 用它。**必须排在 `$ctx` 之前**（2026-10-29）：
        //   `BuildContext` 的主轴在浮层里是**无界**（`RenderPopupPanel` 按内容自尺寸）
        //   ⇒ 退到它会把 60 格全部造出来（虚拟化静默失效）。
        $declared = $this->view()->viewportExtentPx();
        if ($declared > 0.0) {
            return $declared;
        }

        return max(0.0, $window);
    }

    private function view(): LazyList
    {
        $widget = $this->widget();
        if (!$widget instanceof LazyList) {
            // 框架不变量：StatefulElement 按 get_class 配对，配错不可能发生
            Errors::report('LazyListState 只能配 LazyList（框架内部不变量被破坏）');
        }

        return $widget;
    }
}
