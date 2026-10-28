<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Dev\Smoke\App\TableResizeApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\CursorShape;
use Drift\Input\PointerEvent;
use Drift\Rendering\ColorToken;
use Drift\Theme\ThemeData;
use Drift\Widget\Element;
use Drift\Widget\Table;
use Drift\Widget\TableColumn;
use Drift\Widget\TableColumnWidth;
use Drift\Widget\Widget;

/**
 * 列宽拖拽（受控第 ④ 层 + 手柄是表头格的子 widget）。
 *
 * 三件事：① 手柄在不在 / 只挂在 `resizable()` 的列上；
 * ② 拖动上报的值对不对（含 clamp 与"没拖动就不报"）；③ 第 ④ 层真的压过 ①②③。
 *
 * ★ 分割线（⑩）也在这里：它存在的理由是"手柄是 5px 的**透明**命中区，
 *   光标变 `SizeWE` 只在已经命中之后才出现 ⇒ 用户没法在移动前看到边界在哪"。
 *   所以必须验两层：结构（每格都有线、1px、全表头高、不占列宽）+ 像素
 *   （线真的画出来了，取色随悬停/拖动变）。只验结构会漏掉"线被盖住 / 取色没接上"。
 *
 * ⚠️ 除 `testRealRoute*` 外，全部直接调 `pointerHandlerValue()`，**验不出
 *   `ownsPress`**（绕过了 `InputRouter::pointerOwner()` 的父链遍历）。
 *   那条由"手柄是 Stack 的子、比整格更近"保证，真机拖动是它的最终验收。
 *
 * ★ 两条子进程断言（`resizable()` 没接 `onColumnResize` ⇒ 退出码 3）留在
 *   `tools/smoke.php`。
 */
final class ColumnResizeTest extends SmokeTestCase
{
    public function testResizableIsOptIn(): void
    {
        check(
            'TableColumn · resizable() 默认关（不接上报就不该出现手柄）',
            TableColumn::make('x', 'X')->isResizable() === false
                && TableColumn::make('x', 'X')->resizable()->isResizable() === true,
            TableColumn::make('x', 'X')->isResizable() ? '默认开' : '默认关',
        );

        $this->flushChecks();
    }

    public function testHandleOnlyOnResizableColumns(): void
    {
        $tableMount = Fixtures::mount(...);
        $headerCellsOf = Fixtures::headerCellsOf(...);
        $headerRowOf = Fixtures::headerRowOf(...);
        $handleOf = Fixtures::handleOf(...);
        $widthsOf = Fixtures::widthsOf(...);

        $sink = new ResizeSink();
        $el = $tableMount(Fixtures::resizable([], $sink));
        $cells = $headerCellsOf($el);
        $row = $headerRowOf($el);
        $widths = $row === null ? [] : $widthsOf($row);
        $handle = $handleOf($cells[1] ?? null);

        check(
            'Table · 手柄只挂在 resizable() 的列上，光标 SizeWE',
            $handle !== null
                && $handle->widget()->cursorShape() === CursorShape::SizeWE
                && $handleOf($cells[2] ?? null) === null,
            $handle === null ? '第 2 列没有手柄' : '第 2 列有手柄，第 3 列没有',
        );
        check(
            'Table · 手柄是浮层，不占列宽（3 列宽序列仍是 [60, 130, 130]）',
            \count($widths) === 3
                && abs($widths[0] - 60.0) < 0.5
                && abs($widths[1] - 130.0) < 0.5
                && abs($widths[2] - 130.0) < 0.5,
            \json_encode($widths),
        );

        $this->flushChecks();
    }

