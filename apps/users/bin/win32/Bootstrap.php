<?php
declare(strict_types=1);

namespace App\Users;

use Drift\App\FrameClock;
use Drift\App\Paths;
use Drift\App\ProcessTaskHost;
use Drift\App\ProcessWorker;
use Drift\App\WindowStateStore;
use Drift\Errors;
use Drift\Platform\Win32\Win32Platform;
use Drift\Rendering\Color;

use App\Users\Store\MemoryUserSource;
use App\Users\Jobs\JobRegistry;
use Drift\Platform\Win32\ResizeHookState;

/**
 * 组合根（§A.2.4 / §5.1）：**全应用唯一提到平台实现的地方**。
 *
 * 共享代码（`UsersApplication` / 页面 / 控件 / store）零平台引用；跨平台 = 同一份
 * 应用源码为每个 OS **各编译一次**（AOT 单 exe）。换平台 = 换这个文件。
 *
 * ★ **循环归平台**（§5.2 裁决 1）：`Application` 没有 `run()`，只提供
 *   `attach()` 与 `frame()` —— `while ($platform->pump())` 这一行就是
 *   "框架绝不自建循环"的全部代价。
 * ★ **中止序列第 ② 步在别的一切之前装好**（§1.2）：引导阶段就崩的话，没有错误框
 *   就只剩"闪退"。传 callable 而不是 Platform 实例（`Errors` 不依赖 Platform 类型）。
 */
final class Bootstrap
{
    /** 窗口客户区（**逻辑**像素）；物理像素由 `Screen::scale()` 决定（§6） */
    private const WINDOW_WIDTH = 900;

    private const WINDOW_HEIGHT = 640;

