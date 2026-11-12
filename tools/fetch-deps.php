<?php
declare(strict_types=1);

/**
 * 从 GitHub Releases 取回 `drift-deps-win-x64.zip`，校验后解压到仓库根。
 *
 * `pack-deps.php` 只能「从已有的 cpp/ 重新打包」，新机器 clone 后一个都没有 ——
 * 这条命令补的就是那一环，否则档 B 只能人工拷 U 盘。
 *
 * 源优先级：`--url` > `DRIFT_DEPS_URL`（逗号分隔，可指镜像/内网）> GitHub release。
 * 校验优先用入库的 `deps.lock.json`；远端 `.sha256` 只作交叉验证，两者不一致就停。
 *
 * 用法：
 *   php tools/fetch-deps.php                          # 用 origin 推 repo，取 latest
 *   php tools/fetch-deps.php --tag=deps-v1
 *   php tools/fetch-deps.php --url=<zip> --force
 *   DRIFT_DEPS_URL=https://mirror.example.com/drift php tools/fetch-deps.php
 */

const ASSET = 'drift-deps-win-x64.zip';

$root = dirname(__DIR__);
$repo = null;
$tag = null;
$url = null;
$force = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--repo=')) {
        $repo = substr($arg, 7);
    } elseif (str_starts_with($arg, '--tag=')) {
        $tag = substr($arg, 6);
    } elseif (str_starts_with($arg, '--url=')) {
        $url = substr($arg, 6);
    } elseif ($arg === '--force') {
        $force = true;
    } else {
        fwrite(STDERR, "未知参数 {$arg}\n");
        exit(1);
    }
}

/**
 * 从 origin 推 owner/repo：仓库一旦托管到 GitHub，这一步就不该再让人手填。
 * 支持 https 与 scp 风格的 ssh 地址。
 */
function repoFromOrigin(): ?string
{
    $out = [];
    $code = 0;
    exec('git config --get remote.origin.url 2>&1', $out, $code);
    if ($code !== 0 || $out === []) {
        return null;
    }
    $remote = trim($out[0]);
    if (preg_match('#github\.com[:/]+([^/]+)/([^/]+?)(?:\.git)?/?$#', $remote, $m)) {
        return $m[1] . '/' . $m[2];
    }
    return null;
}

/**
 * 候选下载地址，按优先级排列。
 * 镜像地址只写到目录即可（`https://m/drift` → `https://m/drift/<asset>`），
 * 也可以直接给带文件名的完整地址。
 *
 * @return list<string>
 */
function candidates(?string $explicit, ?string $repo, ?string $tag): array
{
    if ($explicit !== null) {
        return [$explicit];
    }

    $urls = [];
    $env = getenv('DRIFT_DEPS_URL');
    if (is_string($env) && trim($env) !== '') {
        foreach (explode(',', $env) as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $urls[] = str_ends_with(strtolower($raw), '.zip')
                ? $raw
                : rtrim($raw, '/') . '/' . ASSET;
        }
    }

    if ($repo !== null) {
        $urls[] = $tag === null
            ? "https://github.com/{$repo}/releases/latest/download/" . ASSET
            : "https://github.com/{$repo}/releases/download/{$tag}/" . ASSET;
    }

    return $urls;
}

/**
 * 下载到 $dest。$resume 为真时从已有字节续传。
 *
 * @return int HTTP 状态码；0 表示失败（原因已打印，$quiet 时留给调用方说）
 */
