<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Support;

use Drift\Dev\Smoke\WindowState\FakeWindowHost;
use Drift\Platform\Clipboard;
use Drift\Platform\Dialog;
use Drift\Platform\InputSource;
use Drift\Platform\Platform;
use Drift\Platform\PlatformHooks;
use Drift\Platform\Screen;
use Drift\Platform\SurfaceProvider;
use Drift\Platform\Tray;
use Drift\Platform\WakeReason;
use Drift\Platform\WindowHost;

/**
 * `Platform` 的最小第二实现（对齐 Qt `qminimal` 的用途：验证抽象可替换，不测真窗口）。
 *
 * ★ 与 `tools/fakes/win32.php` 分工不同：那是 C 扩展函数级替身，仍让真的
 *   `Win32Platform` 策略跑；这里替换的是**整个平台接口**。
 */
final class HeadlessPlatform implements Platform
{
    /** @var list<string> 被调过的方法名，供契约判据验证注入路径真的走到了 */
    public array $calls = [];

    private HeadlessScreen $screen;

    private HeadlessInputSource $input;

    private FakeWindowHost $window;

    private HeadlessPlatformHooks $hooks;

    private float $clock = 0.0;

    public function __construct()
    {
        $this->screen = new HeadlessScreen();
        $this->input = new HeadlessInputSource();
        $this->window = new FakeWindowHost();
        $this->hooks = new HeadlessPlatformHooks();
    }

    public function hooks(): PlatformHooks
    {
        $this->calls[] = __FUNCTION__;

        return $this->hooks;
    }

    public function screen(int $windowHandle): Screen
    {
        $this->calls[] = __FUNCTION__;

        return $this->screen;
    }

    public function window(): WindowHost
    {
        $this->calls[] = __FUNCTION__;

        return $this->window;
    }

    public function surface(int $windowHandle): ?SurfaceProvider
    {
        $this->calls[] = __FUNCTION__;

        return null;
    }

    public function input(int $windowHandle): InputSource
    {
        $this->calls[] = __FUNCTION__;

        return $this->input;
    }

    public function fatalBox(string $title, string $body): void
    {
        $this->calls[] = __FUNCTION__;
    }

    public function clipboard(): ?Clipboard
    {
        $this->calls[] = __FUNCTION__;

        return null;
    }

    public function dialog(): ?Dialog
    {
        $this->calls[] = __FUNCTION__;

        return null;
    }

    public function tray(): ?Tray
    {
        $this->calls[] = __FUNCTION__;

        return null;
    }

    public function consumeModalBlockedMs(): float
    {
        $this->calls[] = __FUNCTION__;

        return 0.0;
    }

    public function waitEvents(float $timeoutMs): WakeReason
    {
        $this->calls[] = __FUNCTION__;

        return WakeReason::Quit;
    }

    public function pumpEvents(): int
    {
        $this->calls[] = __FUNCTION__;

        return 0;
    }

    public function imeCaret(int $windowHandle, ?array $rect): void
    {
        $this->calls[] = __FUNCTION__;
    }

    public function now(): float
    {
        $this->calls[] = __FUNCTION__;

        return $this->clock += 1.0;
    }

    public function configDir(): string
    {
        $this->calls[] = __FUNCTION__;

        return '';
    }
}
