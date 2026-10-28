<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Input\PointerEvent;
use Drift\Rendering\ColorToken;
use Drift\Theme\ThemeData;
use Drift\Widget\TableSelection;
use Drift\Widget\TableSelectionMode;

/**
 * 选择（受控）+ 行态（悬停 / 选中）。
 *
 * 三条判据（`docs/DESIGN-TABLE-2026-10-26.md` §4 裁决 4）：
 * ① 选中集是**受控值对象**：应用持有、控件只上报"点完之后应该是什么"；
 * ② Shift 连选的真前置是 `PointerEvent` 的修饰键；
 * ③ 行态在**行容器**上合一，`cellBuilder` 不知道自己被选中。
 *
 * ★ 语义底稿在 `tests/Unit/Widget/TableRowPickerTest`（纯函数）。这里只验
 *   "行容器的 pointer 真的把事件送到了语义层、上报真的到了应用、行态真的画出来了"。
 * ★ 这里**没有** `runCase` 那两条（声明 selection 不接上报 ⇒ 退出码 3）：
 *   子进程用例留在 `tools/smoke.php`。理由是它的判据是"进程退出码"，
 *   PHPUnit 里跑要自己 `proc_open`，且它测的是 `Errors` 的上报链路，
 *   与"改 Table 只跑 Table"无关。
 */
final class SelectionTest extends SmokeTestCase
{
    public function testTapSemanticsReachTheController(): void
    {
        $tableMount = Fixtures::mount(...);
        $tableRowElsOf = Fixtures::rowElsOf(...);
        $tableTapRow = Fixtures::tapRow(...);
        $tableBuild = Fixtures::build(...);

        // ② 点击语义：无修饰 ⇒ 替换
        $sink = new SelectionSink();
        $el = $tableMount(Fixtures::selectable(TableSelection::none(), null, $sink));
        $tableTapRow($el, 0, PointerEvent::down(10.0, 10.0));
        check(
            'Table · 无修饰点行 ⇒ 替换为这一行（不是"加上"）',
            $sink->last?->keys() === [0],
            \json_encode($sink->last?->keys()),
        );

        // Ctrl ⇒ 反选并保留已有（前置：行 0 已选）
        $sink = new SelectionSink();
        $el = $tableMount(Fixtures::selectable(TableSelection::of([0]), null, $sink));
        $tableTapRow($el, 1, PointerEvent::down(10.0, 10.0, 0, true));
        check(
            'Table · Ctrl 点行 ⇒ 反选并保留已有（{0} + Ctrl 点 1 ⇒ {0,1}）',
            $sink->last?->keys() === [0, 1],
            \json_encode($sink->last?->keys()),
        );

        // Shift ⇒ 从锚点到该行的区间（前置：先无修饰点行 0 立锚点）
        $sink = new SelectionSink();
        $el = $tableMount(Fixtures::selectable(TableSelection::of([0]), null, $sink));
        $tableTapRow($el, 0, PointerEvent::down(10.0, 10.0));
        $tableTapRow($el, 3, PointerEvent::down(10.0, 10.0, 0, false, false, true));
        check(
            'Table · Shift 点行 ⇒ 锚点到该行的区间被整体选中（0..3）',
            $sink->last?->keys() === [0, 1, 2, 3],
            \json_encode($sink->last?->keys()),
        );

        // 单选模式 ⇒ 永远只有一个
        $sink = new SelectionSink();
        $el = $tableMount(Fixtures::selectable(TableSelection::of([0]), TableSelectionMode::Single, $sink));
        $tableTapRow($el, 3, PointerEvent::down(10.0, 10.0, 0, true));
        check(
            'Table · 单选模式忽略 Ctrl ⇒ 仍是替换（只剩这一行）',
            $sink->last?->keys() === [3],
            \json_encode($sink->last?->keys()),
        );

        // ③ 纯展示表不挂交互声明（"悬停高亮"是"这里能点"的暗示，不该给）
        $plain = $tableMount($tableBuild(3));
        $plainRows = $tableRowElsOf($plain);
        check(
            'Table · 未声明 selection / onRowTap ⇒ 行不挂 pointer（纯展示表零开销）',
            $plainRows !== [] && $plainRows[0]->widget()->pointerHandlerValue() === null,
            $plainRows === [] ? '没有行' : ($plainRows[0]->widget()->pointerHandlerValue() === null ? '没挂' : '挂了'),
        );

        $this->flushChecks();
    }

    public function testRowInkForSelectedAndHovered(): void
    {
        $tableMount = Fixtures::mount(...);
        $tableBoxOf = Fixtures::boxOf(...);
        $tableRasterOf = Fixtures::rasterOf(...);
        $tableRowElsOf = Fixtures::rowElsOf(...);

        // ④ 选中行是淡 Accent（压在表格底 Surface 上），未选中且偶数行是通透的白
        $sink = new SelectionSink();
        $inkEl = $tableMount(Fixtures::selectable(TableSelection::of([0]), null, $sink));
        $raster = $tableRasterOf($tableBoxOf($inkEl), 320, 240);
        $sel = $raster->pixelAt(305, 52);     // 第 0 行（选中）
        $idle = $raster->pixelAt(305, 132);   // 第 2 行（未选中、偶数行 ⇒ 透出表格底）
        check(
            'Table · 像素：选中行被染成淡 Accent（蓝 ≥ 红），未选中行是表格底的白',
            $sel->b > $sel->r + 15
                && $idle->r === 255 && $idle->g === 255 && $idle->b === 255,
            \sprintf(
                '选中 rgb(%d,%d,%d) / 未选 rgb(%d,%d,%d)',
                $sel->r, $sel->g, $sel->b,
                $idle->r, $idle->g, $idle->b,
            ),
        );

        // ⑤ 悬停行：`Hover` 底色（不透明）压过斑马
        $sink = new SelectionSink();
        $hoverEl = $tableMount(Fixtures::selectable(TableSelection::none(), null, $sink));
        $hoverRows = $tableRowElsOf($hoverEl);
        $handler = $hoverRows[1]->widget()->hoverHandlerValue();
        $handler?->__invoke(true);
        Fixtures::pump($hoverEl);
        $hoverPx = $tableRasterOf($tableBoxOf($hoverEl), 320, 240)->pixelAt(305, 92);
        $hoverInk = ThemeData::light()->color(ColorToken::Hover);
        check(
            'Table · 像素：悬停行是 `Hover` 底色（第 1 行，压过它的斑马底）',
            $hoverPx->equals($hoverInk),
            \sprintf(
                'rgb(%d,%d,%d) vs 期望 rgb(%d,%d,%d)',
                $hoverPx->r, $hoverPx->g, $hoverPx->b,
                $hoverInk->r, $hoverInk->g, $hoverInk->b,
            ),
        );

        $this->flushChecks();
    }
}
