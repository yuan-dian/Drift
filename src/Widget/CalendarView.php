<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Layout\FlexAlign;

/**
 * 月历（A3 第三件前半，2026-10-29；同日补**日 / 月 / 年三档视图**）。
 *
 * ```php
 * CalendarView::make(YearMonth::make(2026, 10))
 *     ->selected($this->date)                              // 受控：值住应用的 State
 *     ->onSelected(fn(LocalDate $d) => $this->setState(fn() => $this->date = $d))
 *     ->onMonthChange(fn(YearMonth $m) => $this->setState(fn() => $this->month = $m));
 * ```
 *
 * ## 四个岔口
 *
 * | 岔口 | 裁决 | 理由 |
 * |---|---|---|
 * | 自己当渲染盒 vs 组合 | **`StatefulWidget` + `Column`/`Row`/`Button`** | 零新增 Element / RenderBox（同 `TreeView` / `TabView`）。日历是"一堆按钮排成网格"，没有需要盒级才知道的东西 |
 * | 月份：受控 vs 自持 | **受控**（`month` + `onMonthChange`） | 与 `TreeView::expanded` 同族。`DatePicker` 要"选完自动跟到那个月"、应用要"打开就跳到本月"，自持的话两件都得补反向 API |
 * | **视图粒度**（日 / 月 / 年）：受控 vs 自持 | **自持**（`CalendarViewState::$mode`） | 它不承载值语义（应用不关心用户停在年视图），只影响这棵子树 —— 正是 `StatefulWidget` 的判据。受控的话应用要凭空多存一个字段，而它对业务毫无意义 |
 * | 只读 vs 可嵌 | **可嵌，但不开面板** | 它自己**不知道**自己在一个浮层里 —— 浮层开关是 `DatePicker` 与 `Application` 的事（`§5.1` 裁决 1）。切视图**不弹任何东西**，是原地换内容 |
 *
 * ## 三档视图
 *
 * | 模式 | 标题 | 网格 | 点一格 ⇒ | 箭头 ⇒ |
 * |---|---|---|---|---|
 * | 日 | `2026 年 10 月` | 7 列 × 5~6 行 | `onSelected(那天)` | ±1 月 |
 * | 月 | `2026 年` | 4 列 × 3 行（12 个月） | `onMonthChange` + 回日 | ±1 年 |
 * | 年 | `2021 - 2032` | 4 列 × 3 行（12 年） | `onMonthChange` + 回月 | ±12 年 |
 *
 * ★ **点标题循环**（日 → 月 → 年 → 日）：跨年翻月从"按 17 次箭头"变成三次点击。
 *   这是拿 layui `laydate` 对照后**唯一值得吸收**的一条（见 `REVIEW-2026-10-28.md` §17.4）。
 * ★ **标题可点 ⇔ 有 `onMonthChange`**：没有回调时切到月视图去点格子只会点出
 *   一片死寂 —— 那是比"不能切"更差的手感。没回调时行为与 2026-10-29 之前完全一致。
 * ★ **选年不直接跳回日视图**，而是回月视图："选年"的语义是"我要看那一年的月份"，
 *   直接落到 10 月反而让人再点一次标题。
 *
 * ## 有意边界
 *
 * - **不标"今天"**：控件读系统时间会让 golden 每次跑都不一样。要标就把今天当
 *   `selected()` 传进来，或用应用自己的装饰。
 * - **别月日期留空**，不画灰色的上/下月数字：框架没有"禁用态"（`disabled()`），
 *   灰而可点的格子会让人以为能选 —— 空白是唯一诚实的表达。
 * - **格子不进 Tab 序**（`skipFocus()`）：31 个日期格全进 Tab 序是可用性灾难。
 *   按规范该由**方向键**在格间移动（W8-4 未做），那之前只支持指针选。
 * - **不解析输入、不做范围限制**（min/max / 禁用某几天）：前者是 `DatePicker`
 *   的事，后者需要新的约束模型，等真有人要。
 * - **行数随月变**（5 或 6 行）：28 天且 1 号是周一时只需 4 行，太扁 ⇒ 补到 5 行。
 * - **切视图时高度会变**（日 5~6 行 vs 月/年 3 行）：不强行等高 —— 面板内容是
 *   换了，不是"同一张图的两种画法"。
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
        // ★ 必须调：`$mods` 在基类构造器里初始化，不调的话 `syncBox()` 访问它时
        //   报 "must not be accessed before initialization"（而栈顶落在框架里）
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
     *
     * ★ 三档视图共用这一条通道：翻页 / 选月 / 选年都是"我想看别的月份"。
     *   分三条通道只会让应用写三份几乎一样的回调。
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

        // 向上取整（整数运算，不碰浮点）
        return max(self::MIN_ROWS, intdiv($count + self::COLUMNS - 1, self::COLUMNS));
    }

    public function createState(): State
    {
        return new CalendarViewState();
    }
}

/**
 * `CalendarView` 的纯 UI 态：**现在看的是哪一档视图**（日 / 月 / 年）。
 *
 * ★ 三档共用同一个 `View` 的受控值（`month` / `selected`），`mode` 只决定
 *   "这份受控值渲染成什么形状" —— 切模式不动值，切值也不动模式。
 */
