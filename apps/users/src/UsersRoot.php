<?php
declare(strict_types=1);

namespace App\Users;

use Drift\Input\KeyChord;
use Drift\Layout\Overflow;
use Drift\Routing\PageHost;
use Drift\Routing\RouteTable;
use Drift\Theme\SystemTheme;
use Drift\Theme\ThemeData;
use Drift\Theme\ThemeScope;
use Drift\Widget\AppBar;
use Drift\Widget\BuildContext;
use Drift\Widget\Dock;
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
 * ★ 三件共享态由构造点注入；订阅点全在控件内部（`StatusBar` / `PageHost` /
 *   页面里的 `SubjectBuilder`）—— 值**不在这里读**（"Root 只组合"由 lint 的
 *   `dependency-direction` + 评审共同守）。
 * ★ **菜单打开接线由外部注入**（`$menuOpener`）：Widget 不许依赖 App（§4.3）⇒
 *   `UsersApplication::buildRoot()` 把自己的 `showMenu()` 包成闭包传进来。
 */
final class UsersRoot extends StatelessWidget
{
    /** @var null|callable(Menu, ?\Drift\Widget\Element): void */
    private $menuOpener = null;

    /**
     * 窗口拖动闭包（P1-Stage 1b 无边框窗）；`null` = 有原生标题栏，顶条不是标题栏。
     *
     * ★ 非 null 就**同时**意味着两件事：顶条可拖动 + 画三个窗口按钮。它们不是
     *   两个开关而是同一件事的两面 —— "无边框"这个事实的两个后果。分成两个
     *   注入点会让"可拖但没按钮"（拖得动、关不掉）这种状态写得出来。
     */
    private ?\Closure $windowDrag = null;

    public function __construct(
        private readonly AppStore $app,
        private readonly UserStore $users,
        private readonly SignupForm $form,
    ) {
        parent::__construct();
    }

    public static function make(AppStore $app, UserStore $users, SignupForm $form): self
    {
        return new self($app, $users, $form);
    }

    /**
     * 菜单打开回调（应用侧接线）。
     *
     * @param callable(Menu, ?\Drift\Widget\Element): void $opener
     */
    public function menuOpener(callable $opener): static
    {
        $this->mutate();
        $this->menuOpener = $opener(...);

        return $this;
    }

    /** @internal 读法（测试断言"接线到位"）；`null` ⇒ 菜单点了没反应 */
    public function menuOpenerHandler(): ?\Closure
    {
        return $this->menuOpener;
    }

    /**
     * 声明"顶条就是标题栏"（P1-Stage 1b 无边框窗）：可拖窗 + 画窗口按钮。
     *
     * @param \Closure(): void $drag 组合根递的 `fn () => $window->beginDrag($handle)`
     */
    public function windowDrag(\Closure $drag): static
    {
        $this->mutate();
        $this->windowDrag = $drag;

        return $this;
    }

    public function build(BuildContext $ctx): Widget
    {
        $theme = $this->app->systemTheme() === SystemTheme::Dark
            ? ThemeData::dark()
            : ThemeData::light();

        $bar = AppBar::make()->title('用户管理')->children([
            NavButton::make('列表')->command('nav.users'),
            NavButton::make('注册')->command('nav.signup'),
            NavButton::make('设置')->command('nav.settings'),
            MenuBar::make()->menus([
                Menu::make('文件')->items([
                    MenuItem::make('刷新')->chord(KeyChord::ctrl('R'))->command('users.refresh'),
                    MenuItem::make('新建')->chord(KeyChord::ctrl('N'))->command('nav.signup'),
                    MenuItem::separator(),
                    MenuItem::make('退出')->command('app.quit'),
                ])->onOpen($this->menuOpener ?? static function (Menu $m, ?\Drift\Widget\Element $a): void {
                }),
            ]),
        ]);

        // ★ 无边框窗：顶条当标题栏（可拖 + 三个窗口按钮）。两件事同源，见字段注释。
        if ($this->windowDrag !== null) {
            $bar->windowDrag($this->windowDrag)->windowButtons(true);
        }

        return ThemeScope::make($theme, Dock::make()
            ->top($bar)
            // ★ 传 store + 切片函数，由 StatusBar 内部 select 订阅
            ->bottom(StatusBar::make()
                ->source($this->app)
                ->select(static fn(AppStore $s): string => $s->statusLine()))
            // ★ 传 store + 切片；PageHost 内部 select 订阅当前页，换页 = 旧页 Offstage 化
            ->center(PageHost::make()
                ->source($this->app)
                ->select(static fn(AppStore $s): string => $s->currentPage())
                ->table($this->routeTable()))
            ->overflow(Overflow::Clip));
    }

    private function routeTable(): RouteTable
    {
        return RouteTable::make()
            ->route('users', fn(): Widget => UsersPage::make($this->users))
            ->route('signup', fn(): Widget => SignupPage::make($this->form))
            ->route('settings', fn(): Widget => SettingsPage::make($this->app));
    }
}
