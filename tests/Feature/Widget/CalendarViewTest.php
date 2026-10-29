<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\App\FocusManager;
use Drift\App\Frame;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\ActivationContext;
use Drift\Widget\Button;
use Drift\Widget\CalendarView;
use Drift\Widget\Element;
use Drift\Widget\IconButton;
use Drift\Widget\LocalDate;
use Drift\Widget\YearMonth;

/**
 * `CalendarView`：月历网格（A3 第三件的前半，2026-10-29）。
 *
 * ## 判据钉什么
 *
 * ① **行数**（5 / 6 行，随月份形状变），② **只有本月是格子**（别月留白，不是灰色按钮），
 * ③ **首日列位**（前导格数 = 1 号的周几 —— 前导算错一位整月都错位），
 * ④ **列等宽**（40，靠 `Expanded` 等分 + `Button::compact()` 让得出宽度），
 * ⑤ **受控**（点格子/箭头只上报、画面不动），⑥ **选中态**，⑦ **不占 Tab 序**。
 *
 * ★ ③ 是这一件最容易静默错的地方：前导格用 `SizedBox` 占位，错了不会报错，
 *   只会让"10 月 1 日"出现在错误的列上 —— 而这种图在肉眼上"看着像日历"。
 */
final class CalendarViewTest extends SmokeTestCase
{
    // ── ① 行数 ─────────────────────────────────────────────────────────

    public function testRowCountFollowsMonthShape(): void
    {
        // 5 行：前导 3 + 31 天 = 34 格
        check('CalendarView · 2026-10 是 5 行', CalendarView::make(YearMonth::make(2026, 10))->rowCount() === 5);
        // 6 行：前导 6 + 31 天 = 37 格
        check('CalendarView · 2026-03 是 6 行', CalendarView::make(YearMonth::make(2026, 3))->rowCount() === 6);
        // 5 行：前导 6 + 28 天 = 34 格
        check('CalendarView · 2026-02 是 5 行', CalendarView::make(YearMonth::make(2026, 2))->rowCount() === 5);
        // ★ 只算出来 4 行（前导 0 + 28 天 = 28 格）⇒ `MIN_ROWS` 补到 5
        check(
            'CalendarView · 2021-02 只需 4 行但补到 5（太扁）',
            CalendarView::make(YearMonth::make(2021, 2))->rowCount() === 5
        );
    }

    // ── ② 只有本月是格子 ───────────────────────────────────────────────

    public function testOnlyThisMonthsDaysAreButtons(): void
    {
        check('CalendarView · 2026-10 画出 31 个日期格', $this->dayLabels(2026, 10) === $this->sequence(31));
        check('CalendarView · 2024-02（闰）画出 29 个', $this->dayLabels(2024, 2) === $this->sequence(29));
        check('CalendarView · 2026-02（平）画出 28 个', $this->dayLabels(2026, 2) === $this->sequence(28));
    }

    // ── ③ 首日列位 ─────────────────────────────────────────────────────

    public function testFirstDayStartsInTheColumnOfItsWeekday(): void
    {
        // 2026-10-01 是周四 ⇒ 前导 3 格 ⇒ 第一行只有 4 个日期格
        $cells = $this->dayCells($this->render(2026, 10));
        $firstRow = $this->firstRow($cells);

        check('CalendarView · 2026-10 第一行 4 个日期格（前导 3）', \count($firstRow) === 4, 'n=' . \count($firstRow));
        check(
            'CalendarView · 第一格是 1 号',
            ($firstRow[0][0] ?? '') === '1',
            '实际 ' . ($firstRow[0][0] ?? '(空)')
        );
        // 起始列 = 前导 3 ⇒ x = PAD(8) + 3 × 40
        check(
            'CalendarView · 1 号落在第 4 列（x ≈ 128）',
            \abs($firstRow[0][1] - 128.0) < 0.5,
            'x=' . ($firstRow[0][1] ?? '?')
        );

        // 2021-02-01 是周一（前导 0）⇒ 第一行满 7 格，起始 x = 8
        $cells2021 = $this->dayCells($this->render(2021, 2));
        $first2021 = $this->firstRow($cells2021);
        check('CalendarView · 2021-02 第一行 7 个日期格（前导 0）', \count($first2021) === 7, 'n=' . \count($first2021));
        check(
            'CalendarView · 2021-02 的 1 号贴第 1 列（x ≈ 8）',
            \abs($first2021[0][1] - 8.0) < 0.5,
            'x=' . ($first2021[0][1] ?? '?')
        );
    }

