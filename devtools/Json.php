<?php
declare(strict_types=1);

namespace Drift\DevTools;

/**
 * 一条记录 ⇄ 一行 JSON。**只管编码纪律，不管分帧与长度上限**。
 *
 * ## 为什么单独一个类
 *
 * 这套纪律有两个消费者：{@see \Drift\DevTools\Protocol\Wire}（协议消息，有单条上限、
 * 顶层必须是对象）与 {@see \Drift\DevTools\Session\Evidence}（证据包的记录行，没有
 * 8MB 的协议上限）。若让后者直接调前者，`Session` 目录就反向依赖 `Protocol`，
 * 而 `Protocol\RemoteSession` 又实现 `Session\Session` —— 一个没登记的目录环，
 * 将来谁想搬动其中一个都要先解开它。
 *
 * 也不各写一份：`JSON_PRESERVE_ZERO_FRACTION` 漏一次，"整数值的浮点"就在往返里
 * 变成 int，而报错的是判据、不是漏写的那处。同一件事只有一处写法。
 *
 * ## 必须带 `JSON_PRESERVE_ZERO_FRACTION`
 *
 * PHP 的 JSON 只有一种数字。`json_encode(41.0)` 写出的是 `41`，读回来是 **int** ——
 * 于是"跨进程读到的值与同进程逐值相同"会在每一个整数值的浮点字段上假红（帧时间戳
 * `0.0 / 16.0 / 32.0` 全是整数浮点）。加上这个标志，浮点永远带小数点，往返后类型不变。
 *
 * 这不是给判据开后门：不带它，读回来的东西**真的**变了类型。这一行标志是"在边界处
 * 保住语义"，而不是"在比较处容忍差异"。
 *
 * @internal Debug 层的编码基座，不是给别人用的序列化工具
 */
final class Json
{
    /**
     * 编码 / 解码共用的标志。
     *
     * 解码侧用 `JSON_THROW_ON_ERROR`；`JSON_PRESERVE_ZERO_FRACTION` 只在编码侧有意义，
     * 但两者一起传不会错（解码忽略它）。
     */
    public const int FLAGS = \JSON_UNESCAPED_UNICODE
        | \JSON_UNESCAPED_SLASHES
        | \JSON_PRESERVE_ZERO_FRACTION
        | \JSON_THROW_ON_ERROR;

    /**
     * @param array<array-key, mixed> $value
     *
     * @throws \InvalidArgumentException 含 JSON 表达不了的值
     */
    public static function encode(array $value): string
    {
        // ★ 先自己走一遍，把"不是数据"的值挡在编码之前。
        //   `json_encode` 对对象**不会**失败：`new stdClass()` 会写成 `{}`、一个
        //   只有私有属性的对象也写成 `{}` —— 解回来是空数组。那种"写成功了但内容没了"
        //   比报错难查得多，而且它只会在读者那边显形。
        self::assertPlain($value);

        try {
            return \json_encode($value, self::FLAGS);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('编不成 JSON：' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws \InvalidArgumentException 不是合法 JSON，或顶层不是对象
     */
    public static function decode(string $line): array
    {
        try {
            $raw = \json_decode($line, true, 512, self::FLAGS);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('不是合法 JSON：' . $e->getMessage(), 0, $e);
        }
        if (!\is_array($raw) || \array_is_list($raw)) {
            throw new \InvalidArgumentException('顶层必须是对象（`[...]` 不行）');
        }

        return $raw;
    }

    /**
     * 递归确认"只有标量 / null / 数组"。错误信息带路径，直接指到那个值。
     *
     * 路径用 `a.b.0.c` 的写法而不是 `a[b][0][c]`：它是给日志和命令行看的，
     * 多一层引号只会让人多读一遍。
     */
    public static function assertPlain(mixed $value, string $path = '值'): void
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
            'JSON 表达不了的值：' . $path . ' 是 ' . \get_debug_type($value)
            . '（只能放标量 / 数组 / null；对象会被编成 `{}` 而内容丢失）',
        );
    }
}
