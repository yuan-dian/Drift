<?php
declare(strict_types=1);

/**
 * 版本的唯一真相源（Chromium `chrome/VERSION` / Flutter `bin/internal/engine.version` 的形态）。
 *
 * `VERSION` 是框架版本，`DEPS_VERSION` 是配套二进制依赖包的 tag —— 两者分开，
 * 因为换代码版本和换 Skia 包是两件事，混在一个文件里会让 diff 看不出动了哪头。
 *
 * 本文件既可 require（拿到 driftVersion / driftDepsTag / driftVersionCheck），
 * 也可直接跑：
 *   php tools/version.php            # 人读
 *   php tools/version.php --json     # 机器可读
 *   php tools/version.php --check    # 一致性判据，供 bin/drift verify 调用，不一致 exit 1
 */

const DRIFT_VERSION_RE = '/^\d+\.\d+\.\d+-preview\.\d+$/';
const DRIFT_DEPS_TAG_RE = '/^deps-v\d+$/';

function driftVersionRoot(): string
{
    return dirname(__DIR__);
}

function driftVersion(?string $root = null): string
{
    return trim((string) @file_get_contents(($root ?? driftVersionRoot()) . '/VERSION'));
}

function driftDepsTag(?string $root = null): string
{
    return trim((string) @file_get_contents(($root ?? driftVersionRoot()) . '/DEPS_VERSION'));
}

/**
 * Skia 源码 commit。二进制包必须能追到源码，`unpinned` 只用于来源不可考的历史包
 * （deps-v1 是从别的机器拷入的预编译包），CI 重建时必须是 40 位 hash。
 */
function driftSkiaRevision(?string $root = null): string
{
    return trim((string) @file_get_contents(($root ?? driftVersionRoot()) . '/SKIA_REVISION'));
}

function driftSkiaRevisionValid(string $rev): bool
{
    return $rev === 'unpinned' || preg_match('/^[0-9a-f]{40}$/', $rev) === 1;
}

/**
 * 用数组形式起进程：Windows 上 exec() 会经 cmd.exe，命令里的 `2>/dev/null`
 * 会被当成路径并吐一堆 "The system cannot find the path specified."。
 * @return list<string>
 */
function driftGitLines(string $root, array $args): array
{
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = @proc_open(array_merge(['git'], $args), $spec, $pipes, $root);
    if (!is_resource($p)) {
        return [];
    }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    if ($code !== 0) {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode("\n", $out)), static fn (string $l): bool => $l !== ''));
}

/**
 * @return array{version:string,tag:string,depsTag:string,skiaRevision:string,git:array{sha:?string,tagsOnHead:list<string>}}
 */
function driftVersionInfo(?string $root = null): array
{
    $root ??= driftVersionRoot();
    $version = driftVersion($root);
    $sha = driftGitLines($root, ['rev-parse', '--short=9', 'HEAD'])[0] ?? null;
    $tags = driftGitLines($root, ['tag', '--points-at', 'HEAD']);

    return [
        'version' => $version,
        'tag' => 'v' . $version,
        'depsTag' => driftDepsTag($root),
        'skiaRevision' => driftSkiaRevision($root),
        'git' => ['sha' => $sha, 'tagsOnHead' => $tags],
    ];
}

/**
 * 版本文件不能只是"写在那儿"：CHANGELOG、git tag、依赖锁文件都从它派生，
 * 任一处漂了都要在 verify 里响，而不是等发版时才发现 tag 和 CHANGELOG 对不上。
 *
 * @return array{ok:bool,errors:list<string>,info:array}
 */
