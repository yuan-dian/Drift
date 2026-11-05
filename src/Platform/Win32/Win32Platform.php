<?php
declare(strict_types=1);

namespace Drift\Platform\Win32;

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
use function drift_win_wait;

/**
 * Win32 平台实现：只做两件别处做不了的事 —— 装配六个子端口（谁持有谁），
 * 以及把桥的命名事件翻译成框架类型（translate() 里的那张表）。其余一律转发。
 * @api platform
 */
final class Win32Platform implements Platform
{
    private ?Win32WindowHost $window = null;

    /**
     * 按窗口句柄缓存的事件队列。句柄就是 key：队列是有状态对象，窗口是它的天然归属。
     * @var array<int, Win32InputSource>
     */
    private array $inputs = [];

    private ?Win32Clipboard $clipboard = null;

    private ?Win32Dialog $dialog = null;

    /** @var array<int, Win32SurfaceProvider> 按窗口句柄缓存（同一窗口只造一个表面） */
    private array $surfaces = [];

    /**
     * waitEvents() 取到、还没派发的原始桥事件，由 pumpEvents() 消费；桥一次只回一条关心的事件，故上限 1 条。
     * @var list<array<string, mixed>>
     */
    private array $pending = [];

    /**
     * fatalBox() 记下的模态阻塞时间（毫秒）—— consumeModalBlockedMs() 取走。
     * 对话框那一份在 Win32Dialog 上；这里只放 fatalBox() 自己的。读后即清是其契约。
     */
    private float $modalBlockedMs = 0.0;

    /**
     * @param string|null $renderer 光栅后端（'skia'；null = 软光栅）。
     *   归组合根：换后端是部署形态，不是运行期配置。
     */
    public function __construct(
        private readonly ?string $renderer = null,
    ) {
    }

