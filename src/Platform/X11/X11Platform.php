<?php
declare(strict_types=1);

namespace Drift\Platform\X11;

use Drift\Geometry\Axis;
use Drift\Input\CloseRequestedEvent;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Input\ScrollEvent;
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

use function drift_x11_now_ms;
use function drift_x11_config_dir;
use function drift_x11_wait;

/**
 * X11 平台实现：装配六个子端口，把桥的命名事件翻译成框架类型。
 *
 * ★ 壳服务里 clipboard 由 xclip 桥真实现（纯文本，与 Win32 同契约）；dialog/tray
 *   仍返回 null 而非假实现 —— 调用方必须处理 null，与 Win32 的 M2 降级一致。
 *   剪贴板在桥函数缺失时（纯 PHP 单测）也返回 null。
 * @api platform
 */
final class X11Platform implements Platform
{
    private ?X11WindowHost $window = null;

    /** @var array<int, X11InputSource> */
    private array $inputs = [];

    /** @var array<int, X11SurfaceProvider> */
    private array $surfaces = [];

    /** 壳服务懒建：与 Win32Platform 同形，不支持的仍是 null */
    private ?X11Clipboard $clipboard = null;

    /** @var list<array<string, mixed>> */
    private array $pending = [];

    public function screen(int $windowHandle): Screen
    {
        return new X11Screen($this->displayHost(), $windowHandle);
    }

    public function window(): WindowHost
    {
        return $this->displayHost();
    }

    private function displayHost(): X11WindowHost
    {
        return $this->window ??= new X11WindowHost();
    }

    public function surface(int $windowHandle): ?SurfaceProvider
    {
        if ($windowHandle <= 0) {
            return null;
        }

        // 传同一个窗口宿主：表面创建时要现读客户区尺寸（attach 的同步首帧早于 resize 事件）。
        return $this->surfaces[$windowHandle] ??= new X11SurfaceProvider($windowHandle, $this->displayHost());
    }

    public function input(int $windowHandle): InputSource
    {
        return $this->inputOf($windowHandle);
    }

    private function inputOf(int $windowHandle): X11InputSource
    {
        if ($windowHandle <= 0) {
            $windowHandle = $this->displayHost()->handle() ?? 0;
        }

        return $this->inputs[$windowHandle] ??= new X11InputSource();
    }

    public function fatalBox(string $title, string $body): void
    {
        // 无原生错误框的降级：不假装 Modal，也不记阻塞时长。
        \fwrite(\STDERR, "[FATAL] {$title}: {$body}\n");
    }

    public function clipboard(): ?Clipboard
    {
        // xclip 桥在纯 PHP 单测里不存在函数，此时退回 null（与 Win32 的 M2 降级同口径）。
        // 真机 / AOT 工程里三个函数都在，返回实现、让 Ctrl+C/V/X 端到端可用。
        if (!\function_exists('drift_x11_clipboard_read_text')) {
            return null;
        }
        return $this->clipboard ??= new X11Clipboard();
    }

    public function dialog(): ?Dialog
    {
        return null;
    }

    public function tray(): ?Tray
    {
        return null;
    }

    public function consumeModalBlockedMs(): float
    {
        return 0.0;
    }

    public function waitEvents(float $timeoutMs): WakeReason
    {
        $event = drift_x11_wait($timeoutMs);
        $type = (string) ($event['type'] ?? 'quit');

        if ($type === 'quit') {
            return WakeReason::Quit;
        }
        if ($type === 'timeout') {
            return WakeReason::Timeout;
        }
        if ($type === 'wake') {
            return WakeReason::Woken;
        }

        $this->pending[] = $event;

        return WakeReason::Message;
    }

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

    private function translate(array $event): int
    {
        $type = (string) ($event['type'] ?? 'quit');
        if ($type === 'quit' || $type === 'noop' || $type === 'timer' || $type === 'dpi') {
            return 0;
        }

        $handle = (int) ($event['hwnd'] ?? 0);

        if ($type === 'resize') {
            $target = $handle > 0 ? $handle : ($this->displayHost()->handle() ?? 0);
            $provider = $this->surfaces[$target] ?? null;
            if ($provider !== null) {
                $provider->surface()->resize(
                    max(0, (int) ($event['w'] ?? 0)),
                    max(0, (int) ($event['h'] ?? 0)),
                    1.0,
                );
            }

            return 0;
        }

        if ($type === 'close-requested') {
            $this->inputOf($handle)->push(new CloseRequestedEvent());

            return 1;
        }

        if ($type === 'deactivate') {
            // 窗口失焦（FocusOut 且是"真的切走"）：与 Win32Platform 同口径，
            // 收口进行中的指针手势（capture/gesture/drag），不动浮层。
            $this->inputOf($handle)->push(new WindowDeactivatedEvent());

            return 1;
        }

        if ($type === 'wheel') {
            // 桥给的是 WHEEL_DELTA 倍数（一格 = 120），这里收成"格"；
            // X11 桥已按 Win32 口径定好方向与轴，PHP 只做归一化，不做平台判断。
            $this->inputOf($handle)->push(ScrollEvent::make(
                (float) ((int) ($event['x'] ?? 0)),
                (float) ((int) ($event['y'] ?? 0)),
                (float) ((int) ($event['delta'] ?? 0)) / 120.0,
                ($event['axis'] ?? '') === 'horizontal' ? Axis::Horizontal : Axis::Vertical,
            ));

            return 1;
        }

        if ($type === 'pointer') {
            $this->inputOf($handle)->push(PointerEvent::fromBridge(
                (float) ((int) ($event['x'] ?? 0)),
                (float) ((int) ($event['y'] ?? 0)),
                (int) ($event['button'] ?? 0),
                (int) ($event['phase'] ?? 0),
                (bool) ($event['ctrl'] ?? false),
                (bool) ($event['alt'] ?? false),
                (bool) ($event['shift'] ?? false),
            ));

            return 1;
        }

        if ($type === 'key') {
            $name = (string) ($event['key'] ?? '');
            if ($name !== '') {
                $this->inputOf($handle)->push(KeyEvent::down(KeyChord::make(
                    $name,
                    (bool) ($event['ctrl'] ?? false),
                    (bool) ($event['alt'] ?? false),
                    (bool) ($event['shift'] ?? false),
                )));

                return 1;
            }
        }

        if ($type === 'char') {
            // 文本输入走 character()：KeyEvent::character 用首字符做 chord、
            // 原文做 text —— TextField 的 TextKeymap 只看 isText()，加速键不看。
            $text = (string) ($event['text'] ?? '');
            if ($text !== '') {
                $this->inputOf($handle)->push(KeyEvent::character($text));

                return 1;
            }
        }

        return 0;
    }

    public function imeCaret(int $windowHandle, ?array $rect): void
    {
        // 无 IME 桥；不代理到 XIM，留空即"无插入符"。
    }

    public function now(): float
    {
        return drift_x11_now_ms();
    }

    public function configDir(): string
    {
        return drift_x11_config_dir();
    }
}
