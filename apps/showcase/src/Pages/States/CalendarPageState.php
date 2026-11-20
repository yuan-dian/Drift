<?php
declare(strict_types=1);

namespace App\Showcase\Pages\States;

use Drift\Geometry\EdgeInsets;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\CalendarView;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\LocalDate;
use Drift\Widget\Row;
use Drift\Widget\State;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use Drift\Widget\Wrap;
use Drift\Widget\YearMonth;
use App\Showcase\Pages\Base\ShowcaseState;

final class CalendarPageState extends ShowcaseState
{
    private ?YearMonth $month = null;

    private ?LocalDate $selected = null;

    public function initState(): void
    {
        $this->month = YearMonth::make(2026, 10);
        $this->selected = LocalDate::make(2026, 10, 29);
    }

    public function build(BuildContext $ctx): Widget
    {
        return Card::make(
            Column::make()->spacing(10.0)->padding(EdgeInsets::all(20.0))->children([
                Text::make('日历 CalendarView')->title(),
                Text::make('7 列 × 5~6 行：前导格数由「1 号是周几」算，行数随月份形状变。')->caption(),
                Text::make('点标题在「日 → 月 → 年」之间切换：跨年翻月不用按 17 次箭头。')->caption(),
                Text::make('月份与选中值都在本页 State —— 工具栏和日历箭头改的是同一份数据；格子不进 Tab 序。')->caption(),
                $this->toolbar(),
                Row::make()->children([$this->calendarPanel()]),
            ])
        )->paddingAll(0.0);
    }

    private function toolbar(): Widget
    {
        $month = $this->month();

        return Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
            Button::make('上个月')->variant(ButtonVariant::Flat)->onPressed(function (): void {
                $this->setState(function (): void {
                    $this->month = $this->month()->plusMonths(-1);
                });
            }),
            Button::make('下个月')->variant(ButtonVariant::Flat)->onPressed(function (): void {
                $this->setState(function (): void {
                    $this->month = $this->month()->plusMonths(1);
                });
            }),
            Text::make(
                '显示 ' . $month->label() . ' · 选中 ' . ($this->selected?->iso() ?? '（无）')
            )->caption(),
        ]);
    }

    private function calendarPanel(): Widget
    {
        // ★ 白卡片只负责"框 + 内边距"：`CalendarView` 走 `createRenderObjectFromState()`
        //   返回 null 那条路（组合型 StatefulWidget），自己**不产渲染盒** ⇒ 给它套
        //   `padding()` 会响亮失败（修饰无处可去），留白落在外面这层。
        return Card::make(
            CalendarView::make($this->month())
                ->selected($this->selected)
                ->onSelected(function (LocalDate $date): void {
                    $this->setState(function () use ($date): void {
                        $this->selected = $date;
                        // 选中的日期一定在本月（别月格子留空、不可点）⇒ 这里不改月份，
                        // 但保留"跟着走"的写法：将来允许点别月时它自动正确
                        $this->month = $date->yearMonth();
                    });
                })
                ->onMonthChange(function (YearMonth $next): void {
                    $this->setState(function () use ($next): void {
                        $this->month = $next;
                    });
                })
        )->paddingAll(6.0);
    }

    private function month(): YearMonth
    {
        return $this->month ?? YearMonth::make(2026, 10);
    }
}
