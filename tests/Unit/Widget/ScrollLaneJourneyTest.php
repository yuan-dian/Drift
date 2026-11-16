<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Widget\Curve;
use Drift\Widget\ScrollLane;

/**
 * `ScrollLane::animateTo()` 的判据（P1-4 第一条）。
 *
 * 与滚轮 `motion` 的分工是**语义**：滚轮是"追目标"的指数趋近，这里要的是
 * 定时长 + 曲线。两条路都不能改 `scrollBy` 的"调完就到"语义（81 处依赖）。
 *
 * 判据全部从公共读数 `offset()` 观察，不读 `journey` / `progress()`：
 * 变异「每帧直接把 offset 设成目标」/「用户接管后还继续拽」都必须红。
 */
final class ScrollLaneJourneyTest extends SmokeTestCase
{
    private static function identity(float $value): float
    {
        return $value;
    }

    /** 推一帧；`$apply` 与生产 State 同形（Δ → move → 回写盒值） */
    private static function step(ScrollLane $lane, float $ts, ?callable $push = null): bool
    {
        return $lane->tick($ts, static function (float $delta) use ($lane, $push): void {
            $lane->move($delta, $push ?? self::identity(...));
        });
    }

    public function testSamplesIntermediateOffsetsAndLandsExactly(): void
    {
        $lane = new ScrollLane();
        $lane->animateTo(100.0, 160.0, Curve::linear());

        $seen = [];
        for ($i = 1; $i <= 30; $i++) {
            self::step($lane, $i * 16.0);
            $seen[] = $lane->offset();
        }

        $unique = array_values(array_unique($seen));
        $monotonic = true;
        foreach ($unique as $i => $value) {
            if ($i > 0 && $value < $unique[$i - 1]) {
                $monotonic = false;
            }
        }

        check(
            'ScrollLane · animateTo 中途取样多帧（不是一步到位）',
            count($unique) > 4,
            'samples=' . count($unique),
        );
        check(
            'ScrollLane · 取样单调不回退',
            $monotonic,
            json_encode($unique),
        );
        check(
            'ScrollLane · 终点精确落到目标（收口帧不丢最后一小段）',
            $lane->offset() === 100.0,
            'o=' . \sprintf('%.6g', $lane->offset()),
        );
        check(
            'ScrollLane · 收口后不再占帧',
            self::step($lane, 32 * 16.0) === false,
            '',
        );

        $this->flushChecks();
    }

    public function testUserMoveInterruptsJourney(): void
    {
        $lane = new ScrollLane();
        $lane->animateTo(100.0, 400.0, Curve::linear());
        for ($i = 1; $i <= 6; $i++) {
            self::step($lane, $i * 16.0);
        }
        $at = $lane->offset();
        check('ScrollLane · 中断前确实在途中', $at > 0.0 && $at < 100.0, 'o=' . $at);

        $lane->move(5.0, self::identity(...));   // 用户接管
        for ($i = 7; $i <= 60; $i++) {
            self::step($lane, $i * 16.0);
        }

        check(
            'ScrollLane · 用户一动，定时滚动立即让位（不把画面继续拽到 100）',
            $lane->offset() === $at + 5.0,
            'o=' . \sprintf('%.6g', $lane->offset()),
        );

        $this->flushChecks();
    }

    public function testGlideInterruptsJourney(): void
    {
        $lane = new ScrollLane();
        $lane->animateTo(100.0, 400.0, Curve::linear());
        for ($i = 1; $i <= 6; $i++) {
            self::step($lane, $i * 16.0);
        }

        $lane->glide(10.0);   // 滚轮接管
        for ($i = 7; $i <= 80; $i++) {
            self::step($lane, $i * 16.0);
        }

        check(
            'ScrollLane · 滚轮接管后目标不再是 100（只走滚轮滑行）',
            $lane->offset() < 100.0 && $lane->offset() > 0.0,
            'o=' . \sprintf('%.6g', $lane->offset()),
        );

        $this->flushChecks();
    }

    public function testNewJourneySupersedesOld(): void
    {
        $lane = new ScrollLane();
        $lane->animateTo(100.0, 400.0, Curve::linear());
        for ($i = 1; $i <= 6; $i++) {
            self::step($lane, $i * 16.0);
        }

        $lane->animateTo(0.0, 160.0, Curve::linear());   // 反向
        for ($i = 7; $i <= 40; $i++) {
            self::step($lane, $i * 16.0);
        }

        check(
            'ScrollLane · 后一次调用顶掉前一次（反向回到 0，不被旧目标拽走）',
            $lane->offset() === 0.0,
            'o=' . \sprintf('%.6g', $lane->offset()),
        );

        $this->flushChecks();
    }

    public function testBoundaryStopsInsteadOfGrinding(): void
    {
        $lane = new ScrollLane(40.0);
        $stuck = static fn(float $_): float => 40.0;   // 盒永远夹回 40
        $lane->animateTo(100.0, 160.0, Curve::linear());

        $first = self::step($lane, 16.0, $stuck);   // 起手帧 δ=0，不判撞墙
        check(
            'ScrollLane · 目标够不到 ⇒ 起手后立刻收口，不朝边界空转',
            $first === true && self::step($lane, 32.0, $stuck) === false
                && self::step($lane, 48.0, $stuck) === false,
            'o=' . \sprintf('%.6g', $lane->offset()),
        );

        $this->flushChecks();
    }
}
