<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\App\Io\AsyncHttp;
use Drift\App\Io\IoPoller;
use Drift\Async\Future;
use Drift\Commands\CommandArg;
use Drift\Commands\CommandGate;
use Drift\Commands\CommandHandler;
use Drift\Commands\CommandRouter;
use Drift\Core\Pipeline;
use Drift\Debug\Session\Recorder;
use Drift\Geometry\BoxConstraints;
use Drift\Input\AcceleratorTable;
use Drift\Input\CursorShape;
use Drift\Input\InputEvent;
use Drift\Input\SemanticsTree;
use Drift\Layout\RenderBox;
use Drift\Platform\Clipboard;
use Drift\Rendering\RenderSurface;
use Drift\Text\TextMetricsProvider;
use Drift\Theme\ThemeData;
use Drift\Widget\BuildContext;
use Drift\Widget\Drag\Draggable;
use Drift\Widget\Element;
use Drift\Widget\Modal;
use Drift\Widget\Snackbar;
use Drift\Widget\Widget;

/**
 * 应用声明与兼容门面：应用只写 `buildRoot()` / `title()` / `commands()`。
 *
 * 每窗口的根树、帧循环、焦点、输入与浮层住 {@see AppShell}；本类保留原有
 * 公共入口，应用、组合根与测试的调用方式不变。
 *
 * @api
 */
abstract class Application
{
    /** 一帧预算（毫秒，60fps）。用途是回答“超了先砍谁”，不是 CPU 分配表。 */
    public const float BUDGET_MS = 16.7;

    /** 连续多少帧超预算才算“真超”（单帧超常是 GC 或系统抢占）。 */
    public const int OVER_BUDGET_FRAMES = 10;

    /**
     * 降级用的耗时分组（毫秒），不是帧阶段 —— 阶段顺序看 `FrameStage::ORDERED`。
     * 超了先砍语义推送，`layout` / `paint` / `present` 永不砍。
     */
    public const array STAGE_BUDGET_MS = [
        'A 组（四个泵）' => 1.0,
        'settle' => 1.0,
        'layout' => 2.0,
        'paint' => 4.0,
        'present' => 5.0,
        '余量' => 3.7,
    ];

    private readonly AppShell $shell;

