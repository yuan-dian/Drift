<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use App\Showcase\Pages\Base\StatefulShowcasePage;

use App\Showcase\ShowcaseCapabilities;
use Drift\Widget\CalendarView;
use Drift\Widget\State;
use App\Showcase\Pages\States\CalendarPageState;

/**
 * 日历 CalendarView。
 *
 * ★ 这一页演的是**受控**的另一半：月份住本页 `State`，所以工具栏的「上个月 /
 *   下个月」能直接翻 —— 与日历自己的箭头走的是同一条路（都只上报，值由应用改）。
 *   控件自持的话，"打开时跳到某月 / 记住上个月看的月份"应用一件也做不到。
 *
 * ★ 日历**不读系统时间**：没有"今天"高亮，`selected` 也只是应用传进来的一个值。
 *   这是刻意的 —— 一读时钟，golden 基线就每天都不一样。
 */
final class CalendarPage extends StatefulShowcasePage
{
    public static function make(ShowcaseCapabilities $caps): self
    {
        return new self($caps);
    }

    public function createState(): State
    {
        return new CalendarPageState($this->caps);
    }
}
