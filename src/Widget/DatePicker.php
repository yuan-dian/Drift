<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;

/**
 * 日期选择器 —— 触发框 + 浮层月历（A3 第三件的第三前半，2026-10-29）。
 *
 * ```php
 * DatePicker::make()
 *     ->value($this->date)                          // 受控：值住应用的 State
 *     ->placeholder('选择日期')
 *     ->onChanged(fn(LocalDate $d) => $this->setState(fn() => $this->date = $d));
 * ```
 *
 * ## 形状从哪来（为什么没有 `RenderDatePicker`）
 *
 * 与 `Select` 同一条裁决（见其类头的 ★★）：交出一个 `Button`（`Default` 变体 = 有底有边）
 * + 两条声明 —— `overlayPanel()` 管浮层、`overlaySignature()` 管"值变了面板要重读"。
 * 焦点 / 激活 / 光标 / 命中 / 语义全部白送，零新增 Element / RenderBox。
 *
 * 面板内容就是 `CalendarView` —— 它**不需要知道**自己在浮层里（其类头第 4 条岔口）。
 *
 * ## 两条更新通道（同 `Select` 的分工）
 *
 * | 面板内容取决于 | 谁负责传播 |
 * |---|---|
 * | 受控值（Widget 上） | 框架：`overlaySignature()` 比对 |
 * | 翻到哪个月（State 上） | 本类：`PickerPanelSignal::notifyChanged()` |
 *
 * ★ 只有前者的后果：翻月后箭头按了没反应（面板是第二棵树，主树重建不带动它）。
 * ★ 只有后者的后果：应用把值改成别的日期，面板高亮停在旧日期上。
 *
 * ## 与 `CalendarView` 的分工
 *
 * `CalendarView` 不管"我在不在浮层里"，也不管"选完要不要收"；本类管这两件。
 * 于是同一个 `CalendarView` 既能嵌在页面里（`CalendarPage`），又能进浮层。
 *
 * ## 有意边界
 *
 * - **不读系统时间**：`make()` 不要求月份，是因为"打开看哪个月"由值决定
 *   （`value` 的月），值为空时退到 `initialMonth()`（默认 2000-01）。要"打开就
 *   跳到本月"是**应用**的事 —— 控件一读时钟，golden 基线就每天不一样。
 * - **选完即收**：只有一个交互面，点完就是完成。要"选完不关"的是
 *   `DateTimePicker`（两个面，选一半就关会让另一半得重新打开）。
 * - **`range` / `min/max` 不做**：前者契约留白，后者要先有禁用态。
 * - **没有下拉箭头**：内置字模缺 `▾`（同 `Select` 的能力边界第 1 条）。
 *
 * @api
 */
final class DatePicker extends StatefulWidget
{
    /** 触发框默认宽（逻辑像素）—— "2026-10-29" 十个字符放得下 */
    public const float DEFAULT_WIDTH = 180.0;

    /** 面板最小宽（日历自己声明 296 + 外壳内边距） */
    public const float PANEL_MIN_WIDTH = 304.0;

    /** 面板高度上限（日历 5~6 行 ≈ 250，留余量） */
    public const float PANEL_MAX_HEIGHT = 400.0;

    private ?LocalDate $value = null;

    private readonly YearMonth $initialMonth;

    private string $placeholder = '选择日期';

    private float $width = self::DEFAULT_WIDTH;

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

    /** 当前选中的日期（受控）；`null` ⇒ 触发框显示占位文字 */
    public function value(?LocalDate $date): static
    {
        $this->mutate();
        $this->value = $date;

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

    /** @param callable(LocalDate): void $handler */
    public function onChanged(callable $handler): static
    {
        $this->mutate();
        $this->onChanged = $handler(...);

        return $this;
    }

    // ── State 侧读的口径（`@internal`）──

    /** @internal */
    public function valueOf(): ?LocalDate
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
    public function onChangedHandler(): ?\Closure
    {
        return $this->onChanged;
    }

    /** 触发框上显示什么：有值 ⇒ ISO；没值 ⇒ 占位文字 */
    public function displayText(): string
    {
        $value = $this->value;

        return $value === null ? $this->placeholder : $value->iso();
    }

    public function createState(): State
    {
        return new DatePickerState();
    }
}

/**
 * `DatePicker` 的私有 State —— 骨架（触发框 / 面板开合 / 翻月通知）在
 * `CalendarPickerState`，这里只回答"面板里放什么"。
 *
 * @internal
 */
final class DatePickerState extends CalendarPickerState
{
    protected function trigger(): Button
    {
        return Button::make($this->picker()->displayText())
            ->variant(ButtonVariant::Default)
            ->width($this->picker()->widthValue());
    }

    protected function panelMinWidth(): float
    {
        return DatePicker::PANEL_MIN_WIDTH;
    }

    protected function panelMaxHeight(): float
    {
        return DatePicker::PANEL_MAX_HEIGHT;
    }

    protected function panelContent(): Widget
    {
        return CalendarView::make($this->shownMonth())
            ->selected($this->picker()->valueOf())
            ->onSelected(function (LocalDate $date): void {
                $this->commit($date);
            })
            ->onMonthChange(function (YearMonth $next): void {
                $this->flipMonth($next);
            });
    }

    protected function defaultMonth(): YearMonth
    {
        $value = $this->picker()->valueOf();

        return $value === null ? $this->picker()->initialMonthValue() : $value->yearMonth();
    }

    /** 面板在意的只有受控值（月份是私有态，走 `signal`） */
    protected function valueSignature(): string
    {
        $value = $this->picker()->valueOf();

        return $value === null ? '' : $value->iso();
    }

    /** 选中一天：**先收面板，再回调应用**（顺序见 `CalendarPickerState::dismissPanel()`） */
    private function commit(LocalDate $date): void
    {
        $handler = $this->picker()->onChangedHandler();
        $this->dismissPanel();

        if ($handler !== null) {
            $handler($date);
        }
    }

    private function picker(): DatePicker
    {
        $widget = $this->widget();
        if (!$widget instanceof DatePicker) {
            // 框架不变量：StatefulElement 按 get_class 配对，配错不可能发生
            Errors::report('DatePickerState 只能配 DatePicker（框架内部不变量被破坏）');
        }

        return $widget;
    }
}
