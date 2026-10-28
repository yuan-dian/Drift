<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Tests\Support\ScrollFixtures;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;

/**
 * 单轴容器的**按轴语义**（`Scrollable::scrollByAxis`）。
 *
 * 主轴由 Widget 决定（`ScrollView(..., $vertical)`），不由调用方猜：问错的那根轴
 * 应该**什么都不做**，而不是报错，也不是"有溢出就换个方向跑"。
 *
 * ★ 这条原本长在 smoke 的 Table 段里，靠 `ensureVisible` 段的 `$revState`/`$hState`
 *   活着（相距 3000 行）。主题上它属于滚动容器而不是表格 —— 与 ensureVisible
 *   一起迁出来时顺带独立成文件，免得下次改 ensureVisible 又把它带崩。
 */
final class ScrollAxisTest extends SmokeTestCase
{
    public function testWrongAxisIsANoOp(): void
    {
        $vertical = ScrollFixtures::vertical();
        $horizontal = ScrollFixtures::horizontal();
        $vState = ScrollFixtures::stateOf($vertical);
        $hState = ScrollFixtures::stateOf($horizontal);

        $vState?->scrollBy(400.0);
        $hState?->scrollBy(400.0);
        $vBefore = $vState?->offset() ?? -1.0;
        $hBefore = $hState?->offset() ?? -1.0;

        $vState?->scrollByAxis(Axis::Horizontal, 50.0);
        $hState?->scrollByAxis(Axis::Vertical, 50.0);
        check(
            'Scroll · 按轴：纵向视口收到 Horizontal / 横向视口收到 Vertical ⇒ 都不动',
            $vState !== null && $hState !== null
                && $vState->offset() === $vBefore
                && $hState->offset() === $hBefore,
            'v=' . (string) ($vState?->offset() ?? -1.0) . ' h=' . (string) ($hState?->offset() ?? -1.0),
        );
    }

    public function testMainAxisTakesEffect(): void
    {
        $horizontal = ScrollFixtures::horizontal();
        $state = ScrollFixtures::stateOf($horizontal);
        $state?->scrollBy(400.0);
        $before = $state?->offset() ?? -1.0;

        $state?->scrollByAxis(Axis::Horizontal, 50.0);
        check(
            'Scroll · 按轴：横向视口收到 Horizontal ⇒ 生效（主轴由 Widget 决定，不由调用方猜）',
            $state !== null && $state->offset() === $before + 50.0,
            'h=' . (string) ($state?->offset() ?? -1.0) . ' expect=' . ($before + 50.0),
        );
    }
}
