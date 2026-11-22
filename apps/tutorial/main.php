<?php
declare(strict_types=1);

namespace {

    /**
     * `apps/tutorial` 的入口 —— **整个应用的第 1 个文件**。
     *
     * ★ 三件事必须一字不差，否则 `-m bin` 构不出窗口程序：
     *   ① 函数名 `main`、**无参数、无返回值**；
     *   ② 必须在**全局**命名空间 —— tpc 会加 `/SUBSYSTEM:WINDOWS
     *      /ENTRY:mainCRTStartup`，C++ 侧找的就是这个符号；
     *   ③ 实例化与调静态方法都要写**全限定名**（`(new \App\Tutorial\Bootstrap())->run()`）。
     *
     * ★ 入口只做转发，不写任何装配 —— 装配在 `bin/win32/Bootstrap.php`
     *   （组合根）。`main.php` 没有类型声明、不受 PSR-4 与 lint 分层管。
     * ★ `run()` 来自 `AppEntry`（进程角色由框架判），入口永远是这一行。
     */
    function main(): void
    {
        (new \App\Tutorial\Bootstrap())->run();
    }
}
