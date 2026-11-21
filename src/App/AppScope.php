<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\App\Io\IoPoller;
use Drift\App\Restoration\RestorationBucket;
use Drift\App\Restoration\RestorationStore;
use Drift\Commands\CommandRouter;
use Drift\Errors;
use Drift\Input\AcceleratorTable;
use Drift\Platform\Clipboard;
use Drift\Platform\Dialog;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Text\FontLocator;
use Drift\Text\TextMetricsProvider;
use Drift\Text\TtfFace;
use Drift\Theme\ThemeData;

/**
 * 应用作用域：一个进程一份的东西住这里，一个窗口一份的留在 `Application`。
 * 共享的是全局命令表、主题、度量、后台端口、IO 轮询、加速键表。
 * 每个槽惰性建（构造不解析字体、不建线程池），取值只在本类一处，
 * 默认值即"零配置也能跑"那一套（与 `Application` 原默认一致）。
 * 换后端在组合根显式 `setXxx()`，不在子类覆写（可覆写会留下"两个队列"的静默版本）。
 * @api
 */
final class AppScope
{
    /** 全局命令表（`Application` 的 router 以它为父） */
    private ?CommandRouter $commands = null;

    private ?ThemeData $theme = null;

    private ?TextMetricsProvider $metrics = null;

    private ?AcceleratorTable $accelerators = null;

    /** 后台执行端口（`null` = 本应用不用后台任务；三态由 `$taskHostDecided` 表达） */
    private ?TaskHost $taskHost = null;

    /** 有没有人对 `taskHost` 表过态（`false` ⇒ 取默认 `SyncTaskHost`） */
    private bool $taskHostDecided = false;

    /** 网络图缓存配置；先于 `setTaskHost()` 设置也生效，避免装配顺序静默吃掉配置 */
    private string $imageCacheDir = '';

    private ?\Closure $imageCacheKey = null;

    /** 对话框端口（`null` = 本平台没实现；`Environment` 会据此报 Diagnostic） */
    private ?Dialog $dialog = null;

    /** 剪贴板端口（同上） */
    private ?Clipboard $clipboard = null;

    private ?IoPoller $io = null;

    /** 业务状态恢复（一个进程一份；未装配 ⇒ 不持久化） */
    private ?RestorationStore $restoration = null;

    /** 已登记到本 scope 的窗口数（`enter()` 自增） */
    private int $windows = 0;

    /**
     * 构造只收不可变的那两项（主题 / 加速键表 —— 纯值，给了就定了）。
     * `taskHost` 不进构造器：它有三态（没给 / 给了 null / 给了一个端口），收进
     * `setTaskHost()` 让三态由"调没调过"表达。
     */
    public function __construct(
        ?ThemeData $theme = null,
        ?AcceleratorTable $accelerators = null,
    ) {
        $this->theme = $theme;
        $this->accelerators = $accelerators;
    }

    /** 语义糖：`AppScope::make()` 与 `new AppScope()` 等价 */
    public static function make(): self
    {
        return new self();
    }

    // ── 全局命令表 ──────────────────────────────────────────────────────

    /**
     * 应用级命令表（父表）—— `Application` 构造时以它为父建本窗的 router。
     * $scope->commands()->on('app.quit', new QuitCommand($window));
     * 父链让"注册一次、所有窗口可派发"成立，本窗同名命令就近优先覆盖。
     */
    public function commands(): CommandRouter
    {
        return $this->commands ??= CommandRouter::make();
    }

    // ── 主题 ────────────────────────────────────────────────────────────

    /** 当前主题（未设置 ⇒ `ThemeData::light()`） */
    public function theme(): ThemeData
    {
        return $this->theme ??= ThemeData::light();
    }

    /**
     * 换主题 —— 共享它的每个窗口下一帧都读到新值（`theme()` 是取值口）。
     * 不做广播：平台 `ThemeChangedEvent` 逐窗口投递，本类只负责"值只有一处"。
     */
    public function setTheme(ThemeData $theme): self
    {
        $this->theme = $theme;

        return $this;
    }

