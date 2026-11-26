<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

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
 * ## 这层只剩"协议面"与"跨域组合"
 *
 * 二十三个查询分属六个域，实现各在**自己的类**里，按它读的那份记录分：
 *
 * | 域 | 类 | 读什么 | 回答 |
 * |---|---|---|---|
 * | 因果 | {@see CausalQuery} | 帧窗口（600） | 这帧变了什么、因为谁 |
 * | 几何 | {@see GeometryQuery} | 几何窗口（60） | 盒在哪、多大、约束怎么传 |
 * | 绘制 | {@see PaintQuery} | 绘制窗口（60） | 画了谁、省了谁 |
 * | 外部工作 | {@see TrafficQuery} | 网络 500 / 异步 200 | 请求与任务落在哪一帧 |
 * | 结构 | {@see StructureQuery} | 节点注册表 + 几何注解 | 第 N 帧谁在树上 |
 * | 内省 | {@see LiveQuery} | 活树（不是证据） | 现在这个对象是什么样 |
 *
 * 分开的理由不是行数：六个域各有**只对自己成立**的口径（几个窗口宽度不同、
 * 缺省帧该落在哪个窗口、"往前找"对谁成立），混在一个类里最容易被下一个改动的
 * 人按邻居的口径顺手改掉 —— 而每一处这类漂移的表现都是"答案自洽、可读、全错"。
 * 于是**读侧的分界线与写侧（{@see Recorder} 的那几份记录）一一对应**：将来写侧
 * 真要拆，每个域类正好对着一个 store，不用重新切一遍。
 *
 * 留在门面上的只有三件：
 *
 * - **协议面**：23 个签名与 {@see QueryRouter::QUERY_SPECS} 逐参一致（判据用反射钉住）。
 *   门面只分派，不带状态 —— 带状态的门面就是下一轮上帝类的种子。
 * - **跨域组合** {@see self::align()}：一帧里同时取因果 / 几何 / 网络，它必须同时
 *   知道三个域，这正说明它是组合者而不是某一域的主人。
 * - **跨域搜索** {@see self::find()}：给一段文本，回一串**地址**，横跨节点与请求。
 *
 * ## 两个聚合的口径不同，不要合并
 *
 * `hotspots()` 答"谁在写"（只算 `kind=state` 的根因边），`nodes()` 答"谁在重建"
 * （含父传播）。把传播算进根因，排名会全是下游节点；只看根因，又看不出哪个子树
 * 被牵连最重。两条口径的完整理由见 {@see CausalQuery}。
 *
 * @api
 */
final class Query
{
    /**
     * 读面：记录的解释全在 {@see History}（审查面，不进产物）。门面本身**不持有**
     * {@see Recorder} —— 那是写面，门面认识它只为了构造一次读面。
     */
    private readonly History $history;

    private readonly CausalQuery $causal;

    private readonly GeometryQuery $geometry;

    private readonly PaintQuery $paint;

    private readonly TrafficQuery $traffic;

    private readonly StructureQuery $structure;

    private readonly LiveQuery $live;

    public function __construct(Recorder $recorder)
    {
        $history = new History($recorder);
        $this->history = $history;
        $this->causal = new CausalQuery($history);
        $this->geometry = new GeometryQuery($history);
        $this->paint = new PaintQuery($history);
        $this->traffic = new TrafficQuery($history);
        // 结构依赖几何：树行上的盒大小与矩形是几何域的注解，不是结构域自己采的。
        $this->structure = new StructureQuery($history, $this->geometry);
        // 内省要发号（`pick` 命中一个新节点时给它一个地址），所以它还认识写面。
        $this->live = new LiveQuery($recorder, $history);
    }

    /** @return array<string, mixed> 帧数 / 节点数 / 容量 / 最近帧耗时与重建数 */
    public function stats(): array
    {
        return $this->history->stats();
    }

    /**
     * 最近 N 帧的摘要，按帧号升序；`since` 是增量游标。
     *
     * @return array{frames: list<array<string, mixed>>, window: array<string, mixed>, dropped: int, since: int|null}
     *
     * @see CausalQuery::frames()
     */
    public function frames(int $limit = 30, bool $rebuiltOnly = false, ?int $since = null): array
    {
        return $this->causal->frames($limit, $rebuiltOnly, $since);
    }

    /**
     * 单帧展开：谁重建了、根因怎么分布。
     *
     * @return array<string, mixed>
     *
     * @see CausalQuery::explain()
     */
    public function explain(int $frame): array
    {
        return $this->causal->explain($frame);
    }

