<?php
declare(strict_types=1);

namespace App\Users;

use Drift\App\Application;
use Drift\App\TaskHost;

use App\Users\Jobs\FetchUsersJob;
use Drift\Commands\CommandHandler;
use Drift\Input\AcceleratorTable;
use Drift\Input\KeyChord;
use Drift\Widget\Menu\Menu;
use Drift\Widget\Widget;

use App\Users\Commands\AppCommands;
use App\Users\Commands\NavigationCommands;
use App\Users\Commands\SignupCommands;
use App\Users\Commands\UserCommands;
use App\Users\Store\AppStore;
use App\Users\Store\SignupForm;
use App\Users\Store\UserSource;
use App\Users\Store\UserStore;

/**
 * 应用（§A.2.5）：title / buildRoot / commands / accelerators。
 *
 * ★ 基类只有 `__construct(iterable $handlers = [])` · `buildRoot()` · `title()` ·
 *   `commands()` · `theme()` · `metrics()` · `attach()` · `frame()` · `dispatch()` ·
 *   `router()` ⇒ 三条硬约束（§23.2 O-18 方案 A）：
 *   ① 三个覆写**全是 public**（子类收窄可见性 ⇒ PHP Fatal）；
 *   ② **没有 `boot()`** —— 依赖装配就在**构造点**（O-15 方案 E）；
 *   ③ `commands()` 返回 `iterable<string, CommandHandler>` 字典 —— 链的第 ③ 环
 *      由 `Application::attach()` 用 `router()->on()` 自动拼好。
 */
final class UsersApplication extends Application
{
    private AppStore $app;

    private UserStore $users;

    private SignupForm $form;

    private UserSource $src;

    /** 后台端口（`null` = 同步路径）；`Application::taskHost()` 覆写它 */
    private ?TaskHost $host;

    /** @internal `buildRoot()` 被调次数（"Root 只组合"的观测面：框架只该调一次） */
    private int $rootBuilds = 0;

    /**
     * 依赖装配：**就在这里**（没有 boot()）。
     *
     * ★ 后端由组合根注入（§11.7 / §A.3.9）：`Bootstrap` 决定给内存源还是线程池源
     *   ⇒ 换后端 = 组合根一行，页面与 store 代码零改动。
     * ★ handler **不在这时**注册：框架在 `attach()` 里才调 `commands()`，那一刻
     *   字段才就位 ⇒ 声明式注册只写一处。
     */
    /**
     * @param null|TaskHost $host 非 null ⇒ 首屏取数走**后台**（§17.4.5：进程池 / 同步队列）；
     *   null ⇒ 同步 `UserSource`（解释器与旧测试用）。★ 组合根的"一行"就在这里。
     * @param string $apiBase 后台取数的服务地址（默认 mock 后端；测试传随机端口）
     */
    public function __construct(
        UserSource $src,
        ?TaskHost $host = null,
        string $apiBase = FetchUsersJob::DEFAULT_BASE,
    ) {
        parent::__construct();

        $this->src = $src;
        $this->host = $host;
        $this->app = new AppStore();
        $this->users = new UserStore($this->src, $host, $apiBase);
        $this->form = new SignupForm();

        // 首屏：进 Loading（用户看得到）→ 结果由 frame 的 drain 段回贴（§11.5 / §17.4.2）
        $this->users->load();
    }

    public function title(): string
    {
        return '用户管理';
    }

    public function buildRoot(): Widget
    {
        $this->rootBuilds++;

        // ★ 菜单打开接线：把本应用的 `showMenu()` 包成闭包递给 Root
        //   （Widget 不许依赖 App —— §4.3；应用自己的类当然可以）。
        return UsersRoot::make($this->app, $this->users, $this->form)
            ->menuOpener(function (Menu $menu, ?\Drift\Widget\Element $anchor): void {
                $this->showMenu($menu->panel(), $anchor);
            });
    }

    // ── @internal 测试与 devtools 读法（M3 出口判据的断言面） ──────────

    public function rootBuilds(): int
    {
        return $this->rootBuilds;
    }

    public function appStore(): AppStore
    {
        return $this->app;
    }

    public function userStore(): UserStore
    {
        return $this->users;
    }

    public function signupForm(): SignupForm
    {
        return $this->form;
    }

    /**
     * 动作缝注册表（§11.4.3）：可枚举、可 lint、可单测。
     *
     * @return iterable<string, CommandHandler> `命令名 => handler 实例`
     */
    /**
     * ★★ **覆写注入点**（§17.4.2）：`Application` 每帧 drain 的就是这个实例。
     *   ★ 漏了这一步的后果（第一版就这样）：`UserStore` 拿的是注入的 `ProcessTaskHost`，
     *     而 `frame()` 用默认新建的 `SyncTaskHost` drain —— **两个实例、两套队列**，
     *     worker 早把结果回了，UI 却永远 `Loading`（不报错、不卡帧，最坏的那种静默）。
     */
    protected function taskHost(): ?TaskHost
    {
        return $this->host;
    }
    public function commands(): iterable
    {
        // ★ 方案 E（§23.2 O-15 裁决）：依赖在构造点 new 出来 ⇒ 不需要 provide() / 反射。
        //   多命令共用同一 handler ⇒ 提局部变量（不提会 new 出多个实例）。
        $nav = new NavigationCommands($this->app);
        $user = new UserCommands($this->users, $this->app);
        $signup = new SignupCommands($this->form, $this->src, $this->app, $this->users);
        $appCmds = new AppCommands($this->app);

        return [
            'nav.users' => $nav,
            'nav.signup' => $nav,
            'nav.settings' => $nav,
            'user.del' => $user,
            'users.refresh' => $user,
            'form.submit' => $signup,
            'app.quit' => $appCmds,
            'app.theme' => $appCmds,
        ];
    }

    /**
     * 应用级加速键（§9.1 路由链第 ① 环，在焦点之前）。
     *
     * ★ `AcceleratorTable` 2026-10-16 已落地（§4.2 `src/Input/` 行）—— 这一条
     *   正是 §19 M3 出口判据"Ctrl+S 等加速键可用"的落点。
     */
    public function accelerators(): AcceleratorTable
    {
        return AcceleratorTable::make()
            ->bind(KeyChord::ctrl('R'), 'users.refresh')
            ->bind(KeyChord::ctrl('S'), 'form.submit')
            ->bind(KeyChord::f(5), 'users.refresh');
    }
}
