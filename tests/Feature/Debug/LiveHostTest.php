<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\Debug\Protocol\ProtocolError;
use Drift\Debug\Protocol\RemoteSession;
use Drift\Dev\Debug\Cursor;
use Drift\Dev\Debug\Discovery;
use Drift\Dev\Debug\SocketChannel;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 常驻宿主的端到端判据：**真子进程、真 socket**。
 *
 * ## 为什么这一组必须是端到端
 *
 * 其余调试判据用内存通道（`HostTest` 的口径），因为它们判的是"哪条消息得到哪个答复"。
 * 这一组判的恰恰是**跨进程**的东西：`--speed` 会不会把墙钟混进 `t`、宿主重启后令牌
 * 会不会换、宿主被杀之后观察者还能报出 pid。这些在同一个进程里全都不成立 —— 同一
 * 进程里没有"重启"，也没有"进程不在了"。
 *
 * ## 慢
 *
 * 每个用例要起一个 PHP 子进程、等它把发现文件写出来。所以这组刻意只放**非它不可**
 * 的判据，其余仍留在 `HostTest`。等发现文件最多 10 秒，超时就把宿主的输出贴出来 ——
 * "宿主没起来"和"宿主起来了但没写文件"要能分开。
 */
final class LiveHostTest extends SmokeTestCase
{
    /** @var resource|null */
    private $process = null;

    /** @var list<string> 每个宿主一个发现条目 id，收尾时清掉 */
    private array $registered = [];

