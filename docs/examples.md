> 本文件从 `DESIGN.md` 拆分而来（2026-10-21 文档瘦身），主文档见 `DESIGN.md`。

# §A 完整示例

> 本章是**可照抄的代码**。设计条文在 §0–§24，这里只回答"**具体怎么写**"。
> ★ 示例遵守全部纪律（§2.4）与全部命名规则（§15.4）；若发现示例违反某条裁决，**那是本文的 bug**。

## A.1 最小可运行（M1 的目标：一个窗口画出「Hello Drift」）

```
apps/hello/
  project.yml
  main.php
  bin/win32/Bootstrap.php
  src/HelloApplication.php
```

```php
<?php
// apps/hello/main.php
declare(strict_types=1);

namespace {
    /** ★ `-m bin` 硬要求：全局唯一 main()，且必须在全局命名空间（§4.5） */
    function main(): void
    {
        \App\Hello\Bootstrap::run();      // ★ 前导 `\`：命名空间内调全局符号（§16.5 陷阱 3）
    }
}
```

```php
<?php
// apps/hello/bin/win32/Bootstrap.php
// ★ 本段**逐字等于**仓库里的 apps/hello/bin/win32/Bootstrap.php
//   （2026-10-15 真机构建 + 运行截图核对 —— §24.1 #40 ③ 的回填）
declare(strict_types=1);

namespace App\Hello;

use Drift\Errors;
use Drift\Platform\Win32\Win32Platform;

/** 组合根：全应用唯一提到平台实现的地方（§5.1） */
final class Bootstrap
{
    /** 窗口客户区（**逻辑**像素）；物理像素由 `Screen::scale()` 决定（§6） */
    private const WINDOW_WIDTH = 640;

    private const WINDOW_HEIGHT = 400;

    public static function run(): void
    {
        $platform = new Win32Platform();

        // ★ 中止序列第 ② 步必须在**别的一切之前**装好（§1.2）：引导阶段就崩的话，
        //   没有错误框就只剩"闪退"。★ 传的是 callable 而不是 Platform 实例 ——
        //   `Errors` 不依赖 Platform 类型，"平台名只出现在组合根"才不被破坏（§5.1）。
        Errors::installFatalBox(
            static function (string $title, string $body) use ($platform): void {
                $platform->fatalBox($title, $body);
            }
        );

        $window = $platform->window();
        $handle = $window->createWindow('Hello Drift', self::WINDOW_WIDTH, self::WINDOW_HEIGHT);
        $window->show($handle);          // ★ 少了它窗口不显示 —— 画面永远看不到，且不报错

        $provider = $platform->surface($handle);
        if ($provider === null) {
            // `Errors::report()` 是 Fatal ⇒ 走 §1.2 中止序列，不会返回。
            // 这一行 `return` 只为让下面的 `$provider->surface()` 类型收窄成立。
            Errors::report('Win32: 平台没给出渲染表面（窗口句柄无效）');

            return;
        }

        $screen = $platform->screen();
        $surface = $provider->surface();

        // ★ 首帧之前把表面尺寸定下来：`attach()` 内部就会跑一帧（`Application::attach`），
        //   而那帧要往表面上画 ⇒ 尺寸为 0 时那一帧会被丢弃
        //   （症状：画面到第二次 resize 才出现，**不报错**）。
        $surface->resize(
            (int) round((float) self::WINDOW_WIDTH * $screen->scale()),
            (int) round((float) self::WINDOW_HEIGHT * $screen->scale()),
            $screen->scale(),
        );

        $app = new HelloApplication();
        $app->attach($surface, $screen->viewport());

        // ★ 循环归平台（§5.2 裁决 1）：`Application` **没有** run()。
        //   这一行 `while` 就是"框架绝不自建循环"的全部代价。
        while ($platform->pump()) {
            $app->frame($platform->now(), $screen->viewport());
        }
    }
}
```

```php
<?php
// apps/hello/src/HelloApplication.php
declare(strict_types=1);

namespace App\Hello;

use Drift\App\Application;
use Drift\Widget\Center;
use Drift\Widget\Text;
use Drift\Widget\Widget;

final class HelloApplication extends Application
{
    public function title(): string { return 'Hello Drift'; }

    public function buildRoot(): Widget
    {
        return Center::make()->child(
            Text::make('Hello Drift')->title()
        );
    }
}
```

```yaml
# apps/hello/project.yml
# ★ 与仓库里的 apps/hello/project.yml 同构（注释从简，完整理由在那个文件里）
name: hello
mode: bin
platform: win32
no-console: true            # tpc 会加 /SUBSYSTEM:WINDOWS **并自动补 /ENTRY:mainCRTStartup**

cxx-std: c++20              # Skia 头需要（SkMathPriv 用 <bit>）；base.yml 已链 Skia
cxx-flags: [/utf-8, /wd4267, /FS]
ld-flags: [/STACK:8388608]  # AOT 后 PHP 函数栈帧远大于 Zend 虚拟栈

# 只列**真的用到**的（桥里每个 API 都能对上一本库）
link-libs: [user32.lib, gdi32.lib, advapi32.lib]

sources:
  - .                       # 本应用：main.php + src/ + bin/win32/
  - ../../src               # Drift 框架（bin 模式 = 整程序 AOT）
  - ../../cpp-src           # Win32 桥：drift_win32.stub.php + drift_win32.cc

ignore: [build]             # 产物不能进 sources（否则生成的 .cc 被再翻译一遍）
build-dir: build
```

```bash
# 构建（★ 从仓库根调用 —— cxx-flags/ld-flags **不过** tpc 的路径解析，相对 cwd）
php tools/drift-build.php hello      # ★ 首选：四个工具链坑全封在里面（§16.2）
./hello.exe

# 等价的手工形态（四个坑要自己填，任一漏了就报"看不出根因"的错）：
#   PHPRC=<私有 ini 目录>  PHPX_HOME=<发行版>/phpx  \
#   INCLUDE=… LIB=… PATH=… \
#   php <typephp 源码树>/bin/tpc.php --project apps/hello/project.yml -O2 -m bin
```

> ★ **本节代码 2026-10-15 全部真机跑通**（`php tools/drift-build.php hello` → `hello.exe` → 窗口画出「Hello Drift」，截图核对）。
> 在此之前本节含 **5 处照抄不过**的写法（§24.1 #23 的三处 + #40 ③ 的两处）—— "§A 章是可照抄的代码"这条承诺，今天才第一次为真。

## A.2 完整应用：`users`（列表 + 搜索 + 删除 + 表单 + 异步 + 多页 + 菜单 + 快捷键）

### A.2.1 目录树