    public function screen(int $windowHandle): Screen
    {
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
     * 某条事件该投进哪条队列（translate() 用；input() 不用它）。
     * 与 input($h) 的差别只有一条回落：$windowHandle <= 0 表示"这条事件没有
     * 归属"（测试手工造的剧本 / 老壳不带 hwnd）⇒ 归主窗。
     */
    private function inputOf(int $windowHandle): Win32InputSource
    {
        if ($windowHandle <= 0) {
            $windowHandle = $this->displayHost()->handle() ?? 0;
        }

        return $this->inputs[$windowHandle] ??= new Win32InputSource();
    }

    public function fatalBox(string $title, string $body): void
    {
        // ★ MessageBoxW 是原生模态（用户看多久它就开多久）；它走桥的 MessageBoxW
        //   而非渲染栈 —— 渲染栈本身可能正是崩掉的那一环。
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

        // 对话框的账在 Win32Dialog 上（谁跑的模态谁记账），这里只做汇总。
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

        // 句柄在建窗前是 null，owner 必须每次现取 ⇒ 传窗口对象。
        // 本类造的是具体类，用 displayHost() 而非 window() 避免运行期才失败。
        $this->dialog = new Win32Dialog($this->displayHost());

        return $this->dialog;
    }

    public function tray(): ?Tray
    {
        return null;   // M2+
    }

    /**
     * 等一条 OS 消息，最多 $timeoutMs；`< 0` = 无限等，返回为什么醒。
     * 超时用高精度可等待定时器（~1ms），不再用 `SetTimer` 的系统 tick（~15.6ms）——
     * 后者会把 16ms 请求落到 31ms，帧间隔只剩两档。
     */
    public function waitEvents(float $timeoutMs): WakeReason
    {
        $event = $timeoutMs >= 0.0
            ? drift_win_wait($timeoutMs)
            : drift_win_pump();

        $type = (string) ($event['type'] ?? 'quit');

        if ($type === 'quit') {
            return WakeReason::Quit;
        }

        if ($type === 'timeout') {
            return WakeReason::Timeout;
        }

        // 被 IO 线程叫醒：没有载荷，活在后端自己的完成队列里。
        // 不能落进下面的"暂存"分支 —— 那条路会把空事件压进 pending，下一轮再当"未知类型"丢掉。
        if ($type === 'wake') {
            return WakeReason::Woken;
        }

        // 只暂存，不派发：派发归 pumpEvents()。拆开是为了让"等"可被超时/唤醒打断无数次。
        $this->pending[] = $event;

        return WakeReason::Message;
    }

    /**
     * 把这轮唤醒取到的事件归一化并投给 input()，返回条数。
     * 先摘快照再翻译：翻译里会 push()，push 的实现不应被"正在遍历 pending"影响。
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
     * 把一条原始桥事件翻译成框架类型并投出去，返回投进 input() 的条数。
     * 桥给的每条事件都带 hwnd，翻译结果投进该窗口的队列（多窗口防串扰）。
     * quit 在 waitEvents() 已变 WakeReason::Quit，这里防御性返回 0；
     * timer / dpi 无载荷，返回 0；其余按类型造对应事件。
     * ★ resize 必须同时喂表面：Screen::viewport() 读桥里已变的客户区尺寸，
     *   表面像素尺寸要显式 resize() 才跟上 ⇒ 漏掉会"窗口拉大后画面贴左上角、
     *   右边全白"，不报错。
     */
    private function translate(array $event): int
    {
        $type = (string) ($event['type'] ?? 'quit');

        if ($type === 'quit') {
            return 0;   // 见 `waitEvents()`：quit 不进 pending
        }

        // 归属窗口：桥给每条事件都带 hwnd，翻译结果要投进这个窗口的队列。
        // 缺字段 ⇒ 0 ⇒ 由 inputOf() 归主窗。
        $handle = (int) ($event['hwnd'] ?? 0);

        if ($type === 'resize') {
            $target = $handle;
            if ($target === 0) {
                $target = $this->displayHost()->handle() ?? 0;
            }
            $this->applyResize($target, (int) ($event['w'] ?? 0), (int) ($event['h'] ?? 0));

            return 0;
        }

        if ($type === 'timer' || $type === 'dpi') {
            // 两者都"没有载荷要翻译"，waitEvents() 的返回值就是信号本身。
            return 0;
        }

        if ($type === 'theme') {
            // 载荷为空 —— 新主题查 Screen::systemTheme()（不缓存，每次读注册表）。
            $this->inputOf($handle)->push(new ThemeChangedEvent());

            return 1;
        }

        if ($type === 'deactivate') {
            // WM_ACTIVATE 且 WA_INACTIVE：消费者收口指针手势（capturePointer 的释放路径）。
            // 只发失活、不发对称激活。
            $this->inputOf($handle)->push(new WindowDeactivatedEvent());

            return 1;
        }

        if ($type === 'close-requested') {
            // 只有声明了守卫的窗口才会走到这里。它进 input() 队列而不是当场问应用：
            // 关闭的决策要在帧边界做（典型实现是弹模态确认框），在 WndProc 里问就是消息泵重入。
            $this->inputOf($handle)->push(new CloseRequestedEvent());

            return 1;
        }

        if ($type === 'files-dropped') {
            // 桥给 '\n' 分隔的多路径原始串 + 客户区物理坐标（除 dpr 转逻辑）。
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
            // 在壳里一次 PHP 都不进（WndProc 只入队 + 叫醒泵）。
            // 索引 < 0 = 坏载荷（不该发生）⇒ 不投事件、返回 0：泵的职责是"处理完这条消息"。
            $index = (int) ($event['idx'] ?? -1);
            if ($index >= 0) {
                $input->push(new SemanticsInvokeEvent($index));

                return 1;
            }

            return 0;
        }

        if ($type === 'uia-set-value') {
            // 载荷是字符串（数值型已由壳 %.17g 格式化）。
            $index = (int) ($event['idx'] ?? -1);
            if ($index >= 0) {
                $input->push(new SemanticsSetValueEvent($index, (string) ($event['value'] ?? '')));

                return 1;
            }

            return 0;
        }

        if ($type === 'pointer') {
            // 桥给 phase（0=down 1=move 2=up 3=leave，与 PointerPhase 同值），坐标除 dpr 转逻辑单位。
            $dpr = Dpr::of($handle);
            $input->push(PointerEvent::fromBridge(
                (float) ((int) ($event['x'] ?? 0)) / $dpr,
                (float) ((int) ($event['y'] ?? 0)) / $dpr,
                (int) ($event['button'] ?? 0),
                (int) ($event['phase'] ?? 0),
                // 修饰键：壳从 GetKeyState 读三个位再拆成布尔。
                // 缺了它 Ctrl/Shift+点击在 Input 层无法表达 ⇒ 表格多选退化成"点一下切换"。
                (bool) ($event['ctrl'] ?? false),
                (bool) ($event['alt'] ?? false),
                (bool) ($event['shift'] ?? false),
            ));

            return 1;
        }

        if ($type === 'wheel') {
            // 坐标除 dpr 转逻辑单位；delta 是桥给的原始 WHEEL_DELTA 倍数（一格 = 120），这里收成"格"。
            // axis 缺省 = 纵向（壳只在横向时才发该字段）。
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
     * 用户配置目录 —— %APPDATA%\Drift，壳只给路径不创建。
     * 空字符串 = 平台答不出来（SHGetKnownFolderPath 失败：极少见）⇒
     *   WindowStateStore 静默跳过持久化，不是静默崩在别处。
     * 末尾的 Drift 子目录是品牌隔离：不把用户文件直接倒进 %APPDATA% 根。
     */
    public function configDir(): string
    {
        return drift_win_config_dir();
    }

    public function imeCaret(int $windowHandle, ?array $rect): void
    {
        // 空 = 无插入符（(-1,-1,0) 是 C++ 侧的哨兵值）。
        if ($rect === null) {
            drift_win_ime_caret($windowHandle, -1.0, -1.0, 0.0);
            return;
        }

        [$x, $y, $h] = $rect;
        // 用该窗口的 DPI（不是最后建的窗的 scale）：跨 DPI 屏用错会按错误比例放候选框。
        $scale = Dpr::of($windowHandle);
        drift_win_ime_caret(
            $windowHandle,
            (float)$x * $scale,
            (float)$y * $scale,
            (float)$h * $scale,
        );
    }

    /**
     * 把客户区的物理尺寸交给表面（唯一一次量化）。
     * @param int $windowHandle 这条 resize 来自哪个窗口
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
