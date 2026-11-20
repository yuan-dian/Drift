<?php
declare(strict_types=1);

namespace App\Tutorial;

use Drift\App\AppPaths;
use Drift\App\WindowSet;
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
 *
 * ★ 进程级钩子（fatal box / resize pump）由平台 `hooks()` 提供，`WindowSet`
 *   构造时装、`run()` 的 finally 卸；本文件只决定开哪些窗、装什么应用。
 */
final class Bootstrap
{
    /** 窗口客户区（**逻辑**像素）；物理像素由 `Screen::scale()` 决定 */
    private const WINDOW_WIDTH = 720;

    private const WINDOW_HEIGHT = 560;

    public static function run(): void
    {
        $platform = new Win32Platform();
        $set = new WindowSet($platform, AppPaths::forApp($platform, 'tutorial'));

        $set->open(
            'main',
            self::WINDOW_WIDTH,
            self::WINDOW_HEIGHT,
            static function (): TutorialApplication {
                return new TutorialApplication();
            },
        )->setMinSize(480, 360);

        $set->run();
    }
}
