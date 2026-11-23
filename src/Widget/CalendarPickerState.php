<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 带月历浮层的选择器 State 基类（`DatePicker` / `DateTimePicker`）。
 *
 * 两者"触发框 + 浮层面板"的骨架逐字相同，差别只在面板内容与"提交后收不收"：
 * 前者选完即收，后者两个面（日历 + 时刻列）选一半就收会让另一半得重开。
 * 于是骨架留在这里，面板内容与提交语义交给子类。
 *
 * ★ 私有态只有两样：**翻到哪个月**（`$month`，`null` = 跟受控值走，每次打开复位）
 *   与**面板是否开着**（`$dismiss !== null` ⇔ 开着，不另设布尔——两个真相会漂）。
 * ★ 两条更新通道（同 `Select` 的分工）：受控值走框架的 `overlaySignature()`；
 *   翻月走 `PickerPanelSignal`（面板在第二棵树上，`setState` 到不了）。
 *
 * @internal
 */
abstract class CalendarPickerState extends State
{
    private ?YearMonth $month = null;

    private ?\Closure $dismiss = null;

    /** @internal 翻月后通知面板重读 */
    private PickerPanelSignal $signal;

    #[\Override]
    public function initState(): void
    {
        $this->signal = new PickerPanelSignal();
    }

    public function build(BuildContext $ctx): Widget
    {
        return $this->trigger()
            ->overlayPanel($this->panelFactory(), $this->panelMaxWidth())
            // 受控值变了 ⇒ 面板重读由框架负责（同 `Select`）
            ->overlaySignature(fn (): string => $this->valueSignature());
    }

    // ── 子类回答的问题 ──────────────────────────────────────────────────

    /** 触发框（`Button` 的 `Default` 变体 = 有底有边）。基类往上挂浮层声明，子类不管 */
    abstract protected function trigger(): Button;

    /** 面板内容最小宽（交给 `OptionPanel`） */
    abstract protected function panelMinWidth(): float;

    abstract protected function panelMaxHeight(): float;

    /** 面板内容 —— **每次重建都调**，必须读当前值，不能传快照 */
    abstract protected function panelContent(): Widget;

    /** 值为空且没翻过月时，面板先显示哪个月 */
    abstract protected function defaultMonth(): YearMonth;

    /** 面板在意的受控部分（受控值变了就得重读） */
    abstract protected function valueSignature(): string;

    /** 面板宽上限（`overlayPanel()` 的第二参）；`null` = 框架默认 */
    protected function panelMaxWidth(): ?float
    {
        return null;
    }

    // ── 子类可用的骨架 ──────────────────────────────────────────────────

    /** 面板这一帧显示哪个月：翻过就跟翻到的，否则跟受控值 */
    final protected function shownMonth(): YearMonth
    {
        $month = $this->month;

        return $month ?? $this->defaultMonth();
    }

    /**
     * 翻月：**只通知面板**，不 `setState` —— 触发框上的字一个字都不会变，
     * 标脏主树等于白重排一遍页面（同 `Select::moveHighlight()`）。
     */
    final protected function flipMonth(YearMonth $next): void
    {
        $this->month = $next;
        $this->signal->notifyChanged();
    }

    /**
     * 收起面板。**先收再回调应用**（顺序同 `Select::commit()`）—— 反过来会让面板
     * 带着新值多画一帧再消失，表现是"点一下闪一下"。
     */
    final protected function dismissPanel(): void
    {
        $dismiss = $this->dismiss;
        $this->dismiss = null;

        if ($dismiss !== null) {
            $dismiss();
        }
    }

    private function panelFactory(): \Closure
    {
        return function (\Closure $dismiss): Widget {
            $this->dismiss = $dismiss;
            // 每次打开都从受控值出发 —— 上次翻到 2025 年不该成为下次的起点
            $this->month = null;

            return OptionPanel::make(
                $this->signal,
                fn (): Widget => $this->panelContent(),
                $this->panelMinWidth(),
                $this->panelMaxHeight(),
                fn (): mixed => $this->onPanelClosed(),
                false,   // 日历与时刻列都定高 ⇒ 外壳再套一层 `ScrollView` 就是嵌套滚动（会崩）
            );
        };
    }

    /** 面板被回收 —— 三条关闭路径（点外面 / owner 清扫 / Esc）的唯一汇合点 */
    private function onPanelClosed(): void
    {
        $this->dismiss = null;
    }
}
