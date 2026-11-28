<?php
declare(strict_types=1);

namespace Drift\DevTools\Session;

use Drift\Debug\Session\AsyncRecord;
use Drift\Debug\Session\EvidenceBag;
use Drift\Debug\Session\FrameRecord;
use Drift\Debug\Session\GeometryRecord;
use Drift\Debug\Session\NetworkBodyRecord;
use Drift\Debug\Session\NetworkRecord;
use Drift\Debug\Session\PaintRecord;
use Drift\Debug\Session\RecordOptions;
use Drift\Debug\Session\Recorder;
use Drift\Debug\Session\Script;

/**
 * 已记录事实的**读面**：把 {@see Recorder} 写进环里的东西解释成查询能直接用的形状。
 *
 * ## 为什么读要跟写分家
 *
 * 分家不是为好看，是因为**两者的生命周期与归属完全不同**：
 *
 * - {@see Recorder} 是**写面**（A54 的 L1）：热路径上有 tap，tpc 全量编译，类可达
 *   即整类进产物。它必须留在 exe 里，且必须**薄** —— 每个多出来的方法都是一段
 *   发布态永远走不到的机器码。
 * - 本类是**解释面**（A54 的 L3）：只在有调试会话时被调用，随 `Query` / `Evidence`
 *   一起进 `project.yml` 的 `ignore`，不进产物。
 *
 * 分开之前实测：`Recorder.cc` 5786 行里有 1388 行（24%）是这些读方法 —— 发布态
 * 一次都执行不到，却跟着 exe 走。这不是"行数大"的美学问题，是白扛。
 *
 * ## 为什么不是"按域拆成六个写面类"
 *
 * 写面里六个域**共享同一份状态**：`frameId` 是所有时间轴的共同基准，`nodes` 同时
 * 被因果、几何、绘制、网络落地引用，`trim()` 一次管六个窗口。拆成对象只是把
 * 同一份状态变成跨对象传参 —— 耦合没消失，还多了 tpc 要编的类壳。
 *
 * 读面不同：每个方法只读一两张表，彼此不写。所以分界线落在**读写之间**，
 * 落点就是 `Recorder::ledger()` 这一个出口。
 *
 * ## 为什么一个 `ledger()` 出口而不是十几个 getter
 *
 * 读面要的正是"整环"，不是"某一张表"：`why()` 要帧环、`pathOf()` 要节点表、
 * `geometryRows()` 要几何表加基线。十几个 getter 会让写面多扛十几个方法壳，
 * 而且下一个加一个域的人要先想"该加哪个 getter"。一个出口，加域只改一处。
 *
 * 数组赋值是写时复制：一次 `ledger()` 是十几个标量拷贝，不复制元素。
 *
 * ## 为什么每次调用都重新取
 *
 * `debug serve` 是长期运行的：写面每帧都在变。构造时取一次快照，读者会拿到
 * 几帧前的树 —— 那正是 A64 禁止的"拿现在冒充当时"的镜像（拿当时冒充现在）。
 * 取一次是十几个赋值，比一次查询本身的成本低得多。
 *
 * @internal 记录层的读面，是 {@see Query} 与 {@see Evidence} 的实现依赖；
 *           应用代码不该直接 new 它 —— 要查记录请走 `Query` / `DebugSession`。
 */
final class History
{
    public function __construct(private readonly Recorder $recorder)
    {
    }

    /** 录制器的采集预算（读面判断"这一域到底开了没有"要用）。 */
    public function options(): RecordOptions
    {
        return $this->recorder->options();
    }

    /** 代际号：`reset()` 自增。派生缓存以它为键，帧号重用也不会命中旧数据。 */
    public function generation(): int
    {
        return $this->recorder->generation();
    }

    /** 原始帧号：`beginFrame()` 每帧自增，与 `frames` 窗口是否裁剪无关。 */
    public function frameId(): int
    {
        return $this->recorder->frameId();
    }

    /** 录制中的脚本；没开脚本时为 `null`。导出证据包与重放要用。 */
    public function script(): ?Script
    {
        return $this->recorder->script();
    }

    // ---------------------------------------------------------------- 结构

