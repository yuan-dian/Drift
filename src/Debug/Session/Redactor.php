<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 证据里的凭证脱敏 —— 唯一规则表。
 *
 * ## 为什么单独一个类
 *
 * 早先规则散在 `NetworkBodyRecord::prepare()` 里：JSON 分支用键名表，文本分支用
 * 另一条正则，两套规则各认一半 —— 而 `prepare()` 又是"先截断再解析"，截断让 JSON
 * 解析失败、退回文本分支，于是 `"password": "…"` 一条也认不出。三类自报
 * （`format=text` / `redacted=0` / 看起来正常）同时指向"没发现敏感字段"。
 *
 * 现在规则只有一份：`SECRET_PATTERNS`。它同时供
 * - 全文正则（`scrub()`，在**截断之前**跑，截断不会让敏感字段漏网）
 * - 结构化键名判定（`isSecretKey()`，给 JSON 补漏）
 *
 * ## 为什么正则片段里写 `[_-]?` 而不是三份字面量
 *
 * 同一个键在实际报文里有三种写法：`access_token` / `access-token` / `accessToken`。
 * `access[_-]?token` 配 `i` 修饰符三种全中；`canonical()` 去掉 `[_-]?` 后得到
 * `accesstoken`，正好等于"去分隔符 + 小写"的规范化结果 —— 一份表两处用，不会漂。
 *
 * ## 只换凭证，不动业务字段
 *
 * `rows` 是空的必须看得见。换了几处要报出来（`redacted`），不许悄悄改数据让人
 * 以为看到的是原文。这与 `truncated` 是同一条纪律：**改动读者看到的内容，必须自报**。
 *
 * @api
 */
final class Redactor
{
    public const string REDACTED = '[REDACTED]';

    /**
     * 凭证键的**正则片段**表（小写；`[_-]?` 覆盖下划线 / 连字符 / 驼峰三种写法）。
     *
     * 这是唯一真相：{@see isSecretKey()} 由它派生，不另存一份字面量清单。
     */
    private const array SECRET_PATTERNS = [
        'password', 'passwd', 'pwd', 'secret',
        'token', 'access[_-]?token', 'refresh[_-]?token', 'id[_-]?token',
        'authorization', 'api[_-]?key', 'private[_-]?key', 'client[_-]?secret',
        'cookie', 'set[_-]?cookie', 'credential', 'credentials',
    ];

    /**
     * 值形态规则：没有键名也能认出是凭证的几种。
     *
     * `Bearer` 与 JWT 是最常见的两种 —— 它们常出现在自定义 header 回显、
     * 日志字段、`{"auth": "Bearer …"}` 这类**没有敏感键名**的位置。
     *
     * 两条都带"值必须像凭证"的护栏（长度 + 至少一个非字母字符），
     * 否则 `Basic authentication is required` 这种普通句子会被误换。
     *
     * @var list<string>
     */
    private const array VALUE_PATTERNS = [
        '/\bBearer\s+(?=[A-Za-z0-9._~+\/=-]{8,})(?=[A-Za-z0-9._~+\/=-]*[0-9._~+\/=-])[A-Za-z0-9._~+\/=-]+/i',
        '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{4,}/',
    ];

    /**
     * 在**全文**上脱敏。
     *
     * 调用方必须保证这是截断之前的完整文本：先截断会让被切断的键值认不出来，
     * 而那种漏网在报告里表现为"没发现敏感字段"。
     *
     * @return array{text: string, count: int} 换掉几处（不是几个字节）
     */
    public static function scrub(string $text): array
    {
        if ($text === '') {
            return ['text' => '', 'count' => 0];
        }

        $count = 0;

        // 键名 + 分隔符 + 值。值到空白 / 引号 / `&;,}` 为止：这三种是
        // `k=v&k2=v2`、`"k": "v"`、`{k: v}` 的共同边界。
        $pattern = '/(?<![\w-])(' . self::keysPattern() . ')(["\']?\s*[=:]\s*["\']?)([^\s"\'&;,}]+)/i';
        $out = \preg_replace_callback(
            $pattern,
            static function (array $m) use (&$count): string {
                ++$count;

                return $m[1] . $m[2] . self::REDACTED;
            },
            $text,
        ) ?? $text;

        foreach (self::VALUE_PATTERNS as $valuePattern) {
            $out = \preg_replace_callback(
                $valuePattern,
                static function (array $m) use (&$count): string {
                    ++$count;

                    // `Bearer xxx` 保留方案名：读者要知道"这是个 Bearer 凭证"。
                    return \str_starts_with(\strtolower($m[0]), 'bearer')
                        ? 'Bearer ' . self::REDACTED
                        : self::REDACTED;
                },
                $out,
            ) ?? $out;
        }

        return ['text' => $out, 'count' => $count];
    }

    /**
     * 结构化补漏：正则没认出来的格式（键值跨行、值里含空格）在这里兜住。
     *
     * 返回值里的 `count` 是**补**换掉的数量 —— 非零就说明 `scrub()` 的正则
     * 有缺口。目前不把它当错误报出去（补上了就是补上了），但它是一个可观测的
     * 信号：真出现时应该回头补 `SECRET_PATTERNS`。
     *
     * @param array<array-key, mixed> $data
     * @return array{data: array<array-key, mixed>, count: int}
     */
    public static function scrubArray(array $data): array
    {
        $count = 0;
        /** @var array<array-key, mixed> $clean */
        $clean = self::walk($data, $count);

        return ['data' => $clean, 'count' => $count];
    }

    /** 这个键名是不是凭证键（规范化后比对，`Set-Cookie` / `setCookie` 都算）。 */
    public static function isSecretKey(string $key): bool
    {
        $normalized = \str_replace(['-', '_'], '', \strtolower($key));
        foreach (self::SECRET_PATTERNS as $pattern) {
            if (self::canonical($pattern) === $normalized) {
                return true;
            }
        }

        return false;
    }

    private static function keysPattern(): string
    {
        return \implode('|', self::SECRET_PATTERNS);
    }

    /** 正则片段 ⇒ 规范化字面量（`access[_-]?token` ⇒ `accesstoken`）。 */
    private static function canonical(string $pattern): string
    {
        return \str_replace('[_-]?', '', $pattern);
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function walk(array $value, int &$count): array
    {
        $out = [];
        foreach ($value as $key => $child) {
            if (self::isSecretKey((string) $key)) {
                // 已经换过的不要再数一遍：`scrub()` 在全文上跑过之后，这里的值
                // 多半已经是 `[REDACTED]`。重复计数会让"换了几处"翻倍，读者
                // 按这个数去核对原文，会发现"少了一处"。
                if ($child !== self::REDACTED) {
                    ++$count;
                }
                $out[$key] = self::REDACTED;
            } elseif (\is_array($child)) {
                $out[$key] = self::walk($child, $count);
            } else {
                $out[$key] = $child;
            }
        }

        return $out;
    }
}
