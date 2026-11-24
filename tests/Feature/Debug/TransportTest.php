<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\Replay;
use Drift\Debug\Protocol\RemoteSession;
use Drift\Debug\Session\Script;
use Drift\Debug\Session\SessionQueries;
use Drift\Dev\Debug\Plan;
use Drift\Dev\Debug\ProcessTransport;
use Drift\Dev\Debug\Runner;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * D3 判据：**同一份脚本，同进程跑与隔着管道跑，读回来的值逐值相同**。
 *
 * ## 为什么这条判据要"两份独立运行"而不是"同一次运行读两遍"
 *
 * 同一次运行里"读两遍"证明不了任何东西：第二遍读的还是同一份内存。真正会坏的
 * 是**值过管道**这一路 —— 类型被 JSON 吃掉（`40.0 → 40`）、数字键变字符串、
 * 浮点被截断、某个字段根本没发。所以这里跑两次：一次就地驱动，一次起子进程驱动，
 * 然后比它们读到的**全部因果**。
 *
 * 两份运行能比，靠的是引擎确定：`Digest` 已经排除了时间，剩下的（谁重建了、
 * 因为谁、跑了哪几段）必须逐值相同 —— 这条前提由 `--verify` 独立守着。
 *
 * ## 为什么用真页面（表格页）
 *
 * 夹具里没有虚拟化、单元格选中、焦点链。跨进程要证明的是"真实一帧的因果能原样
 * 过去"，用一个 3 个控件的页面证明不了 360 个节点时的管道行为。
 *
 * ## 时间字段刻意不参与比较
 *
 * `cost` / `wall` / 各段纳秒是这台机器这一秒的运气，两次运行必然不同。它们只要
 * "确实过了管道且是正数"就够了 —— 拿它们做相等判据，判据会永远红，等于没有。
 */
final class TransportTest extends SmokeTestCase
{
    private function script(): Script
    {
        // 点一下表格：一帧里 300+ 个节点重建，管道要搬的是"一整个森林"。
        return Plan::build('table', 800.0, 600.0, ['page' => 1], 1, 1, [['click', '400,300']]);
    }

    /** @return array<string, mixed> 只收"必须逐值相同"的东西 */
    private function collect(SessionQueries $session): array
    {
        $raw = $session->query('frames', ['limit' => 10])['frames'];

        return [
            'digest' => $session->query('digest'),
            'cascade' => $session->query('cascade', ['frame' => 2, 'maxDepth' => 3, 'maxChildren' => 3]),
            'nodes' => $session->query('nodes', ['frames' => 0, 'limit' => 0]),
            'hotspots' => $session->query('hotspots', ['frames' => 0, 'limit' => 0]),
            'script' => $session->query('script'),
            'frames' => self::stripTime($raw),
            'sinceOne' => self::stripTime($session->query('frames', ['since' => 1, 'limit' => 10])['frames']),
            // 原始帧表另留一份：时间列不参与相等，但要证明它确实过了管道。
            'raw' => $raw,
        ];
    }

