<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

use Drift\Debug\Protocol\ProtocolError;
use Drift\Debug\Session\SessionQueries;
use Drift\Debug\Session\Thresholds;

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
    ) {
    }

    /**
     * `/api/brief` 与 `debug-brief --json` 共用的载荷。
     *
     * 共用而不是各拼一遍：两处拼装就是两个"同一份证据"的定义，判据 1 也就只能
     * 比到"差不多"。
     *
     * @param array<string, mixed> $facts
     * @param array{findings: list<Finding>, coverage: array<string, mixed>, thresholds: array<string, float|int>} $projection
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
            '/api/brief' => $this->briefResponse(),
            '/api/state' => $this->json($this->queries->query('state')),
            '/api/script' => $this->json($this->queries->query('script')),
            '/api/frames' => $this->json($this->queries->query('frames', [
                'since' => (int) ($query['since'] ?? 0),
                'limit' => (int) ($query['limit'] ?? 200),
            ])),
            '/api/boxes' => $this->json($this->queries->query('boxes', [
                'frame' => (int) ($query['frame'] ?? 0) ?: null,
            ])),
            '/api/node' => $this->node($query),
            default => $this->error(404, 'not_found', "没有这个路径：{$path}"),
        };
    }

    /** @return array{status: int, type: string, body: string} */
    private function briefResponse(): array
    {
        $facts = Facts::gather($this->queries, $this->label);

        return $this->json(self::brief($this->label, $facts, Brief::project($facts, $this->thresholds)));
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
            return $this->json(($this->control)($action, $body));
        } catch (ProtocolError $e) {
            // 从属宿主上 `pause` 是合法请求但做不到：这是 501 不是 400。
            return $this->error(
                $e->errorCode === ProtocolError::NOT_APPLICABLE ? 501 : 400,
                $e->errorCode,
                $e->getMessage(),
            );
        }
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
