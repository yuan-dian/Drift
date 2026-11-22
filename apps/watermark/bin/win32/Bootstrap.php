<?php
declare(strict_types=1);

namespace App\Watermark;

use Drift\App\AppEntry;
use Drift\App\AppScope;
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
 *   后台任务宿主、对话框端口、最小尺寸 —— 前两件都装进 `AppScope`，应用零参数。
 * ★ **不做无边框窗 / 窗口材质**（`apps/users` 与 `apps/showcase` 里那两段）：那是
 *   §质感 的演示项，本应用是业务闭环 —— 多一段环境变量接线就多一处"只在某种设置下
 *   才走得到"的路径，而它对这个应用的价值是零。
 */
final class Bootstrap extends AppEntry
{
    /** 窗口客户区（**逻辑**像素）；物理像素由 `Screen::scale()` 决定（§6） */
    private const WINDOW_WIDTH = 960;

    private const WINDOW_HEIGHT = 780;

    protected function jobs(): array
    {
        return JobRegistry::make();
    }

    protected function start(): void
    {
        $platform = new Win32Platform();

        // ★ 对话框端口：**建窗之前取**（缺端口当场失败，而不是等用户点了"选择图片"
        //   才发现）。`null` = 本平台没实现 ⇒ 走中止序列。
        $dialog = $platform->dialog();
        if (!$dialog instanceof Dialog) {
            Errors::report('本平台没有对话框实现，watermark 无法选图');

            return;
        }

        $set = new WindowSet($platform, 'watermark');

        // ★ 端口全进作用域：对话框 + 后台池都是"一个进程一份"，多窗口自动共用。
        //   `useBackgroundJobs()` 收掉 exe → 池 → 预热 → 装端口四步（预热让进窗就取
        //   签名的 275ms 落在建窗期），取不到 exe 就不装后台端口，submit 给 rejected Future。
        $scope = AppScope::make()
            ->setDialog($dialog)
            ->useBackgroundJobs($this->jobs());

        $set->open(
            'main',
            self::WINDOW_WIDTH,
            self::WINDOW_HEIGHT,
            static fn (): WatermarkApplication => new WatermarkApplication($scope),
        )
            // ★ 最小尺寸（§5.4）：挡住"拖到比内容更小"—— 那种状态会踩布局的溢出
            //   硬校验（`Dock` 的 top+bottom 就吃掉了全部高度）。
            ->setMinSize(480, 360)
            // ★ UIA 无障碍（§9 / M5）：语义树是每窗一棵。不接 ⇒ 不建树、零开销。
            ->onSemantics();

        $set->run();
    }
}
