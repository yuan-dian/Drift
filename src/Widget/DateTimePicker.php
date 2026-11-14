<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;

/**
 * 日期时间选择器 —— 触发框 + 浮层里**并排**的月历与时分秒列（2026-10-29）。
 *
 * ```php
 * DateTimePicker::make()
 *     ->value($this->at)                            // 受控：LocalDateTime
 *     ->onChanged(fn(LocalDateTime $v) => $this->setState(fn() => $this->at = $v));
 *
 * DateTimePicker::make()->withSeconds();            // 时间列出三列（默认时 / 分）
 * ```
 *
 * ## 为什么是"两块并排"而不是一个更大的日历
 *
 * 拿 layui `laydate` 对照过（`type: 'datetime'` 的面板就是左日历 + 右时分秒列）。
 * 结构上与 `Select` 的那条裁决同源：**组合，不新写渲染盒**。
 *
 * | 组成 | 谁 | 它知道自己被并排了吗 |
 * |---|---|---|
 * | 左 | `CalendarView` | 不知道（`DatePicker` 也复用它） |
 * | 右 | `TimeView` | 不知道（应用可以单独放它在页面上） |
 * | 外壳 | 本类 + `OptionPanel` | 只有它知道 |
 *
 * ## 与 `DatePicker` 的**唯一**行为差别：选完不收面板
 *
 * 两个交互面（日期 / 时刻），"点一下就把浮层收掉"会让"再改另一半"变成重新打开。
 * 收起由用户点面板外 / Esc 决定。`DatePicker` 只有一个面，点完即完成。
 *
 * ## 值为空时选时刻 ⇒ 日期取面板当前月的 1 号
 *
 * 这是一个**明确的猜测**，写在这里而不是藏进代码：`LocalDateTime` 必须两半都有，
 * 而"只选了时刻"时日期无处可来。取了面板正在显示的那个月，是因为用户翻月
 * 已经表达过"我在看这段时间"。等真有人嫌不对，再加"先选日期"的约束。
 *
 * ## 有意边界
 *
 * - **不读系统时间**、**不做 `range` / `min/max`**（同 `DatePicker`）。
 * - **没有"确定"按钮**：点即选 + 受控，提交时机是应用的事。
 * - **时间列格子不进 Tab 序**（`TimeView` 的边界），整块面板靠指针操作。
 *
 * @api
 */
final class DateTimePicker extends StatefulWidget
{
    /** 触发框默认宽 —— "2026-10-29 09:05" 放得下 */
    public const float DEFAULT_WIDTH = 200.0;

    /** 面板高度上限（日历 5~6 行 ≈ 250） */
    public const float PANEL_MAX_HEIGHT = 400.0;

    /** 两块面板之间的间隔 */
    public const float PANEL_GAP = 8.0;

    /**
     * 面板宽的下限：日历半宽 + 间隔 + 时刻列宽。
     *
     * ★ 必须是**方法**而不是常量：时刻列随 `withSeconds()` 从两列变三列，
     *   写死一个数时两列模式会白空一截、三列模式又可能够不着。日历那半直接
     *   用 `DatePicker` 的公开下限 —— 两个面板的日历部分本来就一样宽。
     */
    public static function panelMinWidth(bool $withSeconds): float
    {
        return DatePicker::PANEL_MIN_WIDTH
            + self::PANEL_GAP
            + TimeView::widthOf($withSeconds);
    }

    private ?LocalDateTime $value = null;

    private readonly YearMonth $initialMonth;

    private string $placeholder = '选择日期时间';

    private float $width = self::DEFAULT_WIDTH;

    private bool $seconds = false;

    private ?\Closure $onChanged = null;

    private function __construct(?YearMonth $initialMonth)
    {
        // ★ 必须调：`$mods` 在基类构造器里初始化（同 `CalendarView`）
        parent::__construct();
        $this->initialMonth = $initialMonth ?? YearMonth::make(2000, 1);
    }

    /**
     * @param YearMonth|null $initialMonth 值为空时面板先显示哪个月（`null` = 2000-01）
     */
    public static function make(?YearMonth $initialMonth = null): self
    {
        return new self($initialMonth);
    }

    public function value(?LocalDateTime $value): static
    {
        $this->mutate();
        $this->value = $value;

        return $this;
    }

    /** 时间列出三列（时 / 分 / 秒）；默认只有时 / 分 */
    public function withSeconds(): static
    {
        $this->mutate();
        $this->seconds = true;

        return $this;
    }

    public function placeholder(string $text): static
    {
        $this->mutate();
        $this->placeholder = $text;

        return $this;
    }

    public function width(float $width): static
    {
        $this->mutate();
        $this->width = $width;

        return $this;
    }

    /** @param callable(LocalDateTime): void $handler */
    public function onChanged(callable $handler): static
    {
        $this->mutate();
        $this->onChanged = $handler(...);

        return $this;
    }

    // ── State 侧读的口径（`@internal`）──

    /** @internal */
    public function valueOf(): ?LocalDateTime
    {
        return $this->value;
    }

    /** @internal */
    public function initialMonthValue(): YearMonth
    {
        return $this->initialMonth;
    }

    /** @internal */
    public function placeholderText(): string
    {
        return $this->placeholder;
    }

