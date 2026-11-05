<?php
declare(strict_types=1);

namespace {

    // 开发态 AOT 壳：唯一进二进制的 PHP 文件。业务代码全部运行期动态加载。
    function main(): void
    {
        $entry = getenv('DRIFT_DEV_ENTRY');
        if ($entry === false || $entry === '') {
            $entry = getcwd() . '/apps/dev/dev-entry.php';
        }

        // 变量路径 require ⇒ 编译器不内联、不解析符号 ⇒ 走内嵌 ZendVM。
        $boot = static function (string $path): void {
            require $path;
        };
        $boot($entry);
    }
}