function driftVersionCheck(?string $root = null): array
{
    $root ??= driftVersionRoot();
    $errors = [];
    $info = driftVersionInfo($root);
    $version = $info['version'];
    $depsTag = $info['depsTag'];

    if (!is_file($root . '/VERSION') || preg_match(DRIFT_VERSION_RE, $version) !== 1) {
        $errors[] = "VERSION 缺失或格式不对（应形如 0.1.0-preview.1）：{$version}";
    }
    if (!is_file($root . '/DEPS_VERSION') || preg_match(DRIFT_DEPS_TAG_RE, $depsTag) !== 1) {
        $errors[] = "DEPS_VERSION 缺失或格式不对（应形如 deps-v1）：{$depsTag}";
    }

    $skiaRev = driftSkiaRevision($root);
    if (!is_file($root . '/SKIA_REVISION') || !driftSkiaRevisionValid($skiaRev)) {
        $errors[] = "SKIA_REVISION 缺失或格式不对（40 位 commit 或 unpinned）：{$skiaRev}";
    }

    // 第二份真相一旦出现，早晚不同步。Composer 对 library 也建议版本由 VCS 推导。
    $composer = json_decode((string) @file_get_contents($root . '/composer.json'), true);
    if (is_array($composer) && array_key_exists('version', $composer)) {
        $errors[] = 'composer.json 不该写 version：唯一真相源是根目录 VERSION';
    }

    $changelog = (string) @file_get_contents($root . '/CHANGELOG.md');
    if ($version === '' || preg_match('/^##\s+v' . preg_quote($version, '/') . '\b/mu', $changelog) !== 1) {
        $errors[] = "CHANGELOG.md 里没有 `## v{$version}` 这一节 —— 改版本必须同步开一节";
    }

    // 只管框架 tag；deps-vN 是依赖包自己的 tag，也会挂在同一个 commit 上。
    foreach ($info['git']['tagsOnHead'] as $t) {
        if (preg_match('/^v\d/', $t) === 1 && $t !== $info['tag']) {
            $errors[] = "HEAD 上的框架 tag 是 {$t}，但 VERSION 是 {$version}（应为 {$info['tag']}）";
        }
    }

    // 运行时用的是编译期常量，不是运行时读文件（AOT 产物里没有仓库根目录）。
    // 它是派生产物，所以这里只比对，改版本请走 gen-version.php。
    $constSrc = (string) @file_get_contents($root . '/src/Core/Version.php');
    preg_match("/public const string VERSION = '([^']+)'/", $constSrc, $cm);
    preg_match("/public const string DEPS_TAG = '([^']+)'/", $constSrc, $dm);
    if (($cm[1] ?? null) !== $version) {
        $errors[] = 'src/Core/Version.php 的 VERSION 与 VERSION 不一致 —— 跑 php tools/gen-version.php';
    }
    if (($dm[1] ?? null) !== $depsTag) {
        $errors[] = 'src/Core/Version.php 的 DEPS_TAG 与 DEPS_VERSION 不一致 —— 跑 php tools/gen-version.php';
    }

    $lock = json_decode((string) @file_get_contents($root . '/deps.lock.json'), true);
    if (!is_array($lock)) {
        $errors[] = '读不到 deps.lock.json';
    } else {
        if (($lock['driftVersion'] ?? null) !== $version) {
            $errors[] = 'deps.lock.json 的 driftVersion 与 VERSION 不一致 —— 改版本后重跑 pack-deps.php';
        }
        if (($lock['depsTag'] ?? null) !== $depsTag) {
            $errors[] = 'deps.lock.json 的 depsTag 与 DEPS_VERSION 不一致 —— 换包后重跑 pack-deps.php';
        }
        if (($lock['skiaRevision'] ?? null) !== $skiaRev) {
            $errors[] = 'deps.lock.json 的 skiaRevision 与 SKIA_REVISION 不一致 —— 换 Skia 后重跑 pack-deps.php';
        }

        // README 里那份校验值是给人的，pack-deps 不会自动同步它：换包忘改就会让
        // 使用者校验失败，而上面所有版本判据都还是绿的。
        $readme = (string) @file_get_contents($root . '/README.md');
        preg_match_all('/\b[0-9a-f]{64}\b/i', $readme, $rm);
        $hashes = array_map('strtolower', $rm[0]);
        if ($hashes === []) {
            $errors[] = 'README.md 里找不到 SHA256 —— 换包后要同步「当前包的校验值」';
        } else {
            foreach ($hashes as $h) {
                if (!hash_equals((string) $lock['sha256'], $h)) {
                    $errors[] = "README.md 的 SHA256（{$h}）与 deps.lock.json 不一致 —— 换包后同步 README";
                    break;
                }
            }
        }
    }

    return ['ok' => $errors === [], 'errors' => $errors, 'info' => $info];
}

// ── CLI ──
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $args = array_slice($argv, 1);
    $unknown = array_filter($args, static fn (string $a): bool => !in_array($a, ['--json', '--check'], true));
    if ($unknown !== []) {
        fwrite(STDERR, '未知参数：' . implode(' ', $unknown) . "\n");
        exit(1);
    }

    if (in_array('--check', $args, true)) {
        $result = driftVersionCheck();
        foreach ($result['errors'] as $e) {
            fwrite(STDERR, "✗ {$e}\n");
        }
        if (!$result['ok']) {
            exit(1);
        }
        printf("✓ 版本一致：%s · %s · tag v%s\n", $result['info']['version'], $result['info']['depsTag'], $result['info']['version']);
        exit(0);
    }

    $info = driftVersionInfo();
    if (in_array('--json', $args, true)) {
        echo json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
        exit(0);
    }
    printf("Drift  : %s\n", $info['version']);
    printf("Tag    : %s\n", $info['tag']);
    printf("依赖包 : %s\n", $info['depsTag']);
    printf("Skia   : %s\n", $info['skiaRevision']);
    printf("Git    : %s\n", $info['git']['sha'] ?? '(无 git)');
    if ($info['git']['tagsOnHead'] !== []) {
        printf("HEAD tag: %s\n", implode(', ', $info['git']['tagsOnHead']));
    }
}
