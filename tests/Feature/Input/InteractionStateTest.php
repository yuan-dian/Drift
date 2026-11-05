<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Input;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Layout\RenderProxy;
use Drift\Rendering\Color;

/**
 * 两相位声明**不能合并**（§DESIGN-INTERACTION-FEEDBACK-2026-11-05 §3）。
 *
 * 合并成"一个交互态"会让只声明悬停色的盒被推一个它根本不画的按下态 ——
 * 状态在传、画面不动、没有任何报错。这组断言单独钉住 `acceptsHover()` /
 * `acceptsPress()` 各自只认自己那一档声明。
 */
final class InteractionStateTest extends SmokeTestCase
{
    public function testOnePhaseDeclarationDoesNotEnableTheOther(): void
    {
        $box = new RenderProxy();
        check('两相位 · 未声明时悬停不接', !$box->acceptsHover());
        check('两相位 · 未声明时按下不接', !$box->acceptsPress());

        $color = new Color(10, 20, 30, 255);

        $box->setInteraction($color, null);
        check('两相位 · 只声明悬停 ⇒ 悬停接', $box->acceptsHover());
        check('两相位 · 只声明悬停 ⇒ 按下仍不接', !$box->acceptsPress(), '只画悬停的盒不该收到不画的按下态');

        $box->setInteraction(null, $color);
        check('两相位 · 只声明按下 ⇒ 悬停不接', !$box->acceptsHover());
        check('两相位 · 只声明按下 ⇒ 按下接', $box->acceptsPress());

        $this->flushChecks();
    }

    public function testClearingBothPhasesDisablesFeedback(): void
    {
        $box = new RenderProxy();
        $color = new Color(10, 20, 30, 255);

        $box->setInteraction($color, $color);
        check('两相位 · 两档都声明 ⇒ 悬停接', $box->acceptsHover());
        check('两相位 · 两档都声明 ⇒ 按下接', $box->acceptsPress());

        $box->setInteraction(null, null);
        check('两相位 · 清空后悬停不接', !$box->acceptsHover());
        check('两相位 · 清空后按下不接', !$box->acceptsPress());

        $this->flushChecks();
    }
}
