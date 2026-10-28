<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Smoke\App\RevealApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\LazyFixtures;
use Drift\Dev\Tests\Support\ScrollFixtures;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Widget\ScrollViewState;

/**
 * 滚动容器的**键盘可达性**（`04` §6.3 的 `ensureVisible`，W6 L4-6）。
 *
 * 守的是"Tab 把焦点移出视口时，焦点环会画在视口外 ⇒ 用户看不到焦点在哪"。
 * 算术只有一处（`RenderScrollView::revealDelta()`），两个 `Scrollable` 实现
 * 都只做"找自己的盒 → 转交"。
 *
 * ★ 每条都写了它抓的反例 —— 没有反例的断言是空转。
 * ★ 视口 200 高、行高 40：`240 + 8 − 200 = 48`、`200 + 8 − 200 = 8` —— 三个
 *   候选值（40 / 48 / 200）互不相等，"每次只补差量"与"每次整屏对齐"分得开。
 */
final class EnsureVisibleTest extends SmokeTestCase
{
    public function testRevealDeltaForTargetAboveViewport(): void
    {
        // ① 盒级算术（不经过 State / 应用）：目标在视口上方 ⇒ 负增量
        //    反例：忘了减 `margin` ⇒ −300；把视口当成窗口高 240 ⇒ 差 40。
        $app = new RevealApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 200.0)));
        $app->scrollState()?->scrollBy(400.0);
        $app->frame(16.0, BoxConstraints::tight(new Size(320.0, 200.0)));

        // ★ 盒在重建时**换新**（Widget 不可变 ⇒ 每次 build 造一个新盒）⇒ 必须在
        //   frame() 之后重新取。拿首帧那个盒（`scrollOffset` 恒 0）算出来永远是 0 ——
        //   这条是首跑红掉的：`revealDelta()` 得 0，看着像公式错、其实是基准旧。
        $box = $app->scrollBox();
        $up = $box?->revealDelta(new Rect(0.0, 100.0, 100.0, 20.0), 8.0, $box->appliedOffset(Axis::Vertical)) ?? -999.0;
        check(
            'ensureVisible · revealDelta：目标在视口上方 ⇒ −308（100 − 8 − 400）',
            $up === -308.0,
            (string) $up,
        );
    }

    public function testStateLevelScrollsTheOffset(): void
    {
        // ② 真滚过去（`04` §12.2 #10）
        //    反例：只改盒不算 State ⇒ offset 仍是 400；夹上界夹错 ⇒ 停在 600。
        $app = $this->scrolledApp();
        $state = $app->scrollState();
        $state?->ensureVisible(new Rect(0.0, 100.0, 100.0, 20.0));
        check(
            'ensureVisible · 上滚到 top − 8（400 → 92：增量 −308 加到 400 上）',
            $state?->offset() === 92.0,
            (string) ($state?->offset() ?? -1.0),
        );
    }

    public function testAlreadyVisibleDoesNotDirty(): void
    {
        // ③ 已在视口内 ⇒ 不动、**不标脏**（`04` §12.2 #11）
        //    反例：无条件 `scrollBy(0)` 会走 `setState` ⇒ 每帧重建一次（白烧）。
        $app = $this->scrolledApp();
        $state = $app->scrollState();
        $state?->ensureVisible(new Rect(0.0, 100.0, 100.0, 20.0));   // 先滚到 92
        $app->frame(32.0, BoxConstraints::tight(new Size(320.0, 200.0)));
        $state?->ensureVisible(new Rect(0.0, 120.0, 100.0, 20.0));   // 120..140 全在 92..292 内

        check(
            'ensureVisible · 已在视口内 ⇒ 偏移不变且不标脏（省一次重建）',
            $state?->offset() === 92.0 && $state?->needsBuild() === false,
            'offset=' . ($state?->offset() ?? -1.0)
                . ' dirty=' . ($state?->needsBuild() === true ? '1' : '0'),
        );
    }

    public function testScrollsDownToBottomPlusMargin(): void
    {
        // ④ 下滚：`bottom + 8 − 视口`
        //    反例：把目标起点当终点 ⇒ 只滚 12（108 → 120）而不是 420。
        $app = $this->scrolledApp();
        $state = $app->scrollState();
        $state?->ensureVisible(new Rect(0.0, 100.0, 100.0, 20.0));   // → 92
        $state?->ensureVisible(new Rect(0.0, 700.0, 100.0, 20.0));
        check(
            'ensureVisible · 下滚到 bottom + 8 − 视口（92 → 528，增量 436）',
            $state?->offset() === 528.0,
            (string) ($state?->offset() ?? -1.0),
        );
    }

    public function testTallerThanViewportAlignsTopAndIsIdempotent(): void
    {
        // ⑤ 目标比视口还高（300 > 200）⇒ 上沿对齐，且**幂等**
        //    反例：不加这条分支会先滚到底、再滚回顶 —— 连着调两次在两端来回跳。
        $app = $this->scrolledApp();
        $state = $app->scrollState();
        $state?->scrollBy(-400.0);   // 回到 0
        $state?->ensureVisible(new Rect(0.0, 100.0, 100.0, 300.0));
        $state?->ensureVisible(new Rect(0.0, 100.0, 100.0, 300.0));
        check(
            'ensureVisible · 目标比视口高 ⇒ 上沿对齐 92 且幂等（第二次不动）',
            $state?->offset() === 92.0,
            (string) ($state?->offset() ?? -1.0),
        );
    }

    public function testHorizontalViewportUsesXAndWidth(): void
    {
        // ⑥ 横向视口取 x/width
        //    反例：只写纵向公式 ⇒ 横向滚动容器里 Tab 永远不跟（静默不生效）。
        $state = ScrollFixtures::stateOf(ScrollFixtures::horizontal());
        $state?->scrollBy(400.0);

        // ★ 用**下滚**验轴向：上滚的公式（`top − margin − offset`）里没有视口，
        //   横向纵向算出同一个值 ⇒ 验不出"取的是 width 还是 height"。
        //   下滚：`728 − 400 − 320 = 8` ⇒ 408；若错取高 100 ⇒ 628（两个值分得开）。
        $state?->ensureVisible(new Rect(700.0, 0.0, 20.0, 10.0));
        check(
            'ensureVisible · 横向视口用 x/width（下滚 400 → 408；按高算会得 628）',
            $state instanceof ScrollViewState && $state->offset() === 408.0,
            $state instanceof ScrollViewState ? (string) $state->offset() : 'no-state',
        );
    }

    public function testLazyColumnSharesTheSameArithmetic(): void
    {
        // ⑦ LazyColumn 是**同一形状**的实现（内容坐标 = 完整内容坐标）
        //    反例：把 `sliceOrigin` 再补一遍 ⇒ 488 变成 448（差两行）。
        $root = LazyFixtures::mount(LazyFixtures::column(500, 40.0));
        $state = LazyFixtures::stateOf($root);
        $state?->ensureVisible(new Rect(0.0, 700.0, 100.0, 20.0));
        check(
            'ensureVisible · LazyColumn 同一条算术（488 = 720 + 8 − 240）',
            $state?->offset() === 488.0,
            (string) ($state?->offset() ?? -1.0),
        );
    }

    public function testTabIntoViewportAndBack(): void
    {
        // ⑧ 真消费：Tab 换焦点 ⇒ 自动滚进视口（6 次 Tab 落到 b5，y 200..240）
        //    反例：`revealFocused()` 没接上 ⇒ offset 恒 0（Tab 走得到、看不见）。
        $app = new RevealApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 200.0)));
        $state = $app->scrollState();
        $ts = 0.0;
        for ($i = 0; $i < 6; $i++) {
            $ts += 16.0;
            $app->dispatchInput(KeyEvent::down(KeyChord::tab()));
            $app->frame($ts, BoxConstraints::tight(new Size(320.0, 200.0)));
        }
        check(
            'ensureVisible · Tab 到视口外的按钮 ⇒ 自动滚进来（6 次 ⇒ 焦点 b5、offset 48）',
            $app->focusedLabel() === 'b5' && $state?->offset() === 48.0,
            'focus=' . ($app->focusedLabel() ?? 'null') . ' offset=' . ($state?->offset() ?? -1.0),
        );

        // ⑨ 反向同样跟：Shift+Tab 回退到 b0 ⇒ offset 回 0
        //    反例：只在 `focusNext()` 侧接线 ⇒ 反向导航时焦点跑到视口上方。
        for ($i = 0; $i < 5; $i++) {
            $ts += 16.0;
            $app->dispatchInput(KeyEvent::down(KeyChord::shift('Tab')));
            $app->frame($ts, BoxConstraints::tight(new Size(320.0, 200.0)));
        }
        check(
            'ensureVisible · Shift+Tab 反向同样跟（回到 b0 ⇒ offset 回 0）',
            $app->focusedLabel() === 'b0' && $state?->offset() === 0.0,
            'focus=' . ($app->focusedLabel() ?? 'null') . ' offset=' . ($state?->offset() ?? -1.0),
        );
    }

    /** ① 之后的状态：offset 已滚到 400，盒也重建过一轮 */
    private function scrolledApp(): RevealApplication
    {
        $app = new RevealApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 200.0)));
        $app->scrollState()?->scrollBy(400.0);
        $app->frame(16.0, BoxConstraints::tight(new Size(320.0, 200.0)));

        return $app;
    }
}
