<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\State;

use Drift\Core\Pipeline;
use Drift\Dev\Smoke\State\HoverWidget;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Widget\State;

/**
 * `settle()` 多轮收敛：**计数不漂移**（`03` §5.1 / §5.3）。
 *
 * ★ `pendingDirty` 是进程级静态，前面用例卸载时留的脏要排除在外 —— 所以先取基线
 *   再比差值，否则"计数不漂移"会被别人的泄漏判红（这是量具的自保，不是松判据）。
 * ★ 判据必须是"100 轮之后**回**到基线"，不是"每轮都等于基线"：
 *   帧内短暂非零是正常的，泄漏的表现是单调增长。
 */
final class SettleDriftTest extends SmokeTestCase
{
    public function testPendingDirtyDoesNotDriftOverHundredFrames(): void
    {
        $ctx = smokeContext();

        $pendingBase = Pipeline::current()->pendingDirty();

        $driftElement = (new HoverWidget())->createElement();
        $driftElement->mount(null, $ctx);
        $driftState = $driftElement->state();
        for ($i = 1; $i <= 100; $i++) {
            $driftState->onRowHover($i); // Cell 换新值 ⇒ 标脏
            $driftElement->settle();
        }
        check(
            'settle · 计数不漂移（100 帧后 pendingDirty 与开局一致）',
            Pipeline::current()->pendingDirty() === $pendingBase,
            'pending=' . Pipeline::current()->pendingDirty() . ' base=' . $pendingBase
        );
        $driftElement->unmount();

        $this->flushChecks();
    }
}
