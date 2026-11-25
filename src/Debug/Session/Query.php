<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Size;
use Drift\Widget\Element;
use Drift\Widget\StatefulElement;

/**
 * 查询面：把录制记录读成"能回答问题的形状"。
 *
 * 纯函数：只读 {@see Recorder} 的不可变帧记录，不改状态、不碰元素树。
 * 所以可以重试、可以缓存、可以在任意线程问卷（记录本身在写时拷贝）。
 *
 * ★ 活树例外：{@see self::pick()} 为命中的活树节点发号，使它能被 `why(node)`
 *   继续追问。`props()` 曾经也是例外（把字段读数存进快照窗口、写进证据包），
 *   按 A55 已降为纯内省：只读当场活树、不写任何记录 —— 属性复算不了，不是证据。
 *
 * ## 两个聚合的口径不同，不要合并
 *
 * - `hotspots()` 回答"谁在写"：只聚合 `kind=state` 的根因边，按调用点 + State 类排名。
 * - `nodes()` 回答"谁在重建"：按节点聚合，含父传播。
 *
 * 一个 `setState` 会让整棵子树逐层产生 `parent` 边。把传播算进根因，排名会全是
 * 下游节点，真正的原因反而沉底。反过来只看根因，又看不出"哪个子树被牵连最重"。
 *
 * @api
 */
final class Query
{
    /** @var array<int, array<string, mixed>> 帧号 => 该帧的森林骨架（复用于 cascade / 聚合） */
    private array $shapeCache = [];

    /** `shapeCache` 属于哪一代；与 `Recorder::generation()` 不一致就整体丢弃。 */
    private int $cacheGeneration = -1;

    /** L2 内存台账：跨多次 memory() 查询累积同一本 WeakMap，不进证据包（A55）。 */
    private ?MemoryProbe $memoryProbe = null;

    public function __construct(private readonly Recorder $recorder)
    {
    }

    /**
     * 进程内存 + 活树对象的跨帧内省（L2，不是证据）。
     *
     * ★ 必须注入活树：内存看的是**现在**有哪些对象、活了多久；离线包没有活树，
     *   返回空会读成"没有对象"，所以由派发层在注入回调缺失时明确拒绝。
     * ★ 不写 Recorder、不进包、不进指纹：这是 A55 的 L2 内省，只有结构上可复算的
     *   标量才能进证据，而"对象活了几帧"离开当下就无法重算。
     *
     * @param list<Element> $roots
     * @return array<string, mixed>
     */
    public function memory(array $roots, int $limit = 40): array
    {
        if (!MemoryProbe::available()) {
            return ['found' => false, 'reason' => 'memory-unavailable'];
        }
        $this->memoryProbe ??= new MemoryProbe();
        $answer = $this->memoryProbe->sample($roots, $this->recorder->lastFrameId(), $limit);
        $answer['found'] = true;
        $answer['limit'] = $limit;

        return $answer;
    }

    /**
     * 把 `site`（`file:line`）解释成方法定义位置（L2，不是证据）。
     *
     * 与 `why()` / `hotspots()` 给出的 `site` 同源：那些输出里的是一个地址字符串，
     * 这里把它翻译成"在哪个类的哪个方法里、定义在第几行"。纯读，不碰活树与记录。
     *
     * @return array<string, mixed>
     */
    public function source(string $site): array
    {
        return SourceLocator::locate($site);
    }

    /** @return array<string, mixed> 帧数 / 节点数 / 容量 / 最近帧耗时与重建数 */
    public function stats(): array
    {
        return $this->recorder->stats();
    }

    /**
     * 按节点读 Widget / State 配置（dev 反射，纯内省，不是证据）。
     *
     * ★ 帧号不是参数：属性读的是**现在**的活树，给一个历史帧号却返回当前值
     *   就是"伪称对齐"。返回值里的 `liveFrame` 明说这是哪一帧读到的。
     *
     * ★ 不写任何记录（A55）：这一帧过去后对象已变，把字段写进证据包等于声称
     *   一件无法复算的事。字段用 {@see ObjectDump} 的反射原语现读，与 `eval`
     *   的 `$node` 同源 —— 同一个对象在两个入口看到同一份字段。
     *
     * @param list<Element> $roots 全部树根（主树 + 浮层），由派发层给 —— Debug 层不依赖 App
     * @return array<string, mixed>
     */
    public function props(int $node, array $roots, int $maxDepth = 3, int $maxFields = 120): array
    {
        if (!ObjectDump::available()) {
            return ['found' => false, 'node' => $node, 'reason' => 'reflection-unavailable'];
        }

        $element = $this->findElement($roots, $node);
        if ($element === null) {
            // "号没发过"与"号发过但节点已卸载"必须分开：前者是地址写错，后者是活树里已经没有它。
            $status = $this->recorder->nodeStatus($node);
            $known = $this->recorder->nodeIndex()[$node] ?? null;

            return [
                'found' => false,
                'node' => $node,
                'reason' => match ($status) {
                    'evicted' => 'node-evicted',
                    'active' => 'node-unmounted',
                    default => 'unknown-node',
                },
                'type' => $known['c'] ?? null,
            ];
        }

        $t0 = \hrtime(true);
        $widget = $element->widget();
        $state = $element instanceof StatefulElement ? $element->state() : null;
        $redact = $this->recorder->redactionEnabled();

        $w = ObjectDump::fields($widget, $maxDepth, $maxFields, $redact);
        $s = $state === null
            ? ['fields' => [], 'omitted' => 0, 'cut' => 0, 'cycles' => 0, 'redacted' => 0, 'redact' => $redact]
            : ObjectDump::fields($state, $maxDepth, $maxFields, $redact);

        return [
            'found' => true,
            'liveFrame' => $this->recorder->lastFrameId(),
            'widget' => self::shortClass($widget::class),
            'element' => self::shortClass($element::class),
            'state' => $state === null ? null : self::shortClass($state::class),
            'fields' => \array_merge($w['fields'], $s['fields']),
            'omitted' => $w['omitted'] + $s['omitted'],
            'cut' => $w['cut'] + $s['cut'],
            'cycles' => $w['cycles'] + $s['cycles'],
            'redacted' => $w['redacted'] + $s['redacted'],
            'redact' => $redact,
            'costMs' => (\hrtime(true) - $t0) / 1e6,
        ];
    }

    /**
     * 在多棵树里按节点号找活元素。
     *
     * 查找本身住 {@see Element::findInRoots()}：叠加层（高亮）也要同一个查找，
     * 而它拿不到 `Query`。两份递归迟早漂，且漂出来的表现是"某个深度以外永远
     * 查不到"——正是这次修掉的那类静默。
     *
     * @param list<Element> $roots
     */
    private function findElement(array $roots, int $node): ?Element
    {
        return Element::findInRoots($roots, $node, fn(Element $e): bool => $this->recorder->owns($e));
    }

    /**
     * 点选反查：视口坐标 ⇒ 命中链（根 → 最深的命中盒）。
     *
     * ★ 不写生产盒字段：命中走 `RenderBox::hitTest()` 的唯一实现，反查走
     *   `Element::boxOwners()` 的按需映射（焦点链已用的那一份）。这两样都只在
     *   查询发生时跑，录制没开也能用，AOT 可达面不变（A44）。
     *
     * ★ `chain` 给的是**完整盒链**，不是只给最底层盒：包装层是谁、自绘内容落在
     *   哪个盒里，正是布局调试要回答的问题。没有 Element 认领的盒照实列出
     *   （`node = null`），不悄悄跳过，也不把父节点冒充成它的拥有者。
     *
     * ★ 未命中与没树根分开报：点空了不是"点到了根节点"。
     *
     * @param list<array{element: Element, x: float, y: float}> $roots 主树在前、浮层在后
     * @return array<string, mixed>
     */
    public function pick(float $x, float $y, array $roots): array
    {
        $base = [
            'x' => $x,
            'y' => $y,
            'liveFrame' => $this->recorder->lastFrameId(),
            'roots' => 0,
        ];

        $elements = [];
        foreach ($roots as $entry) {
            $element = $entry['element'] ?? null;
            if ($element instanceof Element) {
                $elements[] = $element;
            }
        }
        $base['roots'] = \count($elements);
        if ($elements === []) {
            return $base + [
                'found' => false,
                'reason' => 'no-roots',
                'tree' => null,
                'scope' => null,
                'hit' => null,
                'chain' => [],
                'ownerless' => 0,
            ];
        }

        // 映射建一次，链上每个盒共用；焦点反查走同一个方法，口径不会分叉。
        $owners = Element::boxOwners($elements);

        // 浮层在上、主树在下：与 InputRouter 的命中顺序一致。
        for ($i = \count($roots) - 1; $i >= 0; $i--) {
            $root = $roots[$i];
            $element = $root['element'] ?? null;
            if (!$element instanceof Element) {
                continue;
            }

            // 视口坐标减根原点：浮层不是从 (0,0) 起画，漏了就整体偏一个锚点。
            $hit = $element->effectiveBox()?->hitTest(
                $x - (float) ($root['x'] ?? 0.0),
                $y - (float) ($root['y'] ?? 0.0),
            );
            if ($hit === null) {
                continue;
            }

            $chain = [];
            $ownerless = 0;
            // Debug 层不 import Layout：沿渲染父链走，遇到没有 size() 的对象（sliver 等）
            // 就停。事实仍来自生产盒本身，查询只把它们压成标量。
            for ($box = $hit; $box !== null && \method_exists($box, 'size'); $box = $box->parent()) {
                $owner = $owners[\spl_object_id($box)] ?? null;
                if ($owner === null) {
                    $ownerless++;
                }
                $chain[] = self::boxFact($box, $owner, $this->recorder);
            }
            $chain = \array_reverse($chain);
            $last = $chain[\count($chain) - 1];

            return $base + [
                'found' => true,
                'reason' => null,
                'tree' => $i,
                'scope' => $i === 0 ? 'main' : 'overlay',
                'hit' => $last,
                'chain' => $chain,
                'ownerless' => $ownerless,
            ];
        }

        return $base + [
            'found' => false,
            'reason' => 'no-hit',
            'tree' => null,
            'scope' => null,
            'hit' => null,
            'chain' => [],
            'ownerless' => 0,
        ];
    }