    /**
     * 节点登记表：id ⇒ 父 id（`-1` 根 / `-2` 未解析）+ Widget 短类名 + 是否 Flex
     * 容器 + 最后见到帧 `s` + 出生帧 `f`（旧证据包没有 `f`）。
     *
     * 摘要与将来的跨进程传输都要它：光有一串 id，换台机器就不知道是谁。
     *
     * 摘要只取 `c` 与 `p`（见 {@see Digest::ofMode()}）—— `s` / `f` 是时间轴上的
     * 位置，不是结构，进指纹会让"同一棵树多跑一帧"看起来像另一个应用。
     *
     * @return array<int, array{p: int, c: string, x: bool, s: int, f?: int}>
     */
    public function nodeIndex(): array
    {
        return $this->recorder->ledger()['nodes'];
    }

    /**
     * 已回收节点：id ⇒ `['p' => 父, 'c' => 类名, 'x' => Flex, 'f' => 出生帧,
     * 's' => 最后见到帧, 'g' => 离开树的那帧]`。
     *
     * `g = 0` 表示"没有离开树的记录"（几何采集关着时无从得知）。旧证据包里这个值
     * 是单个 int，{@see EvidenceBag::evictedRow()} 归一。
     *
     * @return array<int, array{p: int, c: string, x: bool, f: int, s: int, g: int}>
     */
    public function evictedNodes(): array
    {
        $rows = [];
        foreach ($this->recorder->ledger()['evicted'] as $id => $meta) {
            $rows[(int) $id] = EvidenceBag::evictedRow($meta);
        }

        return $rows;
    }

    /**
     * 节点 ⇒ 离开树的那一帧。
     *
     * 只在几何采样的先序遍历里写（`Recorder::sampleGeometry()`），所以它答的是
     * "那一帧的树上已经看不到它了"，不是"它被 GC 了"。几何采集关着时这份表是空的
     * —— 回放必须把这种情况报成"未知"而不是"一直活着"（见 {@see Query::tree()}）。
     *
     * @return array<int, int>
     */
    public function goneNodes(): array
    {
        return $this->recorder->ledger()['gone'];
    }

    /** 查询侧的单一出口：活动 / 已回收 / 从未发号，三者不可混。 */
    public function nodeStatus(int $node): string
    {
        $l = $this->recorder->ledger();
        if (isset($l['nodes'][$node])) {
            return 'active';
        }

        return isset($l['evicted'][$node]) ? 'evicted' : 'unknown';
    }

    /**
     * 这个节点是不是 Flex 容器（`Column` / `Row` / 任何 `extends Flex`）。
     *
     * 归因要用：Flex 父在 `performLayout()` 里按 grow/shrink 给子分配尺寸，
     * 子拿到的 `applied` 因此不等于"父 inner 原样传下来"，与基类默认链对不上。
     * 这是**合法的对不上**，要归成 `parent-flex` 而不是兜底的 `child-custom`。
     */
    public function isFlex(int $nodeId): bool
    {
        return ($this->recorder->ledger()['nodes'][$nodeId]['x'] ?? false) === true;
    }

    /** 这个节点有没有自己的盒；复合控件（PageShell 等）没有，只能看它的盒子类后代。 */
    public function isBoxless(int $node): bool
    {
        return isset($this->recorder->ledger()['boxless'][$node]);
    }

    /** 给无盒节点找一个有盒的直接孩子，供查询给"去查这个"的建议而不是空手。 */
    public function firstBoxedChild(int $node): ?int
    {
        $l = $this->recorder->ledger();
        foreach ($l['nodes'] as $id => $meta) {
            if ($meta['p'] === $node && !isset($l['boxless'][$id]) && isset($l['digest'][$id])) {
                return $id;
            }
        }

        return null;
    }

    /** @return list<int> 没有自己盒的节点（复合控件），导出证据包要逐条带上。 */
    public function boxlessNodes(): array
    {
        $ids = \array_map('\intval', \array_keys($this->recorder->ledger()['boxless']));
        \sort($ids);

        return $ids;
    }

    /** 节点的 Widget 短类名（树按类型展开、按类型聚合都用它）。 */
    public function typeOf(int $nodeId): string
    {
        return $this->recorder->ledger()['nodes'][$nodeId]['c'] ?? '';
    }

