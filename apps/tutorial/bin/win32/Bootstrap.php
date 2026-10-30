<?php
declare(strict_types=1);

namespace App\Tutorial;

use Drift\App\EventLoop;
use Drift\Errors;
use Drift\Platform\Win32\ResizeHookState;
use Drift\Platform\Win32\Win32Platform;

/**
 * 组合根（composition root）—— **全应用唯一提到平台实现的地方**。
 *
 * ## 这个文件为什么这么长
 *
 * 它是**模板**，不是逻辑：121 行里只有 **2 行**是本应用自己的
 * （`new TutorialApplication()` 与窗口标题/尺寸常量），其余是"把平台、
 * 表面、输入队列、主循环接起来"的固定动作。抄的时候整段抄，只改那 2 行。
 *
 * ★ **主循环为什么不在框架的 `Application` 里**（DESIGN §5.2 裁决 1「框架绝不自建
 *   循环」）：`Application` 只提供 `attach()` / `frame()`，没有 `run()` ——
 *   自己 `GetMessage` 的框架就没法被移植（每个 OS 一套）。
 *   ★★ **2026-10-30（N2）更正上一版的说法**：上一版写"抽进框架就要长出
 *   '每帧钩子 / 启动钩子 / 关窗钩子'三套回调，消费者只有 3 个且各需不同钩子 ⇒
 *   那是过度设计"。**实测是错的**：四个应用的循环体逐字相同，各应用的"每帧工作"
 *   （reap 子进程 / 存窗口几何）根本不在循环里，而在 `ResizeHookState`、
 *   退出后的 `persist()` 这些**循环之外**的位置。所以现在抽成了
 *   `EventLoop::run()`（它仍把"等"委托给 `Platform::waitEvents()`，
 *   不自建事件源 ⇒ 不与那条裁决冲突）。
 *
 * ★ **`Application` 没有 `run()`**，只有 `attach()` 与 `frame()`；"循环"这一行
 *   就是这条裁决的全部代价。
 *
 * ★ 跨平台 = **同一份应用源码为每个 OS 各编译一次**（AOT 单 exe），不是同一份
 *   二进制到处跑 ⇒ 换平台 = 换这个文件（`bin/<os>/Bootstrap.php`）。
 */
final class Bootstrap
{
    /** 窗口客户区（**逻辑**像素）；物理像素由 `Screen::scale()` 决定 */
    private const WINDOW_WIDTH = 720;

    private const WINDOW_HEIGHT = 560;

    public static function run(): void
    {
        $platform = new Win32Platform();

        // ★ 中止序列第 ② 步必须在**别的一切之前**装好：引导阶段就崩的话，
        //   没有错误框就只剩"闪退"。传 callable 而不是 Platform 实例 ——
        //   `Errors` 不依赖 Platform 类型，"平台名只出现在组合根"才不被破坏。
        Errors::installFatalBox(
            static function (string $title, string $body) use ($platform): void {
                $platform->fatalBox($title, $body);
            }
        );

        $window = $platform->window();
        $handle = $window->createWindow('Drift 教程', self::WINDOW_WIDTH, self::WINDOW_HEIGHT);
        $window->show($handle);

        $provider = $platform->surface($handle);
        if ($provider === null) {
            // `Errors::report()` 是 Fatal ⇒ 走中止序列，不会返回。
            // 这一行 `return` 只为让下面的类型收窄成立。
            Errors::report('Win32: 平台没给出渲染表面（窗口句柄无效）');

            return;
        }

        $screen = $platform->screen();
        $surface = $provider->surface();

        // 首帧之前把表面尺寸定下来：`attach()` 内部就会跑一帧，而那帧要往表面上画
        // ⇒ 尺寸为 0 时那一帧会被丢弃（现象：画面到第二次 resize 才出现）。
        $surface->resize(
            (int) round((float) self::WINDOW_WIDTH * $screen->scale()),
            (int) round((float) self::WINDOW_HEIGHT * $screen->scale()),
            $screen->scale(),
        );

        // ══════════ 本应用自己的第 1 行 ══════════
        $app = new TutorialApplication();
        // ═════════════════════════════════════════

        $app->attach($surface, $screen->viewport());

        // 最小窗口尺寸：挡住"拖到比内容更小"——那种状态会踩布局硬校验
        // （用户实测：可用宽 93 vs 条轨 94 ⇒ 弹 Fatal，拖动被打断）。
        // "窗口被拖小"是用户行为、不是编程错误 ⇒ 最小尺寸是标配。
        $window->setMinSize(480, 360);

        // 拖动边缘重排：模态尺寸循环里主循环被系统挂起，壳在 WM_SIZE 里
        // **同步调回** PHP 走轻量重绘（只 settle/layout/paint/present，
        // 不碰任务/动画/焦点/语义）。入口 `drift_resize_hook_pump()` 是
        // AOT 具名符号（见 src/Platform/Win32/ResizeHook.php）。
        // 不注入 ⇒ 壳退回"原尺寸贴 + 余区填底色"（拖动中不放大、松手补齐）。
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


        // ★★★ **主循环交给 `EventLoop`**（N2，2026-10-30）：等 / 派发 / 跑帧。
        //
        //   这一段少了，异步与动画在真机上**永远不动**（本应用 ④ 段真机实测：
        //   点「开始加载」后停住，1500ms 后截图与 400ms 时 md5 逐字节相同）。
        //   根因链条：`pump()` 阻塞在 `GetMessageW` ⇒ 没有输入事件时循环一直睡 ⇒
        //   `after()` 的回调永远不响、`Spinner` 永远不转 ⇒ 后台结果只能等下一次鼠标
        //   移动捎带。**smoke 抓不到**：那里的每一帧都是显式跑的，缺的是驱动方。
        //
        //   现在"等多久"由 `FrameClock::nextWaitMs()` 按**还有什么活**算出来：
        //   动画 / 异步在途 ⇒ 15ms 帧节奏；只有定时器 ⇒ 一觉睡到点（不再每 15ms 空醒）；
        //   真的没事 ⇒ 无限等（CPU 归零）。光标 / IME / 退出语义在 `EventLoop` 里各写一次。
        EventLoop::run($platform, $app, $handle);
    }
}
