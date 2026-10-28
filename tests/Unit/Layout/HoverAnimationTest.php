<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Layout;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderButton;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\SizedBox;

/**
 * 盒级跟手动画（`AnimatedValue` + `RenderObject::hoverProgress`，2026-10-26）。
 *
 * 判据（与 `tools/smoke.php` ㉒ 段"盒级跟手动画"部分一致）：
 *   ① 首帧必须自报"在跑"—— 报 false 会让帧时钟当场停摆、值永远停在起点；
 *   ② 单调逼近目标；收口后自己停下（否则空闲空转）；
 *   ③ 状态变化必须**画出来**：给按钮套 8px 留白，收口后"盒外那一圈"从全白变成有墨。
 *
 * ★ 时间由 `tickAnimations(ms)` 手动推进，不需要 `Application::frame()` ⇒ Unit 层。
 * ★ 第 ③ 条必须从**盒外**数墨：只数盒内会把"状态色变深"当成"浮起"（盒内两者无法区分）。
 */
final class HoverAnimationTest extends SmokeTestCase
{
    /** 数盒外留白那一圈有多少非白像素（`$pad` 是四边留白宽度） */
    private function ringInk(RenderBox $box, int $width, int $height, int $pad): int
    {
        $canvas = spinCanvas($box, $width, $height);

        $n = 0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $inside = $x >= $pad && $x < $width - $pad && $y >= $pad && $y < $height - $pad;
                if ($inside) {
                    continue;
                }
                if (!$canvas->pixelAt($x, $y)->equals(\Drift\Rendering\Color::white())) {
                    $n++;
                }
            }
        }

        return $n;
    }

    public function testProgressStartsAtZeroAndRidesTheRamp(): void
    {
        $ctx = smokeContext();
        $hoverBtn = Button::make('悬停浮起')->variant(ButtonVariant::Default)->createRenderObject($ctx);
        $hoverBtn->layout(BoxConstraints::tight(new Size(120.0, 36.0)));
        check(
            'hover · 从未悬停过 ⇒ progress 就是 0（不建槽，也没有半截状态）',
            $hoverBtn->hoverProgress() === 0.0,
            'p=' . $hoverBtn->hoverProgress(),
        );

        $hoverBtn->setHovered(true);
        check(
            'hover · 刚进入那一帧 ⇒ 值仍是 0（一进来就是 1 的话"有过渡"是假的）',
            $hoverBtn->hoverProgress() === 0.0,
            'p=' . $hoverBtn->hoverProgress(),
        );
        check(
            'hover · 首帧 tick 必须自报"在跑"（报 false ⇒ 帧时钟停摆 ⇒ 永远停在起点）',
            $hoverBtn->tickAnimations(1000.0) === true,
        );

        $hoverSamples = [];
        for ($hfi = 1; $hfi <= 24; $hfi++) {
            $hoverBtn->tickAnimations(1000.0 + $hfi * 16.0);
            $hoverSamples[] = $hoverBtn->hoverProgress();
        }
        $hoverMonotonic = true;
        for ($hfi = 1; $hfi < count($hoverSamples); $hfi++) {
            if ($hoverSamples[$hfi] < $hoverSamples[$hfi - 1]) {
                $hoverMonotonic = false;
            }
        }
        check(
            'hover · 24 帧内单调逼近 1（指数逼近不会回头）',
            $hoverMonotonic && $hoverBtn->hoverProgress() > 0.98,
            'p=' . $hoverBtn->hoverProgress() . ' monotonic=' . ($hoverMonotonic ? 'yes' : 'no'),
        );
        check(
            'hover · 走到目标就收口（tick 返回 false ⇒ 帧时钟能落闸、空闲不空转）',
            $hoverBtn->tickAnimations(1000.0 + 25 * 16.0) === false,
        );

        $this->flushChecks();
    }

    public function testLiftDrawsInkOutsideTheBox(): void
    {
        $ctx = smokeContext();

        // —— 浮起真的画了墨：给按钮套 8px 留白，收口后"盒外那一圈"必须从全白变成有墨 ——
        $hoverHostEl = SizedBox::make(Button::make('影')->variant(ButtonVariant::Default))
            ->padding(EdgeInsets::all(8.0))
            ->createElement();
        $hoverHostEl->mount(null, $ctx);
        $hoverHostBox = $hoverHostEl->effectiveBox();
        $hoverHostBox?->layout(BoxConstraints::loose(new Size(300.0, 200.0)));
        $hoverHostSize = $hoverHostBox?->size();
        $hoverInner = $hoverHostEl->findBoxOf(RenderButton::class);

        if ($hoverHostBox !== null && $hoverHostSize !== null && $hoverInner !== null) {
            $ringW = (int) \round($hoverHostSize->width);
            $ringH = (int) \round($hoverHostSize->height);
            $ringBefore = $this->ringInk($hoverHostBox, $ringW, $ringH, 8);

            $hoverInner->setHovered(true);
            $hoverInner->tickAnimations(2000.0);
            for ($hfi = 1; $hfi <= 30; $hfi++) {
                $hoverInner->tickAnimations(2000.0 + $hfi * 16.0);
            }
            $ringAfter = $this->ringInk($hoverHostBox, $ringW, $ringH, 8);

            check(
                'hover · 浮起把影子画到盒外（静息时那一圈全白，收口后有墨）',
                $ringBefore === 0 && $ringAfter > 20,
                'ringBefore=' . $ringBefore . ' ringAfter=' . $ringAfter,
            );
        } else {
            check('hover · 浮起把影子画到盒外（静息时那一圈全白，收口后有墨）', false, '夹具没挂上');
        }

        $this->flushChecks();
    }
}