    /** 从根到该节点的类名路径，用于面板显示与"这是哪个节点"的自证。 */
    public function pathOf(int $nodeId): string
    {
        $l = $this->recorder->ledger();
        $parts = [];
        $cursor = $nodeId;
        $guard = 0;
        // 已回收节点也走这条链：回放早期帧时，画面上大半是"后来消失了的东西"，
        // 它们的路径正是"它当时在哪"的答案。只在注册表里找会让它们集体变成空串，
        // 而空串看起来跟"这是个根"一模一样。
        while ($cursor > 0 && $guard++ < 32) {
            $meta = $l['nodes'][$cursor] ?? $l['evicted'][$cursor] ?? null;
            if ($meta === null) {
                break;
            }
            // 旧证据包 / 测试直塞的回收值是单个 int。`evictedNodes()` 会归一，但这条
            // 链读的是原始表（不归一，否则每次 `pathOf` 都扫一遍整张回收表）。
            if (!\is_array($meta)) {
                break;
            }
            $type = (string) ($meta['c'] ?? '');
            if ($type === '') {
                break; // 旧证据包里的回收节点没有类型：给半截路径不如给空，别编
            }
            \array_unshift($parts, $type);
            $cursor = (int) ($meta['p'] ?? -1);
        }

        return \implode(' > ', $parts);
    }

    // ------------------------------------------------------------- 帧与因果

    /**
     * 帧环的窗口容量与实际条数（性能预算与面板概览用）。
     *
     * 这四个（`frames` / `frame` / `lastFrameId` / `stats`）留在写面上，因为它们是
     * **帧环本体的薄访问**：每个只有一行实现，且名字在别处被同名方法占用
     * （`Application::frame()` 等），搬它们会制造一批看起来一样的改错点。
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return $this->recorder->stats();
    }

    /** @return array<int, FrameRecord> */
    public function frames(): array
    {
        return $this->recorder->frames();
    }

    public function frame(?int $id = null): ?FrameRecord
    {
        return $this->recorder->frame($id);
    }

    public function lastFrameId(): int
    {
        return $this->recorder->lastFrameId();
    }

    /**
     * 为什么这个节点在这一帧变了。
     *
     * `trail` 是从目标沿父边一路走到根的链路（目标在前、根在末尾）。
     * 只印直接边等于回答"因为父节点重建了"却不说父是谁 —— 开发者要的正是
     * "哪条链把我带下来的"。链路来自 `FrameRecord::parentOf()`，与 `cascade`
     * 共用同一份拓扑，不另算。
     *
     * @return array<string, mixed>
     */
    public function why(int $nodeId, ?int $frameId = null): array
    {
        // 不指定帧时取**最近一次重建它的帧**，不是最后一帧：多动作场景里最后一帧
        // 往往是静止帧，按最后一帧回答会得到"它没有重建"，把问题原样退回来。
        $frame = $frameId !== null ? $this->recorder->frame($frameId) : $this->latestRebuiltFrame($nodeId);
        $causes = [];
        $trail = [];
        if ($frame !== null) {
            foreach ($frame->causes as $edge) {
                if ($edge->toId === $nodeId) {
                    $causes[] = $edge->toArray();
                }
            }

            $parent = $frame->parentOf();
            $causeOf = $frame->causeOf();
            $cursor = $nodeId;
            $guard = 0;
            while ($guard++ < 64) {
                $edge = $causeOf[$cursor] ?? null;
                $trail[] = [
                    'id' => $cursor,
                    'path' => $this->pathOf($cursor),
                    'kind' => $edge?->kind ?? 'none',
                    'site' => $edge?->site ?? '',
                    'detail' => $edge?->detail ?? '',
                ];
                $next = $parent[$cursor] ?? null;
                // 因果链到根因为止：`state` 是"为什么"的答案本身，再往上问的是
                // "谁带着它重建" —— 那是层级问题，由 `cascade` 的树回答。
                if ($edge !== null && $edge->kind === 'state') {
                    break;
                }
                if ($next === null || $next === $cursor) {
                    break;
                }
                $cursor = $next;
            }
        }

        return [
            'node' => $nodeId,
            'path' => $this->pathOf($nodeId),
            'frame' => $frame?->id,
            'rebuilt' => $frame?->rebuilt($nodeId) ?? false,
            'causes' => $causes,
            'trail' => $trail,
        ];
    }

    /** 最近一次重建过该节点的帧；一次都没有则退回最后一帧（好让 `why` 如实说"没重建"）。 */
    private function latestRebuiltFrame(int $nodeId): ?FrameRecord
    {
        foreach (\array_reverse($this->recorder->frames(), true) as $frame) {
            if ($frame->rebuilt($nodeId)) {
                return $frame;
            }
        }

        return $this->recorder->frame($this->recorder->lastFrameId());
    }