    /**
     * 去掉时间列，只留"结构"。
     *
     * @param list<array<string, mixed>> $frames
     *
     * @return list<array<string, mixed>>
     */
    private static function stripTime(array $frames): array
    {
        $out = [];
        foreach ($frames as $frame) {
            unset($frame['t'], $frame['cost'], $frame['stageMs'], $frame['unattributedMs']);
            $out[] = $frame;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function inProcess(Script $script): array
    {
        $opened = Runner::open(
            $script->target,
            $script->width,
            $script->height,
            $script->meta(),
            $script->fork(),
        );
        $session = $opened['session'];

        try {
            Replay::apply($session, $script);

            return $this->collect($session);
        } finally {
            $session->close();
        }
    }

    /** @return array<string, mixed> */
    private function overPipe(Script $script): array
    {
        $transport = ProcessTransport::spawn([
            \PHP_BINARY,
            \dirname(__DIR__, 3) . '/tools/debug-host.php',
        ]);
        $session = new RemoteSession($transport);

        try {
            $session->open($script->fork());
            Replay::apply($session, $script);

            return $this->collect($session);
        } finally {
            $session->close();
        }
    }

    public function testSameScriptThroughAHostReadsTheSameCausality(): void
    {
        $script = $this->script();

        $local = $this->inProcess($script);
        $remote = $this->overPipe($script);

        /** @var array<string, mixed> $dl */
        $dl = $local['digest'];
        /** @var array<string, mixed> $dr */
        $dr = $remote['digest'];

        check('节点表逐值相同', $dl['nodes'] === $dr['nodes'], 'id 分配顺序或类型在管道上变了');
        check(
            '逐帧因果逐值相同',
            $dl['frames'] === $dr['frames'],
            '第一个不同的帧：' . self::firstDifferent($dl, $dr),
        );
        check('总摘要相同', $dl['digest'] === $dr['digest']);
        check('因果森林相同', $local['cascade'] === $remote['cascade']);
        check('节点聚合相同', $local['nodes'] === $remote['nodes']);
        check('根因聚合相同', $local['hotspots'] === $remote['hotspots']);
        check('游标（since=1）读到的也相同', $local['sinceOne'] === $remote['sinceOne']);

        /** @var array<string, mixed> $sl */
        $sl = $local['script'];
        /** @var array<string, mixed> $sr */
        $sr = $remote['script'];
        check('录下来的脚本逐值相同', $sl === $sr, '管道把某一步的字段弄丢了或改了类型');

        /** @var list<array<string, mixed>> $fl */
        $fl = $local['frames'];
        /** @var list<array<string, mixed>> $fr */
        $fr = $remote['frames'];
        check('帧数相同', \count($fl) === \count($fr), \count($fl) . ' / ' . \count($fr));
        check(
            '逐帧重建数相同',
            \array_column($fl, 'rebuilt') === \array_column($fr, 'rebuilt'),
            \implode(',', \array_column($fl, 'rebuilt')) . ' / ' . \implode(',', \array_column($fr, 'rebuilt')),
        );

        // ★ 区分度：这一轮真的重建了一批节点。没有这条，"两边都是空"也能全绿。
        check(
            '点击帧确实重建了节点（不是空跑的假绿）',
            ($fl[1]['rebuilt'] ?? 0) > 50,
            '重建数=' . ($fl[1]['rebuilt'] ?? 0),
        );

        // 时间是两次运行各自的运气 —— 不参与相等，但必须真的过了管道。
        /** @var list<array<string, mixed>> $rawRemote */
        $rawRemote = $remote['raw'];
        check(
            '耗时字段过了管道且是正数',
            \is_float($rawRemote[1]['cost']) && $rawRemote[1]['cost'] > 0.0,
            \get_debug_type($rawRemote[1]['cost'] ?? null) . ' ' . (string) ($rawRemote[1]['cost'] ?? ''),
        );
        check(
            '段耗时也在（浮点，不是被取整的 int）',
            \is_float($rawRemote[1]['stageMs']['settle'] ?? null),
            \get_debug_type($rawRemote[1]['stageMs']['settle'] ?? null),
        );
    }

    /**
     * 游标不是"最近 N 帧"的同义词。
     *
     * `since` 语义下 `limit` 是"从最老的未读开始最多给几条"；把两者混成一个参数，
     * "从头取"会静默变成"取最近的"，差集正好是历史帧 —— 而报告看起来完整。
     */
    public function testCursorReturnsOnlyNewerFrames(): void
    {
        $remote = $this->overPipe($this->script());

        /** @var list<array<string, mixed>> $since */
        $since = $remote['sinceOne'];
        $ids = \array_map(static fn(array $f): int => (int) $f['id'], $since);

        check('给了 1 条（只有第 2 帧）', \count($since) === 1, \implode(',', $ids));
        check('帧号严格大于游标', $ids !== [] && \min($ids) > 1, \implode(',', $ids));
    }

    /**
     * 对端在等响应时消失 ⇒ 明确的协议错误，不是"读到空数组"。
     *
     * 静默返回空会让报告变成"这页什么都没干"，而真正的原因是宿主崩了。
     */
    public function testDeadHostRaisesAProtocolError(): void    {
        $transport = ProcessTransport::spawn([\PHP_BINARY, '-r', 'exit(0);']);
        $session = new RemoteSession($transport);

        $raised = null;
        try {
            $session->open($this->script()->fork());
        } catch (\Throwable $e) {
            $raised = $e;
        } finally {
            $session->close();
        }

        check('抛了异常', $raised !== null, '宿主已退出却当成成功');
        check(
            '是协议层的 closed，而不是别的什么',
            $raised instanceof \Drift\Debug\Protocol\ProtocolError
                && $raised->errorCode === \Drift\Debug\Protocol\ProtocolError::CLOSED,
            $raised === null ? '（无异常）' : $raised::class . '：' . $raised->getMessage(),
        );
    }

    /**
     * 端到端：`--remote` 与不带它的 CLI 必须给出**同一份报告**（时间列除外）。
     *
     * 上面两条判据走的是 `RemoteSession`，绕开了命令行的接线 —— "`--remote` 到底
     * 有没有把会话交出去"没人守。这条把整个管道串起来：同一条命令行、同一页、
     * 两份 JSON，除了时间列以外逐值相同。
     *
     * 时间列（`wall` / `cost` / `stageMs` / `mountMs`）是两次运行各自的运气，
     * 保留下来做相等判据会让这条永远红。`t` **要**比：它是脚本记下来的，不是量出来的。
     */
    public function testCliRemoteAndCliLocalAgreeOnEverythingButTiming(): void
    {
        $args = ['--page=table', '--frames=1', '--click=400,300'];

        $local = $this->runCli($args);
        $remote = $this->runCli([...$args, '--remote']);

        check('两次都出了 JSON 报告', $local !== null && $remote !== null);
        if ($local === null || $remote === null) {
            return;
        }

        $a = self::withoutTiming($local);
        $b = self::withoutTiming($remote);

        check(
            '除时间列外逐值相同',
            $a === $b,
            '第一处不同：' . self::firstDifference($a, $b),
        );
        check('报告里有 360 个登记节点（不是空跑的假绿）', ($a['stats']['nodes'] ?? 0) > 50, (string) ($a['stats']['nodes'] ?? 0));
        check(
            '远端那份确实带着量出来的墙钟',
            \is_float($remote['frames'][1]['wall'] ?? null) && $remote['frames'][1]['wall'] > 0.0,
            \get_debug_type($remote['frames'][1]['wall'] ?? null),
        );

        // ★ `--remote` 真的把会话交出去了吗？只比"两份一样"是证明不了的 ——
        //   开关悄悄退化成同进程跑时，两份仍然一样（因为走的是同一段代码）。
        //   宿主进程号是唯一可观测量：同进程为 null，交错进程则是别人。
        check('同进程跑时没有宿主进程', \array_key_exists('hostPid', $local) && $local['hostPid'] === null, self::show($local['hostPid'] ?? '缺字段'));
        check(
            '远端跑时是另一个进程',
            \is_int($remote['hostPid'] ?? null) && $remote['hostPid'] !== \getmypid(),
            self::show($remote['hostPid'] ?? null) . ' / 本进程 ' . \getmypid(),
        );
    }

    /**
     * @param list<string> $args
     *
     * @return array<string, mixed>|null
     */
    private function runCli(array $args): ?array    {
        $command = [\PHP_BINARY, \dirname(__DIR__, 3) . '/bin/drift', 'debug', ...$args, '--json'];
        $pipes = [];
        $process = \proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!\is_resource($process)) {
            return null;
        }

        // 先读完 stdout 再读 stderr：报告只有几十 KB，不会撑满 stderr 的管道缓冲。
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($process);

        if ($err !== '') {
            \fwrite(\STDERR, "CLI stderr：{$err}\n");
        }

        $decoded = \json_decode($out, true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * 去掉量出来的时间列（保留脚本里的 `t`）。
     *
     * @param array<array-key, mixed> $report
     *
     * @return array<array-key, mixed>
     */
    private static function withoutTiming(array $report): array
    {
        $drop = ['wall', 'cost', 'stageMs', 'unattributedMs', 'mountMs', 'lastCostMs', 'hostPid'];
        $out = [];
        foreach ($report as $key => $value) {
            if (\in_array($key, $drop, true)) {
                continue;
            }
            $out[$key] = \is_array($value) ? self::withoutTiming($value) : $value;
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $a
     * @param array<array-key, mixed> $b
     */
    private static function firstDifference(array $a, array $b, string $path = ''): string
    {
        foreach ($a as $key => $value) {
            $here = $path . '/' . (string) $key;
            if (!\array_key_exists($key, $b)) {
                return $here . '（只在 A 有）';
            }
            $other = $b[$key];
            if (\is_array($value) && \is_array($other)) {
                $inner = self::firstDifference($value, $other, $here);
                if ($inner !== '') {
                    return $inner;
                }
                continue;
            }
            if ($value !== $other) {
                return $here . '：' . \json_encode($value) . ' vs ' . \json_encode($other);
            }
        }
        foreach ($b as $key => $_) {
            if (!\array_key_exists($key, $a)) {
                return $path . '/' . (string) $key . '（只在 B 有）';
            }
        }

        return '';
    }

    /** 断言细节用：任何值都能显示（失败信息里看得见"实际是什么"）。 */
    private static function show(mixed $value): string
    {
        return \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION) ?: \get_debug_type($value);
    }

    /**
     * 两份摘要里第一个不同的帧号（给人看的那一行）。
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function firstDifferent(array $a, array $b): string
    {
        /** @var array<int, string> $fa */
        $fa = $a['frames'] ?? [];
        /** @var array<int, string> $fb */
        $fb = $b['frames'] ?? [];
        foreach ($fa as $id => $hash) {
            if (($fb[$id] ?? null) !== $hash) {
                return (string) $id;
            }
        }

        return \count($fa) === \count($fb) ? '（帧数相同，无差异）' : '（帧数不同）';
    }
}
