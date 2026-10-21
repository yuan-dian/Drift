<?php
declare(strict_types=1);

namespace App\Showcase;

use Drift\App\Paths;
use Drift\App\WindowStateStore;
use Drift\App\FrameClock;
use Drift\Errors;
use Drift\Platform\Win32\Win32Platform;
use Drift\Platform\Win32\ResizeHookState;

/**
 * 组合根：**全应用唯一提到平台实现的地方**（§5.1）。
 *
 * 共享代码（`ShowcaseApplication` / 页面 / 控件）零平台引用；跨平台 = 同一份应用
 * 源码为每个 OS **各编译一次**（AOT 单 exe），不是同一份二进制到处跑。
 * 换平台 = 换这个文件（`bin/<os>/Bootstrap.php`），§4.5。
 *
 * ★ **循环归平台**（§5.2 裁决 1）：`Application` **没有** `run()`，只提供
 *   `attach($surface, $constraints)` 与 `frame($timestampMs, $constraints)`。
 *   `while ($platform->pump())` 这一行就是"框架绝不自建循环"的全部代价。
 *
 * ★ 本文件与 hello / users 的 Bootstrap 逐行同构（§4.5 组合根形态）——
 *   换应用只换 `new ShowcaseApplication()` 一行。
 *   它是 `apps/hello` 里唯一引用 `Drift\Platform\Win32` 的文件 ⇒ 也因此是
 *   `apps` 只依赖 `@api` 这条规则（§4.3）唯一需要 `@api platform` 标注的地方。
 */
final class Bootstrap
{
    /** 窗口客户区（**逻辑**像素）；物理像素由 `Screen::scale()` 决定（§6） */
    private const WINDOW_WIDTH = 960;

    private const WINDOW_HEIGHT = 640;

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
        $handle = $window->createWindow('Drift 示例台', self::WINDOW_WIDTH, self::WINDOW_HEIGHT);
        $window->show($handle);

        // ★ 窗口状态读回（§5.4 / §19 M5）：**启动一次同步 IO**，不在帧内。
        //   ★ 顺序在 `surface->resize()` **之前** —— resize 定首帧尺寸，而首帧就该是
        //     还原后的尺寸；放到后面用户会先看到一帧默认尺寸再被拽过去。
        //   ★ 没有配置文件 / 读坏了 / 平台没给配置目录 ⇒ 一律**静默降级**到默认几何
        //     （§5.4 纪律 2，G2 的显式例外；每次降级写一条 Diagnostic）。
        $state = new WindowStateStore(Paths::userConfig($platform->configDir(), 'showcase.window'));
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

        // ★ 剪贴板端口在这里递进应用（2026-10-21，#51）：框架不持平台（§5.1）
        //   ⇒ `Application::clipboard()` 默认 null；示例台是 `Ctrl+C/X/V`
        //   （§9.1 让位规则 ② 的编辑命令环）的第一个真实消费者。
        //   ★ 递的是**端口**不是平台实例 —— Win32 实现仍只出现在本文件。
        $app = new ShowcaseApplication($platform->clipboard());
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

        // ★ **事件队列必须每帧前抽干**：平台把 Win32 消息翻成 Drift 事件推进
        //   `input()`，而**派发是框架的活**（§5.2 裁决 1：循环归平台，派发归框架）。
        //   不抽干的话队列会无限增长，而窗口对点击/按键**毫无反应** —— 且不报错。
        // ★ `dispatchInput()` 对指针事件返回命中链解析出的光标形状 ⇒ 由**这里**
        //   喂给 `setCursor()`：应用它需要 `WindowHost`，而框架不持平台（§5.1）。
        $input = $platform->input();

        // ★★ 自适应帧时钟（§17.4.9）：`pump()` 阻塞在 `GetMessageW` —— 没输入事件时
        //   循环一直睡，后台结果只能等下一条用户消息捎带（现象：任务完了界面几秒后才变、
        //   Loading 转圈图不动）。`FrameClock` 按 `Application::wantsFrame()` 起/停 15ms
        //   定时器：有活才跳、没活就睡（空闲 CPU 归零）。同 users 应用的接线。
        $clock = new FrameClock($platform->timers());
        // ★ 进循环前先上膛：此刻可能已有在途任务（首屏取数），不定时器第一次 pump 会无限阻塞。
        $clock->pace($app->wantsFrame());

        while ($platform->pump()) {
            while (($event = $input->poll()) !== null) {
                $shape = $app->dispatchInput($event);
                if ($shape !== null) {
                    $window->setCursor($handle, $shape);
                }
                // ★ IME 时序双保险（2026-10-19）：组字可能在同帧的**下一个**输入
                //   事件发生（TranslateMessage 在消息循环层触发 WM_IME_STARTCOMPOSITION）
                //   ⇒ 事件处理后立即同步 caret，不等帧末（FrameClock 空闲睡眠时帧末
                //   版会晚到，输入法已按旧位置放过一次候选框）。与帧末那次同口径。
                $platform->imeCaret($app->imeCaretRect());
            }

            // ★ 帧由帧时钟跑（同 users）：顺带量两帧间隔，帧后再按 `wantsFrame()`
            //   决定下次要不要被叫醒（动画在跑 ⇒ 继续跳；都停 ⇒ 定时器停摆、CPU 归零）。
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