```
apps/users/
  project.yml
  main.php
  bin/
    win32/Bootstrap.php          # 组合根（Windows）
    macos/Bootstrap.php          # 组合根（macOS）—— 由 if: 二选一
  preview-fixture.php            # drift-preview 的离线夹具（返回 MemoryUserSource）
  src/
    UsersApplication.php         # extends Application：title / buildRoot / commands / accelerators
    UsersRoot.php                # 只做组合（§12.2）
    Pages/                       # ★ 目录大小写 = 命名空间（PSR-4；lint `namespace-mismatch` 守）
      UsersPage.php              # 列表 + 搜索 + 删除
      SignupPage.php             # 表单（property hooks + SubjectBuilder）
      SettingsPage.php           # 主题 / 光标 / 拖放 / 焦点序演示（§A.3）
    Store/                       # ★ 业务层：零 GUI 依赖（连 Drift\Widget 都不 import）
      AppStore.php               # 当前页 + 状态栏 + 主题
      UserStore.php              # 行数据 + 三态 + 缓存 getter
      User.php                   # 值对象
      LoadStatus.php             # backed enum
      UserSource.php             # 数据源接口（+ 结果值对象 Page）
      MemoryUserSource.php       # 实现（测试 / 离线 / 本样板默认）
      SignupForm.php             # 表单（property hooks）
    Commands/
      NavigationCommands.php
      UserCommands.php
      SignupCommands.php
      AppCommands.php
    Widgets/
      StatusBar.php              # 应用私有专职订阅控件
      UserRow.php                # 列表行（纯展示，不吃 store）
```

> ★ **2026-10-16 落地实况**：上面这份与 `apps/users/` 逐文件一致（20 个 PHP）。
>   两处与旧版目录树不同，都是被**门**逼出来的：
>   ① **目录名 PascalCase** —— PSR-4 要求目录名与命名空间一致（`Store/` ↔ `App\Users\Store`），
>      旧图的小写 `store/` 一落地就被 lint `namespace-mismatch` 抓（37 条 error）；
>   ② `HttpUserSource.php` **没有** —— 它依赖 `TaskHost` / `Job`（§17.2，M4，`src/` 里 0 文件）
>      ⇒ 按 A15「不预写没有消费者的代码」不落；M4 到位后照 `Bootstrap` 那一行切换即可。

### A.2.2 `composer.json`（解释器面：preview / lint / 单测）

```jsonc
{
  "autoload": {
    "psr-4": {
      "Drift\\":      "src/",
      "Drift\\Dev\\": "tools/",
      "App\\Users\\": ["apps/users/src/", "apps/users/bin/"]
    }
  }
}
```

### A.2.3 `project.yml`（同仓开发形态）

```yaml
name: users
mode: bin
platform: win32
version: 0.1.0
php-version: "8.5"
optimize: 2
job: 16
cxx-std: c++20
build-dir: ../../build/users
no-console: true

sources:
  - main.php
  - src
  - ../../src
  - ../../cpp-src
  - path: bin/win32
    if: PHP_OS_FAMILY == "Windows"
  - path: bin/macos
    if: PHP_OS_FAMILY == "Darwin"

ignore:
  - ../../src/platform/macos
  - ../../src/platform/win32

defines:
  - DRIFT_USERS_ALL_DECL=<php_users_all_decl.h>

include-paths: [../../cpp/skia/include]
link-paths:    [../../cpp/skia/out/Release-x64]
link-libs:
  - user32.lib
  - gdi32.lib
  - ole32.lib
  - imm32.lib
  - comdlg32.lib
  - shell32.lib
  - advapi32.lib
cxx-flags: [/utf-8, /MT, /DUSE_SKIA]
```

> ★ **2026-10-16 校正**：上面这份是**目标形态**（Skia + SDK 分发），当前**真实可构建**的配置
> 是 `apps/users/project.yml` 的逐字形态（两个 exe 都构建通过）：
>
> ```yaml
> name: users
> version: 0.1.0
> info:
>   Author: Drift
>   Description: Drift 框架 M3 出口判据 —— §A.2 的完整应用
> include: ../../build/base.yml     # 编译参数共享（§4.6）
> sources:
>   - .
>   - ../../src
>   - ../../cpp-src
> ignore: [build, tests]            # 要写全（list 替换语义）
> ```
>
> 三处差别要记住（都写进了 §16.4 陷阱 7 / 陷阱 8 与 `build/base.yml` 注释）：
> ① **`build/base.yml` 已链 Skia**（2026-10-16 起，`SkiaCanvas` 落地）⇒ `cxx-std: c++20` +
>    Skia 的 include/link 项（**12 个 lib** 按依赖序，**不含** skottie / skshaper / skparagraph /
>    skunicode / icu —— M1 的 `SkiaCanvas` 不做文本塑形 ⇒ **不需要 `icudtl.dat`**）。★ **体积代价
>    已实测**：同一份源码剥掉 Skia 桥那 4 个文件后，`hello.exe` **2.48 MiB ⇒ 6.24 MiB（+3.76
>    MiB / +151%）** ⇒ 是否改成"按应用 opt-in"记在 §24.2 #12；
> ② **`/MT` 能加**（实测构建通过）：tpc 的 `/MD` 写在 `cxxflags` **之前**（`Msvc.php:104`）
>    ⇒ 用户 `cxx-flags` 里的 `/MT` 覆盖它；且 `php8ts` 是 **DLL** ⇒ 跨 DLL 边界不共享 CRT
>    ⇒ "静态库里 `/MT` 致命"那条不适用。★ 但 `/MT` **换不到"免 CRT / 单文件"**（exe 仍导入
>    `VCRUNTIME140.dll`）⇒ 加它只为匹配 Skia 包，**不是**为省体积；
> ③ `defines: DRIFT_*_ALL_DECL` 只在 **lib 模式**用（§16.4 陷阱 5），bin 模式不需要。

**SDK 分发形态（§10.2）**：应用侧把框架换成一行 stub：

```yaml
# apps/users/project.yml —— 形态 B（-m lib）消费侧
name: users
mode: bin
platform: win32
build-dir: ../../build/users
no-console: true
sources:
  - main.php
  - src
  - ../../sdk/drift.stub.php     # ★ 一行取代 ../../src + ../../cpp-src + Skia 路径 + link-libs
  - path: bin/win32
    if: PHP_OS_FAMILY == "Windows"
# ★ 不需要 include-paths / link-paths / link-libs（全烘进 drift.dll）
# ★ 但构建必须用源码树 tpc：php bin/tpc.php（§10.2.2）
```

### A.2.4 入口与组合根

```php
<?php
// apps/users/main.php
declare(strict_types=1);

namespace {
    function main(): void { \App\Users\Bootstrap::run(); }
}
```

```php
<?php
// apps/users/bin/win32/Bootstrap.php —— 组合根，全应用唯一提到平台实现的地方（§5.1）
declare(strict_types=1);

namespace App\Users;

use App\Users\Store\HttpUserSource;

final class Bootstrap
{
    public static function run(): void
    {
        $platform = new \Drift\Platform\Win32\Win32Platform();

        // ★ 后端挂在**源**上，不挂 Application（§11.7）：M1–M3 同步 ⇒ 无参构造；
        //   M4 线程池 ⇒ `new HttpUserSource(new ThreadPoolTaskHost())`，只改这一行。
        $src = new HttpUserSource();
        $app = new UsersApplication($src);

        // ★ 循环归平台（§5.2 裁决 1）：`Application` **没有** run()，只提供
        //   `attach($surface, $constraints)` 与 `frame($timestampMs, $constraints)`。
        //   表面由平台给，帧时钟由平台驱动 ⇒ 测试能喂任意帧序列（G3）。
        //   ★ `surface()` 返回 `?SurfaceProvider` ⇒ 必须收窄成 `RenderSurface`。
        $handle = $platform->window()->createWindow('Users', 900, 640);
        $platform->window()->show($handle);
        $provider = $platform->surface($handle);
        if ($provider === null) {
            \Drift\Errors::report('平台没给出渲染表面');   // Fatal ⇒ 不返回
            return;
        }
        $screen  = $platform->screen();
        $surface = $provider->surface();
        $surface->resize(
            (int) round(900.0 * $screen->scale()),
            (int) round(640.0 * $screen->scale()),
            $screen->scale(),
        );
        $app->attach($surface, $screen->viewport());
        while ($platform->pump()) {
            $app->frame($platform->now(), $screen->viewport());
        }
        // ★ 窗口状态读回 / 写回（`WindowStateStore`，§5.4）：启动一次、退出一次同步 IO。
    }
}
```

