<?php
declare(strict_types=1);

// ★★ 本文件**刻意不带 namespace**（全仓唯一例外，lint `namespace-mismatch`
//    有白名单登记）：AOT 下 C++ 壳按**符号名**直接调用一个已知具名入口，
//    而命名空间的函数会被注册成带前缀的符号，全局限定调用找不到
//    （实验仓踩过 "Call to undefined function"）。
//
// 它解决的是架构事实而非风格问题：拖窗口边缘时 Windows 进入**模态尺寸循环**
// （WM_ENTERSIZEMOVE → 系统自己泵消息 → WM_EXITSIZEMOVE），PHP 主循环
// （`drift_win_pump()` 里阻塞的 GetMessageW）被挂起 ⇒ 拖动期间 PHP 一个帧都
// 跑不了。要"拖动中就是新尺寸的新帧"（而不是旧帧拉伸），唯一的刷新机会在
// 窗口过程内部 —— 由它**同步**调回 PHP 跑一帧。
//
// 与桥纪律的关系：这里**没有闭包/函数指针穿越边界**，壳调的是一个编译期
// 已知的具名函数（与 main 调 `php_drift__application__run` 同性质）。
// 待跑的内容由 PHP 侧静态槽持有（`ResizeHookState::$pump`），从不跨边界传递。

use Drift\Platform\Win32\ResizeHookState;

/**
 * 同步重排入口：壳在模态拖动循环里按定时器（16ms）调用一次。
 *
 * ★ 带 `$windowHandle`（2026-10-30，M1-b）：多窗口下"谁在拖"只有壳知道，
 *   而重排要用**那个窗口**的表面与视口。无参版本会让第二个窗口的拖动
 *   去重排第一个窗口（不报错、只画错窗）。
 *
 * 无运行实例 / 未注入（smoke、离屏测试）⇒ 静默返回 —— 壳无从判断 PHP 侧
 * 状态，容错责任放在这一侧。
 */
function drift_resize_hook_pump(int $windowHandle): void
{
    ResizeHookState::pump($windowHandle);
}
