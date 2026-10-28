<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Widget\State;
use Drift\Widget\Table;
use Drift\Widget\TableColumn;
use Drift\Widget\TableColumnWidth;

/**
 * 列虚拟化（阶段 6）：宽表只造看得见的列。
 *
 * 三条判据（`docs/DESIGN-TABLE-2026-10-26.md` §6 阶段 6）：
 * ① 宽表只造可见列（8 列 → 3 列）；
 * ② 横滚在缓冲带内 ⇒ 0 重建、跨出去 ⇒ 恰好 1 次；
 * ③ 裁列后**列位不漂**（补回的前缀偏移必须精确，否则横向越滚越偏）。
 *
 * ★ 反例自检（实测）：① 不裁列 ⇒ ①红（3 → 8）；② 横滚无条件标脏 ⇒ ②红（0 → 1）；
 *   ③ 不补前缀偏移 ⇒ ③红（首列 x = −300 而不是 0）；表头漏补 ⇒ 最后一条红。
 */
final class ColumnWindowTest extends SmokeTestCase
{
    public function testOnlyVisibleColumnsAreBuilt(): void
    {
        $tableMount = Fixtures::mount(...);
        $tableStateOf = Fixtures::stateOf(...);
        $tableFrame = Fixtures::frame(...);
        $textXOf = Fixtures::textXOf(...);

        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $row = ['id' => $i];
            for ($c = 0; $c < 8; $c++) {
                $row['c' . $c] = 'v' . $i . '-' . $c;
            }
            $rows[] = $row;
        }

        $columns = [];
        for ($c = 0; $c < 8; $c++) {
            $columns[] = TableColumn::make('c' . $c, 'C' . $c)
                ->field('c' . $c)
                ->width(TableColumnWidth::fixed(150.0));
        }

        // ★ `cacheExtent(0)`：窗口边界才可精确断言（有缓冲带时"第几列起"取决于默认值 200）。
        $el = $tableMount(
            Table::make($columns, $rows)
                ->keyOf(static fn(array $row): int => $row['id'])
                ->rowHeight(40.0)
                ->headerHeight(32.0)
                ->cacheExtent(0.0),
        );
        $state = $tableStateOf($el);

        check(
            '阶段 6 · 宽表 8 列 × 150 / 视口 320 ⇒ 只造 3 列（虚拟化生效）',
            $state?->createdColumns() === 3,
            'created=' . (string) ($state?->createdColumns() ?? -1),
        );
        check(
            '阶段 6 · 首列可见（第 0 列真的造出来了）',
            $textXOf($el, 'v0-0') !== null,
            (string) ($textXOf($el, 'v0-0') ?? 'null'),
        );

        // ★ 横滚 100px：窗口 [100,420) 仍在 [0,450) 内 ⇒ 不该重建（否则"滚一点就重排全表"）
        $x0 = $textXOf($el, 'v0-0');
        $before = State::rebuiltCount();
        $state?->scrollByAxis(Axis::Horizontal, 100.0);
        $tableFrame($el);
        $inBand = State::rebuiltCount() - $before;
        check(
            '阶段 6 · 横滚 100px 未跨列 ⇒ 重建 0 次（窗口盖得住就不换列）',
            $inBand === 0,
            'rebuilt=' . $inBand,
        );

        // ★ 再滚 200（累计 300）：跨出 [0,450) ⇒ 必须换一批列
        $before = State::rebuiltCount();
        $state?->scrollByAxis(Axis::Horizontal, 200.0);
        $tableFrame($el);
        $cross = State::rebuiltCount() - $before;
        check(
            '阶段 6 · 横滚跨出缓冲带 ⇒ 恰好重建 1 次（换列不是缺陷）',
            $cross === 1,
            'rebuilt=' . $cross,
        );
        check(
            '阶段 6 · 换列后仍是 3 列（新窗口同样只造可见的）',
            $state?->createdColumns() === 3,
            'created=' . (string) ($state?->createdColumns() ?? -1),
        );

        // ★★ 最关键的一条：offsetX=300 时第 2 列的屏幕 x 必须与 offsetX=0 时第 0 列的 x 相同。
        //   两者内容坐标差 300、偏移差 300 ⇒ 屏幕坐标必须相等。少补前缀偏移时第 2 列会落在
        //   −300（直接被裁掉），这条立刻红。
        $x2 = $textXOf($el, 'v0-2');
        check(
            '阶段 6 · 裁列后列位不漂（第 2 列 x == 第 0 列原 x，前缀偏移精确）',
            $x0 !== null && $x2 !== null && abs($x2 - $x0) < 0.5,
            'x0=' . (string) $x0 . ' x2=' . (string) $x2,
        );

        // ★ 表头与行区**各写了一遍**前缀偏移（`headerRow()` / 行构造）。阶段 1 闸门第 3 条
        //   的原话是"两处各算一次必然漂"—— 这里正好是那个易漂点：只给行补、忘了表头，
        //   两边就差一个 `$colLeading`。表头第 2 列的标题必须与数据格同一 x。
        $headX2 = $textXOf($el, 'C2');
        check(
            '阶段 6 · 表头与数据行对齐（表头 C2 的 x == 数据格 v0-2 的 x）',
            $headX2 !== null && $x2 !== null && abs($headX2 - $x2) < 0.5,
            'head=' . (string) $headX2 . ' cell=' . (string) $x2,
        );

        $this->flushChecks();
    }
}
