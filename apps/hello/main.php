<?php
declare(strict_types=1);

namespace {

    /**
     * `apps/hello` 的入口（§4.5）。
     *
     * ★ `-m bin` 的**硬要求**：全局唯一 `main()`，且必须在**全局**命名空间
     *   —— 它是 C++ 侧 `mainCRTStartup` 找的那个符号（§16.4：tpc 会加
     *   `/SUBSYSTEM:WINDOWS /ENTRY:mainCRTStartup`）。
     *
     * ★ 前导 `\` 不能省：命名空间内调全局符号必须写全限定名（§16.5 陷阱 3），
     *   少了它 `Bootstrap` 会被解析成 `\App\Hello\Bootstrap`... 而这**恰好**是对的
     *   路径，所以这个坑在这里不咬人 —— 但下一行改成别的全局符号就会咬。
     *   按纪律写死。
     *
     * ★ 入口**只有一行**：真正的装配在 `bin/win32/Bootstrap.php`（组合根，
     *   全应用唯一提到平台实现的地方，§5.1）。入口保持"哑"，是因为
     *   `main.php` 无类型声明、不受 PSR-4 与 lint 的分层检查管（§4.4）。
     */
    function main(): void
    {
        (new \App\Hello\Bootstrap())->run();
    }
}
