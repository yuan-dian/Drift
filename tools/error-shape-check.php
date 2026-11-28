<?php
declare(strict_types=1);

/**
 * 「错误码贯通链路」的接线对账（V2 `errshape` step）。
 *
 * ★ 为什么需要：`AsyncValue::code()` 加对了不等于 UI 真拿得到 —— 只要
 *   `ProcessWorker` 不把 code 写进 JSON、`ProcessTaskHost` / `ThreadTaskHost`
 *   还原时改回 `new \RuntimeException`、`AsyncCommand::fail()` 只取消息，
 *   能力就是假的（跨进程任务是本仓主要异步后端）。PHPUnit 验「行为对不对」，
 *   这里验「五段接线还在不在」，不同源。
 * ★ 纯 PHP 内建，不 require autoload —— 对账器不该依赖它要检查的那套代码。
 * ★ 判据只认**去掉注释后的源码**：曾有对账器把注释里提到的调用
 *   当成真实调用，变异没咬红。
 *
 * 用法：
 *   php tools/error-shape-check.php              # 对账，exit 0/1
 *   php tools/error-shape-check.php --selftest   # 对账器自证（合成源码）
 */

/** 去掉 docblock / 整行注释，避免注释里的特征串造成假绿 */
function error_shape_code(string $src): string
{
    $src = \preg_replace('~/\*.*?\*/~s', '', $src) ?? $src;
    $src = \preg_replace('~^\s*//[^\n]*$~m', '', $src) ?? $src;

    return $src;
}

/**
 * @param array<string, string> $sources 文件相对路径 => 源码
 * @return list<string> 缺失的接线要素（空 = 全在）
 */
function error_shape_missing(array $sources): array
{
    $code = [];
    foreach ($sources as $path => $src) {
        $code[$path] = error_shape_code($src);
    }

    $has = static function (string $path, string $needle) use ($code): bool {
        return isset($code[$path]) && \str_contains($code[$path], $needle);
    };
    /** 按方法切段：数总次数挡不住「删了一处、另一处还在」（M6 曾这样漏过） */
    $section = static function (string $path, string $marker) use ($code): string {
        $src = $code[$path] ?? '';
        $start = \strpos($src, $marker);
        if ($start === false) {
            return '';
        }
        $next = \strpos($src, 'public function', $start + \strlen($marker));

        return \substr($src, $start, $next === false ? null : $next - $start);
    };

    $missing = [];

    // ① 单一提取点：自带 code 优先，普通异常回落 getCode()。
    $require = [
        'src/Async/Failure.php' => [
            'public static function codeOf(' => '缺 codeOf() 单一提取点',
            'instanceof self' => 'Failure 自带的 code 没被优先读',
            '->getCode()' => '普通异常的 getCode() 没取',
        ],
        'src/Async/AsyncValue.php' => [
            "string \$code = ''" => 'error() 没有第二参（旧形状会静默丢 code）',
            'public function code(): string' => 'UI 侧没有 code() 出口',
        ],
        'src/State/AsyncCommand.php' => [
            'Failure::codeOf(' => 'fail() 没走统一提取（写死空串就是能力假的）',
            'public function mapError(' => '应用侧 errorMapper 插槽没接',
        ],
        'src/App/ProcessWorker.php' => [
            "'code' => \$code" => 'worker 回复载荷不带 code',
            'Failure::codeOf($e)' => 'worker catch 没提取异常 code',
        ],
        'src/App/ProcessTaskHost.php' => [
            'new Failure(' => '进程池还原端改回 RuntimeException（code 丢在边界）',
        ],
        'src/App/ThreadTaskHost.php' => [
            'new Failure(' => 'IO 池还原端改回 RuntimeException（code 丢在边界）',
        ],
        'src/Async/Future.php' => [
            "'code' => Failure::codeOf(\$error)" => 'allSettled 的元素丢 code',
        ],
    ];

    foreach ($require as $path => $needles) {
        if (!isset($code[$path])) {
            $missing[] = "缺文件 {$path}";
            continue;
        }
        foreach ($needles as $needle => $why) {
            if (!$has($path, $needle)) {
                $missing[] = "{$why}：{$path} 缺 {$needle}";
            }
        }
    }

    // ② code 必须跟着换态走：carriedFrom / withProgress 各自透传，code() 真的返回它。
    foreach (['carriedFrom', 'withProgress'] as $method) {
        $body = $section('src/Async/AsyncValue.php', 'public function ' . $method);
        if ($body === '' || !\str_contains($body, '$this->code')) {
            $missing[] = "AsyncValue::{$method}() 换态时丢了 code（错误态带旧值刷新会归零）";
        }
    }
    if (!\str_contains($section('src/Async/AsyncValue.php', 'public function code('), 'return $this->code')) {
        $missing[] = 'AsyncValue::code() 没返回 $this->code（UI 拿到恒空串）';
    }

    return $missing;
}

