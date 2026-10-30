<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Errors;
use Drift\Platform\Platform;

/**
 * **组合根的窗口表**：建窗 → 还原几何 → 定表面 → `attach` → 登记，最后交给 `EventLoop`。
 *
 * ## 为什么有它（不是"把 30 行搬进一个类"）
 *
 * 四个组合根里的那段代码是**逐行相同**的（`tutorial` 的注释甚至写着"抄的时候整段抄"）。
 * 而它里面有三条"漏掉即静默"的契约：
 *
 * | 契约 | 漏掉的后果（都不报错） |
 * |---|---|
 * | 还原几何必须在 `surface->resize()` **之前** | 先看到一帧默认尺寸再被拽过去 |
 * | `resize()` 必须用**读回的**客户区，不能用常量 | 还原了窗口、画面仍按旧尺寸排 ⇒ 四周拉伸/裁切 |
 * | `attach()` 之前表面尺寸必须非 0 | 首帧被丢弃 —— 画面到第二次 resize 才出现 |
 *
 * 再加一条**致命的**：`ResizeHookState::$pump` 与 `Errors::installFatalBox()` 是
 * 两个静态钩子，捕获的闭包持有整条应用对象图。**不卸 = 对象活到 PHP shutdown
 * = AOT 解引用已销毁的类元数据 = 关窗必崩**（实测 8/8）。这条纪律写在四个文件里
 * 各一遍，漏一处就复发 —— 所以它进 `run()` 的 `finally`（见 `$release`）。
 *
 * ## 次序不可换
 *
 * ```text
 * ① createWindow + show
 * ② makeApp + $beforeRestore      ← frameless 会改客户区，必须在还原之前
 * ③ 状态还原 restore
 * ④ surface + screen
 * ⑤ $beforeAttach                 ← 材质 / 底色要在首帧之前
 * ⑥ resize(实际客户区)
 * ⑦ attach                        ← 内含首帧
 * ```
 *
 * ## 这一阶**只做启动期登记**
 *
 * 运行中开窗（点按钮弹出新窗口）需要"在帧边界执行"，否则 `attach()` 会在
 * 某条事件回调的中间跑一整帧（重入）。那归 `Inbox`（事件循环册 N4），
 * 本类不预先发明 —— 未消费的分支 = 未实现。
 *
 * @api 组合根一行：`(new WindowSet($platform, 'app.window'))->open(...)->run()`
 */
final class WindowSet
{
    /** @var array<int, Window> 键 = 句柄。**只增不减**：关掉的窗口也要 persist */
    private array $windows = [];

    /**
     * @param Platform $platform 任意平台实现（本类不认识 Win32）
     * @param string $statePath 窗口状态文件的**完整路径**（`Paths::userConfig()` 的结果）；
     *   `''` = 不持久化。为什么不收文件名：路径由平台给，而本类不做平台查询 ——
     *   "文件放哪"是组合根的决定，且收完整路径让测试能指向临时目录。
     * @param null|\Closure():void $release 循环与持久化之后调一次：**卸载组合根装过的
     *   进程级静态钩子**。为什么不放进 `open()`：装可以分散（要尽早），卸必须集中在
     *   最末尾，且只有一处 —— 忘一处就是 AOT 关窗必崩。放构造参数而不是让组合根
     *   在 `run()` 之后自己写，是为了让它**不可能**被异常路径跳过（`finally`）。
     */
    public function __construct(
        private readonly Platform $platform,
        private readonly string $statePath,
        private readonly ?\Closure $release = null,
    ) {
    }

