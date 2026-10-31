<?php
declare(strict_types=1);

namespace Drift\App\Io;

/**
 * 一次 HTTP 响应的**值**（`Http` 的产物，2026-10-31）。
 *
 * ```php
 * $res = Http::get('http://127.0.0.1:8099/api/rows?page=2');
 * $res->status();      // 200
 * $res->ok();          // true（2xx）
 * $res->header('content-type');
 * $res->json();        // 解析好的数组/标量；不是 JSON 当场抛
 * $res->elapsedMs();   // 这一次往返花了多久（含 DNS / 连接 / TLS / 传输）
 * ```
 *
 * ## 为什么是一个值对象，而不是 `Http::get()` 直接回字符串
 *
 * 因为**"取到一段字节"不等于"这次请求成功了"**。两份手写 curl（`apps/users` 与
 * `apps/showcase` 各一份，2026-10-31 收成一套）都各自重复了同一段判断：
 * 状态码不是 2xx 要抛、body 不是 JSON 要抛、要把 body 前 200 字带进错误消息。
 * 那三条判断只要有**一处**漏了，症状就是"接口 500 了但界面显示空列表"——
 * 不报错、只是没数据。⇒ 状态码、头、体、耗时**一起**交出来，判断留给 `ok()`
 * 与 `json()` 这两个说得清的方法。
 *
 * ## 头为什么统一小写
 *
 * HTTP 头名**不区分大小写**（RFC 9110 §5.1），而"按名字取头"是唯一天天在用的
 * 操作。两处手写实现里都没处理这件事（`$headers['Content-Type']` 与
 * `$headers['content-type']` 是两把钥匙）⇒ 构造时统一成小写，`header()` 也小写
 * 入参，于是**怎么拼都取得着**。同名多值按 `", "` 合并（Cookie 之外的头都允许，
 * 而且 curl 自己的 `-i` 输出也是这么显示的）。
 *
 * ★ **不提供 `toArray()` / `fromArray()`**：那对方法只在"响应要跨进程传"时才有意义，
 *   而本类**就住在 worker 里**（`Http` 是阻塞的，见其类注释）—— 跨进程传的是
 *   业务数据（数组），不是响应对象。没有消费者的序列化分支按本仓纪律 =
 *   未实现。
 *
 * @api
 */
final class HttpResponse
{
    /**
     * 错误消息里"body 前 N 字"的默认长度（与两处手写 curl 的 `substr($raw, 0, 200)` 一致）。
     *
     * ★ 为什么是 200：够看清服务端回的那句人话（`{"error":"..."}`），又不至于让一条
     *   异常消息把日志刷成一屏 HTML。
     */
    private const SNIPPET_CHARS = 200;

    /** @var array<string, string> 头名已小写；同名多值按 `", "` 合并 */
    private readonly array $headers;

    /**
     * ★ 参数顺序即"读的顺序"：先看状态码，再看体，其余是补充。
     *
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
     *
     * @api
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * 2xx —— **唯一**该被当成"成功"的区间。
     *
     * ★ 3xx 不算：`Http` 默认不跟随重定向（`followRedirects => 0`），所以拿到 3xx
     *   意味着"你要的东西在别处"。把它当成功会让调用方解析一段空 body 或一段
     *   `<html>Moved</html>`。要么开 `followRedirects`，要么自己看 `header('location')`。
     *
     * @api
     */
    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * 按名字取头（大小写不敏感）；没有 ⇒ null（**不是**空串 —— 空串是合法值）
     *
     * @api
     */
    public function header(string $name): ?string
    {
        return $this->headers[\strtolower(\trim($name))] ?? null;
    }

    /**
     * @return array<string, string> 全部头（小写键）
     *
     * @api
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * 原始 body（curl 已解压；编码即服务端给的编码）
     *
     * @api
     */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * 最终 URL（跟随重定向后是最后一跳）—— 日志里要能看出"到底打到了哪"
     *
     * @api
     */
    public function url(): string
    {
        return $this->url;
    }

    /**
     * 端到端毫秒（DNS + 连接 + TLS + 传输；`CURLINFO_TOTAL_TIME`，由 curl 计）
     *
     * @api
     */
    public function elapsedMs(): float
    {
        return $this->elapsedMs;
    }

    /**
     * 解析 JSON（关联数组）—— **失败当场抛**，不返回 `null` 让你去猜。
     *
     * ★ 为什么把 `json_decode` 收进值对象：两处手写实现都写着
     *   `if (!\is_array($data) || empty($data['ok']))` —— 第一半是框架能判的
     *   （"这段字节是不是 JSON"），第二半才是业务（"服务端说没说 ok"）。
     *   只有第一半进来，业务判断才只剩一行。
     * ★ 合法的 `null`（body 就是 `null`）**不报错**：那是合法 JSON，只是没有内容。
     *
     * @return mixed 解析结果（数组 / 标量 / null）
     *
     * @throws HttpException body 不是合法 JSON（消息里带状态码与前 200 字，够定位）
     *
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
     *
     * ★ 压换行是必须的：服务端回的 HTML 错误页有几百个换行，原样塞进异常消息会
     *   把日志切成一片一片，反而看不清是哪一条。
     *
     * @api
     */
    public function snippet(int $max = self::SNIPPET_CHARS): string
    {
        $flat = \preg_replace('/\s+/u', ' ', $this->body) ?? $this->body;
        $flat = \trim($flat);
        if ($max <= 0 || \mb_strlen($flat, 'UTF-8') <= $max) {
            return $flat;
        }

        // ★ 按**字符**截断（`mb_substr`）：按字节截会把一个汉字劈成两半，
        //   而这条消息最终要进 `json_encode`（worker 的错误回程）—— 非法 UTF-8
        //   会让**整条回复**编码失败，症状是"任务失败"变成"encode failed"。
        //   `mbstring` 是运行时 ini 保证加载的（`drift-build` 写死，见 `KeyEvent` 同款用法）。
        return \mb_substr($flat, 0, $max, 'UTF-8') . '…';
    }
}