    /**
     * 链上一环的标量事实；`node` 只由会话发号，查询不自造地址。
     */
    private static function boxFact(object $box, ?Element $owner, Recorder $recorder): array
    {
        $size = $box->size();
        $fact = [
            'box' => self::shortClass($box::class),
            'size' => $size === null ? null : ['width' => $size->width, 'height' => $size->height],
            'node' => null,
            'widget' => null,
            'element' => null,
            'state' => null,
            'path' => null,
        ];
        if ($owner === null) {
            return $fact;
        }

        $node = $recorder->idOf($owner);
        $state = $owner instanceof StatefulElement ? $owner->state() : null;
        $fact['node'] = $node;
        $fact['widget'] = $recorder->typeOf($node);
        $fact['element'] = self::shortClass($owner::class);
        $fact['state'] = $state === null ? null : self::shortClass($state::class);
        $fact['path'] = $recorder->pathOf($node);

        return $fact;
    }

    private static function shortClass(string $class): string
    {
        $pos = \strrpos($class, '\\');

        return $pos === false ? $class : \substr($class, $pos + 1);
    }

    /**
     * 高亮状态：**现在高亮谁、什么时候请求的、最后一次真的上屏在哪一帧**。
     *
     * ## 为什么 `onScreen` 要单独算
     *
     * "请求了高亮"与"高亮到了屏幕上"是两件事，中间隔着一次绘制。元素已卸载、
     * 盒没有尺寸、帧被整段跳过，都会让请求停在第一件上 —— 而屏幕上什么都没有，
     * 静默。三个字段一起给，读者能自己判断失败在哪一环：
     *
     * | `node` | `drawnFrame` | 含义 |
     * |---|---|---|
     * | `0` | 任意 | 没有高亮（`drawnFrame` 是历史上最后一次画的，只作参考） |
     * | `>0` | `null` | 请求了，但一帧都还没画过 |
     * | `>0` | `< askedFrame` | 请求了，但还没画到它（`onScreen=false`） |
     * | `>0` | `>= askedFrame` 且 `drawnNode` 相同 | 就在屏幕上（`onScreen=true`） |
     *
     * ★ 号对得上还不够：清掉再一次高亮同一个号时，`drawnFrame` 会停在**上一次**
     *   那个号上屏的帧。比 `askedFrame` 就是为了把这种"旧画"与"新请求"分开。
     *
     * ★ `frame` 是当前帧号（`beginFrame()` 数出来的），不是末个落盘帧：查询发生在
     *   两帧之间时两者相同，而 `frames` 窗口一帧都没落盘时后者是 0。
     *
     * @return array<string, mixed>
     */
    public function highlight(): array
    {
        $node = $this->recorder->highlightedNode();
        $askedFrame = $this->recorder->highlightFrame();
        $drawn = $this->recorder->overlayDrawn();

        return [
            'node' => $node,
            'type' => $node > 0 ? $this->recorder->typeOf($node) : null,
            'path' => $node > 0 ? $this->recorder->pathOf($node) : null,
            'askedFrame' => $askedFrame,
            'drawnNode' => $drawn === null ? null : $drawn['node'],
            'drawnFrame' => $drawn === null ? null : $drawn['frame'],
            'onScreen' => $node > 0
                && $drawn !== null
                && $drawn['node'] === $node
                && $drawn['frame'] >= $askedFrame,
            'frame' => $this->recorder->frameId(),
        ];
    }

    /**
     * 最近 N 帧的摘要，按帧号升序。
     *
     * `since` 是**增量游标**：只要帧号大于它的帧。游标由调用方持有（记最后一条的
     * `id`），服务端不记"你看到哪了" —— 无状态的读可以重试、可以并行、可以
     * 两个消费者各拿一份互不干扰。这也正是"订阅"给不了的东西：订阅一旦漏一条，
     * 之后每一条都是错的，而游标只是"再问一次"。
     *
     * @return list<array<string, mixed>>
     */
    /**
     * @return array{frames: list<array<string, mixed>>, window: array<string, mixed>, dropped: int, since: int|null}
     */
    public function frames(int $limit = 30, bool $rebuiltOnly = false, ?int $since = null): array
    {
        $all = $this->recorder->frames();
        $window = [
            'from' => $all === [] ? 0 : (int) \array_key_first($all),
            'to' => $all === [] ? 0 : (int) \array_key_last($all),
            'capacity' => (int) ($this->recorder->stats()['capacity'] ?? 0),
            'frames' => \count($all),
        ];

        $out = [];
        $dropped = 0;
        if ($since !== null) {
            // 游标落后到窗口外：中间那段真的没了，不是"这段没有重建"。
            $dropped = $window['from'] > 0 ? \max(0, $window['from'] - $since - 1) : 0;
            foreach ($all as $id => $frame) {
                if ($id <= $since) {
                    continue;
                }
                if ($rebuiltOnly && $frame->rebuilt === []) {
                    continue;
                }
                $out[] = $this->frameSummary($frame);
            }

            // 游标语义下 `limit` 是"最多给这么多"，从**最老的未读**开始：
            // 丢弃最新的会让读者以为"就这些"，而最老的丢不掉才是真相。
            $out = $limit > 0 ? \array_slice($out, 0, $limit) : $out;
        } else {
            foreach ($this->window($limit) as $frame) {
                if ($rebuiltOnly && $frame->rebuilt === []) {
                    continue;
                }
                $out[] = $this->frameSummary($frame);
            }
        }

        return ['frames' => $out, 'window' => $window, 'dropped' => $dropped, 'since' => $since];
    }

    /** @return array<string, mixed> */
    private function frameSummary(FrameRecord $frame): array
    {
        return [
            'id' => $frame->id,
            't' => $frame->t,
            'cost' => $frame->costMs,
            'rebuilt' => \count($frame->rebuilt),
            'causes' => \count($frame->causes),
            'stageMs' => FrameRecord::toMs($frame->stageNs),
            'unattributedMs' => $frame->unattributedMs(),
            'settleSkipped' => $frame->settleSkipped,
            'paintSkipped' => $frame->paintSkipped,
        ];
    }

    /**
     * 单帧展开：谁重建了、根因怎么分布。
     *
     * 参数名与协议键同名（`frame`）：`query('explain', ['frame' => 2])` 里的键、
     * 这里的变量、`explain(2)` 的地址，三处必须是同一个词 —— 否则规格表要靠一张
     * 别名映射才能核对，而别名表本身就会漂。
     *
     * @return array<string, mixed>
     */
    public function explain(int $frame): array
    {
        $record = $this->recorder->frame($frame);
        if ($record === null) {
            throw new \InvalidArgumentException("没有这一帧：{$frame}（缓冲窗口之外或尚未发生）");
        }

        $rebuilt = [];
        foreach ($record->rebuilt as $id) {
            $rebuilt[] = ['id' => $id, 'path' => $this->recorder->pathOf($id)];
        }

        $byKind = ['state' => 0, 'parent' => 0, 'unknown' => 0];
        $byRoot = [];
        foreach ($record->causes as $edge) {
            if (isset($byKind[$edge->kind])) {
                ++$byKind[$edge->kind];
            }
            if ($edge->kind !== 'state') {
                continue;
            }
            $key = $edge->site . "\0" . $edge->detail;
            $byRoot[$key] ??= ['site' => $edge->site, 'detail' => $edge->detail, 'count' => 0];
            ++$byRoot[$key]['count'];
        }

        $roots = \array_values($byRoot);
        \usort($roots, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'id' => $record->id,
            't' => $record->t,
            'cost' => $record->costMs,
            'stages' => $record->stages,
            'stageMs' => FrameRecord::toMs($record->stageNs),
            'unattributedMs' => $record->unattributedMs(),
            'settleSkipped' => $record->settleSkipped,
            'paintSkipped' => $record->paintSkipped,
            'rebuilt' => $rebuilt,
            'byKind' => $byKind,
            'roots' => $roots,
        ];
    }

    /**
     * 某个节点在某一帧为什么重建（D0 `why()` 的转发，帧缺省为最近一帧）。
     *
     * @return array<string, mixed>
     */
    public function why(int $node, ?int $frame = null): array
    {
        return $this->recorder->why($node, $frame);
    }