/**
 * 一份「五段接线齐全」的合成源码，供 --selftest 做正对照。
 *
 * @return array<string, string>
 */
function error_shape_good_sources(): array
{
    return [
        'src/Async/Failure.php' => \implode("\n", [
            'final class Failure extends \RuntimeException {',
            '  public static function codeOf(\Throwable $error): string {',
            '    if ($error instanceof self) { return $error->errorCode(); }',
            '    $c = $error->getCode();',
            '    return $c == 0 ? "" : (string) $c;',
            '  }',
            '}',
        ]),
        'src/Async/AsyncValue.php' => \implode("\n", [
            "public static function error(string \$message, string \$code = ''): self { }",
            'public function code(): string { return $this->code; }',
            'public function carriedFrom(self $p): self { return new self(0, null, false, "", 0.0, false, $this->code); }',
            'public function withProgress(float $f): self { return new self(0, null, false, "", $f, true, $this->code); }',
        ]),
        'src/State/AsyncCommand.php' => \implode("\n", [
            'public function mapError(?callable $m): self { }',
            '$code = Failure::codeOf($error);',
        ]),
        'src/App/ProcessWorker.php' => \implode("\n", [
            "\$encoded = json_encode(['id' => \$id, 'ok' => false, 'error' => \$message, 'code' => \$code]);",
            'return self::error($id, get_class($e), Failure::codeOf($e));',
        ]),
        'src/App/ProcessTaskHost.php' => \implode("\n", [
            'new Failure((string) ($msg["error"] ?? ""), (string) ($msg["code"] ?? "")),',
        ]),
        'src/App/ThreadTaskHost.php' => \implode("\n", [
            'new Failure((string) ($row["error"] ?? ""), (string) ($row["code"] ?? "")),',
        ]),
        'src/Async/Future.php' => \implode("\n", [
            "\$take(['ok' => false, 'value' => null, 'error' => \$error->getMessage(), 'code' => Failure::codeOf(\$error)]);",
        ]),
    ];
}

$selftest = \in_array('--selftest', \array_slice($argv, 1), true);
$failures = 0;

