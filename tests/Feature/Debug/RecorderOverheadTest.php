<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\Dev\Bench\RecorderOverhead;
use Drift\Dev\Tests\Support\SmokeTestCase;

/** 开销量具必须有数字，且四档确实走各自的采集路径。 */
final class RecorderOverheadTest extends SmokeTestCase
{
    public function testFourBudgetsReportRealNumbers(): void
    {
        $rows = 40;
        $result = RecorderOverhead::measure($rows, 8);

        check('四档都量到了', \array_keys($result) === ['off', 'frames', 'geometry', 'full']);
        check('关闭档没有记录', ($result['off']['recordedFrames'] ?? -1) === 0);
        check('frames-only 只记帧', ($result['frames']['recordedFrames'] ?? 0) === 19
            && ($result['frames']['geometryFrames'] ?? -1) === 0
            && ($result['frames']['networkRequests'] ?? -1) === 0,
            \json_encode($result['frames']));
        check('geometry 档真的采了几何', ($result['geometry']['geometryFrames'] ?? 0) > 0);
        check('full 档也采了几何', ($result['full']['geometryFrames'] ?? 0) > 0);
        check(
            'frames-only 帧预算占用低于 2%',
            ($result['frames']['budgetPct'] ?? 100) < RecorderOverhead::FRAMES_ONLY_BUDGET_PCT,
            \json_encode($result['frames']),
        );

        $this->flushChecks();
    }
}