    /**
     * 某个节点在某一帧为什么重建（帧缺省为最近一帧）。
     *
     * @return array<string, mixed>
     *
     * @see CausalQuery::why()
     */
    public function why(int $node, ?int $frame = null): array
    {
        return $this->causal->why($node, $frame);
    }

    /**
     * 跨帧聚合根因（`kind=state`）：调用点 + State 类。
     *
     * @return list<array<string, mixed>>
     *
     * @see CausalQuery::hotspots()
     */
    public function hotspots(int $frames = 300, int $limit = 10): array
    {
        return $this->causal->hotspots($frames, $limit);
    }

    /**
     * 跨帧聚合节点：重建次数、自身写入与父传播。
     *
     * @return list<array<string, mixed>>
     *
     * @see CausalQuery::nodes()
     */
    public function nodes(int $frames = 300, int $limit = 10): array
    {
        return $this->causal->nodes($frames, $limit);
    }

    /**
     * 单帧因果森林：结构根、同形兄弟折叠、子树规模、state 写入点、按类型构成。
     *
     * @return array<string, mixed>
     *
     * @see CausalQuery::cascade()
     */
    public function cascade(int $frame, int $maxDepth = 5, int $maxChildren = 8): array
    {
        return $this->causal->cascade($frame, $maxDepth, $maxChildren);
    }

    /**
     * 一帧全部盒的几何摘要（只读几何记录，供投影层跨盒比较约束）。
     *
     * @return array{frame: int, rows: list<array<string, mixed>>, window: array<string, mixed>}
     *
     * @see GeometryQuery::boxes()
     */
    public function boxes(?int $frame = null): array
    {
        return $this->geometry->boxes($frame);
    }

    /**
     * 单节点盒模型：margin / border / padding / content 四层与位置。
     *
     * @return array<string, mixed>
     *
     * @see GeometryQuery::boxModel()
     */
    public function boxModel(int $node, ?int $frame = null): array
    {
        return $this->geometry->boxModel($node, $frame);
    }

    /**
     * 单节点约束链：逐跳复算并与快照交叉验证，标出归不到基类默认的跳。
     *
     * @return array<string, mixed>
     *
     * @see GeometryQuery::constraints()
     */
    public function constraints(int $node, ?int $frame = null, int $maxDepth = 12): array
    {
        return $this->geometry->constraints($node, $frame, $maxDepth);
    }

    /**
     * 一帧的绘制事实：重画节点集合、层缓存命中数、各边界重录原因。
     *
     * @return array<string, mixed>
     *
     * @see PaintQuery::paint()
     */
    public function paint(?int $frame = null): array
    {
        return $this->paint->paint($frame);
    }

    /**
     * 跨帧重画聚合：谁每帧都在画、哪些边界反复重录、按原因分布与命中率。
     *
     * @return array<string, mixed>
     *
     * @see PaintQuery::repaints()
     */
    public function repaints(int $frames = 300, int $limit = 10): array
    {
        return $this->paint->repaints($frames, $limit);
    }

    /**
     * 网络请求骨架：请求 → 帧对齐 → 落地节点（不含报文）。
     *
     * @return array{rows: list<array<string, mixed>>, window: array<string, mixed>, total: int}
     *
     * @see TrafficQuery::network()
     */
    public function network(?int $request = null, int $limit = 50): array
    {
        return $this->traffic->network($request, $limit);
    }

    /**
     * 按 requestId 取请求 / 响应报文（脱敏 + 截断，默认不进证据面）。
     *
     * @return array<string, mixed>
     *
     * @see TrafficQuery::networkBody()
     */
    public function networkBody(int $request, string $part = 'both', int $maxBytes = 8 * 1024): array
    {
        return $this->traffic->networkBody($request, $part, $maxBytes);
    }

    /**
     * 异步任务时间线：提交 / 派发 / 结算帧、状态、耗时与落地节点。
     *
     * @return array{rows: list<array<string, mixed>>, window: array<string, mixed>, total: int, frame: int, captured: bool}
     *
     * @see TrafficQuery::async()
     */
    public function async(?int $job = null, int $limit = 50): array
    {
        return $this->traffic->async($job, $limit);
    }

