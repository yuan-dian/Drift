<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\StatefulShowcasePage;

use App\Showcase\ShowcaseCapabilities;
use Drift\Widget\State;
use App\Showcase\Pages\States\SegmentedState;

/**
 * 分段控件 Segmented—— W8 控件增补的第一个控件。
 *
 * 形态对照 iOS `UISegmentedControl` / WinUI `Segmented`：一组平级的视图切换，
 * **选中恰好一段**。三段演示各管一条：
 *
 *   ① 三段 + 受控值：点了只回调新序号，值住 State（与 Checkbox / TextField 同纪律）；
 *   ② 两段：最短形态（等宽段在标签长短不齐时也齐）；
 *   ③ 整组禁用：整组不进 Tab 序、点不动。
 *
 * ★ 这一页同时是**能力边界的如实记录**：方向键在段间移动焦点今天没有
 *   （Tab 一段一格，与 Win32 单选组一致）；悬停高亮没有视觉态。见页末。
 */
#[Route(id: 'segmented', title: '分段 Segmented', icon: IconName::Menu)]
final class SegmentedPage extends StatefulShowcasePage
{
    public static function make(ShowcaseCapabilities $caps): self
    {
        return new self($caps);
    }

    public function createState(): State
    {
        return new SegmentedState($this->caps);
    }
}
