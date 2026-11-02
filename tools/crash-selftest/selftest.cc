// 崩溃捕获的**自证**程序（不进 AOT、不进产品代码）。
//
// 为什么单独编而不是塞进 watermark：要证明"过滤器会写"，只能真崩一次。
// 挂在产品入口上就得为验证多构建两次 AOT，且验证代码会留在产品里。
//
// ## 怎么跑（2026-11-02 实测通过）
//
// ```powershell
// # 1. 编（INCLUDE / LIB / PATH 需已按 build/base.yml 那套 MSVC 环境设好）
// cl /nologo /EHsc /std:c++20 /O2 /I cpp-src `
//    /Fe:tools\crash-selftest\selftest.exe /Fo:tools\crash-selftest\ `
//    tools\crash-selftest\selftest.cc cpp-src\win32\crash_report.cc `
//    /link /MAP:tools\crash-selftest\selftest.map
// #    ★ cl 不认裸 `/MAP:路径`，必须 `/link /MAP:` 才落到指定位置。
//
// # 2. 崩（预期 exit 0xC0000005；bash 里看到 139）
// tools\crash-selftest\selftest.exe
//
// # 3. 翻译日志：必须命中 `main`
// php tools/rva2sym.php tools/crash-selftest/selftest.map --log "%APPDATA%\Drift\crash-latest.log"
// ```
//
// ## 判据（三条，缺一条就说明有一环断了）
//
// | # | 期望 | 断在哪一环 |
// |---|---|---|
// | ① | `%APPDATA%\Drift\crash-latest.log` 出现，含 `exception=0xc0000005 (access violation)` | `SetUnhandledExceptionFilter` 没装上 / 装错位置 |
// | ② | `access=write target=0x0`，且栈里有一帧带 `<== crash point` | 过滤器跑起来了但没读到 `ExceptionInformation` / 栈没抓到 |
// | ③ | 拿 `--log` 翻译，崩点那帧翻出 `main` | `/MAP` 没生效，或 `rva2sym` 的 RVA 口径不对 |
//
// ★ ②里"崩点标记"与 `addr=` 必须是**同一个地址**：对不上就说明 `CaptureStackBackTrace`
//   的 skip 数和实际栈形状不符（过滤器里多套了一层），此时栈是**错位的**，
//   比没有栈更危险 —— 会把人引到无关函数上。

#include "win32/crash_report.h"

int main()
{
    drift::win32::installCrashHandler();

    // 写空指针：`volatile` 防止编译器把这次写入优化掉。
    // ★ 选"写"而不是"读"：读空指针在某些优化下会被折叠，而写的目标地址
    //   会原样出现在 `ExceptionInformation[1]`，正好用来验判据 ②。
    volatile int *p = nullptr;
    *p = 42;

    return 0;
}
