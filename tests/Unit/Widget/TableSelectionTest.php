<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Core\Literal;
use Drift\State\Cell;
use Drift\Widget\Table;
use Drift\Widget\TableColumn;
use Drift\Widget\TableSelection;
use PHPUnit\Framework\TestCase;

/**
 * 选中集值对象：不可变 + int / string key 严格分开。
 *
 * ★ 两个"不报错"的坑在这里钉死：
 *   ① 可变会让受控失效——应用拿到 `$next` 之后又改 `$current`，界面与数据静默分叉；
 *   ② PHP 数组键会把 `7` 与 `"7"` 静默合并——行身份来自用户数据，两种都会被用到。
 */
final class TableSelectionTest extends TestCase
{
    public function testIsImmutable(): void
    {
        $empty = TableSelection::none();
        $one = $empty->add(7);

        self::assertTrue($empty->isEmpty());
        self::assertSame(1, $one->count());
        self::assertNotSame($empty, $one);
        // 原对象没被写进去
        self::assertFalse($empty->has(7));
    }

    public function testIntAndStringKeysAreDistinct(): void
    {
        $sel = TableSelection::none()->add(7)->add('7');

        self::assertTrue($sel->has(7));
        self::assertTrue($sel->has('7'));
        self::assertSame(2, $sel->count());
    }

    public function testToggleWithAllAndOnly(): void
    {
        $sel = TableSelection::none()->add(7)->add('7');

        // toggle 已存在的 ⇒ 反选掉
        self::assertSame(['7'], $sel->toggle(7)->keys());
        // toggle 不存在的 ⇒ 加进来
        self::assertSame([7, '7', 9], $sel->toggle(9)->keys());

        $int = TableSelection::none()->add(7);
        // withAll 已经满足 ⇒ 返回同一实例（调用方据此跳过通知）
        self::assertSame($int, $int->withAll([7]));
        // only 只留指定集合
        self::assertSame([9], $sel->only([9])->keys());
    }

    public function testRemoveAndOrder(): void
    {
        $sel = TableSelection::of(['c', 'a', 'b']);

        // 顺序按插入，不按字典序（应用读 keys() 时不该被重排）
        self::assertSame(['c', 'a', 'b'], $sel->keys());
        self::assertSame(['c', 'b'], $sel->remove('a')->keys());
        // 去掉不存在的 ⇒ 原样返回
        self::assertSame($sel, $sel->remove('zzz'));
    }

    public function testOfDeduplicates(): void
    {
        self::assertSame(['a', 'b'], TableSelection::of(['a', 'b', 'a', 'b'])->keys());
    }

    public function testTableAcceptsSelectionValueAndChannel(): void
    {
        $value = TableSelection::of([7]);
        $channelValue = TableSelection::of(['r2']);
        $cell = Cell::of($channelValue);
        $columns = [TableColumn::make('a', 'A')->field('a')];
        $rows = [['a' => 1]];

        $byValue = Table::make($columns, $rows)->selection($value);
        $byChannel = Table::make($columns, $rows)->selectionFrom($cell);

        self::assertSame($value, $byValue->selectionSet());
        self::assertSame($channelValue, $byChannel->selectionSet());
        self::assertInstanceOf(Literal::class, $byValue->selectionReadable());
        self::assertSame($cell, $byChannel->selectionReadable());
    }
}
