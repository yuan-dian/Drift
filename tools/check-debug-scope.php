<?php
declare(strict_types=1);

/**
 * 调试面的**物理边界**守门器（A54 L3 → A72 重写）。
 *
 * ★ 为什么需要它：tpc 是**全量编译 sources、没有死代码消除**（类可达即整类进产物）。
 *   A54 时审查面还在 `src/Debug`，只能靠 `project.yml` 的 `ignore` 逐条点名排掉 ——
 *   24 条手写清单，漏一条不报错，只是多扛几千行永不执行的代码进 exe；而
 *   `ignore` 是 list，合并时整体替换，某个工程自己写一遍就要把整张表抄一遍
 *   （第一版就漏了 `Session.php`，它 extends 两个已排除的父接口，留下子接口 = 悬空继承）。
 *
 * ★ A72 换了机制：审查面**物理搬到 `devtools/`**，而各应用的 sources 只有
 *   `../../src` ⇒ 它不在编译范围里，不需要任何 `ignore` 条目。边界从
 *   「人工清单」变成「目录 + sources」，于是这组判据也从**对账白名单**
 *   变成**结构断言**：
 *
 *   ① `src/Debug/` 下只许有写面文件（目录即真相；多一个 = 审查面搬回来了）；
 *   ② `src/` 的**代码**不得引用 `Drift\DevTools\`（框架不认识审查面，注释不算）；
 *   ③ 没有任何应用的 sources 把 `devtools/` 收进编译范围；
 *   ④ `ignore` 里不得再出现 `src/Debug/*` 或 `devtools` 条目（那是 A54 的遗留，
 *      留着会让人以为"这些是被排掉的"，实际是路径不存在被静默跳过）。
 *
 * ★ 纯 PHP 内建、不 require autoload —— 守门器不依赖它要检查的那套代码。
 *   只读 `project.yml` 的顶层 list 段，不做通用 YAML 解析（tpc 的 list 只有两种写法：
 *   `- value` 与 `- path: value` + 缩进的 `if:`）。
 *
 * 用法：
 *   php tools/check-debug-scope.php              # 对账全部应用 + 结构断言，exit 0/1
 *   php tools/check-debug-scope.php --selftest   # 守门器自证（合成用例）
 */

/** 允许留在 `src/Debug` 的写面文件（L1：热路径 tap + 记录类型）。 */
function writeFace(): array
{
    return [
        'src/Debug/Session/AsyncRecord.php',
        'src/Debug/Session/Edge.php',
        'src/Debug/Session/EvidenceBag.php',
        'src/Debug/Session/FrameRecord.php',
        'src/Debug/Session/GeometryCapture.php',
        'src/Debug/Session/GeometryRecord.php',
        'src/Debug/Session/InputScript.php',
        'src/Debug/Session/NetworkBodyRecord.php',
        'src/Debug/Session/NetworkRecord.php',
        'src/Debug/Session/PaintRecord.php',
        'src/Debug/Session/RecordOptions.php',
        'src/Debug/Session/Recorder.php',
        'src/Debug/Session/Redactor.php',
        'src/Debug/Session/Script.php',
        'src/Debug/Session/SessionState.php',
        'src/Debug/Session/Step.php',
        'src/Debug/Session/Thresholds.php',
    ];
}

/** `src/Debug` 下实际存在的 .php，路径相对仓库根。 */
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
    sort($files);
    return $files;
}

/** 读一个顶层 list 段；键不存在返回 null（区别于"存在但是空表"）。 */
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
 * 只留代码：注释整段剔掉。
 *
 * 判据 ② 要放过「注释里解释 A72 为什么这么摆」的写法 —— 那是在描述边界，
 * 不是在依赖边界。`@see` 里提到审查面的类名同样不算引用。
 */
