<?php
declare(strict_types=1);

namespace App\Showcase;

use Drift\App\AppScope;
use Drift\App\Paths;
use Drift\App\WindowSet;
use Drift\Errors;
use Drift\Platform\Win32\ResizeHookState;
use Drift\Platform\Win32\Win32Platform;

/**
 * 组合根：**全应用唯一提到平台实现的地方**（§5.1）。
 *
 * 共享代码（`ShowcaseApplication` / 页面 / 控件）零平台引用；跨平台 = 同一份应用
 * 源码为每个 OS **各编译一次**（AOT 单 exe），不是同一份二进制到处跑。
 *
 * ★ 建窗 / 还原几何 / 定表面首帧 / `attach` / 主循环 / 几何写回 / 卸静态钩子
 *   全在 `WindowSet`（2026-10-30）。**本文件只决定"开几个窗、装什么应用"**。
 *
 * ★ **循环归平台**（§5.2 裁决 1）：`Application` **没有** `run()`，等待仍由
 *   `Platform::waitEvents()` 给，`WindowSet::run()` 只是编排。
 */
final class Bootstrap
{
    /** 窗口客户区（**逻辑**像素）；物理像素由 `Screen::scale()` 决定（§6） */
    private const WINDOW_WIDTH = 960;

    private const WINDOW_HEIGHT = 640;

    /** 窗口状态文件名（§5.4）：一个应用一个文件，里面按槽位分组 */
    private const STATE_FILE = 'showcase.window';

    public static function run(): void
    {
        $platform = new Win32Platform();

        // ★ 中止序列第 ② 步必须在**别的一切之前**装好（§1.2）。传 callable 而不是
        //   平台实例 —— `Errors` 不依赖 Platform 类型（§5.1）。
        Errors::installFatalBox(
            static function (string $title, string $body) use ($platform): void {
                $platform->fatalBox($title, $body);
            }
        );

        // ★ 第三个参数是**卸载**：两处静态钩子捕获的闭包持有整条应用对象图，
        //   不卸 ⇒ 活到 PHP shutdown ⇒ AOT 关窗必崩（实测 8/8）。
        $set = new WindowSet($platform, Paths::userConfig($platform->configDir(), self::STATE_FILE), static function (): void {
            ResizeHookState::$pump = null;
            Errors::installFatalBox(null);
        });

        // ★ **`AppScope` 是进程一份的那半边**（M2）：字体、任务池、IO 轮询器、
        //   主题、全局命令。单窗口时它等价于"每个应用自建一个"，多窗口时
        //   它才是"两窗共用一份"的物质基础 —— 这里显式建一个，让第二窗有东西可共享。
        $scope = AppScope::make();

        // ★ 对话框 / 剪贴板端口从**这里**递进应用（框架不持平台，§5.1）：
        //   示例台是 `Ctrl+C/X/V` 与「对话框」页的唯一消费者。
        //   递的是**端口**不是平台实例 —— Win32 实现仍只出现在本文件。
        $main = $set->open(
            'main',
            'Drift 示例台',
            self::WINDOW_WIDTH,
            self::WINDOW_HEIGHT,
            static function () use ($platform, $scope): ShowcaseApplication {
                return new ShowcaseApplication($platform->dialog(), $platform->clipboard(), $scope);
            },
        );

        // ★ 真机探针钩子（`tools/probe-close.py ... stay`）：`DRIFT_CLOSE_CONFIRM=1`
        //   ⇒ 启动即把守卫答案设成"拒绝"，不必先点导航到「对话框」页再点开关。
        //   默认路径不变：不设变量 ⇒ `false`。
        if (\getenv('DRIFT_CLOSE_CONFIRM') === '1') {
            $main->app()->setConfirmClose(true);
        }

        $main
            // ★ 最小窗口尺寸（§5.4）：挡住"拖到比内容更小"——那种状态会踩 C5/C6
            //   的布局硬校验（用户实测：可用宽 93 vs 条轨 94 ⇒ 弹 Fatal）。
            ->setMinSize(420, 320)
            // ★ 关闭可拦截（2026-10-30）：声明之后 `WM_CLOSE` 不再直接销毁 ——
            //   它变成一条 `CloseRequestedEvent`，在帧边界落到
            //   `ShowcaseApplication::onCloseRequested()`。
            ->setCloseGuard(true)
            // ★ UIA 无障碍（§9 / M5）：把语义树接到**本窗**。不接 ⇒ 不建语义树、
            //   零开销。示例台是值型 Pattern 的展示台，与 users 同形。
            ->onSemantics();

        // ★★★ **第二个真实窗口**（2026-10-30，M3）：`DRIFT_SECOND_WINDOW=1` 时再建
        //   一个独立工具窗。它不是"再开一个示例台"—— 装的是 `InspectorApplication`
        //   （一块小面板），因为要验的是**基础设施**：两个 `Application` 实例共用
        //   一个 `AppScope`、各自收自己的事件、关一个另一个还在。
        //   ★ 默认不设变量 ⇒ 单窗，与之前逐字节相同。
        //   ★ 槽位名 `inspector` 与主窗的 `main` 不同 ⇒ 几何各存各的。
        if (\getenv('DRIFT_SECOND_WINDOW') === '1') {
            $set->open(
                'inspector',
                '多窗口 · 检查器',
                420,
                560,
                static function () use ($scope): InspectorApplication {
                    return new InspectorApplication($scope);
                },
            )->setMinSize(320, 260);
        }

        // ★ 拖动边缘重排：业务逻辑在 `WindowSet`，这里只是接线（`ResizeHookState`
        //   是 Win32 静态槽，`src/App` 不许引用平台实现）。按句柄分发 ⇒ 多窗时
        //   不会让副窗的拖动去重排主窗（不报错、只画错窗）。
        ResizeHookState::$pump = static function (int $windowHandle) use ($set): void {
            $set->repaintForResize($windowHandle);
        };

        $set->run();
    }
}
