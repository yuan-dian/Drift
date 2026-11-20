<?php
declare(strict_types=1);

namespace App\Showcase\Pages\States;

use Drift\Geometry\EdgeInsets;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\DatePicker;
use Drift\Widget\DateTimePicker;
use Drift\Widget\LocalDate;
use Drift\Widget\LocalDateTime;
use Drift\Widget\LocalTime;
use Drift\Widget\ScrollView;
use Drift\Widget\Text;
use Drift\Widget\TimeView;
use Drift\Widget\Widget;
use Drift\Widget\Wrap;
use Drift\Widget\YearMonth;
use App\Showcase\Pages\Base\ShowcaseState;

final class PickerPageState extends ShowcaseState
{
    private YearMonth $month;
    private LocalDate $date;
    private LocalDateTime $at;
    private LocalTime $time;

    public function initState(): void
    {
        $this->month = YearMonth::make(2026, 10);
        $this->date = LocalDate::make(2026, 10, 29);
        $this->at = LocalDateTime::make(LocalDate::make(2026, 10, 29), LocalTime::make(9, 5));
        $this->time = LocalTime::make(18, 30);
    }

    public function build(BuildContext $ctx): Widget
    {
        // ★ 顶层包 ScrollView：内容高于视口时**竖向**可滚动恢复，而不是被裁掉
        //   拿不回来（与 DisabledPage 同款修复）。内联内容只是触发框 + 日期/时间
        //   行，虚拟化的日历视图只在点击后的浮层里，不会和这里套出嵌套滚动。
        return ScrollView::make(
            Card::make(
                Column::make()->spacing(14.0)->padding(EdgeInsets::all(20.0))->children([
                    Text::make('日期时间 Picker')->title(),
                    Text::make('触发框 + 浮层：面板里就是 CalendarView / TimeView，它们并不知道自己在浮层里。')->caption(),
                    Text::make('日期与时刻是两个可复用件，DateTimePicker 只是把它们并排。')->caption(),
                    $this->dateRow(),
                    $this->dateTimeRow(),
                    $this->timeRow(),
                ])
            )->paddingAll(0.0)
        );
    }

    private function dateRow(): Widget
    {
        return Column::make()->spacing(6.0)->children([
            Text::make('DatePicker —— 值是一个 LocalDate，选完即收面板')->caption(),
            Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
                DatePicker::make($this->month)
                    ->value($this->date)
                    ->placeholder('选择日期')
                    ->onChanged(function (LocalDate $date): void {
                        $this->setState(function () use ($date): void {
                            $this->date = $date;
                            // 选中的日期一定在面板显示的月里 —— 跟着走，下次打开就停在那儿
                            $this->month = $date->yearMonth();
                        });
                    }),
                Text::make('当前：' . $this->date->iso())->caption(),
            ]),
        ]);
    }

    private function dateTimeRow(): Widget
    {
        return Column::make()->spacing(6.0)->children([
            Text::make('DateTimePicker —— 值是 LocalDateTime，左日历右时刻列；选完不收面板')->caption(),
            Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
                DateTimePicker::make($this->month)
                    ->value($this->at)
                    ->placeholder('选择日期时间')
                    ->onChanged(function (LocalDateTime $value): void {
                        $this->setState(function () use ($value): void {
                            $this->at = $value;
                        });
                    }),
                Text::make('当前：' . $this->at->label())->caption(),
            ]),
        ]);
    }

    private function timeRow(): Widget
    {
        return Column::make()->spacing(6.0)->children([
            Text::make('TimeView —— 时刻列可以单独用（三列带秒；默认只有时 / 分）')->caption(),
            Wrap::make()->runSpacing(16.0)->spacing(16.0)->children([
                Card::make(
                    TimeView::make($this->time)
                        ->withSeconds()
                        ->onChanged(function (LocalTime $time): void {
                            $this->setState(function () use ($time): void {
                                $this->time = $time;
                            });
                        })
                )->paddingAll(6.0),
                Text::make('当前：' . $this->time->label(true))->caption(),
            ]),
        ]);
    }
}
