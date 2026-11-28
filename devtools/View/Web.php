<?php
declare(strict_types=1);

namespace Drift\DevTools\View;

use Drift\DevTools\Json;
use Drift\DevTools\Protocol\ProtocolError;
use Drift\DevTools\Protocol\RemoteSession;
use Drift\DevTools\Session\SessionQueries;
use Drift\Debug\Session\Thresholds;
use Drift\DevTools\View\Rules\RuleSelection;
use Drift\DevTools\View\Rules\Severity;

/**
 * Web 视图的服务端：路由 + JSON 应答。**不含 HTML / CSS / JS**（在 `web/` 下）。
 *
 * ## 它不加工任何东西
 *
 * `/api/*` 的响应体一律是查询层的原样数组加个信封，不改名、不排序、不算派生值。
 * 任何一处加工都会成为**第四份真相**：终端一份、机器一份、页面一份、再加一份
 * "服务端贴心整理过的"。而分叉的表现是"页面上 42ms，报告里 42.05ms"，没人能
 * 判断哪份是错的。
 *
 * 唯一的例外是 `/api/node`：它把 `boxModel` 与 `props` 并放进同一个信封。两个
 * 查询各自原样，合并只是省一次往返 —— 不是派生。
 *
 * ## 为什么阈值要随载荷走
 *
 * `thresholds` 出现在 `/api/brief` 里，页面显示阈值时只读它。页面若自己写一个
 * `16.6`，那么"页面说慢帧线是 16.6"与"服务端按 5ms 判"会同时成立，而两边都
 * 不像错的。B4 §7：页面不许有第二个数字。
 *
 * @internal 证据层内部；`devtools/bin/debug-web.php` 是它唯一的调用方
 */
final class Web
{
    /** 启动时定下的规则选择；查询串可以再覆盖（见 {@see selectionWith()}）。 */
    private readonly RuleSelection $selection;

    /**
     * @param null|\Closure(string, array<string, mixed>): array<string, mixed> $control
     *   `pause` / `resume` / `highlight` / `pick` 的落地：`(动作, 参数)` 进，回执数组出。
     *   `null` = 这个会话没有干预面（离线读包 / 从属宿主），`/api/ctl` 一律 501。
     * @param ?RemoteSession $events
     *   `/api/events` 的事件源。`null` = 这个会话没有事件源（离线读包 / 自己跑的会话），
     *   那时流里只推 `tick`。**不能靠"试一下有没有事件"来判**：EventSource 拿到 501
     *   会当成网络错误反复重连，而"没有事件源"是正常状态不是故障。
     */
    public function __construct(
        private readonly SessionQueries $queries,
        private readonly string $label,
        private readonly Thresholds $thresholds,
        private readonly string $root,
        private readonly ?\Closure $control = null,
        ?RuleSelection $selection = null,
        private readonly ?string $evidenceDir = null,
        private readonly ?string $sessionId = null,
        private readonly ?RemoteSession $events = null,
    ) {
        $this->selection = $selection ?? RuleSelection::all();
    }

    /**
     * `/api/brief` 与 `debug-brief --json` 共用的载荷。
     *
     * 共用而不是各拼一遍：两处拼装就是两个"同一份证据"的定义，判据 1 也就只能
     * 比到"差不多"。
     *
     * @param array<string, mixed> $facts
     * @param array{findings: list<Finding>, coverage: array<string, mixed>, thresholds: array<string, float|int>, selection?: array<string, mixed>} $projection
     * @return array<string, mixed>
     */
    public static function brief(string $label, array $facts, array $projection): array
    {
        return [
            'label' => $label,
            'window' => $facts['window'] ?? [],
            'digest' => $facts['digest']['digest'] ?? '',
            // 最贵帧由投影给（facts 里 `explain` 是照着它取的），页面不自己找 max：
            // 找了就是第二份"哪一帧最值得看"的判据，而它与 findings 的口径不一定一致。
            'worst' => (int) ($facts['explain']['id'] ?? 0),
            'thresholds' => $projection['thresholds'],
            // 页面要显示"这份报告只跑了两条规则"，否则 `--rule=` 的报告
            // 与完整报告在页面上长得一模一样。
            'selection' => $projection['selection'] ?? [],
            'coverage' => $projection['coverage'],
            'findings' => \array_map(
                static fn(Finding $one): array => $one->toArray(),
                $projection['findings'],
            ),
        ];
    }

