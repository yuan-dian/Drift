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
use Drift\Input\GestureArena;
use Drift\Input\GestureRecognizer;
use Drift\Input\InputEvent;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Input\ScrollEvent;
use Drift\Input\ThemeChangedEvent;
use Drift\Platform\Clipboard;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderTextField;
use Drift\Layout\LayoutTolerance;
use Drift\Layout\RenderObject;
use Drift\Layout\RenderPopupPanel;
use Drift\Layout\RenderScrollView;
use Drift\Rendering\RenderSurface;
use Drift\Rendering\SurfaceProfile;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Text\FontLocator;
use Drift\Text\TtfFace;
use Drift\Geometry\Rect;
use Drift\Text\TextMetricsProvider;
use Drift\Widget\TextFieldState;
use Drift\Widget\Scrollable;
use Drift\Theme\ThemeData;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Drag\Draggable;
use Drift\Widget\Element;
use Drift\Widget\Menu\Menu;
use Drift\Widget\Modal;
use Drift\Widget\StatefulElement;
use Drift\Widget\Widget;
use Drift\Widget\SemanticsBuilder;
use Drift\Widget\ToggleBase;

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
abstract class Application
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
     * UI 线程身份（`GetCurrentThreadId`，attach 时记下）。
     *
     * ★ 为什么不是 `getmypid()`：同进程所有线程的 pid 相同 ⇒ 区分不了 UI 线程与 worker。
     *   见 DESIGN §17.4.2.1（WPF `VerifyAccess()` 的等价物）。
     */
    protected int $uiThreadId = 0;

    protected CommandRouter $router;

    /** 拖动状态机（§A.3.5）；懒建 —— 不拖的应用零成本 */
    protected ?DragController $drag = null;

    /**
     * 进行中的手势竞技场（§9.1 手势消歧，2026-10-18 落地）。
     *
     * ★ **仅当命中链上有 `Draggable` 声明者时开**：`down` 记 arena（成员 =
     *   Tap + Drag），激活从 down 推迟到裁决 —— `up` 未过阈值 ⇒ Tap 胜 ⇒ 激活；
     *   `move` 过阈值 ⇒ Drag claim ⇒ Tap 让位（修"拖可拖控件时命令在 down 先响"）。
     *   无 `Draggable` 声明者的控件维持"down 即激活"（A15 不动，防回归）。
     * ★ 拖动阈值 `DRAG_THRESHOLD_PX` 归 `GestureArena`（识别器自带，单一出处）。
     */
    protected ?GestureArena $gestureArena = null;

    /**
     * 滚动条 thumb 拖动状态（§12.3，2026-10-19）。
     *
     * `['state' => Scrollable, 'box' => RenderScrollView, 'last' => float]`。
     * ★ 为什么单独一个状态而不是并进 `DragController`：thumb 不是控件（它是
     *   paint 期画的），没有 Draggable 声明、不进手势竞技场 —— 混进去会让
     *   "拖动的是控件"这条判据失真。
     */
    private ?array $thumbDrag = null;

    /**
     * 捕获指针的节点（`06` §5，W3-L5-6）：非空 ⇒ 指针事件**只发给它**，
     * 不再命中测试。
     *
     * ★ **捕获的全部意义就是"不命中测试"**：拖到控件外面仍然归它 —— 否则
     *   "拖出边界就断"这类 bug 每个控件都要自己防一遍（现在已经有
     *   `DragController` 和 `$thumbDrag` 两套手写版本，第三套必然走样）。
     * ★ 四条释放路径（少一条就是"事件永远发给死节点"）：
     *   ① 指针抬起（UP / CANCEL）② 捕获者卸载（`frame()` 里清扫 + DIAGNOSTIC）
     *   ③ 窗口失焦 ④ 浮层外的 `down`（浮层优先于捕获，见 `dispatchPointer`）。
     */
    private ?Element $captureNode = null;

    /**
     * 文本框选手势（2026-10-21，§A.2.9 的指针侧）：
     * `down` 落**编辑中**的输入框 ⇒ 记 State，`move` 延伸、`up` / `leave` 收口。
     *
     * ★ **只记 State，不记盒**：编辑只标脏 ⇒ 帧末 flush 会重建盒
     *   （`effectiveBox()` 是新对象）⇒ 缓存盒等于拿上一帧的几何算这一帧
     *   的偏移。盒每步现取（`textSelectBox()`）。
     */
    private ?TextFieldState $textSelect = null;

    /**
     * 语义树消费闭包（§9 / M5 UIA，per-app opt-in，同 taskHost() 形态）。
     *
     * ★ **默认 null ⇒ 不建语义树**（SemanticsBuilder"没人调就不建"，零开销）；
     *   应用覆写返回闭包即接入 UIA（组合根把闭包接到 WindowHost::pushSemantics）。
     */
    protected ?\Closure $semanticsSink = null;

    /**
     * 滚轮一格滚多少逻辑像素（2026-10-17："不跟手"的根因修复）。
     *
     * ★ 分工：平台只做**量化**（`÷120` 把 WHEEL_DELTA 收成"格"，§6.1），
     *   "一格值多少 px" 是**策略**，归框架。48 = 3 行 × 16px 行高，与
     *   Windows 默认 `SPI_GETWHEELSCROLLINES=3`、浏览器每格 ~40–53px 同量级。
     *   ★ 修复前是 1px/格 ⇒ "滑不动/不跟手"（一格 notch 只挪一个像素）。
     * ★ 桥未暴露 `SPI_GETWHEELSCROLLINES` ⇒ 这里是常量而非系统值（缺口已登记）。
     */
    private const WHEEL_NOTCH_PX = 48.0;

    /**
     * 分片任务每帧的时间预算（毫秒，§17.4.3）。
     *
     * ★ 4 的来历：一帧 16.7ms，留 12ms 给布局 / 绘制 / 输入（§13.3 全树重排）
     *   ⇒ 分片最多吃掉四分之一帧。`SlicedJob::step()` 自己决定一次干多少，
     *   这只是**建议值**（"干到超预算就返回"）。
     */
    private const SLICE_BUDGET_MS = 4;

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

        // 只注册**显式传进来**的那些。`commands()` **不在构造器里调** ——
        // PHP 的构造链是"父先子后"，此刻子类构造器里的字段（`$this->store` 之类）
        // 还都是 null ⇒ 照 §A.2 的样例会直接炸。注册推迟到 `attach()`（见那儿）。
        foreach ($handlers as $name => $handler) {
            $this->router->on((string) $name, $handler);
        }
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
     * 栈顶条目的 Element（测试 / devtools）。
     *
     * @deprecated 用 `overlays()->top()?->element` —— 本方法只是旧槽位的投影，
     *             "栈顶"在有模态压着时返回的是模态的元素，名字会骗人。
     */
    public function menuElement(): ?Element
    {
        return $this->overlays->top()?->element;
    }

    /**
     * 栈顶条目的落点（夹紧后；测试 / devtools）。
     *
     * @deprecated 用 `overlays()->top()` 的 `x`/`y`（同上：栈顶 ≠ 菜单）。
     */
    public function menuOrigin(): array
    {
        $top = $this->overlays->top();

        return [$top?->x ?? 0.0, $top?->y ?? 0.0];
    }

    /**
     * 打开模态（§A.3.7 / §12.1 第 6 条）—— 与菜单**共用**同一个浮层槽位。
     *
     * ```php
     * $this->application()->showModal(
     *     Modal::make()->title('确认删除')->child($body)
     * );
     * ```
     *
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
        foreach ($this->overlays->all() as $e) {
            if ($e->anchor !== null && !$e->anchor->isMounted()) {
                $this->removeOverlay($e);

                continue;
            }

            $this->placeEntry($e, $constraints);
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

        $box->layout(BoxConstraints::unbounded());

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
        [$x, $y] = self::originFor($e->prefer, $ax, $ay, $aw, $ah, $w, $h, $maxW, $maxH);

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
    ): array {
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
     * IME 定位用的光标矩形（§8.4）—— **逻辑像素**的窗口坐标 `[x, y, h]`。
     *
     * ★ 返回 null = 无插入符（无焦点 / 焦点不在编辑态 / 光标矩形算不出来）。
     *   组合根把它喂给 `Platform::imeCaret()`；平台侧负责 × dpr（§6.1：
     *   **量化只发生在平台侧** —— 这里给逻辑值，与 `BoxConstraints`/视口同单位）。
     *
     * ★ 为什么不做在框架里每帧自动同步：**框架不持平台**（§5.1「平台名只出现
     *   在组合根」）⇒ 这一行的驱动归组合根。几何算在框架侧是为了可头侧测试
     *   （见 smoke 的 IME 夹具）—— 坐标换算是这条链上最容易错的一段。
     */
    public function imeCaretRect(): ?array
    {
        $focused = $this->focus?->focused();
        if (!$focused instanceof StatefulElement) {
            return null;
        }

        $state = $focused->state();
        if (!$state instanceof TextFieldState || !$state->isEditing()) {
            return null;
        }

        $box = $focused->effectiveBox();
        if ($box === null) {
            return null;
        }

        $local = $state->caretRect();   // 盒内坐标（含内边距）
        if (!$local instanceof Rect) {
            return null;
        }

        [$originX, $originY] = self::absoluteOrigin($box);
        [$padLeft, $padTop] = self::contentInset($box);

        // ★ **必须加 padding**（arch-v2 `02` §4.1.1）：`caretRect()` 是**内容盒**
        //   坐标（插入符自己走 `paintContent()` 那条路，画得是对的），而
        //   `absoluteOrigin()` 给的是**边框盒**原点。W5 之前两者是同一个（padding
        //   是祖先盒），之后差一个 `(padLeft, padTop)`。
        //   漏了 ⇒ IME 候选框偏一个 padding；而候选框是**独立原生窗口**，不在应用
        //   截图里 ⇒ 四类"只有真机能抓"的失败模式一条也抓不到它。
        return [
            $originX + $padLeft + $local->left,
            $originY + $padTop + $local->top,
            $local->height,
        ];
    }

    /**
     * 盒的**内容盒原点**相对边框盒原点的偏移（arch-v2 `02` §4.1.1）。
     *
     * W5 之前 padding 是祖先盒 ⇒ `absoluteOrigin()` 已经含了它；W5 之后 padding
     * 搬进同一个盒 ⇒ 凡是"内容盒坐标 → 窗口坐标"的地方都要自己补上这一步。
     *
     * @return array{0: float, 1: float} `[dx, dy]`
     */
    private static function contentInset(RenderBox $box): array
    {
        $m = $box->mods();
        if ($m === null || !$m->hasPadding()) {
            return [0.0, 0.0];
        }

        return [$m->padLeft(), $m->padTop()];
    }

    /**
     * 焦点里**在编辑**的输入框（§9.1 第 ③ 环与指针框选共用的门控）。
     *
     * ★ 与 `imeCaretRect()` 同一个判据：焦点链上的 `StatefulElement` 且
     *   `TextFieldState::isEditing()`。编辑命令与框选起手走同一个助手
     *   ⇒ 不可能一个放行、另一个拦住。
     */
    private function editingTextFieldState(): ?TextFieldState
    {
        $focused = $this->focus?->focused();
        if (!$focused instanceof StatefulElement) {
            return null;
        }

        $state = $focused->state();
        if (!$state instanceof TextFieldState || !$state->isEditing()) {
            return null;
        }

        return $state;
    }

    /**
     * 输入框的**真实字段盒**（穿过包装层现取，不缓存 —— 见 `$textSelect`）。
     *
     * ★ 有 `label()` 的字段 build() 出来是 `Column[Text, 字段]` ⇒ 焦点元素的
     *   `effectiveBox()` 是 Column 的盒；拿它算 x 偏移会整体错一个标签宽
     *   （2026-10-19 IME 候选框贴角的同一个坑，见 `Element::findBox()`）。
     */
    private function textSelectBox(TextFieldState $state): ?RenderTextField
    {
        $focused = $this->focus?->focused();
        if (!$focused instanceof StatefulElement || $focused->state() !== $state) {
            return null;
        }

        $box = $focused->findBox(static fn(RenderBox $b): bool => $b instanceof RenderTextField);

        return $box instanceof RenderTextField ? $box : null;
    }

    /**
     * down 落在输入框上（`focusHit()` 已成功 ⇒ 焦点就是它）⇒ 插入符落到
     * 指针处 + 记下框选手势（`move` 延伸、`up` / `leave` 收口）。
     *
     * ★ `moveCaret()` 折叠既有选区 —— "点一下 = 重新定位"（与各平台一致），
     *   选区靠随后的拖动重建，不继承上一轮。
     * ★ `offsetAt()` 收**盒内** x（它自己减左内边距）⇒ 先扣掉盒的绝对原点。
     */
    private function beginTextSelection(float $x): void
    {
        $state = $this->editingTextFieldState();
        if ($state === null) {
            return;
        }

        $box = $this->textSelectBox($state);
        if ($box === null) {
            return;
        }

        [$originX] = self::absoluteOrigin($box);
        [$padLeft] = self::contentInset($box);
        // ★ `offsetAt()` 要的是**内容盒** x（它自己再减一次 `insets->left`）⇒
        //   先扣边框盒原点，再扣 padding（`02` §4.1.1）。漏了 ⇒ 插入符落点偏右
        //   一个 padding，点到的是后面几个字。
        $state->moveCaret($box->offsetAt($x - $originX - $padLeft));

        $this->textSelect = $state;
    }

    /**
     * 框选推进（`move`）：选区活动端延伸到指针处。
     *
     * ★ 取不到盒（控件卸载 / 焦点被挪走）⇒ 就地收口，不拖着坏手势。
     */
    private function extendTextSelection(float $x): void
    {
        $state = $this->textSelect;
        if ($state === null) {
            return;
        }

        $box = $this->textSelectBox($state);
        if ($box === null) {
            $this->textSelect = null;

            return;
        }

        [$originX] = self::absoluteOrigin($box);
        [$padLeft] = self::contentInset($box);
        // ★ 同 `beginTextSelection()`：`offsetAt()` 收内容盒 x。
        $state->extendSelectionTo($box->offsetAt($x - $originX - $padLeft));
    }

    /**
     * 编辑命令环（§9.1）：`Ctrl+C` 复制 / `Ctrl+X` 剪切 / `Ctrl+V` 粘贴。
     *
     * 位置在加速键**之前**（§9.1 让位规则 ② 的"编辑命令优先"）：焦点里有个
     * 在编辑的输入框时，这三个组合归它。返回 `false` = 这一环没吃，事件
     * **原样**继续往加速键走 —— 四条门控任一不满足都放行（不吞键）：
     *
     *   ① 不是 C / X / V
     *   ② 焦点里没有在编辑的输入框（§5.1：`State` 拿不到平台 ⇒ 环住 `App`）
     *   ③ 平台没递剪贴板进来（`clipboard()` 返回 null）
     *   ④ C / X 没有选区；V 剪贴板没有文本
     *
     * ★ ④ 的设计：**没有剪贴板时 `Ctrl+C` 不是死键** —— 回落给加速键，应用
     *   照样能把它绑成"复制选中行"之类的命令（"声明独占却没人接"让键失效，
     *   正是 §9.1 让位规则想避免的）。
     * ★ `V` 不经 `claimKeys` 声明独占：声明了就得有人接，没人接就是死键；
     *   这里的"环在加速键之前"已经达到同一优先级，且门控不满足时自动让位。
     */
    private function dispatchEditCommand(KeyEvent $event): bool
    {
        $chord = $event->chord;

        $copy = $chord->is(KeyChord::ctrl('C'));
        $cut = $chord->is(KeyChord::ctrl('X'));
        $paste = $chord->is(KeyChord::ctrl('V'));

        if (!$copy && !$cut && !$paste) {
            return false;
        }

        $state = $this->editingTextFieldState();
        if ($state === null) {
            return false;
        }

        $clipboard = $this->clipboard();
        if ($clipboard === null) {
            return false;
        }

        if ($paste) {
            if (!$clipboard->hasText()) {
                return false;
            }

            $state->replaceSelection($clipboard->readText());

            return true;
        }

        if (!$state->hasSelection()) {
            return false;
        }

        $clipboard->writeText($state->selectedText());

        if ($cut) {
            $state->deleteSelection();
        }

        return true;
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

        $frame->run($root, $constraints, $timestampMs, $this->overlays->all());

        // ★ ⓹ 迟到挂载补 tick（2026-10-20 修框架洞）：⓸ 在 settle **之前**跑，而页面
        //   挂载发生在 `Frame::run()` 的 settle 段 —— 于是"这一帧刚挂上的页"的 State
        //   在 ⓸ 时还不在树里、从未被 `tickFrame()` 访问 ⇒ `$animating` 当帧永远是
        //   false ⇒ `wantsFrame()` 返回 false ⇒ 帧时钟不重新上膛 ⇒ **之后没鼠标事件就
        //   永远静止**（Spinner / 自绘页同病，真机探针抓到）。补一遍把刚挂载的页也 tick
        //   一次；既有状态因 `tickFrameStamped` 的每帧戳被跳过（不重复推进）。任一新挂载
        //   的 State 还想下一帧 ⇒ `$animating` 置真 ⇒ 时钟重新上膛，动画得以续跑。
        if ($trace !== null) {
            $trace[] = 'tickLateMount';
        }
        $this->animating = $this->animating || $this->tickAnimations($timestampMs);

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
        $captureNode = $this->captureNode;
        if ($captureNode !== null && !$captureNode->isMounted()) {
            Errors::report(sprintf(
                '指针捕获：捕获者已卸载但仍握着指针 —— 自动释放。'
                    . "\n" . '修法：在控件自己的卸载路径里先调 releasePointer()，'
                    . '或把捕获挂在会被卸载的子树外面。',
            ), Errors::DIAGNOSTIC);
            $this->captureNode = null;
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
            );
        } finally {
            LayoutTolerance::$previewing = $previousTolerant;
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

        return $tasks !== null && $tasks->pending() > 0;
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
    private function pumpSemantics(): void
    {
        $sink = $this->semanticsSink;
        if ($sink === null) {
            return;
        }
        $tree = SemanticsBuilder::build($this->root, $this->focus?->focused());
        ($sink)($tree->toArrays()['nodes']);
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

        if ($element instanceof StatefulElement) {
            $state = $element->state();
            if ($state !== null && $state->tickFrameStamped($timestampMs, $this->frameSerial)) {
                $busy = true;
            }
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
     * ★ 释放有四条路（见 `$captureNode` 的注释），**少一条就是死节点收事件**。
     *
     * @api
     */
    public function capturePointer(Element $node): void
    {
        $this->captureNode = $node;
    }

    /**
     * 释放指针捕获（幂等；没捕获 ⇒ 无事发生）。
     *
     * @api
     */
    public function releasePointer(): void
    {
        $this->captureNode = null;
    }

    /** @internal 当前捕获者（测试 / devtools） */
    public function captureNode(): ?Element
    {
        return $this->captureNode;
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
     * @return CursorShape|null 指针事件 ⇒ 命中链解析出的光标形状（★ 与焦点
     *   **同一次命中**，不重复走树 —— §9.1「一次命中，三方消费」）；其余事件
     *   ⇒ `null`（不改光标）。★ 应用它需要 `WindowHost`，而**框架不持平台**
     *   （§5.1：共享代码零平台引用）⇒ 由组合根把返回值喂给 `setCursor()`。
     *
     * @api
     */
    public function dispatchInput(InputEvent $event): ?CursorShape
    {
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

        if ($event instanceof KeyEvent) {
            // §9.1 关闭语义 #3：**ESC 在 `dispatchKey` 之前拦** —— 否则被焦点
            // 控件（输入框）吃掉，菜单关不掉。
            if ($event->chord->is(KeyChord::escape())) {
                // 浮层栈：栈顶条目决定 Esc 语义（arch-v2 `01` §6.3）
                // ★ `dismissOnEsc=false` ⇒ **吞掉但不关**。"拒绝"不等于"放行" ——
                //   若把 ESC 交回加速键链，Ctrl+W 之类能绕过"必须处理的对话框"。
                $top = $this->overlays->top();
                if ($top !== null) {
                    if (!$top->dismissOnEsc) {
                        return null;
                    }

                    $this->removeOverlay($top);

                    return null;
                }

                if ($this->drag?->isDragging() === true) {
                    $this->cancelDrag();

                    return null;
                }

                // 仅 arena 未裁决（按下未过阈值）⇒ ESC 丢弃这次手势意图并消费该键
                if ($this->gestureArena !== null && !$this->gestureArena->isClosed()) {
                    $this->gestureArena->leave();
                    $this->gestureArena = null;

                    return null;
                }

                // ESC 同样取消文本框选手势（选区折叠归焦点控件的 Esc 分支，
                // 见 `TextFieldState::handleKey()`）
                $this->textSelect = null;
            }

            // §9.1 第 ① 环**之前**：编辑命令（Ctrl+C / X / V，§9.1 让位规则 ②
            // 的"编辑命令优先"）。返回 false = 这一环没吃 ⇒ 原样落给加速键。
            if ($this->dispatchEditCommand($event)) {
                return null;
            }

            // §9.1 第 ① 环：加速键（在焦点之前）。
            // 让位规则 ②：焦点控件声明了独占该组合 ⇒ 不吃快捷键，往下走焦点链。
            $accel = $this->accelerators()->resolve($event->chord);
            if ($accel !== null && !$focus->claims($event->chord)) {
                $this->dispatch($accel);

                return null;
            }

            if ($focus->dispatchKey($event)) {
                return null;
            }

            // §9.1：焦点按钮"回车/空格能触发"—— 动作缝的键盘侧激活
            $this->activateFocused($event);

            return null;
        }

        if ($event instanceof PointerEvent) {
            return $this->dispatchPointer($event, $root, $focus);
        }

        if ($event instanceof ScrollEvent) {
            // 滚轮（2026-10-17）：命中 → 沿命中链找最近的 ScrollViewState → scrollBy。
            //
            // ★ 与指针分支同构的三段：**浮层开着 ⇒ 先归浮层**（长菜单自己滚），
            //   轮在浮层外 ⇒ 不穿透滚主树（同语义 #1：否则"关菜单"翻掉整页）。
            //   ★ 命中一次、就滚那一个：指针停在哪个视口里就滚哪个 —— 多个滚动
            //   容器各自滚动，外层的视口不吞内层的事件（scrollAt 只找最近的）。
            // ★ **格 → 像素只在这里发生一次**（策略层）：`$event->delta` 是平台
            //   量化出的"格"（WHEEL_DELTA/120），`scrollBy()` 起收的是逻辑像素。
            $px = $event->delta * self::WHEEL_NOTCH_PX;

            for ($i = $this->overlays->count() - 1; $i >= 0; $i--) {
                $e = $this->overlays->at($i);
                if ($e === null || $e->element === null) {
                    continue;
                }

                $hit = $focus->hitTest($event->x - $e->x, $event->y - $e->y, $e->element);
                if ($hit !== null) {
                    $this->scrollAt($focus->elementAt($e->element, $hit), $px);

                    return null;
                }

                if ($e->barrier) {
                    return null; // 浮层开着、轮在浮层外：不穿透
                }
            }

            $hit = $focus->hitTest($event->x, $event->y, $root);
            $element = $hit === null ? null : $focus->elementAt($root, $hit);
            $this->scrollAt($element, $px);

            return null;
        }

        return null;
    }

    /**
     * 沿命中 Element 的父链找**最近**的可滚动视口并滚动它（滚轮路由）。
     *
     * ★ 找不到 ⇒ 无事发生（轮在不可滚动的区域上是合法的，不报错、不改光标）。
     * ★ **只找最近的**：嵌套滚动容器里，轮落在内层上就只滚内层 —— 外层的
     *   视口不吞内层的事件（§A.15：不预先发明没消费者的相位/规则，嵌套滚动的
     *   "滚动到边界再传给外层"留给真有消费者时）。
     *
     * @internal 由 dispatchInput() 的 ScrollEvent 分支调
     */
    /**
     * 命中滚动条 thumb（返回拖动状态；`null` = 没点中）。
     *
     * ★ 沿命中链找**最近**的可滚动视口，再问它的 `thumbRect()` —— 两个条件都满足
     *   才算点中：视觉上 thumb 压在那个视口的右缘（横向在底缘），点在那里
     *   用户的意思就是"拖这条"。
     * ★ 容差 2px：条只有 6px 宽，像素级精确抓取对鼠标过于苛刻。
     *
     * @return null|array{state: Scrollable, box: RenderScrollView, last: float}
     */
    private function scrollThumbAt(float $x, float $y, Element $root, FocusManager $focus): ?array
    {
        $hit = $focus->hitTest($x, $y, $root);
        $element = $hit === null ? null : $focus->elementAt($root, $hit);

        for ($node = $element; $node !== null; $node = $node->parent()) {
            if (!$node instanceof StatefulElement) {
                continue;
            }

            $state = $node->state();
            if (!$state instanceof Scrollable) {
                continue;
            }

            $box = $node->findBox(static fn(RenderBox $b): bool => $b instanceof RenderScrollView);
            if (!$box instanceof RenderScrollView) {
                return null;
            }

            $bar = $box->thumbRect();
            if ($bar === null) {
                return null;   // 内容不溢出：没有条可拖
            }

            [$originX, $originY] = self::absoluteOrigin($box);
            $left = $originX + $bar->left - 2.0;
            $top = $originY + $bar->top - 2.0;

            if ($x < $left || $x > $left + $bar->width + 4.0
                || $y < $top || $y > $top + $bar->height + 4.0) {
                return null;
            }

            return [
                'state' => $state,
                'box' => $box,
                'last' => $box->isVertical() ? $y : $x,
            ];
        }

        return null;
    }

    /**
     * thumb 拖动的一步：位移 → 滚动量（主轴方向按视口取向取 x 或 y）。
     *
     * ★ 映射是"thumb 走多远、内容走多远"：`dy * maxScroll / thumbTravel`。
     *   thumb 下移 ⇒ offset 增大（看到下面）—— 与 `scrollBy` 的正向语义一致，
     *   所以**不需要额外取反**（滚轮那层已经在壳里翻译过了）。
     */
    private function dragScrollThumb(PointerEvent $event): void
    {
        $drag = $this->thumbDrag;
        if ($drag === null) {
            return;
        }

        /** @var Scrollable $state */
        $state = $drag['state'];
        /** @var RenderScrollView $box */
        $box = $drag['box'];

        $travel = $box->thumbTravel();
        $max = $box->maxScroll();
        if ($travel <= 0.0 || $max <= 0.0) {
            return;
        }

        $now = $box->isVertical() ? $event->y : $event->x;
        $delta = ($now - (float) $drag['last']) * $max / $travel;
        if ($delta !== 0.0) {
            $state->scrollBy($delta);
        }

        $this->thumbDrag['last'] = $now;
    }

    private function scrollAt(?Element $element, float $delta): void
    {
        for ($node = $element; $node !== null; $node = $node->parent()) {
            if (!$node instanceof StatefulElement) {
                continue;
            }

            $state = $node->state();
            if ($state instanceof Scrollable) {
                $state->scrollBy($delta);

                return;
            }
        }
    }

    /**
     * 命中的盒是否落在**模态面板**里（而非遮罩上）。
     *
     * ★ `Modal` 的 build 把面板包成 `ModalHost → Center → PopupPanel`，遮罩上的
     *   命中停在 `Center` / `ModalHost`（默认 `hitTest()`：子都不中 ⇒ 返回自己）
     *   ⇒ "父链上有 `RenderPopupPanel`" 就是"点在面板里"的判据。用父链而不是
     *   "命中的是不是 Center"，是为了面板外面再包布局（比如加个底部按钮栏）也成立。
     * ★ 边界：面板盒含 `elevation` 的投影留白 ⇒ 投影那 6 逻辑像素也算"面板内"
     *   （点它不关模态）。要精确到 `panelRect()` 得把坐标换算进盒坐标系，
     *   收益不值这个复杂度 —— 记在这里而不是假装没有。
     */
    private function hitsModalPanel(?RenderObject $hit): bool
    {
        for ($node = $hit; $node !== null; $node = $node->parent()) {
            if ($node instanceof RenderPopupPanel) {
                return true;
            }
        }

        return false;
    }

    /**
     * 把一条指针事件交给捕获者（`06` §5.2）。
     *
     * ★ **坐标不换算**：回调拿到的是客户区坐标 —— 与 `PointerEvent` 同源，
     *   控件要局部坐标就自己减 `absoluteOrigin()`。在这里替它减会逼框架
     *   假设"捕获者一定有一个盒"（拖放 feedback 没有）。
     * ★ 回调抛异常 ⇒ 报错 + **释放捕获**：留着一个会抛的捕获者等于之后每个
     *   指针事件都炸一次，而且树里没有别的东西能收到事件（静默冻住）。
     */
    private function dispatchToCapture(Element $node, PointerEvent $event): ?CursorShape
    {
        $handler = null;
        for ($n = $node; $n !== null; $n = $n->parent()) {
            $candidate = $n->widget()->pointerHandlerValue();
            if ($candidate !== null) {
                $handler = $candidate;

                break;
            }
        }

        if ($handler === null) {
            return null;   // 捕获者没声明回调：合法（捕获只用于"吃掉事件"）
        }

        try {
            $result = $handler($event, $node);
        } catch (\Throwable $e) {
            Errors::report(sprintf(
                '指针捕获：回调未捕获异常 —— %s（%s:%d）',
                $e->getMessage(),
                basename($e->getFile()),
                $e->getLine(),
            ));
            $this->releasePointer();

            return null;
        }

        return $result instanceof CursorShape ? $result : null;
    }

    /**
     * 指针事件派发（**四相位**，2026-10-17 move/up/leave 通路落地）。
     *
     * - **down**：三段（浮层优先 / 右键菜单 / 焦点激活 —— M3 修复的行为不动）
     *   + 拖放阈值起点（落在 `Draggable` 上记 pending，**不开始拖动**）
     * - **move**：光标刷新 + 悬停派发（目标变化才通知）+ 拖放阈值推进
     * - **up**：拖放放下（激活仍住 down —— 不做 press/release 视觉，A15）
     * - **leave**：悬停全退出 + 拖放取消（无 `SetCapture` 的诚实边界：
     *   拖出窗口即取消，**不卡死**）
     *
     * ★ **浮层开着时四相位都先归浮层**（与 down 同一条"不穿透"纪律）：
     *   命中浮层 ⇒ 菜单项悬停 / 光标；miss 浮层 ⇒ move/leave 只退悬停、
     *   **不关浮层**（关只归 down —— §9.1 语义 #1）。
     */
    private function dispatchPointer(PointerEvent $event, Element $root, FocusManager $focus): ?CursorShape
    {
        // ── 浮层栈：**从顶往下**逐层问（arch-v2 `01` §6.1）─────────────
        for ($i = $this->overlays->count() - 1; $i >= 0; $i--) {
            $e = $this->overlays->at($i);
            if ($e === null || $e->element === null) {
                continue;
            }

            // 浮层局部坐标 = 客户区坐标 − 浮层原点（Frame 画时的 translate）
            $hit = $focus->hitTest($event->x - $e->x, $event->y - $e->y, $e->element);

            if ($hit !== null) {
                // 模态：ModalHost 盖满客户区 ⇒ 命中恒非 null；点遮罩 = 关闭意图
                if ($e->scrim && !$this->hitsModalPanel($hit)) {
                    if ($event->isDown()) {
                        $this->releasePointer();   // 浮层优先于捕获（`06` §10）

                        if ($e->dismissOnTapOutside) {
                            $this->removeOverlay($e);
                        }

                        return null; // 不可关时同样吞掉（遮罩后面不能动）
                    }

                    if ($event->isMove() || $event->isLeave()) {
                        $focus->hoverTo(null);
                    }

                    return null;
                }

                if ($event->isDown()) {
                    // 浮层接管这次按下 ⇒ 捕获必须让位（`06` §10「浮层优先于捕获」：
                    // 用户主动点浮层，那次 down 不该被拖拽者吃掉）
                    $this->releasePointer();

                    // 菜单项（MenuItem extends Button）与根树按钮同规则：焦点落在
                    // 浮层内本次点击上 ⇒ 立即激活。命令经 dispatch() 分发 ⇒ 浮层按
                    // 语义 #2 在分发后关闭 —— 与键盘 Enter 完全同路。
                    if ($focus->focusHit($hit, $e->element)) {
                        $this->activateFocusedWidget();
                    }
                } elseif ($event->isMove()) {
                    // 浮层内悬停：菜单项高亮（§9.1 语义 #5）
                    $focus->hoverTo($focus->elementAt($e->element, $hit));
                } elseif ($event->isLeave()) {
                    $focus->hoverTo(null);
                }

                return $event->isDown() || $event->isMove() ? $focus->cursorOf($hit) : null;
            }

            // 点在条目盒外
            if ($e->barrier) {
                if ($event->isDown()) {
                    $this->releasePointer();   // 浮层优先于捕获（`06` §10）

                    // §9.1 关闭语义 #1：点浮层外 ⇒ 关且**吞掉该次按下**（不穿透）
                    if ($e->dismissOnTapOutside) {
                        $this->removeOverlay($e);
                    }

                    return null;
                }

                // move/up/leave 在浮层外：不穿透、不关浮层（关只归 down）
                if ($event->isMove() || $event->isLeave()) {
                    $focus->hoverTo(null);
                }

                return null;
            }
            // barrier=false（Tooltip / Toast）：继续往下问
        }

        // ── 指针捕获（`06` §5，W3-L5-6）：捕获期**不命中测试** ───────────
        // ★ 放在浮层段**之后**：浮层优先于捕获（`06` §10 裁决）—— 用户主动点浮层
        //   时那次 down 先归浮层；但 `barrier=false` 的浮层（Toast / Tooltip）
        //   在上面时照样穿透到捕获者，拖到一半弹个 Toast 不该断拖。
        $capture = $this->captureNode;
        if ($capture !== null) {
            $cursor = $this->dispatchToCapture($capture, $event);

            if ($event->isUp() || $event->isLeave()) {
                $this->releasePointer();   // 释放路径 ①：指针抬起 / 离开窗口
            }

            return $cursor;
        }

        // ── 滚动条 thumb 拖动（§12.3，2026-10-19）─────────────────────
        // thumb 是 `paint()` 画的胶囊，**不是子盒** ⇒ 命中测试看不见它，
        // 所以必须先于内容接管：down 落在 thumb 上就吃掉本次手势（不喂
        // focusHit、不派发给内容），move 按位移映射成滚动量，up/leave 结束。
        if ($this->thumbDrag !== null) {
            if ($event->isMove()) {
                $this->dragScrollThumb($event);

                return null;
            }

            $this->thumbDrag = null;   // up / leave / 其它相位：本次拖动结束

            return null;
        }

        if ($event->isDown() && $event->isPrimary()) {
            $thumb = $this->scrollThumbAt($event->x, $event->y, $root, $focus);
            if ($thumb !== null) {
                $this->thumbDrag = $thumb;

                return null;   // 吞掉：大拇指上的按下不算"点内容"
            }
        }

        $hit = $focus->hitTest($event->x, $event->y, $root);   // ★ 只走一次树
        $element = $hit === null ? null : $focus->elementAt($root, $hit);

        if ($event->isDown()) {
            // ★ 右键最小集（§9.1）：非主键 ⇒ 找最近的 `ContextMenu` 声明者并打开。
            if (!$event->isPrimary()) {
                $this->openContextMenu($element);

                return null;
            }

            // §9.1 手势消歧：命中链上有 `Draggable` 声明者 ⇒ 开 GestureArena，
            // **激活从 down 推迟到 arena 裁决**（up 未过阈值 ⇒ Tap 胜 ⇒ 激活；
            // move 过阈值 ⇒ Drag claim ⇒ Tap 让位）—— 修"拖可拖控件时命令在 down
            // 先响过"（原注释自承"激活已在 down 发生过"，§9.1"拖动中的 tap 让位"）。
            if ($this->draggableOwner($element) !== null) {
                $this->beginGestureArena($element, $hit, $root, $focus, $event->x, $event->y);

                return $focus->cursorOf($hit);
            }

            // 无 `Draggable` 竞争者 ⇒ 维持 M3 行为：焦点真落在本次点击上 ⇒ 立即
            // 激活（与键盘 Enter/Space 同入口 activateFocusedWidget）。空点击 /
            // 不可聚焦命中 ⇒ focusHit 返回 false，不激活。按下即激活，不做
            // press/release 视觉（A15）。
            if ($focus->focusHit($hit, $root)) {
                $this->activateFocusedWidget();

                // 点进**编辑中**的输入框 ⇒ 插入符落到指针处 + 起框选手势
                // （§A.2.9 的指针侧：与 Shift+方向键同一个选区模型）
                $this->beginTextSelection($event->x);
            }

            return $focus->cursorOf($hit);
        }

        if ($event->isMove()) {
            // 框选推进：down 在输入框上起的手势 ⇒ 延伸选区活动端
            if ($this->textSelect !== null) {
                $this->extendTextSelection($event->x);
            }

            // 手势竞技场推进：过阈值 ⇒ Drag claim（begin + 刷新悬停目标）
            $arena = $this->gestureArena;
            if ($arena !== null && !$arena->isClosed()) {
                $arena->move($event->x, $event->y);
            }

            // 已在拖动（arena 已裁决出 Drag，或宿主直起）⇒ 刷新悬停目标
            if ($this->drag !== null && $this->drag->isDragging()) {
                $this->updateDrag($event->x, $event->y);
            }

            $focus->hoverTo($element);

            return $focus->cursorOf($hit);
        }

        if ($event->isUp()) {
            // arena 未裁决 ⇒ 无人 claim ⇒ 第一个成员（Tap）胜 ⇒ 激活
            $arena = $this->gestureArena;
            if ($arena !== null && !$arena->isClosed()) {
                $arena->up($event->x, $event->y);
            }

            $this->gestureArena = null;

            if ($this->drag !== null && $this->drag->isDragging()) {
                $this->dropDrag();
            }

            $this->textSelect = null;   // 框选手势收口（选区本身留在字段里）

            return null;
        }

        // leave：悬停全退出 + 手势取消（arena 关闭不触发任何人）+ 拖放取消
        // （指针离开客户区 —— 无 SetCapture ⇒ 拖出窗外收不到后续 move/up，
        // 必须在这里收口，否则拖动卡死）。
        $focus->hoverTo(null);
        $this->textSelect = null;   // 指针出窗 ⇒ 框选同样收口（无 SetCapture 的诚实边界）
        if ($this->gestureArena !== null) {
            $this->gestureArena->leave();
            $this->gestureArena = null;
        }

        if ($this->drag !== null && $this->drag->isDragging()) {
            $this->cancelDrag();
        }

        return null;
    }

    /**
     * down 命中链有 `Draggable` 声明者 ⇒ 开 `GestureArena`（§9.1 手势消歧）。
     *
     * 成员 = Tap（`up` 未过阈值 ⇒ 胜 ⇒ 激活 down 落焦点的控件）+ Drag（`move`
     * 过阈值 ⇒ claim ⇒ 开始拖动，源 = 声明者 owner）。**Tap 必须排在首位** ——
     * arena 的默认胜者是 `members[0]`。
     *
     * @internal 由 `dispatchPointer()` 的 down 分支调
     */
    private function beginGestureArena(Element $owner, ?RenderBox $hit, Element $root, FocusManager $focus, float $x, float $y): void
    {
        $draggable = $owner->widget()->draggableValue();

        if ($draggable === null) {
            return;
        }

        $arena = new GestureArena($x, $y);

        // Tap：up 无人 claim ⇒ 胜 ⇒ 焦点落到 down 命中处则激活（同 down 分支原语义）
        $arena->add(GestureRecognizer::tap(function (float $tx, float $ty) use ($focus, $hit, $root): void {
            if ($focus->focusHit($hit, $root)) {
                $this->activateFocusedWidget();
            }
        }));

        // Drag：move 过阈值 ⇒ claim ⇒ 开始拖动（源 = owner）并刷新悬停目标
        $arena->add(GestureRecognizer::drag(
            GestureArena::DRAG_THRESHOLD_PX,
            function (float $cx, float $cy) use ($draggable, $owner): void {
                $this->drag()->begin($draggable, $owner);
                $this->updateDrag($cx, $cy);
            }
        ));

        $this->gestureArena = $arena;
    }

    /** 沿父链找最近的 `Draggable` 声明者（§A.3.5：子声明覆盖祖先） */
    private function draggableOwner(?Element $element): ?Element
    {
        for ($node = $element; $node !== null; $node = $node->parent()) {
            if ($node->widget()->draggableValue() !== null) {
                return $node;
            }
        }

        return null;
    }

    /**
     * 右键入口（§9.1"右键最小集"）：沿命中 Element 的父链找**最近**的声明者。
     *
     * ★ 找不到 ⇒ 什么也不做（右键落在没有上下文菜单的地方是合法的，不报错、
     *   也不落给焦点 —— 右键不是"选中"）。
     */
    private function openContextMenu(?Element $hit): void
    {
        for ($element = $hit; $element !== null; $element = $element->parent()) {
            $menu = $element->widget()->contextMenuValue();

            if ($menu !== null) {
                $this->showMenu($menu->panel(), $element);

                return;
            }
        }
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
        if ($element instanceof StatefulElement
            && $element->state()?->handleCommand($name, $arg) === true) {
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
     * 焦点控件的 Enter / Space ⇒ 激活（§9.1 路由链第 ③ 环的激活语义）。
     *
     * 键盘 Enter/Space 激活路径（指针路径走 dispatchPointer() 的 down 分支，
     * 同终点 activateFocusedWidget()，2026-10-17 指针四相位已落地 §24.2 #16）。
     *
     * @internal 由 dispatchInput() 调
     */
    private function activateFocused(KeyEvent $event): void
    {
        if (!$event->chord->is(KeyChord::enter()) && !$event->chord->is(KeyChord::make('Space'))) {
            return;
        }

        $this->activateFocusedWidget();
    }

    /**
     * 激活当前焦点控件 —— 键盘（Enter/Space）与指针（按下）两路径的**唯一**入口。
     *
     * §9.1 动作缝：Menu 走 `onOpen`（应用接线到 `showMenu()`）、Button 走
     * 命令（全链）或 `onPressed`。Menu 分支必须在 Button 之前 —— 子类会被
     * `instanceof` 认成父类。
     */
    private function activateFocusedWidget(): void
    {
        $element = $this->focus?->focused();
        $widget = $element?->widget();

        // ★ 菜单标题（`Menu extends Button`）⇒ 走 `onOpen`（应用接线到 `showMenu()`）。
        //   分支必须在 `Button` 之前 —— 子类会被 `instanceof` 认成父类。
        if ($widget instanceof Menu) {
            $open = $widget->openHandler();
            if ($open !== null) {
                $open($widget, $element);
            }

            return;
        }

        // ★ 开关类（`Checkbox` / `Radio` / `Toggle`）：与按钮同一入口，但"激活"
        //   的含义是**换值**（回调收新值）。分支必须在 `Button` 之前 —— 二者互不
        //   继承，但放在 `!$widget instanceof Button` 早退之后会永远进不来。
        if ($widget instanceof ToggleBase) {
            $widget->activate();

            return;
        }

        if (!$widget instanceof Button) {
            return;
        }

        // 命令优先（走全链：冒泡 → 注册表）；onPressed 是纯 UI 回调，框架兜底调
        $name = $widget->commandName();
        if ($name !== null) {
            $this->dispatch($name, $widget->commandArg() ?? CommandArg::none());

            return;
        }

        $handler = $widget->pressedHandler();
        if ($handler !== null) {
            $handler();
        }
    }

    /**
     * @internal 测试与 devtools 用：注册一个 handler（不必进构造器）
     */
    public function register(string $name, CommandHandler $handler): void
    {
        $this->router->on($name, $handler);
    }

    // ── 应用内拖放（§A.3.5）：声明住 Widget，状态机住这里 ──────────────

    /**
     * 拖动状态机（§A.3.5）。
     *
     * ★ **真机指针序列已接线**（2026-10-17，§24.2 #16）：`down` 在 `Draggable`
     *   上记阈值起点（`beginPendingDrag`，**按下 ≠ 拖动**），`move` 过
     *   `DRAG_THRESHOLD` 才 `begin`，`up` 放下，`leave`/ESC 取消。宿主 / 测试
     *   仍可直接调这四个入口（与 `Spinner::tick()` 同一个分期形态）。
     */
    public function drag(): DragController
    {
        return $this->drag ??= new DragController();
    }

    /**
     * @internal 开始拖动（源 = 最近命中的 Element；命中处须有 `Draggable` 声明）
     */
    public function beginDrag(Draggable $draggable): bool
    {
        $this->drag()->begin($draggable, $this->focus?->lastHit());

        return true;
    }

    /**
     * @internal 指针移动：刷新悬停目标（**目标变化才通知** `onHover`）
     */
    public function updateDrag(float $x, float $y): void
    {
        $root = $this->root;
        $focus = $this->focus;
        $drag = $this->drag;

        if ($root === null || $focus === null || $drag === null || !$drag->isDragging()) {
            return;
        }

        $hit = $focus->hitTest($x, $y, $root);

        $drag->hoverTo($hit === null ? null : $focus->elementAt($root, $hit));
    }

    /**
     * @internal 放下：命中 `DragTarget` ⇒ 交出负载。
     *
     * @return bool 是否被接收
     */
    public function dropDrag(): bool
    {
        return $this->drag?->drop() ?? false;
    }

    /** @internal 取消拖动（ESC 也会cancel —— 见 `dispatchInput()`） */
    public function cancelDrag(): void
    {
        $this->drag?->cancel();
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
