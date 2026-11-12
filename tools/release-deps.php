<?php
declare(strict_types=1);

/**
 * 把 `dist/drift-deps-win-x64.zip` 发成 GitHub Release 资产。
 *
 * 本机没有 `gh`，网页上传又容易漏传 `.sha256`，所以直接用 API 走一遍：
 * 建 release → 传 zip → 传校验值 → 打印可访问地址。
 *
 * 校验是前置的：包里装的和 `deps.lock.json` 记的必须是同一个东西，否则
 * 发出去的资产会让所有人 fetch 失败（他们拿 lock 校验），而这里毫无察觉。
 *
 * 发版前还会对一遍版本锚点：包内 `deps.lock.json` 记的 driftVersion / depsTag
 * 必须与根目录 VERSION / DEPS_VERSION 相同。改了版本却没重打包就发出去，
 * 所有人 fetch 时拿 lock 校验会集体失败（Flutter 打包流程拒绝发布过时的
 * engine.version，同理）。
 *
 * 用法：
 *   GITHUB_TOKEN=ghp_xxx php tools/release-deps.php        # tag 取 DEPS_VERSION
 *   php tools/release-deps.php --dry-run                    # 只看要做什么
 *   php tools/release-deps.php --force                      # 覆盖已有同名资产
 */

require __DIR__ . '/deps-common.php';
require __DIR__ . '/version.php';

const ASSET = 'drift-deps-win-x64.zip';

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

$zipPath = $root . '/dist/' . ASSET;
$shaPath = $zipPath . '.sha256';
$lockPath = $root . '/deps.lock.json';

if (!is_file($zipPath)) {
    fwrite(STDERR, "找不到 {$zipPath}\n先跑：php tools/pack-deps.php\n");
    exit(1);
}
if (!is_file($shaPath)) {
    fwrite(STDERR, "找不到 {$shaPath}\npack-deps 会同时写出它；缺了说明打包不完整，重新打包。\n");
    exit(1);
}

$lock = null;
if (is_file($lockPath)) {
    $decoded = json_decode((string) file_get_contents($lockPath), true);
    if (is_array($decoded) && isset($decoded['sha256'])) {
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

$actual = (string) hash_file('sha256', $zipPath);
$shaLine = trim((string) file_get_contents($shaPath));
preg_match('/\b([0-9a-f]{64})\b/i', $shaLine, $m);
$declared = isset($m[1]) ? strtolower($m[1]) : null;

printf("包    : %s（%.1f MiB）\n", $zipPath, filesize($zipPath) / 1048576);
printf("SHA256: %s\n", $actual);

// 三种不一致都要拦住：会发出去的资产必须能和 lock 对账，否则所有人 fetch 失败。
if ($declared === null) {
    fwrite(STDERR, "✗ {$shaPath} 里没有 SHA256\n");
    exit(1);
}
if (!hash_equals($declared, $actual)) {
    fwrite(STDERR, "✗ .sha256 与包不符（期望 {$declared}），重新打包\n");
    exit(1);
}
if (!hash_equals((string) $lock['sha256'], $actual)) {
    fwrite(STDERR, "✗ deps.lock.json 与包不符：\n  lock {$lock['sha256']}\n  包   {$actual}\n");
    fwrite(STDERR, "  先跑 pack-deps 让 lock 跟上，再发版 —— 这套流程的前提就是两者一致。\n");
    exit(1);
}
echo "校验  : 包 / .sha256 / deps.lock.json 三者一致\n";
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
    . "```\nphp tools/fetch-deps.php\n```\n\n"
    . "- 包内：" . ($lock['libs'] ?? '?') . " 个 `.lib`、"
    . (isset($lock['fonts']) && is_array($lock['fonts']) ? count($lock['fonts']) : 2) . " 个字体、"
    . ($lock['licenses'] ?? '?') . " 份许可全文（`cpp/licenses/`）\n"
    . "- SHA256：`{$actual}`\n"
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

if ($dryRun) {
    echo "\n[dry-run] 计划：\n";
    echo $release === null
        ? "  创建 release {$tag}，上传 " . ASSET . " 与 .sha256\n"
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

    $clash = array_values(array_filter([ASSET, ASSET . '.sha256'], static fn (string $n): bool => isset($existing[$n])));
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
$uploads = [
    [$zipPath, ASSET, 'application/zip'],
    [$shaPath, ASSET . '.sha256', 'text/plain'],
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

printf("\n完成：%s\n", (string) ($release['html_url'] ?? "https://github.com/{$repo}/releases/tag/{$tag}"));
echo "复验：在干净目录 clone 后跑 php tools/fetch-deps.php --tag={$tag}\n";
