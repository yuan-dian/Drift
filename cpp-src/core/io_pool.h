/**
 * 跨平台 IO 线程池（A2 / N5）—— **本文件与 `.cc` 里零 `windows.h`**。
 *
 * ★★ 为什么单独成文件（2026-10-30）：线程池的**池体**（线程 / 互斥量 / 条件变量 /
 *   队列）是纯 C++20，一行 Win32 都没有；只有"**怎么叫醒 UI 线程**"那一句是平台的。
 *   ⇒ 按"能替代就只留一套"拆成两半：
 *     · `core/io_pool.*`   —— 池体 + 一个 `WakeFn` 钩子（本文件）
 *     · `win32/io_wake.*`  —— 钩子的 Win32 实现（`PostMessageW` 到 `HWND_MESSAGE` 窗）
 *   接第二个平台时只写它自己的 `io_wake`（macOS `dispatch_async(main)` / Linux
 *   `g_idle_add` 或"自管道 + select"），**池体一行不改**。
 *
 * ★ 为什么不把钩子做成虚基类：`C++ 侧不引接口`（与 PHP 侧 `TaskHost` 的裁决同源）——
 *   一个函数指针 + 一个 `void*` 就够了，虚表在这里只换来一次间接调用和一堆
 *   `#include` 纠缠。**只有一个实现时，接口是负担**。
 *
 * ★ 三条不变量（违反任一条都是"不报错、只是结果没了"）：
 *   ① **先入完成队列、再叫醒** —— 反过来的话泵可能先被叫醒、`poll()` 取到空数组、
 *      睡回去，那条结果就永远躺在队列里；
 *   ② **唤醒走"钩子"，不走"共享标志位轮询"** —— 池体不知道 UI 是消息泵还是
 *      eventfd，它只负责"叫一声"；
 *   ③ **worker 线程不许回调 PHP**（AOT 红线：零闭包跨边界）⇒ 只能"入队 + 叫醒"，
 *      结果用 JSON 字符串交回 UI 线程。
 *
 * ★ 与进程后端（`ProcessTaskHost`）的分工：本池适合 **IO 密集**（启动 ~50µs、
 *   共享地址空间），进程适合 **CPU 密集或会崩的代码**（隔离，但 spawn 275ms）。
 */

#ifndef DRIFT_CORE_IO_POOL_H
#define DRIFT_CORE_IO_POOL_H

#include <string>

namespace drift {
namespace io {

/** 叫醒 UI 线程的方式 —— 由平台侧安装（`win32/io_wake.cc` 装 `PostMessageW`） */
using WakeFn = void (*)(void *ctx);

/**
 * 安装唤醒钩子（幂等：后装的覆盖先装的）。
 *
 * ★ 必须在 `start()` **之前**装：worker 一跑起来就可能叫醒，那时钩子还是空的
 *   ⇒ 那一条结果要等用户碰鼠标才被看见（"1.2s 就完了、3s 后才变"）。
 */
void setWakeHook(WakeFn fn, void *ctx);

/**
 * 起池（幂等：已经在跑就只回报线程数）。
 *
 * @return 实际线程数（`>= 1`）；`0` = 池起不来，调用方据此**立刻失败**
 */
int start(int threads);

/** 投一条任务（不阻塞：只入队 + 通知）。`false` = 池没起 */
bool submit(int id, const std::string &handler, int intArg, const std::string &strArg);

/**
 * 取走**全部**已完成的结果。
 *
 * @return JSON 数组；空数组是 `"[]"`（**不是空串** —— 调用方据此决定要不要
 *         `json_decode`，两者都判会多一次分支）
 */
std::string poll();

/** 当前活着的 worker 数（探针判据：关窗后必须是 0） */
int threads();

/** 累计执行条数（探针判据：0 丢失 = 这个数 == 提交数） */
long long handled();

/**
 * 关池：置 stop ⇒ 叫醒全部 worker ⇒ 等它们把队列里剩下的活干完。
 *
 * ★ **必须 join**：不 join 就是"进程退出时线程还在跑"，那是崩溃而不是泄漏
 *   （worker 可能在叫醒一个已销毁的窗口）。
 * ★ 幂等（重复调用只回报 0）。
 */
int stop();

}   // namespace io
}   // namespace drift

#endif   // DRIFT_CORE_IO_POOL_H