    // ── ④ 列等宽 ───────────────────────────────────────────────────────

    public function testColumnsAreEqualWidth(): void
    {
        $cells = $this->dayCells($this->render(2021, 2));
        $first = $this->firstRow($cells);

        $gaps = [];
        for ($i = 1; $i < \count($first); $i++) {
            $gaps[] = $first[$i][1] - $first[$i - 1][1];
        }

        $ok = $gaps !== [];
        foreach ($gaps as $gap) {
            if (\abs($gap - CalendarView::CELL_WIDTH) > 0.5) {
                $ok = false;
            }
        }

        check(
            'CalendarView · 相邻列间距都是 40（7 列等分 280）',
            $ok,
            'gaps=' . \json_encode(\array_map(static fn(float $g): float => \round($g, 2), $gaps))
        );
    }

    // ── ⑤ 受控 ─────────────────────────────────────────────────────────

    public function testClickingDayReportsDateWithoutChangingPicture(): void
    {
        $seen = [];
        $view = CalendarView::make(YearMonth::make(2026, 10))
            ->onSelected(static function (LocalDate $d) use (&$seen): void {
                $seen[] = $d->iso();
            });

        $root = $this->mount($view);
        $this->frame($root);

        $this->dayButton($root, '15')?->activate(ActivationContext::none());
        check('CalendarView · 点 15 号 ⇒ onSelected 收到 2026-10-15', $seen === ['2026-10-15'], 'seen=' . \json_encode($seen));

        // 受控：上报完画面不动 —— 高亮要应用改 `selected()` 才发生
        $this->frame($root);
        check('CalendarView · 上报后画面不变（15 号仍未高亮）', !$this->isPrimary($root, '15'));
    }

    public function testMonthArrowsReportTargetMonth(): void
    {
        $seen = [];
        $view = CalendarView::make(YearMonth::make(2026, 10))
            ->onMonthChange(static function (YearMonth $m) use (&$seen): void {
                $seen[] = $m->year . '-' . $m->month;
            });

        $root = $this->mount($view);
        $this->frame($root);

        $arrows = $this->collectIconButtons($root);
        check('CalendarView · 标题行两个箭头', \count($arrows) === 2, 'n=' . \count($arrows));

        $arrows[0]?->activate(ActivationContext::none());
        $arrows[1]?->activate(ActivationContext::none());

        check(
            'CalendarView · 左箭头报 2026-9、右箭头报 2026-11',
            $seen === ['2026-9', '2026-11'],
            'seen=' . \json_encode($seen)
        );

        // 受控：两个月号都不改变当前画面
        check('CalendarView · 翻月上报后月份仍是 2026-10', $view->monthValue()->equals(YearMonth::make(2026, 10)));
    }

    public function testWithoutCallbacksNothingIsFocusable(): void
    {
        $root = $this->mount(CalendarView::make(YearMonth::make(2026, 10)));
        $this->frame($root);

        check('CalendarView · 无回调时日期格仍然画出来', \count($this->dayLabels(2026, 10)) === 31);

        // ★ 判据必须走 `FocusManager`：`focusNode()` 只在声明过 focusOrder/skipFocus
        //   时才被创建 ⇒ "焦点节点为 null"不等于"不进 Tab 序"（那是断言没牙）。
        $fm = new FocusManager();
        $fm->collect([$root]);
        $order = $fm->order();

        check('CalendarView · 整张月历不占 Tab 站（31 个日期格挤进去是灾难）', \count($order) === 0, 'n=' . \count($order));
    }

    // ── ⑥ 选中态 ───────────────────────────────────────────────────────

    public function testSelectedDayIsPrimary(): void
    {
        $root = $this->mount(
            CalendarView::make(YearMonth::make(2026, 10))->selected(LocalDate::make(2026, 10, 15))
        );
        $this->frame($root);

        check('CalendarView · 选中的 15 号是 Primary', $this->isPrimary($root, '15'));

        $primary = 0;
        foreach ($this->collectButtons($root) as $button) {
            if ($button->variantValue()->name === 'Primary') {
                $primary++;
            }
        }

        check('CalendarView · 只有一格是 Primary', $primary === 1, 'n=' . $primary);
    }