    /**
     * 单帧的**因果森林**：重建节点按父边连成树，state 写入点作为树里的标记。
     *
     * ## 为什么是森林而不是排行榜
     *
     * 一次动作帧里 350 个节点各重建 1 次，"按次数排名"没有任何区分度，排序退化成
     * 路径先序，前几行必然全是祖先。成熟工具（React Profiler / Flutter Widget
     * Rebuild Stats）在单帧里给的是**层级**，次数留到多帧聚合才有意义。
     *
     * ## 根的定义：父没有重建的节点
     *
     * 森林因此在结构上**不重叠**：`Σ(根.subtree × 根.count) + hidden == rebuilt`，可判据。
     * state 写入点**不另起根**（它常常嵌在另一个写入点的子树里，比如
     * `PageShell ⊃ Table`），只标 `kind` + `site` —— 嵌套关系正是"两次写入谁在谁里面"
     * 的答案，另起根会把它抹平。
     *
     * ## 同形兄弟折叠
     *
     * 表格页的一帧 360 个重建，其中 `Column` 底下 12 个 `SizedBox` 完全同形 —— 逐条
     * 列出来就是 12 行一模一样的东西，把树本身的形状冲散。所以按**结构指纹**把同形
     * 兄弟折成一组（`count=N`，另带全部 `ids`），一层里有多少种形状就显示几行。
     * 这是 perf / WPA 折叠相同调用栈、Instruments 折叠递归的同一手法。
     *
     * 折叠是**无损**的：组里留着每个成员的真实 id，展开与 `why(id)` 都还能用；
     * 丢掉的只有兄弟之间的先后次序，而重建次序不承载因果。
     *
     * 指纹是**递归**的（类型 + 因果 + 子树规模 + 子节点指纹的多重集）。只比类型与规模
     * 会把 `Column[Text, Text]` 和 `Column[Padding[Text]]` 折成一组：规模一样、结构
     * 不同，那是在报告里编造结构。
     *
     * 口径：`×N` 是兄弟个数，其余数字（`subtree` / `omitted` / `hidden`）都是**每个
     * 实例**的。于是一行可以读成"这个形状重复了 N 份，每份这么大"。
     *
     * @param int $maxDepth     树往下展开几层（更深标 `cut`）
     * @param int $maxChildren  每层最多留几**组**（其余计入 `omitted` / `hidden`）
     * @return array<string, mixed>
     */
    public function cascade(int $frame, int $maxDepth = 5, int $maxChildren = 8): array
    {
        $record = $this->recorder->frame($frame);
        if ($record === null) {
            throw new \InvalidArgumentException("没有这一帧：{$frame}（缓冲窗口之外或尚未发生）");
        }

        $shape = $this->shapeOf($record);
        /** @var array<int, list<int>> $children */
        $children = $shape['children'];
        /** @var array<int, int> $subtree */
        $subtree = $shape['subtree'];
        $causeOf = $record->causeOf();
        $signature = $this->signatures($record, $children, $causeOf, $subtree);

        // 写入 ≠ 重建：帧跳过 settle、或元素在 settle 前被卸载时，`state` 边落在这一帧
        // 却没有对应的 rebuild。从 `rebuilt` 取会漏掉它们，而 `explain` 的 byKind 数是
        // 全部 state 边 —— 两处差一条、且没有解释。所以从 causes 取，并标明有没有落地。
        $rebuiltSet = [];
        foreach ($record->rebuilt as $id) {
            $rebuiltSet[$id] = true;
        }
        $writes = [];
        foreach ($record->causes as $edge) {
            if ($edge->kind !== 'state') {
                continue;
            }
            $id = $edge->toId;
            $writes[] = [
                'id' => $id,
                'path' => $this->recorder->pathOf($id),
                'type' => $this->recorder->typeOf($id),
                'site' => $edge->site,
                'detail' => $edge->detail,
                'subtree' => $subtree[$id] ?? 1,
                'rebuilt' => isset($rebuiltSet[$id]),
            ];
        }
        \usort($writes, static fn(array $a, array $b): int => $b['subtree'] <=> $a['subtree']
            ?: \strcmp((string) $a['path'], (string) $b['path']));

        // 森林这一层同样受 `maxChildren` 约束 —— 一条规则用到底，不为"根"开特例。
        // 截断量单独报出去（顶层的 `omitted` / `hidden`），调用方才能把"树加起来
        // 不等于 rebuilt"解释清楚，而不是让它看起来像漏了节点。
        /** @var list<array<string, mixed>> $tree */
        [$tree, $omitted, $hidden] = $this->foldGroups($shape['roots'], $children, $causeOf, $subtree, $signature, 0, $maxDepth, $maxChildren);

        return [
            'frame' => $record->id,
            'rebuilt' => \count($record->rebuilt),
            'depthMax' => (int) $shape['depthMax'],
            'roots' => $tree,
            'omitted' => $omitted,
            'hidden' => $hidden,
            'writes' => $writes,
            'byType' => $this->byType($record),
        ];
    }

    /**
     * 一帧里所有盒的几何摘要，按 node id 升序。
     *
     * 这是 `boxModel` 的"整帧版"，只读同一份几何记录，不碰活盒、不新采集。
     * 它给投影层的是**原料**而不是结论：父子约束怎么比、什么算异常，由投影层
     * 按规则决定。查询层一旦自己判"这里不对"，证据与意见就长在同一处了。
     *
     * `window` 随答案一起给：几何窗口只有 60 帧（帧窗口是 600），查不到时
     * 必须说得清是"窗口外"还是"没有这个盒"。
     *
     * @return array{frame: int, rows: list<array<string, mixed>>, window: array<string, mixed>}
     */
    public function boxes(?int $frame = null): array
    {
        // 缺省帧取**几何窗口的最新**，不是帧窗口的最新。
        //
        // ★ 两个窗口宽度不同（几何 60、帧 600），且几何按"有变化才落记录"跳帧 ——
        //   用 `lastFrameId()` 当缺省，静止几十秒后它必然跑出几何覆盖范围，
        //   于是"打开面板一片空白"而每个环节都自认为正常。缺省值必须落在
        //   它自己要查的那个窗口里。
        $window = $this->recorder->geometryCoverage();
        $frameId = $frame ?? (int) ($window['to'] ?? 0);
        if ($frameId < (int) ($window['from'] ?? 0) || $frameId > (int) ($window['to'] ?? 0)) {
            return [
                'frame' => $frameId,
                'rows' => [],
                'window' => $window,
                'reason' => 'frame-out-of-window',
            ];
        }

        $records = $this->recorder->geometryRows($frameId);
        \ksort($records, \SORT_NUMERIC);

        $rows = [];
        // 几何按变化存：目标帧没重新采样就往前找。整张表的"实际生效帧"必须回显，
        // 否则读者会把 `rows` 当成 `frameId` 那一刻的快照 —— 那正是"三域看起来
        // 都对齐、实际几何是上一帧"的来源。
        $effective = 0;
        foreach ($records as $node => $record) {
            $rows[] = [
                'node' => $node,
                'parent' => $record->parent,
                'type' => $this->recorder->typeOf($node),
                'applied' => $record->applied,
                'inner' => $record->inner,
                'size' => $record->size,
                'box' => $record->box,
                // 相对父**内容盒原点**的偏移与内边距。留原样是为了让"为什么这个盒
                // 在这儿"能被复算；绝对矩形见下面的 `rect`。
                'offset' => $record->offset,
                'pad' => $record->pad,
            ];
            $effective = \max($effective, $record->frame);
        }

        // 绝对矩形只在这里算一次。
        //
        // 让每个消费者自己从 `offset` 累加，等于把一次树的先序遍历发给每个人抄一遍，
        // 而抄错的表现是**盒子错位**——没有读者会先怀疑自己的遍历。这不是判断（"这里
        // 不对"），是坐标变换，所以归查询层；口径与 `pick()` 的 `hitTest` 一致：
        // 子盒原点是相对父内容盒原点的。
        $abs = self::absoluteRects($rows);
        foreach ($rows as $i => $row) {
            $rect = $abs[(int) $row['node']] ?? ['x' => 0.0, 'y' => 0.0];
            $rows[$i]['rect'] = [
                'x' => $rect['x'],
                'y' => $rect['y'],
                'w' => (float) ($row['size']['w'] ?? 0.0),
                'h' => (float) ($row['size']['h'] ?? 0.0),
            ];
        }

        return [
            'frame' => $frameId,
            'effective' => $effective,
            'stale' => $rows !== [] && $effective !== $frameId,
            'rows' => $rows,
            'window' => $window,
        ];
    }

    /**
     * 把"相对父内容盒原点的偏移"累加成视口坐标（border 盒左上角）。
     *
     * 记录里刻意不存绝对坐标：绝对坐标会"因为祖先挪了一像素"而整片失效，而几何是
     * 按签名变化存的 —— 存相对偏移时，一个盒的位置变只影响它自己这一条。
     *
     * 父不在记录里（根，或父本帧没采样）时该节点自成原点；成环时打断并给 0：
     * 环意味着记录层坏了，视图不该陪着一起死。
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, array{x: float, y: float}>
     */
    private static function absoluteRects(array $rows): array
    {
        $byNode = [];
        foreach ($rows as $row) {
            $byNode[(int) $row['node']] = $row;
        }

        $abs = [];
        $resolve = function (int $node, array $seen) use (&$resolve, &$abs, $byNode): array {
            if (isset($abs[$node])) {
                return $abs[$node];
            }

            $row = $byNode[$node] ?? null;
            if ($row === null || isset($seen[$node])) {
                return $abs[$node] = ['x' => 0.0, 'y' => 0.0];
            }
            $seen[$node] = true;

            $x = (float) ($row['offset']['x'] ?? 0.0);
            $y = (float) ($row['offset']['y'] ?? 0.0);
            $parent = (int) $row['parent'];
            if ($parent === $node || !isset($byNode[$parent])) {
                return $abs[$node] = ['x' => $x, 'y' => $y];
            }

            $origin = $resolve($parent, $seen);

            return $abs[$node] = [
                'x' => $origin['x'] + (float) ($byNode[$parent]['pad']['l'] ?? 0.0) + $x,
                'y' => $origin['y'] + (float) ($byNode[$parent]['pad']['t'] ?? 0.0) + $y,
            ];
        };

        foreach ($byNode as $node => $row) {
            $resolve($node, []);
        }

        return $abs;
    }

