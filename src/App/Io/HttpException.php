<?php
declare(strict_types=1);

namespace Drift\App\Io;

/**
 * `Http` 的失败—— **传输失败**与**非 2xx** 共用一种异常。
 *
 * ```php
 * try {
 *     $data = Http::get($url)->json();
 * } catch (HttpException $e) {
 *     $e->status();   // 404 ⇒ 业务上"这一页没有"；null ⇒ 连不上/超时/证书
 *     $e->url();      // 打的是哪个地址（含 query，重定向后是最末一跳）
 * }
 * ```
 *
 * ## 为什么分成 `status === null` 与 `status !== null` 两档
 *
 * 这两种失败**该被区别对待**，而且区别不在于消息文字：
 *
 * | `status()` | 含义 | 通常怎么处理 |
 * |---|---|---|
 * | `null` | 传输层没拿到响应（DNS 失败 / 连不上 / 超时 / 证书） | 重试有意义；离线提示 |
 * | `404` | 拿到了响应，服务端说没有 | 重试没意义；显示"没有这一页" |
 * | `500` | 拿到了响应，服务端自己错了 | 重试可能有用；把 body 摘要带进日志 |
 *
 * 一个 `\RuntimeException` 就只能靠字符串匹配去分辨这三档 —— 那正是本类存在的理由。
 *
 * ★ **不继承 `Errors` 的任何东西**：`Errors::report()` 那条路是"框架级事故"（Fatal /
 *   Diagnostic），而一次请求失败是**业务常态**。把它报到那里会让真事故淹没在
 *   每页一次的 404 里。
 *
 * @api
 */
final class HttpException extends \RuntimeException
{
    /**
     * @param string $message 人话（含方法、URL、状态码、body 摘要）
     * @param string $url 打出去的地址（便于日志归因）
     * @param int|null $status 非 2xx 时是那个状态码；传输层失败是 `null`
     */
    public function __construct(
        string $message,
        private readonly string $url = '',
        private readonly ?int $status = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * 打出去的地址（跟随重定向时是最后一跳 —— 与 `HttpResponse::url()` 同一口径）
     *
     * @api
     */
    public function url(): string
    {
        return $this->url;
    }

    /**
     * 非 2xx 的状态码；`null` = 没拿到响应（传输层失败）
     *
     * @api
     */
    public function status(): ?int
    {
        return $this->status;
    }
}
