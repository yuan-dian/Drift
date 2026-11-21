<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\State;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\State\Cell;

/**
 * `CellWrite`：一次性写入的值形态（2026-11-21）。
 *
 * ★ 判据针对"延迟"：`setTo()` 只打包、不写；调用时才写。少了这条，
 *   把 `setTo()` 实现成当场 `set()` 再返回一个空动作也能过其它断言。
 */
final class CellWriteTest extends SmokeTestCase
{
    public function testSetToDefersWriteUntilInvoked(): void
    {
        $cell = Cell::of(0)->named('target');
        $write = $cell->setTo(7);

        check('写入动作 · 打包时没写', $cell->peek() === 0);
        check('写入动作 · 可直接当 callable 用', \is_callable($write));
        check('写入动作 · 诊断名带上目标格子', \str_contains($write->describe(), 'target'));

        $write();
        check('写入动作 · 调用后才写', $cell->peek() === 7);
        check('写入动作 · 定了值', $cell->get() === 7);

        $this->flushChecks();
    }

    public function testSetToHonorsEqualityGate(): void
    {
        $cell = Cell::of(1);
        $hits = 0;
        $cell->subscribeFor(new \stdClass(), function () use (&$hits): void {
            $hits++;
        });

        $cell->setTo(1)();
        check('写入动作 · 等值不通知', $hits === 0, 'hits=' . $hits);

        $cell->setTo(2)();
        check('写入动作 · 变值通知一次', $hits === 1, 'hits=' . $hits);

        $this->flushChecks();
    }
}