### A.2.5 `UsersApplication`

```php
<?php
// apps/users/src/UsersApplication.php
declare(strict_types=1);

namespace App\Users;

use Drift\App\Application;
use Drift\Commands\CommandHandler;
use Drift\Widget\Widget;

use App\Users\Store\{AppStore, UserStore, SignupForm, UserSource};
use App\Users\Commands\{NavigationCommands, UserCommands, SignupCommands, AppCommands};

/**
 * ★ 2026-10-15 按**已实现的** `Drift\App\Application` 重写（§23.2 O-18 方案 A）。
 *   基类只有：`__construct(iterable $handlers = [])` · `buildRoot()` · `title()` ·
 *   `commands(): iterable` · `theme()` · `metrics()` · `attach()` · `frame()` ·
 *   `dispatch()` · `router()`。⇒ 三条硬约束：
 *   ① 三个覆写**全是 public** —— 子类收窄可见性 ⇒ `PHP Fatal: Access level must be
 *      public`（§24.1 #23，`commands()` 要能被 devtools 枚举）；
 *   ② **没有 `boot()`** —— 依赖装配就在**构造点**（O-15 方案 E 的原话）；
 *   ③ `commands()` 返回 `iterable<string, CommandHandler>` 字典，不是 `CommandRouter`
 *      —— 链的第 ③ 环由 `Application::attach()` 用 `router()->on()` 自动拼好。
 */
final class UsersApplication extends Application
{
    private AppStore   $app;
    private UserStore  $users;
    private SignupForm $form;
    private UserSource $src;          // ★ SignupCommands 也依赖它（§23.2 O-15 复核发现 ⑧）

    /**
     * 依赖装配：**就在这里**（没有 boot()）。
     *
     * ★ 后端由组合根注入（§11.7 / §A.3.9）：`Bootstrap` 决定给同步源还是线程池源
     *   ⇒ 换后端 = 组合根一行，页面与 store 代码零改动。
     * ★ handler **不在这时**注册：框架在 `attach()` 里才调 `commands()`，那一刻
     *   `$this->users` 等字段才就位 ⇒ 声明式注册只写一处（见 `commands()`）。
     */
    public function __construct(UserSource $src)
    {
        $this->src   = $src;
        $this->app   = new AppStore();
        $this->users = new UserStore($this->src);
        $this->form  = new SignupForm();

        $this->users->load();          // 首屏：进 Loading → 结果回贴（§11.5）
    }

    public function title(): string { return '用户管理'; }

    public function buildRoot(): Widget
    {
        return UsersRoot::make($this->app, $this->users, $this->form);
    }

    /**
     * 动作缝注册表（§11.4.3）：可枚举、可 lint、可单测。
     *
     * @return iterable<string, CommandHandler> `命令名 => handler 实例`
     */
    public function commands(): iterable
    {
        // ★ 方案 E（§23.2 O-15 裁决）：依赖在构造点 new 出来 ⇒ 不需要 provide() / 反射。
        //   多命令共用同一 handler ⇒ 提局部变量（不提会 new 出多个实例）。
        $nav  = new NavigationCommands($this->app);
        $user = new UserCommands($this->users, $this->app);

        return [
            'nav.users'     => $nav,
            'nav.signup'    => $nav,
            'nav.settings'  => $nav,
            'user.del'      => $user,
            'users.refresh' => $user,
            'form.submit'   => new SignupCommands($this->form, $this->src, $this->app, $this->users),
            'app.quit'      => new AppCommands($this->app),
        ];
    }
}

/**
 * 应用级加速键（§9.1 路由链第 ① 环，在焦点之前）。
 *
 * ★ `AcceleratorTable` **2026-10-16 已落地** ⇒ 不再是"空钩子"：这一条正是
 *   §19 M3 出口判据"Ctrl+S 等加速键可用"的落点（smoke 有断言）。
 */
public function accelerators(): AcceleratorTable
{
    return AcceleratorTable::make()
        ->bind(KeyChord::ctrl('R'), 'users.refresh')
        ->bind(KeyChord::ctrl('S'), 'form.submit')
        ->bind(KeyChord::f(5),      'users.refresh');
}

// ⏳ 仍不预挂的只剩 `onSystemThemeChanged()` —— `ThemeChangedEvent` 在 §4.2 有名字、
//   `src/` 里 0 文件（§19 M4）⇒ 按 A15「不提供收益≈0 的公开 API」等能力落地当天再加。
//   主题**切换本身**今天已可用（`SettingsPage` 的两个按钮 → `app.theme` 命令 →
//   `AppStore::setSystemTheme()` → `UsersRoot` 的 `ThemeScope` 消费）。
```

### A.2.6 `UsersRoot`（只做组合）

> ★ **2026-10-16 落地时的两处增量**（样板是骨架，实现是它的完成态）：① 外层包一层
> `ThemeScope::make($theme, $dock)` —— 否则"设置页切主题"没有消费点（§A.3.3）；
> ② `MenuBar` 放进了 `AppBar` 的 children，并把菜单的**打开回调**由应用注入
> （`menuOpener(...)`）—— Widget 不许依赖 App（§4.3），所以 `showMenu()` 只能由
> `UsersApplication::buildRoot()` 包成闭包传进来。

```php
<?php
// apps/users/src/UsersRoot.php
declare(strict_types=1);

namespace App\Users;

use Drift\Widget\{StatelessWidget, BuildContext, Widget};
use Drift\Routing\{PageHost, RouteTable};
use Drift\Widget\{Dock, AppBar, NavButton};
use Drift\Widget\Menu\{MenuBar, Menu, MenuItem};
use Drift\Layout\Overflow;      // ★ Overflow 是 Layout 层的封闭域 enum，不在 Widget（§24.1 #34：原稿从 Widget 导入，照抄即 Class not found）
use Drift\Input\KeyChord;

use App\Users\Store\{AppStore, UserStore, SignupForm};
use App\Users\Pages\{UsersPage, SignupPage, SettingsPage};
use App\Users\Widgets\StatusBar;

final class UsersRoot extends StatelessWidget
{
    public function __construct(
        private readonly AppStore   $app,
        private readonly UserStore  $users,
        private readonly SignupForm $form,
    ) { parent::__construct(); }

    public function build(BuildContext $ctx): Widget
    {
        return Dock::make()
            ->top(AppBar::make()->children([
                MenuBar::make()->menus([
                    Menu::make('文件')->items([
                        MenuItem::make('刷新')->chord(KeyChord::ctrl('R'))->command('users.refresh'),
                        MenuItem::make('新建用户')->chord(KeyChord::ctrl('N'))->command('nav.signup'),
                        MenuItem::separator(),
                        MenuItem::make('退出')->command('app.quit'),
                    ]),
                    Menu::make('视图')->items([
                        MenuItem::make('设置')->command('nav.settings'),
                    ]),
                ]),
                NavButton::make('列表')->command('nav.users'),
                NavButton::make('注册')->command('nav.signup'),
            ]))
            // ★ 传 store + 切片函数；值不在这里读（§12.2）
            ->bottom(StatusBar::make()
                ->source($this->app)
                ->select(fn(AppStore $s): string => $s->statusLine()))
            ->center(PageHost::make()
                ->source($this->app)
                ->select(fn(AppStore $s): string => $s->currentPage())
                ->table($this->routeTable()))
            ->overflow(Overflow::Clip);
    }

    private function routeTable(): RouteTable
    {
        return RouteTable::make()
            ->route('users',    fn(): Widget => UsersPage::make($this->users))
            ->route('signup',   fn(): Widget => SignupPage::make($this->form))
            ->route('settings', fn(): Widget => SettingsPage::make($this->app));
    }
}
```