    /**
     * 哪些请求的落地改过这个节点（按 requestId 升序）。
     *
     * 只读 `landed[]`，不建第二份 `node → request` 索引：网络窗口 500 条，扫一遍
     * 比维护一份会与骨架漂移的镜像便宜，也更诚实——索引一旦漏更新，就会给出
     * "没有请求落地"这种看起来正常、实际错误的答案。
     *
     * @return list<int>
     */
    public function requestsLandingOn(int $node): array
    {
        $ids = [];
        foreach ($this->recorder->ledger()['network'] as $id => $record) {
            if (\in_array($node, $record->landed, true)) {
                $ids[] = $id;
            }
        }
        \sort($ids, \SORT_NUMERIC);

        return $ids;
    }

    // ---------------------------------------------------------------- 几何

    /** @return list<int> 有几何记录的帧号（升序） */
    public function geometryFrames(): array
    {
        $ids = \array_map('\intval', \array_keys($this->recorder->ledger()['geometry']));
        \sort($ids);

        return $ids;
    }

    /**
     * 取某帧某节点的几何事实。
     *
     * 该帧没记不代表"那时没这个节点"——只代表**这一帧几何没变**，所以往前找最近
     * 的一次记录。找不到时返回 null，调用方必须区分"节点不存在"和"窗口外"，不能
     * 把 null 当成默认几何（那会凭空造出一个盒子）。
     *
     * ★ **未来帧一律返回 null**，不 clamp 到窗口末端。
     *   早先是 `for ($f = \min($frame, $max); ...)`：查 `frame=99`（窗口只到 2）
     *   会拿到 `frame=1` 的记录并报 `found=true` —— 一个根本没采样过的帧看起来
     *   有几何。这类答案比"没查到"危险得多，因为它自洽、可读、且完全错。
     *
     *   上界是**最新几何记录**而不是最新帧，这是刻意的：它同时是"这一帧真的采过
     *   几何吗"的答案。静止 140 帧后主循环帧号会跑出窗口，而快照锚点必须落在
     *   窗口内（否则底图套上另一帧的线框）—— `LiveTicker` 就靠这个空表判锚点。
     *   要"该帧生效的几何"而不是"该帧采过几何"，调用方自己把帧号夹到窗口内
     *   （见 {@see Query::tree()}）。
     *
     *   修在这里而不是修在每个查询里：四个查询（`boxModel` / `boxes` /
     *   `constraints` / `align`）各判一遍窗口，必然只修到其中几处 —— 之前就是
     *   只有 `align` 判了，另外三个照样返回旧帧。
     */
    public function geometryAt(int $frame, int $node): ?GeometryRecord
    {
        $l = $this->recorder->ledger();
        $geometry = $l['geometry'];
        if ($geometry === []) {
            return null;
        }

        $keys = \array_keys($geometry);
        $min = \min($keys);
        $max = \max($keys);
        if ($frame > $max) {
            return null; // 窗口里没有这一帧的采样：往前找等于把"没采"说成"采了"
        }
        if (($l['gone'][$node] ?? \PHP_INT_MAX) <= $frame) {
            return null; // 这一帧时节点已经离开树；旧记录只属于更早的帧
        }
        for ($f = $frame; $f >= $min; --$f) {
            if (isset($geometry[$f][$node])) {
                return $geometry[$f][$node];
            }
        }

        // 窗口滚动裁掉了祖先那一条唯一记录（签名此后没变、不再重采）。基线保留最后
        // 一次采样，用它补出父链；只在本帧之前记过才算，查更早的历史帧不能拿现在补。
        $base = $l['baseline'][$node] ?? null;
        if ($base !== null && $base->frame <= $frame) {
            return $base;
        }

        return null;
    }