    /**
     * 建一个窗口、还原它的几何、装一个应用，登记进表。
     *
     * @param string $slot 窗口槽位名 —— **跨运行稳定**（写进状态文件），与句柄无关。
     *   两个窗口用同一个槽位 ⇒ 后写的覆盖先写的（下次启动只有一个还原，**不报错**）。
     * @param \Closure(): Application $makeApp 造这个窗口的应用。**不传句柄**：
     *   `Application` 构造不该知道窗口（它拿不到，也不需要）。
     * @param null|\Closure(Window):void $beforeRestore 建窗之后、还原几何之前
     *   （无边框会改变客户区，必须在还原之前；此时 `surface()` 还是 null）
     * @param null|\Closure(Window):void $beforeAttach 表面就绪之后、首帧之前
     *   （材质 / 底色 —— 否则先闪一下默认外观）
     */
    public function open(
        string $slot,
        string $title,
        int $width,
        int $height,
        \Closure $makeApp,
        ?\Closure $beforeRestore = null,
        ?\Closure $beforeAttach = null,
    ): Window {
        $host = $this->platform->window();
        $handle = $host->createWindow($title, $width, $height);
        $host->show($handle);

        $app = $makeApp();
        if (!$app instanceof Application) {
            // 闭包返回错类型 ⇒ 后面每一次 `attach()` 都是"读未初始化字段"式的
            // 半崩溃。当场报 Fatal 比让它晚 300 行崩在别处划算。
            Errors::report('WindowSet：makeApp 没有返回 Application 实例');
        }

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

        // ★ 读回的**实际**客户区，不是 `$width/$height` 常量：还原之后尺寸多半
        //   已经变了。照常量排 ⇒ 还原了窗口、画面还按旧尺寸排（四周拉伸，不报错）。
        $actual = $host->clientSize($handle);
        $dpr = $screen->scale();
        $surface->resize(
            (int) round($actual->width * $dpr),
            (int) round($actual->height * $dpr),
            $dpr,
        );

        $app->attach($surface, $screen->viewport());

        $this->windows[$handle] = $window;

        return $window;
    }

    /**
     * 跑主循环，然后**必然**做两件收尾：逐窗写回几何、卸静态钩子。
     *
     * ★ 用 `finally`：`EventLoop::run()` 抛异常时几何仍要写回（用户调过的窗口大小
     *   不该因为一个崩溃就丢），钩子更必须卸 —— 否则崩溃变成"崩两次"。
     *
     * ★ persist 遍历 `$windows` 而不是循环里剩下的表：**已关的窗口也要存**
     *   （`WindowHost::position()` 对死句柄回填"最后已知几何"）。用户点 X 关窗
     *   是常态，不为它写就等于什么都不记。
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
            foreach ($this->windows as $handle => $window) {
                $window->state()?->persist($host, $handle);
            }

            // ★★ **清表 + 回收环**（2026-10-30）：组合根的树不是引用计数的树 ——
            //   它是**环**。`ShowcaseApplication` 把 `notify()` / `after()` / `showModal()`
            //   三个闭包 `use ($this)` 递给根部件 ⇒ 应用 → 根 Element → Widget →
            //   闭包 → 应用。环只能靠 GC，回收时机不确定。
            //   AOT 产物在 PHP shutdown 段释放 Zend 堆时，若图还活着就会解引用
            //   已销毁的类元数据 ⇒ `php8ts.dll+0x24b30` 访问冲突（窗口早关完、
            //   状态也写完，最后一步崩）。
            //   ⇒ 在这里主动放手：清表（去掉 `WindowSet` 这一条根）+ 强制回收。
            //   单窗口也做 —— 它便宜（一次 GC），而"有时崩有时不崩"取决于 GC 阈值
            //   恰好有没有到，那种复现条件不可接受。
            $this->windows = [];
            \gc_collect_cycles();

            if ($this->release !== null) {
                ($this->release)();
            }
        }
    }

    /**
     * 拖动边缘时的轻量重绘（**按句柄分发**）—— 壳的 `drift_resize_hook_pump()` 入口。
     *
     * ```php
     * ResizeHookState::$pump = static function (int $h) use ($set): void { $set->repaintForResize($h); };
     * ```
     *
     * ★ 组合根必须自己接这一行（`ResizeHookState` 是 Win32 的静态槽，`src/App`
     *   不许引用平台实现）。逻辑在这里，接线在组合根 —— 与本类不收 `Win32Platform`
     *   是同一条边界。
     * ★ 句柄不在表里 ⇒ 静默跳过：该窗口不是本组合根建的（或已销毁）。
     *   ★ **不能**用"客户区 0×0"判活 —— 最小化也是 0×0，那是合法状态。
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
