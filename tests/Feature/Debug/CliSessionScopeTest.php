<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\Debug\Session\Script;
use Drift\DevTools\Cli\Commands;
use Drift\DevTools\Host\Plan;
use Drift\DevTools\Report\ReportRunner;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 出口生命周期收敛（A74）与 report 编排外移。
 *
 * 八个"取证据"出口原来各写十步：解析 → 通用参数 → 用法提示 → autoAttach →
 * script → resolve → export → 查询 → 渲染 → close。漏任一步都不报错，只会让
 * 某个出口悄悄和别人分叉（`find` 就漏了 export，`--out` 静默不落盘）。
 * 这里一半是**结构判据**（步骤只在壳里出现一次），一半是**真往返**（壳仍然
 * 把同一份证据以同样的形状交出来）。
 */
final class CliSessionScopeTest extends SmokeTestCase
{
    /** @var list<string> */
    private const EVIDENCE_EXPORTS = [
        'async', 'brief', 'find', 'network', 'node', 'props', 'tree', 'tree-diff',
    ];

    /** @var list<string> */
    private const OTHER_EXPORTS = [
        'capabilities', 'ctl', 'eval', 'host', 'highlight', 'loop',
        'panel', 'pick', 'pick-live', 'probe', 'report', 'serve', 'targets', 'web',
    ];

    private static ?string $evidence = null;

    public function testEveryEvidenceExportGoesThroughTheScope(): void
    {
        foreach (self::EVIDENCE_EXPORTS as $verb) {
            $src = $this->exportSource($verb);
            // brief 要在开会话前校验基线组合，所以把提前解析好的参数交给壳（第三参）。
            $open = $verb === 'brief'
                ? "SessionScope::open('brief', \$argv, \$args)->run("
                : "SessionScope::open('{$verb}', \$argv)->run(";
            check("{$verb} 经统一壳打开", \str_contains($src, $open));
            foreach (['Source::resolve(', 'Source::autoAttach(', '->export()', '$source?->close()'] as $step) {
                check("{$verb} 不再自己走「{$step}」", !\str_contains($src, $step), $step);
            }
            // brief 例外：它自己的 --rules / --baseline 校验必须先于 resolve。
            if ($verb !== 'brief') {
                check("{$verb} 不再自己解析参数", !\str_contains($src, 'Commands::parseOrExit('));
            }
        }
    }

    public function testOtherExportsStayOutsideTheScope(): void
    {
        foreach (self::OTHER_EXPORTS as $verb) {
            $src = $this->exportSource($verb);
            check("{$verb} 不借壳", !\str_contains($src, 'SessionScope'), $verb);
        }
    }

    public function testScopeOwnsEveryCommonStepExactlyOnce(): void
    {
        $src = (string) \file_get_contents(\dirname(__DIR__, 3) . '/devtools/Cli/SessionScope.php');
        foreach ([
            'Commands::parseOrExit(',
            'Source::autoAttach(',
            'Source::resolve(',
            'Commands::examples(',
        ] as $step) {
            check("壳里恰好一处「{$step}」", \substr_count($src, $step) === 1, (string) \substr_count($src, $step));
        }
        // 这两条必须看语句而不是子串：注释掉 `// $source->export();` 时子串还在，
        // 只数 substr_count 会给出假绿（A74 变异自证咬出来的）。
        check('壳里有真正的 export 语句', \preg_match('/^[ \t]*\$source->export\(\);$/m', $src) === 1);
        check('壳里有真正的 close 语句', \preg_match('/^[ \t]*\$this->source->close\(\);$/m', $src) === 1);
    }

    public function testSourceKindsComeFromTheSchema(): void
    {
        $tree = Commands::set('tree')->parse(['debug-tree.php', '--page=table']);
        $node = Commands::set('node')->parse(['debug-node.php', '--page=table', '--node=1', '--frame=1']);
        check('tree 声明了 --from', $tree->supports('from'));
        check('tree 声明了 --out', $tree->supports('out'));
        check('node 没声明 --from', !$node->supports('from'));
        check('node 没声明 --out', !$node->supports('out'));
        check('两者都有 --session', $tree->supports('session') && $node->supports('session'));
        check('supports 不等于 has', !$tree->has('from') && $tree->supports('from'));
    }

    public function testMissingPagePointsAtRegistryExamples(): void
    {
        $run = $this->runExport('debug-tree.php', '--root=5');
        check('没页面退出 2', $run['code'] === 2, (string) $run['code']);
        check('提示要一个页面', \str_contains($run['err'], '要一个页面'), $run['err']);
        $examples = Commands::examples('tree') ?? '';
        $first = \explode("\n", $examples)[0] ?? '';
        check('提示里的用法来自脚本文档块', $first !== '' && \str_contains($run['err'], $first), $run['err']);
    }

