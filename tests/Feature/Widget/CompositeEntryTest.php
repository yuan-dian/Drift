<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\App\Application;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Widget\Button;
use Drift\Widget\CalendarView;
use Drift\Widget\Element;
use Drift\Widget\LocalDate;
use Drift\Widget\LocalTime;
use Drift\Widget\TimeView;
use Drift\Widget\Widget;
use Drift\Widget\YearMonth;

/**
 * 复合控件的键盘入口（§57）：**roving tabindex** —— 整块只留一个"活动格"进 Tab 序。
 *
 * 改前的实况（两个都测了）：日历 Tab 停在外部按钮上不动、时刻表 `focusNext()` 直接
 * 落空 ⇒ **键盘完全进不去**。而两处注释都写着"整张日历只该有一个入口"—— 那个入口
 * 从来没做。判据钉三件事，少一件都能靠"全进"或"全不进"蒙过去：
 *
 * 1. **进得去**：Tab 落在活动格上；
 * 2. **不爆 Tab 序**：31 / 144 格不进（进了要按几十次才出得去）；
 * 3. **占站 ⇔ 真按得动**：没回调的格子与箭头连入口都不占（按了没反应比进不去更糟）。
 */
final class CompositeEntryTest extends SmokeTestCase
{
    private const W = 360.0;

    private const H = 520.0;

    private static function constraints(): BoxConstraints
    {
        return BoxConstraints::tight(new Size(self::W, self::H));
    }

    private function app(Widget $root): Application
    {
        $app = new class extends Application {
            private Widget $testRoot;

            public function setRoot(Widget $widget): void
            {
                $this->testRoot = $widget;
            }

            public function buildRoot(): Widget
            {
                return $this->testRoot;
            }
        };
        $app->setRoot($root);
        $probe = new SurfaceProbe();
        $probe->resize((int) self::W, (int) self::H, 1.0);
        $app->attach($probe, self::constraints());
        $app->frame(0.0, self::constraints());

        return $app;
    }

    /**
     * Tab 序里各站的标签（文档序）。
     *
     * 走 `FocusManager::order()` 而不是遍历 `focusNode()`：焦点节点为 `null` 不等于
     * "不进 Tab 序"（`skipFocus()` 是声明，节点可能压根没建）—— 那是断言没牙。
     *
     * @return list<string>
     */
    private function stops(Application $app): array
    {
        $out = [];
        foreach ($app->focus()?->order() ?? [] as $element) {
            $widget = $element->widget();
            $out[] = $widget instanceof Button ? $widget->label() : $widget::class;
        }

        return $out;
    }

    private function where(Application $app): string
    {
        $element = $app->focus()?->focused();
        if ($element === null) {
            return 'null';
        }

        $widget = $element->widget();

        return $widget instanceof Button ? $widget->label() : $widget::class;
    }

    private function label(Application $app, string $text): ?Element
    {
        $root = $app->root();
        if ($root === null) {
            return null;
        }

        return findElement(
            $root,
            static fn(Element $e): bool => $e->widget() instanceof Button && $e->widget()->label() === $text,
        );
    }

    // ── 日历 ───────────────────────────────────────────────────────────

    public function testCalendarEntersAtTheSelectedDay(): void
    {
        $app = $this->app(CalendarView::make(YearMonth::make(2026, 10))
            ->selected(LocalDate::make(2026, 10, 15))
            ->onSelected(static function (LocalDate $date): void {})
            ->onMonthChange(static function (YearMonth $month): void {}));

        $stops = $this->stops($app);
        check(
            'roving · 日历 Tab 序恰好 4 站（‹ / 标题 / › / 活动日）',
            $stops === ['上个月', '2026 年 10 月', '下个月', '15'],
            'stops=' . \json_encode($stops),
        );

        $app->focus()?->focusNext();
        check('roving · Tab 第一站是上个月箭头', $this->where($app) === '上个月', $this->where($app));

        $app->focus()?->focusNext();
        $app->focus()?->focusNext();
        $app->focus()?->focusNext();
        check(
            'roving · Tab 进到日历里落点是**选中日**（15 号）',
            $this->where($app) === '15',
            $this->where($app),
        );

        $this->flushChecks();
    }

    public function testCalendarWithoutSelectionEntersAtDayOne(): void
    {
        $app = $this->app(CalendarView::make(YearMonth::make(2026, 10))
            ->onSelected(static function (LocalDate $date): void {}));

        check(
            'roving · 没选中日 ⇒ 入口是 1 号（不猜"今天"：视图受控，值只来自应用）',
            $this->stops($app) === ['1'],
            'stops=' . \json_encode($this->stops($app)),
        );

        $this->flushChecks();
    }

