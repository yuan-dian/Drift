<?php
declare(strict_types=1);

// 本文件刻意不带 namespace（全仓唯一例外，lint `namespace-mismatch` 白名单）：
// AOT 下 C++ 壳按符号名直接调用具名入口，命名空间函数会注册成带前缀符号，全局限定调用找不到。
// 拖窗口边缘时 Windows 进入模态尺寸循环，PHP 主循环被挂起；要"拖动中就是新尺寸的帧"，
// 只能在窗口过程里同步调回 PHP 跑一帧。
// 没有闭包 / 函数指针跨边界：壳调编译期已知的函数，待跑内容由 PHP 静态槽持有。

use Drift\Platform\Win32\ResizeHookState;

/**
 * 同步重排入口：壳在模态拖动循环里每 16ms 调一次。
 *
 * 必须带 `$windowHandle`：多窗口下只有壳知道谁在拖，无参版本会去重排第一个窗口（不报错、只画错窗）。
 * 无运行实例 / 未注入 ⇒ 静默返回，容错责任在 PHP 侧。
 */
function drift_resize_hook_pump(int $windowHandle): void
{
    ResizeHookState::pump($windowHandle);
}
