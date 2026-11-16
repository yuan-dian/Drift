<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Docs;

use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 新文档体系的正文纪律：不写行号、源码路径不能是幽灵、裁决必须带失效条件。
 *
 * ★ 链接可达、页面结构与导航孤儿由 tools/doccheck.php 守；这里只守正文里指向
 *   源码的符号路径与裁决表结构，不把两份检查抄成同一份清单。
 */
final class ArchitectureDocTest extends SmokeTestCase
{
    /** @var list<string> 文档分区 */
    private const array SECTIONS = ['start', 'guides', 'concepts', 'reference', 'decisions', 'contributing'];

    public function testAllLiveDocsAvoidLineAnchors(): void
    {
        foreach ($this->liveDocs() as $file) {
            $anchors = $this->lineAnchors((string) file_get_contents($file));
            check(
                '文档纪律 · ' . basename($file) . ' 不写 file:line（行号会漂，按符号名定位）',
                $anchors === [],
                'anchors=' . implode(',', $anchors),
            );
        }

        $this->flushChecks();
    }

    public function testAllLiveDocsReferenceOnlyRealSourceFiles(): void
    {
        foreach ($this->liveDocs() as $file) {
            $ghosts = array_values(array_filter(
                $this->srcPaths((string) file_get_contents($file)),
                static fn (string $p): bool => !is_file(dirname(__DIR__, 3) . '/' . $p),
            ));
            check(
                '文档纪律 · ' . basename($file) . ' 引用的 src 路径真实存在',
                $ghosts === [],
                'ghosts=' . implode(',', $ghosts),
            );
        }

        $this->flushChecks();
    }

    public function testDecisionIndexCarriesExpiryConditions(): void
    {
        $src = (string) file_get_contents($this->path('docs/decisions/README.md'));
        $rows = 0;
        foreach (explode("\n", $src) as $line) {
            if (preg_match('/^\|\s*[ACNPE]\d+\s*\|/u', $line) !== 1) {
                continue;
            }
            $rows++;
            $cells = explode('|', $line);
            check(
                '裁决索引 · ' . trim($cells[1] ?? '') . ' 带失效条件',
                trim($cells[4] ?? '') !== '',
                'expiry=' . trim($cells[4] ?? ''),
            );
        }
        check('裁决索引 · 登记表不是空壳', $rows > 0, 'rows=' . $rows);

        $this->flushChecks();
    }

    public function testExtractorsCatchDrift(): void
    {
        $fake = "见 `src/Widget/Ghost.php:25` 与 `drift_win32.cc:378`；\n"
            . "简写（`:46`）、`src/Widget/Real.php` 不带行号。\n";
        check(
            '文档纪律 · 行号提取器抓得到三种形态（判据不是恒绿空壳）',
            count($this->lineAnchors($fake)) === 3,
            implode(',', $this->lineAnchors($fake)),
        );
        check(
            '文档纪律 · 行号提取器不误伤版本号与坐标',
            $this->lineAnchors('`16.7` ms、`(type, key)`、`src/Widget/Widget.php`、`v1.2.3`') === [],
            'false-positive',
        );
        check(
            '文档纪律 · src 路径提取器抓得到路径',
            $this->srcPaths($fake) === ['src/Widget/Ghost.php', 'src/Widget/Real.php'],
            implode(',', $this->srcPaths($fake)),
        );

        $this->flushChecks();
    }

    /** @return list<string> */
    private function liveDocs(): array
    {
        $files = [];
        foreach (self::SECTIONS as $section) {
            foreach (glob($this->path('docs/' . $section) . '/*.md') ?: [] as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /** @return list<string> 完整路径锚点 + 裸文件名锚点 + 简写 `:NN` */
    private function lineAnchors(string $src): array
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

        return array_values(array_unique($hits));
    }

    /** @return list<string> */
    private function srcPaths(string $src): array
    {
        preg_match_all('#\bsrc/[A-Za-z0-9_/.-]+\.php\b#', $src, $m);

        return array_values(array_unique($m[0]));
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }
}