### A.2.7 `AppStore`（当前页 / 状态栏 / 主题）

```php
<?php
// apps/users/src/store/AppStore.php
declare(strict_types=1);

namespace App\Users\Store;

use Drift\State\Subject;
use Drift\Theme\{ThemeData, SystemTheme};

/** ★ 业务层：不认识任何 GUI 类型（连 Drift\Widget 都不 import） */
final class AppStore extends Subject
{
    private string $currentPage = 'users';
    private string $statusLine  = '就绪';
    private SystemTheme $systemTheme = SystemTheme::Light;

    public function currentPage(): string { return $this->currentPage; }
    public function statusLine(): string  { return $this->statusLine; }
    public function systemTheme(): SystemTheme { return $this->systemTheme; }

    public function navigate(string $page): void
    {
        if ($page === $this->currentPage) { return; }     // ★ 相等门控：同页不广播
        $this->currentPage = $page;
        $this->notifyChanged();
    }

    public function say(string $line): void
    {
        $this->statusLine = $line;
        $this->notifyChanged();
    }

    public function setSystemTheme(SystemTheme $t): void
    {
        $this->systemTheme = $t;
        $this->notifyChanged();
    }
}
```

### A.2.8 业务数据：值对象 / 枚举 / 数据源 / Store / 表单

```php
<?php
// apps/users/src/store/User.php
declare(strict_types=1);
namespace App\Users\Store;

final readonly class User
{
    public function __construct(
        public int    $id,
        public string $name,
        public string $email,
    ) {}
}
```

```php
<?php
// apps/users/src/store/LoadStatus.php
declare(strict_types=1);
namespace App\Users\Store;

/** ★ 封闭域 ⇒ 消费它的 match 禁止 default（§12.1 第 2 条） */
enum LoadStatus: int
{
    case Idle    = 0;
    case Loading = 1;
    case Ready   = 2;
    case Error   = 3;
}
```

```php
<?php
// apps/users/src/store/UserSource.php
declare(strict_types=1);
namespace App\Users\Store;

final readonly class Page
{
    /** @param list<User> $items */
    public function __construct(public bool $ok, public array $items = [], public string $error = '') {}
}

interface UserSource
{
    public function fetchPage(int $page): Page;
    public function delete(int $id): bool;
    public function submit(string $endpoint, array $payload): bool;
}
```

```php
<?php
// apps/users/src/store/HttpUserSource.php
declare(strict_types=1);
namespace App\Users\Store;

use Drift\App\{Job, TaskHost};

/**
 * ★ 走 TaskHost：M1–M3 是同步直跑；M4 换成线程池，本文件一字不改（§17.2）
 * ★ Job 是类不是闭包（§17.2）：可枚举、可单测、绕开"闭包跨线程未验"
 */
final class FetchPageJob implements Job
{
    public function __construct(private int $page) {}
    public function run(): Page { /* HTTP 取一页 ⇒ Page */ }
}

final class DeleteUserJob implements Job
{
    public function __construct(private int $id) {}
    public function run(): bool { /* HTTP DELETE ⇒ bool */ }
}

final class HttpUserSource implements UserSource
{
    public function __construct(private TaskHost $tasks) {}

    public function fetchPage(int $page): Page
    {
        $result = null;
        $this->tasks->submit(new FetchPageJob($page), function (Page $p) use (&$result): void {
            $result = $p;                       // ★ 回贴在 UI 线程执行（§17.2.3）
        });
        return $result ?? new Page(ok: false, error: 'pending');
    }
    // delete() / submit() 同构，略
}
```

```php
<?php
// apps/users/src/store/UserStore.php
declare(strict_types=1);
namespace App\Users\Store;

use Drift\State\Subject;

final class UserStore extends Subject
{
    public function __construct(private UserSource $src) {}

    /** @var list<User> */ private array $rows = [];
    private LoadStatus $status = LoadStatus::Idle;
    private string $query = '';

    // ── 派生值：缓存 getter（§11.3.4）────────────────────────────
    private ?array $visibleCache = null;             // ★ 缓存槽

    public function status(): LoadStatus { return $this->status; }
    public function rows(): array        { return $this->rows; }
    public function query(): string      { return $this->query; }

    /** 过滤后的行（每帧会被 select 调一次） */
    public function visible(): array
    {
        return $this->visibleCache ??= ($this->query === ''
            ? $this->rows
            : array_values(array_filter($this->rows,
                fn(User $u): bool => str_contains($u->name, $this->query))));
    }

    // ── 变更 ────────────────────────────────────────────────
    public function setQuery(string $q): void
    {
        if ($q === $this->query) { return; }         // 相等门控
        $this->query = $q;
        $this->visibleCache = null;                  // ★ 依赖变了 ⇒ 清缓存（lint `stale-derived` 守这一行）
        $this->notifyChanged();
    }

    public function load(int $page = 1): void
    {
        $this->status = LoadStatus::Loading;
        $this->notifyChanged();
        $r = $this->src->fetchPage($page);           // 同步三态后端：loading 态画不出来（§11.5 注）
        if ($r->ok) { $this->rows = $r->items; $this->status = LoadStatus::Ready; }
        else        { $this->status = LoadStatus::Error; }
        $this->visibleCache = null;                  // ★ rows 变了 ⇒ 清缓存
        $this->notifyChanged();
    }

    public function remove(int $id): bool
    {
        if (! $this->src->delete($id)) { return false; }
        $this->rows = array_values(array_filter($this->rows, fn(User $u): bool => $u->id !== $id));
        $this->visibleCache = null;                  // ★
        $this->notifyChanged();
        return true;
    }
}
```

```php
<?php
// apps/users/src/store/SignupForm.php
declare(strict_types=1);
namespace App\Users\Store;

use Drift\State\Subject;

/** ★ property hooks：字段名只写一次，且是「属性」⇒ 拼错 = 编译错误（§11.4.1） */
final class SignupForm extends Subject
{
    public string $name  = '' { set (string $v) { $this->name  = $v; $this->submitError = ''; $this->notifyChanged(); } }
    public string $email = '' { set (string $v) { $this->email = $v; $this->submitError = ''; $this->notifyChanged(); } }

    /** 提交结果属于业务状态 ⇒ 放 Store（§11.4.3） */
    public string $submitError = '';

    /** @return list<string> */
    public function validate(): array
    {
        $e = [];
        if ($this->name === '')  { $e[] = '姓名不能为空'; }
        if (! str_contains($this->email, '@')) { $e[] = '邮箱格式不正确'; }
        return $e;
    }

    /** ★ 唯一序列化出口 */
    public function toApiPayload(): array
    {
        return ['name' => $this->name, 'email' => $this->email];
    }

    public function fail(string $msg): void { $this->submitError = $msg; $this->notifyChanged(); }
    public function reset(): void           { $this->name = ''; $this->email = ''; $this->submitError = ''; $this->notifyChanged(); }
}
```

