<?php
declare(strict_types=1);

/**
 * X11 桥的解释器替身（PHPUnit / smoke 共用）。
 *
 * ★ 只放这次新接的窗口能力桥函数；clipboard / dialog / tray 仍走 X11Platform
 *   的 function_exists 降级，不在这里定义，免得改变既有 null 语义的测试。
 * ★ 签名与 cpp-src/x11/drift_x11.stub.php 逐字一致，由 abi-check 机器校验。
 */
if (!function_exists('drift_x11_dpi')) {
    function drift_x11_dpi(int $hwnd): float
    {
        return (float) ($GLOBALS['DRIFT_FAKE_X11_DPI'] ?? 96.0);
    }
}

// 建窗/几何的假实现：PHPUnit 里 X11WindowHost 的折算要能落到可断言的记录上。
// 注意只在函数不存在时定义 —— 真 AOT / 真桥下这些函数已存在，不被替身顶掉。
if (!function_exists('drift_x11_create')) {
    function drift_x11_create(string $title, int $width, int $height): int { return 1; }
    function drift_x11_show(int $hwnd): void {}
    function drift_x11_destroy(int $hwnd): void {}
    function drift_x11_is_alive(int $hwnd): bool { return true; }
    function drift_x11_client_size(int $hwnd): array
    {
        return ['w' => $GLOBALS['DRIFT_FAKE_X11_W'] ?? 800, 'h' => $GLOBALS['DRIFT_FAKE_X11_H'] ?? 600];
    }
    function drift_x11_set_title(int $hwnd, string $title): void {}
    function drift_x11_set_min_size(int $hwnd, int $w, int $h): void
    {
        $GLOBALS['DRIFT_FAKE_X11_MINSIZE'][] = [$hwnd, $w, $h];
    }
    function drift_x11_place_window(int $hwnd, float $x, float $y, float $w, float $h, bool $maximized): void
    {
        $GLOBALS['DRIFT_FAKE_X11_PLACE'][] = [$hwnd, $x, $y, $w, $h, $maximized];
    }
    function drift_x11_maximize_toggle(int $hwnd): void {}
    function drift_x11_minimize(int $hwnd): void {}
    function drift_x11_window_position(int $hwnd): array
    {
        return ['x' => $GLOBALS['DRIFT_FAKE_X11_X'] ?? 100, 'y' => $GLOBALS['DRIFT_FAKE_X11_Y'] ?? 200];
    }
    function drift_x11_window_state(int $hwnd): array
    {
        return ['max_v' => false, 'max_h' => false, 'iconic' => false];
    }
    function drift_x11_wait(float $timeout_ms): array { return ['type' => 'timeout']; }
    function drift_x11_present(int $hwnd, string $rgba, int $width, int $height): void {}
    function drift_x11_now_ms(): float { return 0.0; }
    function drift_x11_config_dir(): string { return ''; }
}

if (!function_exists('drift_x11_set_cursor')) {
    function drift_x11_set_cursor(int $hwnd, int $shape): void
    {
        $GLOBALS['DRIFT_FAKE_X11_CURSOR'][] = [$hwnd, $shape];
    }
}

if (!function_exists('drift_x11_set_frameless')) {
    function drift_x11_set_frameless(int $hwnd, int $dragHeight): void
    {
        $GLOBALS['DRIFT_FAKE_X11_FRAMELESS'][] = [$hwnd, $dragHeight];
    }
}

if (!function_exists('drift_x11_begin_drag')) {
    function drift_x11_begin_drag(int $hwnd): void
    {
        $GLOBALS['DRIFT_FAKE_X11_DRAG'][] = $hwnd;
    }
}

/** @return list<array{int, int}> */
function x11CursorCalls(): array
{
    return $GLOBALS['DRIFT_FAKE_X11_CURSOR'] ?? [];
}

/** @return list<array{int, int}> */
function x11FramelessCalls(): array
{
    return $GLOBALS['DRIFT_FAKE_X11_FRAMELESS'] ?? [];
}

/** @return list<int> */
function x11DragCalls(): array
{
    return $GLOBALS['DRIFT_FAKE_X11_DRAG'] ?? [];
}

/** @return list<array{int, int, int}> */
function x11MinSizeCalls(): array
{
    return $GLOBALS['DRIFT_FAKE_X11_MINSIZE'] ?? [];
}

/** @return list<array{int, float, float, float, float, bool}> */
function x11PlaceCalls(): array
{
    return $GLOBALS['DRIFT_FAKE_X11_PLACE'] ?? [];
}

/** 清空所有 X11 桥调用记录，供测试间隔离。 */
function x11FakeReset(): void
{
    unset(
        $GLOBALS['DRIFT_FAKE_X11_CURSOR'],
        $GLOBALS['DRIFT_FAKE_X11_FRAMELESS'],
        $GLOBALS['DRIFT_FAKE_X11_DRAG'],
        $GLOBALS['DRIFT_FAKE_X11_MINSIZE'],
        $GLOBALS['DRIFT_FAKE_X11_PLACE'],
        $GLOBALS['DRIFT_FAKE_X11_DPI'],
        $GLOBALS['DRIFT_FAKE_X11_W'],
        $GLOBALS['DRIFT_FAKE_X11_H'],
        $GLOBALS['DRIFT_FAKE_X11_X'],
        $GLOBALS['DRIFT_FAKE_X11_Y'],
    );
}
