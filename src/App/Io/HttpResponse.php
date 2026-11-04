<?php
declare(strict_types=1);

namespace Drift\App\Io;

/**
 * 一次 HTTP 响应的**值**对象：状态码、头、体、耗时一起交出，
 * 成功判定交给 `ok()`，解析交给 `json()`。
 * 头名统一小写：HTTP 头名不区分大小写，构造时归一，同名多值按 `", "` 合并。
 * 不提供 `toArray()` / `fromArray()`：本类只活在 worker 内，跨进程传的是业务数据。
 * @api
 */
final class HttpResponse
{
    /**
     * 错误消息里"body 前 N 字"的默认长度。
     * 取 200：够看清服务端回的那句人话，又不让一条异常消息刷成一屏 HTML。
     */
    private const SNIPPET_CHARS = 200;

    /** @var array<string, string> 头名已小写；同名多值按 `", "` 合并 */
    private readonly array $headers;

    /**
     * @param int $status HTTP 状态码（0 = 没拿到响应，只有传输层失败才会出现）
     * @param array<string, string> $headers 原始头（大小写随便，构造时统一小写）
     * @param string $body 响应体（curl 已解压）
     * @param string $url **最终** URL（跟随重定向后是最后一跳；不跟随则等于入参）
     * @param float $elapsedMs 端到端耗时（毫秒）
     */
    public function __construct(
        private readonly int $status,
        array $headers,
        private readonly string $body,
        private readonly string $url,
        private readonly float $elapsedMs,
    ) {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $key = \strtolower(\trim((string) $name));
            if ($key === '') {
                continue;
            }
            $text = \trim((string) $value);
            $normalized[$key] = isset($normalized[$key]) ? $normalized[$key] . ', ' . $text : $text;
        }
        $this->headers = $normalized;
    }

    /**
     * HTTP 状态码（2xx/3xx/4xx/5xx；`0` 只会出现在"自己没有响应"的失败上）
     * @api
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * 2xx —— **唯一**该被当成"成功"的区间。
     * ★ 3xx 不算：`Http` 默认不跟随重定向，拿到 3xx 意味着"你要的东西在别处"。
     *   把它当成功会让调用方解析一段空 body 或一段 `<html>Moved</html>`。
     *   要么开 `followRedirects`，要么自己看 `header('location')`。
     * @api
     */
    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * 按名字取头（大小写不敏感）；没有 ⇒ null（**不是**空串 —— 空串是合法值）
     * @api
     */
    public function header(string $name): ?string
    {
        return $this->headers[\strtolower(\trim($name))] ?? null;
    }

    /**
     * @return array<string, string> 全部头（小写键）
     * @api
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * 原始 body（curl 已解压；编码即服务端给的编码）
     * @api
     */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * 最终 URL（跟随重定向后是最后一跳）—— 日志里要能看出"到底打到了哪"
     * @api
     */
    public function url(): string
    {
        return $this->url;
    }

    /**
     * 端到端毫秒（DNS + 连接 + TLS + 传输；`CURLINFO_TOTAL_TIME`，由 curl 计）
     * @api
     */
    public function elapsedMs(): float
    {
        return $this->elapsedMs;
    }

    /**
     * 解析 JSON（关联数组）—— **失败当场抛**，不返回 `null` 让你去猜。
     * ★ 合法的 `null`（body 就是 `null`）**不报错**：那是合法 JSON，只是没有内容。
     * @return mixed 解析结果（数组 / 标量 / null）
     * @throws HttpException body 不是合法 JSON（消息带状态码与前 200 字，够定位）
     * @api
     */
    public function json(): mixed
    {
        $decoded = \json_decode($this->body, true);
        if ($decoded === null && \json_last_error() !== JSON_ERROR_NONE) {
            throw new HttpException(
                \sprintf(
                    'HTTP %d 的响应不是合法 JSON（%s）：%s',
                    $this->status,
                    \json_last_error_msg(),
                    $this->snippet(),
                ),
                $this->url,
                $this->status,
            );
        }

        return $decoded;
    }

    /**
     * body 的一行摘要（压掉换行 + 截断）—— 错误消息里"看得见现场"的那一段。
     * 压换行是必须的：服务端回的 HTML 错误页有几百个换行，原样塞进异常消息
     * 会把日志切成一片一片，反而看不清是哪一条。
     * @api
     */
    public function snippet(int $max = self::SNIPPET_CHARS): string
    {
        $flat = \preg_replace('/\s+/u', ' ', $this->body) ?? $this->body;
        $flat = \trim($flat);
        if ($max <= 0 || \mb_strlen($flat, 'UTF-8') <= $max) {
            return $flat;
        }

        // ★ 按**字符**截断（`mb_substr`）：按字节截会把汉字劈成两半，而这条消息
        //   最终要进 `json_encode`（worker 错误回程）—— 非法 UTF-8 会让整条回复
        //   编码失败，症状是"任务失败"变成"encode failed"。
        return \mb_substr($flat, 0, $max, 'UTF-8') . '…';
    }
}
