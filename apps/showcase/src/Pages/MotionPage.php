<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\StatefulShowcasePage;

use App\Showcase\ShowcaseCapabilities;
use Drift\Widget\AnimationController;
use Drift\Widget\Curve;
use Drift\Widget\State;
use App\Showcase\Pages\States\MotionState;

/**
 * 动画 Motion—— `AnimationController` + `Curve` 的第一个消费者。
 *
 * ★ **这一页存在的理由**（§14.3 / §24.2 的判据）：框架能力**没有消费者 = 未实现**。
 *   `AnimationController::tick()` 若只被 smoke 夹具调到，它仍然是"一段没人用的
 *   算术"；进了示例台才有"每帧真的变了"这件事。
 *
 * ★ **演示的正是曲线的差别**：两个控制器**同时长**，一个 `easeOut`、一个 `linear`，
 *   同一个按钮一起 `forward()` ⇒ 两条进度条分开跑，一眼看出"快起慢收"是什么。
 */
#[Route(id: 'motion', title: '动画 Motion', icon: IconName::Play)]
final class MotionPage extends StatefulShowcasePage
{
    public static function make(ShowcaseCapabilities $caps): self
    {
        return new self($caps);
    }

    public function createState(): State
    {
        return new MotionState($this->caps);
    }
}
