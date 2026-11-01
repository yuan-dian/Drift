<?php
declare(strict_types=1);

namespace Drift\Platform\Win32;

use Drift\Errors;
use Drift\Geometry\Axis;
use Drift\Input\CloseRequestedEvent;
use Drift\Input\FilesDroppedEvent;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Input\ScrollEvent;
use Drift\Input\SemanticsInvokeEvent;
use Drift\Input\SemanticsSetValueEvent;
use Drift\Input\ThemeChangedEvent;
use Drift\Input\WindowDeactivatedEvent;
use Drift\Platform\Clipboard;
use Drift\Platform\Dialog;
use Drift\Platform\InputSource;
use Drift\Platform\Platform;
use Drift\Platform\Screen;
use Drift\Platform\SurfaceProvider;
use Drift\Platform\Tray;
use Drift\Platform\WakeReason;
use Drift\Platform\WindowHost;

use function drift_win_config_dir;
use function drift_win_ime_caret;
use function drift_win_message_box;
use function drift_win_now_ms;
use function drift_win_pump;

/**
 * Win32 平台实现 —— **Drift 的第一个平台**（§5.1 / §19 M1）。
 *
 * ★ 它只做两件别处做不了的事：① 把六个子端口装配起来（谁持有谁）；
 *   ② **把桥的命名事件翻译成框架的类型**（`translate()` 里的那张表）。
 *   其余一律转发 —— 平台层不该有策略。
 *
 * ★ **`dialog()` 2026-10-30 起也不是其中之一**（A5-①）：`Win32Dialog` 落地，
 *   四个方法全部走原生模态（`GetOpenFileNameW` / `MessageBoxW`）。理由同剪贴板
 *   —— 端口已经有**真实消费者**（示例台「对话框」页），而"实现了但没人用"与
 *   "返回 null"一样没用。<br>
 *   ★ **`tray()` 仍是 `null`**（A5-③ 未做）：`Tray` 端口今天一个消费者都没有，
 *   先实现等于让"支持"变成一句无法验证的话。
 *
 * ★ `fatalBox()` 走桥的 `MessageBoxW`，**不依赖渲染栈** —— 渲染栈本身可能正是
 *   崩掉的那一环（§1.2）。组合根在 `attach()` 之前就把它装进 `Errors`：
 *   `Errors::installFatalBox(fn($t, $b) => $platform->fatalBox($t, $b))`。
 *
 * @api platform
 */
final class Win32Platform implements Platform
{
    /**
     * `waitEvents()` 的**闹钟**定时器 id（`SetTimer` 的 `nIDEvent`）。
     *
     * ★ 这个 id 在**整个壳的 id 空间里是共用的**（`WM_TIMER` 只带一个 `wParam`），
     *   所以它必须是 `Win32Platform` 私有的常量、不许任何应用层定时器撞上。
     *   旧心跳（`FrameClock::TIMER_ID = 0x4452`）在 N2 已随心跳一起删除 ⇒ 今天
     *   本进程只用这一个 id；将来若再加第二个内部定时器，**必须在这里登记**。
     * `0x4457` = "DW"（Drift Wait）。
     */
    private const WAIT_TIMER_ID = 0x4457;

    private ?Win32WindowHost $window = null;

    /**
     * 按窗口句柄缓存的事件队列（2026-10-30，M1-b）。
     *
     * ★ **句柄就是 key**：队列是有状态对象、窗口是它的天然归属 ⇒ 不需要再包一层
     *   `forWindow()` 视图对象（见 `Platform` 的类注释）。
     *
     * @var array<int, Win32InputSource>
     */
    private array $inputs = [];

    private ?Win32TimerHost $timers = null;

    private ?Win32Clipboard $clipboard = null;

    private ?Win32Dialog $dialog = null;

    /** @var array<int, Win32SurfaceProvider> 按窗口句柄缓存（同一窗口只造一个表面） */
    private array $surfaces = [];

