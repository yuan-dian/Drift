<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use App\Showcase\Pages\Base\StatefulShowcasePage;

use App\Showcase\ShowcaseCapabilities;
use Drift\Widget\DatePicker;
use Drift\Widget\DateTimePicker;
use Drift\Widget\State;
use Drift\Widget\TimeView;
use App\Showcase\Pages\States\PickerPageState;

/**
 * 日期时间 DatePicker / DateTimePicker / TimeView。
 *
 * ★ 这一页演的是**一个入口、两个实现件**：日期与时刻各自是独立控件
 *   （`CalendarView` / `TimeView`），`DateTimePicker` 只是把它们并排放进浮层。
 *   所以页面上第三块是**裸的 `TimeView`** —— 它在页面里和在浮层里是同一个东西。
 *
 * ★ 所有值住本页 `State`（受控）：选择器只上报，改不改由页面决定。
 * ★ 不读系统时间：初始值全是写死的常量 ⇒ golden 基线稳定。
 */
final class PickerPage extends StatefulShowcasePage
{
    public static function make(ShowcaseCapabilities $caps): self
    {
        return new self($caps);
    }

    public function createState(): State
    {
        return new PickerPageState($this->caps);
    }
}