if ($selftest) {
    $good = error_shape_good_sources();
    $mutate = static function (array $sources, string $path, string $from, string $to): array {
        $sources[$path] = \str_replace($from, $to, $sources[$path]);

        return $sources;
    };
    $report = static function (string $name, array $sources, string $needle) use (&$failures): void {
        $got = \array_values(\array_filter(
            error_shape_missing($sources),
            static fn(string $m): bool => \str_contains($m, $needle)
        ));
        $ok = \count($got) === 1;
        echo ($ok ? '  ✓ ' : '  ✗ ') . $name . "\n";
        if (!$ok) {
            $failures++;
            \fwrite(STDERR, '    实得 ' . \json_encode($got) . "\n");
        }
    };

    $cases = [
        ['name' => '接线齐全 ⇒ 绿', 'got' => error_shape_missing($good), 'want' => []],
    ];
    foreach ($cases as $case) {
        $ok = $case['got'] === $case['want'];
        echo ($ok ? '  ✓ ' : '  ✗ ') . $case['name'] . "\n";
        if (!$ok) {
            $failures++;
            \fwrite(STDERR, '    实得 ' . \json_encode($case['got']) . "\n");
        }
    }

    $report(
        '删 codeOf() ⇒ 报缺单一提取点',
        $mutate($good, 'src/Async/Failure.php', 'public static function codeOf(', 'public static function legacy('),
        '缺 codeOf() 单一提取点'
    );
    $report(
        'docblock 里写 codeOf() 但真实实现删了 ⇒ 仍报缺（防注释假绿）',
        $mutate($good, 'src/Async/Failure.php', 'public static function codeOf(', "/** `codeOf()` */\npublic static function legacy("),
        '缺 codeOf() 单一提取点'
    );
    $report(
        'error() 退回单参 ⇒ 报旧形状',
        $mutate($good, 'src/Async/AsyncValue.php', "string \$code = ''", ''),
        '没有第二参'
    );
    $report(
        '删 code() 出口 ⇒ 报 UI 拿不到',
        $mutate($good, 'src/Async/AsyncValue.php', 'public function code(): string', 'public function legacy(): string'),
        '没有 code() 出口'
    );
    $report(
        'carriedFrom 丢 code ⇒ 报换态会丢',
        $mutate($good, 'src/Async/AsyncValue.php', 'false, $this->code); }' . "\n" . 'public function withProgress', 'false, ""); }' . "\n" . 'public function withProgress'),
        '换态时丢了 code'
    );
    $report(
        'withProgress 丢 code ⇒ 报换态会丢（另一处，防「一处还在就算过」）',
        $mutate($good, 'src/Async/AsyncValue.php', '$f, true, $this->code); }', '$f, true, ""); }'),
        '换态时丢了 code'
    );
    $report(
        'code() 不返回字段 ⇒ 报 UI 恒空串',
        $mutate($good, 'src/Async/AsyncValue.php', 'public function code(): string { return $this->code; }', 'public function code(): string { return ""; }'),
        '恒空串'
    );
    $report(
        'fail() 写死空串 ⇒ 报没走统一提取',
        $mutate($good, 'src/State/AsyncCommand.php', 'Failure::codeOf($error)', "''"),
        '没走统一提取'
    );
    $report(
        '删 mapError() ⇒ 报策略插槽没接',
        $mutate($good, 'src/State/AsyncCommand.php', 'public function mapError(', 'public function legacy('),
        'errorMapper 插槽没接'
    );
    $report(
        'worker 载荷删 code ⇒ 报 JSON 不带 code',
        $mutate($good, 'src/App/ProcessWorker.php', "'code' => \$code", ''),
        '回复载荷不带 code'
    );
    $report(
        '进程池还原改回 RuntimeException ⇒ 报边界丢 code',
        $mutate($good, 'src/App/ProcessTaskHost.php', 'new Failure(', 'new \RuntimeException('),
        '进程池还原端'
    );
    $report(
        'IO 池还原改回 RuntimeException ⇒ 报边界丢 code',
        $mutate($good, 'src/App/ThreadTaskHost.php', 'new Failure(', 'new \RuntimeException('),
        'IO 池还原端'
    );
    $report(
        'allSettled 丢 code ⇒ 报组合链丢 code',
        $mutate($good, 'src/Async/Future.php', "'code' => Failure::codeOf(\$error)", ''),
        'allSettled 的元素丢 code'
    );
    $report(
        '整个文件缺失 ⇒ 报缺文件',
        (static function (array $s): array { unset($s['src/Async/Failure.php']); return $s; })($good),
        '缺文件'
    );

    echo $failures === 0
        ? "[error-shape-check --selftest] ✓ 全过\n"
        : "[error-shape-check --selftest] ✗ {$failures} 条红\n";
    exit($failures === 0 ? 0 : 1);
}

$root = \dirname(__DIR__);
$paths = [
    'src/Async/Failure.php',
    'src/Async/AsyncValue.php',
    'src/State/AsyncCommand.php',
    'src/App/ProcessWorker.php',
    'src/App/ProcessTaskHost.php',
    'src/App/ThreadTaskHost.php',
    'src/Async/Future.php',
];
$sources = [];
foreach ($paths as $path) {
    $sources[$path] = (string) \file_get_contents($root . '/' . $path);
}

echo "[error-shape-check] 对账错误码贯通链路（worker → 两个 TaskHost → AsyncCommand → AsyncValue / allSettled）\n";
$missing = error_shape_missing($sources);
if ($missing !== []) {
    foreach ($missing as $m) {
        \fwrite(STDERR, "  - {$m}\n");
    }
    exit(1);
}

echo "[error-shape-check] ✓ 五段接线齐全，code 不丢在跨进程边界\n";
exit(0);
