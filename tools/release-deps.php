<?php
declare(strict_types=1);

/**
 * 把 `dist/drift-deps-<平台>.zip` 发成 GitHub Release 资产。
 *
 * deps-v1 只有一个 win-x64 资产；deps-v2 起按 Platforms.php 的已发布平台逐个上传，
 * 每个 zip 配一个 .sha256，校验值都在同一份 deps.lock.json 里对账。
 *
 * 本机没有 `gh`，网页上传又容易漏传 `.sha256`，所以直接用 API 走一遍：
 * 建 release → 传 zip → 传校验值 → 打印可访问地址。
 *
 * 校验是前置的：包里装的和 `deps.lock.json` 记的必须是同一个东西，否则
 * 发出去的资产会让所有人 fetch 失败（他们拿 lock 校验），而这里毫无察觉。
 *
 * 发版前还会对一遍版本锚点：lock 里的 driftVersion / depsTag 必须与根目录
 * VERSION / DEPS_VERSION 相同。改了版本却没重打包就发出去，所有人 fetch 时拿 lock
 * 校验会集体失败（Flutter 打包流程拒绝发布过时的 engine.version，同理）。
 *
 * 用法：
 *   GITHUB_TOKEN=ghp_xxx php tools/release-deps.php        # tag 取 DEPS_VERSION
 *   php tools/release-deps.php --dry-run                    # 只看要做什么
 *   php tools/release-deps.php --force                      # 覆盖已有同名资产
 */

use Drift\Dev\Deps\Platforms;

require __DIR__ . '/deps-common.php';
require __DIR__ . '/version.php';
require_once __DIR__ . '/Deps/Platforms.php';

$root = dirname(__DIR__);
$repo = null;
$tag = null;
$tokenArg = null;
$notes = null;
$notesFile = null;
$draft = false;
$force = false;
$dryRun = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--repo=')) {
        $repo = substr($arg, 7);
    } elseif (str_starts_with($arg, '--tag=')) {
        $tag = substr($arg, 6);
    } elseif (str_starts_with($arg, '--token=')) {
        $tokenArg = substr($arg, 8);
    } elseif (str_starts_with($arg, '--notes=')) {
        $notes = substr($arg, 8);
    } elseif (str_starts_with($arg, '--notes-file=')) {
        $notesFile = substr($arg, 13);
    } elseif ($arg === '--draft') {
        $draft = true;
    } elseif ($arg === '--force') {
        $force = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } else {
        fwrite(STDERR, "未知参数 {$arg}\n");
        exit(1);
    }
}

// tag 不是自由参数：它由 DEPS_VERSION 唯一决定，否则「发出去的包」和
// 「fetch 默认去取的 tag」可以对不上，而且没人会察觉。保留 --tag 只为显式确认。
$depsTag = driftDepsTag($root);
if ($tag === null || trim($tag) === '') {
    $tag = $depsTag;
} else {
    $tag = trim($tag);
}
if ($tag !== $depsTag) {
    fwrite(STDERR, "✗ --tag={$tag} 与 DEPS_VERSION（{$depsTag}）不一致。\n");
    fwrite(STDERR, "  改 DEPS_VERSION → 重跑 pack-deps.php → 再发版，别绕过真相源。\n");
    exit(1);
}

$lockPath = $root . '/deps.lock.json';
$lock = null;
if (is_file($lockPath)) {
    $decoded = json_decode((string) file_get_contents($lockPath), true);
    if (is_array($decoded)) {
        $lock = $decoded;
    }
}
if ($lock === null) {
    fwrite(STDERR, "✗ 读不到 deps.lock.json —— 版本锚点也在里面，先跑 pack-deps.php\n");
    exit(1);
}
if (($lock['driftVersion'] ?? null) !== driftVersion($root)
    || ($lock['depsTag'] ?? null) !== $depsTag) {
    fwrite(STDERR, "✗ deps.lock.json 的版本锚点与 VERSION / DEPS_VERSION 不一致：\n");
    fwrite(STDERR, '  lock ' . ($lock['driftVersion'] ?? '?') . ' / ' . ($lock['depsTag'] ?? '?')
        . '，仓库 ' . driftVersion($root) . " / {$depsTag}\n");
    fwrite(STDERR, "  改版本后必须重跑 pack-deps.php，否则 fetch 拿到的包与仓库记录对不上。\n");
    exit(1);
}

