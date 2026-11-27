<?php
declare(strict_types=1);

namespace Drift\DevTools\Protocol;

/**
 * 协议里的**事件名**与它们的 `data` 形状。
 *
 * ## 为什么事件要单独一份声明
 *
 * 动词（{@see Methods}）是"客户端说、宿主答"，查询（`QueryRouter::QUERIES`）是
 * "问一次拿一份"。事件是第三种方向——**宿主自己开口**——所以它既不属于动词表，
 * 也不属于查询表。塞进任何一边都会让那边多出一条"其实不是同类"的条目。
 *
 * 这份表同时是能力目录 `events` 一栏的唯一来源，与 `Methods::ALL` /
 * `QUERY_SPECS` 同一套纪律：双向闭合，少了当场抛、多了也当场抛。客户端按目录
 * 决定要不要等某个事件，而不是靠"发一条试试"。
 *
 * ## 首版只有一个 topic
 *
 * `frame`。它是唯一有真实消费者的：`debug web` 靠它把 500ms 轮询换成推送。
 * 别的事件（`state` / `overlay`）都能由 `query` 答，先不开——每多一个 topic，
 * 对端就多一份"什么时候会来"的预期要维护。
 *
 * @api
 */
final class Events
{
    /**
     * 宿主推进了一帧。
     *
     * `data` 是 `{id, cost}`，**不是** `wall`：`wall` 只有客户端发 `frame` 那条
     * 路径量得到（`pump()` 的返回值），自走宿主推进帧时不产生它。让同一个事件的
     * 形状随"谁推的"变化，比少一个字段糟得多——`cost` 是录制时就有的，与
     * `frames` 查询同口径，两条路径都给得出。
     */
    public const string FRAME = 'frame';

    /**
     * 事件 => `data` 的字段与类型。`?float` 表示可能为 `null`。
     *
     * @var array<string, array{about: string, data: array<string, string>}>
     */
    private const array META = [
        self::FRAME => [
            'about' => '宿主推进了一帧（无论谁推的）；data.id 是推进后的末帧号',
            'data' => ['id' => 'int', 'cost' => '?float'],
        ],
    ];

    /** 全部事件名。能力目录与判据都从这里取，别再写第二份名单。 */
    public const array ALL = [self::FRAME];

    /**
     * 能力目录的 `events` 一栏。
     *
     * @return list<array{name: string, about: string, data: array<string, string>}>
     *
     * @throws \LogicException 声明与 {@see self::ALL} 对不上
     */
    public static function describe(): array
    {
        foreach (self::META as $name => $_meta) {
            if (!\in_array($name, self::ALL, true)) {
                throw new \LogicException("事件目录给未登记的 {$name} 写了形状：Events::ALL 里没有它");
            }
        }

        $out = [];
        foreach (self::ALL as $name) {
            $meta = self::META[$name] ?? null;
            if ($meta === null) {
                throw new \LogicException("事件目录缺少 {$name} 的形状声明");
            }
            $out[] = ['name' => $name, 'about' => $meta['about'], 'data' => $meta['data']];
        }

        return $out;
    }
}