### A.2.9 页面

```php
<?php
// apps/users/src/pages/UsersPage.php
declare(strict_types=1);
namespace App\Users\Pages;

use Drift\Widget\{StatelessWidget, BuildContext, Widget};
use Drift\Widget\{Column, Row, Text, Button, TextField, Each, Spinner, Padding, Spacer};
use Drift\State\SubjectBuilder;
use Drift\Commands\CommandArg;

use App\Users\Store\{UserStore, User, LoadStatus};
use App\Users\Widgets\UserRow;

final class UsersPage extends StatelessWidget
{
    public function __construct(private readonly UserStore $store) { parent::__construct(); }

    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->spacing(16)->padding(24)->children([
            Text::make('用户目录')->title(),

            Row::make()->spacing(8)->children([
                // ── 搜索框：官方订阅形态（§11.4.2）—— 不提供 bind() ──
                SubjectBuilder::make($this->store)
                    ->select(fn(UserStore $s): string => $s->query())
                    ->builder(fn(string $q): Widget =>
                        TextField::make()->value($q)->label('搜索')->placeholder('输入姓名')
                            ->onChanged(fn(string $x) => $this->store->setQuery($x))),
                Button::primary('刷新')->command('users.refresh'),
                Spacer::make(),
                Button::primary('新建用户')->command('nav.signup'),
            ]),

            // ── 列表：订阅点在这层，行内只吃值（§11.3.2 纪律 2）──────
            SubjectBuilder::make($this->store)
                ->select(fn(UserStore $s): LoadStatus => $s->status())
                ->builder(fn(LoadStatus $st): Widget => match ($st) {      // ★ 无 default（封闭域）
                    LoadStatus::Loading => Spinner::make(),
                    LoadStatus::Error   => Row::make()->spacing(8)->children([
                        Text::make('加载失败')->error(),
                        Button::primary('重试')->command('users.refresh'),
                    ]),
                    LoadStatus::Ready   => SubjectBuilder::make($this->store)
                        ->select(fn(UserStore $s): array => $s->visible())   // ★ 切片：只跟过滤结果
                        ->builder(fn(array $rows): Widget =>
                            Each::make(fn(User $u): int => $u->id)        // ★ key 必填
                                ->items($rows, fn(User $u): Widget => UserRow::make($u))),
                    LoadStatus::Idle    => Text::make('尚未加载')->caption(),
                }),
        ]);
    }
}
```

> ★ **已按 §23.2 O-16 裁决修正**（2026-10-14）：搜索框原先**直接读** `$this->store->query()` 而**没有订阅** —— 在 `StatelessWidget` 里这就是 `dead-subscription`（值变了不刷新）。现改为**官方订阅形态**：`SubjectBuilder::select(...)` 包一层 + 手写 `value()/onChanged()`。
> ★ 顺带：这也是 §11.4.2 那张"摩擦点对照"表**为什么要删**的活证据 —— 表里说"不用记得包 `SubjectBuilder`"，而**真实的正确写法就是必须包**。

> ★ **本节样本的实况（2026-10-16 收尾核验）**：**本页样板引用的类型全部已落地，可以整页编译并跑** —— `Draggable`（2026-10-16 落）、`Spinner`（同日）、`TextField`（10-15）、`CursorShape`（随 M1）及其余控件。★ 落地时被 lint 抓到的两处**样板自身**问题（已按实现修正）：① `SubjectBuilder::make($store)` 是"配置一个专职订阅者"，**页面不必** `implements SubscribingWidget`（规则判据已随之修正）；② 过滤闭包**不能**是 `static fn` —— 它读 `$this->query`，而它在 `notifyChanged()` 的订阅者回调里 ⇒ 报错会被"订阅者异常隔离"包成 RECOVERABLE ⇒ debug 下整局中止。

```php
<?php
// apps/users/src/widgets/UserRow.php —— 纯展示，不吃 store（§11.3.2 纪律 2）
declare(strict_types=1);
namespace App\Users\Widgets;

use Drift\Widget\{StatelessWidget, BuildContext, Widget};
use Drift\Widget\{Row, Text, Button, Spacer};
use Drift\Commands\CommandArg;
use Drift\Input\CursorShape;
use Drift\Widget\Drag\Draggable;

use App\Users\Store\User;

final class UserRow extends StatelessWidget
{
    public function __construct(private readonly User $user) { parent::__construct(); }

    public function build(BuildContext $ctx): Widget
    {
        $u = $this->user;
        return Row::make()->spacing(12)->cursor(CursorShape::PointingHand)->children([
            Text::make($u->name)->body(),
            Text::make($u->email)->caption(),
            Spacer::make(),
            Button::danger('删除')->command('user.del')->arg(CommandArg::id($u->id)),
        ])
        // 应用内拖放：把这一行拖到「回收站」上（§12.4，M3，零平台端口）
        ->draggable(Draggable::make($u->id));
    }
}
```

```php
<?php
// apps/users/src/pages/SignupPage.php
declare(strict_types=1);
namespace App\Users\Pages;

use Drift\Widget\{StatelessWidget, BuildContext, Widget};
use Drift\Widget\{Column, Text, TextField, Button, Row, Spacer};
use Drift\State\SubjectBuilder;                 // ★ 不再需要 Bound（§23.2 O-16 裁决）

use App\Users\Store\SignupForm;

final class SignupPage extends StatelessWidget
{
    public function __construct(private readonly SignupForm $form) { parent::__construct(); }

    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->spacing(12)->padding(24)->children([
            Text::make('注册新用户')->title(),

            // ── 表单字段：官方订阅形态（§11.4.2）—— 每字段读一次、写一次 ──
            SubjectBuilder::make($this->form)
                ->select(fn(SignupForm $f): string => $f->name)
                ->builder(fn(string $v): Widget =>
                    TextField::make()->value($v)->label('姓名')
                        ->onChanged(fn(string $x) => $this->form->name = $x)),

            SubjectBuilder::make($this->form)
                ->select(fn(SignupForm $f): string => $f->email)
                ->builder(fn(string $v): Widget =>
                    TextField::make()->value($v)->label('邮箱')
                        ->onChanged(fn(string $x) => $this->form->email = $x)),

            // 提交错误也是业务状态 ⇒ 订阅回 UI（§11.4.3）
            SubjectBuilder::make($this->form)
                ->select(fn(SignupForm $f): string => $f->submitError)
                ->builder(fn(string $err): Widget =>
                    $err === '' ? Row::make() : Text::make($err)->error()),

            Row::make()->spacing(8)->children([
                Button::primary('提交')->command('form.submit'),
                Spacer::make(),
                Button::make('清空')->onPressed(fn() => $this->form->reset()),   // ★ 纯 UI 回调 ⇒ 闭包合法
            ]),
        ]);
    }
}
```

