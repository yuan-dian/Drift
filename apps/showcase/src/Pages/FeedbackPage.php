<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use App\Showcase\Pages\Base\StatefulShowcasePage;

use App\Showcase\ShowcaseCapabilities;
use Drift\Widget\Modal;
use Drift\Widget\Offstage;
use Drift\Widget\Spinner;
use Drift\Widget\State;
use App\Showcase\Pages\States\FeedbackState;

/**
 * 反馈 Feedback—— 三态（Loading / Error / Empty）+ Spinner + 模态对话框。
 *
 * 对照 LayUI 的提示体系（提示条 / 加载态 / 空数据）。Drift 的三态用
 * **封闭域 match 无 default**（§12.1 第 2 条：加一个状态编译期就叫）——
 * 本页把三种状态摆在同一屏，用 `Offstage` 切换（保留各自状态，不销毁）。
 *
 * ★ **模态对话框演示后来补上**：`Modal` 的关闭按钮（`X`）落地后，
 *   而示例台此前**没有任何一处** `Modal::make()` ⇒ 新机制在真机上不可见。
 *   示例台的职责就是"覆盖现有控件面"（见 `ShowcaseRoot` 类注释），所以这里
 *   给它一个入口，顺带把 `dismissible(false)` 的对照也摆上。
 */
final class FeedbackPage extends StatefulShowcasePage
{
    public static function make(ShowcaseCapabilities $caps): self
    {
        return new self($caps);
    }

    public function createState(): State
    {
        return new FeedbackState($this->caps);
    }
}
