<?php
declare(strict_types=1);

/**
 * drift-preview —— 无头跑完整 layout + paint，产出 SVG（§16.3 / §A.5）。
 *
 * ```bash
 * php tools/drift-preview.php --page=tools/Preview/demo.php [--width=320] [--height=240]
 *                              [--dpr=1] [--theme=light|dark] [--out=out.svg]
 *                              [--fixture=store.php] [--ascii]
 * ```
 *
 * `--page` 指向一个返回 `Widget` 的 PHP 文件（页面级预览，§12.2）。带参页面用
 * `--fixture` 指向一个返回 store 实例的文件 —— 页面类名约定与 §4.5 的
 * `pages/` 一页一文件一致：文件名 `UsersPage.php` ⇒ 类 `UsersPage`，
 * 构造参数取 `$store`（fixture 的返回值）。
 *
 * ★ **不编译、不依赖 OS**（§16.3）：软光栅 + 纯 PHP ⇒ 内环秒级。
 * ★ **同一份渲染路径**：`Frame` → `DisplayList` → `RasterCanvas` ⇒ 预览到的就是
 *   生产会画的（§14.1"渲染是纯函数"），不是另一套近似。
 */

use Drift\Dev\Preview\Renderer;
use Drift\Dev\Preview\SvgSurface;
use Drift\Rendering\Color;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Theme\SystemTheme;
use Drift\Theme\ThemeData;
use Drift\App\Application;
use Drift\Widget\Widget;

require __DIR__ . '/autoload.php';

/** @return array<string, string|bool|null> */
function parseArgs(array $argv): array
{
    $args = [];
    foreach ($argv as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, 'true');
        $args[$key] = $value;
    }

    return $args;
}

/** @param array<string, string|bool|null> $args */
function argStr(array $args, string $key, string $default): string
{
    $value = $args[$key] ?? $default;

    return is_string($value) ? $value : $default;
}

$args = parseArgs($argv ?? []);
$page = argStr($args, 'page', '') ?: argStr($args, 'app', '');

if ($page === '') {
    fwrite(STDERR, "用法：php tools/drift-preview.php (--page=<Widget 的 php> | --app=<Application 的 php>) [--width=320] [--height=240] [--dpr=1] [--theme=light|dark] [--out=x.svg] [--fixture=store.php] [--ascii]\n");
    exit(2);
}

if (!is_file($page)) {
    fwrite(STDERR, "找不到页面文件：{$page}\n");
    exit(2);
}

$width = (float) argStr($args, 'width', '320');
$height = (float) argStr($args, 'height', '240');
$dpr = (float) argStr($args, 'dpr', '1');
$theme = argStr($args, 'theme', 'light') === 'dark' ? ThemeData::dark() : ThemeData::light();
$fixture = argStr($args, 'fixture', '');

$systemTheme = argStr($args, 'theme', 'light') === 'dark' ? SystemTheme::Dark : SystemTheme::Light;
$theme = ThemeData::forSystem($systemTheme);

// ── 载入页面 ────────────────────────────────────────────────
$store = null;
if ($fixture !== '') {
    if (!is_file($fixture)) {
        fwrite(STDERR, "找不到 fixture 文件：{$fixture}\n");
        exit(2);
    }
    $store = require $fixture; // 约定：返回 store 实例
}

$before = get_declared_classes();
require $page;
$declared = array_values(array_diff(get_declared_classes(), $before));

if ($declared === []) {
    fwrite(STDERR, "页面文件没有声明任何类：{$page}\n");
    exit(2);
}

// 取**第一个**声明的类 —— 不是最后一个：`use` 语句的返回类型/父类解析会把框架类
// 也拉进来（`Widget` / `StatelessWidget`），取末个就可能拿到一个抽象基类。
$pageClass = reset($declared);
$target = $store === null ? new $pageClass() : new $pageClass($store);

// `--app` 与 `--page` 共用一个入口：载入的东西是 Application 就走 attach()
// （连带验 buildRoot/title/commands），是 Widget 就走 render()。
if (!$target instanceof Widget && !$target instanceof Application) {
    fwrite(STDERR, "{$pageClass} 既不是 Widget 也不是 Application\n");
    exit(2);
}

// ── 渲染 ────────────────────────────────────────────────────
$surface = new SvgSurface();
$renderer = new Renderer(
    $surface,
    new DeterministicMetricsProvider(
        DeterministicFontManager::standard()->collection(),
        new DeterministicShaper(),
    ),
    $theme,
);

$svg = $target instanceof Application
    ? $renderer->renderApp($target, $width, $height, $dpr)
    : $renderer->render($target, $width, $height, $dpr);

$out = argStr($args, 'out', '');
if ($out !== '') {
    $dir = dirname($out);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($out, $svg);
    fwrite(STDOUT, "已写出 {$out}（" . strlen($svg) . " 字节）\n");
} else {
    fwrite(STDOUT, $svg . "\n");
}

// ── 尺寸摘要：终端里确认"这页真的排开了" ────────────────────
if (($args['ascii'] ?? null) === 'true') {
    $box = $renderer->effectiveRoot();
    fwrite(STDOUT, "\n根盒：" . ($box === null ? 'null' : sprintf(
        '%.1f × %.1f（逻辑）｜设备 %d × %d 像素',
        $box->size()->width,
        $box->size()->height,
        (int) round($width * $dpr),
        (int) round($height * $dpr),
    )) . "\n");
    fwrite(STDOUT, 'SVG：' . substr_count($svg, '<rect x=') . " 条像素 run\n");
}

exit(0);