    // ── 度量（字体只解析一次）────────────────────────────────────────────

    /**
     * 布局确定性的注入点 —— 整个进程一份。
     * 度量接真字体（`TtfFace` 解析 cmap + hmtx），位图面降级为 `soft` 后端的字形源。
     * `TtfFace` 注册在最前（全字集），位图两面跟在后面服务 TTF 也没有的字。
     * 找不到字体文件 ⇒ 退纯位图（记 Diagnostic，不是静默）。
     * 惰性：构造不碰字体文件，第一次问才解析。
     */
    public function metrics(): TextMetricsProvider
    {
        if ($this->metrics !== null) {
            return $this->metrics;
        }

        $latin = DeterministicFontManager::latinFace();
        $cjk = DeterministicFontManager::cjkFace();

        $manager = new DeterministicFontManager();
        $fontPath = FontLocator::find('NotoSansSC-Regular.ttf');
        $ttf = $fontPath === null ? null : TtfFace::fromFile($fontPath, [$latin, $cjk]);

        if ($ttf !== null) {
            $manager->registerFace($ttf);
        }

        $manager->registerFace($latin);
        $manager->registerFace($cjk);

        $this->metrics = new DeterministicMetricsProvider(
            $manager->collection(),
            new DeterministicShaper(),
        );

        return $this->metrics;
    }

    // ── 加速键 ──────────────────────────────────────────────────────────

    /**
     * 加速键表（路由链第 ① 环）。
     * 可以共享：加速键是"按键组合 → 命令名"的声明式映射，不持有窗口状态；
     * 真正的窗口隔离发生在命令派发（`Application::dispatch()`）。
     */
    public function accelerators(): AcceleratorTable
    {
        return $this->accelerators ??= AcceleratorTable::make();
    }

    public function setAccelerators(AcceleratorTable $accelerators): self
    {
        $this->accelerators = $accelerators;

        return $this;
    }

    // ── 后台端口 ────────────────────────────────────────────────────────

    /**
     * 后台执行端口 —— 一个进程一个池。
     * 未表态 ⇒ `SyncTaskHost`（永远可用，回调在帧内）；表过态 ⇒ 就是那个
     * （`null` 表示本应用不用后台任务 ⇒ `frame()` 整段跳过 drain）。
     */
    public function taskHost(): ?TaskHost
    {
        if (!$this->taskHostDecided) {
            $this->taskHost = new SyncTaskHost();
            $this->taskHostDecided = true;
        }

        return $this->taskHost;
    }

    /**
     * 换后端（组合根一行）：`->setTaskHost(new ProcessTaskHost(4))`。
     * ★ 必须在第一个窗口 `attach()` 之前调 —— 端口在 `attach()` 时被 `PumpHost`
     *   取走并缓存，中途换掉会让"入队 / drain"分属两个队列。
     */
    public function setTaskHost(?TaskHost $taskHost): self
    {
        $this->taskHost = $taskHost;
        $this->taskHostDecided = true;

        // 异步图片跟着后台端口一起装配 —— 组合根不用多写一行，业务也不用注入服务。
        ImageAssets::install($taskHost, $this->imageCacheDir, $this->imageCacheKey);

        return $this;
    }

    /**
     * 网络图缓存：目录 + 签名 URL 的稳定键回调，组合根一行配好。
     *
     * ★ 回调做纯文本变换（剥掉每次都变的签名 query），结果随 Job 参数进 worker；
     *   worker 不用注册同一个回调。与 `setTaskHost()` 的调用顺序无关。
     */
    public function setImageCache(string $cacheDir = '', ?\Closure $cacheKey = null): self
    {
        $this->imageCacheDir = $cacheDir;
        $this->imageCacheKey = $cacheKey;
        ImageAssets::install($this->taskHost, $cacheDir, $cacheKey);

        return $this;
    }

