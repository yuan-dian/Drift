<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Debug;

use Drift\Dev\Debug\View\TreeRows;
use PHPUnit\Framework\TestCase;

/**
 * 因果树 ⇒ 行表：单链折叠、折叠组、键与父链、行文本。
 *
 * ## 为什么这些判据要落在行表上，而不是渲染出来的字符串上
 *
 * 上一期把单链折叠写在 `debug-report.php` 的渲染函数里，直接后果是**它没法被验证**：
 * 那个函数往 `STDOUT` 写、按 budget 截断，判据只能靠肉眼。现在折叠是
 * {@see TreeRows::flatten()} 的事，行是一等数据，判据能直接钉住三件事：
 * 折了几层、折出来的键是谁、父链指向谁。
 *
 * 行文本仍然要钉，但只钉"标记在不在"（`×N` / `共 N 个` / `←` / `… 另 N 支`）——
 * 对齐宽度是排版，改一次列宽不该让 20 条判据变红。
 */
final class TreeRowsTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $kids
     * @param array<string, mixed>       $over
     *
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

    public function testASingleChildChainFoldsIntoOneRow(): void
    {
        $roots = [self::node(1, 'Shell', [self::node(2, 'Card', [self::node(3, 'Table')], ['subtree' => 2])], ['subtree' => 3])];

        $rows = TreeRows::flatten($roots);

        $this->assertCount(1, $rows);
        $this->assertSame('Shell > Card > Table', $rows[0]->label);
        $this->assertSame('t:3', $rows[0]->key(), '键取链尾：链中间那些节点没有自己的行');
        $this->assertSame(0, $rows[0]->depth);
        $this->assertSame(1, $rows[0]->subtree, '规模取链尾（链上每一层都是它的超集）');
        $this->assertNull($rows[0]->parentKey);
        $this->assertFalse($rows[0]->hasChildren);
    }

    /**
     * 写入点必须留在自己那一行：`←` 紧挨着写它的那个类型，混在链中间就分不清
     * 是"整条链都在写"还是"最后那一个在写"。
     */
    public function testAWritePointEndsTheChain(): void
    {
        $roots = [self::node(1, 'Shell', [
            self::node(2, 'Card', [self::node(3, 'Table')], [
                'kind' => 'state',
                'site' => 'src/State/Cell.php:117',
                'detail' => 'TableState',
                'subtree' => 2,
            ]),
        ], ['subtree' => 3])];

        $rows = TreeRows::flatten($roots);

        $this->assertCount(2, $rows);
        $this->assertSame('Shell > Card', $rows[0]->label);
        $this->assertSame('t:2', $rows[0]->key());
        $this->assertSame('state', $rows[0]->kind);
        $this->assertSame('Table', $rows[1]->label);
        $this->assertSame(1, $rows[1]->depth);
        $this->assertSame('t:2', $rows[1]->parentKey, '子行的父键是链尾，不是链首');
        $this->assertStringContainsString('← src/State/Cell.php:117', $rows[0]->text());
    }

    /** `omitted > 0` 时规模不能再当"下一层减一"往回推 —— 链在这里必须断。 */
    public function testATruncatedNodeCannotBeAPassThrough(): void
    {
        $roots = [self::node(1, 'Shell', [self::node(2, 'Card')], ['omitted' => 2, 'hidden' => 5, 'subtree' => 6])];

        $rows = TreeRows::flatten($roots);

        // 截断的节点**仍然可以有几个孩子**（maxChildren 只丢多出来的那几支），
        // 但链不能再往下吃：规模已经不是"下一层减一"了。
        $this->assertCount(2, $rows);
        $this->assertSame('Shell', $rows[0]->label, '链在这里断开，Card 不并进这一行');
        $this->assertStringContainsString('另 2 支', $rows[0]->text());
        $this->assertStringContainsString('（5 个节点）', $rows[0]->text());
    }

    /**
     * 折叠组可以当**链尾**（再往下就是它的孩子），但不能当链中间的跳板：
     * 夹在中间读起来是"每份里还有一层"，那是另一句话。
     */
    public function testAGroupCanOnlyEndAChain(): void
    {
        $roots = [self::node(1, 'Shell', [
            self::node(2, 'SizedBox', [self::node(3, 'Text')], ['count' => 3, 'subtree' => 2]),
        ], ['subtree' => 6])];

        $rows = TreeRows::flatten($roots);

        $this->assertCount(2, $rows);
        $this->assertSame('Shell > SizedBox ×3 #2', $rows[0]->label);
        $this->assertSame(3, $rows[0]->count);
        $this->assertSame(6, $rows[0]->instances(), '共 6 个 = 3 份 × 每份 2');
        $this->assertStringContainsString('共 6 个', $rows[0]->text());
        $this->assertSame('Text', $rows[1]->label);
    }

    public function testCollapsedRowsHideChildrenWithoutChangingTheKey(): void
    {
        $roots = [self::node(1, 'Shell', [
            self::node(2, 'Card', [self::node(3, 'Table')], ['subtree' => 2]),
            self::node(4, 'Text'),
        ], ['subtree' => 4])];

        $open = TreeRows::flatten($roots);
        $folded = TreeRows::flatten($roots, ['t:1' => true]);

        $this->assertCount(3, $open, 'Shell 自己 + Card > Table（单链折叠）+ Text');
        $this->assertCount(1, $folded, '折叠 Shell 之后只剩它自己');
        $this->assertSame($open[0]->key(), $folded[0]->key(), '折叠不改变身份');
        $this->assertTrue($open[0]->hasChildren);
        $this->assertTrue($folded[0]->hasChildren, '折叠只影响行表，不改变"它有没有孩子"这个事实');
    }

    public function testUnknownKindIsMarkedInTheText(): void
    {
        $rows = TreeRows::flatten([self::node(1, 'Overlay', [], ['kind' => 'unknown'])]);

        $this->assertStringContainsString('← ? 无可见原因', $rows[0]->text());
    }

    /** 排版与 CLI 同一份：`  ` + 缩进 + 标签补到 44 + 规模右对齐 5 位。 */
    public function testTheTextLayoutMatchesTheCliReport(): void
    {
        $rows = TreeRows::flatten([self::node(1, 'Shell', [], ['subtree' => 7])]);

        $this->assertSame(\sprintf('  %-44s %5d', 'Shell', 7), $rows[0]->text());
    }
}
