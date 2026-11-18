<?php
declare(strict_types=1);

namespace Drift\Platform\X11;

use Drift\Input\CloseRequestedEvent;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
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
 * ★ 剪服务这一版返回 null（clipboard/dialog/tray）而非假实现 ——
 *   调用方必须处理 null，这条契约与 Win32 的 M2 降级一致。
 * @api platform
 */
final class X11Platform implements Platform
{
    private ?X11WindowHost $window = null;

    /** @var array<int, X11InputSource> */
    private array $inputs = [];

    /** @var array<int, X11SurfaceProvider> */
    private array $surfaces = [];

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
        return null;
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
