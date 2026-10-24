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
use Drift\Input\ThemeChangedEvent;
use Drift\Input\WindowDeactivatedEvent;
use Drift\Platform\Clipboard;
use Drift\Layout\RenderBox;
use Drift\Layout\LayoutTolerance;
use Drift\Layout\RenderPopupPanel;
use Drift\Layout\RenderScrollView;
use Drift\Rendering\Canvas;
use Drift\Rendering\ColorToken;
use Drift\Rendering\Paint;
use Drift\Rendering\RenderSurface;
use Drift\Rendering\SurfaceProfile;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Text\FontLocator;
use Drift\Text\TtfFace;
use Drift\Geometry\Rect;
use Drift\Text\TextMetricsProvider;
use Drift\Widget\Scrollable;
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
use Drift\Widget\SnackbarPanel;
use Drift\Widget\TooltipPanel;

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
abstract class Application implements TextEditingHost, InputHost
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
     * 滚动输入路由（S1 第 5 步拆出的片）：滚轮 / 滚动条 thumb 拖动 /
     * 焦点滚进视口。构造期建（host = 本类，只经 `focus()` 摸焦点链）。
     */
    private ScrollInputRouter $scrollInput;

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
     * 语义树消费闭包（§9 / M5 UIA，per-app opt-in，同 taskHost() 形态）。
     *
     * ★ **默认 null ⇒ 不建语义树**（SemanticsBuilder"没人调就不建"，零开销）；
     *   应用覆写返回闭包即接入 UIA（组合根把闭包接到 WindowHost::pushSemantics）。
     */
    protected ?\Closure $semanticsSink = null;
    /**
     * 分片任务每帧的时间预算（毫秒，§17.4.3）。
     *
     * ★ 4 的来历：一帧 16.7ms，留 12ms 给布局 / 绘制 / 输入（§13.3 全树重排）
     *   ⇒ 分片最多吃掉四分之一帧。`SlicedJob::step()` 自己决定一次干多少，
     *   这只是**建议值**（"干到超预算就返回"）。
     */
    private const SLICE_BUDGET_MS = 4;

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
    /**
     * 浮层栈（arch-v2 `01`，W3）：**唯一真实来源**。`showMenu()` / `showModal()` 往这里 push。
     *
     * ★ 栈顺序 = 绘制顺序 = 命中顺序，不引入 z-index。
     * ★ 01-a/01-b 期间这里还挂着七个镜像字段（`$menu`/`$menuElement`/`$menuAnchorX`/
     *   `$menuAnchorY`/`$menuX`/`$menuY`/`$modal`）供未迁移的读点用；01-c 把读点
     *   全改成栈访问后已删除 —— 现在没有"两份状态"，只有一个栈。
     */
    protected OverlayStack $overlays;

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

    /**
     * 后台执行端口（§17.2）—— `attach()` 时由 `taskHost()` 建一次并缓存。
     *
     * ★ **必须缓存实例**：后端持有结果队列，每次现建一个会让"这一帧入队、
     *   下一帧 drain"落空（队列被换掉 ⇒ 结果永远等不到）。
     *   ⇒ 与 `accelerators()`（每次现建也行）不同，本端口是**有状态**的。
     *
     * ★ `null` = 本应用不用后台任务（`taskHost()` 返回 null）⇒ `frame()` 的那段
     *   drain 整个跳过，零开销。
     */
    protected ?TaskHost $tasks = null;

    /**
     * 排队中的分片任务（§17.4.3，P1）—— 每帧推一次 `step()`。
     *
     * ★ **不走 `TaskHost`**：分片永远在 UI 线程跑（它解决的是"别把一帧卡死"，
     *   不是"别占 UI 线程"）。所以它和端口正交：换后端不影响分片。
     *
     * ★ 元素是数组而不是对象：只有四个字段、生命周期一帧内、不需要身份 ——
     *   为它造一个类只会多一层没有信息的壳（对比 `Completion`：那个要跨边界传，
     *   有语义）。
     *
     * @var list<array{job: SlicedJob, onDone: \Closure, onError: ?\Closure, budgetMs: int}>
     */
    protected array $sliced = [];

    /** 上一帧有没有动画在跑（§17.4.9 的第四个判据，由 `tickAnimations()` 写） */
    private bool $animating = false;

    /** 每帧自增的序列号 —— `State::tickFrameStamped` 用它做同帧幂等（迟到挂载补 tick 用） */
    private int $frameSerial = 0;

    /** 上一帧的时间戳（`repaintForResize()` 复用：拖动重排不该自己读环境时钟） */
    private float $lastTimestampMs = 0.0;

    /**
     * 待触发的延时回调（W8-2，2026-10-23）—— `after()` 排进来，`pumpTimers()` 消费。
     *
     * ★ **为什么不做成"平台多开几个 `TimerHost` id"**：`TimerHost` 是**周期**定时器
     *   （`SetTimer` 会一直响），而这里要的是**一次性**延时；再者在平台层做 id 分配
     *   就要在 `pump()` 里把 `timer` 事件按 id 分发回框架 —— 一圈下来只换来
     *   15.6ms → 0ms 的精度提升，而 Tooltip / Snackbar 的 500ms 停留对 15ms 无感。
     *   ★ 代价必须说清楚：**有定时器待触发期间帧时钟保持上膛**（每 15ms 一帧空转，
     *   直到到期）。这是 `wantsFrame()` 必须加第 5 条判据的另一面 —— 不加就永远
     *   不响，加了就有这段空转。Tooltip 停留期最多 500ms（约 33 帧），可接受。
     *
     * ★★ `due` 的基准是 `max(上一帧, 上一次输入)` —— 见 `after()` 与 `$lastInputMs`。
     *   2026-10-24 之前只用"上一帧"：`after()` 常被**帧外**的回调调用（指针点击 →
     *   `onOpen`），而空闲应用的"上一帧"可能是几百毫秒前 ⇒ `due` 落在过去 ⇒
     *   **定时器当场触发**。现象（真机实测）：远程搜索的"正在加载…"一帧都没出现过。
     *
     * @var array<int, array{due: float, cb: \Closure}>
     */
    private array $timers = [];

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

    /** 下一个定时器 id（进程内自增，`cancelAfter()` 用它） */
    private int $nextTimerId = 1;

    /**
     * 当前悬停的 Tooltip 目标（`tooltipTextValue()` 声明者链上的最近 Element）。
     *
     * ★ `null` = 没有悬停在任何带提示的控件上。
     */
    private ?Element $tooltipTarget = null;

    /** 已排期的"显示"定时器 id；`null` = 没有在等待（已显示 / 已取消） */
    private ?int $tooltipTimer = null;

    /** Tooltip 浮层当前是否**真的挂在栈上**（决定"相邻切换是否要再等 500ms"） */
    private bool $tooltipShown = false;

    /** Tooltip 浮层条目（隐藏时精确移除它，不误伤同栈的菜单/模态） */
    private ?OverlayEntry $tooltipEntry = null;

    /**
     * 排队中的 Snackbar（**不含正在显示的那条**，W8-2）。
     *
     * ★ FIFO、**不设上限**：连续触发（保存 → 保存 → 保存）都该看到。
     *   框架不替应用猜"哪条更重要" —— 雪崩场景由应用自己限流。
     *
     * @var list<Snackbar>
     */
    private array $snackQueue = [];

    /** 正在显示的那条（`null` = 没有通知在显示） */
    private ?OverlayEntry $snackEntry = null;

    /** 当前这条的自动消失定时器（`null` = 没有排期，含 `duration=0` 的"永不消失"） */
    private ?int $snackTimer = null;

    /**
     * 非阻塞 I/O 轮询器（§17.4.4，P2）—— 每帧 `select(0)` 问一轮，就绪了就回调。
     *
     * ★ 挂载时建一次（它持有监听表，是**有状态**的，同 `$tasks`）。
     * ★ 为什么不是"线程里阻塞读"：见 §17.2.6 —— 线程要 30ms 起、进程要 275ms 起，
     *   而 `select(0)` 的代价是微秒级，且回调天然落在 UI 线程的帧内。
     */
    protected ?IoPoller $io = null;

    /** @param iterable<string, CommandHandler> $handlers `commands()` 的返回值 */
    public function __construct(iterable $handlers = [])
    {
        $this->router = CommandRouter::make();
        $this->overlays = new OverlayStack();

        // S1 第 4 步：文本编辑三侧（IME / 指针框选 / 编辑命令）收成最小片。
        // host = $this（片只经 TextEditingHost 摸焦点与剪贴板）—— 构造期
        // 建无时序问题：两个 getter 都是运行期就绪的依赖，片内每次现读。
        $this->textEditing = new TextEditingBridge($this);

        // S1 第 5 步：滚动输入路由（滚轮 / thumb 拖动 / 焦点滚进视口）。
        $this->scrollInput = new ScrollInputRouter($this);

        // S1 第 6 步：输入路由主体（键 / 指针四相位 / 滚轮 / 手势消歧 / 拖放 /
        // 指针捕获）。后建：收另两片做片间组合（不经 host，同第 5 步口径）。
        $this->input = new InputRouter($this, $this->scrollInput, $this->textEditing);

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

    /** 主题：构造时给死；换肤走 `Drift\Theme\ThemeScope`（§2446） */
    public function theme(): ThemeData
    {
        return ThemeData::light();
    }

    /**
     * 后台执行端口（§17.2）—— **组合根覆写它换后端**（§17.2.3 的"一行"）。
     *
     * ```php
     * protected function taskHost(): ?TaskHost
     * {
     *     return new ProcessTaskHost(4);   // 换后端只改这一行
     * }
     * ```
     *
     * ★ **只在 `attach()` 调一次**（返回值被缓存）：后端持有队列，每次现建会让
     *   "入队 / drain"分属两个队列。
     * ★ 默认给 `SyncTaskHost`（永远可用）⇒ 任何应用**默认就有正确的异步语义**
     *   （回调在帧内），只是"慢"；换真后端那天业务代码不用动。
     * ★ 返回 `null` ⇒ 本应用不用后台任务（`frame()` 跳过 drain）。
     *
     * @api
     */
    protected function taskHost(): ?TaskHost
    {
        return new SyncTaskHost();
    }

    /**
     * 已挂载的后台端口（`null` = 未挂载或应用声明不用）。
     *
     * @internal 测试与 devtools 读它；应用不该绕过 `submit()` 直接碰后端。
     */
    public function tasks(): ?TaskHost
    {
        return $this->tasks;
    }
    /**
     * 加速键表（§9.1 路由链第 ① 环，焦点之前）。
     *
     * ```php
     * public function accelerators(): AcceleratorTable
     * {
     *     return AcceleratorTable::make()
     *         ->bind(KeyChord::ctrl('R'), 'users.refresh');
     * }
     * ```
     *
     * ★ 默认空表 ⇒ 不覆写的应用零加速键、也零开销（`isEmpty()` 短路）。
     * ★ 每帧查询**现取现建**也行（值小、bind 便宜）；要省就缓存到字段——
     *   应用级数据没有不可变纪律（不是 Widget）。
     *
     * @api
     */
    public function accelerators(): AcceleratorTable
    {
        return AcceleratorTable::make();
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
     * 度量实现（缓存住 —— 它现在要读一次字体文件，不能每次问都读 11 MB）。
     *
     * @internal 缓存字段；子类覆写 `metrics()` 时不受影响
     */
    private ?TextMetricsProvider $metricsCache = null;

    /**
     * **布局确定性的注入点**（§14.2）：度量由宿主给，不由平台探测。
     *
     * ## 2026-10-17：从"纯位图"改为"真字体优先，位图作字形源"
     *
     * 位图字模只覆盖生成器扫到的那批字（864），表外字符退 `NotdefFontFace`
     * 按宽/窄猜 1.0 / 0.5 em —— 而 `skia` 后端的字形来自真 TTF（全字集）。
     * 于是"画得出、宽度是猜的"：长句错位、换行点算错。现在把**度量**接回真字体
     * （`TtfFace` 解析 cmap + hmtx），位图面降级为 `soft` 后端的**字形源**。
     *
     * ★ 顺序要紧：`TtfFace` 注册在**最前**（它 `hasGlyph()` 查真字体 cmap ⇒
     *   全字集），位图两面跟在后面只服务"TTF 也没有的字"和 `soft` 的字形委托。
     * ★ 找不到字体文件 ⇒ 退纯位图（**降级留痕**：`TtfFace::fromFile()` 记
     *   Diagnostic；不是静默 —— 现象会是"表外字宽度不对"，那条日志是唯一线索）。
     *
     * @api
     */
    public function metrics(): TextMetricsProvider
    {
        if ($this->metricsCache !== null) {
            return $this->metricsCache;
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

        $this->metricsCache = new DeterministicMetricsProvider(
            $manager->collection(),
            new DeterministicShaper(),
        );

        return $this->metricsCache;
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

        // ★ 兜底：子类构造器若没调 parent::__construct()（老夹具遗留），
        //   `$overlays` 会是未初始化状态 ⇒ 首帧 placeOverlays() 直接 Fatal。
        $this->overlays ??= new OverlayStack();

        // ★ 端口在挂载时建一次并缓存（它持有结果队列，不能每帧现建）。
        $this->tasks = $this->taskHost();

        // ★ 轮询器同理：监听表是它的状态，每帧现建等于每帧丢掉所有监听。
        $this->io = new IoPoller();

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
        //   在 `attach()` 之后 `wantsFrame()` 仍是 false ⇒ 帧时钟永不上膛 ⇒
        //   **转圈图一辈子不动**。这条是给 §17.4.10 写冒烟时抓到的，不是设计时想到的。
        $this->frame(0.0, $constraints);

        // 焦点链要在**挂载之后**建（此刻才有树可走）。
        $this->focus = new FocusManager();
        $this->focus->onHoverChanged(function (?Element $hit): void {
            $this->onHoverHit($hit);
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
     * 打开浮层面板（O-20 方案 A：第二棵根渲染树）。
     *
     * ```php
     * // 菜单标题的 onOpen 里
     * ->onOpen(fn(Menu $m, ?Element $anchor) => $this->showMenu($m->panel(), $anchor))
     * ```
     *
     * @param Widget $panel 面板（通常是 `PopupPanel`；菜单用 `Menu::panel()` / `ContextMenu::panel()`）
     * @param Element|null $anchor 锚点 Element；`null` ⇒ 用最近一次命中的（`FocusManager::lastHit()`）
     *
     * ★ **调用点是应用**（菜单的 `onOpen` / 右键入口）—— 打开要碰 `Application`，
     *   而 §4.3 禁 `Widget → App`；于是"菜单声明形体、应用管落位"这条分工
     *   （§5.1 裁决 1）在类型层面就成立了。
     *
     * @api
     */
    public function showMenu(Widget $panel, ?Element $anchor = null): void
    {
        if ($this->root === null) {
            return; // 未挂载：没有客户区，浮层无处安放
        }

        $anchor ??= $this->focus?->lastHit();

        $e = new OverlayEntry($panel);
        $e->anchor = $anchor;
        $e->prefer = OverlayEntry::BELOW;
        $e->barrier = true;
        $e->scrim = false;
        $e->dismissOnTapOutside = true;
        $e->dismissOnEsc = true;
        $e->focusTrap = false;

        $this->pushOverlay($e);
    }

    /** 关掉浮层（已关 ⇒ 无事发生；幂等） */
    public function closeMenu(): void
    {
        // 语义不变：它原来清的就是"整个槽位" ⇒ 栈版 = 全清
        while (($e = $this->overlays->pop()) !== null) {
            $e->element?->unmount();
        }

        // ★ 全清会顺手把 Tooltip / Snackbar 也清掉 ⇒ 它们的状态位必须跟着复位，
        //   否则下一次悬停会以为"还显示着"从而**跳过 500ms 延时**（或反过来握着
        //   一个已经不在栈里的条目、把队列卡死）。
        $this->tooltipEntry = null;
        $this->tooltipShown = false;
        $this->snackEntry = null;

        if ($this->snackTimer !== null) {
            $this->cancelAfter($this->snackTimer);
            $this->snackTimer = null;
        }
    }

    /**
     * 悬停命中变化（`FocusManager::onHoverChanged` 的消费者）—— Tooltip 的时序全在这里。
     *
     * ```text
     * 进入带提示的控件 ──after(500)──▶ showTooltip
     * 离开            ──cancel + 立即──▶ hideTooltip
     * 已显示时移到另一个 ──立即──▶ showTooltip（Windows 手感）
     * ```
     *
     * ★ **相邻切换不等 500ms** 是刻意的：指针扫过一排图标时，如果每个都要重新
     *   停留 500ms，用户会觉得"提示坏了"。代价是"手快划过两个图标"也会换一次
     *   内容 —— 但那比"等不到"好。
     * ★ **时序只在框架一处**：这正是"声明式"相对"应用写 hover 回调"的主要收益
     *   （见 `Widget::tooltip()` 的注释）。
     */
    private function onHoverHit(?Element $hit): void
    {
        $next = self::tooltipOwner($hit);

        if ($next === $this->tooltipTarget) {
            return;   // 同一个控件内的移动 / 同样"没有提示" ⇒ 什么都不做
        }

        $this->tooltipTarget = $next;

        // 还没到点就换了目标 ⇒ 撤掉旧排期（否则 500ms 后会弹出上一个控件的提示）
        if ($this->tooltipTimer !== null) {
            $this->cancelAfter($this->tooltipTimer);
            $this->tooltipTimer = null;
        }

        if ($next === null) {
            $this->hideTooltip();

            return;
        }

        if ($this->tooltipShown) {
            $this->showTooltip($next);

            return;
        }

        // ★ 闭包**不捕获 `$next`**：到点时以 `$tooltipTarget` 为准（它可能已经被
        //   后来的 hover 换掉了）。捕获旧值 + 忘记比对 = 弹出上一个控件的提示。
        $this->tooltipTimer = $this->after(self::TOOLTIP_DELAY_MS, function (): void {
            $this->tooltipTimer = null;

            $target = $this->tooltipTarget;
            if ($target !== null) {
                $this->showTooltip($target);
            }
        });
    }

    /**
     * 沿父链找最近的**带提示**声明者（`Widget::tooltipTextValue() !== null`）。
     *
     * ★ 与 `FocusManager::hoverOwner()` 是**两条独立判据**：那个找的是 `hover()`
     *   声明者（为了派发悬停高亮），本方法找的是 `tooltip()` 声明者。一个控件可以
     *   只声明其中任意一个。
     */
    private static function tooltipOwner(?Element $element): ?Element
    {
        for ($node = $element; $node !== null; $node = $node->parent()) {
            // ★ 空串判据（不是 `!== null`）：可选标量在 AOT 下退化 `php::Var`，
            //   2026-10-23 真机上 `?string` 恒读成 null ⇒ 提示永远不出现。
            if ($node->widget()->tooltipTextValue() !== '') {
                return $node;
            }
        }

        return null;
    }

    /**
     * 弹出提示（已显示则先撤旧的）。
     *
     * ★ 条目参数逐条都有理由：
     *   - `barrier=false` ⇒ 不拦层外点击（提示不该吃掉用户的点击）
     *   - `hitTest=false` ⇒ **指针看不见这一层**（否则悬停目标被面板抢走 ⇒ 闪烁死循环）
     *   - `dismissOnTapOutside=false` / `dismissOnEsc=false` ⇒ 提示不是模态，
     *     "关闭"只归悬停离开
     *   - `owner=$anchor` ⇒ 锚点被回收时自动收尸（`placeOverlays()` 的清扫）
     *   - `restoreFocus=null`（push 之后覆盖）⇒ **不许动焦点**：`pushOverlay()` 会
     *     快照当前焦点，关闭时"恢复"会把焦点从用户刚点的地方抢走
     */
    private function showTooltip(Element $anchor): void
    {
        $text = $anchor->widget()->tooltipTextValue();
        if ($text === '') {
            return;
        }

        $this->hideTooltip();   // 换内容：先撤旧的（同一条路径，不重复实现）

        $e = new OverlayEntry(TooltipPanel::make($text));
        $e->anchor = $anchor;
        $e->owner = $anchor;
        $e->prefer = OverlayEntry::ABOVE_CENTER;
        $e->barrier = false;
        $e->scrim = false;
        $e->dismissOnTapOutside = false;
        $e->dismissOnEsc = false;
        $e->focusTrap = false;
        $e->hitTest = false;
        $e->maxWidth = TooltipPanel::MAX_WIDTH;

        $this->pushOverlay($e);

        // ★ 必须在 push **之后**覆盖：`pushOverlay()` 内部会把当前焦点快照进
        //   `restoreFocus`（那是菜单/模态需要的"焦点回锚"）。
        $e->restoreFocus = null;

        $this->tooltipEntry = $e;
        $this->tooltipShown = true;
    }

    /** 撤掉提示浮层（未显示 ⇒ 无事发生，幂等） */
    private function hideTooltip(): void
    {
        $e = $this->tooltipEntry;

        $this->tooltipEntry = null;
        $this->tooltipShown = false;

        if ($e !== null) {
            $this->removeOverlay($e);
        }
    }

    /**
     * 弹一条底部通知（W8-2，2026-10-23）。
     *
     * ```php
     * // ★ 组合根接线（§4.3：Widget 层够不到 Application）—— 把这条闭包递给页面
     * ($this->notify)(
     *     Snackbar::make('已保存')->action('撤销', function (): void { ... })
     * );
     * ```
     *
     * ★★ **`$this->application()->showSnackbar(...)` 不存在**（`State` 上没有那个
     *   访问器，§4.3 禁 `Widget → App`）。本注释 2026-10-23 上午写成了那个形态，
     *   结果示例台真机点一下就把进程打死，而 smoke 全绿 —— 夹具本来就拿着
     *   `Application`，根本没走那条路。**示例台是这条 API 唯一的真实消费者。**
     * ★ **第一条立即显示，后面的排队**（FIFO）。不排队的话"连续保存三次"只会
     *   看到最后一次 —— 那正是通知最容易失职的场景。
     * ★ **`duration=0` ⇒ 永不自动消失**，只能 `dismissSnackbar()` 收（错误提示用）。
     * ★ 与 Tooltip 一样**不动焦点**（`restoreFocus` 置 null）：通知不该把焦点从
     *   用户正在操作的地方抢走。
     * ★★ 与 Tooltip **相反**的一点：`hitTest` 保持默认 `true` —— 动作按钮要能点。
     *   Tooltip 是 `false`（指针看不见它），Snackbar 必须看得见。这两个需求正好
     *   相反，改任何一个之前先想清楚是哪一类。
     *
     * @api
     */
    public function showSnackbar(Snackbar $snackbar): void
    {
        if ($this->root === null) {
            return; // 未挂载：没有客户区，通知无处安放
        }

        if ($this->snackEntry !== null) {
            $this->snackQueue[] = $snackbar;

            return;
        }

        $this->presentSnackbar($snackbar);
    }

    /**
     * 收掉当前这条通知（有排队 ⇒ 立刻上队首那条）。幂等。
     *
     * ★ 动作按钮点完也走这里（见 `SnackbarPanel` 注入的关闭回调）。
     */
    public function dismissSnackbar(): void
    {
        if ($this->snackTimer !== null) {
            $this->cancelAfter($this->snackTimer);
            $this->snackTimer = null;
        }

        $e = $this->snackEntry;
        $this->snackEntry = null;

        if ($e !== null) {
            $this->removeOverlay($e);
        }

        $next = $this->shiftSnackQueue();
        if ($next !== null) {
            $this->presentSnackbar($next);
        }
    }

    /** 当前是否有通知在显示（测试 / devtools） */
    public function hasSnackbar(): bool
    {
        return $this->snackEntry !== null;
    }

    /** 排队等待的通知条数（测试 / devtools） */
    public function snackbarQueueLength(): int
    {
        return \count($this->snackQueue);
    }

    /** 当前通知的条目（测试 / devtools 读参数用） */
    public function snackbarEntry(): ?OverlayEntry
    {
        return $this->snackEntry;
    }

    /**
     * 取队首并出队（`null` = 队列空）。
     *
     * ★ **单独一个带返回类型的方法**：`$this->snackQueue[0]` 的类型在 AOT 侧会被
     *   推成 `php::Var`，直接传给 `presentSnackbar(Snackbar)` 会 C2440。集中在这里
     *   转换一次，调用点就有类型了（同 `OverlayStack::at()` 的 `?OverlayEntry`）。
     */
    private function shiftSnackQueue(): ?Snackbar
    {
        $next = null;
        $rest = [];

        foreach ($this->snackQueue as $item) {
            if ($next === null) {
                $next = $item;

                continue;
            }

            $rest[] = $item;
        }

        $this->snackQueue = $rest;

        return $next;
    }

    /** 把一条通知压栈并排自动消失（内部用；已挂载前提下才调） */
    private function presentSnackbar(Snackbar $snackbar): void
    {
        $e = new OverlayEntry(SnackbarPanel::make($snackbar, function (): void {
            $this->dismissSnackbar();
        }));

        $e->prefer = OverlayEntry::BOTTOM_CENTER;
        $e->inset = Snackbar::MARGIN;
        $e->barrier = false;
        $e->scrim = false;
        $e->dismissOnTapOutside = false;
        $e->dismissOnEsc = false;
        $e->focusTrap = false;
        $e->maxWidth = Snackbar::MAX_WIDTH;
        // ★ `hitTest` 保持默认 true —— 动作按钮要能点（与 Tooltip 相反）。

        $this->pushOverlay($e);

        // ★ push 之后覆盖：`pushOverlay()` 会把当前焦点快照进 `restoreFocus`。
        $e->restoreFocus = null;

        $this->snackEntry = $e;

        $duration = $snackbar->durationValue();
        if ($duration <= 0) {
            return; // 永不自动消失（要显式 dismissSnackbar）
        }

        $this->snackTimer = $this->after($duration, function (): void {
            $this->snackTimer = null;
            $this->dismissSnackbar();
        });
    }

    /**
     * 浮层栈本体（`01` §8.1）—— 测试 / devtools 的正式入口。
     *
     * ★ 下面那批 `menu*` / `modal*` 兼容方法是**旧槽位 API 的残留**：它们都只是
     *   对这一个栈的投影。新代码要"看有几层、每层是什么"就读这里。
     *
     * @api
     */
    public function overlays(): OverlayStack
    {
        return $this->overlays;
    }

    /**
     * 菜单是否开着：栈里存在 `barrier=true && scrim=false` 的条目（`01` §8.1）。
     *
     * ★ **模态不算菜单**：模态是 `scrim=true` ⇒ 不满足条件。混答的后果是
     *   `showModal()` 之后 `isMenuOpen()` 返回真，应用里"菜单开着就别响应某个
     *   快捷键"之类的判断会开始撒谎。
     */
    public function isMenuOpen(): bool
    {
        foreach ($this->overlays->all() as $e) {
            if ($e->barrier && !$e->scrim) {
                return true;
            }
        }

        return false;
    }

    /**
     * 打开模态（§A.3.7 / §12.1 第 6 条）—— 与菜单**共用**同一个浮层槽位。
     *
     * ```php
     * // 组合根接线（§4.3：Widget 层够不到 Application）
     * ($this->openModal)(Modal::make()->title('确认删除')->child($body));
     * ```
     *
     * ★ **`$this->application()` 不存在**（同 `showSnackbar()` 的注释）。
     * ★ **模态盖在菜单之上**（`01` §8 的**唯一行为变化**）：W3 之前"同一槽位 ⇒
     *   打开模态先关菜单"，那是**槽位的产物、不是需求** —— 在下拉里点「新建分类…」
     *   弹出对话框是正常交互，关掉对话框后下拉应该还在。要"开模态就关菜单"是
     *   **应用的策略**（自己先 `closeMenu()`），框架不再替它决定。
     * ★ **面板自居中靠组合**：`Modal::build()` 给的是 `ModalHost → Center →
     *   PopupPanel`，本方法只负责"盖满 + 原点归零"（`placeEntry()` 的 `CENTER` 分支）。
     *
     * @api
     */
    public function showModal(Modal $modal): void
    {
        if ($this->root === null) {
            return; // 未挂载：没有客户区，模态无处安放（同 showMenu）
        }

        $e = new OverlayEntry($modal);
        $e->anchor = null;
        $e->prefer = OverlayEntry::CENTER;
        $e->barrier = true;
        $e->scrim = true;
        $e->dismissOnTapOutside = $modal->isDismissible();
        $e->dismissOnEsc = $modal->isDismissible();
        $e->focusTrap = true;

        $this->pushOverlay($e);
    }

    /**
     * 关掉模态（幂等；没开 ⇒ 无事发生）。
     *
     * ★ **只有应用能调它**：`Modal` 自己不关（§A.3.7「关不关归应用」）。
     *   `dismissible` 只管"用户动作能不能关"，不限制这里 —— 应用永远能关。
     *
     * @api
     */
    public function closeModal(): void
    {
        $e = $this->topModalEntry();
        if ($e === null) {
            return;
        }

        $this->removeOverlay($e);
    }

    /**
     * 把条目挂载并压栈（`showMenu` / `showModal` 的共同部分）。
     *
     * ★ `restoreFocus` 在这里快照：关闭时焦点该回到打开前的节点。
     */
    private function pushOverlay(OverlayEntry $e): void
    {
        $element = $e->panel->createElement();
        $element->mount(null, $this->menuContext());

        $e->element = $element;
        $e->restoreFocus = $this->focus?->focused();

        $this->overlays->push($e);
    }

    /** 精确移除一个浮层条目并卸载它的树（`01` §7.1） */
    private function removeOverlay(OverlayEntry $e): void
    {
        $restore = $e->restoreFocus;

        $e->element?->unmount();
        $this->overlays->remove($e);

        // `01` §6.4 焦点回锚：关掉浮层后焦点回到**打开它之前**的节点。
        // ★ 锚点已离线（它自己也被这次关闭带走了）⇒ 什么都不做，让下一帧的
        //   `collect()` 把焦点清空 —— 不猜"上一个焦点"，猜错就是焦点跑到
        //   用户没预期的地方。
        if ($restore !== null && $restore->isMounted()) {
            $this->focus?->focus($restore);
        }
    }

    /**
     * 按条目关浮层（S1 第 6 步：`InputHost` 契约的强制产物）。
     *
     * ★ 不是应用扩展点 —— `removeOverlay()` 的浮层策略（卸载 / 焦点回锚）整个
     *   住本类，输入路由片只表达"关掉这个条目"一个意图，不碰策略细节。
     *
     * @internal
     */
    public function dismissOverlay(OverlayEntry $e): void
    {
        $this->removeOverlay($e);
    }

    /**
     * 焦点陷阱的根集（`01` §6.4）—— 空数组 = 无陷阱。
     *
     * ★ 取**最上面**那个 `focusTrap` 条目（§9 边界 #14：模态里再开模态时只有
     *   最上面那层生效），并把**压在它上面的层一起收进来**：模态里开 `Select`
     *   下拉时，下拉是独立根、不在模态子树里 —— 只收模态会让下拉 Tab 不到。
     */
    private function focusTrapRoots(): array
    {
        $all = $this->overlays->all();
        $from = null;
        foreach ($all as $i => $e) {
            if ($e->focusTrap) {
                $from = $i;
            }
        }

        if ($from === null) {
            return [];
        }

        $roots = [];
        for ($i = $from, $n = count($all); $i < $n; $i++) {
            if ($all[$i]->element !== null) {
                $roots[] = $all[$i]->element;
            }
        }

        return $roots;
    }

    /**
     * 最上面的 scrim（模态）条目（`01` §8.1）。
     *
     * ★ 取**最上面的 scrim 条目**，不是"栈顶是不是模态"：模态下面可能还压着
     *   菜单（新语义允许）⇒ 栈顶是模态时才相等。
     */
    private function topModalEntry(): ?OverlayEntry
    {
        $found = null;
        foreach ($this->overlays->all() as $e) {
            if ($e->scrim) {
                $found = $e;
            }
        }

        return $found;
    }

    public function isModalOpen(): bool
    {
        return $this->topModalEntry() !== null;
    }

    /** 当前模态（测试 / devtools / 应用读 `dismissible`） */
    public function modal(): ?Modal
    {
        $e = $this->topModalEntry();
        if ($e === null || !$e->panel instanceof Modal) {
            return null;
        }

        return $e->panel;
    }

    /**
     * 浮层布局段（arch-v2 `01` §4）：**每层独立**量 + 定位 + 夹取。
     *
     * ★ 写 `$e->x/$e->y`（条目持有落点），不再写应用级单槽位字段。
     * ★ 每帧把条目根的盒 `setBase($e->x, $e->y)` 写一次 —— **写在这里而不是
     *   `Frame::run`**：Frame 只拿到算好的原点，它不该知道 base 的存在（`01` §4.4）。
     * ★ **锚点没了 ⇒ 自动 pop**（`01` §9 边界 #2）：锚点被虚拟化回收 / 所在行被
     *   删掉之后，浮层悬在左上角是**错形态**（用户看到的是一个不知道属于谁的框）。
     *   先清扫再定位 —— 清扫在快照上做，避免边遍历边改栈。
     */
    private function placeOverlays(BoxConstraints $constraints): void
    {
        // ★★ **浮层的重建必须早于浮层的布局**（2026-10-24 立的 invariant）。
        //
        //   为什么：`Element::update()` 会**换一个新渲染盒**（§13.2"Widget 不可变 ⇒
        //   换盒与换 Widget 是同一件事的两次表达"），`adoptBoxes()` 再把新盒挂进祖先。
        //   本方法原先只做"摆盒"，而 `Frame::run()` 的 settle 段排在它**后面** ⇒
        //   settle 换上的新盒这一帧**没排过**（`size` 为 null、offset 为默认值），
        //   于是 `paintContent()` 第一句"没排过 ⇒ 无墨"早退 ⇒ **面板空白一帧**。
        //
        //   首个暴露者：`Select` 面板里的 `ScrollView`（2026-10-24）—— 滚一格换一次盒、
        //   空白一格，连续滚动时面板几乎全白；而滚动本身是对的（下一帧 `appliedOffset`
        //   就到位），所以极像"渲染偶发抽风"，不像结构错。
        //   ★ 这不是 Select 特有的洞：**任何浮层里的有态控件**都中招（模态里放个
        //     `TextField`，每敲一键空白一帧）—— 只是以前没有浮层装过会自重建的内容。
        //
        //   ★ 放本方法里而不是调用点：两个调用点（`frame()` / `repaintForResize()`）
        //     都要这条顺序，而 resize 那条路更需要（约束变了 + 树也重建了）。
        //   ★ `Frame::run()` 里那段浮层 settle 保留不动，而且是**必须的**：它的返回值
        //     进 `$didRebuild`（"任一浮层重建 ⇒ 本帧不能跳过 layout"）。只在本方法 settle
        //     会把"早 settle 引发的重建"漏出那个判据 ⇒ layout 被跳过 ⇒ 新盒仍没排过。
        //     两遍 settle 幂等（`settle()` 只标脏 / 重建，无副作用累积）。
        //   ★ 异常仍走帧末那套上报口径（与 `Frame::run()` 的 settle 段同措辞）。
        try {
            // ★ 受控值 ⇒ 面板重读（W8-2，2026-10-24）：**必须在 settle 之前**，
            //   理由同下一条 invariant —— settle 换上的新盒要这一帧就排过。
            $this->syncPanelSignatures();

            foreach ($this->overlays->all() as $e) {
                $e->element?->settle();
            }
        } catch (Throwable $t) {
            Errors::report(sprintf(
                '帧循环：浮层 settle 段未捕获异常冒到帧末（沿途无错误边界）—— %s（%s:%d）',
                $t->getMessage(),
                basename($t->getFile()),
                $t->getLine(),
            ));
        }

        foreach ($this->overlays->all() as $e) {
            if ($e->anchor !== null && !$e->anchor->isMounted()) {
                $this->removeOverlay($e);

                continue;
            }

            // ★ `owner` 清扫（`01` §7）：锚点与归属者是**两个**概念 —— 锚点管"挂在哪"，
            //   归属者管"谁的生命周期决定我该不该在"。Tooltip 两者同为悬停目标，
            //   但下拉这类"锚在按钮、归属在控件"的浮层只能靠 owner 收尸。
            //   ★ 2026-10-23 之前本字段**零消费**（`01` §7 承诺了、代码没做）——
            //     死字段比缺失更贵：读代码的人会以为防线已经在。
            if ($e->owner !== null && !$e->owner->isMounted()) {
                $this->removeOverlay($e);

                continue;
            }

            $this->placeEntry($e, $constraints);
        }
    }

    /**
     * 受控值变了 ⇒ 面板重读（W8-2，2026-10-24）。
     *
     * ## 它补的是哪条缝
     *
     * ★★ **浮层是第二棵根渲染树**（`OverlayEntry::$panel` 的字段注释就是这条设计）：
     *   主树重建**不带动**面板。而 `Select` / `ComboBox` 的面板内容来自**受控属性**
     *   （`loading` / 选项表 / `emptyText`）—— 应用 `setState` 之后面板不会自己重读。
     *   2026-10-24 之前这条职责在每个控件身上（Subject + `initState` 订阅 +
     *   `didUpdateWidget` 比对签名 + notify，四步漏一步就全静默），现在收到这里。
     *
     * ## 两道闸（顺序不能换）
     *
     * ① **锚点 Widget 换了** ⇒ 那个 `State` 重建过 ⇒ 受控属性**可能**变了。
     *    `Widget` 不可变 ⇒ `===` 就是判据，O(1)。没换 ⇒ 连签名都不算
     *    （签名要拼整张选项表，5000 项的远程列表每帧算一遍是白烧）。
     * ② **签名变了** ⇒ 面板**真的**在意的东西变了 ⇒ 标脏。这一步是必需的：
     *    应用每次重建都造新的 `Option` 实例，只看①会让面板每帧重建 ⇒ 滚动位置回顶部。
     *
     * ## 为什么必须在 `placeOverlays()` 的 settle 段**之前**调
     *
     * 标脏只是置标，重建发生在紧随的 settle 里；而 `placeEntry()` 排在 settle
     * **之后** ⇒ "重建 → 布局"的顺序成立。反过来（放到 `Frame::run()` 的浮层
     * settle 段）会得到"这一帧排过的还是旧盒"，即 `76549c7` 修掉的那个"面板空白一帧"。
     *
     * ## 代价（如实记录）
     *
     * ★ 面板内容比主树**晚一帧**：本帧 `placeOverlays()` 跑的时候，主树还没 settle
     *   （`Frame::run()` 在它后面）⇒ 锚点还是旧 Widget ⇒ 这一帧看不见变化。
     *   60fps 下 16ms，用户不可见；但**用例必须跑两帧**（`tools/smoke.php` 的
     *   `$ssSettle` 就是这条）。
     *
     * @internal
     */
    private function syncPanelSignatures(): void
    {
        foreach ($this->overlays->all() as $e) {
            // 还没挂载（本帧刚 push）⇒ 面板内容本来就是按当前值造的
            if ($e->element === null) {
                continue;
            }

            $current = $e->anchor?->widget();
            if ($current === null || $current === $e->seenAnchor) {
                continue;
            }
            $e->seenAnchor = $current;

            // 没声明签名 ⇒ 不传播（**加性**：Menu / Tooltip / Modal / 静态面板逐字节不变）
            $signature = $current->overlaySignatureValue();
            if ($signature === null) {
                continue;
            }

            $value = $signature();
            if ($value === $e->lastSignature) {
                continue;
            }
            $e->lastSignature = $value;

            // 无态面板（静态内容）⇒ 无事发生，且是对的：它的内容只由 Widget 决定
            $e->element->markNeedsBuild();
        }
    }

    /**
     * 单层定位（`01` §4.1）：
     *   ① 量（模态 tight 盖满；其余无界自尺寸，超大退化 tight）
     *   ② 锚矩形（`absoluteOrigin` 沿父链，遇浮层根停止）
     *   ③ 首选方向 → 候选原点
     *   ④ 翻转（只翻一次）
     *   ⑤ 夹取
     *   ⑥ 写 `$e->x/$e->y` + 盒 `setBase`
     */
    private function placeEntry(OverlayEntry $e, BoxConstraints $constraints): void
    {
        $box = $e->element?->effectiveBox();
        if ($box === null) {
            return;
        }

        $maxW = $constraints->maxWidth;
        $maxH = $constraints->maxHeight;

        // ① 量：模态紧约束盖满（原点归零，面板自居中靠 Modal::build 的 Center）
        if ($e->prefer === OverlayEntry::CENTER) {
            if ($constraints->hasBoundedWidth() && $constraints->hasBoundedHeight()) {
                $box->layout(BoxConstraints::tight(
                    new \Drift\Geometry\Size($maxW, $maxH),
                ));
            } else {
                $box->layout(BoxConstraints::unbounded());
            }

            $e->x = 0.0;
            $e->y = 0.0;
            $box->setBase(0.0, 0.0);

            return;
        }

        // ★ 宽度上限（W8-2）：`Tooltip` 这类"按内容自尺寸、但可能很长"的层必须给
        //   有界宽 —— `RenderPopupPanel` 只在约束有界时才夹宽，而 ⑤ 的夹紧**只挪 x
        //   不改宽** ⇒ 不限宽就是"右边内容溢出到客户区外"。
        //   ★ 高度仍无界：菜单靠 `innerMaxH` 的 `maxHeight === INF` 分支决定"不限高"，
        //     改成有界会连带把菜单限高（行为变更，与本步无关）。
        $capW = $e->maxWidth > $maxW ? $maxW : $e->maxWidth;
        if (\is_infinite($capW)) {
            $box->layout(BoxConstraints::unbounded());
        } else {
            // ★ 走 `capWidth()` 工厂，**不要**在这里手写 `BoxConstraints::INFINITY`
            //   —— 外部类常量引用在 AOT 下求值成 0（面板宽高全 0、画面一丝不变，
            //   而 smoke 全绿）。详见 `BoxConstraints::capWidth()` 的注释。
            $box->layout(BoxConstraints::capWidth($capW));
        }

        // 超大面板退化：夹进客户区重量一次（内容溢出由面板自己 Overflow::Clip）
        $size = $box->size();
        if ($size !== null && ($size->width > $maxW || $size->height > $maxH)) {
            $box->layout(BoxConstraints::tight(new \Drift\Geometry\Size($maxW, $maxH)));
        }

        // ② 面板矩形（含投影留白的盒要内缩 `elevation` 才是"面板"本身）
        if ($box instanceof RenderPopupPanel) {
            $panel = $box->panelRect();
            $w = $panel->width;
            $h = $panel->height;
            $pad = $panel->left;
        } else {
            $size = $box->size();
            $w = $size?->width ?? 0.0;
            $h = $size?->height ?? 0.0;
            $pad = 0.0;
        }

        // ② 锚矩形
        [$ax, $ay, $aw, $ah] = $this->anchorRect($e);

        // ③④ 首选方向 + 翻转
        [$x, $y] = self::originFor($e->prefer, $ax, $ay, $aw, $ah, $w, $h, $maxW, $maxH, $e->inset);

        // ⑤ 夹取
        $x = min(max($x, 0.0), max(0.0, $maxW - $w));
        $y = min(max($y, 0.0), max(0.0, $maxH - $h));

        // ⑥ 写落点（盒原点 = 面板原点 − 投影留白）
        $e->x = $x - $pad;
        $e->y = $y - $pad;
        $box->setBase($e->x, $e->y);
    }

    /**
     * 锚矩形（客户区坐标）：`[x, y, w, h]`。
     *
     * ★ 无锚（模态已提前返回）/ 锚点已卸载 ⇒ `[0,0,0,0]`（落左上角，夹取兜底）。
     * ★ `AT_POINTER` 暂退化为锚点（右键菜单当前用 `anchor`，不用指针坐标）。
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function anchorRect(OverlayEntry $e): array
    {
        $anchor = $e->anchor;
        if ($anchor === null || !$anchor->isMounted()) {
            return [0.0, 0.0, 0.0, 0.0];
        }

        $box = $anchor->effectiveBox();
        if ($box === null) {
            return [0.0, 0.0, 0.0, 0.0];
        }

        [$x, $y] = self::absoluteOrigin($box);
        $size = $box->size();

        return [$x, $y, $size?->width ?? 0.0, $size?->height ?? 0.0];
    }

    /**
     * 首选方向 → 候选原点，放不下就**只翻一次**（`01` §4.2 / §4.3：不做通用避让，
     * 否则同一控件在不同窗口尺寸下弹出方向不同 ⇒ golden 无法比）。
     *
     * @return array{0: float, 1: float}
     */
    private static function originFor(
        int $prefer,
        float $ax,
        float $ay,
        float $aw,
        float $ah,
        float $w,
        float $h,
        float $maxW,
        float $maxH,
        float $inset = 0.0,
    ): array {
        // ★ 贴底居中（Snackbar）：**不做翻转** —— 一条通知没有"上面放不下就翻到下面"
        //   这种语义（它本来就贴底）。放不下（窗口比横条还矮）由 ⑤ 的夹取兜住。
        if ($prefer === OverlayEntry::BOTTOM_CENTER) {
            return [($maxW - $w) / 2.0, $maxH - $h - $inset];
        }

        if ($prefer === OverlayEntry::ABOVE) {
            if ($ay - $h >= 0.0) {
                return [$ax, $ay - $h];
            }
            if ($ay + $ah + $h <= $maxH) {
                return [$ax, $ay + $ah];   // 翻到下方
            }

            return [$ax, $ay - $h];
        }

        if ($prefer === OverlayEntry::RIGHT) {
            if ($ax + $aw + $w <= $maxW) {
                return [$ax + $aw, $ay];
            }
            if ($ax - $w >= 0.0) {
                return [$ax - $w, $ay];    // 翻到左侧
            }

            return [$ax + $aw, $ay];
        }

        if ($prefer === OverlayEntry::LEFT) {
            if ($ax - $w >= 0.0) {
                return [$ax - $w, $ay];
            }
            if ($ax + $aw + $w <= $maxW) {
                return [$ax + $aw, $ay];
            }

            return [$ax - $w, $ay];
        }

        // ★ 居中两向（W8-2 Tooltip）：水平对准锚点中心，垂直与 BELOW/ABOVE 同规则。
        //   夹取仍由调用方的 ⑤ 兜底 —— 锚点贴边时 tooltip 会偏离中心而不出界，
        //   这是刻意的（`01` §4.2「不做通用避让」：同一控件在不同窗口尺寸下弹出
        //   方向不同 ⇒ golden 无法比）。
        if ($prefer === OverlayEntry::BELOW_CENTER) {
            $cx = $ax + ($aw - $w) / 2.0;
            if ($ay + $ah + $h <= $maxH) {
                return [$cx, $ay + $ah];
            }
            if ($ay - $h >= 0.0) {
                return [$cx, $ay - $h];    // 翻到上方
            }

            return [$cx, $ay + $ah];
        }

        if ($prefer === OverlayEntry::ABOVE_CENTER) {
            $cx = $ax + ($aw - $w) / 2.0;
            if ($ay - $h >= 0.0) {
                return [$cx, $ay - $h];
            }
            if ($ay + $ah + $h <= $maxH) {
                return [$cx, $ay + $ah];   // 翻到下方
            }

            return [$cx, $ay - $h];
        }

        // BELOW（默认）/ AT_POINTER
        if ($ay + $ah + $h <= $maxH) {
            return [$ax, $ay + $ah];
        }
        if ($ay - $h >= 0.0) {
            return [$ax, $ay - $h];        // 翻到上方
        }

        return [$ax, $ay + $ah];
    }

    /** 浮层的渲染环境（复用主树的 —— 同主题 / 同度量） */
    private function menuContext(): BuildContext
    {
        return $this->root?->context()
            ?? new BuildContext(0.0, 0.0, $this->theme(), $this->metrics());
    }

    /**
     * 盒在客户区的绝对原点 —— 委派到 `RenderBox::absoluteOrigin()`（单一出处，
     * Layout 拥有盒几何）。历史：2026-10-18 语义树落地时把这段 8 行父链累加上提，
     * 免得 Input/Widget 侧为了算几何再抄一份（或反向依赖 App）。
     */
    private static function absoluteOrigin(RenderBox $box): array
    {
        return RenderBox::absoluteOrigin($box);
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

        foreach ($this->overlays->all() as $e) {
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
        $this->pumpIo();
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
        $this->pumpTasks();
        if ($prof) {
            SurfaceProfile::add('pumpTasks', \hrtime(true) - $t);
        }

        // ★ ③ 最后推进分片任务（P1）：完成的那一个也在此刻回调 ⇒ 同样落在 settle 之前。
        //   顺序（结果 → 分片 → settle）不重要，**都在 settle 之前**才重要。
        $t = $prof ? \hrtime(true) : 0;
        if ($trace !== null) {
            $trace[] = 'pumpSliced';
        }
        $this->pumpSliced();
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
        $this->pumpTimers($timestampMs);
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

        // 浮层布局段（逐层自尺寸 + 钉锚点 + 夹进客户区）—— 见 placeOverlays()
        $t = $prof ? \hrtime(true) : 0;
        if ($trace !== null) {
            $trace[] = 'placeOverlays';
        }
        $this->placeOverlays($constraints);
        if ($prof) {
            SurfaceProfile::add('placeOverlays', \hrtime(true) - $t);
        }

        // ★ `settle` 是**标记**不是真段（`FramePhase::CORE`）：Frame::run() 内部
        //   才是 settle → layout → paint → present。标记打在它前面，
        //   "跨过 settle"的越界就拿它当下标。
        if ($trace !== null) {
            $trace[] = 'settle';
        }

        $frame->run($root, $constraints, $timestampMs, $this->overlays->all(), function (Canvas $canvas): void {
            $this->paintFocusRing($canvas);
        });

        // 环已经落盘 ⇒ `wantsFrame()` 的第七判据从这里回落 false（有界）
        $this->paintedFocus = $this->focusRingTarget();

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
        foreach ($this->overlays->all() as $e) {
            if ($e->element !== null) {
                $popupRoots[] = $e->element;
            }
        }
        $this->focus?->collect([$root, ...$popupRoots], $this->focusTrapRoots());
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
     * ★ 界面要跟手的语义仍由主循环兜底：松手后 `pump()` 恢复，积压的 resize 已
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

        // ★ 预览期（拖动中的瞬时尺寸）：`Overflow::Report` 降级为裁剪 —— 用户把窗口
        //   拖窄时「内容暂时放不下」是**合法状态**，不能弹 Fatal 打断拖动；松手后
        //   主循环的正式帧照旧响亮失败（真溢出仍会被发现）。
        $previousTolerant = LayoutTolerance::$previewing;
        LayoutTolerance::$previewing = true;
        try {
            $this->placeOverlays($constraints);
            $frame->run(
                $root,
                $constraints,
                $this->lastTimestampMs,
                $this->overlays->all(),
                function (Canvas $canvas): void {
                    $this->paintFocusRing($canvas);
                },
            );
        } finally {
            LayoutTolerance::$previewing = $previousTolerant;
        }
    }

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
        $id = $this->nextTimerId++;
        $this->timers[$id] = [
            // ★ 基准取"上一帧"与"上一次输入"的**较大者**（见 `$lastInputMs`）：
            //   在输入回调里排的定时器必须以**输入那一刻**起算 —— 用"上一帧"会让
            //   空闲应用上的 400ms 变成"立刻"（真机实测：加载态一帧都没出现）。
            'due' => \max($this->lastTimestampMs, $this->lastInputMs)
                + (float) ($milliseconds < 0 ? 0 : $milliseconds),
            'cb' => $callback,
        ];

        return $id;
    }

    /** 撤销一个延时回调（已触发 / id 不存在 ⇒ 无事发生，幂等） */
    public function cancelAfter(int $id): void
    {
        unset($this->timers[$id]);
    }

    /**
     * 推进到期的延时回调（每帧一次，在 `settle()` 之前）。
     *
     * ★ **先摘再调**：回调里可以安全地 `after()` 新定时器 / `cancelAfter()` 别的
     *   —— 否则遍历中改 `$this->timers` 会漏掉或重复触发。
     * ★ 回调抛异常**不吞**（与 `pumpTasks()` / `pumpSliced()` 同策略：S3 无边界
     *   ⇒ Fatal 序列）。
     */
    private function pumpTimers(float $timestampMs): void
    {
        if ($this->timers === []) {
            return;
        }

        // ★ 先把到期的挑出来（不在遍历里改数组）
        $due = [];
        foreach ($this->timers as $id => $timer) {
            if ($timer['due'] <= $timestampMs) {
                $due[$id] = $timer['cb'];
            }
        }

        foreach ($due as $id => $callback) {
            unset($this->timers[$id]);
            $callback();
        }
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
     * ★ 顺序按"最可能为真"排（分片每帧都在跑，任务队列通常为空）。
     *
     * @api 组合根每帧调一次（`FrameClock` 就是这么用的）
     */
    public function wantsFrame(): bool
    {
        // ★ 第四个判据：有动画在跑（§17.4.9）。排最前 —— Loading 期间它恒为真。
        if ($this->animating) {
            return true;
        }

        if ($this->sliced !== []) {
            return true;
        }

        $io = $this->io;
        if ($io !== null && $io->watchCount() > 0) {
            return true;
        }

        $tasks = $this->tasks;
        if ($tasks !== null && $tasks->pending() > 0) {
            return true;
        }

        // ★ 第五个判据（W8-2）：有延时回调在等 ⇒ 必须保持上膛，否则永远不响。
        //   排最后 —— 它**通常为空**（一次 `===` 比较），而前四条在活跃场景下更常为真。
        if ($this->timers !== []) {
            return true;
        }

        // ★★ 第六个判据（W8-2 远程搜索，2026-10-24）：**面板内容落后一帧**。
        //   见 `panelsLagBehind()` —— 少了它，远程搜索的结果到了、数据也对，
        //   面板却**永远**停在旧内容（真机现象，smoke 因为显式跑两帧看不见）。
        // ★★ 第七个判据（W8-5 焦点视觉，2026-10-24）：**环目标变过但还没画**。
        //   焦点环是应用级叠加（`paintFocusRing`），不经过任何 `setState` ⇒
        //   没有这条，键盘 Tab 换焦点后环要等**下一次鼠标移动**才出现（真机现象）。
        //   ★ 比的是 `focusRingTarget()` 而不是 `focused()`：指针模态下目标恒 `null`
        //     ⇒ 点击隐藏环也能立刻触发一帧（否则点击后环要等下次输入才消失）。
        //   有界：画完把 `$paintedFocus` 记成当前目标 ⇒ 立刻回落 false。
        if ($this->focusRingTarget() !== $this->paintedFocus) {
            return true;
        }

        return $this->panelsLagBehind();
    }

    /**
     * 有浮层的锚点 Widget 换了、但 `syncPanelSignatures()` 还没看到 ⇒ 必须再跑一帧。
     *
     * ★★ 为什么这条判据是**必需**的：`syncPanelSignatures()` 跑在 `placeOverlays()`
     *   里，而主树在它**之后**才 settle ⇒ 那一帧它看到的锚点还是**旧** Widget
     *   （`Widget` 不可变 ⇒ 旧实例的受控属性也是旧的），只能等下一帧再看一次。
     *   - smoke 里"跑两帧"是**显式写的**（`$cbSettle` / `$ssSettle`）⇒ 从来看不出问题；
     *   - 真机上**没有下一帧** —— 应用处理完一次 `setState` 就静默了（前五条判据全假），
     *     帧时钟不上膛 ⇒ 面板永远停在旧内容。2026-10-24 实测：远程搜索的
     *     `deliver()` 明明把选项换成了 2 项（日志 n=2），面板还列着旧的 4 项。
     *
     * ★ **自终止**：`syncPanelSignatures()` 无论签名变没变都会更新 `seenAnchor`
     *   ⇒ 下一帧这个判据即为假，不会空转。这一点是它能放进 `wantsFrame()` 的前提。
     * ★ 成本 O(浮层数)，通常是 0 或 1 次 `===`。
     */
    private function panelsLagBehind(): bool
    {
        foreach ($this->overlays->all() as $e) {
            if ($e->element === null) {
                continue;
            }

            $current = $e->anchor?->widget();
            if ($current !== null && $current !== $e->seenAnchor) {
                return true;
            }
        }

        return false;
    }

    /**
     * 遍历树推进所有动画，并回答"**还有动画在跑吗**"（§17.4.9）。
     *
     * ★ **浮层是第二棵树**（与 `focus->collect()` / `placeOverlays()` 同一个坑）：只走
     *   `$root` 的话，菜单里放一个 `Spinner` 就永远不转，而且**没有任何报错**。
     * ★ 成本 O(树)，与同帧的 `layout()` 同阶（§13.3：Render 层本就全树重排）。
     */
    /**
     * 注入语义树消费者（§9 / M5 UIA）—— 组合根把它接到 `WindowHost::pushSemantics()`。
     *
     * ★ 框架只存闭包、**不碰平台**（§5.1：平台引用止于组合根）；默认不注入 ⇒ 不建语义树。
     */
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

    /** 六阶段预算拼成一行（诊断文案用；数字的唯一来源是 {@see self::STAGE_BUDGET_MS}） */
    private static function stageBudgetText(): string
    {
        $parts = [];
        foreach (self::STAGE_BUDGET_MS as $name => $ms) {
            $parts[] = sprintf('%s %.1f', (string) $name, $ms);
        }

        return implode(' / ', $parts);
    }

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

    /** @internal 夹具 / devtools：上次推送用的是哪一档（`full` / `coalesce` / `none`） */
    public function semanticsMode(): string
    {
        return $this->semanticsMode;
    }

    private function tickAnimations(float $timestampMs): bool
    {
        $busy = false;

        $root = $this->root;
        if ($root !== null && $this->tickSubtree($root, $timestampMs)) {
            $busy = true;
        }

        foreach ($this->overlays->all() as $e) {
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
     */
    private function tickSubtree(Element $element, float $timestampMs): bool
    {
        $busy = false;

        $state = States::of($element);
        if ($state !== null && $state->tickFrameStamped($timestampMs, $this->frameSerial)) {
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
     * 取后台结果并分派（§17.4.2 的第一步）。
     *
     * ★ 队列只能由 UI 线程消费 ⇒ 断言在这里（§17.4.2.1 的第二条断言位）。
     * ★ 整体包一层 try/catch：用户回调抛异常**不许**穿过帧循环的剩余阶段。
     *   处理策略与 `Frame::run()` 的 settle 段一致（S3：无边界 ⇒ Fatal 序列）——
     *   **不吞**，吞掉就是"界面停在上一次的样子且不报错"（G2 明令禁止）。
     */
    private function pumpTasks(): void
    {
        $tasks = $this->tasks;
        if ($tasks === null) {
            return;
        }

        $this->assertUiThread('drain');

        try {
            $tasks->drain(function (Completion $completion): void {
                $this->complete($completion);
            });
        } catch (\Throwable $e) {
            Errors::report(sprintf(
                '帧循环：后台结果回调未捕获异常 —— %s（%s:%d）',
                $e->getMessage(),
                basename($e->getFile()),
                $e->getLine(),
            ));
        }
    }

    /**
     * 把一条 `Completion` 送到对应回调（成功 → `onDone`，失败 → `onError`）。
     *
     * ★ **没给 `$onError` ⇒ Fatal 序列**：任务失败被静默吞掉就是"界面永远转圈"，
     *   与三态的 `Error` 分支（§A.2.9）矛盾 —— 那是 G2 禁止的半静默。
     */
    private function complete(Completion $completion): void
    {
        if ($completion->error !== null) {
            if ($completion->onError === null) {
                Errors::report(sprintf(
                    '后台任务失败（未提供 onError）—— %s: %s',
                    get_class($completion->error),
                    $completion->error->getMessage(),
                ));

                return;
            }

            ($completion->onError)($completion->error);

            return;
        }

        ($completion->onDone)($completion->value);
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
     * ★ 预算默认 {@see self::SLICE_BUDGET_MS}；任务自己决定一次干多少。
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
        int $budgetMs = self::SLICE_BUDGET_MS,
    ): void {
        $this->assertUiThread('submitSliced');

        $this->sliced[] = [
            'job' => $job,
            'onDone' => \Closure::fromCallable($onDone),
            'onError' => $onError === null ? null : \Closure::fromCallable($onError),
            'budgetMs' => $budgetMs,
        ];
    }

    /**
     * 推进所有分片任务（§17.4.3）—— 每帧一次，在 `settle()` 之前。
     *
     * ★ **swap 语义**：先取快照再清空，未完成的回填 ⇒ 回调里 `submitSliced()`
     *   的新任务不会丢（也不会让本帧无限长）。与 `SyncTaskHost::drain()` 同一手法。
     * ★ `step()` 抛异常 ⇒ 该任务终止、走 `$onError`（不再回填）——
     *   与 `Job::run()` 同一条错误通道，不新增第四态。
     */
    private function pumpSliced(): void
    {
        if ($this->sliced === []) {
            return;
        }

        $this->assertUiThread('slice');

        $queue = $this->sliced;
        $this->sliced = [];

        try {
            foreach ($queue as $item) {
                try {
                    $more = $item['job']->step($item['budgetMs']);
                } catch (\Throwable $e) {
                    $this->complete(new Completion(null, $e, $item['onDone'], $item['onError']));

                    continue;
                }

                if ($more) {
                    $this->sliced[] = $item;

                    continue;
                }

                $this->complete(new Completion($item['job']->result(), null, $item['onDone'], $item['onError']));
            }
        } catch (\Throwable $e) {
            // 回调抛异常：与 `pumpTasks()` 同策略（S3：无边界 ⇒ Fatal 序列，不吞）
            Errors::report(sprintf(
                '帧循环：分片任务回调未捕获异常 —— %s（%s:%d）',
                $e->getMessage(),
                basename($e->getFile()),
                $e->getLine(),
            ));
        }
    }

    /** @internal 测试与 devtools：还有几个分片任务在排队 */
    public function slicedCount(): int
    {
        return count($this->sliced);
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
        return $this->io;
    }

    /**
     * 问一轮非阻塞 I/O（§17.4.4）—— 每帧一次，在 `settle()` 之前。
     *
     * ★ 与 `pumpTasks()` / `pumpSliced()` 同策略：回调异常不吞（S3 ⇒ Fatal 序列），
     *   但要用 try/catch 把它变成"指明来源的错误"，而不是裸栈冒到组合根。
     */
    private function pumpIo(): void
    {
        $io = $this->io;
        if ($io === null) {
            return;
        }

        $this->assertUiThread('poll');

        try {
            $io->poll();
        } catch (\Throwable $e) {
            Errors::report(sprintf(
                '帧循环：IO 就绪回调未捕获异常 —— %s（%s:%d）',
                $e->getMessage(),
                basename($e->getFile()),
                $e->getLine(),
            ));
        }
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
     * while ($platform->pump()) {
     *     while (($event = $input->poll()) !== null) {
     *         $shape = $app->dispatchInput($event);
     *         if ($shape !== null) { $window->setCursor($handle, $shape); }
     *     }
     *     $app->frame($platform->now(), $screen->viewport());
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

            return null;
        }

        $root = $this->root;
        $focus = $this->focus;

        if ($root === null || $focus === null) {
            return null;   // 未挂载：没有树就没有路由
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
        if ($handled && $this->isMenuOpen() && !$this->isModalOpen()) {
            $this->closeMenu();
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
     * 开 / 关控件声明的面板（W8-2 Select）—— `InputRouter::activateFocusedWidget()`
     * 的通用缝（S1 第 6 步起激活语义住 InputRouter，本方法是 `InputHost` 契约口子）。
     *
     * ★ **开关语义**：同一个控件再激活一次就收掉。键盘 Enter 能走到这里（鼠标点
     *   第二下走不到 —— 面板开着时点控件在面板**外**，`barrier` 会先把它关掉并吞掉
     *   那次按下，这是下拉的标准手感）。
     * ★ **不靠字段记"谁开着"**：直接扫栈里 `owner === $anchor` 的条目。字段会在
     *   `dismissOnTapOutside` / `closeMenu()` / owner 清扫这些**不经过本类**的移除
     *   路径上变陈旧（Snackbar 的 `snackEntry` 就是靠 `closeMenu()` 手动复位兜的，
     *   那种补丁能少一个是一个）。
     *
     * @internal `InputHost` 契约口子，不是应用扩展点
     */
    public function toggleWidgetPanel(Element $anchor, \Closure $factory): void
    {
        $open = $this->widgetPanelEntry($anchor);
        if ($open !== null) {
            $this->removeOverlay($open);

            return;
        }

        // ★ dismiss 由**本类**注入（§4.3：面板里的按钮拿不到 `Application`）。
        //   走"按 owner 找条目"而不是捕获 `$e` —— 闭包里按引用捕获变量是 tpc 未验证形态。
        $panel = $factory(function () use ($anchor): void {
            $this->closeWidgetPanel($anchor);
        });

        $e = new OverlayEntry($panel);
        $e->anchor = $anchor;
        // ★ owner = 锚点自身：页面走了没关下拉 ⇒ `placeOverlays()` 的 owner 清扫收尸。
        $e->owner = $anchor;

        // ★ 受控值传播的**基线**（W8-2，2026-10-24，见 `syncPanelSignatures()`）：
        //   开面板这一刻就把"锚点当前 Widget"和"它声明的签名"记下来 —— 否则下一帧
        //   会以为"签名从空变成了有值"，白重建一次面板（滚动位置回到顶部）。
        $anchorWidget = $anchor->widget();
        $e->seenAnchor = $anchorWidget;
        $signature = $anchorWidget->overlaySignatureValue();
        if ($signature !== null) {
            $e->lastSignature = $signature();
        }
        $e->prefer = OverlayEntry::BELOW;
        // ★★ `barrier = true`（与菜单同）—— 这是 `dismissOnTapOutside` **唯一**生效的
        //   路径：`dispatchPointer()` 里"点在条目盒外"那一支整个包在 `if ($e->barrier)` 里，
        //   `barrier = false` 的条目直接穿透，永远轮不到关闭。
        $e->barrier = true;
        $e->scrim = false;
        $e->dismissOnTapOutside = true;
        $e->dismissOnEsc = true;
        $e->focusTrap = false;
        $e->maxWidth = self::PANEL_MAX_WIDTH;

        // ★ **不动 `restoreFocus`**（与 Tooltip / Snackbar 相反）：那两个是被"悬停"/
        //   "定时器"触发的，打开时焦点根本不在锚点上，快照下来是错的；这里打开它的
        //   就是锚点自己 ⇒ `pushOverlay()` 的快照天然正确，收面板时焦点回到控件上。
        $this->pushOverlay($e);
    }

    /** 栈里属于这个锚点的面板条目（没有 ⇒ `null`） */
    private function widgetPanelEntry(Element $anchor): ?OverlayEntry
    {
        foreach ($this->overlays->all() as $e) {
            if ($e->owner === $anchor) {
                return $e;
            }
        }

        return null;
    }

    /** 收掉这个锚点的面板（幂等；没有 ⇒ 无事发生） */
    private function closeWidgetPanel(Element $anchor): void
    {
        $e = $this->widgetPanelEntry($anchor);
        if ($e === null) {
            return;
        }

        $this->removeOverlay($e);
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
}
