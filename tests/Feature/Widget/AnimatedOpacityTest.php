<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\App\Frame;
use Drift\Dev\Smoke\Frame\RecordingSurface;
use Drift\Dev\Smoke\Layout\LayoutCountBox;
use Drift\Dev\Smoke\Layout\LayoutCountWidget;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Rendering\DisplayList;
use Drift\Widget\AnimatedOpacity;
use Drift\Widget\AnimatedOpacityState;
use Drift\Widget\Element;
use Drift\Widget\Opacity;
use Drift\Widget\Padding;
use Drift\Widget\StatefulElement;
use Drift\Widget\Widget;

/**
 * 隐式动画（`AnimatedOpacity`）：只写目标值，控件自己追过去。
 *
 * ## 判据必须落在"省了什么"上，不能落在"看起来动了"
 *
 * 本件的**全部价值**是"每帧只改合成槽，不重建、不重排"。画面在"每帧全量重建"
 * 的实现下**逐像素一样** ⇒ 只有计数器（`LayoutCountBox`）分得出来。同理
 * "compose 值写进去了"要看**录到的命令**（`OP_LAYER_PUSH`），不是看
 * `composeOpacity()` 读回 —— 后者在"写了但不画"的实现上照样对。
 *
 * ## 四条各自挡一种退化
 *
 * | 判据 | 挡的退化 |
 * |---|---|
 * | 目标变后**首帧 `tickFrame()` 仍返回 true** | 用"值变了没有"当判据 ⇒ 首帧 false ⇒ 帧时钟落闸 ⇒ 动画停在起点（不报错） |
 * | 中途父重建 compose **不跳到目标** | `build()` 声明目标值 ⇒ 重建瞬间闪到终点再被拉回 |
 * | 动画全程 `layoutCalls` **不涨** | 把合成当布局输入（改透明度触发重排） |
 * | 目标没变 ⇒ `tickFrame()` 立刻 false | 每次重建都白跑一段动画 |
 *
 * ## ★★★ 夹具：盒在 `update()` 时**换新**，不许缓存
 *
 * `Element::update()` 走 `syncBox()` ⇒ 新盒、`boxEpoch` 变（这也是 `Frame`
 * 不跳过 layout 的原因）。第一版测试把 `effectiveBox()` 的结果存在变量里跨
 * `update()` 使用，于是读到的是**上一代的盒**：`setCompose()` 写在新盒上、
 * 断言读旧盒，表现为"compose 一直等于初值"——看起来像产品代码没生效，
 * 实际是夹具拿了尸体。**每次断言前重新取盒**（`proxyOf()` / `leafBoxOf()`）。
 */
final class AnimatedOpacityTest extends SmokeTestCase
{
    private static function constraints(): BoxConstraints
    {
        return BoxConstraints::tight(new Size(320.0, 240.0));
    }

    /**
     * 挂一棵 `AnimatedOpacity → Opacity → RenderProxy → LayoutCountWidget`，并跑首帧。
     *
     * ★ **必须跑一帧再找盒**：`StatefulWidget` 的元素在 `mount()` 时还没有盒
     *   （`build()` 要到 settle 才跑）⇒ 先取盒只会拿到 `null`。
     *
     * @return array{0: Element, 1: Frame, 2: RecordingSurface, 3: Widget}
     */
    private static function mount(float $target): array
    {
        $leaf = new LayoutCountWidget(new Size(80.0, 40.0));
        $element = AnimatedOpacity::make($target, $leaf)->createElement();
        $element->mount(null, smokeContext());

        $surface = new RecordingSurface();
        $surface->resize(320, 240, 1.0);
        $frame = new Frame($surface);
        $frame->run($element, self::constraints(), 0.0);

        // 首帧就得有盒，否则后面全部断言都在读 null
        self::proxyOf($element);
        self::leafBoxOf($element);

        return [$element, $frame, $surface, $leaf];
    }

