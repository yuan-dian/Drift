<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\App\Io\IoPoller;
use Drift\Async\Future;
use Drift\Commands\CommandArg;
use Drift\Commands\CommandHandler;
use Drift\Commands\CommandRouter;
use Drift\Core\Pipeline;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Input\AcceleratorTable;
use Drift\Input\CursorShape;
use Drift\Input\InputEvent;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Input\ScrollEvent;
use Drift\Input\SemanticsInvokeEvent;
use Drift\Input\SemanticsSetValueEvent;
use Drift\Input\TapTracker;
use Drift\Input\ThemeChangedEvent;
use Drift\Input\WindowDeactivatedEvent;
use Drift\Platform\Clipboard;
use Drift\Layout\RenderBox;
use Drift\Layout\LayoutTolerance;
use Drift\Layout\RenderScrollView;
use Drift\Rendering\Canvas;
use Drift\Rendering\ColorToken;
use Drift\Rendering\FrameStage;
use Drift\Rendering\Paint;
use Drift\Rendering\RenderSurface;
use Drift\Rendering\SurfaceProfile;
use Drift\Text\TextMetricsProvider;
use Drift\Widget\Scrollable;
use Drift\Widget\SemanticsValueEditable;
use Drift\Theme\ThemeData;
use Drift\Widget\BuildContext;
use Drift\Widget\Drag\Draggable;
use Drift\Widget\Element;
use Drift\Widget\Modal;
use Drift\Widget\State;
use Drift\Widget\States;
use Drift\Widget\Widget;
use Drift\Widget\WindowSize;
use Drift\Widget\SemanticsBuilder;
use Drift\Widget\Snackbar;

/**
 * 组合根：应用只写 `buildRoot()` / `title()` / `commands()`。
 *
 * 持有根 Element 与一帧的编排器，注册命令，`frame()` 供平台驱动一帧。
 * 事件循环与渲染表面都由平台注入，故无 Win32 / Skia 也能跑完整应用。
 *
 * @api
 */
abstract class Application implements TextEditingHost, InputHost, OverlayHost, PumpHostHost
{
    protected ?Element $root = null;

    protected ?Frame $frame = null;

    /** 焦点链宿主。焦点是渲染态、只能有一份，故由本类持有而不外部注入。 */
    protected ?FocusManager $focus = null;

    /** 输入模态：`true` = 最近一次是键盘（`:focus-visible` 的依据，初值 false）。 */
    private bool $keyboardFocusVisible = false;

    /** 上一次真的画上屏的环目标（`null` = 上次没画） */
    private ?Element $paintedFocus = null;

    /** UI 线程 id（`GetCurrentThreadId`，`attach()` 时记；`getmypid()` 区分不了线程）。 */
    protected int $uiThreadId = 0;

    protected CommandRouter $router;

    /** 应用作用域：进程一份的东西（主题 / 度量 / 命令父表 / 加速键 / 端口 / IO）。 */
    private readonly AppScope $scope;

    /** 已向 `$scope` 登记过本窗口（`attach()` 可重入 ⇒ 只登记一次） */
    private bool $scopeEntered = false;

    /** 滚动输入路由：滚轮 / thumb 拖动 / 焦点滚进视口。只经 `focus()` 摸焦点链。 */
    private ScrollInputRouter $scrollInput;

    /** 浮层栈 Owner：栈所有权 + 开合策略 + Tooltip / Snackbar 时序件。 */
    private OverlayManager $overlayHost;

    /** 文本编辑桥：IME 光标 / 指针框选 / 编辑命令。 */
    private TextEditingBridge $textEditing;

    /** 输入路由主体：键 / 指针 / 滚轮三侧 + 手势状态（竞技场 / 拖放 / 捕获）。 */
    private InputRouter $input;

    /** 浮层几何：每帧一次的 settle→清扫→定位。只读栈、读盒、写盒。 */
    private OverlayLayout $overlayLayout;

    /** 异步泵：IO / 任务 / 定时器 / 分片。不读 root / focus / overlays。 */
    private PumpHost $pumps;

    /** 语义树消费闭包。`null` ⇒ 不建语义树（零开销）。 */
    protected ?\Closure $semanticsSink = null;
    /** 一帧预算（毫秒，60fps）。用途是回答"超了先砍谁"，不是 CPU 分配表。 */
    public const BUDGET_MS = 16.7;

    /** 连续多少帧超预算才算"真超"（单帧超常是 GC 或系统抢占）。 */
    public const OVER_BUDGET_FRAMES = 10;

    /**
     * 六阶段预算（毫秒）。是降级顺序的依据：超了先砍语义推送，
     * `layout` / `paint` / `present` 永不砍（砍了等于画面不更新）。
     */
    public const STAGE_BUDGET_MS = [
        'A 组（四个泵）' => 1.0,
        'settle' => 1.0,
        'layout' => 2.0,
        'paint' => 4.0,
        'present' => 5.0,
        '余量' => 3.7,
    ];

    /** 连续超预算的帧数（0 = 本帧在预算内） */
    private int $overBudgetStreak = 0;

    /** 是否处于"超预算"状态（连续 {@see self::OVER_BUDGET_FRAMES} 帧超了 ⇒ true） */
    private bool $overBudget = false;

    /** 最近一帧的耗时（毫秒；0 = 还没量过） */
    private float $frameCostMs = 0.0;

    /** 观测到的最差一帧（毫秒） */
    private float $worstFrameMs = 0.0;

    /** 语义推送还剩几帧不推（0 = 这帧要推） */
    private int $semanticsFrameSkip = 0;

    /** 上次推语义时的焦点（焦点一变必须立即推，永不节流） */
    private ?Element $semanticsFocused = null;

    /** 上次推语义时的结构版本（`-1` = 还没推过 ⇒ 首帧必然 full） */
    private int $lastStructureVersion = -1;

    /** 攒了几帧没推（0 = 没在攒） */
    private int $semanticsCoalesced = 0;

    /** coalesce 的帧数上限：没有上限 ⇒ 动画期间一直不推，读屏用户完全失去反馈。 */
    private const SEMANTICS_COALESCE_MAX = 4;

    /** 悬停多久才弹出 Tooltip（毫秒，桌面惯例档）。已在显示时切到另一控件立即换内容。 */
    public const TOOLTIP_DELAY_MS = 500;

    /**
     * 控件浮层面板（`Widget::overlayPanel()`）的宽度上限。
     * 必须限定：非居中面板拿到无界约束，夹紧只挪 x 不改宽 ⇒ 长选项会溢出客户区。
     */
    public const PANEL_MAX_WIDTH = 320.0;

    /** 上次推送的档位（`full` / `coalesce` / `none`）—— 夹具与 devtools 读它 */
    private string $semanticsMode = 'none';

    /** 上一帧有没有动画在跑。只读消费者：`continueFrame()` 的续跑节奏、`pumpSemantics()` 的档位。 */
    private bool $animating = false;

    /**
     * 动效活跃表：本帧需要被 tick 的节点，顺序无关。
     * 存 `Element` 而非 `RenderBox`：盒每次重建被换新，`renderObject()` 才指向当前盒。
     *
     * @var list<Element>
     */
    private array $activeTicks = [];

    /** 累计访问过的节点数（只增；测试按次数断言，时间是噪声）。 */
    private int $animationVisits = 0;

    /** 每帧自增的序列号 —— `State::tickFrameStamped` 用它做同帧幂等（迟到挂载补 tick 用） */
    private int $frameSerial = 0;

    /**
     * 本帧执行过的段名（`FramePhase` 关时为 `null`）。段名由 `stage()` 推进。
     *
     * @var list<string>|null
     */
    private ?array $frameTrace = null;

    /**
     * 被跳过的零视口帧数。只用于观测，不参与决策。
     *
     * @internal
     */
    private int $zeroViewportFrames = 0;

    /** 上一帧的时间戳（`repaintForResize()` 复用：拖动重排不该自己读环境时钟） */
    private float $lastTimestampMs = 0.0;

    /**
     * 最近一次输入派发的时间戳（`dispatchInput()` 的第 2 参）。`0.0` = 未提供。
     *
     * 框架不自己读钟，唯一时间来源是壳注入。"点了之后延时 400ms"必须以点击那一刻为
     * 基准，而 `$lastTimestampMs` 是上一帧的，空闲时可能差几百毫秒。
     */
    private float $lastInputMs = 0.0;

    /** 点击链（"这一下是第几次点击"）。住在应用层：路由层不持钟。规则见 `TapTracker`。 */
    private TapTracker $taps;

    /**
     * 本应用唯一的帧管线（结构/内容版本、脏记账、换代序号、量具）。
     *
     * @internal 应用代码不该碰它；测试/探针用 {@see self::pipeline()}
     */
    private readonly Pipeline $pipeline;

