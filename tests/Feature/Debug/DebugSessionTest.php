<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\Application;
use Drift\App\DebugSession;
use Drift\Debug\Session\Recorder;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
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
                'align' => ['node' => $nodeId, 'frame' => 2],
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

            // 反向：`match` 里的分支不能有名单外的名字（否则能力面漏报）。
            $path = \dirname(__DIR__, 3) . '/src/App/DebugSession.php';
            $src = \is_file($path) ? (string) \file_get_contents($path) : '';
            check('能读到会话源码（路径漂了就别假装通过）', $src !== '', $path);

            // 查询名有 camelCase（boxModel / networkBody）：只匹配小写会漏掉它们，
            // 判据就成了摆设。这里覆盖全字母名。
            \preg_match_all("/'([a-zA-Z]+)' => \\\$this->queries->/", $src, $m);
            $found = $m[1] ?? [];
            check('正则至少命中一条分支（失配时不许假绿）', $found !== [], 'found=' . \json_encode($found));

            $extra = \array_diff($found, \array_keys(DebugSession::QUERIES));
            check('match 分支都在 QUERIES 名单里', $extra === [], 'extra=' . \json_encode($extra));
        } finally {
            $session->close();
        }
    }

    public function testFrameRecordsCarryStageCosts(): void
    {
        $app = $this->boot();
        $session = DebugSession::open($app, $this->constraints());

        try {
            $session->pump(0.0);
            QueryState::$last?->increment();
            $session->pump(16.0);

            $frames = $session->query('frames');
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
