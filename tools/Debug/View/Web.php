<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

use Drift\Debug\Protocol\ProtocolError;
use Drift\Debug\Session\SessionQueries;
use Drift\Debug\Session\Thresholds;
use Drift\Dev\Debug\View\Rules\RuleSelection;
use Drift\Dev\Debug\View\Rules\Severity;

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
 * @internal 证据层内部；`tools/debug-web.php` 是它唯一的调用方
 */
final class Web
{
    /** 启动时定下的规则选择；查询串可以再覆盖（见 {@see selectionWith()}）。 */
    private readonly RuleSelection $selection;

    /**
     * @param null|\Closure(string, array<string, mixed>): array<string, mixed> $control
     *   `pause` / `resume` 的落地：`(动作, 参数)` 进，回执数组出。`null` = 这个会话
     *   没有驱动面（离线读包 / 从属宿主），`/api/ctl` 一律 501。
     */
    public function __construct(
        private readonly SessionQueries $queries,
        private readonly string $label,
        private readonly Thresholds $thresholds,
        private readonly string $root,
        private readonly ?\Closure $control = null,
        ?RuleSelection $selection = null,
        private readonly ?string $evidenceDir = null,
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
     * @return array{status: int, type: string, body: string}
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
            '/api/repaints' => $this->json($this->queries->query('repaints', [
                'limit' => (int) ($query['limit'] ?? 200),
            ])),
            '/api/node' => $this->node($query),
            // 画面与证据是两条通道：`/api/shot` 吐的是宿主拍的真机像素（PNG），
            // 只用于垫底。`/api/shot/ping` 是心跳 + 问"最新那张是哪一帧"，
            // 前端靠它决定要不要换图 —— 拿不到新图就不换，而不是换成空。
            '/api/shot' => $this->shot($query),
            '/api/shot/ping' => $this->shotPing(),
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
     * 一个节点的事实卡：几何 + 属性。
     *
     * 页面上点一下要能回答"这是谁、多大、为什么这么宽"，三样分别来自 `boxModel`
     * 与 `props`。分两次请求会让"选中"这个动作要等两个往返，而中间那一刻页面
     * 显示的是上一帧的尺寸。
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

        $frame = (int) ($query['frame'] ?? 0) ?: null;
        $args = $frame === null ? ['node' => $node] : ['node' => $node, 'frame' => $frame];

        return $this->json([
            'node' => $node,
            'frame' => $frame,
            'boxModel' => $this->tryQuery('boxModel', $args),
            'props' => $this->tryQuery('props', $args),
        ]);
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
        if ($action !== 'pause' && $action !== 'resume') {
            return $this->error(400, 'bad_request', 'action 只能是 pause 或 resume');
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

        $frame = (int) ($query['frame'] ?? 0);
        $file = $frame > 0 ? $dir . '/f' . $frame . '.png' : $dir . '/latest.png';
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
     * 心跳 + 「最新那张是哪一帧」。
     *
     * 心跳走这里而不是靠 `/api/shot` 自己被请求：页面只在帧变了才换图，静止的应用
     * 没有新帧 ⇒ 不请求图 ⇒ 心跳断 ⇒ 宿主以为没人看，把画面通道关了。那时候用户
     * 一动窗口就"图不更新"，而它本来是活的。
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

        $frame = null;
        $at = null;
        $meta = @\file_get_contents($dir . '/shot.json');
        if ($meta !== false) {
            $decoded = \json_decode($meta, true);
            if (\is_array($decoded)) {
                $frame = $decoded['frame'] ?? null;
                $at = $decoded['at'] ?? null;
            }
        }

        return $this->json(['shot' => $frame, 'at' => $at, 'channel' => true]);
    }

    /** `.drift/shot`（不存在就建）—— 与宿主 `Shot` 用的是同一个约定路径。 */
    private function shotRoot(): ?string
    {
        if ($this->evidenceDir === null) {
            return null;
        }

        $dir = $this->evidenceDir . '/shot';
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0775, true);
        }

        return \is_dir($dir) ? $dir : null;
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