function codeOnly(string $source): string
{
    $out = '';
    foreach (token_get_all($source) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                continue;
            }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

/**
 * 判据 ①：`src/Debug` 的文件集合恰好等于写面清单。
 *
 * 多一个 = 审查面搬回 `src`（会进产物）；少一个 = 写面文件被删/改名（清单过期）。
 *
 * @param list<string> $actual  相对仓库根的 .php 路径
 * @param list<string> $allowed 写面清单
 * @return list<string>
 */
function auditDebugDir(array $actual, array $allowed): array
{
    $errors = [];
    foreach (array_diff($actual, $allowed) as $extra) {
        $errors[] = "src 出现审查面：{$extra}（A72 后审查面住 devtools/，留在 src 会进 AOT 产物）";
    }
    foreach (array_diff($allowed, $actual) as $missing) {
        $errors[] = "写面文件缺失：{$missing}（热路径 tap 会炸；或者清单过期了）";
    }
    return $errors;
}

/**
 * 判据 ②：`src/` 的代码不得引用 `Drift\DevTools\`。
 *
 * @param array<string, string> $codeByFile 相对路径 => 去掉注释后的源码
 * @return list<string>
 */
function auditStrayRefs(array $codeByFile): array
{
    $errors = [];
    foreach ($codeByFile as $path => $code) {
        if (str_contains($code, 'Drift\\DevTools\\')) {
            $errors[] = "框架代码引用了审查面：{$path}（src 会被 AOT 编进产物，devtools 不会 ⇒ 产物里断链）";
        }
    }
    return $errors;
}

/**
 * 判据 ③：没有应用的 sources 把 `devtools/` 收进编译范围。
 *
 * @param list<string> $sources 已归一的 source 根（相对仓库根）
 */
function auditSources(array $sources): array
{
    $errors = [];
    foreach ($sources as $s) {
        $covers = $s === ''
            || $s === 'devtools'
            || str_starts_with($s, 'devtools/')
            || str_starts_with('devtools', $s . '/');
        if ($covers) {
            $errors[] = "sources 把 devtools/ 收进了编译范围：{$s}（审查面会被编进 AOT 产物）";
        }
    }
    return $errors;
}

/**
 * 判据 ④：`ignore` 里不得再有 A54 时代的调试条目。
 *
 * @param list<string> $ignore 已归一的 ignore 条目
 */
function auditIgnore(array $ignore): array
{
    $errors = [];
    foreach ($ignore as $i) {
        $legacy = str_starts_with($i, 'src/Debug/')
            || str_starts_with($i, 'src/Debug')
            || $i === 'src/App/DebugSession.php'
            || $i === 'src/App/Replay.php'
            || $i === 'devtools'
            || str_starts_with($i, 'devtools/');
        if ($legacy) {
            $errors[] = "ignore 里的调试条目已过期：{$i}（A72 后靠目录 + sources 划界，不再逐条排）";
        }
    }
    return $errors;
}

/** 一个应用的结构对账 + 全仓结构断言。@return list<string> 失败原因（空 = 通过） */
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

    $roots = [];
    foreach ($sources as $s) {
        $roots[] = normalise($appDir, $root, $s);
    }
    $excluded = [];
    foreach ($ignore as $i) {
        $excluded[] = normalise($appDir, $root, $i);
    }

    $errors = auditSources($roots);
    foreach (auditIgnore($excluded) as $e) {
        $errors[] = "[{$appName}] {$e}";
    }

    // ignore 条目拼错不能静默：tpc 对不存在的条目直接 continue。
    foreach ($ignore as $i) {
        $n = normalise($appDir, $root, $i);
        if (($n === 'src' || str_starts_with($n, 'src/')) && !file_exists($root . '/' . $n)) {
            $errors[] = "[{$appName}] ignore 条目 {$i} 不存在（路径拼错？）";
        }
    }

    return $errors;
}