    /**
     * 自动 attach 必须先于「要一个页面」的守卫。
     *
     * A74 把顺序调反过：无参数时在 `autoAttach()` 之前就退出，于是"当前目录
     * 恰有一个活会话就自动接"永远走不到，而 `debug web` 不走这条壳、仍能自动接。
     * 判据不靠读源码：造一份指向**没人监听**端口的活会话登记，正确的顺序会走到
     * 连接并报"连不上"，错误的顺序则连发现都没发生。
     */
    public function testAutoAttachRunsBeforeTheMissingPageGuard(): void
    {
        $dir = \sys_get_temp_dir() . '/a76-attach-' . \bin2hex(\random_bytes(4));
        \mkdir($dir . '/.drift', 0700, true);
        \file_put_contents($dir . '/.drift/session.json', (string) \json_encode([
            'protocol' => 1,
            'sessions' => [[
                'id' => 'probe',
                'pid' => (int) \getmypid(),
                'port' => self::closedPort(),
                'token' => 'x',
                'target' => 'async-table',
                'startedAt' => \gmdate('c'),
            ]],
        ]));
        try {
            $run = $this->runExportIn($dir, 'debug-tree.php', '--json');
            check('无参数时先发现活会话', !\str_contains($run['err'], '要一个页面'), $run['err']);
            check('随后如实报连不上', \str_contains($run['err'], '连不上 127.0.0.1:'), $run['err']);
            check('不能继续时退出 2', $run['code'] === 2, (string) $run['code']);
        } finally {
            @\unlink($dir . '/.drift/session.json');
            @\rmdir($dir . '/.drift');
            @\rmdir($dir);
        }
    }

    /**
     * `--session` 单独给时，提示要指向它真正需要的搭档，而不是笼统的「要一个页面」。
     */
    public function testSessionWithoutAttachSaysWhatItNeeds(): void
    {
        $run = $this->runExport('debug-tree.php', '--session=abc');
        check('--session 单独给退出 2', $run['code'] === 2, (string) $run['code']);
        check('提示要配合 --attach', \str_contains($run['err'], '要配合 --attach'), $run['err']);
    }

    public function testEvidenceStillExportsThroughTheScope(): void
    {
        $out = \sys_get_temp_dir() . '/a74-scope-' . \bin2hex(\random_bytes(4)) . '.evidence';
        try {
            $run = $this->runExport(
                'debug-tree.php', '--page=async-table', '--rows=3', '--frames=1',
                '--out=' . $out, '--json',
            );
            check('带 --out 仍成功', $run['code'] === 0, \trim($run['err']));
            check('壳把证据包落盘', \is_file($out), $out);
            if (\is_file($out)) {
                $head = (string) \file_get_contents($out);
                check('包首行是 manifest', \str_contains($head, 'manifest'), \substr($head, 0, 80));
            }
        } finally {
            if (\is_file($out)) {
                \unlink($out);
            }
        }
    }

    public function testOfflineAnswersStillCarryTheSameEvidence(): void
    {
        $case = self::caseFile();
        $labels = [];
        foreach (['tree', 'network', 'brief'] as $verb) {
            $run = $this->runExport('debug-' . $verb . '.php', '--from=' . $case, '--json');
            check("{$verb} 离线 JSON 可用", $run['code'] === 0, \trim($run['err']));
            $answer = \json_decode($run['out'], true);
            // 包的 label 取包内脚本的目标，不是文件名 —— Source::resolve() 的既有口径。
            check("{$verb} 的 label 来自包内脚本", \is_array($answer) && ($answer['label'] ?? null) === 'async-table', \substr($run['out'], 0, 120));
            // network 的骨架可以是空的（这一页没发请求），但不能没有 window 口径。
            $factKey = match ($verb) {
                'network' => 'window',
                'brief' => 'findings',
                default => 'rows',
            };
            check("{$verb} 带 {$factKey} 口径", \is_array($answer) && \array_key_exists($factKey, $answer), \substr($run['out'], 0, 120));
            if (\is_array($answer)) {
                $labels[] = $answer['label'] ?? null;
            }
        }
        check('三个出口读回同一份证据', \count(\array_unique($labels, \SORT_REGULAR)) === 1);
    }

    public function testReportOrchestrationLivesInItsOwnClass(): void
    {
        $src = $this->exportSource('report');
        foreach (['run_once', 'script_summary', 'report_verify', 'attribution_of'] as $old) {
            check("report 出口不再定义 {$old}()", \preg_match('/\bfunction\s+' . $old . '\s*\(/', $src) !== 1);
        }
        foreach (['runOnce', 'scriptSummary', 'reportVerify', 'attributionOf'] as $method) {
            check("ReportRunner 提供 {$method}()", \method_exists(ReportRunner::class, $method));
        }
        foreach (['runOnce', 'scriptSummary', 'reportVerify'] as $method) {
            check("report 出口调用 {$method}()", \str_contains($src, 'ReportRunner::' . $method . '('));
        }
        // 判据不通过与"进程崩了"必须分开：CI 才能区分"跑挂了"与"跑完了但结论否定"。
        check(
            '--verify 不通过用 3',
            \preg_match('/reportVerify\(.*\)\s*\?\s*0\s*:\s*3/s', $src) === 1,
        );
        check('--verify 与 --save 明确互斥', \str_contains($src, "--verify 与 --save 不能同用"));
    }

