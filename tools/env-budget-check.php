<?php
declare(strict_types=1);

/**
 * 构建环境的进程级判据：传给 tpc 的环境块必须落在 Windows 上限内。
 *
 * ★ 为什么要有：宿主（IDE / 智能体）注入 `CODEBUDDY_MCP_CONFIG` 这类十几 KB 的
 *   变量，287 项加起来 34KB > 32767 ⇒ CreateProcess 截断 ⇒ PHP 的 curl 扩展
 *   初始化失败，tpc 0.2s 退出并报 `Unable to start curl module`。干净 shell 里
 *   同样的命令却正常 —— 差别只在环境块大小，现象极难指向真因。
 * ★ 两侧都判：该剔的必须剔（否则再超限），不该剔的一个不能少（否则构建缺 PATH/INCLUDE）。
 *
 * 用法：php tools/env-budget-check.php   （exit 0/1）
 */

use Drift\Dev\Doctor\Environment;

require __DIR__ . '/autoload.php';

const ENV_HARD_LIMIT = 32767;

$root = dirname(__DIR__);
$fail = 0;
$check = static function (bool $ok, string $what) use (&$fail): void {
    fwrite(STDOUT, ($ok ? '✓ ' : '✗ ') . $what . "\n");
    if (!$ok) {
        $fail++;
    }
};

$blockSize = static function (array $env): int {
    $size = 1;
    foreach ($env as $name => $value) {
        $size += strlen($name) + 1 + strlen((string) $value) + 1;
    }

    return $size;
};

// ① 真实环境：裁剪后必须在硬上限内
//   ★ 没有构建环境（CI 的非发行版 PHP、缺 PHPX）就明确 SKIP 退出：
//     本判据的前提是 `prepare()` 能跑通，硬判会变成"环境没装好"的红灯，
//     那不是它要守的东西。
try {
    $prepared = Environment::prepare($root, withToolchain: false);
} catch (\Throwable $e) {
    fwrite(STDOUT, 'SKIP 无构建环境：' . $e->getMessage() . "\n");
    exit(0);
}
$realSize = $blockSize($prepared->env);
$check($realSize <= ENV_HARD_LIMIT, sprintf('真实环境块 %d 字节 ≤ %d', $realSize, ENV_HARD_LIMIT));

// ② 反例：注入 20KB 宿主前缀变量，必须被剔除
$big = str_repeat('x', 20000);
putenv('CODEBUDDY_FAKE_BIG=' . $big);
$withHost = Environment::prepare($root, withToolchain: false);
putenv('CODEBUDDY_FAKE_BIG');
$check(!isset($withHost->env['CODEBUDDY_FAKE_BIG']), '20KB 宿主前缀变量被剔除');
$check(
    $blockSize($withHost->env) <= ENV_HARD_LIMIT,
    sprintf('注入宿主变量后仍在上限内（%d 字节）', $blockSize($withHost->env)),
);

// ③ 反例：非宿主前缀的超大变量走体积兜底，也要剔掉
putenv('DRIFT_FAKE_BIG=' . str_repeat('y', 20000));
$withLoose = Environment::prepare($root, withToolchain: false);
putenv('DRIFT_FAKE_BIG');
$check(!isset($withLoose->env['DRIFT_FAKE_BIG']), '非宿主前缀的超大变量走兜底被剔除');
$check(
    $blockSize($withLoose->env) <= ENV_HARD_LIMIT,
    sprintf('兜底后仍在上限内（%d 字节）', $blockSize($withLoose->env)),
);

// ④ 不能裁过头：系统与构建必需项一个不少（大小写不敏感 —— Windows 环境变量如此）
$mustKeep = ['PATH', 'SystemRoot', 'TEMP', 'PHPRC', 'PHP_HOME', 'PHPX_HOME'];
$present = array_flip(array_map('strtoupper', array_keys($prepared->env)));
$missing = array_values(array_filter(
    $mustKeep,
    static fn (string $name): bool => !isset($present[strtoupper($name)]),
));
$check($missing === [], '必需项保留' . ($missing === [] ? '' : '：缺 ' . implode(',', $missing)));

// ⑤ 根因判据：用裁剪后的 env 真起一个 PHP，curl 必须能加载
$out = $root . '/_build/env-check-curl.log';
@unlink($out);
$proc = proc_open(
    [PHP_BINARY, '-r', 'echo extension_loaded("curl") ? "CURL_OK" : "CURL_MISSING";'],
    [0 => ['file', 'nul', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out, 'a']],
    $pipes,
    $root,
    $prepared->env,
    ['bypass_shell' => true],
);
$text = '';
if (is_resource($proc)) {
    proc_close($proc);
    $text = (string) @file_get_contents($out);
}
@unlink($out);
$check(str_contains($text, 'CURL_OK'), '裁剪后的环境能正常加载 curl');
$check(!str_contains($text, 'Unable to start curl'), '无 "Unable to start curl module"');

fwrite(STDOUT, $fail === 0 ? "\n全部通过\n" : "\n{$fail} 条失败\n");
exit($fail === 0 ? 0 : 1);
