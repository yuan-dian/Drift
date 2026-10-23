<?php
declare(strict_types=1);

namespace Drift\Platform\Win32;

use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Input\ScrollEvent;
use Drift\Input\ThemeChangedEvent;
use Drift\Input\WindowDeactivatedEvent;
use Drift\Platform\Clipboard;
use Drift\Platform\Dialog;
use Drift\Platform\InputSource;
use Drift\Platform\Platform;
use Drift\Platform\Screen;
use Drift\Platform\SurfaceProvider;
use Drift\Platform\TimerHost;
use Drift\Platform\Tray;
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
 *   ② **把桥的命名事件翻译成框架的类型**（`pump()` 里的那张 switch）。
 *   其余一律转发 —— 平台层不该有策略。
 *
 * ★ **两个壳服务返回 `null`**（`dialog()` / `tray()`）：
 *   §5.1 纪律 2 把"不支持"设计成**类型层面**的合法状态。返回 `null` 与
 *   "实现了但什么都不做"的区别是：前者调用方必须处理，后者会被当成能用的服务
 *   静默吞掉。★ **`clipboard()` 2026-10-21 起不再是其中之一** —— 第一个消费者
 *   （`Application` 的 `Ctrl+C/V/X`，§9.1 第 ③ 环）已落地，见 `Win32Clipboard`。
 *
 * ★ `fatalBox()` 走桥的 `MessageBoxW`，**不依赖渲染栈** —— 渲染栈本身可能正是
 *   崩掉的那一环（§1.2）。组合根在 `attach()` 之前就把它装进 `Errors`：
 *   `Errors::installFatalBox(fn($t, $b) => $platform->fatalBox($t, $b))`。
 *
 * @api platform
 */
final class Win32Platform implements Platform
{
    private ?Win32WindowHost $window = null;

    private ?Win32Screen $screen = null;

    private ?Win32InputSource $input = null;

    private ?Win32TimerHost $timers = null;

    private ?Win32Clipboard $clipboard = null;

    /** @var array<int, Win32SurfaceProvider> 按窗口句柄缓存（同一窗口只造一个表面） */
    private array $surfaces = [];

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

    public function screen(): Screen
    {
        return $this->screen ??= new Win32Screen($this->window());
    }

    public function window(): WindowHost
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

    public function input(): InputSource
    {
        return $this->input ??= new Win32InputSource();
    }

    public function timers(): TimerHost
    {
        return $this->timers ??= new Win32TimerHost($this->window());
    }

    public function fatalBox(string $title, string $body): void
    {
        drift_win_message_box($title, $body);
    }

    public function clipboard(): ?Clipboard
    {
        return $this->clipboard ??= new Win32Clipboard();
    }

    public function dialog(): ?Dialog
    {
        return null;   // M2+
    }

    public function tray(): ?Tray
    {
        return null;   // M2+
    }

