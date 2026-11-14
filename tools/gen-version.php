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
 *   --check 只比对不写盘，供 bin/drift verify 与人工核对。
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
// 类型化类常量已过整树 codegen（511 php → 451 cc / 0 skip），与 src/ 其余常量同口径；
// 改模板要同步 tools/version.php 的取值正则。
$class = <<<'PHP'
<?php
declare(strict_types=1);

namespace Drift\Core;

/**
 * 框架版本常量。**由 tools/gen-version.php 从根目录 VERSION / DEPS_VERSION 生成，勿手改**。
 *
 * 真相源是那两个纯文本文件（Chromium chrome/VERSION、Flutter engine.version 的形态）：
 * shell / CI 能直接 cat，而运行时代码需要编译期就确定的值 —— AOT 产物里没有仓库根
 * 目录可以读，所以这里存一份派生副本，由 bin/drift verify 保证它不漂。
 *
 * @api
 */
final class Version
{
    public const string VERSION = '%s';
    public const string BASE = '%s';
    public const int PREVIEW = %d;
    public const string TAG = 'v%s';
    public const string DEPS_TAG = '%s';

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
// bench 是量具、不进产品 PE 资源，只有 version:；其余应用三处版本必须齐备。
// 只比对替换后文本会发现不了「字段根本不存在」，所以这里显式数一遍。
$peOptional = ['bench'];
foreach ($apps as $yml) {
    $text = (string) file_get_contents($yml);
    if (!in_array(basename(dirname($yml)), $peOptional, true)) {
        foreach (['version', 'file-version', 'product-version'] as $field) {
            if (preg_match('/^\s*' . preg_quote($field, '/') . ':\s*\S+/m', $text) !== 1) {
                fwrite(STDERR, "✗ {$yml} 缺 {$field}（PE 版本字段）\n");
                $ok = false;
            }
        }
    }
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

if (!$ok) {
    fwrite(STDERR, "✗ 版本派生副本不一致或缺字段，跑：php tools/gen-version.php\n");
    fwrite(STDERR, "  （{$constPath} 与 apps/*/project.yml 都是派生产物）\n");
    exit(1);
}

if ($checkOnly) {
    echo "✓ 版本副本一致（{$version} · {$depsTag}）\n";
    exit(0);
}

printf("版本 : %s（PE %s）· %s\n", $version, $peVersion, $depsTag);
printf("派生 : src/Core/Version.php + %d 份 apps/*/project.yml\n", count($apps));
echo "改版本：改根目录 VERSION（或 DEPS_VERSION）→ 跑本脚本 → 提交所有派生文件。\n";
