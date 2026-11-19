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

// 剪贴板纯文本三件套（与 win32 同名同契约，平台二选一编入）。
// 空 / 无 owner / 超时 ⇒ readText 空串、hasText false；writeText 失败静默。
function drift_x11_clipboard_read_text(): string {}
function drift_x11_clipboard_write_text(string $text): void {}
function drift_x11_clipboard_has_text(): bool {}

// 系统外观：XSettings 的亮/暗与高对比度两轴；桥不返回系统色（X11 没有统一 API）。
// 返回 ['dark' => bool, 'high_contrast' => bool]。
function drift_x11_system_appearance(): array {}

// IME 插入符定位（XIM spot location）。visible=false 表示无插入符，桥会清掉旧的 IC 位置。
// ★ 只定位候选窗；组合文字事件属于框架层 ImeEvent，尚未实现，别把它当完整 IME。
function drift_x11_ime_set_caret(int $hwnd, bool $visible, float $x, float $y, float $h): void {}