    /**
     * @param array<string, mixed> $query 已解析的查询串
     * @param array<string, mixed> $body  POST 的 JSON 体（只 `/api/ctl` 用）
     *
     * @return array{status: int, type: string, body?: string, stream?: \Closure(): ?string}
     *   `body` 是一次性响应；`stream` 是流式（只有 `/api/events` 走这条）。
     */
    public function route(string $method, string $path, array $query = [], array $body = []): array
    {
        if ($method === 'POST' && $path === '/api/ctl') {
            return $this->control($body);
        }

        if ($method !== 'GET') {
            return $this->error(405, 'method_not_allowed', "不支持 {$method}");
        }

        return match ($path) {
            '/', '/index.html' => $this->asset('index.html', 'text/html; charset=utf-8'),
            '/app.js' => $this->asset('app.js', 'text/javascript; charset=utf-8'),
            '/style.css' => $this->asset('style.css', 'text/css; charset=utf-8'),
            '/api/brief' => $this->briefResponse($query),
            '/api/state' => $this->json($this->queries->query('state')),
            // 协议目录走同一个查询面：离线读包也能问。页面只在页眉显示版本，
            // 具体有哪些查询由 CLI 的 capabilities 出口给人看，不在 Web 里再列一份。
            '/api/capabilities' => $this->json($this->queries->query('capabilities')),
            // 选中只有宿主那一份真相。页面自己的高亮只是它的镜像，轮询这里校正；
            // 若让页面自己记"我选了谁"，真窗口拾取和别的观察者改的选中永远传不进来。
            '/api/highlight' => $this->json($this->selection()),
            '/api/script' => $this->json($this->queries->query('script')),
            '/api/frames' => $this->json($this->queries->query('frames', [
                'since' => (int) ($query['since'] ?? 0),
                'limit' => (int) ($query['limit'] ?? 200),
            ])),
            '/api/boxes' => $this->json($this->queries->query('boxes', [
                'frame' => (int) ($query['frame'] ?? 0) ?: null,
            ])),
            // 绘制与几何并列而不是塞进 `/api/boxes`：一个盒"在哪、多大"与"这一帧
            // 画没画它"是两个问题，合进一个响应会让页面拿不到 `captured=false`
            // 这种"没采"与"没画"的区别。
            '/api/paint' => $this->json($this->queries->query('paint', [
                'frame' => (int) ($query['frame'] ?? 0) ?: null,
            ])),
            // `repaints` 没有 HTTP 路由：跨帧重画聚合是**规则**的口径（`repaint-storm`
            // / `layer-miss` 经 `Facts` 取它），页面上要看的已经由 `/api/paint` 按帧
            // 给全。曾经挂过 `/api/repaints`，前端一次都没调 —— 死路由由
            // `tools/check-web-routes.php` 守。
            // 网络泳道与请求详情走同一个查询面；列表只给骨架，报文仍要显式问。
            '/api/network' => $this->json($this->queries->query('network', [
                'limit' => (int) ($query['limit'] ?? 100),
            ])),
            '/api/async' => $this->json($this->queries->query('async', [
                'job' => (int) ($query['job'] ?? 0) ?: null,
                'limit' => (int) ($query['limit'] ?? 100),
            ])),
            // 树与节点卡分工：树只给可导航的扁平先序行，几何已经按同一帧贴好；
            // 点中一行再问 /api/node 拿盒模型 / 约束 / 因果，不在前端拼第二棵树。
            '/api/tree' => $this->json($this->queries->query('tree', [
                'root' => (int) ($query['root'] ?? 0),
                'depth' => (int) ($query['depth'] ?? 6),
                'limit' => (int) ($query['limit'] ?? 200),
                'frame' => (int) ($query['frame'] ?? 0) ?: null,
            ])),
            // 两帧结构对比是**事件流**：不拿两次 /api/tree 在前端相减（那两份响应
            // 已被 limit 截断，相减会把截断当成"没变"）。查询层直接给出生 / 离开 /
            // 重建，前端只画；区间左开右闭，base 用 0 表示从录制开始。
            '/api/tree-diff' => $this->json($this->queries->query('treeDiff', [
                'base' => (int) ($query['base'] ?? 0),
                'frame' => (int) ($query['frame'] ?? 0) ?: null,
                'limit' => (int) ($query['limit'] ?? 200),
            ])),
            // 报文是骨架的可选附件，默认不抓；空表与"没抓"必须分开，所以单开一个出口。
            '/api/body' => $this->body($query),
            '/api/node' => $this->node($query),
            // 搜索命中 request:N 后的落地页。请求详情走同一个查询面，不另存一份。
            '/api/request' => $this->request($query),
            // 画面与证据是两条通道：`/api/shot?serial=N` 吐的是宿主拍的真机像素（PNG），
            // 只用于垫底。`/api/shot/ping` 是心跳 + 问"最新那张是第几张、拍的哪一帧"，
            // 前端靠它决定要不要换图 —— 拿不到新图就不换，而不是换成空。
            '/api/shot' => $this->shot($query),
            '/api/shot/ping' => $this->shotPing(),
            // 事件流（A80）。这是唯一一个**不结束**的响应：头写完就一直挂着，
            // 由 `HttpServer` 的 tick 反复问"现在有什么"。
            '/api/events' => $this->events(),
            // 搜索与节点卡是同一个循环的两半：先按文本拿到地址，再按地址取事实。
            // 页面不自己过滤 `/api/boxes` 的结果 —— 那会在前端重写一遍匹配规则，
            // 而"同一次搜索三种实现"正是这一层要避免的。
            '/api/find' => $this->find($query),
            default => $this->error(404, 'not_found', "没有这个路径：{$path}"),
        };
    }

