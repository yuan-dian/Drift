<?php
declare(strict_types=1);

namespace App\Tutorial;

use Drift\App\Paths;
use Drift\App\WindowSet;
use Drift\Errors;
use Drift\Platform\Win32\ResizeHookState;
use Drift\Platform\Win32\Win32Platform;

/**
 * 组合根（composition root）—— **全应用唯一提到平台实现的地方**。
 *
 * ## 这份文件现在只有三件事是本应用自己的
 *
 * 1. `new TutorialApplication()`（应用类名）
 * 2. 窗口标题与尺寸常量
 * 3. 最小窗口尺寸
 *
 * 其余（建窗 / 还原几何 / 定表面首帧 / `attach` / 主循环 / 几何写回 /
 * 卸静态钩子）全在 `WindowSet` 里 —— 2026-10-30 之前这里是 121 行，
 * 四个应用逐行相同，注释还写着"抄的时候整段抄"。**模板不该靠抄**：
 * 抄错一处的那条路只有第二窗会走到。
 *
 * ★ **循环归平台**（§5.2 裁决 1）：`Application` **没有** `run()`，
 *   等待仍由 `Platform::waitEvents()` 给，`WindowSet::run()` 只是编排。
 *
 * ★ 跨平台 = **同一份应用源码为每个 OS 各编译一次**（AOT 单 exe），
 *   换平台 = 换这个文件（`bin/<os>/Bootstrap.php`）。
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

        // ★ 第三个参数是**卸载**：两处静态钩子捕获的闭包持有整条应用对象图，
        //   不卸 ⇒ 它们活到 PHP shutdown ⇒ AOT 解引用已销毁的类元数据 ⇒
        //   关窗必崩（实测 8/8）。放在这里而不是 `run()` 之后手写，是为了让
        //   异常路径也卸（`WindowSet::run()` 的 `finally`），且只写一处。
        $set = new WindowSet($platform, Paths::userConfig($platform->configDir(), 'tutorial.window'), static function (): void {
            ResizeHookState::$pump = null;
            Errors::installFatalBox(null);
        });

        $set->open(
            'main',
            null,   // ★ 标题来自 TutorialApplication::title()（2026-10-30）
            self::WINDOW_WIDTH,
            self::WINDOW_HEIGHT,
            static function (): TutorialApplication {
                return new TutorialApplication();
            },
        )->setMinSize(480, 360);

        // ★ 拖动边缘重排：模态尺寸循环里主循环被系统挂起，壳在 `WM_SIZE` 里
        //   **同步调回** PHP 走轻量重绘。业务逻辑在 `WindowSet::repaintForResize()`，
        //   这里只是接线 —— `ResizeHookState` 是 Win32 的静态槽，`src/App` 不许
        //   引用平台实现，所以这两行必须留在组合根。
        ResizeHookState::$pump = static function (int $windowHandle) use ($set): void {
            $set->repaintForResize($windowHandle);
        };

        $set->run();
    }
}
