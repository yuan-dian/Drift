// 原生文件对话框 / 提示框。`comdlg32.lib` 不在 link-libs ⇒ pragma 必须随本 TU 走。
#include "string_conv.h"

#include <phpx.h>
#include <windows.h>
#include <commdlg.h>
#include <algorithm>
#include <cstdio>
#include <cwchar>
#include <string>
#include <vector>

using namespace php;   // `String` / `Int` / `Bool` 是 php::*

#pragma comment(lib, "comdlg32.lib")

// 原生对话框（§5.1 壳服务 / A5-①）：`Drift\Platform\Dialog` 的 Win32 实现。
// ★★ 四个函数全是模态：系统跑内层消息循环期间 PHP 一帧都跑不到 ⇒ 别放进动画/滚动回调。
// ★ ABI 函数必须全局作用域（C2601 且 PHP smoke 看不出，同 `clipboard.cc`）。
// ★ 文案一律从 PHP 传来（UTF-8 → `toWide`），这里不放中文字符串字面量。

/**
 * `显示名\t通配符`（行间 `\n`）→ `OPENFILENAMEW::lpstrFilter` 要的
 * **双 NUL 结尾**宽字符表：`名\0通配符\0名\0通配符\0\0`。
 *
 * ★ 为什么不在 PHP 侧直接拼双 NUL：NUL 穿过 ABI 之后在调试器里全是一样的点，
 *   出了错看不出来；而 `\t` / `\n` 是**可读、可单测**的中间形态
 *   （`Win32Dialog::filterText()` 有断言），一次转换留在这里。
 */
static std::vector<wchar_t> buildFilterWide(const String &filters) {
    std::vector<wchar_t> out;
    std::string raw(filters.data(), filters.length());

    size_t start = 0;
    while (start < raw.size()) {
        size_t end = raw.find('\n', start);
        if (end == std::string::npos) {
            end = raw.size();
        }

        std::string line = raw.substr(start, end - start);
        if (!line.empty() && line[line.size() - 1] == '\r') {
            line.erase(line.size() - 1);   // 容忍 CRLF：调用方用 heredoc 拼也不会坏
        }

        if (!line.empty()) {
            size_t tab = line.find('\t');
            std::string label = tab == std::string::npos ? line : line.substr(0, tab);
            std::string pattern = tab == std::string::npos ? std::string() : line.substr(tab + 1);

            std::wstring wideLabel = toWide(String(label));
            std::wstring widePattern = toWide(String(pattern));
            out.insert(out.end(), wideLabel.begin(), wideLabel.end());
            out.push_back(L'\0');
            out.insert(out.end(), widePattern.begin(), widePattern.end());
            out.push_back(L'\0');
        }

        if (end == raw.size()) {
            break;
        }
        start = end + 1;
    }

    if (out.empty()) {
        return out;   // 空表 ⇒ 调用方给 NULL（PHP 侧保证至少一行，这里是防御）
    }

    out.push_back(L'\0');   // 双 NUL 的第二个
    return out;
}

/**
 * 打开 / 保存 的公共部分：取消与真失败在这里分开处理。
 *
 * 成功返回**选中的路径表**（空表 = 取消）。`multi` 只对"打开"有意义 ——
 * 保存对话框没有多选（Win32 会忽略 `OFN_ALLOWMULTISELECT`）。
 *
 * ★★ **多选时 `lpstrFile` 里的形状不是"路径列表"**（2026-11-02）：
 *   - 单选：`C:\a\b.png\0\0` —— 整串就是一个完整路径。
 *   - 多选：`C:\a\0b.png\0c.png\0\0` —— **第一段是目录**，后面才是文件名。
 *   把两种形状都当"路径列表"读 ⇒ 多选时拿到一个目录 + 若干裸文件名
 *   （后续 `fopen` 全部失败，而对话框本身"成功"了）。判别依据是
 *   **第一段之后还有没有内容**：有 ⇒ 多选形状。
 */