    /** @return array{status: int, type: string, body: string} */
    private function briefResponse(array $query): array
    {
        $selection = $this->selectionWith($query);
        if ($selection === null) {
            $raw = (string) ($query['minSeverity'] ?? '');

            return $this->error(400, 'bad_request', "未知严重度：{$raw}；可用：info / warning / error");
        }

        $facts = Facts::gather($this->queries, $this->label);

        return $this->json(self::brief(
            $this->label,
            $facts,
            Brief::project($facts, $this->thresholds, $selection),
        ));
    }

    /**
     * 查询串覆盖启动时的规则选择：`?rule=slow-frame,fanout&minSeverity=warning`。
     *
     * 返回 `null` 表示严重度拼错了。不在 `Severity::parse()` 里抛异常，是因为
     * 同一个函数也服务于命令行 —— 那里 `exit(2)` 才是对的，而这里要 400。
     *
     * @param array<string, mixed> $query
     */
    private function selectionWith(array $query): ?RuleSelection
    {
        $minRaw = $query['minSeverity'] ?? null;
        $min = null;
        if (\is_string($minRaw) && $minRaw !== '') {
            $min = Severity::parse($minRaw);
            if ($min === null) {
                return null;
            }
        }

        $only = self::csv($query['rule'] ?? null);
        $without = self::csv($query['without'] ?? null);
        if ($only === [] && $without === [] && $min === null) {
            return $this->selection;
        }

        return new RuleSelection($only, $without, $min);
    }

    /**
     * `rule=a,b` 与 `rule[]=a&rule[]=b` 都接受。
     *
     * @return list<string>
     */
    private static function csv(mixed $raw): array
    {
        $items = \is_array($raw) ? $raw : ($raw === null ? [] : [$raw]);
        $out = [];
        foreach ($items as $one) {
            if (!\is_string($one)) {
                continue;
            }
            foreach (\explode(',', $one) as $piece) {
                $piece = \trim($piece);
                if ($piece !== '') {
                    $out[] = $piece;
                }
            }
        }

        return \array_values(\array_unique($out));
    }

