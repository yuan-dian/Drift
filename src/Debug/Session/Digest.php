<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 记录的**指纹**：两份运行是不是同一份因果。
 *
 * ## 为什么需要它
 *
 * "重放一遍看看"只有变成一个**会咬红的判据**才有价值。没有指纹时，
 * "两次跑出来差不多"靠肉眼比 —— 而 360 个重建节点的帧，肉眼比不出来。
 *
 * ## 只覆盖因果与段结构，不覆盖时间
 *
 * 排除 `t` / `costMs` / 各段纳秒。理由不是"省事"，而是它们的**用途不同**：
 * 时间是这台机器这一秒的运气，重跑必然不同；而"谁重建了、因为谁、跑了哪些段"
 * 必须逐值相同。把时间放进来，指纹就永远红，等于没有指纹。
 *
 * 时钟一致性由 {@see Script::digest()} 保证：脚本里每一帧的时间戳是**记下来的**，
 * 两次重放喂的是同一串数字。所以"驱动相同"是可判的，"引擎确定"由本类判。
 *
 * ## 为什么把节点表单独摘要
 *
 * 帧里只有 id。同一串 id 在另一份记录里可能是另一批控件（分配顺序变了），
 * 那时"重建集合相同"是假相同。节点表（id ⇒ 类型 + 父 id）一起比，才能说
 * "同一个控件，同样地变了"。
 *
 * @api
 */
final class Digest
{
    /**
     * 单帧的规范形状 —— 与 `explain` 查询同源，但**只留可复现的部分**。
     *
     * @return array<string, mixed>
     */
    public static function ofFrame(FrameRecord $frame): array
    {
        $causes = [];
        foreach ($frame->causes as $edge) {
            $causes[] = [$edge->kind, $edge->fromId, $edge->toId, $edge->site, $edge->detail];
        }

        return [
            'rebuilt' => $frame->rebuilt,
            'causes' => $causes,
            'stages' => $frame->stages,
            'skip' => [$frame->settleSkipped, $frame->paintSkipped],
        ];
    }

    /**
     * @return array{frames: array<int, string>, nodes: string, digest: string}
     */
    public static function of(Recorder $recorder): array
    {
        $frames = [];
        foreach ($recorder->frames() as $id => $frame) {
            $frames[(int) $id] = self::hash(self::ofFrame($frame));
        }

        $nodes = [];
        foreach ($recorder->nodeIndex() as $id => $meta) {
            $nodes[(int) $id] = [$meta['c'], $meta['p']];
        }
        \ksort($nodes);

        return [
            'frames' => $frames,
            'nodes' => self::hash($nodes),
            'digest' => self::hash(['frames' => $frames, 'nodes' => $nodes]),
        ];
    }

    /**
     * 外部条件指纹：**只覆盖"外部给了什么"，不覆盖"多久回来"**。
     *
     * 覆盖：请求的 method、脱敏后的 URL 模板、`status`、出现顺序。
     * 不覆盖：帧号（`startedAtFrame` / `settledAtFrame`）、`elapsedMs`、`bytes`、
     * `landed` 节点 —— 那些是这台机器这一次的运气，或者已经属于因果事实。
     *
     * ## 为什么不含输入事件
     *
     * 输入在 {@see Script::digest()} 里，已经进了驱动指纹。重复计入的后果不是
     * "多算一遍"，而是**诊断价值归零**：两份证据驱动不同时，`drive` 与 `external`
     * 同时红，于是分不清"是我驱动得不一样"还是"外部条件不一样"，而两者的修复
     * 动作相反（改脚本 vs 改 mock / 等外部）。
     *
     * ## 为什么 URL 要模板化
     *
     * 签名参数（`?sig=…&ts=…`）每跑一次都不同，直接进指纹会让它**恒红**——
     * 与 A38 把 URL 从因果边里赶出去是同一个理由。数字路径段同理（`/items/123`
     * 与 `/items/124` 是同一类请求）。
     */
    public static function ofExternal(Recorder $recorder): string
    {
        $items = [];
        foreach ($recorder->networkRows() as $row) {
            $items[] = [$row->method, self::urlTemplate($row->url), $row->status];
        }

        return self::hash($items);
    }

    /** 去掉 query / fragment，数字路径段收成 `:n`。 */
    private static function urlTemplate(string $url): string
    {
        $base = \substr($url, 0, \strcspn($url, '?#'));
        $segments = \explode('/', $base);
        foreach ($segments as $i => $segment) {
            if ($segment !== '' && \ctype_digit($segment)) {
                $segments[$i] = ':n';
            }
        }

        return \implode('/', $segments);
    }

    /**
     * 稳定的哈希：键序固定（构造顺序即语义顺序），JSON 不转义、不带浮点。
     *
     * @param array<string, mixed>|list<mixed>|int|string $value
     */
    public static function hash(array|int|string $value): string
    {
        return \hash('sha256', \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '');
    }

    /** 给人看的短指纹（12 位足够区分，也放得进一行报告）。 */
    public static function short(string $hash): string
    {
        return \substr($hash, 0, 12);
    }
}
