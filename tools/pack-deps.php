<?php
declare(strict_types=1);

/**
 * 把一个平台的 `cpp/skia*` 与 `cpp/fonts` 打成可分发的 zip。
 *
 * deps-v1 只有 win-x64 单包；deps-v2 起每个平台一个资产，共享同一份入库锁。
 * 用它而不是手工压缩：包内容要能复现，README 与 deps.lock.json 的 SHA256 才有意义。
 * 只解决**分发形态**，不解决"第一个包从哪来" —— 见 README「构建环境」档 B ①。
 *
 * 用法：php tools/pack-deps.php [--platform=win-x64|linux-x64] [--out=<zip 路径>]
 */

use Drift\Dev\Deps\Platforms;

$root = dirname(__DIR__);
require __DIR__ . '/version.php';
require_once __DIR__ . '/Deps/Platforms.php';

// 包里必须写明它属于哪个框架版本、该发到哪个 tag：否则 Release 上一堆 zip
// 只能靠上传时间分辨，而 fetch 默认取的那个 tag 也无从推导。
$driftVersion = driftVersion($root);
$depsTag = driftDepsTag($root);
if (preg_match(DRIFT_VERSION_RE, $driftVersion) !== 1
    || preg_match(DRIFT_DEPS_TAG_RE, $depsTag) !== 1) {
    fwrite(STDERR, "VERSION / DEPS_VERSION 格式不对（{$driftVersion} / {$depsTag}），先修再打包。\n");
    fwrite(STDERR, "跑 php tools/version.php --check 看详情。\n");
    exit(1);
}

$platformId = Platforms::current();
$out = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $out = substr($arg, 6);
    } elseif (str_starts_with($arg, '--platform=')) {
        $platformId = substr($arg, 11);
    } else {
        fwrite(STDERR, "未知参数 {$arg}\n用法：php tools/pack-deps.php [--platform=<id>] [--out=<zip>]\n");
        exit(1);
    }
}
try {
    $platform = Platforms::get($platformId);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
if (!$platform['published']) {
    fwrite(STDERR, "平台 {$platformId} 还没有已发布的构建通道；先把产物接进 CI 再改 Platforms.php。\n");
    exit(1);
}
$out ??= $root . '/dist/' . $platform['asset'];
$skiaPrefix = $platform['skiaDir'];

$skia = $root . '/' . $skiaPrefix;
$fonts = $root . '/cpp/fonts';

foreach ([$skia, $fonts] as $dir) {
    if (!is_dir($dir)) {
        fwrite(STDERR, "缺少 {$dir}\n");
        fwrite(STDERR, "外部依赖不入库，需先从有该包的机器拷入（见 README「构建环境」）。\n");
        exit(1);
    }
}

// ── 版本元数据 ──
// 二进制包要能追到源码。unpinned 只留给来源不可考的历史包，CI 重建必须钉 commit。
$skiaRevision = driftSkiaRevision($root);
if (!driftSkiaRevisionValid($skiaRevision)) {
    fwrite(STDERR, "SKIA_REVISION 格式不对（{$skiaRevision}）：应为 40 位 Skia commit 或 unpinned。\n");
    exit(1);
}
$provenance = $skiaRevision === 'unpinned' ? 'legacy' : 'ci';

$milestone = 'unknown';
$milestoneFile = $skia . '/include/core/SkMilestone.h';
if (is_file($milestoneFile)) {
    if (preg_match('/#define\s+SK_MILESTONE\s+(\d+)/', (string) file_get_contents($milestoneFile), $m)) {
        $milestone = $m[1];
    }
}

$buildArgs = [];
$argsFile = $skia . '/out/Release-x64/args.gn';
if (is_file($argsFile)) {
    foreach (file($argsFile, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if (preg_match('/^(is_debug|target_cpu|cc|cxx|skia_[a-z_]+)\s*=/', $line)) {
            $buildArgs[] = $line;
        }
    }
}

// ── 收集文件 ──
/** @var list<array{0: string, 1: string}> [zip 内路径, 绝对路径] */
$entries = [];
foreach ([[$skiaPrefix, $skia], ['cpp/fonts', $fonts]] as [$prefix, $dir]) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $rel = substr($file->getPathname(), strlen($dir) + 1);
        // pdb 是调试符号，构建不需要，体积却很大
        if (str_ends_with(strtolower($rel), '.pdb')) {
            continue;
        }
        // 字体许可由 OFL.txt 统一注入，源目录里的同类文件不重复收。
        // ★ 只限 cpp/fonts —— Skia 源目录里的（如 include/third_party/vulkan/LICENSE）
        //   是包头自带的许可，跳掉就是少给一份该给的文本。
        if ($prefix === 'cpp/fonts' && preg_match('/^(OFL|LICEN[CS]E)/i', basename($rel))) {
            continue;
        }
        // 解包回填会把这些注入项留在源目录里，别再当源文件收一遍（entry 会重复）
        if (($prefix === $skiaPrefix && $rel === 'LICENSE')
            || ($prefix === 'cpp/fonts' && in_array($rel, ['OFL.txt', 'FONT-NOTICE.txt'], true))
        ) {
            continue;
        }
        $entries[] = [$prefix . '/' . str_replace('\\', '/', $rel), $file->getPathname()];
    }
}

