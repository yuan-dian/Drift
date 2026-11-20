<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use App\Showcase\Pages\Base\StatefulShowcasePage;

use App\Showcase\ShowcaseCapabilities;
use Drift\Widget\Column;
use Drift\Widget\State;
use App\Showcase\Pages\States\DisabledPageState;

/**
 * 禁用态 disabled()——一个声明、四处消费。
 *
 * 演四件事，都要求**动手点**才看得出：
 *
 * 1. 禁用的按钮点不动，而它旁边的可用按钮照常累加（基线：同一条点击链）；
 * 2. `->disabled(false)` 是"恢复"，不是"声明过就永久禁用"；
 * 3. 禁用一个 `Column` ⇒ 里面的控件一起点不动（子树级）；
 * 4. 切换锁定后整块**变淡** —— 变淡是合成 alpha 折进去的，不是各控件自己调色。
 *
 * ★ 页面上写明 `enabled(false)` 与 `disabled()` 的关系：**同一个状态**（前者是后者按
 *   布尔取反的别名），不是两套并存的样式开关。收敛掉了四份局部实现，
 *   连带裁决"禁用不进 Tab 序"（见 `REVIEW-2026-10-28.md` §21）。
 */
final class DisabledPage extends StatefulShowcasePage
{
    public static function make(ShowcaseCapabilities $caps): self
    {
        return new self($caps);
    }

    public function createState(): State
    {
        return new DisabledPageState($this->caps);
    }
}
