<?php
declare(strict_types=1);

/**
 * 把 `cpp/skia` 与 `cpp/fonts` 打成可分发的 zip。
 *
 * 用它而不是手工压缩：包内容要能复现，README 里的 SHA256 才有意义。
 * 只解决**分发形态**，不解决"第一个包从哪来" —— 见 README「构建环境」档 B ①。
 *
 * 用法：php tools/pack-deps.php [--out=<zip 路径>]
 */

$root = dirname(__DIR__);
require __DIR__ . '/version.php';

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

$out = $root . '/dist/drift-deps-win-x64.zip';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $out = substr($arg, 6);
    }
}

$skia = $root . '/cpp/skia';
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
        if (preg_match('/^(is_debug|target_cpu|cc|cxx|skia_use_icu|skia_enable_ganesh)\s*=/', $line)) {
            $buildArgs[] = $line;
        }
    }
}

// ── 收集文件 ──
/** @var list<array{0: string, 1: string}> [zip 内路径, 绝对路径] */
$entries = [];
foreach ([['cpp/skia', $skia], ['cpp/fonts', $fonts]] as [$prefix, $dir]) {
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
        // ★ 只限 cpp/fonts —— cpp/skia 下的（如 include/third_party/vulkan/LICENSE）
        //   是包头自带的许可，跳掉就是少给一份该给的文本。
        if ($prefix === 'cpp/fonts' && preg_match('/^(OFL|LICEN[CS]E)/i', basename($rel))) {
            continue;
        }
        // 解包回填会把这些注入项留在源目录里，别再当源文件收一遍（entry 会重复）
        if (($prefix === 'cpp/skia' && $rel === 'LICENSE')
            || ($prefix === 'cpp/fonts' && in_array($rel, ['OFL.txt', 'FONT-NOTICE.txt'], true))
        ) {
            continue;
        }
        $entries[] = [$prefix . '/' . str_replace('\\', '/', $rel), $file->getPathname()];
    }
}

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
$manifest .= "Skia milestone : {$milestone}\n";
$manifest .= 'Skia 源码 rev  : ' . ($skiaRevision === 'unpinned' ? 'unpinned（历史包，来源不可考）' : $skiaRevision) . "\n";
$manifest .= "来源类型       : {$provenance}（legacy = 预编译包拷入；ci = 由 SKIA_REVISION 重建）\n";
$manifest .= 'Skia 构建参数  : ' . ($buildArgs === [] ? '(args.gn 缺失)' : implode(' ', $buildArgs)) . "\n";
$manifest .= '文件数         : ' . count($entries) . "\n";
$manifest .= '解压后大小     : ' . round($totalBytes / 1048576, 1) . " MiB\n";
$manifest .= "包内路径       : cpp/skia/**、cpp/fonts/**、cpp/licenses/**、cpp/DEPS-MANIFEST.txt\n";
$manifest .= "解压方式       : 解压到仓库根，得到 cpp/skia 与 cpp/fonts\n";
$manifest .= "\n分发包只含构建所需：Skia 的 include / modules / src / out/Release-x64 全部 .lib\n";
$manifest .= "（含 base.yml 当前未链接的 icu、skottie、svg 等 —— 它们体积不大，但一旦\n";
$manifest .= "构建配置启用就缺不了，删掉省不了多少却会变成 LNK2019）。\n";
$manifest .= "\n许可：完整清单与逐份全文在包内 cpp/licenses/（THIRD-PARTY.md 为索引）。\n";
$manifest .= "Skia 为 BSD-3-Clause，Noto Sans SC 为 SIL OFL 1.1，另九个静态库为 MIT /\n";
$manifest .= "FTL / Unicode V3 / IJG+BSD-3 / libpng v2 / BSD-3 / MIT+Apache-2.0 / zlib。\n";
$manifest .= "其中 Skia 与 OFL 另在 cpp/skia/LICENSE、cpp/fonts/OFL.txt 留了就近副本，\n";
$manifest .= "内容与 cpp/licenses/ 相同。分发时 cpp/licenses/ 整个目录必须随附，勿删。\n";

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
// 包内一切路径都挂在 cpp/ 下：包是解压到仓库根的，顶层放 DEPS-MANIFEST.txt 或
// licenses/ 会直接污染工作区（还会盖掉仓库里更新的 licenses/）。
$zip->addFromString('cpp/DEPS-MANIFEST.txt', $manifest);
$zip->addFromString('cpp/skia/LICENSE', (string) file_get_contents($skiaLicenseFile));
$zip->addFromString('cpp/fonts/OFL.txt', (string) file_get_contents($oflFile));
$zip->addFromString('cpp/fonts/FONT-NOTICE.txt', $fontNotice);
$zip->addFromString('cpp/licenses/THIRD-PARTY.md', (string) file_get_contents($thirdPartyFile));
foreach ($licenseFiles as $lic) {
    $zip->addFromString('cpp/licenses/' . basename($lic), (string) file_get_contents($lic));
}
// ZIP 条目默认带当前时间 ⇒ 同一份 cpp/ 重打包也会得到不同 SHA，README 里的校验值和
// CI「lock 没变就不发版」的判断都会失效。按可复现构建惯例固定到 SOURCE_DATE_EPOCH，
// 缺省 1980-01-01（ZIP DOS 时间戳下限）。
$epoch = getenv('SOURCE_DATE_EPOCH');
$ts = is_string($epoch) && preg_match('/^\d+$/', trim($epoch)) === 1 ? (int) trim($epoch) : 315532800;
for ($i = 0; $i < $zip->numFiles; $i++) {
    $zip->setMtimeIndex($i, $ts);
}
$zip->setArchiveComment("Drift {$driftVersion} · {$depsTag}: Skia M{$milestone} + Noto Sans SC (OFL)");
$zip->close();

// ── 结果 ──
$size = filesize($out);
$sha = hash_file('sha256', $out);
$generated = 5 + count($licenseFiles);
printf("输出 : %s\n", $out);
printf("大小 : %.1f MiB（原始 %.1f MiB）\n", $size / 1048576, $totalBytes / 1048576);
printf("文件 : %d 个 entry（其中生成 %d：清单 / 许可全文 / 就近副本）\n", count($entries) + $generated, $generated);
printf("SHA256: %s\n", $sha);
echo "\n解压到仓库根：unzip ", basename($out), " -d <仓库根>\n";
echo "或 PowerShell：Expand-Archive ", basename($out), " -DestinationPath <仓库根>\n";

file_put_contents($out . '.sha256', $sha . '  ' . basename($out) . "\n");

// ── 入库的锁文件 ──
// 只发 .sha256 是自证：包和校验值同源上传，被替换时两者一起变，等于没校验。
// 把 sha256 写进入库的 lock 后，换包必须同时改仓库文件，diff 里看得见。
$lock = [
    'asset' => basename($out),
    'driftVersion' => $driftVersion,
    'depsTag' => $depsTag,
    'sha256' => $sha,
    'bytes' => $size,
    'entries' => count($entries) + $generated,
    'skiaMilestone' => $milestone,
    'skiaRevision' => $skiaRevision,
    'provenance' => $provenance,
    'skiaBuildArgs' => $buildArgs,
    'libs' => count(glob($skia . '/out/Release-x64/*.lib') ?: []),
    'fonts' => array_map('basename', glob($fonts . '/*.ttf') ?: []),
    'licenses' => count($licenseFiles),
    'generatedAt' => date('Y-m-d'),
];
$lockFile = $root . '/deps.lock.json';
file_put_contents(
    $lockFile,
    json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);
printf("锁文件: %s\n", $lockFile);
