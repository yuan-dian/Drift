/**
 * X11 剪贴板纯文本三件套 —— `Drift\Platform\Clipboard` 的 Linux 实现。
 *
 * ## 为什么是 xclip 子进程，而不是桥内自实现 selection owner
 *
 * X11 没有"剪贴板服务"：CLIPBOARD 是一个 selection，内容住在 **owner 客户端**进程里，
 * 读要发 ConvertSelection → 等 SelectionNotify → GetProperty 的异步往返。两个自实现陷阱：
 *
 * ① **同 connection 自死锁**：一个进程可以同时是 owner 和 requestor，而这两半走同一条
 *    X connection。同步 readText() 阻塞在等 SelectionNotify 时，事件泵停摆 ⇒ 自己作为
 *    owner 收不到别人的 SelectionRequest ⇒ 对方等不到数据 ⇒ 谁也不发通知。成熟框架
 *    （Qt / Chromium / crcbl-shell）的解都是 "nothing waits"：在 pump 里交替推进两半，
 *    要求把同步的 Clipboard 端口改成异步回调 —— 那会波及 TextEditingBridge 与整条
 *    编辑链，是完整一个 P1 的量级。
 * ② **owner 退出即丢**：源进程一关，selection 就没了；要靠 SAVE_TARGETS 在退出前交给
 *    clipboard manager，而 headless（Xvfb + fluxbox）根本没有 manager。
 *
 * xclip 把上面两件事都包了：它 fork 一个常驻进程当 owner（源退出内容仍在，等价于一个
 * 极小 clipboard manager），且 owner 在**独立进程**里 —— 我们的 X connection 上永远只有
 * requestor 一半，不会有 SelectionRequest 打回来，故同步 readText 不会自死锁。
 * 于是端口保持同步、接口零改动，异步性全隔离在本 TU 的进程边界内。
 *
 * ## 三条硬纪律（都来自实测 / Chromium 事故）
 *
 * - **显式 `-t UTF8_STRING`**：xclip 未指定 target 时默认按 STRING(Latin-1) 回答，
 *   中文会变问号（WSL Xvfb 实测）。写入和读取两端都显式指定。
 * - **读必须有界超时 + kill**：Chromium crbug 571343363 —— owner 不回 SelectionNotify
 *   （退出 / 格式不匹配 / INCR 卡住）时，一次 paste 永久 hang 并堵死串行请求队列。
 *   这里每次 read 都带 deadline，到点 kill 子进程并回收。
 * - **子进程必须 waitpid 回收**：否则每次 Ctrl+V 留一个僵尸，长跑进程会被僵尸填满。
 *
 * 失败语义：读不到 / 超时 / 无 owner / xclip 缺失 ⇒ 空串（`Clipboard` 契约：空串不是错误）。
 *
 * 本文件不 include phpx：只认 std::string 与 POSIX，UTF-8 直通；php 类型换算在 ABI 层。
 */
#ifndef DRIFT_X11_CLIPBOARD_H
#define DRIFT_X11_CLIPBOARD_H

#include <string>

namespace drift {
namespace x11 {

/** 读纯文本；空 / 失败 / 无 owner / 超时 ⇒ 空串（不抛） */
std::string clipboardReadText();

/** 写纯文本（覆盖现有内容）；失败静默（端口契约 writeText(): void 无错误通道） */
void clipboardWriteText(const std::string &text);

/** 剪贴板上有没有纯文本 —— 让"粘贴"能先判可用性 */
bool clipboardHasText();

}   // namespace x11
}   // namespace drift

#endif   // DRIFT_X11_CLIPBOARD_H
