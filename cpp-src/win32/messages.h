/**
 * 平台自定义消息（`WM_APP + N`）。**主体与 `uia.cc` 共用** —— provider 用它把
 * UIA 的 COM 回调转投到窗口线程，WndProc 是唯一的接收方。
 */
#pragma once

#include <windows.h>

/** 叫醒阻塞在 `GetMessageW` 的泵。`WM_SIZE` / `WM_DPICHANGED` 是同步调进 WndProc 的，
 *  `PeekMessage` 取不到 ⇒ 载荷靠队列、这条消息只负责"有活了"。 */
constexpr UINT kMsgWake = WM_APP + 1;

/**
 * UIA `Invoke` 的转投消息（§9 / M5）。必须回窗口线程落地：`Invoke()` 跑在 COM 的
 * RPC 线程上，回调里重入 PHP 会让进程静默消失（无异常无日志，客户端只见
 * ElementNotAvailable）。到了窗口线程也**不跑 PHP** 只入队 —— 泵的契约是一次调用
 * 交一条事件，跑完 PHP 没人保证下一帧会跑（真机：命令执行了、画面不动）。
 */
constexpr UINT kMsgUiaInvoke = WM_APP + 2;

/** UIA `SetValue` 的转投消息（§4.4）。lParam 只能传整数 ⇒ 字符串用 `new wstring`
 *  传指针，WndProc 取出后 `delete`（所有权一次性转移）。`Toggle()` 不走这条，
 *  它复用 `kMsgUiaInvoke`（换值即激活）。 */
constexpr UINT kMsgUiaSetValue = WM_APP + 3;

/** UIA `Expand` / `Collapse`（§65）。不复用 `kMsgUiaInvoke`：后者落到"节点中心合成
 *  一次 tap"，而树行的 tap 是**选中**不是展开。`wp` = 节点索引，`lp` = 1 展开 / 0 折叠。 */
constexpr UINT kMsgUiaExpand = WM_APP + 4;
