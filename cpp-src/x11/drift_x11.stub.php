<?php
declare(strict_types=1);

/**
 * X11 平台桥声明。只给 Linux 工程引入；与 win32 stub 不可同时进 sources。
 */
function drift_x11_create(string $title, int $width, int $height): int {}
function drift_x11_show(int $hwnd): void {}
function drift_x11_destroy(int $hwnd): void {}
function drift_x11_is_alive(int $hwnd): bool {}
function drift_x11_client_size(int $hwnd): array {}
function drift_x11_set_title(int $hwnd, string $title): void {}
function drift_x11_set_min_size(int $hwnd, int $w, int $h): void {}
function drift_x11_place_window(int $hwnd, float $x, float $y, float $w, float $h, bool $maximized): void {}
function drift_x11_maximize_toggle(int $hwnd): void {}
function drift_x11_minimize(int $hwnd): void {}
function drift_x11_window_position(int $hwnd): array {}
function drift_x11_window_state(int $hwnd): array {}
function drift_x11_wait(float $timeout_ms): array {}
function drift_x11_present(int $hwnd, string $rgba, int $width, int $height): void {}
function drift_x11_now_ms(): float {}
function drift_x11_config_dir(): string {}

// 平台无关的核心桥：Linux 侧的空实现在 drift_x11.cc，避免把 win32.cc 拖进来。
function drift_thread_id(): int {}
function drift_io_start(int $threads): int {}
function drift_io_submit(int $id, string $handler, int $intArg, string $strArg): bool {}
function drift_io_poll(): string {}
function drift_io_threads(): int {}
function drift_io_handled(): int {}
function drift_io_stop(): int {}

// 图片解码（Skia codec；与 win32/WIC 同名同契约，平台二选一编入）。
// 返回 ['w'=>int, 'h'=>int, 'rgba'=>string]，解不出时空数组（不抛）。
function drift_image_decode(string $path, int $maxWidth = 0): array {}