    /** @param iterable<string, CommandHandler> $handlers `commands()` 的返回值 */
    public function __construct(iterable $handlers = [], ?AppScope $scope = null)
    {
        // 先建：下面每个片在构造期就可能经版本号问这条管线。
        $this->pipeline = new Pipeline();
        // 本类对应用级状态的每一处读都经它。
        $this->scope = $scope ?? AppScope::make();

        // 以应用级命令表为父（就近优先）：全局命令各窗都能派发；本窗 handler 持本窗 Store，
        // 绝不能串到别的窗口去。
        $this->router = CommandRouter::make($this->scope->commands());

        $this->taps = new TapTracker();

        // host = $this，片只经 TextEditingHost 摸焦点与剪贴板。
        $this->textEditing = new TextEditingBridge($this);

        $this->scrollInput = new ScrollInputRouter($this);

        // 建在 input 之前（它收本片做片间组合），也建在 overlayLayout 之前（布局片经它读栈）。
        $this->overlayHost = new OverlayManager($this);

        // 后建：收 scrollInput / textEditing / overlayHost 做片间组合。
        $this->input = new InputRouter(
            $this,
            $this->scrollInput,
            $this->textEditing,
            $this->overlayHost,
        );

        $this->overlayLayout = new OverlayLayout($this->overlayHost);

        // host = $this，片只经 PumpHostHost 摸时间基准与 taskHost()。
        $this->pumps = new PumpHost($this);

        // `commands()` 不在这里调：PHP 构造链父先子后，此刻子类字段还是 null。
        // 注册推迟到 `attach()`。
        foreach ($handlers as $name => $handler) {
            $this->router->on((string) $name, $handler);
        }
    }

    /**
     * 焦点链。不是应用扩展点，唯一消费者是 `TextEditingBridge`。
     *
     * @internal
     */
    public function focus(): ?FocusManager
    {
        return $this->focus;
    }

    /** 应用子树（只写这一个方法就能跑起来） */
    abstract public function buildRoot(): Widget;

    /** 窗口标题 */
    public function title(): string
    {
        return 'Drift';
    }

    /**
     * 命令处理器：`命令名 => handler`，不是 `list<handler>`。
     * handler 不知道自己的命令名，且一个实例可挂多个名字 ⇒ 名字只能在注册侧给。
     *
     * @return iterable<string, CommandHandler>
     *
     * @api
     */
    public function commands(): iterable
    {
        return [];
    }

    /** 应用作用域 —— 多窗口时把同一个实例递给每个 `Application`（共享字体 / 线程池 / IO / 主题）。
     *
     * @api
     */
    public function scope(): AppScope
    {
        return $this->scope;
    }

    /** 主题：默认取 `AppScope`。多窗口下覆写会让本窗与别的窗口不一致（`attach()` 报 DIAGNOSTIC）。 */
    public function theme(): ThemeData
    {
        return $this->scope->theme();
    }

    /**
     * 后台执行端口（应用级，一个进程一个池）。`null` ⇒ 本应用不用后台任务。
     *
     * `final`：子类覆写是加载期致命错误。换后端在装配处 `AppScope::setTaskHost()`，
     * 否则会出现"注入一个池、`frame()` 却 drain 另一个"的两套队列。
     *
     * @api
     */
    final public function taskHost(): ?TaskHost
    {
        return $this->scope->taskHost();
    }

    /**
     * 提交一个后台任务 —— 应用侧唯一提交入口。
     *
     * 没有端口时返回 `Future::rejected()` 而不抛：提交点常在 `build()` 里，抛异常会
     * 打断渲染管线（整页消失），rejected 只让命令落到 error 态。
     * 默认 `SyncTaskHost` 会在 `submit()` 内同步跑完；网络 / 磁盘 / 大计算必须换后端，
     * 否则 UI 照样卡在这一行。
     *
     * @api
     */
    final public function submit(Job $job): Future
    {
        $host = $this->taskHost();
        if ($host === null) {
            return Future::rejected(new \RuntimeException(
                '本应用没有后台任务端口（AppScope::setTaskHost(null)）：'
                . \get_class($job) . ' 无处可跑'
            ));
        }

        return $host->submit($job);
    }

    /**
     * 已挂载的后台端口（`null` = 未挂载或应用声明不用）。
     *
     * @internal 测试与 devtools 读它；应用走 {@see self::submit()}，不要碰后端。
     */
    public function tasks(): ?TaskHost
    {
        return $this->pumps->tasks();
    }

    /**
     * 上一帧的时间戳（`PumpHost` 经它算 `after()` 的基准）。
     *
     * @internal 片间契约口子，不是应用扩展点
     */
    public function lastTimestampMs(): float
    {
        return $this->lastTimestampMs;
    }

    /**
     * 最近一次输入派发的时刻。
     *
     * @internal 片间契约口子，同上
     */
    public function lastInputMs(): float
    {
        return $this->lastInputMs;
    }
    /**
     * 加速键表（应用级，默认取 `AppScope`）。解析成**命令名**后再走 `dispatch()`，
     * 故共享一张表也不会让 `Ctrl+S` 串到别的窗口。
     *
     * 焦点控件认得的键归控件，认不得才轮到全局快捷键（不需任何声明）。
     *
     * @api
     */
    public function accelerators(): AcceleratorTable
    {
        return $this->scope->accelerators();
    }

    /**
     * 剪贴板壳服务 —— 组合根覆写它接真平台（框架不持平台）。
     *
     * 默认 `null` = 本应用没有剪贴板 ⇒ `Ctrl+C / X / V` 整族回落给加速键。
     *
     * @api
     */
    public function clipboard(): ?Clipboard
    {
        return null;
    }

    /** 字体度量（应用级，整个进程一份）。多窗口下覆写等于每窗解析一遍字体、各持一份缓存。
     *
     * @api
     */
    public function metrics(): TextMetricsProvider
    {
        return $this->scope->metrics();
    }

    public function router(): CommandRouter
    {
        return $this->router;
    }

    /**
     * 挂载：建根 Element、整棵树挂上。
     *
     * @param BoxConstraints $constraints 根收到的约束（通常 = 窗口逻辑尺寸）
     *
     * @api
     */
    public function attach(RenderSurface $surface, BoxConstraints $constraints, ?BuildContext $context = null): void
    {
        // 之后的任何线程断言都以此为准。
        $this->uiThreadId = \drift_thread_id();

        // 挂载期就写这条管线：`createElement()` / `mount()` 会推结构版本号，不切会记到别的应用账上。
        Pipeline::enter($this->pipeline);

        // 第一个窗口把主题灌进 scope，后续窗口必须一致（不一致 ⇒ DIAGNOSTIC）。
        // `attach()` 可重入 ⇒ 同一实例只登记一次，否则第二次 attach 会误报"第 2 个窗口"。
        if (!$this->scopeEntered) {
            $this->scopeEntered = true;
            $this->scope->enter($this->theme());
        }

        // 端口 / 轮询器从作用域取；`attach()` 只缓存引用，不负责建。
        $this->pumps->attach($this->scope->taskHost(), $this->scope->io());

        $context ??= new BuildContext(
            $constraints->maxWidth,
            $constraints->maxHeight,
            $this->theme(),
            $this->metrics(),
        );

        // 此刻子类字段已就位，`commands()` 才可信。重复注册会 Fatal ⇒ 必须可重入。
        foreach ($this->commands() as $name => $handler) {
            if (!$this->router->has((string) $name)) {
                $this->router->on((string) $name, $handler);
            }
        }

        $root = $this->buildRoot()->createElement();
        $root->mount(null, $context);
        $this->root = $root;

        $this->frame = new Frame($surface, $this->pipeline);

        // 首帧必须走 `frame()` 而非 `Frame::run()`：后者绕过 `tickAnimations()`，纯动画应用
        // 挂载后 `wantsFrame()` 仍为 false ⇒ 帧时钟不再上膛 ⇒ 转圈图一辈子不动。
        $this->frame(0.0, $constraints);

        // 焦点链要在挂载之后建（此刻才有树可走）。
        $this->focus = new FocusManager();
        $this->focus->onHoverChanged(function (?Element $hit): void {
            $this->overlayHost->onHoverHit($hit);
        });
        $this->focus->collect([$root]);
    }

    /** 根 Element（挂载后才有）—— 测试与 devtools 读它 */
    public function root(): ?Element
    {
        return $this->root;
    }

    /**
     * UI 线程 id（`0` = 未挂载）。
     *
     * @internal 应用不该读线程身份 —— 它只用于断言。
     */
    public function uiThreadId(): int
    {
        return $this->uiThreadId;
    }

    /**
     * UI 线程断言：`uiThreadId == 0`（未挂载）不算失败，只在真的跨线程时失败。
     *
     * 必须响亮失败：静默就等于给数据竞争留缝，那类 bug 只在压力下偶发。
     *
     * @internal 框架内部用；应用不应调用。
     */
    public function assertUiThread(string $where): void
    {
        if ($this->uiThreadId === 0 || \drift_thread_id() === $this->uiThreadId) {
            return;
        }

        Errors::report(sprintf(
            'UI 线程断言：%s 跑在非 UI 线程（UI=%d，当前=%d）—— 数据竞争的前兆',
            $where,
            $this->uiThreadId,
            \drift_thread_id(),
        ));
    }

    /** IME 定位用的光标矩形。组合根按 `Application::imeCaretRect()` 调，实现住 `TextEditingBridge`。 */
    public function imeCaretRect(): ?array
    {
        return $this->textEditing->imeCaretRect();
    }


