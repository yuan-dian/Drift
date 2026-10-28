<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Widget\ScrollBarFade;

/**
 * 滚动条空闲淡出（替代"滚动惯性"：滚轮是离散输入，见 `ScrollBarFade` 类注释）。
 *
 * 判据（与 `tools/smoke.php` ㉒ 段"滚动条空闲淡出"部分一致）：
 *   ① 从没滚过 ⇒ 恒亮 1.0 且 tick 直接返回 false（不建槽、不占帧，静态截图一个像素不变）；
 *   ② 刚滚过 ⇒ 立刻全亮（不会先淡入一遍）；空闲未满 900ms 仍全亮（停手就淡是另一种毛病）；
 *   ③ 超时淡到 0.35 而不是 0（消失之后用户不知道这里能滚）；淡完收口，再滚能重新起飞。
 *
 * ★ 纯状态机，零渲染依赖 ⇒ Unit 层。
 */
final class ScrollBarFadeTest extends SmokeTestCase
{
    public function testNeverScrolledStaysFullyOpaque(): void
    {
        $barFade = new ScrollBarFade();
        check(
            '滚动条 · 从没滚过 ⇒ 恒亮 1.0（静态截图的像素一个不变）',
            $barFade->opacity() === 1.0,
            'o=' . $barFade->opacity(),
        );
        check('滚动条 · 没滚过时 tick 直接返回 false（不建槽、不占帧）', $barFade->tick(0.0) === false);

        $this->flushChecks();
    }

    public function testFadesOnlyAfterIdleThreshold(): void
    {
        $barFade = new ScrollBarFade();
        $barFade->noteScroll();
        check('滚动条 · 刚滚过 ⇒ 立刻全亮（不会先淡入一遍）', $barFade->opacity() === 1.0, 'o=' . $barFade->opacity());

        for ($bfi = 1; $bfi <= 51; $bfi++) {
            $barFade->tick($bfi * 16.0);   // 到 816ms，还没到 900ms 的空闲线
        }
        check(
            '滚动条 · 空闲未满 900ms ⇒ 仍全亮（停手就淡是另一种毛病）',
            $barFade->opacity() === 1.0,
            'o=' . $barFade->opacity(),
        );

        for ($bfi = 52; $bfi <= 120; $bfi++) {
            $barFade->tick($bfi * 16.0);
        }
        check(
            '滚动条 · 空闲超 900ms ⇒ 淡到 0.35（不是 0：消失之后用户不知道这里能滚）',
            abs($barFade->opacity() - 0.35) < 0.02,
            'o=' . $barFade->opacity(),
        );
        check(
            '滚动条 · 淡完就收口（再 tick 返回 false ⇒ 帧时钟落闸）',
            $barFade->tick(121.0 * 16.0) === false,
        );

        $barFade->noteScroll();
        for ($bfi = 1; $bfi <= 12; $bfi++) {
            $barFade->tick(121.0 * 16.0 + $bfi * 16.0);
        }
        check(
            '滚动条 · 再滚一下 ⇒ 很快回到全亮（收口后仍能重新起飞）',
            $barFade->opacity() > 0.9,
            'o=' . $barFade->opacity(),
        );

        $this->flushChecks();
    }
}
