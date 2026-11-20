<?php
declare(strict_types=1);

namespace App\Watermark;

use Drift\App\ProcessTaskHost;
use Drift\App\ProcessWorker;
use Drift\App\WindowSet;
use Drift\Errors;
use Drift\Platform\Dialog;
use Drift\Platform\Win32\Win32Platform;

use App\Watermark\Jobs\JobRegistry;

/**
 * 组合根（§A.2.4 / §5.1）：**全应用唯一提到平台实现的地方**。
 *
 * 共享代码（`WatermarkApplication` / `WatermarkRoot` / 三张卡片 / store / jobs）零
 * 平台引用；跨平台 = 同一份应用源码为每个 OS **各编译一次**（AOT 单 exe）。
 *
 * ★ 建窗 / 还原几何 / 定表面首帧 / `attach` / 主循环 / 几何写回 / 卸静态钩子全在
 *   `WindowSet`（2026-10-30）。本文件只剩**本应用自己的三件事**：
 *   后台任务宿主、对话框端口、最小尺寸。
 * ★ **不做无边框窗 / 窗口材质**（`apps/users` 与 `apps/showcase` 里那两段）：那是
 *   §质感 的演示项，本应用是业务闭环 —— 多一段环境变量接线就多一处"只在某种设置下
 *   才走得到"的路径，而它对这个应用的价值是零。
 */
final class Bootstrap
{
    /** 窗口客户区（**逻辑**像素）；物理像素由 `Screen::scale()` 决定（§6） */
    private const WINDOW_WIDTH = 960;

    private const WINDOW_HEIGHT = 780;

    public static function run(): void
    {
        // ★★ worker 分支：**同一个 exe**，靠参数分流（§17.4.5）。
        //   ★ `$argv` 在 tpc 编译期被拒 ⇒ 必须用 `$_SERVER['argv']`。
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

            return;   // stdin 关闭 = UI 进程走了 ⇒ 正常收工
        }

        $platform = new Win32Platform();

        // ★ 对话框端口：**建窗之前取**（`Win32Dialog` 持有窗口对象、owner 每次现取，
        //   所以取早取晚都一样；这里早取是为了"缺端口"当场失败，而不是等用户点了
        //   "选择图片"才发现）。`null` = 本平台没实现 ⇒ 走 §1.2 中止序列。
        $dialog = $platform->dialog();
        if (!$dialog instanceof Dialog) {
            Errors::report('本平台没有对话框实现，watermark 无法选图');

            return;
        }

        // ★ 后台端口：把本 exe 以 `--drift-worker` 起成常驻子进程。`$argv[0]` = 本
        //   exe 路径（AOT 下由启动器给）；空 ⇒ 退化为同步（不静默崩）。
        $exe = (string) ($argv[0] ?? '');
        $taskHost = $exe === ''
            ? null
            : new ProcessTaskHost([$exe, '--drift-worker'], JobRegistry::make());

        // ★★ 预热 worker：子进程的第一个任务要付约 275ms 启动
        //   （`probes/RESULTS.md` §12 ⑧），而本应用**进窗就取签名** —— 不预热的话
        //   那段空等正好落在用户第一眼。`prewarm()` 只把进程**发起**（`proc_open`
        //   异步、立即返回），启动期于是与"建窗 / 首帧"重叠。
        //   ★ 必须在 `open()` 之前：第一个窗口的 `attach()` 会取走端口并缓存。
        if ($taskHost !== null) {
            $taskHost->prewarm();
        }

        $set = new WindowSet($platform, 'watermark');

        $set->open(
            'main',
            self::WINDOW_WIDTH,
            self::WINDOW_HEIGHT,
            static function () use ($dialog, $taskHost): WatermarkApplication {
                return new WatermarkApplication($dialog, $taskHost);
            },
        )
            // ★ 最小尺寸（§5.4）：挡住"拖到比内容更小"—— 那种状态会踩布局的溢出
            //   硬校验（`Dock` 的 top+bottom 就吃掉了全部高度）。
            ->setMinSize(480, 360)
            // ★ UIA 无障碍（§9 / M5）：语义树是每窗一棵。不接 ⇒ 不建树、零开销。
            ->onSemantics();

        $set->run();
    }
}
