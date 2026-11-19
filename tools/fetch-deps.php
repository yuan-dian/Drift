<?php
declare(strict_types=1);

/**
 * 从 GitHub Releases 取回当前平台的 deps zip，校验后解压到仓库根。
 *
 * deps-v1 只有 win-x64 单包；deps-v2 起按 `Platforms.php` 选当前平台资产。
 * `pack-deps.php` 只能「从已有的 cpp/ 重新打包」，新机器 clone 后一个都没有 ——
 * 这条命令补的就是那一环，否则档 B 只能人工拷 U 盘。
 *
 * 源优先级：`--url` > `DRIFT_DEPS_URL`（逗号分隔，可指镜像/内网）> GitHub release。
 * 校验优先用入库的 `deps.lock.json`；远端 `.sha256` 只作交叉验证，两者不一致就停。
 *
 * token 是可选的：匿名走 `releases/download/...` 直链；给了 token 就改走 API 解析
 * asset id，再按 `Accept: application/octet-stream` 下载。私有 fork / 镜像后面匿名
 * 一律 404（不是 403 —— 看起来像"还没发布"），那种情况必须给 token。
 *
 * 默认取 `DEPS_VERSION` 指定的 tag（版本锚点，不跟随 `latest`）：
 * 代码发版比依赖包勤，latest 很容易指到没有 zip 的那个 release 上去。
 *
 * 用法：
 *   php tools/fetch-deps.php                          # 取 DEPS_VERSION 的 tag
 *   php tools/fetch-deps.php --platform=linux-x64     # 显式指定平台（默认按宿主 OS）
 *   php tools/fetch-deps.php --latest                 # 改用仓库最新 release
 *   php tools/fetch-deps.php --tag=deps-v2            # 临时钉别的版本
 *   GITHUB_TOKEN=ghp_xxx php tools/fetch-deps.php     # 私有 fork / 匿名受限时
 *   php tools/fetch-deps.php --url=<zip> --force
 *   php tools/fetch-deps.php --url=D:/transfer/drift-deps-win-x64.zip
 *                                                     # 离线包（内网 / 访问不了 GitHub 时）
 *   DRIFT_DEPS_URL=https://mirror.example.com/drift php tools/fetch-deps.php
 *   DRIFT_DEPS_URL=D:/transfer php tools/fetch-deps.php   # 本地目录同样认（自动补包名）
 */

use Drift\Dev\Deps\Platforms;
use Drift\Dev\Deps\Source;

require __DIR__ . '/deps-common.php';
require __DIR__ . '/version.php';
// 直接 require 单个文件，不拉 `autoload.php`：本脚本要能「装个 PHP 就跑」（见 deps-common 头注）
require __DIR__ . '/Deps/Source.php';
require_once __DIR__ . '/Deps/Platforms.php';

