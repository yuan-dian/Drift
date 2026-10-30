<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\App\Io\IoPoller;
use Drift\Commands\CommandArg;
use Drift\Commands\CommandHandler;
use Drift\Commands\CommandRouter;
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
use Drift\Rendering\Paint;
use Drift\Rendering\RenderSurface;
use Drift\Rendering\SurfaceProfile;
use Drift\Geometry\Rect;
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
use Drift\Widget\SemanticsBuilder;
use Drift\Widget\Snackbar;

/**
 * **组合根**（§4.5）：应用只写 `buildRoot()` / `title()` / `commands()`。
 *
 * 它做四件事，别的都不做：① 持有根 Element 与一帧的 orchestrator；② 把
 * `commands()` 注册进 `CommandRouter`；③ **拼完 §11.4.3 的命令派发链** ——
 * 第 ①② 环（树内冒泡）在这里，第 ③ 环是 `CommandRouter`，第 ④ 环是"谁都没接"；
 * ④ 提供 `frame()` 让平台驱动一帧。
 *
 * ★ **它不拥有事件循环**（§5.2 裁决 1）：循环住平台，本类只提供"被告知该跑
 *   一帧时"的入口。所以测试能喂任意帧序列，动画相位 = f(帧时间戳) 才是确定的。
 *
 * ★ **表面是注入的，不是它造的**（§7.2 裁决 4：表面与树互认识）。⇒ 没有
 *   Win32 / Skia 桥也能在 `php.exe` 里跑完整应用（§14 的物质基础）。
 *
 * ```php
 * final class UsersApplication extends Application
 * {
 *     public function buildRoot(): Widget { return UsersRoot::make($this->store); }
 *     public function title(): string { return '用户管理'; }
 * }
 * ```
 *
 * @api
 */
abstract class Application implements TextEditingHost, InputHost, OverlayHost, PumpHostHost
{
    protected ?Element $root = null;

    protected ?Frame $frame = null;

    /**
     * 输入路由宿主（§9.1 的 ②③ 环 + 命中测试）。
     *
     * ★ 它本身是 `@internal` 的实现细节，但**由 `Application` 持有**：路由需要根
     *   Element，而根 Element 住在这里 ⇒ 让调用方自己拿一个 `FocusManager`
     *   会变成"两份焦点状态"（§9.1 的焦点是渲染态，只能有一份）。
     */
    protected ?FocusManager $focus = null;

    /**
     * 输入模态：最近一次是键盘（`true`）还是指针（`false`）—— `:focus-visible` 的依据。
     *
     * ★ 指针（点击 / 移动 / 滚轮）之后**隐藏**焦点环，键盘之后**显示** —— Windows
     *   惯例。否则每次点按钮都闪一圈蓝框。
     * ★ 初值 `false`：启动时没人按过键盘 ⇒ 第一次点击不该看到环。
     */
    private bool $keyboardFocusVisible = false;

    /** 上一次**画过**的环目标（`wantsFrame()` 第七判据；`null` = 上次没画环） */
    private ?Element $paintedFocus = null;

    /**
     * UI 线程身份（`GetCurrentThreadId`，attach 时记下）。
     *
     * ★ 为什么不是 `getmypid()`：同进程所有线程的 pid 相同 ⇒ 区分不了 UI 线程与 worker。
     *   见 DESIGN §17.4.2.1（WPF `VerifyAccess()` 的等价物）。
     */
    protected int $uiThreadId = 0;

    protected CommandRouter $router;

    /**
     * **应用作用域**（M2）：一个进程一份的东西（主题 / 度量 / 命令父表 / 加速键 /
     * 后台端口 / IO 轮询器）住在这里；本类只持"每窗一份"的那些。
     *
     * ★ 构造参数可注入 ⇒ 多窗口时 N 个 `Application` 共享同一个实例；
     *   不注入 ⇒ 自建一个 ⇒ **所有既有应用零改动**。
     */
    private readonly AppScope $scope;

    /** 已向 `$scope` 登记过本窗口（`attach()` 可重入 ⇒ 只登记一次） */
    private bool $scopeEntered = false;

    /**
     * 滚动输入路由（S1 第 5 步拆出的片）：滚轮 / 滚动条 thumb 拖动 /
     * 焦点滚进视口。构造期建（host = 本类，只经 `focus()` 摸焦点链）。
     */
    private ScrollInputRouter $scrollInput;

    /**
     * 浮层栈 Owner（S1 第 8 步）—— 栈所有权 + 开合策略 + 时序件住那片，
     * 本类只经它转调（15 个 public 委托壳）与直调（帧循环 / hover 回调）。
     */
    private OverlayManager $overlayHost;

    /**
     * 文本编辑三侧的桥（S1 第 4 步拆出的最小片）：IME 光标 / 指针框选 /
     * 编辑命令。构造期建（host = 本类），Application 只"持片 + 转交"。
     *
     * ★ 为什么整个搬走而不留代理方法：这三侧在 Application 里散成 6 个
     *   方法 + 1 个字段，只共享"焦点里在编辑的输入框"一个判据；收成一片
     *   后，IME 合成 / 选区矩形 / 双击选词的演进都落在一个文件里。
     */
    private TextEditingBridge $textEditing;

    /**
     * 输入路由主体（S1 第 6 步拆出的片）：§9.1 路由链的键 / 指针 / 滚轮三侧
     * + 手势状态（竞技场 / 拖放 / 指针捕获）。构造期建（host = 本类，另收
     * scrollInput + textEditing 两片做片间组合）。
     *
     * ★ 为什么最后建：它收另两片做片间组合（不经 host，同第 5 步口径）。
     * ★ Application 只"持片 + 转交"：`dispatchInput()` / 拖放四入口 / 捕获三
     *   方法留壳（外部直调不能断），壳内只做记账与转发。
     */
    private InputRouter $input;

    /**
     * 浮层几何布局策略（S1 第 7 步拆出的片）：每帧一次的 settle→清扫→定位。
     * 构造期建（host = 本类，只经 `overlays()` 读栈 + `dismissOverlay()` 收窄）。
     *
     * ★ 为什么先于 OverlayHost 主体拆：它只读栈、读盒、写盒，与输入 / 命令 /
     *   snackbar / tooltip 零耦合（`12-Application拆分.md` §4g 的版图 D 类）。
     */
    private OverlayLayout $overlayLayout;

    /**
     * 异步泵片（S1 第 9 步）：`frame()` 里 settle 之前的四个泵 + 定时器 +
     * 分片提交。构造期建（host = 本类）—— 泵只依赖自持的 tasks/io/sliced/
     * timers 四个状态 + 宿主的 UI 线程断言与时间基准，不读 root / focus /
     * overlays（真正零耦合）。
     */
    private PumpHost $pumps;

    /**
     * 语义树消费闭包（§9 / M5 UIA，per-app opt-in，同 taskHost() 形态）。
     *
     * ★ **默认 null ⇒ 不建语义树**（SemanticsBuilder"没人调就不建"，零开销）；
     *   应用覆写返回闭包即接入 UIA（组合根把闭包接到 WindowHost::pushSemantics）。
     */
    protected ?\Closure $semanticsSink = null;
    /**
     * 一帧的预算（毫秒）—— 60fps 的 16.7ms（`06` §3.2）。
     *
     * ★ 实测端到端 3.1ms（M4）⇒ 余量 5.3×。这个常量的用途**不是"按它分配 CPU"**，
     *   而是回答"超了先砍谁"（见 {@see self::STAGE_BUDGET_MS} 与 `06` §3.3）。
     */
    public const BUDGET_MS = 16.7;

