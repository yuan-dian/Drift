<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Layout\FlexAlign;

/**
 * 月历（日 / 月 / 年三档视图）。
 *
 * 受控：当前月与选中日均由应用经 `month()` / `selected()` 持有，视图只上报变更（`onMonthChange()` / `onSelected()`）。
 * ★ **标题可点 ⇔ 有 `onMonthChange`**：没有回调时切到月视图去点格子只会点出一片死寂 —— 比"不能切"更差的手感。
 *
 * @api
 */
final class CalendarView extends StatefulWidget
{
    public const COLUMNS = 7;

    /** 格子宽：15px 的日期字 + 两侧 `gap` 内边距（`Button::compact()`）+ 呼吸 */
    public const CELL_WIDTH = 40.0;

    /** 最少 5 行 —— 4 行的月份（28 天且 1 号是周一）在视觉上太扁 */
    public const MIN_ROWS = 5;

    private YearMonth $month;

    private ?LocalDate $selected = null;

    private ?\Closure $onSelected = null;

    private ?\Closure $onMonthChange = null;

    private function __construct(YearMonth $month)
    {
        // 必须调 `parent::__construct()`：`$mods` 在基类构造器里初始化，不调的话 `syncBox()` 访问它时报 "must not be accessed before initialization"。
        parent::__construct();
        $this->month = $month;
    }

    public static function make(YearMonth $month): self
    {
        return new self($month);
    }

    /** 当前显示哪个月（受控 —— 翻月由 `onMonthChange()` 上报） */
    public function month(YearMonth $month): static
    {
        $this->mutate();
        $this->month = $month;

        return $this;
    }

    /** 当前选中的日期（受控）；不给 ⇒ 一格都不高亮 */
    public function selected(?LocalDate $date): static
    {
        $this->mutate();
        $this->selected = $date;

        return $this;
    }

    public function onSelected(\Closure $handler): static
    {
        $this->mutate();
        $this->onSelected = $handler;

        return $this;
    }

    /**
     * 点了上/下月箭头、月格或年格。**只上报，不自持** —— 受控值住应用那边。
     * 三档视图共用这一条通道：翻页 / 选月 / 选年都是"我想看别的月份"。
     */
    public function onMonthChange(\Closure $handler): static
    {
        $this->mutate();
        $this->onMonthChange = $handler;

        return $this;
    }

    public function monthValue(): YearMonth
    {
        return $this->month;
    }

    // ── State 侧读的口径（`@internal`：只给同文件的 `CalendarViewState` 调）──

    /** @internal */
    public function selectedValue(): ?LocalDate
    {
        return $this->selected;
    }

    /** @internal */
    public function onSelectedHandler(): ?\Closure
    {
        return $this->onSelected;
    }

    /** @internal */
    public function onMonthChangeHandler(): ?\Closure
    {
        return $this->onMonthChange;
    }

    /** 本月要画几行（5 或 6）——也是"这个月有多少格"的唯一来源 */
    public function rowCount(): int
    {
        $count = $this->month->firstWeekday() + $this->month->daysInMonth();

        return max(self::MIN_ROWS, intdiv($count + self::COLUMNS - 1, self::COLUMNS));
    }

    public function createState(): State
    {
        return new CalendarViewState();
    }
}

/**
 * `CalendarView` 的纯 UI 态：**现在看的是哪一档视图**（日 / 月 / 年）。
 * 三档共用同一个 `View` 的受控值（`month` / `selected`），`mode` 只决定"这份受控值渲染成什么形状" —— 切模式不动值，切值也不动模式。
 */
final class CalendarViewState extends State
{
    private const MODE_DAY = 0;
    private const MODE_MONTH = 1;
    private const MODE_YEAR = 2;

    /** 面板内边距 */
    private const PAD = 8.0;

    /** 周起始固定周一（中文习惯） */
    private const WEEKDAYS = ['一', '二', '三', '四', '五', '六', '日'];

    /** 月 / 年视图 4 列 × 3 行 = 12 格 —— 与"12 个月"天然对齐 */
    private const PICK_COLUMNS = 4;

    /** 年视图一屏 12 年 */
    private const YEAR_SPAN = 12;

    /** 当前年落在屏内第 6 位（`year - 5`）—— 附近的年总在屏上，不必先翻页 */
    private const YEAR_LEAD = 5;

    private int $mode = self::MODE_DAY;

