<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Tests\Support\GridFixtures;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Widget\Grid;
use Drift\Widget\Text;

/**
 * `Grid` 的切块判据（W8-3，从 smoke 迁出）。
 *
 * ## 迁移时的有效性审计（2026-10-28 第 23 批）
 *
 * - **合并**：原 smoke 的「末行补了 1 个占位（3 个子）」与「每格等宽」是**同一条语义**
 *   —— 后者比较整行宽度数组，末行若只有 2 子则数组长度对不上、直接红。故合并进
 *   `testEveryCellSameWidthAndLastRowPadded`，子数进 detail（少一条冗余断言）。
 * - **有效性**：四条都落在被测语义上（行数 / 格宽 / 行高与行距 / 构造期守卫）。
 *   去掉 `Grid::rowOf()` 补 `Spacer` 那段 ⇒ 格宽 104→162，第二条立刻红。
 * - **假绿排查**：`cellWidths()` 对未排子盒返回 -1，比较 104 会红（不是 null 恒真）；
 *   行数组取下标前都加 `count` 守卫，避免"未定义下标 warning"被当成通过。
 */
final class GridTest extends SmokeTestCase
{
    public function testChunksIntoRows(): void
    {
        // ① 5 个子 / 3 列 ⇒ ceil(5/3) = 2 行
        $rows = GridFixtures::rowBoxes(GridFixtures::mount(
            Grid::make(3)->spacing(12.0)->children(self::cells(5)),
        ));
        check(
            'Grid · 5 个子 / 3 列 ⇒ 2 行（ceil(5/3)）',
            \count($rows) === 2,
            'rows=' . \count($rows),
        );
    }

    public function testEveryCellSameWidthAndLastRowPadded(): void
    {
        // ② ★ 核心判据：末行不满时格宽与首行**相同**（靠 `Spacer` 占位）。
        //   合并了原「末行补了 1 个占位」—— 下面的整行数组比较已经蕴含"末行有 3 个子"。
        $rows = GridFixtures::rowBoxes(GridFixtures::mount(
            Grid::make(3)->spacing(12.0)->children(self::cells(5)),
        ));
        $widths = \array_map(
            static fn($row): array => GridFixtures::cellWidths($row),
            $rows,
        );
        check(
            'Grid · 每格等宽 = (336−12×2)/3 = 104，末行不满也不变宽（末行补足 3 子）',
            $widths === [[104.0, 104.0, 104.0], [104.0, 104.0, 104.0]],
            var_export($widths, true),
        );
    }

    public function testRowHeightAndSpacing(): void
    {
        // ③ cellHeight(50) + spacing(12) ⇒ 行高 50、第二行 offsetY 62
        $rows = GridFixtures::rowBoxes(GridFixtures::mount(
            Grid::make(3)->spacing(12.0)->cellHeight(50.0)->children(self::cells(5)),
        ));
        $first = \count($rows) >= 1 ? $rows[0]->size() : null;
        $secondY = \count($rows) >= 2 ? $rows[1]->offsetY() : -1.0;
        check(
            'Grid · cellHeight(50) ⇒ 行高 50，行距 12 ⇒ 第二行 offsetY = 62',
            $first !== null && $first->height === 50.0 && $secondY === 62.0,
            'h=' . var_export($first?->height, true) . ' y2=' . $secondY,
        );
    }

    public function testZeroColumnsFailsLoudly(): void
    {
        // ④ 列数非法 ⇒ 构造期响亮失败（G1：不留到布局期变成"没格子"）
        //   ★ 子进程：`Errors::report` 走 `abort()` ⇒ `exit(3)`，进程内 try/catch 接不住。
        $log = \sys_get_temp_dir() . '/drift-gridzero-' . \getmypid() . '.log';
        @\unlink($log);
        [$code, $out] = runFatalCase('gridzero', $log);
        $text = \is_file($log) ? (string) \file_get_contents($log) : '';
        @\unlink($log);
        check(
            'Grid · 列数 0 ⇒ 构造期响亮失败（exit 3，不是"没格子"）',
            $code === 3 && \str_contains($text, '列数必须 ≥ 1'),
            'code=' . $code . ' out=' . $out,
        );
    }

    /** @return list<Text> */
    private static function cells(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = Text::make('格 ' . $i);
        }

        return $out;
    }
}
