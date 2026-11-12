<?php
declare(strict_types=1);

/**
 * 从 VERSION / DEPS_VERSION 派生所有版本副本，让「改版本」只需动一个文件。
 *
 * TypePHP 上游的 bump commit（7136faf）一次手改 4 行：project.yml 的 version /
 * file-version / product-version + Translator.php 的 VERSION 常量。本仓 8 个
 * app 的 project.yml 也有同样三处，注释里明写「两处真相，改版本时一起改」。
 * 手抄迟早漏一处，且漏了没人知道 —— 所以这里生成，version.php --check 判据。
 *
 * 用法：php tools/gen-version.php [--check]
 *   --check 只比对不写盘，供 gate 与人工核对。
 */

require __DIR__ . '/version.php';

$root = dirname(__DIR__);
$version = driftVersion($root);
$depsTag = driftDepsTag($root);
if (preg_match(DRIFT_VERSION_RE, $version) !== 1
    || preg_match(DRIFT_DEPS_TAG_RE, $depsTag) !== 1) {
    fwrite(STDERR, "VERSION / DEPS_VERSION 格式不对（{$version} / {$depsTag}）\n");
    exit(1);
}
preg_match('/-preview\.(\d+)$/', $version, $m);
$preview = (int) $m[1];
$base = substr($version, 0, -strlen('-preview.' . $preview));
$checkOnly = in_array('--check', array_slice($argv, 1), true);

// PE 资源版本保持三段数字：tpc 现在能构出来，换成带预览号的字符串要重跑真机验证，
// 收益（属性页多显示 -preview.1）不值这个风险。完整版本由代码常量承载。
$peVersion = $base;

/**
 * 把生成物写回；值没变就不碰文件，避免每次跑都产生无意义 diff。
 * @return bool 当前内容是否已与期望一致
 */
function syncFile(string $path, string $expected, bool $checkOnly): bool
{
    $actual = is_file($path) ? (string) file_get_contents($path) : null;
    if ($actual === $expected) {
        return true;
    }
    if ($checkOnly) {
        return false;
    }
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
        fwrite(STDERR, "无法创建 " . dirname($path) . "\n");
        exit(1);
    }
    file_put_contents($path, $expected);
    printf("生成 : %s\n", $path);
    return true;
}

// ── 代码里的常量 ──
// 不用 `public const string`：src/ 会被 tpc 整树 AOT 编译，类型化类常量在
// 编译器侧没有验证过；仓库现有常量一律无类型，别为版本信息去趟这个雷。
$class = <<<'PHP'
<?php
declare(strict_types=1);

namespace Drift\Core;

/**
 * 框架版本常量。**由 tools/gen-version.php 从根目录 VERSION / DEPS_VERSION 生成，勿手改**。
 *
 * 真相源是那两个纯文本文件（Chromium chrome/VERSION、Flutter engine.version 的形态）：
 * shell / CI 能直接 cat，而运行时代码需要编译期就确定的值 —— AOT 产物里没有仓库根
 * 目录可以读，所以这里存一份派生副本，由 gate 保证它不漂。
 *
 * @api
 */
final class Version
{
    public const VERSION = '%s';
    public const BASE = '%s';
    public const PREVIEW = %d;
    public const TAG = 'v%s';
    public const DEPS_TAG = '%s';

    private function __construct()
    {
    }
}
PHP;
$class = sprintf($class, $version, $base, $preview, $version, $depsTag) . "\n";
$constPath = $root . '/src/Core/Version.php';

$ok = syncFile($constPath, $class, $checkOnly);

// ── 各应用 project.yml 的三处版本 ──
$apps = glob($root . '/apps/*/project.yml') ?: [];
foreach ($apps as $yml) {
    $text = (string) file_get_contents($yml);
    $patched = preg_replace_callback(
        '/^(\s*(?:version|file-version|product-version):\s*)(\S+)(\s*)$/m',
        static fn (array $m): string => $m[1] . $peVersion . $m[3],
        $text
    );
    if ($patched === null) {
        fwrite(STDERR, "正则失败：{$yml}\n");
        exit(1);
    }
    // probes/ 与 _codegen/ 的实验工程不随产品发版，不在这里管。
    if (!syncFile($yml, $patched, $checkOnly)) {
        $ok = false;
    }
}

if ($checkOnly) {
    if (!$ok) {
        fwrite(STDERR, "✗ 版本副本与 VERSION / DEPS_VERSION 不一致，跑：php tools/gen-version.php\n");
        fwrite(STDERR, "  （{$constPath} 与 apps/*/project.yml 都是派生产物）\n");
        exit(1);
    }
    echo "✓ 版本副本一致（{$version} · {$depsTag}）\n";
    exit(0);
}

printf("版本 : %s（PE %s）· %s\n", $version, $peVersion, $depsTag);
printf("派生 : src/Core/Version.php + %d 份 apps/*/project.yml\n", count($apps));
echo "改版本：改根目录 VERSION（或 DEPS_VERSION）→ 跑本脚本 → 提交所有派生文件。\n";
