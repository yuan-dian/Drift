<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\App\Io\IoPoller;
use Drift\Commands\CommandRouter;
use Drift\Errors;
use Drift\Input\AcceleratorTable;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Text\FontLocator;
use Drift\Text\TextMetricsProvider;
use Drift\Text\TtfFace;
use Drift\Theme\ThemeData;

/**
 * **应用作用域**（M2）：一个进程一份的东西住在这里，一个窗口一份的东西留在
 * `Application`。
 *
 * ```text
 * AppScope（进程一份）              Application（每窗一份）
 * ├─ CommandRouter（全局命令，父）   ├─ CommandRouter（本窗命令，子）
 * ├─ ThemeData                      ├─ Element $root / Frame
 * ├─ TextMetricsProvider            ├─ FocusManager
 * ├─ TaskHost                       ├─ OverlayManager + OverlayLayout
 * ├─ IoPoller                       ├─ InputRouter / ScrollInputRouter
 * └─ AcceleratorTable               └─ TextEditingBridge / PumpHost
 * ```
 *
 * ## 为什么必须有这一层（不是"顺手抽个类"）
 *
 * 六项里**五项必须共享**（不共享就是错或浪费），一项**可以共享**（加速键）：
 * 前五项的失败形态全是静默的 ——
 *
 * | 项 | 每窗口一份的后果 |
 * |---|---|
 * | `metrics()` | `TtfFace::fromFile()` 解析 11MB 字体 + 两套位图面 ⇒ 两窗两份字形缓存 |
 * | `taskHost()` | `ProcessTaskHost(4)` 变成两个 4 线程池 ⇒ UI 每帧只 drain 其中一个 |
 * | `io()` | 两个 `IoPoller` watch 同一批 fd ⇒ **同一次可读回调跑两次**（下单付两次款那种） |
 * | `theme()` | 切主题要逐个通知，漏一个就是"一半深色一半浅色" |
 * | `commands()` | "退出应用"这类全局命令无处安放（每窗各注册一次 ⇒ 重复注册 Fatal） |
 *
 * ★ `taskHost` 那条**已经在真机上演过一次**：`apps/users` 第一版让 `UserStore`
 *   拿注入的 `ProcessTaskHost`、而 `frame()` 用默认新建的 `SyncTaskHost` drain
 *   —— 两个实例两套队列，worker 早回了结果、UI 永远 Loading，**不报错**。
 *   ⇒ 端口从"谁碰巧建了它"改成"装配处显式给一次"，这类 bug 才在结构上消失。
 *
 * ## 惰性，但不是"第一次问是谁就问谁"
 *
 * 每个槽都是 `??=` 惰性建（构造 `AppScope` 不解析字体、不建线程池），但**取值
 * 只有一处**（本类），所以"第一个窗口碰巧说了算"这种隐式规则不存在 ——
 * 要么组合根显式 `setXxx()`，要么取默认。
 *
 * ★ **默认值是"零配置也能跑"的那一套**：`ThemeData::light()` / 空加速键表 /
 *   `SyncTaskHost`（同步后端，永远可用）/ 一个空 `IoPoller`。这与 `Application`
 *   原来的默认值逐条相同 ⇒ 单窗口应用换用共享 scope 后**行为不变**。
 *
 * ## 破坏面（2026-10-30 裁决）
 *
 * `Application::taskHost()` 从"应用扩展点"变成 `final` 委托 ⇒ 子类再覆写它是
 * **加载期致命错误**（`Cannot override final method`）。这是有意的：留着可覆写
 * 就等于留着"两个队列"的静默版本。换后端改成装配处给：
 *
 * ```php
 * $scope = AppScope::make()->setTaskHost(new ProcessTaskHost(4));
 * $app = new UsersApplication(new UserSource(), $scope);
 * ```
 *
 * @api
 */
final class AppScope
{
    /** 全局命令表（`Application` 的 router 以它为父，见 `CommandRouter::make()`） */
    private ?CommandRouter $commands = null;

    private ?ThemeData $theme = null;

    private ?TextMetricsProvider $metrics = null;