    /**
     * `--verify --save=x` 曾经静默不写、也不报错：验证先 exit，保存永远到不了。
     * 现在要么写要么响亮拒绝 —— 不能"看起来做了，实际没做"。
     */
    public function testVerifyRefusesToSaveAScriptItNeverProduces(): void
    {
        $dir = \sys_get_temp_dir() . '/a76-verify-' . \bin2hex(\random_bytes(4));
        \mkdir($dir, 0700, true);
        $script = $dir . '/case.json';
        \file_put_contents($script, Plan::build('table', 800.0, 600.0, [], 1, 1, [])->json());
        $saved = $dir . '/must-not-exist.json';
        try {
            $run = $this->runExport(
                'debug-report.php', '--replay=' . $script, '--verify', '--save=' . $saved,
            );
            check('同用退出 2', $run['code'] === 2, (string) $run['code'] . ' ' . $run['err']);
            check('说明两者不能同用', \str_contains($run['err'], '不能同用'), $run['err']);
            check('不静默写一半', !\is_file($saved), $saved);
        } finally {
            @\unlink($script);
            @\unlink($saved);
            @\rmdir($dir);
        }
    }

    public function testReportRunnerStillExplainsItsSearch(): void
    {
        $script = Plan::build('table', 800.0, 600.0, [], 1, 1, [['key', 'Tab']]);
        $summary = ReportRunner::scriptSummary($script);
        check('脚本概览保留帧数', ($summary['frames'] ?? -1) >= 1, (string) \json_encode($summary));
        check('脚本概览保留摘要', \is_string($summary['digest'] ?? null) && $summary['digest'] !== '');

        $run = ['report' => ['attribution' => [
            ['id' => 2, 'ids' => [10, 11]],
        ]]];
        check('取到指定帧的归因', ReportRunner::attributionOf($run, 2)['ids'] === [10, 11]);
        check('没有的帧返回 null', ReportRunner::attributionOf($run, 99) === null);
    }

    public function testReportVerifyStillJudgesTwoRuns(): void
    {
        $digest = [
            'nodes' => 'nodes-hash',
            'digest' => 'record-hash',
            'frames' => [1 => 'f1', 2 => 'f2'],
        ];
        $plan = static fn(): Script => Plan::build('table', 800.0, 600.0, [], 1, 1, []);
        $a = ['script' => $plan(), 'digest' => $digest];
        $b = ['script' => $plan(), 'digest' => $digest];

        // `fwrite(STDOUT)` 不受 ob_start 影响，所以这里只判返回值；
        // 文本 / JSON 的形状由 TransportTest 的真 `--verify` 往返守着。
        $ok = ReportRunner::reportVerify($a, $b, 'case.json', true);
        check('相同两遍仍判过', $ok);

        $b['digest']['frames'] = [1 => 'f1', 2 => 'different'];
        $bad = ReportRunner::reportVerify($a, $b, 'case.json', true);
        check('逐帧不同仍判红', !$bad);

        $b['digest']['frames'] = $digest['frames'];
        $b['digest']['nodes'] = 'other-nodes';
        check('节点表不同也判红', !ReportRunner::reportVerify($a, $b, 'case.json', true));
    }

    private function exportSource(string $verb): string
    {
        return (string) \file_get_contents(\dirname(__DIR__, 3) . '/devtools/bin/debug-' . $verb . '.php');
    }

    private static function caseFile(): string
    {
        if (self::$evidence !== null) {
            return self::$evidence;
        }
        $path = \sys_get_temp_dir() . '/a74-scope-' . \bin2hex(\random_bytes(4)) . '.evidence';
        $out = [];
        $code = 0;
        \exec(\escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg(\dirname(__DIR__, 3) . '/devtools/bin/debug-tree.php')
            . ' --page=async-table --rows=3 --frames=2 --resolve --out=' . \escapeshellarg($path) . ' --json 2>&1', $out, $code);
        if ($code !== 0 || !\is_file($path)) {
            throw new \RuntimeException('生成判据证据包失败：' . \implode("\n", $out));
        }

        return self::$evidence = $path;
    }

    /**
     * @param list<string> $args
     *
     * @return array{code: int, out: string, err: string}
     */
    private function runExport(string ...$args): array
    {
        return $this->runExportIn(null, ...$args);
    }

    /**
     * @param list<string> $args
     *
     * @return array{code: int, out: string, err: string}
     */
    private function runExportIn(?string $cwd, string ...$args): array
    {
        $cmd = [\PHP_BINARY, \dirname(__DIR__, 3) . '/devtools/bin/' . \array_shift($args), ...$args];
        $pipes = [];
        $proc = \proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $cwd);
        if (!\is_resource($proc)) {
            throw new \RuntimeException('起不了出口进程');
        }
        \fclose($pipes[0]);
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return ['code' => \proc_close($proc), 'out' => $out, 'err' => $err];
    }

    /** 拿一个"刚关掉、肯定没人监听"的本机端口（先绑 0 再立刻释放）。 */
    private static function closedPort(): int
    {
        $probe = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            return 1;
        }
        $name = (string) \stream_socket_get_name($probe, false);
        \fclose($probe);

        return (int) \substr($name, (int) \strrpos($name, ':') + 1);
    }
}