    /** @internal */
    public function widthValue(): float
    {
        return $this->width;
    }

    /** @internal */
    public function showsSeconds(): bool
    {
        return $this->seconds;
    }

    /** @internal */
    public function onChangedHandler(): ?\Closure
    {
        return $this->onChanged;
    }

    public function displayText(): string
    {
        $value = $this->value;

        return $value === null ? $this->placeholder : $value->label($this->seconds);
    }

    public function createState(): State
    {
        return new DateTimePickerState();
    }
}

/**
 * `DateTimePicker` 的私有 State —— 与 `DatePickerState` 同构，只有面板内容不同。
 *
 * @internal
 */
final class DateTimePickerState extends State
{
    /** 用户翻到的月份；`null` = 跟着受控值走（每次打开面板复位） */
    private ?YearMonth $month = null;

    /** **`null` ⇔ 面板关着** */
    private ?\Closure $dismiss = null;

    /** 翻月后通知面板重读（`PickerPanelSignal`，与 `DatePicker` 共用） */
    private PickerPanelSignal $signal;

    #[\Override]
    public function initState(): void
    {
        $this->signal = new PickerPanelSignal();
    }

    public function build(BuildContext $ctx): Widget
    {
        return Button::make($this->picker()->displayText())
            ->variant(ButtonVariant::Default)
            ->width($this->picker()->widthValue())
            ->overlayPanel($this->panelFactory(), $this->minWidth() + 16.0)
            ->overlaySignature(fn (): string => $this->signature());
    }

    /** 面板宽下限（按当前列数算）—— `overlayPanel` 自报的上限也由它派生 */
    private function minWidth(): float
    {
        return DateTimePicker::panelMinWidth($this->picker()->showsSeconds());
    }

    private function panelFactory(): \Closure
    {
        return function (\Closure $dismiss): Widget {
            $this->dismiss = $dismiss;
            $this->month = null;

            return OptionPanel::make(
                $this->signal,
                fn (): Widget => $this->panelContent(),
                $this->minWidth(),
                DateTimePicker::PANEL_MAX_HEIGHT,
                fn (): mixed => $this->onPanelClosed(),
                false,   // ★ 时刻列自带 `LazyColumn` ⇒ 外壳再套一层就是嵌套滚动（会崩）
            );
        };
    }

    private function panelContent(): Widget
    {
        return Row::make()->spacing(DateTimePicker::PANEL_GAP)->children([
            $this->calendar(),
            $this->time(),
        ]);
    }

    private function calendar(): Widget
    {
        $value = $this->picker()->valueOf();

        return CalendarView::make($this->month())
            ->selected($value?->date)
            ->onSelected(function (LocalDate $date): void {
                $this->commitDate($date);
            })
            ->onMonthChange(function (YearMonth $next): void {
                $this->showMonth($next);
            });
    }

    private function time(): Widget
    {
        $view = TimeView::make($this->timeValue())
            ->onChanged(function (LocalTime $time): void {
                $this->commitTime($time);
            });

        if ($this->picker()->showsSeconds()) {
            $view->withSeconds();
        }

        return $view;
    }

    /** 时间列的受控值：值里没有时刻（或整个值为空）⇒ 从 00:00 起步 */
    private function timeValue(): LocalTime
    {
        $value = $this->picker()->valueOf();

        return $value === null ? LocalTime::midnight() : $value->time;
    }

    private function month(): YearMonth
    {
        $month = $this->month;
        if ($month !== null) {
            return $month;
        }

        $value = $this->picker()->valueOf();

        return $value === null ? $this->picker()->initialMonthValue() : $value->date->yearMonth();
    }

    private function showMonth(YearMonth $next): void
    {
        $this->month = $next;
        $this->signal->notifyChanged();
    }

    /**
     * 选了日期：**保持时刻，只换日期**；整个值为空时退到当天 0 点。
     *
     * ★ 不收面板（两个面还要继续用）。
     */
    private function commitDate(LocalDate $date): void
    {
        $value = $this->picker()->valueOf();
        $next = $value === null
            ? LocalDateTime::make($date, LocalTime::midnight())
            : $value->withDate($date);

        $this->emit($next);
    }

    /** 选了时刻：保持日期；整个值为空时用面板当前月的 1 号（见类头那条猜测） */
    private function commitTime(LocalTime $time): void
    {
        $value = $this->picker()->valueOf();

        if ($value !== null) {
            $this->emit($value->withTime($time));

            return;
        }

        $month = $this->month();
        $this->emit(LocalDateTime::make(LocalDate::make($month->year, $month->month, 1), $time));
    }

    private function emit(LocalDateTime $next): void
    {
        $handler = $this->picker()->onChangedHandler();
        if ($handler !== null) {
            $handler($next);
        }
    }

    private function onPanelClosed(): void
    {
        $this->dismiss = null;
    }

    private function signature(): string
    {
        $value = $this->picker()->valueOf();

        return $value === null ? '' : $value->iso();
    }

    private function picker(): DateTimePicker
    {
        $widget = $this->widget();
        if (!$widget instanceof DateTimePicker) {
            // 框架不变量：StatefulElement 按 get_class 配对，配错不可能发生
            Errors::report('DateTimePickerState 只能配 DateTimePicker（框架内部不变量被破坏）');
        }

        return $widget;
    }
}