    private ?AcceleratorTable $accelerators = null;

    /** 后台执行端口（`null` = 本应用不用后台任务；三态由 `$taskHostDecided` 表达） */
    private ?TaskHost $taskHost = null;

    /** 有没有人对 `taskHost` 表过态（`false` ⇒ 取默认 `SyncTaskHost`） */
    private bool $taskHostDecided = false;

    private ?IoPoller $io = null;

    /** 已登记到本 scope 的窗口数（`enter()` 自增） */
    private int $windows = 0;

    /**
     * 构造只收**不可变的那两项**（主题 / 加速键表 —— 纯值，给了就定了）。
     *
     * ★ `taskHost` **不进构造器**：它有三态（没给 / 给了 null / 给了一个端口），
     *   而 PHP 的默认参数分不出"没给"和"给了 null"，硬凑就要哨兵值或
     *   `func_num_args()`。收进 `setTaskHost()` 让三态由"调没调过"表达，签名也干净。
     *
     * ```php
     * $scope = new AppScope(theme: ThemeData::dark());
     * ```
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
     * 应用级命令表（**父表**）—— `Application` 构造时以它为父建本窗的 router。
     *
     * ```php
     * $scope->commands()->on('app.quit', new QuitCommand($window));
     * ```
     *
     * ★ 为什么是**父链**而不是"每窗各注册一份"：重复注册会命中
     *   `CommandRouter::on()` 的 G2 检查（同名静默覆盖 ⇒ Fatal），而"退出应用"
     *   这种命令**天然要被每个窗口看到**。父链让"注册一次、所有窗口可派发"成立，
     *   本窗同名命令仍然覆盖它（就近优先，与冒泡链同一形状）。
     */
    public function commands(): CommandRouter
    {
        return $this->commands ??= CommandRouter::make();
    }

    // ── 主题 ────────────────────────────────────────────────────────────

    /** 当前主题（未设置 ⇒ `ThemeData::light()`，与 `Application::theme()` 原默认一致） */
    public function theme(): ThemeData
    {
        return $this->theme ??= ThemeData::light();
    }

    /**
     * 换主题 —— **共享它的每个窗口下一帧都读到新值**（`theme()` 是取值口）。
     *
     * ★ **不做广播**（2026-10-30 裁决）：平台的 `ThemeChangedEvent` 本来就是
     *   **逐窗口投递**的（`Win32Platform::pump()` 按 hwnd 分发，M1 落地后成立）
     *   ⇒ 通知由平台负责，本类只负责"值只有一处"。自己再维护一张监听表只会
     *   多一份"窗口关了要摘监听器"的生命周期负担，而多窗口的窗口表在 M3 才存在。
     * ★ 单窗口下这条也就等于"应用自己换主题值"：`applyNewContext()` 读的是
     *   `theme()`，下一次 `ThemeChangedEvent`（或显式 `applyNewContext`）后生效。
     */
    public function setTheme(ThemeData $theme): self
    {
        $this->theme = $theme;

        return $this;
    }

    // ── 度量（字体只解析一次）────────────────────────────────────────────

