<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\Debug\Session\SessionState;
use Drift\Dev\Debug\Plan;
use Drift\Dev\Debug\Source;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 一次驱动结束后的状态：本地与远端必须逐值相同。
 *
 * 这里要用真的 `Source::resolve()`（它自己起子进程跑 `--remote`），因为要判的正是
 * "调用方读到的结果"——协议层的回执对不对，在 HostTest 里判；两者拼起来才说明
 * 观察者不会因为连的是另一个进程就看到一个不同的世界。
 */
final class FinishTest extends SmokeTestCase
{
    public function testOneRuleForBothSettledStates(): void
    {
        check('跑完是 finished/null', SessionState::afterDrive(null) === ['state' => 'finished', 'reason' => null]);
        check('at 是 paused/at', SessionState::afterDrive(2) === ['state' => 'paused', 'reason' => 'at']);
        $this->flushChecks();
    }

    public function testLocalAndRemoteEndInTheSameState(): void
    {
        $rows = [];
        foreach ([
            ['local', false, null],
            ['local-at', false, 2],
            ['remote', true, null],
            ['remote-at', true, 2],
        ] as [$name, $remote, $at]) {
            $source = Source::resolve(
                drive: Plan::build('table', 800.0, 600.0, [], 3, 1, []),
                remote: $remote,
                at: $at,
            );
            try {
                $rows[$name] = $source->queries()->query('state');
            } finally {
                $source->close();
            }
        }

        check('四个组合都读到了状态', \count($rows) === 4);
        $shape = static fn(array $row): array => [
            'state' => $row['state'] ?? null,
            'reason' => $row['reason'] ?? null,
            'lastFrame' => $row['lastFrame'] ?? null,
        ];

        check('整份脚本收在 finished', $shape($rows['local']) === ['state' => 'finished', 'reason' => null, 'lastFrame' => 3], \json_encode($rows['local']));
        check('--at 收在 paused(at)', $shape($rows['local-at']) === ['state' => 'paused', 'reason' => 'at', 'lastFrame' => 2], \json_encode($rows['local-at']));
        check('远端与本地逐值相同（整份）', $shape($rows['remote']) === $shape($rows['local']), \json_encode($rows['remote']));
        check('远端与本地逐值相同（--at）', $shape($rows['remote-at']) === $shape($rows['local-at']), \json_encode($rows['remote-at']));

        $tokens = \array_column($rows, 'session');
        check('四次的会话令牌互不相同', \count(\array_unique($tokens)) === 4, \implode(' ', $tokens));
        $this->flushChecks();
    }
}