    /**
     * `waitEvents()` 取到、**还没派发**的原始桥事件（`pumpEvents()` 消费）。
     *
     * ★ 今天是 `list` 但上限 1 条：桥一次只回一条关心的事件（见 `pumpEvents()`）。
     *   用列表而不是 `?array`，是为了 N4 给桥加非阻塞取法后**不用改结构**。
     *
     * @var list<array<string, mixed>>
     */
    private array $pending = [];

    /** 等待定时器起不来只报一次（否则每帧一条，把环形缓冲刷满） */
    private bool $waitTimerReported = false;

    /**
     * `fatalBox()` 记下的模态阻塞时间（毫秒）—— `consumeModalBlockedMs()` 取走。
     *
     * ★ 对话框那一份在 `Win32Dialog` 上（谁跑的模态谁记账）；这里只放
     *   `fatalBox()` 自己的。读后即清是 {@see self::consumeModalBlockedMs()} 的契约。
     */
    private float $modalBlockedMs = 0.0;

    /**
     * @param string|null $renderer 光栅后端（`'skia'`；`null` = 软光栅，§7.2 裁决 4）。
     *   ★ **归组合根**：换后端是**部署形态**（Skia 让产物多几 MiB，§4.6），
     *   不是运行期配置 ⇒ 由 `bin/<os>/Bootstrap.php` 显式传入。
     *   ★ **不读环境变量** —— §16.7 的 `nano-hygiene` 禁 `getenv`/`putenv`。
     */
    public function __construct(
        private readonly ?string $renderer = null,
    ) {
    }

    public function screen(int $windowHandle): Screen
    {
        // ★ 不缓存：`Win32Screen` 自己无状态（`scale()` / `viewport()` 都现读），
        //   缓存只会多一张要失效的表。见 `Win32Screen` 的类注释。
        return new Win32Screen($this->displayHost(), $windowHandle);
    }

    public function window(): WindowHost
    {
        return $this->displayHost();
    }

    private function displayHost(): Win32WindowHost
    {
        return $this->window ??= new Win32WindowHost();
    }

    public function surface(int $windowHandle): ?SurfaceProvider
    {
        if ($windowHandle <= 0) {
            return null;   // 没建窗 / 建窗失败 ⇒ 类型层面的"没有表面"
        }

        return $this->surfaces[$windowHandle] ??= new Win32SurfaceProvider($windowHandle, $this->renderer);
    }

    public function input(int $windowHandle): InputSource
    {
        return $this->inputOf($windowHandle);
    }

    /**
     * 某条**事件**该投进哪条队列（`translate()` 用；`input()` 不用它）。
     *
     * ★ 与 `input($h)` 的差别只有一条回落：`$windowHandle <= 0` 表示"这条事件没有
     *   归属"（测试手工造的剧本 / 老壳不带 `hwnd`）⇒ 归**主窗**。单窗时这与
     *   `input(主窗)` 是同一条队列（多窗口之前的行为逐字节不变）；
     *   多窗时也不会被塞进任意一个窗口（宁可留在这条没人抽的队列里）。
     */
    private function inputOf(int $windowHandle): Win32InputSource
    {
        if ($windowHandle <= 0) {
            $windowHandle = $this->displayHost()->handle() ?? 0;
        }

        return $this->inputs[$windowHandle] ??= new Win32InputSource();
    }

    /**
     * 等消息的闹钟定时器（**平台私有**，2026-10-30 M1-b 起不在 `Platform` 接口上）。
     *
     * ★ 它挂 `liveHandle()`（任一活窗口）而不是"最后建的窗"，理由见 `Win32TimerHost`。
     */
    private function timers(): Win32TimerHost
    {
        return $this->timers ??= new Win32TimerHost($this->displayHost());
    }