    /**
     * 系统主题变了。不带参数：框架拿不到 `Screen::systemTheme()`，应用自己持 screen 时
     * 在这里重读并更新 `theme()` 的来源。默认空实现。
     *
     * 调用时机在 `applyNewContext()` 之前 —— 钩子跑完 `theme()` 才是新值。
     *
     * @api
     */
    protected function onSystemThemeChanged(): void
    {
    }

    /**
     * 窗口被要求关闭 —— 回答"同意吗"。默认 `true`。
     *
     * 只在组合根声明了守卫的窗口上会被问；返回 `true` ⇒ 框架销毁窗口，`false` ⇒ 原样留着。
     * 不要自己调 `close()`（那会再发一条 `WM_CLOSE`）。调用点在帧边界，故可安全弹模态框。
     *
     * @api
     */
    protected function onCloseRequested(): bool
    {
        return true;
    }

    /**
     * 供 `EventLoop` 调（`onCloseRequested()` 是 `protected`）。
     *
     * @internal 只有 `EventLoop` 该调；应用覆写 {@see self::onCloseRequested()}
     */
    public function closeRequested(): bool
    {
        return $this->onCloseRequested();
    }

    /** 把新的渲染环境下发到根与浮层两棵树（浮层是第二棵树）。
     *
     * 主题值与旧值 `===` 时 `applyContext()` 幂等短路 ⇒ 不跟随系统主题的应用零重建成本。
     */
    private function applyNewContext(): void
    {
        $root = $this->root;
        if ($root === null) {
            return;
        }

        $context = $root->context()->withTheme($this->theme());
        $root->applyContext($context);

        foreach ($this->overlayHost->overlays()->all() as $e) {
            $e->element?->applyContext($context);
        }
    }

    /**
     * 窗口**跨档**了就把新尺寸下发（档内不动）。
     *
     * ★ 档内不重建是有意的：拖动 resize 是高频路径（平台在模态尺寸循环里同步调回），
     *   逐像素重建全树会卡；而"档位不变 ⇒ 布局结论不变"，重排就够了。
     * ★ 只下发给根与浮层两棵树 —— 与 `applyNewContext()` 同一族，别处没有第三棵。
     */
    private function adoptWindowSize(BoxConstraints $constraints): void
    {
        $root = $this->root;
        if ($root === null || $constraints->maxWidth <= 0.0) {
            return;
        }

        $next = WindowSize::fromWidth($constraints->maxWidth);
        if ($next === $root->context()->windowSize()) {
            return;
        }

        // `rebuild()` 会推结构版本号 ⇒ 先切到本应用的管线，否则记到别的窗口账上。
        Pipeline::enter($this->pipeline);

        $context = $root->context()->withWindow($constraints->maxWidth, $constraints->maxHeight, $next);
        $root->applyContext($context);

        foreach ($this->overlayHost->overlays()->all() as $e) {
            $e->element?->applyContext($context);
        }
    }

    /**
     * 跑一帧：平台每帧调一次。
     *
     * @param float $timestampMs 注入的时间戳（不读环境时钟）
     *
     * @api
     */
    public function frame(float $timestampMs, BoxConstraints $constraints): void
    {
        $root = $this->root;
        $frame = $this->frame;
        if ($root === null || $frame === null) {
            return; // 未挂载：没有树就没有帧
        }

        // 成本 = 读一次 TEB + 一次整数比较。
        $this->assertUiThread('frame');

        // 树里的节点没有指向应用的引用，经 `Pipeline::current()` 找本帧的记账本；
        // 切了不恢复 —— 帧后测试/探针读的正是刚跑完这一帧的计数。
        Pipeline::enter($this->pipeline);

        // 帧开始的请求记为已服务，帧内新增的留给下一帧；必须在四个泵之前。
        $this->pipeline->beginFrame();

        // 跨档 ⇒ 本帧的 settle 就会重建（档内只重排，不在此处动手）。
        $this->adoptWindowSize($constraints);

        $settleSkipBefore = $this->pipeline->settleSkipCount();
        $paintSkipBefore = $this->pipeline->paintSkipCount();

        $this->lastTimestampMs = $timestampMs;

        // `State::tickFrameStamped` 用它识别"本帧已推进过的状态"。
        $this->frameSerial++;

        // 段名与分段计时同源：两者都只经 `stage()` 推一次，手写两份必漂。
        $this->frameTrace = FramePhase::begin();

        // ① 外部数据先到，回调里 setState 的脏标记由紧随的 settle 消费。
        $this->stage(FrameStage::PUMP_IO, function (): void {
            $this->pumps->pumpIo();
        });

        // ② 必须在 settle 之前：回调只标脏，放到 settle 之后会让"loading → ready"多闪一帧空。
        $this->stage(FrameStage::PUMP_TASKS, function (): void {
            $this->pumps->pumpTasks();
        });

        // ③ 同理，`after()` 回调里的 `setState()` 同帧生效。
        $this->stage(FrameStage::PUMP_TIMERS, function () use ($timestampMs): void {
            $this->pumps->pumpTimers($timestampMs);
        });

        // ④ 同理。返回值喂给 `wantsFrame()` 决定下次要不要被叫醒；`stage()` 返回 `mixed`，
        // 这里把类型收敛回 `bool`。
        $this->animating = (bool) $this->stage(FrameStage::TICK_ANIMATIONS, function () use ($timestampMs): bool {
            return $this->tickAnimations($timestampMs);
        });

        // 零视口 = 没有像素可画，不是内容放不下：拿 `tight(0,0)` 布局会让 Text 每字一行，
        // 高度约束 0 ⇒ C6 Fatal。泵已全部跑完，这里只跳过布局与绘制。
        if ($constraints->maxWidth <= 0.0 || $constraints->maxHeight <= 0.0) {
            $this->zeroViewportFrames++;

            // 动画与 `after()` 照常推进，故续跑判据照样要跑，否则最小化期间动画停在半路。
            $this->continueFrame($settleSkipBefore, $paintSkipBefore);
            $this->pipeline->endFrame();

            return;
        }

        // 浮层布局段（逐层自尺寸 + 钉锚点 + 夹进客户区）—— 见 placeOverlays()
        $this->stage(FrameStage::PLACE_OVERLAYS, function () use ($constraints): void {
            $this->overlayLayout->placeOverlays($constraints);
        });

        // `settle` 是标记不是真段：计时在 `Frame::run()` 里跨越整段、夹着早退，包不进闭包，
        // 这里只补位置。它是唯一不经 `stage()` 的段名。
        if ($this->frameTrace !== null) {
            $this->frameTrace[] = FrameStage::SETTLE;
        }

        // 本帧要不要画：`Frame` 只看树，看不见应用级叠加 —— 焦点环目标变了没画、
        // 浮层锚点换了但面板还落后。取跑帧前的目标：paint 只会画跑帧前那个环。
        $ringBefore = $this->focusRingTarget();
        $paintRequested = $ringBefore !== $this->paintedFocus
            || $this->overlayLayout->panelsLagBehind();

        // 窗口帧：约束来自客户区，"内容比窗口大"是合法状态 ⇒ C5/C6 降级为裁剪 + diagnostic，
        // 不中止进程。直接调 `Frame::run()` 的测试仍按严格口径，写错布局照样红。
        $painted = LayoutTolerance::withinWindow(fn(): bool => $frame->run($root, $constraints, $timestampMs, $this->overlayHost->overlays()->all(), function (Canvas $canvas): void {
            $this->paintFocusRing($canvas);
        }, $paintRequested));

        // 环真的上屏才记账；跳过或 paint 抛异常 ⇒ 不记账 ⇒ 下一帧继续请求。
        if ($painted) {
            $this->paintedFocus = $ringBefore;
        }

        // ⑤ 迟到挂载补 tick：④ 在 settle 之前跑，而页面挂在 settle 段 ⇒ 新挂上的 State 当帧
        // 从未被 tick ⇒ `$animating` 为假 ⇒ 帧时钟不上膛 ⇒ 之后没输入就永远静止。
        // 只 tick 登记表（其他 State 已在 ④ 访问过），并跳过已卸载的。
        $this->stage(FrameStage::TICK_LATE_MOUNT, function () use ($timestampMs): void {
            foreach ($this->pipeline->takeMounted() as $mountedState) {
                $mountedElement = $mountedState->element();
                if ($mountedElement === null || !$mountedElement->isMounted()) {
                    continue;
                }

                if ($mountedState->tickFrameStamped($timestampMs, $this->frameSerial)) {
                    $this->animating = true;

                    // 已在动就必须进活跃表，否则下一帧只走表、没人再 tick 它，动画停在第一帧。
                    if (!$mountedElement->isAnimationActive()) {
                        $mountedElement->markAnimationActive(true);
                        $this->activeTicks[] = $mountedElement;
                    }
                }
            }
        });

        // 每帧重建焦点链：settle 可能换掉子树，旧链会握着已移除的 Element（按键静默丢给死节点）。
        // 浮层是第二棵树，也要进链，否则菜单项永远 Tab 不到。
        $this->stage(FrameStage::FOCUS_COLLECT, function () use ($root): void {
            // ★ 捕获者卸载 ⇒ 必须释放：settle 换掉子树后，指针事件全发给死节点（静默冻住）。
            $captureNode = $this->input->captureNode();
            if ($captureNode !== null && !$captureNode->isMounted()) {
                Errors::report(sprintf(
                    '指针捕获：捕获者已卸载但仍握着指针 —— 自动释放。'
                        . "\n" . '修法：在控件自己的卸载路径里先调 releasePointer()，'
                        . '或把捕获挂在会被卸载的子树外面。',
                ), Errors::DIAGNOSTIC);
                $this->input->releasePointer();
            }
            $popupRoots = [];
            foreach ($this->overlayHost->overlays()->all() as $e) {
                if ($e->element !== null) {
                    $popupRoots[] = $e->element;
                }
            }
            $this->focus?->collect([$root, ...$popupRoots], $this->overlayHost->focusTrapRoots());
        });

        // 每帧末经 sink 交出语义树（无 sink ⇒ 不建，零开销）。
        $this->stage(FrameStage::PUMP_SEMANTICS, function (): void {
            $this->pumpSemantics();
        });

        // 放在 `pumpSemantics()` 之后：语义推送里若改状态，本帧不会再 settle，须算帧外。
        $this->continueFrame($settleSkipBefore, $paintSkipBefore);
        $this->pipeline->endFrame();

        if ($this->frameTrace !== null) {
            FramePhase::end($this->frameTrace);
        }
    }

