<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\App\Io\IoPoller;
use Drift\Commands\CommandArg;
use Drift\Commands\CommandHandler;
use Drift\Commands\CommandRouter;
use Drift\Core\Pipeline;
use Drift\Debug\Session\Recorder;
use Drift\Errors;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Input\AcceleratorTable;
use Drift\Input\CursorShape;
use Drift\Input\InputEvent;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Input\ScrollEvent;
use Drift\Input\SemanticsExpandEvent;
use Drift\Input\SemanticsInvokeEvent;
use Drift\Input\SemanticsScrollEvent;
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
use Drift\Widget\SemanticsExpandable;
use Drift\Widget\SemanticsValueEditable;
use Drift\Theme\ThemeData;
use Drift\Widget\BuildContext;
use Drift\Widget\Drag\Draggable;
use Drift\Widget\Element;
use Drift\Widget\FocusHost;
use Drift\Widget\Modal;
use Drift\Widget\SemanticsBuilder;
use Drift\Widget\Snackbar;
use Drift\Widget\State;
use Drift\Widget\States;
use Drift\Widget\Widget;
use Drift\Widget\WindowSize;

/**
 * 每窗口运行时：根树、帧循环、焦点、输入、浮层、语义与动画记账。
 *
 * 应用声明（`buildRoot()` / `commands()` / 生命周期钩子）留在 `Application`；
 * 本类只经其公开入口取声明，不把帧循环反向暴露给页面。
 *
 * @internal 由 `Application` 持有；应用代码不应直接引用。
 */
final class AppShell implements TextEditingHost, InputHost, OverlayHost, PumpHostHost, FocusHost
{
    private ?Element $root = null;

    private ?Frame $frame = null;

    /** 焦点链宿主。焦点是渲染态、只能有一份，故由本类持有而不外部注入。 */
    private ?FocusManager $focus = null;

    /** 输入模态：`true` = 最近一次是键盘（`:focus-visible` 的依据，初值 false）。 */
    private bool $keyboardFocusVisible = false;

    /** 上一次真的画上屏的环目标（`null` = 上次没画） */
    private ?Element $paintedFocus = null;

    /**
     * 调试叠加层要描边的元素（`null` = 没有）。由调试会话经
     * {@see \Drift\App\Application::setDebugHighlight()} 设置。
     *
     * ★ 存 `Element` 而不是节点号：号是调试层的词汇（`Recorder` 发、每会话重发），
     *   壳读不懂它，也不该为它去依赖 `Debug\Session` 的查找。
     */
    private ?Element $debugHighlight = null;

    /** 上一次真的画上屏的叠加目标（与 `$paintedFocus` 同一个口径，同一类记账） */
    private ?Element $paintedHighlight = null;

    /**
     * 这个窗口的录制器（`null` = 没在录）。
     *
     * ★ 录制器**属于应用**而不是进程：一个进程可以有两个窗口，而一份录制器同时收
     *   两个窗口的帧就是串证据（节点表混进别人的节点、帧号被别人的帧推走、指纹跟着变）。
     *   存这里而不是每次问静态的"当前录制器"：静态那个在帧外没有窗口上下文，问到谁
     *   全看最后一次装的是谁。见 `Recorder::bindTo()`。
     */
    private ?Recorder $debugRecorder = null;

    /**
     * 帧出口观察者（`null` = 没人看）。在 `afterPaint` 最末调用，画布尚有效。
     *
     * ★ 存在的唯一理由是"给画面一个出口"：像素只有这一刻拿得到（`endFrame()` 之后
     *   画布就被释放了）。它**不参与任何决策** —— 不标脏、不请求帧、不改 `paintRequested`，
     *   所以挂上它对被测对象零影响。持 `Canvas` 而不是具体后端类型，是为了不让
     *   壳认识 Raster / Skia。
     *
     * @var null|\Closure(Canvas, int): void
     */
    private ?\Closure $frameObserver = null;

    /**
     * 有人要一张图，但树没变 ⇒ 这一帧本来会整段跳过绘制（`Frame::run()` 的快路）。
     * 置上它，下一帧强制画一次，画完就清。
     *
     * ★ 与"环形重绘"不同：它是**一次性**的，且只由观察者触发。没有它的话，
     *   一个已经静止的应用永远拍不到第一张图 —— 画面通道对"起来就不动的窗口"
     *   完全失效，而那恰好是最常见的观察场景。
     */
    private bool $paintOnDemand = false;

    /** UI 线程 id（`GetCurrentThreadId`，`attach()` 时记；`getmypid()` 区分不了线程）。 */
    private int $uiThreadId = 0;

    private CommandRouter $router;

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

    /** 窗口级环境门面（notify/delay/modal），构造期随两个宿主片段一起建好。 */
    private readonly AppEnvironment $environment;

    /** 语义树消费闭包。`null` ⇒ 不建语义树（零开销）。 */
    private ?\Closure $semanticsSink = null;

    /** 连续超预算的帧数（0 = 本帧在预算内） */
    private int $overBudgetStreak = 0;

    /** 是否处于"超预算"状态（连续 {@see Application::OVER_BUDGET_FRAMES} 帧超了 ⇒ true） */
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
    private const int SEMANTICS_COALESCE_MAX = 4;

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

    /** 本帧事实（段名 / 是否跳过 settle、paint），仅录制开启时建；`null` = 没开录制 */
    private ?array $devFacts = null;

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

