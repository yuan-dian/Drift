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
 * 私有仓库必须给 token：`releases/download/...` 那条路对私有资产没有可匿名访问的地址
 * （匿名拿到的是 404，不是 403 —— 看起来像"还没发布"）。有 token 就改走 API 解析
 * asset id，再按 `Accept: application/octet-stream` 下载。
 *
 * 默认取 `DEPS_VERSION` 指定的 tag（版本锚点，不跟随 `latest`）：
 * 代码发版比依赖包勤，latest 很容易指到没有 zip 的那个 release 上去。
 *
 * 用法：
 *   php tools/fetch-deps.php                          # 取 DEPS_VERSION 的 tag
 *   php tools/fetch-deps.php --latest                 # 改用仓库最新 release
 *   php tools/fetch-deps.php --tag=deps-v2            # 临时钉别的版本
 *   GITHUB_TOKEN=ghp_xxx php tools/fetch-deps.php     # 私有仓库
 *   php tools/fetch-deps.php --url=<zip> --force
 *   DRIFT_DEPS_URL=https://mirror.example.com/drift php tools/fetch-deps.php
 */

require __DIR__ . '/deps-common.php';
require __DIR__ . '/version.php';

const ASSET = 'drift-deps-win-x64.zip';

$root = dirname(__DIR__);
$repo = null;
$tag = driftDepsTag($root);
$url = null;
$tokenArg = null;
$force = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--repo=')) {
        $repo = substr($arg, 7);
    } elseif (str_starts_with($arg, '--tag=')) {
        $tag = substr($arg, 6);
    } elseif ($arg === '--latest') {
        $tag = null;
    } elseif (str_starts_with($arg, '--url=')) {
        $url = substr($arg, 6);
    } elseif (str_starts_with($arg, '--token=')) {
        $tokenArg = substr($arg, 8);
    } elseif ($arg === '--force') {
        $force = true;
    } else {
        fwrite(STDERR, "未知参数 {$arg}\n");
        exit(1);
    }
}

/**
 * 一个下载源：zip 地址、鉴权头，以及对应的 `.sha256` 地址。
 *
 * @return array{label:string,url:string,headers:list<string>,shaUrl:?string,shaHeaders:list<string>}
 */
function source(string $label, string $url, array $headers = [], ?string $shaUrl = null, array $shaHeaders = []): array
{
    return [
        'label' => $label,
        'url' => $url,
        'headers' => $headers,
        'shaUrl' => $shaUrl,
        'shaHeaders' => $shaHeaders,
    ];
}

/**
 * GitHub 这一路。有 token 走 API（私有仓库唯一可行），没有就退回匿名直链。
 *
 * @return array{label:string,url:string,headers:list<string>,shaUrl:?string,shaHeaders:list<string>}|null
 */
function githubSource(string $repo, ?string $tag, ?string $token): ?array
{
    if ($token === null) {
        $url = $tag === null
            ? "https://github.com/{$repo}/releases/latest/download/" . ASSET
            : "https://github.com/{$repo}/releases/download/{$tag}/" . ASSET;
        return source('GitHub Release（匿名直链）', $url, [], $url . '.sha256');
    }

    $releaseUrl = $tag === null
        ? depsApiRoot() . "/repos/{$repo}/releases/latest"
        : depsApiRoot() . "/repos/{$repo}/releases/tags/{$tag}";
    $release = depsApiJson($releaseUrl, $token);
    if ($release === null) {
        return null;
    }

    $assets = [];
    foreach ($release['assets'] ?? [] as $a) {
        if (isset($a['name'], $a['id'])) {
            $assets[(string) $a['name']] = (int) $a['id'];
        }
    }
    if (!isset($assets[ASSET])) {
        $shown = $release['tag_name'] ?? ($tag ?? 'latest');
        fwrite(STDERR, "  …… release {$shown} 里没有 " . ASSET . "，现有资产：\n");
        fwrite(STDERR, '       ' . (implode('、', array_keys($assets)) ?: '（空）') . "\n");
        return null;
    }

    $octet = [
        'Authorization: Bearer ' . $token,
        'X-GitHub-Api-Version: 2022-11-28',
        'Accept: application/octet-stream',
    ];
    $shaId = $assets[ASSET . '.sha256'] ?? null;

    return source(
        'GitHub Release（API：' . ($release['tag_name'] ?? ($tag ?? 'latest')) . '）',
        depsApiRoot() . "/repos/{$repo}/releases/assets/{$assets[ASSET]}",
        $octet,
        $shaId === null ? null : depsApiRoot() . "/repos/{$repo}/releases/assets/{$shaId}",
        $octet,
    );
}