$root = dirname(__DIR__);
$repo = null;
$tag = driftDepsTag($root);
$url = null;
$tokenArg = null;
$force = false;
$platformId = Platforms::current();

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--repo=')) {
        $repo = substr($arg, 7);
    } elseif (str_starts_with($arg, '--tag=')) {
        $tag = substr($arg, 6);
    } elseif ($arg === '--latest') {
        $tag = null;
    } elseif (str_starts_with($arg, '--platform=')) {
        $platformId = substr($arg, 11);
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
try {
    $platform = Platforms::get($platformId);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
if (!$platform['published']) {
    fwrite(STDERR, "平台 {$platformId} 还没有已发布的资产；先接好构建通道再改 Platforms.php。\n");
    exit(1);
}
$asset = $platform['asset'];

/**
 * 一个下载源：zip 地址、鉴权头，以及对应的 `.sha256` 地址。
 *
 * `local = true` 表示 `url` 是**本机文件路径**：那句「拷过来就行」以前只能写在文档里，
 * 现在是一条命令（离线 / 内网机器走这条）。
 *
 * @return array{label:string,url:string,headers:list<string>,shaUrl:?string,shaHeaders:list<string>,local:bool}
 */
function source(string $label, string $url, array $headers = [], ?string $shaUrl = null, array $shaHeaders = [], bool $local = false): array
{
    return [
        'label' => $label,
        'url' => $url,
        'headers' => $headers,
        'shaUrl' => $shaUrl,
        'shaHeaders' => $shaHeaders,
        'local' => $local,
    ];
}

/**
 * GitHub 这一路。有 token 走 API（私有 fork 唯一可行），没有就退回匿名直链。
 *
 * @return array{label:string,url:string,headers:list<string>,shaUrl:?string,shaHeaders:list<string>,local:bool}|null
 */
function githubSource(string $repo, ?string $tag, ?string $token, string $asset): ?array
{
    if ($token === null) {
        $url = $tag === null
            ? "https://github.com/{$repo}/releases/latest/download/" . $asset
            : "https://github.com/{$repo}/releases/download/{$tag}/" . $asset;
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
    if (!isset($assets[$asset])) {
        $shown = $release['tag_name'] ?? ($tag ?? 'latest');
        fwrite(STDERR, "  …… release {$shown} 里没有 {$asset}，现有资产：\n");
        fwrite(STDERR, '       ' . (implode('、', array_keys($assets)) ?: '（空）') . "\n");
        return null;
    }

    $octet = [
        'Authorization: Bearer ' . $token,
        'X-GitHub-Api-Version: 2022-11-28',
        'Accept: application/octet-stream',
    ];
    $shaId = $assets[$asset . '.sha256'] ?? null;

    return source(
        'GitHub Release（API：' . ($release['tag_name'] ?? ($tag ?? 'latest')) . '）',
        depsApiRoot() . "/repos/{$repo}/releases/assets/{$assets[$asset]}",
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
$zipPath = $dist . '/' . $asset;
$part = $zipPath . '.part';
$lockPath = $root . '/deps.lock.json';

if (!is_dir($dist) && !mkdir($dist, 0777, true) && !is_dir($dist)) {
    fwrite(STDERR, "无法创建 {$dist}\n");
    exit(1);
}

// lock 是权威校验值：它入库，换包必须同时改仓库文件，diff 里看得见。
$lock = null;
$lockPlatform = null;
if (is_file($lockPath)) {
    $decoded = json_decode((string) file_get_contents($lockPath), true);
    if (is_array($decoded)) {
        $lock = $decoded;
        if (isset($decoded['platforms'][$platformId]) && is_array($decoded['platforms'][$platformId])) {
            $lockPlatform = $decoded['platforms'][$platformId];
        } elseif ($platformId === Platforms::WIN && isset($decoded['sha256'])) {
            // deps-v1 的旧锁只有一个 win-x64 条目，形状保持不变。
            $lockPlatform = $decoded;
        } else {
            fwrite(STDERR, "⚠ deps.lock.json 缺平台 {$platformId} 的条目，本轮只验远端 .sha256\n");
        }
    } else {
        fwrite(STDERR, "⚠ deps.lock.json 解析失败，本轮只验远端 .sha256\n");
    }
}

$token = depsToken($tokenArg);

if ($url === null && $repo === null) {
    $repo = depsRepoFromOrigin();
}

/** @var list<array{label:string,url:string,headers:list<string>,shaUrl:?string,shaHeaders:list<string>,local:bool}> $sources */
$sources = [];
$githubFailed = false;
if ($url !== null) {
    $localPath = Source::localPath($url);
    if ($localPath !== null) {
        $sources[] = source('本地文件', $localPath, [], null, [], true);
    } elseif (!Source::isUrl($url)) {
        // 早退：交给 curl 只会得到 `URL rejected: Port number was not a decimal number`
        fwrite(STDERR, "✗ 既不是网址也不是已存在的文件：{$url}\n");
        fwrite(STDERR, "      网址要带协议（https://…）；离线包请给路径（先把 pack-deps 的产物拷过来）。\n");
        exit(1);
    } else {
        $sources[] = source('显式地址', $url, [], $url . '.sha256');
    }
} else {
    $env = getenv('DRIFT_DEPS_URL');
    if (is_string($env) && trim($env) !== '') {
        foreach (explode(',', $env) as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $u = str_ends_with(strtolower($raw), '.zip') ? $raw : rtrim($raw, '/') . '/' . $asset;
            // 目录与直给文件同义：`DRIFT_DEPS_URL=D:/transfer` 会补上包名再判
            $localPath = Source::localPath($u);
            if ($localPath !== null) {
                $sources[] = source('本地文件', $localPath, [], null, [], true);
                continue;
            }
            $sources[] = source('镜像', $u, [], $u . '.sha256');
        }
    }
    if ($repo !== null) {
        $gh = githubSource($repo, $tag, $token, $asset);
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
        fwrite(STDERR, "GitHub 这一路没拿到 {$asset}。多半是 release 还没建、资产还没上传：\n");
        fwrite(STDERR, "      php tools/release-deps.php --tag=" . ($tag ?? driftDepsTag()) . "\n");
        if ($token === null) {
            fwrite(STDERR, "若取的是私有 fork，匿名一律 404，需要给 token：GITHUB_TOKEN=ghp_xxx\n");
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
    // 断点续传只对「上次下到一半」有意义；服务器不认 Range 会回 200 并把完整响应
    // 追加到 .part 上（curl 不报错），deps-common 会按非 206 判失败。
    $resumed = is_file($part) && filesize($part) > 0;
    $done = false;
    foreach ($sources as $src) {
        if ($src['local']) {
            printf("拷贝 : 本地文件 %s\n", $src['url']);
            if (@copy($src['url'], $part)) {
                $used = $src;
                $done = true;
                break;
            }
            fwrite(STDERR, "✗ 拷贝失败：{$src['url']}\n");
            @unlink($part);
            continue;
        }
        $label = $resumed
            ? sprintf('下载 : %s（从 %d 字节续）', $src['label'], (int) filesize($part))
            : "下载 : {$src['label']}";
        printf("%s\n", $label);
        // 失败就丢掉 .part 整段重下，别把「不支持续传」当成网络故障。
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
            fwrite(STDERR, "提示：若是私有 fork，匿名一律 404，需给 token：GITHUB_TOKEN=ghp_xxx\n");
        }
        fwrite(STDERR, "提示：默认取 DEPS_VERSION 指定的 tag（当前 " . driftDepsTag() . "）。\n");
        fwrite(STDERR, "      确认该 tag 已发过 release，或用 --tag=<tag> / --url=<直链> 显式指定。\n");
        fwrite(STDERR, "      离线 / 内网：在已有 cpp/ 的机器上 php tools/pack-deps.php --platform={$platformId}，\n");
        fwrite(STDERR, "      再把 dist/{$asset} 拷过来，用 --url=<那个路径> 安装。\n");
        exit(1);
    }
} else {
    printf("复用已有 %s（--force 重下）\n", $zipPath);
}

$actual = hash_file('sha256', $pending ? $part : $zipPath);
$remote = $used['local']
    ? Source::localSha($used['url'])
    : remoteSha($used['shaUrl'], $used['shaHeaders'], $dist . '/.sha256.tmp');
$expected = $lockPlatform['sha256'] ?? $remote;

if ($lockPlatform !== null && $remote !== null && !hash_equals((string) $lockPlatform['sha256'], $remote)) {
    @unlink($part);
    fwrite(STDERR, "✗ deps.lock.json 与 release 的 .sha256 不一致：\n");
    fwrite(STDERR, '  lock   ' . $lockPlatform['sha256'] . "\n  release {$remote}\n");
    fwrite(STDERR, "  说明锁文件没跟上新包，或资产被换过。确认后用 pack-deps 重新生成 lock。\n");
    exit(1);
}

if ($expected === null) {
    fwrite(STDERR, "⚠ 既无 deps.lock.json 平台条目也取不到 .sha256，无法校验。本地 SHA256：\n{$actual}\n");
    fwrite(STDERR, "  请与 docs/guides/dependencies-and-release.md 记录的校验值人工比对后再用。\n");
} elseif (!hash_equals((string) $expected, $actual)) {
    @unlink($part);
    $ref = $lockPlatform !== null ? 'deps.lock.json' : '.sha256';
    fwrite(STDERR, "✗ SHA256 不符（对照 {$ref}），包已损坏或被替换：\n  期望 {$expected}\n  实际 {$actual}\n");
    exit(1);
} else {
    $ref = $lockPlatform !== null ? 'deps.lock.json' : '远端 .sha256';
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

printf("解压 : %d 个 entry → %s（平台 %s）\n", $zip->numFiles, $root, $platformId);
if (!$zip->extractTo($root)) {
    fwrite(STDERR, "解压失败\n");
    $zip->close();
    exit(1);
}
$zip->close();

// 解压成功不等于装对了：漏库要到构建期才炸，这里先数一遍
$libs = glob($root . '/' . $platform['skiaDir'] . '/out/Release-x64/*.' . $platform['libExt']) ?: [];
$ttfs = glob($root . '/cpp/fonts/*.ttf') ?: [];
$lics = glob($root . '/cpp/licenses/*.txt') ?: [];
printf("结果 : %d 个 *.%s、%d 个字体、%d 份许可\n", count($libs), $platform['libExt'], count($ttfs), count($lics));

if (count($libs) < $platform['minLibs'] || count($ttfs) < 2) {
    fwrite(STDERR, "✗ 解压后内容不全：包不完整，或解压到了错误的仓库根\n");
    exit(1);
}

if ($lockPlatform !== null) {
    $miss = [];
    if (isset($lockPlatform['libs']) && count($libs) !== (int) $lockPlatform['libs']) {
        $miss[] = "lib 期望 {$lockPlatform['libs']} 实得 " . count($libs);
    }
    if (isset($lockPlatform['licenses']) && count($lics) !== (int) $lockPlatform['licenses']) {
        $miss[] = "许可期望 {$lockPlatform['licenses']} 实得 " . count($lics);
    }
    if (isset($lockPlatform['fonts']) && is_array($lockPlatform['fonts']) && count($ttfs) !== count($lockPlatform['fonts'])) {
        $miss[] = '字体期望 ' . count($lockPlatform['fonts']) . ' 实得 ' . count($ttfs);
    }
    if ($miss !== []) {
        fwrite(STDERR, '✗ 与 deps.lock.json 对不上：' . implode('；', $miss) . "\n");
        exit(1);
    }
    echo "对账 : 与 deps.lock.json 的 {$platformId} 条目一致（entry {$lockPlatform['entries']}）\n";
}

echo "\n下一步：php tools/doctor.php\n";
