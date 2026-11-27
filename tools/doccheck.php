<?php
declare(strict_types=1);

/**
 * 文档体系自检：链接可达、页面结构、无行号锚点、无旧路径、裁决表完整、导航无孤儿。
 * 用法：php tools/doccheck.php
 */

const DOC_SECTIONS = ['start', 'guides', 'concepts', 'reference', 'decisions', 'contributing'];

const OLD_DOC_PATHS = [
    'docs/00-README.md', 'docs/architecture/', 'docs/arch-v2/', 'docs/_archive/',
    'docs/tutorial.md', 'docs/typephp-issues/', 'DESIGN.md', 'AOT-CONSTRAINTS.md',
    'DEV-MODE-', 'CUSTOM-WIDGET-GUIDE-', 'RELEASE-DEPS.md', 'DEBT-', 'PLAN-',
    'REVIEW-', 'DESIGN-', 'ANALYSIS-', 'EVAL-', 'SUMMARY-UI-DATA', 'GAP-ANALYSIS',
    'ARCH-REVIEW', 'UI-THREAD-MODEL', 'EVENT-LOOP', 'MULTI-WINDOW',
    'S4-DELETE-LIST', 'TEST-STRATEGY', 'COMMENT-STYLE', 'RELEASE-READINESS',
];

/** @return list<string> */
function collectDocs(string $dir): array
{
    $found = [];
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            $found = array_merge($found, collectDocs($path));
        } elseif (str_ends_with($entry, '.md')) {
            $found[] = $path;
        }
    }
    sort($found);

    return $found;
}

/** @return list<string> */
function linkTargets(string $src): array
{
    preg_match_all('/\[[^\]]*\]\(([^)]+)\)/u', $src, $matches);
    $targets = [];
    foreach ($matches[1] as $raw) {
        $target = trim(explode(' ', trim($raw))[0]);
        if ($target === '' || str_starts_with($target, '#') || preg_match('#^[a-z]+://#i', $target)) {
            continue;
        }
        $targets[] = explode('#', $target)[0];
    }

    return $targets;
}

function normalizeRelative(string $path): string
{
    $parts = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            if ($parts !== [] && end($parts) !== '..') {
                array_pop($parts);
            } else {
                $parts[] = '..';
            }
        } else {
            $parts[] = $segment;
        }
    }

    return implode('/', $parts);
}

/** @return list<string> */
function lineAnchors(string $src): array
{
    $hits = [];
    preg_match_all(
        '#\b((?:src|cpp-src|tools|tests|apps|docs|golden)/[A-Za-z0-9_/.-]+|[A-Za-z0-9_-]+)\.(?:php|cc|h|py|md|yml):\d+(?:-\d+)?\b#',
        $src,
        $m
    );
    foreach ($m[0] as $hit) {
        $hits[] = $hit;
    }
    preg_match_all('/`:[0-9]+`/u', $src, $m2);
    foreach ($m2[0] as $hit) {
        $hits[] = $hit;
    }

    return $hits;
}

$root = dirname(__DIR__);
require_once $root . '/tools/autoload.php';
$docs = $root . '/docs';
$files = collectDocs($docs);
$errors = [];
$linkCount = 0;
$decisionCount = 0;

foreach ($files as $file) {
    $rel = substr($file, strlen($root) + 1);
    $src = (string) file_get_contents($file);
    $lines = explode("\n", $src);

    if (!preg_match('/^# \S/u', $src)) {
        $errors[] = $rel . ': 缺少一级标题';
    }
    $head = implode("\n", array_slice($lines, 0, 5));
    if (!preg_match('/^> \S/mu', $head)) {
        $errors[] = $rel . ': 缺少题记（一级标题后的一段说明）';
    }

    $anchors = lineAnchors($src);
    if ($anchors !== []) {
        $errors[] = $rel . ': 出现行号锚点 ' . implode(', ', $anchors);
    }

    foreach (OLD_DOC_PATHS as $old) {
        if (str_contains($src, $old)) {
            $errors[] = $rel . ': 引用了旧文档路径 ' . $old;
        }
    }

    $relative = substr($rel, strlen('docs/'));
    $first = explode('/', $relative)[0];
    if (str_contains($relative, '/') && !in_array($first, DOC_SECTIONS, true)) {
        $errors[] = $rel . ': 文档不在允许的分区下';
    }

    foreach (linkTargets($src) as $target) {
        $linkCount++;
        $full = dirname($file) . '/' . $target;
        if (!is_file($full)) {
            $errors[] = $rel . ': 死链 ' . $target;
        }
    }
}

$index = $docs . '/README.md';
$linked = [];
foreach (linkTargets((string) file_get_contents($index)) as $target) {
    $full = $docs . '/' . normalizeRelative($target);
    if (is_file($full)) {
        $linked[$full] = true;
    }
}
foreach ($files as $file) {
    if ($file !== $index && !isset($linked[$file])) {
        $errors[] = substr($file, strlen($root) + 1) . ': 导航首页没有链接到这页（孤儿页）';
    }
}

$decisions = (string) file_get_contents($docs . '/decisions/README.md');
foreach (explode("\n", $decisions) as $line) {
    if (!preg_match('/^\|\s*[ACNPE]\d+\s*\|/u', $line)) {
        continue;
    }
    $decisionCount++;
    $cells = explode('|', $line);
    $expiry = isset($cells[4]) ? trim($cells[4]) : '';
    if ($expiry === '' || $expiry === '-') {
        $errors[] = 'docs/decisions/README.md: 裁决缺少失效条件 ' . trim($cells[1] ?? '');
    }
}

foreach (\Drift\DevTools\Cli\Doc::problems((string) file_get_contents($docs . '/reference/cli.md')) as $problem) {
    $errors[] = 'docs/reference/cli.md: ' . $problem;
}

fwrite(STDOUT, sprintf("文档检查：%d 篇 / %d 条链接 / %d 条裁决\n", count($files), $linkCount, $decisionCount));
if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, '  ERROR ' . $error . "\n");
    }
    fwrite(STDERR, sprintf("文档检查失败：%d 个问题\n", count($errors)));
    exit(1);
}
fwrite(STDOUT, "文档检查通过：链接、结构、裁决表与导航均完整\n");
