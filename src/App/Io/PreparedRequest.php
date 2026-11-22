<?php
declare(strict_types=1);

namespace Drift\App\Io;

/**
 * 一次 HTTP 请求"配置好、还没执行"的中间态。
 *
 * 把 `Http::request()` 拆成 prepare / execute / finish 三段后，本类携带
 * prepare 的成果：curl handle、头收集器、multipart 清理句柄，以及 finish
 * 需要的请求元数据（方法 / URL / expectOk）。阻塞路径与 curl_multi 异步路径
 * 共用同一个 prepare 与 finish —— HTTP 语义（校验 / TLS / 头解析 / 错误分类）
 * 只留在 `Http` 一处，异步壳不复制任何一个字节。
 *
 * @internal
 */
final class PreparedRequest
{
    /**
     * @param mixed $handle curl handle（PHP 8 的 \CurlHandle 对象；用 mixed 避免
     *        AOT 对扩展类名的解析，调用点就是 curl_* 系列）
     * @param bool $expectOk 非 2xx 是否抛
     */
    public function __construct(
        public readonly mixed $handle,
        public readonly string $method,
        public readonly string $url,
        public readonly bool $expectOk,
        public readonly HeaderCollector $headers,
        public readonly ?MultipartBody $multipart,
    ) {
    }

    /** curl 提前失败时文件句柄不会吐完 —— 必须由调用方在收尾时调一次 */
    public function closeMultipart(): void
    {
        $this->multipart?->close();
    }
}