    /**
     * 一个节点的盒模型：margin / border / padding / content 四层 + 位置。
     *
     * 全部来自帧边界的几何快照，不碰活盒——所以导出的证据在另一个进程里也能问
     * 同一个问题。找不到时说清是"没这个节点"还是"几何窗口外"，不返回默认零值：
     * 默认零值会让一个不存在的盒子看起来像"尺寸正常"。
     *
     * @return array<string, mixed>
     */
    public function boxModel(int $node, ?int $frame = null): array
    {
        $frameId = $frame ?? $this->recorder->lastFrameId();
        $hit = $this->geometryLookup($node, $frameId);
        if ($hit['record'] === null) {
            return $hit['reason'] === 'frame-out-of-window'
                ? $this->frameOutOfWindow($node, $frameId, $hit['window'])
                : $this->geometryMiss($node, $frameId, $hit['window']);
        }

        return ['found' => true] + $hit['record']->toArray() + ['window' => $hit['window']];
    }

    /**
     * 一个节点收到的约束是从哪几跳传下来的。
     *
     * 每一跳查两件事：
     * ① 父的 `applied`（tighten 后）经 padding 内缩与 align 放松，是否得到父的 `inner`；
     * ② 父的 `inner` 经子的宽高修饰收紧，是否得到子记录的 `applied`。
     * 两者都对 ⇒ `base`（基类默认链）；①错 ⇒ `parent-performLayout`（父在
     * performLayout 里另算了 inner）；②错且"父 inner 无界 + 子带 flex" ⇒
     * `parent-flex`（Flex 父按 grow/shrink 给子分配尺寸，最典型的是 `Column` 里的
     * `Expanded`）；②错且不满足那个组合 ⇒ `child-custom`（aspect 等修饰，或中间
     * 还有别人改过）。**对不上不是错误，是答案**：能归默认的归默认，不能的说出不能，
     * 绝不猜一个来源填进去——那是"看起来很有道理"的证据，比没有更危险。
     *
     * 链是**复算**出来的（读快照 + 重跑纯函数），并与快照里实际记的值交叉验证；
     * 两次复算逐值相同即为判据，不需要在 layout 热路径插任何钩子。
     *
     * @return array<string, mixed>
     */
    public function constraints(int $node, ?int $frame = null, int $maxDepth = 12): array
    {
        $frameId = $frame ?? $this->recorder->lastFrameId();
        $hit = $this->geometryLookup($node, $frameId);
        if ($hit['record'] === null) {
            return $hit['reason'] === 'frame-out-of-window'
                ? $this->frameOutOfWindow($node, $frameId, $hit['window'])
                : $this->geometryMiss($node, $frameId, $hit['window']);
        }

        $window = $hit['window'];
        $all = [];
        $cursor = $hit['record'];
        // 环保护：父链来自记录，记录一旦坏掉（父指向自己/互指）这里会无限循环。
        // 与 `pathOf()` 的 `$guard` 同一条纪律：报告不该陪着记录一起死。
        $guard = 0;
        while ($cursor !== null && $cursor->parent > 0 && $guard++ < 64) {
            $parent = $this->recorder->geometryAt($frameId, $cursor->parent);
            if ($parent === null) {
                $all[] = [
                    'from' => $cursor->parent,
                    'to' => $cursor->node,
                    'verified' => false,
                    'source' => 'parent-outside-window',
                    'received' => $cursor->applied,
                ];
                break;
            }

            $expectedInner = GeometryRecord::unpack($parent->applied);
            $pad = $parent->pad;
            if ($pad['l'] != 0.0 || $pad['t'] != 0.0 || $pad['r'] != 0.0 || $pad['b'] != 0.0) {
                $expectedInner = $expectedInner->deflate(
                    new EdgeInsets($pad['l'], $pad['t'], $pad['r'], $pad['b']),
                );
            }
            if ($parent->align !== null) {
                $expectedInner = $expectedInner->loosen();
            }
            $innerOk = GeometryRecord::pack($expectedInner) === $parent->inner;

            $expectedApplied = GeometryRecord::unpack($parent->inner);
            if ($cursor->box !== null || $cursor->aspect !== null) {
                $size = new Size(
                    $cursor->box['w'] ?? $expectedApplied->maxWidth,
                    $cursor->box['h'] ?? $expectedApplied->maxHeight,
                );
                $expectedApplied = $expectedApplied->tighten($size);
            }
            $appliedOk = GeometryRecord::pack($expectedApplied) === $cursor->applied;

            // ---- 第四类：Flex 父在 performLayout 里按 grow/shrink 分配尺寸。
            //
            // 判据是两个**结构事实**，不猜：
            // ① 父是 Flex 容器（`Column` / `Row` / 任何 `extends Flex`，由记录层
            //    在登记节点时按 `instanceof` 判定，不是类名清单）；
            // ② 子自己**没有**宽高修饰（`box` / `aspect` 都为 null）。
            // 子没改约束却拿不到父的 inner ⇒ 只能是父在 performLayout 里重新分的。
            //
            // 有 box/aspect 的子不算：那是子侧改的，即使父是 Flex 也归 `child-custom`
            // —— 归因要指向真正动手的那一方。
            $childTweaked = $cursor->box !== null || $cursor->aspect !== null;
            $parentFlex = $this->recorder->isFlex($parent->node);

            $all[] = [
                'from' => $parent->node,
                'fromType' => $this->recorder->typeOf($parent->node),
                'to' => $cursor->node,
                'toType' => $this->recorder->typeOf($cursor->node),
                'received' => $cursor->applied,
                'expected' => GeometryRecord::pack($expectedApplied),
                'parentInner' => $parent->inner,
                'verified' => $innerOk && $appliedOk,
                'source' => !$innerOk
                    ? 'parent-performLayout'
                    : ($appliedOk
                        ? 'base'
                        : (($parentFlex && !$childTweaked) ? 'parent-flex' : 'child-custom')),
            ];
            $cursor = $parent;
        }

        $total = \count($all);
        $chain = $maxDepth > 0 ? \array_slice($all, 0, $maxDepth) : $all;
        $verified = 0;
        foreach ($chain as $hop) {
            if ($hop['verified']) {
                ++$verified;
            }
        }

        return [
            'found' => true,
            'frame' => $frameId,
            'node' => $node,
            'type' => $this->recorder->typeOf($node),
            'hops' => $total,
            'chain' => $chain,
            'verified' => $verified,
            'unverified' => \count($chain) - $verified,
            'cut' => $total > \count($chain),
            'omitted' => \max(0, $total - \count($chain)),
            'window' => $window,
        ];
    }

    /**
     * 一帧的**绘制事实**：画了哪些节点、层缓存命中几次、哪些边界因什么重录。
     *
     * ## 为什么与 `boxes()` 分成两个查询
     *
     * 几何是"节点 × 帧"的二维事实，绘制是"帧 × 节点集合"的一维事实。合成一个
     * `frameFacts()` 会让两边都开始按对方的口径补字段（几何跟着报 `hits`、绘制
     * 跟着报 `applied`），而它们根本不是同一件事。
     *
     * ## 没有"往前找最近一帧"
     *
     * 几何按变化存，所以 `geometryAt()` 会往前找；绘制**每帧都落一条**（哪怕这帧
     * 什么都没画，也是 `painted: []`）。因此查不到就是"那一帧没采"，不存在"用上一帧
     * 代替"——往前找会把"这帧没画"说成"这帧画了上一帧的那些"。
     *
     * `captured=false` 与"这一帧没画"分开报：前者是预算没开，后者是 `painted: []`。
     *
     * @return array<string, mixed>
     */
    public function paint(?int $frame = null): array
    {
        $window = $this->recorder->paintCoverage();
        // 同 `boxes()`：缺省帧落在**自己的**覆盖窗口里。绘制窗口比帧窗口窄得多，
        // 拿帧窗口的最新帧当缺省，静止一会儿就查不到，而页面会把它读成"这帧没画"。
        $frameId = $frame
            ?? ((int) ($window['to'] ?? 0) ?: $this->recorder->lastFrameId());

        if (($window['captured'] ?? false) !== true) {
            // 未开预算时不能给空集合：那会被读成"这帧什么都没画"，而真相是没采。
            return [
                'frame' => $frameId,
                'found' => false,
                'reason' => 'paint-not-captured',
                'window' => $window,
            ];
        }

        $record = $this->recorder->paintFrame($frameId);
        if ($record === null) {
            return [
                'frame' => $frameId,
                'found' => false,
                'reason' => $frameId < (int) $window['from'] || $frameId > (int) $window['to']
                    ? 'frame-out-of-window'
                    : 'no-paint-record',
                'window' => $window,
            ];
        }

        return ['found' => true] + $record->toArray() + ['window' => $window];
    }