    public function fatalBox(string $title, string $body): void
    {
        // ★ `MessageBoxW` 是**原生模态**（用户看多久它就开多久）—— 与对话框
        //   同一条账本（2026-10-30：帧时钟要从"两帧间隔"里剔除这段时间，
        //   否则每次弹框都可能白弹一个 RECOVERABLE）。
        $startedNs = \hrtime(true);
        try {
            drift_win_message_box($title, $body);
        } finally {
            $this->modalBlockedMs += (float) (\hrtime(true) - $startedNs) / 1e6;
        }
    }

    public function consumeModalBlockedMs(): float
    {
        $blocked = $this->modalBlockedMs;
        $this->modalBlockedMs = 0.0;

        // ★ 对话框的账在 `Win32Dialog` 上（谁跑的模态谁记账），这里只做汇总。
        //   ★ 用具体类型收窄而不是接口：`dialog()` 返回的是 `?Dialog`，AOT 对
        //     "接口值喂进类类型形参"不做静态判断 —— 本类自己造的就是具体类。
        $dialog = $this->dialog;
        if ($dialog instanceof Win32Dialog) {
            $blocked += $dialog->consumeModalBlockedMs();
        }

        return $blocked;
    }

    public function clipboard(): ?Clipboard
    {
        return $this->clipboard ??= new Win32Clipboard();
    }

    public function dialog(): ?Dialog
    {
        if ($this->dialog !== null) {
            return $this->dialog;
        }

        // ★ 传**窗口对象**而不是它的句柄：句柄在建窗之前是 `null`，而 owner 必须
        //   **每次现取**（理由见 `Win32Dialog` 的类注释）。
        // ★ `displayHost()` 而不是 `window()`：AOT 对"接口值喂进类类型形参"不做
        //   静态判断 —— 别让它有机会在运行期才失败。
        $this->dialog = new Win32Dialog($this->displayHost());

        return $this->dialog;
    }

    public function tray(): ?Tray
    {
        return null;   // M2+
    }

