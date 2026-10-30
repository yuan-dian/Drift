<?php
declare(strict_types=1);

namespace App\Hello;

use Drift\App\EventLoop;
use Drift\Errors;
use Drift\Platform\Screen;
use Drift\Platform\Win32\ResizeHookState;
use Drift\Platform\Win32\Win32Platform;
use Drift\Rendering\RenderSurface;

/**
 * 组合根：**全应用唯一提到平台实现的地方**（§5.1）。
 *
 * 共享代码（`HelloApplication` / 页面 / 控件）零平台引用；跨平台 = 同一份应用
 * 源码为每个 OS **各编译一次**（AOT 单 exe），不是同一份二进制到处跑。
 * 换平台 = 换这个文件（`bin/<os>/Bootstrap.php`），§4.5。
 *
 * ★ **循环归平台**（§5.2 裁决 1）：`Application` **没有** `run()`，只提供
 *   `attach($surface, $constraints)` 与 `frame($timestampMs, $constraints)`。
 *   `EventLoop::run($platform, [$handle => $app])` 这一行就是"框架绝不自建循环"
 *   的全部代价（等待仍由平台给：`Platform::waitEvents()`）。
 *
 * ★ **本文件就是 §19 M1 的出口判据**："一个窗口画出「Hello Drift」"。
 *   它是 `apps/hello` 里唯一引用 `Drift\Platform\Win32` 的文件 ⇒ 也因此是
 *   `apps` 只依赖 `@api` 这条规则（§4.3）唯一需要 `@api platform` 标注的地方。
 */
final class Bootstrap
{
    /** 窗口客户区（**逻辑**像素）；物理像素由 `Screen::scale()` 决定（§6） */
    private const WINDOW_WIDTH = 640;

    private const WINDOW_HEIGHT = 400;

    /** 最小客户区（§5.4）：挡住"拖到比内容更小" */
    private const MIN_WIDTH = 420;

    private const MIN_HEIGHT = 320;

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

        /** @var array<int, HelloApplication> $apps 窗口表（键 = 句柄，交给 `EventLoop`） */
        $apps = [];

        /** @var array<int, array{0: HelloApplication, 1: RenderSurface, 2: Screen}> $drawn */
        $drawn = [];

        self::openWindow($platform, 'Hello Drift', self::WINDOW_WIDTH, self::WINDOW_HEIGHT, $apps, $drawn);

        // ★★ **真机多窗口探针**（2026-10-30，M1-b）：`DRIFT_TWO_WINDOWS=1` 时再建一个
        //   窗口、装第二个 `HelloApplication` 实例。
        //
        //   为什么这条分支写在这里而不是只写在探针脚本里：多窗口的验收点是
        //   **"两个窗口各自收自己的事件、各自跑帧、关一个另一个还在"**，而这三条
        //   只有在**真实的组合根**里才成立（探针脚本跑不进 AOT 产物）。
        //   同一个 `Application` 类实例化两次是本仓 M2 之后就允许的形态
        //   （进程级资源在 `AppScope` 上）；hello 不用异步/任务，故各建各的 scope 无害。
        //
        //   默认不设变量 ⇒ 单窗，与 2026-10-30 之前逐字节相同。
        if (\getenv('DRIFT_TWO_WINDOWS') === '1') {
            self::openWindow($platform, 'Hello Drift · 第二窗', 480, 360, $apps, $drawn);
        }

        // ★ 拖动边缘重排（§5.2）：模态尺寸循环里主循环被系统挂起，壳在 WM_SIZE
        //   里**同步调回** PHP 走轻量重绘（只 settle/layout/paint/present，不碰
        //   任务/动画/焦点/语义）。入口 `drift_resize_hook_pump()` 是 AOT 具名
        //   符号（见 src/Platform/Win32/ResizeHook.php）。
        //   不注入 ⇒ 壳退回"原尺寸贴 + 余区填底色"（拖动中不放大、松手补齐）。
        //
        //   ★ **按句柄分发**（2026-10-30，M1-b）：壳已经知道是哪个窗口在拖，
        //     不按句柄就会让第二窗的拖动去重排第一窗（不报错、只画错窗）。
        ResizeHookState::$pump = static function (int $windowHandle) use ($drawn): void {
            $entry = $drawn[$windowHandle] ?? null;
            if ($entry === null) {
                return;   // 该窗口没走本组合根（或已销毁）：静默跳过
            }

            [$app, $surface, $screen] = $entry;
            $dpr = $screen->scale();
            $vp = $screen->viewport();
            $surface->resize(
                (int) round($vp->maxWidth * $dpr),
                (int) round($vp->maxHeight * $dpr),
                $dpr,
            );
            $app->repaintForResize($vp);
        };

