<?php
declare(strict_types=1);

namespace App\Showcase;

use Drift\App\AppEntry;
use Drift\App\AppScope;
use Drift\App\WindowSet;
use Drift\Platform\Win32\Win32Platform;
use Drift\Theme\ThemeData;

use App\Showcase\Jobs\JobRegistry;

/**
 * 组合根：**全应用唯一提到平台实现的地方**（§5.1）。
 *
 * 共享代码（`ShowcaseApplication` / 页面 / 控件）零平台引用；跨平台 = 同一份应用
 * 源码为每个 OS **各编译一次**（AOT 单 exe），不是同一份二进制到处跑。
 *
 * ★ 建窗 / 还原几何 / 定表面首帧 / `attach` / 主循环 / 几何写回 / 卸静态钩子
 *   全在 `WindowSet`。**本文件只决定"开几个窗、装什么应用"**。
 *
 * ★ **循环归平台**（§5.2 裁决 1）：`Application` **没有** `run()`，等待仍由
 *   `Platform::waitEvents()` 给，`WindowSet::run()` 只是编排。
 */
final class Bootstrap extends AppEntry
{
    /** 窗口客户区（**逻辑**像素）；物理像素由 `Screen::scale()` 决定（§6） */
    private const WINDOW_WIDTH = 960;

    private const WINDOW_HEIGHT = 640;

    /**
     * 最小客户区（**逻辑**像素）—— 挡住"拖到比内容更小"。
     *
     * ★ 提成常量而不是留在 `setMinSize()` 里：`tools/narrow-audit.php` 要拿它
     *   减去侧栏宽度，算出"最小窗口时内容区还剩多少"，再逐页审计装不装得下。
     *   写死在那条命令里 ⇒ 改了这里审计还按老值跑，**全绿是假的**。
     */
    private const MIN_WINDOW_WIDTH = 420;

    private const MIN_WINDOW_HEIGHT = 320;

    /** 窗口状态文件名（§5.4）：一个应用一个文件，里面按槽位分组 */
    private const APP_NAME = 'showcase';

    protected function jobs(): array
    {
        return JobRegistry::make();
    }

    protected function start(): void
    {
        $platform = new Win32Platform();
        $set = new WindowSet($platform, self::APP_NAME);

        // ★ **`AppScope` 是进程一份的那半边**（M2）：字体、任务池、IO 轮询器、
        //   主题、全局命令。单窗口时它等价于"每个应用自建一个"，多窗口时
        //   它才是"两窗共用一份"的物质基础 —— 这里显式建一个，让第二窗有东西可共享。
        $scope = AppScope::make();

        // ★ 启动时按系统外观取一份主题：HC 信号与系统色只在组合根能问（§5.1
        //   纪律 1，Application 不持平台）。运行期切外观走 ThemeChangedEvent
        //   自带的快照，由 Application 换主题并全树下发，不必再问平台。
        $scope->setTheme(ThemeData::forAppearance($platform->screen(0)->appearance()));

        // ★★ 后台任务后端（N3/N5）：异步表格要**真慢任务**跑在别的进程里 ——
        //   默认的 `SyncTaskHost` 会在 `submit()` 那一帧同步跑完 job（慢网络 ⇒ UI 卡死）。
        //   `useBackgroundJobs()` 收掉 exe → 池 → 预热 → 装端口四步，必须在第一个窗口
        //   `attach()` 之前（端口在那时被 `PumpHost` 取走并缓存）。
        //   ★ **池容量 3**：这是「并发取数」页真正并行的前置条件。3 是最小的"够用"值；
        //     池是懒扩张的（`prewarm()` 只发 1 个），不点那页时进程数与串行时一样。
        //     满载时 UI + 3 worker ≈ 100 + 3×31 MB，其余页永远只用到第一个 worker。
        //     `maxWorkers` 是组合根的决定，不是页面能提的要求（一个 worker 是一整套 AOT 运行时）。
        $scope->useBackgroundJobs($this->jobs(), maxWorkers: 3);

        // ★ 数据源模式：默认本地慢任务（开箱即用，零依赖）；
        //   `DRIFT_SHOWCASE_HTTP=1` ⇒ UI 侧 AsyncHttp 真 HTTP（先起 `tools/app-workers/showcase-mock.php`）。
        $rowsMode = \getenv('DRIFT_SHOWCASE_HTTP') === '1'
            ? ShowcaseApplication::ROWS_HTTP
            : ShowcaseApplication::ROWS_LOCAL;

        // ★ 对话框 / 剪贴板端口装进作用域（框架不持平台，§5.1）：
        //   示例台是 `Ctrl+C/X/V` 与「对话框」页的唯一消费者。
        //   递的是**端口**不是平台实例 —— Win32 实现仍只出现在本文件。
        $scope->setDialog($platform->dialog())->setClipboard($platform->clipboard());

        $main = $set->open(
            'main',
            self::WINDOW_WIDTH,
            self::WINDOW_HEIGHT,
            static fn (): ShowcaseApplication => new ShowcaseApplication($scope, $rowsMode),
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
            //   ★ 这两个数不是"随便定一个够大"：`tools/narrow-audit.php` 按它算出
            //     内容区宽度并逐页验证"装得下"，改小/改大都要重跑那条审计。
            ->setMinSize(self::MIN_WINDOW_WIDTH, self::MIN_WINDOW_HEIGHT)
            // ★ 关闭可拦截：声明之后 `WM_CLOSE` 不再直接销毁 ——
            //   它变成一条 `CloseRequestedEvent`，在帧边界落到
            //   `ShowcaseApplication::onCloseRequested()`。
            ->setCloseGuard(true)
            // ★ UIA 无障碍（§9 / M5）：把语义树接到**本窗**。不接 ⇒ 不建语义树、
            //   零开销。示例台是值型 Pattern 的展示台，与 users 同形。
            ->onSemantics();

        // ★★★ **第二个真实窗口**（M3）：`DRIFT_SECOND_WINDOW=1` 时再建
        //   一个独立工具窗。它不是"再开一个示例台"—— 装的是 `InspectorApplication`
        //   （一块小面板），因为要验的是**基础设施**：两个 `Application` 实例共用
        //   一个 `AppScope`、各自收自己的事件、关一个另一个还在。
        //   ★ 默认不设变量 ⇒ 单窗，与之前逐字节相同。
        //   ★ 槽位名 `inspector` 与主窗的 `main` 不同 ⇒ 几何各存各的。
        if (\getenv('DRIFT_SECOND_WINDOW') === '1') {
            $set->open(
                'inspector',
                420,
                560,
                static function () use ($scope): InspectorApplication {
                    return new InspectorApplication($scope);
                },
                '多窗口 · 检查器',
            )->setMinSize(320, 260);
        }

        $set->run();
    }
}
