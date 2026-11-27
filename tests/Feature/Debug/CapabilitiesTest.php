<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\DevTools\App\DebugSession;
use Drift\DevTools\Protocol\Capabilities;
use Drift\DevTools\Protocol\Methods;
use Drift\DevTools\Protocol\ProtocolError;
use Drift\DevTools\Protocol\RemoteSession;
use Drift\DevTools\Protocol\Wire;
use Drift\DevTools\Session\QueryRouter;
use Drift\Debug\Session\Recorder;
use Drift\Debug\Session\Script;
use Drift\DevTools\Host\Drive;
use Drift\DevTools\Host\Host;
use Drift\DevTools\Host\Plan;
use Drift\DevTools\Host\ProcessTransport;
use Drift\DevTools\Host\Runner;
use Drift\DevTools\Host\SingleServer;
use Drift\Dev\Tests\Support\FakeTransport;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 协议版本与能力目录（A71）。
 *
 * ## 判据 standing
 *
 * 能力目录最大的风险不是"少一列"，而是**多一份说法**：客户端按目录去用，
 * 宿主按另一处判断拒绝；或者加了一个查询忘了写进目录，客户端永远不知道它存在。
 * 所以这里的判据大多是**双向闭合**与**同源**：目录的名单逐项等于各声明处的名单，
 * 目录说"不可用"的理由逐字等于真调用回的错误文本。
 *
 * 行为判据用内存通道（回执形状），真管道只判一次 `capabilities` 往返 —— 与
 * `HostTest` 的分工一致：消息怎么送不由这里管。
 */
final class CapabilitiesTest extends SmokeTestCase
{
    private function script(): Script
    {
        return Plan::build('SlowFrameApplication', 320.0, 240.0, [], 1, 1, []);
    }

    /** @param list<array<string, mixed>> $commands */
    private function serve(array $commands, bool $allowEval = false, bool $armPick = false): FakeTransport
    {
        $lines = [];
        foreach ($commands as $command) {
            $lines[] = Wire::encode($command);
        }
        $transport = new FakeTransport($lines);
        $arm = $armPick ? static fn(bool $on): array => ['armed' => $on] : null;
        $host = new Host(new SingleServer($transport), null, null, $allowEval, $arm);
        $host->serve(static fn(Script $s): array => Runner::open(
            $s->target,
            $s->width,
            $s->height,
            $s->meta(),
            $s,
        ));

        return $transport;
    }

    /**
     * 只看宿主的**配置面**：不跑 serve() 循环。
     *
     * 自走宿主在有消息时不会自己退（它随时准备接下一个观察者），拿 FakeTransport
     * 跑 serve() 会把它挂死；这里直接问回执的形状，真调用的拒绝理由由上面的
     * 内存通道判据独立钉住。
     *
     * @return array<string, mixed>
     */
    private function hostCaps(bool $allowEval, bool $driven, bool $armPick): array
    {
        $arm = $armPick ? static fn(bool $on): array => ['armed' => $on] : null;
        $host = new Host(
            new SingleServer(new FakeTransport([])),
            null,
            $driven ? new Drive($this->script()) : null,
            $allowEval,
            $arm,
        );
        $method = new \ReflectionMethod(Host::class, 'capabilities');

        return $method->invoke($host, null);
    }

    /** @param array<string, mixed> $params */
    private static function call(int $id, string $method, array $params = []): array
    {
        return ['id' => $id, 'method' => $method, 'params' => $params];
    }

