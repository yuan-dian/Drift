<?php
declare(strict_types=1);

namespace Drift\Platform\Win32;

use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Platform\Clipboard;
use Drift\Platform\Dialog;
use Drift\Platform\InputSource;
use Drift\Platform\Platform;
use Drift\Platform\Screen;
use Drift\Platform\SurfaceProvider;
use Drift\Platform\TimerHost;
use Drift\Platform\Tray;
use Drift\Platform\WindowHost;

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
 * ★ **三个壳服务返回 `null`**（`clipboard()` / `dialog()` / `tray()`）：
 *   §5.1 纪律 2 把"不支持"设计成**类型层面**的合法状态。M1 不实现它们，
 *   因为今天**没有消费者**（第一个消费者是 `TextInputClient` 的 `Ctrl+C/V`，
 *   归 M3）。返回 `null` 与"实现了但什么都不做"的区别是：前者调用方必须处理，
 *   后者会被当成能用的服务静默吞掉。
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

    /** @var array<int, Win32SurfaceProvider> 按窗口句柄缓存（同一窗口只造一个表面） */
    private array $surfaces = [];

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

        return $this->surfaces[$windowHandle] ??= new Win32SurfaceProvider($windowHandle);
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
        return null;   // M3（`TextInputClient` 的复制/粘贴）
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
     * | `timer` | ⏳ M1 丢弃：`FrameClock` 归 M4，今天没有消费者 |
     * | `pointer` | ⇒ `PointerEvent::down()`（坐标除 dpr 转**逻辑**单位） |
     * | `key` | ⇒ `KeyEvent::down(KeyChord::make(键名, ctrl, alt, shift))` |
     * | `char` | ⇒ `KeyEvent::character()` |
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
            // `timer`：`FrameClock` 归 M4，今天没有消费者。
            // `dpi`：**不需要消费者** —— 表面跟着随行的 `WM_SIZE` ⇒ `resize` 事件走
            //   （§6.1 的量化点），而布局每帧本就全树跑（§13.3）⇒ 没有"额外触发 relayout"
            //   这回事。
            //   ★ 这里原先写"全树重建由下一帧 `viewport()` 变化驱动" —— **不成立**：跨屏时
            //     逻辑客户区尺寸不变（物理尺寸变、dpr 也变，两者相抵）⇒ `viewport()` 不变。
            return true;
        }

        $input = $this->input();

        if ($type === 'pointer') {
            $dpr = $this->screen()->scale();
            if ($dpr <= 0.0) {
                $dpr = 1.0;
            }
            $input->push(PointerEvent::down(
                (float) ((int) ($event['x'] ?? 0)) / $dpr,
                (float) ((int) ($event['y'] ?? 0)) / $dpr,
                (int) ($event['button'] ?? 0),
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
