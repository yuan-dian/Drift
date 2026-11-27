<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\DevTools\App\DebugSession;
use Drift\DevTools\Protocol\Methods;
use Drift\DevTools\Protocol\ProtocolError;
use Drift\DevTools\Protocol\Wire;
use Drift\Debug\Session\Recorder;
use Drift\Debug\Session\Script;
use Drift\DevTools\Host\Drive;
use Drift\DevTools\Host\Host;
use Drift\DevTools\Host\Plan;
use Drift\DevTools\Host\Runner;
use Drift\DevTools\Host\SingleServer;
use Drift\Dev\Tests\Support\FakeTransport;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 宿主进程的协议行为：方法分派、错误信封、状态机。
 *
 * ## 为什么用内存通道而不是真子进程
 *
 * 这里要判的是"哪条消息得到哪个答复"——与"消息怎么送到"无关。混在一起测时，
 * 一次红分不清是协议错还是平台错（Windows 管道、编码、缓冲），排障成本远高于收益。
 * 真管道的判据在 {@see TransportTest}：那边只判**值**是否逐值相同。
 *
 * ## 判据不止"回得对"，还有"错了也活着"
 *
 * 一条错消息（名字拼错、参数缺字段、副作用返回了对象）必须是**一条错误信封**，
 * 而不是让宿主崩掉：崩掉之后客户端只看到"管道断了"，真正的原因已经丢了。
 * 所以每条错误判据后面都跟一条正常请求，证明循环还在。
 */
final class HostTest extends SmokeTestCase
{
    private function script(): Script
    {
        return Plan::build('SlowFrameApplication', 320.0, 240.0, [], 1, 1, []);
    }

    /**
     * @param list<array<string, mixed>> $commands
     * @param null|callable(Script): array{session: DebugSession, mountMs: float} $open
     * @param string|null $token 访问令牌；给了才允许 attach
     */
    private function serve(array $commands, ?callable $open = null, ?string $token = null): FakeTransport
    {
        $lines = [];
        foreach ($commands as $command) {
            $lines[] = Wire::encode($command);
        }

        $transport = new FakeTransport($lines);
        $host = new Host(new SingleServer($transport), $token);
        $host->serve($open ?? static fn(Script $s): array => Runner::open(
            $s->target,
            $s->width,
            $s->height,
            $s->meta(),
            $s,
        ));

        return $transport;
    }

    /** @param array<string, mixed> $params */
    private static function call(int $id, string $method, array $params = []): array
    {
        return ['id' => $id, 'method' => $method, 'params' => $params];
    }

