<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Debug;

use Drift\Debug\Session\Step;
use Drift\DevTools\Host\Plan;
use PHPUnit\Framework\TestCase;

/**
 * 命令行动作 ⇒ 驱动脚本。
 *
 * ## 判据是**时间戳序列**，不是"跑起来没报错"
 *
 * 动作与帧的相对时刻决定了因果归到哪一帧：动作后的第一帧**必须与动作同一个
 * 时间戳**（同一次输入在同一帧里被 settle 消费）。差一个 16ms 就会把"输入"
 * 和"消费"拆成两帧，报告里那一帧的重建数会掉到另一帧去 —— 而两份报告都正常。
 */
final class PlanTest extends TestCase
{
    public function testFramesAndActionsShareTheSameClock(): void
    {
        $script = Plan::build('table', 800.0, 600.0, ['rows' => 6], 2, 1, [['click', '400,300']]);

        $kinds = [];
        $times = [];
        foreach ($script->steps() as $step) {
            $kinds[] = $step->kind;
            $times[] = $step->t;
        }

        // 按下 + 抬起一次成型，中间不插帧；动作之后的第一帧与动作同一时刻。
        $this->assertSame([Step::FRAME, Step::FRAME, Step::POINTER, Step::POINTER, Step::FRAME], $kinds);
        $this->assertSame([0.0, 16.0, 32.0, 32.0, 32.0], $times);
        $this->assertSame(['rows' => 6], $script->meta());
    }

    public function testHoldAddsOneFrameEachAfterTheAction(): void
    {
        $script = Plan::build('table', 800.0, 600.0, [], 1, 3, [['key', 'Tab']]);

        $frames = [];
        foreach ($script->steps() as $step) {
            if ($step->kind === Step::FRAME) {
                $frames[] = $step->t;
            }
        }

        $this->assertSame([0.0, 16.0, 32.0, 48.0], $frames);
    }

    public function testNamedKeysAreNormalizedToKeyCodes(): void
    {
        $this->assertSame('Down', Plan::steps('key', 'down', 0.0)[0]->data['chord']);
        $this->assertSame('A', Plan::steps('key', 'a', 0.0)[0]->data['chord']);
        $this->assertSame(true, Plan::steps('key', 'Ctrl+A', 0.0)[0]->data['ctrl']);

        // 能打印的字符走文本输入，不按键码：中文与大小写都靠这条。
        $this->assertSame('井', Plan::steps('text', '井', 0.0)[0]->data['text']);
    }

    public function testResolveBecomesANamedEffect(): void
    {
        $step = Plan::steps('resolve', '', 5.0)[0];

        $this->assertSame(Step::EFFECT, $step->kind);
        $this->assertSame('resolve', $step->data['name']);
    }

    public function testWheelIsMeasuredInCellsWithAxis(): void
    {
        $vertical = Plan::steps('wheel', '400,300,20', 0.0)[0];
        $horizontal = Plan::steps('wheel', '400,300,4,h', 0.0)[0];

        // `20` 而不是 `20.0`：{@see Step} 在构造处把"没有小数部分的浮点"收成整数，
        // 否则同一份脚本存盘再读就不再逐个相等（JSON 只有一种数字）。
        $this->assertSame(20, $vertical->data['delta']);
        $this->assertSame('v', $vertical->data['axis']);
        $this->assertSame('h', $horizontal->data['axis']);
    }

    public function testUnknownActionIsRejectedInsteadOfIgnored(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Plan::steps('drag', '1,2', 0.0);
    }
}