    /**
     * 跑一段帧阶段：段名推进 trace + 交给 `SurfaceProfile` 计时。
     *
     * 两个出口只能在同一处写，否则清单会漂（trace 有、计时没有，成本落到「未归属」而无人报错）。
     *
     * @param string $stage `FrameStage` 的常量之一
     * @return mixed `$body` 的返回值
     */
    private function stage(string $stage, \Closure $body): mixed
    {
        if ($this->frameTrace !== null) {
            $this->frameTrace[] = $stage;
        }

        return SurfaceProfile::time($stage, $body);
    }

    /**
     * 帧末续跑：把四条"跑完这一帧才知道"的判据变成调度请求。
     *
     * 前两条（动画、轮询）用 `scheduleNextFrame()` 按帧节奏反复醒，给 `0` 就是能跑多快跑多快；
     * 后两条（焦点环、面板落后）用 `scheduleFrame()`，多等一个帧间隔就是肉眼可见的延迟。
     *
     * 另报空转：要了帧（`consumed > 0`）但 settle 与 paint 都整段跳过，说明有人无条件 schedule。
     *
     * @param int $settleSkipBefore 本帧开始时的 settle 跳过计数
     * @param int $paintSkipBefore 本帧开始时的 paint 跳过计数
     */
    private function continueFrame(int $settleSkipBefore, int $paintSkipBefore): void
    {
        if ($this->animating || $this->pumps->hasPolledAsync()) {
            $this->pipeline->scheduleNextFrame();
        }

        if ($this->focusRingTarget() !== $this->paintedFocus
            || $this->overlayLayout->panelsLagBehind()
        ) {
            $this->pipeline->scheduleFrame();
        }

        $consumed = $this->pipeline->frameConsumed();
        $this->pipeline->noteIdleSpin(
            $consumed > 0
            && $this->pipeline->settleSkipCount() > $settleSkipBefore
            && $this->pipeline->paintSkipCount() > $paintSkipBefore,
        );

        if ($this->pipeline->idleSpinFrames() === 3) {
            Errors::report(
                '帧调度：连续 3 帧有人要求重画，但 settle 与 paint 都整段跳过 —— '
                    . '某个变更点在"什么都没变"时也无条件调了 scheduleFrame()'
                    . '（这会让帧时钟永不上闸、CPU 空转且收不到输入）。'
                    . "\n" . '修法：在标记之前先做同值早退（参考 RenderObject::setHovered()）。',
                Errors::DIAGNOSTIC,
            );
        }
    }

    /**
     * 拖动 resize 专用的轻量重绘：不跑泵、不推进画、不重建焦点链、不推语义树。
     *
     * 它会被壳在 WM_SIZE（模态循环内）同步调回，能碰的状态越少越安全。时间戳复用上一帧的 ——
     * 这是"同一时刻换个尺寸再画一次"，自己读钟会破坏 `frame()` 的单一时钟来源。
     *
     * @api 由平台壳的拖动重排入口调（`src/Platform/Win32/ResizeHook.php`）
     */
    public function repaintForResize(BoxConstraints $constraints): void
    {
        $root = $this->root;
        $frame = $this->frame;
        if ($root === null || $frame === null) {
            return;
        }

        $this->assertUiThread('repaintForResize');

        // 零视口：`tight(0,0)` 会让 Text 每字一行 ⇒ C6 Fatal。这里不跑泵，跳过整段是对的。
        if ($constraints->maxWidth <= 0.0 || $constraints->maxHeight <= 0.0) {
            return;
        }

        // 拖动中的重排同样是窗口帧：裁剪 + 留痕，不弹 Fatal 打断拖动。
        // 档位若变了得先重建树 —— 放在跑帧之前，同帧生效（否则会闪一帧旧布局）。
        $this->adoptWindowSize($constraints);

        LayoutTolerance::withinWindow(function () use ($constraints, $frame, $root): void {
            $this->overlayLayout->placeOverlays($constraints);
            $frame->run(
                $root,
                $constraints,
                $this->lastTimestampMs,
                $this->overlayHost->overlays()->all(),
                function (Canvas $canvas): void {
                    $this->paintFocusRing($canvas);
                },
            );
        });
    }

    // ------------------------------------------------------------------
    // 异步泵 API 委托壳（S1 第 9 步）：实现搬进 {@see PumpHost}，
    // **API 位置不动** —— 应用 / 组合根 / 测试照旧按 Application 调。
    // frame() 的四泵与 wantsFrame() 判据直调片，不经这些壳。
    // ------------------------------------------------------------------

    /**
     * 排一个一次性延时回调。
     *
     * 时间基准是上一帧的时间戳，不是环境时钟；在 `frame()` 之外调用会早一个帧间隔（≤一帧）。
     * 回调在 `frame()` 内、settle 之前跑 ⇒ 里面的 `setState()` 同帧生效。
     * 有定时器在等 ⇒ `wantsFrame()` 为真，否则阻塞的消息泵永远不会被叫醒。
     *
     * @param int $milliseconds 延时（毫秒；负数按 0 处理 ⇒ 下一帧就触发）
     * @return int 定时器 id（`cancelAfter()` 用）
     *
     * @api
     */
    public function after(int $milliseconds, \Closure $callback): int
    {
        return $this->pumps->after($milliseconds, $callback);
    }

    /** 撤销一个延时回调（已触发 / id 不存在 ⇒ 无事发生，幂等） */
    public function cancelAfter(int $id): void
    {
        $this->pumps->cancelAfter($id);
    }

    /**
     * 本应用还需要下一帧吗 —— 自适应帧时钟的唯一判据。
     *
     * 消息泵是阻塞的，没人说"还有活"，平台就不起定时器。所有来源都有界（任务回贴完、
     * 动画跑完 ⇒ false），故空闲不空转。别在这里加判据 —— 想加就去变更现场调 `scheduleFrame()`。
     *
     * @api 组合根每帧调一次（`FrameClock` 就是这么用的）
     */
    public function wantsFrame(): bool
    {
        return $this->pipeline->wantsFrame();
    }

    /**
     * 欠的账是"立刻跑"那一档吗（像素脏 / 焦点环没画 / 面板落后）。
     *
     * 为真 ⇒ 等待层给 `0`；为假但 {@see self::wantsFrame()} 为真 ⇒ 给帧间隔。
     *
     * @api `FrameClock::nextWaitMs()` 每轮问一次
     */
    public function needsFrameImmediately(): bool
    {
        return $this->pipeline->wantsFrameImmediately();
    }

    /**
     * 下一个到点的定时器（绝对毫秒，与 `Platform::now()` 同一时基）；`null` = 没有。
     *
     * 只算 `after()` 的延时回调：任务 / socket 可读没有到点时刻（那是轮询）。
     * 定时器不算"要帧"，由等待层与 `wantsFrame()` 取较早者。
     *
     * @api 等待层（`FrameClock::nextWaitMs()`）每轮问一次
     */
    public function nextDeadlineMs(): ?float
    {
        return $this->pumps->nextDeadlineMs();
    }

    /**
     * 本应用一共跑过多少帧（含 `attach()` 的那一帧）。
     *
     * @internal 量具：静态树下 paint 整段跳过，"跑帧"≠"画帧"，故需数帧本身。
     */
    public function frameCount(): int
    {
        return $this->frameSerial;
    }

    /**
     * 本应用的帧管线。
     *
     * @internal 给测试 / 探针 / devtools 精确读某一应用的数字。业务代码不该读它。
     */
    public function pipeline(): Pipeline
    {
        return $this->pipeline;
    }

