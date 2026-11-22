<?php
declare(strict_types=1);

// apps/users/main.php —— 入口（§A.2.4）：一行调组合根，其余全在 App\Users
namespace {
    function main(): void
    {
        (new \App\Users\Bootstrap())->run();
    }
}
