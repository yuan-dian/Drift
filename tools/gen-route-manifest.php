<?php
declare(strict_types=1);

/**
 * 从应用页面类的 `#[Route]` 生成 AOT 页清单。
 *
 * 用法：
 *   php tools/gen-route-manifest.php <app>            # 写入，app 可为 showcase 或 apps/showcase
 *   php tools/gen-route-manifest.php <app> --check    # 只检查是否过期
 *   php tools/gen-route-manifest.php --selftest
 *
 * 应用根与命名空间从 composer.json 的 autoload.psr-4 推导，不内置应用名。
 * AOT 不扫目录，清单必须是编译期可见的常量数组；扫描只认 `src/Pages/*.php` 一层。
 * 顺序沿用现有清单，新页按类名追加，删页自动移除；`verify` 的 sceneset step 盯漂移。
 */

/** @return list<string> */
function route_manifest_order(array $scanned, array $existing): array
{
    $scan = \array_values(\array_unique($scanned));
    \sort($scan);

    $out = [];
    foreach ($existing as $class) {
        if (\in_array($class, $scan, true) && !\in_array($class, $out, true)) {
            $out[] = $class;
        }
    }
    foreach ($scan as $class) {
        if (!\in_array($class, $out, true)) {
            $out[] = $class;
        }
    }

    return $out;
}

/** @return list<string> */
function route_manifest_scan(string $dir, string $expectedNamespace): array
{
    $out = [];
    foreach (\glob($dir . '/*.php') ?: [] as $file) {
        $src = (string) \file_get_contents($file);
        if (\preg_match('/namespace\s+([^;]+)\s*;/', $src, $ns) !== 1) {
            continue;
        }
        if (\trim($ns[1]) !== $expectedNamespace) {
            continue;
        }
        if (\preg_match('/#\[Route\s*\([\s\S]*?\)\]\s*(?:final\s+)?(?:readonly\s+)?class\s+([A-Za-z_]\w*)/', $src, $m) === 1) {
            $out[] = $m[1];
        }
    }

    return $out;
}

/** @return list<string> */
function route_manifest_existing(string $path): array
{
    if (!\is_file($path)) {
        return [];
    }
    \preg_match_all('/Pages\\\\([A-Z]\w*)::class/', (string) \file_get_contents($path), $m);

    return $m[1];
}

/**
 * 从 composer 的 psr-4 表按应用 src 目录反查命名空间。
 *
 * @param array<string, list<string>|string> $psr4
 */
function route_manifest_namespace(array $psr4, string $appRoot, string $root): ?string
{
    $needle = \rtrim(\str_replace('\\', '/', $appRoot), '/') . '/src';
    $root = \rtrim(\str_replace('\\', '/', $root), '/');
    foreach ($psr4 as $prefix => $paths) {
        foreach ((array) $paths as $path) {
            $path = \str_replace('\\', '/', $path);
            if (!\str_starts_with($path, '/') && \preg_match('/^[A-Za-z]:\//', $path) !== 1) {
                $path = $root . '/' . $path;
            }
            if (\rtrim($path, '/') === $needle) {
                return \rtrim($prefix, '\\') . '\\';
            }
        }
    }

    return null;
}

/** @param list<string> $classes */
function route_manifest_render(string $namespace, array $classes): string
{
    $body = [
        '<?php',
        'declare(strict_types=1);',
        '',
        'namespace ' . $namespace . 'Routes;',
        '',
        '/**',
        ' * AOT 页清单，由 `php tools/gen-route-manifest.php <app>` 生成。',
        ' * 新增页先写 `#[Route]` 再跑生成器；手工改会在 `verify` 的 sceneset step 报红。',
        ' *',
        ' * @internal 启动期反射与量具专用',
        ' */',
        'final class RouteManifest',
        '{',
        '    /** @var list<class-string> */',
        '    public const array CLASSES = [',
    ];
    foreach ($classes as $class) {
        $body[] = "        \\{$namespace}Pages\\{$class}::class,";
    }
    $body[] = '    ];';
    $body[] = '}';
    $body[] = '';

    return \implode("\n", $body);
}

