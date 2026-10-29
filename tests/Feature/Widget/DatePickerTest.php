<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Smoke\App\PickerApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\PointerEvent;
use Drift\Widget\Button;
use Drift\Widget\DatePicker;
use Drift\Widget\DateTimePicker;
use Drift\Widget\LocalDate;
use Drift\Widget\LocalDateTime;
use Drift\Widget\LocalTime;
use Drift\Widget\YearMonth;

/**
 * `DatePicker` / `DateTimePicker`：触发框 + 浮层（2026-10-29）。
 *
 * ## 判据钉什么
 *
 * ① 激活 ⇒ 框架开面板（`overlayPanel()` 这条通用缝对选择器同样成立）；
 * ② 面板里真的是日历（标题 + 31 格），`DateTimePicker` 还并排了时分列；
 * ③ `DatePicker` 选完即收；`DateTimePicker` 选完**不收**（两个面还要继续用）；
 * ④ 受控：只上报、触发框文字不动；
 * ⑤ 不影响既有控件（host 按钮照旧 `onPressed`）。
 *
 * ★ ③ 的差别是这两个类唯一的行为分叉，也是最容易被"顺手统一"掉的一条。
 */
final class DatePickerTest extends SmokeTestCase
{
    private const W = 900.0;
    private const H = 700.0;

