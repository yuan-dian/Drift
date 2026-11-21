<?php
declare(strict_types=1);

namespace App\Showcase\Jobs;

use Drift\App\Io\Http;
use Drift\App\JobArgs;
use Drift\App\NamedJob;

/**
 * 取一页表格行（**后台任务**）—— 示例台异步表格的数据来源。
 *
 * ## 为什么是 `NamedJob`
 *
 * 跨进程只能传"名字 + JSON 参数"（`NamedJob` 的约定），所以本任务的构造入参
 * 必须全部可序列化。`ProcessTaskHost` 在 worker 进程里用注册表按名字重建它。
 *
 * ## 两种数据源（同一个 Job，页面无感）
 *
 * | 模式 | 干什么 | 用途 |
 * |---|---|---|
 * | `local`（默认） | `usleep(1.2s)` + 生成行 | **开箱即用**：零依赖也能演示"慢任务不卡 UI" |
 * | `http` | 真 `Http::get()` 打 mock 后端（同样慢 1.2s） | 演示"真实网络 IO"（socket + 阻塞读 + 超时） |
 *
 * ★★★ **两种模式都在 worker 进程里跑** —— 这是本示例的意义所在：不管"慢"来自
 *   `usleep` 还是慢网络，UI 进程都只做三件事（提交 → 每帧看一眼 → 收到结果重绘），
 *   一帧都不会被拖住。`local` 不是"假异步"：`usleep` 在子进程里，UI 线程同帧
 *   照样跑满 60fps（真机判据见 `probe-*`）。
 *
 * ★ 为什么不用 `getenv` 选模式：`nano-hygiene` 禁环境变量（AOT 不保证），
 *   模式走**构造参数**（`args()` 原样跨进程带过去）—— 组合根决定一次，worker 照着跑。
 *
 * ★ 返回**数组**而不是对象：跨进程只传 JSON 能表达的东西；转 `Table` 行也是
 *   UI 侧的事（`Table` 本来就吃关联数组）。
 */
final class FetchRowsJob implements NamedJob
{
    use JobArgs;

    /** 注册表里的键（UI 与 worker 必须一致） */
    public const NAME = 'showcase.rows';

    /** 顺序 == 构造器参数顺序 */
    private const FIELDS = ['page', 'perPage', 'mode', 'baseUrl'];

    /** 本地模式的模拟耗时（微秒）—— 1.2s，足够让 loading 态被用户看见 */
    private const LOCAL_DELAY_US = 1_200_000;

    /**
     * HTTP 模式的默认后端。
     *
     * 起法：`php -S 127.0.0.1:8098 tools/app-workers/showcase-mock.php`
     * 然后 `DRIFT_SHOWCASE_HTTP=1` 启动示例台（见 `bin/win32/Bootstrap.php`）。
     */
    public const DEFAULT_BASE = 'http://127.0.0.1:8098';

    /** 连接超时（秒）：连不上就该早失败，不要等满总超时 */
    private const CONNECT_TIMEOUT_S = 5;

    /** 总超时（秒）：比 mock 的 1.2s 宽裕，又不至于让 UI 永远等 */
    private const TIMEOUT_S = 10;

    public const MODE_LOCAL = 'local';

    public const MODE_HTTP = 'http';

    public function __construct(
        private readonly int $page = 1,
        private readonly int $perPage = 20,
        private readonly string $mode = self::MODE_LOCAL,
        private readonly string $baseUrl = self::DEFAULT_BASE,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, source: string}
     */
    public function run(): mixed
    {
        if ($this->mode === self::MODE_HTTP) {
            return $this->fetchOverHttp();
        }

        return $this->simulateSlowLocal();
    }

    /**
     * 本地慢任务：先睡 1.2s，再生成这一页的行。
     *
     * ★ 睡在 **worker 进程**里 —— 这正是"业务耗时与 UI 隔离"的最小证明：
     *   主进程在这 1.2s 里照常跑帧（转圈动画在转、窗口能拖）。
     *
     * ★ 行数据来自 `RowsSeed`（与 mock 后端共用一份形状定义），本方法只负责"慢"。
     *
     * @return array{items: list<array<string, mixed>>, total: int, source: string}
     */
    private function simulateSlowLocal(): array
    {
        \usleep(self::LOCAL_DELAY_US);

        $page = RowsSeed::page($this->page, $this->perPage);

        return [
            'items' => $page['items'],
            'total' => $page['total'],
            'source' => '本地慢任务',
        ];
    }

    /**
     * 真 HTTP：打 mock 后端（`php -S 127.0.0.1:8098 apps/showcase/mock-server.php`）。
     *
     * ★ 用 `Http::get()`（框架那一条路）而不是在应用里手写 curl：
     *   超时/状态码/body 截断由 `Drift\App\Io\Http` 一处管，本文件只留"载荷长什么样"。
     * ★ 非 2xx / 解析失败一律抛 —— 页面会进 `error` 态（可重试），不静默。
     *
     * @return array{items: list<array<string, mixed>>, total: int, source: string}
     */
    private function fetchOverHttp(): array
    {
        $url = \rtrim($this->baseUrl, '/')
            . '/api/rows?page=' . $this->page . '&per=' . $this->perPage;

        // ★ 与 `apps/users` 的 `FetchUsersJob` 走**同一条路**（`Drift\App\Io\Http`）。
        //   之前两边各有一份 30 行的 curl（同样的超时、状态码判断、body 截断）
        //   —— 那一份已经删掉，本文件只剩"这个接口的载荷长什么样"这一条业务知识。
        $res = Http::get($url, [
            'headers' => ['Accept' => 'application/json'],
            'connectTimeout' => self::CONNECT_TIMEOUT_S,
            'timeout' => self::TIMEOUT_S,
        ]);
        $data = $res->json();

        if (!\is_array($data) || empty($data['ok'])) {
            throw new \RuntimeException('取表格失败（服务端）：' . $res->snippet());
        }

        $items = $data['items'] ?? [];
        $total = $data['total'] ?? 0;

        return [
            'items' => \is_array($items) ? $items : [],
            'total' => (int) $total,
            // ★ 往返耗时来自 `HttpResponse::elapsedMs()`：示例台把"真的走了网络"写在
            //   `source` 里，用户一眼能看出这一页是 HTTP 还是本地造的（`loadMore` 的
            //   页脚会显示它）。
            'source' => 'HTTP ' . $this->baseUrl . '（' . \round($res->elapsedMs()) . 'ms）',
        ];
    }
}