> ★ **已按 §23.2 O-16 裁决（A′）修正**（2026-10-14）：原稿用 `TextField::bind(...)` / `Bound::make(...)`，而 `Bound` 按现签名**订阅不了**（第一个参数是**值**不是 `Subject`）⇒ 两者已**删除**。现形态是官方推荐写法。
> ★ **诚实代价**：每个字段 4 行、且**必须记得包 `SubjectBuilder`**（漏了 ⇒ 值变了不刷新 ⇒ lint `dead-subscription` 守）。这是 PHP 语言层封死"1 次出现"之后的必然结果（§11.4.2 的四层论据）。

### A.2.10 命令处理器

```php
<?php
// apps/users/src/commands/UserCommands.php
declare(strict_types=1);
namespace App\Users\Commands;

use Drift\Commands\{CommandHandler, CommandArg};
use App\Users\Store\{UserStore, AppStore};

final class UserCommands implements CommandHandler
{
    public function __construct(private UserStore $users, private AppStore $app) {}

    public function handle(string $name, CommandArg $arg): bool
    {
        return match ($name) {
            'users.refresh' => $this->refresh(),
            'user.del'      => $this->delete($arg),
            default         => false,          // ★ 不是我管 ⇒ 继续冒泡（bool 的语义在此）
        };
    }

    private function refresh(): bool
    {
        $this->app->say('正在刷新…');
        $this->users->load();
        $this->app->say('已刷新');
        return true;
    }

    private function delete(CommandArg $arg): bool
    {
        $ok = $this->users->remove($arg->i);
        $this->app->say($ok ? '已删除' : '删除失败');
        return true;
    }
}
```

```php
<?php
// apps/users/src/commands/NavigationCommands.php
declare(strict_types=1);
namespace App\Users\Commands;

use Drift\Commands\{CommandHandler, CommandArg};
use App\Users\Store\AppStore;

final class NavigationCommands implements CommandHandler
{
    public function __construct(private AppStore $app) {}

    public function handle(string $name, CommandArg $arg): bool
    {
        if (! str_starts_with($name, 'nav.')) { return false; }   // 继续冒泡
        $this->app->navigate(substr($name, 4));
        return true;
    }
}
```

```php
<?php
// apps/users/src/commands/SignupCommands.php
declare(strict_types=1);
namespace App\Users\Commands;

use Drift\Commands\{CommandHandler, CommandArg};
use App\Users\Store\{SignupForm, UserSource, AppStore, UserStore};

final class SignupCommands implements CommandHandler
{
    public function __construct(
        private SignupForm $form,
        private UserSource $src,
        private AppStore   $app,
        private UserStore  $users,
    ) {}

    public function handle(string $name, CommandArg $arg): bool
    {
        if ($name !== 'form.submit') { return false; }

        $errs = $this->form->validate();
        if ($errs !== []) {
            $this->form->fail(implode('；', $errs));       // ★ 反馈走 Store，不走 State
            return true;
        }

        $ok = $this->src->submit('/api/signup', $this->form->toApiPayload());   // ★ 唯一序列化出口
        if (! $ok) { $this->form->fail('提交失败，请重试'); return true; }

        $this->form->reset();
        $this->app->say('注册成功');
        $this->app->navigate('users');
        $this->users->load();
        return true;
    }
}
```

> ★ **已裁决（2026-10-14，§23.2 O-15 方案 E）**：依赖**从构造点来** —— `on()` 收**实例**，所以依赖在组合根（§A.2.5 `commands()`）显式 `new` 出来 ⇒ **不需要 `provide()` 依赖登记、不需要反射、不需要动态 `new`**。上面两个 handler 就按 `new UserCommands($this->users, $this->app)` / `new SignupCommands($this->form, $this->src)` 装配。★ 代价：handler 是注册期单例 ⇒ **必须无状态**（跨命令状态属于 Store）。

### A.2.11 应用私有控件：`StatusBar`（专职订阅者）

```php
<?php
// apps/users/src/widgets/StatusBar.php
declare(strict_types=1);
namespace App\Users\Widgets;

use Drift\Widget\{StatefulWidget, State, BuildContext, Widget, Text, Row, Spacer};
use Drift\State\{Subject, Subscription, SubscribingWidget};

final class StatusBar extends StatefulWidget implements SubscribingWidget   // ★ 接口标记
{
    private ?Subject $source = null;
    /** @var null|callable(Subject): mixed */
    private $select = null;

    public function source(Subject $s): self  { $this->mutate(); $this->source = $s;  return $this; }
    public function select(callable $f): self { $this->mutate(); $this->select = $f;  return $this; }

    public function createState(): State { return new StatusBarState(); }

    public function getSource(): ?Subject { return $this->source; }
    public function getSelect(): ?callable { return $this->select; }
}

final class StatusBarState extends State
{
    private string $text = '';

    public function initState(): void
    {
        $w = $this->widget();
        if ($w->getSource() === null || $w->getSelect() === null) { return; }
        // ★ 订阅袋：dispose() 基类统一全退（§11.3 RT-1）
        $this->watch($w->getSource(), function (): void {
            $v = ($this->widget()->getSelect())($this->widget()->getSource());
            if ($v === $this->text) { return; }              // ★ 相等门控
            $this->setState(function () use ($v): void { $this->text = (string) $v; });
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        return Row::make()->children([ Text::make($this->text)->caption(), Spacer::make() ]);
    }
}
```

## A.3 能力示例集（§9 声明但原方案零示例的部分）

### A.3.1 加速键 + 菜单栏 + 上下文菜单

```php
// ① 应用级加速键（§9.1 路由链第 ① 环，★ 在焦点之前）
// ✅ `AcceleratorTable` 已落地（2026-10-16）—— 形状即此（第 ① 环，焦点之前）。
public function accelerators(): AcceleratorTable
{
    return AcceleratorTable::make()
        ->bind(KeyChord::ctrl('R'), 'users.refresh')
        ->bind(KeyChord::ctrl('S'), 'form.submit')       // IME 组合期不生效；输入框独占键集优先
        ->bind(KeyChord::f(5),      'users.refresh');
}

// ② 菜单栏：自绘控件，放在 Dock::top（§5.1 裁决：原生菜单栏不做）
AppBar::make()->children([
    MenuBar::make()->menus([
        Menu::make('文件')->items([
            MenuItem::make('刷新')->chord(KeyChord::ctrl('R'))->command('users.refresh'),
            MenuItem::make('新建')->chord(KeyChord::ctrl('N'))->command('nav.signup'),
            MenuItem::separator(),
            MenuItem::make('退出')->command('app.quit'),
        ]),
    ]),
])

// ★ 菜单的"打开"由**应用接线**（Widget 不许依赖 App —— §4.3）：
//   Menu::onOpen(fn(Menu $m, ?Element $anchor) => $this->showMenu($m->panel(), $anchor))
//   触发点是焦点标题的 Enter/Space 或指针按下（2026-10-17 四相位已落地 §24.2 #16）。

// ③ 上下文菜单：右键（最小集：PointerEvent button；不等 GestureArena —— §9.1）
Row::make()->contextMenu(
    ContextMenu::make()->items([
        MenuItem::make('删除该行')->command('user.del')->arg(CommandArg::id($u->id)),
        MenuItem::make('复制邮箱')->onPressed(fn() => $this->clipboard()->set($u->email)),
    ])
)

// ④ 输入框的编辑命令（2026-10-21 #50 实况，§24.2 #32）：
//   · `Ctrl+A`（全选）由 `TextField` **构造时自带** claimKeys 声明独占 —— 应用不用写
//   · `Ctrl+C/X/V`（复制/剪切/粘贴）**不要**在这里声明：归 `Application` 的编辑命令环
//     （在加速键之前，四条门控任一不满足整族回落）⇒ 应用仍能把它们绑给自己的命令。
//     在这里声明却连着没剪贴板的平台 ⇒ 死键（没人接、也不落给加速键）。
TextField::make()   // Ctrl+A 已内置；C/V/X 不用（也不该）声明
```