    public function testSelectedDateInAnotherMonthIsNotHighlighted(): void
    {
        // 受控的必然结果：选中值在别的月时，本月一个格子都不亮（不是"错了"）
        $root = $this->mount(
            CalendarView::make(YearMonth::make(2026, 10))->selected(LocalDate::make(2026, 9, 15))
        );
        $this->frame($root);

        $primary = 0;
        foreach ($this->collectButtons($root) as $button) {
            if ($button->variantValue()->name === 'Primary') {
                $primary++;
            }
        }

        check('CalendarView · 选中值在 9 月 ⇒ 10 月一格都不亮', $primary === 0, 'n=' . $primary);
    }

    // ── 夹具 ───────────────────────────────────────────────────────────

    private function render(int $year, int $month): Element
    {
        $root = $this->mount(CalendarView::make(YearMonth::make($year, $month)));
        $this->frame($root);

        return $root;
    }

    private function mount(CalendarView $view): Element
    {
        $element = $view->createElement();
        $element->mount(null, smokeContext());

        return $element;
    }

    private function frame(Element $root): void
    {
        $surface = new SurfaceProbe();
        $surface->resize(360, 420, 1.0);
        // ★ 松约束：月历自己声明宽度（7 × 40 + 内边距），不该被夹具撑满 ——
        //   紧约束下列宽会变成"可用宽 / 7"，`CELL_WIDTH` 就测不出来了
        (new Frame($surface))->run($root, BoxConstraints::loose(new Size(360.0, 420.0)), 64.0);
    }

    /** @return list<string> 画出来的日期数字（文档序） */
    private function dayLabels(int $year, int $month): array
    {
        $out = [];
        foreach ($this->dayCells($this->render($year, $month)) as $cell) {
            $out[] = $cell[0];
        }

        return $out;
    }

    /** @return list<array{string,float,float}> [标签, x, y] */
    private function dayCells(Element $root): array
    {
        $out = [];
        $walk = function (Element $el) use (&$walk, &$out): void {
            $widget = $el->widget();
            if ($widget instanceof Button && !$widget instanceof IconButton) {
                $box = $el->effectiveBox();
                if ($box !== null) {
                    [$x, $y] = boxOrigin($box);
                    $out[] = [$widget->label(), $x, $y];
                }
            }
            $el->visitChildren($walk);
        };
        $walk($root);

        return $out;
    }

    /** 同一 y 的第一行（文档序首个格子所在的 y） */
    private function firstRow(array $cells): array
    {
        if ($cells === []) {
            return [];
        }

        $y = $cells[0][2];
        $out = [];
        foreach ($cells as $cell) {
            if (\abs($cell[2] - $y) < 0.5) {
                $out[] = $cell;
            }
        }

        return $out;
    }

    /** @return list<Button> 所有文字按钮（日期格） */
    private function collectButtons(Element $root): array
    {
        $out = [];
        $walk = function (Element $el) use (&$walk, &$out): void {
            $widget = $el->widget();
            if ($widget instanceof Button && !$widget instanceof IconButton) {
                $out[] = $widget;
            }
            $el->visitChildren($walk);
        };
        $walk($root);

        return $out;
    }

    /** @return list<IconButton> */
    private function collectIconButtons(Element $root): array
    {
        $out = [];
        $walk = function (Element $el) use (&$walk, &$out): void {
            $widget = $el->widget();
            if ($widget instanceof IconButton) {
                $out[] = $widget;
            }
            $el->visitChildren($walk);
        };
        $walk($root);

        return $out;
    }

    private function dayButton(Element $root, string $label): ?Button
    {
        foreach ($this->collectButtons($root) as $button) {
            if ($button->label() === $label) {
                return $button;
            }
        }

        return null;
    }

    private function isPrimary(Element $root, string $label): bool
    {
        return $this->dayButton($root, $label)?->variantValue()->name === 'Primary';
    }

    /** @return list<string> '1'..'31' */
    private function sequence(int $days): array
    {
        $out = [];
        for ($i = 1; $i <= $days; $i++) {
            $out[] = (string) $i;
        }

        return $out;
    }
}