    private string $log = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = (string) \tempnam(\sys_get_temp_dir(), 'drift-serve-');
    }

    protected function tearDown(): void
    {
        $this->stopHost();
        if ($this->log !== '' && \is_file($this->log)) {
            @\unlink($this->log);
        }
        parent::tearDown();
    }

    /**
     * 起一个宿主，等它登记，返回它的发现条目。
     *
     * @param list<string> $args
     *
     * @return array{id: string, pid: int, port: int, token: string, target: string, startedAt: string}
     */
    private function startHost(array $args): array
    {
        $root = \dirname(__DIR__, 3);
        $devNull = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = \proc_open(
            [\PHP_BINARY, $root . '/tools/debug-serve.php', ...$args],
            [
                0 => ['file', $devNull, 'r'],
                1 => ['file', $this->log, 'a'],
                2 => ['file', $this->log, 'a'],
            ],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true],
        );
        if (!\is_resource($process)) {
            self::fail('宿主进程起不来');
        }
        $this->process = $process;

        $status = \proc_get_status($process);
        $pid = (int) $status['pid'];
        $discovery = Discovery::inDirectory($root);

        $deadline = \microtime(true) + 10.0;
        while (\microtime(true) < $deadline) {
            foreach ($discovery->sessions() as $entry) {
                if ($entry['pid'] === $pid) {
                    $this->registered[] = $entry['id'];

                    return $entry;
                }
            }
            if (!\proc_get_status($process)['running']) {
                self::fail("宿主在登记前就退了：\n" . $this->hostOutput());
            }
            \usleep(50_000);
        }

        self::fail("宿主 10 秒内没有写发现文件：\n" . $this->hostOutput());

        return [];
    }

    /** 连上并 attach。 */
    private function attach(array $entry): RemoteSession
    {
        $session = new RemoteSession(SocketChannel::connect($entry['port']));
        $session->attach($entry['token']);

        return $session;
    }

    /**
     * 等脚本跑完。
     *
     * 观察者随时可以接上**正在跑**的会话，所以"接上时只有 1 帧"不是 bug。判据要的是
     * 跑完之后的终态，因此必须先等到 `finished` 再比 —— 否则比的是"截到哪算哪"。
     *
     * @return array<string, mixed> 终态（`finished` 或 `paused` 或 `crashed`）
     */
    private function waitSettled(RemoteSession $session, float $seconds = 20.0): array
    {
        $deadline = \microtime(true) + $seconds;
        $state = $session->query('state');
        while (\microtime(true) < $deadline) {
            if ($state['state'] !== 'running') {
                return $state;
            }
            \usleep(20_000);
            $state = $session->query('state');
        }

        self::fail('会话 ' . $seconds . ' 秒内没有跑完，状态仍是 ' . \json_encode($state['state']));

        return [];
    }

    private function stopHost(): void
    {
        if ($this->process !== null) {
            @\proc_terminate($this->process, 9);
            @\proc_close($this->process);
            $this->process = null;
        }

        $discovery = Discovery::inDirectory(\dirname(__DIR__, 3));
        foreach ($this->registered as $id) {
            $discovery->unregister($id);
        }
        $this->registered = [];
    }

    private function hostOutput(): string
    {
        return \is_file($this->log) ? (string) \file_get_contents($this->log) : '(没有输出)';
    }

    /**
     * 判据 1：三种 `--speed` 跑同一脚本，帧时间序列与结构指纹**逐值相同**。
     *
     * 这里比的是 `t` 序列，**不是只比 digest**。`Digest` 刻意不含时间（它就是为跨进程
     * 比对设计的），所以"拿 `now()` 当 `t`"这种错在 digest 上完全不显形 —— 只比指纹
     * 的判据会恒绿，而它本该防的正是这件事。
     */
    public function testThreeSpeedsProduceTheSameTimeSeries(): void
    {
        $runs = [];
        foreach (['0', '1', '4'] as $speed) {
            $entry = $this->startHost(['--page=table', '--frames=3', '--speed=' . $speed]);
            $session = $this->attach($entry);
            $this->waitSettled($session);
            $frames = $session->query('frames', ['limit' => 0])['frames'];
            $runs[$speed] = [
                't' => \array_map(static fn(array $f): float => (float) $f['t'], $frames),
                'digest' => $session->query('digest')['digest'] ?? '',
            ];
            $session->detach();
            $this->stopHost();

            check("speed={$speed} 跑出了三帧", \count($runs[$speed]['t']) === 3, \count($runs[$speed]['t']) . ' 帧');
        }

        check(
            '三种速度的帧时间序列逐值相同（`t` 只来自脚本）',
            $runs['0']['t'] === $runs['1']['t'] && $runs['1']['t'] === $runs['4']['t'],
            \json_encode(\array_map(static fn(array $r): array => $r['t'], $runs)),
        );
        check(
            '结构指纹也逐值相同',
            $runs['0']['digest'] === $runs['1']['digest'] && $runs['1']['digest'] === $runs['4']['digest'],
            \json_encode(\array_map(static fn(array $r): string => $r['digest'], $runs)),
        );
        check(
            '帧时间就是脚本里的 0 / 16 / 32（不是墙钟）',
            $runs['0']['t'] === [0.0, 16.0, 32.0],
            \json_encode($runs['0']['t']),
        );
    }

    /** 判据 2：末帧号与令牌三处同源 —— `state` / `frames` / attach 回执。 */
    public function testStateAndFramesAgreeOnSessionAndLastFrame(): void
    {
        $entry = $this->startHost(['--page=table', '--frames=2']);
        $session = $this->attach($entry);
        $attachedLast = $session->lastFrameId();

        $state = $this->waitSettled($session);
        $frames = $session->query('frames', ['limit' => 0])['frames'];
        $lastOfFrames = (int) ($frames[\count($frames) - 1]['id'] ?? 0);

        check('attach 回执的末帧不会超过脚本帧数', $attachedLast >= 0 && $attachedLast <= 2, (string) $attachedLast);
        check('state 的末帧与 frames 的末帧同源', $state['lastFrame'] === $lastOfFrames && $lastOfFrames === 2, \sprintf('state=%d frames=%d', $state['lastFrame'], $lastOfFrames));
        check('state 与会话查到的令牌一致', $state['session'] !== '' && $state['session'] !== null, \json_encode($state['session']));
        check('跑完了状态是 finished', $state['state'] === 'finished', (string) $state['state']);
        check('finished 没有 reason', $state['reason'] === null, \json_encode($state['reason']));

        $session->detach();
    }

    /** 判据 6：宿主重启后令牌换了，老游标必须识别出"这不是同一场会话"。 */
    public function testRestartChangesTheSessionToken(): void
    {
        $first = $this->startHost(['--page=table', '--frames=1']);
        $a = $this->attach($first);
        $old = $a->query('state')['session'];
        $a->detach();
        $this->stopHost();

        $second = $this->startHost(['--page=table', '--frames=1']);
        $b = $this->attach($second);
        $new = $this->waitSettled($b)['session'];
        $frames = $b->query('frames', ['limit' => 0]);

        check('两次的访问令牌不同', $first['token'] !== $second['token']);
        check('两次的会话令牌不同', $old !== $new, \json_encode([$old, $new]));
        check('frames 响应带的是新会话的令牌', ($frames['session'] ?? null) === $new, \json_encode($frames['session'] ?? null));

        // 老游标在新会话上必须归零，而不是"接着往下数"。
        $stale = new Cursor((string) $old, 590);
        check('老游标判出会话不符', !$stale->isFreshFor($frames));
        check('归零后重取', $stale->advance($frames)->frame === 0);

        $b->detach();
    }

    /** 判据 7：宿主退出后报 pid，而不是"连接被拒绝"。 */
    public function testDeadHostIsReportedByPid(): void
    {
        $entry = $this->startHost(['--page=table', '--frames=1']);

        // 先确认活着时是"连得上"
        $session = $this->attach($entry);
        $session->detach();

        $this->stopHost();

        $d = Discovery::inDirectory(\dirname(__DIR__, 3));
        $message = $d->diagnose($entry);
        check('报的是宿主已退出', \str_contains($message, '宿主已退出'), $message);
        check('并且带上 pid（用户知道该去重启谁）', \str_contains($message, 'pid ' . $entry['pid']), $message);
        check('死条目不进 live 列表', \array_column($d->live(), 'id') !== [$entry['id']]);
    }

    /** 判据 8：令牌不对的连接进不来，而正确的令牌能进。 */
    public function testWrongTokenIsRejectedOnTheWire(): void
    {
        $entry = $this->startHost(['--page=table', '--frames=1']);

        $wrong = new RemoteSession(SocketChannel::connect($entry['port']));
        try {
            $wrong->attach(\str_repeat('0', 32));
            check('错误令牌被拒绝', false, '居然接上了');
        } catch (ProtocolError $e) {
            check('错误令牌被拒绝', $e->errorCode === ProtocolError::UNAUTHORIZED, $e->errorCode . '：' . $e->getMessage());
        } finally {
            $wrong->detach();
        }

        // 被拒之后宿主还活着：正确令牌仍能接上。
        $right = $this->attach($entry);
        $settled = $this->waitSettled($right);
        check('被拒之后正确令牌仍可接', $settled['state'] === 'finished', \json_encode($settled));
        $right->detach();
    }
}
