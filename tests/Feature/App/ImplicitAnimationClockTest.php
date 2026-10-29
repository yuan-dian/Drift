<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\Dev\Smoke\App\FadeApplication;
use Drift\Dev\Smoke\App\FadeFixtureState;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\StatefulElement;

/**
 * **settle 期间起飞的动画必须叫醒帧时钟**（2026-10-29，真机抓到）。
 *
 * ## 现象与根因
 *
 * 真机：在示例台「动画 Motion」点「淡入」，卡片**一动不动**；再点一次才动。
 *
 * ```text
 * Application::frame()
 *   ⓸ tickAnimations()      ← 目标还是旧的 ⇒ 看不到"刚起飞" ⇒ animating=false
 *   Frame::run()
 *      settle → didUpdateWidget() → toward(新目标)   ← 动画在这里才开始
 *   ⇒ wantsFrame() === false ⇒ 帧时钟落闸 ⇒ 动画永远不动
 * ```
 *
 * 第二次点击能救回来，是因为那时 ⓸ 才看到"上一帧已经起飞了"。所以症状是
 * **"要点两次"**，而不是"完全没反应" —— 这也是它一直没被当成 bug 的原因。
 *
 * ## 判据
 *
 * | 判据 | 挡的退化 |
 * |---|---|
 * | 目标变了那一帧 `wantsFrame()` **为真** | 不叫醒时钟 ⇒ 动画停在起点（不报错） |
 * | 首帧后连续几帧值真的动了 | `requestFrame()` 写成了"多跑一帧就收工" |
 * | 目标没变 ⇒ `wantsFrame()` 为假 | 每帧都无条件 `requestFrame()` ⇒ 空闲 60fps 空转 |
 *
 * ★ 第三条是**反例方向**：只测前两条，把 `requestFrame()` 写成无条件调用也全绿。
 */
final class ImplicitAnimationClockTest extends SmokeTestCase
{
    private static function constraints(): BoxConstraints
    {
        return BoxConstraints::tight(new Size(320.0, 240.0));
    }

    /** @return array{0: FadeApplication, 1: FadeFixtureState} */
    private static function mount(): array
    {
        $app = new FadeApplication();
        $app->attach(new SurfaceProbe(), self::constraints());

        $root = $app->rootElement();
        if (!$root instanceof StatefulElement) {
            self::fail('夹具根不是 StatefulElement（夹具坏了）');
        }
        $state = $root->state();
        if (!$state instanceof FadeFixtureState) {
            self::fail('夹具 State 类型不对（夹具坏了）');
        }

        return [$app, $state];
    }

    private static function composeOf(FadeApplication $app): float
    {
        $box = $app->rootElement()?->effectiveBox();
        if ($box === null) {
            self::fail('夹具没造出盒（夹具坏了）');
        }

        return $box->composeOpacity();
    }

    public function testTargetChangeRearmsTheClock(): void
    {
        [$app, $state] = self::mount();

        check(
            '隐式动画 · 前置：挂载后静止（首次挂载不播）⇒ wantsFrame() 为假',
            $app->wantsFrame() === false && self::composeOf($app) === 1.0,
            \sprintf('opacity=%.6g', self::composeOf($app))
        );

        $state->setTarget(0.2);
        $app->frame(16.0, self::constraints());

        // ★★ 本件的主判据：这一帧的 settle 里才 toward() ⇒ 帧时钟必须被重新上膛
        check(
            '隐式动画 · 目标变了那一帧 **wantsFrame() 必须为真**（否则动画停在起点、要点两次）',
            $app->wantsFrame() === true,
            'wantsFrame=false ⇒ 帧时钟落闸'
        );

        // 再跑几帧：值真的滑过去（不是"只上膛不推进"）
        for ($i = 2; $i <= 10; ++$i) {
            $app->frame(16.0 * $i, self::constraints());
        }
        $mid = self::composeOf($app);
        check(
            '隐式动画 · 上膛之后值真的滑过去了（严格在 0.2 与 1.0 之间）',
            $mid > 0.2 && $mid < 1.0,
            \sprintf('opacity=%.6g', $mid)
        );

        // 跑到收口，然后反例方向：静止后不该再要帧
        for ($i = 11; $i <= 90; ++$i) {
            $app->frame(16.0 * $i, self::constraints());
        }
        check(
            '隐式动画 · 收口到目标 0.2（`===`）',
            self::composeOf($app) === 0.2,
            \sprintf('opacity=%.6g', self::composeOf($app))
        );
        check(
            '隐式动画 · 反例：收口后 `wantsFrame()` 为假（空闲不空转）',
            $app->wantsFrame() === false,
            'wantsFrame=true ⇒ 空闲还在烧 CPU'
        );
    }

    /**
     * 反例方向之二：**目标没变**时重建一次，不该叫醒时钟。
     *
     * 否则每次父控件重建都白跑一段（`requestFrame()` 写成无条件调用就是这个症状）。
     */
    public function testSameTargetDoesNotRearmTheClock(): void
    {
        [$app, $state] = self::mount();

        $state->setTarget(1.0);          // 与当前目标相同
        $app->frame(16.0, self::constraints());

        check(
            '隐式动画 · 反例：目标没变 ⇒ 不叫醒时钟（wantsFrame() 仍为假）',
            $app->wantsFrame() === false,
            'wantsFrame=true ⇒ 每次重建都白跑一段动画'
        );
    }
}