    /** `AnimatedOpacity` 的有效盒（= `Opacity` 造的那个 `RenderProxy`，合成槽在它身上） */
    private static function proxyOf(Element $element): RenderBox
    {
        $box = $element->effectiveBox();
        if ($box === null) {
            self::fail('AnimatedOpacity 没造出盒（夹具坏了）');
        }

        return $box;
    }

    /**
     * 数计数器用的叶子盒。
     *
     * ★ 必须用 `findBoxOf()` 而不是 `effectiveBoxOf()`：后者只看"本节点的有效盒"
     *   （这里是 `Opacity` 的 `RenderProxy`），不会继续下探找指定类。
     */
    private static function leafBoxOf(Element $element): LayoutCountBox
    {
        $box = $element->findBoxOf(LayoutCountBox::class);
        if (!$box instanceof LayoutCountBox) {
            self::fail('没找到 LayoutCountBox（夹具坏了）');
        }

        return $box;
    }

    /**
     * ★ 用**递归找**而不是"根必须是 `StatefulElement`"：本类有一组夹具把
     *   `AnimatedOpacity` 包在 `Padding(->repaintBoundary())` 里（验外层缓存），
     *   那时根是 `RenderProxyWidget` 的元素，直接判根就会误报"夹具坏了"。
     */
    private static function stateOf(Element $element): AnimatedOpacityState
    {
        $found = null;
        $walk = static function (Element $e) use (&$walk, &$found): void {
            if ($found === null && $e instanceof StatefulElement && $e->state() instanceof AnimatedOpacityState) {
                $found = $e->state();
            }
            $e->visitChildren($walk);
        };
        $walk($element);

        if (!$found instanceof AnimatedOpacityState) {
            self::fail('AnimatedOpacity 的 State 没找到（夹具坏了）');
        }

        return $found;
    }

    /**
     * 挂一棵 `Padding(->repaintBoundary()) → AnimatedOpacity → …`（模拟"在
     * `ScrollView` 里"：页面内容区就是一个重绘边界）。
     *
     * @return array{0: Element, 1: Frame, 2: RecordingSurface, 3: Widget}
     */
    private static function mountUnderBoundary(float $target): array
    {
        $leaf = new LayoutCountWidget(new Size(80.0, 40.0));
        $element = Padding::make(AnimatedOpacity::make($target, $leaf))
            ->all(8.0)
            ->repaintBoundary()
            ->createElement();
        $element->mount(null, smokeContext());

        $surface = new RecordingSurface();
        $surface->resize(320, 240, 1.0);
        $frame = new Frame($surface);
        $frame->run($element, self::constraints(), 0.0);

        return [$element, $frame, $surface, $leaf];
    }

    /** 一帧：先推动画（生产里由 `Application::frame()` 在 `Frame::run()` 之前做），再画 */
    private static function step(Element $el, Frame $frame, AnimatedOpacityState $state, float $ts): bool
    {
        $busy = $state->tickFrame($ts);
        $frame->run($el, self::constraints(), $ts);

        return $busy;
    }