/** 取 `.sha256` 资产里的哈希；取不到返回 null（不阻断，但要人眼比对） */
function remoteSha(?string $url, array $headers, string $tmp): ?string
{
    if ($url === null || depsDownload($url, $tmp, $headers, true) === 0) {
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

$token = depsToken($tokenArg);

if ($url === null && $repo === null) {
    $repo = depsRepoFromOrigin();
}

/** @var list<array{label:string,url:string,headers:list<string>,shaUrl:?string,shaHeaders:list<string>}> $sources */
$sources = [];
$githubFailed = false;
if ($url !== null) {
    $sources[] = source('显式地址', $url, [], $url . '.sha256');
} else {
    $env = getenv('DRIFT_DEPS_URL');
    if (is_string($env) && trim($env) !== '') {
        foreach (explode(',', $env) as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $u = str_ends_with(strtolower($raw), '.zip') ? $raw : rtrim($raw, '/') . '/' . ASSET;
            $sources[] = source('镜像', $u, [], $u . '.sha256');
        }
    }
    if ($repo !== null) {
        $gh = githubSource($repo, $tag, $token);
        if ($gh === null) {
            $githubFailed = true;
        } else {
            $sources[] = $gh;
        }
    }
}

if ($sources === []) {
    if ($githubFailed) {
        // 已经知道是 GitHub 这一路出的问题，别再说「没有 origin」——原因上面打过了
        fwrite(STDERR, "GitHub 这一路没拿到资产。\n");
        if ($token === null) {
            fwrite(STDERR, "私有仓库必须给 token（匿名一律 404）：\n");
            fwrite(STDERR, "      GITHUB_TOKEN=ghp_xxx php tools/fetch-deps.php\n");
        } else {
            fwrite(STDERR, "token 有效的话，多半是 release 还没建：\n");
            fwrite(STDERR, "      php tools/release-deps.php --tag=" . ($tag ?? driftDepsTag()) . "\n");
        }
        exit(1);
    }
    fwrite(STDERR, "推不出下载地址：当前 checkout 没有 GitHub 的 origin。\n");
    fwrite(STDERR, "请显式指定：php tools/fetch-deps.php --repo=owner/name\n");
    exit(1);
}

$pending = !is_file($zipPath) || $force;
$used = $sources[0];

if ($pending) {
    // 断点续传只对「上次下到一半」有意义；服务器不认 Range 时会返回 200 并从头写，
    // 那会把旧 .part 和新内容拼在一起，所以看到失败就丢掉重下一次。
    $resumed = is_file($part) && filesize($part) > 0;
    $done = false;
    foreach ($sources as $src) {
        $label = $resumed
            ? sprintf('下载 : %s（从 %d 字节续）', $src['label'], (int) filesize($part))
            : "下载 : {$src['label']}";
        printf("%s\n", $label);
        // 服务器不认 Range 时 curl 会直接报错，而不是返回 200 —— 静默试一次，
        // 失败就丢掉 .part 整段重下，别把"不支持续传"当成网络故障。
        $code = $resumed
            ? depsDownload($src['url'], $part, $src['headers'], true, true)
            : depsDownload($src['url'], $part, $src['headers'], false, false);
        if ($code === 0 && $resumed) {
            echo "       续传失败（服务器可能不支持 Range），整段重下\n";
            @unlink($part);
            $resumed = false;
            $code = depsDownload($src['url'], $part, $src['headers'], false, false);
        }
        if ($code !== 0) {
            $used = $src;
            $done = true;
            break;
        }
        @unlink($part);
    }
    if (!$done) {
        @unlink($part);
        fwrite(STDERR, "所有下载源都失败了。\n");
        if ($token === null) {
            fwrite(STDERR, "提示：私有仓库取不到资产（匿名一律 404），请给 token：\n");
            fwrite(STDERR, "      GITHUB_TOKEN=ghp_xxx php tools/fetch-deps.php\n");
        }
        fwrite(STDERR, "提示：默认取 DEPS_VERSION 指定的 tag（当前 " . driftDepsTag() . "）。\n");
        fwrite(STDERR, "      确认该 tag 已发过 release，或用 --tag=<tag> / --url=<直链> 显式指定。\n");
        exit(1);
    }
} else {
    printf("复用已有 %s（--force 重下）\n", $zipPath);
}

$actual = hash_file('sha256', $pending ? $part : $zipPath);
$remote = remoteSha($used['shaUrl'], $used['shaHeaders'], $dist . '/.sha256.tmp');
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
    $ref = isset($lock['sha256']) ? 'deps.lock.json' : '.sha256';
    fwrite(STDERR, "✗ SHA256 不符（对照 {$ref}），包已损坏或被替换：\n  期望 {$expected}\n  实际 {$actual}\n");
    exit(1);
} else {
    $ref = isset($lock['sha256']) ? 'deps.lock.json' : '远端 .sha256';
    echo "校验 : SHA256 一致（{$ref}）\n";
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
