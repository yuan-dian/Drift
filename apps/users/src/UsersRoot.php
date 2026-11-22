<?php
declare(strict_types=1);

namespace App\Users;

use Drift\Input\KeyChord;
use Drift\Layout\Overflow;
use Drift\Routing\PageHost;
use Drift\Routing\RouteTable;
use Drift\Theme\ThemeScope;
use Drift\Widget\AppBar;
use Drift\Widget\BuildContext;
use Drift\Widget\Dock;
use Drift\Widget\Element;
use Drift\Widget\Environment;
use Drift\Widget\Menu\Menu;
use Drift\Widget\Menu\MenuBar;
use Drift\Widget\Menu\MenuItem;
use Drift\Widget\NavButton;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Widget;

use App\Users\Pages\SettingsPage;
use App\Users\Pages\SignupPage;
use App\Users\Pages\UsersPage;
use App\Users\Store\AppStore;
use App\Users\Store\SignupForm;
use App\Users\Store\UserStore;
use App\Users\Widgets\StatusBar;

/**
 * 组合根（§A.2.6 / §12.2）：**只组合，不写任何具体布局**。
 *
 * ★ 三件共享态 + 一个门面由构造点注入；订阅点全在控件内部（`StatusBar` /
 *   `PageHost` / 页面里的 `Bind`）—— 值**不在这里读**（"Root 只组合"
 *   由 lint 的 `dependency-direction` + 评审共同守）。
 * ★ 菜单打开与窗口拖动都走 `Environment`：这两件事以前各是一条注入闭包
 *   （`menuOpener` / `windowDrag`），类型都是 `\Closure` ⇒ 写反是静默 bug，
 *   而且"注入点有几个"取决于这款应用恰好要什么能力。
 */
final class UsersRoot extends StatelessWidget
{
    /** @var null|callable(): void `Enter` 提交（见 `submitHandler()`） */
    private ?\Closure $submitForm = null;

    public function __construct(
        private readonly AppStore $app,
        private readonly UserStore $users,
        private readonly SignupForm $form,
        private readonly Environment $env,
    ) {
        parent::__construct();
    }

    public static function make(
        AppStore $app,
        UserStore $users,
        SignupForm $form,
        Environment $env,
    ): self {
        return new self($app, $users, $form, $env);
    }

    /** @param callable(): void $submit 组合根递的 `fn () => $this->dispatch('form.submit')` */
    public function submitHandler(callable $submit): static
    {
        $this->mutate();
        $this->submitForm = $submit(...);

        return $this;
    }

    public function build(BuildContext $ctx): Widget
    {
        $theme = $ctx->theme();
        $env = $this->env;

        $bar = AppBar::make()->title('用户管理')->children([
            NavButton::make('列表')->command('nav.users'),
            NavButton::make('注册')->command('nav.signup'),
            NavButton::make('设置')->command('nav.settings'),
            MenuBar::make()->menus([
                Menu::make('文件')->items([
                    MenuItem::make('刷新')->chord(KeyChord::ctrl('R'))->command('users.refresh'),
                    MenuItem::make('新建')->chord(KeyChord::ctrl('N'))->command('nav.signup'),
                    MenuItem::make('返回')->chord(KeyChord::alt('Left'))->command('nav.back'),
                    MenuItem::separator(),
                    MenuItem::make('退出')->command('app.quit'),
                ])->onOpen(
                    // 菜单对象自己会造面板，锚点是命中它的那个元素
                    static function (Menu $menu, ?Element $anchor) use ($env): void {
                        $env->showMenu($menu->panel(), $anchor);
                    },
                ),
            ]),
        ]);

        // ★ 无边框窗：顶条当标题栏（可拖 + 三个窗口按钮）。两件事同源 ——
        //   "无边框"这个事实的两个后果，分成两个开关会写出"可拖但关不掉"。
        if ($env->supportsWindowDrag()) {
            $bar->windowDrag(static function () use ($env): void {
                $env->startWindowDrag();
            })->windowButtons(true);
        }

        return ThemeScope::make($theme, Dock::make()
            ->top($bar)
            // ★ 订阅在 StatusBar 内部（`Bind`）：这里只给 store
            ->bottom(StatusBar::make($this->app))
            // ★ 页名是格子 ⇒ PageHost 读它就是订阅（换页 = 旧页 Offstage 化）
            ->center(PageHost::make()
                ->from($this->app->pageSource())
                ->table($this->routeTable()))
            ->overflow(Overflow::Clip));
    }

    private function routeTable(): RouteTable
    {
        return RouteTable::make()
            ->route('users', fn(): Widget => UsersPage::make($this->users))
            ->route('signup', fn(): Widget => SignupPage::make($this->form, $this->submitForm))
            ->route('settings', fn(): Widget => SettingsPage::make($this->app));
    }
}
