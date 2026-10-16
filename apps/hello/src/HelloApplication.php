<?php
declare(strict_types=1);

namespace App\Hello;

use Drift\App\Application;
use Drift\Input\CursorShape;
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
        // ★ `->cursor()` 是 §9.1「一次命中，三方消费」的第 ③ 方（2026-10-15 落地）：
        //   声明一次，命中链从命中的盒向上取**最近**的声明者 ⇒ 整窗可点。
        //   真机验证：鼠标移进窗口后 `GetCursorInfo` 报 `IDC_HAND`（不是默认箭头）。
        //   这是 §20 纪律 4「每个契约能力必须在示例中出现」的落点 —— 也是最省的
        //   一条：不新增页面、不新增控件，只多一个链式调用。
        return Center::make()->cursor(CursorShape::PointingHand)->child(
            Text::make('Hello Drift')->title()
        );
    }

    public function title(): string
    {
        return 'Hello Drift';
    }
}