    /**
     * 跨帧聚合**重画**：谁每帧都在画、哪些边界每次都在重录。
     *
     * ## 为什么需要聚合，而不是让投影层逐帧读 `paint()`
     *
     * 绘制窗口 60 帧，单帧最多 4096 个节点。逐帧读 = 60 次查询、24 万个节点号，
     * 远端会话还要 60 个往返。而规则要问的只有"这个节点在窗口里被画了几帧"——
     * 聚合在查询层做一次，答案是一个几十行的表。
     *
     * ## `frames` 是分母，必须回显
     *
     * `nodes[].frames` 是"窗口里有几帧画过它"，`frames` 是"窗口里有几帧"。读者
     * 拿前者比后者才知道是不是"每帧都画"。少了分母，`frames: 5` 看着像"画了 5 次
     * 挺少的"，其实窗口里只有 5 帧。
     *
     * ## 命中率的分子分母都是**边界次数**，不是节点数
     *
     * 一次命中意味着整棵子树一条 `paint()` 都没跑。把省掉的节点算进分母，会让
     * "边界放在大树顶上"看起来命中率极低 —— 那是把收益算成了成本。
     *
     * @param int $frames 只看最近 N 个绘制帧（`<= 0` = 全窗口）
     * @param int $limit  节点表最多几行（`0` = 不截断）；`nodesTotal` 永远给真实行数
     * @return array<string, mixed>
     */
    public function repaints(int $frames = 300, int $limit = 10): array
    {
        $window = $this->recorder->paintCoverage();
        if (($window['captured'] ?? false) !== true) {
            return [
                'captured' => false,
                'frames' => 0,
                'hits' => 0,
                'misses' => 0,
                'hitRate' => 0.0,
                'byReason' => [],
                'nodes' => [],
                'nodesTotal' => 0,
                'window' => $window,
            ];
        }

        $ids = $this->recorder->paintFrames();
        if ($frames > 0 && \count($ids) > $frames) {
            $ids = \array_slice($ids, -$frames);
        }

        $hits = 0;
        $misses = 0;
        $byReason = [];
        /** @var array<int, array{node: int, frames: int, misses: int, reason: string}> $agg */
        $agg = [];
        foreach ($ids as $id) {
            $record = $this->recorder->paintFrame($id);
            if ($record === null) {
                continue;
            }
            $hits += $record->layerHits;
            $misses += \count($record->misses);

            foreach ($record->misses as $node => $reason) {
                $agg[$node] ??= ['node' => $node, 'frames' => 0, 'misses' => 0, 'reason' => ''];
                ++$agg[$node]['misses'];
                // 留**最近一次**原因：窗口里原因可能变（先 resized 后 invalidated），
                // 而"最近一次为什么"才是下一帧要修的那个。
                $agg[$node]['reason'] = $reason;
                $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
            }
            foreach ($record->painted as $node) {
                $agg[$node] ??= ['node' => $node, 'frames' => 0, 'misses' => 0, 'reason' => ''];
                ++$agg[$node]['frames'];
            }
        }

        $rows = \array_values($agg);
        // 三级排序：先按"画过几帧"（每帧都画的最该看），再按"重录几次"（边界反复失效），
        // 最后按节点号保证输出稳定 —— 否则同一份证据两次查询的行序可能不同。
        \usort($rows, static fn(array $a, array $b): int => ($b['frames'] <=> $a['frames'])
            ?: ($b['misses'] <=> $a['misses'])
            ?: ($a['node'] <=> $b['node']));

        $total = \count($rows);
        $boundary = $hits + $misses;

        // 路径与类型在**裁完之后**才查：`pathOf()` 要沿父链拼一串，4000 个节点全查
        // 一遍而只显示 10 行，是把代价花在没人看的行上。排序只用标量，所以裁在前。
        $shown = $limit > 0 ? \array_slice($rows, 0, $limit) : $rows;
        foreach ($shown as $i => $row) {
            $shown[$i] = [
                'node' => $row['node'],
                'path' => $this->recorder->pathOf($row['node']),
                'type' => $this->recorder->typeOf($row['node']),
                'frames' => $row['frames'],
                'misses' => $row['misses'],
                'reason' => $row['reason'] === '' ? null : $row['reason'],
            ];
        }

        return [
            'captured' => true,
            'frames' => \count($ids),
            'hits' => $hits,
            'misses' => $misses,
            'hitRate' => $boundary === 0 ? 0.0 : $hits / $boundary,
            'byReason' => $byReason,
            'nodes' => $shown,
            'nodesTotal' => $total,
            'window' => $window,
        ];
    }

    /**
     * 跨域对齐：同一节点、同一显式帧，一次取到因果 / 几何 / 网络三张脸。
     *
     * ## 帧只在一处选
     *
     * `frame` 必填。三域的"缺省帧"语义本来就不同（因果取最近重建帧、几何取
     * 最后一帧、网络没有缺省），让各域自己缺省再拼起来，会给出"三域都在 #N"
     * 的假象。调用方——包括 `brief` 的投影——先选帧，再传进来；本查询只读，
     * 不做"帮你选一个"。
     *
     * ## 每域回显自己实际命中的帧
     *
     * 几何是按变化存的：目标帧没重新采样就往前找最近一条。若它返回 `frame=1`
     * 而请求 `frame=2`，这里标 `stale=true` 并回显 `1`，绝不伪称 `2`。一个
     * "看起来三域都对齐、实际几何是上一帧"的答案比明确说 stale 更危险。
     *
     * ## 三域各自的 miss 形状归一
     *
     * `found: false` + `reason` + `window`，不再有 `reason` / `miss` / 无 found
     * 三种拼法。`reason` 是分类（unknown-node / frame-out-of-window /
     * no-render-box / no-landed-request），不是错误全文。
     *
     * @return array<string, mixed>
     */
    public function align(int $node, int $frame): array
    {
        $known = $this->recorder->typeOf($node) !== '';
        $base = [
            'node' => $node,
            'frame' => $frame,
            'path' => $this->recorder->pathOf($node),
        ];
        $win = [
            'frames' => $this->recorder->stats()['capacity'],
            'geometry' => $this->recorder->geometryCoverage(),
            'network' => $this->recorder->networkCoverage(),
        ];
        if (!$known) {
            $reason = $this->recorder->nodeStatus($node) === 'evicted' ? 'node-evicted' : 'unknown-node';

            return $base + [
                'found' => false,
                'reason' => $reason,
                'causal' => ['found' => false, 'reason' => $reason],
                'geometry' => $this->geometryMiss($node, $frame, $win['geometry']),
                'network' => ['found' => false, 'reason' => $reason, 'window' => $win['network']],
                'windows' => $win,
            ];
        }

        // ---- 因果：显式帧，不走 why 的"最近重建帧"缺省。
        $frameRecord = $this->recorder->frame($frame);
        if ($frameRecord === null) {
            $causal = ['found' => false, 'reason' => 'frame-out-of-window'];
        } else {
            $causes = [];
            foreach ($frameRecord->causes as $edge) {
                if ($edge->toId === $node) {
                    $causes[] = $edge->toArray();
                }
            }
            $causal = [
                'found' => true,
                'frame' => $frameRecord->id,
                'rebuilt' => $frameRecord->rebuilt($node),
                'causes' => $causes,
            ];
        }

        // ---- 几何：记录自带实际帧号，stale 由它判定，不靠调用方猜。
        // 判窗口与查记录都走 `geometryLookup()`：与另外三个查询同一份语义。
        $hit = $this->geometryLookup($node, $frame, $win['geometry']);
        $geo = $hit['record'];
        if ($geo === null) {
            $geometry = $hit['reason'] === 'frame-out-of-window'
                ? $this->frameOutOfWindow($node, $frame, $win['geometry'])
                : $this->geometryMiss($node, $frame, $win['geometry']);
        } else {
            $geometry = [
                'found' => true,
                'frame' => $geo->frame,
                'stale' => $geo->frame !== $frame,
                'size' => $geo->size,
                'offset' => $geo->offset,
                'applied' => $geo->applied,
            ];
        }

        // ---- 网络：只认帧末回填的 landed[]；扫 500 条窗口反查，不建镜像索引。
        $landed = [];
        foreach ($this->recorder->requestsLandingOn($node) as $requestId) {
            $request = $this->recorder->networkAt($requestId);
            if ($request !== null && $request->settledAtFrame === $frame) {
                $landed[] = [
                    'request' => $request->requestId,
                    'method' => $request->method,
                    'url' => $request->url,
                    'status' => $request->status,
                    'ok' => $request->ok,
                ];
            }
        }
        $network = $landed === []
            ? ['found' => false, 'reason' => 'no-landed-request', 'window' => $win['network']]
            : ['found' => true, 'frame' => $frame, 'requests' => $landed, 'window' => $win['network']];

        $causalFound = ($causal['found'] ?? false) === true;
        $geometryFound = ($geometry['found'] ?? false) === true;
        $networkFound = ($network['found'] ?? false) === true;

        return $base + [
            // `found` = 这个地址在证据里存在（任一域有值）；`complete` = 三域齐全。
            // 分开说：读者常把 `found=true` 误读成"三域都有"，那就又是一次假对齐。
            'found' => $causalFound || $geometryFound || $networkFound,
            'complete' => $causalFound && $geometryFound && $networkFound,
            'causal' => $causal,
            'geometry' => $geometry,
            'network' => $network,
            'windows' => $win,
        ];
    }

