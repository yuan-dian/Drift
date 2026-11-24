<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

/**
 * 时间轴上的一帧：一行一个帧号，一条横条表示墙钟相对本窗口最大值的比例。
 *
 * ## 为什么用横条而不是只给数字
 *
 * 一列 "40.31" 得逐行读才知道哪帧慢；横条把"哪几帧贵"变成一眼。同理"这次动作
 * 之后有几帧"也是形状：静止帧是短条，动作帧是一根长条。
 *
 * 归一化取**窗口内最大值**而不是某个绝对阈值：阈值要跟着机器与页面走，猜错了
 * 满屏都是满条或都是空条。代价是条长只在一次渲染内可比 —— 帧表本来就是这个口径。
 *
 * @internal 面板内部数据
 */
final class FrameRow implements Line
{
    public function __construct(
        public readonly int $frameId,
        public readonly float $wall,
        public readonly int $rebuilt,
        public readonly int $causes,
        /** 当前树看的是这一帧时置位（帧表里标出来，免得"我在看哪帧"要靠翻页找） */
        public readonly bool $current,
        public readonly string $bar,
    ) {
    }

    public function key(): string
    {
        return 'f:' . $this->frameId;
    }

    public function text(): string
    {
        return \sprintf(
            '%s %-6d %8.2fms  %-20s %4d 重建%s',
            $this->current ? '*' : ' ',
            $this->frameId,
            $this->wall,
            $this->bar,
            $this->rebuilt,
            $this->causes > 0 ? '' : '  （静止）',
        );
    }

    /**
     * 横条：`width` 格，按 `$value / $max` 取整。
     *
     * 非零值至少给一格 —— 0.4ms 的帧与 0.0ms 的"没跑"是两件事，四舍五入成空条会
     * 把它们混成一件。真空值（0.0）必须留空，否则"没量到"和"量到很小"分不开。
     */
    public static function bar(float $value, float $max, int $width = 16): string
    {
        if ($max <= 0.0 || $value <= 0.0) {
            return '';
        }
        $cells = (int) \round($value / $max * $width);
        $cells = \max(1, \min($width, $cells));

        return \str_repeat('█', $cells);
    }
}
