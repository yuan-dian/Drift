<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Debug;

use Drift\Dev\Debug\View\Brief;
use Drift\Dev\Debug\View\Finding;
use PHPUnit\Framework\TestCase;

/**
 * 简报投影：规则形状、裁剪账、纯函数性。
 *
 * ## 这些判据防的是什么
 *
 * 原型在真实页面上踩出三类**静默错误**——不报错、不警告，只是报告变成假的：
 *
 * 1. 从只在 `count > 1` 时存在的 `ids` 读代表 id，拿到 0，印出 `node:0`；
 * 2. 查询层先按默认 `limit` / 默认聚合窗口截断，投影层就数不出自己裁了多少；
 * 3. 树的深度截断让规则走不到出问题的那一层，报告显示"没有发现问题"。
 *
 * 三条的共同点是"报告看起来正常"。所以判据不能只断言"输出里有某个字符串"，
 * 要断言**在缺数据时输出会承认缺数据**——一个指不到东西的地址、一句
 * "扫过的那部分没问题"，都必须能被测试区分开。
 */
final class BriefTest extends TestCase
{
    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function facts(array $over = []): array
    {
        return \array_merge([
            'label' => 'test',
            'stats' => ['capacity' => 600, 'nodes' => 10],
            'frames' => [['id' => 1, 'cost' => 1.0, 'rebuilt' => 0]],
            'hotspots' => [],
            'nodes' => [],
            'digest' => ['digest' => 'abcdef012345'],
            'window' => ['from' => 1, 'to' => 1, 'count' => 1, 'capacity' => 600],
        ], $over);
    }

    /**
     * @param list<array<string, mixed>> $roots
     * @return array<string, mixed>
     */
    private static function cascade(array $roots, int $rebuilt = 1, array $over = []): array
    {
        return \array_merge([
            'frame' => 1,
            'rebuilt' => $rebuilt,
            'depthMax' => 1,
            'roots' => $roots,
            'omitted' => 0,
            'hidden' => 0,
            'writes' => [],
            'byType' => [],
        ], $over);
    }

    /**
     * @param list<array<string, mixed>> $kids
     * @param array<string, mixed>       $over
     * @return array<string, mixed>
     */
    private static function node(int $id, string $type, array $kids = [], array $over = []): array
    {
        return \array_merge([
            'id' => $id,
            'type' => $type,
            'kind' => 'parent',
            'count' => 1,
            'subtree' => 1,
            'site' => '',
            'detail' => '',
            'children' => $kids,
            'omitted' => 0,
            'hidden' => 0,
            'cut' => false,
        ], $over);
    }

    /** @return list<Finding> */
    private static function findings(array $facts): array
    {
        return Brief::findings($facts);
    }

    /** @param list<Finding> $findings */
    private static function ruleOf(array $findings): string
    {
        return $findings[0]->rule ?? '?';
    }

    /**
     * 缺 `node id` 时不许编出一个地址。
     *
     * 原型从 `ids[0]` 读代表 id —— 而 `ids` 只在 `count > 1` 时存在 —— 拿到 0，
     * 报告照印 `node:0`，既不报错也指不到任何东西（0 不在有效 id 域里）。
     * 现在代表 id 取自总是存在的 `id` 字段，这类读取本身已经修掉；这条判据守的是
     * **形状退化的那一刻**：字段缺失、为 0、或将来换了记法，地址都只能退到帧，
     * 不能拿一个像地址的东西充数。
     */
    public function testMissingIdsNeverProducesAPhantomNodeAddress(): void
    {
        $facts = self::facts([
            'cascade' => self::cascade([self::node(0, 'PageShell', [], ['subtree' => 200])], 200),
            'cascadeQuery' => ['frame' => 1, 'maxDepth' => 12, 'maxChildren' => 0],
        ]);

        $findings = self::findings($facts);
        $fanout = null;
        foreach ($findings as $one) {
            if ($one->rule === 'fanout') {
                $fanout = $one;
                break;
            }
        }

        self::assertNotNull($fanout, '扇出规则应该命中');
        self::assertSame('frame:1', $fanout->address, '缺 node id 时地址只能到帧');
        self::assertStringNotContainsString('node:0', $fanout->address);
    }