    /** 记一次帧耗时（诊断 / 语义推送节流的输入） */
    public function noteFrameCost(float $milliseconds): void
    {
        // 量坏了（NaN / 负值）⇒ 不立超预算：一次坏样本会把语义推送白白节流几帧。
        if (!\is_finite($milliseconds) || $milliseconds < 0.0) {
            return;
        }

        $this->frameCostMs = $milliseconds;
        if ($milliseconds > $this->worstFrameMs) {
            $this->worstFrameMs = $milliseconds;
        }

        if ($milliseconds <= self::BUDGET_MS) {
            $this->overBudgetStreak = 0;
            $this->overBudget = false;

            return;
        }

        $this->overBudgetStreak++;
        $this->overBudget = $this->overBudgetStreak >= self::OVER_BUDGET_FRAMES;

        // 只在刚立起来的那一帧报：每帧报一次会把只有 100 条的环形缓冲刷满。
        if ($this->overBudgetStreak === self::OVER_BUDGET_FRAMES) {
            Errors::report(sprintf(
                '连续 %d 帧超过 %.1fms 预算（本帧 %.1fms，最差 %.1fms）'
                    . ' ⇒ 语义推送降到每 4 帧一次（焦点变化仍立即推）。'
                    . "\n" . '六阶段预算：%s —— 超了先砍语义推送，layout / paint / present 永不砍（`06` §3.3）。'
                    . "\n" . '修法：① 打开分段计时看哪一段超了；'
                    . '② 检查本帧是否 rebuild 了整棵子树（动画应该用 setCompose 而不是 markDirty）。',
                self::OVER_BUDGET_FRAMES,
                self::BUDGET_MS,
                $milliseconds,
                $this->worstFrameMs,
                self::stageBudgetText(),
            ), Errors::DIAGNOSTIC);
        }
    }

    /** 是否处于超预算状态（devtools / 夹具也读它） */
    public function overBudget(): bool
    {
        return $this->overBudget;
    }

    /** 最近一帧的耗时（毫秒；0 = 还没量过） */
    public function frameCostMs(): float
    {
        return $this->frameCostMs;
    }

    /** 观测到的最差一帧（毫秒） */
    public function worstFrameMs(): float
    {
        return $this->worstFrameMs;
    }

    /**
     * 被跳过的零视口帧数。
     *
     * @internal 夹具用它证明"那一帧确实被跳过了"，而不是"恰好没崩"。
     */
    public function zeroViewportFrames(): int
    {
        return $this->zeroViewportFrames;
    }

    /** 六阶段预算拼成一行（诊断文案用；数字的唯一来源是 {@see self::STAGE_BUDGET_MS}） */
    private static function stageBudgetText(): string
    {
        $parts = [];
        foreach (self::STAGE_BUDGET_MS as $name => $ms) {
            $parts[] = sprintf('%s %.1f', (string) $name, $ms);
        }

        return implode(' / ', $parts);
    }

    /** 注入语义树消费者（组合根接到 `WindowHost::pushSemantics()`）。不注入 ⇒ 不建语义树。 */
    public function setSemanticsSink(callable $sink): void
    {
        $this->semanticsSink = \Closure::fromCallable($sink);
    }

    /**
     * 语义推送两档：`full`（结构或焦点变了）与 `coalesce`（动画中，每 4 帧合并一次）。
     *
     * ★ 结构 / 焦点变化 ⇒ 立即 full，两条节流都不看：增量表达不了"多了一个节点"，
     * 攒着推会让读屏的树与真实树错位；焦点延迟 50ms 就让人怀疑键盘失灵。
     * 超预算时降到每 4 帧一次，但只约束没有结构 / 焦点变化的常态帧。
     */
    private function pumpSemantics(): void
    {
        $sink = $this->semanticsSink;
        if ($sink === null) {
            return;
        }

        $focused = $this->focus?->focused();
        $focusChanged = $focused !== $this->semanticsFocused;
        $version = $this->pipeline->structureVersion();
        $structureChanged = $version !== $this->lastStructureVersion;

        // ① 结构 / 焦点变了 ⇒ full，立即（不看 coalesce，也不看超预算）
        if ($structureChanged || $focusChanged) {
            $this->lastStructureVersion = $version;
            $this->semanticsFocused = $focused;
            $this->semanticsCoalesced = 0;
            $this->semanticsFrameSkip = $this->overBudget ? 3 : 0;
            $this->pushSemantics($sink, $focused, 'full');

            return;
        }

        // ② 动画中 ⇒ coalesce：攒着，攒满上限必须推一次
        if ($this->animating) {
            $this->semanticsMode = 'coalesce';
            if (++$this->semanticsCoalesced < self::SEMANTICS_COALESCE_MAX) {
                return;
            }
        }

        // ③ 超预算时降到每 4 帧一次
        if ($this->overBudget && $this->semanticsFrameSkip > 0) {
            $this->semanticsFrameSkip--;

            return;
        }
        $this->semanticsFrameSkip = $this->overBudget ? 3 : 0;
        $this->semanticsCoalesced = 0;
        $this->pushSemantics($sink, $focused, $this->animating ? 'coalesce' : 'full');
    }

    /** @param \Closure(array<int, array<string, mixed>>): void $sink */
    private function pushSemantics(\Closure $sink, ?Element $focused, string $mode): void
    {
        $this->semanticsMode = $mode;
        $tree = SemanticsBuilder::build($this->root, $focused);
        ($sink)($tree->toArrays()['nodes']);
    }

    /**
     * 按**语义节点索引**激活一次（§9 / M5 无障碍的"可操作"半边）。
     *
     * 消费者：`dispatchInput()` 收到 `SemanticsInvokeEvent` 时转调（UIA
     * `IInvokeProvider::Invoke()` ⇒ 壳入队 ⇒ 平台翻成该事件）。也直接可调
     * （冒烟夹具、devtools）—— 它不依赖"事件从哪来"。
     *
     * ★★ **落成"在节点中心派发一次合成 tap"，而不是"直接调控件的动作"**：
     *   激活语义只有一处（输入路由 → 命中 → `Activatable::activate()`）。
     *   另写一条"按角色找动作"的路会与它漂移 —— 按钮走 `command`、`Segment` 走
     *   `onSelected`、`Toggle` 走 `onChanged`…每加一个控件就要补一支，而且
     *   "读屏点"与"鼠标点"行为不同是**没人测得出来**的那类缺陷。
     *
     * ★ 坐标：语义节点几何就是渲染树的逻辑坐标（与命中测试同一套），
     *   所以中心点可以直接喂给 `dispatchInput()`。
     *
     * ★ 索引口径：`SemanticsBuilder::toArrays()['nodes']` 的下标（前序），与推给
     *   UIA 的那份快照同一套编号。树在两次推送之间变了 ⇒ 索引可能指到别的节点
     *   （与任何 UI 自动化一样是竞态）；结构变化会立刻触发整棵重推，窗口很短。
     *
     * @return bool 真的派发了（越界 / 零面积 ⇒ false —— 消费方据此回
     *   `UIA_E_ELEMENTNOTAVAILABLE`，不能假装成功）
     */
    public function invokeSemanticsNode(int $index): bool
    {
        $tree = SemanticsBuilder::build($this->root, null);
        if ($index < 0 || $index >= $tree->count()) {
            return false;
        }
        $node = $tree->node($index);
        if ($node->w <= 0 || $node->h <= 0) {
            return false;
        }
        $x = $node->x + $node->w / 2.0;
        $y = $node->y + $node->h / 2.0;

        // ★ 合成一次**完整**指针手势：先 move 再 down/up。
        //   真实的鼠标点击永远先有 `WM_MOUSEMOVE`（悬停链先建立）——
        //   "凭空 down"在真机上不激活（`CursorShape` 退默认），别把它当成
        //   "少一条事件无所谓"。
        $this->dispatchInput(PointerEvent::move($x, $y));
        $this->dispatchInput(PointerEvent::down($x, $y));
        $this->dispatchInput(PointerEvent::up($x, $y));

        return true;
    }

    /**
     * 按**语义节点索引**写入一个值（UIA `IValueProvider` / `IRangeValueProvider`
     * 的 `SetValue`，§4.4 提交 2）。
     *
     * ★ 寻址与 `invokeSemanticsNode()` 同源（节点几何中心 → 命中 → Element），
     *   区别只在最后一步：**不派发 tap**，而是沿命中 Element 的父链找
     *   `SemanticsValueEditable`（与 `activateFocusedWidget()` 找 `Activatable`
     *   同构）。`SetValue` 是"把值改成 X"，不是"点一下"。
     * ★ 返回 false 的四种情形（越界 / 零面积 / 未挂载 / 无人接）⇒ 消费方回
     *   `UIA_E_ELEMENTNOTAVAILABLE`，**不能假装成功**。
     */
    public function setSemanticsValue(int $index, string $value): bool
    {
        $tree = SemanticsBuilder::build($this->root, null);
        if ($index < 0 || $index >= $tree->count()) {
            return false;
        }
        $node = $tree->node($index);
        if ($node->w <= 0 || $node->h <= 0) {
            return false;
        }
        $root = $this->root;
        $focus = $this->focus;
        if ($root === null || $focus === null) {
            return false;
        }
        $hit = $focus->hitTest($node->x + $node->w / 2.0, $node->y + $node->h / 2.0, $root);
        $element = $hit === null ? null : $focus->elementAt($root, $hit);
        for ($e = $element; $e !== null; $e = $e->parent()) {
            $widget = $e->widget();
            if ($widget instanceof SemanticsValueEditable) {
                return $widget->setSemanticsValue($value);
            }
        }

        return false;
    }

