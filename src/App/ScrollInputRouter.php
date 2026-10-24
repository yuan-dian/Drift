<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Geometry\Rect;
use Drift\Input\PointerEvent;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderScrollView;
use Drift\Widget\Element;
use Drift\Widget\Scrollable;
use Drift\Widget\States;

/**
 * **滚动输入路由**（S1 第 5 步）：滚轮 / 滚动条 thumb 拖动 / 焦点滚进视口。
 *
 * ```text
 * ScrollEvent ──▶ scrollAt()（沿命中链找最近 Scrollable，只滚一个）
 * PointerEvent ──▶ beginThumbDrag() ──move──▶ dragThumb() ──up/leave──▶ finishThumbDrag()
 * 键盘焦点切换 ──▶ revealFocused()（§9.1 键盘可达性硬要求）
 * ```
 *
 * ★ **为什么从 Application 拆出来**：这三路在 Application 里散成 5 个方法
 *   + `$thumbDrag` 字段，共享同一个穿透口径（"沿父链找最近的 Scrollable"）。
 *   收成一片后，嵌套滚动策略（将来"滚到边界再传给外层"）的演进落在一个
 *   文件里；且它是 InputRouter 主体拆分（路线表步 6）的天然前哨。
 *
 * ★ **thumb 不是子盒**（`paint()` 画的胶囊，命中测试看不见它）⇒ down 落在
 *   thumb 上必须**先于内容接管**：本次手势整个归 thumb，不喂 focusHit、
 *   不派发给内容。`beginThumbDrag()` 返回 true = 吞掉（调用方直接 return）。
 *
 * ★ **只经 `TextEditingHost` 的 `focus()` 摸宿主持位链**：滚轮与 thumb 命中
 *   都是纯函数式（root / focus 由调用方传入），只有 `revealFocused()` 要读
 *   当前焦点。
 *
 * @internal S1 的拆分片，同 `FocusManager` —— 不是应用契约
 */
final class ScrollInputRouter
{
    /**
     * 进行中的 thumb 拖动状态（`null` = 没有）。
     *
     * ★ `array{state: Scrollable, box: RenderScrollView, last: float}` ——
     *   `last` 是上一事件的指针位置（映射"thumb 走多远、内容走多远"用）。
     *   **只记 State 不缓存盒的兄弟问题**：盒每步现取会丢（帧末 flush 重建），
     *   但 thumb 拖动的几何必须**同一帧内连续** ⇒ 这里记盒（拖动不跨帧重建，
     *   指针 move 之间没有 settle）。
     */
    private ?array $thumbDrag = null;

    public function __construct(private TextEditingHost $host)
    {
    }

    /**
     * 沿父链（含起点）找**最近**的可滚动视口（`Scrollable` 穿透的唯一出口）。
     *
     * ★ 滚轮路由（`scrollAt`）、键盘可达性（`revealFocused`）、thumb 命中
     *   （`scrollThumbAt`）三处共用同一口径：只找最近的，不逐层上报
     *   （`04` §6.2 的裁决）。
     * ★ 要盒经 `State::element()` 回 Element 再 `findBoxOf()` —— 穿透知识
     *   与盒查找不混在一处（S1 步 2/3 立的两条规矩）。
     */
    private function nearestScrollable(?Element $from): ?Scrollable
    {
        for ($node = $from; $node !== null; $node = $node->parent()) {
            $state = States::of($node);
            if ($state instanceof Scrollable) {
                return $state;
            }
        }

        return null;
    }

    /**
     * 沿命中 Element 的父链找**最近**的可滚动视口并滚动它（滚轮路由）。
     *
     * ★ 找不到 ⇒ 无事发生（轮在不可滚动的区域上是合法的，不报错、不改光标）。
     * ★ **只找最近的**：嵌套滚动容器里，轮落在内层上就只滚内层 —— 外层的
     *   视口不吞内层的事件（§A.15：不预先发明没消费者的相位/规则，嵌套滚动的
     *   "滚动到边界再传给外层"留给真有消费者时）。
     */
    public function scrollAt(?Element $element, float $delta): void
    {
        $this->nearestScrollable($element)?->scrollBy($delta);
    }

    /**
     * 命中滚动条 thumb（返回拖动状态；`null` = 没点中）。
     *
     * ★ 沿命中链找**最近**的可滚动视口，再问它的 `thumbRect()` —— 两个条件都满足
     *   才算点中：视觉上 thumb 压在那个视口的右缘（横向在底缘），点在那里
     *   用户的意思就是"拖这条"。
     * ★ 容差 2px：条只有 6px 宽，像素级精确抓取对鼠标过于苛刻。
     * ★ 盒失败（有 `Scrollable` 却没有 `RenderScrollView`）⇒ `null` 而非
     *   继续往上找：视觉上这里没有条，事件就该落到别处，而不是隔着一个
     *   不是滚动器的祖先去抓外层的条。
     *
     * @return null|array{state: Scrollable, box: RenderScrollView, last: float}
     */
    private function scrollThumbAt(float $x, float $y, Element $root, FocusManager $focus): ?array
    {
        $hit = $focus->hitTest($x, $y, $root);
        $element = $hit === null ? null : $focus->elementAt($root, $hit);

        $state = $this->nearestScrollable($element);
        if ($state === null) {
            return null;
        }

        /** @var RenderScrollView|null $box */
        $box = $state->element()?->findBoxOf(RenderScrollView::class);
        if ($box === null) {
            return null;
        }

        $bar = $box->thumbRect();
        if ($bar === null) {
            return null;   // 内容不溢出：没有条可拖
        }

        [$originX, $originY] = RenderBox::absoluteOrigin($box);
        $left = $originX + $bar->left - 2.0;
        $top = $originY + $bar->top - 2.0;

        if ($x < $left || $x > $left + $bar->width + 4.0
            || $y < $top || $y > $top + $bar->height + 4.0) {
            return null;
        }

        return [
            'state' => $state,
            'box' => $box,
            'last' => $box->isVertical() ? $y : $x,
        ];
    }

