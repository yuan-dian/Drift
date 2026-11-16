<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\Axis;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Layout\RenderScrollView;

/**
 * `LazyList` 的 State：持滚动偏移 + 决定这一帧造哪几格（两个轴共用一份）。
 * 窗口在 build 期算，不在布局期算：布局期拿不到"还没造的 Widget"，
 * 视口主轴长度取上一帧盒的实测值（首帧无盒 ⇒ 退回窗口尺寸，是嵌套视口上界）。
 * ★ 偏移上界必须在此夹：只夹下界的 `scrollBy()` 在内容变短时
 *   会把窗算到列表之外，造出一片空白（不报错）。
 * @internal 应用只认 `LazyColumn` / `LazyRow`
 */
final class LazyListState extends State implements Scrollable, ScrollMetrics, ConstraintObserver
{
    /** 这根轴的滚动状态机（偏移 + 动画 + 淡出）；懒建：从没滚过的列表不额外造对象。 */
    private ?ScrollLane $lane = null;

    /**
     * 锚点：视口起点那一格在**完整内容**里的位置 —— 序号 + 格内滚过的比例（`0..1`，
     * 保留"没对齐到格"的那点偏移）。`anchorKey` 是这一格的身份（`keyOf()` 给的）；
     * 没配 `keyOf` 时恒 `null` ⇒ 内容变短只能按序号夹。
     */
    private int $anchorIndex = 0;
    private float $anchorFrac = 0.0;
    private ?string $anchorKey = null;

    /** 上一次见到的项数（没配 `keyOf` 时用它判断"内容变了没有"） */
    private int $lastCount = -1;

    /**
     * 上一次 `build()` 造出来的窗口在**完整内容坐标**里的范围 `[start, end)`。
     * 滚动不再 `setState()`，故"窗还盖不盖得住视口"须在 build 之外判：
     * 盖得住只推偏移（0 重建），盖不住才标脏重建窗口。没有这两个数，
     * 滚出缓冲带后下方就是一片空白（且不报错：盒照样画，只是没内容）。
     */
    private float $windowStart = 0.0;

    private float $windowEnd = 0.0;

    /** 本帧 build 用的视口主轴长度（`-1` = 还没 build 过）；约束反馈拿它比 */
    private float $builtViewport = -1.0;

    /**
     * 播种初始偏移（`LazyList::initialOffset()`）。
     * 必须同时播种锚点：`didUpdateWidget()` 用"锚点那一格的 key 还在原位"判内容有没有动，
     * 锚点停在初始值（序号 0、key `null`）时，只要 offset > 0 就会被判成"锚点丢了"
     * ⇒ 第二次重建直接跳回顶部。播种走与滚动同一条 `rememberAnchor()`，口径不会分叉。
     */
    #[\Override]
    public function initState(): void
    {
        $this->pipeline()->addConstraintObserver($this);

        $this->view()->controllerValue()?->attach($this);

        $px = $this->view()->initialOffsetPx();

        if ($px <= 0.0) {
            return;
        }

        $this->lane()->set($px);
        $this->rememberAnchor();
    }

    /** 当前偏移（逻辑像素，≥ 0，已按上一帧的内容长度夹过上界） */
    public function offset(): float
    {
        return $this->lane()->offset();
    }

    /**
     * 内容能滚到的最大偏移（盒没挂 ⇒ 0）。
     *
     * 读盒而不是自己按 `项数 × 格长` 算：完整长度已由 `build()` 以 `virtualExtent`
     * 交给盒，自己再算一遍迟早与滚动条漂。
     */
    public function maxOffset(): float
    {
        /** @var RenderScrollView|null $box */
        $box = $this->element()?->effectiveBoxOf(RenderScrollView::class);
        if ($box === null) {
            return 0.0;
        }

        return $box->maxScroll($this->view()->isVertical() ? Axis::Vertical : Axis::Horizontal);
    }

    /** 滚到绝对偏移（用 Δ 表达 ⇒ 夹取仍只有 `ScrollLane::move()` 一条路径） */
    public function scrollTo(float $offset): void
    {
        $this->scrollBy($offset - $this->offset());
    }

    #[\Override]
    public function animateTo(float $offset, float $durationMs, Curve $curve): void
    {
        $this->lane()->animateTo($offset, $durationMs, $curve);
    }

    /** 主轴：`LazyRow` 是横向 */
    public function scrollAxis(): Axis
    {
        return $this->view()->isVertical() ? Axis::Vertical : Axis::Horizontal;
    }

