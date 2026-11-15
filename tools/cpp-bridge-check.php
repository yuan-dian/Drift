<?php
declare(strict_types=1);

/**
 * C++ 桥接层的结构对账（V2 `cppbridge` step）。
 *
 * 锁三条「拆 TU 才会暴露、且 AOT 编译/链接期才炸」的约束：
 *   ① `cpp-src/core/` 平台无关 —— 零 windows.h / Win32 API；
 *   ② `comdlg32.lib` / `dwmapi.lib` 不在 `build/base.yml` 的 link-libs，
 *      靠 `#pragma comment(lib, ...)` 随 TU 走。搬走调用却没带 pragma ⇒ LNK2019；
 *   ③ ABI 入口 TU 必须引入 `php::*` 类型（`using namespace php;`）——
 *      `String`/`Int`/`Bool` 不是全局名，漏了这句 T1 全绿、AOT 编译期才炸。
 *
 * ★ 判据只认**去掉注释后的源码**：注释里的 API 名字会造成假绿/假红
 *   （`error-shape-check.php` 的 M1 变异踩过）。
 * ★ 纯 PHP 内建，不 require autoload —— 对账器不该依赖它要检查的那套代码。
 *
 * 用法：
 *   php tools/cpp-bridge-check.php              # 对账，exit 0/1
 *   php tools/cpp-bridge-check.php --selftest   # 合成源码变异自证
 */

/** @return array<string, string> 相对路径 => 真实源码 */
function cpp_bridge_sources(string $root): array
{
    $dir = $root . '/cpp-src';
    if (!\is_dir($dir)) {
        return [];
    }

    $out = [];
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
    /** @var \SplFileInfo $f */
    foreach ($it as $f) {
        if (!$f->isFile() || !\in_array($f->getExtension(), ['cc', 'h'], true)) {
            continue;
        }
        $abs = \str_replace('\\', '/', $f->getPathname());
        $rel = 'cpp-src/' . \ltrim(\substr($abs, \strlen(\str_replace('\\', '/', $root) . '/cpp-src/')), '/');
        $out[$rel] = (string) \file_get_contents($abs);
    }
    \ksort($out);

    return $out;
}

function cpp_bridge_code(string $src): string
{
    $src = \preg_replace('~/\*.*?\*/~s', '', $src) ?? $src;
    $src = \preg_replace('~//[^\n]*$~m', '', $src) ?? $src;

    return $src;
}

/**
 * @param array<string, string> $sources
 * @return list<string> 违规项（空 = 全过）
 */
function cpp_bridge_missing(array $sources): array
{
    $missing = [];

    // ① core/ 零 Win32
    $win32 = ['#include <windows.h>', 'CreateThreadpoolIo', 'PostQueuedCompletionStatus', 'HWND', 'PostMessageW'];
    foreach ($sources as $path => $src) {
        if (!\str_starts_with($path, 'cpp-src/core/')) {
            continue;
        }
        $code = cpp_bridge_code($src);
        foreach ($win32 as $needle) {
            if (\str_contains($code, $needle)) {
                $missing[] = $path . ' 出现 Win32 依赖 ' . $needle . '（core/ 必须平台无关）';
                break;
            }
        }
    }

    // ② 扩展库的 pragma 必须与使用它的 API 同 TU
    $rules = [
        'comdlg32.lib' => ['GetOpenFileNameW', 'GetSaveFileNameW', 'CommDlgExtendedError'],
        'dwmapi.lib' => ['DwmSetWindowAttribute'],
    ];
    foreach ($sources as $path => $src) {
        if (!\str_ends_with($path, '.cc')) {
            continue;
        }
        $code = cpp_bridge_code($src);
        foreach ($rules as $lib => $apis) {
            $used = false;
            foreach ($apis as $api) {
                if (\str_contains($code, $api)) {
                    $used = true;
                    break;
                }
            }
            if (!$used) {
                continue;
            }
            $pragma = '~#pragma\s+comment\s*\(\s*lib\s*,\s*["\']' . \preg_quote($lib, '~') . '["\']\s*\)~i';
            if (!\preg_match($pragma, $code)) {
                $missing[] = $path . ' 用了 ' . $lib . ' 的 API 却没带 pragma（不在 link-libs ⇒ LNK2019）';
            }
        }
    }

    // ③ 拆 TU 首轮实测：`String` / `Int` / `Bool` 是 php::*，不是全局名。
    //    主体靠 `using namespace php;`，新 TU 漏这一句 ⇒ T1 全绿、AOT 编译期才炸。
    foreach ($sources as $path => $src) {
        if (!\str_ends_with($path, '.cc')) {
            continue;
        }
        $code = cpp_bridge_code($src);
        if (!\str_contains($code, 'php_drift_')) {
            continue;
        }
        if (!\str_contains($code, 'using namespace php;') && !\str_contains($code, 'php::')) {
            $missing[] = $path . ' 是 ABI 入口 TU 却没引入 php::* 类型（拆 TU 后 AOT 编译期才炸）';
        }
    }

    return $missing;
}