    /**
     * 树被截断时，不许说"没有发现问题"。
     *
     * 这是三条里最危险的一条：读者会把"规则没触发"读成"没问题"，而规则没触发
     * 只是因为它在能走到的那几层里什么都没看见。`inconclusive` 与 `clean` 的区别
     * 就是这一句话的区别。
     */
    public function testTruncatedTreeIsInconclusiveRatherThanClean(): void
    {
        $truncated = self::facts([
            'cascade' => self::cascade([
                self::node(1, 'PageShell', [], ['cut' => true, 'omitted' => 3]),
            ]),
            'cascadeQuery' => ['frame' => 1, 'maxDepth' => 4, 'maxChildren' => 0],
        ]);

        self::assertSame('inconclusive', self::ruleOf(self::findings($truncated)));
    }

    /** 完整扫完且无命中，才可以宣称"窗口内没有触发规则"。 */
    public function testCompleteTreeWithNoHitsMaySayClean(): void
    {
        $whole = self::facts([
            'cascade' => self::cascade([self::node(1, 'PageShell', [self::node(2, 'Text')])]),
            'cascadeQuery' => ['frame' => 1, 'maxDepth' => 12, 'maxChildren' => 0],
            'frames' => [['id' => 1, 'cost' => 1.0, 'rebuilt' => 1]],
        ]);

        self::assertSame('clean', self::ruleOf(self::findings($whole)));
    }

    /**
     * 裁剪账要写出**扫描范围**，不能只写"没裁"。
     *
     * "扫到第 2 层没发现问题"和"扫到第 12 层没发现问题"是两句不同的话；只报
     * "无裁剪"时读者无法区分这两句，于是最坏情况下会把"没看那么深"当成"看了没发现"。
     */
    public function testOmissionLineAlwaysStatesScanDepth(): void
    {
        $deep = self::facts([
            'cascade' => self::cascade([self::node(1, 'PageShell', [], ['cut' => true])]),
            'cascadeQuery' => ['frame' => 1, 'maxDepth' => 4, 'maxChildren' => 0],
        ]);
        $deepLine = \end(Brief::project($deep, 40, 100)['lines']);
        self::assertStringContainsString('扫到第 1 层', $deepLine);
        self::assertStringContainsString('1 处未展开', $deepLine);
        self::assertStringContainsString('maxDepth=4', $deepLine);

        $whole = self::facts([
            'cascade' => self::cascade([self::node(1, 'PageShell', [self::node(2, 'Text')])]),
            'cascadeQuery' => ['frame' => 1, 'maxDepth' => 12, 'maxChildren' => 0],
        ]);
        $wholeLine = \end(Brief::project($whole, 40, 100)['lines']);
        self::assertStringContainsString('扫到第 2 层（完整）', $wholeLine);
    }

    /**
     * 同一份事实、同一个预算 ⇒ 逐字节相同。
     *
     * 投影是纯函数，这是"可复算"的最后一环：只要它带时间戳、随机、或依赖
     * 不稳定的排序，同一份证据两次投影就会不同，指纹也就失去了意义。
     */
    public function testProjectionIsByteStable(): void
    {
        $facts = self::facts([
            'frames' => [
                ['id' => 3, 'cost' => 40.0, 'rebuilt' => 120],
                ['id' => 2, 'cost' => 40.0, 'rebuilt' => 120],
                ['id' => 1, 'cost' => 5.0, 'rebuilt' => 1],
            ],
            'window' => ['from' => 1, 'to' => 3, 'count' => 3, 'capacity' => 600],
            'explain' => ['id' => 3, 'cost' => 40.0, 'stageMs' => ['settle' => 30.0], 'unattributedMs' => 0.0, 'rebuilt' => [1, 2]],
            'cascade' => self::cascade([self::node(1, 'PageShell', [], ['subtree' => 120])], 120),
            'cascadeQuery' => ['frame' => 3, 'maxDepth' => 12, 'maxChildren' => 0],
        ]);

        $first = Brief::project($facts, 40, 100);
        $second = Brief::project($facts, 40, 100);

        self::assertSame($first['lines'], $second['lines']);
        self::assertSame($first['omitted'], $second['omitted']);
    }

