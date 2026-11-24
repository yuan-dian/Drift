<?php
declare(strict_types=1);

namespace Drift\Dev\Debug;

/**
 * 观察者的读位置：**哪个会话的第几帧**。两项缺一不可。
 *
 * ## 为什么不能只用帧号
 *
 * 帧号是每会话从 1 重发的。宿主重启后，`since=590` 落到新会话上意味着"所有帧都
 * 已读过" —— 于是查询返回空，观察者以为"没有新帧"，实际是"时间线换了，一切从头"。
 * 这种错不会报错，只会让画面永远停在上一次的内容上。
 *
 * ## 为什么住在消费者侧而不是采集层
 *
 * 它记的是"读到哪里"，不是"采到了什么"。放进 `Recorder` 会让采集层反过来知道
 * "有人读到第几帧了"——于是回滚、裁剪、`--loop` 重跑都得考虑读者的位置，而录制
 * 本来不该知道有没有人在读。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class Cursor
{
    public function __construct(
        public readonly string $session,
        public readonly int $frame = 0,
    ) {
    }

    /** 新会话的起点：`since = 0` 意思是"从头给"（`frames()` 会跳过 `id <= 0`，即全给）。 */
    public static function fresh(string $session): self
    {
        return new self($session, 0);
    }

    /**
     * 这份 `frames` 响应是不是我这个会话的。
     *
     * 响应里没有 `session` 键时按"是"处理：那是还没带会话概念的旧宿主，老游标
     * 逻辑（裸帧号）在它上面仍然成立，此时重置反而会白丢一遍历史。
     *
     * @param array<string, mixed> $response
     */
    public function isFreshFor(array $response): bool
    {
        return !\array_key_exists('session', $response) || $response['session'] === $this->session;
    }

    /**
     * 推进到响应里的最后一帧。会话不符时**回到新会话的起点**，不是接着往下数。
     *
     * 返回新实例而不是原地改：调用方手里可能还留着上一份游标（比如一次查询失败
     * 要重试），共享可变状态会让"重试"变成"重试一次跳两帧"。
     *
     * @param array<string, mixed> $response
     */
    public function advance(array $response): self
    {
        if (!\array_key_exists('session', $response)) {
            return $this->withLastFrameOf($response);
        }

        $session = (string) $response['session'];
        if ($session !== $this->session) {
            return new self($session, 0);
        }

        return $this->withLastFrameOf($response);
    }

    /**
     * 往后走，绝不往前走。
     *
     * 两个观察者共用一个游标、或者响应乱序到达时，"较小的一批"不该把位置拉回去——
     * 那会让已经看过的帧再被算作新帧。
     *
     * @param array<string, mixed> $response
     */
    private function withLastFrameOf(array $response): self
    {
        $last = $this->frame;
        $frames = $response['frames'] ?? null;
        if (\is_array($frames)) {
            foreach ($frames as $frame) {
                $id = \is_array($frame) ? (int) ($frame['id'] ?? 0) : 0;
                if ($id > $last) {
                    $last = $id;
                }
            }
        }

        return $last === $this->frame ? $this : new self($this->session, $last);
    }
}