    /**
     * 等**一条** OS 消息，最多 `$timeoutMs`，返回**为什么醒**（§3.4 轴 B1）。
     *
     * ## 超时怎么来的（诚实：这是**兜底**，不是终局）
     *
     * `GetMessageW` 没有超时参数。真做要换
     * `MsgWaitForMultipleObjectsEx(…, timeout, QS_ALLINPUT, MWMO_INPUTAVAILABLE)` ——
     * 那是**桥的改动**，而桥的改动必须真机验（本仓纪律：宣称不构成可用性证据）。
     * 所以这一版用**系统定时器兜住**：`SetTimer(hwnd, WAIT_TIMER_ID, ceil(t))` 起一个
     * 闹钟，`GetMessageW` 被它叫醒后立刻 `KillTimer`。
     *
     * ★ 三条代价都记在这里（N4 换 `MsgWait` + 唤醒事件时一起消掉）：
     *   ① **粒度 = 系统 tick（~15.6ms）** ⇒ `waitEvents(4.0)` 实际约 15.6ms。这与今天
     *      `FrameClock` 的 15ms 心跳**同粒度**，所以不构成回归。`waitEvents(0.0)` 同理
     *      **不是"立刻返回"**：它落到系统的 1ms 下限，实际仍是一个 tick（~15.6ms）。
     *      `EventLoop` 明知这一点仍然照调（见那里的注释：跳过等待 = 能跑多快跑多快）。
     *   ② 每次等待多一对 `SetTimer`/`KillTimer`（各约 2µs），只在"要等"时才有。
     *   ③ `KillTimer` **不收回**已经排进队列的 `WM_TIMER` ⇒ 下一轮可能看到一个早到的
     *      `timer` 事件、多跑一帧。无害：帧是幂等的，N1 已把空帧压到近零。
     *
     * ★ **定时器真的起不来**（还没建窗 / id 冲突 / 资源不足）⇒ 退回**无限阻塞**
     *   （与今天同），并记一条 `RECOVERABLE`：此时"等到点"落空、动画会卡在某个相位 —
     *   必须留痕，否则又是一次"不报错的半失效"。
     */
    public function waitEvents(float $timeoutMs): WakeReason
    {
        $armed = false;

        if ($timeoutMs >= 0.0) {
            $ms = (int) \ceil($timeoutMs);
            if ($ms < 1) {
                // `SetTimer` 的 0 是"尽快"，但落到系统 tick 上仍是 ~15.6ms ⇒ 1 更诚实
                $ms = 1;
            }

            $armed = $this->timers()->start(self::WAIT_TIMER_ID, $ms);

            if (!$armed && !$this->waitTimerReported) {
                $this->waitTimerReported = true;
                Errors::report(
                    'Win32 消息泵：等待定时器起不来（未建窗 / id 冲突 / 资源不足）'
                    . '—— 退化为无限阻塞，"等到点"落空（动画会卡在某个相位）',
                    Errors::RECOVERABLE,
                );
            }
        }

        $event = drift_win_pump();

        // ★ **一定要停**：`SetTimer` 是**周期**定时器，不 KillTimer 就是永久
        //   ~15.6ms 心跳（空闲也 64 次/秒），比不加超时更糟。
        if ($armed) {
            $this->timers()->stop(self::WAIT_TIMER_ID);
        }

        $type = (string) ($event['type'] ?? 'quit');

        if ($type === 'quit') {
            return WakeReason::Quit;
        }

        // ★ 只有**我们那个** id 算超时。别的 `timer` 事件（今天框架不用，但壳的 id
        //   空间是共用的）仍按"有消息"处理 —— 它同样意味着"该跑一帧了"。
        if ($type === 'timer' && (int) ($event['id'] ?? 0) === self::WAIT_TIMER_ID) {
            return WakeReason::Timeout;
        }

        // ★ 被 IO 线程叫醒（A2 / N5）：**没有载荷**，活在后端自己的完成队列里。
        //   ★ 不能落进下面的"暂存"分支 —— 那条路会把一个空事件压进 `$pending`，
        //     下一轮 `pumpEvents()` 再当"未知类型"丢掉，看起来对，实则每叫醒一次
        //     就多一条僵尸事件（队列只涨不消，`pending` 永远非空）。
        if ($type === 'wake') {
            return WakeReason::Woken;
        }

        // ★ 只暂存，**不派发**：派发归 `pumpEvents()`。拆开是为了让"等"可以被
        //   超时/唤醒打断无数次而不会顺手把事件投进队列（投了却不跑帧 = 队列涨、
        //   画面不动，**不报错**）。
        $this->pending[] = $event;

        return WakeReason::Message;
    }

    /**
     * 把这轮唤醒取到的事件**全部**归一化并投给 `input()`，返回条数。
     *
     * ★ 今天的上限是 **1 条**：桥的 `drift_win_pump()` 一次调用只回一条**关心**的事件
     *   （中间那些不关心的消息它自己在壳里吃掉了）。要一次取多条得给桥加
     *   `timeout = 0` 的非阻塞取法 —— 那和 `MsgWait` 是同一批改动，归 N4。
     * ★ 先摘快照再翻译：翻译里会 `input()->push()`，而 `push` 的实现不该被
     *   "正在遍历 pending"影响（与 `TaskHost::drain()` 的 swap 同一手法）。
     */
    public function pumpEvents(): int
    {
        if ($this->pending === []) {
            return 0;
        }

        $pending = $this->pending;
        $this->pending = [];

        $count = 0;
        foreach ($pending as $event) {
            $count += $this->translate($event);
        }

        return $count;
    }