    /**
     * down 落在 thumb 上 ⇒ 起拖动（返回 `true` = 本次按下被吞掉）。
     *
     * ★ 调用方（`dispatchPointer` 的 down 分支）拿到 `true` 直接 return ——
     *   "大拇指上的按下不算点内容"：不喂 focusHit、不派发给控件。
     */
    public function beginThumbDrag(float $x, float $y, Element $root, FocusManager $focus): bool
    {
        $thumb = $this->scrollThumbAt($x, $y, $root, $focus);
        if ($thumb === null) {
            return false;
        }

        $this->thumbDrag = $thumb;

        return true;
    }

    public function isDraggingThumb(): bool
    {
        return $this->thumbDrag !== null;
    }

    /**
     * thumb 拖动的一步：位移 → 滚动量（主轴方向按视口取向取 x 或 y）。
     *
     * ★ 映射是"thumb 走多远、内容走多远"：`dy * maxScroll / thumbTravel`。
     *   thumb 下移 ⇒ offset 增大（看到下面）—— 与 `scrollBy` 的正向语义一致，
     *   所以**不需要额外取反**（滚轮那层已经在壳里翻译过了）。
     */
    public function dragThumb(PointerEvent $event): void
    {
        $drag = $this->thumbDrag;
        if ($drag === null) {
            return;
        }

        /** @var Scrollable $state */
        $state = $drag['state'];
        /** @var RenderScrollView $box */
        $box = $drag['box'];

        $travel = $box->thumbTravel();
        $max = $box->maxScroll();
        if ($travel <= 0.0 || $max <= 0.0) {
            return;
        }

        $now = $box->isVertical() ? $event->y : $event->x;
        $delta = ($now - (float) $drag['last']) * $max / $travel;
        if ($delta !== 0.0) {
            $state->scrollBy($delta);
        }

        $this->thumbDrag['last'] = $now;
    }

    /** thumb 拖动收口（up / leave / 窗口失活 / ESC）。幂等。 */
    public function finishThumbDrag(): void
    {
        $this->thumbDrag = null;
    }

    /**
     * 把**当前焦点**滚进它所在的滚动视口（`04` §6.3：键盘可达性硬要求）。
     *
     * ★ 沿父链找**最近**的 `Scrollable`（与滚轮路由同一口径）：嵌套视口
     *   只滚最里面那个，不逐层上报（`04` §6.2 的裁决）。
     *
     * ★ 目标矩形用**绝对坐标之差**换算成内容坐标，不自己沿父链累加偏移：
     *   `absoluteOrigin()` 已经把滚动偏移算进去了（偏移住子盒的 `offsetX/Y`），
     *   再加回 `appliedOffset()` 就回到内容坐标。手抄累加是 M2-3 那一族
     *   bug 的温床（padding 被算两次、偏移被算两次 —— 都不报错）。
     *
     * ★ 焦点控件**还没挂盒**（虚拟列表里没造出来的行 / 刚换过树）⇒ 不动。
     *   这也是 `LazyColumn` 的既有边界：Tab 只能在已造出来的行之间走。
     */
    public function revealFocused(): void
    {
        $focused = $this->host->focus()?->focused();
        $box = $focused?->effectiveBox();
        if ($focused === null || $box === null) {
            return;
        }

        $size = $box->size();
        if ($size === null) {
            return;
        }

        $state = $this->nearestScrollable($focused->parent());
        if ($state === null) {
            return;
        }

        /** @var RenderScrollView|null $scroll */
        $scroll = $state->element()?->findBoxOf(RenderScrollView::class);
        if ($scroll === null || $scroll->size() === null) {
            return;
        }

        [$scrollX, $scrollY] = RenderBox::absoluteOrigin($scroll);
        [$boxX, $boxY] = RenderBox::absoluteOrigin($box);
        $dx = $boxX - $scrollX;
        $dy = $boxY - $scrollY;
        $offset = $scroll->appliedOffset();

        // ★ 偏移只在**主轴**上加回；交叉轴上的绝对差就是内容坐标。
        $target = $scroll->isVertical()
            ? new Rect($dx, $dy + $offset, $size->width, $size->height)
            : new Rect($dx + $offset, $dy, $size->width, $size->height);

        $state->ensureVisible($target);
    }
}
