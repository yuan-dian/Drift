<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\DevTools\App\DebugSession;
use Drift\Debug\Session\Script;
use Drift\DevTools\Host\Plan;
use Drift\DevTools\Host\Reopen;
use Drift\DevTools\Host\Runner;
use Drift\Dev\Smoke\App\PageHostApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;

/**
 * 重开的忠实性：`Reopen` 跑 N 轮、逐层比对。
 *
 * ## 为什么判据 5 必须用真应用
 *
 * "重开两次得到同样的指纹"这条判据最容易恒绿：只要两轮是同一棵树，它天然成立。
 * 所以必须有一个**会咬红**的对照——复用同一个应用实例时，第二轮从第一轮的终点
 * 起步，指纹必须不同。这个差异只有真树+真输入才有，mock 编不出来（mock 的
 * "状态"是我们自己写的，是我们想验证的东西本身）。
 *
 * 夹具用滚轮而不是点击：滚动位置是**累积**的，第二轮滚到同一处时已经在别的位置；
 * 而点击一个开关会在一来一回之后回到原值，第二轮看起来"一致"——那正是
 * B5 §2.2 里"第 2、3 轮彼此相同"的形态，最容易被误读成通过。
 */
final class LoopTest extends SmokeTestCase
{
    // ------------------------------------------------------------ 判据 1 / 2

    public function testRebuildingThePageRepeatsTheSameDigest(): void
    {
        $script = Plan::build('table', 800.0, 600.0, ['page' => 1], 2, 1, []);
        $report = Reopen::run($this->rebuildOpener($script), $script, 3);

        check('三轮一致', $report['consistent'] === true, 'divergence: ' . \json_encode($report['divergence']));
        check('没有差异可报', $report['divergence'] === null);
        check('三轮都有记录', \count($report['runs']) === 3);

        $sessions = \array_unique(\array_column($report['runs'], 'session'));
        check('会话令牌互不相同（真的重建了三次）', \count($sessions) === 3, \implode(',', $sessions));

        $nodes = \array_unique(\array_column($report['runs'], 'nodeCount'));
        check('节点数三轮相同', \count($nodes) === 1);
        check('节点表指纹三轮相同', \count(\array_unique(\array_column($report['runs'], 'nodesDigest'))) === 1);
    }

    // ------------------------------------------------------------ 判据 5（变异）

    public function testReusingTheApplicationBreaksConsistency(): void
    {
        $script = Plan::build('async-table', 800.0, 600.0, ['page' => 1], 3, 2, [['wheel', '400,300,-240']]);
        $constraints = BoxConstraints::tight(new Size(800.0, 600.0));
        $app = null;

        $reuse = static function (int $round) use (&$app, $script, $constraints): array {
            if ($app === null) {
                $app = PageHostApplication::forRoute('async-table', null);
                $app->attach(new SurfaceProbe(), $constraints);
            }

            return [
                'session' => DebugSession::open($app, $constraints, script: $script->fork()),
                // 复用应用时没有挂载成本可量 —— 给 0 而不是编一个数。
                'mountMs' => 0.0,
            ];
        };

        $report = Reopen::run($reuse, $script, 4);

        check('复用应用时必须不一致', $report['consistent'] === false);
        $divergence = $report['divergence'];
        check('报出了第一处不同', \is_array($divergence));
        if (!\is_array($divergence)) {
            return;
        }

        check('分叉从第 2 轮开始', $divergence['round'] === 2, (string) $divergence['round']);
        check('结构没变、因果变了', $divergence['part'] === 'frame', (string) $divergence['part']);
        check('指到第 1 帧', ($divergence['frame'] ?? 0) === 1);
        check('后几轮都不对（滚动累积，不是偶发）', $divergence['rounds'] === [2, 3, 4]);
    }

    // ------------------------------------------------------------ 判据 6

    public function testSingleRunDoesNotClaimConsistency(): void
    {
        $script = Plan::build('table', 800.0, 600.0, ['page' => 1], 1, 1, []);
        $report = Reopen::run($this->rebuildOpener($script), $script, 1);

        // `null` 不是"不一致"，是"没测"。出口靠它拒绝 `--assert` 单轮。
        check('单轮不声称一致', $report['consistent'] === null);
        check('单轮没有差异可报', $report['divergence'] === null);
        check('单轮也有一行记录', \count($report['runs']) === 1);
    }

    public function testRunsOutOfRangeIsRejected(): void
    {
        $script = Plan::build('table', 800.0, 600.0, ['page' => 1], 1, 1, []);
        foreach ([0, -1, Reopen::MAX_RUNS + 1] as $bad) {
            try {
                Reopen::run($this->rebuildOpener($script), $script, $bad);
                check("--runs={$bad} 应当被拒", false);
            } catch (\InvalidArgumentException) {
                check("--runs={$bad} 被拒", true);
            }
        }
        // 上面拒绝的那些不会造会话，录制器没残留 —— 再跑一次正常的必须能开。
        $ok = Reopen::run($this->rebuildOpener($script), $script, 2);
        check('被拒之后仍能正常重开', $ok['consistent'] === true);
    }