### A.3.2 光标形状（命中一次、三方消费）

```php
// 控件声明，EventLoop 在 WM_SETCURSOR 时问命中链，取最深声明者 ⇒ WindowHost::setCursor()
Row::make()->cursor(CursorShape::PointingHand)->children([...])   // 可点
Text::make('可选中文本')->cursor(CursorShape::IBeam)              // 文本
Column::make()->cursor(CursorShape::SizeAll)->draggable(...)      // 拖动把手
Button::primary('提交中…')->disabled()->cursor(CursorShape::Wait) // 忙碌
//   ★ ⏳ `Widget::disabled()` **尚未实现**（2026-10-16 核：全仓无此方法，§4.2 也未点名）
//     —— 这是"文档承诺、代码没有"的又一处，登记待补（禁用态还要定：命中仍吞事件但不可激活）。
```

### A.3.3 主题切换 / 深色 / 高对比度

```php
// ① 主题 scope：谁生效查父链，不算特异性权重（§18 避开 CSS 层叠）
// ★ 真实 API 是**两参构造** `make(ThemeData, Widget)`（2026-10-16 核验）——
//   本示例原写 `make()->theme(...)->child(...)` 是链式，代码里没有那两个方法。
ThemeScope::make(
    $this->app->systemTheme() === SystemTheme::Dark ? ThemeData::dark() : ThemeData::light(),
    $page,
)

// ② 系统主题变更：走 screen() 通道，与 DpiChanged 同路（§9.2）
// ⏳ `ThemeChangedEvent` 尚未实现（§19 M4）⇒ 钩子不预挂，落地当天再加；形状先定。
public function onSystemThemeChanged(SystemTheme $t): void
{
    $this->app->setSystemTheme($t);        // 改 store ⇒ notify ⇒ 全树重建（不是 relayout）
}

// ③ 高对比度并入 ThemeData 作为 token 覆盖层
// ★ 原写的 overrideTokens([ColorToken::Border => …]) **在 PHP 里不合法**：
//   枚举实例是对象，**不能作数组键**（`Illegal offset type`）。
//   ⇒ 改成值对象的 withX()（与 §15.7 `BoxStyle::withX()` 同源）：
//     点覆盖用 withColor()，整棵子树一起换用 withHighContrast()。
$theme = ThemeData::light()->withColor(ColorToken::Border, ColorToken::Border->highContrastValue());
$themeAll = ThemeData::light()->withHighContrast();   // 面覆盖
```

### A.3.4 焦点遍历顺序

```php
// 默认 = 文档序（深度优先）；容器可整体跳过；需定制用 focusOrder
Column::make()->children([
    AppBar::make()->children([...])->skipFocus(),        // ★ 工具按钮组不进 Tab 序（显式）
    TextField::make()->focusOrder(1),
    TextField::make()->focusOrder(2),
    Button::primary('提交')->focusOrder(3),
])
// ★ lint：同一容器内混用「有 focusOrder」与「无 focusOrder」的子节点 ⇒ 报错
```

### A.3.5 应用内拖放（M3，零平台端口）

★ **实现现状（2026-10-17）**：两个声明（`Draggable` / `DragTarget`）与状态机（`DragController`）已落地；
**真机指针序列已接线**（2026-10-17，§24.2 #16）：`PointerEvent` 四相位（down/move/up/leave）
驱动完整闭环 —— `down` 记阈值起点（**按下 ≠ 拖动**）、`move` 过 6 逻辑像素才 `begin`、
`up` 放下交出 payload、`leave`（拖出窗口）取消不卡死。`feedback`（跟随指针画的浮层）
仍归 M4。

```php
// 源
Row::make()->draggable(Draggable::make(payload: $user->id, feedback: Text::make($user->name)))
// 目标
Container::make()
    ->dragTarget(DragTarget::make()
        ->onAccept(fn(int $id) => $this->users->remove($id))
        ->onHover(fn(bool $h) => $this->setState(fn() => $this->hot = $h)))
```

### A.3.6 虚拟列表 + 状态外置（M5）

```php
// ★ 虚拟化会 dispose 被回收项的 State ⇒ 跨回收存活的状态必须外置（§12.3）
final class UserListState extends State
{
    // ❌ 错误：private int $scrollOffset = 0;        // 滚出视口 ⇒ State 被 dispose ⇒ 位置丢失
    // ✅ 正确：放 Store
    public function build(BuildContext $ctx): Widget
    {
        return ListView::make()
            ->itemCount(count($this->store->rows()))
            ->itemExtent(48.0)
            ->scrollOffset($this->store->listScrollOffset())                  // ★ 外置
            ->onScroll(fn(float $o) => $this->store->setListScrollOffset($o))
            ->itemBuilder(fn(int $i): Widget => UserRow::make($this->store->rows()[$i]));
    }
}
```

### A.3.7 模态

> ★ **机制已裁（2026-10-16 O-20，按 A）**：模态复用菜单浮层的第二棵根渲染树（`Application` 同一槽位 + 紧约束盖满分支）；遮罩 `ModalBarrier` 自绘（半透明走 `Color::fromRgba` / `Paint::withAlpha`，光栅后端混色未验 ⇒ 实现第一天验）；**关不关归应用** —— `Modal` 只发命令、绝不自己 `closeModal()`。`dismissible=false` 的模态吞掉 ESC 与遮罩点击但**不关**（拒绝 ≠ 放行给全局快捷键）。

```php
// 桌面主形态 = 外壳 + 内容槽 + 模态（不做导航栈，§12.1 第 6 条）
// ★★ **`$this->application()` 不存在**（`State` 上没有这个访问器，§4.3 禁
//    `Widget → App`）。本文档 2026-10-16 起就写着它，2026-10-23 示例台照抄后
//    真机点一下**进程直接崩**、而 smoke 全绿。浮层归 `Application` 管，应用侧
//    只有两条合规路：**组合根接线**（下面这个 `$openModal`）或**框架托管**
//    （`Widget::tooltip()` 那种声明式）。
($this->openModal)(
    Modal::make()->title('确认删除')->child(
        Column::make()->spacing(16)->children([
            Text::make('删除后不可恢复，确定吗？')->body(),
            Row::make()->spacing(8)->children([
                Button::danger('删除')->command('user.del.confirmed')->arg(CommandArg::id($id)),
                Button::make('取消')->onPressed(fn() => ($this->closeModal)()),   // 纯 UI 回调
            ]),
        ])
    )
);
```

```php
// 组合根（唯一持有 Application 的地方）：
$app->run(Root::make(
    openModal: fn(Modal $m) => $app->showModal($m),
    closeModal: fn() => $app->closeModal(),
));
```

### A.3.8 窗口状态持久化

