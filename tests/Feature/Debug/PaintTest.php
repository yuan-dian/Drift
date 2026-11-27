<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\Application;
use Drift\DevTools\App\DebugSession;
use Drift\DevTools\Session\Evidence;
use Drift\DevTools\Session\Query;
use Drift\Debug\Session\PaintRecord;
use Drift\Debug\Session\RecordOptions;
use Drift\Debug\Session\Recorder;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * S3d 判据：绘制域记的是不是"这一帧真的画了哪些节点"。
 *
 * ## 钉什么
 *
 * - **盒 → 节点映射对得上**：`painted` 里每个节点在节点表里都有类型与路径。
 *   映射错了不会报错，只会让"谁在重画"指向一个不存在的地址；
 * - **命中与未命中分开**：层缓存命中意味着整棵子树一条 `paint()` 都没跑。
 *   把命中算进 `painted`，报告会说"省掉的节点也画了"——正好反了；
 * - **聚合能由逐帧复算**：`repaints()` 与 `paint(frame)` 必须同源。两处各数一遍
 *   必然漂，而漂的表现是"简报说每帧都画、逐帧看只有 3 帧"；
 * - **没采 ≠ 没画**：预算没开时查绘制域要报 `paint-not-captured`，而"这一帧整段
 *   跳过绘制"是一个**有记录**的空集合。混成一句，读者会一直等一条永远不会有的记录；
 * - **窗口外不 clamp**：绘制每帧都落一条，"查不到"只能是没采，不存在"用上一帧
 *   代替"——往前找会把"这帧没画"说成"这帧画了上一帧的那些"；
 * - **证据往返逐值相同**：`PaintRecord` 只存标量，出包再入包不能变形。
 */
