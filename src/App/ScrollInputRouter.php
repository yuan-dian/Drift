<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Geometry\Axis;
use Drift\Geometry\Rect;
use Drift\Input\PointerEvent;
use Drift\Layout\RenderBox;
use Drift\Layout\ScrollBarGeometry;
use Drift\Layout\ScrollBarHost;
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
     * ★ `array{state: Scrollable, box: ScrollBarHost, axis: Axis, last: float}` ——
     *   `axis` 是**这条** thumb 的轴（表有两根条，拖动中必须记住拖的是哪根，
     *   否则每帧重新判定会把横向拖动判成纵向）；`last` 是该轴上的上一次指针位置
     *   （映射"thumb 走多远、内容走多远"用）。
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
     * 沿命中 Element 的父链找**最近**的可滚动视口并按轴滚动它（滚轮路由）。
     *
     * ★ 找不到 ⇒ 无事发生（轮在不可滚动的区域上是合法的，不报错、不改光标）。
     * ★ **只找最近的**：嵌套滚动容器里，轮落在内层上就只滚内层 —— 外层的
     *   视口不吞内层的事件（§A.15：不预先发明没消费者的相位/规则，嵌套滚动的
     *   "滚动到边界再传给外层"留给真有消费者时）。
     *
     * ## 轴的分派：竖向是**默认手势**，横向是**明确意图**
     *
     * | 请求 | 容器支持该轴 | 容器只有别的轴 |
     * |---|---|---|
     * | 竖向（普通滚轮）| 滚竖轴 | **回落到它唯一那根**（横向 `LazyRow` 照样能滚）|
     * | 横向（横轮 / Shift+竖轮）| 滚横轴 | 不回落（纵向列表不能被触控板横划带着上下跑）|
     *
     * 竖向回落保的是既有手感（Windows 横向 ListView 同款）：竖轮是用户唯一的
     * 默认手势，落到"只能横着滚"的区域上，他不可能是想让它不动。
     *
     * ★ 判据是 `scrollAxes()`（**结构性**：这个容器有几根轴），不是 `maxScroll()`
     *   （**状态性**：这根轴当前有没有溢出）。用后者会在"盒还没布局 / 上限恒 0"
     *   时把事件吞掉 —— 滚轮必须先于布局正确工作。
     *
     * ★★ **走 `glideByAxis()` 而不是 `scrollByAxis()`**（2026-10-29）：滚轮是**离散**
     *   事件（一格 48px），瞬间跳一格在高刷屏上就是"闪现"。摊成若干帧之后才是
     *   "滑过去"。★ 动画的每一帧位移仍然交给 `scrollBy()` ⇒ 夹取 / 换窗 / 锚点 /
     *   提交编辑全部复用既有那一条路，动画不重写任何一条。
     *   ★ **thumb 拖动不走这里**（见 `dragThumb()`）：拖动必须 1:1 跟手，动画化会漂移。
     */
    public function scrollAt(?Element $element, Axis $axis, float $delta): void
    {
        $state = $this->nearestScrollable($element);
        if ($state === null) {
            return;
        }

        $host = $this->barHostOf($state->element());
        if ($host === null) {
            // 没有滚动条宿主盒 ⇒ 不知道它有几根轴，交给 State 自己判（问错轴它忽略）
            $state->glideByAxis($axis, $delta);

            return;
        }

        $axes = $host->scrollAxes();
        if ($this->axisListed($axes, $axis)) {
            $state->glideByAxis($axis, $delta);

            return;
        }

        if ($axis === Axis::Vertical && \count($axes) === 1) {
            $state->glideByAxis($axes[0], $delta);
        }
    }

    /**
     * `Axis` 是否在某份轴清单里。
     *
     * ★ 手写循环而不是 `in_array()`：AOT 下对 enum 的松散比较是不可靠的
     *   （`in_array` 默认非 strict），而 `===` 是阶段 5 已经验过的比较方式。
     *
     * @param list<Axis> $axes
     */
    private function axisListed(array $axes, Axis $axis): bool
    {
        foreach ($axes as $candidate) {
            if ($candidate === $axis) {
                return true;
            }
        }

        return false;
    }

    /**
     * 命中滚动条 thumb（返回拖动状态；`null` = 没点中）。
     *
     * ★ 沿命中链找**最近**的可滚动视口，再问它各轴的 `thumbRectInRoot()` ——
     *   两个条件都满足才算点中：视觉上 thumb 压在那个视口的右缘 / 底缘，
     *   点在那里用户的意思就是"拖这条"。
     * ★ **按反序遍历 `scrollAxes()`**：那份清单是**绘制顺序**（先画的在前），
     *   后画的压在先画的上面 ⇒ 命中要反过来问，否则右下角那 6×6 会抓到横条
     *   （看得见的是竖条）。与 `RenderBox` 逆序问子盒是同一条惯例。
     * ★ 容差 2px：条只有 6px 宽，像素级精确抓取对鼠标过于苛刻。
     * ★ 盒失败（有 `Scrollable` 却没有滚动条宿主盒）⇒ `null` 而非
     *   继续往上找：视觉上这里没有条，事件就该落到别处，而不是隔着一个
     *   不是滚动器的祖先去抓外层的条。
     *
     * @return null|array{state: Scrollable, box: ScrollBarHost, axis: Axis, last: float}
     */
    private function scrollThumbAt(float $x, float $y, Element $root, FocusManager $focus): ?array
    {
        $hit = $focus->hitTest($x, $y, $root);
        $element = $hit === null ? null : $focus->elementAt($root, $hit);

        $state = $this->nearestScrollable($element);
        if ($state === null) {
            return null;
        }

        $host = $this->barHostOf($state->element());
        if ($host === null) {
            return null;
        }

        $axes = $host->scrollAxes();
        for ($i = \count($axes) - 1; $i >= 0; $i--) {
            $axis = $axes[$i];
            $bar = $host->thumbRectInRoot($axis);
            if ($bar === null) {
                continue;   // 该轴内容不溢出：没有条可拖
            }

            $left = $bar->left - 2.0;
            $top = $bar->top - 2.0;

            if ($x < $left || $x > $left + $bar->width + 4.0
                || $y < $top || $y > $top + $bar->height + 4.0) {
                continue;
            }

            return [
                'state' => $state,
                'box' => $host,
                'axis' => $axis,
                'last' => ScrollBarGeometry::project($x, $y, $axis),
            ];
        }

        return null;
    }

    /**
     * 找 State 对应的滚动条宿主盒（**类型转换只在这一处**）。
     *
     * ★ 写成独立方法而不是就地 `instanceof`：返回类型声明成 `?ScrollBarHost`
     *   才是干净的窄化（与 `nearestScrollable()` 同一形态）。就地窄化会让
     *   调用处的变量同时被当成 `RenderBox` 与接口，那是 tpc 静态推断的地雷区。
     */
    private function barHostOf(?Element $element): ?ScrollBarHost
    {
        $box = $element?->findBoxOf(ScrollBarHost::class);

        return $box instanceof ScrollBarHost ? $box : null;
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
     * thumb 拖动的一步：位移 → 滚动量（按**拖的那条轴的**分量算）。
     *
     * ★ 映射是"thumb 走多远、内容走多远"：`dy * maxScroll / thumbTravel`。
     *   thumb 下移 ⇒ offset 增大（看到下面）—— 与 `scrollBy` 的正向语义一致，
     *   所以**不需要额外取反**（滚轮那层已经在壳里翻译过了）。
     * ★ 走 `scrollByAxis()` 而不是 `scrollBy()`：后者是**主轴**语义，表的横条
     *   在主轴（纵向）之外 —— 用 `scrollBy()` 会把横向拖动喂给纵向偏移。
     */
    public function dragThumb(PointerEvent $event): void
    {
        $drag = $this->thumbDrag;
        if ($drag === null) {
            return;
        }

        /** @var Scrollable $state */
        $state = $drag['state'];
        /** @var ScrollBarHost $box */
        $box = $drag['box'];
        /** @var Axis $axis */
        $axis = $drag['axis'];

        $travel = $box->thumbTravel($axis);
        $max = $box->maxScroll($axis);
        if ($travel <= 0.0 || $max <= 0.0) {
            return;
        }

        $now = ScrollBarGeometry::project($event->x, $event->y, $axis);
        $delta = ($now - (float) $drag['last']) * $max / $travel;
        if ($delta !== 0.0) {
            $state->scrollByAxis($axis, $delta);
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

        // ★ 单轴视口只报一根轴（`scrollAxes()` 恒非空）；偏移只在**主轴**上加回，
        //   交叉轴上的绝对差就是内容坐标。
        $axes = $scroll->scrollAxes();
        $axis = \count($axes) > 0 ? $axes[0] : Axis::Vertical;
        $offset = $scroll->appliedOffset($axis);

        $target = $axis === Axis::Vertical
            ? new Rect($dx, $dy + $offset, $size->width, $size->height)
            : new Rect($dx + $offset, $dy, $size->width, $size->height);

        $state->ensureVisible($target);
    }
}