    /**
     * 同耗时要有确定次序：耗时降序 → 帧号升序。
     *
     * 没有次级键时，`usort` 的结果取决于输入顺序，而输入顺序又是一个"看起来
     * 无关紧要"的实现细节。今天稳定、明天不稳的东西不能进证据。
     */
    public function testEqualCostFramesTieBreakByFrameId(): void
    {
        $facts = self::facts([
            'frames' => [
                ['id' => 7, 'cost' => 40.0, 'rebuilt' => 1],
                ['id' => 2, 'cost' => 40.0, 'rebuilt' => 1],
            ],
            'window' => ['from' => 2, 'to' => 7, 'count' => 2, 'capacity' => 600],
        ]);

        $addresses = [];
        foreach (self::findings($facts) as $one) {
            if ($one->rule === 'slow-frame') {
                $addresses[] = $one->address;
            }
        }

        self::assertSame(['frame:2', 'frame:7'], $addresses);
    }

    /**
     * 预算不够时：先丢明细、再丢发现，**计数永远保留**。
     *
     * 明细能按地址重新取回，发现是"要去查什么"的入口，丢了就不知道该查哪。
     * 但"丢了几条"这条账不能被丢——那正是读者判断"我看到的全不全"的唯一依据。
     */
    public function testTightBudgetKeepsCountsAndDropsDetailFirst(): void
    {
        $frames = [];
        for ($i = 1; $i <= 8; $i++) {
            $frames[] = ['id' => $i, 'cost' => 100.0 - $i, 'rebuilt' => 1];
        }
        $facts = self::facts([
            'frames' => $frames,
            'window' => ['from' => 1, 'to' => 8, 'count' => 8, 'capacity' => 600],
            'hotspots' => [['site' => 'a.php:1', 'subtree' => 9, 'frames' => 8, 'writes' => 8]],
            'nodes' => [['id' => 1, 'path' => 'A > B', 'rebuilds' => 8, 'self' => 8, 'inherited' => 0]],
        ]);

        $tight = Brief::project($facts, 8, 100);
        $tail = \end($tight['lines']);

        self::assertGreaterThan(0, $tight['omitted']['findings'], '发现超出预算时要记账');
        self::assertStringContainsString('发现', (string) $tail);
        self::assertStringContainsString('未显示', (string) $tail);
        // 发现至少留一条：一条都没有等于"没有结论"，而实际有结论。
        $shown = 0;
        foreach ($tight['lines'] as $line) {
            if (\str_starts_with($line, '  · ')) {
                ++$shown;
            }
        }
        self::assertGreaterThanOrEqual(1, $shown);
    }

    /**
     * 一条发现必须带齐五样（规则名、地址、度量、阈值、复算）。
     *
     * 缺任何一样，它就从"证据"退化成"意见"——尤其是"复算"，没有它，
     * 结论不可否证，等于要求读者相信。
     */
    public function testEveryFindingCarriesTheFiveParts(): void
    {
        $facts = self::facts([
            'frames' => [['id' => 2, 'cost' => 40.0, 'rebuilt' => 200]],
            'window' => ['from' => 1, 'to' => 2, 'count' => 2, 'capacity' => 600],
            'explain' => ['id' => 2, 'cost' => 40.0, 'stageMs' => ['settle' => 30.0], 'unattributedMs' => 0.0, 'rebuilt' => [1]],
            'cascade' => self::cascade([self::node(5, 'PageShell', [], ['subtree' => 200])], 200),
            'cascadeQuery' => ['frame' => 2, 'maxDepth' => 12, 'maxChildren' => 0],
        ]);

        foreach (self::findings($facts) as $one) {
            self::assertNotSame('', $one->rule);
            self::assertNotSame('', $one->address);
            self::assertNotSame('', $one->metric);
            self::assertNotSame('', $one->threshold);
            self::assertNotSame('', $one->recheck);
        }
    }
}
