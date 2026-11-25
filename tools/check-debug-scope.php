<?php
declare(strict_types=1);

/**
 * AOT 产物的调试面守门（A54 的机器判据）。
 *
 * ★ 为什么需要：tpc 是**全量编译 sources、没有死代码消除**（类可达即整类进产物）。
 *   `project.yml` 的 `ignore` 写漏一条不会报任何错 —— 只是多扛几千行永不执行的
 *   审查面进 exe。而 `ignore` 是 list，合并时**整体替换**：在 base.yml 里加不算数，
 *   每个应用都得自己重复一遍。手写白名单靠不住（第一版就漏了 `Session.php`，
 *   它 `extends SessionQueries, SessionDriver`，排掉父接口留下子接口 = 悬空继承）。
 *
 * ★ 判据只有一条：**每个 AOT 应用里，调试侧能被编译的文件集合恰好等于写面白名单**。
 *   多一个 ⇒ 审查面泄漏；少一个 ⇒ 热路径上 tap 的类被排掉（构建期才炸）。
 *   外加一条：`src/` 下的 ignore 条目必须真的命中文件，拼错路径不能静默通过。
 *
 * ★ 纯 PHP 内建、不 require autoload —— 守门器不依赖它要检查的那套代码。
 *   只读 `project.yml` 的顶层 list 段，不做通用 YAML 解析（tpc 的 list 只有两种写法：
 *   `- value` 与 `- path: value` + 缩进的 `if:`）。
 *
 * 用法：
 *   php tools/check-debug-scope.php              # 对账全部应用，exit 0/1
 *   php tools/check-debug-scope.php --selftest   # 守门器自证（合成用例）
 */

/** 允许进 AOT 产物的调试侧文件（L1 写面：热路径 tap + 记录类型）。 */
function writeFace(): array
{
    return [
        'src/Debug/Session/Edge.php',
        'src/Debug/Session/EvidenceBag.php',
        'src/Debug/Session/FrameRecord.php',
        'src/Debug/Session/GeometryCapture.php',
        'src/Debug/Session/GeometryRecord.php',
        'src/Debug/Session/InputScript.php',
        'src/Debug/Session/NetworkBodyRecord.php',
        'src/Debug/Session/NetworkRecord.php',
        'src/Debug/Session/PaintRecord.php',
        'src/Debug/Session/PropSnapshot.php',
        'src/Debug/Session/RecordOptions.php',
        'src/Debug/Session/Recorder.php',
        'src/Debug/Session/Redactor.php',
        'src/Debug/Session/Script.php',
        'src/Debug/Session/SessionState.php',
        'src/Debug/Session/Step.php',
        'src/Debug/Session/Thresholds.php',
    ];
}

/** 调试侧的全部候选文件（写面 + 审查面），路径相对仓库根。 */
function debugFiles(string $root): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root . '/src/Debug',
        FilesystemIterator::SKIP_DOTS
    ));
    foreach ($it as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
            // Windows 上 getPathname() 给反斜杠；统一成 '/' 再比对（否则整表误判）。
            $path = str_replace('\\', '/', $f->getPathname());
            $files[] = 'src/Debug/' . substr($path, strlen(str_replace('\\', '/', $root . '/src/Debug/')));
        }
    }
    foreach (['src/App/DebugSession.php', 'src/App/Replay.php'] as $extra) {
        if (is_file($root . '/' . $extra)) {
            $files[] = $extra;
        }
    }
    sort($files);
    return $files;
}

/** 读一个顶层 list 段；键不存在返回 null（区别于“存在但是空表”）。 */
function listSection(string $yml, string $key): ?array
{
    $out = null;
    // 先把注释整段抹掉：中文注释里出现过被 \R 切成续行的字节，续行没有 '#'
    // 前缀会被当成顶层内容，把后面的 list 项全吞掉（实测漏掉 Session.php）。
    $yml = preg_replace('~#[^\r\n]*~', '', $yml) ?? $yml;
    foreach (preg_split('~\R~', $yml) ?: [] as $line) {
        if (preg_match('~^' . preg_quote($key, '~') . ':\s*$~', $line)) {
            $out = [];
            continue;
        }
        if ($out === null) {
            continue;
        }
        // 只有「顶层 key:」才结束当前段；注释续行/空行不算。
        if (preg_match('~^[A-Za-z_][\w-]*:~', $line)) {
            break;
        }
        if (!preg_match('~^\s+-\s+(.*)$~', $line, $m)) {
            continue;
        }
        $value = trim($m[1]);
        if (str_starts_with($value, 'path:')) {
            $value = trim(substr($value, 5));
        }
        $value = trim(preg_replace('~\s+#.*$~', '', $value) ?? $value);
        $value = trim($value, "\"'");
        if ($value !== '') {
            $out[] = $value;
        }
    }
    return $out;
}