    /** 断言细节用：任何值都能显示出来（失败信息里看得见"实际是什么"）。 */
    private static function show(mixed $value): string
    {
        return \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION) ?: \get_debug_type($value);
    }

    public function testOpenFrameQueryCloseIsOneSession(): void
    {
        $replies = $this->serve([
            self::call(1, Methods::OPEN, ['script' => $this->script()->toArray()]),
            self::call(2, Methods::FRAME, ['t' => 0.0]),
            self::call(3, Methods::QUERY, ['name' => 'stats']),
            self::call(4, Methods::CLOSE),
        ])->replies();

        check('四条命令得到四条响应', \count($replies) === 4, '实际 ' . \count($replies));
        if (\count($replies) !== 4) {
            return;
        }

        /** @var array<string, mixed> $opened */
        $opened = $replies[0]['result'];
        check('open 成功', $replies[0]['ok'] === true, self::show($replies[0]));
        check('目标与视口原样回来', $opened['target'] === 'SlowFrameApplication', (string) $opened['target']);
        check('视口是浮点对', $opened['viewport'] === [320.0, 240.0], self::show($opened['viewport']));
        check('挂载耗时是正数', \is_float($opened['mountMs']) && $opened['mountMs'] > 0.0, (string) $opened['mountMs']);

        /** @var array<string, mixed> $frame */
        $frame = $replies[1]['result'];
        // 帧号由宿主给：客户端自己数会与"容量裁剪 / 跳过 settle"错开。
        check('帧号由宿主给', $frame['id'] === 1, self::show($frame['id']));
        check('墙钟是浮点', \is_float($frame['wall']), \get_debug_type($frame['wall']));

        check('stats 看到那一帧', ($replies[2]['result']['frames'] ?? 0) === 1, self::show($replies[2]['result']));
        check('close 成功', $replies[3]['ok'] === true);
    }

    public function testUnknownMethodIsAnEnvelopeNotACrash(): void
    {
        $replies = $this->serve([
            self::call(1, Methods::OPEN, ['script' => $this->script()->toArray()]),
            self::call(2, 'reboot'),
            self::call(3, Methods::QUERY, ['name' => 'stats']),
        ])->replies();

        check('三条命令都回了', \count($replies) === 3, '实际 ' . \count($replies));
        if (\count($replies) !== 3) {
            return;
        }

        check('不认识的方法被拒绝', $replies[1]['ok'] === false);
        check(
            '错误码是 unknown_method',
            ($replies[1]['error']['code'] ?? '') === ProtocolError::UNKNOWN_METHOD,
            self::show($replies[1]),
        );
        check('一条错消息不该带走整个会话', $replies[2]['ok'] === true);
    }

    public function testQueryBeforeOpenIsRejected(): void
    {
        $replies = $this->serve([self::call(1, Methods::QUERY, ['name' => 'stats'])])->replies();

        check('回了一条', \count($replies) === 1);
        check(
            '没开会话时查询被拒绝',
            ($replies[0]['error']['code'] ?? '') === ProtocolError::NOT_OPEN,
            self::show($replies[0] ?? null),
        );
    }

    /** 一个宿主只服务一次运行：第二次 `open` 会覆盖记录，那两份结论都不可信。 */
    public function testSecondOpenIsRejected(): void
    {
        $script = $this->script()->toArray();
        $replies = $this->serve([
            self::call(1, Methods::OPEN, ['script' => $script]),
            self::call(2, Methods::OPEN, ['script' => $script]),
        ])->replies();

        check('回了两次', \count($replies) === 2);
        check('第一次打开成功', $replies[0]['ok'] === true);
        check(
            '第二次被拒绝',
            ($replies[1]['error']['code'] ?? '') === ProtocolError::ALREADY_OPEN,
            self::show($replies[1] ?? null),
        );
    }

    /**
     * 带令牌的 `open` 是「接现有会话」，令牌不对必须说清楚是令牌不对。
     *
     * 这条与上一条分开的意义：`already_open` 让调用方去新开进程，而令牌不对时新开
     * 进程是错的动作 —— 会话还在跑，该做的是去发现文件里拿当前令牌。
     */
    public function testAttachWithWrongTokenIsUnauthorizedNotAlreadyOpen(): void
    {
        $script = $this->script()->toArray();
        $replies = $this->serve([
            self::call(1, Methods::OPEN, ['script' => $script]),
            self::call(2, Methods::OPEN, ['token' => \str_repeat('0', 32)]),
            // 被拒之后循环还活着：会话仍然可以查
            self::call(3, Methods::QUERY, ['name' => 'state']),
        ], token: 'secret')->replies();

        check('回了三次', \count($replies) === 3, self::show(\count($replies)));
        check(
            '令牌不对报 unauthorized',
            ($replies[1]['error']['code'] ?? '') === ProtocolError::UNAUTHORIZED,
            self::show($replies[1] ?? null),
        );
        check('被拒之后会话没坏', $replies[2]['ok'] === true, self::show($replies[2] ?? null));
    }

    /**
     * 令牌对上了就**复用**，不重建：重建会把观察者要看的"那一次运行"换成另一次，
     * 而两次的帧号、节点号本来就不保证相同。
     */
    public function testAttachWithRightTokenReusesTheRunningSession(): void
    {
        $replies = $this->serve([
            self::call(1, Methods::OPEN, ['script' => $this->script()->toArray()]),
            self::call(2, Methods::FRAME, ['t' => 0.0]),
            self::call(3, Methods::OPEN, ['token' => 'secret']),
            self::call(4, Methods::QUERY, ['name' => 'frames']),
        ], token: 'secret')->replies();

        $first = $replies[0]['result'] ?? [];
        $attach = $replies[2]['result'] ?? [];

        check('接上了', ($attach['reused'] ?? null) === true, self::show($attach));
        check(
            '会话令牌与第一次 open 给的是同一个（不是新开的）',
            ($attach['session'] ?? null) === ($first['session'] ?? 'x'),
            self::show($attach['session'] ?? null),
        );
        check('末帧号一并给出，观察者的游标有起点', ($attach['lastFrame'] ?? null) === 1, self::show($attach['lastFrame'] ?? null));
        check(
            '接上之后能直接读到刚才那一帧',
            \count($replies[3]['result']['frames'] ?? []) === 1,
            self::show($replies[3] ?? null),
        );
    }

    /** 宿主上还没有会话时去接，要报「没有会话可接」，而不是把它当成新建。 */
    public function testAttachBeforeOpenSaysThereIsNoSession(): void
    {
        $replies = $this->serve([
            self::call(1, Methods::OPEN, ['token' => 'secret']),
        ], token: 'secret')->replies();

        check(
            '没有会话可接时明确报错',
            ($replies[0]['error']['code'] ?? '') === ProtocolError::NOT_OPEN,
            self::show($replies[0] ?? null),
        );
        check(
            '错误里说清楚是「没有会话」而不是「没 open」',
            \str_contains((string) ($replies[0]['error']['message'] ?? ''), '没有会话可接'),
            self::show($replies[0]['error']['message'] ?? null),
        );
    }

    public function testBadParamsAreReportedAndTheLoopContinues(): void
    {
        $replies = $this->serve([
            self::call(1, Methods::OPEN, ['script' => $this->script()->toArray()]),
            // 缺 `t`
            self::call(2, Methods::FRAME, []),
            // 不认识的查询名
            self::call(3, Methods::QUERY, ['name' => 'nope']),
            self::call(4, Methods::QUERY, ['name' => 'digest']),
        ])->replies();

        check('四条命令都回了', \count($replies) === 4, '实际 ' . \count($replies));
        if (\count($replies) !== 4) {
            return;
        }

        check(
            '缺参数被当成 bad_request',
            ($replies[1]['error']['code'] ?? '') === ProtocolError::BAD_REQUEST,
            self::show($replies[1]),
        );
        check('查询名写错是失败', $replies[2]['ok'] === false);
        check(
            '错误信息指得出那个名字',
            \str_contains((string) ($replies[2]['error']['message'] ?? ''), 'nope'),
            (string) ($replies[2]['error']['message'] ?? ''),
        );
        check('之后照常能查', $replies[3]['ok'] === true);
        check('digest 查询有内容', (bool) ($replies[3]['result']['digest'] ?? false));
    }

    public function testMalformedLineGetsAnErrorWithoutID(): void
    {
        $transport = new FakeTransport(['{"id":1,"method":']);
        (new Host(new SingleServer($transport)))->serve(static fn(Script $s): array => Runner::open(
            $s->target,
            $s->width,
            $s->height,
            $s->meta(),
            $s,
        ));

        $replies = $transport->replies();
        check('坏消息也回了一条（不能让客户端干等）', \count($replies) === 1);
        if ($replies === []) {
            return;
        }
        check('没有 id 就回 null', \array_key_exists('id', $replies[0]) && $replies[0]['id'] === null);
        check(
            '错误码是 bad_request',
            ($replies[0]['error']['code'] ?? '') === ProtocolError::BAD_REQUEST,
            self::show($replies[0]),
        );
    }

    /** 副作用的返回值要原样过管道（类型也原样）：42 不能变成 "42"。 */
    public function testEffectValueKeepsItsType(): void
    {
        $open = static function (Script $s): array {
            $opened = Runner::open($s->target, $s->width, $s->height, $s->meta(), $s);
            $opened['session']->defineEffect('double', static fn(array $a): int => ((int) ($a['n'] ?? 0)) * 2);

            return $opened;
        };

        $replies = $this->serve([
            self::call(1, Methods::OPEN, ['script' => $this->script()->toArray()]),
            self::call(2, Methods::EFFECT, ['name' => 'double', 'args' => ['n' => 21]]),
        ], $open)->replies();

        check('回了两次', \count($replies) === 2);
        check('副作用成功', ($replies[1]['ok'] ?? false) === true, self::show($replies[1] ?? null));
        check(
            '返回值与类型都原样',
            ($replies[1]['result']['value'] ?? null) === 42,
            self::show($replies[1]['result'] ?? null),
        );
    }

    /**
     * 副作用返回对象 ⇒ 一条错误信封，**不是**半个 JSON。
     *
     * 这条挡的是最坏的一种坏法：编到一半失败会让分帧从此错位，客户端后面每条
     * 响应都配错请求，而错误信息指向的是"协议坏了"，不是那个返回对象的回调。
     */
    public function testUnencodableEffectValueIsAnErrorEnvelopeAndTheLoopSurvives(): void
    {
        $open = static function (Script $s): array {
            $opened = Runner::open($s->target, $s->width, $s->height, $s->meta(), $s);
            $opened['session']->defineEffect('object', static fn(): object => new \stdClass());

            return $opened;
        };

        $replies = $this->serve([
            self::call(1, Methods::OPEN, ['script' => $this->script()->toArray()]),
            self::call(2, Methods::EFFECT, ['name' => 'object']),
            self::call(3, Methods::QUERY, ['name' => 'stats']),
        ], $open)->replies();

        check('三条命令都回了', \count($replies) === 3, '实际 ' . \count($replies));
        if (\count($replies) !== 3) {
            return;
        }

        check('编不出来是失败', $replies[1]['ok'] === false);
        check(
            '失败原因是编码',
            ($replies[1]['error']['code'] ?? '') === ProtocolError::CALL_FAILED
                && \str_contains((string) ($replies[1]['error']['message'] ?? ''), 'JSON'),
            self::show($replies[1]),
        );
        check('分帧没错位，下一条照常', $replies[2]['ok'] === true);
    }

    /** 对端直接断开（EOF）时，录制器必须卸掉 —— 否则同一个进程里下一次 `open` 会被拒。 */
    public function testTranscriptEndUninstallsTheRecorder(): void
    {
        $this->serve([self::call(1, Methods::OPEN, ['script' => $this->script()->toArray()])]);

        check('宿主退出后不该留着录制器', !Recorder::installed());
    }

    /**
     * 从属宿主上 `pause` / `resume` 没有意义：客户端停手就是暂停。
     *
     * 报 `not_applicable` 而不是装作成功 —— 假装成功会让 `ctl pause` 打印一个
     * `paused` 然后什么也没发生，而用户以为停住了。
     */
    public function testPauseOnAClientDrivenHostIsNotApplicable(): void
    {
        $replies = $this->serve([
            self::call(1, Methods::OPEN, ['script' => $this->script()->toArray()]),
            self::call(2, Methods::PAUSE),
            self::call(3, Methods::RESUME),
            self::call(4, Methods::QUERY, ['name' => 'state']),
        ])->replies();

        check('回了四次', \count($replies) === 4, '实际 ' . \count($replies));
        if (\count($replies) !== 4) {
            return;
        }

        check(
            'pause 报 not_applicable',
            ($replies[1]['error']['code'] ?? '') === ProtocolError::NOT_APPLICABLE,
            self::show($replies[1]),
        );
        check(
            'resume 报 not_applicable',
            ($replies[2]['error']['code'] ?? '') === ProtocolError::NOT_APPLICABLE,
            self::show($replies[2]),
        );
        check('被拒之后会话没坏', $replies[3]['ok'] === true, self::show($replies[3]));
        check('状态还是 running（没被谎称暂停）', ($replies[3]['result']['state'] ?? '') === 'running', self::show($replies[3]['result'] ?? null));
    }

    public function testFinishMarksTheEndWithTheAtFact(): void
    {
        check('FINISH 已登记在协议里', \in_array(Methods::FINISH, Methods::ALL, true));

        $replies = $this->serve([
            self::call(1, Methods::OPEN, ['script' => $this->script()->toArray()]),
            self::call(2, Methods::FRAME, ['t' => 16.7]),
            self::call(3, Methods::FINISH, ['at' => 2]),
            self::call(4, Methods::QUERY, ['name' => 'state']),
        ])->replies();

        check('回了四次', \count($replies) === 4, '实际 ' . \count($replies));
        if (\count($replies) !== 4) {
            return;
        }

        $finish = $replies[2]['result'] ?? null;
        $state = $replies[3]['result'] ?? null;
        check('finish 成功', $replies[2]['ok'] === true, self::show($replies[2]));
        check('finish 报 paused', ($finish['state'] ?? '') === 'paused', self::show($finish));
        check('reason 是 at', ($finish['reason'] ?? '') === 'at', self::show($finish));
        check('finish 回执与 state 查询逐值相同', $finish === $state, self::show($finish) . ' vs ' . self::show($state));
    }

    public function testFinishRejectsAnInvalidAtAndStaysAlive(): void
    {
        $replies = $this->serve([
            self::call(1, Methods::OPEN, ['script' => $this->script()->toArray()]),
            self::call(2, Methods::FINISH, ['at' => 0]),
            self::call(3, Methods::FINISH, ['at' => '2']),
            self::call(4, Methods::QUERY, ['name' => 'state']),
        ])->replies();

        check('回了四次', \count($replies) === 4, '实际 ' . \count($replies));
        if (\count($replies) !== 4) {
            return;
        }

        check('at=0 报 bad_request', ($replies[1]['error']['code'] ?? '') === ProtocolError::BAD_REQUEST, self::show($replies[1]));
        check('字符串 at 报 bad_request', ($replies[2]['error']['code'] ?? '') === ProtocolError::BAD_REQUEST, self::show($replies[2]));
        check('被拒后会话还在', ($replies[3]['result']['state'] ?? '') === 'running', self::show($replies[3]));
    }

    /** `open` 回执照样说清楚帧是谁推的，客户端不必靠"发一条试试"去猜。 */
    public function testOpenSaysTheClientDrives(): void
    {
        $replies = $this->serve([
            self::call(1, Methods::OPEN, ['script' => $this->script()->toArray()]),
        ])->replies();

        check(
            'open 回执带 driven=false',
            ($replies[0]['result']['driven'] ?? null) === false,
            self::show($replies[0]['result']['driven'] ?? null),
        );
    }

    /**
     * 给了驱动却拿一个不能立即返回的 `wait()`，要当场拒绝。
     *
     * 放过去的表现最糟：宿主卡在等消息上，帧一帧不动，而它看起来"起来了"。
     */
    public function testDriveRequiresAPollableServer(): void
    {
        $host = new Host(new SingleServer(new FakeTransport([])), null, new Drive($this->script()));

        try {
            $host->serve(static fn(Script $s): array => Runner::open(
                $s->target,
                $s->width,
                $s->height,
                $s->meta(),
                $s,
            ));
            check('该拒绝却没拒', false, '居然跑起来了');
        } catch (\LogicException $e) {
            check('拒绝时说的是"不能自走"', \str_contains($e->getMessage(), '自走'), $e->getMessage());
        }
    }
}