    // ------------------------------------------------------------ 判据 3 / 4

    public function testRecordedScriptMatchesTheDriveOnEveryRun(): void
    {
        // 带副作用与输入：这两类步骤的时刻与形状最容易在"驱动 → 记录"之间漂。
        // `rows` 必须有：`Runner::open` 只在有取数替身时才注册 `resolve` 效果。
        $script = Plan::build('async-table', 800.0, 600.0, ['page' => 1, 'rows' => 6], 2, 1, [['resolve', ''], ['click', '400,120']]);
        $report = Reopen::run($this->rebuildOpener($script), $script, 2);

        foreach ($report['runs'] as $row) {
            check("第 {$row['round']} 轮录下的脚本与喂进去的相同", $row['scriptMatches'] === true);
        }
    }

    public function testMemoryStaysFlatAcrossRuns(): void
    {
        $script = Plan::build('table', 800.0, 600.0, ['page' => 1], 1, 1, []);
        $report = Reopen::run($this->rebuildOpener($script), $script, 5);

        check('每轮都采到了内存', \count(\array_filter(\array_column($report['runs'], 'memory'))) === 5);
        // 阈值放宽到 5%：判据要的是"没有跨会话泄漏"，不是"内存一位不差"。
        check(
            '五轮内存涨幅 < 5%',
            \abs($report['memory']['deltaPercent']) < 5.0,
            \sprintf('%+.2f%%', $report['memory']['deltaPercent']),
        );
    }

    public function testEveryRunReportsItsMountCost(): void
    {
        $script = Plan::build('table', 800.0, 600.0, ['page' => 1], 1, 1, []);
        $report = Reopen::run($this->rebuildOpener($script), $script, 2);

        foreach ($report['runs'] as $row) {
            check("第 {$row['round']} 轮有挂载耗时", $row['mountMs'] > 0.0, (string) $row['mountMs']);
        }
        check('中位数为正', $report['mount']['median'] > 0.0);
    }

    /** @return callable(int): array{session: DebugSession, mountMs: float} */
    private function rebuildOpener(Script $script): callable
    {
        return static function (int $round) use ($script): array {
            $opened = Runner::open($script->target, $script->width, $script->height, $script->meta(), $script->fork());

            return ['session' => $opened['session'], 'mountMs' => $opened['mountMs']];
        };
    }

    // ------------------------------------------------------------ 出口

    public function testAssertExitsZeroWhenRunsAgree(): void
    {
        [$code, $out] = $this->cli(['--page=table', '--runs=2', '--assert']);

        check('一致时退出码是 0', $code === 0, (string) $code);
        check('结论行打了勾', \str_contains($out, '✓'), $out);
    }

    public function testAssertRefusesASingleRun(): void
    {
        // 一轮的"一致"是空话，所以 `--assert` 必须拒绝，而不是打勾通过。
        [$code, , $err] = $this->cli(['--page=table', '--runs=1', '--assert']);

        check('单轮断言被拒', $code === 2, (string) $code);
        check('说明了理由', \str_contains($err, '至少要 --runs=2'), $err);
    }

    public function testSingleRunIsReportedAsUnmeasurable(): void
    {
        [$code, $out] = $this->cli(['--page=table', '--runs=1']);

        check('不报错', $code === 0);
        check('明说没有可比对象', \str_contains($out, '没有可比对象'), $out);
        check('不打勾', !\str_contains($out, '✓'));
    }

    public function testRemoteIsRefusedWithAReason(): void
    {
        // `--remote` 每轮起一个子进程，那是另一个粒度（新进程本来就干净），
        // 测不出跨会话残留 —— 静默忽略会让人以为测过了。
        [$code, , $err] = $this->cli(['--page=table', '--remote']);

        check('被拒', $code === 2, (string) $code);
        check('说明了为什么不支持', \str_contains($err, '不支持 --remote'), $err);
    }

    public function testJsonCarriesEveryRunAndTheVerdict(): void
    {
        [$code, $out] = $this->cli(['--page=table', '--runs=2', '--json']);
        $decoded = \json_decode($out, true);

        check('退出码 0', $code === 0);
        check('是 JSON', \is_array($decoded), \substr($out, 0, 120));
        if (!\is_array($decoded)) {
            return;
        }

        check('带 label', ($decoded['label'] ?? null) === 'table');
        check('两轮记录', \count($decoded['runs'] ?? []) === 2);
        check('结论是 true', ($decoded['consistent'] ?? null) === true);
        check('每轮都有会话令牌', isset($decoded['runs'][0]['session'], $decoded['runs'][1]['session']));
    }

    /**
     * 跑一次出口脚本。
     *
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function cli(array $args): array
    {
        $root = \dirname(__DIR__, 3);
        $proc = \proc_open(
            \array_merge([\PHP_BINARY, $root . '/devtools/bin/debug-loop.php'], $args),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true],
        );

        self::assertIsResource($proc, 'debug-loop 起不来');
        \fclose($pipes[0]);
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return [\proc_close($proc), $out, $err];
    }
}
