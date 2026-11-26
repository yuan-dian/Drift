<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Debug;

use Drift\Dev\Debug\View\Fit;
use Drift\Dev\Debug\View\Report;
use PHPUnit\Framework\TestCase;

/**
 * 帧因果报告的文本面：分段标题、表格列边界、裁剪账、纯函数。
 *
 * ## 为什么这段现在才第一次有判据
 *
 * 排版原先住在 `tools/debug-report.php` 的 `render_text()` 里，往 `STDOUT` 写、还带
 * `--top` 裁剪 —— 想断言一行，只能把整个脚本跑一遍再比字符串。于是它长期没有判据，
 * 也就长期歪着（「耗时分布」的毫秒列遇中文差 4 列）。搬成 `Report::lines()` 之后，
 * `$report` 是个数组，判据可以只喂它几百字节的 fixture。
 *
 * ## 钉什么、不钉什么
 *
 * 钉**结构与列边界**，不钉整行字符串：改一次列宽不该让这些判据全红，但"两行的毫秒
 * 列没对齐"必须红。数字本身也不重复算一遍 —— 那是 `run_once()` 与查询层的账。
 */
final class ReportViewTest extends TestCase
{
    /**
     * 两帧的最小报告：一帧纯重绘（跳过 settle+paint），一帧重建 3 个节点。
     *
     * @return array<string, mixed>
     */
    private static function report(): array
    {
        return [
            'app' => 'table',
            'replayed' => false,
            'viewport' => [800, 600],
            'mountMs' => 12.5,
            'hostPid' => null,
            'stats' => ['frames' => 2, 'nodes' => 42],
            'frames' => [
                [
                    'id' => 1, 't' => 0.0, 'cost' => 0.3, 'wall' => 4.2,
                    'rebuilt' => 0, 'causes' => 0, 'unattributedMs' => 0.1,
                    'settleSkipped' => true, 'paintSkipped' => true,
                    'stageMs' => ['settle' => 0.1],
                ],
                [
                    'id' => 2, 't' => 16.0, 'cost' => 35.9, 'wall' => 38.4,
                    'rebuilt' => 3, 'causes' => 3, 'unattributedMs' => 0.04,
                    'settleSkipped' => false, 'paintSkipped' => false,
                    'stageMs' => ['settle' => 27.5, 'layout' => 6.9, 'tickAnimations' => 0.01],
                ],
            ],
            'attribution' => [],
            'hotspots' => [],
            'hiddenHotspots' => 0,
            'nodes' => [],
            'hiddenNodes' => 0,
            'why' => null,
            'cascade' => [
                'frame' => 2, 'slowestFrame' => 2, 'rebuilt' => 3, 'depthMax' => 3,
                'roots' => [], 'omitted' => 0, 'hidden' => 0,
                'byType' => [['type' => 'Text', 'nodes' => 3]],
            ],
            'script' => ['steps' => 2, 'frames' => 2, 'inputs' => 1, 'effects' => 0, 'digest' => 'daf931c0b76c'],
        ];
    }

    /**
     * 标题行之后的正文（到空行为止）。表格都是"标题 + 空行 + 表"的形式。
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function after(array $lines, string $heading): array
    {
        $at = null;
        foreach ($lines as $i => $line) {
            if (\str_starts_with($line, $heading)) {
                $at = $i;
                break;
            }
        }
        self::assertNotNull($at, "找不到「{$heading}」这一段");

        $out = [];
        for ($i = $at + 1; $i < \count($lines); ++$i) {
            if ($lines[$i] === '') {
                break;
            }
            $out[] = $lines[$i];
        }

        return $out;
    }

    public function testEverySectionIsPresent(): void
    {
        $text = \implode("\n", Report::lines(self::report()));

        foreach (['应用', '视口', '墙钟', '脚本', '耗时分布', '重建构成', '因果覆盖', '根因', '重建聚合', '逐帧归因'] as $heading) {
            $this->assertStringContainsString($heading, $text, "缺了「{$heading}」这一段");
        }
    }

    /**
     * 分段耗时表的毫秒列必须逐行右边界相同。
     *
     * 这是那条真实 bug 的判据：`（段外）` 是宽字符，旧排版按字节补空格，它那一行比
     * `settle` 短 4 列，而屏幕上看不出是"排版错"还是"数字小"。
     */
    public function testStageTableColumnsLineUpEvenWithWideCharacters(): void
    {
        $rows = self::after(Report::lines(self::report()), '耗时分布');
        $this->assertGreaterThanOrEqual(4, \count($rows), '三个阶段 + 段外');

        // 比"ms 这个后缀结束在第几列"，而不是整行宽度：段外那一行还挂着
        // 「← 不在任何段里」的说明，整行当然更长。
        $ends = [];
        foreach ($rows as $line) {
            $at = \strpos($line, 'ms');
            $this->assertNotFalse($at, "「{$line}」里没有毫秒值");
            $ends[] = Fit::width(\substr($line, 0, $at + 2));
        }
        $this->assertCount(1, \array_unique($ends), '毫秒列的右边界必须逐行相同：' . \implode(' / ', $rows));
        $this->assertStringContainsString('（段外）', \implode("\n", $rows), '段外没有被静默丢掉');
    }

