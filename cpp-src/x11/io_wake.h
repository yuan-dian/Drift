/**
 * IO 线程池的唤醒通道 —— Linux 自管道实现（core/io_pool 的 WakeFn 钩子）。
 *
 * ★★ 为什么是自管道而不是「共享标志位 + 轮询」（见 core/io_pool.h 的不变量 ②）：
 *   UI 线程的 `php_drift_x11_wait()` 本就用 select() 阻塞在 X connection fd 上，
 *   自管道让「被 IO 唤醒」变成 select 的第二个可读 fd —— 池体只管往写端写 1 字节，
 *   UI 线程醒来后 drain 掉、吐 `type=wake`。没有忙轮询，也没有丢唤醒。
 *
 * ★ 与 win32/io_wake 的分工：pool 体（线程/队列/JSON）是同一份 core/io_pool.cc，
 *   本文件只有「怎么叫醒 UI」这一段平台相关代码（与 X11 无关，换 Wayland 也能复用）。
 *   本文件**零依赖 core/io_pool**——由 drift_x11.cc 把两者粘起来（见 php_drift_io_start）。
 *   ⇒ 它可以和 drift_x11.cc 平铺进同一个 source 目录，相对 include 只需同目录的 io_wake.h。
 *
 * ★ 唤醒优先级：真 X 事件 > 唤醒（与 Win32 的「真事件 > 唤醒」同序）。
 *   `ioWakeDrain()` 在 wait() 每次开头调用一次，消化「上一次被真事件抢先返回」时
 *   留在管道里的字节 —— 否则那条唤醒会挂到下一条真事件来才被消费。
 */

#ifndef DRIFT_X11_IO_WAKE_H
#define DRIFT_X11_IO_WAKE_H

namespace drift {
namespace x11 {

/**
 * 建自管道并置非阻塞。**幂等**（重复调用只返回 true）。
 * @return false = pipe/fcntl 失败（池不该起：起了也叫不醒 UI）
 */
bool startIoPipe();

/** select 用的读端 fd；`-1` = 未启动 */
int ioWakeReadFd();

/** worker 线程侧：写 1 字节（非阻塞；管道满说明已有未消费唤醒，丢弃无害） */
void ioWakeNotify();

/** UI 线程侧：读空管道，返回本次是否读到过字节（一次消费） */
bool ioWakeDrain();

/** 关管道（幂等）。★ 必须在 io::stop() join 完 worker 之后调 */
void stopIoPipe();

}   // namespace x11
}   // namespace drift

#endif   // DRIFT_X11_IO_WAKE_H