function download(string $url, string $dest, bool $quiet = false, bool $resume = false): int
{
    $offset = $resume && is_file($dest) ? (int) filesize($dest) : 0;
    $fp = $offset > 0 ? fopen($dest, 'r+b') : fopen($dest, 'wb');
    if ($fp === false) {
        fwrite(STDERR, "无法写入 {$dest}\n");
        return 0;
    }
    if ($offset > 0) {
        fseek($fp, $offset);
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 1800,
            CURLOPT_FAILONERROR => true,
        ]);
        if ($offset > 0) {
            // 本机 PHP 8.5 的 curl 常量表里没有 _LARGE 变体；包远小于 2 GB，int 版够用
            if (defined('CURLOPT_RESUME_FROM_LARGE')) {
                curl_setopt($ch, CURLOPT_RESUME_FROM_LARGE, $offset);
            } else {
                curl_setopt($ch, CURLOPT_RESUME_FROM, $offset);
            }
        }
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if ($ok === false || $code >= 400) {
            if (!$quiet) {
                fwrite(STDERR, "下载失败（HTTP {$code}）：{$err}\n{$url}\n");
            }
            return 0;
        }
        return $code;
    }

    $ctx = stream_context_create(['http' => ['follow_location' => 1, 'timeout' => 1800]]);
    $in = @fopen($url, 'rb', false, $ctx);
    if ($in === false) {
        fclose($fp);
        if (!$quiet) {
            fwrite(STDERR, "下载失败：{$url}\n");
        }
        return 0;
    }
    stream_copy_to_stream($in, $fp);
    fclose($in);
    fclose($fp);
    return 200;
}

/** 取 `.sha256` 资产里的哈希；取不到返回 null（不阻断，但要人眼比对） */
function remoteSha(string $url, string $tmp): ?string
{
    if (download($url . '.sha256', $tmp, true) === 0) {
        @unlink($tmp);
        return null;
    }
    $raw = trim((string) file_get_contents($tmp));
    @unlink($tmp);
    if (preg_match('/\b([0-9a-f]{64})\b/i', $raw, $m)) {
        return strtolower($m[1]);
    }
    return null;
}

$dist = $root . '/dist';
$zipPath = $dist . '/' . ASSET;
$part = $zipPath . '.part';
$lockPath = $root . '/deps.lock.json';

if (!is_dir($dist) && !mkdir($dist, 0777, true) && !is_dir($dist)) {
    fwrite(STDERR, "无法创建 {$dist}\n");
    exit(1);
}

// lock 是权威校验值：它入库，换包必须同时改仓库文件，diff 里看得见。
$lock = null;
if (is_file($lockPath)) {
    $decoded = json_decode((string) file_get_contents($lockPath), true);
    if (is_array($decoded) && isset($decoded['sha256'])) {
        $lock = $decoded;
    } else {
        fwrite(STDERR, "⚠ deps.lock.json 解析失败，本轮只验远端 .sha256\n");
    }
}

if ($url === null && $repo === null) {
    $repo = repoFromOrigin();
}
$urls = candidates($url, $repo, $tag);
if ($urls === []) {
    fwrite(STDERR, "推不出下载地址：当前 checkout 没有 GitHub 的 origin。\n");
    fwrite(STDERR, "请显式指定：php tools/fetch-deps.php --repo=owner/name\n");
    exit(1);
}

$pending = !is_file($zipPath) || $force;

if ($pending) {
    // 断点续传只对「上次下到一半」有意义；服务器不认 Range 时会返回 200 并从头写，
    // 那会把旧 .part 和新内容拼在一起，所以看到 200 就丢掉重下一次。
    $resumed = is_file($part) && filesize($part) > 0;
    $usedUrl = null;
    foreach ($urls as $candidate) {
        $label = $resumed ? sprintf('下载 : %s（从 %d 字节续）', $candidate, filesize($part)) : "下载 : {$candidate}";
        printf("%s\n", $label);
        // 服务器不认 Range 时 curl 会直接报错，而不是返回 200 —— 静默试一次，
        // 失败就丢掉 .part 整段重下，别把"不支持续传"当成网络故障。
        $code = $resumed
            ? download($candidate, $part, true, true)
            : download($candidate, $part, false, false);
        if ($code === 0 && $resumed) {
            echo "       续传失败（服务器可能不支持 Range），整段重下\n";
            @unlink($part);
            $resumed = false;
            $code = download($candidate, $part, false, false);
        }
        if ($code !== 0) {
            $usedUrl = $candidate;
            break;
        }
        @unlink($part);
    }
    if ($usedUrl === null) {
        @unlink($part);
        fwrite(STDERR, "所有下载源都失败了。\n");
        fwrite(STDERR, "提示：latest 指向的 release 未必带依赖包。若依赖包单独发版（推荐），\n");
        fwrite(STDERR, "      用 --tag=deps-v1 指定；tag 名以 docs/RELEASE-DEPS.md 为准。\n");
        exit(1);
    }
} else {
    printf("复用已有 %s（--force 重下）\n", $zipPath);
    $usedUrl = $urls[0];
}

