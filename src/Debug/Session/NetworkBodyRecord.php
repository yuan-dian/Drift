<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 一次请求的报文存档 —— 骨架的**可选附件**，不进指纹、不进简报默认视图。
 *
 * 为什么单独一层而不是塞进 NetworkRecord：
 * - 体积：一条响应 body 可能几 MB，网络窗口 500 条会把证据包撑爆，协议单条
 *   上限 8MB 也过不去；报文按 requestId 单独查，一次只看一条。
 * - 隐私：请求体可能带密码 / token，响应体可能带用户数据。默认骨架可导出、
 *   可被 AI 读；报文必须显式查询、且先脱敏，不能混进默认证据面。
 * - 可复现：Facts 指纹只覆盖因果与段结构；报文若进指纹，服务端换个时间戳
 *   就让整份证据"不同"，指纹就没用了。
 *
 * 为什么仍然要记：返回 200、耗时正常、落地也对了，但字段是错的、用户数据
 * 不对 —— 这类问题光看骨架永远定位不了。A37 的翻转条件就是这样到来的。
 *
 * 脱敏纪律：只换**凭证键**（password / token / authorization …），业务字段一律
 * 保留 —— "rows 是空的"必须看得见。换了几个字段要报出来（`redacted`），
 * 不许悄悄改数据让人以为看到的是原文。
 *
 * @api
 */
final readonly class NetworkBodyRecord
{
    /** 单条 body 存多少字节（超出截断并报 truncated，不静默丢）。 */
    public const int MAX_BYTES = 64 * 1024;

    /** 凭证键：命中即替换值，键名保留（"这里有个 password"本身也是信息）。 */
    private const array SECRET_KEYS = [
        'password', 'passwd', 'pwd', 'secret', 'token', 'accesstoken', 'refreshtoken',
        'authorization', 'apikey', 'api_key', 'privatekey', 'private_key', 'cookie',
        'setcookie', 'set-cookie', 'credential', 'credentials', 'clientsecret', 'client_secret',
    ];

    public const string REDACTED = '[REDACTED]';

    /**
     * @param array{bytes: int, truncated: bool, redacted: int, format: string} $request
     * @param array{bytes: int, truncated: bool, redacted: int, format: string} $response
     */
    public function __construct(
        public int $requestId,
        public string $requestBody,
        public array $request,
        public string $responseBody,
        public array $response,
    ) {
    }

    /**
     * 存一份请求/响应报文：截断 + 脱敏。
     *
     * @param string $requestRaw 请求体（非 multipart 的 body；multipart 传字段名摘要）
     * @param string $responseRaw 响应体
     */
    public static function capture(
        int $requestId,
        string $requestRaw,
        string $responseRaw,
        int $maxBytes = self::MAX_BYTES,
    ): self {
        $request = self::prepare($requestRaw, $maxBytes);
        $response = self::prepare($responseRaw, $maxBytes);

        return new self($requestId, $request['text'], $request['meta'], $response['text'], $response['meta']);
    }

    /**
     * @return array{text: string, meta: array{bytes: int, truncated: bool, redacted: int, format: string}}
     */
    private static function prepare(string $raw, int $maxBytes): array
    {
        $bytes = \strlen($raw);
        $text = $raw;
        $truncated = false;
        if ($bytes > $maxBytes) {
            $text = \substr($raw, 0, $maxBytes);
            $truncated = true;
        }

        $format = 'text';
        $redacted = 0;
        $decoded = \json_decode($text, true);
        if (\is_array($decoded) || \is_object($decoded)) {
            $format = 'json';
            $found = self::countSecrets($decoded);
            $redacted = $found;
            if ($found > 0) {
                // redact() 会把计数器减到 0（用来确认每个命中的键都被换掉），
                // 但 meta 要报的是"换了几个"，所以用另一份计数，别被消耗掉。
                $remaining = $found;
                $clean = self::redact($decoded, $remaining);
                if ($remaining !== 0) {
                    $redacted = $found - $remaining; // 只报真正换掉的，不谎报
                }
                $text = (string) \json_encode(
                    $clean,
                    \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
                );
            }
        }

        // 非 JSON 的明文也可能有 `password=...`：只换常见键值形式，不碰其余文本。
        if ($format === 'text') {
            $text = \preg_replace_callback(
                '/\b(password|passwd|pwd|secret|token|authorization|api[_-]?key|client[_-]?secret)\s*[=:]\s*["\']?([^\s"\'&;,}]+)/i',
                static function (array $m) use (&$redacted): string {
                    ++$redacted;

                    return $m[1] . '=' . self::REDACTED;
                },
                $text,
            ) ?? $text;
        }

        return [
            'text' => $text,
            'meta' => [
                'bytes' => $bytes,
                'truncated' => $truncated,
                'redacted' => $redacted,
                'format' => $format,
            ],
        ];
    }

    /** 递归数有几个凭证键（不修改原值）。 */
    private static function countSecrets(mixed $value): int
    {
        if (!\is_array($value)) {
            return 0;
        }
        $count = 0;
        foreach ($value as $key => $child) {
            if (self::isSecretKey((string) $key)) {
                ++$count;
            } else {
                $count += self::countSecrets($child);
            }
        }

        return $count;
    }

    /** @return array<string, mixed>|mixed */
    private static function redact(mixed $value, int &$remaining): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $child) {
            if (self::isSecretKey((string) $key)) {
                $out[$key] = self::REDACTED;
                --$remaining;
            } else {
                $out[$key] = self::redact($child, $remaining);
            }
        }

        return $out;
    }

    private static function isSecretKey(string $key): bool
    {
        $normalized = \strtolower(\str_replace(['-', '_'], '', $key));

        return \in_array($normalized, self::SECRET_KEYS, true);
    }

    /** @return array<string, mixed> */
    public function toArray(string $part = 'both', int $maxBytes = self::MAX_BYTES): array
    {
        // `request` 在所有查询分支里都表示 requestId（miss 分支也是），元信息另起名。
        // 否则查到报文时 id 会被同名的 meta 数组覆盖，调用方拿不到自己问的那条。
        $out = ['request' => $this->requestId];
        if ($part === 'both' || $part === 'request') {
            $out['requestBody'] = $this->clip($this->requestBody, $maxBytes);
            $out['requestMeta'] = $this->request;
        }
        if ($part === 'both' || $part === 'response') {
            $out['responseBody'] = $this->clip($this->responseBody, $maxBytes);
            $out['responseMeta'] = $this->response;
        }

        return $out;
    }

    /** 查询侧再截一次：存档里最多 64KB，查询可以要更少（协议 8MB 硬限的护栏）。 */
    private function clip(string $text, int $maxBytes): string
    {
        if ($maxBytes <= 0 || \strlen($text) <= $maxBytes) {
            return $text;
        }

        return \substr($text, 0, \max(0, $maxBytes)) . "\n…（查询截断，完整存档 " . \strlen($text) . ' 字节）';
    }
}