function route_manifest_usage(): never
{
    \fwrite(STDERR, "用法：php tools/gen-route-manifest.php <app> [--check] | --selftest\n");
    exit(2);
}

$args = \array_slice($argv, 1);
$selftest = \in_array('--selftest', $args, true);
$check = \in_array('--check', $args, true);
$positional = \array_values(\array_filter(
    $args,
    static fn(string $arg): bool => $arg !== '--check' && $arg !== '--selftest'
));

if ($selftest) {
    $psr4 = [
        'App\\Showcase\\' => ['apps/showcase/src/'],
        'App\\Users\\' => ['apps/users/src/'],
        'Drift\\' => ['src/'],
    ];
    $cases = [
        ['按 composer 映射反查 showcase', route_manifest_namespace($psr4, '/root/apps/showcase', '/root'), 'App\\Showcase\\'],
        ['按 composer 映射反查 users', route_manifest_namespace($psr4, '/root/apps/users', '/root'), 'App\\Users\\'],
        ['未知应用不给假命名空间', route_manifest_namespace($psr4, '/root/apps/ghost', '/root'), null],
        ['保留现有顺序', route_manifest_order(['BPage', 'APage'], ['BPage', 'APage']), ['BPage', 'APage']],
        ['新页按字典序追加', route_manifest_order(['BPage', 'APage', 'CPage'], ['BPage', 'APage']), ['BPage', 'APage', 'CPage']],
        ['删页自动移除', route_manifest_order(['APage'], ['APage', 'BPage']), ['APage']],
        ['重复扫描只留一份', route_manifest_order(['APage', 'APage'], []), ['APage']],
    ];
    $failures = 0;
    echo '[gen-route-manifest --selftest] ' . \count($cases) . " 条合成变异\n";
    foreach ($cases as [$name, $got, $want]) {
        $ok = $got === $want;
        echo ($ok ? '  ok   ' : '  FAIL ') . $name . "\n";
        if (!$ok) {
            $failures++;
            \fwrite(STDERR, '    实得 ' . \json_encode($got) . ' / 期望 ' . \json_encode($want) . "\n");
        }
    }
    echo $failures === 0 ? "全部咬合\n" : "{$failures} 条红\n";
    exit($failures === 0 ? 0 : 1);
}

if (\count($positional) !== 1) {
    route_manifest_usage();
}

$root = \dirname(__DIR__);
$appArg = \str_replace('\\', '/', $positional[0]);
$appDir = \str_contains($appArg, '/') ? $appArg : 'apps/' . $appArg;
$appRoot = \realpath($root . '/' . $appDir);
if ($appRoot === false) {
    \fwrite(STDERR, "找不到应用目录：{$appDir}\n");
    exit(2);
}
$composer = \json_decode((string) \file_get_contents($root . '/composer.json'), true);
$psr4 = $composer['autoload']['psr-4'] ?? [];
$namespace = route_manifest_namespace($psr4, $appRoot, $root);
if ($namespace === null) {
    \fwrite(STDERR, "composer.json 没有把 {$appDir}/src 映射到命名空间\n");
    exit(2);
}
$pagesDir = $appRoot . '/src/Pages';
if (!\is_dir($pagesDir)) {
    \fwrite(STDERR, "应用 {$appDir} 没有 src/Pages 目录\n");
    exit(2);
}
$target = $appRoot . '/src/Routes/RouteManifest.php';
$classes = route_manifest_order(
    route_manifest_scan($pagesDir, $namespace . 'Pages'),
    route_manifest_existing($target)
);
$rendered = route_manifest_render($namespace, $classes);

if ($check) {
    $current = \is_file($target) ? (string) \file_get_contents($target) : '';
    if ($current !== $rendered) {
        \fwrite(STDERR, "路由 manifest 过期：运行 php tools/gen-route-manifest.php {$positional[0]}\n");
        exit(1);
    }
    echo "路由 manifest 最新（{$appDir}，" . \count($classes) . " 页）\n";
    exit(0);
}

\file_put_contents($target, $rendered);
echo "路由 manifest 已生成（{$appDir}，" . \count($classes) . " 页）\n";