    /**
     * 一个节点的事实卡：一次取全，每个 section 自己报可用性。
     *
     * 同时保留旧的顶层键（geometry / layout / properties / causality），让现有页面
     * 不必在这一次改动里重写；新代码只读 `sections`。
     *
     * @param array<string, mixed> $query
     * @return array{status: int, type: string, body: string}
     */
    private function node(array $query): array
    {
        $node = (int) ($query['node'] ?? 0);
        if ($node <= 0) {
            return $this->error(400, 'bad_request', '缺少 node 参数（节点 id 从 /api/boxes 的 node 字段取）');
        }

        $sections = $query['sections'] ?? '';
        if (\is_array($sections)) {
            $sections = \implode(',', \array_map('\strval', $sections));
        }

        try {
            $context = $this->queries->query('nodeContext', [
                'node' => $node,
                'frame' => (int) ($query['frame'] ?? 0) ?: null,
                'sections' => (string) $sections,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->error(400, 'bad_request', $e->getMessage());
        }

        $sections = (array) ($context['sections'] ?? []);

        return $this->json([
            'node' => $node,
            'frame' => (int) ($context['frame'] ?? 0),
            'sections' => $sections,
            'boxModel' => $this->sectionData($sections, 'geometry'),
            'props' => $this->sectionData($sections, 'properties'),
            'constraints' => $this->sectionData($sections, 'layout'),
            'why' => $this->sectionData($sections, 'causality'),
        ]);
    }

    /** @param array<string, array<string, mixed>> $sections */
    private function sectionData(array $sections, string $name): array
    {
        $one = $sections[$name] ?? null;
        if (!\is_array($one)) {
            return ['found' => false, 'reason' => 'section-missing'];
        }
        if (($one['status'] ?? '') === 'error') {
            return ['error' => (string) ($one['reason'] ?? 'section-error')];
        }
        if (($one['status'] ?? '') === 'ok') {
            return \is_array($one['data'] ?? null) ? $one['data'] : ['found' => false];
        }

        $miss = \is_array($one['data'] ?? null) ? $one['data'] : [];

        return ['found' => false, 'reason' => (string) ($one['reason'] ?? $one['status'] ?? 'not-found')] + $miss;
    }

    /** @param array<string, mixed> $query */
    private function request(array $query): array
    {
        $id = (int) ($query['request'] ?? 0);
        if ($id <= 0) {
            return $this->error(400, 'bad_request', '缺少 request 参数（从搜索结果的 request:N 取）');
        }

        return $this->json($this->tryQuery('network', ['request' => $id]));
    }

    /** 按 requestId 取报文附件；`bodies-not-captured` 与"抓了但没这条"由查询层区分。 */
    private function body(array $query): array
    {
        $id = (int) ($query['request'] ?? 0);
        if ($id <= 0) {
            return $this->error(400, 'bad_request', '缺少 request 参数（从请求详情取）');
        }

        return $this->json($this->tryQuery('networkBody', [
            'request' => $id,
            'part' => (string) ($query['part'] ?? 'both'),
            'maxBytes' => (int) ($query['maxBytes'] ?? 8192),
        ]));
    }

    /**
     * 在证据里搜：`?pattern=table&domain=node&limit=20`。
     *
     * 参数错（空 pattern / 未知域）回 400 并说清可用值 —— 回一个空结果会让页面
     * 显示"没有命中"，而真相是"这个请求根本没被接受"。
     *
     * @param array<string, mixed> $query
     * @return array{status: int, type: string, body: string}
     */
    private function find(array $query): array
    {
        $pattern = $query['pattern'] ?? null;
        if (!\is_string($pattern) || \trim($pattern) === '') {
            return $this->error(400, 'bad_request', '缺少 pattern 参数（字面子串，不做正则）');
        }

        $domain = $query['domain'] ?? 'all';
        $case = $query['case'] ?? '';
        $args = [
            'pattern' => $pattern,
            'domain' => \is_string($domain) && $domain !== '' ? $domain : 'all',
            'limit' => (int) ($query['limit'] ?? 50),
            'caseSensitive' => $case === '1' || $case === 'true',
        ];

        try {
            return $this->json($this->queries->query('find', $args));
        } catch (\InvalidArgumentException $e) {
            return $this->error(400, 'bad_request', $e->getMessage());
        }
    }

    /**
     * 查询失败不拖垮整张卡片：一个节点的属性读不到（反射拒了），几何仍然有用。
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function tryQuery(string $name, array $args): array
    {
        try {
            return $this->queries->query($name, $args);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, type: string, body: string}
     */
    private function control(array $body): array
    {
        if ($this->control === null) {
            return $this->error(501, ProtocolError::NOT_APPLICABLE, '这个会话没有驱动面（离线读包或从属宿主）');
        }

        $action = (string) ($body['action'] ?? '');
        if (!\in_array($action, ['pause', 'resume', 'highlight', 'pick'], true)) {
            return $this->error(400, 'bad_request', 'action 只能是 pause / resume / highlight / pick');
        }
        if ($action === 'highlight' && (!\is_int($body['node'] ?? null) || $body['node'] < 0)) {
            return $this->error(400, 'bad_request', 'highlight 需要非负整数 node（0 = 清除）');
        }
        if ($action === 'pick' && !\is_bool($body['enabled'] ?? null)) {
            return $this->error(400, 'bad_request', 'pick 需要布尔 enabled');
        }

        try {
            $result = ($this->control)($action, $body);
        } catch (ProtocolError $e) {
            // 从属宿主上 `pause` 是合法请求但做不到：这是 501 不是 400。
            return $this->error(
                $e->errorCode === ProtocolError::NOT_APPLICABLE ? 501 : 400,
                $e->errorCode,
                $e->getMessage(),
            );
        }

        // 单步（`resume` 带 `frames`）要等到**真的停下**才回执。
        // `resume` 的即时回执是 `running`，而用户按的是"走一步"——回一个 `running`
        // 会让他以为没生效，再按一次就成了两步。同 `debug ctl` 的出口口径。
        if ($action === 'resume' && isset($body['frames'])) {
            $deadline = \microtime(true) + 2.0;
            while (\microtime(true) < $deadline) {
                $state = $this->queries->query('state');
                if (($state['state'] ?? '') !== 'running') {
                    return $this->json($state);
                }
                \usleep(5000);
            }
        }

        return $this->json($result);
    }

    /**
     * 选中真相 + 这个会话能不能写它。
     *
     * `controllable` 复用驱动面的有无：真窗口拾取与高亮都只对活宿主有意义，
     * 而能 pause/resume 的会话正是这类宿主。离线包读得到选中，但改不了。
     *
     * @return array<string, mixed>
     */
    private function selection(): array
    {
        return [
            'highlight' => $this->tryQuery('highlight', []),
            'controllable' => $this->control !== null,
        ];
    }

    /**
     * 宿主拍的真机像素（PNG）—— web 的底图。
     *
     * 命中顺序是「请求的那一帧 → 最新一张 → 没有」。**不往回调成"最近的可用帧"**：
     * 图形与几何必须同一帧，凑一张旧图上去会让"框画在 A、图是 B"这种错位看起来
     * 像布局错误。没有就空着，页面自己说"还没截到"。
     *
     * @param array<string, mixed> $query
     * @return array{status: int, type: string, body: string}
     */
    private function shot(array $query): array
    {
        $dir = $this->shotRoot();
        if ($dir === null) {
            return $this->error(404, 'no_shot', '这个会话没有画面通道（离线读包 / 从属宿主）');
        }

        // 按**快照序号**取图，不按帧号：序号是"第几张画面"，帧号是"主循环跑过几轮"。
        // 拿帧号当图片地址，同一张图会被不同帧号请求很多次，而换图那一刻又可能撞号。
        $serial = (int) ($query['serial'] ?? 0);
        $file = $serial > 0 ? $dir . '/s' . $serial . '.png' : $dir . '/latest.png';
        if (!\is_file($file)) {
            $file = $dir . '/latest.png';
        }
        if (!\is_file($file)) {
            return $this->error(404, 'no_shot', '还没截到帧：宿主没有在拍（或还没画过一帧）');
        }

        $body = @\file_get_contents($file);
        if ($body === false) {
            return $this->error(500, 'shot_read_failed', '读图失败');
        }

        return ['status' => 200, 'type' => 'image/png', 'body' => $body];
    }

    /**
     * `/api/events`：SSE 流。**唯一一个不结束的响应。**
     *
     * ## 两种事件，一个驱动方式
     *
     * - `frame`：宿主真的推进了一帧（来自 `RemoteSession::waitEvents()`）。这是
     *   真推送 —— 页面第一次不用猜"什么时候该看一眼"。
     * - `tick`：**服务端**按节奏推的"该看一眼了"。没有事件源时（离线读包 / 自己跑
     *   的会话）它就是唯一的信号，节奏与原来 `setInterval(poll, 500)` 一致；有事件源
     *   时它退成 2 秒一次的兜底 —— 状态可能变了却没有帧（暂停、跑完），而"暂停了"
     *   这件事不该等到下一帧才知道。
     *
     * 把轮询从浏览器搬到服务端，买的是**页面只有一种驱动方式**：前端不用写
     * "有事件源走这条路、没有走那条"的分支，也就不存在两条路各自漂。
     *
     * ## `tick` 不是协议事件
     *
     * 它不进 `Events::ALL`（那是宿主 ↔ 客户端的协议目录），只是 Web 这一层给页面
     * 的保活信号。混进协议目录会让"宿主能发什么"和"这个页面多久醒一次"变成同一份
     * 名单，而它们的变更理由完全不同。
     *
     * @return array{status: int, type: string, stream: \Closure(): ?string}
     */
    private function events(): array
    {
        $live = $this->events;
        $last = 0.0;
        // 有事件源时 2 秒兜底一次（状态变化不一定伴随帧）；没有就按原来的 500ms。
        $gap = $live === null ? 0.5 : 2.0;

        return [
            'status' => 200,
            'type' => 'text/event-stream; charset=utf-8',
            'stream' => static function () use ($live, $gap, &$last): ?string {
                if ($live !== null) {
                    $events = $live->waitEvents(0);
                    if ($events !== []) {
                        $out = '';
                        foreach ($events as $one) {
                            $out .= 'event: ' . $one['event'] . "\n"
                                . 'id: ' . $one['seq'] . "\n"
                                . 'data: ' . Json::encode($one['data']) . "\n\n";
                        }

                        return $out;
                    }
                }

                $now = \microtime(true);
                if ($now - $last < $gap) {
                    return null;
                }
                $last = $now;

                return "event: tick\ndata: {}\n\n";
            },
        ];
    }

    /**
     * 心跳 + 「最新那张是第几张、拍的哪一帧」。
     *
     * 心跳走这里而不是靠 `/api/shot` 自己被请求：页面只在序号变了才换图，静止的
     * 应用没有新快照 ⇒ 不请求图 ⇒ 心跳断 ⇒ 宿主以为没人看，把画面通道关了。那时
     * 用户一动窗口就"图不更新"，而它本来是活的。
     *
     * @return array{status: int, type: string, body: string}
     */
    private function shotPing(): array
    {
        $dir = $this->shotRoot();
        if ($dir === null) {
            return $this->json(['shot' => null, 'at' => null, 'channel' => false]);
        }

        // 撑开心跳窗口（宿主按 mtime + TTL 判断有没有人看）。
        @\touch($dir . '/want');

        $serial = null;
        $frame = null;
        $at = null;
        $meta = @\file_get_contents($dir . '/shot.json');
        if ($meta !== false) {
            $decoded = \json_decode($meta, true);
            if (\is_array($decoded)) {
                // 两个数一起给：`shot` 决定换不换图，`frame` 决定取哪一帧的几何。
                // 分开问就会拼出"图是第 3 张、几何是第 5 帧"的错位。
                $serial = $decoded['serial'] ?? null;
                $frame = $decoded['frame'] ?? null;
                $at = $decoded['at'] ?? null;
            }
        }

        return $this->json([
            'shot' => $serial,
            'frame' => $frame,
            'at' => $at,
            'channel' => true,
        ]);
    }

    /** `.drift/shot/<sessionId>`（不存在就建）—— 与宿主 `Shot` 用的是同一个约定路径。 */
    private function shotRoot(): ?string
    {
        if ($this->evidenceDir === null) {
            return null;
        }

        $dir = self::shotDirFor($this->evidenceDir, $this->sessionId);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0775, true);
        }

        return \is_dir($dir) ? $dir : null;
    }

