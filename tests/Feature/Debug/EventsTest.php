<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\DevTools\App\DebugSession;
use Drift\DevTools\Host\Drive;
use Drift\DevTools\Host\Host;
use Drift\DevTools\Host\Plan;
use Drift\DevTools\Host\Runner;
use Drift\DevTools\Host\SingleServer;
use Drift\DevTools\Protocol\Capabilities;
use Drift\DevTools\Protocol\Events;
use Drift\DevTools\Protocol\Methods;
use Drift\DevTools\Protocol\ProtocolError;
use Drift\DevTools\Protocol\RemoteSession;
use Drift\DevTools\Protocol\Wire;
use Drift\Debug\Session\Script;
use Drift\Dev\Tests\Support\FakeTransport;
use Drift\Dev\Tests\Support\PollingServer;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 事件：同一条管道上的第二种消息形状（A80）。
 *
 * ## 两侧各判一半
 *
 * 宿主侧要判的是"什么时候发、发出去长什么样"；客户端侧要判的是"收到了以后会不会
 * 把同步的 `call()` 弄错"。两侧的错法完全不同 —— 宿主少发一条在客户端看起来跟
 * "没有新帧"一样，而客户端把事件当成响应则会当场 `bad_response`。
 *
 * ## 最要紧的一条：`call()` 里事件插队
 *
 * 加事件之前 `call()` 只读一条、且要求 `id` 必须对上。宿主一推事件，那条断言就
 * 变成"顺序乱了"。所以这里的头号判据是"事件排在响应前面时，响应仍然被认出来"。
 */
final class EventsTest extends SmokeTestCase
{
    private function script(): Script
    {
        return Plan::build('SlowFrameApplication', 320.0, 240.0, [], 1, 1, []);
    }

    /** @param array<string, mixed> $params */
    private static function call(int $id, string $method, array $params = []): array
    {
        return ['id' => $id, 'method' => $method, 'params' => $params];
    }

