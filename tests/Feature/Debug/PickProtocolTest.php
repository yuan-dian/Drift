<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\DevTools\App\DebugSession;
use Drift\DevTools\Protocol\Methods;
use Drift\DevTools\Protocol\ProtocolError;
use Drift\DevTools\Protocol\Wire;
use Drift\Debug\Session\Script;
use Drift\DevTools\Host\Host;
use Drift\DevTools\Host\Plan;
use Drift\DevTools\Host\Runner;
use Drift\DevTools\Host\SingleServer;
use Drift\Dev\Tests\Support\FakeTransport;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * A58 的协议面：`PICK` 是干预组动词，宿主只做翻译，状态留在回调那一侧。
 *
 * 这里用内存通道，判的是"哪条消息得到哪个答复"；真 socket 往返由
 * {@see LiveAttachTest} 覆盖，状态机由 {@see LivePickTest} 覆盖。
 */
final class PickProtocolTest extends SmokeTestCase
{
    private function script(): Script
    {
        return Plan::build('SlowFrameApplication', 320.0, 240.0, [], 1, 1, []);
    }

    /**
     * 每条命令前面补一条 `open`：宿主对所有非 OPEN 动词都有 `not_open` 守卫，
     * 拾取本来也只在已开的会话上有意义。
     *
     * @param list<array<string, mixed>> $commands
     * @param \Closure(bool): array{armed: bool, reason?: string}|null $armPick
     */
    private function serve(array $commands, ?\Closure $armPick): FakeTransport
    {
        $all = [['id' => 0, 'method' => Methods::OPEN, 'params' => ['script' => $this->script()->toArray()]]];
        foreach ($commands as $command) {
            $all[] = $command;
        }

        $lines = [];
        foreach ($all as $command) {
            $lines[] = Wire::encode($command);
        }
        $transport = new FakeTransport($lines);
        $host = new Host(new SingleServer($transport), null, null, false, $armPick);
        $host->serve(static fn(Script $s): array => Runner::open(
            $s->target,
            $s->width,
            $s->height,
            $s->meta(),
            $s,
        ));

        return $transport;
    }

    /** @param array<string, mixed> $params */
    private static function call(int $id, array $params): array
    {
        return ['id' => $id, 'method' => Methods::PICK, 'params' => $params];
    }

    public function testPickIsRegisteredVerb(): void
    {
        check('PICK 在协议动词表里', \in_array(Methods::PICK, Methods::ALL, true));
        check('动词名与查询名同字但不同层', Methods::PICK === 'pick');
    }

    public function testHostForwardsArmResultVerbatim(): void
    {
        $seen = [];
        $replies = $this->serve([
            self::call(1, ['enabled' => true]),
            self::call(2, ['enabled' => false]),
        ], static function (bool $enabled) use (&$seen): array {
            $seen[] = $enabled;

            return ['armed' => $enabled, 'reason' => $enabled ? '' : 'cancelled'];
        })->decoded();

        check('open + 两条命令三条答复', \count($replies) === 3, self::show($replies));
        check('布防答复来自桥', ($replies[1]['result']['armed'] ?? null) === true, self::show($replies[1]));
        check('撤防答复也来自桥', ($replies[2]['result']['reason'] ?? null) === 'cancelled', self::show($replies[2]));
        check('参数原样穿过去', $seen === [true, false], self::show($seen));
    }

    public function testWithoutWindowIsNotApplicable(): void
    {
        $replies = $this->serve([self::call(1, ['enabled' => true])], null)->decoded();
        check('open + 一条命令两条答复', \count($replies) === 2, self::show($replies));
        check('是错误信封', $replies[1]['ok'] === false, self::show($replies[1]));
        check(
            '理由是 not_applicable 而不是 bad_request',
            ($replies[1]['error']['code'] ?? '') === ProtocolError::NOT_APPLICABLE,
            self::show($replies[1]),
        );
    }

    public function testBadParamIsAnEnvelopeNotACrash(): void
    {
        $replies = $this->serve([
            self::call(1, ['enabled' => 'yes']),
            self::call(2, ['enabled' => true]),
        ], static fn(bool $enabled): array => ['armed' => $enabled])->decoded();

        check(
            '参数错报 bad_request',
            ($replies[1]['error']['code'] ?? '') === ProtocolError::BAD_REQUEST,
            self::show($replies[1]),
        );
        check('一条错消息不带走宿主', $replies[2]['ok'] === true, self::show($replies[2]));
    }

    private static function show(mixed $value): string
    {
        return \json_encode($value, \JSON_UNESCAPED_UNICODE) ?: \get_debug_type($value);
    }
}