    /** @internal 夹具 / devtools：上次推送用的是哪一档（`full` / `coalesce` / `none`） */
    public function semanticsMode(): string
    {
        return $this->semanticsMode;
    }

    /**
     * 推进本帧的动画，回答"**还有动画在跑吗**"（§17.4.9）。
     *
     * ★ **浮层是第二棵树**（与 `focus->collect()` / `placeOverlays()` 同一个坑）：只走
     *   `$root` 的话，菜单里放一个 `Spinner` 就永远不转，而且**没有任何报错**。
     *
     * ★★ **两条路径，一帧只走一条**（2026-11-03）：
     *
     *   | 路径 | 何时 | 成本 |
     *   |---|---|---|
     *   | 全树发现（`collectActiveAnimations`） | 首帧 / 结构变化 / 悬停目标切换 | O(树)，一次性 |
     *   | 只走活跃表（`updateActiveAnimations`） | 其余每一帧 | O(在动的节点)，通常 0–5 |
     *
     *   此前只有全树一条路径：2000 节点的空转帧要 **935µs**（占整帧 98%），
     *   而它换来的信息量只是"有几个人在动"。
     *   ★ **绝不能两条都走**：盒的 `tickAnimations()` 没有帧戳（`State` 有），
     *   同一帧推两次就是动画快进一帧。所以这里是 `if / return` 而不是两个 if。
     */
    private function tickAnimations(float $timestampMs): bool
    {
        // ★ 队列必须**每帧消费**：走全扫时它被全扫覆盖（取走丢弃），走表时并入。
        //   漏消费 ⇒ 下一帧重复入表 ⇒ 同一个盒被推进两次。
        if ($this->pipeline->takeAnimationScan()) {
            $this->pipeline->takeActiveAnimations();

            return $this->collectActiveAnimations($timestampMs);
        }

        return $this->updateActiveAnimations($timestampMs);
    }

    /**
     * 全树发现：重建活跃表，并回答"还有人在动吗"。
     *
     * ★ 三棵树都要走（根 + 每个浮层）—— 见 `tickAnimations()` 的第一条。
     */
    private function collectActiveAnimations(float $timestampMs): bool
    {
        // ★ 不是"并入"而是**重写**：全扫看得到整棵树，它给出的表才是完整答案。
        //   并入会让已停止的旧表项永远留着（出表只发生在 tick 返回 false 的那一帧，
        //   而全扫帧里它们未必被问到 —— 它们可能已不在树里）。
        $this->activeTicks = [];

        $busy = false;

        $root = $this->root;
        if ($root !== null && $this->scanSubtree($root, $timestampMs)) {
            $busy = true;
        }

        foreach ($this->overlayHost->overlays()->all() as $e) {
            if ($e->element !== null && $this->scanSubtree($e->element, $timestampMs)) {
                $busy = true;
            }
        }

        return $busy;
    }

    /**
     * `collectActiveAnimations()` 的递归体。
     *
     * ★ **不许短路**：即使已经知道 `$busy` 为真，兄弟子树里的动画也必须收到这一帧，
     *   否则"整棵树里只推进第一个动画"——现象是多个 Spinner 只有一个在转。
     *
     * ★ **标记位与表必须同一个真理**：全扫是唯一"重写全表"的地方，所以它也是唯一
     *   把标记位写成 `false` 的地方 —— 队列被丢弃的那些节点靠这里对齐（它们的标记
     *   位已在 `State::markDirty()` 里置 true，但没进表；不在这里清回来，它们以后
     *   再 `setState` 就不会重新入队了）。
     *
     * ★ 返回值而不是 `bool &$busy` 出参：**tpc 不支持"按引用传固定类型的标量参数"**
     *   （`Cannot create a reference to variable ... of fixed type php::Bool`）。
     *   闭包里的 `use (&$busy)` 它支持 —— 原 `tickSubtree()` 一直是这个形状。
     */
    private function scanSubtree(Element $element, float $timestampMs): bool
    {
        $busy = false;

        $more = $this->tickElement($element, $timestampMs);
        $element->markAnimationActive($more);

        if ($more) {
            $this->activeTicks[] = $element;
            $busy = true;
        }

        $element->visitChildren(function (Element $child) use ($timestampMs, &$busy): void {
            if ($this->scanSubtree($child, $timestampMs)) {
                $busy = true;
            }
        });

        return $busy;
    }

    /**
     * 只走活跃表：合并新登记的，tick 表内的，停下的出表。
     */
    private function updateActiveAnimations(float $timestampMs): bool
    {
        // ① 把 `State::markDirty()` 报上来的并进来 —— O(新增)，不扫树。
        //   ★ 不 `instanceof Element` 验证：App 层不得认 Widget 的具体类（I2 闸门），
        //     而队列只有一个写者（`State`，它塞的是自己的 `element()`）⇒ 类型由
        //     构造保证。放错的后果是当场 `Call to undefined method`，不是静默。
        foreach ($this->pipeline->takeActiveAnimations() as $node) {
            /** @var Element $node */
            if ($node->isAnimationActive()) {
                continue;
            }
            $node->markAnimationActive(true);
            $this->activeTicks[] = $node;
        }

        // ② 只 tick 表里的人
        $busy = false;
        $next = [];

        foreach ($this->activeTicks as $element) {
            // ★ 卸载即出表（同 `takeMounted()` 的 `isMounted()` 过滤）：不跳过就是
            //   给一个已经离开树的节点发帧 —— 僵尸 tick，且它永远返回 false 也占着表。
            if (!$element->isMounted()) {
                $element->markAnimationActive(false);
                continue;
            }

            if ($this->tickElement($element, $timestampMs)) {
                $next[] = $element;
                $busy = true;
                continue;
            }

            $element->markAnimationActive(false);
        }

        $this->activeTicks = $next;

        return $busy;
    }

    /**
     * 推进**一个**节点上的两处动画，回答"它还要下一帧吗"。
     *
     * ★ **State 与盒在同一次访问里推进**（`RenderObject::tickAnimations`）：
     *   悬停过渡这类"盒态"动画住在 Render 层、不进 State，分开走两遍就是两次
     *   O(树)。同一次访问顺手问一句，`wantsFrame()` 的"有动画在跑"判据自动
     *   覆盖两处 —— **不需要加第八条判据**。
     */
    private function tickElement(Element $element, float $timestampMs): bool
    {
        ++$this->animationVisits;

        $more = false;

        $state = States::of($element);
        if ($state !== null && $state->tickFrameStamped($timestampMs, $this->frameSerial)) {
            $more = true;
        }

        $box = $element->renderObject();
        if ($box !== null && $box->tickAnimations($timestampMs)) {
            $more = true;
        }

        return $more;
    }

    /**
     * 累计访问过的节点数（测试断言"这一帧只碰了几个节点"）。
     *
     * @internal
     */
    public function animationVisits(): int
    {
        return $this->animationVisits;
    }

    /**
     * 当前活跃表里有几个节点。
     *
     * @internal 夹具 / devtools
     */
    public function activeAnimationCount(): int
    {
        return \count($this->activeTicks);
    }

    /**
     * 非阻塞 I/O 轮询器（`null` = 未挂载）。
     *
     * ```php
     * $sock = stream_socket_client("tcp://127.0.0.1:9000", $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT);
     * stream_set_blocking($sock, false);            // ★ 必须：poll 只保证"问一下就走"
     * $app->io()->watch($sock, true, function ($s) use ($app): void { ... });
     * ```
     *
     * @api
     */
    public function io(): ?IoPoller
    {
        return $this->pumps->io();
    }

    /**
     * 捕获指针（`06` §5，W3-L5-6）：从这个节点起的指针事件**只发给它**，不再命中测试。
     *
     * ```php
     * // 控件在处理按下时申请捕获（通常是 Element 自己的 pointer() 回调里）
     * $this->application()->capturePointer($element);
     * ```
     *
     * ★ 捕获**没有引用计数**：后一次覆盖前一次。理由 —— 同时"按住"两个控件的
     *   唯一途径是两根手指，而当前平台是单指针（`PointerEvent` 没有 pointerId）。
     *   多指进来时这里要改成 `array<int, Element>`，届时判据是"事件带 id"。
     * ★ 释放有四条路（见 `InputRouter` 的 `$captureNode` 注释），**少一条就是死节点收事件**。
     *
     * ★ 实现住 `InputRouter`（S1 第 6 步）；本方法是壳，行为不变。
     *
     * @api
     */
    public function capturePointer(Element $node): void
    {
        $this->input->capturePointer($node);
    }

    /**
     * 释放指针捕获（幂等；没捕获 ⇒ 无事发生）。
     *
     * ★ 实现住 `InputRouter`；本方法是壳，行为不变。
     *
     * @api
     */
    public function releasePointer(): void
    {
        $this->input->releasePointer();
    }

    /** @internal 当前捕获者（测试 / devtools）—— 委托 `InputRouter` */
    public function captureNode(): ?Element
    {
        return $this->input->captureNode();
    }