// 选平台：deps-v1 保持旧单包形状；v2 起所有已发布平台必须齐全，缺一个就停。
/** @var array<string, array{platform:array,lock:array,zip:string,sha:string,asset:string}> $selected */
$selected = [];
if (Platforms::multiPlatformTag($depsTag)) {
    if (($lock['schema'] ?? null) !== 2 || !isset($lock['platforms']) || !is_array($lock['platforms'])) {
        fwrite(STDERR, "✗ {$depsTag} 需要 schema=2 的 platforms 表；先跑 php tools/pack-deps.php --platform=…\n");
        exit(1);
    }
    foreach (Platforms::published() as $p) {
        $entry = $lock['platforms'][$p['id']] ?? null;
        if (!is_array($entry)) {
            fwrite(STDERR, "✗ deps.lock.json 缺平台 {$p['id']} —— 发版会漏资产，别用半份锁发 v2\n");
            exit(1);
        }
        if (($entry['asset'] ?? null) !== $p['asset']) {
            fwrite(STDERR, "✗ {$p['id']} 的资产名与平台表不符：lock 是 " . ($entry['asset'] ?? '?') . "，应为 {$p['asset']}\n");
            exit(1);
        }
        $selected[$p['id']] = [
            'platform' => $p,
            'lock' => $entry,
            'zip' => $root . '/dist/' . $p['asset'],
            'sha' => $root . '/dist/' . $p['asset'] . '.sha256',
            'asset' => $p['asset'],
        ];
    }
} else {
    $p = Platforms::get(Platforms::WIN);
    if (!isset($lock['sha256'])) {
        fwrite(STDERR, "✗ deps-v1 的旧锁缺 sha256 —— 先跑 pack-deps.php\n");
        exit(1);
    }
    $selected[Platforms::WIN] = [
        'platform' => $p,
        'lock' => $lock,
        'zip' => $root . '/dist/' . $p['asset'],
        'sha' => $root . '/dist/' . $p['asset'] . '.sha256',
        'asset' => $p['asset'],
    ];
}

// 所有资产先校验完再动远端：中途失败不能留下「发了一半」的 release。
$actualHashes = [];
foreach ($selected as $id => $item) {
    if (!is_file($item['zip'])) {
        fwrite(STDERR, "找不到 {$item['zip']}\n先跑：php tools/pack-deps.php --platform={$id}\n");
        exit(1);
    }
    if (!is_file($item['sha'])) {
        fwrite(STDERR, "找不到 {$item['sha']}\npack-deps 会同时写出它；缺了说明打包不完整，重新打包。\n");
        exit(1);
    }
    $actual = (string) hash_file('sha256', $item['zip']);
    $shaLine = trim((string) file_get_contents($item['sha']));
    preg_match('/\b([0-9a-f]{64})\b/i', $shaLine, $m);
    $declared = isset($m[1]) ? strtolower($m[1]) : null;

    printf("包    : %s（%.1f MiB）\n", $item['zip'], filesize($item['zip']) / 1048576);
    printf("SHA256: %s\n", $actual);

    if ($declared === null) {
        fwrite(STDERR, "✗ {$item['sha']} 里没有 SHA256\n");
        exit(1);
    }
    if (!hash_equals($declared, $actual)) {
        fwrite(STDERR, "✗ .sha256 与包不符（期望 {$declared}），重新打包\n");
        exit(1);
    }
    if (!hash_equals((string) ($item['lock']['sha256'] ?? ''), $actual)) {
        fwrite(STDERR, "✗ deps.lock.json[{$id}] 与包不符：\n  lock {$item['lock']['sha256']}\n  包   {$actual}\n");
        fwrite(STDERR, "  先跑 pack-deps 让 lock 跟上，再发版 —— 这套流程的前提就是两者一致。\n");
        exit(1);
    }
    $actualHashes[$id] = $actual;
}
echo "校验  : 包 / .sha256 / deps.lock.json 三者一致（" . implode('、', array_keys($selected)) . "）\n";
printf("锚点  : %s · %s · %s\n", driftVersion($root), $depsTag, $tag);

if ($tokenArg === null) {
    $token = depsToken(null);
    if ($token === null) {
        fwrite(STDERR, "\n需要 GitHub token（发 release 必须有写权限）：\n");
        fwrite(STDERR, "  GITHUB_TOKEN=ghp_xxx php tools/release-deps.php\n");
        fwrite(STDERR, "  建 token：Settings → Developer settings → Personal access tokens，勾 repo\n");
        exit(1);
    }
} else {
    $token = depsToken($tokenArg);
}

if ($repo === null) {
    $repo = depsRepoFromOrigin();
}
if ($repo === null) {
    fwrite(STDERR, "推不出仓库：没有 GitHub 的 origin。用 --repo=owner/name 指定。\n");
    exit(1);
}

$defaultNotes = "外部二进制依赖：Skia M" . ($lock['skiaMilestone'] ?? '?') . " 预编译 + Noto Sans SC。\n\n"
    . "Skia 源码 commit：`" . ($lock['skiaRevision'] ?? '?') . "`"
    . "（" . ($lock['provenance'] ?? '?') . "；unpinned / legacy 表示历史包来源不可考）\n\n"
    . "解压到仓库根即可，取用走 `php tools/fetch-deps.php`（默认就是 {$tag}）：\n\n"
    . "```\nphp tools/fetch-deps.php\n```\n\n";
