<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Errors;
use Drift\Platform\Platform;
use Drift\Platform\PlatformHooks;

/**
 * **组合根的窗口表**：建窗 → 还原几何 → 定表面 → `attach` → 登记，最后交给 `EventLoop`。
 * 三条"漏掉即静默"的契约：还原几何必须在 `resize()` 之前；`resize()` 用读回的客户区
 * 而非常量；`attach()` 前表面尺寸必须非 0（否则首帧被丢弃）。
 * 进程级静态钩子（fatal box / resize pump）由平台 `hooks()` 端口提供，本类构造时装、
 * `run()` 的 `finally` 卸 —— 捕获的闭包持有整条应用图，不卸 = 关窗必崩。
 * 次序固定：createWindow → makeApp+beforeRestore → restore → surface →
 * beforeAttach → resize(实际客户区) → attach(内含首帧) → **show**。
 * ★ `show()` 排在 `attach()` **之后**：首帧没上屏就显示，客户区是未绘制的空白
 *   （自绘窗口无系统背景刷）⇒ 用户看到"黑窗闪一下才出内容"。
 * @api 组合根一行：`(new WindowSet($platform, AppPaths::forApp($platform, 'app')))->open(...)->run()`
 */
final class WindowSet
{
    /** @var array<int, Window> 键 = 句柄。**只增不减**：关掉的窗口也要 persist */
    private array $windows = [];

    private readonly PlatformHooks $hooks;

    private readonly string $statePath;

    /**
     * @param Platform $platform 任意平台实现（本类不认识 Win32）
     * @param AppPaths $paths 应用专属配置路径：窗口几何落在它的 `windowState()`；`''` = 不持久化。
     *   应用身份在这里只声明一次，本类不再要组合根手拼 `configDir()` + 文件名。
     * @param null|\Closure():void $release 循环与持久化之后调一次：**额外的业务级清理**。
     *   进程级静态钩子不再走这里 —— 平台 `hooks()` 自己装、自己卸，组合根不必再传卸载闭包。
     */
    public function __construct(
        private readonly Platform $platform,
        AppPaths $paths,
        private readonly ?\Closure $release = null,
    ) {
        $this->statePath = $paths->windowState();
        $this->hooks = $platform->hooks();
        $this->hooks->install();
        $this->hooks->setResizePump(function (int $windowHandle): void {
            $this->repaintForResize($windowHandle);
        });
    }

    /**
     * 建一个窗口、还原它的几何、装一个应用，登记进表。
     * @param string $slot 窗口槽位名 —— **跨运行稳定**（写进状态文件），与句柄无关；
     *   两个窗口用同一槽位 ⇒ 后写覆盖先写（下次启动只有一个还原，**不报错**）。
     * @param \Closure(): Application $makeApp 造这个窗口的应用。**不传句柄**：`Application` 构造不该知道窗口。
     * @param string $title 窗口标题；**空串（默认）⇒ 用 `$app->title()`**，
     *   末尾这个实参只是窗口级覆盖（同应用开两窗要不同标题时才有用）。
     * @param null|\Closure(Window):void $beforeRestore 建窗之后、还原几何之前（无边框改客户区，须在还原前；此时 `surface()` 为 null）
     * @param null|\Closure(Window):void $beforeAttach 表面就绪之后、首帧之前（材质/底色 —— 否则先闪默认外观）
     */
    public function open(
        string $slot,
        int $width,
        int $height,
        \Closure $makeApp,
        string $title = '',
        ?\Closure $beforeRestore = null,
        ?\Closure $beforeAttach = null,
    ): Window {
        $host = $this->platform->window();

        // 先造应用、再建窗：标题要在 createWindow() 那一刻定下，建完再 setTitle() 会先闪默认标题；
        // 应用不依赖句柄，提前造无副作用。
        $app = $makeApp();
        if (!$app instanceof Application) {
            // 闭包返回错类型时，现在还没建窗口就报 Fatal（原先窗口已建出、还 show 了）。
            Errors::report('WindowSet：makeApp 没有返回 Application 实例');
        }

        // 空串 = 没给（默认值）：直接拿去建窗会得到无标题窗口，统一回落到应用的 title()。
        if ($title === '') {
            $title = $app->title();
        }

        $handle = $host->createWindow($title, $width, $height);

        $window = new Window($handle, $app, $host);

        if ($beforeRestore !== null) {
            $beforeRestore($window);
        }

        $state = new WindowStateStore($this->statePath, $slot);
        $state->restore($host, $handle);

        $provider = $this->platform->surface($handle);
        if ($provider === null) {
            Errors::report('Win32: 平台没给出渲染表面（窗口句柄无效）');

            return $window;   // 不可达：`report()` 走中止序列，不返回
        }

        $screen = $this->platform->screen($handle);
        $surface = $provider->surface();
        $window->mount($surface, $screen, $state);

        if ($beforeAttach !== null) {
            $beforeAttach($window);
        }

        // ★ 读回的**实际**客户区，不是 `$width/$height` 常量：还原之后尺寸多半已变。
        //   照常量排 ⇒ 还原了窗口、画面还按旧尺寸排（四周拉伸，不报错）。
        $actual = $host->clientSize($handle);
        $dpr = $screen->scale();
        $surface->resize(
            (int) round($actual->width * $dpr),
            (int) round($actual->height * $dpr),
            $dpr,
        );

        $app->attach($surface, $screen->viewport());

        // ★ **首帧之后才显示窗口**（2026-11-19）：建窗时窗口类 `hbrBackground = NULL`
        //   （自绘），客户区在第一次 present 之前是**未绘制**的 —— DWM 没有内容可合成，
        //   用户看到的就是"黑窗闪一下再出内容"。把 `show()` 挪到 `attach()`（内含首帧）
        //   之后，`ShowWindow` + `UpdateWindow` 触发的 `WM_PAINT` 直接从帧缓存
        //   （`WindowSlot::frame`）贴出内容，窗口一出现就是画好的。
        //   ★ 隐藏窗口上 `present()` 的贴屏结果可能被丢弃，但**帧缓存一定留下来了** ——
        //   这正是"先 attach 后 show"能成立的前提，别把 present 当成唯一的落点。
        $host->show($handle);

        $this->windows[$handle] = $window;

        return $window;
    }