    /** @param iterable<string, CommandHandler> $handlers 构造期注册的本窗 handler */
    public function __construct(iterable $handlers = [], ?AppScope $scope = null)
    {
        $this->shell = new AppShell(
            $this,
            $scope,
            $handlers,
            function (): void {
                $this->onSystemThemeChanged();
            },
        );
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
     * 命令门控：`命令名 => CommandGate`。键必须与 `commands()` 对齐。
     *
     * @return iterable<string, CommandGate>
     *
     * @api
     */
    public function commandGates(): iterable
    {
        return [];
    }

    /**
     * 系统主题变了。应用自己持 screen 时在这里重读并更新 `theme()` 的来源。
     *
     * 调用时机在 `applyNewContext()` 之前 —— 钩子跑完 `theme()` 才是新值。
     *
     * @api
     */
    protected function onSystemThemeChanged(): void
    {
    }

    /**
     * 窗口被要求关闭 —— 回答“同意吗”。默认 `true`。
     *
     * 只在组合根声明了守卫的窗口上会被问；调用点在帧边界，故可安全弹模态框。
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

    /** 焦点链（`null` = 还没 attach）。 */
    public function focus(): ?FocusManager
    {
        return $this->shell->focus();
    }

    /** 控件层请求焦点。 */
    public function requestFocus(Element $target): void
    {
        $this->shell->requestFocus($target);
    }

    /** 应用作用域 —— 多窗口时共享字体 / 线程池 / IO / 主题。 */
    public function scope(): AppScope
    {
        return $this->shell->scope();
    }

    /** 异步 HTTP 门面（应用侧唯一入口）。 */
    public function http(): AsyncHttp
    {
        return $this->scope()->asyncHttp();
    }

    /**
     * 主题：默认取 `AppScope`。
     *
     * ★ 这四个能力（`theme` / `metrics` / `accelerators` / `clipboard`）的默认实现
     *   必须直接读 scope —— 壳是反过来调 `$app->theme()` 取覆写值的。改成转发
     *   `$this->shell->theme()` 就是无限递归。
     */
    public function theme(): ThemeData
    {
        return $this->scope()->theme();
    }

    /** 后台执行端口（应用级，一个进程一个池）。 */
    final public function taskHost(): ?TaskHost
    {
        return $this->scope()->taskHost();
    }

    /** 提交一个后台任务，策略从 `AppScope` 读取。 */
    final public function submit(Job $job): Future
    {
        return $this->scope()->dispatch()->submit($job);
    }

    /** 显式策略提交口：调用点自己决定同步 / 拒绝 / 后台。 */
    final public function submitWith(TaskDispatch $dispatch, Job $job): Future
    {
        return $dispatch->submit($job);
    }

    /** 已挂载的后台端口（测试 / devtools）。 */
    public function tasks(): ?TaskHost
    {
        return $this->shell->tasks();
    }

    /** 上一帧的时间戳。 */
    public function lastTimestampMs(): float
    {
        return $this->shell->lastTimestampMs();
    }

    /** 最近一次输入派发的时刻。 */
    public function lastInputMs(): float
    {
        return $this->shell->lastInputMs();
    }

    /** 加速键表（应用级）。 */
    public function accelerators(): AcceleratorTable
    {
        return $this->scope()->accelerators();
    }

    /** 剪贴板端口（`null` = 本应用没有）。 */
    public function clipboard(): ?Clipboard
    {
        return $this->scope()->clipboard();
    }

    /** 应用侧唯一的能力入口。 */
    public function environment(): AppEnvironment
    {
        return $this->shell->environment();
    }

    /** 字体度量（应用级，整个进程一份）。 */
    public function metrics(): TextMetricsProvider
    {
        return $this->scope()->metrics();
    }

    /** 本窗命令表。 */
    public function router(): CommandRouter
    {
        return $this->shell->router();
    }

    /** 挂载：建根 Element、整棵树挂上。 */
    public function attach(RenderSurface $surface, BoxConstraints $constraints, ?BuildContext $context = null): void
    {
        $this->shell->attach($surface, $constraints, $context);
    }

    /** 根 Element（挂载后才有）。 */
    public function root(): ?Element
    {
        return $this->shell->root();
    }

    /**
     * 全部渲染树根：主树在前，浮层在后。
     *
     * ★ 门面转发 `AppShell::treeRoots()`，**不在这里再写一遍遍历** ——
     *   浮层遍历只允许一个出口（`SecondTreeTest` 咬红），漏一处就是下一次
     *   "菜单里的节点查不到"这种不报错的缺口。
     *
     * @internal 查询面按节点找活树元素时用
     * @return list<Element>
     */
    public function treeRoots(): array
    {
        return $this->shell->treeRoots();
    }

    /**
     * 全部渲染树根及其视口原点：主树在前，浮层在后。
     *
     * 与 {@see self::treeRoots()} 共用 `AppShell` 的同一个收集点；点选反查要按
     * `InputRouter` 的口径把视口坐标减掉浮层原点，所以不能只拿 Element 列表。
     *
     * @internal 点选查询用
     * @return list<array{element: Element, x: float, y: float}>
     */
    public function treeRootOrigins(): array
    {
        return $this->shell->treeRootOrigins();
    }

    /** UI 线程 id（`0` = 未挂载）。 */
    public function uiThreadId(): int
    {
        return $this->shell->uiThreadId();
    }

    /** UI 线程断言；未挂载不算失败。 */
    public function assertUiThread(string $where): void
    {
        $this->shell->assertUiThread($where);
    }

    /** IME 定位用的光标矩形。 */
    public function imeCaretRect(): ?array
    {
        return $this->shell->imeCaretRect();
    }

    /**
     * 跑一帧：平台每帧调一次。
     *
     * ★ 帧内"当前录制器"= 本窗口那一份（`null` 也压，见 {@see Recorder::enter()}）。
     *   不压的话，这个窗口的帧会从栈里拿不到东西、落到进程默认值上 —— 也就是
     *   **另一个窗口**的录制器：它的节点表会混进这一页的节点、帧号被推走、指纹
     *   跟着变，而两份报告各自看起来都正常。
     *
     * 录制边界必须成对，故收在这里而不是壳里（壳有零视口提前返回，散在壳里会漏掉
     * 那一帧）。
     *
     * ★ `try/finally`：帧内抛异常时也必须 `endFrame`，否则 recorder 停在
     *   `open` 状态，**下一帧**的 `openCauses` 会把这一帧的残余一起吞进去 ——
     *   一条"看起来合理、实际跨越了两帧"的因果边。异常帧本身标 `aborted`：
     *   半截的 `rebuilt` / `costMs` 不能当正常帧读。
     */
    public function frame(float $timestampMs, BoxConstraints $constraints): void
    {
        Recorder::enter($this->shell->frameRecorder());

        try {
            // ★ 不把上面那个值另存一份交给下面：帧内"用谁的账本"只由栈回答。
            //   另存一份就有两个可以分别写错的地方（"栈说什么"与"这一帧用谁"），
            //   而它们分叉时的表现正是这一层要堵的那种静默串证据。
            $recorder = Recorder::current();
            if ($recorder === null) {
                $this->shell->frame($timestampMs, $constraints);

                return;
            }

            $t0 = \hrtime(true);
            $recorder->beginFrame($timestampMs);
            $aborted = true;
            try {
                $this->shell->frame($timestampMs, $constraints);
                $aborted = false;
            } finally {
                $recorder->endFrame(
                    $this->shell->frameFacts(),
                    (\hrtime(true) - $t0) / 1e6,
                    $this->root(),
                    $aborted,
                );
            }
        } finally {
            Recorder::leave();
        }
    }

    /** 拖动 resize 专用的轻量重绘。 */
    public function repaintForResize(BoxConstraints $constraints): void
    {
        $this->shell->repaintForResize($constraints);
    }

    /**
     * 把这个窗口的录制器交给壳（`null` = 解绑）。
     *
     * ★ 为什么参数是 `?Recorder` 而不是 `bool $recording`：录制器是有状态的实例，
     *   同进程可以有第二个（另一个窗口）。壳只要"记账时用哪一份"，不需要知道
     *   有几份、谁是当前 —— 那两件事归 {@see Recorder::current()}。
     *
     * @internal 调试会话专用（`DebugSession::open()` / `close()`）
     */
    public function setDebugRecorder(?Recorder $recorder): void
    {
        $this->shell->setDebugRecorder($recorder);
    }

    /**
     * 挂/摘帧出口观察者（`null` = 摘）—— 每帧画完之后拿到画布与帧号。
     *
     * ★ 与 {@see self::setDebugRecorder()} 是两件事：录制器记**事实**（几何、重画、因果），
     *   观察者拿**画面**。事实是证据，画面只能看 —— 所以它不参与任何判据，
     *   也不进包（见 `docs/concepts/evidence-model.md`）。
     *
     * @param null|\Closure(\Drift\Rendering\Canvas, int): void $observer
     * @internal 调试会话专用（live 宿主取帧给 web）
     */
    public function setFrameObserver(?\Closure $observer): void
    {
        $this->shell->setFrameObserver($observer);
    }

    /**
     * 装/摘检查器叠加层（`null` = 摘）—— 画在快照之后、`endFrame()` 之前。
     *
     * ★ 闭包返回"这一帧画了东西吗"。壳只记这个布尔，不解释"为什么没画"：
     *   那是策略层能答的事（选中已卸载 / 盒在视口外 / 所有层都关）。
     *
     * @param null|\Closure(\Drift\Rendering\Canvas): bool $painter
     * @internal 调试会话专用
     */
    public function setDebugOverlay(?\Closure $painter): void
    {
        $this->shell->setDebugOverlay($painter);
    }

    /**
     * 叠加层输入变了（开关 / 选中），请求重画一帧。
     *
     * @internal 调试会话专用
     */
    public function requestOverlayRepaint(): void
    {
        $this->shell->requestOverlayRepaint();
    }

    /**
     * 请求画一帧（一次性）—— 画面通道用它给静止的应用补上第一张图。
     *
     * @internal 调试会话专用
     */
    public function requestPaint(): void
    {
        $this->shell->requestPaint();
    }

    /**
     * 这个窗口**绑定**的录制器（`null` = 没在录）。
     *
     * 与 {@see self::frame()} 实际用的那一份不同：帧里还可能借用无主的录制器
     * （测试 / bench），而"这个窗口有没有自己的会话"只看绑定的这一份。
     */
    public function debugRecorder(): ?Recorder
    {
        return $this->shell->boundRecorder();
    }

    /**
     * 让调试叠加层描边这个元素（`null` = 清除）。
     *
     * ★ 为什么参数是 `?Element` 而不是节点号：号是调试层的词汇（`Recorder` 发、
     *   每会话重发、只在发它的会话里有效）。应用层不必懂它，调试会话把号解析成
     *   活元素再交过来 —— 解析只此一份（{@see Element::findInRoots()}）。
     * ★ 它**不改结构**：只影响下一帧多画一层描边。见 `AppShell::setDebugHighlight()`。
     *
     * @internal 调试会话专用（`debug highlight` 出口 / 协议 `highlight` 方法）
     */
    public function setDebugHighlight(?Element $element): void
    {
        $this->shell->setDebugHighlight($element);
    }

    /** 排一个一次性延时回调。 */
    public function after(int $milliseconds, \Closure $callback): int
    {
        return $this->shell->after($milliseconds, $callback);
    }

    /** 撤销一个延时回调。 */
    public function cancelAfter(int $id): void
    {
        $this->shell->cancelAfter($id);
    }

    /** 本应用还需要下一帧吗。 */
    public function wantsFrame(): bool
    {
        return $this->shell->wantsFrame();
    }

    /** 欠的账是“立刻跑”那一档吗。 */
    public function needsFrameImmediately(): bool
    {
        return $this->shell->needsFrameImmediately();
    }

    /** 下一个到点的定时器；`null` = 没有。 */
    public function nextDeadlineMs(): ?float
    {
        return $this->shell->nextDeadlineMs();
    }

    /** 本应用一共跑过多少帧。 */
    public function frameCount(): int
    {
        return $this->shell->frameCount();
    }

    /** 本应用的帧管线。 */
    public function pipeline(): Pipeline
    {
        return $this->shell->pipeline();
    }

    /** 记一次帧耗时。 */
    public function noteFrameCost(float $milliseconds): void
    {
        $this->shell->noteFrameCost($milliseconds);
    }

    /** 是否处于超预算状态。 */
    public function overBudget(): bool
    {
        return $this->shell->overBudget();
    }

    /** 最近一帧的耗时（毫秒）。 */
    public function frameCostMs(): float
    {
        return $this->shell->frameCostMs();
    }

    /** 观测到的最差一帧（毫秒）。 */
    public function worstFrameMs(): float
    {
        return $this->shell->worstFrameMs();
    }

    /** 被跳过的零视口帧数。 */
    public function zeroViewportFrames(): int
    {
        return $this->shell->zeroViewportFrames();
    }

    /** 注入语义树消费者；不注入 ⇒ 不建语义树。 */
    public function setSemanticsSink(callable $sink): void
    {
        $this->shell->setSemanticsSink($sink);
    }

    /** 现建语义树给 dev 内省（A77）；与 UIA 推送同口径，不进每帧、不进证据。 */
    public function semanticsTree(): SemanticsTree
    {
        return $this->shell->semanticsTree();
    }

    /** 按语义节点索引激活一次。 */
    public function invokeSemanticsNode(int $index): bool
    {
        return $this->shell->invokeSemanticsNode($index);
    }

    /** 按语义节点索引写入一个值。 */
    public function setSemanticsValue(int $index, string $value): bool
    {
        return $this->shell->setSemanticsValue($index, $value);
    }

    /** 按语义节点索引展开 / 折叠一行。 */
    public function setSemanticsExpanded(int $index, bool $expand): bool
    {
        return $this->shell->setSemanticsExpanded($index, $expand);
    }

    /** 按语义节点索引把滚动容器滚到某百分比（两轴，`-1` = 该轴不动）。 */
    public function setSemanticsScroll(int $index, float $horizontalPercent, float $verticalPercent): bool
    {
        return $this->shell->setSemanticsScroll($index, $horizontalPercent, $verticalPercent);
    }

    /** 上次语义推送的档位。 */
    public function semanticsMode(): string
    {
        return $this->shell->semanticsMode();
    }

    /** 累计访问过的节点数。 */
    public function animationVisits(): int
    {
        return $this->shell->animationVisits();
    }

    /** 当前活跃动画节点数。 */
    public function activeAnimationCount(): int
    {
        return $this->shell->activeAnimationCount();
    }

    /** 非阻塞 I/O 轮询器（`null` = 未挂载）。 */
    public function io(): ?IoPoller
    {
        return $this->shell->io();
    }

    /** 捕获指针。 */
    public function capturePointer(Element $node): void
    {
        $this->shell->capturePointer($node);
    }

    /** 释放指针捕获。 */
    public function releasePointer(): void
    {
        $this->shell->releasePointer();
    }

    /** 当前捕获者（测试 / devtools）。 */
    public function captureNode(): ?Element
    {
        return $this->shell->captureNode();
    }

    /** 喂一条平台事件。 */
    public function dispatchInput(InputEvent $event, ?float $timestampMs = null): ?CursorShape
    {
        return $this->shell->dispatchInput($event, $timestampMs);
    }

    /** 派发一条命令。 */
    public function dispatch(string $name, ?CommandArg $arg = null): bool
    {
        return $this->shell->dispatch($name, $arg);
    }

    /** 注册一个 handler（不必进构造器）。 */
    public function register(string $name, CommandHandler $handler): void
    {
        $this->shell->register($name, $handler);
    }

    /** 拖动状态机。 */
    public function drag(): DragController
    {
        return $this->shell->drag();
    }

    /** 开始拖动。 */
    public function beginDrag(Draggable $draggable): bool
    {
        return $this->shell->beginDrag($draggable);
    }

    /** 指针移动：刷新悬停目标。 */
    public function updateDrag(float $x, float $y): void
    {
        $this->shell->updateDrag($x, $y);
    }

    /** 放下：命中 `DragTarget` ⇒ 交出负载。 */
    public function dropDrag(): bool
    {
        return $this->shell->dropDrag();
    }

    /** 取消拖动。 */
    public function cancelDrag(): void
    {
        $this->shell->cancelDrag();
    }

    /** 测试：把根盒拿出来做几何断言。 */
    public function rootBox(): ?RenderBox
    {
        return $this->shell->rootBox();
    }

    /** 打开浮层面板。 */
    public function showMenu(
        Widget $panel,
        ?Element $anchor = null,
        ?float $atX = null,
        ?float $atY = null,
    ): void {
        $this->shell->showMenu($panel, $anchor, $atX, $atY);
    }

    /** 关掉浮层。 */
    public function closeMenu(): void
    {
        $this->shell->closeMenu();
    }

    /** 弹一条底部通知。 */
    public function showSnackbar(Snackbar $snackbar): void
    {
        $this->shell->showSnackbar($snackbar);
    }

    /** 收掉当前通知。 */
    public function dismissSnackbar(): void
    {
        $this->shell->dismissSnackbar();
    }

    /** 当前是否有通知在显示。 */
    public function hasSnackbar(): bool
    {
        return $this->shell->hasSnackbar();
    }

    /** 排队等待的通知条数。 */
    public function snackbarQueueLength(): int
    {
        return $this->shell->snackbarQueueLength();
    }

    /** 当前通知的条目。 */
    public function snackbarEntry(): ?OverlayEntry
    {
        return $this->shell->snackbarEntry();
    }

    /** 浮层栈本体。 */
    public function overlays(): OverlayStack
    {
        return $this->shell->overlays();
    }

    /** 轻量弹出层（菜单 / 下拉 / 右键菜单）是否开着。 */
    public function isMenuOpen(): bool
    {
        return $this->shell->isMenuOpen();
    }

    /** 打开模态。 */
    public function showModal(Modal $modal): void
    {
        $this->shell->showModal($modal);
    }

    /** 关掉模态。 */
    public function closeModal(): void
    {
        $this->shell->closeModal();
    }

    /** 按条目关浮层。 */
    public function dismissOverlay(OverlayEntry $e): void
    {
        $this->shell->dismissOverlay($e);
    }

    /** 模态是否开着。 */
    public function isModalOpen(): bool
    {
        return $this->shell->isModalOpen();
    }

    /** 当前模态。 */
    public function modal(): ?Modal
    {
        return $this->shell->modal();
    }

    /** 开 / 关控件声明的面板。 */
    public function toggleWidgetPanel(Element $anchor, \Closure $factory): void
    {
        $this->shell->toggleWidgetPanel($anchor, $factory);
    }
}
