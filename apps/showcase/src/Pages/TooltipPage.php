<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use App\Showcase\Pages\Base\StatefulShowcasePage;

use App\Showcase\ShowcaseCapabilities;
use Drift\Widget\State;
use App\Showcase\Pages\States\TooltipState;

/**
 * 悬停提示 Tooltip。
 *
 * 形态对照 WinUI `ToolTip` / macOS 的 `NSView.toolTip`：**指针停留 500ms** 才出现，
 * 离开即消失，已显示时移到相邻控件立即换内容。
 *
 * 四段演示：
 *
 *   ① 基础：一排图标按钮，各带一句提示；
 *   ② 长文案：超过 320 会换行（面板有宽度上限，不会横穿窗口）；
 *   ③ 相邻切换：两个按钮紧挨着 —— 从一个移到另一个，**不再等 500ms**；
 *   ④ 没有提示的对照：悬停它什么都不该发生。
 *
 * ★ 应用侧只有一行 `->tooltip('…')`：浮层的生命周期、落位、时序全在框架
 *   （`Application::onHoverHit()` / `showTooltip()`）。这正是"声明式"相对
 *   "自己写 hover 回调 + 自己 push 浮层"的收益。
 * ★ 页末如实记录能力边界。
 */
final class TooltipPage extends StatefulShowcasePage
{
    public static function make(ShowcaseCapabilities $caps): self
    {
        return new self($caps);
    }

    public function createState(): State
    {
        return new TooltipState($this->caps);
    }
}