final class CalendarViewState extends State
{
    private const MODE_DAY = 0;
    private const MODE_MONTH = 1;
    private const MODE_YEAR = 2;

    /** 面板内边距 */
    private const PAD = 8.0;

    /** 周起始固定周一（中文习惯）；`weekStart()` 等 i18n 立项再说 */
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
            // ★ 只给宽、不给高：高度由内容（行数）决定 —— `SizedBox` 高度小于
            //   子内容会触发 C6 Fatal，而"5 行还是 6 行"本来就是内容说了算。
            ->boxSize(width: CalendarView::CELL_WIDTH * CalendarView::COLUMNS + self::PAD * 2.0);
    }

    // ── 标题行 ─────────────────────────────────────────────────────────

    /** 标题行：`‹ 2026 年 10 月 ›`（中间那段有回调时可点，用来切视图） */
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
            // ★ 越界那侧**不挂回调**：翻到头了，点了就该毫无反应 ——
            //   上报一个"跟当前一模一样"的月份只会让应用多跑一次无意义的 setState
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
     * 标题：可点时是 `Flat` 按钮（`Expanded` 撑满 ⇒ 点整行都能切）。
     *
     * ★ 不居中不用 `centerContent()`：那是给"代理盒"挪子盒的，`RenderText` 是
     *   叶子（字自己画），挪不动。无回调的分支靠两侧 `Spacer` 等分剩余宽。
     * ★ `Button` 没有粗体档，标题的字重因此比 `Text::bold()` 轻 —— 可换取的是
     *   hover 反馈与"这里能点"的暗示，对这个位置**可点性比字重重要**。
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

    /** 12 格切 4 列 × 3 行（`array_slice` 在 `src/` 无先例，手工切） */
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
     * 月 / 年视图的一个格子（`$value` 是月号或年份）。
     *
     * ★ `skipFocus()` 同日期格：整张日历只该有一个"入口"。
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
     * 选中一格之后：上报 + **下沉一级**（年 ⇒ 月 ⇒ 日）。
     *
     * ★ 下沉是纯 UI 态，不用等应用同意 —— 受控值改没改由应用决定，视图照样退回。
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

            // 首尾留白：框架没有禁用态，灰而可点比空白更误导
            $cells[] = ($day < 1 || $day > $days)
                ? Expanded::make(SizedBox::make())
                : $this->dayCell(LocalDate::make($month->year, $month->month, $day));
        }

        // 手工切行（`array_slice` 在本仓 `src/` 无先例，AOT 侧不值得试第一条）
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
     * 一个日期格。
     *
     * ★ `skipFocus()`：整张月历只该有一个"入口"，不该让 31 个格子挤进 Tab 序。
     *   指针点击不受影响（`skipFocus()` 只挡 Tab 序，2026-10-29 修）。
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