/** 把 list 项归一成「相对仓库根」的比较路径。 */
function normalise(string $appDir, string $root, string $entry): string
{
    $p = str_replace('\\', '/', $entry);
    if ($p === '.' || $p === './') {
        return 'apps/' . basename($appDir);
    }
    if (str_starts_with($p, '..')) {
        $p = substr($p, 3); // ../../src/... 相对 apps/<name>/ ⇒ src/...
        while (str_starts_with($p, '../')) {
            $p = substr($p, 3);
        }
    } elseif (!str_starts_with($p, '.')) {
        $p = 'apps/' . basename($appDir) . '/' . $p;
    }
    return rtrim(str_replace('./', '/', $p), '/');
}

/**
 * 一个应用的编译范围对账。
 * @return list<string> 失败原因（空 = 通过）
 */
function checkApp(string $root, string $appName): array
{
    $appDir = $root . '/apps/' . $appName;
    $project = $appDir . '/project.yml';
    if (!is_file($project)) {
        return ["找不到 {$project}"];
    }
    $yml = (string) file_get_contents($project);
    $sources = listSection($yml, 'sources') ?? [];
    $ignore = listSection($yml, 'ignore');

    // list 整体替换：本文件没写 ignore 才继承 base.yml。
    if ($ignore === null) {
        $base = (string) file_get_contents($root . '/build/base.yml');
        $ignore = listSection($base, 'ignore') ?? [];
    }

    $sourceRoots = [];
    foreach ($sources as $s) {
        $sourceRoots[] = normalise($appDir, $root, $s);
    }
    // 不编译框架 `src` 的应用（dev 壳只编译 dev-shell.php + 一个 ResizeHook）
    // 没有调试面可谈；写面「被排掉」是假阳性。
    $compilesFramework = false;
    foreach ($sourceRoots as $s) {
        // 必须是整棵 `src` 进 sources；dev 壳只挑了 src/Platform/... 单个文件。
        if ($s === 'src') {
            $compilesFramework = true;
            break;
        }
    }
    if (!$compilesFramework) {
        return [];
    }

    $excluded = [];
    foreach ($ignore as $i) {
        $excluded[] = normalise($appDir, $root, $i);
    }

    $errors = [];

    // ignore 里指向 src/ 的条目必须命中真实文件/目录：拼错路径不能静默通过。
    foreach ($ignore as $i) {
        $n = normalise($appDir, $root, $i);
        if (str_starts_with($n, 'src/') && !file_exists($root . '/' . $n)) {
            $errors[] = "ignore 条目 {$i} 不存在（路径拼错？）";
        }
    }

    $compiled = [];
    foreach (debugFiles($root) as $file) {
        $underSource = false;
        foreach ($sourceRoots as $s) {
            if ($s !== '' && ($file === $s || str_starts_with($file, $s . '/'))) {
                $underSource = true;
                break;
            }
        }
        if (!$underSource) {
            continue;
        }
        foreach ($excluded as $e) {
            if ($e !== '' && ($file === $e || str_starts_with($file, $e . '/'))) {
                continue 2;
            }
        }
        $compiled[] = $file;
    }
    sort($compiled);

    $allowed = writeFace();
    foreach (array_diff($compiled, $allowed) as $leak) {
        $errors[] = "审查面泄漏进 AOT：{$leak}（该加进 project.yml 的 ignore）";
    }
    foreach (array_diff($allowed, $compiled) as $missing) {
        $errors[] = "写面被排掉：{$missing}（热路径 tap 会炸）";
    }
    return $errors;
}