    /**
     * 结构树：**第 N 帧**的树（谁在、谁已消失、谁这一帧重建了）。
     *
     * @return array<string, mixed>
     *
     * @see StructureQuery::tree()
     */
    public function tree(int $root = 0, int $depth = 6, int $limit = 200, ?int $frame = null): array
    {
        return $this->structure->tree($root, $depth, $limit, $frame);
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
     * ## 为什么它留在这里而不是进某个域
     *
     * 它同时认识因果、几何、网络三个域 —— 落进任何一个，"另外两个"就成了那个域的
     * 依赖，依赖方向立刻成环。组合者住在门面上，三个域彼此仍然互不认识。
     *
     * @return array<string, mixed>
     */
    public function align(int $node, int $frame): array
    {
        $known = $this->history->typeOf($node) !== '';
        $base = [
            'node' => $node,
            'frame' => $frame,
            'path' => $this->history->pathOf($node),
        ];
        $win = [
            'frames' => $this->history->stats()['capacity'],
            'geometry' => $this->history->geometryCoverage(),
            'network' => $this->history->networkCoverage(),
        ];
        if (!$known) {
            $reason = $this->history->nodeStatus($node) === 'evicted' ? 'node-evicted' : 'unknown-node';

            return $base + [
                'found' => false,
                'reason' => $reason,
                'causal' => ['found' => false, 'reason' => $reason],
                'geometry' => $this->geometry->miss($node, $frame, $win['geometry']),
                'network' => ['found' => false, 'reason' => $reason, 'window' => $win['network']],
                'windows' => $win,
            ];
        }

        // ---- 因果：显式帧，不走 why 的"最近重建帧"缺省。
        $causal = $this->causal->forNode($node, $frame);

        // ---- 几何：记录自带实际帧号，stale 由它判定，不靠调用方猜。
        // 判窗口与查记录都走 GeometryQuery::lookup()：与另外三个查询同一份语义。
        $hit = $this->geometry->lookup($node, $frame, $win['geometry']);
        $geo = $hit['record'];
        if ($geo === null) {
            $geometry = $hit['reason'] === 'frame-out-of-window'
                ? $this->geometry->outOfWindow($node, $frame, $win['geometry'])
                : $this->geometry->miss($node, $frame, $win['geometry']);
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
        foreach ($this->history->requestsLandingOn($node) as $requestId) {
            $request = $this->history->networkAt($requestId);
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
            foreach ($this->history->nodeIndex() as $id => $_meta) {
                ++$scanned;
                $type = $this->history->typeOf($id);
                if (!$hit($type)) {
                    continue;
                }
                ++$matched;
                $all[] = [
                    'domain' => 'node',
                    'address' => 'node:' . $id,
                    // 路径只对**命中项**算：3000 个节点各拼一遍父链，代价落在这条
                    // 查询最贵的地方，而它只对返回的几十行有用。
                    'text' => $this->history->pathOf($id),
                ];
            }
            $counts['node'] = [
                'scanned' => $scanned,
                'matched' => $matched,
                'returned' => 0,
                'evicted' => \count($this->history->evictedNodes()),
            ];
        }

        if (\in_array('request', $domains, true)) {
            $scanned = 0;
            $matched = 0;
            foreach ($this->history->networkRows() as $record) {
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
                'window' => $this->history->networkCoverage(),
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

    /**
     * 按节点读 Widget / State 配置（dev 反射活树，即时内省、不是证据、不进包）。
     *
     * @param list<\Drift\Widget\Element> $roots
     * @return array<string, mixed>
     *
     * @see LiveQuery::props()
     */
    public function props(int $node, array $roots, int $maxDepth = 3, int $maxFields = 120): array
    {
        return $this->live->props($node, $roots, $maxDepth, $maxFields);
    }

    /**
     * 进程内存与活树对象存活跨度（dev 内省，WeakMap 台账，不是证据、不进包）。
     *
     * @param list<\Drift\Widget\Element> $roots
     * @return array<string, mixed>
     *
     * @see LiveQuery::memory()
     */
    public function memory(array $roots, int $limit = 40): array
    {
        return $this->live->memory($roots, $limit);
    }

    /**
     * 把 site（`file:line`）解释成方法定义位置（dev 反射，即时内省、不是证据）。
     *
     * @return array<string, mixed>
     *
     * @see LiveQuery::source()
     */
    public function source(string $site): array
    {
        return $this->live->source($site);
    }

    /**
     * 按视口坐标点选：命中链（根→最深的盒）+ 每层节点 / Widget / 路径，含浮层原点。
     *
     * @param list<array{element: \Drift\Widget\Element, x: float, y: float}> $roots
     * @return array<string, mixed>
     *
     * @see LiveQuery::pick()
     */
    public function pick(float $x, float $y, array $roots): array
    {
        return $this->live->pick($x, $y, $roots);
    }

    /**
     * 高亮状态：现在高亮谁 / 何时请求 / 最后一次真的上屏在哪帧。
     *
     * @return array<string, mixed>
     *
     * @see LiveQuery::highlight()
     */
    public function highlight(): array
    {
        return $this->live->highlight();
    }
}
