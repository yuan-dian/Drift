<?php
declare(strict_types=1);

namespace App\Showcase;

use Drift\App\AppScope;
use Drift\App\Application;
use Drift\Async\Future;
use Drift\Commands\CommandHandler;
use Drift\Platform\Clipboard;
use Drift\Platform\Dialog;
use Drift\Widget\Modal;
use Drift\Widget\Snackbar;
use Drift\Widget\Widget;

use App\Showcase\Commands\ShowcaseCommands;
use App\Showcase\Jobs\FetchRowsJob;
use App\Showcase\ShowcaseCapabilities;

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
    public function __construct(
        private Dialog $dialog,
        private ?Clipboard $clipboard = null,
        ?AppScope $scope = null,
        private string $rowsMode = FetchRowsJob::MODE_LOCAL,
    ) {
        // ★ 不传 scope ⇒ 基类自建一个（单窗口时与 2026-10-30 之前逐字节相同）。
        //   多窗口时组合根传入**同一个**实例 ⇒ 字体 / 任务池 / IO 轮询器 / 主题
        //   只有一份（见 `AppScope` 类注释的五项表）。
        parent::__construct([], $scope);
    }

    public function buildRoot(): Widget
    {
        // ★ **应用侧接线**（§4.3 禁 `Widget → App`，`Menu::onOpen` 同款）：
        //   浮层归 `Application` 管，而页面在 `Drift\Widget` 层够不到它 ⇒ 由这里
        //   （唯一持有 `$this` 的地方）把"弹一条通知"这个能力递下去。
        //   ★ 2026-10-23 之前示例台写的是 `$this->application()->showSnackbar()`，
        //     那个方法**不存在** ⇒ 真机点一下进程直接崩、而 smoke 全绿。
        //
        // ★ **对话框端口（A5-①）不走闭包**：它不是"做一件事"，是一个有四种返回
        //   语义的服务（`?string` × 2 / `void` / `bool`）。包成闭包只会让调用点
        //   看不出返回值是什么。`Drift\Platform\Dialog` 是**端口接口**（`@api
        //   platform`），不是平台类 —— §4.3 的边界不变。
        //
        // ★ 它排在构造器**第一个且必填**（`?Clipboard` 之后就不行了：tpc 不许
        //   可选参数排在必选之前）：这一页是 `Dialog` 端口唯一的消费者，缺端口
        //   等于那个能力没接上 ⇒ 让组合根当场失败，而不是让按钮点了没反应。
        //   多出来的好处是不必把 `?Dialog` 收窄成 `Dialog`（AOT 收窄会读成 Var）。
        return ShowcaseRoot::make(new ShowcaseCapabilities(
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
            $this->dialog,
            // ★ 第四个能力，方向与上面三个**相反**（页面 → 应用）：打开/关闭
            //   "关闭前确认"这个开关。它会改变 `onCloseRequested()` 的答案，
            //   而那个方法只有 `Application` 有 —— 页面够不到（§4.3）。
            //   ★ 做成一个"设值"能力而不是让页面自己去拦：拦截点只有一处
            //     （帧边界、由 `EventLoop` 统一调用），页面只表达意图。
            function (bool $enabled): void {
                $this->setConfirmClose($enabled);
            },
            // ★ 第五个能力，也是本应用**唯一的异步入口**：取第 N 页。
            //   页面只认"给我第 N 页的结果"（`\Closure(int): Future`），背后是
            //   子进程池还是 `Future::resolved` 由组合根决定 ⇒ 业务与 UI 隔离
            //   落在**接线层**，页面零感知。见 `AsyncTablePage` 的类注释。
            //   ★ 走 `submit()` 而不是自己去拿 `TaskHost`：没有后台端口时它返回
            //     rejected 的 Future（页面落到 error 态 + 可重试），而"哪里没装"
            //     这件事在 `submit()` 内部报清楚 —— 这里不需要再写一遍。
            function (int $page): Future {
                return $this->submit(new FetchRowsJob($page, 20, $this->rowsMode));
            },
        ));
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

    /**
     * 「关闭前确认」开关（2026-10-30 可拦截关闭的**真实消费者**）。
     *
     * ★ 为什么这个状态住在 `Application` 上而不是页面里：回答"要不要关"的是
     *   `ShowcaseApplication::onCloseRequested()`，而页面够不到它（§4.3）——
     *   页面只负责**打开/关闭这个开关**（能力由组合根以闭包递下去，
     *   与 `after()` / `showModal()` 同一形状，方向从"应用→页面"变成"页面→应用"）。
     */
    private bool $confirmClose = false;

    /** 由「对话框」页的按钮调（经 `buildRoot()` 递下去的闭包） */
    public function setConfirmClose(bool $enabled): void
    {
        $this->confirmClose = $enabled;
    }

    /**
     * 关闭守卫（§5.1「关闭可拦截」）：开着就走原生确认框，关着直接放行。
     *
     * ★ 组合根对这个窗口调了 `setCloseGuard($handle, true)` ⇒ `WM_CLOSE` 会变成
     *   一条 `CloseRequestedEvent` 落到这里。**这就是完整的消费者**：
     *   拒绝 ⇒ 窗口原样留着（`confirm()` 返回 false）。
     * ★ 弹框在这里是安全的：调用点在**帧边界**（`EventLoop` 抽完事件之后），
     *   不在 WndProc 里 —— 壳为什么不能同步问，见 `enqueueCloseRequest` 的注释。
     */
    protected function onCloseRequested(): bool
    {
        if (!$this->confirmClose) {
            return true;
        }

        return $this->dialog->confirm(
            '关闭示例台',
            '「关闭前确认」是开着的。点「否」窗口会留着 —— 这是关闭可拦截的演示。',
        );
    }
}