    public function testDragReportsTwiceClampedAndOnlyAfterMove(): void
    {
        $tableMount = Fixtures::mount(...);
        $headerCellsOf = Fixtures::headerCellsOf(...);
        $headerRowOf = Fixtures::headerRowOf(...);
        $handleOf = Fixtures::handleOf(...);
        $widthsOf = Fixtures::widthsOf(...);
        $fire = Fixtures::fireOnHandle(...);

        // ③ 拖动：move 上报 finished=false，up 再报一次 finished=true，值相同
        $sink = new ResizeSink();
        $el = $tableMount(Fixtures::resizable([], $sink));
        $handle = $handleOf($headerCellsOf($el)[1] ?? null);
        $base = $widthsOf($headerRowOf($el))[1] ?? 0.0;
        $fire($handle, PointerEvent::down(100.0, 10.0));
        $fire($handle, PointerEvent::move(140.0, 10.0));
        $fire($handle, PointerEvent::up(140.0, 10.0));

        check(
            'Table · 拖动上报 (列key, 起始宽+增量, finished)：拖动中 false、抬起 true 且值相同',
            \count($sink->log) === 2
                && $sink->log[0][0] === 'name' && $sink->log[0][2] === false
                && $sink->log[1][0] === 'name' && $sink->log[1][2] === true
                && abs($sink->log[0][1] - ($base + 40.0)) < 0.5
                && $sink->log[0][1] === $sink->log[1][1],
            \json_encode($sink->log) . ' base=' . $base,
        );

        // ④ 纯点一下手柄（没 move）不产生上报 —— 否则应用的"有变化才存盘"会被打成每次都存
        $sink = new ResizeSink();
        $el = $tableMount(Fixtures::resizable([], $sink));
        $handle = $handleOf($headerCellsOf($el)[1] ?? null);
        $fire($handle, PointerEvent::down(100.0, 10.0));
        $fire($handle, PointerEvent::up(100.0, 10.0));
        check(
            'Table · 只按下不拖动 ⇒ 不上报（"点了手柄"不等于"改了列宽"）',
            $sink->log === [],
            \json_encode($sink->log),
        );

        // ⑤ clamp：拖到 -500 停在 minWidth(120)，拖到 +500 停在 maxWidth(260)
        $sink = new ResizeSink();
        $el = $tableMount(Fixtures::resizable([], $sink));
        $handle = $handleOf($headerCellsOf($el)[1] ?? null);
        $fire($handle, PointerEvent::down(0.0, 10.0));
        $fire($handle, PointerEvent::move(-500.0, 10.0));
        $fire($handle, PointerEvent::move(500.0, 10.0));
        check(
            'Table · 拖拽也过 clampWidth：左拖停在 minWidth(120)，右拖停在 maxWidth(260)',
            \count($sink->log) === 2
                && abs($sink->log[0][1] - 120.0) < 0.5
                && abs($sink->log[1][1] - 260.0) < 0.5,
            \json_encode($sink->log),
        );

        $this->flushChecks();
    }

    public function testColumnWidthsOverrideWinsOverFlex(): void
    {
        $tableMount = Fixtures::mount(...);
        $headerRowOf = Fixtures::headerRowOf(...);
        $widthsOf = Fixtures::widthsOf(...);

        // ⑥ 第 ④ 层真的压过 flex 分配：override name=200 ⇒ [60, 200, 60]（city 拿剩下）
        $sink = new ResizeSink();
        $widths = $widthsOf($headerRowOf($tableMount(Fixtures::resizable(['name' => 200.0], $sink))));
        check(
            'Table · columnWidths() 是最优先的第 ④ 层：flex 列被 override 后不再分剩余（[60,200,60]）',
            \count($widths) === 3
                && abs($widths[0] - 60.0) < 0.5
                && abs($widths[1] - 200.0) < 0.5
                && abs($widths[2] - 60.0) < 0.5,
            \json_encode($widths),
        );

        // ⑦ override 指向不存在的列 ⇒ 静默忽略（历史偏好 vs 现在状态）
        $sink = new ResizeSink();
        $ghost = $widthsOf($headerRowOf($tableMount(Fixtures::resizable(['nope' => 999.0], $sink))));
        check(
            'Table · columnWidths() 指向已不存在的列 ⇒ 静默忽略（列删了、存盘的旧值还在是常态）',
            \count($ghost) === 3
                && abs($ghost[1] - 130.0) < 0.5
                && abs($ghost[2] - 130.0) < 0.5,
            \json_encode($ghost),
        );

        $this->flushChecks();
    }

