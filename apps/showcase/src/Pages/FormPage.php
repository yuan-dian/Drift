<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use App\Showcase\Pages\Base\StatefulShowcasePage;

use App\Showcase\ShowcaseCapabilities;
use Drift\Widget\Card;
use Drift\Widget\Offstage;
use Drift\Widget\Row;
use Drift\Widget\State;
use Drift\Widget\Widget;
use App\Showcase\Pages\States\FormState;

/**
 * 表单 Form—— LayUI 文档页（layui.dev/docs/2/form/）的结构复刻。
 *
 * ★ 曾记的两处缺口今天都已闭合：通用圆角容器就是 `Card`（或 `SizedBox` 加
 *   `->background()` / `->radius()`；修饰挂在根 `Widget` 上，不是某个盒样式基类
 *   的特权）；固定宽标签在 `SizedBox` 到位后也做得出，本页 `field()` 仍是
 *   `Row(cross:End)` 的历史写法。仍缺的只有 label 位置，见页末对照。
 * ★ 预览/代码切换靠 `Offstage` + `setState`：保留状态、不画。
 */
final class FormPage extends StatefulShowcasePage
{
    public static function make(ShowcaseCapabilities $caps): self
    {
        return new self($caps);
    }

    public function createState(): State
    {
        return new FormState($this->caps);
    }
}