    /**
     * 喂一条平台事件（§9.1 路由链的入口）。
     *
     * ```php
     * while (($event = $input->poll()) !== null) {   // 由 EventLoop::run() 驱动
     *     $shape = $app->dispatchInput($event, $platform->now());
     *     if ($shape !== null) { $window->setCursor($handle, $shape); }
     * }
     * ```
     *
     * @param float|null $timestampMs 这一条事件**到达的时刻**（壳注入 `Platform::now()`）。
     *   只有一件事用它：`after()` 的计时基准（见 `$lastInputMs`）。★ 不传 ⇒
     *   基准退回"上一帧"，与 2026-10-24 之前逐字节相同（测试 / 解释器不受影响）。
     *
     * @return CursorShape|null 指针事件 ⇒ 命中链解析出的光标形状（★ 与焦点
     *   **同一次命中**，不重复走树 —— §9.1「一次命中，三方消费」）；其余事件
     *   ⇒ `null`（不改光标）。★ 应用它需要 `WindowHost`，而**框架不持平台**
     *   （§5.1：共享代码零平台引用）⇒ 由组合根把返回值喂给 `setCursor()`。
     *
     * ★ 实现住 `InputRouter`（S1 第 6 步）。本壳保留三件**应用侧**职责：
     *   ① `lastInputMs` / `keyboardFocusVisible` 记账（`after()` 与焦点环的基准）
     *   ② 失活收口（必须**先于**"未挂载"守卫，见下）
     *   ③ 主题分发（`ThemeChangedEvent` ⇒ 钩子 + 新 context —— 帧循环职责，
     *      不是输入路由）。
     * ④ 帧欠账兜底：帧内标脏不会自己上膛，派发后若确实脏了却没有下一帧，补一笔
     *      （见 `dispatchInput()` 的 `finally`）。
     *
     * @api
     */
    public function dispatchInput(InputEvent $event, ?float $timestampMs = null): ?CursorShape
    {
        $epochBefore = $this->pipeline->paintEpoch();

        try {
            return $this->dispatchInputInner($event, $timestampMs);
        } finally {
            // ★ 输入引起的像素变更必须有一帧来画（`DEBT` §42）。标脏落在帧内时
            //   `bumpPaintEpoch()` 不上膛（它假定本帧 paint 会消费），可输入完全可能
            //   在 paint 之后到达 ⇒ 这笔脏永远画不出来，直到下一条输入把帧叫醒 ——
            //   真机上就是"点击后第一条指针输入被吃掉、第二条才生效"。
            //   只在"真标脏 + 欠账丢了"时补一刀：正常路径不动，也不引入空转。
            if ($this->pipeline->paintEpoch() !== $epochBefore && !$this->pipeline->wantsFrame()) {
                $this->pipeline->scheduleFrame();
            }
        }
    }

    /** @internal 真正的派发；帧欠账由 `dispatchInput()` 统一兜底。 */
    private function dispatchInputInner(InputEvent $event, ?float $timestampMs = null): ?CursorShape
    {
        // ★★ 输入入口也切管线（2026-11-02，技术债 D3①）：回调里的 `setState()`
        //   是**帧外**写 —— 不切的话它会记到"上一次跑帧的那个应用"的账上，于是
        //   本应用的 `markedCount` 不涨 ⇒ `Frame::needsSettle()` 判"没变" ⇒
        //   **点了没反应**（且不报错）。多应用下这是最贵的一个静默 bug。
        Pipeline::enter($this->pipeline);

        // ★ 记下"用户动了一下"的时刻 —— `after()` 的基准要取它（见 `$lastInputMs`）。
        //   放在最前面：失活事件也要记（它同样是"用户动了一下"）。
        if ($timestampMs !== null) {
            $this->lastInputMs = $timestampMs;
        }

        // ★ 点击链（2026-10-27）：把"第几次点击"标在事件上，供控件判双击。
        //   **只在有时间戳时标**：没有时间就没有间隔，"这一下是第几次"无从谈起 ⇒
        //   保持默认 `1`（测试与解释器不传时间戳，行为与之前逐字节相同）。
        //   放在这里而不是路由层：时间是壳给 Application 的，路由层不持钟。
        if ($event instanceof PointerEvent && $timestampMs !== null) {
            $event = $this->taps->annotate($event, $timestampMs);
        }

        // ★ 输入模态（`:focus-visible`，W8-5 续作六）：最近一次是键盘还是指针。
        //   键盘 ⇒ 显示焦点环；指针（点击 / 移动 / 滚轮）⇒ 隐藏。
        //   ★ 放在最前面：失活事件不改模态（它不是"用户操作控件"），但它下面会
        //     提前 return，别让模态逻辑被它跳过。
        //   ★★ **只在真的翻转时举手**（2026-11-02，D1）：模态是焦点环目标的另一半
        //     （`focusRingTarget()` = `keyboardFocusVisible ? focused() : null`），
        //     而环不经过 `setState` ⇒ 没有脏信号。以前靠 `wantsFrame()` 每轮重问
        //     兜住；现在变化点自己 `scheduleFrame()`。无脑每次都调就是空喊 ——
        //     鼠标移动是最高频的事件，那会让帧时钟永不上闸。
        if ($event instanceof KeyEvent) {
            if (!$this->keyboardFocusVisible) {
                $this->keyboardFocusVisible = true;
                $this->pipeline->scheduleFrame();
            }
        } elseif ($event instanceof PointerEvent || $event instanceof ScrollEvent) {
            if ($this->keyboardFocusVisible) {
                $this->keyboardFocusVisible = false;
                $this->pipeline->scheduleFrame();
            }
        }

        // ★ 失活**先于**"未挂载"守卫：窗口可能在拆卸途中失去前台（Alt+Tab 关窗），
        //   那一刻树可能已经没了，但手势状态仍要清 —— 它不依赖树。
        // ★ 只收口手势，**不关浮层**（W3 裁决：失焦 ≠ 放弃，`06` §10）。
        if ($event instanceof WindowDeactivatedEvent) {
            // 释放路径 ③（`InputRouter` 的 `$captureNode` 注释）：指针手势的全部
            // 收口信号都来自指针自己（`up` / `leave`），而"拖到一半切走"两个都不会来
            // （系统不发 `WM_MOUSEMOVE`，`WM_LBUTTONUP` 落在别的窗口）
            // ⇒ 必须靠窗口级信号补这一刀。
            $this->input->cancelActiveGestures();

            // 点击链同样断：失活期间不会再有"同一串点击"的第二下
            $this->taps->reset();

            return null;
        }

        $root = $this->root;
        $focus = $this->focus;

        if ($root === null || $focus === null) {
            return null;   // 未挂载：没有树就没有路由
        }

        // ★ UIA 的"可操作"半边（§9 / M5）：读屏要求激活一个语义节点。
        //   走 `dispatchInput()` 而不是壳直接回调 —— 平台把它翻成一条**输入事件**
        //   （`SemanticsInvokeEvent`），于是它与其他事件同队、同轮循环，激活引起的
        //   重建由紧随的 `FrameClock::tick()` 落盘。见该事件的类注释。
        if ($event instanceof SemanticsInvokeEvent) {
            $this->invokeSemanticsNode($event->index);

            return null;   // 不是指针事件 ⇒ 不改光标
        }

        // ★ UIA 的"可写"半边（§4.4）：读屏要求把某个语义节点的值改成 X。
        //   与 Invoke 同一条队列/同轮循环；区别只在落到 `setSemanticsValue()`
        //   （不派发点击 —— `SetValue` 不是"点一下"）。
        if ($event instanceof SemanticsSetValueEvent) {
            $this->setSemanticsValue($event->index, $event->value);

            return null;
        }

        if ($event instanceof ThemeChangedEvent) {
            // §9.2 事件通路的终点：显式消费（不落 default 丢弃）。
            //
            // ★ 顺序要紧：**先钩子、再下发**。`onSystemThemeChanged()` 是应用
            //   重读系统主题、更新自己 `theme()` 来源的地方；它跑完，`theme()`
            //   才是新值 ⇒ 这之后下发的 context 才带着新主题。
            $this->onSystemThemeChanged();
            $this->applyNewContext();

            return null;
        }

        return $this->input->dispatch($event, $root, $focus);
    }


    /**
     * 焦点环 —— **应用级叠加**，不侵入任何控件（W8-5 续作五）。
     *
     * ★ 为什么挂在 `Frame::run()` 的 afterPaint：树与浮层都画完之后再画，环才压在
     *   最上层（浮层里的焦点也盖得住）。
     * ★ 为什么不做成 `Modifier` 或各控件自绘：那要么每个盒都得知道"我在焦点上"
     *   （盒状态跨重建会丢，见 `State::didChangeFocus()` 的注释），要么给
     *   `RenderObject` 注入焦点查询 —— 都比这一处多。
     * ★ 形状由盒回答（`outlineRadius()`），这里只决定画不画与颜色：主题 `Accent`
     *   2px 外扩描边，圆角跟随控件（§50）。
     */
    /**
     * 焦点环的**目标**（`null` = 本帧不画）。
     *
     * ★ 一个纯函数收口两处消费者：`paintFocusRing()` 画它、`wantsFrame()` 比对它。
     *   分开写两份判据会出现"焦点变了但环不该画（指针模态）"却仍请求一帧的死循环。
     */
    private function focusRingTarget(): ?Element
    {
        return $this->keyboardFocusVisible ? $this->focus?->focused() : null;
    }

