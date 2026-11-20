<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\StatefulShowcasePage;

use App\Showcase\ShowcaseCapabilities;
use Drift\Widget\Option;
use Drift\Widget\Select;
use Drift\Widget\State;
use Drift\Widget\Widget;
use App\Showcase\Pages\States\SelectState;

/**
 * 下拉选择 Select。
 *
 * 形态对照 Win32 `ComboBox` / Material `DropdownButton`：一个"选择框"，
 * 点开是一列选项，选中一项后把值交给应用（**受控**）。
 *
 * 七段演示：
 *
 *   ① 基本：三个城市，选中项在下拉里用强调色底标出来；
 *   ② 值 ≠ 显示名：`Option::make('北京市', '110000')` —— 值域是身份、标签是给人看的字；
 *   ③ 宽控件：`triggerWidth()` 定宽（标签靠左，没有下拉箭头）；
 *   ④ 长列表：30 项 ⇒ 面板被限高到 260 且**可以滚**（已支持）；
 *   ⑤ 映射短形式：`['110000' => '北京市']`（键 = 值）；
 *   ⑥ 行数组：`Option::fromRows($rows, 'name', 'id')`（DB / HTTP 的形状）；
 *   ⑦ 远程三态：加载中 / 失败 / 空表 —— 外加 `onOpen()` 打开才取数。
 *
 * ★ 应用侧只有这几个链式方法：`options / value / onChanged` + 三态
 *   `loading / emptyText / errorText` + `onOpen`。开面板、落位、点外面关、ESC 关、
 *   控件卸载自动收尸全在框架 —— 这与 Tooltip 是**同一类**声明式浮层
 *   （`Widget::overlayPanel()`），不需要组合根接线（与 Snackbar 相反）。
 * ★ 方向键（已支持）：面板打开后 ↓/↑ 移高亮（环绕）、Enter 提交。
 *   高亮是**控件私有态**，应用不用为"光标停在第几行"再存一个字段。
 * ★ 页末如实记录能力边界。
 */
#[Route(id: 'select', title: '下拉 Select', icon: IconName::ChevronDown)]
final class SelectPage extends StatefulShowcasePage
{
    public static function make(ShowcaseCapabilities $caps): self
    {
        return new self($caps);
    }

    public function createState(): State
    {
        return new SelectState($this->caps);
    }
}