    /**
     * 跨帧聚合**根因**：同一调用点写同一 State 的次数。
     *
     * 只算 `kind=state`。`parent` / `unknown` 是传播与兜底，算进来会让排名失真。
     *
     * @return list<array<string, mixed>>
     */
    public function hotspots(int $frames = 300, int $limit = 10): array
    {
        $agg = [];
        foreach ($this->window($frames) as $frame) {
            $shape = $this->shapeOf($frame);
            /** @var array<int, int> $subtree */
            $subtree = $shape['subtree'];
            foreach ($frame->causes as $edge) {
                if ($edge->kind !== 'state') {
                    continue; // 传播与兜底不是根因：算进来会让排名全是下游节点
                }
                $key = $edge->site . "\0" . $edge->detail;
                if (!isset($agg[$key])) {
                    $agg[$key] = [
                        'site' => $edge->site,
                        'detail' => $edge->detail,
                        'writes' => 0,
                        'frames' => [],
                        'nodes' => [],
                        'subtree' => 0,
                    ];
                }
                ++$agg[$key]['writes'];
                $agg[$key]['frames'][$frame->id] = true;
                $agg[$key]['nodes'][$edge->toId] = true;
                // 同一写入点多帧出现时取**最大单帧波及**：与"写了多少次"是两个问题，
                // 合并成合计会把"偶尔一次打到整树"和"每帧动 3 个"算成同一档。
                $agg[$key]['subtree'] = \max((int) $agg[$key]['subtree'], $subtree[$edge->toId] ?? 1);
            }
        }

        return $this->rank(
            \array_values(\array_map(
                static fn(array $row): array => [
                    'site' => $row['site'],
                    'detail' => $row['detail'],
                    'writes' => $row['writes'],
                    'frames' => \count($row['frames']),
                    'nodes' => \count($row['nodes']),
                    'subtree' => $row['subtree'],
                ],
                $agg,
            )),
            'writes',
            'site',
            $limit,
        );
    }

    /**
     * 跨帧聚合**节点**：谁被重建得最多，以及其中多少是自身写入、多少是父传播。
     *
     * @return list<array<string, mixed>>
     */
    public function nodes(int $frames = 300, int $limit = 10): array
    {
        $agg = [];
        foreach ($this->window($frames) as $frame) {
            $shape = $this->shapeOf($frame);
            /** @var array<int, int> $subtree */
            $subtree = $shape['subtree'];
            foreach ($frame->rebuilt as $id) {
                $agg[$id] ??= [
                    'id' => $id,
                    'path' => $this->recorder->pathOf($id),
                    'rebuilds' => 0,
                    'self' => 0,
                    'inherited' => 0,
                    'subtree' => 0,
                    'first' => $frame->id,
                    'last' => $frame->id,
                ];
                ++$agg[$id]['rebuilds'];
                $agg[$id]['last'] = $frame->id;
                $agg[$id]['subtree'] = \max((int) $agg[$id]['subtree'], $subtree[$id] ?? 1);
            }
            foreach ($frame->causes as $edge) {
                $id = $edge->toId;
                if (!isset($agg[$id])) {
                    continue;
                }
                if ($edge->kind === 'state') {
                    ++$agg[$id]['self'];
                } else {
                    ++$agg[$id]['inherited'];
                }
            }
        }

        $rows = \array_values($agg);
        // 排序三级：次数（跨帧才有区分度）→ 覆盖子树（单帧里"谁牵动得多"）→ 路径（稳定）。
        // 只按次数排会在"每节点各 1 次"的动作帧里退化成路径先序，把真正的分叉点埋掉。
        \usort($rows, static fn(array $a, array $b): int => ($b['rebuilds'] <=> $a['rebuilds'])
            ?: ((int) $b['subtree'] <=> (int) $a['subtree'])
            ?: \strcmp((string) $a['path'], (string) $b['path']));

        return $limit > 0 ? \array_slice($rows, 0, $limit) : $rows;
    }

    /**
     * 网络请求骨架：请求 → 帧对齐 → 落地节点，与帧因果共用同一条时间轴。
     *
     * ## 为什么是骨架而不是报文
     *
     * 抓包工具回答"发了什么、回了什么"，这里回答的是它们给不了的第三层：
     * 响应落在第几帧、让谁重建了。记 body / header 既撑爆证据包又碰隐私，而且
     * 抓包做得更好 —— 证据的定位是定位，不是副本。
     *
     * ## 窗口自报是硬义务
     *
     * 网络有独立的 500 条窗口（帧是 600）。查不到时不能让读者以为是"那段时间没
     * 发请求"：要么请求被裁掉了，要么录制器当时没装。所以 `window` 随答案一起给。
     *
     * @return array{rows: list<array<string, mixed>>, window: array<string, mixed>, total: int}
     */
    public function network(?int $request = null, int $limit = 50): array
    {
        $window = $this->recorder->networkCoverage();
        if ($request !== null) {
            $record = $this->recorder->networkAt($request);
            if ($record === null) {
                return [
                    'rows' => [],
                    'window' => $window,
                    'total' => 0,
                    'miss' => $this->networkMissReason($request, $window),
                ];
            }

            return ['rows' => [$record->toArray()], 'window' => $window, 'total' => 1];
        }

        $rows = \array_map(
            static fn(NetworkRecord $r): array => $r->toArray(),
            $this->recorder->networkRows(),
        );
        $total = \count($rows);

        return [
            // 取**最新**的 N 条：查网络面板的人要看刚发的请求。早先 `array_slice($rows, 0, …)`
            // 取的是最旧的 —— 窗口满 500 条时，列表里全是几分钟前的东西。
            'rows' => $limit > 0 ? \array_slice($rows, -$limit) : $rows,
            'window' => $window,
            'total' => $total,
        ];
    }

    /**
     * 按 requestId 取报文（骨架的可选附件）。
     *
     * ## 为什么不并进 network()
     *
     * 报文默认不出现——体积、隐私、协议 8MB 上限三条理由。把它做成独立查询，
     * `network()` 保持默认轻量可导出；要看 body 时显式问一次，还带窗口自报。
     *
     * ## 截断是分两层的
     * 存档时单条最多 `MAX_BYTES`（64KB），查询时再按 `$maxBytes` 截一次——
     * 后者是协议护栏，防止"一条 64KB × 50 条"撑爆单条消息。两层都报截断，
     * 读者知道看到的是片段而不是全部。
     *
     * @return array<string, mixed>
     */
    public function networkBody(int $request, string $part = 'both', int $maxBytes = 8 * 1024): array
    {
        $window = $this->recorder->bodyCoverage();
        $part = \in_array($part, ['both', 'request', 'response'], true) ? $part : 'both';
        $maxBytes = \max(0, $maxBytes);
        if (($window['captured'] ?? false) !== true) {
            // 未开启报文预算时，空窗口不是"抓了但为空"：读者必须能分辨这两种事实。
            return [
                'request' => $request,
                'found' => false,
                'miss' => 'bodies-not-captured',
                'window' => $window,
            ];
        }
        $record = $this->recorder->bodyAt($request);
        if ($record === null) {
            $skeleton = $this->recorder->networkAt($request);

            return [
                'request' => $request,
                'found' => false,
                // 骨架在 ⇒ 报文只是没存/被裁（`body-not-retained`）；骨架不在 ⇒
                // 连请求本身都查不到，这时才用**网络**窗口判"窗口外还是没这条"。
                // 用报文窗口判会错：报文窗口(200)比网络窗口(500)小，网络里还在的
                // 请求会被说成"窗口外"。
                'miss' => $skeleton === null
                    ? $this->networkMissReason($request, $this->recorder->networkCoverage())
                    : 'body-not-retained',
                'window' => $window,
            ];
        }

        return ['found' => true] + $record->select($part, $maxBytes) + ['window' => $window];
    }