    private function paintFocusRing(Canvas $canvas): void
    {
        $focused = $this->focusRingTarget();

        if ($focused === null) {
            return;
        }

        $box = $focused->effectiveBox();

        if ($box === null || !$box->hasSize()) {
            return;
        }

        $box->paintFocusOutline(
            $canvas,
            Paint::stroke($this->theme()->color(ColorToken::Accent), 2.0),
        );
    }

    /**
     * 派发一条命令（§11.4.3 全链）。
     *
     * 顺序刻意如此：**树内冒泡优先于应用级注册表** —— 页面自己最知道这条命令
     * 是不是它的（`users.refresh` 与列表页的关系，框架不该猜）。返回 true 就停。
     *
     * ★ **第 ①② 环从根开始深度优先**：现在还没有 hitTest（`Input` 层属 M3），
     *   所以"命中的 Element"这一环暂时由根遍历代替 —— 语义等价（都是"从具体
     *   往一般找"），等 hitTest 落地只改这一处。
     *
     * @api
     */
    public function dispatch(string $name, ?CommandArg $arg = null): bool
    {
        // ★ 同 `dispatchInput()`：命令 handler 里的 `setState()` 也是帧外写。
        Pipeline::enter($this->pipeline);

        $root = $this->root;
        $handled = false;

        if ($root !== null && $this->bubble($root, $name, $arg ?? CommandArg::none())) {
            $handled = true;
        } else {
            $handled = $this->router->dispatch($name, $arg ?? CommandArg::none());
        }

        // §9.1 关闭语义 #2：**命令分发之后才关**浮层 —— 菜单项的处理逻辑可能
        // 自己开关浮层（例如"新建"打开另一个面板），此时先关会把它关掉。
        // ★ **模态不走这条**（§A.3.7「关不关归应用」）：模态里的按钮派完命令，
        //   模态必须还在 —— 否则"确认删除"的对话框会在命令执行前消失，而
        //   "校验失败就留在框里"这种正确写法直接做不到。菜单则相反：项点完
        //   就关是菜单的语义（§9.1 语义 #2）。
        if ($handled && $this->overlayHost->isMenuOpen() && !$this->overlayHost->isModalOpen()) {
            $this->overlayHost->closeMenu();
        }

        return $handled;
    }

    /** 深度优先找第一个接得的 `State`（第 ①② 环） */
    private function bubble(Element $element, string $name, CommandArg $arg): bool
    {
        if (States::of($element)?->handleCommand($name, $arg) === true) {
            return true;
        }

        $accepted = false;
        $element->visitChildren(function (Element $child) use ($name, $arg, &$accepted): void {
            if (!$accepted && $this->bubble($child, $name, $arg)) {
                $accepted = true;
            }
        });

        return $accepted;
    }

    /**
     * @internal 测试与 devtools 用：注册一个 handler（不必进构造器）
     */
    public function register(string $name, CommandHandler $handler): void
    {
        $this->router->on($name, $handler);
    }

    // ── 应用内拖放（§A.3.5）：声明住 Widget，状态机住 InputRouter ────────

    /**
     * 拖动状态机（§A.3.5）—— 委托 `InputRouter`（S1 第 6 步）。
     *
     * ★ **真机指针序列已接线**（2026-10-17，§24.2 #16）：`down` 在 `Draggable`
     *   上记阈值起点（`beginPendingDrag`，**按下 ≠ 拖动**），`move` 过
     *   `DRAG_THRESHOLD` 才 `begin`，`up` 放下，`leave`/ESC 取消。宿主 / 测试
     *   经这四个壳入口直调（与 `Spinner::tick()` 同一个分期形态）。
     */
    public function drag(): DragController
    {
        return $this->input->drag();
    }

    /**
     * @internal 开始拖动（源 = 最近命中的 Element；命中处须有 `Draggable` 声明）
     */
    public function beginDrag(Draggable $draggable): bool
    {
        return $this->input->beginDrag($draggable);
    }

    /**
     * @internal 指针移动：刷新悬停目标（**目标变化才通知** `onHover`）
     */
    public function updateDrag(float $x, float $y): void
    {
        $this->input->updateDrag($x, $y);
    }

    /**
     * @internal 放下：命中 `DragTarget` ⇒ 交出负载。
     *
     * @return bool 是否被接收
     */
    public function dropDrag(): bool
    {
        return $this->input->dropDrag();
    }

    /** @internal 取消拖动（ESC 也会cancel —— 见 `dispatchInput()`） */
    public function cancelDrag(): void
    {
        $this->input->cancelDrag();
    }


    /**
     * @internal 测试：把根盒拿出来做几何断言
     *
     * ★ 用 `effectiveBox()`：应用根常是 `StatelessWidget`（无盒），能排的是它
     *   底下的容器 —— 用 `renderObject()` 会得到 null，看不出"到底是没排开
     *   还是根本没有可绘制内容"。
     */
    public function rootBox(): ?RenderBox
    {
        return $this->root?->effectiveBox();
    }
    // ------------------------------------------------------------------
    // 浮层 API 委托壳（S1 第 8 步）：实现搬进 {@see OverlayManager}，
    // **API 位置不动** —— 应用 / 组合根 / 测试照旧按 Application 调。
    // 内部调用点（帧循环 / hover 回调 / 失活收口）直调片，不经这些壳。
    // ------------------------------------------------------------------

    /**
     * 打开浮层面板（O-20 方案 A：第二棵根渲染树）。
     *
     * @param float|null $atX 指针位置（客户区坐标）；给了 ⇒ 弹在这一点（右键菜单）。
     *                        不下传则按 `$anchor` 的盒落位（菜单栏下拉）。
     * @api
     */
    public function showMenu(
        Widget $panel,
        ?Element $anchor = null,
        ?float $atX = null,
        ?float $atY = null,
    ): void {
        $this->overlayHost->showMenu($panel, $anchor, $atX, $atY);
    }

    /** 关掉浮层（已关 ⇒ 无事发生；幂等） */
    public function closeMenu(): void
    {
        $this->overlayHost->closeMenu();
    }

    /**
     * 弹一条底部通知（W8-2）：第一条立即显示，后面的排队（FIFO）。
     *
     * @api
     */
    public function showSnackbar(Snackbar $snackbar): void
    {
        $this->overlayHost->showSnackbar($snackbar);
    }

    /** 收掉当前这条通知（有排队 ⇒ 立刻上队首那条）。幂等。 */
    public function dismissSnackbar(): void
    {
        $this->overlayHost->dismissSnackbar();
    }

    /** 当前是否有通知在显示（测试 / devtools） */
    public function hasSnackbar(): bool
    {
        return $this->overlayHost->hasSnackbar();
    }

    /** 排队等待的通知条数（测试 / devtools） */
    public function snackbarQueueLength(): int
    {
        return $this->overlayHost->snackbarQueueLength();
    }

    /** 当前通知的条目（测试 / devtools 读参数用） */
    public function snackbarEntry(): ?OverlayEntry
    {
        return $this->overlayHost->snackbarEntry();
    }

    /**
     * 浮层栈本体（`01` §8.1）—— 测试 / devtools 的正式入口。
     *
     * @api
     */
    public function overlays(): OverlayStack
    {
        return $this->overlayHost->overlays();
    }

    /**
     * 菜单是否开着：栈里存在 `barrier=true && scrim=false` 的条目（`01` §8.1）。
     *
     * ★ **模态不算菜单**：模态是 `scrim=true` ⇒ 不满足条件。
     */
    public function isMenuOpen(): bool
    {
        return $this->overlayHost->isMenuOpen();
    }

    /**
     * 打开模态（§A.3.7 / §12.1 第 6 条）—— 与菜单**共用**同一个浮层槽位。
     *
     * @api
     */
    public function showModal(Modal $modal): void
    {
        $this->overlayHost->showModal($modal);
    }

    /**
     * 关掉模态（幂等；没开 ⇒ 无事发生）。
     *
     * @api
     */
    public function closeModal(): void
    {
        $this->overlayHost->closeModal();
    }

    /**
     * 按条目关浮层 —— `InputRouter` / `OverlayLayout` 两片的契约口子。
     *
     * @internal
     */
    public function dismissOverlay(OverlayEntry $e): void
    {
        $this->overlayHost->dismissOverlay($e);
    }

    /** 模态是否开着（测试 / devtools / 快捷键门控） */
    public function isModalOpen(): bool
    {
        return $this->overlayHost->isModalOpen();
    }

    /** 当前模态（测试 / devtools / 应用读 `dismissible`） */
    public function modal(): ?Modal
    {
        return $this->overlayHost->modal();
    }

    /**
     * 开 / 关控件声明的面板（W8-2 Select）—— `InputRouter` 的通用缝。
     *
     * @internal 片间契约口子，不是应用扩展点
     */
    public function toggleWidgetPanel(Element $anchor, \Closure $factory): void
    {
        $this->overlayHost->toggleWidgetPanel($anchor, $factory);
    }
}