final class PaintTest extends SmokeTestCase
{
    private function constraints(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(200.0, 120.0));
    }

    /**
     * 两棵子树各自是重绘边界，只有左边那棵会变。
     *
     * 这是**命中路径唯一能被咬到的形状**：左边界失效 → 整帧要重画；右边界没被
     * 波及 → 重放命令流。单边界（表格页那种）永远只有未命中，`hits` 那条分支
     * 一行都跑不到，而它正是"边界到底省了多少"的全部依据。
     *
     * @return array{0: DebugSession, 1: Recorder}
     */
    private function splitSession(): array
    {
        $c = $this->constraints();
        $app = new SplitBoundaryApplication();
        $app->attach(new SurfaceProbe(), $c);

        $session = DebugSession::open($app, $c, RecordOptions::full(600));
        // 前两帧是挂载：绘制在 `attach()` 里就发生了，帧内 `paintSkipped=true`。
        $session->pumpTimes(2, 16.0, 0.0);

        return [$session, $session->recorder()];
    }

    public function testPaintedNodesAreRealNodesInTheTree(): void
    {
        [$session] = $this->splitSession();
        try {
            SplitBoundaryState::$last?->bump();
            $session->pumpTimes(1, 16.0, 32.0);

            $agg = $session->query('repaints', ['frames' => 0, 'limit' => 0]);
            check('绘制窗口里有帧', ($agg['frames'] ?? 0) > 0, \json_encode($agg['window'] ?? []));
            check('窗口里有节点被画', ($agg['nodesTotal'] ?? 0) > 0, \json_encode($agg['nodesTotal'] ?? null));

            $bad = [];
            foreach ($agg['nodes'] as $row) {
                // 类型与路径都来自节点表。盒 → 节点映射一旦错位，这里读到的是空串，
                // 而报告会照印一个 `node:N` 地址 —— 既指不到东西也不报错。
                if ((string) ($row['type'] ?? '') === '' || (string) ($row['path'] ?? '') === '') {
                    $bad[] = $row['node'];
                }
            }
            check('每个重画节点都在结构树里（有类型与路径）', $bad === [], \json_encode($bad));
        } finally {
            $session->close();
        }
        $this->flushChecks();
    }

    /**
     * 命中与未命中必须分开，且原因能落到具体节点上。
     *
     * 一条判据同时钉两件事：`hits > 0`（重放分支真的跑了）与"未命中的那个节点
     * 就是左边那棵边界"。只钉前者，一个"把所有边界都记成命中"的实现也能绿。
     */
    public function testLayerCacheHitAndMissLandOnDifferentBoundaries(): void
    {
        [$session] = $this->splitSession();
        try {
            SplitBoundaryState::$last?->bump();
            $session->pumpTimes(1, 16.0, 32.0);

            $frame = $session->query('state')['lastFrame'];
            $paint = $session->query('paint', ['frame' => $frame]);
            check('绘制记录存在', ($paint['found'] ?? false) === true, \json_encode($paint));

            check('至少有一次层缓存命中', (int) ($paint['hits'] ?? 0) > 0,
                \json_encode(['hits' => $paint['hits'] ?? null, 'painted' => $paint['painted'] ?? []]));

            $misses = $paint['misses'] ?? [];
            check('有边界未命中', $misses !== [], \json_encode($misses));
            foreach ($misses as $node => $reason) {
                check("未命中原因属于已知四种（node {$node}）", \in_array($reason, [
                    'first', 'invalidated', 'resized', 'capabilities',
                ], true), (string) $reason);
            }

            // 命中 = 整棵子树一条 paint 都没跑 ⇒ 被重放的边界**不在** painted 里。
            // 这条是"省掉的节点不能被记成画过的节点"的直接判据。
            $painted = $paint['painted'] ?? [];
            $overlap = \array_intersect(\array_keys($misses), $painted);
            check('未命中的边界自己确实画了（在 painted 里）', \count($overlap) === \count($misses),
                \json_encode(['misses' => \array_keys($misses), 'painted' => $painted]));
            check('painted 去重', \count($painted) === \count(\array_unique($painted)), \json_encode($painted));

            // ★ 集合大小不能超过真画次数：每个进集合的节点都至少对应一次 `paint()`。
            //   把命中的盒也塞进集合会立刻破这条（集合比真画次数多），而它正是本域
            //   最容易犯、最难自查的错 —— 命中是"省下来的"，记成"画过的"以后报告
            //   会把省掉的成本算成开销。
            check('集合不超过真画次数（命中不是"画过"）',
                \count($painted) <= (int) ($paint['paintedTotal'] ?? 0),
                \json_encode([
                    'painted' => \count($painted),
                    'paintedTotal' => $paint['paintedTotal'] ?? null,
                    'hits' => $paint['hits'] ?? null,
                ]));
        } finally {
            $session->close();
        }
        $this->flushChecks();
    }

    /**
     * 聚合能由逐帧复算 —— 两个查询必须同源。
     *
     * 复算走**另一条路**：逐帧 `paint(frame)` 累加，与 `repaints()` 一次算好的比。
     * 两边都从同一个 `Recorder` 读，但一个是逐帧视图、一个是聚合视图；聚合里
     * 多算一次、漏一帧、或把 `hits` 当节点数加，都会在这里露出来。
     */
    public function testAggregateCanBeRecomputedFromPerFrameRecords(): void
    {
        [$session] = $this->splitSession();
        try {
            SplitBoundaryState::$last?->bump();
            $session->pumpTimes(2, 16.0, 32.0);

            $agg = $session->query('repaints', ['frames' => 0, 'limit' => 0]);
            $frames = $session->query('frames', ['limit' => 0])['frames'];

            $hits = 0;
            $misses = 0;
            $nodes = [];
            $boundary = [];
            foreach ($frames as $one) {
                $paint = $session->query('paint', ['frame' => (int) $one['id']]);
                if (($paint['found'] ?? false) !== true) {
                    continue;
                }
                $hits += (int) $paint['hits'];
                $misses += \count($paint['misses']);
                foreach ($paint['painted'] as $node) {
                    $nodes[$node] = true;
                }
                foreach (\array_keys($paint['misses']) as $node) {
                    $boundary[$node] = true;
                }
            }

            check('帧数一致', (int) ($agg['frames'] ?? -1) === \count($frames),
                \json_encode(['agg' => $agg['frames'] ?? null, 'frames' => \count($frames)]));
            check('命中数可由逐帧复算', (int) ($agg['hits'] ?? -1) === $hits,
                \json_encode(['agg' => $agg['hits'] ?? null, 'recomputed' => $hits]));
            check('未命中数可由逐帧复算', (int) ($agg['misses'] ?? -1) === $misses,
                \json_encode(['agg' => $agg['misses'] ?? null, 'recomputed' => $misses]));

            $aggNodes = [];
            foreach ($agg['nodes'] as $row) {
                $aggNodes[(int) $row['node']] = true;
            }
            \ksort($nodes);
            \ksort($aggNodes);
            check('节点集合与逐帧并集相同', \array_keys($nodes) === \array_keys($aggNodes),
                \json_encode(['agg' => \array_keys($aggNodes), 'recomputed' => \array_keys($nodes)]));

            foreach ($agg['nodes'] as $row) {
                $node = (int) $row['node'];
                check("节点 {$node} 只在边界集合里才带原因",
                    ((int) $row['misses'] > 0) === isset($boundary[$node]),
                    \json_encode($row));
            }
        } finally {
            $session->close();
        }
        $this->flushChecks();
    }

    /**
     * 绘制窗口只有 60 帧，跑过头必须裁掉最老的。
     *
     * 这个窗口比帧窗口（600）短一个量级：逐帧一个节点集合，跟着 600 帧走会让
     * 证据包与常驻内存都翻十倍。裁掉之后 `from` / `to` 要跟着动 —— 窗口自报的
     * 就是这两个数，它们不跟着走等于窗口在说谎。
     */
    public function testPaintWindowIsBoundedAndReportsItsRange(): void
    {
        [$session] = $this->splitSession();
        try {
            $session->pumpTimes(Recorder::PAINT_FRAMES + 10, 16.0, 32.0);

            $agg = $session->query('repaints', ['frames' => 0, 'limit' => 0]);
            $window = $agg['window'] ?? [];
            $last = $session->query('state')['lastFrame'];

            check('窗口不超过容量', (int) ($agg['frames'] ?? 0) <= Recorder::PAINT_FRAMES,
                \json_encode(['frames' => $agg['frames'] ?? null, 'cap' => Recorder::PAINT_FRAMES]));
            check('窗口是满的（跑够了帧）', (int) ($agg['frames'] ?? 0) === Recorder::PAINT_FRAMES,
                \json_encode(['frames' => $agg['frames'] ?? null]));
            check('窗口末端跟到末帧', (int) ($window['to'] ?? 0) === $last,
                \json_encode(['to' => $window['to'] ?? null, 'lastFrame' => $last]));
            check('窗口起点 = 末端 − 容量 + 1',
                (int) ($window['from'] ?? 0) === $last - Recorder::PAINT_FRAMES + 1,
                \json_encode(['from' => $window['from'] ?? null, 'to' => $window['to'] ?? null]));
            check('被裁掉的帧查不到（不 clamp 回来）',
                ($session->query('paint', ['frame' => 1])['reason'] ?? '') === 'frame-out-of-window',
                \json_encode($session->query('paint', ['frame' => 1])));
        } finally {
            $session->close();
        }
        $this->flushChecks();
    }

    /**
     * "没采"与"这一帧没画"是两件事。
     *
     * 前者是预算没开（`captured=false`，查任何帧都报 `paint-not-captured`），
     * 后者是帧整段跳过了绘制（`found=true` 且 `painted=[]`）。合成一句，读者会
     * 把"没开采集"读成"这帧没重画"，然后一直查下去。
     */
    public function testNotCapturedIsNotTheSameAsNothingPainted(): void
    {
        $c = $this->constraints();

        // ① 预算没开：`default()` 不含绘制域。
        $app = new SplitBoundaryApplication();
        $app->attach(new SurfaceProbe(), $c);
        $off = DebugSession::open($app, $c, RecordOptions::default(600));
        try {
            $off->pumpTimes(1, 16.0, 0.0);
            $miss = $off->query('paint', ['frame' => 1]);
            check('未开预算时报 paint-not-captured', ($miss['found'] ?? true) === false
                && ($miss['reason'] ?? '') === 'paint-not-captured', \json_encode($miss));
            check('未开预算时不返回空集合（那会被读成"没画"）', !\array_key_exists('painted', $miss),
                \json_encode(\array_keys($miss)));

            $agg = $off->query('repaints');
            check('聚合也自报 captured=false', ($agg['captured'] ?? true) === false, \json_encode($agg));
            check('聚合不给空节点表冒充"没有重画"', ($agg['nodes'] ?? ['x']) === [], \json_encode($agg));
        } finally {
            $off->close();
        }

        // ② 预算开着，但这一帧整段跳过绘制：有记录、集合为空。
        [$session] = $this->splitSession();
        try {
            $skipped = null;
            foreach ($session->query('frames', ['limit' => 0])['frames'] as $one) {
                if (($one['paintSkipped'] ?? false) === true) {
                    $skipped = (int) $one['id'];
                    break;
                }
            }
            check('夹具里有整段跳过绘制的帧', $skipped !== null, '挂载帧应当 paintSkipped');

            $paint = $session->query('paint', ['frame' => (int) $skipped]);
            check('跳过的帧仍然有绘制记录', ($paint['found'] ?? false) === true, \json_encode($paint));
            check('跳过的帧集合为空而不是"查不到"', ($paint['painted'] ?? null) === []
                && (int) ($paint['paintedTotal'] ?? -1) === 0, \json_encode($paint));
            check('空集合的命中率是 0 而不是 100%', (float) ($paint['hitRate'] ?? -1.0) === 0.0,
                \json_encode($paint['hitRate'] ?? null));
        } finally {
            $session->close();
        }

        $this->flushChecks();
    }

    /**
     * 命中率声明是 float，全命中时也必须是 `1.0`，不能是 `1`。
     *
     * `int / int` 在解释器里"除得尽就回 int"（`4/4 === 1`），在 AOT 下更是整数
     * 除法 —— 同一份记录两个答案。显式 `(float)` 把两端钉在同一种类型上。
     */
    public function testHitRateStaysFloatEvenWhenItIsWhole(): void
    {
        $partial = new PaintRecord(1, [], [9 => PaintRecord::MISS_FIRST], 1, 3, 0, false);
        check('比例是浮点', \is_float($partial->hitRate()), \gettype($partial->hitRate()));
        check('比例值正确', $partial->hitRate() === 0.75, (string) $partial->hitRate());

        $full = new PaintRecord(1, [], [], 1, 4, 0, false);
        check('全命中为 1.0', \is_float($full->hitRate()) && $full->hitRate() === 1.0, \gettype($full->hitRate()));
    }

    /**
     * 窗口外的帧号不许被 clamp 成窗口末端。
     *
     * 绘制每帧落一条记录，所以"查不到"只有两种原因：帧在窗口外，或这一帧没采。
     * 若实现里有个"往前找最近一条"，`frame=9999` 会拿到最后一帧的画集合并标成
     * `found=true` —— 自洽、可读、全错。
     */
    public function testOutOfWindowFrameIsReportedNotClamped(): void
    {
        [$session] = $this->splitSession();
        try {
            $ahead = $session->query('paint', ['frame' => 9999]);
            check('未来帧报 frame-out-of-window', ($ahead['found'] ?? true) === false
                && ($ahead['reason'] ?? '') === 'frame-out-of-window', \json_encode($ahead));
            check('未来帧不返回画集合', !\array_key_exists('painted', $ahead), \json_encode(\array_keys($ahead)));

            $behind = $session->query('paint', ['frame' => -1]);
            check('窗口之前的帧同样报窗口外', ($behind['reason'] ?? '') === 'frame-out-of-window',
                \json_encode($behind));
        } finally {
            $session->close();
        }
        $this->flushChecks();
    }

    /**
     * 出包再入包，绘制域逐值相同。
     *
     * 走的是真实的 NDJSON：`misses` 是非连续整数键的映射，编码成对象、读回成
     * 字符串键 —— 这一层最容易掉类型。比"PHP 数组层面相等"多守一道。
     */
    public function testEvidenceRoundTripKeepsPaintFacts(): void
    {
        [$session, $recorder] = $this->splitSession();
        try {
            SplitBoundaryState::$last?->bump();
            $session->pumpTimes(1, 16.0, 32.0);

            $before = $session->query('repaints', ['frames' => 0, 'limit' => 0]);
            $frame = $session->query('state')['lastFrame'];
            $beforeFrame = $session->query('paint', ['frame' => $frame]);

            $path = \sys_get_temp_dir() . '/drift-paint-' . \bin2hex(\random_bytes(6)) . '.ndjson';
            try {
                Evidence::write($recorder, $path);
                $bag = Evidence::read($path);
                check('包里声明了绘制域', \in_array(Evidence::D_PAINT, $bag->domains, true),
                    \json_encode($bag->domains));
                check('包里读回了绘制记录', $bag->paint !== [], \json_encode(\array_keys($bag->paint)));

                $restored = new Query(Recorder::hydrate($bag));
                check('聚合逐值相同', $restored->repaints(0, 0) === $before,
                    \json_encode(['before' => $before, 'after' => $restored->repaints(0, 0)]));
                check('单帧逐值相同', $restored->paint($frame) === $beforeFrame,
                    \json_encode(['before' => $beforeFrame, 'after' => $restored->paint($frame)]));
            } finally {
                @\unlink($path);
            }
        } finally {
            $session->close();
        }
        $this->flushChecks();
    }
}

/** @internal 判据夹具：两棵各自成边界的子树 */
final class SplitBoundaryApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Column::make()->children([new SplitBoundaryProbe()]);
    }
}

/** @internal 判据夹具 */
final class SplitBoundaryProbe extends StatefulWidget
{
    public function createState(): State
    {
        return new SplitBoundaryState();
    }
}

/** @internal 判据夹具：只改左边那棵子树，右边保持不动 */
final class SplitBoundaryState extends State
{
    public static ?self $last = null;

    public int $left = 0;

    public function initState(): void
    {
        self::$last = $this;
    }

    public function bump(): void
    {
        $this->setState(function (): void {
            ++$this->left;
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        // 两个 `Text` 各自声明为重绘边界：左边内容变了只作废左边那条缓存，
        // 右边那棵的层缓存在同一帧里仍然有效 —— 命中与未命中因此可以同时出现。
        return Column::make()->children([
            Text::make('L' . $this->left)->repaintBoundary(),
            Text::make('R0')->repaintBoundary(),
        ]);
    }
}
