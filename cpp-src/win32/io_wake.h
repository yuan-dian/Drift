/**
 * IO 线程池的**唤醒通道（Win32 实现）** —— `core/io_pool` 的 `WakeFn` 钩子。
 *
 * ★★ 为什么唤醒不能跟着池体走（2026-10-30）：池体是纯 C++20（线程 / 互斥量 /
 *   条件变量 / 队列），"叫醒 UI 线程"却必然是平台的 —— Windows 是
 *   `PostMessageW` 到一个 `HWND_MESSAGE` 窗口，macOS 是
 *   `dispatch_async(dispatch_get_main_queue())`，Linux/GTK 是 `g_idle_add` 或
 *   "自管道 + select"。⇒ **这一半按平台各写一份，池体一行不改**。
 *
 * ★ 为什么用消息而不是 `SetEvent` + 一个等待句柄：本仓的消息泵是
 *   `GetMessageW(NULL, …)`（取本线程**任意窗口**的消息），一个消息专用窗就够，
 *   不需要再造一条"等谁"的分支。`SetEvent` 那套要给 `MsgWaitForMultipleObjects`
 *   加一个句柄参数，并且"事件 + 消息"两种唤醒要在泵里合流 —— 多一处能错的地方。
 *
 * ★ 优先级：**真事件 > 唤醒**。唤醒只是个闹钟（无载荷），活在后端自己的完成队列里。
 */

#ifndef DRIFT_WIN32_IO_WAKE_H
#define DRIFT_WIN32_IO_WAKE_H

namespace drift {
namespace win32 {

/**
 * 建消息专用窗 + 装唤醒钩子。**必须在 `io::start()` 之前调**。
 *
 * @return `false` = 建窗失败（此时池不该起：起了也永远叫不醒 UI）
 */
bool startIoWake();

/**
 * 取走"被叫醒过"这个标记（一次消费）。
 *
 * ★★ 调用点必须在 `GetMessageW` **之前**（`php_drift_win_pump()` 的开头）：
 *   标记可能在上一次调用里就被置上了（那一次有真事件排在前面、先返回了真事件）。
 *   只在取消息之后查的话，这条"被叫醒"就永远等不到下一条消息 —— 挂死在
 *   `GetMessageW` 里。
 */
bool consumeWake();

/** 销毁唤醒窗（幂等）。★ 必须先 `io::stop()` join 完线程再调 */
void stopIoWake();

}   // namespace win32
}   // namespace drift

#endif   // DRIFT_WIN32_IO_WAKE_H
