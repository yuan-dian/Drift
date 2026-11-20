<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use App\Showcase\Pages\Base\StatefulShowcasePage;

use App\Showcase\ShowcaseCapabilities;
use Drift\Widget\Button;
use Drift\Widget\State;
use App\Showcase\Pages\States\ButtonsState;

/**
 * 按钮 Button—— 三变体 + 盒样式 + 悬停/光标（新落地的两个消费者）。
 *
 * ★ 本页是 **StatefulWidget**：悬停计数要住 State —— 不是页页都能 Stateless。
 *   `hover()`（§24.2 #16）与 `cursor()`（三方消费第 ③ 方）在这里
 *   有**真实消费者**：鼠标划过 ⇒ 计数 +1；命中 ⇒ 指针变手型。
 */
final class ButtonsPage extends StatefulShowcasePage
{
    public static function make(ShowcaseCapabilities $caps): self
    {
        return new self($caps);
    }

    public function createState(): State
    {
        return new ButtonsState($this->caps);
    }
}