    /**
     * 第 ② 层（列的 `minWidth` / `maxWidth`）在**布局里**也生效。
     *
     * ★ 与 `ColumnWidthsTest` 的分工：那边验 `resolve()` 的算术（夹取在分配之后、
     *   不回头补偿剩余），这边验"列声明真的传到布局"—— flex 分到 173.33
     *   但 `maxWidth(100)` ⇒ 停在 100。只验算术会漏掉"声明没接上"。
     */
    public function testColumnMaxWidthClampsFlexAllocation(): void
    {
        $root = Fixtures::mount(
            Table::make(
                [
                    TableColumn::make('id', 'ID')->field('id')
                        ->width(TableColumnWidth::fixed(60.0)),
                    TableColumn::make('name', '姓名')->field('name')
                        ->width(TableColumnWidth::flex(2.0))->maxWidth(100.0),
                    TableColumn::make('city', '城市')->field('city')
                        ->width(TableColumnWidth::flex(1.0)),
                ],
                Fixtures::rows(3),
            )->keyOf(static fn(array $row): int => $row['id'])
                ->rowHeight(Fixtures::ROW_H)
                ->headerHeight(Fixtures::HEADER_H),
        );

        $widths = Fixtures::widthsOf(Fixtures::headerRowOf($root));

        check(
            'Table · maxWidth 夹住 flex 分配（173.33 ⇒ 100）',
            isset($widths[1]) && abs($widths[1] - 100.0) < 0.5,
            \json_encode($widths),
        );

        $this->flushChecks();
    }

    public function testDividerStructureAndInk(): void
    {
        $tableMount = Fixtures::mount(...);
        $tableBoxOf = Fixtures::boxOf(...);
        $tableRasterOf = Fixtures::rasterOf(...);
        $tablePx = Fixtures::pxOf(...);
        $headerCellsOf = Fixtures::headerCellsOf(...);
        $headerRowOf = Fixtures::headerRowOf(...);
        $dividerOf = Fixtures::dividerOf(...);
        $handleOf = Fixtures::handleOf(...);
        $widthsOf = Fixtures::widthsOf(...);

        $sink = new ResizeSink();
        $el = $tableMount(Fixtures::resizable([], $sink));
        $cells = $headerCellsOf($el);
        $divider0 = $dividerOf($cells[0] ?? null);
        $dividerLast = $dividerOf($cells[2] ?? null);
        $size0 = $divider0?->effectiveBox()?->size();

        check(
            'Table · 每列表头右缘都有分割线（含最后一列 —— 它就是表格的右边框）',
            $divider0 !== null && $dividerLast !== null,
            '第 1 列=' . ($divider0 === null ? '无' : '有')
                . ' 末列=' . ($dividerLast === null ? '无' : '有'),
        );
        check(
            'Table · 分割线 1px 宽、全表头高，且是浮层不占列宽（宽序列仍 [60,130,130]）',
            $size0 !== null
                && abs($size0->width - 1.0) < 0.01
                && abs($size0->height - Fixtures::HEADER_H) < 0.5
                && abs(($widthsOf($headerRowOf($el))[1] ?? 0.0) - 130.0) < 0.5,
            $size0 === null
                ? '量不到尺寸'
                : \sprintf(
                    '%.2f×%.2f 宽序=%s',
                    $size0->width,
                    $size0->height,
                    \json_encode($widthsOf($headerRowOf($el))),
                ),
        );

        // 像素：第 1 列的线在 x=59（列宽 60，线贴右缘 1px），表头纵向取中点 y=16
        $theme = ThemeData::light();
        $borderInk = $theme->color(ColorToken::Border);
        $accentInk = $theme->color(ColorToken::Accent);
        $shot = $tableRasterOf($tableBoxOf($el), 320, 240);
        check(
            'Table · 像素：分割线是 `Border` 色（与表头下那条横线同色，视觉上是同一套边界）',
            $shot->pixelAt(59, 16)->equals($borderInk),
            $tablePx($shot, 59, 16) . ' / 期望 ' . \sprintf(
                'rgb(%d,%d,%d)',
                $borderInk->r,
                $borderInk->g,
                $borderInk->b,
            ),
        );

        // 悬停第 2 列的手柄 ⇒ 第 2 列那条线（x=189）变 Accent，第 1 列（x=59）保持 Border
        $handle = $handleOf($cells[1] ?? null);
        $handle?->widget()->hoverHandlerValue()?->__invoke(true);
        Fixtures::pump($el);
        $shot = $tableRasterOf($tableBoxOf($el), 320, 240);
        check(
            'Table · 悬停手柄 ⇒ 那一列的线变 `Accent`（"这里能拖"的事前提示，光标来不及定位）',
            $shot->pixelAt(189, 16)->equals($accentInk),
            $tablePx($shot, 189, 16),
        );
        check(
            'Table · 悬停只染**那一列**的线（相邻列仍是 `Border`）',
            $shot->pixelAt(59, 16)->equals($borderInk)
                && $shot->pixelAt(319, 16)->equals($borderInk),
            'x=59 ' . $tablePx($shot, 59, 16) . ' / x=319 ' . $tablePx($shot, 319, 16),
        );

        // 拖动中：指针已离开手柄（hover 报 leave），线仍要保持 Accent；抬起后回到 Border
        $sink = new ResizeSink();
        $dragEl = $tableMount(Fixtures::resizable([], $sink));
        $dragHandle = $handleOf($headerCellsOf($dragEl)[1] ?? null);
        Fixtures::fireOnHandle($dragHandle, PointerEvent::down(100.0, 16.0));
        $dragHandle?->widget()->hoverHandlerValue()?->__invoke(false);
        Fixtures::fireOnHandle($dragHandle, PointerEvent::move(100.0, 16.0));
        Fixtures::pump($dragEl);
        $shot = $tableRasterOf($tableBoxOf($dragEl), 320, 240);
        check(
            'Table · 拖动中指针已离开手柄（hover=false）⇒ 线保持 `Accent`（拖动中不闪断）',
            $shot->pixelAt(189, 16)->equals($accentInk),
            $tablePx($shot, 189, 16),
        );

        Fixtures::fireOnHandle($dragHandle, PointerEvent::up(100.0, 16.0));
        Fixtures::pump($dragEl);
        $shot = $tableRasterOf($tableBoxOf($dragEl), 320, 240);
        check(
            'Table · 抬起后高亮消失（线回到 `Border`）',
            $shot->pixelAt(189, 16)->equals($borderInk),
            $tablePx($shot, 189, 16),
        );

        $this->flushChecks();
    }