    /** 视口尺寸；盒没挂 ⇒ 零尺寸（翻页键据此不吞键） */
    public function viewportExtent(): Size
    {
        /** @var RenderScrollView|null $box */
        $box = $this->element()?->effectiveBoxOf(RenderScrollView::class);

        return $box?->size() ?? Size::zero();
    }

    /** @internal 卸载即解绑：句柄留着死 State 会滚到虚空的盒上 */
    #[\Override]
    public function dispose(): void
    {
        $this->pipeline()->removeConstraintObserver($this);
        $this->view()->controllerValue()?->detach($this);
    }

    /**
     * 布局后自检：视口与 build 时用的不一样 ⇒ 本帧重造窗口。
     *
     * 首帧必然触发一次（build 时还没有盒 ⇒ 用了窗口尺寸兜底），这正是要根治的
     * 「先按窗口造一版、下一帧才纠正」。稳态返回 `null`，不多跑一轮 layout。
     */
    public function pollConstraintDrift(): ?string
    {
        /** @var RenderScrollView|null $box */
        $box = $this->element()?->effectiveBoxOf(RenderScrollView::class);
        $constraints = $box === null ? null : $box->constraints();
        if ($constraints === null) {
            return null;
        }

        $vertical = $this->view()->isVertical();
        $limit = $vertical ? $constraints->maxHeight : $constraints->maxWidth;
        if (\is_infinite($limit)) {
            return null;   // 无界：视口跟随内容 / 声明值，没有可比的数
        }

        $now = max(0.0, $limit);
        $used = $this->builtViewport;
        if ($used >= 0.0 && \abs($now - $used) <= 0.5) {
            return null;
        }

        $this->markDirty();

        return \sprintf(
            '%s: 视口 %s → %.1f',
            $this->view()->kindName(),
            $used < 0.0 ? '未定' : \sprintf('%.1f', $used),
            $now,
        );
    }

    /**
     * 滚动 Δ（逻辑像素；正 = 内容上移，即"往下看"）。
     * 能不重建就不重建：偏移只改两个数，不改"有哪些子"；窗口仍盖得住视口
     * ⇒ 推给盒（就地改 + 标重排）即可；只有滚出缓冲带才标脏重建。
     * ★ 回写**盒夹过的值**（不是 `$next`）：内容变短时 State 里那个"增量累加值"
     *   会长期大于上限，之后每笔增量都先消耗死区 —— 真机表现"滚轮转了画面不动"。
     */
    public function scrollBy(float $delta): void
    {
        /** @var RenderScrollView|null $box */
        $box = $this->element()?->effectiveBoxOf(RenderScrollView::class);

        // 盒还没挂（首帧之前 / 盒被换掉）⇒ 只改字段 + 标脏：没有盒就没有
        //   "就地推给盒"这回事，只能等下一次 build 把值递下去。
        if ($box === null) {
            if (!$this->lane()->move($delta, static fn(float $value): float => $value)) {
                return;
            }

            $this->setState(function (): void {
                $this->rememberAnchor();
            });

            $this->view()->controllerValue()?->sync();

            return;
        }

        $vertical = $this->view()->isVertical();
        $moved = $this->lane()->move(
            $delta,
            static function (float $value) use ($box, $vertical): float {
                return $box->setScrollOffset($vertical ? Axis::Vertical : Axis::Horizontal, $value);
            },
        );
        if (!$moved) {
            return;
        }

        $this->rememberAnchor();

        // 窗口盖不住视口 ⇒ 必须重建（否则下方/上方是空白）
        $size = $box->size();
        $viewport = $size === null ? 0.0 : ($vertical ? $size->height : $size->width);
        if ($viewport <= 0.0 || !$this->windowCovers($viewport)) {
            $this->markDirty();
        }

        $this->view()->controllerValue()?->sync();
    }

    /**
     * 上一帧造的窗口是否完整盖住当前视口 `[offset, offset+viewport)`。
     * 用 `offset`（盒已夹过）而不是调用方传的 `$next`：滚到边界时盒会把值夹回，
     * 按未夹的值判会以为"还没滚到" ⇒ 多标一次脏。
     */
    private function windowCovers(float $viewport): bool
    {
        $offset = $this->lane()->offset();

        return $offset >= $this->windowStart && $offset + $viewport <= $this->windowEnd;
    }

