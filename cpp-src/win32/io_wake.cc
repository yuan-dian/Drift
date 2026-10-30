/**
 * IO 线程池的唤醒通道 —— Win32 实现。见 `io_wake.h` 的拆分理由。
 *
 * ★ 本文件是**整个线程能力里唯一的平台相关部分**（约 60 行）：一个窗口类、一个
 *   窗口过程、一句 `PostMessageW`。池体（`core/io_pool.cc`）一行 Win32 都没有。
 */

#define NOMINMAX

#include "io_wake.h"

#include <windows.h>

#include "../core/io_pool.h"

namespace drift {
namespace win32 {

namespace {

/**
 * 唤醒消息号 —— 用 `WM_APP + 2` 而不是主窗口的 `WM_APP + 1`。
 *
 * ★ 虽然两者发往**不同的窗口**（各自的 WndProc 各认各的），同号也不会串；但
 *   排错时看到日志里两个 `WM_APP+1` 会先怀疑"发错窗"。分开纯粹为了可读。
 */
const UINT kIoWakeMsg = WM_APP + 2;

const wchar_t *kIoWakeClass = L"DriftIoWake";

HWND g_wakeWnd = NULL;

/**
 * 被叫醒过 —— 只在 UI 线程读写（窗口过程与泵都在 UI 线程）⇒ **不需要 atomic**。
 *
 * ★ 这是"池体 → UI"的**唯一**共享状态，而且只有单向的一比特。
 */
bool g_woken = false;

/**
 * 唤醒窗口的消息过程 —— 只认 `kIoWakeMsg`，其余交回默认。
 *
 * ★ 这个窗口是 `HWND_MESSAGE`（消息专用）：不进任务栏、不收输入、不参与
 *   Z 序。它唯一的作用是**让 `GetMessageW` 有东西可取**。
 */
LRESULT CALLBACK wakeProc(HWND hwnd, UINT msg, WPARAM w, LPARAM l)
{
    if (msg == kIoWakeMsg) {
        g_woken = true;
        return 0;
    }

    return DefWindowProcW(hwnd, msg, w, l);
}

/**
 * 装进池体的钩子 —— **在 worker 线程上被调用**。
 *
 * ★ 只做一句 `PostMessageW`：跨线程 Post 是线程安全的（系统内部排队），
 *   不需要任何锁。★ 窗口可能已经销毁（关窗与在途 worker 的竞态）⇒ 判 `NULL`。
 */
void wakeUi(void *)
{
    if (g_wakeWnd != NULL) {
        PostMessageW(g_wakeWnd, kIoWakeMsg, 0, 0);
    }
}

}   // namespace

bool startIoWake()
{
    if (g_wakeWnd == NULL) {
        HINSTANCE inst = GetModuleHandleW(NULL);

        WNDCLASSEXW wc;
        ZeroMemory(&wc, sizeof(wc));
        wc.cbSize = sizeof(wc);
        wc.lpfnWndProc = wakeProc;
        wc.hInstance = inst;
        wc.lpszClassName = kIoWakeClass;
        RegisterClassExW(&wc);   // 重复注册返回 0，无害

        g_wakeWnd = CreateWindowExW(0, kIoWakeClass, L"", 0, 0, 0, 0, 0,
                                    HWND_MESSAGE, NULL, inst, NULL);
        if (g_wakeWnd == NULL) {
            return false;
        }
    }

    g_woken = false;

    // ★ 钩子必须在池起之前装（见 `io_pool.h` 的 `setWakeHook`）。
    io::setWakeHook(wakeUi, nullptr);

    return true;
}

bool consumeWake()
{
    if (!g_woken) {
        return false;
    }

    g_woken = false;

    return true;
}

void stopIoWake()
{
    if (g_wakeWnd != NULL) {
        DestroyWindow(g_wakeWnd);
        g_wakeWnd = NULL;
    }

    io::setWakeHook(nullptr, nullptr);
    g_woken = false;
}

}   // namespace win32
}   // namespace drift