    /** 帧表的表头与数据必须落在同一组列上：表头错侧比错列更误导。 */
    public function testFrameTableHeaderMatchesTheDataColumns(): void
    {
        $lines = Report::lines(self::report());
        $at = null;
        foreach ($lines as $i => $line) {
            if (\str_contains($line, '跳过') && \str_starts_with($line, '  帧号')) {
                $at = $i;
                break;
            }
        }
        $this->assertNotNull($at, '找不到帧表表头');

        $header = $lines[$at];
        $first = $lines[$at + 1];
        $headerSkip = Fit::width(\substr($header, 0, \strpos($header, '跳过')));
        $firstSkip = Fit::width(\substr($first, 0, \strpos($first, 'settle')));

        $this->assertSame($headerSkip, $firstSkip, '「跳过」列的表头与数据必须同起点');
    }

    /** 帧内与帧外分列：只看"帧内"会让人以为这一帧很便宜。 */
    public function testOutsideFrameTimeIsReportedSeparately(): void
    {
        $lines = Report::lines(self::report());
        $row = null;
        foreach ($lines as $line) {
            if (\str_starts_with($line, '     1 ')) {
                $row = $line;
                break;
            }
        }
        $this->assertNotNull($row, '帧 #1 的数据行');

        // 帧 #1：墙钟 4.2、帧内 0.3 ⇒ 帧外 3.90；两段都被跳过。
        $this->assertStringContainsString('3.90', $row, '帧外 = 墙钟 - 帧内');
        $this->assertStringContainsString('settle+paint', $row, '跳过的段要标出来');
    }

    /** 裁剪必须自报：不说，读者就把"我没看到"当成"不存在"。 */
    public function testTruncationAlwaysComesWithAnAccount(): void
    {
        $report = self::report();
        $report['hiddenNodes'] = 350;
        $report['nodes'] = [
            ['rebuilds' => 1, 'id' => 1, 'subtree' => 360, 'self' => 1, 'inherited' => 0, 'path' => 'PageShell'],
            ['rebuilds' => 1, 'id' => 2, 'subtree' => 359, 'self' => 0, 'inherited' => 1, 'path' => 'PageShell > Card'],
        ];

        $text = \implode("\n", Report::lines($report));
        $this->assertStringContainsString('还有 350 个节点没列出', $text);
        $this->assertStringContainsString('次数无区分度', $text, '每行重建次数相同时要说明排序依据');
    }

    /** 没有状态写入就说没有：空表格冒充"没问题"是证据面最不能出的错。 */
    public function testEmptySectionsSaySoInsteadOfRenderingAnEmptyTable(): void
    {
        $text = \implode("\n", Report::lines(self::report()));

        $this->assertStringContainsString('（这段里没有状态写入', $text);
        $this->assertStringContainsString('（这段里没有节点重建）', $text);
        $this->assertStringContainsString('（没有带根因的帧）', $text);
        $this->assertStringNotContainsString('为什么 #', $text, '没问 --why 就不该凭空出现这一段');
    }

    /** 纯函数：同一份报告跑两遍逐字节相同（无时间戳、无随机、排序确定）。 */
    public function testSameReportGivesTheSameBytes(): void
    {
        $this->assertSame(Report::lines(self::report()), Report::lines(self::report()));
    }

    public function testNoTrailingWhitespaceOnAnyLine(): void
    {
        foreach (Report::lines(self::report()) as $i => $line) {
            $this->assertSame(\rtrim($line), $line, "第 {$i} 行行尾有空格");
        }
    }
}
