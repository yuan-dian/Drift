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
 * 脱敏规则与顺序见 {@see Redactor}：只换**凭证键**，业务字段一律保留
 * （"rows 是空的"必须看得见），换了几处要报出来（`redacted`）。
 *
 * @api
 */
final readonly class NetworkBodyRecord
{
    /** 单条 body 存多少字节（超出截断并报 truncated，不静默丢）。 */
    public const int MAX_BYTES = 64 * 1024;

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
     * 存一份请求/响应报文：脱敏 + 截断。
     *
     * @param string $requestRaw 请求体（非 multipart 的 body；multipart 传字段名摘要）
     * @param string $responseRaw 响应体
     * @param bool   $redact      是否脱敏；`false` 时 meta 记 `redact: false`
     */
    public static function capture(
        int $requestId,
        string $requestRaw,
        string $responseRaw,
        int $maxBytes = self::MAX_BYTES,
        bool $redact = true,
    ): self {
        $request = self::prepare($requestRaw, $maxBytes, $redact);
        $response = self::prepare($responseRaw, $maxBytes, $redact);

        return new self($requestId, $request['text'], $request['meta'], $response['text'], $response['meta']);
    }

    /**
     * ## 顺序是 脱敏 → 解析 → 截断，一步都不能换
     *
     * 早先是 截断 → 解析：截断把 JSON 切成半截，`json_decode` 必然失败，整段退回
     * 文本分支；而文本正则要求 `key=value`，认不出 `"password": "…"`。结果是
     * **64KB 以上的 JSON 请求体里密码原样留存**，同时报 `format=text` /
     * `redacted=0` —— 三条自报全都指向"没发现敏感字段"。这个组合最坏的地方在于
     * 它看起来完全正常，只有把存档原样打印出来才看得见明文。
     *
     * 现在：脱敏在**全文**上做（截断不可能造成漏网），格式判定也在全文上做
     * （截断后的 JSON 不是 JSON，报 `text` 会误导读者），截断放最后。
     *
     * @return array{text: string, meta: array{bytes: int, truncated: bool, redacted: int, redact: bool, format: string}}
     */
    private static function prepare(string $raw, int $maxBytes, bool $redact): array
    {
        $bytes = \strlen($raw);

        $text = $raw;
        $redacted = 0;
        if ($redact) {
            $scrubbed = Redactor::scrub($raw);
            $text = $scrubbed['text'];
            $redacted = $scrubbed['count'];
        }

        $format = 'text';
        $decoded = \json_decode($text, true);
        if (\is_array($decoded)) {
            $format = 'json';
            if ($redact) {
                // 结构化补漏：正则漏掉的格式（键值跨行、值含空格）在这里兜住。
                // 补漏数计入 `redacted`，不单独报 —— 读者关心的是"换了几处"。
                $clean = Redactor::scrubArray($decoded);
                $redacted += $clean['count'];
                if ($redacted > 0) {
                    $text = (string) \json_encode(
                        $clean['data'],
                        \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
                    );
                }
            }
        }

        $truncated = false;
        if (\strlen($text) > $maxBytes) {
            // 按**字符**边界截断：按字节切会把最后一个多字节字符劈成半个，
            // 前端渲染成替换符，看起来像"响应里有乱码"这种不存在的 bug。
            $text = \mb_strcut($text, 0, $maxBytes, 'UTF-8');
            $truncated = true;
        }

        return [
            'text' => $text,
            'meta' => [
                'bytes' => $bytes,
                'truncated' => $truncated,
                'redacted' => $redacted,
                // 关掉脱敏时 `redacted: 0` 的意思是"没扫过"，不是"扫了没发现"。
                // 不带这个位，读者会把明文当成"已检查且干净"。
                'redact' => $redact,
                'format' => $format,
            ],
        ];
    }

    /**
     * 无参转换方法：tpc 把 `toArray()` 识别为转换方法，**禁止带形参**
     * （带参时翻译直接 Fatal， interpreter 与 lint 都看不出来）。
     * 带查询预算的投影走 {@see self::select()}。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->select('both', self::MAX_BYTES);
    }

    /**
     * 证据包里的形状：**不截断**，与 {@see self::toArray()} 不同。
     *
     * `toArray()` 走 `select('both', MAX_BYTES)`，超出 64KB 会再截一刀——存档说
     * "完整"、导出的却是片段。包里存的是已经在采集处截过的那份原文，读取方要
     * 按自己的预算再 `select()`，而不是被导出器悄悄截一次。
     *
     * @return array<string, mixed>
     */
    public function encode(): array
    {
        return [
            'request' => $this->requestId,
            'requestBody' => $this->requestBody,
            'requestMeta' => $this->request,
            'responseBody' => $this->responseBody,
            'responseMeta' => $this->response,
        ];
    }

    /** @param array<string, mixed> $raw */
    public static function decode(array $raw): self
    {
        /** @var array{bytes: int, truncated: bool, redacted: int, format: string} $req */
        $req = self::meta((array) ($raw['requestMeta'] ?? []));
        /** @var array{bytes: int, truncated: bool, redacted: int, format: string} $res */
        $res = self::meta((array) ($raw['responseMeta'] ?? []));

        return new self(
            (int) ($raw['request'] ?? 0),
            (string) ($raw['requestBody'] ?? ''),
            $req,
            (string) ($raw['responseBody'] ?? ''),
            $res,
        );
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{bytes: int, truncated: bool, redacted: int, redact: bool, format: string}
     */
    private static function meta(array $raw): array
    {
        return [
            'bytes' => (int) ($raw['bytes'] ?? 0),
            'truncated' => (bool) ($raw['truncated'] ?? false),
            'redacted' => (int) ($raw['redacted'] ?? 0),
            'redact' => (bool) ($raw['redact'] ?? true),
            'format' => (string) ($raw['format'] ?? 'text'),
        ];
    }

    /**
     * 查询投影：选一侧或两侧，并在查询侧再截一次。
     *
     * @return array<string, mixed>
     */
    public function select(string $part, int $maxBytes): array
    {
        // `request` 在所有查询分支里都表示 requestId（miss 分支也是），元信息另起名。
        // 否则查到报文时 id 会被同名的 meta 数组覆盖，调用方拿不到自己问的那条。
        $out = ['request' => $this->requestId];
        if ($part === 'both' || $part === 'request') {
            [$out['requestBody'], $clipped] = $this->clip($this->requestBody, $maxBytes);
            // 查询侧再截一次，meta 必须跟着改：存档说"完整"、实际给的是片段，
            // 读者会以为自己看到的就是全部 —— 与"裁剪伪装成结论"是同一类错误。
            // 用 `array_merge` 而不是 `+`：`+` 不覆盖同名键，`truncated=false`
            // 会活下来，那就白改了。
            $out['requestMeta'] = $clipped
                ? \array_merge($this->request, ['truncated' => true, 'queryTruncated' => true])
                : $this->request;
        }
        if ($part === 'both' || $part === 'response') {
            [$out['responseBody'], $clipped] = $this->clip($this->responseBody, $maxBytes);
            $out['responseMeta'] = $clipped
                ? \array_merge($this->response, ['truncated' => true, 'queryTruncated' => true])
                : $this->response;
        }

        return $out;
    }

    /**
     * 查询侧再截一次：存档里最多 64KB，查询可以要更少（协议 8MB 硬限的护栏）。
     *
     * @return array{0: string, 1: bool} 文本 + 这次查询是否截了
     */
    private function clip(string $text, int $maxBytes): array
    {
        if ($maxBytes <= 0 || \strlen($text) <= $maxBytes) {
            return [$text, false];
        }

        return [
            \mb_strcut($text, 0, \max(0, $maxBytes), 'UTF-8')
                . "\n…（查询截断，完整存档 " . \strlen($text) . ' 字节）',
            true,
        ];
    }
}