    private static function show(mixed $value): string
    {
        return \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION) ?: \get_debug_type($value);
    }

    /** @param list<array<string, mixed>> $rows */
    private static function names(array $rows): array
    {
        return \array_map(static fn(array $r): string => (string) $r['name'], $rows);
    }

    public function testDirectoryIsDerivedFromTheExistingDeclarations(): void
    {
        $reply = Capabilities::describe();

        check('方法名单逐项等于动词表', self::names($reply['methods']) === Methods::ALL, self::show($reply['methods']));
        check('查询名单逐项等于查询面', self::names($reply['queries']) === \array_keys(QueryRouter::QUERIES));
        check('错误码逐项等于协议错误表', $reply['errors'] === ProtocolError::ALL);

        $byName = [];
        foreach ($reply['queries'] as $row) {
            $byName[$row['name']] = $row;
        }
        $selfServed = \array_filter($byName, static fn(array $r): bool => $r['origin'] === 'session');
        check('自产查询恰好四个', \count($selfServed) === \count(QueryRouter::SELF_SERVED));
        check('capabilities 是会话自产', ($byName['capabilities']['origin'] ?? '') === 'session');
        check('自产查询没有编造参数', ($byName['capabilities']['params'] ?? null) === []);

        $shape = [];
        foreach ($byName['treeDiff']['params'] ?? [] as $p) {
            $shape[$p['name']] = $p;
        }
        check('查询参数从规格表派生', \array_keys($shape) === ['base', 'frame', 'limit'], self::show($shape));
        check('可选可空参数的 default 是 null', \array_key_exists('frame', $shape) && \array_key_exists('default', $shape['frame']) && $shape['frame']['default'] === null);
        check('可选可空参数不是必填', ($shape['frame']['required'] ?? true) === false);
        check('有默认值的参数不是必填', ($shape['base']['required'] ?? true) === false);
        $explain = [];
        foreach ($byName['explain']['params'] ?? [] as $p) {
            $explain[$p['name']] = $p;
        }
        check('必填参数不给假默认值', ($explain['frame']['required'] ?? false) === true && !\array_key_exists('default', $explain['frame'] ?? []));
        $injected = \array_values(\array_filter($byName['pick']['params'] ?? [], static fn(array $p): bool => $p['name'] === 'roots'));
        check('注入源如实标注', ($injected[0]['injected'] ?? false) === true, self::show($injected));
        check('注入源不是必填', ($injected[0]['required'] ?? true) === false);
        check('注入源不当成默认值', ($injected[0]['source'] ?? '') === 'treeRootOrigins' && !\array_key_exists('default', $injected[0] ?? []), self::show($injected));
    }

    /**
     * 角色声明必须与动词表**双向**闭合：少一项在 `methods()` 里会抛，多一项
     * 原先没人管 —— 退役一个动词却留下角色声明，目录会继续替它回答。
     */
    public function testMethodMetaHasNoRetiredLeftovers(): void
    {
        $meta = (new \ReflectionClassConstant(Capabilities::class, 'METHOD_META'))->getValue();
        check('角色声明逐项等于动词表', \array_keys($meta) === Methods::ALL, self::show(\array_keys($meta)));
    }

    public function testVersionIsEnforcedNotDecoration(): void
    {
        check('当前版本被接受', Capabilities::supports(Capabilities::VERSION));
        check('老版本被拒绝', !Capabilities::supports(Capabilities::MIN_VERSION - 1));
        check('未来版本被拒绝', !Capabilities::supports(Capabilities::VERSION + 1));

        try {
            Capabilities::describe(Capabilities::VERSION + 1);
            check('未来版本显式抛错', false);
        } catch (ProtocolError $e) {
            check('未来版本显式抛错', $e->errorCode === ProtocolError::UNSUPPORTED_VERSION, $e->errorCode);
        }
        check('没报版本留 null', Capabilities::describe()['clientProtocol'] === null);
        check('报了版本就回显', Capabilities::describe(Capabilities::VERSION)['clientProtocol'] === Capabilities::VERSION);
    }

    public function testCapabilityDiscoveryWorksBeforeOpen(): void
    {
        $replies = $this->serve([
            ['id' => 1, 'method' => Methods::CAPABILITIES, 'params' => [], 'protocol' => Capabilities::VERSION],
            self::call(2, Methods::QUERY, ['name' => 'stats']),
        ])->replies();

        check('open 之前得到两条响应', \count($replies) === 2, self::show($replies));
        check('能力发现成功', ($replies[0]['ok'] ?? null) === true, self::show($replies[0] ?? null));
        check('能力发现回执带版本', ($replies[0]['result']['protocol'] ?? null) === Capabilities::VERSION);
        check('紧随其后的查询仍然未开会话', ($replies[1]['error']['code'] ?? '') === ProtocolError::NOT_OPEN);
        check('发现不偷偷开会话', ($replies[0]['result']['host']['sessionOpen'] ?? true) === false);
    }

    public function testHostAvailabilityComesFromTheSameRefusalAsTheCall(): void
    {
        $replies = $this->serve([
            self::call(1, Methods::CAPABILITIES),
            self::call(2, Methods::OPEN, ['script' => $this->script()->toArray()]),
            self::call(3, Methods::EVAL, ['expression' => '1 + 1']),
        ])->replies();
        $methods = [];
        foreach ($replies[0]['result']['methods'] ?? [] as $row) {
            $methods[$row['name']] = $row;
        }
        check('eval 默认关在目录里说不可用', ($methods[Methods::EVAL]['available'] ?? true) === false);
        check('从属宿主的 frame 可用', ($methods[Methods::FRAME]['available'] ?? false) === true);
        check('从属宿主的 pause 不可用', ($methods[Methods::PAUSE]['available'] ?? true) === false);
        check('没窗口时 pick 不可用', ($methods[Methods::PICK]['available'] ?? true) === false);
        check('拒绝理由不是空话', \str_contains((string) ($methods[Methods::EVAL]['reason'] ?? ''), '--allow-eval'));
        check('拒绝理由逐字等于真调用', ($replies[2]['error']['message'] ?? '') === ($methods[Methods::EVAL]['reason'] ?? ''));
        check('真调用确实被拒', ($replies[2]['error']['code'] ?? '') === ProtocolError::NOT_APPLICABLE);

        $driven = $this->hostCaps(true, true, true);
        $drivenMethods = [];
        foreach ($driven['methods'] ?? [] as $row) {
            $drivenMethods[$row['name']] = $row;
        }
        check('自走宿主的 frame 不可用', ($drivenMethods[Methods::FRAME]['available'] ?? true) === false);
        check('自走宿主的 pause 可用', ($drivenMethods[Methods::PAUSE]['available'] ?? false) === true);
        check('自走宿主的 finish 不可用', ($drivenMethods[Methods::FINISH]['available'] ?? true) === false);
        check('开了开关 eval 可用', ($drivenMethods[Methods::EVAL]['available'] ?? false) === true);
        check('有窗口时 pick 可用', ($drivenMethods[Methods::PICK]['available'] ?? false) === true);
        check('自走拒绝理由不是空话', \str_contains((string) ($drivenMethods[Methods::FRAME]['reason'] ?? ''), 'resume --frames=1'));
    }

    public function testEnvelopeVersionIsCheckedOnEveryMessage(): void
    {
        $replies = $this->serve([
            ['id' => 1, 'method' => Methods::CAPABILITIES, 'params' => [], 'protocol' => Capabilities::VERSION + 1],
            ['id' => 2, 'method' => Methods::CAPABILITIES, 'params' => [], 'protocol' => '1'],
            ['id' => 3, 'method' => Methods::CAPABILITIES, 'params' => []],
        ])->replies();

        check('未来版本报 unsupported_version', ($replies[0]['error']['code'] ?? '') === ProtocolError::UNSUPPORTED_VERSION);
        check('非整数版本报 bad_request', ($replies[1]['error']['code'] ?? '') === ProtocolError::BAD_REQUEST);
        check('没报版本照旧可用', ($replies[2]['ok'] ?? null) === true, self::show($replies[2] ?? null));
        check('坏版本不影响后续消息', ($replies[2]['result']['protocol'] ?? null) === Capabilities::VERSION);
    }

    public function testOfflinePackageCanAskTheSameDirectory(): void
    {
        $router = QueryRouter::offline(new Recorder());
        $reply = $router->query('capabilities');

        check('离线也能问能力', $reply['protocol'] === Capabilities::VERSION);
        check('离线目录与本地声明同源', self::names($reply['queries']) === \array_keys(QueryRouter::QUERIES));
        check('离线没有 host 一栏', !\array_key_exists('host', $reply));
        // 会话自产查询依然逐项可派发（A71 加进 QUERIES 后仍要闭合）。
        foreach (QueryRouter::SELF_SERVED as $name) {
            $ok = true;
            try {
                $router->query($name);
            } catch (\Throwable $e) {
                $ok = false;
            }
            check("自产查询可派发：{$name}", $ok);
        }
        check('会话查询面别名同步', DebugSession::QUERIES === QueryRouter::QUERIES);
    }

    public function testRemoteSessionProbesTheHostBeforeOpenOverARealPipe(): void
    {
        $transport = ProcessTransport::spawn([\PHP_BINARY, \dirname(__DIR__, 3) . '/devtools/bin/debug-host.php']);
        $remote = new RemoteSession($transport);
        try {
            $reply = $remote->capabilities();
            check('真管道上能发现能力', ($reply['protocol'] ?? null) === Capabilities::VERSION, self::show($reply));
            check('远端记住宿主版本', $remote->peerProtocol() === Capabilities::VERSION);
            check('真管道目录方法数对齐', self::names($reply['methods'] ?? []) === Methods::ALL);
            check('未 open 前 peerProtocol 不是空', $remote->peerProtocol() !== null);

            // 之后的每条消息都带版本号；老逻辑不能因为多了信封字段就断掉。
            $remote->open($this->script());
            check('握手后 open 照常', $remote->hostPid() !== null);
            check('握手后查询照常', \is_array($remote->query('stats')));
        } finally {
            $remote->detach();
            $transport->close();
        }
    }
}