    /**
     * 把一条**原始**桥事件翻译成框架类型并投出去，返回投进 `input()` 的条数。
     *
     * 翻译表（与 `cpp-src/drift_win32.stub.php` 的 `drift_win_pump()` 表一一对应）：
     *
     * | 桥给的 | 这里做什么 |
     * |--------|-----------|
     * | `quit` | **不在这里处理** —— `waitEvents()` 已经把它变成 `WakeReason::Quit`（防御性返回 0） |
     * | `resize` | 把物理尺寸 + dpr 交给表面 `resize()`（§6.1 的量化点）。**不算事件**（返回 0） |
     * | `dpi` | **丢弃**（当前无消费者）。表面尺寸/缩放由随行的 `WM_SIZE` ⇒ `resize` 事件驱动（§6.1 的量化点）；`layout()` 本就每帧全树跑（§13.3）⇒ 不需要额外触发 |
     * | `timer` | 丢弃 —— **不是没人要，而是消费者不在这一层**：`SetTimer` 的唯一作用是**把阻塞的 `GetMessageW` 叫醒**，事件本身没有载荷要翻译 |
     * | `pointer` | ⇒ `PointerEvent::fromBridge()`（四相位 down/move/up/leave，坐标除 dpr 转**逻辑**单位） |
     * | `wheel` | ⇒ `ScrollEvent::make()`（坐标除 dpr；delta 收成"格"= 原始值 / 120；`axis` 缺省纵向） |
     * | `key` | ⇒ `KeyEvent::down(KeyChord::make(键名, ctrl, alt, shift))` |
     * | `char` | ⇒ `KeyEvent::character()` |
     * | `theme` | ⇒ `ThemeChangedEvent`（§9.2 系统主题变更；载荷为空，新主题由消费者查 `Screen::systemTheme()`） |
     * | `uia-invoke` | ⇒ `SemanticsInvokeEvent`（§9 / M5 无障碍的"可操作"半边） |
     * | `files-dropped` | ⇒ `FilesDroppedEvent`（A5-② 拖文件进窗：`paths` 是 `'\n'` 分隔的原始串） |
     * | `close-requested` | ⇒ `CloseRequestedEvent`（仅声明了关闭守卫的窗口；见 `WindowHost::setCloseGuard()`） |
     * | 其它 | 丢弃 —— 桥已经保证"只回传 Drift 关心的"，这里是防御性兜底 |
     *
     * ★ **`resize` 必须同时喂表面**：`Screen::viewport()` 读的是桥里的客户区尺寸
     *   （已经变了），而表面的像素尺寸要显式 `resize()` 才会跟上 ⇒ 漏掉这一步的
     *   现象是"窗口拉大后画面还贴在左上角一块、右边全白"，**不报错**。
     * ★ 返回**条数**而不是 `void`：`resize` / `dpi` / `timer` 都不进输入队列，
     *   "投了几条"是 `EventLoop` 与 `pumpEvents()` 的公开返回值 —— 把它算准，
     *   否则那个数字只会是"翻译了几个原始事件"的模糊口径。
     */
    private function translate(array $event): int
    {
        $type = (string) ($event['type'] ?? 'quit');

        if ($type === 'quit') {
            return 0;   // 见 `waitEvents()`：quit 不进 pending
        }

        // ★ **归属窗口**（2026-10-30，M1-b）：桥给每条事件都带 hwnd（`toArray()`
        //   在 switch 之前统一写）。翻译结果要投进**这个窗口**的队列 —— 否则
        //   多窗口下两个应用会抢同一条事件（点一下，两个窗都响应）。
        //   缺字段 ⇒ 0 ⇒ 由 `inputOf()` 归主窗（单窗行为逐字节不变）。
        $handle = (int) ($event['hwnd'] ?? 0);

        if ($type === 'resize') {
            // ★ 表面的像素尺寸要显式 `resize()` 才会跟上（§6.1 的量化点）。
            //   改动前 `applyResize()` 内部取"最后建的窗"⇒ 窗 A 的 `WM_SIZE`
            //   会去 resize 窗 B 的表面（多窗口下最容易在真机上看见的一条）。
            $target = $handle;
            if ($target === 0) {
                $target = $this->displayHost()->handle() ?? 0;
            }
            $this->applyResize($target, (int) ($event['w'] ?? 0), (int) ($event['h'] ?? 0));

            return 0;
        }

        if ($type === 'timer' || $type === 'dpi') {
            // 见翻译表：两者都"没有载荷要翻译"，`waitEvents()` 的返回值就是信号本身。
            return 0;
        }

        if ($type === 'theme') {
            // §9.2：载荷为空 —— 新主题查 `Screen::systemTheme()`（不缓存，每次读注册表）。
            $this->inputOf($handle)->push(new ThemeChangedEvent());

            return 1;
        }

        if ($type === 'deactivate') {
            // `WM_ACTIVATE` 且 `WA_INACTIVE`：消费者收口指针手势（`capturePointer` 的
            // 释放路径 ③）。★ 只发失活、**不发对称的激活** —— 今天没有东西需要在
            // 激活时做（A15：没有消费者不预先发明线上语义）。
            $this->inputOf($handle)->push(new WindowDeactivatedEvent());

            return 1;
        }

        if ($type === 'close-requested') {
            // 可拦截关闭（2026-10-30）：**只有声明了守卫的窗口**才会走到这里
            // （`drift_win_set_close_guard`）。没声明的窗口，壳在 WM_CLOSE 里
            // 直接销毁，这条事件根本不会出现 —— 所以没有"多余的往返"。
            // ★ 它进 `input()` 队列而不是当场问应用：关闭的决策要在**帧边界**做
            //   （典型实现是弹模态确认框），在 WndProc 里问就是消息泵重入
            //   （见壳里 `enqueueCloseRequest` 的注释）。
            $this->inputOf($handle)->push(new CloseRequestedEvent());

            return 1;
        }

        if ($type === 'files-dropped') {
            // A5-②：桥给 '\n' 分隔的多路径原始串 + 客户区物理坐标（除 dpr 转逻辑）。
            $dpr = Dpr::of($handle);
            $this->inputOf($handle)->push(new FilesDroppedEvent(
                (string) ($event['paths'] ?? ''),
                (float) ((int) ($event['x'] ?? 0)) / $dpr,
                (float) ((int) ($event['y'] ?? 0)) / $dpr,
            ));

            return 1;
        }

        $input = $this->inputOf($handle);

        if ($type === 'uia-invoke') {
            // UIA `Invoke`（§9 / M5）：**在壳里一次 PHP 都不进**（WndProc 只入队 + 叫醒泵）。
            // ★ 为什么不做成"壳回调应用"：消息泵的契约是"一次调用交一条事件"，在派发里
            //   跑完 PHP 就没人保证下一帧会跑（真机现象：命令执行了、画面一动不动）。
            // ★ 索引 < 0 = 坏载荷（不该发生）⇒ 不投事件、返回 0：泵的职责是"处理完这条
            //   消息"，不是"断言载荷合法"。
            $index = (int) ($event['idx'] ?? -1);
            if ($index >= 0) {
                $input->push(new SemanticsInvokeEvent($index));

                return 1;
            }

            return 0;
        }

        if ($type === 'uia-set-value') {
            // UIA `SetValue`（§4.4 提交 2）：载荷是字符串（数值型已由壳 `%.17g` 格式化）。
            $index = (int) ($event['idx'] ?? -1);
            if ($index >= 0) {
                $input->push(new SemanticsSetValueEvent($index, (string) ($event['value'] ?? '')));

                return 1;
            }

            return 0;
        }

        if ($type === 'pointer') {
            // ★ 四相位（2026-10-17 move/up/leave 通路）：桥给 `phase`
            //   （0=down 1=move 2=up 3=leave，与 `PointerPhase` 同值），坐标除 dpr 转
            //   逻辑单位 —— 与 down 同口径（§6.1 量化只在平台侧）。
            $dpr = Dpr::of($handle);
            $input->push(PointerEvent::fromBridge(
                (float) ((int) ($event['x'] ?? 0)) / $dpr,
                (float) ((int) ($event['y'] ?? 0)) / $dpr,
                (int) ($event['button'] ?? 0),
                (int) ($event['phase'] ?? 0),
                // 修饰键（2026-10-26）：壳从 `GetKeyState` 读三个位再拆成布尔。
                // ★ 缺了它 Ctrl/Shift+点击在 `Input` 层**无法表达** ⇒ 表格多选退化成
                //   "点一下切换"，Shift 连选做不到。
                (bool) ($event['ctrl'] ?? false),
                (bool) ($event['alt'] ?? false),
                (bool) ($event['shift'] ?? false),
            ));

            return 1;
        }

        if ($type === 'wheel') {
            // ★ 与指针同口径：坐标除 dpr 转逻辑单位；delta 是桥给的原始 WHEEL_DELTA
            //   倍数（一格 = 120），这里收成"格"。★ `axis` 缺省 = 纵向（老壳不带该
            //   字段；壳只在横向时才发它）。壳已经把 Shift+竖轮翻成横轴了。
            $dpr = Dpr::of($handle);
            $input->push(ScrollEvent::make(
                (float) ((int) ($event['x'] ?? 0)) / $dpr,
                (float) ((int) ($event['y'] ?? 0)) / $dpr,
                (float) ((int) ($event['delta'] ?? 0)) / 120.0,
                ($event['axis'] ?? '') === 'horizontal' ? Axis::Horizontal : Axis::Vertical,
            ));

            return 1;
        }

        if ($type === 'key') {
            $name = (string) ($event['key'] ?? '');
            if ($name !== '') {
                $input->push(KeyEvent::down(KeyChord::make(
                    $name,
                    (bool) ($event['ctrl'] ?? false),
                    (bool) ($event['alt'] ?? false),
                    (bool) ($event['shift'] ?? false),
                )));

                return 1;
            }

            return 0;
        }

        if ($type === 'char') {
            $text = (string) ($event['text'] ?? '');
            if ($text !== '') {
                $input->push(KeyEvent::character($text));

                return 1;
            }

            return 0;
        }

        return 0;
    }