    private function constraints(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(self::W, self::H));
    }

    private function boot(): PickerApplication
    {
        $app = new PickerApplication();
        $app->attach(new SurfaceProbe(), $this->constraints());
        $app->root()?->effectiveBox()?->layout($this->constraints());
        $app->frame(1000.0, $this->constraints());

        return $app;
    }

    /** 点主树第 `$index` 个按钮的中心 */
    private function press(PickerApplication $app, int $index): void
    {
        $center = $app->centerOf($index);
        $app->dispatchInput(PointerEvent::down($center[0] ?? 0.0, $center[1] ?? 0.0));
        $app->frame(1100.0, $this->constraints());
    }

    // ── ① 开面板 ───────────────────────────────────────────────────────

    public function testActivationOpensPanel(): void
    {
        $app = $this->boot();
        check('DatePicker · 初始没有浮层', $app->overlayCount() === 0, 'count=' . $app->overlayCount());

        $this->press($app, 0);

        check('DatePicker · 点触发框 ⇒ 开一层浮层', $app->overlayCount() === 1, 'count=' . $app->overlayCount());

        [$w, $h] = $app->panelSize();
        // ★ 尺寸单独断言：位置对但尺寸 0 = 画面一丝不变（Tooltip 那次就是这样漏的）
        check('DatePicker · 面板宽高非零', $w > 0.0 && $h > 0.0, 'size=' . $w . 'x' . $h);
        check('DatePicker · 面板宽 ≥ PANEL_MIN_WIDTH', $w >= DatePicker::PANEL_MIN_WIDTH, 'w=' . $w);
    }

    // ── ② 面板内容 ─────────────────────────────────────────────────────

    public function testPanelContainsCalendar(): void
    {
        $app = $this->boot();
        $this->press($app, 0);

        // ★ 标题是 `Button`（有 onMonthChange ⇒ 可点切视图），不是 `Text`
        check(
            'DatePicker · 面板标题是 2026 年 10 月',
            $app->panelButton('2026 年 10 月') !== null,
            'texts=' . \json_encode($app->panelTexts())
        );

        // 15 是受控值 ⇒ Primary；同时它必须是日历格而不是别的东西
        $day = $app->panelButton('15')?->widget();
        check('DatePicker · 面板里有 15 号格子', $day instanceof Button);
        check('DatePicker · 15 号是 Primary（受控值高亮）', $day instanceof Button && $day->variantValue()->name === 'Primary');

        // 31 个日期格（1..31 各一个）
        $days = 0;
        foreach ($app->panelButtons() as $element) {
            $widget = $element->widget();
            if ($widget instanceof Button && \preg_match('/^\d{1,2}$/', $widget->label()) === 1) {
                $days++;
            }
        }
        check('DatePicker · 面板里正好 31 个日期格', $days === 31, 'n=' . $days);
    }

    public function testDateTimePanelHasBothPanels(): void
    {
        $app = $this->boot();
        $this->press($app, 1);

        $texts = $app->panelTexts();
        check(
            'DateTimePicker · 面板里有日历标题',
            $app->panelButton('2026 年 10 月') !== null,
            'texts=' . \json_encode($texts)
        );
        check('DateTimePicker · 面板里有「时」表头', \in_array('时', $texts, true));
        check('DateTimePicker · 面板里有「分」表头', \in_array('分', $texts, true));
        check('DateTimePicker · 默认没有「秒」表头', !\in_array('秒', $texts, true));

        [$w] = $app->panelSize();
        $min = DateTimePicker::panelMinWidth(false);
        check(
            'DateTimePicker · 面板比单独日历宽（并排两块）',
            $w >= $min,
            'w=' . $w . ' min=' . $min
        );
    }

    // ── ③ 选完收 / 不收 ────────────────────────────────────────────────

    public function testDatePickerClosesAfterPickingDay(): void
    {
        $app = $this->boot();
        $this->press($app, 0);

        $center = $app->panelCenter('20');
        $app->dispatchInput(PointerEvent::down($center[0] ?? 0.0, $center[1] ?? 0.0));
        $app->frame(1200.0, $this->constraints());

        check('DatePicker · 点 20 号 ⇒ 上报 2026-10-20', $app->dates === ['2026-10-20'], 'dates=' . \json_encode($app->dates));
        check('DatePicker · 选完面板收起', $app->overlayCount() === 0, 'count=' . $app->overlayCount());
    }

    public function testDateTimePickerKeepsPanelOpenAfterPickingDay(): void
    {
        $app = $this->boot();
        $this->press($app, 1);

        $center = $app->panelCenter('20');
        $app->dispatchInput(PointerEvent::down($center[0] ?? 0.0, $center[1] ?? 0.0));
        $app->frame(1200.0, $this->constraints());

        check(
            'DateTimePicker · 点 20 号 ⇒ 上报 2026-10-20T09:05:00（时刻不变）',
            $app->stamps === ['2026-10-20T09:05:00'],
            'stamps=' . \json_encode($app->stamps)
        );
        check('DateTimePicker · 面板仍然开着（还要选时刻）', $app->overlayCount() === 1, 'count=' . $app->overlayCount());
    }

    public function testDateTimePickerPickingHourKeepsDate(): void
    {
        $app = $this->boot();
        $this->press($app, 1);

        // 时列当前滚到 07 起（9 - LEAD_ROWS），点 11
        // ★ "11" 在日历里也有（11 号）⇒ 必须按区域取：时刻列在日历右侧（x ≥ 296）
        $center = $app->panelCenterRightOf(296.0, '11');
        $app->dispatchInput(PointerEvent::down($center[0] ?? 0.0, $center[1] ?? 0.0));
        $app->frame(1200.0, $this->constraints());

        check(
            'DateTimePicker · 点 11 点 ⇒ 2026-10-15T11:05:00（日期不变）',
            $app->stamps === ['2026-10-15T11:05:00'],
            'stamps=' . \json_encode($app->stamps)
        );
        check('DateTimePicker · 面板仍然开着', $app->overlayCount() === 1, 'count=' . $app->overlayCount());
    }

    // ── ④ 受控 ─────────────────────────────────────────────────────────

    public function testTriggerTextDoesNotChangeWithoutAppState(): void
    {
        $app = $this->boot();
        $trigger = $app->buttonAt(0)?->widget();
        check('DatePicker · 触发框显示受控值', $trigger instanceof Button && $trigger->label() === '2026-10-15', 'label=' . ($trigger instanceof Button ? $trigger->label() : '?'));

        // 夹具的 onChanged 只记录不改值 ⇒ 面板收掉后触发框照旧
        $this->press($app, 0);
        $center = $app->panelCenter('20');
        $app->dispatchInput(PointerEvent::down($center[0] ?? 0.0, $center[1] ?? 0.0));
        $app->frame(1200.0, $this->constraints());

        $after = $app->buttonAt(0)?->widget();
        check(
            'DatePicker · 应用不改值 ⇒ 触发框文字不动（受控）',
            $after instanceof Button && $after->label() === '2026-10-15',
            'label=' . ($after instanceof Button ? $after->label() : '?')
        );
    }

    // ── ⑤ 不影响既有控件 ───────────────────────────────────────────────

    public function testHostButtonStillWorks(): void
    {
        $app = $this->boot();
        $this->press($app, 2);

        check('PickerApplication · host 按钮照旧走 onPressed', $app->hostPressed === ['host'], 'host=' . \json_encode($app->hostPressed));
        check('PickerApplication · host 按钮不开浮层', $app->overlayCount() === 0, 'count=' . $app->overlayCount());
    }

    // ── 纯值对象 ───────────────────────────────────────────────────────

    public function testLocalTimeValueObject(): void
    {
        $t = LocalTime::make(9, 5);
        check('LocalTime · label 默认不带秒', $t->label() === '09:05', 'label=' . $t->label());
        check('LocalTime · iso 恒带秒', $t->iso() === '09:05:00', 'iso=' . $t->iso());
        check('LocalTime · fromIso 收 HH:MM', LocalTime::fromIso('09:05')->equals($t));
        check('LocalTime · fromIso 收 HH:MM:SS', LocalTime::fromIso('09:05:00')->equals($t));
        check('LocalTime · totalSeconds', $t->totalSeconds() === 9 * 3600 + 5 * 60);
        check('LocalTime · 比较', LocalTime::make(9, 5)->compareTo(LocalTime::make(9, 6)) < 0);
    }

    public function testLocalDateTimeValueObject(): void
    {
        $at = LocalDateTime::make(LocalDate::make(2026, 10, 29), LocalTime::make(9, 5));
        check('LocalDateTime · iso 用 T 分隔', $at->iso() === '2026-10-29T09:05:00', 'iso=' . $at->iso());
        check('LocalDateTime · label 用空格', $at->label() === '2026-10-29 09:05', 'label=' . $at->label());
        check('LocalDateTime · fromIso 收 T', LocalDateTime::fromIso('2026-10-29T09:05:00')->equals($at));
        check('LocalDateTime · fromIso 收空格', LocalDateTime::fromIso('2026-10-29 09:05:00')->equals($at));
        check(
            'LocalDateTime · withTime 只换时刻',
            $at->withTime(LocalTime::make(18, 30))->iso() === '2026-10-29T18:30:00',
            'iso=' . $at->withTime(LocalTime::make(18, 30))->iso()
        );
    }

    public function testDefaultMonthsAreStable(): void
    {
        check('DatePicker · make() 不读时钟（默认 2000-01）', DatePicker::make()->initialMonthValue()->equals(YearMonth::make(2000, 1)));
        check('DateTimePicker · 同上', DateTimePicker::make()->initialMonthValue()->equals(YearMonth::make(2000, 1)));
    }
}
