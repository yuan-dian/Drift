// 剪贴板纯文本三件套。`user32.lib` 在 `build/base.yml` 的 link-libs 里。
#include "string_conv.h"

#include <phpx.h>
#include <windows.h>
#include <cstring>
#include <cwchar>
#include <string>

using namespace php;   // `String` / `Bool` 是 php::* —— ABI 入口 TU 必须自带

// 剪贴板（§5.1 壳服务）：只回答"有没有文本 / 读出来是什么 / 写进去"。
// 选择态与 Ctrl+C/V/X 的翻译住 PHP 侧。
// ★ ABI 函数必须全局作用域：插进函数体 ⇒ C2601，而 PHP 侧 smoke 的
//   `function_exists` 假实现一点都看不出来（2026-10-21 实测）。

/**
 * 打开剪贴板（带重试）。
 *
 * ★ 为什么必须重试：`OpenClipboard` 在**别的进程**正握着剪贴板时返回 FALSE
 *   （ERROR_ACCESS_DENIED）—— 这是 Windows 的既定行为、不是错误路径。不重试的
 *   后果是"用户刚在别的程序里复制过，这边的 Ctrl+V 就随机失效"，且**不报错**
 *   （G2 反模式：把平台时序噪声当永久失败）。
 */
static bool openClipboardWithRetry(int attempts) {
    for (int i = 0; i < attempts; i++) {
        if (OpenClipboard(NULL)) {
            return true;
        }
        Sleep(2);
    }
    return false;
}

/** 读纯文本；空 / 非文本 / 打不开 ⇒ 空串（端口契约：空串不是错误） */
String php_drift_win_clipboard_read_text() {
    if (!openClipboardWithRetry(8)) {
        return String("");
    }

    std::string out;
    if (IsClipboardFormatAvailable(CF_UNICODETEXT)) {
        HANDLE handle = GetClipboardData(CF_UNICODETEXT);
        if (handle != NULL) {
            const wchar_t *text = (const wchar_t *)GlobalLock(handle);
            if (text != NULL) {
                // CF_UNICODETEXT 保证 NUL 结尾 ⇒ 可以按 wcslen 取长
                out = toUtf8(text, (int)wcslen(text));
                GlobalUnlock(handle);
            }
        }
    }

    CloseClipboard();
    return String(out.c_str());
}

/**
 * 写纯文本（覆盖剪贴板现有内容）。
 *
 * ★ 失败是**静默**的：端口契约是 `writeText(): void`（调用方没有错误通道），
 *   而"写失败"在 Windows 上只剩"剪贴板被别的进程占着"一种，已由重试消掉。
 *   真要吃这个错，先把端口改成返回 `bool` —— 今天没有消费者要它（A15）。
 */
void php_drift_win_clipboard_write_text(String text) {
    if (!openClipboardWithRetry(8)) {
        return;
    }

    std::wstring wide = toWide(text);
    SIZE_T bytes = (SIZE_T)(wide.size() + 1) * sizeof(wchar_t);

    HANDLE handle = GlobalAlloc(GMEM_MOVEABLE, bytes);
    if (handle == NULL) {
        CloseClipboard();
        return;
    }

    memcpy(GlobalLock(handle), wide.c_str(), bytes);
    GlobalUnlock(handle);

    if (SetClipboardData(CF_UNICODETEXT, handle) == NULL) {
        // 系统没接手 ⇒ 块还是我们的，必须自己放（否则每失败一次漏一块全局内存）
        GlobalFree(handle);
    }

    CloseClipboard();
}

/** 剪贴板上有没有纯文本 —— 让"粘贴"能先判可用性再改状态 */
Bool php_drift_win_clipboard_has_text() {
    if (!openClipboardWithRetry(8)) {
        return Bool(false);
    }

    bool has = IsClipboardFormatAvailable(CF_UNICODETEXT) != FALSE;
    CloseClipboard();
    return Bool(has);
}

