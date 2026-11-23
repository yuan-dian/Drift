<?php
declare(strict_types=1);

/**
 * 从 `#[Route]` 生成 showcase 的 AOT 页清单。
 *
 * 用法：
 *   php tools/gen-route-manifest.php           # 写入
 *   php tools/gen-route-manifest.php --check   # 只检查是否过期
 *   php tools/gen-route-manifest.php --selftest
 *
 * AOT 不扫目录，清单必须是编译期可见的常量数组。扫描只认 Pages/ 一层带 `#[Route]` 的类；
 * 顺序沿用现有清单，新页按类名追加，删页自动移除。`verify` 的 sceneset step 会盯住漂移。
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
function route_manifest_scan(string $dir): array
{
    $out = [];
    foreach (\glob($dir . '/*.php') ?: [] as $file) {
        $src = (string) \file_get_contents($file);
        if (\preg_match('/#\[Route\s*\([\s\S]*?\)\]\s*(?:final\s+)?(?:readonly\s+)?class\s+([A-Za-z_]\w*)/', $src, $m) === 1) {
            $out[] = $m[1];
        }
    }

    return $out;
}

/** @return list<string> */
function route_manifest_existing(string $manifest, string $legacy): array
{
    $path = \is_file($manifest) ? $manifest : $legacy;
    if (!\is_file($path)) {
        return [];
    }
    $src = (string) \file_get_contents($path);
    \preg_match_all('/Pages\\\\([A-Z]\w*)::class/', $src, $m);

    return $m[1];
}

/** @param list<string> $classes */
function route_manifest_render(array $classes): string
{
    $body = [
        '<?php',
        'declare(strict_types=1);',
        '',
        'namespace App\\Showcase\\Routes;',
        '',
        'use App\\Showcase\\Pages;',
        '',
        '/**',
        ' * AOT 页清单，由 `php tools/gen-route-manifest.php` 从 `#[Route]` 生成。',
        ' * 新增页先写注解再跑生成器；手工改会在 `verify` 的 sceneset step 报红。',
        ' *',
        ' * @internal 启动期反射与量具专用',
        ' */',
        'final class ShowcaseRouteManifest',
        '{',
        '    /** @var list<class-string> */',
        '    public const array CLASSES = [',
    ];
    foreach ($classes as $class) {
        $body[] = "        Pages\\{$class}::class,";
    }
    $body[] = '    ];';
    $body[] = '}';
    $body[] = '';

    return \implode("\n", $body);
}

$args = \array_slice($argv, 1);
foreach ($args as $arg) {
    if (!\in_array($arg, ['--check', '--selftest'], true)) {
        \fwrite(STDERR, "用法：php tools/gen-route-manifest.php [--check|--selftest]\n");
        exit(2);
    }
}

if (\in_array('--selftest', $args, true)) {
    $cases = [
        ['保留现有顺序', ['BPage', 'APage'], ['BPage', 'APage'], ['BPage', 'APage']],
        ['新页按字典序追加', ['BPage', 'APage', 'CPage'], ['BPage', 'APage'], ['BPage', 'APage', 'CPage']],
        ['删页自动移除', ['APage'], ['APage', 'BPage'], ['APage']],
        ['空旧清单走字典序', ['CPage', 'APage', 'BPage'], [], ['APage', 'BPage', 'CPage']],
        ['重复扫描只留一份', ['APage', 'APage'], [], ['APage']],
    ];
    $failures = 0;
    echo '[gen-route-manifest --selftest] ' . \count($cases) . " 条合成变异\n";
    foreach ($cases as [$name, $scanned, $existing, $want]) {
        $got = route_manifest_order($scanned, $existing);
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

$root = \dirname(__DIR__);
$pagesDir = $root . '/apps/showcase/src/Pages';
$manifestPath = $root . '/apps/showcase/src/Routes/ShowcaseRouteManifest.php';
$legacyPath = $root . '/apps/showcase/src/Routes/ShowcaseRoutes.php';
$classes = route_manifest_order(
    route_manifest_scan($pagesDir),
    route_manifest_existing($manifestPath, $legacyPath)
);
$rendered = route_manifest_render($classes);

if (\in_array('--check', $args, true)) {
    $current = \is_file($manifestPath) ? (string) \file_get_contents($manifestPath) : '';
    if ($current !== $rendered) {
        \fwrite(STDERR, "路由 manifest 过期：运行 php tools/gen-route-manifest.php\n");
        exit(1);
    }
    echo '路由 manifest 最新（' . \count($classes) . " 页）\n";
    exit(0);
}

\file_put_contents($manifestPath, $rendered);
echo '路由 manifest 已生成（' . \count($classes) . " 页）\n";