    /**
     * 目标帧上生效的全部盒几何：每个节点取不晚于该帧的最新一条。
     *
     * 与 {@see geometryAt()} 是同一套"往前找最近记录"的语义，只是这里一次给全，
     * 供投影层跨盒扫描（例如比较父给子的约束与子实际收到的约束）。逐节点各问一次
     * 会得到同样的答案，但那要多 N 次往返，而且让消费者自己拼一张表。
     *
     * 离开树的节点不再算"生效"：否则切窗后上一页的盒会一直挂在新画面上。
     *
     * @return array<int, GeometryRecord> node id => 该帧生效的几何记录
     */
    public function geometryRows(int $frame): array
    {
        $l = $this->recorder->ledger();
        $geometry = $l['geometry'];
        // 未来帧不给"每节点最近一次"：那会把窗口末端的一整套几何当成"该帧生效"，
        // 与 `geometryAt()` 的 clamp 是同一个错误，只是从单节点变成整张表。
        // 上界取最新几何记录的理由同 {@see geometryAt()}：它同时是"采过没有"。
        if ($geometry === [] || $frame > \max(\array_keys($geometry))) {
            return [];
        }

        $out = [];
        foreach ($geometry as $f => $rows) {
            if ($f > $frame) {
                break;
            }
            foreach ($rows as $node => $record) {
                if (($l['gone'][(int) $node] ?? \PHP_INT_MAX) <= $frame) {
                    continue;
                }
                $out[$node] = $record;
            }
        }

        // 稳定祖先的唯一记录被窗口裁掉后，用基线补回，让绝对坐标的父链不断。
        // 窗口里已有更新的记录时不覆盖；离树节点、晚于目标帧的记录不补。
        foreach ($l['baseline'] as $node => $record) {
            if (isset($out[$node]) || $record->frame > $frame) {
                continue;
            }
            if (($l['gone'][(int) $node] ?? \PHP_INT_MAX) <= $frame) {
                continue;
            }
            $out[$node] = $record;
        }

        return $out;
    }

    /**
     * 该帧**新采**的几何（不含前序帧累积）。
     *
     * 与 {@see geometryRows()} 的区别是致命的：那个是"到该帧为止每个节点最近
     * 一次"（查询要的语义），这个是"这一帧真的记了哪些"。导出证据包必须用后者——
     * 用前者会把每一帧的前序记录重复写一遍，文件大一倍，而条数账会立刻对不上。
     *
     * @return array<int, GeometryRecord>
     */
    public function geometrySamples(int $frame): array
    {
        return $this->recorder->ledger()['geometry'][$frame] ?? [];
    }

    /** @return array{mode: string, frames: int, capacity: int, from: int, to: int, nodes: int} */
    public function geometryCoverage(): array
    {
        $geometry = $this->recorder->ledger()['geometry'];
        $keys = \array_keys($geometry);
        $nodes = 0;
        foreach ($geometry as $rows) {
            $nodes += \count($rows);
        }

        return [
            'mode' => $this->recorder->options()->geometry()->value,
            'frames' => \count($keys),
            'capacity' => Recorder::GEOMETRY_FRAMES,
            'from' => $keys === [] ? 0 : \min($keys),
            'to' => $keys === [] ? 0 : \max($keys),
            'nodes' => $nodes,
        ];
    }

    // ---------------------------------------------------------------- 绘制

    /** @return list<int> 有绘制记录的帧号（升序） */
    public function paintFrames(): array
    {
        $ids = \array_map('\intval', \array_keys($this->recorder->ledger()['paint']));
        \sort($ids);

        return $ids;
    }

    public function paintFrame(int $frame): ?PaintRecord
    {
        return $this->recorder->ledger()['paint'][$frame] ?? null;
    }

    /**
     * 绘制域的窗口与采集状态。
     *
     * `captured=false` 与"这一帧没画"必须分开：前者是预算没开，后者是帧整段跳过。
     * 少了这一位，读者会把"没采"读成"没画"。
     *
     * @return array<string, mixed>
     */
    public function paintCoverage(): array
    {
        $ids = $this->paintFrames();

        return [
            'captured' => $this->recorder->options()->paint(),
            'frames' => \count($ids),
            'capacity' => Recorder::PAINT_FRAMES,
            'from' => $ids === [] ? 0 : $ids[0],
            'to' => $ids === [] ? 0 : $ids[\count($ids) - 1],
            'paintedCap' => Recorder::MAX_PAINTED_NODES,
            'missesCap' => Recorder::MAX_PAINT_MISSES,
        ];
    }

    // ---------------------------------------------------------------- 网络

    /** @return list<int> 有报文的 requestId（升序） */
    public function bodyRequests(): array
    {
        $ids = \array_map('\intval', \array_keys($this->recorder->ledger()['bodies']));
        \sort($ids);

        return $ids;
    }

    public function bodyAt(int $requestId): ?NetworkBodyRecord
    {
        return $this->recorder->ledger()['bodies'][$requestId] ?? null;
    }

