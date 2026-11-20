<?php
declare(strict_types=1);

namespace App\Showcase;

use Drift\App\AppScope;
use Drift\App\ProcessTaskHost;
use Drift\App\ProcessWorker;
use Drift\App\WindowSet;
use Drift\Platform\Win32\Win32Platform;
use Drift\Theme\ThemeData;

use App\Showcase\Jobs\FetchRowsJob;
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
final class Bootstrap
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

    public static function run(): void
    {
        // ★★ worker 分支：**同一个 exe**，靠参数分流（与 `apps/users` 同形）。
        //   worker 只跑 `ProcessWorker::serve()`（读 stdin、跑任务、回 stdout），
        //   **不进消息循环、不建窗口、不碰 UI** ⇒ "业务与 UI 隔离"的物质基础。
        //   ★ `$argv` 在 tpc 编译期被拒 ⇒ 必须走 `$_SERVER['argv']`（`apps/users` 的注释）。
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

            return; // stdin 关闭 = UI 进程走了 ⇒ 正常收工
        }

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
        //   这里换成常驻子进程池（与 `apps/users` 同形）。
        //   ★ `$argv[0]` = 本 exe 路径（AOT 下由启动器给）；空则退化为同步，不静默崩。
        //   ★ 必须在**第一个窗口 attach() 之前**设置：端口在 attach 时被 `PumpHost`
        //     取走并缓存（中途换掉会让"入队 / drain"分属两个队列）。
        $exe = (string) ($argv[0] ?? '');
        if ($exe !== '') {
            // ★★ **池容量 3**：这是「并发取数」页真正并行的前置条件。
            //   `maxWorkers` 是**组合根的决定**，不是页面能提的要求 —— 一个 worker
            //   是一整套 AOT 运行时（几十 MB），"开几个"只有应用知道。
            //
            //   为什么是 3 而不是 5：
            //   - 那页要同时取 3 页数据，3 是最小的"够用"值；
            //   - 每个并发槽 = 一个常驻子进程。5 个在 8GB 机器上就是"开一个页面
            //     崩一次内存"的写法，而收益只是少等几秒（`ASYNC-ARCH-2026-10-31.md` §15）；
            //   - 池是**懒扩张**的（`prewarm()` 只发 1 个）：不点那页时，进程数与原来
            //     完全一样，第 2、3 个只在真有第二条任务同时在途时才 spawn。
            //
            //   ★ 代价要记住：满载时 UI 进程 + 3 个 worker ≈ 100 MB + 3×31 MB。
            //     其余 33 页全是单任务，永远只用到第一个 worker。
            $host = new ProcessTaskHost([$exe, '--drift-worker'], JobRegistry::make(), 3);

            // ★ 预热：把首条任务的 275ms 进程启动挪到建窗之前。
            //   见 `ProcessTaskHost::prewarm()` —— 它只发起进程，不等就绪。
            $host->prewarm();

            $scope->setTaskHost($host);
        }

        // ★ 数据源模式：默认本地慢任务（开箱即用，零依赖）；
        //   `DRIFT_SHOWCASE_HTTP=1` ⇒ 真 HTTP（先起 `apps/showcase/mock-server.php`）。
        $rowsMode = \getenv('DRIFT_SHOWCASE_HTTP') === '1'
            ? FetchRowsJob::MODE_HTTP
            : FetchRowsJob::MODE_LOCAL;

        // ★ 对话框 / 剪贴板端口从**这里**递进应用（框架不持平台，§5.1）：
        //   示例台是 `Ctrl+C/X/V` 与「对话框」页的唯一消费者。
        //   递的是**端口**不是平台实例 —— Win32 实现仍只出现在本文件。
        $main = $set->open(
            'main',
            self::WINDOW_WIDTH,
            self::WINDOW_HEIGHT,
            static function () use ($platform, $scope, $rowsMode): ShowcaseApplication {
                return new ShowcaseApplication($platform->dialog(), $platform->clipboard(), $scope, $rowsMode);
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
