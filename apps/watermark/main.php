<?php
declare(strict_types=1);

// apps/watermark/main.php —— 入口（§A.2.4）：一行调组合根，其余全在 App\Watermark
namespace {
    /**
     * 全局唯一 `main()`（`-m bin` 的硬要求，见 `apps/hello/main.php` 的注释）。
     *
     * ★ 前导 `\` 不能省：命名空间内调全局符号必须写全限定名。
     */
    function main(): void
    {
        (new \App\Watermark\Bootstrap())->run();
    }
}