    public function testReadOnlyCalendarHasNoTabStop(): void
    {
        // 一个回调都不给：格子按下去什么都不会发生 ⇒ 连入口都不该占
        $app = $this->app(CalendarView::make(YearMonth::make(2026, 10))
            ->selected(LocalDate::make(2026, 10, 15)));

        check(
            'roving · 只读日历一个站都不占（占站 ⇔ 真按得动）',
            $this->stops($app) === [],
            'stops=' . \json_encode($this->stops($app)),
        );

        $this->flushChecks();
    }

    public function testArrowKeysWalkBetweenDays(): void
    {
        $app = $this->app(CalendarView::make(YearMonth::make(2026, 10))
            ->selected(LocalDate::make(2026, 10, 15))
            ->onSelected(static function (LocalDate $date): void {}));

        $app->focus()?->focus($this->label($app, '15'));
        $app->focus()?->dispatchKey(KeyEvent::down(KeyChord::right()));
        check(
            'roving · 从 15 按 → ⇒ 16（其余格子不进 Tab 序，但方向键够得着）',
            $this->where($app) === '16',
            $this->where($app),
        );

        $app->focus()?->dispatchKey(KeyEvent::down(KeyChord::down()));
        check('roving · 再按 ↓ ⇒ 23（同一列）', $this->where($app) === '23', $this->where($app));

        $this->flushChecks();
    }

    public function testEnterSelectsTheFocusedDay(): void
    {
        $picked = [];
        $app = $this->app(CalendarView::make(YearMonth::make(2026, 10))
            ->selected(LocalDate::make(2026, 10, 15))
            ->onSelected(static function (LocalDate $date) use (&$picked): void {
                $picked[] = $date->iso();
            }));

        $app->focus()?->focus($this->label($app, '15'));
        $app->focus()?->dispatchKey(KeyEvent::down(KeyChord::right()));

        // ★ 走**完整派发**：激活是第 ③ 环的活，`dispatchKey()` 只管 ①（焦点链）
        $app->dispatchInput(KeyEvent::down(KeyChord::enter()));

        check(
            'roving · Enter 选中焦点所在的 16 号（激活经第 ③ 环）',
            $picked === ['2026-10-16'],
            'picked=' . \json_encode($picked),
        );

        $this->flushChecks();
    }

    /**
     * 标题是键盘进月/年视图的**唯一**入口 —— 它必须是 Tab 站，且 Enter 真能切。
     *
     * ★ 顺带钉住月视图的 roving：12 个格子里只有"当前月"占站。
     */
    public function testTitleSwitchesToMonthViewByKeyboard(): void
    {
        $app = $this->app(CalendarView::make(YearMonth::make(2026, 10))
            ->onSelected(static function (LocalDate $date): void {})
            ->onMonthChange(static function (YearMonth $month): void {}));

        $title = $this->label($app, '2026 年 10 月');
        check('roving · 标题在 Tab 序里（能切视图才有它）', $title !== null);
        if ($title === null) {
            $this->flushChecks();

            return;
        }

        $app->focus()?->focus($title);
        $app->dispatchInput(KeyEvent::down(KeyChord::enter()));
        $app->frame(32.0, self::constraints());

        $stops = $this->stops($app);
        check(
            'roving · 标题按 Enter ⇒ 切到月视图，12 格里只有"10 月"占站',
            $stops === ['上一年', '2026 年', '下一年', '10 月'],
            'stops=' . \json_encode($stops),
        );

        $this->flushChecks();
    }

    // ── 时刻表 ─────────────────────────────────────────────────────────
    public function testTimeViewEntersAtTheCurrentValue(): void
    {
        $app = $this->app(TimeView::make(LocalTime::make(9, 5))
            ->onChanged(static function (LocalTime $time): void {}));

        $stops = $this->stops($app);
        check(
            'roving · 时刻表 Tab 序恰好 2 站（时列 09 / 分列 05）',
            $stops === ['09', '05'],
            'stops=' . \json_encode($stops),
        );

        $app->focus()?->focusNext();
        check('roving · Tab 落进时列当前值', $this->where($app) === '09', $this->where($app));

        $app->focus()?->focusNext();
        check('roving · 再 Tab 落到分列当前值', $this->where($app) === '05', $this->where($app));

        $this->flushChecks();
    }

    public function testTimeViewWithSecondsHasOneStopPerColumn(): void
    {
        $app = $this->app(TimeView::make(LocalTime::make(9, 5, 7))
            ->withSeconds()
            ->onChanged(static function (LocalTime $time): void {}));

        $stops = $this->stops($app);
        check(
            'roving · 三列 ⇒ 3 站（每列一个，144 格不全进）',
            $stops === ['09', '05', '07'],
            'stops=' . \json_encode($stops),
        );

        $this->flushChecks();
    }

    public function testReadOnlyTimeViewHasNoTabStop(): void
    {
        $app = $this->app(TimeView::make(LocalTime::make(9, 5)));

        check(
            'roving · 只读时刻表一个站都不占',
            $this->stops($app) === [],
            'stops=' . \json_encode($this->stops($app)),
        );

        $this->flushChecks();
    }
}
