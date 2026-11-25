#include "../../cpp-src/win32/crash_report.h"

#include <windows.h>

/**
 * 故意制造一次写空指针，验证崩溃过滤器会把 PHP 黑匣子 sidecar 附进日志。
 *
 * 这里只做两件事：装过滤器、崩。任何额外逻辑都可能改变栈形状或掩盖第一现场。
 */
int main()
{
    drift::win32::installCrashHandler();

    volatile int *target = nullptr;
    *target = 1;
    return 0;
}
