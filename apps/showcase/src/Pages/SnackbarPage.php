<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use App\Showcase\Pages\Base\StatefulShowcasePage;

use App\Showcase\ShowcaseCapabilities;
use Drift\Widget\Snackbar;
use Drift\Widget\State;
use Drift\Widget\Widget;
use App\Showcase\Pages\States\SnackbarState;

/**
 * 底部通知 Snackbar。
 *
 * 形态对照 Material `SnackBar`：贴底居中的一条浮动卡片，`duration` 到了自动消失，
 * 可带一个动作按钮（「撤销」）。
 *
 * 四段演示：
 *
 *   ① 普通通知：4s 后自动消失；
 *   ② 带动作：点「撤销」→ 先调处理器，再收掉通知；
 *   ③ 排队：一次推三条 —— **第一条立即显示，后两条排队**，前一条走了才上位；
 *   ④ 不自动消失（`duration(0)`）：错误提示要用户自己确认。
 *
 * ★ 应用侧只有一行 `$this->caps->notify(Snackbar::make(...))`：队列、定时、落位全在框架。
 * ★ **`$notifier` 由组合根递进来**（`ShowcaseApplication::buildRoot()`）——
 *   `State` 上没有 `application()` 访问器（§4.3 禁 `Widget → App`）。
 * ★ 容易写错成 `$this->application()->showSnackbar()`：真机点一下进程
 *   直接崩、而 smoke 全绿（夹具拿着 `Application`，走的是另一条路）。
 * ★ 页末如实记录能力边界。
 */
final class SnackbarPage extends StatefulShowcasePage
{
    /** @param \Closure(Snackbar): void $notifier */
    /** @param \Closure(Snackbar): void $notifier */
    public static function make(ShowcaseCapabilities $caps): self
    {
        return new self($caps);
    }

    public function createState(): State
    {
        return new SnackbarState($this->caps);
    }
}
