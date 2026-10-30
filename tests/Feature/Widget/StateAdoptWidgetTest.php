<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Smoke\App\ProbeWidget;
use Drift\Dev\Smoke\App\StateAdoptApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;

/**
 * `State::adoptWidget()` —— **`didUpdateWidget()` 不再要求覆写者调 `parent::`**
 * （2026-10-30）。
 *
 * ## 钉的是什么
 *
 * 契约："框架在调用**用户钩子**之前先把新描述落到 `$this->widget`"。
 * 判据因此只能是：**一个故意漏调 parent 的覆写者**，在换参数之后从
 * `$this->widget()` 读到**新**值。
 *
 * ★ 这是本仓少见的"测一个**反常规**写法"的用例 —— 因为被测的正是"错误写法不再
 *   出错"。既有四处覆写全都调了 parent，所以常规用例永远碰不到这一支。
 */
final class StateAdoptWidgetTest extends SmokeTestCase
{
    private const W = 400.0;

    private const H = 300.0;

    public function testOverridingStateReadsNewWidgetWithoutCallingParent(): void
    {
        $surface = new SurfaceProbe();
        $surface->resize((int) self::W, (int) self::H, 1.0);
        $constraints = BoxConstraints::tight(new Size(self::W, self::H));

        $app = new StateAdoptApplication();
        $app->attach($surface, $constraints);
        $app->frame(16.0, $constraints);

        $host = $app->host;
        $state = ProbeWidget::$last;

        check('夹具 · 首帧建出 host State', $host !== null);
        check('夹具 · 首帧建出子 State', $state !== null);

        if ($host === null || $state === null) {
            $this->flushChecks();

            return;   // 夹具没搭起来，后面的断言没有意义（且会致命错误）
        }

        // ★ 换参数：`ProbeWidget` **类型不变** ⇒ `Element::update()`（不是新建）
        //   ⇒ 唯一会调 `didUpdateWidget()` 的路径。
        $host->change('b');
        $app->frame(32.0, $constraints);

        check(
            'State · 漏调 parent::didUpdateWidget() 也能从 $this->widget() 读到新描述',
            $state->seenFromField === 'b',
            '修好前会是 \'a\'（$this->widget 停在旧值）—— 实际读到 '
            . \json_encode($state->seenFromField),
        );

        check(
            'State · 覆写者的钩子确实被调用了（排除"根本没走到"）',
            $state->updates >= 1,
            'updates=' . $state->updates,
        );

        $this->flushChecks();
    }
}