static std::vector<std::wstring> runFileDialog(Int owner, const String &title, const String &filters,
                                               const String &defaultName, bool save, bool multi) {
    std::vector<wchar_t> filterWide = buildFilterWide(filters);

    // ★ `nMaxFile` 是**字符数**不是字节数；32K 宽字符 ≈ 14K 个汉字路径，够。
    //   ★ 多选时这**一整块**要装下"目录 + 所有文件名"，所以它必须比单选更宽裕：
    //     32K 字符约等于 1000 个 30 字符的文件名，超出部分 Win32 直接**不返回**
    //     （返回值仍是成功，用户会以为漏选了）—— 这是 API 的固有限制，不是我们的
    //     取舍；真要更大就得上 `IFileOpenDialog`（COM），不值得为这一点引进来。
    std::vector<wchar_t> fileBuf(32768, 0);
    std::wstring seed = toWide(defaultName);
    if (!seed.empty() && seed.size() < fileBuf.size()) {
        std::copy(seed.begin(), seed.end(), fileBuf.begin());
    }

    std::wstring titleWide = toWide(title);

    OPENFILENAMEW ofn;
    ZeroMemory(&ofn, sizeof(ofn));
    ofn.lStructSize = sizeof(ofn);
    ofn.hwndOwner = (HWND)(INT_PTR)owner;
    ofn.lpstrFilter = filterWide.empty() ? NULL : filterWide.data();
    ofn.lpstrFile = fileBuf.data();
    ofn.nMaxFile = (DWORD)fileBuf.size();
    ofn.lpstrTitle = titleWide.empty() ? NULL : titleWide.c_str();
    // ★ `OFN_PATHMUSTEXIST`：路径打错时对话框自己拦住，不把无效路径递回上层。
    // ★ `OFN_NOCHANGEDIR`：**不许改进程的工作目录** —— 应用侧有相对路径
    //   （`getcwd()` 那条纪律），读文件之后 cwd 变了会让后面每次读写都错位。
    ofn.Flags = OFN_EXPLORER | OFN_PATHMUSTEXIST | OFN_NOCHANGEDIR;
    ofn.Flags |= save ? OFN_OVERWRITEPROMPT : OFN_FILEMUSTEXIST;
    if (multi && !save) {
        ofn.Flags |= OFN_ALLOWMULTISELECT;
    }

    BOOL ok = save ? GetSaveFileNameW(&ofn) : GetOpenFileNameW(&ofn);
    if (!ok) {
        DWORD code = CommDlgExtendedError();
        if (code != 0) {
            // 端口（`Dialog::openFile(): ?string`）只有"取消 / 成功"两态 ⇒
            // 真失败**不能**装作用户点了取消：壳自己喊出来（§1.2 响亮失败）。
            wchar_t message[128];
            swprintf_s(message, L"file dialog failed: 0x%08lX", (unsigned long)code);
            MessageBoxW((HWND)(INT_PTR)owner, message, L"Drift",
                        MB_OK | MB_ICONERROR | MB_TASKMODAL | MB_SETFOREGROUND);
        }
        return std::vector<std::wstring>();
    }

    std::vector<std::wstring> paths;

    // ★ 形状判别：第一段之后还有内容 ⇒ 多选形状（第一段是目录）。
    //   单选时 `wcslen` 直接到结尾，`rest` 指向结尾 NUL ⇒ 走上面的单元素分支。
    const wchar_t *dir = fileBuf.data();
    size_t dirLen = wcslen(dir);
    const wchar_t *rest = dir + dirLen + 1;

    if (*rest == L'\0') {
        paths.push_back(std::wstring(dir));
        return paths;
    }

    // 多选：`dir` 是目录，`rest` 起是若干以 NUL 分隔的文件名。
    // ★ 目录**可能已带尾分隔符**（根目录 `C:\`）：拼之前先判，否则出 `C:\\a.png`。
    std::wstring base(dir);
    if (!base.empty() && base[base.size() - 1] != L'\\' && base[base.size() - 1] != L'/') {
        base.push_back(L'\\');
    }

    while (*rest != L'\0') {
        paths.push_back(base + rest);
        rest += wcslen(rest) + 1;
    }

    return paths;
}

String php_drift_win_open_file(Int owner, String title, String filters, String defaultName) {
    std::vector<std::wstring> paths = runFileDialog(owner, title, filters, defaultName, false, false);
    if (paths.empty()) {
        return String();
    }

    return String(toUtf8(paths[0].c_str(), (int) paths[0].size()));
}

/**
 * 多选：返回**每行一个 UTF-8 路径**（`\n` 连接）；取消 ⇒ 空串。
 *
 * ★ 用 `\n` 而不是把数组穿过 ABI：桥只认 `int / string / bool` 这些原语
 *   （`Array` 得自己造 `zend_array`，那是另一套出错面）。Windows 路径**不允许**
 *   换行符 ⇒ `\n` 是无歧义分隔符，不需要转义。
 * ★ 空串 = 取消。**单选一个文件时也是一个元素**，不会退化成"带 `\n` 的怪形状"。
 */
String php_drift_win_open_files(Int owner, String title, String filters) {
    std::vector<std::wstring> paths = runFileDialog(owner, title, filters, String(), false, true);

    std::string joined;
    for (size_t i = 0; i < paths.size(); ++i) {
        if (i > 0) {
            joined.push_back('\n');
        }
        joined += toUtf8(paths[i].c_str(), (int) paths[i].size());
    }

    return String(joined.c_str());
}

String php_drift_win_save_file(Int owner, String title, String filters, String defaultName) {
    std::vector<std::wstring> paths = runFileDialog(owner, title, filters, defaultName, true, false);
    if (paths.empty()) {
        return String();
    }

    return String(toUtf8(paths[0].c_str(), (int) paths[0].size()));
}

void php_drift_win_alert(Int owner, String title, String body) {
    std::wstring wideTitle = toWide(title);
    std::wstring wideBody = toWide(body);
    MessageBoxW((HWND)(INT_PTR)owner, wideBody.c_str(), wideTitle.c_str(),
                MB_OK | MB_ICONINFORMATION | MB_TASKMODAL | MB_SETFOREGROUND);
}

Bool php_drift_win_confirm(Int owner, String title, String body) {
    std::wstring wideTitle = toWide(title);
    std::wstring wideBody = toWide(body);
    // ★ `MB_DEFBUTTON2`：默认焦点在"否"。破坏性动作的默认不能是"执行"
    //   （`Dialog::confirm()` 的契约：关掉窗口 ⇒ false）。
    int result = MessageBoxW((HWND)(INT_PTR)owner, wideBody.c_str(), wideTitle.c_str(),
                             MB_YESNO | MB_ICONQUESTION | MB_DEFBUTTON2
                                 | MB_TASKMODAL | MB_SETFOREGROUND);
    return Bool(result == IDYES);
}
