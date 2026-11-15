<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Docs;

use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 活文档分册的引用纪律（根 `DESIGN.md` §9）。
 *
 * ★ 行号每次改代码都漂：治理前 14 分册有 167 个完整路径锚点 + 46 个简写锚点
 *   （`` `:NN` ``），其中只有约四成指向真正的声明行，其余指向注释、方法体中段或空行。
 *   纪律是「只给文件 + 符号」，不是「把行号改对」—— 改对了下一轮又漂。
 * ★ 只管 `docs/architecture/`。`docs/` 顶层的历史稿 / 评审稿绑定当时快照，
 *   行号是事实记录，按 `DESIGN.md` §9 不改写。
 */
final class ArchitectureDocTest extends SmokeTestCase
{
    /** @var list<string> 集合封闭：新增分册必须显式登记，判据才不会静默漏检 */
    private const array LIVE_DOCS = [
        '00-README.md',
        '01-三层树与身份.md',
        '02-帧管线与异步.md',
        '03-布局与渲染盒.md',
        '04-绘制与双后端.md',
        '05-文本与字体栈.md',
        '06-输入-手势与焦点.md',
        '07-状态-订阅-命令.md',
        '08-控件面.md',
        '09-主题与语义.md',
        '10-平台端口与-Win32-壳.md',
        '11-编译器约束与-AOT-坑.md',
        '12-工程与工具.md',
        '13-与-v7-设计对照.md',
        '14-组合根与装配.md',
    ];

    public function testLiveDocSetIsClosed(): void
    {
        $actual = array_map('basename', glob($this->path('docs/architecture') . '/*.md') ?: []);
        sort($actual);
        $declared = self::LIVE_DOCS;
        sort($declared);

        check(
            '文档纪律 · 活文档分册集合封闭（新增分册必须显式登记）',
            $actual === $declared,
            'actual=' . implode(',', array_diff($actual, $declared))
                . ' missing=' . implode(',', array_diff($declared, $actual)),
        );

        $this->flushChecks();
    }

    public function testLiveDocsCarryNoLineAnchors(): void
    {
        foreach (glob($this->path('docs/architecture') . '/*.md') ?: [] as $file) {
            $anchors = $this->lineAnchors((string) file_get_contents($file));
            check(
                '文档纪律 · ' . basename($file) . ' 不写 file:line（行号会漂，按符号名定位）',
                $anchors === [],
                'anchors=' . implode(',', $anchors),
            );
        }

        $this->flushChecks();
    }

    public function testLiveDocsReferenceOnlyRealSourceFiles(): void
    {
        foreach (glob($this->path('docs/architecture') . '/*.md') ?: [] as $file) {
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

    public function testHistoryDraftsKeepTheirSnapshotAnchors(): void
    {
        $withAnchors = 0;
        foreach (glob($this->path('docs') . '/*.md') ?: [] as $file) {
            if ($this->lineAnchors((string) file_get_contents($file)) !== []) {
                $withAnchors++;
            }
        }

        check(
            '文档纪律 · 历史稿仍带当时快照的行号（证明没把历史改写成假的）',
            $withAnchors > 0,
            'files=' . $withAnchors,
        );

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