foreach ($selected as $id => $item) {
    $defaultNotes .= "- {$id}：`{$item['asset']}`，SHA256 `{$actualHashes[$id]}`\n";
}
$defaultNotes .= "- 包内：Skia 静态库、2 个字体、许可全文（`cpp/licenses/`）\n"
    . "- 校验值以仓库里的 `deps.lock.json` 为准，本页 `.sha256` 只作交叉验证\n";

$body = $notes ?? $defaultNotes;
if ($notesFile !== null) {
    $body = (string) file_get_contents($notesFile);
}

$base = depsApiRoot() . "/repos/{$repo}";
printf("仓库  : %s\nTag   : %s%s\n", $repo, $tag, $draft ? '（draft）' : '');

// 1. 查已有 release
[$code, $resp] = depsRequest('GET', "{$base}/releases/tags/{$tag}", $token);
$release = null;
if ($code === 200 && $resp !== null) {
    $decoded = json_decode($resp, true);
    $release = is_array($decoded) ? $decoded : null;
} elseif ($code !== 404) {
    fwrite(STDERR, "✗ 查询 release 失败（HTTP {$code}）：\n{$resp}\n");
    exit(1);
}

$allNames = [];
foreach ($selected as $item) {
    $allNames[] = $item['asset'];
    $allNames[] = $item['asset'] . '.sha256';
}

if ($dryRun) {
    echo "\n[dry-run] 计划：\n";
    echo $release === null
        ? "  创建 release {$tag}，上传 " . implode('、', $allNames) . "\n"
        : "  复用已有 release {$tag}（id {$release['id']}），"
            . ($force ? "删除同名资产后重传\n" : "同名资产存在时会拒绝，需 --force\n");
    exit(0);
}

$existing = [];
if ($release !== null) {
    foreach ($release['assets'] ?? [] as $a) {
        $existing[(string) ($a['name'] ?? '')] = (int) ($a['id'] ?? 0);
    }
    printf("复用  : 已有 release（%d 个资产）\n", count($existing));

    $clash = array_values(array_filter($allNames, static fn (string $n): bool => isset($existing[$n])));
    if ($clash !== []) {
        if (!$force) {
            fwrite(STDERR, "✗ 已有同名资产：" . implode('、', $clash) . "\n");
            fwrite(STDERR, "  资产只能删了重传（GitHub 不支持改内容）。确认要覆盖就加 --force。\n");
            exit(2);
        }
        foreach ($clash as $name) {
            [$dc] = depsRequest('DELETE', "{$base}/releases/assets/{$existing[$name]}", $token);
            if ($dc !== 204) {
                fwrite(STDERR, "✗ 删除 {$name} 失败（HTTP {$dc}）\n");
                exit(1);
            }
            printf("删除  : %s（旧资产）\n", $name);
        }
    }
} else {
    // 2. 建 release。target_commitish 用当前 HEAD：GitHub 会据此建 tag，
    //    不指定就落到默认分支的 HEAD 上 —— 那可能是别人刚推的提交。
    $payload = [
        'tag_name' => $tag,
        'name' => "依赖包 {$tag}",
        'body' => $body,
        'draft' => $draft,
        'prerelease' => false,
    ];
    $head = depsHeadSha();
    if ($head !== null) {
        $payload['target_commitish'] = $head;
    }

    [$cc, $cbody] = depsRequest('POST', "{$base}/releases", $token, $payload);
    if ($cc !== 201 || $cbody === null) {
        fwrite(STDERR, "✗ 创建 release 失败（HTTP {$cc}）：\n{$cbody}\n");
        exit(1);
    }
    $created = json_decode($cbody, true);
    if (!is_array($created) || !isset($created['id'])) {
        fwrite(STDERR, "✗ 创建 release 的响应看不懂：\n{$cbody}\n");
        exit(1);
    }
    $release = $created;
    printf("创建  : release %s（id %d）\n", $tag, (int) $release['id']);
}

// 3. 传资产。先 zip 后校验值：中途失败时至少不会有"校验值在、包不在"的假象。
$releaseId = (int) $release['id'];
foreach ($selected as $item) {
    $uploads = [
        [$item['zip'], $item['asset'], 'application/zip'],
        [$item['sha'], $item['asset'] . '.sha256', 'text/plain'],
    ];
    foreach ($uploads as [$path, $name, $mime]) {
        $url = depsUploadsRoot() . "/repos/{$repo}/releases/{$releaseId}/assets?name=" . rawurlencode($name);
        printf("上传  : %s（%.1f MiB）… ", $name, filesize($path) / 1048576);
        [$uc, $ubody] = depsUpload($url, $path, $mime, $token);
        if ($uc !== 201) {
            echo "\n";
            fwrite(STDERR, "✗ 上传 {$name} 失败（HTTP {$uc}）：\n{$ubody}\n");
            exit(1);
        }
        echo "ok\n";
    }
}

printf("\n完成：%s\n", (string) ($release['html_url'] ?? "https://github.com/{$repo}/releases/tag/{$tag}"));
echo "复验：在干净目录 clone 后跑 php tools/fetch-deps.php --tag={$tag}\n";
