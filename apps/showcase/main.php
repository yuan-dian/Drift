<?php
declare(strict_types=1);

namespace {

    /**
     * `apps/showcase` 的入口（§4.5）—— 与 hello / users 逐行同构，只有类名不同。
     *
     * `-m bin` 硬要求：全局唯一 `main()` 且在**全局**命名空间（§16.4）。
     * 前导 `\` 照纪律写死（§16.5 陷阱 3）。
     */
    function main(): void
    {
        (new \App\Showcase\Bootstrap())->run();
    }
}
