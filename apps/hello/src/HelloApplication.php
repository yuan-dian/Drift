<?php
declare(strict_types=1);

namespace App\Hello;

use Drift\App\Application;
use Drift\Widget\Center;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * §A.1 的最小应用：一个窗口画出「Hello Drift」。
 *
 * ★ 应用只写这三样（§4.5）：`buildRoot()` / `title()` / `commands()`。
 *   平台名**不出现在这里** —— 那是 `bin/<platform>/Bootstrap.php` 的职责。
 *
 * ★ 三个覆写是 `public` 不是 `protected`：基类 `Application` 把它们定义成
 *   public（devtools 要枚举 `commands()`），子类收窄可见性 ⇒ PHP Fatal。
 *   （§A.1 的样例原写 `protected`，照抄会编译不过 —— 见 §24.1 第 23 项。）
 */
final class HelloApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Center::make()->child(Text::make('Hello Drift')->title());
    }

    public function title(): string
    {
        return 'Hello Drift';
    }
}