    /**
     * 连续多少帧超预算才算"真超"（`06` §3.4）。
     *
     * ★ **不用单帧判定**：单帧超预算通常是垃圾回收或系统抢占，连续 10 帧才是真问题。
     *   与 `00` §5 的"单帧 > 8ms 连续 10 帧"同一口径。
     */
    public const OVER_BUDGET_FRAMES = 10;

    /**
     * 六阶段预算（毫秒，`06` §3.2）。
     *
     * ★ 它是**降级顺序的依据**，不是 CPU 分配表：超了先砍语义推送（D1），
     *   `layout` / `paint` / `present` **永不砍** —— 砍了等于画面不更新，
     *   比掉帧严重一个数量级（`06` §3.3 的 D5 边界）。
     */
    public const STAGE_BUDGET_MS = [
        'A 组（四个泵）' => 1.0,
        'settle' => 1.0,
        'layout' => 2.0,
        'paint' => 4.0,
        'present' => 5.0,
        '余量' => 3.7,
    ];

    /**
     * 浮层面板（O-20 第二棵根渲染树）：菜单 / 上下文菜单 / 模态共用同一槽位。
     *
     * ★ 与 `$root` **同构**（一个 Widget + 一个 Element）—— 树 / diff / 布局零配合，
     *   `Frame` 只在主树之后多画一棵，"绘制顺序即层叠"故**不引入 z-index**。
     *
     * ★ **锚点是应用级几何**：面板钉在锚点矩形的左下角，再夹进客户区。锚点由
     *   `showMenu()` 的 `$anchor` 给，`null` ⇒ 用最近一次命中的 Element
     *   （§5.1 裁决 1 的锚点来源 —— 命令是"意图"不带位置，§11.4.3）。
     */
    /** 连续超预算的帧数（0 = 本帧在预算内） */
    private int $overBudgetStreak = 0;

    /** 是否处于"超预算"状态（连续 {@see self::OVER_BUDGET_FRAMES} 帧超了 ⇒ true） */
    private bool $overBudget = false;

    /** 最近一帧的耗时（毫秒；0 = 还没量过） */
    private float $frameCostMs = 0.0;

    /** 观测到的最差一帧（毫秒） */
    private float $worstFrameMs = 0.0;

    /** D1：语义推送还剩几帧不推（0 = 这帧要推） */
    private int $semanticsFrameSkip = 0;

    /** D1：上次推语义时焦点是谁（焦点一变必须立即推，**焦点永不节流**） */
    private ?Element $semanticsFocused = null;

    /** `07` §6.2：上次推语义时的结构版本（`-1` = 还没推过 ⇒ 首帧必然 full） */
    private int $lastStructureVersion = -1;

    /** `07` §6.2 coalesce：攒了几帧没推（0 = 没在攒） */
    private int $semanticsCoalesced = 0;

    /**
     * coalesce 的帧数上限：攒满就必须推一次（`07` §9 #10）。
     *
     * ★ 没有上限 = "滚动/动画期间一直不推" ⇒ 读屏用户**完全失去反馈**。
     *   与 `06` §3.4 的 D1 同值（4 帧 ≈ 64ms）。
     */
    private const SEMANTICS_COALESCE_MAX = 4;

    /**
     * 悬停多久才弹出 Tooltip（毫秒，W8-2）。
     *
     * ★ 500ms 是桌面惯例（Windows / macOS 都在 400–700ms 这一档）。太短 ⇒ 指针
     *   扫过一排图标时提示乱闪；太长 ⇒ 用户以为没有提示。
     * ★ **相邻切换不等**：已经显示着的时候移到另一个带提示的控件，**立即**换内容
     *   （Windows 手感）—— 见 `onHoverHit()`。
     */
    public const TOOLTIP_DELAY_MS = 500;

    /**
     * 控件声明的浮层面板（`Widget::overlayPanel()`）的宽度上限（W8-2 Select）。
     *
     * ★ 与 `TooltipPanel::MAX_WIDTH` 分开：那是"一行提示"，这是"一列选项"。
     * ★ 宽度上限是**必须**的（不是审美）：`placeEntry()` 给非居中层的是无界约束，
     *   `RenderPopupPanel` 只在有界时才夹宽，而 ⑤ 的夹紧**只挪 x 不改宽** ⇒
     *   不限宽就是"长选项右边溢出到客户区外"。
     */
    public const PANEL_MAX_WIDTH = 320.0;

    /** 上次推送的档位（`full` / `coalesce` / `none`）—— 夹具与 devtools 读它 */
    private string $semanticsMode = 'none';

    /** 上一帧有没有动画在跑（§17.4.9 的第四个判据，由 `tickAnimations()` 写） */
    private bool $animating = false;

    /**
     * 上一帧看到的 `State::frameWantedCount()`（"settle 期间起飞"的动画）。
     *
     * ★ 与 `$animating` 分开存：`$animating` 每帧被 `tickAnimations()` **整体
     *   覆盖**，而这一份要跨帧记住"上次数到哪儿"——否则每帧都会判成"又有人
     *   要帧"，空闲时永远上膛。
     */
    private int $lastFrameWanted = 0;

    /** 每帧自增的序列号 —— `State::tickFrameStamped` 用它做同帧幂等（迟到挂载补 tick 用） */
    private int $frameSerial = 0;

    /**
     * 被跳过的零视口帧数（最小化 / 桌面临时切走）。
     *
     * ★ 只用于夹具观测"这一帧确实被跳过了"，不参与任何决策。
     *
     * @internal
     */
    private int $zeroViewportFrames = 0;

    /** 上一帧的时间戳（`repaintForResize()` 复用：拖动重排不该自己读环境时钟） */
    private float $lastTimestampMs = 0.0;

    /**
     * **最近一次输入派发**的时间戳（`dispatchInput()` 的第 2 参，由壳注入）。
     *
     * ★ 为什么要它：框架不自己读钟（G3），唯一的时间来源是壳每帧递进来的
     *   `$timestampMs`。但"用户点了某个东西"这件事发生在**两帧之间**，此时
     *   `lastTimestampMs` 是**上一帧**的 —— 应用空闲时可能是几百毫秒前。
     *   而"点了之后延时 400ms"必须以**点击那一刻**为基准，不能以上一帧为基准。
     * ★ 默认 `0.0`：测试与解释器不传时间戳 ⇒ 行为与之前**逐字节相同**
     *   （`max(上一帧, 0)` = 上一帧）。
     */
    private float $lastInputMs = 0.0;

    /**
     * 点击链（"这一下是第几次点击"）。
     *
     * ★ 住在**应用**而不是 `InputRouter`：路由层是"无时钟"的策略层，而时间只有
     *   壳递进来的这一条（`dispatchInput()` 的第 2 参）。放在这里 ⇒ 时间与"用时间
     *   算出来的东西"在同一处，路由层不用为它改签名。
     * ★ 规则见 `TapTracker`（间隔 / 距离 / 按键 / 修饰键四条）。
     */
    private TapTracker $taps;

