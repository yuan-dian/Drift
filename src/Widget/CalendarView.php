<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\FlexAlign;

/**
 * 月历网格（A3 第三件的前半，2026-10-29）。
 *
 * ```php
 * CalendarView::make(YearMonth::make(2026, 10))
 *     ->selected($this->date)                              // 受控：值住应用的 State
 *     ->onSelected(fn(LocalDate $d) => $this->setState(fn() => $this->date = $d))
 *     ->onMonthChange(fn(YearMonth $m) => $this->setState(fn() => $this->month = $m));
 * ```
 *
 * ## 三个岔口
 *
 * | 岔口 | 裁决 | 理由 |
 * |---|---|---|
 * | 自己当渲染盒 vs 组合 | **`StatelessWidget` + `Column`/`Row`/`Button`** | 零新增 Element / RenderBox（同 `TreeView` / `TabView`）。日历是"一堆按钮排成网格"，没有需要盒级才知道的东西 |
 * | 月份：受控 vs 自持 | **受控**（`month` + `onMonthChange`） | 与 `TreeView::expanded` 同族。`DatePicker` 要"选完自动跟到那个月"、应用要"打开就跳到本月"，自持的话两件都得补反向 API |
 * | 只读 vs 可嵌 | **只读 + 可嵌**（不改值、不开关面板） | 它自己**不知道**自己在一个浮层里 —— 开关是 `DatePicker` 与 `Application` 的事（`§5.1` 裁决 1） |
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
 *
 * @api
 */
final class CalendarView extends StatelessWidget
{
    public const COLUMNS = 7;

    /** 格子宽：15px 的日期字 + 两侧 `gap` 内边距（`Button::compact()`）+ 呼吸 */
    public const CELL_WIDTH = 40.0;

    /** 最少 5 行 —— 4 行的月份（28 天且 1 号是周一）在视觉上太扁 */
    public const MIN_ROWS = 5;

    /** 面板内边距 */
    private const PAD = 8.0;

    /** 周起始固定周一（中文习惯）；`weekStart()` 等 i18n 立项再说 */
    private const WEEKDAYS = ['一', '二', '三', '四', '五', '六', '日'];

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

    /** 点了上/下月箭头。**只上报，不自己翻** —— 受控值住应用那边 */
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

    /** 本月要画几行（5 或 6）——也是"这个月有多少格"的唯一来源 */
    public function rowCount(): int
    {
        $count = $this->month->firstWeekday() + $this->month->daysInMonth();

        // 向上取整（整数运算，不碰浮点）
        return max(self::MIN_ROWS, intdiv($count + self::COLUMNS - 1, self::COLUMNS));
    }

    public function build(BuildContext $ctx): Widget
    {
        $kids = [$this->header(), $this->weekdayRow()];

        foreach ($this->weekRows() as $row) {
            $kids[] = $row;
        }

        return Column::make()
            ->spacing(2.0)
            ->paddingAll(self::PAD)
            ->children($kids)
            // ★ 只给宽、不给高：高度由内容（行数）决定 —— `SizedBox` 高度小于
            //   子内容会触发 C6 Fatal，而"5 行还是 6 行"本来就是内容说了算。
            ->boxSize(width: self::CELL_WIDTH * self::COLUMNS + self::PAD * 2.0);
    }

    /** 标题行：`‹ 2026 年 10 月 ›` */
    private function header(): Widget
    {
        $prev = $this->month->plusMonths(-1);
        $next = $this->month->plusMonths(1);
        $on = $this->onMonthChange;

        $left = IconButton::of(IconName::ArrowLeft, '上个月')->variant(ButtonVariant::Flat)->skipFocus();
        $right = IconButton::of(IconName::ArrowRight, '下个月')->variant(ButtonVariant::Flat)->skipFocus();

        if ($on !== null) {
            $left->onPressed(static function () use ($on, $prev): void {
                $on($prev);
            });
            $right->onPressed(static function () use ($on, $next): void {
                $on($next);
            });
        }

        // ★ 居中不用 `centerContent()`：那是给"代理盒"挪子盒的，`RenderText` 是
        //   叶子（字自己画），挪不动。两侧各一个 `Spacer` 等分剩余宽，才是真居中。
        return Row::make()->cross(FlexAlign::Center)->children([
            $left,
            Expanded::make(Row::make()->children([
                Spacer::make(),
                Text::make($this->month->label())->bold(),
                Spacer::make(),
            ])),
            $right,
        ]);
    }

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
        $lead = $this->month->firstWeekday();
        $days = $this->month->daysInMonth();
        $rows = $this->rowCount();

        $cells = [];
        for ($i = 0; $i < $rows * self::COLUMNS; $i++) {
            $day = $i - $lead + 1;

            // 首尾留白：框架没有禁用态，灰而可点比空白更误导
            $cells[] = ($day < 1 || $day > $days)
                ? Expanded::make(SizedBox::make())
                : $this->dayCell(LocalDate::make($this->month->year, $this->month->month, $day));
        }

        // 手工切行（`array_slice` 在本仓 `src/` 无先例，AOT 侧不值得试第一条）
        $out = [];
        for ($r = 0; $r < $rows; $r++) {
            $row = [];
            for ($c = 0; $c < self::COLUMNS; $c++) {
                $row[] = $cells[$r * self::COLUMNS + $c];
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
        $isSelected = $this->selected !== null && $this->selected->equals($date);

        $cell = Button::make((string) $date->day)
            ->compact()
            ->skipFocus()
            ->variant($isSelected ? ButtonVariant::Primary : ButtonVariant::Flat);

        $on = $this->onSelected;
        if ($on !== null) {
            $cell->onPressed(static function () use ($on, $date): void {
                $on($date);
            });
        }

        return Expanded::make($cell);
    }
}
