<?php
declare(strict_types=1);

/**
 * 极简 PSR-4 装载器。
 *
 * ★ 直接消费 composer.json 的 autoload.psr-4，不生成 vendor/autoload.php。
 *   理由：composer.json 在本项目的首要身份是「物理边界表」（§4.1）——
 *   装载器与 scan-autoload-drift 读同一份数据，边界就只有一个来源。
 * ★ 工具入口自行注册 autoload，不进 composer autoload（§4.1）。
 */

$root = dirname(__DIR__);
$config = json_decode(
    (string) file_get_contents($root . '/composer.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);

$prefixes = [];
foreach ($config['autoload']['psr-4'] ?? [] as $prefix => $dirs) {
    $prefixes[$prefix] = array_map(
        static fn(string $dir): string => $root . '/' . trim($dir, '/') . '/',
        (array) $dirs
    );
}

// 最长前缀优先：Drift\Dev\ 必须排在 Drift\ 之前
uksort($prefixes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

spl_autoload_register(static function (string $class) use ($prefixes): void {
    foreach ($prefixes as $prefix => $dirs) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $file = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        foreach ($dirs as $dir) {
            if (is_file($dir . $file)) {
                require $dir . $file;
                return;
            }
        }
    }
});