    /** @param iterable<string, CommandHandler> $handlers `commands()` 的返回值 */
    public function __construct(iterable $handlers = [], ?AppScope $scope = null)
    {
        // ★ 作用域先定（M2）：本类对应用级状态的每一处读都经它。
        $this->scope = $scope ?? AppScope::make();

        // ★ 本窗 router 以**应用级命令表为父**（父链，就近优先）：
        //   全局命令注册一次、每个窗口可派发；本窗 `commands()` 仍各注册各的
        //   （handler 持本窗的 Store ⇒ 绝不能被别的窗口派发到）。
        $this->router = CommandRouter::make($this->scope->commands());

        $this->taps = new TapTracker();

        // S1 第 4 步：文本编辑三侧（IME / 指针框选 / 编辑命令）收成最小片。
        // host = $this（片只经 TextEditingHost 摸焦点与剪贴板）—— 构造期
        // 建无时序问题：两个 getter 都是运行期就绪的依赖，片内每次现读。
        $this->textEditing = new TextEditingBridge($this);

        // S1 第 5 步：滚动输入路由（滚轮 / thumb 拖动 / 焦点滚进视口）。
        $this->scrollInput = new ScrollInputRouter($this);

        // S1 第 8 步：浮层栈 Owner（栈所有权 + 开合策略 + Tooltip/Snackbar 时序件）。
        // ★ 建在 input 之前（InputRouter 收它做片间组合）、建在 overlayLayout
        //   之前（布局片经它读栈）。自持栈，不依赖宿主任何运行期状态。
        $this->overlayHost = new OverlayManager($this);

        // S1 第 6 步：输入路由主体（键 / 指针四相位 / 滚轮 / 手势消歧 / 拖放 /
        // 指针捕获）。后建：收另两片 + OverlayManager 做片间组合（不经 host，
        // 同第 5 步口径；浮层四口子从 InputHost 移除，改走构造参数）。
        $this->input = new InputRouter(
            $this,
            $this->scrollInput,
            $this->textEditing,
            $this->overlayHost,
        );

        // S1 第 7 步：浮层几何布局策略（帧循环每帧一次的纯几何段）。
        // ★ 建在 overlayHost 之后（经它读栈）、建在帧循环之前（frame() 第一处消费）。
        $this->overlayLayout = new OverlayLayout($this->overlayHost);

        // S1 第 9 步：异步泵片（四个泵 + 定时器 + 分片）。host = 本类（片只经
        // PumpHostHost 摸时间基准与 taskHost()），构造期建无时序问题。
        $this->pumps = new PumpHost($this);

        // 只注册**显式传进来**的那些。`commands()` **不在构造器里调** ——
        // PHP 的构造链是"父先子后"，此刻子类构造器里的字段（`$this->store` 之类）
        // 还都是 null ⇒ 照 §A.2 的样例会直接炸。注册推迟到 `attach()`（见那儿）。
        foreach ($handlers as $name => $handler) {
            $this->router->on((string) $name, $handler);
        }
    }

    /**
     * 焦点链（S1 第 4 步：`TextEditingHost` 契约的强制产物）。
     *
     * ★ 不是应用扩展点（对比 `clipboard()` 的 @api）—— 目前唯一消费者是
     *   `TextEditingBridge`（片只经 host 摸焦点）。标 `@internal`：若将来
     *   组合根真要读焦点（如 UIA 推送），那是显式升级 @api 的时刻，
     *   不是默认放它进应用面。
     *
     * @internal
     */
    public function focus(): ?FocusManager
    {
        return $this->focus;
    }

    /** 应用子树（§4.5：只写这一个方法就能跑起来） */
    abstract public function buildRoot(): Widget;

    /** 窗口标题 */
    public function title(): string
    {
        return 'Drift';
    }

    /**
     * 命令处理器（§11.4.3）：**应用级依赖清单，集中一处**。
     *
     * 形态是 `命令名 => handler`，不是 `list<handler>` —— handler 不知道自己的
     * 命令名（`handle()` 只收名字，返回"我处理了吗"）。而 §11.4.3 的"多命令共用
     * 一个 handler"要求**一个实例能挂多个名字** ⇒ 名字必须在注册侧给出。
     *
     * ```php
     * return ['users.refresh' => $this->cmds, 'users.delete' => $this->cmds];
     * ```
     *
     * @return iterable<string, CommandHandler>
     *
     * @api
     */
    public function commands(): iterable
    {
        return [];
    }

    /**
     * 应用作用域（M2）—— **组合根在多窗口时把同一个实例递给每个 `Application`**。
     *
     * ```php
     * $scope = AppScope::make()->setTaskHost(new ProcessTaskHost(4));
     * $a = new ShowcaseApplication($dialog, $clipboard, $scope);   // 窗 1
     * $b = new ShowcaseApplication($dialog, $clipboard, $scope);   // 窗 2
     * // 两个窗口共用：字体解析一次、一个线程池、一个 IO 轮询器、一份主题
     * ```
     *
     * @api
     */
    public function scope(): AppScope
    {
        return $this->scope;
    }

    /**
     * 主题（**应用级**）：默认取 `AppScope` 的值。
     *
     * ★ 仍然可以覆写（本窗特化），但多窗口下**不要**覆写 —— 共享 scope 里
     *   只有一个主题值，覆写的那个窗口会与别的窗口不一致（`AppScope::enter()`
     *   在 `attach()` 时会把这件事报成 `DIAGNOSTIC`，不会静默）。
     */
    public function theme(): ThemeData
    {
        return $this->scope->theme();
    }

    /**
     * 后台执行端口（§17.2）—— **应用级，一个进程一个池**（M2 从"扩展点"降为只读口）。
     *
     * ★★ **`final` 是有意的**（2026-10-30）：在 M2 之前它是应用覆写点
     *   （`protected function taskHost()`），于是"`UserStore` 拿注入的
     *   `ProcessTaskHost`、`frame()` 用默认 `SyncTaskHost` drain"这种**两套队列**
     *   的 bug 只能靠注释防。现在覆写它是**加载期致命错误**
     *   （`Cannot override final method`）—— 响亮失败，与 G2 一致。
     *
     * 换后端的写法搬到装配处（一行、显式、与窗口数无关）：
     *
     * ```php
     * $scope = AppScope::make()->setTaskHost(new ProcessTaskHost(4));
     * $app = new UsersApplication(new UserSource(), $scope);
     * ```
     *
     * ★ 默认 `SyncTaskHost`（永远可用 ⇒ 回调在帧内）；`null` ⇒ 本应用不用后台
     *   任务（`frame()` 跳过 drain）。
     *
     * @api
     */
    final public function taskHost(): ?TaskHost
    {
        return $this->scope->taskHost();
    }

    /**
     * 已挂载的后台端口（`null` = 未挂载或应用声明不用）。
     *
     * @internal 测试与 devtools 读它；应用不该绕过 `submit()` 直接碰后端。
     */
    public function tasks(): ?TaskHost
    {
        return $this->pumps->tasks();
    }

    /**
     * 时间基准（`PumpHostHost` 的口子）：上一帧的时间戳。
     *
     * @internal 片间契约口子（`PumpHost` 经它算 `after()` 的基准），不是应用扩展点
     */
    public function lastTimestampMs(): float
    {
        return $this->lastTimestampMs;
    }

    /**
     * 时间基准（`PumpHostHost` 的口子）：最近一次输入派发的时刻。
     *
     * @internal 片间契约口子，同上
     */
    public function lastInputMs(): float
    {
        return $this->lastInputMs;
    }
    /**
     * 加速键表（§9.1 路由链第 ① 环，焦点之前）—— **应用级**：默认取 `AppScope`。
     *
     * ```php
     * // 单窗口：照旧覆写（行为与 M2 之前逐条相同）
     * public function accelerators(): AcceleratorTable { ... }
     *
     * // 多窗口：放进 scope，注册一次全窗生效
     * $scope->setAccelerators(AcceleratorTable::make()->bind(KeyChord::ctrl('R'), 'users.refresh'));
     * ```
     *
     * ★ 共享**不会串窗**：加速键只把组合键解析成**命令名**，命令派发走
     *   `Application::dispatch()`（本窗树 → 本窗 router → 父链）⇒ 同一个 `Ctrl+S`
     *   在两个窗口里各自落到自己的 handler。
     * ★ 默认空表 ⇒ 不覆写的应用零加速键、也零开销（`isEmpty()` 短路）。
     *
     * @api
     */
    public function accelerators(): AcceleratorTable
    {
        return $this->scope->accelerators();
    }

