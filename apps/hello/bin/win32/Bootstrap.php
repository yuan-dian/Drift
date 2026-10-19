<?php
declare(strict_types=1);

namespace App\Hello;

use Drift\Errors;
use Drift\Platform\Win32\Win32Platform;

/**
 * 组合根：**全应用唯一提到平台实现的地方**（§5.1）。
 *
 * 共享代码（`HelloApplication` / 页面 / 控件）零平台引用；跨平台 = 同一份应用
 * 源码为每个 OS **各编译一次**（AOT 单 exe），不是同一份二进制到处跑。
 * 换平台 = 换这个文件（`bin/<os>/Bootstrap.php`），§4.5。
 *
 * ★ **循环归平台**（§5.2 裁决 1）：`Application` **没有** `run()`，只提供
 *   `attach($surface, $constraints)` 与 `frame($timestampMs, $constraints)`。
 *   `while ($platform->pump())` 这一行就是"框架绝不自建循环"的全部代价。
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
        $window->show($handle);

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
        $surface->resize(
            (int) round((float) self::WINDOW_WIDTH * $screen->scale()),
            (int) round((float) self::WINDOW_HEIGHT * $screen->scale()),
            $screen->scale(),
        );

        $app = new HelloApplication();
        $app->attach($surface, $screen->viewport());

        // ★ **事件队列必须每帧前抽干**：平台把 Win32 消息翻成 Drift 事件推进
        //   `input()`，而**派发是框架的活**（§5.2 裁决 1：循环归平台，派发归框架）。
        //   不抽干的话队列会无限增长，而窗口对点击/按键**毫无反应** —— 且不报错。
        // ★ `dispatchInput()` 对指针事件返回命中链解析出的光标形状 ⇒ 由**这里**
        //   喂给 `setCursor()`：应用它需要 `WindowHost`，而框架不持平台（§5.1）。
        $input = $platform->input();
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

            $app->frame($platform->now(), $screen->viewport());

            // ★ IME 定位（§8.4）：框架算几何（逻辑像素）、平台做量化。
            //   组合根不调这一行 = 输入法候选框贴在窗口左上角（能打字、
            //   候选框位置错）—— 半可用失败形态，故三行注释写死原因。
            $platform->imeCaret($app->imeCaretRect());
        }
    }
}