/**
 * 语义字段三向对账：`SemanticsTree::toArrays()` 的键（扣掉 devtools 专用）
 * 必须与 `php_drift_win_uia_push()` 里 `a.get()` 的键**完全相等**。
 *
 * ★ 加一个语义字段要改六处，漏掉 C++ 那处不报错：客户端只读到 VT_EMPTY。
 *   与 `SemanticsMetaTest::testSerializedKeysMatchNodeFields()` 合起来覆盖三向
 *   （Node 字段 ⇄ 导出键 ⇄ C++ 读取键）。
 *
 * @return list<string> 违规项（空 = 全过）
 */
function cpp_bridge_semantics_drift(string $treePhp, string $pushCc): array
{
    $tree = cpp_bridge_code($treePhp);
    $push = cpp_bridge_code($pushCc);

    if (!\preg_match('~public function toArrays\(\): array\s*\{(.*?)\n    \}~s', $tree, $m)) {
        return ['找不到 SemanticsTree::toArrays()'];
    }
    \preg_match_all('~\'([A-Za-z][A-Za-z0-9]*)\'\s*=>\s*\$n->~', $m[1], $tm);
    $phpKeys = \array_values(\array_unique($tm[1] ?? []));

    if (!\preg_match('~void php_drift_win_uia_push\([^)]*\)\s*\{(.*?)\n\}~s', $push, $pm)) {
        return ['找不到 php_drift_win_uia_push()'];
    }
    \preg_match_all('~a\.get\("([A-Za-z][A-Za-z0-9]*)"\)~', $pm[1], $cm);
    $cppKeys = \array_values(\array_unique($cm[1] ?? []));

    // `type` / `depth` 只进 devtools 快照，不进 UIA 树
    $expected = \array_values(\array_diff($phpKeys, ['type', 'depth']));
    \sort($expected);
    \sort($cppKeys);
    if ($expected !== $cppKeys) {
        return ['语义字段漂移：PHP 导出 ' . \json_encode($expected)
            . ' / C++ 读取 ' . \json_encode($cppKeys)];
    }

    return [];
}

/**
 * UIA provider 的**结构契约**（P0-2）。
 *
 * ★ 这是结构对账，不是行为判据：行为由 `tools/probe-uia-state.py` 在 AOT 真机上量。
 *   它盯的是「删一行分派 / 删一个属性分支 / 删一次 raise」这类静默退化 ——
 *   C++ 重编 170s/次，不可能为每组变异都重编。
 *
 * @return list<string> 违规项（空 = 全过）
 */
function cpp_bridge_uia_contract(string $uiaCc): array
{
    $code = cpp_bridge_code($uiaCc);
    if (!\preg_match('~static const Rule rules\[\] = \{(.*?)\n        \};~s', $code, $m)) {
        return ['找不到 UIA Pattern 分派表'];
    }

    $bad = [];
    foreach ([
        'UIA_InvokePatternId', 'UIA_ValuePatternId', 'UIA_TogglePatternId',
        'UIA_RangeValuePatternId', 'UIA_SelectionItemPatternId',
        'UIA_ExpandCollapsePatternId', 'UIA_SelectionPatternId',
    ] as $p) {
        if (\substr_count($m[1], $p) !== 1) {
            $bad[] = '分派表 ' . $p;
        }
    }
    foreach (['UIA_HelpTextPropertyId', 'UIA_AutomationIdPropertyId', 'UIA_AcceleratorKeyPropertyId'] as $p) {
        if (\substr_count($code, $p) !== 1) {
            $bad[] = '属性分支 ' . $p;
        }
    }
    foreach (['UiaRaiseAutomationPropertyChangedEvent', 'UIA_Text_TextChangedEventId'] as $p) {
        if (!\str_contains($code, $p)) {
            $bad[] = '事件 ' . $p;
        }
    }

    return $bad === [] ? [] : ['UIA 契约缺项/重复：' . \implode(', ', $bad)];
}

