<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\Application;
use Drift\DevTools\App\DebugSession;
use Drift\Debug\Session\Recorder;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\SemanticsTree;
use Drift\Rendering\SurfaceProfile;

/**
 * D1 判据：会话门面必须与直接驱动**产出同一份记录**。
 *
 * 这是"三种形态一套查询"的第一块砖：如果会话自己引入了一份记账（比如内部缓存、
 * 自己数帧），那么将来接上文件传输时，"headless 的结论"和"真实窗口的结论"就会
 * 从源头分叉。所以这里逐帧比 `rebuilt` 与每条边的 `kind / from / to / site`，
 * 只放过时间戳与耗时（那两个本来就依赖墙钟）。
 */
final class DebugSessionTest extends SmokeTestCase
{
    private function constraints(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(200.0, 120.0));
    }

    private function boot(): Application
    {
        $app = new QueryApplication();
        $app->attach(new SurfaceProbe(), $this->constraints());

        return $app;
    }

    public function testSessionMatchesDirectDriving(): void
    {
        $direct = $this->driveDirect();
        $session = $this->driveSession();

        check(
            '帧数一致',
            \count($direct) === \count($session),
            'direct=' . \count($direct) . ' session=' . \count($session),
        );

        $same = true;
        $detail = '';
        foreach ($direct as $i => $frame) {
            $other = $session[$i] ?? null;
            if ($other === null || $frame !== $other) {
                $same = false;
                $detail = '第 ' . $i . ' 帧：' . \json_encode($frame) . ' vs ' . \json_encode($other);
                break;
            }
        }
        check('逐帧记录一致（id / rebuilt / 边 / 跳过标记）', $same, $detail);
    }

    public function testQueryNamesAllDispatch(): void
    {
        $app = $this->boot();
        $session = DebugSession::open($app, $this->constraints());

        try {
            $session->pump(0.0);
            QueryState::$last?->increment();
            $session->pump(16.0);

            $nodeId = QueryState::$last?->element()?->debugId ?? 0;
            // 新增查询时这里必须补参数：没有必填参数的查询可以直接调，有的必须给。
            $args = [
                'stats' => [],
                'frames' => [],
                'explain' => ['frame' => 2],
                'why' => ['node' => $nodeId, 'frame' => 2],
                'hotspots' => [],
                'nodes' => [],
                'cascade' => ['frame' => 2],
                'boxModel' => ['node' => $nodeId],
                'constraints' => ['node' => $nodeId],
                'network' => [],
                'networkBody' => ['request' => 999],
                'async' => [],
                'tree' => [],
                'treeDiff' => [],
                'semantics' => [],
                'semanticsNode' => ['index' => 0],
                'semanticsHit' => ['x' => 1.0, 'y' => 2.0],
                'align' => ['node' => $nodeId, 'frame' => 2],
                'nodeContext' => ['node' => $nodeId, 'frame' => 2],
                'find' => ['pattern' => 'Text'],
                'props' => ['node' => $nodeId],
                'memory' => [],
                'source' => ['site' => 'no-such-file.php:1'],
                'pick' => ['x' => 1.0, 'y' => 2.0],
                'state' => [],
            ];

            foreach (DebugSession::QUERIES as $name => $_) {
                $ok = false;
                try {
                    $ok = \is_array($session->query($name, $args[$name] ?? []));
                } catch (\Throwable $e) {
                    $ok = false;
                }
                check("名单里的查询可派发：{$name}", $ok);
            }

            $threw = false;
            try {
                $session->query('nope');
            } catch (\InvalidArgumentException) {
                $threw = true;
            }
            check('未知查询抛异常，不返回空数组', $threw);

            // 双向闭合：能力面（QUERIES）与协议形状（QUERY_SPECS + 会话自产的三条）
            // 必须一一对应。少一条 → 有查询派发不了；多一条 → 名单在吹牛。
            // 旧判据是靠正则读源码里的 `match` 分支，改成规格表后就永远失配 ——
            // 而"失配时假绿"比没有判据更糟。
            //
            // 自产的三条不进规格表，是因为它们没有 `Query` 方法可转发：`digest` 算
            // 指纹、`script` 回脚本、`state` 回会话身份与状态。把它们塞进规格表就得
            // 给 `Query` 造三个假方法，而 `Query` 只认 `Recorder`。
            $selfServed = \Drift\DevTools\Session\QueryRouter::SELF_SERVED;
            $declared = \array_keys(DebugSession::QUERIES);
            $shaped = \array_merge(\array_keys(DebugSession::QUERY_SPECS), $selfServed);
            \sort($declared);
            \sort($shaped);
            check(
                'QUERIES 与 QUERY_SPECS 双向闭合',
                $declared === $shaped,
                'declared=' . \json_encode($declared) . ' shaped=' . \json_encode($shaped),
            );

            // 规格表是协议面的唯一事实源：它说有几个参数、什么类型、缺省多少，
            // `Query` 的方法签名就必须是那个样子。两处谈不拢时不会报错，只会让
            // "同一次调用在本地与远端给出不同结果"——两条路径各自看起来都正常。
            $mismatch = [];
            $reflection = new \ReflectionClass(\Drift\DevTools\Session\Query::class);
            foreach (DebugSession::QUERY_SPECS as $name => [$method, $params]) {
                if (!$reflection->hasMethod($method)) {
                    $mismatch[] = "{$name}: Query 上没有方法 {$method}";
                    continue;
                }
                $actual = $reflection->getMethod($method)->getParameters();
                if (\count($actual) !== \count($params)) {
                    $mismatch[] = \sprintf('%s: 参数个数 %d ≠ %d', $name, \count($params), \count($actual));
                    continue;
                }

                $i = 0;
                foreach ($params as $key => $spec) {
                    $p = $actual[$i++];
                    $type = $spec[0];
                    $extra = $spec[1] ?? null;

                    if ($p->getName() !== $key) {
                        $mismatch[] = "{$name}.{$key}: 形参名是 \${$p->getName()}";
                        continue;
                    }

                    $rt = $p->getType();
                    // 注入参数按注入源定类型：树根是数组，语义树是 `SemanticsTree`
                    // 对象（A77 要复用生产 hitTest，不能压成标量数组）。
                    $want = $type === 'inject'
                        ? (($spec[1] ?? '') === 'semanticsTree' ? SemanticsTree::class : 'array')
                        : \ltrim($type, '?');
                    if (!$rt instanceof \ReflectionNamedType || $rt->getName() !== $want
                        || ($type !== 'inject' && \str_starts_with($type, '?') !== $rt->allowsNull())) {
                        $mismatch[] = \sprintf('%s.%s: 类型不是 %s', $name, $key, $type);
                        continue;
                    }
                    if ($type === 'inject') {
                        continue;
                    }

                    $optional = \array_key_exists(1, $spec) || \str_starts_with($type, '?');
                    if ($p->isOptional() !== $optional) {
                        $mismatch[] = \sprintf(
                            '%s.%s: 可选性应为「%s」',
                            $name,
                            $key,
                            $optional ? '可选' : '必填',
                        );
                        continue;
                    }
                    if ($optional && $p->isDefaultValueAvailable() && $p->getDefaultValue() !== $extra) {
                        $mismatch[] = \sprintf(
                            '%s.%s: 缺省 %s ≠ %s',
                            $name,
                            $key,
                            \json_encode($p->getDefaultValue()),
                            \json_encode($extra),
                        );
                    }
                }
            }
            check('QUERY_SPECS 与 Query 签名逐参一致', $mismatch === [], \implode('；', $mismatch));
        } finally {
            $session->close();
        }
    }

    public function testNodeContextKeepsSectionStatusAndLiveProps(): void
    {
        $app = $this->boot();
        $session = DebugSession::open($app, $this->constraints());

        try {
            $session->pump(0.0);
            QueryState::$last?->increment();
            $session->pump(16.0);
            $boxes = $session->query('boxes', ['frame' => 1]);
            $node = (int) ($boxes['rows'][0]['node'] ?? 0);

            $all = $session->query('nodeContext', ['node' => $node, 'frame' => 2]);
            check('聚合视图回显节点', (int) ($all['node'] ?? 0) === $node, \json_encode($all['node'] ?? null));
            check('几何 section 可用', ($all['sections']['geometry']['status'] ?? '') === 'ok', \json_encode($all['sections']['geometry'] ?? []));
            check('几何回退到已采帧并标 stale', ($all['sections']['geometry']['data']['stale'] ?? false) === true, \json_encode($all['sections']['geometry'] ?? []));
            check('属性 section 标 live', ($all['sections']['properties']['live'] ?? false) === true, \json_encode($all['sections']['properties'] ?? []));
            check('源码 section 不吞成异常', ($all['sections']['source']['status'] ?? 'error') !== 'error', \json_encode($all['sections']['source'] ?? []));

            $stateNode = QueryState::$last?->element()?->debugId ?? 0;
            $src = $session->query('nodeContext', ['node' => $stateNode, 'frame' => 2, 'sections' => 'source']);
            check('源码 section 能定位', ($src['sections']['source']['status'] ?? '') === 'ok', \json_encode($src['sections']['source'] ?? []));
            check('源码带编辑器 URI', \str_starts_with((string) ($src['sections']['source']['data']['editorUri'] ?? ''), 'vscode://file/'), \json_encode($src['sections']['source']['data'] ?? []));

            $one = $session->query('nodeContext', ['node' => $node, 'frame' => 2, 'sections' => 'geometry']);
            check('只回请求的 section', \array_keys($one['sections']) === ['geometry'], \implode(',', \array_keys($one['sections'])));

            $net = $session->query('nodeContext', ['node' => $node, 'frame' => 2, 'sections' => 'network']);
            check(
                '没发请求的节点回 empty 而非 unavailable',
                ($net['sections']['network']['status'] ?? '') === 'empty',
                \json_encode($net['sections']['network'] ?? []),
            );
        } finally {
            $session->close();
        }

        $this->flushChecks();
    }

    public function testFrameRecordsCarryStageCosts(): void
    {
        $app = $this->boot();
        $session = DebugSession::open($app, $this->constraints());

        try {
            $session->pump(0.0);
            QueryState::$last?->increment();
            $session->pump(16.0);

            $frames = $session->query('frames')['frames'];
            check('帧表不是空的', $frames !== []);
            $withCost = 0;
            foreach ($frames as $frame) {
                if (($frame['stageMs'] ?? []) !== []) {
                    ++$withCost;
                }
            }
            check(
                '每一帧都带段耗时（段账没接上时这里会全空）',
                $withCost === \count($frames),
                'withCost=' . $withCost . ' frames=' . \count($frames),
            );

            $detail = $session->query('explain', ['frame' => 2]);
            $stageMs = $detail['stageMs'] ?? [];
            check('explain 也展开段耗时', $stageMs !== [], 'stageMs=' . \json_encode($stageMs));
            check(
                'settle 真的记到了耗时（不是只有段名）',
                (float) ($stageMs['settle'] ?? 0) > 0.0,
                'stageMs=' . \json_encode($stageMs),
            );
            check('未归属是数不是空', \is_float($detail['unattributedMs'] ?? null));
        } finally {
            $session->close();
        }

        check('关闭会话后量具还原，不替调用方关探针', SurfaceProfile::$on === false);
    }

    public function testCallerOwnedProfileIsLeftAlone(): void
    {
        SurfaceProfile::$on = true;
        SurfaceProfile::reset();
        SurfaceProfile::add('foreign', 1000);

        try {
            $app = $this->boot();
            $session = DebugSession::open($app, $this->constraints());
            try {
                $session->pump(0.0);
            } finally {
                $session->close();
            }

            check('调用方开着量具时不会替他关掉', SurfaceProfile::$on === true);
            check(
                '调用方的累计账没被帧首清零冲掉',
                (SurfaceProfile::$ns['foreign'] ?? 0) === 1000,
                'ns=' . \json_encode(SurfaceProfile::$ns),
            );
        } finally {
            SurfaceProfile::$on = false;
            SurfaceProfile::reset();
        }
    }

    public function testOpenRefusesNestedSessionAndUnattachedApp(): void
    {
        $app = $this->boot();

        $first = DebugSession::open($app, $this->constraints());
        try {
            $threw = false;
            try {
                DebugSession::open($app, $this->constraints());
            } catch (\LogicException) {
                $threw = true;
            }
            check('已有会话时不静默接管，而是拒绝', $threw);
        } finally {
            $first->close();
        }

        check('关闭后录制器已卸下', Recorder::current() === null);

        $bare = new QueryApplication();
        $threw = false;
        try {
            DebugSession::open($bare, $this->constraints());
        } catch (\LogicException) {
            $threw = true;
        }
        check('没挂表面的应用不能开会话', $threw);
    }

    // ------------------------------------------------------------------ 驱动

    /** @return list<array<string, mixed>> */
    private function driveDirect(): array
    {
        $app = $this->boot();
        $recorder = Recorder::install(); // 与 `DebugSession::open()` 同一起点：attach 之后

        try {
            $this->drive(function (float $t) use ($app): void {
                $app->frame($t, $this->constraints());
            });

            return $this->normalize($recorder);
        } finally {
            Recorder::uninstall();
        }
    }

    /** @return list<array<string, mixed>> */
    private function driveSession(): array
    {
        $app = $this->boot();
        $session = DebugSession::open($app, $this->constraints());

        try {
            $this->drive(function (float $t) use ($session): void {
                $session->pump($t);
            });

            return $this->normalize($session->recorder());
        } finally {
            $session->close();
        }
    }

    /**
     * 两条驱动路径共用的同一段操作。
     *
     * ★ 必须共用：`site` 记的是调用点行号，两边各写一遍 `increment()` 就必然不同，
     *   那样比出来的差异是"测试写法不同"，不是"驱动方式影响了记录"。
     *
     * @param callable(float): void $pump
     */
    private function drive(callable $pump): void
    {
        $pump(0.0);
        QueryState::$last?->increment();
        $pump(16.0);
        QueryState::$last?->increment();
        $pump(32.0);
    }

    /**
     * `state` 查询：令牌 + 末帧 + 状态/原因。
     *
     * 三条判据各自防一件事：
     * - 令牌非空且两次 open 不同：跨进程观察者靠它判"时间线换了"；
     * - 末帧与 `frames()` 末帧一致：两个数必须同一时刻读（分两次查时中间可能又推进
     *   一帧），否则"观察者看到的"与"宿主报的"天然对不上；
     * - `reason` 区分 `at` 与 `requested`：两者对观察者含义相反（去看吧 / 一会儿还动）。
     */
    public function testStateCarriesTokenFrameAndReason(): void
    {
        $app = $this->boot();
        $session = DebugSession::open($app, $this->constraints());

        try {
            $session->pump(0.0);
            $session->pump(16.0);

            $state = $session->query('state');
            check('令牌非空', \is_string($state['session']) && $state['session'] !== '', 'session=' . \json_encode($state['session']));
            check('初始状态是 running', $state['state'] === 'running', (string) $state['state']);
            check('running 没有原因', $state['reason'] === null, \json_encode($state['reason']));

            $framesResponse = $session->query('frames');
            check(
                'frames 响应带会话令牌 —— 跨会话判断的唯一依据',
                ($framesResponse['session'] ?? null) === $state['session'],
                \json_encode($framesResponse['session'] ?? null),
            );

            /** @var list<array<string, mixed>> $frameRows */
            $frameRows = $framesResponse['frames'];
            $lastFromFrames = $frameRows === [] ? 0 : (int) $frameRows[\count($frameRows) - 1]['id'];
            check(
                '末帧与 frames() 末帧同源',
                $state['lastFrame'] === $lastFromFrames && $lastFromFrames > 0,
                \sprintf('state=%d frames=%d', $state['lastFrame'], $lastFromFrames),
            );

            $session->markState('paused', 'at');
            $paused = $session->query('state');
            check('状态改成 paused', $paused['state'] === 'paused', (string) $paused['state']);
            check('原因保留 at（与 requested 区分）', $paused['reason'] === 'at', \json_encode($paused['reason']));
            check('令牌不随状态变', $paused['session'] === $state['session']);

            $threw = false;
            try {
                $session->markState('nonsense');
            } catch (\InvalidArgumentException) {
                $threw = true;
            }
            check('不认识的状态当场拒绝', $threw);
        } finally {
            $session->close();
        }
    }

    /**
     * 只留结构字段：时间戳与耗时依赖墙钟，逐值比必然抖。
     *
     * @return list<array<string, mixed>>
     */
    private function normalize(Recorder $recorder): array
    {
        $out = [];
        foreach ($recorder->frames() as $frame) {
            $causes = [];
            foreach ($frame->causes as $edge) {
                $causes[] = [$edge->kind, $edge->fromId, $edge->toId, $edge->site, $edge->detail];
            }
            $out[] = [
                'id' => $frame->id,
                'rebuilt' => $frame->rebuilt,
                'causes' => $causes,
                'settle' => $frame->settleSkipped,
                'paint' => $frame->paintSkipped,
            ];
        }

        return $out;
    }
}
