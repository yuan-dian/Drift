<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

use Drift\DevTools\Session\Digest;

/**
 * 高亮视图：把 `highlight` 查询 + 叠加前后的像素指纹压成几行。
 *
 * ## 为什么这里要带像素
 *
 * "请求了高亮"与"高亮到了屏幕上"是两件事，中间隔着一次绘制。查询面的
 * `onScreen` 是**录制器自报**（壳在 afterPaint 里报了"我画了"），而自报是
 * 可以被写坏的：`devFacts` 少传一个键、`endFrame` 少读一帧，"报了"与"画了"
 * 就分家。像素指纹是唯一不依赖那条链的证据 —— 所以这里两个都给，读者能看出
 * 它们是否互相印证。
 *
 * ★ 像素指纹只在**同一个会话里前后两次**有意义：它含底色 / 主题 / 视口，
 *   跨机器跨版本不可比。这不是缺陷，是它的用途决定的（判"变了没"，不是判"是什么"）。
 *
 * ## 两条纪律（与其它视图同）
 *
 * 1. **纯函数**：同样的输入 ⇒ 逐字节相同的行；不读时钟、不读环境。
 * 2. **自报缺失**：拿不到像素时明说"未取像素"，不打印一个假的"未变"。
 *
 * @internal 证据层视图；`tools/debug-highlight.php` 是它唯一的调用方
 */
final class Highlight
{
    /**
     * @param array<string, mixed> $answer   `highlight` 查询的返回
     * @param string|null $before 高亮前的像素指纹（`null` = 没取）
     * @param string|null $after  高亮后的像素指纹（`null` = 没取）
     *
     * @return list<string>
     */
    public static function lines(array $answer, ?string $before = null, ?string $after = null): array
    {
        $node = (int) ($answer['node'] ?? 0);
        $asked = (int) ($answer['askedFrame'] ?? 0);
        $drawn = $answer['drawnFrame'] ?? null;
        $onScreen = ($answer['onScreen'] ?? false) === true;

        if ($node === 0) {
            $lines = ['高亮：无（已清除）'];
            if ($drawn !== null) {
                // 历史事实照实说：清掉之后屏幕上没有叠加，但"最后一次画的是谁"仍在。
                $lines[] = \sprintf('  最后一次画过的：第 %s 帧 / #%s（现在不在屏上）', (string) $drawn, (string) ($answer['drawnNode'] ?? '?'));
            }

            return [...$lines, ...self::pixelLines($before, $after)];
        }

        $lines = [
            \sprintf(
                '高亮 #%d  %s（%s）',
                $node,
                (string) ($answer['type'] ?? '?'),
                (string) ($answer['path'] ?? '?'),
            ),
            \sprintf(
                '  请求帧 %d · 上屏帧 %s · 状态 %s',
                $asked,
                $drawn === null ? '未画过' : (string) $drawn,
                $onScreen ? '已在屏上' : '还没上屏',
            ),
        ];

        if (!$onScreen) {
            // 三种都让 `onScreen` 为假，修法完全不同 —— 逐个说清，别让人猜。
            $lines[] = match (true) {
                $drawn === null => '  ⚠ 一帧都还没画过：高亮只在**下一次绘制**发生时才可见（推一帧）',
                (int) $drawn < $asked => '  ⚠ 最后一次画的是更早的一帧：再推一帧才轮到这个高亮',
                default => '  ⚠ 屏幕上是**另一个**节点的叠加（清掉再一次高亮同一个号会这样）',
            };
        }

        return [...$lines, ...self::pixelLines($before, $after)];
    }

    /** @return list<string> */
    private static function pixelLines(?string $before, ?string $after): array
    {
        if ($before === null || $after === null) {
            return ['  像素：未取（这个出口没拿到表面）'];
        }

        return [
            \sprintf(
                '  像素 %s → %s（%s）',
                Digest::short($before),
                Digest::short($after),
                $before === $after ? '没变：叠加层没画出来' : '变了：叠加层上了屏',
            ),
        ];
    }
}