    /** @return array{captured: bool, requests: int, capacity: int, from: int, to: int, bytes: int} */
    public function bodyCoverage(): array
    {
        $bodies = $this->recorder->ledger()['bodies'];
        $keys = \array_keys($bodies);
        $bytes = 0;
        foreach ($bodies as $body) {
            $bytes += $body->request['bytes'] + $body->response['bytes'];
        }

        return [
            'captured' => $this->recorder->options()->bodies(),
            'requests' => \count($keys),
            'capacity' => Recorder::BODY_CAPACITY,
            'from' => $keys === [] ? 0 : \min($keys),
            'to' => $keys === [] ? 0 : \max($keys),
            'bytes' => $bytes,
        ];
    }

    /** @return list<NetworkRecord> 按 requestId 升序 */
    public function networkRows(): array
    {
        $rows = \array_values($this->recorder->ledger()['network']);
        \usort($rows, static fn(NetworkRecord $a, NetworkRecord $b): int => $a->requestId <=> $b->requestId);

        return $rows;
    }

    public function networkAt(int $requestId): ?NetworkRecord
    {
        return $this->recorder->ledger()['network'][$requestId] ?? null;
    }

    /** @return array{captured: bool, requests: int, capacity: int, from: int, to: int, inFlight: int} */
    public function networkCoverage(): array
    {
        $network = $this->recorder->ledger()['network'];
        $keys = \array_keys($network);
        $inFlight = 0;
        foreach ($network as $record) {
            if ($record->settledAtFrame === 0) {
                ++$inFlight;
            }
        }

        return [
            'captured' => $this->recorder->options()->network(),
            'requests' => \count($keys),
            'capacity' => Recorder::NETWORK_CAPACITY,
            'from' => $keys === [] ? 0 : \min($keys),
            'to' => $keys === [] ? 0 : \max($keys),
            'inFlight' => $inFlight,
        ];
    }

    // -------------------------------------------------------------- 异步任务

    /** @return list<AsyncRecord> 按 id 升序 */
    public function asyncRows(): array
    {
        $rows = \array_values($this->recorder->ledger()['async']);
        \usort($rows, static fn(AsyncRecord $a, AsyncRecord $b): int => $a->id <=> $b->id);

        return $rows;
    }

    public function asyncAt(int $id): ?AsyncRecord
    {
        return $this->recorder->ledger()['async'][$id] ?? null;
    }

    /** @return array{captured: bool, jobs: int, capacity: int, from: int, to: int, inFlight: int} */
    public function asyncCoverage(): array
    {
        $async = $this->recorder->ledger()['async'];
        $keys = \array_keys($async);
        $inFlight = 0;
        foreach ($async as $record) {
            if ($record->settledAtFrame === 0) {
                ++$inFlight;
            }
        }

        return [
            'captured' => $this->recorder->options()->async(),
            'jobs' => \count($keys),
            'capacity' => Recorder::ASYNC_CAPACITY,
            'from' => $keys === [] ? 0 : \min($keys),
            'to' => $keys === [] ? 0 : \max($keys),
            'inFlight' => $inFlight,
        ];
    }

    // ---------------------------------------------------------------- 高亮

    /** 当前高亮的节点号；`0` = 没有。 */
    public function highlightedNode(): int
    {
        return $this->recorder->ledger()['highlighted'];
    }

    /** 当前这个高亮是在第几帧被请求的；`0` = 本次录制还没请求过。 */
    public function highlightFrame(): int
    {
        return $this->recorder->ledger()['highlightFrame'];
    }

    /**
     * 最近一次真的上屏的某一层叠加；一次都没有为 `null`。
     *
     * ★ 与 {@see highlightedNode()} 分开记，是为了区分两件事："请求了高亮"与"高亮
     *   真的到了屏幕上"。中间隔着绘制（元素已卸载、盒没尺寸、帧被跳过都会让它只停在
     *   第一件上）—— 而那种失败是静默的。
     * ★ 按层取（`highlight` / `inspect`），默认 `highlight`：两层能独立失败，把
     *   "高亮上屏了"当成"检查器也上屏了"就是又一次把两件事写成一个布尔。
     *
     * @return array{frame: int, node: int}|null
     */
    public function overlayDrawn(string $layer = 'highlight'): ?array
    {
        return $this->recorder->ledger()['overlay'][$layer] ?? null;
    }

    /**
     * 证据脱敏总开关（属性域与报文域共用同一份状态与同一张规则表）。
     *
     * ★ 属性会读任意 State 字段，里面可能有 password / token。报文已经默认脱敏，
     *   属性若原样进证据包，同一个威胁模型就出现两种口径。
     */
    public function redactionEnabled(): bool
    {
        return $this->recorder->options()->redact();
    }
}