    /**
     * 按轴滚动：只有主轴接受（`LazyRow` 的主轴是 x）。
     * 主轴来自 Widget（`LazyList::isVertical()`），不由调用方猜。
     */
    public function scrollByAxis(Axis $axis, float $delta): void
    {
        if (($axis === Axis::Vertical) !== $this->view()->isVertical()) {
            return;
        }

        $this->scrollBy($delta);
    }

    /**
     * 按轴滚动，带动画（滚轮专用；细节见 `Scrollable::glideByAxis()`）。
     * 本方法只剩"轴对不对"这一层判据 —— 起飞前对齐、目标累加、点亮条都在
     *   `ScrollLane::glide()`（三个实现共用一份）。
     */
    public function glideByAxis(Axis $axis, float $delta): void
    {
        if (($axis === Axis::Vertical) !== $this->view()->isVertical()) {
            return;
        }

        $this->lane()->glide($delta);
    }

    /** 这根轴的滚动状态机（懒建：从没滚过的列表不建） */
    private function lane(): ScrollLane
    {
        $lane = $this->lane;
        if ($lane === null) {
            $lane = new ScrollLane(0.0, null, $this);
            $this->lane = $lane;
        }

        return $lane;
    }

    /**
     * 推进这一帧的滚动动画与滚动条淡出（先滚动、后淡出）。
     * 这里的 `scrollBy()` 会顺带做 `rememberAnchor()` 与"窗口盖不住就标脏"，
     * 故动画期间每帧都走一次换窗判断 —— 那是必要的：一步 5px 也可能跨过缓冲带边界，
     * 跳过它就会出现"滑过去时下方露白"。
     */
    #[\Override]
    public function tickFrame(float $timestampMs): bool
    {
        $lane = $this->lane();

        $busy = $lane->tick($timestampMs, function (float $delta): void {
            $this->scrollBy($delta);
        });

        if ($lane->fade()->tickFor($this, $timestampMs)) {
            $busy = true;
        }

        return $busy;
    }

