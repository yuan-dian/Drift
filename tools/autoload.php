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

// ── 壳函数替身（解释器下没有 C++ 桥）───────────────────────────────
// smoke / preview 用 php.exe 直接跑框架代码，而 `drift_thread_id()` 的真实实现住在
// `cpp-src/win32/drift_win32.cc`（只有 AOT 产物才有）。单线程解释器里"UI 线程"就是当前线程
// ⇒ 给一个固定的非零值即可（`0` 表示"未挂载"，不能拿来顶替）。
if (!function_exists('drift_thread_id')) {
    function drift_thread_id(): int
    {
        return 1;
    }
}