    /** @param DisplayList $list */
    private static function countOps(DisplayList $list, int $op): int
    {
        $n = 0;
        for ($i = 0, $total = $list->count(); $i < $total; ++$i) {
            if ($list->opAt($i) === $op) {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * 目标变了 ⇒ 中间帧走一部分、收口 `===` 目标、动画期间不重排。
     */
    public function testTargetChangeGlidesWithoutRelayout(): void
    {
        [$el, $frame, $surface, $leafWidget] = self::mount(1.0);
        $state = self::stateOf($el);

        check(
            'AnimatedOpacity · 首帧 compose = 目标（**首次挂载不播**）',
            self::proxyOf($el)->composeOpacity() === 1.0 && self::leafBoxOf($el)->layoutCalls === 1,
            \sprintf('opacity=%.6g layout=%d', self::proxyOf($el)->composeOpacity(), self::leafBoxOf($el)->layoutCalls)
        );

        // 父控件换了个目标（这就是应用侧全部的代码）
        $el->update(AnimatedOpacity::make(0.3, $leafWidget));
        // ★ 这次 update 会换盒 ⇒ 先跑一帧让新盒落地，之后拿它当基线
        $frame->run($el, self::constraints(), 16.0);
        $baseLayout = self::leafBoxOf($el)->layoutCalls;
        $basePaint = self::leafBoxOf($el)->paintCalls;

        // ★ 首帧必须报"还要下一帧"：`AnimatedValue` 首帧只记起点、值没变，
        //   若用"值变了没有"当返回值，帧时钟当场落闸、动画停在 1.0（不报错）。
        $busy = self::step($el, $frame, $state, 32.0);
        check(
            'AnimatedOpacity · 目标变了之后**首帧仍报 busy**（否则帧时钟落闸、动画不动）',
            $busy === true,
            'busy=' . ($busy ? 'true' : 'false')
        );

        // 跑到中途：必须严格在 (0.3, 1.0) 之间 —— 证明是"滑"不是"跳"
        $ts = 32.0;
        $mid = null;
        for ($i = 0; $i < 8; ++$i) {
            $ts += 16.0;
            self::step($el, $frame, $state, $ts);
            $v = self::proxyOf($el)->composeOpacity();
            if ($v < 1.0 && $v > 0.3) {
                $mid = $v;
            }
        }
        check(
            'AnimatedOpacity · 中途帧 compose 严格在 (0.3, 1.0) 之间（是滑过去，不是闪现）',
            $mid !== null,
            'mid=' . ($mid ?? -1.0)
        );
        check(
            'AnimatedOpacity · 动画期间**一次都没重排**（合成不是布局输入）',
            self::leafBoxOf($el)->layoutCalls === $baseLayout,
            \sprintf('base=%d now=%d', $baseLayout, self::leafBoxOf($el)->layoutCalls)
        );

        // 收口
        // ★ 帧数按半衰期算：150ms 半衰期从 1.0 → 0.3 要 0.92s ⇒ 16ms × 90 = 1.44s
        //   （写 60 帧只有 0.96s，边界上会差一点点没落定）。
        for ($i = 0; $i < 90; ++$i) {
            $ts += 16.0;
            self::step($el, $frame, $state, $ts);
        }
        check(
            'AnimatedOpacity · 收口后 compose **精确等于**目标 0.3（`===`，不是约等于）',
            self::proxyOf($el)->composeOpacity() === 0.3,
            \sprintf('opacity=%.6g', self::proxyOf($el)->composeOpacity())
        );
        check(
            'AnimatedOpacity · 收口后 `tickFrame()` 返回 false（帧时钟可以落闸）',
            $state->tickFrame($ts + 16.0) === false,
            'still busy'
        );
        check(
            'AnimatedOpacity · 整段动画**仍然只排过那一次版**',
            self::leafBoxOf($el)->layoutCalls === $baseLayout,
            \sprintf('base=%d now=%d', $baseLayout, self::leafBoxOf($el)->layoutCalls)
        );
        check(
            'AnimatedOpacity · 但每帧都画了（省的是重排与重建，不是画面）',
            self::leafBoxOf($el)->paintCalls > $basePaint + 30,
            \sprintf('base=%d now=%d', $basePaint, self::leafBoxOf($el)->paintCalls)
        );

        check(
            'AnimatedOpacity · 不透明度真被画出来（命令流里有 `OP_LAYER_PUSH`）',
            self::countOps($surface->list(), DisplayList::OP_LAYER_PUSH) >= 1,
            '共 ' . $surface->list()->count() . ' 条命令'
        );
    }

    /**
     * ★★ **动画中途父重建 ⇒ 不跳到目标**。
     *
     * `build()` 交的是"当前动画值"而不是目标值。反过来写的话，重建那一刻
     * `setMods()` 会把合成槽重置成目标 ⇒ 画面瞬间跳到终点，下一帧再被拉回
     * 中间值 —— 一次肉眼可见的闪烁，且**没有任何报错**。
     *
     * ★ 这条能证伪的前提是"重建发生在**动画中途**"：在起点或终点重建，
     *   当前值与目标值恰好相等，两种实现分不出来。
     */
    public function testRebuildMidFlightDoesNotJump(): void
    {
        [$el, $frame, , $leafWidget] = self::mount(1.0);
        $state = self::stateOf($el);

        $el->update(AnimatedOpacity::make(0.2, $leafWidget));
        $frame->run($el, self::constraints(), 16.0);

        // 跑到中途（目标仍是 0.2）
        $ts = 16.0;
        for ($i = 0; $i < 6; ++$i) {
            $ts += 16.0;
            self::step($el, $frame, $state, $ts);
        }
        $mid = self::proxyOf($el)->composeOpacity();
        check(
            'AnimatedOpacity · 前置：动画确实在**中途**（0.2 < compose < 1.0）',
            $mid < 1.0 && $mid > 0.2,
            \sprintf('mid=%.6g', $mid)
        );

        // 目标不变，只是父控件重建了一次
        $el->update(AnimatedOpacity::make(0.2, $leafWidget));
        $frame->run($el, self::constraints(), $ts + 16.0);
        check(
            'AnimatedOpacity · 中途重建后 compose **仍是中途值**（不跳到目标 0.2）',
            self::proxyOf($el)->composeOpacity() === $mid,
            \sprintf('before=%.6g after=%.6g', $mid, self::proxyOf($el)->composeOpacity())
        );
    }

    /**
     * 反例：目标**没变**时不该有动画 —— 否则每次重建都白跑一段。
     */
    public function testSameTargetStaysStill(): void
    {
        [$el, $frame, , $leafWidget] = self::mount(0.6);
        $state = self::stateOf($el);

        $el->update(AnimatedOpacity::make(0.6, $leafWidget));
        $frame->run($el, self::constraints(), 16.0);
        check(
            'AnimatedOpacity · 反例：目标没变 ⇒ 不起动画（tickFrame 直接 false）',
            $state->tickFrame(32.0) === false && self::proxyOf($el)->composeOpacity() === 0.6,
            \sprintf('opacity=%.6g', self::proxyOf($el)->composeOpacity())
        );
    }

    /**
     * ★★★ **合成槽变化必须让外层重绘边界重录**（2026-10-29，真机抓到的"要点两次"）。
     *
     * `pushLayer(alpha)` 是**录进 `DisplayList` 的一条命令**，而 `LayerCache` 的
     * 命中判据是 `layoutEpoch + 尺寸`。只改合成槽时两者都不变 ⇒ 边界命中缓存、
     * 重放旧 alpha ⇒ **值在滑、屏上是死的**（真机现象：点一次毫无反应，再点一次
     * 触发 rebuild 才"突然"淡到位）。
     *
     * 判据落在**计数器**上（像素在这里分不出来：两种实现画出来的都是"某一帧的
     * 正确画面"，差别只在"哪一帧"）：
     *
     * | 判据 | 挡的退化 |
     * |---|---|
     * | 动画期间叶子 `paintCalls` 每帧涨 | 外层缓存命中 ⇒ 重放旧 alpha（本件的病灶） |
     * | **反例**：目标不变时 `paintCalls` 不涨 | 为了让上一条绿而把缓存整体关掉 |
     *
     * ★ 反例那条是必须的：`invalidateEnclosingLayer()` 写成"每次 `setCompose()`
     *   无条件失效"也全绿，但静止画面会一直重录。
     */
    public function testComposeChangeInvalidatesEnclosingRepaintBoundary(): void
    {
        // ── 控制组：每帧写**同一个**值 ⇒ 边界仍命中缓存 ⇒ 一次都不重录 ──
        // ★ 必须显式每帧写一次：动画静止时 `tickFrame()` 返回 null、根本不碰
        //   `setCompose()` ⇒ 那样测不出"值没变就不失效"这道守卫。
        [$el, $frame, , ] = self::mountUnderBoundary(1.0);
        $inner = self::stateOf($el)->element()?->effectiveBox();
        if ($inner === null) {
            self::fail('夹具坏了：拿不到 Opacity 的盒');
        }
        $base = self::leafBoxOf($el)->paintCalls;
        for ($i = 1; $i <= 5; ++$i) {
            $inner->setCompose(1.0);          // 与当前值相同
            $frame->run($el, self::constraints(), 16.0 * $i);
        }
        check(
            'AnimatedOpacity · 反例：写同值 ⇒ 外层边界仍命中缓存（不白重录）',
            self::leafBoxOf($el)->paintCalls === $base,
            \sprintf('base=%d now=%d（涨了 ⇒ 每帧无条件失效，静止画面白烧）', $base, self::leafBoxOf($el)->paintCalls)
        );

        // ── 实验组：动画中 ⇒ 每帧都要重录（否则重放的是旧 alpha） ──
        [$el2, $frame2, , $leafWidget2] = self::mountUnderBoundary(1.0);
        $state = self::stateOf($el2);
        $el2->update(Padding::make(AnimatedOpacity::make(0.2, $leafWidget2))->all(8.0)->repaintBoundary());
        $frame2->run($el2, self::constraints(), 16.0);

        $midBase = self::leafBoxOf($el2)->paintCalls;
        $ts = 16.0;
        for ($i = 0; $i < 6; ++$i) {
            $ts += 16.0;
            $state->tickFrame($ts);
            $frame2->run($el2, self::constraints(), $ts);
        }
        check(
            'AnimatedOpacity · 合成槽变了 ⇒ 外层边界**每帧重录**（否则屏上停在旧 alpha）',
            self::leafBoxOf($el2)->paintCalls > $midBase + 3,
            \sprintf('base=%d now=%d（不涨 ⇒ 重放了旧 alpha）', $midBase, self::leafBoxOf($el2)->paintCalls)
        );
    }

    /**
     * ★★ `Opacity` 存在的**唯一理由**：修饰符的合成槽一个盒只有一个，套两层会
     * **覆盖**而不是相乘；`Opacity` 每次包装自己的盒，所以能嵌套。
     *
     * 判据落在命令流上：两层各推一次层 ⇒ 2 条 `OP_LAYER_PUSH`。改成
     * `->opacity(0.5)->opacity(0.5)` 只有 1 条（后写的覆盖先写的，实际效果 0.5
     * 而不是 0.25）—— 画面上"都是半透明"，肉眼分不出，只有数命令能分。
     */
    public function testNestedOpacityMultipliesInsteadOfOverwriting(): void
    {
        $leaf = new LayoutCountWidget(new Size(80.0, 40.0));
        $root = Opacity::make(0.5, Opacity::make(0.5, $leaf))->createElement();
        $root->mount(null, smokeContext());

        $surface = new RecordingSurface();
        $surface->resize(320, 240, 1.0);
        $frame = new Frame($surface);
        $frame->run($root, self::constraints(), 0.0);

        check(
            'Opacity · 两层各推一次层（相乘 0.25，而不是覆盖成 0.5）',
            self::countOps($surface->list(), DisplayList::OP_LAYER_PUSH) === 2,
            'pushLayer=' . self::countOps($surface->list(), DisplayList::OP_LAYER_PUSH)
        );
        check(
            'Opacity · 叶子盒照旧只排一次版（包一层不动布局）',
            self::leafBoxOf($root)->layoutCalls === 1,
            'layout=' . self::leafBoxOf($root)->layoutCalls
        );
    }
}
