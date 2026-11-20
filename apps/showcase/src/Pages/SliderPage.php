<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\StatefulShowcasePage;

use App\Showcase\ShowcaseCapabilities;
use Drift\Widget\Slider;
use Drift\Widget\State;
use App\Showcase\Pages\States\SliderState;

/**
 * 滑杆 Slider。
 *
 * 形态对照 iOS `UISlider` / WinUI `Slider`：一条轨道 + 一个拇指，连续取值。
 * 四段演示各管一条：
 *
 *   ① 连续（0–100，step 0）：点轨道立即跳值、拖拇指跟手、方向键按 1% 走；
 *   ② 步长（0–10，step 1）：量化真的生效（读数只会是整数）；
 *   ③ 窄盒（width 100）：几何全按盒宽推，不写死像素；
 *   ④ 禁用：点不动、方向键不吃。
 *
 * ★ 这一页同时是**能力边界的如实记录**（页末）：没有悬停态、没有"拖动时浮出
 *   数值气泡"、没有双向（垂直）滑杆。这些都是"没有消费者"的形态，不是漏做。
 */
#[Route(id: 'slider', title: '滑杆 Slider', icon: IconName::Sliders)]
final class SliderPage extends StatefulShowcasePage
{
    public static function make(ShowcaseCapabilities $caps): self
    {
        return new self($caps);
    }

    public function createState(): State
    {
        return new SliderState($this->caps);
    }
}
