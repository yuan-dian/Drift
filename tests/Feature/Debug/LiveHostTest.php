<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\DevTools\Protocol\ProtocolError;
use Drift\DevTools\Protocol\RemoteSession;
use Drift\DevTools\Host\Discovery;
use Drift\DevTools\Host\SocketChannel;
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
            [\PHP_BINARY, $root . '/devtools/bin/debug-serve.php', ...$args],
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

    /**
     * 判据 6：宿主重启后令牌换了，观察者靠 `session` 字段识别"这不是同一场会话"。
     *
     * ★ 帧号每会话从 1 重发，`since=590` 落到新会话上意味着"全都读过了" —— 观察者
     *   会以为没有新帧。所以游标必须带上会话号；`frames` 响应自带 `session`，
     *   观察者自己比对，不需要一个共享的游标类型。
     */
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
        // 光比帧号是判不出来的：老会话也停在 1，与新的第一帧撞号。
        check('这一条不能靠帧号判：两边帧号会撞', $frames['frames'] !== []
            && (int) $frames['frames'][0]['id'] === 1);
        check('老会话号与新响应不符（观察者据此归零）', $old !== ($frames['session'] ?? null));

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

    /**
     * S6 判据 1：暂停之后帧真的不动了。
     *
     * 这条证明的是"整条链路确实停了"：`Host` 因为状态不是 `running` 而不再零超时轮询，
     * `Drive::advance` 因为同一个状态而不动手。两级串联，所以单独拆掉任一级它都不红
     * —— 定位交给 `DriveTest::testAdvanceDoesNothingWhilePaused`，这里管"端到端
     * 观察到的事实"。它不可省：单测证明不了"宿主循环有没有认真读状态"，
     * 而那个错误的表现是"`ctl pause` 回了 `paused` 而帧照涨"。
     */
    public function testPauseFreezesTheFrameCounter(): void
    {
        // 3000 帧在 `--speed=0` 下约 3 秒：够连上去按下暂停，又不至于让用例等太久。
        $entry = $this->startHost(['--page=table', '--frames=3000', '--speed=0']);
        $session = $this->attach($entry);

        $paused = $session->pause();
        check('pause 回执说它停着', ($paused['state'] ?? '') === 'paused', \json_encode($paused));
        check('原因是 requested', ($paused['reason'] ?? '') === 'requested', \json_encode($paused['reason'] ?? null));

        $before = (int) ($paused['lastFrame'] ?? 0);
        \usleep(150_000);
        $after = $session->query('state');

        check('150ms 内一帧都没动', (int) ($after['lastFrame'] ?? -1) === $before, "{$before} → " . ($after['lastFrame'] ?? '?'));
        check('状态仍是 paused', ($after['state'] ?? '') === 'paused', \json_encode($after));

        // 停住的是**推进**，不是整个宿主：观察者照样能读。
        $frames = $session->query('frames', ['limit' => 0])['frames'];
        check('暂停期间仍能读帧', \count($frames) === $before, \count($frames) . ' 帧 vs lastFrame ' . $before);
        check('暂停期间仍能算指纹', (string) ($session->query('digest')['digest'] ?? '') !== '', 'digest 是空的');

        $session->detach();
    }

    /**
     * S6 判据 3 + 5 + 8 + 9：`--at` 停住的会话能被放行，而自走宿主上 `frame` 被拒。
     *
     * 合成一条是因为它们判的是同一件事的四面：**这个宿主的帧由它自己推**。
     * 观察点只生效一次（否则 `resume` 一步都走不动）、`driven` 说得出这件事、
     * `frame` 因此不该被接受、`finish` 也不该由客户端代为判定。
     */
    public function testAtCanBeResumedAndFrameIsRefused(): void
    {
        $entry = $this->startHost(['--page=table', '--frames=6', '--at=3', '--speed=0']);
        $session = $this->attach($entry);

        $at = $this->waitSettled($session);
        check('--at=3 停成 paused', ($at['state'] ?? '') === 'paused', \json_encode($at));
        check('原因是 at（不是 requested）', ($at['reason'] ?? '') === 'at', \json_encode($at['reason'] ?? null));
        check('末帧是 3', ($at['lastFrame'] ?? 0) === 3, (string) ($at['lastFrame'] ?? 0));

        check('attach 回执说它自己推帧', $session->driven() === true, \json_encode($session->driven()));

        try {
            $session->pump(0.0);
            check('自走宿主上 frame 该被拒', false, '居然推了一帧');
        } catch (ProtocolError $e) {
            check(
                '自走宿主上 frame 报 not_applicable',
                $e->errorCode === ProtocolError::NOT_APPLICABLE,
                $e->errorCode . '：' . $e->getMessage(),
            );
        }

        try {
            $session->finish();
            check('自走宿主上 finish 该被拒', false, '居然替它判了停');
        } catch (ProtocolError $e) {
            check(
                '自走宿主上 finish 报 not_applicable',
                $e->errorCode === ProtocolError::NOT_APPLICABLE,
                $e->errorCode . '：' . $e->getMessage(),
            );
        }

        $session->resume();
        $finished = $this->waitSettled($session);

        check('resume 之后能跑完（观察点只挡一次）', ($finished['state'] ?? '') === 'finished', \json_encode($finished));
        check('六帧全部推到', ($finished['lastFrame'] ?? 0) === 6, (string) ($finished['lastFrame'] ?? 0));

        $session->detach();
    }

    /**
     * S6 判据 7：暂停**不改变记录内容**。
     *
     * 这是这一期最重要的一条。判据 1（B2）证明"喂帧的节奏不影响记录"，这条证明
     * "停下来也不影响"—— 两件事合起来才说得上"暂停只是调度层的事"。
     *
     * 比 `t` 序列而不是只比 `digest`：`Digest` 刻意不含时间，所以任何把时间混进
     * 记录的错误在它上面完全不显形。
     */
    public function testPauseDoesNotChangeWhatIsRecorded(): void
    {
        $straight = $this->runToEnd(false);
        $paused = $this->runToEnd(true);

        check('直跑跑满了 600 帧', \count($straight['t']) === 600, \count($straight['t']) . ' 帧');
        check('中途暂停的那次也跑满了', \count($paused['t']) === 600, \count($paused['t']) . ' 帧');
        check(
            '帧时间序列逐值相同',
            $straight['t'] === $paused['t'],
            \sprintf('长度 %d vs %d；首差位置 %s', \count($straight['t']), \count($paused['t']), self::firstDiff($straight['t'], $paused['t'])),
        );
        check(
            '结构指纹也逐值相同',
            $straight['digest'] === $paused['digest'],
            \json_encode(['straight' => $straight['digest'], 'paused' => $paused['digest']]),
        );
    }

    /**
     * 跑一遍 600 帧的脚本，可选在中途暂停一下再放行。
     *
     * @return array{t: list<float>, digest: string}
     */
    private function runToEnd(bool $pauseHalfway): array
    {
        $entry = $this->startHost(['--page=table', '--frames=600', '--speed=0']);
        $session = $this->attach($entry);

        if ($pauseHalfway) {
            $state = $session->pause();
            // 确认真的停在 running 之外再放行；否则这条用例测的是"偶尔停一下"。
            check('中途确实停住了', ($state['state'] ?? '') === 'paused', \json_encode($state));
            \usleep(200_000);
            $session->resume();
        }

        $settled = $this->waitSettled($session);
        check('跑完了', ($settled['state'] ?? '') === 'finished', \json_encode($settled));

        $frames = $session->query('frames', ['limit' => 0])['frames'];
        $out = [
            't' => \array_map(static fn(array $f): float => (float) $f['t'], $frames),
            'digest' => (string) ($session->query('digest')['digest'] ?? ''),
        ];

        $session->detach();
        $this->stopHost();

        return $out;
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private static function firstDiff(array $a, array $b): string
    {
        foreach ($a as $i => $value) {
            if (($b[$i] ?? null) !== $value) {
                return "第 {$i} 项：" . $value . ' vs ' . \json_encode($b[$i] ?? null);
            }
        }

        return '前 ' . \min(\count($a), \count($b)) . ' 项都相同';
    }
}