    /** 断言细节用：任何值都能显示出来。 */
    private static function show(mixed $value): string
    {
        return \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION) ?: \get_debug_type($value);
    }

    /**
     * 客户端驱动的宿主：跑完一串命令，把通道还回来。
     *
     * @param list<array<string, mixed>> $commands
     */
    private function serve(array $commands): FakeTransport
    {
        $lines = [];
        foreach ($commands as $command) {
            $lines[] = Wire::encode($command);
        }

        $transport = new FakeTransport($lines);
        $host = new Host(new SingleServer($transport), null);
        $host->serve(static fn (Script $s): array => Runner::open(
            $s->target,
            $s->width,
            $s->height,
            $s->meta(),
            $s,
        ));

        return $transport;
    }

    /** @param array<string, mixed> $event */
    private static function eventLine(int $seq, int $id, ?float $cost = null): string
    {
        return Wire::encode([
            'event' => Events::FRAME,
            'seq' => $seq,
            'data' => ['id' => $id, 'cost' => $cost],
        ]);
    }

    // ------------------------------------------------------------------ 宿主侧

    public function testFrameEventCarriesIdAndCost(): void
    {
        $transport = $this->serve([
            self::call(1, Methods::OPEN, ['script' => $this->script()->toArray()]),
            self::call(2, Methods::FRAME, ['t' => 0.0]),
            self::call(3, Methods::FRAME, ['t' => 16.0]),
        ]);

        $events = $transport->events();
        check('两帧两条事件', \count($events) === 2, '实际 ' . \count($events));
        if (\count($events) !== 2) {
            return;
        }

        check('事件名是 frame', ($events[0]['event'] ?? null) === Events::FRAME, self::show($events[0]));
        check(
            'seq 是宿主自己的计数，从 1 起',
            ($events[0]['seq'] ?? 0) === 1 && ($events[1]['seq'] ?? 0) === 2,
            self::show($events),
        );
        check(
            'data.id 是推进后的末帧号',
            ($events[0]['data']['id'] ?? 0) === 1 && ($events[1]['data']['id'] ?? 0) === 2,
            self::show($events),
        );
        check(
            'cost 是宿主量到的墙钟（浮点，不是 null）',
            \is_float($events[0]['data']['cost'] ?? null),
            self::show($events[0]['data'] ?? null),
        );
        check(
            '事件信封没有 id（有 id 就成了响应）',
            !\array_key_exists('id', $events[0]),
            self::show($events[0]),
        );
    }

    public function testNoEventWhenNothingWasPushed(): void
    {
        // 只有 open 与查询：一帧都没推，就不该有事件。判据反过来咬"每条消息都发一条"。
        $transport = $this->serve([
            self::call(1, Methods::OPEN, ['script' => $this->script()->toArray()]),
            self::call(2, Methods::QUERY, ['name' => 'state']),
        ]);

        check('没推帧就没有事件', $transport->events() === [], self::show($transport->events()));
        check('响应还是两条', \count($transport->replies()) === 2, '实际 ' . \count($transport->replies()));
    }

    public function testDriveHostPublishesWhatItPushed(): void
    {
        $script = $this->script();
        $transport = new FakeTransport([Wire::encode(self::call(1, Methods::CAPABILITIES))]);
        // 自走宿主必须有会"立即返回空"的服务器，否则 `pollOnce()` 的空闲分支走不到，
        // 而帧只在那里推（`Drive::advance()`）。
        $host = new Host(new PollingServer($transport), null, new Drive($script));
        $opened = Runner::open($script->target, $script->width, $script->height, $script->meta(), $script);
        $session = $opened['session'];
        $host->adopt($session);

        try {
            // 第一轮：客户端问能力，通道由此被登记（没登记过就没有收件人）。
            $host->pollOnce(0);
            check('通道已登记', $transport->replies() !== [], self::show($transport->sent));

            // 第二轮：没有消息 ⇒ 空闲分支推一步 ⇒ 应该广播一帧。
            $host->pollOnce(0);
            $events = $transport->events();
            check('自走推的帧也广播', \count($events) === 1, self::show($events));
            check(
                '自走宿主量不到墙钟 ⇒ cost 是 null，不是 0',
                \array_key_exists('cost', $events[0]['data'] ?? []) && $events[0]['data']['cost'] === null,
                self::show($events[0]['data'] ?? null),
            );

            // 第三轮：脚本已经推完，这一步一帧都没推 ⇒ 不该再发。
            $host->pollOnce(0);
            check('没推进帧就不重发', \count($transport->events()) === 1, self::show($transport->events()));
        } finally {
            $host->closeChannels();
            $session->close();
        }
    }

    // ------------------------------------------------------------------ 客户端侧

    public function testCallSkipsEventAndBuffersIt(): void
    {
        // 事件排在响应**前面** —— 加事件之前这里会撞 `bad_response`。
        $transport = new FakeTransport([
            self::eventLine(1, 7, 1.5),
            Wire::encode(['id' => 1, 'ok' => true, 'result' => ['state' => 'paused']]),
        ]);
        $session = new RemoteSession($transport);

        $state = $session->pause();
        check('响应照常拿到', ($state['state'] ?? null) === 'paused', self::show($state));

        $events = $session->events();
        check('事件进了缓冲', \count($events) === 1, self::show($events));
        check('缓冲里是那条事件', ($events[0]['data']['id'] ?? 0) === 7, self::show($events[0] ?? null));
        check('取走之后缓冲空', $session->events() === [], self::show($session->events()));
    }

    public function testWaitEventsReadsOneWhenReadable(): void
    {
        $transport = new FakeTransport([self::eventLine(3, 9)]);
        $session = new RemoteSession($transport);

        $events = $session->waitEvents(1000);
        check('可读时读到一条', \count($events) === 1 && ($events[0]['seq'] ?? 0) === 3, self::show($events));
        check('读走了', $transport->pending === [], self::show($transport->pending));
        check('再等就没东西了', $session->waitEvents(0) === [], '实际非空');
    }

    public function testWaitEventsDoesNotReadOnPipe(): void
    {
        // 管道上 `readable()` 恒 false（Windows 匿名管道不支持 `stream_select`）。
        $transport = new FakeTransport([self::eventLine(1, 4)]);
        $transport->canPoll = false;
        $session = new RemoteSession($transport);

        check('管道上不等，回空', $session->waitEvents(1000) === [], '实际非空');
        check('也没读走（留给下一次 call）', \count($transport->pending) === 1, self::show($transport->pending));
    }

    public function testWaitEventsRejectsAResponse(): void
    {
        // 手上没有在途请求，此刻到达的响应说明通道顺序已经不可信。
        $transport = new FakeTransport([Wire::encode(['id' => 5, 'ok' => true, 'result' => []])]);
        $session = new RemoteSession($transport);

        $caught = null;
        try {
            $session->waitEvents(1000);
        } catch (ProtocolError $e) {
            $caught = $e;
        }

        check(
            '等事件收到响应 ⇒ bad_response',
            $caught !== null && $caught->errorCode === ProtocolError::BAD_RESPONSE,
            $caught === null ? '没抛' : $caught->errorCode,
        );
    }

    public function testBufferIsBounded(): void
    {
        $limit = 256;
        $total = 300;
        $lines = [];
        for ($i = 1; $i <= $total; $i++) {
            $lines[] = self::eventLine($i, $i);
        }
        $lines[] = Wire::encode(['id' => 1, 'ok' => true, 'result' => ['state' => 'running']]);

        $session = new RemoteSession(new FakeTransport($lines));
        $session->pause();

        $events = $session->events();
        check('缓冲有界', \count($events) === $limit, '实际 ' . \count($events));
        check('丢掉的被计数', $session->droppedEvents() === $total - $limit, '实际 ' . $session->droppedEvents());
        check('丢的是最旧的（留下的从第 45 条起）', ($events[0]['seq'] ?? 0) === $total - $limit + 1, self::show($events[0] ?? null));
    }

    public function testUnknownEventIsRejected(): void
    {
        $transport = new FakeTransport([
            Wire::encode(['event' => 'nope', 'seq' => 1, 'data' => []]),
            Wire::encode(['id' => 1, 'ok' => true, 'result' => ['state' => 'running']]),
        ]);
        $session = new RemoteSession($transport);

        $caught = null;
        try {
            $session->pause();
        } catch (ProtocolError $e) {
            $caught = $e;
        }

        check(
            '不认识的事件 ⇒ bad_response（不是静默忽略）',
            $caught !== null && $caught->errorCode === ProtocolError::BAD_RESPONSE,
            $caught === null ? '没抛' : $caught->errorCode,
        );
    }

    public function testEventEnvelopeMustBeComplete(): void
    {
        $transport = new FakeTransport([
            Wire::encode(['event' => Events::FRAME, 'data' => ['id' => 1, 'cost' => null]]),
            Wire::encode(['id' => 1, 'ok' => true, 'result' => ['state' => 'running']]),
        ]);
        $session = new RemoteSession($transport);

        $caught = null;
        try {
            $session->pause();
        } catch (ProtocolError $e) {
            $caught = $e;
        }

        check(
            '缺 seq 的信封 ⇒ bad_response',
            $caught !== null && $caught->errorCode === ProtocolError::BAD_RESPONSE,
            $caught === null ? '没抛' : $caught->errorCode,
        );
    }

    // ------------------------------------------------------------------ 目录

    public function testEventsDescribeIsClosedBothWays(): void
    {
        $described = Events::describe();
        check('目录条数与 ALL 一致', \count($described) === \count(Events::ALL), self::show($described));

        $names = \array_map(static fn (array $one): string => $one['name'], $described);
        check('ALL 里每个事件都有形状', $names === Events::ALL, self::show($names));
        check(
            'frame 的 data 声明了 id 与 cost',
            ($described[0]['data']['id'] ?? null) === 'int' && ($described[0]['data']['cost'] ?? null) === '?float',
            self::show($described[0] ?? null),
        );

        $caps = Capabilities::describe(null);
        check('能力目录里有 events 一栏', \is_array($caps['events'] ?? null), self::show($caps['events'] ?? null));
        check('协议版本升到 2', Capabilities::VERSION === 2 && Capabilities::MIN_VERSION === 2, 'VERSION=' . Capabilities::VERSION);
    }
}