/** 合成用例自证：改一个 ignore、加一个文件，判据都必须咬红。 */
function selftest(string $root): int
{
    $failures = 0;
    $check = static function (string $name, bool $ok) use (&$failures): void {
        echo '  ' . ($ok ? '✓' : '✗') . " {$name}\n";
        if (!$ok) {
            $failures++;
        }
    };

    $actual = checkApp($root, 'showcase');
    $check('当前 showcase 绿（写面 17 项、审查面 12 项）', $actual === []);

    // 应用侧刻意**显式重写** ignore：这样既能验「继承 base」的常规路径，
    // 也能在合成用例里改单条并重跑（改 base 会污染真实工程）。
    $appYml = (string) file_get_contents($root . '/apps/showcase/project.yml');
    $shared = listSection((string) file_get_contents($root . '/build/base.yml'), 'ignore') ?? [];
    $base = $appYml . "\nignore:\n";
    foreach ($shared as $entry) {
        $base .= '  - ' . $entry . "\n";
    }
    $tmp = $root . '/apps/zz-scope-probe';
    if (!mkdir($tmp, 0777, true)) {
        fwrite(STDERR, "建不出临时应用目录 {$tmp}\n");
        return 1;
    }
    try {
        // ① 漏一条 ignore ⇒ 必须报泄漏；② 漏的是父接口那类 ⇒ 也要报。
        file_put_contents($tmp . '/project.yml', str_replace(
            '  - ../../src/Debug/Session/Session.php',
            '',
            $base
        ));
        $got = checkApp($root, 'zz-scope-probe');
        $check('删掉 Session.php 的 ignore ⇒ 咬红悬空继承',
            in_array('审查面泄漏进 AOT：src/Debug/Session/Session.php（该加进 project.yml 的 ignore）', $got, true));

        // ③ ignore 路径拼错 ⇒ 必须报不存在。
        file_put_contents($tmp . '/project.yml', str_replace(
            '  - ../../src/Debug/Session/Query.php',
            '  - ../../src/Debug/Session/Querry.php',
            $base
        ));
        $got = checkApp($root, 'zz-scope-probe');
        $check('ignore 路径拼错 ⇒ 咬红',
            in_array('ignore 条目 ../../src/Debug/Session/Querry.php 不存在（路径拼错？）', $got, true));

        // ④ 把写面排掉 ⇒ 必须报。
        file_put_contents($tmp . '/project.yml', str_replace(
            '  - ../../src/Debug/Json.php',
            "  - ../../src/Debug/Json.php\n  - ../../src/Debug/Session/Recorder.php",
            $base
        ));
        $got = checkApp($root, 'zz-scope-probe');
        $check('写面 Recorder 被排掉 ⇒ 咬红',
            in_array('写面被排掉：src/Debug/Session/Recorder.php（热路径 tap 会炸）', $got, true));
    } finally {
        @unlink($tmp . '/project.yml');
        @rmdir($tmp);
    }

    echo $failures === 0
        ? "[check-debug-scope --selftest] ✓ 全过\n"
        : "[check-debug-scope --selftest] ✗ {$failures} 条红\n";
    return $failures === 0 ? 0 : 1;
}

$root = dirname(__DIR__);
if (in_array('--selftest', $argv, true)) {
    exit(selftest($root));
}

$apps = [];
foreach (glob($root . '/apps/*/project.yml') ?: [] as $project) {
    $apps[] = basename(dirname($project));
}
sort($apps);

echo "[check-debug-scope] AOT 调试面 = 写面白名单（" . count(writeFace()) . " 项）\n";
$failed = 0;
foreach ($apps as $app) {
    $errors = checkApp($root, $app);
    if ($errors === []) {
        echo "  ✓ {$app}\n";
        continue;
    }
    $failed++;
    echo "  ✗ {$app}\n";
    foreach ($errors as $e) {
        fwrite(STDERR, "      - {$e}\n");
    }
}
echo $failed === 0
    ? "[check-debug-scope] ✓ " . count($apps) . " 个应用全绿\n"
    : "[check-debug-scope] ✗ {$failed} 个应用红\n";
exit($failed === 0 ? 0 : 1);