    public function build(BuildContext $ctx): Widget
    {
        $kids = [$this->header()];

        foreach ($this->body() as $row) {
            $kids[] = $row;
        }

        return Column::make()
            ->spacing(2.0)
            ->paddingAll(self::PAD)
            ->children($kids)
            // 只给宽、不给高：高度由内容（行数）决定，SizedBox 高度小于子内容会触发 C6 Fatal，"5 行还是 6 行"本来就是内容说了算。
            ->boxSize(width: CalendarView::CELL_WIDTH * CalendarView::COLUMNS + self::PAD * 2.0);
    }

    // ── 标题行 ─────────────────────────────────────────────────────────

    /** 标题行：`‹ 年 月 ›`（中间那段有回调时可点，用来切视图） */
    private function header(): Widget
    {
        $on = $this->view()->onMonthChangeHandler();

        $left = IconButton::of(IconName::ArrowLeft, $this->stepLabel(-1))
            ->variant(ButtonVariant::Flat)
            ->skipFocus();
        $right = IconButton::of(IconName::ArrowRight, $this->stepLabel(1))
            ->variant(ButtonVariant::Flat)
            ->skipFocus();

        if ($on !== null) {
            $prev = $this->shift(-1);
            $next = $this->shift(1);

            if ($prev !== null) {
                $left->onPressed(static function () use ($on, $prev): void {
                    $on($prev);
                });
            }

            if ($next !== null) {
                $right->onPressed(static function () use ($on, $next): void {
                    $on($next);
                });
            }
        }

        return Row::make()->cross(FlexAlign::Center)->children([
            $left,
            Expanded::make($this->title()),
            $right,
        ]);
    }

    /**
     * 标题：可点时是 `Flat` 按钮（`Expanded` 撑满 ⇒ 点整行都能切；无回调分支靠两侧 `Spacer` 等分剩余宽）。
     */
    private function title(): Widget
    {
        $text = $this->titleText();

        if ($this->view()->onMonthChangeHandler() === null) {
            return Row::make()->children([
                Spacer::make(),
                Text::make($text)->bold(),
                Spacer::make(),
            ]);
        }

        return Button::make($text)
            ->variant(ButtonVariant::Flat)
            ->compact()
            ->skipFocus()
            ->onPressed(function (): void {
                $this->cycleMode();
            });
    }

    private function titleText(): string
    {
        $m = $this->view()->monthValue();

        if ($this->mode === self::MODE_MONTH) {
            return $m->year . ' 年';
        }

        if ($this->mode === self::MODE_YEAR) {
            $start = $this->yearStart();

            return $start . ' - ' . ($start + self::YEAR_SPAN - 1);
        }

        return $m->label();
    }

    /** 箭头的无障碍文案 —— 同一次点击在不同视图下搬的是不同的东西 */
    private function stepLabel(int $dir): string
    {
        $prefix = $dir < 0 ? '上' : '下';

        return $prefix . match ($this->mode) {
            self::MODE_MONTH => '一年',
            self::MODE_YEAR => '一页',
            default => '个月',
        };
    }

    // ── 三档视图的主体 ─────────────────────────────────────────────────

    /** @return list<Widget> */
    private function body(): array
    {
        return match ($this->mode) {
            self::MODE_MONTH => $this->monthBody(),
            self::MODE_YEAR => $this->yearBody(),
            default => $this->dayBody(),
        };
    }