    /**
     * 阻塞等到**一条**事件并把它翻译成框架类型，返回"循环是否继续"。
     *
     * 翻译表（与 `drift_win32.stub.php` 的 `drift_win_pump()` 表一一对应）：
     *
     * | 桥给的 | 这里做什么 |
     * |--------|-----------|
     * | `quit` | 返回 `false` ⇒ 组合根的 `while` 跳出 |
     * | `resize` | 把物理尺寸 + dpr 交给表面 `resize()`（§6.1 的量化点） |
     * | `dpi` | **丢弃**（当前无消费者）。表面尺寸/缩放由随行的 `WM_SIZE` ⇒ `resize` 事件驱动（§6.1 的量化点）；`layout()` 本就每帧全树跑（§13.3）⇒ 不需要额外触发 |
     * | `timer` | 丢弃 —— **不是没人要，而是消费者不在这一层**：`SetTimer` 的
     *   唯一作用是**把阻塞的 `GetMessageW` 叫醒**，事件本身没有载荷要翻译。
     *   消费方是组合根的 `FrameClock`（§17.4.9）：`pump()` 返回 true 就已经是
     *   它要的全部信号 |
     * | `pointer` | ⇒ `PointerEvent::fromBridge()`（四相位 down/move/up/leave，坐标除 dpr 转**逻辑**单位） |
     * | `wheel` | ⇒ `ScrollEvent::make()`（坐标除 dpr；delta 收成"格"= 原始值 / 120） |
     * | `key` | ⇒ `KeyEvent::down(KeyChord::make(键名, ctrl, alt, shift))` |
     * | `char` | ⇒ `KeyEvent::character()` |
     * | `theme` | ⇒ `ThemeChangedEvent`（§9.2 系统主题变更；载荷为空，
     *   新主题由消费者查 `Screen::systemTheme()` —— Win32 侧查询实时） |
     * | 其它 | 丢弃 —— 桥已经保证"只回传 Drift 关心的"，这里是防御性兜底 |
     *
     * ★ **`resize` 必须同时喂表面**：`Screen::viewport()` 读的是桥里的客户区尺寸
     *   （已经变了），而表面的像素尺寸要显式 `resize()` 才会跟上 ⇒ 漏掉这一步的
     *   现象是"窗口拉大后画面还贴在左上角一块、右边全白"，**不报错**。
     */
    public function pump(): bool
    {
        $event = drift_win_pump();
        $type = (string) ($event['type'] ?? 'quit');

        if ($type === 'quit') {
            return false;
        }

        if ($type === 'resize') {
            $this->applyResize((int) ($event['w'] ?? 0), (int) ($event['h'] ?? 0));

            return true;
        }

        if ($type === 'timer' || $type === 'dpi') {
            // `timer`：**没有载荷要翻译** —— `pump()` 返回 true 本身就是信号
            //   （`FrameClock::tick()` 靠它跑下一帧，§17.4.9）。
            // `dpi`：**不需要消费者** —— 表面跟着随行的 `WM_SIZE` ⇒ `resize` 事件走
            //   （§6.1 的量化点），而布局每帧本就全树跑（§13.3）⇒ 没有"额外触发 relayout"
            //   这回事。
            //   ★ 这里原先写"全树重建由下一帧 `viewport()` 变化驱动" —— **不成立**：跨屏时
            //     逻辑客户区尺寸不变（物理尺寸变、dpr 也变，两者相抵）⇒ `viewport()` 不变。
            return true;
        }

        if ($type === 'theme') {
            // §9.2：系统主题变更。载荷为空 —— 新主题查 `Screen::systemTheme()`
            // （Win32Screen 不缓存，每次读注册表）。框架侧显式消费见
            // `Application::dispatchInput` 的同名分支。
            $this->input()->push(new ThemeChangedEvent());

            return true;
        }

        if ($type === 'deactivate') {
            // `WM_ACTIVATE` 且 `WA_INACTIVE`：用户切走了。载荷为空 —— 消费者只
            // 需要知道"这事发生了"。框架侧收口指针手势（`capturePointer` 的
            // 释放路径 ③），见 `Application::dispatchInput` 的同名分支。
            // ★ 只发失活、**不发对称的激活**：今天没有东西需要在激活时做
            //   （A15：没有消费者不预先发明线上语义）。
            $this->input()->push(new WindowDeactivatedEvent());

            return true;
        }

        $input = $this->input();

        if ($type === 'pointer') {
            // ★ 四相位（2026-10-17 move/up/leave 通路）：桥给 `phase`
            //   （0=down 1=move 2=up 3=leave，与 `PointerPhase` 同值），
            //   坐标除 dpr 转逻辑单位 —— 与 down 同口径（§6.1 量化只在平台侧）。
            //   相位→工厂的映射住 `PointerEvent::fromBridge()`（唯一一处）。
            $dpr = $this->screen()->scale();
            if ($dpr <= 0.0) {
                $dpr = 1.0;
            }
            $input->push(PointerEvent::fromBridge(
                (float) ((int) ($event['x'] ?? 0)) / $dpr,
                (float) ((int) ($event['y'] ?? 0)) / $dpr,
                (int) ($event['button'] ?? 0),
                (int) ($event['phase'] ?? 0),
            ));

            return true;
        }

        if ($type === 'wheel') {
            // ★ 与指针同口径：坐标除 dpr 转逻辑单位；delta 是桥给的原始
            //   WHEEL_DELTA 倍数（一格 = 120），这里收成"格"—— 逻辑单位才是
            //   §6.1 的量化点分界（平台只做一次量化，框架侧不做位运算）。
            $dpr = $this->screen()->scale();
            if ($dpr <= 0.0) {
                $dpr = 1.0;
            }
            $input->push(ScrollEvent::make(
                (float) ((int) ($event['x'] ?? 0)) / $dpr,
                (float) ((int) ($event['y'] ?? 0)) / $dpr,
                (float) ((int) ($event['delta'] ?? 0)) / 120.0,
            ));

            return true;
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
            }

            return true;
        }

        if ($type === 'char') {
            $text = (string) ($event['text'] ?? '');
            if ($text !== '') {
                $input->push(KeyEvent::character($text));
            }

            return true;
        }

        return true;
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

    public function imeCaret(?array $rect): void
    {
        // 空 = 无插入符（(-1,-1,0) 是 C++ 侧的哨兵值，见 g_imeX 的注释）
        if ($rect === null) {
            drift_win_ime_caret(-1.0, -1.0, 0.0);
            return;
        }

        [$x, $y, $h] = $rect;
        $scale = $this->screen()->scale();
        drift_win_ime_caret((float)$x * $scale, (float)$y * $scale, (float)$h * $scale);
    }

    /** 把客户区的**物理**尺寸交给表面（唯一一次量化，§6.1） */
    private function applyResize(int $width, int $height): void
    {
        $handle = $this->window()?->handle();
        if ($handle === null || $width <= 0 || $height <= 0) {
            return;
        }

        $provider = $this->surfaces[$handle] ?? null;
        if ($provider === null) {
            return;   // 表面还没被 `attach()` 要过 ⇒ 等它来时 `resize()` 会带上尺寸
        }

        $provider->surface()->resize($width, $height, $this->screen()->scale());
    }
}
