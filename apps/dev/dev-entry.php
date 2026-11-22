<?php
declare(strict_types=1);

// 运行期动态入口。改本文件不重编 dev.exe，由 drift-dev.php 重启进程生效。
require getcwd() . '/tools/autoload.php';

$app = getenv('DRIFT_DEV_APP');
if ($app === false || $app === '') {
    $app = 'hello';
}
if (preg_match('/^[A-Za-z0-9_]+$/', $app) !== 1) {
    fwrite(STDERR, "[dev] 非法应用名：{$app}\n");
    return;
}

// 组合根按 PSR-4 找 apps/<app>/bin/win32/Bootstrap.php（见 composer.json）。
$class = 'App\\' . ucfirst(strtolower($app)) . '\\Bootstrap';
if (!class_exists($class)) {
    fwrite(STDERR, "[dev] 找不到组合根 {$class}\n");
    return;
}

(new $class())->run();
