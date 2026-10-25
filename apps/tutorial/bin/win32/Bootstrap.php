<?php
declare(strict_types=1);

namespace App\Tutorial;

use Drift\App\FrameClock;
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
 * ★ **主循环为什么归应用、不在框架里**（DESIGN §5.2 裁决 1「框架绝不自建
 *   循环」）：`while ($platform->pump())` 里除了派发还要做**应用自己的每帧工作**
 *   —— `apps/users` 在这里 reap 子进程、`apps/showcase` 在这里泵 `FrameClock`
 *   并在关窗时存窗口几何。抽进框架就必须长出"每帧钩子 / 启动钩子 / 关窗钩子"
 *   三套回调，而消费者只有 3 个且各需不同钩子 ⇒ 那才是过度设计。
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

        // ★ **事件队列必须每帧前抽干**：平台把 Win32 消息翻成 Drift 事件推进
        //   `input()`，而**派发是框架的活**（§5.2：循环归平台，派发归框架）。
        //   不抽干的话队列会无限增长，而窗口对点击/按键**毫无反应** —— 且不报错。
        // ★ `dispatchInput()` 返回命中链解析出的光标形状 ⇒ 由**这里**喂给
        //   `setCursor()`：应用它需要 `WindowHost`，而框架不持平台。
        $input = $platform->input();

        // ★★★ **自适应帧时钟**（这一段少了，异步与动画在真机上永远不动）。
        //
        //   根因：`pump()` 阻塞在壳的 `GetMessageW` —— **没有输入事件时循环一直睡**。
        //   而 `Application::wantsFrame()` 只是"回答要不要跑下一帧"，**没人问就没人答**：
        //   定时器不上膛 ⇒ `after()` 排的回调永远不响、`Spinner` 永远不转、后台结果只能
        //   等下一次鼠标移动捎带。**闸门抓不到**（smoke 自己推进时钟，每一帧都是显式跑的）。
        //   ⇒ 2026-10-25 从本应用的 ④ 段真机实测：点「开始加载」后停住，
        //     1500ms 后截图与 400ms 时**逐字节相同**（md5 一致）。
        //
        //   `FrameClock` 按 `wantsFrame()` 起/停 15ms 定时器：有活才跳、没活就睡
        //   （空闲 CPU 归零）。它同时负责量帧耗时（`noteFrameCost`）。
        $clock = new FrameClock($platform->timers());
        // 进循环前先上膛：此刻可能已有在途的定时器（首帧就排了 `after()`），
        // 不先 pace 的话第一次 `pump()` 会无限阻塞。
        $clock->pace($app->wantsFrame());

        while ($platform->pump()) {
            while (($event = $input->poll()) !== null) {
                $shape = $app->dispatchInput($event, $platform->now());
                if ($shape !== null) {
                    $window->setCursor($handle, $shape);
                }
                // IME 时序双保险：组字可能发生在同帧的**下一个**输入事件
                // （TranslateMessage 在消息循环层触发 WM_IME_STARTCOMPOSITION）
                // ⇒ 事件处理后立即同步 caret，不等帧末。
                $platform->imeCaret($app->imeCaretRect());
            }

            // 帧由帧时钟跑（内含 `$app->frame()` + 按 `wantsFrame()` 重新上膛）
            $clock->tick($app, $platform->now(), $screen->viewport());

            // IME 定位：框架算几何（逻辑像素）、平台做量化。
            // 不调这一行 = 输入法候选框贴在窗口左上角（能打字、位置错）。
            $platform->imeCaret($app->imeCaretRect());
        }

        $clock->stop();
    }
}