    // ── 平台端口 ────────────────────────────────────────────────────────

    /**
     * 对话框端口 —— 一个进程一份。
     *
     * ★ 不进构造器：它在**建窗之前**由组合根从 `Platform::dialog()` 取，而
     *   scope 可能在更早的地方就被 `AppScope::make()` 建好了。三态（没给 /
     *   给了 null / 给了端口）与 `taskHost` 同一个形状，用"调没调过"表达。
     * ★ `null` 不是错误：本平台确实可能没有对话框（X11 的 zenity 缺席），
     *   `Environment` 在第一个消费者出现时才留 Diagnostic。
     */
    public function dialog(): ?Dialog
    {
        return $this->dialog;
    }

    public function setDialog(?Dialog $dialog): self
    {
        $this->dialog = $dialog;

        return $this;
    }

    /** 剪贴板端口 —— 一个进程一份（`CF_UNICODETEXT` 是全局的，分窗口没有意义） */
    public function clipboard(): ?Clipboard
    {
        return $this->clipboard;
    }

    public function setClipboard(?Clipboard $clipboard): self
    {
        $this->clipboard = $clipboard;

        return $this;
    }

    // ── 非阻塞 IO ───────────────────────────────────────────────────────

    /**
     * 非阻塞 I/O 轮询器 —— 一个进程一个，监听表只有一份。
     * ★ 不共享就是错：两个 `IoPoller` 各自 watch 同一个 fd ⇒ 同一次可读派发两次回调。
     */
    public function io(): IoPoller
    {
        return $this->io ??= new IoPoller();
    }

    // ── 业务状态恢复 ────────────────────────────────────────────────────

    /** 组合根在建应用之前装配；store 构造即恢复，应用构造时读 bucket 已有值。 */
    public function setRestoration(RestorationStore $store): self
    {
        $this->restoration = $store;

        return $this;
    }

    /** 应用侧唯一入口：显式 DI，不做向上查找（§9.1 否决 `BuildContext::of()`）。 */
    public function restoration(): ?RestorationBucket
    {
        return $this->restoration?->bucket();
    }

    /** @internal `WindowSet` 在退出时取它写回一次 */
    public function restorationStore(): ?RestorationStore
    {
        return $this->restoration;
    }

    // ── 窗口登记（主题一致性检查的唯一理由）──────────────────────────────

    /**
     * 登记一个窗口（`Application::attach()` 调一次）。
     * ★ 第一个窗口把它的主题灌进 scope，后续窗口必须与之一致，不一致报 `DIAGNOSTIC`。
     *   守的是"一半深色一半浅色"：某窗口覆写 `theme()` 而别的没覆写就会发生。
     * ★ 只在 `attach()` 检查一次：`theme()` 在帧内被读多次，把检查放进去是热路径。
     * ★ 用 `!=` 而非 `!==`：`ThemeData` 是值对象，两个 `ThemeData::light()` 是
     *   两个实例但同一主题，按实例比较会误报不一致。
     * @return int 本 scope 已登记的窗口数（含本次，`1` = 第一个）
     */
    public function enter(ThemeData $theme): int
    {
        if (++$this->windows === 1) {
            // ??=：装配处显式给主题时以它为准，第一个窗口只兜底（不覆盖显式决定）。
            $this->theme ??= $theme;

            return $this->windows;
        }

        if ($this->theme != $theme) {
            Errors::report(sprintf(
                'AppScope：第 %d 个窗口的 theme() 与共享主题不一致 —— 多窗口请把主题放进 AppScope，不要覆写 Application::theme()',
                $this->windows,
            ), Errors::DIAGNOSTIC);
        }

        return $this->windows;
    }

    /** 已登记的窗口数 */
    public function windows(): int
    {
        return $this->windows;
    }
}
