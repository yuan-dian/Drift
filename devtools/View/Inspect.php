<?php
declare(strict_types=1);

namespace Drift\DevTools\View;

use Drift\DevTools\Inspect\Inspector;
use Drift\DevTools\Session\Digest;

/**
 * 检查器叠加视图：把 `inspect` 回执 + 叠加前后的像素指纹压成几行。
 *
 * 与 `Highlight` 同一条纪律：**"开了"与"画了"是两个事实**，两个都印。
 * 只印层名的实现会让"`box-model` 忘了把节点号接上"看起来完全正常 ——
 * 打开着、回执说开着，屏幕上一片空白。
 *
 * 三种"什么都没出现"要分开说，因为修法不同：
 *   - 全部层都关：命令语义是"清屏"，不是失败；
 *   - `box-model` 开着但没有选中节点：先 `highlight`；
 *   - 画过但上屏帧是旧值 / 一帧没画：叠加只在下一次绘制里出现。
 *
 * @internal 输出层视图；`devtools/bin/debug-inspect.php` 是它唯一的调用方
 */
final class Inspect
{
    /**
     * @param list<string> $layers      实际生效的层
     * @param int|null     $drawnFrame  最近一次真的上屏的帧号（`null` = 还没画过）
     * @param bool         $hasSelection 当前有没有选中的节点
     * @param string|null  $before      叠加前的像素指纹（`null` = 没取）
     * @param string|null  $after       叠加后的像素指纹（`null` = 没取）
     *
     * @return list<string>
     */
    public static function lines(
        array $layers,
        ?int $drawnFrame,
        bool $hasSelection,
        ?string $before = null,
        ?string $after = null,
    ): array {
        if ($layers === []) {
            $lines = ['叠加层：全关'];
            if ($drawnFrame !== null) {
                $lines[] = \sprintf('  最后一次画过的是第 %d 帧（现在屏幕上是干净的）', $drawnFrame);
            }

            // 全关时不比像素：这条命令的语义就是"清屏"，"没变"不是失败。
            return $lines;
        }

        $lines = [
            '叠加层：' . \implode('  ', $layers),
            \sprintf('  上屏帧：%s', $drawnFrame === null ? '还没画过' : (string) $drawnFrame),
        ];

        // "层 → 前置条件"只有一份声明（`Inspector::NEEDS_SELECTION`）：这里只
        // 负责把命中的层名念出来，不在视图里再维护第二份层表。
        $needs = \array_values(\array_intersect($layers, Inspector::NEEDS_SELECTION));
        if ($needs !== [] && !$hasSelection) {
            $lines[] = \sprintf(
                '  ⚠ %s 需要先选中一个节点：debug-highlight --node=N（或 Web 里点一个）',
                \implode(' / ', $needs),
            );
        }

        if ($drawnFrame === null) {
            $lines[] = '  ⚠ 一帧都还没画过：叠加只在**下一次绘制**时出现（推一帧 / 让应用动一下）';
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