// 迭代顺序随文件系统变；排序后才让跨机器重打包的字节一致。
usort($entries, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

$totalBytes = 0;
foreach ($entries as [, $path]) {
    $totalBytes += filesize($path);
}

// ── 许可文本 ──
// 全部是重分发义务（Skia 的 BSD-3 二进制条款、OFL 条件 2、九个静态库各自的声明），
// 放仓库 `licenses/` 而不是 `cpp/`：后者不入库，放那儿换台机器打包就丢了。
// 整目录注入，不在脚本里枚举文件名 —— 新增一个静态库时只补文件，不改这里。
$licensesDir = $root . '/licenses';
$thirdPartyFile = $licensesDir . '/THIRD-PARTY.md';
$oflFile = $licensesDir . '/OFL-NotoSansSC.txt';
$skiaLicenseFile = $licensesDir . '/BSD-3-Clause-Skia.txt';
foreach ([$thirdPartyFile, $oflFile, $skiaLicenseFile] as $lic) {
    if (!is_file($lic)) {
        fwrite(STDERR, "缺少 {$lic}\n");
        exit(1);
    }
}
$licenseFiles = glob($licensesDir . '/*.txt') ?: [];
sort($licenseFiles);
if (count($licenseFiles) < 3) {
    fwrite(STDERR, "licenses/ 下只有 " . count($licenseFiles) . " 份许可，明显不全\n");
    exit(1);
}

$fontNotice = <<<TXT
Noto Sans SC —— 字体来源与许可
============================

字体文件：NotoSansSC-Regular.ttf / NotoSansSC-Bold.ttf
许可：SIL Open Font License, Version 1.1
版权：Copyright 2014-2021 Adobe (http://www.adobe.com/),
      with Reserved Font Name 'Source'。Noto 是 Google 的商标。

OFL 1.1 全文：见同目录 OFL.txt

★ 重分发条件 2：随字体提供时必须一并保留上面的版权声明与本许可证。
  本目录已同时提供 OFL.txt，**勿删**。
TXT;

$manifest = "Drift 外部二进制依赖包\n";
$manifest .= "========================\n\n";
$manifest .= "Drift 版本     : {$driftVersion}\n";
$manifest .= "配套 tag       : {$depsTag}\n";
$manifest .= "目标平台       : {$platformId}\n";
$manifest .= "Skia milestone : {$milestone}\n";
$manifest .= 'Skia 源码 rev  : ' . ($skiaRevision === 'unpinned' ? 'unpinned（历史包，来源不可考）' : $skiaRevision) . "\n";
$manifest .= "来源类型       : {$provenance}（legacy = 预编译包拷入；ci = 由 SKIA_REVISION 重建）\n";
$manifest .= 'Skia 构建参数  : ' . ($buildArgs === [] ? '(args.gn 缺失)' : implode(' ', $buildArgs)) . "\n";
$manifest .= '文件数         : ' . count($entries) . "\n";
$manifest .= '解压后大小     : ' . round($totalBytes / 1048576, 1) . " MiB\n";
$manifest .= "包内路径       : {$skiaPrefix}/**、cpp/fonts/**、cpp/licenses/**、cpp/DEPS-MANIFEST.txt\n";
$manifest .= "解压方式       : 解压到仓库根，得到 {$skiaPrefix} 与 cpp/fonts\n";
$manifest .= "\n分发包只含构建所需：Skia 的 include / modules / src / out/Release-x64 静态库\n";
$manifest .= "（*.{$platform['libExt']}）。deps-v2 起两岸已裁掉 pdf / skottie / svg /\n";
$manifest .= "skparagraph / skshaper / icu / perfetto / piex / dng_sdk 等零引用模块。\n";
$manifest .= "\n许可：完整清单与逐份全文在包内 cpp/licenses/（THIRD-PARTY.md 为索引）。\n";
$manifest .= "Skia 为 BSD-3-Clause，Noto Sans SC 为 SIL OFL 1.1，另九个静态库为 MIT /\n";
$manifest .= "FTL / Unicode V3 / IJG+BSD-3 / libpng v2 / BSD-3 / MIT+Apache-2.0 / zlib。\n";
$manifest .= "其中 Skia 与 OFL 另在 {$skiaPrefix}/LICENSE、cpp/fonts/OFL.txt 留了就近副本，\n";
$manifest .= "内容与 cpp/licenses/ 相同。分发时 cpp/licenses/ 整个目录必须随附，勿删。\n";

/**
 * ZIP 的 DOS 时间按 CRT 本地时区写（包内不写 UT extra）：同一个 SOURCE_DATE_EPOCH
 * 在 UTC+8 机器落成 08:00、UTC 机器落成 00:00 ⇒ 跨时区重打包 SHA 不同。
 * putenv('TZ') 与 date_default_timezone_set 都改不了 libzip 的这一行为，
 * 所以写盘后直接把每个条目的 DOS 字段覆盖成固定的 1980-01-01 00:00。
 */
function normalizeZipDosTimes(string $path): void
{
    $b = (string) file_get_contents($path);
    $len = strlen($b);
    $fixed = "\x00\x00\x21\x00"; // DOS time=0、date=1980-01-01

    $i = 0;
    while ($i + 30 <= $len && substr($b, $i, 4) === "PK\x03\x04") {
        for ($j = 0; $j < 4; $j++) {
            $b[$i + 10 + $j] = $fixed[$j];
        }
        $nameLen = unpack('v', substr($b, $i + 26, 2))[1];
        $extraLen = unpack('v', substr($b, $i + 28, 2))[1];
        $compSize = unpack('V', substr($b, $i + 18, 4))[1];
        $i += 30 + $nameLen + $extraLen + $compSize;
    }

    $eocd = strrpos($b, "PK\x05\x06");
    if ($eocd === false) {
        fwrite(STDERR, "ZIP 结构异常：找不到 EOCD\n");
        exit(1);
    }
    $p = unpack('V', substr($b, $eocd + 16, 4))[1];
    $count = unpack('v', substr($b, $eocd + 10, 2))[1];
    for ($k = 0; $k < $count && substr($b, $p, 4) === "PK\x01\x02"; $k++) {
        for ($j = 0; $j < 4; $j++) {
            $b[$p + 12 + $j] = $fixed[$j];
        }
        $nameLen = unpack('v', substr($b, $p + 28, 2))[1];
        $extraLen = unpack('v', substr($b, $p + 30, 2))[1];
        $commentLen = unpack('v', substr($b, $p + 32, 2))[1];
        $p += 46 + $nameLen + $extraLen + $commentLen;
    }

    file_put_contents($path, $b);
}

// ── 写 zip ──
$dir = dirname($out);
if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
    fwrite(STDERR, "无法创建 {$dir}\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "无法创建 {$out}\n");
    exit(1);
}

foreach ($entries as [$name, $path]) {
    $zip->addFile($path, $name);
}
// 包内一切路径都挂在 cpp/ 下：包是解压到仓库根的，顶层放文件会污染工作区（还会盖掉仓库里更新的 licenses/）。
$zip->addFromString('cpp/DEPS-MANIFEST.txt', $manifest);
$zip->addFromString($skiaPrefix . '/LICENSE', (string) file_get_contents($skiaLicenseFile));
$zip->addFromString('cpp/fonts/OFL.txt', (string) file_get_contents($oflFile));
$zip->addFromString('cpp/fonts/FONT-NOTICE.txt', $fontNotice);
$zip->addFromString('cpp/licenses/THIRD-PARTY.md', (string) file_get_contents($thirdPartyFile));
foreach ($licenseFiles as $lic) {
    $zip->addFromString('cpp/licenses/' . basename($lic), (string) file_get_contents($lic));
}
$zip->setArchiveComment("Drift {$driftVersion} · {$depsTag} · {$platformId}: Skia M{$milestone} + Noto Sans SC (OFL)");
$zip->close();
normalizeZipDosTimes($out);

// ── 结果 ──
$size = filesize($out);
$sha = hash_file('sha256', $out);
$generated = 5 + count($licenseFiles);
printf("平台 : %s\n", $platformId);
printf("输出 : %s\n", $out);
printf("大小 : %.1f MiB（原始 %.1f MiB）\n", $size / 1048576, $totalBytes / 1048576);
printf("文件 : %d 个 entry（其中生成 %d：清单 / 许可全文 / 就近副本）\n", count($entries) + $generated, $generated);
printf("SHA256: %s\n", $sha);
echo "\n解压到仓库根：unzip ", basename($out), " -d <仓库根>\n";
echo "或 PowerShell：Expand-Archive ", basename($out), " -DestinationPath <仓库根>\n";

file_put_contents($out . '.sha256', $sha . '  ' . basename($out) . "\n");

$libsFound = glob($skia . '/out/Release-x64/*.' . $platform['libExt']) ?: [];
if (count($libsFound) < $platform['minLibs']) {
    fwrite(STDERR, "✗ {$platformId} 只有 " . count($libsFound) . " 个 *.{$platform['libExt']}，少于 " . $platform['minLibs'] . " —— 产物不完整\n");
    exit(1);
}

// ── 入库的锁文件 ──
// 只发 .sha256 是自证：包和校验值同源上传，被替换时两者一起变，等于没校验。
// 把 sha256 写进入库的 lock 后，换包必须同时改仓库文件，diff 里看得见。
// 不写生成时间：它按天漂，会让「lock 没变就不发版」误判，且没有消费方。
$baseLock = [
    'driftVersion' => $driftVersion,
    'depsTag' => $depsTag,
    'skiaRevision' => $skiaRevision,
    'provenance' => $provenance,
];
$platformLock = [
    'asset' => basename($out),
    'sha256' => $sha,
    'bytes' => $size,
    'entries' => count($entries) + $generated,
    'skiaMilestone' => $milestone,
    'skiaBuildArgs' => $buildArgs,
    'skiaDir' => $skiaPrefix,
    'libExt' => $platform['libExt'],
    'libs' => count($libsFound),
    'fonts' => array_map('basename', glob($fonts . '/*.ttf') ?: []),
    'licenses' => count($licenseFiles),
];

$lockFile = $root . '/deps.lock.json';
if (!Platforms::multiPlatformTag($depsTag)) {
    // deps-v1 已发布且不可变：保持旧锁形状，避免改一个字段就让所有人 fetch 失败。
    $lock = $baseLock + [
        'asset' => $platformLock['asset'],
        'sha256' => $platformLock['sha256'],
        'bytes' => $platformLock['bytes'],
        'entries' => $platformLock['entries'],
        'skiaMilestone' => $platformLock['skiaMilestone'],
        'skiaBuildArgs' => $platformLock['skiaBuildArgs'],
        'libs' => $platformLock['libs'],
        'fonts' => $platformLock['fonts'],
        'licenses' => $platformLock['licenses'],
    ];
} else {
    $prior = json_decode((string) @file_get_contents($lockFile), true);
    $platforms = is_array($prior) && isset($prior['platforms']) && is_array($prior['platforms'])
        ? $prior['platforms']
        : [];
    $platforms[$platformId] = $platformLock;
    ksort($platforms);
    $mirrorId = isset($platforms[Platforms::WIN]) ? Platforms::WIN : (string) array_key_first($platforms);
    $mirror = $platforms[$mirrorId];
    // win-x64 顶层镜像只照顾 README / 旧消费点；平台表才是完整真相源。
    $lock = $baseLock + [
        'schema' => 2,
        'platforms' => $platforms,
        'asset' => $mirror['asset'],
        'sha256' => $mirror['sha256'],
        'bytes' => $mirror['bytes'],
        'entries' => $mirror['entries'],
        'skiaMilestone' => $mirror['skiaMilestone'],
        'skiaBuildArgs' => $mirror['skiaBuildArgs'],
        'libs' => $mirror['libs'],
        'fonts' => $mirror['fonts'],
        'licenses' => $mirror['licenses'],
    ];
}

file_put_contents(
    $lockFile,
    json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);
printf("锁文件: %s（平台 %s）\n", $lockFile, $platformId);