    /**
     * 剪贴板壳服务（§5.1 纪律 2）—— **组合根覆写它接真平台**。
     *
     * ```php
     * public function clipboard(): ?Clipboard
     * {
     *     return $this->platform()->clipboard();   // 平台名只出现在组合根
     * }
     * ```
     *
     * ★ 框架不持平台（§5.1 纪律 1）⇒ 端口只能由外层递进来，与 `theme()` /
     *   `taskHost()` 同一形态。默认 `null` = 本应用没有剪贴板 ⇒ `Ctrl+C / X / V`
     *   整族回落给加速键（见 `dispatchEditCommand()` 的门控 ③）。
     * ★ 消费者：编辑命令环（复制 / 剪切 / 粘贴，§9.1 让位规则 ②）。
     *
     * @api
     */
    public function clipboard(): ?Clipboard
    {
        return null;
    }

    /**
     * **布局确定性的注入点**（§14.2）：度量由宿主给，不由平台探测 —— **应用级**。
     *
     * ★ M2 起实现搬到 `AppScope::metrics()`（**整个进程一份**）：它要读一次
     *   11MB 字体（`TtfFace::fromFile()`）并注册三套字形面，每窗口一份就是
     *   解析 N 次 + N 份字形缓存。原来住 `$this->metricsCache`，多窗口下那段
     *   缓存只对本窗有效。
     * ★ 仍然可以覆写（本窗特化），但多窗口下**别** —— 与 `theme()` 同一条理由。
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
     * ★ 根**不包 `ThemeScope`** —— 应用想要全局主题就自己包（`buildRoot()` 里
     *   一层 `ThemeScope::make($this->theme(), $root)`），框架不替它决定。
     *   少了这一层 indirection，"换肤"就少一个可测试的落点。
     *
     * @param BoxConstraints $constraints 根收到的约束（通常 = 窗口逻辑尺寸）
     *
     * @api
     */
    public function attach(RenderSurface $surface, BoxConstraints $constraints, ?BuildContext $context = null): void
    {
        // ★ UI 线程身份在**挂载之前**记：之后的任何回调都以此为准（§17.4.2.1）。
        $this->uiThreadId = \drift_thread_id();

        // ★ 作用域登记（M2）：第一个窗口把主题灌进 scope，后续窗口必须一致 ——
        //   不一致在这里报 DIAGNOSTIC（多窗口"一半深色一半浅色"的唯一入口是
        //   "某个窗口覆写了 theme()"）。只在挂载时查一次，避免进热路径。
        // ★ `attach()` 可重入（测试会重复挂载）⇒ 同一实例只登记一次，否则
        //   "第 2 个窗口"的判据会在同一个窗口的第二次 attach 上误报。
        if (!$this->scopeEntered) {
            $this->scopeEntered = true;
            $this->scope->enter($this->theme());
        }

        // ★ 端口 / 轮询器**从作用域取**（M2）：一个进程一个池、一个监听表。
        //   `attach()` 只缓存引用，不负责"建"—— 建的时机归装配处。
        $this->pumps->attach($this->scope->taskHost(), $this->scope->io());

        $context ??= new BuildContext(
            $constraints->maxWidth,
            $constraints->maxHeight,
            $this->theme(),
            $this->metrics(),
        );

        // 注册期（§11.4.3）：此刻子类字段已就位，`commands()` 才是可信的。
        // 每条命令重复注册 ⇒ Fatal（`CommandRouter::on()` 的那条 G2 检查），
        // 所以这里**必须可重入**：`attach()` 调两次会第二次命中同名注册。
        foreach ($this->commands() as $name => $handler) {
            if (!$this->router->has((string) $name)) {
                $this->router->on((string) $name, $handler);
            }
        }

        $root = $this->buildRoot()->createElement();
        $root->mount(null, $context);
        $this->root = $root;

        $this->frame = new Frame($surface);

        // ★ 首帧走**同一个入口**（§17.4.10）：直接调 `Frame::run()` 会绕过
        //   `tickAnimations()` ⇒ 纯动画应用（根就是一个 `Spinner`、没有异步任务）
        //   在 `attach()` 之后 `wantsFrame()` 仍是 false ⇒ 循环再也不按帧节奏醒 ⇒
        //   **转圈图一辈子不动**。这条是给 §17.4.10 写冒烟时抓到的，不是设计时想到的。
        $this->frame(0.0, $constraints);

        // 焦点链要在**挂载之后**建（此刻才有树可走）。
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
     * UI 线程 id（`0` = 未挂载）。测试与 devtools 读它。
     *
     * @internal 应用不该读线程身份 —— 它只用于断言（§17.4.2.1）。
     */
    public function uiThreadId(): int
    {
        return $this->uiThreadId;
    }

    /**
     * UI 线程断言（§17.4.2.1，WPF `VerifyAccess()` 的等价物）。
     *
     * ★ 只在"当前线程不是 UI 线程"时失败；`uiThreadId == 0`（未挂载）不算失败 ——
     *   挂载前本来就没有 UI 线程可谈。
     *
     * ★ 这是**响亮失败**（Fatal 序列），不是静默忽略：静默就等于给数据竞争留缝，
     *   而那类 bug 只在压力下偶发（§17.2 第 ① 处）。
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

    /**
     * IME 定位用的光标矩形（§8.4）—— 转交 `TextEditingBridge`（S1 第 4 步）。
     *
     * ★ 几何与门控都住在片里（IME / 框选 / 编辑命令是同一族认知）；这里留
     *   public 壳是因为组合根（`Bootstrap.php` 的 `imeCaret()` 喂值）按
     *   `Application::imeCaretRect()` 调 —— **API 位置不动，实现搬走**。
     */
    public function imeCaretRect(): ?array
    {
        return $this->textEditing->imeCaretRect();
    }


    /**
     * 系统主题变了（§9.2 / §A.2.5 的应用钩子）—— **应用扩展点**。
     *
     * ★ 框架不持平台（§5.1）⇒ 拿不到 `Screen::systemTheme()`，这个钩子**不带
     *   参数**：应用自己持有 screen（组合根注入）时在这里重读，并更新自己
     *   `theme()` 的来源字段。默认**空实现** —— 不跟随系统主题的应用零开销。
     *
     * ★ 调用时机在 `applyNewContext()` **之前**（见 `dispatchInput` 的
     *   ThemeChangedEvent 分支）：钩子跑完 `theme()` 才是新值。
     *
     * @api
     */
    protected function onSystemThemeChanged(): void
    {
    }

    /**
     * 这个窗口被要求关闭 —— **回答"同意吗"**（2026-10-30）。
     *
     * ```php
     * protected function onCloseRequested(): bool
     * {
     *     if (!$this->form->isDirty()) {
     *         return true;                     // 没什么要问的 ⇒ 关
     *     }
     *     return $this->dialog->confirm('未保存', '放弃这次修改？');
     * }
     * ```
     *
     * ★ **只在组合根声明了守卫的窗口上会被问**（`WindowHost::setCloseGuard($h, true)`）。
     *   没声明的窗口，`WM_CLOSE` 由壳直接销毁，本方法**永远不会被调** ——
     *   这是"没声明要问 = 关就是了"，不是遗漏。
     * ★ 返回 `true` ⇒ 框架调 `WindowHost::destroy()`（同步销毁）；`false` ⇒
     *   什么都不发生，窗口原样留着（连位置都不动）。**不要**在这里自己调
     *   `close()`：那会再发一条 `WM_CLOSE`，等于自己问自己。
     * ★ 调用点在**帧边界**（`EventLoop` 抽完事件之后、跑帧之前），不是在 WndProc 里
     *   ⇒ 这里可以安全地弹模态框（`Dialog::confirm()`）。壳侧为什么不能同步问，
     *   见 `cpp-src/drift_win32.cc` 的 `enqueueCloseRequest`。
     * ★ 默认 `true`：声明了守卫却忘了覆写，行为退回"点了就关" ——
     *   比"点了没反应、又没有提示"好（那才是真正的死窗口）。
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

    /**
     * 把新的渲染环境下发到**根与浮层**两棵树（O-20 差异表 #2：浮层是第二棵树）。
     *
     * ★ **不是新机制**：`Element::applyContext()` 就是"换一份渲染环境（主题切换
     *   的唯一入口）"，且已实现两种兑现时机（无状态立即重建 / 有状态只标脏、
     *   等帧末 flush）+ 递归只走一遍 —— 本方法只是**第一个生产调用者**。
     *
     * ★ 主题值来自 `$this->theme()`（应用注入点）。若应用没覆写 `theme()`、
     *   也没在钩子里换源 ⇒ 新 context 与旧值 `===` ⇒ `applyContext()` 幂等
     *   短路（零重建）。这是**设计**：不跟随系统主题的应用不该付重建成本。
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
     * 跑一帧（§5.2）：平台每帧调一次。
     *
     * @param float $timestampMs **注入的**时间戳（不读环境时钟）
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

        // ★ 帧必须在 UI 线程跑（§17.4.2.1）。成本 = 读一次 TEB + 一次整数比较。
        $this->assertUiThread('frame');

        $this->lastTimestampMs = $timestampMs;

        // ★ 帧序列号自增（迟到挂载补 tick 的幂等判据）：每帧一个唯一值，
        //   `State::tickFrameStamped` 用它识别"本帧已推进过的状态"。
        $this->frameSerial++;

        // ★ 分段计时（SurfaceProfile，默认关）：frame() 里各泵与收尾的占比，
        //   M4 拆「其余(PHP)」黑箱用（`26` §7）。段键与 Frame.php 的同治一套表。
        $prof = SurfaceProfile::$on;

        // ★ 帧阶段 trace（`06` §2.4，默认关）：九段各记一个名字，帧末比对名单。
        //   关时 $trace 为 null，每段只多一次 `if ($trace !== null)`。
        $trace = FramePhase::begin();

        // ★ ① 先问一轮非阻塞 IO（§17.4.4）：外部数据先到，回调里 setState 的脏标记
        //   由本次 settle 消费（这一句必须在 `Frame::run()` 之前）。
        $t = $prof ? \hrtime(true) : 0;
        if ($trace !== null) {
            $trace[] = 'pumpIo';
        }
        $this->pumps->pumpIo();
        if ($prof) {
            SurfaceProfile::add('pumpIo', \hrtime(true) - $t);
        }

        // ★★ ② 再把后台结果取出来（§17.4.2）—— **必须在 settle 之前**：
        //   回调里 `setState()` 只标脏，紧随的 `Frame::run()` 第 ① 步就是 settle
        //   ⇒ 同帧生效。放到 settle 之后，那次 setState 要等下一帧，
        //   "loading → ready" 会闪一帧空（多一帧延迟，且用户看得见）。
        $t = $prof ? \hrtime(true) : 0;
        if ($trace !== null) {
            $trace[] = 'pumpTasks';
        }
        $this->pumps->pumpTasks();
        if ($prof) {
            SurfaceProfile::add('pumpTasks', \hrtime(true) - $t);
        }

        // ★ ③ 最后推进分片任务（P1）：完成的那一个也在此刻回调 ⇒ 同样落在 settle 之前。
        //   顺序（结果 → 分片 → settle）不重要，**都在 settle 之前**才重要。
        $t = $prof ? \hrtime(true) : 0;
        if ($trace !== null) {
            $trace[] = 'pumpSliced';
        }
        $this->pumps->pumpSliced();
        if ($prof) {
            SurfaceProfile::add('pumpSliced', \hrtime(true) - $t);
        }

        // ★ ③ 推进到期的延时回调（W8-2）：同样**必须在 settle 之前** ——
        //   `after()` 的回调里 `setState()` 由紧随的 `Frame::run()` 的 settle 段
        //   消费，同一帧生效（Tooltip 到点那一帧就画出来，不多闪一帧空）。
        $t = $prof ? \hrtime(true) : 0;
        if ($trace !== null) {
            $trace[] = 'pumpTimers';
        }
        $this->pumps->pumpTimers($timestampMs);
        if ($prof) {
            SurfaceProfile::add('pumpTimers', \hrtime(true) - $t);
        }

        // ★ ⓸ 推进动画（§17.4.9）：同样**必须在 settle 之前** —— `tickFrame()` 里的
        //   `setState()` 由紧随的 `Frame::run()` 的 settle 段消费，同一帧生效。
        //   返回值是"还有动画在跑吗"，喂给 `wantsFrame()` 决定下一次要不要被叫醒。
        $t = $prof ? \hrtime(true) : 0;
        if ($trace !== null) {
            $trace[] = 'tickAnimations';
        }
        $this->animating = $this->tickAnimations($timestampMs);
        if ($prof) {
            SurfaceProfile::add('anim', \hrtime(true) - $t);
        }

        // ★★ **零视口不是"内容放不下"，是"没有像素可画"**（2026-10-25 用户实测最小化即崩）。
        //   最小化时平台报客户区 0×0（`Win32Screen::viewport()` 翻成 `tight(0,0)`；
        //   **口径由壳保证** —— `IsIconic` 直接报 0，别指望 `GetClientRect` 自己给 0，
        //   无边框窗的客户区就是那个 160×28 小方块）。拿它布局时 `Text` 只能每字一行
        //   （宽 0 ⇒ 换行），高度约束又是 0 ⇒ `RenderText 实际 0.0 × 86.4，最多只能用
        //   0.0 × 0.0` 当场 C6 Fatal。
        //   ★ **泵已经全部跑完**（IO / 任务 / 分片 / 延时 / 动画都在上面），这里只跳过
        //   布局与绘制 —— 动画与 `after()` 在最小化期间照常推进，还原时不跳变。
        //   ★ 不做"缓存上次视口继续画"：画进不可见 surface 纯属浪费，且会让
        //   `viewport()` 撒谎。还原时系统必发新的 `WM_SIZE`（带真实尺寸）⇒ 重新布局。
        if ($constraints->maxWidth <= 0.0 || $constraints->maxHeight <= 0.0) {
            $this->zeroViewportFrames++;

            return;
        }

        // 浮层布局段（逐层自尺寸 + 钉锚点 + 夹进客户区）—— 见 placeOverlays()
        $t = $prof ? \hrtime(true) : 0;
        if ($trace !== null) {
            $trace[] = 'placeOverlays';
        }
        $this->overlayLayout->placeOverlays($constraints);
        if ($prof) {
            SurfaceProfile::add('placeOverlays', \hrtime(true) - $t);
        }

        // ★ `settle` 是**标记**不是真段（`FramePhase::CORE`）：Frame::run() 内部
        //   才是 settle → layout → paint → present。标记打在它前面，
        //   "跨过 settle"的越界就拿它当下标。
        if ($trace !== null) {
            $trace[] = 'settle';
        }

        // ★★ **本帧要不要画**（2026-10-30，N1）：`Frame` 只看树（settle / layout /
        //   paintEpoch），**看不见应用级叠加**。这里补上它看不见的两样：
        //   ① 焦点环目标变了还没画（第七判据的反面）；
        //   ② 浮层锚点换了但面板内容还落后（第六判据的反面）。
        //   ★ 取**跑帧前**的目标：paint 只可能把"跑帧前那个环"画进去；跑帧期间
        //     焦点若又变了，那新目标此刻 **还没画** ⇒ `paintedFocus` 必须停在旧值
        //     ⇒ 下一帧自然还请求。此前无条件写 `focusRingTarget()` 正是在这里丢帧
        //     （settle 里 `setState` 挪了焦点 ⇒ 新目标被记成"已画"⇒ 环消失）。
        $ringBefore = $this->focusRingTarget();
        $paintRequested = $ringBefore !== $this->paintedFocus
            || $this->overlayLayout->panelsLagBehind();

        // ★★ **窗口帧**（`LayoutTolerance::withinWindow`）：这一帧的约束来自窗口客户区，
        //   而客户区是用户的东西 —— "内容比窗口大"是合法状态 ⇒ C5/C6 降级为裁剪 + 一条
        //   diagnostic，不中止进程（Flutter release / Qt / WPF / CSS 都是裁剪）。
        //   `attach()` 的首帧也走这里（它调的就是 `frame()`）⇒ 还原成小尺寸启动时
        //   不会卡在 Fatal 框里进退不得。
        //   ★ 严格那一侧没丢：直接调 `Frame::run()` 的测试 / 快照 / golden 仍然是
        //     `$windowDriven = false` ⇒ 写错布局照样红。
        $painted = LayoutTolerance::withinWindow(fn(): bool => $frame->run($root, $constraints, $timestampMs, $this->overlayHost->overlays()->all(), function (Canvas $canvas): void {
            $this->paintFocusRing($canvas);
        }, $paintRequested));

        // 环**真的**上屏才记账 ⇒ `wantsFrame()` 的第七判据从这里回落 false（有界）。
        // ★ 跳过 / paint 抛异常 ⇒ **不记账** ⇒ 判据仍为真 ⇒ 下一帧还会请求 —— 直到
        //   真画出来为止。这是"有界"的唯一正确方向：不能靠"请求过一次就算数"。
        if ($painted) {
            $this->paintedFocus = $ringBefore;
        }

        // ★ ⓹ 迟到挂载补 tick（2026-10-20 修的框架洞，2026-10-22 换成登记表）：
        //   ⓸ 在 settle **之前**跑，而页面挂载发生在 `Frame::run()` 的 settle 段 ——
        //   于是"这一帧刚挂上的页"的 State 在 ⓸ 时还不在树里、从未被 `tickFrame()`
        //   访问 ⇒ `$animating` 当帧永远是 false ⇒ `wantsFrame()` 返回 false ⇒
        //   帧时钟不重新上膛 ⇒ **之后没鼠标事件就永远静止**（Spinner / 自绘页同病，
        //   真机探针抓到）。任一新挂载的 State 还想下一帧 ⇒ `$animating` 置真 ⇒
        //   时钟重新上膛，动画得以续跑。
        if ($trace !== null) {
            $trace[] = 'tickLateMount';
        }
        //   ★ **2026-10-22 改成"只 tick 登记表"**（`06` §4.2）：第二次全树遍历的
        //     目的**只有一个** —— 让"这一帧刚挂上的 State"有机会声明"我还要下一帧"。
        //     全树里其他 State 已经在 ⓸ 被访问过（`tickFrameStamped` 会跳过重复）⇒
        //     遍历它们纯属浪费（树 800 节点 ≈ 800 次虚调用，通常只有 0–5 个新挂载）。
        //   ★ 帧戳仍然要传：登记表里可能有"在 settle **之前**就挂上"的 State
        //     （`placeOverlays()` 期间），它已经被 ⓸ 推进过 ⇒ 帧戳保证不重复推进。
        //   ★ **跳过已卸载的**：`mount()` 了但一直没有帧到来的场景（纯头侧夹具）
        //     会让登记表挂着死节点 —— 不跳过就是 tick 一个已经离开树的状态。
        foreach (State::takeMountedThisFrame() as $mountedState) {
            $mountedElement = $mountedState->element();
            if ($mountedElement === null || !$mountedElement->isMounted()) {
                continue;
            }

            if ($mountedState->tickFrameStamped($timestampMs, $this->frameSerial)) {
                $this->animating = true;
            }
        }

        // ★ ⓹b 同类的另一半（2026-10-29，真机抓到）：**settle 期间才起飞的动画**。
        //   `State::didUpdateWidget()` 里 `toward()` 起的隐式动画，在 ⓸ 时目标还是
        //   旧的 ⇒ `tickAnimations()` 看不到它 ⇒ `$animating` 为 false ⇒ 帧时钟
        //   落闸 ⇒ **动画停在起点**。真机现象：点「淡入」画面一动不动，再点一次
        //   才动（第二次点击时 ⓸ 才看到"还在跑"）。
        //   ★ 这里**只上膛不推进**：推进留给下一帧的 ⓸（同帧再 tick 一次就是
        //     "一帧走两步"，动画快进）。
        //   ★★ 判据是**计数器前后差**而不是登记表：登记表要再问 `element()` /
        //     `isMounted()`，那两跳在 AOT 下任何一个失手都静默退化成"什么都没
        //     发生"（第一版就是这么失败的）。与 `needsSettle()` 读 `markedCount`
        //     同一个理由。
        $frameWanted = State::frameWantedCount();
        if ($frameWanted !== $this->lastFrameWanted) {
            $this->lastFrameWanted = $frameWanted;
            $this->animating = true;
        }

        // ★ 每帧重建焦点链：`settle()` 可能重建了子树 ⇒ 旧链里可能握着**已被移除**
        //   的 Element（按键会静默丢给死节点）。`collect()` 幂等，且会保留仍然存在
        //   的焦点（见 `FocusManager::collect()`）。
        //   O(树) 与同帧的 `layout()` 同阶 —— §13.3：Render 层本就全树重排。
        // ★ **浮层是第二棵树，也要进焦点链**（O-20 差异表 #2）—— 否则菜单项
        //   永远 Tab 不到。
        $t = $prof ? \hrtime(true) : 0;
        if ($trace !== null) {
            $trace[] = 'focusCollect';
        }
        // ★ 捕获者卸载 ⇒ 必须在这里释放（`06` §5.2 释放路径 ②）：settle 可能把
        //   捕获者所在的子树换掉 ⇒ 之后的指针事件全发给一个死节点（静默冻住）。
        //   报 DIAGNOSTIC 而不是静默清空：这是**控件写错了**（该在卸载前自己放），
        //   不报的话现象是"拖到一半不响应了"，没人查得出来。
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
        if ($prof) {
            SurfaceProfile::add('focus', \hrtime(true) - $t);
        }

        // 语义树推给 UIA（§9 / M5）：每帧末经 sink 交出（无 sink ⇒ 不建，零开销）。
        $t = $prof ? \hrtime(true) : 0;
        if ($trace !== null) {
            $trace[] = 'pumpSemantics';
        }
        $this->pumpSemantics();
        if ($prof) {
            SurfaceProfile::add('semantics', \hrtime(true) - $t);
        }

        if ($trace !== null) {
            FramePhase::end($trace);
        }
    }

    /**
     * 拖动 resize 专用的**轻量重绘**（§5.2 模态尺寸循环）。
     *
     * ★★ 与 `frame()` 的唯一区别：**不碰帧外的东西** —— 不 pumpIo / pumpTasks /
     *   pumpSliced、不推进画、不重建焦点链、不推语义树。它会被平台壳在
     *   **WM_SIZE（模态循环内）同步调回**（C → PHP 重入），能碰的状态越少越安全；
     *   顺带也更快（拖动预算 48ms 留出余量）。
     *
     * ★ 时间戳复用上一帧的：这不是一帧「新时间」，只是「同一时刻换个尺寸再画一次」。
     *   自己读环境时钟会破坏 `frame()` 的单一时钟来源。
     *
     * ★ 界面要跟手的语义仍由主循环兜底：松手后 `waitEvents()` 恢复等待，积压的 resize 已
     *   被合并成一条 ⇒ 主循环跑一帧完整 `frame()` 收尾（任务/动画/语义都补上）。
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

        // 零视口（最小化时 `WM_SIZE` 的 lp 就是 0×0）⇒ 没有可画的东西，也没有
        // 可用于布局的约束（`tight(0,0)` 会让 Text 每字一行 ⇒ C6 Fatal，`02` §2.1）。
        // ★ 与 `frame()` 同一条判据，但这里**不跑泵** —— 拖动重排本来就不碰
        //   任务/动画/语义，跳过整段是对的。
        if ($constraints->maxWidth <= 0.0 || $constraints->maxHeight <= 0.0) {
            return;
        }

        // ★ 拖动中的重排也是**窗口帧**（约束照样来自客户区）—— 与正式帧同一条语义：
        //   裁剪 + 留痕，不弹 Fatal 打断拖动。两处用同一个入口，不留两套开关。
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
     * 排一个**一次性延时回调**（W8-2，2026-10-23）。
     *
     * ```php
     * $id = $this->application()->after(500, function (): void { $this->setState(...); });
     * // 想撤销就 cancelAfter($id)（已触发过 ⇒ 幂等无事发生）
     * ```
     *
     * ★ **时间基准是"上一帧的时间戳"**，不是环境时钟（G3：框架不自己读钟）。
     *   在 `frame()` **之外**调用（例如指针回调里）时，基准会早一个帧间隔
     *   （≤ `FrameClock::FRAME_MS` = 15ms）—— 对 Tooltip / Snackbar 这种"人的
     *   反应时间"尺度无感，但**不要拿它做动画计时**（那是 `AnimationController`
     *   的活，它收的是精确帧时间戳）。
     * ★ **回调在 `frame()` 内、`settle()` 之前跑**（`pumpTimers()` 的位置与
     *   `pumpTasks()` 同级）⇒ 回调里 `setState()` 同帧生效。
     * ★ **有定时器在等 ⇒ `wantsFrame()` 为真** ⇒ 帧时钟保持上膛。这是"定时器
     *   能不能响"的**结构前提**：消息泵是阻塞的，没人叫醒就永远不响。
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
     * 本应用**还需要下一帧吗**（§17.4.9）—— 自适应帧时钟的唯一判据。
     *
     * ★ 平台的消息泵是**阻塞**的（`GetMessageW`）：没有输入事件时它一直睡。所以
     *   "后台任务还在跑""分片没跑完""有流在监听"这三件事必须由框架主动说出口，
     *   平台才知道该起一个定时器把循环叫醒。**这不是优化，是修缺陷**：在此之前
     *   异步结果只能等下一条用户消息捎带。
     * ★ 三件事都**有界**：任务回贴完、分片跑完、监听解除 ⇒ 立刻返回 `false`，
     *   帧时钟停摆、CPU 归零。**空闲不空转**是这条判据存在的一半理由。
     *
     * ★★ **2026-10-30（N2）拆成三个命名谓词**：这句 `bool` 原先混着三种**不同的
     *   需求**，而"等多久"要按需求分别算 —— 混在一起的后果是
     *   `after(400)` 也算"要帧" ⇒ 按 15ms 帧节奏反复醒 ⇒ **26 次唤醒等一个 400ms
     *   的定时器**（改之前真的这样）。现在：
     *   - {@see self::wantsFrameRhythm()} 连续帧（动画 / 异步在途）⇒ 按帧节奏叫醒
     *   - {@see self::needsFrameNow()} 一次性欠账（环没画 / 面板落后）⇒ 立刻
     *   - `nextDeadlineMs()` 定时器 ⇒ 到点才醒
     *   `wantsFrame()` 仍是三者的"或"，**语义逐字节不变**（老读者零影响）。
     *
     * @api 组合根每帧调一次（`FrameClock` 就是这么用的）
     */
    public function wantsFrame(): bool
    {
        if ($this->wantsFrameRhythm()) {
            return true;
        }

        // ★ 定时器（W8-2）：有延时回调在等 ⇒ 必须被叫醒，否则永远不响。
        //   `nextDeadlineMs()` 会给出"什么时候"。
        if ($this->pumps->hasTimers()) {
            return true;
        }

        return $this->needsFrameNow();
    }

    /**
     * **连续帧**：动画在跑 / 分片在推 / 流在监听 / 后台在途（§17.4.9 前两条判据）。
     *
     * ★ 这几种需求的性质是"**按帧节奏反复醒**"（15ms 一帧），而不是"到某个时刻醒一次"。
     *   它们是**轮询**的来源：后台结果与 socket 可读都没有唤醒通道（N4 才有），
     *   只能每帧问一次 —— 这也是为什么 {@see \Drift\App\FrameClock::nextWaitMs()}
     *   对它们给的是帧间隔，而不是 `-1`（无限等会让 socket 永远不被问）。
     */
    public function wantsFrameRhythm(): bool
    {
        // ★ 排最前 —— Loading 期间它恒为真。
        if ($this->animating) {
            return true;
        }

        return $this->pumps->hasAsync();
    }

    /**
     * **立刻欠一帧**：焦点环目标变过还没画 / 面板内容落后一帧（§17.4.9 后两条判据）。
     *
     * ★ 与上面那组的区别：这两条**画一帧就没了**（有界、一次性）。所以等待层给
     *   它们的是 `0`（马上去跑），而不是帧间隔。
     * ★★ 第六判据（W8-2 远程搜索，2026-10-24）：`panelsLagBehind()` —— 少了它，
     *   远程搜索的结果到了、数据也对，面板却**永远**停在旧内容（真机现象，smoke
     *   因为显式跑两帧看不见）。
     * ★★ 第七判据（W8-5 焦点视觉，2026-10-24）：**环目标变过但还没画**。焦点环是
     *   应用级叠加（`paintFocusRing`），不经过任何 `setState` ⇒ 没有这条，键盘 Tab
     *   换焦点后环要等**下一次鼠标移动**才出现（真机现象）。
     *   ★ 比的是 `focusRingTarget()` 而不是 `focused()`：指针模态下目标恒 `null`
     *     ⇒ 点击隐藏环也能立刻触发一帧（否则点击后环要等下次输入才消失）。
     */
    public function needsFrameNow(): bool
    {
        if ($this->focusRingTarget() !== $this->paintedFocus) {
            return true;
        }

        return $this->overlayLayout->panelsLagBehind();
    }

    /**
     * 下一个**到点的定时器**（绝对毫秒，与 `Platform::now()` 同一时基）；`null` = 没有。
     *
     * ★ 它回答的是事实（"最早什么时候有活"），不是策略（"等多久"）—— 后者是
     *   {@see \Drift\App\FrameClock::nextWaitMs()} 的事，因为那里才知道帧间隔。
     * ★ 只算 `after()` 的延时回调：后台任务 / socket 可读**没有**到点时刻，
     *   它们是轮询（见 {@see self::wantsFrameRhythm()}）。
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
     * ★ **@internal 量具**，不是应用契约：`EventLoop` 验"一轮到底跑没跑帧"时，
     *   `SurfaceProbe::frames` 已经不够用了（N1 之后"跑帧"≠"画帧"，
     *   静态树下 paint 整段跳过）⇒ 需要数**帧本身**。
     */
    public function frameCount(): int
    {
        return $this->frameSerial;
    }

    /** 记一次帧耗时（诊断 / 语义推送节流的输入） */
    public function noteFrameCost(float $milliseconds): void
    {
        // ★ 量坏了（NaN / 负值）⇒ **不立超预算**：一次坏样本把降级打开，
        //   接下来几帧的语义推送就被白白节流了。
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

        // ★ 只在**刚立起来的那一帧**报：每帧报一次会把环形缓冲刷满，
        //   反而看不到别的（环形缓冲只有 100 条）。
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

    /** 是否处于超预算状态（D1 的判据；devtools / 夹具也读它） */
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
     * 被跳过的零视口帧数（最小化 / 桌面临时切走时 `frame()` 早退的次数）。
     *
     * ★ 夹具用它证明"那一帧确实被跳过了"，而不是"恰好没崩"。
     *
     * @internal
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

    /**
     * 注入语义树消费者（§9 / M5 UIA）—— 组合根把它接到 `WindowHost::pushSemantics()`。
     *
     * ★ 框架只存闭包、**不碰平台**（§5.1：平台引用止于组合根）；默认不注入 ⇒ 不建语义树。
     */
    public function setSemanticsSink(callable $sink): void
    {
        $this->semanticsSink = \Closure::fromCallable($sink);
    }

    /**
     * 每帧末把语义树推给消费者（§9 / M5 UIA）。
     *
     * ★ 无 sink ⇒ 直接返回（**不建语义树** —— SemanticsBuilder 的"没人调就不建"）。
     * ★ 走 SemanticsBuilder::build()（Element 树）+ toArrays()['nodes']（扁平标量，
     *   正是 WindowHost::pushSemantics 收的形状）。放在 settle/重建之后 ⇒ 反映最新树。
     */
    /**
     * 语义推送：**两档**（`07` §6.2 / §6.4）。
     *
     * | 档 | 什么时候 | 推什么 |
     * |---|---|---|
     * | `full` | 结构变了（节点增删 / 重排）或焦点变了 | 整棵语义树 |
     * | `coalesce` | 动画中（结构没变） | 攒起来，**每 4 帧**合并成一次 |
     *
     * ★★ **`delta` 档诚实降级、没做**（`07` §6.4）：精确 diff 要"每个节点记住
     *   上次推的值"，那是 O(树) 的额外内存；没有字段表就只能推"整棵树的当前值"，
     *   省的是序列化与 IPC、不是遍历 ⇒ 是个"每帧仍遍历全树、只少一次序列化"的
     *   半成品。本册只落两档，delta 记为"结构版本号落地后再评估"。
     *
     * ★★ **结构变 / 焦点变 ⇒ 立即 `full`，两条节流都不看**：
     *   - 增量表达不了"多了一个节点"，攒着推会让读屏的树与真实树错位；
     *   - 焦点一变必须马上说（延迟 50ms 就让人怀疑键盘失灵，`07` §6.3）。
     *
     * ★ D1（`06` §3.3 / §3.4）仍在：超预算时降到**每 4 帧一次**，但只约束
     *   "没有结构/焦点变化"的常态帧。
     */
    private function pumpSemantics(): void
    {
        $sink = $this->semanticsSink;
        if ($sink === null) {
            return;
        }

        $focused = $this->focus?->focused();
        $focusChanged = $focused !== $this->semanticsFocused;
        $version = Element::structureVersion();
        $structureChanged = $version !== $this->lastStructureVersion;

        // ① 结构 / 焦点变了 ⇒ full，**立即**（不看 coalesce，也不看 D1）
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

        // ③ D1：超预算时降到每 4 帧一次
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
     * 遍历树推进所有动画，并回答"**还有动画在跑吗**"（§17.4.9）。
     *
     * ★ **浮层是第二棵树**（与 `focus->collect()` / `placeOverlays()` 同一个坑）：只走
     *   `$root` 的话，菜单里放一个 `Spinner` 就永远不转，而且**没有任何报错**。
     * ★ 成本 O(树)，与同帧的 `layout()` 同阶（§13.3：Render 层本就全树重排）。
     */
    private function tickAnimations(float $timestampMs): bool
    {
        $busy = false;

        $root = $this->root;
        if ($root !== null && $this->tickSubtree($root, $timestampMs)) {
            $busy = true;
        }

        foreach ($this->overlayHost->overlays()->all() as $e) {
            if ($e->element !== null && $this->tickSubtree($e->element, $timestampMs)) {
                $busy = true;
            }
        }

        return $busy;
    }

    /**
     * `tickAnimations()` 的递归体。
     *
     * ★ **不许短路**：即使已经知道 `$busy` 为真，兄弟子树里的动画也必须收到这一帧，
     *   否则"整棵树里只推进第一个动画"——现象是多个 Spinner 只有一个在转。
     *
     * ★ **State 与盒的动画在同一次遍历里推进**（`RenderObject::tickAnimations`）：
     *   悬停过渡这类"盒态"动画住在 Render 层、不进 State，再走一遍树找它们就是
     *   白白多一次 O(树)。同一次访问顺手问一句，`wantsFrame()` 的"有动画在跑"
     *   判据自动覆盖 —— **不需要加第八条判据**。
     */
    private function tickSubtree(Element $element, float $timestampMs): bool
    {
        $busy = false;

        $state = States::of($element);
        if ($state !== null && $state->tickFrameStamped($timestampMs, $this->frameSerial)) {
            $busy = true;
        }

        $box = $element->renderObject();
        if ($box !== null && $box->tickAnimations($timestampMs)) {
            $busy = true;
        }

        $element->visitChildren(function (Element $child) use ($timestampMs, &$busy): void {
            if ($this->tickSubtree($child, $timestampMs)) {
                $busy = true;
            }
        });

        return $busy;
    }

    /**
     * 提交一个**可分片**的长任务（§17.4.3，P1）：每帧推一次 `step()`，不占一帧。
     *
     * ```php
     * $app->submitSliced(
     *     new FilterJob($rows, $query),
     *     fn (array $hits) => $this->store->replace($hits),
     * );
     * ```
     *
     * ★ 与 `TaskHost::submit()` 的差别只在**怎么跑**：分片在 UI 线程的帧间跑，
     *   回调同样**在帧内**、同样在 `settle()` 之前（所以改 Store 同帧生效）。
     * ★ 预算默认 4ms（见 `PumpHost`）；任务自己决定一次干多少。
     *
     * @param callable(mixed): void $onDone 完成后回调（帧内执行）
     * @param null|callable(\Throwable): void $onError 失败回调；`null` ⇒ Fatal 序列
     *
     * @api
     */
    public function submitSliced(
        SlicedJob $job,
        callable $onDone,
        ?callable $onError = null,
        int $budgetMs = 4,
    ): void {
        $this->pumps->submitSliced($job, $onDone, $onError, $budgetMs);
    }

    /** @internal 测试与 devtools：还有几个分片任务在排队 */
    public function slicedCount(): int
    {
        return $this->pumps->slicedCount();
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
     *
     * @api
     */
    public function dispatchInput(InputEvent $event, ?float $timestampMs = null): ?CursorShape
    {
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
        if ($event instanceof KeyEvent) {
            $this->keyboardFocusVisible = true;
        } elseif ($event instanceof PointerEvent || $event instanceof ScrollEvent) {
            $this->keyboardFocusVisible = false;
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
     * ★ 样式：**2px 外扩**描边（`drawRect` 只有填充 ⇒ 四条边拼），主题 `Accent` 色。
     *   直角、不跟随控件圆角（MVP，见文档能力边界）。
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

        $size = $box->size();

        if ($size === null) {
            return;
        }

        [$ox, $oy] = RenderBox::absoluteOrigin($box);
        $paint = Paint::fill($this->theme()->color(ColorToken::Accent));

        $x = $ox - 1.0;
        $y = $oy - 1.0;
        $w = $size->width + 2.0;
        $h = $size->height + 2.0;

        $canvas->drawRect(new Rect($x, $y, $w, 2.0), $paint);
        $canvas->drawRect(new Rect($x, $y + $h - 2.0, $w, 2.0), $paint);
        $canvas->drawRect(new Rect($x, $y, 2.0, $h), $paint);
        $canvas->drawRect(new Rect($x + $w - 2.0, $y, 2.0, $h), $paint);
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
     * @api
     */
    public function showMenu(Widget $panel, ?Element $anchor = null): void
    {
        $this->overlayHost->showMenu($panel, $anchor);
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