/** @return array<string, string> 全绿的合成源码 */
function cpp_bridge_good_sources(): array
{
    return [
        'cpp-src/core/io_pool.cc' => \implode("\n", [
            '#include "io_pool.h"',
            '#include <thread>',
            'void start() { std::thread t; }',
        ]),
        'cpp-src/win32/dialogs.cc' => \implode("\n", [
            '#pragma comment(lib, "comdlg32.lib")',
            'using namespace php;',
            'String php_drift_win_open_file(Int owner) { return String(); }',
            'BOOL ok = GetOpenFileNameW(&ofn);',
        ]),
        'cpp-src/win32/drift_win32.cc' => \implode("\n", [
            '#pragma comment(lib, "dwmapi.lib")',
            'using namespace php;',
            'void php_drift_win_present(Int hwnd) { }',
            'DwmSetWindowAttribute(hwnd, 0, &v, sizeof(v));',
        ]),
    ];
}

$selftest = \in_array('--selftest', \array_slice($argv, 1), true);
$failures = 0;

if ($selftest) {
    $good = cpp_bridge_good_sources();
    $mutate = static function (array $s, string $path, string $from, string $to) use (&$failures): array {
        if (!\str_contains($s[$path], $from)) {
            echo '  ✗ 变异没落地（' . $path . ' 找不到 ' . $from . "）\n";
            $failures++;
            return $s;
        }
        $s[$path] = \str_replace($from, $to, $s[$path]);
        return $s;
    };
    $report = static function (string $name, array $s, string $needle) use (&$failures): void {
        $got = \array_values(\array_filter(
            cpp_bridge_missing($s),
            static fn(string $m): bool => \str_contains($m, $needle)
        ));
        $ok = \count($got) === 1;
        echo ($ok ? '  ✓ ' : '  ✗ ') . $name . "\n";
        if (!$ok) {
            $failures++;
            \fwrite(STDERR, '    实得 ' . \json_encode($got) . "\n");
        }
    };

    $base = cpp_bridge_missing($good);
    echo ($base === [] ? '  ✓ ' : '  ✗ ') . "基线全绿\n";
    if ($base !== []) {
        $failures++;
        \fwrite(STDERR, '    实得 ' . \json_encode($base) . "\n");
    }

    $report('core/ 混入 windows.h ⇒ 红',
        $mutate($good, 'cpp-src/core/io_pool.cc', '#include <thread>', '#include <windows.h>' . "\n" . '#include <thread>'),
        '平台无关');
    $report('对话框 TU 丢 comdlg32 pragma ⇒ 红',
        $mutate($good, 'cpp-src/win32/dialogs.cc', '#pragma comment(lib, "comdlg32.lib")', ''),
        'comdlg32.lib');
    $report('主体丢 dwmapi pragma ⇒ 红',
        $mutate($good, 'cpp-src/win32/drift_win32.cc', '#pragma comment(lib, "dwmapi.lib")', ''),
        'dwmapi.lib');
    $report('ABI 入口 TU 漏 using namespace php ⇒ 红',
        $mutate($good, 'cpp-src/win32/dialogs.cc', "using namespace php;\n", ''),
        'php::*');

    // 注释里的 API 名不该被当成真实调用（防判据被注释骗）
    $onlyComment = $good;
    $onlyComment['cpp-src/win32/dialogs.cc'] = \str_replace(
        'BOOL ok = GetOpenFileNameW(&ofn);',
        '// 旧路径曾用 GetSaveFileNameW(&ofn);' . "\n" . 'BOOL ok = 1;',
        $onlyComment['cpp-src/win32/dialogs.cc']
    );
    $onlyComment['cpp-src/win32/dialogs.cc'] = \str_replace(
        '#pragma comment(lib, "comdlg32.lib")', '',
        $onlyComment['cpp-src/win32/dialogs.cc']
    );
    $got = cpp_bridge_missing($onlyComment);
    echo ($got === [] ? '  ✓ ' : '  ✗ ') . "注释里的 API 名不误报\n";
    if ($got !== []) {
        $failures++;
        \fwrite(STDERR, '    实得 ' . \json_encode($got) . "\n");
    }

    // ④ 语义字段对账：任一侧漏一个键都要红（不是「改了字段但没人读」的静默绿）
    $goodTree = <<<'PHP'
<?php
class T {
    public function toArrays(): array
    {
        $ns[] = [
            'role' => $n->role,
            'name' => $n->name,
            'hint' => $n->hint,
            'type' => $n->type,
            'depth' => $n->depth,
        ];
        return ['count' => 1, 'nodes' => $ns];
    }
}
PHP;
    $goodPush = <<<'CPP'
void php_drift_win_uia_push(Int hwnd, Array nodes) {
    node.role = a.get("role").toInt();
    node.name = a.get("name").toCString();
    node.hint = a.get("hint").toCString();
}
CPP;
    $semBase = cpp_bridge_semantics_drift($goodTree, $goodPush);
    echo ($semBase === [] ? '  ✓ ' : '  ✗ ') . "语义字段基线全绿\n";
    if ($semBase !== []) {
        $failures++;
        \fwrite(STDERR, '    实得 ' . \json_encode($semBase) . "\n");
    }
    $semReport = static function (string $name, string $tree, string $push) use (&$failures): void {
        $got = cpp_bridge_semantics_drift($tree, $push);
        $ok = \count($got) === 1;
        echo ($ok ? '  ✓ ' : '  ✗ ') . $name . "\n";
        if (!$ok) {
            $failures++;
            \fwrite(STDERR, '    实得 ' . \json_encode($got) . "\n");
        }
    };
    $semReport('toArrays 漏导出 hint ⇒ 红',
        \str_replace("            'hint' => \$n->hint,\n", '', $goodTree), $goodPush);
    $semReport('C++ 漏读 hint ⇒ 红', $goodTree,
        \str_replace("    node.hint = a.get(\"hint\").toCString();\n", '', $goodPush));

    // ⑤ UIA 契约：分派表 / 属性分支 / 事件缺一项都要红
    $patterns = [
        'UIA_InvokePatternId', 'UIA_ValuePatternId', 'UIA_TogglePatternId',
        'UIA_RangeValuePatternId', 'UIA_SelectionItemPatternId',
        'UIA_ExpandCollapsePatternId', 'UIA_SelectionPatternId',
    ];
    $goodUia = "static const Rule rules[] = {\n"
        . \implode("\n", \array_map(static fn(string $p): string => '    ' . $p . ',', $patterns))
        . "\n        };\n"
        . "UIA_HelpTextPropertyId UIA_AutomationIdPropertyId UIA_AcceleratorKeyPropertyId\n"
        . "UiaRaiseAutomationPropertyChangedEvent UIA_Text_TextChangedEventId\n";
    $uiaBase = cpp_bridge_uia_contract($goodUia);
    echo ($uiaBase === [] ? '  ✓ ' : '  ✗ ') . "UIA 契约基线全绿\n";
    if ($uiaBase !== []) {
        $failures++;
        \fwrite(STDERR, '    实得 ' . \json_encode($uiaBase) . "\n");
    }
    $uiaReport = static function (string $name, string $src) use (&$failures): void {
        $got = cpp_bridge_uia_contract($src);
        $ok = \count($got) === 1;
        echo ($ok ? '  ✓ ' : '  ✗ ') . $name . "\n";
        if (!$ok) {
            $failures++;
            \fwrite(STDERR, '    实得 ' . \json_encode($got) . "\n");
        }
    };
    $uiaReport('分派表删掉 SelectionPattern ⇒ 红',
        \str_replace('    UIA_SelectionPatternId,' . "\n", '', $goodUia));
    $uiaReport('删掉属性变更 raise ⇒ 红',
        \str_replace('UiaRaiseAutomationPropertyChangedEvent ', '', $goodUia));

    echo $failures === 0 ? "--- cpp-bridge selftest 10/10 ---\n" : "--- cpp-bridge selftest FAIL ({$failures}) ---\n";
    exit($failures === 0 ? 0 : 1);
}

$root = \dirname(__DIR__);
$sources = cpp_bridge_sources($root);
$missing = cpp_bridge_missing($sources);
$treePhp = (string) \file_get_contents($root . '/src/Input/SemanticsTree.php');
$pushCc = $sources['cpp-src/win32/drift_win32.cc'] ?? '';
$missing = \array_merge($missing, cpp_bridge_semantics_drift($treePhp, $pushCc));
$missing = \array_merge($missing, cpp_bridge_uia_contract($sources['cpp-src/win32/uia.cc'] ?? ''));
$core = \count(\array_filter(\array_keys($sources), static fn(string $p): bool => \str_starts_with($p, 'cpp-src/core/')));
$cc = \count(\array_filter(\array_keys($sources), static fn(string $p): bool => \str_ends_with($p, '.cc')));

if ($missing !== []) {
    echo '[cpp-bridge-check] FAIL  files=' . \count($sources) . " cc={$cc} core={$core}\n";
    foreach ($missing as $m) {
        echo '  - ' . $m . "\n";
    }
    exit(1);
}

echo '[cpp-bridge-check] ok  files=' . \count($sources) . " cc={$cc} core={$core}\n";
exit(0);
