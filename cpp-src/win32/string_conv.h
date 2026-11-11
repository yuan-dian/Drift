#ifndef DRIFT_WIN32_STRING_CONV_H
#define DRIFT_WIN32_STRING_CONV_H

#include <phpx.h>
#include <string>

// 跨 TU 共用：勿放回某个 TU 的匿名 namespace（类型会变成另一种）。
// ★ 全限定 `php::String`：本头在主体 `using namespace php;` 之前被包含。
std::wstring toWide(const php::String &text);
std::string toUtf8(const wchar_t *text, int length);

#endif
