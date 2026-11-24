<?php
declare(strict_types=1);

namespace Drift\Debug\Protocol;

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
 * ## 为什么必须带 `JSON_PRESERVE_ZERO_FRACTION`
 *
 * PHP 的 JSON 只有一种数字。`json_encode(41.0)` 写出的是 `41`，读回来是 **int** ——
 * 于是"跨进程读到的值与同进程逐值相同"这条判据会在**每一个整数值的浮点字段**上
 * 假红（帧时间戳 `0.0 / 16.0 / 32.0` 全是整数浮点）。加上这个标志，浮点永远带小数点，
 * 往返之后类型不变。
 *
 * 这不是给判据开后门：不带它，读回来的东西**真的**变了类型，`assertSame` 报红是对的。
 * 换句话说，这一行标志是"在边界处保住语义"的实现，而不是"在比较处容忍差异"。
 *
 * ## 编不出来就报错，不写半句
 *
 * `NAN` / `INF` / 递归引用会让 `json_encode` 返回 `false`。若不检查，发出去的是空串，
 * 对端读到的是"格式错误" —— 报错的位置离原因隔了一条管道。带 `JSON_THROW_ON_ERROR`
 * 抛在编码处，才指得到底是哪个值。
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
        // ★ 先自己走一遍，把"不是数据"的值挡在编码之前。
        //   `json_encode` 对对象**不会**失败：`new stdClass()` 会写成 `{}`、一个
        //   只有私有属性的对象也写成 `{}` —— 解回来是空数组。那种"发成功了但内容没了"
        //   比报错难查得多，而且它只会在对面读的时候显形。
        self::assertPlain($message);

        try {
            $json = \json_encode(
                $message,
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('消息编不成 JSON：' . $e->getMessage(), 0, $e);
        }
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
        try {
            $raw = \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('消息不是合法 JSON：' . $e->getMessage(), 0, $e);
        }
        if (!\is_array($raw) || \array_is_list($raw)) {
            throw new \InvalidArgumentException('消息顶层必须是对象（`[...]` 不行）');
        }

        return $raw;
    }

    /**
     * 递归确认"只有标量 / null / 数组"。错误信息带路径，直接指到那个值。
     *
     * 路径用 `a.b.0.c` 的写法而不是 `a[b][0][c]`：它是给日志和命令行看的，
     * 多一层引号只会让人多读一遍。
     *
     * @param array<array-key, mixed>|mixed $value
     */
    private static function assertPlain(mixed $value, string $path = '消息'): void
    {
        if (\is_array($value)) {
            foreach ($value as $key => $item) {
                self::assertPlain($item, $path . '.' . (string) $key);
            }

            return;
        }
        if (\is_scalar($value) || $value === null) {
            return;
        }

        throw new \InvalidArgumentException(
            '消息里有 JSON 表达不了的值：' . $path . ' 是 ' . \get_debug_type($value)
            . '（只能放标量 / 数组 / null；对象会被编成 `{}` 而内容丢失）',
        );
    }
}
