<?php
declare(strict_types=1);

namespace Drift\App\Io;

use Drift\App\HttpRequestJob;
use Drift\App\JobRunner;
use Drift\App\TaskHost;
use Drift\Async\Future;

/**
 * 异步 HTTP 门面 —— 应用侧唯一的异步 HTTP 入口，返回 Future<HttpResponse>。
 *
 * 与阻塞的 `Http` 的关系：`Http` 只能在 Job::run() 里用（worker 进程），本类让
 * UI 侧也能发请求，且两条路径共用 `Http::prepare()` / `Http::finish()` —— 语义
 * （校验 / TLS / 头 / 错误分类）零分叉。
 *
 * ## 自动分流（门面内部决定，应用侧一个 API）
 * - 小请求（无 multipart、body ≤ 64KB）→ 帧内 curl_multi：不占 worker、不用配池大小。
 * - 大请求（multipart 或 body > 64KB）→ 进程池的 `HttpRequestJob`：流式上传的 fd
 *   与进度回调留在 worker，不顶 UI 帧。
 *
 * ## 心智负担
 * 对开发者仍是"`get()` 返回 Future，then / AsyncScope::await 拿 HttpResponse"，
 * 分流与后端选择全在门面内部 —— 不引入第二套协程、不暴露两个客户端类。
 *
 * @api
 */
final class AsyncHttp
{
    /** 大于这个 body 字节数走进程池（64KB：帧内只放得下小 JSON） */
    public const int INLINE_BODY_LIMIT = 64 * 1024;

    private HttpTransport $transport;

    private ?TaskHost $host;

    /**
     * @param null|TaskHost $host 大请求分流用的后台端口；`null` = 当前栈同步跑
     *        （解释器 / 单测；与 `JobRunner` 的 null 语义一致，不是"拒绝"）
     * @param null|HttpTransport $transport 默认 `CurlMultiTransport`；可注入以便单测
     *        或将来切换后端（Swoole 见 `HttpTransport` 接口的裁决说明）
     */
    public function __construct(?TaskHost $host = null, ?HttpTransport $transport = null)
    {
        $this->host = $host;
        $this->transport = $transport ?? new CurlMultiTransport();
    }

    /** GET（最常用） */
    public function get(string $url, array $opts = []): Future
    {
        return $this->request('GET', $url, $opts);
    }

    /** POST（body 走第二个参数，与 `Http::post()` 同款） */
    public function post(string $url, string $body = '', array $opts = []): Future
    {
        if (isset($opts['body'])) {
            throw new \InvalidArgumentException('AsyncHttp::post() 的 body 走第二个参数，$opts 里不要再给 body');
        }

        return $this->request('POST', $url, $opts + ['body' => $body]);
    }

    /**
     * 通用入口：返回一条 Future<HttpResponse>。
     *
     * opts 与 `Http::request()` 完全同款（见那里），拼错的键同样当场抛。
     *
     * @param array<string, mixed> $opts
     */
    public function request(string $method, string $url, array $opts = []): Future
    {
        // 校验在分流之前：拼错的键与"走哪个后端"无关，门面统一挡下。名单只在 Http 一份。
        Http::assertOptsKeys($opts);
        // onProgress 只在 multipart 有意义（普通 body 是一次性发出的，没有中途观察点）。
        // 门面也挡：否则大 body 非 multipart 会 unset 掉回调、静默没有进度。
        if (isset($opts['onProgress']) && !isset($opts['fields']) && !isset($opts['files'])) {
            throw new \InvalidArgumentException('AsyncHttp：onProgress 只在 multipart（fields / files）时可用 —— 普通 body 没有中途进度');
        }

        if ($this->needsWorker($opts)) {
            return $this->submitWorker($method, $url, $opts);
        }

        return $this->transport->submit($method, $url, $opts);
    }

    /**
     * 换后台端口（AppScope::setTaskHost 转调）—— 更新**同一实例**。
     *
     * ★ 不换实例是硬约束：PumpHost 在 attach 时缓存门面引用，换实例会让
     *   "submit 走新后端、pump 走旧后端"分叉，Future 永不结算。
     */
    public function setTaskHost(?TaskHost $host): void
    {
        $this->host = $host;
    }

    /**
     * 换传输后端（AppScope::setHttpTransport 转调）—— 同样只换**同一实例**内的后端。
     *
     * ★ 必须在无在途请求时换：旧 multi 里的请求只由旧后端 pump，换掉就挂死。
     */
    public function setTransport(HttpTransport $transport): void
    {
        if ($this->transport->inFlight() > 0) {
            throw new \LogicException('AsyncHttp：还有在途请求，不能换传输后端（旧后端的在途请求将无人 pump）');
        }
        $this->transport = $transport;
    }

    /** 当前传输后端（测试 / devtools 读在途数用） */
    public function transport(): HttpTransport
    {
        return $this->transport;
    }

    /** 每帧推进帧内请求 —— 由 PumpHost 在 PUMP_IO 段调用 */
    public function pump(): void
    {
        $this->transport->pump();
    }

    /** 帧内后端有没有在途请求（hasPolledAsync 用它保持帧时钟上膛） */
    public function inFlight(): int
    {
        return $this->transport->inFlight();
    }

    /** multipart 或 body 超过阈值 ⇒ 走进程池 */
    private function needsWorker(array $opts): bool
    {
        if (isset($opts['fields']) || isset($opts['files'])) {
            return true;
        }
        $body = $opts['body'] ?? '';

        return \is_string($body) && \strlen($body) > self::INLINE_BODY_LIMIT;
    }

    /** 大请求走进程池，回传数组在 then 里重建 HttpResponse */
    private function submitWorker(string $method, string $url, array $opts): Future
    {
        // 进度回调的 callable 进不了 args()：job 内部用 JobProgress，UI 侧经
        // Future::onProgress 消费；这里把门面上的回调转接到那条 Future。
        $onProgress = $opts['onProgress'] ?? null;
        unset($opts['onProgress']);

        // worker 的墙钟上限 = HTTP 超时 + 余量：用户设 timeout=60 时不能被默认 30s 误杀。
        // 余量留 10s 给 worker 启动 / 收尾（与上传类 Job 的 TIMEOUT_MARGIN 同口径）。
        $httpTimeoutS = (float) ($opts['timeout'] ?? 10.0);
        $workerTimeoutMs = (int) \ceil($httpTimeoutS * 1000.0) + 10_000;

        $future = JobRunner::exec($this->host, new HttpRequestJob($method, $url, $opts, $workerTimeoutMs));
        if (\is_callable($onProgress)) {
            $future->onProgress($onProgress);
        }

        return $future->then(static function (mixed $wire): HttpResponse {
            if (!\is_array($wire)) {
                throw new \RuntimeException('HttpRequestJob 回传的不是数组（无法重建 HttpResponse）');
            }

            return new HttpResponse(
                (int) ($wire['status'] ?? 0),
                \is_array($wire['headers'] ?? null) ? $wire['headers'] : [],
                (string) ($wire['body'] ?? ''),
                (string) ($wire['url'] ?? ''),
                (float) ($wire['elapsedMs'] ?? 0.0),
            );
        });
    }
}
