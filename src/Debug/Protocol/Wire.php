<?php
declare(strict_types=1);

namespace Drift\Debug\Protocol;

use Drift\Debug\Json;

/**
 * 一条协议消息 ⇄ 一行 JSON。
 *
 * ## 为什么是"一行 JSON"（NDJSON）
 *
 * 分帧方式决定了一个协议能被谁看懂。长度前缀（DAP 的 `Content-Length:`）更省一次
 * 扫描，但人没法直接用 `nc`/`socat` 对着它敲；一行一条则 `echo '…' | nc` 就能问一句。
 * 调试工具本身的可调试性值这一行代码。
 *
 * 合法性靠 JSON 自己保证：`json_encode` 会把字符串里的换行转义成 `\n`，
 * 所以"一行"不会因为内容里有换行而被劈开。
 *
 * ## 编码纪律不在这里
 *
 * `JSON_PRESERVE_ZERO_FRACTION` / `assertPlain` 那一套住在 {@see Json}：证据包
 * （{@see \Drift\Debug\Session\Evidence}）要用同一套，而让 `Session` 反过来依赖
 * `Protocol` 会造出一个没登记的目录环。本类只保留**协议特有**的两条：
 * 单条长度上限、顶层必须是对象。
 *
 * ## 编不出来就报错，不写半句
 *
 * `NAN` / `INF` / 递归引用会让 `json_encode` 返回 `false`。若不检查，发出去的是空串，
 * 对端读到的是"格式错误" —— 报错的位置离原因隔了一条管道。抛在编码处，才指得到
 * 底是哪个值。
 *
 * @api
 */
final class Wire
{
    /**
     * 单条消息上限。超过就拒绝，不去猜"也许只是个大数组"：
     * 一条消息撑到几十 MB 说明查询参数写错了（例如 `limit` 给了个天文数字），
     * 而对端读到的那一刻已经把这个数的内存付掉了。
     */
    public const int MAX_LINE = 8 * 1024 * 1024;

    /**
     * @param array<array-key, mixed> $message
     *
     * @throws \InvalidArgumentException 含 JSON 表达不了的值，或超出单条上限
     */
    public static function encode(array $message): string
    {
        $json = Json::encode($message);
        if (\strlen($json) > self::MAX_LINE) {
            throw new \InvalidArgumentException(
                '消息超过单条上限（' . \strlen($json) . ' > ' . self::MAX_LINE . ' 字节）',
            );
        }

        return $json;
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws \InvalidArgumentException 不是合法 JSON，或顶层不是对象
     */
    public static function decode(string $line): array
    {
        if (\strlen($line) > self::MAX_LINE) {
            throw new \InvalidArgumentException('消息超过单条上限（' . \strlen($line) . ' 字节）');
        }

        return Json::decode($line);
    }
}