        // ★★★ **主循环交给 `EventLoop`**（N2，2026-10-30）：等 / 派发 / 跑帧。
        //
        //   为什么不是自己写那 12 行：`pump()` 阻塞在壳的 `GetMessageW`，没有输入
        //   事件时循环一直睡 ⇒ `after()` 的回调永远不响、`Spinner` 永远不转、后台
        //   结果只能等下一次鼠标移动捎带（2026-10-25 真机实测：点「开始加载」后
        //   1500ms 的截图与 400ms 时**逐字节相同**，日志一个字都没有）。
        //   现在"等多久"由 `FrameClock::nextWaitMs()` 按**还有什么活**算出来
        //   （动画/异步在途 ⇒ 15ms 帧节奏；只有定时器 ⇒ 一觉睡到点；没事 ⇒ 无限等），
        //   光标 / IME / 退出语义都在 `EventLoop` 里各写一次。
        //   ★ 本应用自己不用异步/动画，但它是**所有新应用的抄写来源** ⇒ 这行必须在。
        EventLoop::run($platform, $apps);
    }

    /**
     * 建一个窗口 + 装一个应用，登记进窗口表。
     *
     * ★ 抽成方法是**因为它有第二个调用点**（`DRIFT_TWO_WINDOWS`），不是为了好看：
     *   两处手抄 25 行，"第二窗少了一步"（比如忘了 `resize()` 首帧就是空白）
     *   只在多窗路径上发生 —— 那正是最没人跑的那条路。
     *
     * @param array<int, HelloApplication> $apps 出参：窗口表（键 = 句柄）
     * @param array<int, array{0: HelloApplication, 1: RenderSurface, 2: Screen}> $drawn
     *   出参：拖动重排要用的三件（应用 / 表面 / 那块屏）
     */
    private static function openWindow(
        Win32Platform $platform,
        string $title,
        int $width,
        int $height,
        array &$apps,
        array &$drawn,
    ): int {
        $window = $platform->window();
        $handle = $window->createWindow($title, $width, $height);
        $window->show($handle);

        $provider = $platform->surface($handle);
        if ($provider === null) {
            // `Errors::report()` 是 Fatal ⇒ 走 §1.2 中止序列，不会返回。
            // 这一行 `return` 只为让下面的类型收窄成立。
            Errors::report('Win32: 平台没给出渲染表面（窗口句柄无效）');

            return $handle;
        }

        $screen = $platform->screen($handle);
        $surface = $provider->surface();

        // 首帧之前把表面尺寸定下来：`attach()` 内部就会跑一帧（`Application::attach`），
        // 而那帧要往表面上画 ⇒ 尺寸为 0 时那一帧会被丢弃（画面到第二次 resize 才出现）。
        $surface->resize(
            (int) round((float) $width * $screen->scale()),
            (int) round((float) $height * $screen->scale()),
            $screen->scale(),
        );

        $app = new HelloApplication();
        $app->attach($surface, $screen->viewport());

        // ★ 最小窗口尺寸（§5.4）：挡住"拖到比内容更小"——那种状态会踩 C5/C6
        //   的布局硬校验（用户实测：可用宽 93 vs 条轨 94 ⇒ 弹 Fatal，拖动被打断）。
        //   "窗口被拖小"是用户行为、不是编程错误；最小尺寸是 Qt/WPF/Flutter 的标配。
        $window->setMinSize($handle, self::MIN_WIDTH, self::MIN_HEIGHT);

        $apps[$handle] = $app;
        $drawn[$handle] = [$app, $surface, $screen];

        return $handle;
    }
}
