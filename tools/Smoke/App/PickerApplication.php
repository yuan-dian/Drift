<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\App\OverlayEntry;
use Drift\Layout\RenderBox;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\DatePicker;
use Drift\Widget\DateTimePicker;
use Drift\Widget\Element;
use Drift\Widget\LocalDate;
use Drift\Widget\LocalDateTime;
use Drift\Widget\LocalTime;
use Drift\Widget\Widget;
use Drift\Widget\YearMonth;

/**
 * `DatePicker` / `DateTimePicker` 夹具。
 *
 * 主树 = 日期选择器 + 日期时间选择器 + host 按钮（验"没声明浮层的按钮照旧"）。
 * 两个选择器都**只记录回调、不改值**，这样"受控"那半（画面不动）才测得出来。
 *
 * ★ 判据一律走真实激活链（`PointerEvent::down` ⇒ `activateFocusedWidget()`），
 *   坐标现取（`effectiveBox()` + `absoluteOrigin()`），不赌字模。
 *
 * @internal 测试夹具
 */
final class PickerApplication extends Application
{
    /** @var list<string> `DatePicker` 上报的日期（ISO） */
    public array $dates = [];

    /** @var list<string> `DateTimePicker` 上报的时刻（ISO） */
    public array $stamps = [];

    /** @var list<string> host 按钮回调 */
    public array $hostPressed = [];

    /** 两个选择器共用的初始月 */
    public YearMonth $month;

    public function __construct()
    {
        parent::__construct();
        $this->month = YearMonth::make(2026, 10);
    }

    public function buildRoot(): Widget
    {
        return Column::make()->spacing(12.0)->children([
            DatePicker::make($this->month)
                ->value(LocalDate::make(2026, 10, 15))
                ->onChanged(function (LocalDate $date): void {
                    $this->dates[] = $date->iso();
                }),
            DateTimePicker::make($this->month)
                ->value(LocalDateTime::make(
                    LocalDate::make(2026, 10, 15),
                    LocalTime::make(9, 5, 0),
                ))
                ->onChanged(function (LocalDateTime $value): void {
                    $this->stamps[] = $value->iso();
                }),
            Button::make('host')->onPressed(function (): void {
                $this->hostPressed[] = 'host';
            }),
        ]);
    }

    public function overlayCount(): int
    {
        return $this->overlays()->count();
    }

    public function topEntry(): ?OverlayEntry
    {
        return $this->overlays()->top();
    }

    /** 主树第 `$index` 个按钮（0 = DatePicker 触发框、1 = DateTimePicker、2 = host） */
    public function buttonAt(int $index): ?Element
    {
        $seen = 0;

        return self::findButtonN($this->root(), $index, $seen);
    }

    /** 主树第 `$index` 个按钮的中心（没排过 ⇒ `null`） */
    public function centerOf(int $index): ?array
    {
        return self::center($this->buttonAt($index));
    }

    /** 浮层面板里的全部文字按钮（文档序） */
    public function panelButtons(): array
    {
        $out = [];
        self::collectButtons($this->overlays()->top()?->element, $out);

        return $out;
    }

    /** 面板里标签为 `$label` 的按钮 */
    public function panelButton(string $label): ?Element
    {
        foreach ($this->panelButtons() as $element) {
            $widget = $element->widget();
            if ($widget instanceof Button && $widget->label() === $label) {
                return $element;
            }
        }

        return null;
    }

    /** 面板里某标签按钮的中心 */
    public function panelCenter(string $label): ?array
    {
        return self::center($this->panelButton($label));
    }

    /**
     * 面板里**横坐标 ≥ `$minX`** 的那一列中、标签为 `$label` 的按钮中心。
     *
     * ★ `DateTimePicker` 的面板里 "11" 会出现两次（日历的 11 号、时刻的 11 点）——
     *   按标签取必然撞车，必须按区域取。
     */
    public function panelCenterRightOf(float $minX, string $label): ?array
    {
        foreach ($this->panelButtons() as $element) {
            $widget = $element->widget();
            if (!$widget instanceof Button || $widget->label() !== $label) {
                continue;
            }

            $box = $element->effectiveBox();
            if ($box === null) {
                continue;
            }

            [$x] = self::absoluteOrigin($box);
            if ($x >= $minX) {
                return self::center($element);
            }
        }

        return null;
    }

    /** 面板里的文字（标题 / 表头都是 `Text`） */
    public function panelTexts(): array
    {
        $out = [];
        self::collectTexts($this->overlays()->top()?->element, $out);

        return $out;
    }

    /** 面板整体的宽高（没排过 ⇒ `[0, 0]`） */
    public function panelSize(): array
    {
        $box = $this->overlays()->top()?->element?->effectiveBox();
        $size = $box?->size();

        return [$size?->width ?? 0.0, $size?->height ?? 0.0];
    }

    private static function center(?Element $element): ?array
    {
        $box = $element?->effectiveBox();
        if ($box === null) {
            return null;
        }

        [$x, $y] = self::absoluteOrigin($box);
        $size = $box->size();

        return [$x + ($size?->width ?? 0.0) / 2.0, $y + ($size?->height ?? 0.0) / 2.0];
    }

    private static function findButtonN(?Element $element, int $want, int &$seen): ?Element
    {
        if ($element === null) {
            return null;
        }

        if ($element->widget() instanceof Button) {
            if ($seen === $want) {
                return $element;
            }
            ++$seen;
        }

        for ($i = 0, $n = $element->childCount(); $i < $n; $i++) {
            $hit = self::findButtonN($element->childAt($i), $want, $seen);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /** @param list<Element> $out */
    private static function collectButtons(?Element $element, array &$out): void
    {
        if ($element === null) {
            return;
        }

        if ($element->widget() instanceof Button) {
            $out[] = $element;

            return;   // 按钮没有子按钮
        }

        for ($i = 0, $n = $element->childCount(); $i < $n; $i++) {
            self::collectButtons($element->childAt($i), $out);
        }
    }

    /** @param list<string> $out */
    private static function collectTexts(?Element $element, array &$out): void
    {
        if ($element === null) {
            return;
        }

        $widget = $element->widget();
        if ($widget instanceof \Drift\Widget\Text) {
            $out[] = $widget->content();
        }

        for ($i = 0, $n = $element->childCount(); $i < $n; $i++) {
            self::collectTexts($element->childAt($i), $out);
        }
    }

    private static function absoluteOrigin(RenderBox $box): array
    {
        return RenderBox::absoluteOrigin($box);
    }
}