    /**
     * ⑨ **真实路由**：手柄赢下按下，表头整格收不到。
     *
     * ★ 这是本主题唯一"常规断言验不到"的点：上面全部直接调
     *   `pointerHandlerValue()`，绕过了 `InputRouter::pointerOwner()` 的父链遍历。
     *   判据：同一格上，按右缘（手柄）⇒ 只有 resizeLog；按标题 ⇒ 只有 sortLog。
     */
    public function testRealRouteHandleBeatsHeaderCell(): void
    {
        $app = new TableResizeApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(Fixtures::VIEW_W, Fixtures::VIEW_H)));

        $handle = findElement(
            $app->root(),
            static fn(Element $e): bool => $e->widget()->cursorShape() === CursorShape::SizeWE,
        );
        [$hx, $hy] = Fixtures::centerOf($handle);
        $app->dispatchInput(PointerEvent::down($hx, $hy));
        $app->dispatchInput(PointerEvent::move($hx + 30.0, $hy));
        $app->dispatchInput(PointerEvent::up($hx + 30.0, $hy));

        check(
            'Table · 真实路由：按在手柄上拖 ⇒ 只走列宽，表头排序收不到（手柄比整格近）',
            \count($app->resizeLog) === 2
                && $app->sortLog === []
                && $app->resizeLog[0][2] === false
                && $app->resizeLog[1][2] === true,
            'resize=' . \json_encode($app->resizeLog) . ' sort=' . \json_encode($app->sortLog)
                . ' handle=' . ($handle === null ? 'MISSING' : 'ok'),
        );

        // 对照：按同一格的标题区（远离右缘）⇒ 只有排序，没有列宽
        $title = findElement(
            $app->root(),
            static fn(Element $e): bool => $e->widget() instanceof Widget && $e->widget()->pointerOwnsPressValue()
                && $e->widget()->cursorShape() === CursorShape::PointingHand,
        );
        [$tx, $ty] = Fixtures::centerOf($title);
        $app->sortLog = [];
        $app->resizeLog = [];
        $app->dispatchInput(PointerEvent::down($tx, $ty));
        $app->dispatchInput(PointerEvent::up($tx, $ty));

        check(
            'Table · 真实路由：按在表头标题上 ⇒ 只走排序，不产生列宽上报',
            \count($app->sortLog) === 1 && $app->resizeLog === [],
            'resize=' . \json_encode($app->resizeLog) . ' sort=' . \json_encode($app->sortLog),
        );

        $this->flushChecks();
    }
}
