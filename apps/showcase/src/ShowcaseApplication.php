<?php
declare(strict_types=1);

namespace App\Showcase;

use Drift\App\Application;
use Drift\Widget\Widget;

/**
 * 示例台应用：只写三样（§4.5）—— `buildRoot()` / `title()`。
 *
 * ★ 平台名**不出现在这里**（组合根在 `bin/win32/Bootstrap.php`，§5.1）。
 * ★ 命令系统本应用**不用**：页切换是纯 UI 态（§12.3 判据：承载它的 State 不会被回收）
 *   ⇒ 左导航走 `Button::onPressed()` 闭包 + `setState()`，不注册命令
 *   （§11.4.3 A15：没有消费者不预先发明）。users 应用是命令系统的样板，
 *   这里刻意演示另一条合规路径。
 */
final class ShowcaseApplication extends Application
{
    public function buildRoot(): Widget
    {
        return ShowcaseRoot::make();
    }

    public function title(): string
    {
        return 'Drift 示例台';
    }
}