    /**
     * 在**证据**里找东西：给一段文本，回一串**地址**。
     *
     * ## 为什么不叫"全文检索"
     *
     * 全文检索的产物是"文档 + 位置"，而调试题要的产物是**地址**：`node:12` 能直接
     * 喂给 `boxModel` / `why` / `align`，`request:3` 能直接喂给 `network` /
     * `networkBody`。所以这里不返回命中行号、不返回片段偏移 —— 那种结果在证据
     * 被重新导出、窗口滚动之后就没有意义了（A36 ⑧"地址稳定"）。
     *
     * ## 只搜证据，不搜活树
     *
     * 两个域都可从记录回答，因此**导出的证据包、远端会话、离线包**搜出来的结果
     * 逐值相同。搜活树会引入"这次搜到的节点下次没了"的不确定性，而搜索的第一
     * 用途恰恰是"照着结果去追问"，追问必须还能解析。
     *
     * ## 匹配是字面子串，没有正则
     *
     * 正则有灾难性回溯、有跨实现语义差异（PCRE / JS / 各语言），而搜索的结论要
     * 能被复算。`Table|Column` 在这里按字面找，找不到就是找不到 —— 不猜用户想写
     * 的是"或"。大小写默认不敏感（调试时没人记得住 `SizedBox` 的大小写）。
     *
     * ## 两个域各匹配什么
     *
     * `node` 匹配节点**自身的类型**（`Table` / `SizedBox`），路径只用来显示在哪；
     * `request` 匹配"方法 + URL + 状态"拼成的一行。两个都不搜报文正文：正文默认
     * 不采，搜它会给出"抓了就有、没抓就没有"的不稳定结果。
     *
     * ## 三个数必须分开报：扫了多少、命中多少、给了多少
     *
     * `scanned` 是"这段证据里有多少候选"，`matched` 是命中总数，`returned` 是
     * 截断后真给出去的。少了 `scanned`，"0 条结果"会被读成"没有匹配"，
     * 而它更可能是"这个域根本没有候选"（没采 / 窗口空 / 全被回收）——
     * 两者的下一步动作相反：一个是改关键词，一个是补采集。
     *
     * `evicted` 同理：已回收的节点不在表里，**搜不到不等于没有过**。
     *
     * @param string $pattern 字面子串（不做正则、不切词）
     * @param string $domain  `all` / `node` / `request`
     * @param int    $limit   最多给多少条（命中总数照实报在 `total`）
     * @param bool   $caseSensitive 默认不敏感
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException 空 pattern / 未知域 / limit 非正
     */
    public function find(string $pattern, string $domain = 'all', int $limit = 50, bool $caseSensitive = false): array
    {
        $pattern = \trim($pattern);
        if ($pattern === '') {
            // 空串会匹配一切：那不是搜索结果，是把整份证据倒出来。
            throw new \InvalidArgumentException('find 需要非空 pattern');
        }
        if ($limit < 1) {
            throw new \InvalidArgumentException('find 的 limit 是正整数');
        }
        $domains = match ($domain) {
            'all' => ['node', 'request'],
            'node', 'request' => [$domain],
            default => throw new \InvalidArgumentException(
                'find 不认这个域：' . $domain . '（可用：all / node / request）',
            ),
        };

        $needle = $caseSensitive ? $pattern : \mb_strtolower($pattern, 'UTF-8');
        $hit = static fn(string $haystack): bool => \str_contains(
            $caseSensitive ? $haystack : \mb_strtolower($haystack, 'UTF-8'),
            $needle,
        );

        /** @var array<string, array<string, mixed>> $counts */
        $counts = [];
        /** @var list<array{domain: string, address: string, text: string}> $all */
        $all = [];

        if (\in_array('node', $domains, true)) {
            $scanned = 0;
            $matched = 0;
            // ★ 匹配的是**节点自身的类型**，不是完整路径。按路径匹配会让 `Table`
            //   命中"Table 子树里的每一个节点"（实测 346/364）——结果列表变成整棵树，
            //   搜索就退化成"打印一切"。要列某容器的子树是 `cascade(frame)` 的问题，
            //   不是搜索的问题。路径仍然印在 `text` 里：命中之后紧跟的就是它在哪。
            foreach ($this->recorder->nodeIndex() as $id => $_meta) {
                ++$scanned;
                $type = $this->recorder->typeOf($id);
                if (!$hit($type)) {
                    continue;
                }
                ++$matched;
                $all[] = [
                    'domain' => 'node',
                    'address' => 'node:' . $id,
                    // 路径只对**命中项**算：3000 个节点各拼一遍父链，代价落在这条
                    // 查询最贵的地方，而它只对返回的几十行有用。
                    'text' => $this->recorder->pathOf($id),
                ];
            }
            $counts['node'] = [
                'scanned' => $scanned,
                'matched' => $matched,
                'returned' => 0,
                'evicted' => \count($this->recorder->evictedNodes()),
            ];
        }

        if (\in_array('request', $domains, true)) {
            $scanned = 0;
            $matched = 0;
            foreach ($this->recorder->networkRows() as $record) {
                ++$scanned;
                $text = \sprintf('%s %s %d', $record->method, $record->url, $record->status);
                if (!$hit($text)) {
                    continue;
                }
                ++$matched;
                $all[] = ['domain' => 'request', 'address' => 'request:' . $record->requestId, 'text' => $text];
            }
            $counts['request'] = [
                'scanned' => $scanned,
                'matched' => $matched,
                'returned' => 0,
                // 网络有独立窗口：被裁掉的请求搜不到，这个数说出"这段没搜"。
                'window' => $this->recorder->networkCoverage(),
            ];
        }

        $total = \count($all);
        $matches = \array_slice($all, 0, $limit);
        foreach ($matches as $one) {
            ++$counts[$one['domain']]['returned'];
        }

        return [
            'pattern' => $pattern,
            'domain' => $domain,
            'case' => $caseSensitive,
            'limit' => $limit,
            'total' => $total,
            'returned' => \count($matches),
            'truncated' => $total > \count($matches),
            'domains' => $counts,
            'matches' => $matches,
        ];
    }

    // ------------------------------------------------------------------ 内部

    /**
     * 请求查不到时是"窗口外"还是"根本没有这条"。
     *
     * ★ 两个查询各写一遍必然漂：早先 `network()` 只比 `from`（空窗口 `from=0`，
     *   任何 id 都大于它 ⇒ 一律报 `unknown-request`），`networkBody()` 先看
     *   `requests === 0`。同一个请求，两个查询给两种答案。
     *
     * @param array<string, mixed> $window 需含 `requests` 与 `from`
     */
    private function networkMissReason(int $request, array $window): string
    {
        return (int) ($window['requests'] ?? 0) === 0 || $request < (int) ($window['from'] ?? 0)
            ? 'outside-network-window'
            : 'unknown-request';
    }

    /**
     * 几何查证的**唯一入口**：先判帧在不在窗口内，再查记录。
     *
     * ★ 为什么必须收成一处：`geometryAt()` 的"往前找最近一次"只对**窗口内**成立。
     *   四个查询各判一遍窗口，实际上只会修到其中几个 —— 早先只有 `align()` 判了，
     *   `boxModel` / `boxes` / `constraints` 照样把窗口末端的旧几何当成目标帧的，
     *   于是"查一个根本没采样过的帧"返回 `found=true`。这类答案自洽、可读、全错。
     *
     * @param array<string, mixed>|null $window 已知窗口时传进来，省一次遍历
     * @return array{record: ?GeometryRecord, window: array<string, mixed>, reason: ?string}
     *   `reason` 非 null = 帧不在窗口，"连有没有这个盒"都回答不了。
     */
    private function geometryLookup(int $node, int $frame, ?array $window = null): array
    {
        $window ??= $this->recorder->geometryCoverage();
        $from = (int) ($window['from'] ?? 0);
        $to = (int) ($window['to'] ?? 0);
        if ($frame < $from || $frame > $to) {
            return ['record' => null, 'window' => $window, 'reason' => 'frame-out-of-window'];
        }

        return ['record' => $this->recorder->geometryAt($frame, $node), 'window' => $window, 'reason' => null];
    }

    /**
     * 帧号在窗口外：与"窗口内但没有这个盒"是两件事，不能混。
     *
     * @param array<string, mixed> $window
     * @return array<string, mixed>
     */
    private function frameOutOfWindow(int $node, int $frame, array $window): array
    {
        return [
            'found' => false,
            'node' => $node,
            'frame' => $frame,
            'reason' => 'frame-out-of-window',
            'window' => $window,
        ];
    }

    /**
     * 几何查不到的三种原因必须分开说。
     *
     * 复合控件没有自己的 RenderBox，那是**永久事实**；窗口外是**暂时事实**（换个帧号
     * 可能就有）；节点不存在是**地址错了**。三者混成一句"没找到"，读者会把第一种
     * 当成第二种，一直等一条永远不会出现的记录。
     *
     * @param array<string, mixed> $window
     * @return array<string, mixed>
     */
    private function geometryMiss(int $node, int $frame, array $window): array
    {
        $boxless = $this->recorder->isBoxless($node);
        $out = [
            'found' => false,
            'node' => $node,
            'frame' => $frame,
            'reason' => $boxless
                ? 'no-render-box'
                : match ($this->recorder->nodeStatus($node)) {
                    'evicted' => 'node-evicted',
                    'unknown' => 'unknown-node',
                    default => 'outside-geometry-window',
                },
            'window' => $window,
        ];

        if ($boxless) {
            $child = $this->recorder->firstBoxedChild($node);
            $out['suggest'] = $child === null
                ? '这个节点是复合节点，没有自己的盒；往下找有盒的后代'
                : \sprintf('查有盒的子节点 %d（%s）', $child, $this->recorder->typeOf($child));
        }

        return $out;
    }

    /**
     * 最近 N 帧（`$frames <= 0` 表示全窗口），按帧号升序。
     *
     * @return array<int, FrameRecord>
     */
    private function window(int $frames): array
    {
        $all = $this->recorder->frames();
        if ($frames <= 0 || \count($all) <= $frames) {
            return $all;
        }

        return \array_slice($all, -$frames, null, true);
    }

    /**
     * 帧的森林骨架，按帧号缓存。
     *
     * `roots` 是"父没有重建"的节点 ⇒ 森林不重叠，子树规模之和等于重建数。
     * `subtree` 由深到浅累加（`arsort($depth)` 保证子先于父落账）。
     *
     * @return array{children: array<int, list<int>>, roots: list<int>, subtree: array<int, int>, depthMax: int, parent: array<int, int>}
     */
    private function shapeOf(FrameRecord $frame): array
    {
        // `reset()` 把帧号归零，下一轮又从 1 开始；旧骨架留在缓存里就会被同号帧命中，
        // 于是新因果配旧结构 —— 一棵自洽、可读、全错的树。代际变了先整体丢弃。
        $generation = $this->recorder->generation();
        if ($generation !== $this->cacheGeneration) {
            $this->shapeCache = [];
            $this->cacheGeneration = $generation;
        }
        if (isset($this->shapeCache[$frame->id])) {
            /** @var array{children: array<int, list<int>>, roots: list<int>, subtree: array<int, int>, depthMax: int, parent: array<int, int>} */
            return $this->shapeCache[$frame->id];
        }
        // 缓存键是帧号，环形缓冲裁掉的帧不会再被问到；到容量就整体丢弃，
        // 不让它跟着会话无限长（一份骨架比一条帧记录大得多）。
        if (\count($this->shapeCache) >= 600) {
            $this->shapeCache = [];
        }

        $parent = $frame->parentOf();
        $rebuiltSet = [];
        foreach ($frame->rebuilt as $id) {
            $rebuiltSet[$id] = true;
        }

        $children = [];
        $roots = [];
        $depth = [];
        foreach ($frame->rebuilt as $id) {
            $p = $parent[$id] ?? null;
            if ($p !== null && isset($rebuiltSet[$p])) {
                $children[$p][] = $id;
            } else {
                $roots[] = $id;
            }

            $d = 0;
            $cursor = $id;
            $guard = 0;
            while (isset($parent[$cursor]) && $guard++ < 64) {
                $cursor = $parent[$cursor];
                ++$d;
            }
            $depth[$id] = $d;
        }

        $subtree = [];
        foreach ($frame->rebuilt as $id) {
            $subtree[$id] = 1;
        }
        \arsort($depth);
        foreach ($depth as $id => $d) {
            $p = $parent[$id] ?? null;
            if ($p !== null && isset($subtree[$p])) {
                $subtree[$p] += $subtree[$id];
            }
        }

        $shape = [
            'children' => $children,
            'roots' => $roots,
            'subtree' => $subtree,
            'depthMax' => $depth === [] ? 0 : \max($depth),
            'parent' => $parent,
        ];
        $this->shapeCache[$frame->id] = $shape;

        return $shape;
    }