/** 合成用例自证：每加一条错，判据都必须咬红。 */
function selftest(string $root): int
{
    $failures = 0;
    $check = static function (string $name, bool $ok) use (&$failures): void {
        echo '  ' . ($ok ? '✓' : '✗') . " {$name}\n";
        if (!$ok) {
            $failures++;
        }
    };

    $allowed = writeFace();

    // ① 目录断言：多一个 / 少一个都要红。
    $extra = array_merge($allowed, ['src/Debug/Session/Query.php']);
    $got = auditDebugDir($extra, $allowed);
    $check('审查面搬回 src ⇒ 咬红', in_array(
        'src 出现审查面：src/Debug/Session/Query.php（A72 后审查面住 devtools/，留在 src 会进 AOT 产物）',
        $got,
        true
    ));
    $got = auditDebugDir(array_slice($allowed, 1), $allowed);
    $check('写面文件缺失 ⇒ 咬红', in_array(
        '写面文件缺失：src/Debug/Session/AsyncRecord.php（热路径 tap 会炸；或者清单过期了）',
        $got,
        true
    ));
    $check('写面清单本身自洽（无重复/无缺失）', auditDebugDir($allowed, $allowed) === []);

    // ② 代码引用断言：代码里引用要红，注释里提到不红。
    $got = auditStrayRefs(['src/Widget/Element.php' => 'new \Drift\DevTools\Session\Query();']);
    $check('src 引用 Drift\DevTools ⇒ 咬红', count($got) === 1);
    $got = auditStrayRefs(['src/Widget/Element.php' => codeOnly("<?php\n// 审查面在 Drift\\DevTools\\Session\\Query\n")]);
    $check('注释里提到不算引用', $got === []);
    $got = auditStrayRefs([
        'src/Widget/Element.php' => codeOnly("<?php\n/** @see \\Drift\\DevTools\\Session\\Query */\nnew \\Drift\\Debug\\Session\\Recorder();\n"),
    ]);
    $check('token 过滤真的剔掉 docblock', $got === []);

    // ③ sources 断言：收进 devtools 的三种写法都要红（`../../devtools` 走真实的归一链）。
    foreach (['', 'devtools', 'devtools/Session', '../../devtools'] as $bad) {
        $got = auditSources([str_starts_with($bad, '..') ? normalise($root . '/apps/hello', $root, $bad) : $bad]);
        $check("sources 收进 devtools（" . ($bad === '' ? '仓库根' : $bad) . "）⇒ 咬红", count($got) === 1);
    }
    $check('正常 sources 不红', auditSources(['src', 'apps/hello', 'cpp-src/core']) === []);

    // ④ ignore 断言：A54 时代的条目残留要红。
    foreach (['src/Debug/Protocol', 'src/Debug/Session/Query.php', 'src/App/DebugSession.php', 'devtools'] as $bad) {
        $got = auditIgnore([$bad]);
        $check("ignore 残留调试条目（{$bad}）⇒ 咬红", count($got) === 1);
    }
    $check('正常 ignore 不红', auditIgnore(['build', 'tests', 'mock-server.php']) === []);

    // ⑤ 真实仓库必须全绿。
    $actual = debugFiles($root);
    $check('真实仓库 src/Debug 恰好是写面 ' . count($allowed) . ' 项', auditDebugDir($actual, $allowed) === []);

    echo $failures === 0
        ? "[check-debug-scope --selftest] ✓ 全过\n"
        : "[check-debug-scope --selftest] ✗ {$failures} 条红\n";
    return $failures === 0 ? 0 : 1;
}

$root = dirname(__DIR__);
if (in_array('--selftest', $argv, true)) {
    exit(selftest($root));
}

echo "[check-debug-scope] 结构断言：src/Debug = 写面白名单（" . count(writeFace()) . " 项），审查面住 devtools/\n";
$failed = 0;

// 全仓断言：src/Debug 目录、src 的代码引用。
$errors = auditDebugDir(debugFiles($root), writeFace());
$codeByFile = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $f->getPathname());
    $codeByFile[substr($path, strlen(str_replace('\\', '/', $root)) + 1)] = codeOnly((string) file_get_contents($path));
}
$errors = array_merge($errors, auditStrayRefs($codeByFile));
if ($errors === []) {
    echo "  ✓ src/Debug 只含写面，src 不引用 Drift\\DevTools\n";
} else {
    $failed++;
    foreach ($errors as $e) {
        echo "  ✗ {$e}\n";
    }
}

$apps = [];
foreach (glob($root . '/apps/*/project.yml') ?: [] as $project) {
    $apps[] = basename(dirname($project));
}
sort($apps);

foreach ($apps as $app) {
    $appErrors = checkApp($root, $app);
    if ($appErrors === []) {
        echo "  ✓ {$app}\n";
        continue;
    }
    $failed++;
    foreach ($appErrors as $e) {
        echo "  ✗ {$e}\n";
    }
}

echo $failed === 0 ? "[check-debug-scope] ✓ 全过\n" : "[check-debug-scope] ✗ {$failed} 项红\n";
exit($failed === 0 ? 0 : 1);
