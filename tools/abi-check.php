<?php
declare(strict_types=1);

/**
 * `abi-check` —— 跨层 ABI 契约的 T0 判据（stub 唯一事实源，cc / fakes 被校验）。
 *
 * 用法：
 *   php tools/abi-check.php              # 人类可读
 *   php tools/abi-check.php --json       # 机器可读（CI 固化 schemaHash）
 *   php tools/abi-check.php --selftest   # 变异体自证（判据自己也要被测）
 *
 * 退出码：0 = 一致 · 1 = 判据红 · 2 = 用法错（与 V2 `verify` step 同口径；环境错不当判据红）。
 *
 * ★ 为什么变异必须走真实源码文本：只测比较器会漏掉解析器自己的假绿 ——
 *   一条没匹配上的 str_replace 会让"必须红"的用例变成"没改东西所以绿"。
 */

require __DIR__ . '/autoload.php';

use Drift\Dev\Abi\Contract;

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
$selftest = in_array('--selftest', $args, true);
$json = in_array('--json', $args, true);
$unknown = array_values(array_diff($args, ['--selftest', '--json']));

if ($unknown !== []) {
    fwrite(STDERR, '[abi-check] 未知参数：' . implode(' ', $unknown) . "\n");
    exit(2);
}

if ($selftest) {
    exit(selfTest($root, $json));
}

$result = Contract::check($root);
if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} else {
    render($result);
}
exit($result['ok'] ? 0 : 1);

/** @param array{ok:bool, problems:list<string>, counts:array, schemaHash:string, fakeMissing:list<string>} $r */
function render(array $r): void
{
    printf(
        "[abi-check] stub=%d cc=%d fakes=%d schema=%s\n",
        $r['counts']['stub'],
        $r['counts']['cc'],
        $r['counts']['fakes'],
        $r['schemaHash']
    );
    if (!$r['ok']) {
        printf("[abi-check] ✗ %d 处不一致：\n", count($r['problems']));
        foreach ($r['problems'] as $p) {
            echo '  - ' . $p . "\n";
        }
        return;
    }
    printf(
        "[abi-check] ✓ 三处签名一致 · 替身覆盖 %d/%d · 未覆盖 %d 个（不算失败，清单见 --json）\n",
        $r['counts']['fakes'],
        $r['counts']['stub'],
        count($r['fakeMissing'])
    );
}

function selfTest(string $root, bool $json): int
{
    $t = Contract::readTexts($root);
    $parse = static function (string $stub, string $cc, string $fakes): array {
        return Contract::compare(
            Contract::parseStub($stub),
            Contract::parseCc($cc),
            Contract::parseFakes($fakes)
        );
    };

    $base = $parse($t['stub'], $t['cc'], $t['fakes']);
    $items = [];

    $cases = [
        // 四条必须红：对应 Flutter embedder.h 的「名称 / 个数 / 顺序 / 类型」+ 返回类型
        ['参数类型被改必须红', str_replace('int $width', 'float $width', $t['stub']), $t['cc'], $t['fakes'], false],
        ['参数顺序被换必须红', str_replace(
            'function drift_win_present(int $hwnd, string $rgba, int $width, int $height): void {}',
            'function drift_win_present(string $rgba, int $hwnd, int $width, int $height): void {}',
            $t['stub']
        ), $t['cc'], $t['fakes'], false],
        ['参数少一个必须红', str_replace(
            'function drift_win_present_skia(int $hwnd, int $canvas): void {}',
            'function drift_win_present_skia(int $hwnd): void {}',
            $t['stub']
        ), $t['cc'], $t['fakes'], false],
        ['返回类型被改必须红', str_replace('function drift_thread_id(): int {}', 'function drift_thread_id(): float {}', $t['stub']), $t['cc'], $t['fakes'], false],
        ['C++ 缺实现必须红', $t['stub'], str_replace('Int php_drift_win_create(', 'Int php_drift_win_create_GONE(', $t['cc']), $t['fakes'], false],
        ['替身签名被改必须红', $t['stub'], $t['cc'], str_replace('float $opacity', 'int $opacity', $t['fakes']), false],
        // 三条必须不红：ABI 不含注释 / 参数名，替身不必全覆盖
        ['只加注释不误报', "// abi-probe-variant\n" . $t['stub'], $t['cc'], $t['fakes'], true],
        ['只改参数名不误报', str_replace('int $hwnd', 'int $handle', $t['stub']), $t['cc'], $t['fakes'], true],
        ['三处同步新增函数不误报', $t['stub'] . "\n\n" . 'function drift_abi_probe(int $x): int {}', $t['cc'] . "\n\n" . 'Int php_drift_abi_probe(Int x) {' . "\n", $t['fakes'], true],
    ];

    foreach ($cases as [$label, $stub, $cc, $fakes, $expectOk]) {
        $changed = $stub !== $t['stub'] || $cc !== $t['cc'] || $fakes !== $t['fakes'];
        $r = $parse($stub, $cc, $fakes);
        $items[] = [
            'label' => $label,
            'ok' => $changed && $r['ok'] === $expectOk,
            'variantApplied' => $changed,
            'detected' => !$r['ok'],
        ];
    }

    // 指纹：同一份表稳定；加一个函数必变（否则"忘抬版本"照样假阴性）
    $again = Contract::schemaHash(Contract::parseStub($t['stub']));
    $withExtra = Contract::schemaHash(Contract::parseStub(
        $t['stub'] . "\n\n" . 'function drift_abi_probe(int $x): int {}'
    ));
    $items[] = [
        'label' => '指纹稳定且随签名变化',
        'ok' => $base['ok'] && $base['schemaHash'] === $again && $again !== $withExtra,
        'variantApplied' => true,
        'detected' => false,
    ];

    $failed = count(array_filter($items, static fn(array $i): bool => !$i['ok']));

    if ($json) {
        echo json_encode([
            'ok' => $failed === 0,
            'baselineOk' => $base['ok'],
            'schemaHash' => $base['schemaHash'],
            'cases' => $items,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    } else {
        foreach ($items as $i) {
            printf("%s  %s%s\n", $i['ok'] ? 'ok  ' : 'FAIL', $i['label'], $i['variantApplied'] ? '' : '（变异没落地）');
        }
        printf("--- %d 项全过（%d 条）---\n", $failed === 0 ? 1 : 0, count($items));
    }

    return $failed === 0 ? 0 : 1;
}