$actual = hash_file('sha256', $pending ? $part : $zipPath);
$remote = remoteSha($usedUrl, $dist . '/.sha256.tmp');
$expected = $lock['sha256'] ?? $remote;

if ($lock !== null && $remote !== null && !hash_equals((string) $lock['sha256'], $remote)) {
    @unlink($part);
    fwrite(STDERR, "✗ deps.lock.json 与 release 的 .sha256 不一致：\n");
    fwrite(STDERR, "  lock   {$lock['sha256']}\n  release {$remote}\n");
    fwrite(STDERR, "  说明锁文件没跟上新包，或资产被换过。确认后用 pack-deps 重新生成 lock。\n");
    exit(1);
}

if ($expected === null) {
    fwrite(STDERR, "⚠ 既无 deps.lock.json 也取不到 .sha256，无法校验。本地 SHA256：\n{$actual}\n");
    fwrite(STDERR, "  请与 docs/RELEASE-DEPS.md 记录的校验值人工比对后再用。\n");
} elseif (!hash_equals((string) $expected, $actual)) {
    @unlink($part);
    $source = isset($lock['sha256']) ? 'deps.lock.json' : '.sha256';
    fwrite(STDERR, "✗ SHA256 不符（对照 {$source}），包已损坏或被替换：\n  期望 {$expected}\n  实际 {$actual}\n");
    exit(1);
} else {
    $source = isset($lock['sha256']) ? 'deps.lock.json' : '远端 .sha256';
    echo "校验 : SHA256 一致（{$source}）\n";
}

if ($pending && !rename($part, $zipPath)) {
    fwrite(STDERR, "无法把 {$part} 改名为 {$zipPath}\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    fwrite(STDERR, "无法打开 {$zipPath}\n");
    exit(1);
}

// 包里全是 cpp/**，出现跳出仓库根的路径说明包不可信
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = (string) $zip->getNameIndex($i);
    if (str_starts_with($name, '/') || str_contains($name, '..')) {
        fwrite(STDERR, "包内路径可疑，拒绝解压：{$name}\n");
        $zip->close();
        exit(1);
    }
}

printf("解压 : %d 个 entry → %s\n", $zip->numFiles, $root);
if (!$zip->extractTo($root)) {
    fwrite(STDERR, "解压失败\n");
    $zip->close();
    exit(1);
}
$zip->close();

// 解压成功不等于装对了：漏 lib 要到构建期才炸，这里先数一遍
$libs = glob($root . '/cpp/skia/out/Release-x64/*.lib') ?: [];
$ttfs = glob($root . '/cpp/fonts/*.ttf') ?: [];
$lics = glob($root . '/cpp/licenses/*.txt') ?: [];
printf("结果 : %d 个 .lib、%d 个字体、%d 份许可\n", count($libs), count($ttfs), count($lics));

if (count($libs) < 20 || count($ttfs) < 2) {
    fwrite(STDERR, "✗ 解压后内容不全：包不完整，或解压到了错误的仓库根\n");
    exit(1);
}

if ($lock !== null) {
    $miss = [];
    if (isset($lock['libs']) && count($libs) !== (int) $lock['libs']) {
        $miss[] = "lib 期望 {$lock['libs']} 实得 " . count($libs);
    }
    if (isset($lock['licenses']) && count($lics) !== (int) $lock['licenses']) {
        $miss[] = "许可期望 {$lock['licenses']} 实得 " . count($lics);
    }
    if (isset($lock['fonts']) && is_array($lock['fonts']) && count($ttfs) !== count($lock['fonts'])) {
        $miss[] = '字体期望 ' . count($lock['fonts']) . ' 实得 ' . count($ttfs);
    }
    if ($miss !== []) {
        fwrite(STDERR, '✗ 与 deps.lock.json 对不上：' . implode('；', $miss) . "\n");
        exit(1);
    }
    echo "对账 : 与 deps.lock.json 一致（entry {$lock['entries']}）\n";
}

echo "\n下一步：php tools/doctor.php\n";
