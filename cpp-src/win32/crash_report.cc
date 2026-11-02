#include "crash_report.h"

#include <windows.h>

namespace drift {
namespace win32 {

namespace {

/** 过滤器重入守卫：`1` = 已经在写日志（或写日志时自己又崩了）。 */
volatile LONG g_busy = 0;

/** 栈回溯深度上限。64 帧对"业务 → 框架 → 运行时"三层足够，且栈上数组只有 3KB。 */
const int kMaxFrames = 64;

/** 输出缓冲上限。一条崩溃日志撑死几十行，8KB 够；定长是为了不碰堆。 */
const int kBufSize = 8192;

char *putStr(char *p, char *end, const char *s)
{
    while (*s != '\0' && p < end) {
        *p++ = *s++;
    }
    return p;
}

char *putHex(char *p, char *end, unsigned long long v)
{
    p = putStr(p, end, "0x");
    char tmp[17];
    int n = 0;
    do {
        const int d = (int)(v & 0xFULL);
        tmp[n++] = (char)(d < 10 ? '0' + d : 'a' + (d - 10));
        v >>= 4;
    } while (v != 0 && n < 16);
    while (n > 0 && p < end) {
        *p++ = tmp[--n];
    }
    return p;
}

char *putDec(char *p, char *end, unsigned long long v)
{
    char tmp[21];
    int n = 0;
    do {
        tmp[n++] = (char)('0' + (int)(v % 10ULL));
        v /= 10ULL;
    } while (v != 0 && n < 20);
    while (n > 0 && p < end) {
        *p++ = tmp[--n];
    }
    return p;
}

/** 两位数补零（时间戳用） */
char *put2(char *p, char *end, unsigned int v)
{
    if (p < end) {
        *p++ = (char)('0' + (v / 10u) % 10u);
    }
    if (p < end) {
        *p++ = (char)('0' + v % 10u);
    }
    return p;
}

/**
 * 把一个地址写成 `模块名+0xRVA`。
 *
 * ★ **RVA 而不是绝对地址**：ASLR 每次运行都换基址、换台机器更不一样。RVA 在
 *   同一份 exe/dll 上是稳定的，可以拿 `dumpbin /disasm` 或导出表直接对上。
 */
void describeAddress(char *out, size_t cap, const void *addr)
{
    if (cap == 0) {
        return;
    }
    out[0] = '\0';
    char *p = out;
    char *end = out + cap - 1;

    if (addr == NULL) {
        putStr(p, end, "<null>");
        out[cap - 1] = '\0';
        return;
    }

    HMODULE mod = NULL;
    if (!::GetModuleHandleExW(
            GET_MODULE_HANDLE_EX_FLAG_FROM_ADDRESS | GET_MODULE_HANDLE_EX_FLAG_UNCHANGED_REFCOUNT,
            reinterpret_cast<LPCWSTR>(addr),
            &mod) ||
        mod == NULL) {
        p = putHex(p, end, (unsigned long long)(ULONG_PTR)addr);
        p = putStr(p, end, "<no-module>");
        *p = '\0';
        return;
    }

    WCHAR wpath[512];
    const DWORD n = ::GetModuleFileNameW(mod, wpath, 512);
    const WCHAR *base = wpath;
    for (DWORD i = 0; i < n; ++i) {
        if (wpath[i] == L'\\' || wpath[i] == L'/') {
            base = wpath + i + 1;
        }
    }

    char name[160];
    name[0] = '\0';
    ::WideCharToMultiByte(CP_UTF8, 0, base, -1, name, (int)sizeof(name), NULL, NULL);
    p = putStr(p, end, name);
    p = putStr(p, end, "+");
    p = putHex(p, end, (unsigned long long)((const char *)addr - (const char *)mod));
    *p = '\0';
}

const char *exceptionName(DWORD code)
{
    switch (code) {
    case EXCEPTION_ACCESS_VIOLATION:
        return "access violation";
    case EXCEPTION_ARRAY_BOUNDS_EXCEEDED:
        return "array bounds exceeded";
    case EXCEPTION_DATATYPE_MISALIGNMENT:
        return "datatype misalignment";
    case EXCEPTION_FLT_DIVIDE_BY_ZERO:
        return "float divide by zero";
    case EXCEPTION_ILLEGAL_INSTRUCTION:
        return "illegal instruction";
    case EXCEPTION_INT_DIVIDE_BY_ZERO:
        return "integer divide by zero";
    case EXCEPTION_STACK_OVERFLOW:
        return "stack overflow";
    case EXCEPTION_IN_PAGE_ERROR:
        return "in page error";
    case EXCEPTION_GUARD_PAGE:
        return "guard page";
    default:
        return "unknown";
    }
}

/** 拼 `dir\name`；`dir` 末尾不能有斜杠。返回 false 表示名字太长。 */
bool joinPath(WCHAR *out, size_t cap, const WCHAR *dir, const WCHAR *name)
{
    size_t i = 0;
    for (; dir[i] != L'\0' && i + 1 < cap; ++i) {
        out[i] = dir[i];
    }
    if (i + 1 >= cap) {
        return false;
    }
    out[i++] = L'\\';
    for (size_t j = 0; name[j] != L'\0'; ++j) {
        if (i + 1 >= cap) {
            return false;
        }
        out[i++] = name[j];
    }
    out[i] = L'\0';
    return true;
}

/** 覆盖写一个文件。失败静默（崩溃路径上无处可报）。 */
void writeAll(const WCHAR *path, const char *text, DWORD len)
{
    HANDLE h = ::CreateFileW(
        path,
        GENERIC_WRITE,
        FILE_SHARE_READ,   // 允许别人读（写的时候我在看）
        NULL,
        CREATE_ALWAYS,
        FILE_ATTRIBUTE_NORMAL,
        NULL);
    if (h == INVALID_HANDLE_VALUE) {
        return;
    }
    DWORD written = 0;
    ::WriteFile(h, text, len, &written, NULL);
    ::CloseHandle(h);
}

LONG WINAPI crashFilter(EXCEPTION_POINTERS *ep)
{
    // 重入 / 二次崩溃：直接放行，别把现场盖掉。
    if (::InterlockedExchange(&g_busy, 1) != 0) {
        return EXCEPTION_EXECUTE_HANDLER;
    }

    char buf[kBufSize];
    char *end = buf + kBufSize - 1;
    char *p = buf;

    EXCEPTION_RECORD *rec = (ep != NULL) ? ep->ExceptionRecord : NULL;
    const DWORD code = (rec != NULL) ? rec->ExceptionCode : 0u;
    const void *addr = (rec != NULL) ? rec->ExceptionAddress : NULL;

    // ── 头：时间 / pid / 异常码 / 地址 ──
    SYSTEMTIME st;
    ::GetLocalTime(&st);
    p = putStr(p, end, "time=");
    p = putDec(p, end, st.wYear);
    p = putStr(p, end, "-");
    p = put2(p, end, st.wMonth);
    p = putStr(p, end, "-");
    p = put2(p, end, st.wDay);
    p = putStr(p, end, " ");
    p = put2(p, end, st.wHour);
    p = putStr(p, end, ":");
    p = put2(p, end, st.wMinute);
    p = putStr(p, end, ":");
    p = put2(p, end, st.wSecond);
    p = putStr(p, end, "\npid=");
    p = putDec(p, end, (unsigned long long)::GetCurrentProcessId());
    p = putStr(p, end, " thread=");
    p = putDec(p, end, (unsigned long long)::GetCurrentThreadId());

    p = putStr(p, end, "\nexception=");
    p = putHex(p, end, (unsigned long long)code);
    p = putStr(p, end, " (");
    p = putStr(p, end, exceptionName(code));
    p = putStr(p, end, ") addr=");
    {
        char line[320];
        describeAddress(line, sizeof(line), addr);
        p = putStr(p, end, line);
    }

    // ── 访问冲突的两条附加信息：读还是写、目标地址 ──
    if (rec != NULL && code == EXCEPTION_ACCESS_VIOLATION && rec->NumberParameters >= 2) {
        const ULONG_PTR kind = rec->ExceptionInformation[0];
        p = putStr(p, end, "\naccess=");
        p = putStr(p, end, kind == 0 ? "read" : (kind == 1 ? "write" : (kind == 8 ? "execute(DEP)" : "?")));
        p = putStr(p, end, " target=");
        p = putHex(p, end, (unsigned long long)rec->ExceptionInformation[1]);
    }

    // ── 栈：模块 + RVA（不解析符号，本机没有 dbghelp 链） ──
    //
    // ★ 前几帧是 **ntdll 的异常分发链**（`KiUserExceptionDispatcher` →
    //   `RtlDispatchException` → … → 本过滤器），固定噪声，读日志时直接跳过。
    //   真正的崩点由**与 `addr` 同一地址**的那一帧标出（下面打 `<== 崩点`），
    //   不必数帧号 —— 同一份日志里"addr=" 与"#5"必须能对上，对不上就说明
    //   `CaptureStackBackTrace` 的 skip 数和实际栈形状不符（这是要能看出来的）。
    p = putStr(p, end, "\nstack:\n");
    void *frames[kMaxFrames];
    // ★ 用 **kernel32 的 `CaptureStackBackTrace`**（它对 ntdll 导出的转发），而不是
    //   `RtlCaptureStackBackTrace`：后者要显式链 `ntdll.lib`，而本仓的链接行只有
    //   MSVC 默认库 + Skia + windowscodecs（见 `build/base.yml`）—— 少一个库就是
    //   LNK2019，为了拿栈去动链接配置不值得。
    //   `2` = 跳过 `crashFilter` 与 kernel32 的捕获桩。
    const USHORT n = (USHORT)::CaptureStackBackTrace(2, (DWORD)kMaxFrames, frames, NULL);
    for (USHORT i = 0; i < n && p < end; ++i) {
        char line[320];
        describeAddress(line, sizeof(line), frames[i]);
        p = putStr(p, end, "  #");
        p = putDec(p, end, (unsigned long long)i);
        p = putStr(p, end, " ");
        p = putStr(p, end, line);
        if (frames[i] == addr) {
            // ★ 标记只用 ASCII：本文件是 UTF-8，而 MSVC 对**窄字符串字面量**按当前
            //   代码页解释（无 BOM 时是 ACP）⇒ 中文会写成 GBK 字节，日志在某些
            //   查看器里变乱码。崩溃日志是要被各种工具读的，别赌编码。
            p = putStr(p, end, "   <== crash point");
        }
        p = putStr(p, end, "\n");
    }
    if (n == 0) {
        p = putStr(p, end, "  <no frames captured>\n");
    }
    p = putStr(p, end, "\n");
    *p = '\0';
    const DWORD len = (DWORD)(p - buf);

    // ── 落盘：固定名（找最近一次）+ 带 pid 的（留现场） ──
    WCHAR dir[400];
    const DWORD dn = ::GetEnvironmentVariableW(L"APPDATA", dir, 400);
    if (dn > 0 && dn < 400) {
        WCHAR sub[440];
        if (joinPath(sub, 440, dir, L"Drift")) {
            ::CreateDirectoryW(sub, NULL);   // 已存在时返回 ERROR_ALREADY_EXISTS，忽略
            WCHAR path[520];
            if (joinPath(path, 520, sub, L"crash-latest.log")) {
                writeAll(path, buf, len);
            }
            // crash-<pid>-<tick>.log
            WCHAR name[64];
            WCHAR *q = name;
            const WCHAR *prefix = L"crash-";
            for (int i = 0; prefix[i] != L'\0'; ++i) {
                *q++ = prefix[i];
            }
            {
                // pid 十进制
                WCHAR tmp[21];
                int k = 0;
                DWORD v = (DWORD)::GetCurrentProcessId();
                do {
                    tmp[k++] = (WCHAR)(L'0' + (v % 10u));
                    v /= 10u;
                } while (v != 0 && k < 20);
                while (k > 0) {
                    *q++ = tmp[--k];
                }
            }
            *q++ = L'-';
            {
                WCHAR tmp[24];
                int k = 0;
                ULONGLONG v = (ULONGLONG)st.wHour * 10000000ULL + (ULONGLONG)st.wMinute * 100000ULL +
                              (ULONGLONG)st.wSecond * 1000ULL + st.wMilliseconds;
                do {
                    tmp[k++] = (WCHAR)(L'0' + (int)(v % 10ULL));
                    v /= 10ULL;
                } while (v != 0 && k < 23);
                while (k > 0) {
                    *q++ = tmp[--k];
                }
            }
            for (int i = 0; L".log"[i] != L'\0'; ++i) {
                *q++ = L".log"[i];
            }
            *q = L'\0';
            if (joinPath(path, 520, sub, name)) {
                writeAll(path, buf, len);
            }
        }
    }

    // ★ 返回 EXECUTE_HANDLER ⇒ 进程照常终止（**不**尝试继续执行）。
    //   堆可能已经坏了，继续跑只会让下一个崩溃点漂移，把现场搞乱。
    return EXCEPTION_EXECUTE_HANDLER;
}

}  // namespace

void installCrashHandler()
{
    ::SetUnhandledExceptionFilter(crashFilter);
}

}  // namespace win32
}  // namespace drift
