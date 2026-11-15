<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Semantics;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\SemanticsNode;
use Drift\Widget\Button;
use Drift\Widget\Element;
use Drift\Widget\SemanticsBuilder;
use Drift\Widget\Widget;
use PHPUnit\Framework\TestCase;

/**
 * 语义静态描述（P0-2）：hint / automationId / acceleratorKey。
 *
 * ★ 判据读的是 `toArrays()` 这个**导出面**，不是读 widget 的私有字段：
 *   控件声明对了但导出链漏一环，客户端就是静默的 VT_EMPTY。
 */
final class SemanticsMetaTest extends TestCase
{
    private function lay(Widget $root): Element
    {
        $el = $root->createElement();
        $el->mount(null, smokeContext());
        $el->settle();
        $el->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 240.0)));
        $el->settle();

        return $el;
    }

    public function testMetaReachesSerializedNode(): void
    {
        $w = Button::make('保存')->semanticsMeta(
            hint: '保存当前文档',
            automationId: 'doc.save',
            acceleratorKey: 'Ctrl+S',
        );
        $nodes = SemanticsBuilder::build($this->lay($w))->toArrays()['nodes'];

        $this->assertCount(1, $nodes);
        $this->assertSame('保存当前文档', $nodes[0]['hint']);
        $this->assertSame('doc.save', $nodes[0]['automationId']);
        $this->assertSame('Ctrl+S', $nodes[0]['acceleratorKey']);
    }

    public function testKeysAlwaysPresentAndEmptyByDefault(): void
    {
        $nodes = SemanticsBuilder::build($this->lay(Button::make('普通')))->toArrays()['nodes'];

        // 三键必须恒在（C++ 侧 a.get 靠它降级成空串）；空串 = 不向客户端答该属性。
        $this->assertSame('', $nodes[0]['hint']);
        $this->assertSame('', $nodes[0]['automationId']);
        $this->assertSame('', $nodes[0]['acceleratorKey']);
    }

    public function testMetaIsOrthogonalToState(): void
    {
        $w = Button::make('行')
            ->semanticsState(selected: true, level: 2)
            ->semanticsMeta(hint: '这是一行', automationId: 'table.row');
        $n = SemanticsBuilder::build($this->lay($w))->toArrays()['nodes'][0];

        $this->assertSame(2, $n['selectState'], 'semanticsMeta 不该冲掉状态声明');
        $this->assertSame(2, $n['level']);
        $this->assertSame('table.row', $n['automationId']);
        $this->assertSame('', $n['acceleratorKey'], '未声明的一项保持空');
    }

    /** 加字段忘改 toArrays() 就在这里红：类结构与导出面必须逐键对应。 */
    public function testSerializedKeysMatchNodeFields(): void
    {
        $nodes = SemanticsBuilder::build($this->lay(Button::make('X')))->toArrays()['nodes'];
        $keys = \array_keys($nodes[0]);
        $fields = \array_map(
            static fn(\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass(SemanticsNode::class))->getProperties(\ReflectionProperty::IS_PUBLIC),
        );
        \sort($keys);
        \sort($fields);

        $this->assertSame($fields, $keys);
    }
}