    public function now(): float
    {
        return drift_win_now_ms();
    }

    /**
     * 用户配置目录（§5.4）—— `%APPDATA%\Drift`，壳只给**路径不创建**。
     *
     * ★ 空字符串 = 平台答不出来（`SHGetKnownFolderPath` 失败：极少见，例如
     *   没有用户配置文件的会话）⇒ `WindowStateStore` 静默跳过持久化，
     *   **不是**静默崩在别处。
     * ★ 末尾的 `Drift` 子目录是品牌隔离：不把用户文件直接倒进 `%APPDATA%` 根。
     */
    public function configDir(): string
    {
        return drift_win_config_dir();
    }

    public function imeCaret(int $windowHandle, ?array $rect): void
    {
        // 空 = 无插入符（(-1,-1,0) 是 C++ 侧的哨兵值，见 `WindowSlot::imeX` 的注释）
        if ($rect === null) {
            drift_win_ime_caret($windowHandle, -1.0, -1.0, 0.0);
            return;
        }

        [$x, $y, $h] = $rect;
        // ★ 用**该窗口**的 DPI（不是 `screen()->scale()` —— 那个是"最后建的窗"）：
        //   多窗口跨不同 DPI 屏时，用错的那个会把候选框按错误比例放。
        $scale = Dpr::of($windowHandle);
        drift_win_ime_caret(
            $windowHandle,
            (float)$x * $scale,
            (float)$y * $scale,
            (float)$h * $scale,
        );
    }

    /**
     * 把客户区的**物理**尺寸交给表面（唯一一次量化，§6.1）。
     *
     * @param int $windowHandle 这条 resize 来自**哪个窗口**
     */
    private function applyResize(int $windowHandle, int $width, int $height): void
    {
        if ($windowHandle === 0 || $width <= 0 || $height <= 0) {
            return;
        }

        $provider = $this->surfaces[$windowHandle] ?? null;
        if ($provider === null) {
            return;   // 表面还没被 `attach()` 要过 ⇒ 等它来时 `resize()` 会带上尺寸
        }

        $provider->surface()->resize($width, $height, Dpr::of($windowHandle));
    }
}
