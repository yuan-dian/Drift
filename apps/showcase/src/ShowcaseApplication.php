<?php
declare(strict_types=1);

namespace App\Showcase;

use Drift\App\Application;
use Drift\Commands\CommandHandler;
use Drift\Platform\Clipboard;
use Drift\Widget\Modal;
use Drift\Widget\Snackbar;
use Drift\Widget\Widget;

use App\Showcase\Commands\ShowcaseCommands;

/**
 * 示例台应用：只写三样（§4.5）—— `buildRoot()` / `title()`。
 *
 * ★ 平台名**不出现在这里**（组合根在 `bin/win32/Bootstrap.php`，§5.1）。
 * ★ 命令系统本应用**不用**：页切换是纯 UI 态（§12.3 判据：承载它的 State 不会被回收）
 *   ⇒ 左导航走 `Button::onPressed()` 闭包 + `setState()`，不注册命令
 *   （§11.4.3 A15：没有消费者不预先发明）。users 应用是命令系统的样板，
 *   这里刻意演示另一条合规路径。
 *
 * ★ **剪贴板端口由组合根递进来**（2026-10-21，#51）：`Application` 自己不持平台
 *   （§5.1 纪律 1）⇒ `clipboard()` 默认返回 `null`（编辑命令整族回落给加速键）；
 *   本应用是 `Ctrl+C/X/V` 的第一个真实消费者 ⇒ 构造收一个 `?Clipboard` 并转发。
 *   ★ 只递**端口**不递平台：字段里存的是 `Drift\Platform\Clipboard`（`@api
 *     platform`），Win32 实现仍然只出现在 Bootstrap —— §4.3 的边界不变。
 */
final class ShowcaseApplication extends Application
{
    /**
     * ★★★ **必须调 `parent::__construct()`**（2026-10-25 修）：基类在构造器里建
     *   七个拆分片（`router` / `textEditing` / `scrollInput` / `overlayHost` /
     *   `input` / `overlayLayout` / `pumps`），本类覆写构造器却不调父构造器
     *   ⇒ 那七个字段**全是未初始化**。
     *
     *   现象（真机实测，2026-10-21 → 2026-10-25 一直存在）：`attach()` 第一句
     *   `$this->pumps->attach()` 读未初始化属性 ⇒ 进程 `exit=255`、只剩
     *   "Attempt to read property on null"，**没有任何文件名与行号**。
     *   ★ 解释器本该抛 `Typed property must not be accessed before initialization`，
     *     但 AOT 给的是 `null` ⇒ 错误信息更含糊、更难点。
     *   ★ smoke 抓不到：79 处 `->attach()` 用的都是**调了父构造器**的测试应用
     *     ⇒ 这条只有真机能证伪（"smoke 全绿 ≠ 真机正确"的又一例）。
     *   闸门：`tools/Lint/Rules/AppSubclassCtorParent.php`（I2 同期加，判据写死）。
     */
    public function __construct(private ?Clipboard $clipboard = null)
    {
        parent::__construct();
    }

    public function buildRoot(): Widget
    {

        // ★ **应用侧接线**（§4.3 禁 `Widget → App`，`Menu::onOpen` 同款）：
        //   浮层归 `Application` 管，而页面在 `Drift\Widget` 层够不到它 ⇒ 由这里
        //   （唯一持有 `$this` 的地方）把"弹一条通知"这个能力递下去。
        //   ★ 2026-10-23 之前示例台写的是 `$this->application()->showSnackbar()`，
        //     那个方法**不存在** ⇒ 真机点一下进程直接崩、而 smoke 全绿。
        return ShowcaseRoot::make(
            function (Snackbar $snackbar): void {
                $this->showSnackbar($snackbar);
            },
            // ★ 第二个能力：**一次性延时回调**（`Application::after()`）。
            //   ComboBox 页用它模拟"服务端 400ms 后响应"—— 远程搜索的真实形状是
            //   **异步到达**，不是"点一个按钮让结果出现"。控件侧够不到定时器
            //   （§4.3：`Widget → App` 是禁的）⇒ 只能由组合根递进来。
            function (int $milliseconds, \Closure $callback): void {
                $this->after($milliseconds, $callback);
            },
            // ★ 第三个能力：**开一个模态**。Feedback 页的对话框演示用 ——
            //   `Modal` 是 Widget，而"打开"归 `Application`（§5.1 裁决 1）。
            //   `bindDismiss()` 的注入由 `OverlayManager::showModal()` 自己做，
            //   这里只递"能开"这一半。
            function (Modal $modal): void {
                $this->showModal($modal);
            },
        );
    }

    public function title(): string
    {
        return 'Drift 示例台';
    }

    public function clipboard(): ?Clipboard
    {
        return $this->clipboard;
    }

    /**
     * 动作缝注册表（§11.4.3）：可枚举、可 lint、可单测。
     *
     * ★ 框架在 `attach()` 里调它，用 `router()->on()` 拼好链的第 ③ 环。
     * ★ 这是示例台**第一个**命令族：顶条右侧"刷新"图标按钮
     *   （`AppBar::actions()` 里）走它。此前示例台刻意零命令（页切换是纯 UI 态，
     *   §12.3 / §11.4.3 A15：没有消费者不预先发明）—— 现在有消费者了。
     *
     * @return iterable<string, CommandHandler> `命令名 => handler 实例`
     */
    public function commands(): iterable
    {
        $notify = function (Snackbar $snackbar): void {
            $this->showSnackbar($snackbar);
        };

        return [
            'showcase.refresh' => new ShowcaseCommands($notify),
        ];
    }
}