    /** @return list<Widget> */
    private function dayBody(): array
    {
        $rows = [$this->weekdayRow()];

        foreach ($this->weekRows() as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return list<Widget> */
    private function monthBody(): array
    {
        $current = $this->view()->monthValue()->month;
        $cells = [];

        for ($m = 1; $m <= 12; $m++) {
            $cells[] = $this->pickCell($m . ' 月', $m === $current, $m);
        }

        return $this->pickRows($cells);
    }

    /** @return list<Widget> */
    private function yearBody(): array
    {
        $start = $this->yearStart();
        $current = $this->view()->monthValue()->year;
        $cells = [];

        for ($y = $start; $y < $start + self::YEAR_SPAN; $y++) {
            $cells[] = $this->pickCell((string) $y, $y === $current, $y);
        }

        return $this->pickRows($cells);
    }

    /** 12 格切 4 列 × 3 行 */
    private function pickRows(array $cells): array
    {
        $rows = [];
        $count = \count($cells);

        for ($i = 0; $i < $count; $i += self::PICK_COLUMNS) {
            $row = [];
            for ($c = 0; $c < self::PICK_COLUMNS; $c++) {
                $row[] = $cells[$i + $c];
            }
            $rows[] = Row::make()->children($row);
        }

        return $rows;
    }

    /**
     * 月 / 年视图的一个格子（`$value` 是月号或年份）。`skipFocus()` 同日期格：整张日历只该有一个"入口"。
     */
    private function pickCell(string $label, bool $active, int $value): Widget
    {
        $cell = Button::make($label)
            ->compact()
            ->skipFocus()
            ->variant($active ? ButtonVariant::Primary : ButtonVariant::Flat);

        if ($this->view()->onMonthChangeHandler() !== null) {
            $cell->onPressed(function () use ($value): void {
                $this->pick($value);
            });
        }

        return Expanded::make($cell);
    }

    /**
     * 选中一格之后：上报 + **下沉一级**（年 ⇒ 月 ⇒ 日）。下沉是纯 UI 态，不用等应用同意，视图照样退回。
     */
    private function pick(int $value): void
    {
        $on = $this->view()->onMonthChangeHandler();
        if ($on === null) {
            return;
        }

        $m = $this->view()->monthValue();

        if ($this->mode === self::MODE_MONTH) {
            $on(YearMonth::make($m->year, $value));
            $this->swapMode(self::MODE_DAY);

            return;
        }

        $on(YearMonth::make($value, $m->month));
        $this->swapMode(self::MODE_MONTH);
    }

    private function cycleMode(): void
    {
        $this->swapMode(match ($this->mode) {
            self::MODE_DAY => self::MODE_MONTH,
            self::MODE_MONTH => self::MODE_YEAR,
            default => self::MODE_DAY,
        });
    }

    private function swapMode(int $mode): void
    {
        $this->setState(function () use ($mode): void {
            $this->mode = $mode;
        });
    }

    // ── 翻页 ───────────────────────────────────────────────────────────

    /** 年视图的屏起点（`year - 5`，钳在 1..9988 之间） */
    private function yearStart(): int
    {
        $year = $this->view()->monthValue()->year;

        return max(1, min($year - self::YEAR_LEAD, 10000 - self::YEAR_SPAN));
    }

    /**
     * 翻一页的目标月（保留月号）。**越界返回 `null`** —— 到头了不是错误。
     */
    private function shift(int $dir): ?YearMonth
    {
        $m = $this->view()->monthValue();

        $months = match ($this->mode) {
            self::MODE_MONTH => $dir * 12,
            self::MODE_YEAR => $dir * 12 * self::YEAR_SPAN,
            default => $dir,
        };

        return $m->tryPlusMonths($months);
    }

    // ── 日视图的网格 ───────────────────────────────────────────────────

    private function weekdayRow(): Widget
    {
        $cells = [];

        foreach (self::WEEKDAYS as $label) {
            $cells[] = Expanded::make(Row::make()->children([
                Spacer::make(),
                Text::make($label)->caption(),
                Spacer::make(),
            ]));
        }

        return Row::make()->children($cells);
    }

    /** @return list<Widget> */
    private function weekRows(): array
    {
        $month = $this->view()->monthValue();
        $lead = $month->firstWeekday();
        $days = $month->daysInMonth();
        $rows = $this->view()->rowCount();

        $cells = [];
        for ($i = 0; $i < $rows * CalendarView::COLUMNS; $i++) {
            $day = $i - $lead + 1;

            $cells[] = ($day < 1 || $day > $days)
                ? Expanded::make(SizedBox::make())
                : $this->dayCell(LocalDate::make($month->year, $month->month, $day));
        }

        $out = [];
        for ($r = 0; $r < $rows; $r++) {
            $row = [];
            for ($c = 0; $c < CalendarView::COLUMNS; $c++) {
                $row[] = $cells[$r * CalendarView::COLUMNS + $c];
            }
            $out[] = Row::make()->children($row);
        }

        return $out;
    }

    /**
     * 一个日期格。`skipFocus()`：整张月历只该有一个"入口"，31 个格子不该挤进 Tab 序（只挡 Tab 序，指针点击不受影响）。
     */
    private function dayCell(LocalDate $date): Widget
    {
        $view = $this->view();
        $selected = $view->selectedValue();
        $isSelected = $selected !== null && $selected->equals($date);

        $cell = Button::make((string) $date->day)
            ->compact()
            ->skipFocus()
            ->variant($isSelected ? ButtonVariant::Primary : ButtonVariant::Flat);

        $on = $view->onSelectedHandler();
        if ($on !== null) {
            $cell->onPressed(static function () use ($on, $date): void {
                $on($date);
            });
        }

        return Expanded::make($cell);
    }

    private function view(): CalendarView
    {
        $widget = $this->widget();
        if (!$widget instanceof CalendarView) {
            // 框架不变量：StatefulElement 按 get_class 配对，配错不可能发生
            Errors::report('CalendarViewState 只能配 CalendarView（框架内部不变量被破坏）');
        }

        return $widget;
    }
}