    /**
     * 跑主循环，然后**必然**做两件收尾：逐窗写回几何、卸静态钩子。
     * 用 `finally`：`EventLoop::run()` 抛异常时几何仍要写回，钩子更必须卸 —— 否则崩溃变"崩两次"。
     * ★ persist 遍历 `$windows` 而非循环里剩下的表：**已关的窗口也要存**
     *   （`WindowHost::position()` 对死句柄回填"最后已知几何"）；不为它写就等于什么都不记。
     */
    public function run(): void
    {
        $apps = [];
        foreach ($this->windows as $handle => $window) {
            $apps[$handle] = $window->app();
        }

        try {
            EventLoop::run($this->platform, $apps);
        } finally {
            $host = $this->platform->window();
            $restoration = null;
            foreach ($this->windows as $handle => $window) {
                $window->state()?->persist($host, $handle);
                // 进程一份：多窗口共享同一个 AppScope ⇒ 取到的一定是同一个 store
                $restoration ??= $window->app()->scope()->restorationStore();
            }
            $restoration?->persist();

            // 清表 + 强制回收：组合根的树是环（应用→Element→Widget→闭包→应用），只能靠 GC；
            // 不主动放手，AOT 在 PHP shutdown 解引用已销毁类元数据会崩。单窗口也做（一次 GC 便宜）。
            $this->windows = [];
            \gc_collect_cycles();

            if ($this->release !== null) {
                ($this->release)();
            }
            $this->hooks->release();
        }
    }

    /**
     * 拖动边缘时的轻量重绘（**按句柄分发**）—— 壳的 `drift_resize_hook_pump()` 入口。
     * 接线在构造时由平台 `hooks()->setResizePump()` 完成，本类不认识具体平台；
     * 句柄不在表里 ⇒ 静默跳过：该窗口不是本组合根建的（或已销毁）。
     * ★ **不能**用"客户区 0×0"判活 —— 最小化也是 0×0，那是合法状态。
     */
    public function repaintForResize(int $windowHandle): void
    {
        $window = $this->windows[$windowHandle] ?? null;
        if ($window === null) {
            return;
        }

        $surface = $window->surface();
        $screen = $window->screen();
        if ($surface === null || $screen === null) {
            return;
        }

        $dpr = $screen->scale();
        $vp = $screen->viewport();
        $surface->resize(
            (int) round($vp->maxWidth * $dpr),
            (int) round($vp->maxHeight * $dpr),
            $dpr,
        );
        $window->app()->repaintForResize($vp);
    }

    /** @return array<int, Window> 键 = 句柄（供探针 / 诊断遍历；`EventLoop` 用 `run()`） */
    public function windows(): array
    {
        return $this->windows;
    }
}
