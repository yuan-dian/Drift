<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\StatefulShowcasePage;

use App\Showcase\ShowcaseCapabilities;
use Drift\Widget\ComboBox;
use Drift\Widget\Option;
use Drift\Widget\State;
use Drift\Widget\Widget;
use App\Showcase\Pages\States\ComboBoxPageState;

/**
 * 可编辑下拉 ComboBox。
 *
 * 与 `Select` 的差别只有一条：**框里能打字**。点一下 ⇒ 框变搜索框、列出全部候选；
 * 打字 ⇒ 实时过滤；Enter 或点一项 ⇒ 选中，框回到新标签。
 *
 * 五段演示：
 *
 *   ① 基本：四个城市，打字过滤（"北" ⇒ 北京市 / 北京南站两项）；
 *   ② 值 ≠ 显示名：`Option::make('北京市', '110000')`；
 *   ③ 宽控件：`triggerWidth()` 定宽；
 *   ④ 30 项：面板限高 260 + 可滚 + 打字过滤（几百项也能靠打字收敛）；
 *   ⑤ **远程搜索**：`onSearch` + `status` —— 选项表由"服务端"给，本地过滤让位。
 *
 * ★ 应用侧只有五个链式方法：`options / value / placeholder / onChanged`，
 *   远程模式再加 `status / onOpen / onSearch`。开面板、落位、点外面关、ESC 关、
 *   控件卸载自动收尸全在框架（`Widget::overlayPanel()`，与 `Select` / Tooltip
 *   同一类声明式浮层）。
 * ★ 页末如实记录能力边界 —— 尤其是"方向键换高亮没有"以及为什么。
 */
#[Route(id: 'combo', title: '可编辑 Combo', icon: IconName::Search)]
final class ComboBoxPage extends StatefulShowcasePage
{
    /**
     *        （`Application::after()`）。⑤ 段用它模拟"服务端 400ms 后响应"：
     *        远程搜索的真实形状是**异步到达**，不是"点一个按钮让结果出现"。
     *
     * ★ 为什么不用按钮模拟：面板有"点面板外即关"的语义 ⇒ 点面板外的按钮会把面板
     *   关掉，用户永远看不到"结果到达后面板变了"这一幕。而真应用里到达是**回调**
     *   （`Job::onDone`），根本不需要点。
     * ★ 控件侧够不到定时器（§4.3 禁 `Widget → App`）⇒ 只能由组合根递进来
     *   （与通知页的 `$notify` 同一条路）。
     */
    public static function make(ShowcaseCapabilities $caps): self
    {
        return new self($caps);
    }

    public function createState(): State
    {
        return new ComboBoxPageState($this->caps);
    }

    /** @internal ⑤ 段的 `State` 用它排延时 */
    public function delayClosure(): \Closure
    {
        $caps = $this->caps;
        return static function (int $milliseconds, \Closure $callback) use ($caps): void {
            $caps->delay($milliseconds, $callback);
        };
    }
}