    /**
     * 内容变了 ⇒ 按锚点还原偏移。
     * 每次父重建都走到这里，故先判"内容变没变"：配 `keyOf` 看锚点 key 是否仍在原位
     * （O(1)），没配看项数；判不出就不动，否则浮点误差会磨掉滚动位置。
     * 按 key 不按序号：筛选后锚点项不存在时，按序号夹会跳到末尾（比回顶部更糟）；
     * 找不到 key ⇒ 回顶部。O(n) 只在内容变时跑一次。
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
        // 主轴起始内边距：纵向是上、横向是左（内容从哪儿开始）
        $insetStart = $vertical ? $insets->top : $insets->left;
        $changed = $count !== $this->lastCount;
        $this->lastCount = $count;

        // 从没滚过（或正好在起点）⇒ 锚点就是"起点"，没什么要还原；
        //   这条早退还挡掉"主轴起始内边距"：没滚时 offset 是 0，还原会把它
        //   算成 insetStart ⇒ 一帧白跳。
        if ($this->lane()->offset() <= 0.0) {
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
            $this->lane()->set($insetStart + ($index + $this->anchorFrac) * $extent);

            return;
        }

        // O(1) 判据：锚点那一格的 key 还在原位 ⇒ 内容没动到锚点
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
            $this->lane()->set(0.0);
            $this->anchorIndex = 0;
            $this->anchorFrac = 0.0;
            $this->anchorKey = null;

            return;
        }

        $this->anchorIndex = $found;
        $this->lane()->set($insetStart + ($found + $this->anchorFrac) * $extent);
    }

    /**
     * 记下"视口起点是哪一格的哪个位置"。
     * 只在偏移真的变了时调（`scrollBy()` 里）—— 内容变时的还原走
     *   `didUpdateWidget()`，两者方向相反，混在一起会互相覆盖。
     * 主轴起始内边距要从偏移里扣掉：格子从 `insets` 的起始边开始，
     *   `offset = 0` 时视口起点是内边距而非第 0 格。
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
        $main = max(0.0, $this->lane()->offset() - $start);
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
     * 把内容坐标里的矩形滚进视口。
     * 这里的"内容坐标"是完整内容的坐标（含 `insets`、含所有没造出来的格子）
     * —— 与 `$offset` 同一套坐标，故换算不需再补 `sliceOrigin`。
     * 形状与 `ScrollViewState::ensureVisible()` 同构：算术都在
     *   `RenderScrollView::revealDelta()`，本方法只"找盒 + 转交"。
     */
    public function ensureVisible(Rect $target, float $margin = 8.0): void
    {
        /** @var RenderScrollView|null $box */
        $box = $this->element()?->effectiveBoxOf(RenderScrollView::class);
        if ($box === null) {
            return;
        }

        // 基准传自己待生效的偏移（同 `ScrollViewState`，见 `revealDelta()` 的 `$base` 说明）。
        $delta = $box->revealDelta($target, $margin, $this->lane()->offset());
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

        // 主轴两端的内边距属于内容（纵向是上下、横向是左右）
        $start = $vertical ? $insets->top : $insets->left;
        $end = $vertical ? $insets->bottom : $insets->right;

        // 完整内容的主轴长度（含主轴内边距）—— 滚动条与可滚上限都按它算
        $total = $start + $count * $extent + $end;

        $viewport = $this->viewportEstimate($ctx, $vertical);
        $this->builtViewport = $viewport;
        $offset = min(max(0.0, $this->lane()->offset()), max(0.0, $total - $viewport));

        // 窗口：完整内容坐标 [offset, offset+viewport) 换算成序号区间
        $overscan = $view->overscanPx();
        $first = (int) floor(max(0.0, $offset - $overscan - $start) / $extent);
        $last = (int) ceil(max(0.0, $offset + $viewport + $overscan - $start) / $extent);
        $first = max(0, min($first, $count));
        $last = max($first, min($last, $count));

        // 记下这扇窗的内容坐标范围（滚动期靠它判"要不要重建窗口"，见 `$windowStart`）。
        // 写成 `$start + 序号×格长`：与下面的 `sliceOrigin` 同源，改一处不会漂。
        $this->windowStart = $start + $first * $extent;
        $this->windowEnd = $start + $last * $extent;

        $cells = [];
        for ($i = $first; $i < $last; $i++) {
            // key = 绝对序号（不是窗内序号）：窗口滑动一格时第 k 格仍是第 k 格
            //   ⇒ diff 只增删两端各一个 Element；用窗内序号做 key 则每滚一格
            //   重建全部格子（State 全丢）。
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
        $opacity = $this->lane()->fade()->opacity();

        return ScrollViewBox::make($offset, $vertical, $kid, $total, $start + $first * $extent, $opacity);
    }

    /**
     * 一格：主轴定长 + 绝对序号做 key。
     * 拆成两个 `return` 而不是 `$vertical ? ->height() : ->width()`：tpc 对
     *   "三元两支类型不同"会推成联合类型，而 `height()` / `width()` 各自返回 `static`。
     *   声明返回 `Widget` 后两支都只是子类型 ⇒ 只需一次向上转换。
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
     * 同样拆两个 `return`（`Column` / `Row` 是两个具体类，三元会推联合）。
     */
    private function stack(array $cells, bool $vertical): Widget
    {
        if ($vertical) {
            return Column::make()->children($cells);
        }

        return Row::make()->children($cells);
    }

    /**
     * 视口主轴长度：取**本帧布局要拿到的约束**（`RenderBox::constraints()`）。
     *
     * ★ 以前读"上一帧盒的 size"：首帧没有盒 ⇒ 只能拿窗口尺寸当上界 ⇒ 窗口宽 ≠
     *   视口宽时首帧多造/少造内容，下一帧才纠正（一帧可见的错版式）。
     *   约束是布局的**入参**，与 `RenderScrollView` 自己算视口用的是同一份数。
     * ★ 无界 ⇒ 退回"自己声明的视口"再退回 `ctx`：浮层里主轴是无界的，退到
     *   `ctx` 会把 60 格全部造出来（虚拟化静默失效）。
     */
    private function viewportEstimate(BuildContext $ctx, bool $vertical): float
    {
        $window = $vertical ? $ctx->height : $ctx->width;

        /** @var RenderScrollView|null $box */
        $box = $this->element()?->effectiveBoxOf(RenderScrollView::class);
        if ($box !== null) {
            $constraints = $box->constraints();
            if ($constraints !== null) {
                $limit = $vertical ? $constraints->maxHeight : $constraints->maxWidth;
                if (!\is_infinite($limit) && $limit > 0.0) {
                    return $limit;
                }
            }
        }

        // ★ 自己声明了视口 ⇒ 用它。必须排在 `$ctx` 之前（理由见上）。
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