    /** 与 {@see \Drift\DevTools\Host\Shot::dirFor()} 同一套路径约定，web 与宿主不能各拼一遍。 */
    public static function shotDirFor(string $dir, ?string $sessionId): string
    {
        $root = $dir . '/shot';
        if ($sessionId === null || $sessionId === '') {
            return $root;
        }

        return $root . '/' . $sessionId;
    }

    /**
     * 静态资源只认白名单名字。
     *
     * 比"拒绝 `..`"硬：不存在的扩展名、编码过的路径、大小写变体，全部在正则这一步
     * 就断掉，不依赖后面的路径拼接正确。
     *
     * @return array{status: int, type: string, body: string}
     */
    private function asset(string $name, string $type): array
    {
        if (!\preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $name)) {
            return $this->error(404, 'not_found', '非法资源名');
        }

        $file = $this->root . '/' . $name;
        $body = \is_file($file) ? \file_get_contents($file) : false;
        if ($body === false) {
            return $this->error(404, 'not_found', "资源不存在：{$name}");
        }

        return ['status' => 200, 'type' => $type, 'body' => $body];
    }

    /** @return array{status: int, type: string, body: string} */
    private function json(mixed $data): array
    {
        return [
            'status' => 200,
            'type' => 'application/json; charset=utf-8',
            'body' => (string) \json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
        ];
    }

    /** @return array{status: int, type: string, body: string} */
    private function error(int $status, string $code, string $message): array
    {
        return [
            'status' => $status,
            'type' => 'application/json; charset=utf-8',
            'body' => (string) \json_encode(
                ['error' => $code, 'message' => $message],
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
            ),
        ];
    }
}