    public static function run(): void
    {
        // ★★ worker 分支：**同一个 exe**，靠参数分流（§17.4.5）。
        //   ★ `$argv` 在 tpc 编译期被拒（"The variable $argv is undefined"）⇒ 必须用
        //     `$_SERVER['argv']`（探针 ⑦ 实测：连额外参数一起给全）。
        //   ★ worker 只跑 `ProcessWorker::serve()`（读 stdin、跑任务、回 stdout），
        //     **不进消息循环、不建窗口、不碰 UI**。
        $argv = $_SERVER['argv'] ?? [];
        $isWorker = false;
        foreach ($argv as $arg) {
            if ($arg === '--drift-worker') {
                $isWorker = true;

                break;
            }
        }

        if ($isWorker) {
            ProcessWorker::serve(JobRegistry::make());

            return; // stdin 关闭 = UI 进程走了 ⇒ 正常收工
        }

        $platform = new Win32Platform();

        Errors::installFatalBox(
            static function (string $title, string $body) use ($platform): void {
                $platform->fatalBox($title, $body);
            }
        );

        // ★ **M4 的形态到了**：首屏取数不再占 UI 线程 —— 交给常驻子进程（§17.4.5），
        //   它去请求一个**真 HTTP 后端**（`apps/users/mock-server.php`，故意慢 1.2s）。
        //   ⇒ `LoadStatus::Loading` 第一次真的被用户看见（§11.5 的结论）。
        //   ★ 换回同步只改这一行：`new UsersApplication($src)`（host = null）。
        //   ★ `$argv[0]` = 本 exe 路径（AOT 下由启动器给；空则退化为同步，不静默崩）。
        $src = new MemoryUserSource();
        $exe = (string) ($argv[0] ?? '');
        $host = $exe === '' ? null : new ProcessTaskHost([$exe, '--drift-worker'], JobRegistry::make());
        $app = new UsersApplication($src, $host);

        $window = $platform->window();
        $handle = $window->createWindow('用户管理', self::WINDOW_WIDTH, self::WINDOW_HEIGHT);
        $window->show($handle);

        // ★ 窗口不透明度（P1 §质感 / Stage 1a）：环境变量驱动，**默认不变**。
        //   `DRIFT_WINDOW_OPACITY=0.85` ⇒ 整窗 85% 不透明（半透明）。
        //   ★ 这是 `WindowHost::setOpacity()` 的**真实消费者**（否则它是个没人用的
        //     API）；真正"内容透、控件不透"的毛玻璃是 Stage 2（逐像素 alpha + DWM 亚克力）。
        $opacityEnv = \getenv('DRIFT_WINDOW_OPACITY');
        if ($opacityEnv !== false && $opacityEnv !== '') {
            $window->setOpacity($handle, (float) $opacityEnv);
        }

        // ★ 无边框窗（P1 §质感 / Stage 1b）：环境变量驱动，**默认不变**。
        //   `DRIFT_WINDOW_FRAMELESS=1` ⇒ 去标题栏，顶条自己当标题栏。
        //   ★ 三件事必须**一起**做，少一件就是"半可用"：
        //     ① `setFrameless()` —— 客户区=整窗（`WM_NCCALCSIZE`，样式不变 ⇒
        //        贴边吸附 / 快照布局 / 最大化动画仍是系统的）；
        //     ② `windowDrag()` —— 顶条可拖（否则窗口拖不动）；
        //     ③ `window.*` 三条命令 —— 顶条右侧的自绘窗口按钮（否则关不掉）。
        //   ★ 拖动带传 **0**：壳的原生拖动带（`WM_NCHITTEST` → `HTCAPTION`）只
        //     适用于"应用不画顶条"的场景；这里顶条自己声明拖动，能精确让位给
        //     按钮（壳看不见控件树，原生带会把按钮点击一起吞掉）。
        //   ★ 必须在 `$state->restore()` **之前**：无边框会让客户区变大（多出
        //     原标题栏高度），restore 的客户区↔外框换算得按**新**口径算。
        $framelessEnv = \getenv('DRIFT_WINDOW_FRAMELESS');
        if ($framelessEnv !== false && $framelessEnv !== '' && $framelessEnv !== '0') {
            $window->setFrameless($handle, 0);
            $app->windowDrag(static function () use ($window, $handle): void {
                $window->beginDrag($handle);
            });

            $winCommands = new WindowCommands($window, $handle);
            $app->router()->on('window.minimize', $winCommands);
            $app->router()->on('window.maximize', $winCommands);
            $app->router()->on('window.close', $winCommands);
        }

        // ★ 窗口状态读回（§5.4 / §19 M5）：**启动一次同步 IO**，不在帧内。
        //   ★ 顺序在 `surface->resize()` **之前** —— resize 定首帧尺寸，而首帧就该是
        //     还原后的尺寸；放到后面用户会先看到一帧默认尺寸再被拽过去。
        //   ★ 没有配置文件 / 读坏了 / 平台没给配置目录 ⇒ 一律**静默降级**到默认几何
        //     （§5.4 纪律 2，G2 的显式例外；每次降级写一条 Diagnostic）。
        //   ★ 还原成功后下面的 resize 改用**实际**客户区尺寸，而不是常量。
        $state = new WindowStateStore(Paths::userConfig($platform->configDir(), 'users.window'));
        $state->restore($window, $handle);

        $provider = $platform->surface($handle);
        if ($provider === null) {
            // `Errors::report()` 是 Fatal ⇒ 走 §1.2 中止序列，不会返回。
            // 这一行 `return` 只为让下面的 `$provider->surface()` 类型收窄成立。
            Errors::report('Win32: 平台没给出渲染表面（窗口句柄无效）');

            return;
        }

        $screen = $platform->screen();
        $surface = $provider->surface();

        // ★ 窗口底色 / 逐像素透明（P1 §质感 / Stage 2b）：环境变量驱动，**默认不变**。
        //   `DRIFT_WINDOW_BACKGROUND=#FFFFFF00` ⇒ 全透明底：应用没画到的地方**透出桌面**；
        //   `#FFFFFFB0` ⇒ 半透明底（透出桌面、内容仍可读）。
        //   ★ 这是 `RenderSurface::setBackground()` 的**真实消费者**，也是
        //     `drift_win_set_pixel_alpha()` 的**唯一入口**（带 alpha 的底色 ⇒ 壳切
        //     `UpdateLayeredWindow`）。不设 ⇒ 白底 + GDI 贴屏，零回归。
        //   ★ 必须在 `attach()` **之前**：`attach()` 内部就跑首帧，那一刻底色就得对，
        //     否则首帧按白底画、第二帧才变透明（用户看到闪一下白）。
        //   ★ 解析失败是 Fatal（`Color::fromHex` 只认 `#RGB/#RRGGBB/#RRGGBBAA`）——
        //     环境变量写错不该静默退化成白底。
        //   ★★ **带 alpha 时必须同时 `DRIFT_WINDOW_FRAMELESS=1`**：`UpdateLayeredWindow`
        //     把位图贴在窗口左上角，有标题栏时客户区起点比窗口原点低一个标题栏 ⇒
        //     画面整体上移。壳在"帧尺寸 ≠ 窗口矩形"时会**回落 GDI**（整窗不透明），
        //     所以漏设不会黑屏，只是透明没生效。
        $bgEnv = \getenv('DRIFT_WINDOW_BACKGROUND');
        if ($bgEnv !== false && $bgEnv !== '') {
            $surface->setBackground(Color::fromHex($bgEnv));
        }

        // 首帧之前把表面尺寸定下来：`attach()` 内部就会跑一帧（`Application::attach`），
        // 而那帧要往表面上画 ⇒ 尺寸为 0 时那一帧会被丢弃（画面到第二次 resize 才出现）。
        // 还原后的实际客户区（读回，不是常量）：断言"缩放到真实几何"，否则
        // 还原了窗口大小、画面还按旧尺寸排 ⇒ 四周出现拉伸/裁切且不报错。
        $restored = $window->clientSize($handle);
        $surface->resize(
            (int) round($restored->width * $screen->scale()),
            (int) round($restored->height * $screen->scale()),
            $screen->scale(),
        );

        $app->attach($surface, $screen->viewport());

        // ★ 最小窗口尺寸（§5.4）：挡住"拖到比内容更小"——那种状态会踩 C5/C6
        //   的布局硬校验（用户实测：可用宽 93 vs 条轨 94 ⇒ 弹 Fatal，拖动被打断）。
        //   "窗口被拖小"是用户行为、不是编程错误；最小尺寸是 Qt/WPF/Flutter 的标配。
        //   420×320 逻辑像素 = 顶条（标题 + 3 个导航 + 文件菜单）留余量 + 状态栏 + 数行列表。
        $window->setMinSize(420, 320);

        // ★ 拖动边缘重排（§5.2）：模态尺寸循环里主循环被系统挂起，壳在 WM_SIZE
        //   里**同步调回** PHP 走轻量重绘（只 settle/layout/paint/present，不碰
        //   任务/动画/焦点/语义）。入口 `drift_resize_hook_pump()` 是 AOT 具名
        //   符号（见 src/Platform/Win32/ResizeHook.php）。
        //   不注入 ⇒ 壳退回"原尺寸贴 + 余区填底色"（拖动中不放大、松手补齐）。
        ResizeHookState::$pump = static function () use ($app, $surface, $screen): void {
            $dpr = $screen->scale();
            $vp = $screen->viewport();
            $surface->resize(
                (int) round($vp->maxWidth * $dpr),
                (int) round($vp->maxHeight * $dpr),
                $dpr,
            );
            $app->repaintForResize($vp);
        };

        // ★ UIA 无障碍（§9 / M5）：把语义树消费者接到窗口 —— 框架只产 SemanticsTree、
        //   经闭包交给平台（框架不持平台，§5.1）。不接 ⇒ 不建语义树，零开销。
        $app->setSemanticsSink(static function (array $nodes) use ($window, $handle): void {
            $window->pushSemantics($nodes);
        });

        // ★ UIA 的**可操作**半边（§9 / M5）**不需要在这里接线**：壳把 `Invoke`
        //   转投成一条 `SemanticsInvokeEvent` 塞进输入队列，框架的
        //   `Application::dispatchInput()` 自己接住（见该事件类注释）。
        //   ★ 这里曾经有一个 `UiaInvokeState::$handler = ...` 的静态槽 ——
        //     已随"壳里零 PHP 调用"一起删掉：跨进程回调里重入 PHP 会让进程静默消失。

        // ★ **事件队列必须每帧前抽干**（§5.2 裁决 1：派发归框架）—— 不抽干的话
        //   队列会无限增长，而窗口对点击/按键**毫无反应**，且不报错。
        // ★ `dispatchInput()` 对指针事件返回命中链解析出的光标形状 ⇒ 由这里喂给
        //   `setCursor()`：应用它需要 `WindowHost`，而框架不持平台（§5.1）。
        $input = $platform->input();

        // ★★ 自适应帧时钟（§17.4.9）：`pump()` 阻塞在 `GetMessageW` 上 —— 没有输入
        //   事件时循环会一直睡，**后台结果只能等下一条用户消息捎带**（现象：任务
        //   1.2s 就完了、界面 3s 后才变；Loading 的转圈图一动不动）。
        //   `FrameClock` 按 `Application::wantsFrame()` 起/停一个 15ms 定时器：
        //   有活才跳、没活就睡（空闲 CPU 归零）。
        $clock = new FrameClock($platform->timers());

        // ★ **进循环之前先上膛**：此刻可能已经有在途任务（应用构造器里 `load()`
        //   就提交了首屏取数）⇒ 不定时器的话第一次 `pump()` 就无限期阻塞，缺陷照旧。
        $clock->pace($app->wantsFrame());

        while ($platform->pump()) {
            while (($event = $input->poll()) !== null) {
                $shape = $app->dispatchInput($event, $platform->now());
                if ($shape !== null) {
                    $window->setCursor($handle, $shape);
                }
                // ★ IME 时序双保险（2026-10-19）：组字可能在同帧的**下一个**输入
                //   事件发生（TranslateMessage 在消息循环层触发 WM_IME_STARTCOMPOSITION）
                //   ⇒ 事件处理后立即同步 caret，不等帧末（FrameClock 空闲睡眠时帧末
                //   版会晚到，输入法已按旧位置放过一次候选框）。与帧末那次同口径。
                $platform->imeCaret($app->imeCaretRect());
            }

            // ★ 帧由帧时钟跑：它顺带量"两帧间隔"（§17.4.9 的卡顿观测），
            //   帧后再按 `wantsFrame()` 决定下一次要不要被叫醒。
            $clock->tick($app, $platform->now(), $screen->viewport());

            // ★ IME 定位（§8.4）：框架算几何（逻辑像素）、平台做量化。
            //   组合根不调这一行 = 输入法候选框贴在窗口左上角（能打字、
            //   候选框位置错）—— 半可用失败形态，故三行注释写死原因。
            $platform->imeCaret($app->imeCaretRect());
        }

        $clock->stop();

        // ★ 窗口状态写回（§5.4）：**退出一次同步 IO**（循环之外，不占帧）。
        //   最小化时退出 ⇒ 平台侧 position() 给 null ⇒ 跳过写入，保留上一份状态。
        $state->persist($window, $handle);
    }
}
