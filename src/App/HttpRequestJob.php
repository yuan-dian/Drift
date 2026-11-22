<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\App\Io\Http;

/**
 * 通用 HTTP 请求任务 —— `AsyncHttp` 把大 body / multipart 分流到这里。
 *
 * 为什么大请求不进帧内 curl_multi：multipart 是流式（占 fd、进度回调），
 * 几十 MB 的上传在 UI 帧里推进会顶帧。这类交给进程池，worker 里用阻塞
 * `Http::request()`（语义仍是那一份），结果摊成数组回传。
 *
 * ★ args 只含标量 / 标量数组（opts 里的 onProgress 不能跨进程）：进度用
 *   `JobProgress::report()`，UI 侧经 `Future::onProgress()` 消费。
 * ★ 返回值是数组不是 HttpResponse：跨进程只过 JSON（见 `ProcessWorker`），
 *   门面 `AsyncHttp` 在 then 里重建值对象。
 *
 * @internal 由 AsyncHttp 与 CoreJobs 使用，应用不直接 submit
 */
final class HttpRequestJob implements NamedJob, TimedJob
{
    use JobArgs;

    public const string NAME = 'http.request';

    private const array FIELDS = ['method', 'url', 'opts', 'timeoutMs'];

    /**
     * @param array<string, mixed> $opts `Http::request()` 同款，值必须是标量或标量数组
     */
    public function __construct(
        private readonly string $method,
        private readonly string $url,
        private readonly array $opts,
        private readonly int $timeoutMs = 30_000,
    ) {
    }

    public function timeoutMs(): int
    {
        return $this->timeoutMs;
    }

    /** @return array<string, mixed> */
    public function run(): mixed
    {
        $opts = $this->opts;
        // 进度回调在 worker 侧现场绑：callable 进不了 args()。
        // ★ 只对 multipart 绑：Http 明确禁止普通 body 带 onProgress（没有中途
        //   观察点），大 body 非 multipart 也走这里，绑了会当场抛。
        if (isset($opts['fields']) || isset($opts['files'])) {
            $opts['onProgress'] = static function (float $fraction): void {
                JobProgress::report($fraction);
            };
        }

        $response = Http::request($this->method, $this->url, $opts);

        return [
            'status' => $response->status(),
            'headers' => $response->headers(),
            'body' => $response->body(),
            'url' => $response->url(),
            'elapsedMs' => $response->elapsedMs(),
        ];
    }
}