    /**
     * 布局确定性的注入点（§14.2）：**整个进程一份**。
     *
     * ## 2026-10-17 的裁决原样搬来这里（"真字体优先，位图作字形源"）
     *
     * 位图字模只覆盖生成器扫到的那批字（864），表外字符退 `NotdefFontFace`
     * 按宽/窄猜 1.0 / 0.5 em —— 而 `skia` 后端的字形来自真 TTF（全字集）。
     * 于是"画得出、宽度是猜的"：长句错位、换行点算错。现在**度量**接真字体
     * （`TtfFace` 解析 cmap + hmtx），位图面降级为 `soft` 后端的**字形源**。
     *
     * ★ 顺序要紧：`TtfFace` 注册在**最前**（它 `hasGlyph()` 查真字体 cmap ⇒
     *   全字集），位图两面跟在后面只服务"TTF 也没有的字"和 `soft` 的字形委托。
     * ★ 找不到字体文件 ⇒ 退纯位图（**降级留痕**：`TtfFace::fromFile()` 记
     *   Diagnostic；不是静默 —— 现象会是"表外字宽度不对"，那条日志是唯一线索）。
     * ★ 惰性：构造 `AppScope` 不碰字体文件；第一次问才解析（11MB 那一读）。
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
     * 加速键表（§9.1 路由链第 ① 环）。
     *
     * ★ **可以共享的理由**：加速键是"按键组合 → 命令名"的**声明式映射**，
     *   不持有窗口状态。真正的窗口隔离发生在下一环 —— 命令派发走
     *   `Application::dispatch()`（本窗树 → 本窗 router → 父链）。
     *   ⇒ 同一个 `Ctrl+S` 在两个窗口里各自落到**自己的** handler。
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
     * 后台执行端口（§17.2）—— **一个进程一个池**。
     *
     * ★ 未表态 ⇒ `SyncTaskHost`（永远可用，回调在帧内）；表过态 ⇒ 就是那个
     *   （`null` 表示本应用不用后台任务 ⇒ `frame()` 整段跳过 drain）。
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
     *
     * ★ 必须在**第一个窗口 `attach()` 之前**调 —— 端口在 `attach()` 时被
     *   `PumpHost` 取走并缓存（它持有结果队列，中途换掉会让"入队 / drain"
     *   分属两个队列，正是本类要消灭的那类 bug）。
     */
    public function setTaskHost(?TaskHost $taskHost): self
    {
        $this->taskHost = $taskHost;
        $this->taskHostDecided = true;

        return $this;
    }

    // ── 非阻塞 IO ───────────────────────────────────────────────────────

    /**
     * 非阻塞 I/O 轮询器（§17.4.4）—— **一个进程一个，监听表只有一份**。
     *
     * ★ 这一条是五项里**唯一"不共享就是错"**的（其余四项是浪费或需要广播）：
     *   两个 `IoPoller` 各自 watch 同一个 fd ⇒ 同一次可读派发两次回调。
     * ★ 多窗口下的已知代价（记在这里，别当新发现）：`hasAsync()` 读的是
     *   本轮询器的全局监听数 ⇒ A 窗有 socket 时 B 窗的帧时钟也保持上膛
     *   （**保守多跳一帧**，内容逐像素不变）。与四个全局脏计数器同一口径：
     *   宁多不少，分片是 M3 的债。
     */
    public function io(): IoPoller
    {
        return $this->io ??= new IoPoller();
    }

    // ── 窗口登记（主题一致性检查的唯一理由）──────────────────────────────

    /**
     * 登记一个窗口（`Application::attach()` 调一次）。
     *
     * ★ 做一件事：**第一个窗口把它的主题灌进 scope，后续窗口必须与之一致**，
     *   不一致报 `DIAGNOSTIC`（不改行为 —— 两个窗口各画各的，但日志里留痕）。
     *   这条守的正是"一半深色一半浅色"那个失败形态：它能发生，只是因为
     *   某个窗口覆写了 `theme()` 而别的没覆写。
     *
     * ★ 为什么只在 `attach()` 检查一次：`theme()` 在帧内会被读多次（焦点环、
     *   浮层），把检查放进去就是热路径上的字符串拼接。
     *
     * ★ 为什么用 `!=` 而不是 `!==`：`ThemeData` 是**值对象**（颜色表 + 度量表），
     *   两个窗口各自 `ThemeData::light()` 出来的是两个实例但同一个主题 ——
     *   按实例比较会把"完全一致"误报成不一致。
     *
     * @return int 本 scope 已登记的窗口数（含本次，`1` = 第一个）
     */
    public function enter(ThemeData $theme): int
    {
        if (++$this->windows === 1) {
            // ★ `??=`：装配处显式 `new AppScope(theme: ...)` 时以它为准，
            //   第一个窗口只是**兜底**（不覆盖显式决定）。
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

    /** 已登记的窗口数（M3 的 `map<HWND, WindowRuntime>` 会接管这件事） */
    public function windows(): int
    {
        return $this->windows;
    }
}