    /**
     * 结构指纹：同形的子树得到同一个整数。
     *
     * 用 interning（键串 => 递增整数）而不是 md5：子节点已经是指纹整数，键串长度只与
     * "这一层有几个孩子"有关，不随子树规模膨胀，也没有碰撞可担心。代价是指纹只在**一帧
     * 内**可比 —— 它的用途是分组，不是跨帧身份。要做"这个形状在 20 帧里有 12 帧重建"，
     * 那时该留的是键串本身（内容寻址），不是这里的整数。
     *
     * 键里必须带因果（kind / site / detail）：两个同形节点，一个自己写了状态、一个只是
     * 被父带着重建，折成一组就再也说不出"是从哪儿写的"。
     *
     * @param array<int, list<int>> $children
     * @param array<int, Edge>      $causeOf
     * @param array<int, int>       $subtree
     * @return array<int, int> 节点 id => 指纹
     */
    private function signatures(FrameRecord $frame, array $children, array $causeOf, array $subtree): array
    {
        $signature = [];
        $intern = [];
        $visit = function (int $id) use (&$visit, &$signature, &$intern, $children, $causeOf, $subtree): void {
            $kids = [];
            foreach ($children[$id] ?? [] as $kid) {
                $visit($kid);
                $kids[] = $signature[$kid];
            }
            // 兄弟次序不进入指纹：同形指的是**无序**同构。重建次序不承载因果，
            // 把它算进去只会让"只差构建次序"的两个子树折不成一组 —— 而它们在报告里
            // 讲的是同一件事。
            \sort($kids);
            $edge = $causeOf[$id] ?? null;
            $key = $this->recorder->typeOf($id)
                . "\x1f" . ($edge?->kind ?? '')
                . "\x1f" . ($edge?->site ?? '')
                . "\x1f" . ($edge?->detail ?? '')
                . "\x1f" . ($subtree[$id] ?? 1)
                . "\x1f" . \implode(',', $kids);
            $signature[$id] ??= $intern[$key] ??= \count($intern);
        };

        // 只走访重建节点：`children` 就是按"父也重建"建出来的，所以每个节点恰好一次。
        foreach ($frame->rebuilt as $id) {
            if (!isset($signature[$id])) {
                $visit($id);
            }
        }

        return $signature;
    }

    /**
     * 把一层的兄弟按结构指纹分组，返回 `[组节点, 未显示的兄弟数, 未显示的节点数]`。
     *
     * `$ids` 必须是**同一个父**的孩子（或森林的根）。后两个返回值是**本层**口径
     * （`maxChildren` 裁掉的那部分），不含子层递归的量 —— 内层节点的规模已经算在
     * 根的 `subtree` 里，累加会超算。内层的裁剪由节点上的 `omitted` 逐层报出，
     * 那是**支**口径，消费者递归读它。
     *
     * @param list<int>             $ids
     * @param array<int, list<int>> $children
     * @param array<int, Edge>      $causeOf
     * @param array<int, int>       $subtree
     * @param array<int, int>       $signature
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int}
     */
    private function foldGroups(array $ids, array $children, array $causeOf, array $subtree, array $signature, int $depth, int $maxDepth, int $maxChildren): array
    {
        /** @var array<int, list<int>> $buckets 指纹 => 同形兄弟 */
        $buckets = [];
        foreach ($ids as $id) {
            $buckets[$signature[$id]][] = $id;
        }

        $groups = [];
        foreach ($buckets as $key => $members) {
            // 成员按 id 升序：组内次序与遍历顺序无关，人和判据看到的都是同一串数字。
            \sort($members);
            $groups[] = [
                'sig' => $key,
                'ids' => $members,
                'subtree' => $subtree[$members[0]] ?? 1,
                'type' => $this->recorder->typeOf($members[0]),
            ];
        }
        // 波及大的先显示：预算有限时留下的必须是牵动最多的那一支（"18 个的根"不能把
        // 真正 342 个的那支挤到截断之后）。同波及时"同形份数多"的优先 —— 重复本身
        // 就是这一帧值得看的事实。最后按类型名与指纹兜底，输出与遍历顺序无关。
        \usort($groups, static fn(array $a, array $b): int => $b['subtree'] <=> $a['subtree']
            ?: \count($b['ids']) <=> \count($a['ids'])
            ?: \strcmp($a['type'], $b['type'])
            ?: $a['sig'] <=> $b['sig']);

        $shown = $maxChildren > 0 ? \array_slice($groups, 0, $maxChildren) : $groups;
        $omitted = 0;
        $hidden = 0;
        foreach (\array_slice($groups, \count($shown)) as $group) {
            $omitted += \count($group['ids']);
            $hidden += \count($group['ids']) * $group['subtree'];
        }

        $nodes = [];
        foreach ($shown as $group) {
            $nodes[] = $this->groupNode($group, $children, $causeOf, $subtree, $signature, $depth, $maxDepth, $maxChildren);
        }

        // ★ 这里**只**返回本层被裁的量，不累加子层递归上来的。
        //   看起来"漏了内层裁剪"，其实是口径不同：`hidden` 是**节点**口径，
        //   而内层节点的规模已经算在根的 `subtree` 里（`Σ(根.subtree × 根.count)`
        //   覆盖整棵子树，不论展开与否）。累加子层会让恒等式
        //   `Σ(根.subtree × 根.count) + hidden == rebuilt` 超算。
        //
        //   "内层被 maxChildren 裁掉了"这件事由节点上的 `omitted` 逐层报出，
        //   消费者（`Brief::coverage()`）递归读它 —— 那是**支**口径，可以累加。
        return [$nodes, $omitted, $hidden];
    }

    /**
     * 一组同形兄弟 → 树里的一行。
     *
     * 只走**代表**（id 最小的那个）的子节点：组内成员同形，意味着它们子节点指纹的多重集
     * 相同，所以代表的孩子分组就是每一份实例的孩子分组 —— 不必把 N 份都走一遍，
     * 也不会有"第 7 份和第 3 份长得不一样"这种自相矛盾（那说明指纹算错了）。
     *
     * @param array{sig: int, ids: list<int>, subtree: int, type: string} $group
     * @param array<int, list<int>> $children
     * @param array<int, Edge>      $causeOf
     * @param array<int, int>       $subtree
     * @param array<int, int>       $signature
     * @return array<string, mixed>
     */
    private function groupNode(array $group, array $children, array $causeOf, array $subtree, array $signature, int $depth, int $maxDepth, int $maxChildren): array
    {
        $rep = $group['ids'][0];
        $edge = $causeOf[$rep] ?? null;
        $count = \count($group['ids']);
        $node = [
            'id' => $rep,
            'type' => $group['type'],
            'kind' => $edge?->kind ?? '',
            'count' => $count,
            'subtree' => $group['subtree'],
            'site' => $edge?->site ?? '',
            'detail' => $edge?->detail ?? '',
            'children' => [],
            'omitted' => 0,
            'hidden' => 0,
            'cut' => false,
        ];
        if ($count > 1) {
            // 折叠无损：展开与 `why(id)` 都要能拿到真实 id。单个节点不给 `ids` ——
            // 那会让同一份信息在一帧里有两种写法，而两种写法迟早不一致。
            $node['ids'] = $group['ids'];
        }

        $kids = $children[$rep] ?? [];
        if ($kids === []) {
            return $node;
        }
        if ($depth >= $maxDepth) {
            // 截断处也要把规模说准：支数与节点数都按每个实例计。
            $node['cut'] = true;
            $node['omitted'] = \count($kids);
            $node['hidden'] = $group['subtree'] - 1;

            return $node;
        }

        [$node['children'], $omitted, $hidden] = $this->foldGroups($kids, $children, $causeOf, $subtree, $signature, $depth + 1, $maxDepth, $maxChildren);
        $node['omitted'] = $omitted;
        $node['hidden'] = $hidden;

        return $node;
    }

    /**
     * 按 Widget 类型聚合（React Profiler 的 "component ×N" 口径）。
     *
     * 截断只影响树，不影响这里：类型构成必须永远覆盖全部重建节点。
     *
     * @return list<array{type: string, nodes: int}>
     */
    private function byType(FrameRecord $frame): array
    {
        $agg = [];
        foreach ($frame->rebuilt as $id) {
            $type = $this->recorder->typeOf($id);
            if ($type === '') {
                $type = '?';
            }
            $agg[$type] = ($agg[$type] ?? 0) + 1;
        }
        \arsort($agg);

        $out = [];
        foreach ($agg as $type => $nodes) {
            $out[] = ['type' => (string) $type, 'nodes' => $nodes];
        }

        return $out;
    }

    /**
     * 按次数字段降序；并列时按键字段升序，保证输出稳定（同名同数不随帧序抖）。
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function rank(array $rows, string $by, string $tie, int $limit): array
    {
        \usort($rows, static fn(array $a, array $b): int => ($b[$by] <=> $a[$by])
            ?: \strcmp((string) $a[$tie], (string) $b[$tie]));

        return $limit > 0 ? \array_slice($rows, 0, $limit) : $rows;
    }
}
