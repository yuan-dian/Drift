<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Tests\Support\GridFixtures;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Layout\RenderBox;
use Drift\Widget\LazyGrid;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * `LazyGrid` 的虚拟化与几何判据（W8-3，从 smoke 迁出）。
 *
 * `LazyGrid` = `LazyColumn` 套 `Grid`：分行规则只有一份（与 `Grid` 逐像素一致），
 * 但行节距是**行高 + 行间距**，格子高 = 节距 − 间距。
 *
 * ## 迁移时的有效性审计（2026-10-28 第 23 批）
 *
 * - **有效性**：四条落在被测语义上 —— 虚拟化（视口只造 ceil(240/96)=3 行）/ 格宽与
 *   全量版一致 / 格子高 = 节距−间距 / 末行不变宽 / 构造期守卫。删掉"末行补 Spacer"
 *   或把行节距算错，对应条必红。
 * - **假绿排查**：`$rows[0]->children()[0]` 取下标前都判 `count` 与 `children()` 非空，
 *   避免"未定义下标 warning + null"被当成通过；`absoluteOrigin()` 是父链累加的唯一
 *   出处（行盒 offsetY 恒 0，因为它相对 `SizedBox` 节距盒，量它得不出行距）。
 */
final class LazyGridTest extends SmokeTestCase
{
    public function testViewportVirtualizesRows(): void
    {
        // ⑤ 100 项 / 3 列 = 34 行，视口 240 / 节距 96 ⇒ 只造 3 行（与总行数无关）
        $rows = GridFixtures::rowBoxes(GridFixtures::mount(
            self::lazyGrid(100),
        ));
        check(
            'LazyGrid · 100 项 / 3 列 = 34 行，视口只造 3 行（虚拟化与行数无关）',
            \count($rows) === 3,
            'rows=' . \count($rows),
        );
    }

    public function testCellWidthMatchesFullGrid(): void
    {
        // ⑥ 格宽与全量版**逐像素一致** = 104（分行规则只有一份）
        $rows = GridFixtures::rowBoxes(GridFixtures::mount(self::lazyGrid(100)));
        $widths = \count($rows) >= 1 ? GridFixtures::cellWidths($rows[0]) : [];
        check(
            'LazyGrid · 格宽与全量版逐像素一致 = 104（分行规则只有一份）',
            $widths === [104.0, 104.0, 104.0],
            var_export($widths, true),
        );
    }

    public function testCellHeightAndRowPitch(): void
    {
        // ⑦ 行节距 96、行间距 12 ⇒ 格子高 84；第二行绝对 y = 96
        //   ★ 量行盒的 offsetY 恒 0（它相对 SizedBox 节距盒）⇒ 必须用 absoluteOrigin()
        $rows = GridFixtures::rowBoxes(GridFixtures::mount(self::lazyGrid(100)));
        $cellSize = (\count($rows) >= 1 && \count($rows[0]->children()) >= 1)
            ? $rows[0]->children()[0]->size()
            : null;
        $row1Y = \count($rows) >= 2 ? RenderBox::absoluteOrigin($rows[1])[1] : -1.0;
        check(
            'LazyGrid · 格子高 = 行节距 96 − 行距 12 = 84；行节距 96 ⇒ 第二行绝对 y = 96',
            $cellSize !== null && $cellSize->height === 84.0 && $row1Y === 96.0,
            'cellH=' . var_export($cellSize?->height, true) . ' y2=' . $row1Y,
        );
    }

    public function testLastRowNotWidened(): void
    {
        // ⑧ 末行不满在懒版里同样成立（5 项 ⇒ 2 行，末行 2 格 + 占位，格宽仍 104）
        $rows = GridFixtures::rowBoxes(GridFixtures::mount(self::lazyGrid(5)));
        $lastWidths = \count($rows) >= 2 ? GridFixtures::cellWidths($rows[1]) : [];
        check(
            'LazyGrid · 末行不满也不变宽（5 项 ⇒ 2 行，末行 2 格 + 占位，格宽仍 104）',
            \count($rows) === 2 && $lastWidths === [104.0, 104.0, 104.0],
            'rows=' . \count($rows) . ' w=' . var_export($lastWidths, true),
        );
    }

    public function testSpacingAtLeastPitchFailsLoudly(): void
    {
        // ⑨ 行间距 ≥ 行节距 ⇒ 响亮失败（不留一个 0 高的网格）
        //   ★ 子进程：守卫在 `build()` 里 `Errors::report` ⇒ `abort()` ⇒ `exit(3)`。
        $log = \sys_get_temp_dir() . '/drift-lazygridpitch-' . \getmypid() . '.log';
        @\unlink($log);
        [$code, $out] = runFatalCase('lazygridpitch', $log);
        $text = \is_file($log) ? (string) \file_get_contents($log) : '';
        @\unlink($log);
        check(
            'LazyGrid · 行间距 ≥ 行节距 ⇒ 响亮失败（不留一个 0 高的网格）',
            $code === 3 && \str_contains($text, '行节距必须大于行间距'),
            'code=' . $code . ' out=' . $out,
        );
    }

    private static function lazyGrid(int $count): LazyGrid
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $i;
        }

        return LazyGrid::make(3, 96.0)
            ->spacing(12.0)
            ->items($items, static fn(int $i): Widget => Text::make('项 ' . $i));
    }
}