```php
// ✅ 2026-10-18 已落地（§5.4 / §19 M5）：`WindowStateStore` / `Paths` 在 `src/App/`。
//   启动读回、退出写回，各一次同步 IO；恢复失败**静默降级到默认值**并写一条
//   Diagnostic（§24.3 登记过的 G2 例外，别让应用起不来）。
//
// 组合根（§5.4）：
$platform = new Win32Platform();
$state    = new \Drift\App\WindowStateStore(
    \Drift\App\Paths::userConfig($platform->configDir(), 'users.window')
);
$app      = new UsersApplication(new HttpUserSource());

// ★ 循环归平台（§5.2 裁决 1）—— `Application` **没有** run()：
//   ★ `surface()` 返回 `?SurfaceProvider`，`attach()` 要 `RenderSurface` ⇒ 收窄一次。
$window  = $platform->window();
$handle  = $window->createWindow('Users', 900, 640);
$window->show($handle);
$state->restore($window, $handle);              // 启动一次 —— **必须在 resize 之前**

$provider = $platform->surface($handle);
if ($provider === null) { \Drift\Errors::report('平台没给出渲染表面'); return; }
$screen  = $platform->screen();
$surface = $provider->surface();
$restored = $window->clientSize($handle);       // 读回，不是常量
$surface->resize((int) round($restored->width * $screen->scale()),
                 (int) round($restored->height * $screen->scale()), $screen->scale());
$app->attach($surface, $screen->viewport());
while ($platform->pump()) {
    $app->frame($platform->now(), $platform->screen()->viewport());
}
$state->persist($window, $handle);              // 退出一次
```

### A.3.9 异步：换后端 = 组合根一行

```php
// ★ 后端挂在**源**上，不挂 `Application`（§11.7）⇒ 换后端 = 组合根一行，UI 代码零改动。
//   `TaskHost` 自身归 M4（§19）：M1–M3 同步源无参构造即可（§11.5 的诚实边界：
//   loading 态画不出来，大响应时界面停顿，但 error 态可正确呈现）。

// M1–M3：同步
$app = new UsersApplication(new HttpUserSource());

// M4：线程池 —— 只改这一行
$app = new UsersApplication(new HttpUserSource(new \Drift\App\ThreadPoolTaskHost()));
//                                                 ↑ 只改这一行
```

## A.4 反模式对照（A1–A17：错 / 对 / 谁来守）

| # | ❌ 错 | ✅ 对 | 守它的人 |
|---|-------|-------|---------|
| A1 | `public int $constraints = 0;` 声明了从不读 | 删掉它；或真读它 | lint R11 / W3 |
| A2 | `BoxConstraints(maxWidth: 0)` 表示"无界" | `BoxConstraints(maxWidth: INF)`；`TextRole::Unset` 显式 case | 契约 C2 + review |
| A3 | `if ($ctx->width < 891) { 折叠(); }` | `Wrap::make()` / 问**本容器约束宽** | review |
| A4 | `initState()` 外调 `watch()` / 忘记 `dispose()` | 只在 `initState` 订；订阅袋 `dispose()` 全退 | 运行期断言 + lint |
| A5 | 每次 hover 都 `notifyChanged()` | `Cell::setIfChanged()` / `select` 返回值门控 | 原语 + lint |
| A6 | `catch (\Throwable $e) {}` | `Errors::report(...)` | review + `Errors` 出口 |
| A7 | `match ($st) { Ready => …, default => $rows }` | 列全所有 case（封闭域禁 `default`） | `UnhandledMatchError` |
| A8 | `ThemeScope` 传 `UserStore` | `SubjectBuilder` 订阅 | lint `dead-subscription` |
| A9 | `$box->w = $w; $box->h = $h;` 两处手写 | 只有 `createRenderObject()` 一条路 | lint R12 / W2 |
| A10 | `Root` 里 540 行 `if ($page === …)` | 一页一文件 + `RouteTable`；Root 只组合 | lint（Root 判据） |
| A11 | `$w = Text::make('x'); $tree->child($w); $w->title();` | 先修饰完再挂载（挂载即封存） | 运行期断言（Fatal） |
| A12 | 另写一套 `TextBuilder` 与 `Text` 分叉 | 一个类、一条链；`mutate()` 统一守卫 | lint `alias-mutate` + 删除测试 |
| A13 | `build()` 里 `file_get_contents('http://…')` | `TaskHost::submit(new FetchJob(...))` | review |
| A14 | 虚拟列表的滚动位置放 `State` | 放 `Store`（§12.3） | 纪律 + review |
| A15 | 提供 `->seal()` 却什么也不改变 | 删掉它，`seal()` 降为 `@internal` | review |
| A16 | `Root` 里 `Text::make($store->statusLine())`（无订阅者） | 传 store + `select`，让 `StatusBar` 内部订阅 | lint `dead-subscription` |
| A17 | 页面 `build()` 直接暴露给帧末；症状由**帧循环异常策略**决定（★ 见 §23.2 O-12） | 页面级包错误边界容器 | **纪律 + 容器（都在 M3）** |

## A.5 工具链用法

```bash
# ── 内环（秒级，不编译；§16.3）───────────────────────────────
php -l apps/users/src/pages/UsersPage.php
php tools/drift-lint.php apps/users                       # 规则见 §16.6
php tools/drift-preview.php apps/users --page users --svg out/users.svg

# lint 报错长什么样（每条都指出"症状 + 修法"，不是只报行号）
#   apps/users/src/Pages/UsersPage.php:42  error  dead-subscription
#     build() 读了 UserStore 的值，但本类未实现 SubscribingWidget，也不在任何订阅者子树内。
#     ⇒ 数据变了这里不会重建。修法：把读值的那层包进 SubjectBuilder（或让本类 implements SubscribingWidget）
#
#   apps/users/src/Store/UserStore.php:31  error  stale-derived
#     $visibleCache 依赖 $rows / $query；setQuery() 未清缓存 ⇒ 过滤结果会停在旧值。
#     ⇒ 在 setQuery() 里加 $this->visibleCache = null;
#
#   apps/users/src/Pages/UsersPage.php:56  error  dead-command
#     'user.del' 已发出，但 CommandRouter 未注册任何 handler ⇒ 点了没反应。

# ── 单页预览（带参页面需要注入 store 夹具；§12.1 第 5 条）─────────
php tools/drift-preview.php apps/users --page users \
  --fixture apps/users/tests/fixtures/users_page.php     # 返回一个 UserStore（可离线 MemoryUserSource）

# ── 提交前（T2 级）─────────────────────────────────────────
export PHPRC="D:/TypePHP/typephp-windows-x64"
cd D:/TypePHP/Drift2
tpc apps/users/project.yml -O2 -m bin                    # 只建一个应用（§16.3）
php tools/drift-conformance.php                          # 合成树夹具
php tools/golden.php --update                            # 首次：生成基线
php tools/golden.php                                     # 之后：比对（★ 用仓库内测试字体）

# ── 形态 B（-m lib）：必须用源码树 tpc（§10.2.2）────────────
php bin/tpc.php ../../sdk/project.yml -O2 -m lib         # 库侧（源码树）
php bin/tpc.php apps/users/project.yml -O2 -m bin        # 消费侧
```

```php
<?php
// apps/users/tests/fixtures/users_page.php —— 单页预览夹具（带参页面的必要输入）
declare(strict_types=1);
namespace App\Users\Tests;

use App\Users\Store\{UserStore, MemoryUserSource};

return new UserStore(new MemoryUserSource([
    ['id' => 1, 'name' => '张三', 'email' => 'zhang@example.com'],
    ['id' => 2, 'name' => '李四', 'email' => 'li@example.com'],
]));
```

---