    /**
     * @param iterable<string, CommandHandler> $handlers 构造期注册的本窗 handler
     * @param \Closure(): void $onSystemThemeChanged 应用钩子；主题事件在帧内到达时调用
     */
    public function __construct(
        private readonly Application $app,
        ?AppScope $scope,
        iterable $handlers,
        private readonly \Closure $onSystemThemeChanged,
    ) {
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

        // 剪贴板传覆写点的闭包，不直连 scope：编辑命令环也走 app->clipboard()，
        // 门面走同一处才不会在应用覆写后读到一个不同的端口。
        $this->environment = new AppEnvironment(
            $this->overlayHost,
            $this->pumps,
            $this->scope,
            fn(): ?Clipboard => $this->app->clipboard(),
        );
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

    /**
     * `FocusHost` 的实现：控件层 `Element::requestFocus()` 唯一落到的地方。
     *
     * 守卫是必需的：`attach()` 里 `FocusManager` 在 `mount()` **之后**才建，
     * 而 `setFocusHost($this)` 在之前 ⇒ 首帧挂载期间真要是有请求，这里是 null。
     */
    public function requestFocus(Element $target): void
    {
        $this->focus?->requestFocus($target);
    }

    /** 应用作用域 —— 多窗口时把同一个实例递给每个 `Application`（共享字体 / 线程池 / IO / 主题）。 */
    public function scope(): AppScope
    {
        return $this->scope;
    }

    /** 主题：走应用覆写点；默认实现取 `AppScope`。 */
    public function theme(): ThemeData
    {
        return $this->app->theme();
    }

    /**
     * 已挂载的后台端口（`null` = 未挂载或应用声明不用）。
     *
     * @internal 测试与 devtools 读它；应用走 {@see Application::submit()}，不要碰后端。
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

    /** 加速键表：走应用覆写点；默认实现取 `AppScope`。 */
    public function accelerators(): AcceleratorTable
    {
        return $this->app->accelerators();
    }

    /** 剪贴板：走应用覆写点；默认实现取 `AppScope` 的端口槽。 */
    public function clipboard(): ?Clipboard
    {
        return $this->app->clipboard();
    }

    /** 本窗能力门面（浮层 / 定时器 / 对话框 / 剪贴板 / 窗口拖动）。 */
    public function environment(): AppEnvironment
    {
        return $this->environment;
    }

    /** 字体度量：走应用覆写点；默认实现取 `AppScope`。 */
    public function metrics(): TextMetricsProvider
    {
        return $this->app->metrics();
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
     */
    public function attach(RenderSurface $surface, BoxConstraints $constraints, ?BuildContext $context = null): void
    {
        // 之后的任何线程断言都以此为准。
        $this->uiThreadId = \drift_thread_id();

        // 挂载期就写这条管线：`createElement()` / `mount()` 会推结构版本号，不切会记到别的应用账上。
        Pipeline::enter($this->pipeline);

        // 焦点宿主记在**管线**上（不是 `Element` 的字段）⇒ 顺序无关，且浮层那棵
        //   独立的根自动共用同一份 ⇒ 不需要在 `OverlayManager` 里再设一次。
        $this->pipeline->setFocusHost($this);

        // 第一个窗口把主题灌进 scope，后续窗口必须一致（不一致 ⇒ DIAGNOSTIC）。
        // `attach()` 可重入 ⇒ 同一实例只登记一次，否则第二次 attach 会误报"第 2 个窗口"。
        if (!$this->scopeEntered) {
            $this->scopeEntered = true;
            $this->scope->enter($this->theme());
        }

        // 端口 / 轮询器 / 异步 HTTP 从作用域取；`attach()` 只缓存引用，不负责建。
        $this->pumps->attach($this->scope->taskHost(), $this->scope->io(), $this->scope->asyncHttp());

        $context ??= new BuildContext(
            $constraints->maxWidth,
            $constraints->maxHeight,
            $this->theme(),
            $this->metrics(),
        );

        // 门控与 handler 同一写者；孤立门控（无命令）留痕，避免"声明了却永不生效"。
        $gates = [];
        foreach ($this->app->commandGates() as $name => $gate) {
            $gates[(string) $name] = $gate;
        }

        // 此刻子类字段已就位，`commands()` 才可信。重复注册会 Fatal ⇒ 必须可重入。
        foreach ($this->app->commands() as $name => $handler) {
            $name = (string) $name;
            if (!$this->router->has($name)) {
                $this->router->on($name, $handler, $gates[$name] ?? null);
            }
        }

        foreach ($gates as $name => $_) {
            if (!$this->router->hasInChain($name)) {
                Errors::report(sprintf('命令门控 %s 没有对应的 handler（门控不会生效）', $name), Errors::DIAGNOSTIC);
            }
        }

        $root = $this->app->buildRoot()->createElement();
        $root->mount(null, $context);
        $this->root = $root;

        $this->frame = new Frame($surface, $this->pipeline);

        // 首帧必须走 `frame()` 而非 `Frame::run()`：后者绕过 `tickAnimations()`，纯动画应用
        // 挂载后 `wantsFrame()` 仍为 false ⇒ 帧时钟不再上膛 ⇒ 转圈图一辈子不动。
        // 入口取应用的 `frame()` 而非壳自己的：覆写帧循环是既有扩展点，壳不能绕过它。
        $this->app->frame(0.0, $constraints);

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
     * 全部渲染树根 + 各自的视口原点：主树在前，浮层按栈序在后。
     *
     * 与 {@see self::treeRoots()} 同一份数据，只是把浮层偏移一起带出来 —— 点选反查
     * 要按 `InputRouter` 的口径把视口坐标减掉根原点。两个出口共用这个唯一收集点，
     * 不在 Debug 层再写第二处浮层遍历（A43 ⑥ / A44）。
     *
     * @internal 经 {@see \Drift\App\Application::treeRootOrigins()} 给查询面用
     *
     * @return list<array{element: Element, x: float, y: float}>
     */
    public function treeRootOrigins(): array
    {
        $roots = [];
        $root = $this->root;
        if ($root !== null) {
            $roots[] = ['element' => $root, 'x' => 0.0, 'y' => 0.0];
        }

        foreach ($this->overlayHost->overlays()->all() as $entry) {
            if ($entry->element !== null) {
                $roots[] = [
                    'element' => $entry->element,
                    'x' => $entry->x,
                    'y' => $entry->y,
                ];
            }
        }

        return $roots;
    }

    /**
     * 本应用的全部渲染树根：主树在前，浮层按栈序在后（`null` = 不在树里）。
     *
     * ★ 浮层是**第二棵树**。主题下发 / 窗口档下发 / 焦点链 / 动画发现四处都要走它，
     *   原先各写一遍 `foreach (overlays()->all())` —— "漏了浮层"是反复踩到的那类坑
     *   （菜单里的 Spinner 不转、菜单项 Tab 不到、换了主题菜单不变），且都不报错。
     *
     * @internal 经 {@see \Drift\App\Application::treeRoots()} 给查询面用。
     *
     * @return list<Element>
     */
    public function treeRoots(): array
    {
        $roots = [];
        foreach ($this->treeRootOrigins() as $entry) {
            $roots[] = $entry['element'];
        }

        return $roots;
    }

    /**
     * 把新环境下发到**每一棵**树。
     *
     * ★ `$derive` 从主树的旧环境算出新环境，两棵树共用同一个结果：浮层与主树必须
     *   同主题 / 同窗口档，否则菜单与页面各画一套。
     */
    private function pushContext(\Closure $derive): void
    {
        $root = $this->root;
        if ($root === null) {
            return;
        }

        $context = $derive($root->context());
        foreach ($this->treeRoots() as $element) {
            $element->applyContext($context);
        }
    }

    /** 把新的渲染环境下发到每一棵树。
     *
     * 主题值与旧值 `===` 时 `applyContext()` 幂等短路 ⇒ 不跟随系统主题的应用零重建成本。
     */
    private function applyNewContext(): void
    {
        $this->pushContext(fn(BuildContext $context): BuildContext => $context->withTheme($this->theme()));
    }

    /**
     * 窗口**跨档**了就把新尺寸下发（档内不动）。
     *
     * ★ 档内不重建是有意的：拖动 resize 是高频路径（平台在模态尺寸循环里同步调回），
     *   逐像素重建全树会卡；而"档位不变 ⇒ 布局结论不变"，重排就够了。
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

        $this->pushContext(fn(BuildContext $context): BuildContext => $context->withWindow(
            $constraints->maxWidth,
            $constraints->maxHeight,
            $next,
        ));
    }

    /**
     * 跑一帧：平台每帧调一次。
     *
     * @param float $timestampMs 注入的时间戳（不读环境时钟）
     *
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

        // 绘制域是可选预算：只有当前录制器显式开了它，才把观察者接到管线上。
        // 每帧设一次而不是安装时设一次：会话可能在两个帧之间开 / 关。
        $recorder = Recorder::current();
        $this->pipeline->setPaintObserver(
            $recorder !== null && $recorder->options()->paint() ? $recorder : null,
        );

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
        $this->devFacts = Recorder::installed() ? ['stages' => [], 'settleSkipped' => false, 'paintSkipped' => false] : null;

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

        // ④ 同理。busy 在帧末 `continueFrame()` 里转成 `scheduleNextFrame()`；`stage()` 返回
        // `mixed`，这里把类型收敛回 `bool`。
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
        if ($this->devFacts !== null) {
            $this->devFacts['stages'][] = FrameStage::SETTLE;
        }

        // 本帧要不要画：`Frame` 只看树，看不见应用级叠加 —— 焦点环目标变了没画、
        // 高亮目标变了没画、浮层锚点换了但面板还落后。取跑帧前的目标：paint 只会
        // 画跑帧前那个环 / 那个高亮。
        //
        // ★ 高亮必须并进这一条判据。只把它交给 `afterPaint` 而不管 `paintRequested`，
        //   表现是"设了高亮但屏幕上什么都没有"：`Frame` 发现树没变就整帧跳过，
        //   闭包根本不跑。这类静默只有把失效信号一起并进来才堵得住。
        $ringBefore = $this->focusRingTarget();
        $highlightBefore = $this->debugHighlight;
        $paintRequested = $this->paintOnDemand
            || $ringBefore !== $this->paintedFocus
            || $highlightBefore !== $this->paintedHighlight
            || $this->overlayLayout->panelsLagBehind();

        // 窗口帧：约束来自客户区，"内容比窗口大"是合法状态 ⇒ C5/C6 降级为裁剪 + diagnostic，
        // 不中止进程。直接调 `Frame::run()` 的测试仍按严格口径，写错布局照样红。
        $painted = LayoutTolerance::withinWindow(fn(): bool => $frame->run($root, $constraints, $timestampMs, $this->overlayHost->overlays()->all(), function (Canvas $canvas) use ($highlightBefore): void {
            $this->paintOverlay($canvas, $highlightBefore);
        }, $paintRequested));

        // 真的上屏才记账；跳过或 paint 抛异常 ⇒ 不记账 ⇒ 下一帧继续请求。
        if ($painted) {
            $this->paintedFocus = $ringBefore;
            $this->paintedHighlight = $highlightBefore;
            // 一次性：画成就清。留着不清就成了"每帧都强制重画"，静止的应用空转烧 CPU。
            $this->paintOnDemand = false;
        }

        // ⑤ 迟到挂载补 tick：④ 在 settle 之前跑，而页面挂在 settle 段 ⇒ 新挂上的 State 当帧
        // 从未被 tick ⇒ `$animating` 为假 ⇒ 帧时钟不上膛 ⇒ 之后没输入就永远静止。
        // 只 tick 登记表（其他 State 已在 ④ 访问过），并跳过已卸载的。
        $this->stage(FrameStage::TICK_LATE_MOUNT, function () use ($timestampMs): void {
            foreach ($this->pipeline->takeMounted() as $mountedState) {
                $mountedElement = $mountedState->element();
                if ($mountedElement === null || !$mountedElement->isActive()) {
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
        $this->stage(FrameStage::FOCUS_COLLECT, function (): void {
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
            $this->focus?->collect($this->treeRoots(), $this->overlayHost->focusTrapRoots());
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
        if ($this->devFacts !== null) {
            $this->devFacts['stages'][] = $stage;
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
        // 两条出口（正常 / 零视口）都会到这里，跳过标记只在这里落一次。
        if ($this->devFacts !== null) {
            $this->devFacts['settleSkipped'] = $this->pipeline->settleSkipCount() > $settleSkipBefore;
            $this->devFacts['paintSkipped'] = $this->pipeline->paintSkipCount() > $paintSkipBefore;
        }

        if ($this->animating || $this->pumps->hasPolledAsync()) {
            $this->pipeline->scheduleNextFrame();
        }

        if ($this->focusRingTarget() !== $this->paintedFocus
            || $this->debugHighlight !== $this->paintedHighlight
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
     * 由平台壳的拖动重排入口调（`src/Platform/Win32/ResizeHook.php`）
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
        // ★ 必须先切管线：下面的 `placeOverlays()` 会经 `RenderBox::setBase()` /
        //   `bumpStructure()` 往 `Pipeline::current()` 记账。多窗口下若不切，这一轮
        //   重排的 paintEpoch 记到**上一个跑帧的窗口**账上 ⇒ 本窗静默不重绘
        //   （补：这是「进帧必 enter」铁律漏的第 5 个入口）。
        Pipeline::enter($this->pipeline);

        // 档位若变了得先重建树 —— 放在跑帧之前，同帧生效（否则会闪一帧旧布局）。
        $this->adoptWindowSize($constraints);

        LayoutTolerance::withinWindow(function () use ($constraints, $frame, $root): void {
            $this->overlayLayout->placeOverlays($constraints);
            $highlight = $this->debugHighlight;
            $painted = $frame->run(
                $root,
                $constraints,
                $this->lastTimestampMs,
                $this->overlayHost->overlays()->all(),
                function (Canvas $canvas) use ($highlight): void {
                    // `$record = false`：拖动重排这一帧**不进录制**（没有 begin/endFrame），
                    // 往 `devFacts` 里写一笔只会被下一帧覆盖 —— 靠"别人会重置"成立的
                    // 正确性是隐式的，这里显式关掉。
                    $this->paintOverlay($canvas, $highlight, false);
                },
            );
            // 重排这条路也要记账：不记的话，下一帧的 `paintRequested` 仍认为高亮没上屏，
            // 白画一次（并让 `overlayDrawn` 说"没上屏"而实际已经在了）。
            if ($painted) {
                $this->paintedHighlight = $highlight;
            }
        });
    }

    // ------------------------------------------------------------------
    // 异步泵 API 委托壳（S1 第 9 步）：实现搬进 {@see PumpHost}，
    // **API 位置不动** —— 应用 / 组合根 / 测试照旧按 Application 调。
    // frame() 的 PUMP_* 段直调 PumpHost，不经这些委托壳。
    // ------------------------------------------------------------------

    /**
     * 排一个一次性延时回调。
     *
     * 时间基准是上一帧的时间戳，不是环境时钟；在 `frame()` 之外调用会早一个帧间隔（≤一帧）。
     * 回调在 `frame()` 内、settle 之前跑 ⇒ 里面的 `setState()` 同帧生效。
     * 有定时器在等 ⇒ `nextDeadlineMs()` 给等待层一个到期时刻，按时叫醒阻塞的消息泵。
     *
     * @param int $milliseconds 延时（毫秒；负数按 0 处理 ⇒ 下一帧就触发）
     * @return int 定时器 id（`cancelAfter()` 用）
     *
     */
    public function after(int $milliseconds, \Closure $callback): int
    {
        return $this->environment->delay($milliseconds, $callback);
    }

    /** 撤销一个延时回调（已触发 / id 不存在 ⇒ 无事发生，幂等） */
    public function cancelAfter(int $id): void
    {
        $this->environment->cancelDelay($id);
    }

    /**
     * 本应用还需要下一帧吗 —— 自适应帧时钟的唯一判据。
     *
     * 消息泵是阻塞的，没人说"还有活"，平台就不起定时器。所有来源都有界（任务回贴完、
     * 动画跑完 ⇒ false），故空闲不空转。别在这里加判据 —— 想加就去变更现场调 `scheduleFrame()`。
     *
     * 组合根每帧调一次（`FrameClock` 就是这么用的）
     */
    public function wantsFrame(): bool
    {
        return $this->paintOnDemand || $this->pipeline->wantsFrame();
    }

    /**
     * 欠的账是"立刻跑"那一档吗（像素脏 / 焦点环没画 / 面板落后）。
     *
     * 为真 ⇒ 等待层给 `0`；为假但 {@see self::wantsFrame()} 为真 ⇒ 给帧间隔。
     *
     * `FrameClock::nextWaitMs()` 每轮问一次
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
     * 等待层（`FrameClock::nextWaitMs()`）每轮问一次
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

    /** 本帧事实（段名 / 跳过标记）；没开录制时为空数组。 */
    public function frameFacts(): array
    {
        return $this->devFacts ?? [];
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

        if ($milliseconds <= Application::BUDGET_MS) {
            $this->overBudgetStreak = 0;
            $this->overBudget = false;

            return;
        }

        $this->overBudgetStreak++;
        $this->overBudget = $this->overBudgetStreak >= Application::OVER_BUDGET_FRAMES;

        // 只在刚立起来的那一帧报：每帧报一次会把只有 100 条的环形缓冲刷满。
        if ($this->overBudgetStreak === Application::OVER_BUDGET_FRAMES) {
            Errors::report(sprintf(
                '连续 %d 帧超过 %.1fms 预算（本帧 %.1fms，最差 %.1fms）'
                    . ' ⇒ 语义推送降到每 4 帧一次（焦点变化仍立即推）。'
                    . "\n" . '耗时预算分组：%s —— 超了先砍语义推送，layout / paint / present 永不砍（`06` §3.3）。'
                    . "\n" . '修法：① 打开分段计时看哪一段超了；'
                    . '② 检查本帧是否 rebuild 了整棵子树（动画应该用 setCompose 而不是 markDirty）。',
                Application::OVER_BUDGET_FRAMES,
                Application::BUDGET_MS,
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

    /** 耗时预算分组拼成一行（诊断文案用；数字的唯一来源是 {@see Application::STAGE_BUDGET_MS}） */
    private static function stageBudgetText(): string
    {
        $parts = [];
        foreach (Application::STAGE_BUDGET_MS as $name => $ms) {
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
        // 可聚焦是**框架事实**（`FocusManager` 的两张表），控件自己声明会与
        //   `skipFocus()` / `disabled()` 打架 ⇒ 从这里喂进去（§65）。
        $focusable = $this->focus?->focusableIds();
        $tree = SemanticsBuilder::build($this->root, $focused, $focusable);
        ($sink)($tree->toArrays()['nodes']);
    }

    /**
     * 语义动作的**寻址**：节点索引 → 命中的 Element + 节点中心。
     *
     * ★ Invoke / SetValue / ExpandCollapse 三个 UIA 动作**共用这一处**。索引口径
     *   （`toArrays()['nodes']` 的下标，前序）、零面积拒绝、命中换算是同一件事：
     *   抄成三份，任一份漂了都是"读屏点错格子"，而且**没有报错**。
     * ★ 索引是快照下标：树在两次推送之间变了 ⇒ 可能指到别的节点（与任何 UI 自动化
     *   一样是竞态）；结构变化会立刻整棵重推，窗口很短。
     *
     * @return array{element: Element, x: float, y: float, node: \Drift\Input\SemanticsNode}|null
     *   `null` ⇒ 越界 / 零面积 / 未挂载 / 没命中 —— 消费方回
     *   `UIA_E_ELEMENTNOTAVAILABLE`，不能假装成功
     */
    private function semanticsTarget(int $index): ?array
    {
        $tree = SemanticsBuilder::build($this->root, null);
        if ($index < 0 || $index >= $tree->count()) {
            return null;
        }

        $node = $tree->node($index);
        if ($node->w <= 0 || $node->h <= 0) {
            return null;
        }

        $root = $this->root;
        $focus = $this->focus;
        if ($root === null || $focus === null) {
            return null;
        }

        $x = $node->x + $node->w / 2.0;
        $y = $node->y + $node->h / 2.0;

        // 语义节点几何就是渲染树的逻辑坐标（与命中测试同一套），中心点可直接命中。
        $hit = $focus->hitTest($x, $y, $root);
        $element = $hit === null ? null : $focus->elementAt($root, $hit);

        return $element === null ? null : ['element' => $element, 'x' => $x, 'y' => $y, 'node' => $node];
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
     * @return bool 真的派发了（越界 / 零面积 ⇒ false —— 消费方据此回
     *   `UIA_E_ELEMENTNOTAVAILABLE`，不能假装成功）
     */
    public function invokeSemanticsNode(int $index): bool
    {
        $target = $this->semanticsTarget($index);
        if ($target === null) {
            return false;
        }

        // ★ 合成一次**完整**指针手势：先 move 再 down/up。
        //   真实的鼠标点击永远先有 `WM_MOUSEMOVE`（悬停链先建立）——
        //   "凭空 down"在真机上不激活（`CursorShape` 退默认），别把它当成
        //   "少一条事件无所谓"。
        $this->dispatchInput(PointerEvent::move($target['x'], $target['y']));
        $this->dispatchInput(PointerEvent::down($target['x'], $target['y']));
        $this->dispatchInput(PointerEvent::up($target['x'], $target['y']));

        return true;
    }

    /**
     * 按**语义节点索引**写入一个值（UIA `IValueProvider` / `IRangeValueProvider`
     * 的 `SetValue`，§4.4 提交 2）。
     *
     * ★ **不派发 tap**：`SetValue` 是"把值改成 X"，不是"点一下"。命中后沿父链找
     *   `SemanticsValueEditable`（与 `activateFocusedWidget()` 找 `Activatable` 同构）。
     */
    public function setSemanticsValue(int $index, string $value): bool
    {
        $target = $this->semanticsTarget($index);
        if ($target === null) {
            return false;
        }

        for ($e = $target['element']; $e !== null; $e = $e->parent()) {
            $widget = $e->widget();
            if ($widget instanceof SemanticsValueEditable) {
                return $widget->setSemanticsValue($value);
            }
        }

        return false;
    }

    /**
     * 按**语义节点索引**滚动一个容器（UIA `IScrollProvider`）。
     *
     * ★ 只认主轴：客户端要求滚的百分比落在次轴上（或容器装得下）⇒ false，
     *   回 `UIA_E_NOTSUPPORTED`，不假装成功。
     * ★ 语义节点就声明在容器自己身上，`semanticsScrollMetrics()` 由该 Element 给，
     *   无需沿父链猜 —— 仍保留父链循环以容忍包装层。
     *
     * @return bool 真的滚了（越界 / 无可滚空间 / 轴不符 ⇒ false）
     */
    public function setSemanticsScroll(int $index, float $horizontalPercent, float $verticalPercent): bool
    {
        $target = $this->semanticsTarget($index);
        if ($target === null) {
            return false;
        }

        for ($e = $target['element']; $e !== null; $e = $e->parent()) {
            $metrics = $e->semanticsScrollMetrics();
            if ($metrics === null) {
                continue;
            }

            $pct = $metrics->scrollAxis() === Axis::Horizontal ? $horizontalPercent : $verticalPercent;
            $max = $metrics->maxOffset();
            if ($pct < 0.0 || $max <= 0.0) {
                return false;
            }
            $metrics->scrollTo($max * min(100.0, $pct) / 100.0);

            return true;
        }

        return false;
    }

    /**
     * 按**语义节点索引**展开/折叠一行（UIA `IExpandCollapseProvider`，§65）。
     *
     * ★ **不合成 tap**：行的 tap 是"选中"，展开是另一件事。而箭头那个 `IconButton`
     *   虽然在语义树里，却只是一颗没有身份的按钮 —— 按索引找它比按 Key 找行更脆。
     * ★ `expandState === 0` 直接拒（叶子 / 非集合）：客户端不该对它们发起这条命令，
     *   但它真发来时不能假装成功。
     *
     * @return bool 真的处理了（越界 / 零面积 / 无可展开概念 / 无人接 ⇒ false）
     */
    public function setSemanticsExpanded(int $index, bool $expand): bool
    {
        $target = $this->semanticsTarget($index);
        if ($target === null || $target['node']->expandState === 0) {
            return false;
        }

        for ($e = $target['element']; $e !== null; $e = $e->parent()) {
            $widget = $e->widget();
            if ($widget instanceof SemanticsExpandable) {
                return $widget->setSemanticsExpanded($target['node']->key, $expand);
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
     * ★★ **两条路径，一帧只走一条**：
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
     * ★ 每一棵树都要走（根 + 每个浮层）—— 见 `tickAnimations()` 的第一条。
     */
    private function collectActiveAnimations(float $timestampMs): bool
    {
        // ★ 不是"并入"而是**重写**：全扫看得到整棵树，它给出的表才是完整答案。
        //   并入会让已停止的旧表项永远留着（出表只发生在 tick 返回 false 的那一帧，
        //   而全扫帧里它们未必被问到 —— 它们可能已不在树里）。
        $this->activeTicks = [];

        $busy = false;

        foreach ($this->treeRoots() as $element) {
            if ($this->scanSubtree($element, $timestampMs)) {
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
            // ★ 卸载或**被 Offstage 摘出可见树**即出表（同 takeMounted() 的 isActive() 过滤）：
            //   不跳过就是给隐藏页发帧 —— 动画在没人看时烧 CPU，切回来相位还跑完了。
            if (!$element->isActive()) {
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
     *   O(树)。两处的 busy 在这里合成一个返回值，帧末 `continueFrame()` 据此
     *   记一次 `scheduleNextFrame()`。
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
     */
    public function dispatchInput(InputEvent $event, ?float $timestampMs = null): ?CursorShape
    {
        // ★ 脚本在**唯一漏斗**上记（`Application::dispatchInput()` 只是转发到这里），
        //   不在命令行里记：`--click=x,y` 是命令行语法，引擎看到的是 down + up 两条
        //   事件。记在漏斗上，任何驱动（CLI / 测试 / 真窗口）录出来的脚本形状一致。
        //   ★ 必须在 `$this->taps->annotate()` **之前**（它在 inner 里）：标注是按时间
        //   算出来的结果，脚本记输入、重放重算；记结果会让重放再标一次（双击变三击）。
        Recorder::tapInput($event, $timestampMs);

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

        // ★ 点击链：把"第几次点击"标在事件上，供控件判双击。
        //   **只在有时间戳时标**：没有时间就没有间隔，"这一下是第几次"无从谈起 ⇒
        //   保持默认 `1`（测试与解释器不传时间戳，行为与之前逐字节相同）。
        //   放在这里而不是路由层：时间是壳给 Application 的，路由层不持钟。
        if ($event instanceof PointerEvent && $timestampMs !== null) {
            $event = $this->taps->annotate($event, $timestampMs);
        }

        // ★ 输入模态（`:focus-visible`）：导航键 ⇒ 显示焦点环；
        //   指针（点击 / 移动 / 滚轮）⇒ 隐藏。编辑键不改模态 —— 鼠标点进输入框
        //   再按 Enter / 打字不该凭空冒出蓝框。
        //   ★ 放在最前面：失活事件不改模态（它不是"用户操作控件"），但它下面会
        //     提前 return，别让模态逻辑被它跳过。
        //   ★★ **只在真的翻转时举手**：模态是焦点环目标的另一半
        //     （`focusRingTarget()` = `keyboardFocusVisible ? focused() : null`），
        //     而环不经过 `setState` ⇒ 没有脏信号。以前靠 `wantsFrame()` 每轮重问
        //     兜住；现在变化点自己 `scheduleFrame()`。无脑每次都调就是空喊 ——
        //     鼠标移动是最高频的事件，那会让帧时钟永不上闸。
        if ($event instanceof KeyEvent && $event->isFocusNavigation()) {
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
        //   重建由紧随的 `FrameClock::advance()` 落盘。见该事件的类注释。
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

        // ★ UIA 的"可展开"半边（§65）：读屏在树行上按 `→` / 自动化调
        //   `ExpandCollapsePattern`。与 Invoke 同队列、同轮循环。
        if ($event instanceof SemanticsExpandEvent) {
            $this->setSemanticsExpanded($event->index, $event->expand);

            return null;
        }

        // ★ UIA 的"可滚动"半边：读屏要求把滚动容器滚到某百分比
        //   （`IScrollProvider`）。与上面三条同队列、同轮循环。
        if ($event instanceof SemanticsScrollEvent) {
            $this->setSemanticsScroll($event->index, $event->horizontalPercent, $event->verticalPercent);

            return null;
        }

        if ($event instanceof ThemeChangedEvent) {
            // §9.2 事件通路的终点：显式消费（不落 default 丢弃）。
            //
            // ★ 顺序要紧：**先钩子、再换主题、再下发**。带快照时框架自己按系统
            //   外观换主题；不带（旧形态）则仍由应用覆写 onSystemThemeChanged()
            //   更新自己的主题来源，跑完 theme() 才是新值。
            ($this->onSystemThemeChanged)();
            if ($event->appearance !== null) {
                $this->scope->setTheme(ThemeData::forAppearance($event->appearance));
            }
            $this->applyNewContext();

            return null;
        }

        return $this->input->dispatch($event, $root, $focus);
    }


    /**
     * 焦点环的**目标**（`null` = 本帧不画）。
     *
     * ★ 一个纯函数收口两处消费者：`paintFocusRing()` 画它、`continueFrame()` 比对它
     *   决定要不要 `scheduleFrame()`。分开写两份判据会出现"焦点变了但环不该画（指针模态）"
     *   却仍请求一帧的死循环。
     */
    private function focusRingTarget(): ?Element
    {
        return $this->keyboardFocusVisible ? $this->focus?->focused() : null;
    }

    /**
     * 把焦点环画到最上层 —— **应用级叠加**，不侵入任何控件（W8-5 续作五）。
     *
     * ★ 为什么挂在 `Frame::run()` 的 afterPaint：树与浮层都画完之后再画，环才压在
     *   最上层（浮层里的焦点也盖得住）。
     * ★ 为什么不做成 `Modifier` 或各控件自绘：那要么每个盒都得知道"我在焦点上"
     *   （盒状态跨重建会丢，见 `State::didChangeFocus()` 的注释），要么给
     *   `RenderObject` 注入焦点查询 —— 都比这一处多。
     * ★ 形状由盒回答（`outlineRadius()`），这里只决定画不画与颜色：主题 `Accent`
     *   2px 外扩描边，圆角跟随控件（§50）。
     */
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

        $box->paintOutline(
            $canvas,
            Paint::stroke($this->theme()->color(ColorToken::Accent), 2.0),
        );
    }

    /**
     * 绑定 / 解绑这个窗口的录制器（`null` = 解绑）。
     *
     * ★ 只改"这个窗口归谁记账"，不动 `Recorder::current()`：帧内的当前录制器由
     *   {@see \Drift\App\Application::frame()} 压栈决定，帧外那个是进程默认值。
     *   两件事混在一起写，就会出现"绑定顺手改全局"的副作用 —— 那等于把这次修复
     *   又变回进程单例。
     *
     * @internal 由 `Application::setDebugRecorder()` 转发（调试会话专用）
     */
    public function setDebugRecorder(?Recorder $recorder): void
    {
        $this->debugRecorder = $recorder;
    }

    /** 这个窗口**绑定**的录制器（不走"借无主的"那条兜底）。 */
    public function boundRecorder(): ?Recorder
    {
        return $this->debugRecorder;
    }

    /**
     * 这一帧该用哪一份录制器（`null` = 这一帧不录）。
     *
     * 自己的优先；没有自己的就只能借**无主**的那一份（测试 / bench 手工装的）。
     * 有主的一律不借：那是另一个窗口的证据，借到了就是静默串证据。
     */
    public function frameRecorder(): ?Recorder
    {
        return $this->debugRecorder ?? Recorder::unowned();
    }

    /**
     * 设置调试叠加层的目标（`null` = 清除）。
     *
     * ★ 只改"下一帧画什么"：不标脏、不重建、不重排。高亮不是状态变更，它不该让
     *   settle 跑一遍 —— 想让它上屏靠的是 `frame()` 里的 `paintRequested`，
     *   不是 `setState()`。
     * ★ 同值早退 + 排一帧：高亮恰恰发生在"页面已经稳定、没人会再标脏"的时候，
     *   不主动请求就永远画不出来。
     *
     * @internal 由 `Application::setDebugHighlight()` 转发（调试会话专用）
     */
    public function setDebugHighlight(?Element $element): void
    {
        if ($this->debugHighlight === $element) {
            return;
        }

        $this->debugHighlight = $element;
        $this->pipeline->scheduleFrame();
    }

    /**
     * 应用级叠加层：树与浮层都画完之后、`endFrame()` 之前。
     *
     * 顺序是**高亮在下、焦点环在上**：高亮回答"你选的是这一块"（调试态，持久），
     * 焦点环回答"键盘现在在这里"（交互态，随时消失）。两者压在同一块上时，
     * 更短暂、更该立刻看见的那个在上面。
     *
     * ★ 高亮**不改结构**：不标脏、不重建、不重排，只是在已经画好的画布上多一圈
     *   描边。所以它进不了 `Digest::ofFrame()`（`rebuilt` / `causes` / `stages` /
     *   `skip` 都不该变），"高亮真的上了屏"只能由像素或下面这笔记账来证明。
     *
     * @param bool $record 这一帧是不是录制中的正常帧（决定要不要把"叠加已上屏"报给录制器）
     */
    private function paintOverlay(Canvas $canvas, ?Element $highlight, bool $record = true): void
    {
        $drew = false;

        if ($highlight !== null) {
            $box = $highlight->effectiveBox();
            // 已卸载 / 没有尺寸 ⇒ 这一帧画不出来。**不报**给录制器：让
            // `overlayDrawn()` 停在上一次成功那一帧，读者才分得清"请求了"与"上屏了"。
            if ($box !== null && $box->hasSize()) {
                $box->paintOutline(
                    $canvas,
                    Paint::stroke($this->theme()->color(ColorToken::Warning), 2.0),
                );
                $drew = true;
            }
        }

        $this->paintFocusRing($canvas);

        if ($drew && $record && $this->devFacts !== null) {
            // 只报"画了"，不报"画的谁"：录制器知道当前高亮的是哪个号
            // （`noteHighlight()` 记的），而壳不该为了一笔记账去懂节点号。
            $this->devFacts['overlayDrawn'] = true;
        }

        // 帧出口：画布在 `endFrame()` 里就被释放，这里是**唯一**还能取像素的时刻。
        // 传录制器的帧号（不是 `frameSerial`）—— 观察者要拿它去和 `boxes?frame=N` 配对，
        // 两个计数器各走一条线，混用就是"图与框差一帧"这种谁也没报错的错位。
        if ($this->frameObserver !== null) {
            ($this->frameObserver)($canvas, $this->debugRecorder?->frameId() ?? 0);
        }
    }

    /**
     * 挂/摘帧出口观察者（`null` = 摘）。见 {@see self::$frameObserver}。
     *
     * ★ 不写 `paintRequested`：观察者多一个，帧就该多画一帧 —— 那会让"打开调试视图"
     *   改变应用的帧节奏，而这套系统所有证据都建立在"观察不改变被测对象"上。
     *   静止的应用画面本来就不变，复用它最后一帧即可。
     *
     * @param null|\Closure(Canvas, int): void $observer
     * @internal 由 `Application::setFrameObserver()` 转发（调试会话专用）
     */
    public function setFrameObserver(?\Closure $observer): void
    {
        $this->frameObserver = $observer;
    }

    /**
     * 请求画一帧（一次性）。给画面通道在"应用已经静止"时补上第一张图。
     *
     * ★ 不打破"观察不改变被测对象"：它不改树、不改布局、不产生任何证据，
     *   只让本来就存在的这一帧真的走一遍绘制。帧**号**会 +1，所以调用方必须在
     *   取到图之后重新读帧号，不能拿调用前的号去配对。
     *
     * @internal 调试观察者专用
     */
    public function requestPaint(): void
    {
        $this->paintOnDemand = true;
    }

    /**
     * 派发一条命令（§11.4.3 全链）。
     *
     * 顺序刻意如此：**树内冒泡优先于应用级注册表** —— 页面自己最知道这条命令
     * 是不是它的（`users.refresh` 与列表页的关系，框架不该猜）。返回 true 就停。
     *
     * ★ 第 ①② 环仍从根深度优先，不是从焦点父链上溯：生产侧
     *   `State::handleCommand()` 只有冒烟夹具一个实现，改地址空间是零消费者的
     *   语义变更，须单独裁决。`hitTest` 早已落地，旧注释"等它落地只改这一处"已失效。
     * ★ 门控在冒泡之前判一次 ⇒ 按键 / 点击 / 其它入口共用同一判定。
     *
     */
    public function dispatch(string $name, ?CommandArg $arg = null): bool
    {
        // ★ 同 `dispatchInput()`：命令 handler 里的 `setState()` 也是帧外写。
        Pipeline::enter($this->pipeline);
        $arg ??= CommandArg::none();

        if (!$this->router->enabled($name, $arg)) {
            // 被拦要响亮：按了没反应且查不出原因是最难查的一类 bug。
            // 提前 return 不清浮层 ⇒ 禁用菜单项点完菜单仍开着（与 WPF/Qt 同形）。
            Errors::report(sprintf('命令 %s 当前不可用（门控拒绝）', $name), Errors::DIAGNOSTIC);

            return false;
        }

        $root = $this->root;
        $handled = false;

        if ($root !== null && $this->bubble($root, $name, $arg)) {
            $handled = true;
        } else {
            $handled = $this->router->dispatch($name, $arg);
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
     */
    public function showSnackbar(Snackbar $snackbar): void
    {
        $this->environment->notify($snackbar);
    }

    /** 收掉当前这条通知（有排队 ⇒ 立刻上队首那条）。幂等。 */
    public function dismissSnackbar(): void
    {
        $this->environment->dismissSnackbar();
    }

    /** 当前是否有通知在显示（测试 / devtools） */
    public function hasSnackbar(): bool
    {
        return $this->environment->hasSnackbar();
    }

    /** 排队等待的通知条数（测试 / devtools） */
    public function snackbarQueueLength(): int
    {
        return $this->environment->snackbarQueueLength();
    }

    /** 当前通知的条目（测试 / devtools 读参数用） */
    public function snackbarEntry(): ?OverlayEntry
    {
        return $this->overlayHost->snackbarEntry();
    }

    /**
     * 浮层栈本体—— 测试 / devtools 的正式入口。
     *
     */
    public function overlays(): OverlayStack
    {
        return $this->overlayHost->overlays();
    }

    /**
     * 轻量弹出层是否开着（菜单 / 下拉 / 右键菜单）。
     *
     * 模态有遮罩 ⇒ 不算：混答会让"菜单开着就别响应某快捷键"的判断撒谎。
     */
    public function isMenuOpen(): bool
    {
        return $this->overlayHost->isMenuOpen();
    }

    /**
     * 打开模态（§A.3.7 / §12.1 第 6 条）—— 与菜单**共用**同一个浮层槽位。
     *
     */
    public function showModal(Modal $modal): void
    {
        $this->environment->showModal($modal);
    }

    /**
     * 关掉模态（幂等；没开 ⇒ 无事发生）。
     *
     */
    public function closeModal(): void
    {
        $this->environment->closeModal();
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
