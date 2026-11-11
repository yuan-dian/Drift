// UTF-8（PHP 侧）↔ UTF-16（Win32 W 系列）。空输入 / 转换失败都返回空串。
#include "string_conv.h"

#include <windows.h>

/** UTF-8（PHP 侧）→ UTF-16（Win32 W 系列 API） */
std::wstring toWide(const php::String &text) {
    size_t length = text.length();
    if (length == 0) {
        return std::wstring();
    }

    int needed = MultiByteToWideChar(CP_UTF8, 0, text.data(), (int)length, NULL, 0);
    if (needed <= 0) {
        return std::wstring();
    }

    std::wstring out;
    out.resize((size_t)needed);
    MultiByteToWideChar(CP_UTF8, 0, text.data(), (int)length, &out[0], needed);
    return out;
}

/** UTF-16 → UTF-8 */
std::string toUtf8(const wchar_t *text, int length) {
    if (text == NULL || length <= 0) {
        return std::string();
    }

    int needed = WideCharToMultiByte(CP_UTF8, 0, text, length, NULL, 0, NULL, NULL);
    if (needed <= 0) {
        return std::string();
    }

    std::string out;
    out.resize((size_t)needed);
    WideCharToMultiByte(CP_UTF8, 0, text, length, &out[0], needed, NULL, NULL);
    return out;
}
