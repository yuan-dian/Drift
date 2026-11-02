#pragma once

namespace drift {
namespace win32 {

/**
 * 安装**进程级**崩溃捕获（`SetUnhandledExceptionFilter`）。
 *
 * ## 为什么必须有
 *
 * 2026-11-02 真机：`watermark.exe` 在"任务完成"之后静默消失。Windows 只留下一条
 * 事件日志 —— `php8ts.dll+0x24b30`、`0xc0000005`，而那个偏移落在
 * `zend_object_std_dtor` 之后 0x2c0（**对象析构路径**）。没有栈，就没法判断
 * "谁在释放什么"，只能对着模块名猜。
 *
 * 本仓的纪律是"未被消费的分支 = 未实现"；崩溃这条路径此前**一条判据都没有**，
 * 症状就是"用户说崩了，我们只有一条事件日志"。
 *
 * ## 写什么、写到哪
 *
 * `%APPDATA%\Drift\crash-latest.log`（固定名，每次崩溃覆盖 —— 要找最近一次不必
 * 翻目录）+ 同内容的 `crash-<pid>-<tick>.log`（保留现场）。内容：
 *
 * ```text
 * exception=0xC0000005 (access violation) addr=0x7ff... php8ts.dll+0x24b30
 * access=write target=0x0000000000000018
 * thread=1234
 * stack:
 *   #0 php8ts.dll+0x24b30
 *   #1 watermark.exe+0x1a2b3c
 *   ...
 * ```
 *
 * 全部是 **模块名 + RVA** —— 这样即使 ASLR 换了基址、即使换台机器，RVA 仍然可比。
 *
 * ## 红线（过滤器里不能做的事）
 *
 * - **不碰 CRT 堆**：不用 `std::string` / `new` / `malloc`（崩的时候堆可能已经坏了，
 *   再分配可能二次崩、把现场盖掉）。缓冲全是栈上定长数组 + `WriteFile`。
 * - **不重入**：`InterlockedExchange` 守卫；过滤器自己再崩时直接放行给系统。
 * - **不做符号解析**：`dbghelp` 会加载 pdb、分配内存，本机连 cdb 都没有。
 *   RVA + `dumpbin /disasm` 足够定位，见 `docs/REVIEW-2026-11-01.md` §15.4。
 * - **不改进程行为**：返回 `EXCEPTION_EXECUTE_HANDLER`（照常终止），只是**多留一份证据**。
 *   不尝试"继续执行" —— 堆已坏的情况下继续跑只会让下一个崩溃点漂移。
 */
void installCrashHandler();

}  // namespace win32
}  // namespace drift
