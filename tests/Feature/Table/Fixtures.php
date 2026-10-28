<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderFlex;
use Drift\Layout\RenderObject;
use Drift\Layout\RenderTable;
use Drift\Geometry\Size;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Row;
use Drift\Widget\SizedBox;
use Drift\Widget\StatefulElement;
use Drift\Widget\Table;
use Drift\Widget\TableColumn;
use Drift\Widget\TableColumnWidth;
use Drift\Widget\TableState;

/**
 * `Table` 测试的夹具 —— 从 `tools/smoke.php` 的那批 `$tableXxx` 闭包提上来。
 *
 * ★ 为什么值得单独成类：这些闭包被**九个**分节共用（阶段 1 / 滚动不重建 /
 *   滚动不重排 / 层缓存 / 列虚拟化 / 选择 / 排序 / 列宽拖拽 / 键盘光标格）。
 *   留成脚本变量时，任何一段想单独跑都得把前面几百行一起带上 —— 这正是
 *   "改一处要跑全部 54 秒"的由来。提到类上之后，每个测试方法自建夹具，互不依赖。
 */
final class Fixtures
{
    public const VIEW_W = 320.0;

    public const VIEW_H = 240.0;

    public const ROW_H = 40.0;

    public const HEADER_H = 32.0;

    /** @return list<array{id: int, name: string, city: string}> */
    public static function rows(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['id' => $i, 'name' => '用户 ' . $i, 'city' => '城市 ' . ($i % 7)];
        }

        return $rows;
    }

    /** 三列：固定 60 + flex 2:1（视口 320 ⇒ [60, 173.33, 86.67]） */
    public static function build(int $count): Table
    {
        $columns = [
            TableColumn::make('id', 'ID')->field('id')->width(TableColumnWidth::fixed(60.0)),
            TableColumn::make('name', '姓名')->field('name')->width(TableColumnWidth::flex(2.0)),
            TableColumn::make('city', '城市')->field('city')->width(TableColumnWidth::flex(1.0)),
        ];

        return Table::make($columns, self::rows($count))
            ->keyOf(static fn(array $row): int => $row['id'])
            ->rowHeight(self::ROW_H)
            ->headerHeight(self::HEADER_H);
    }

    /** 挂树 + 按 320×240 布局 */
    public static function mount(Table $widget): Element
    {
        $element = $widget->createElement();
        $element->mount(null, smokeContext());
        $element->effectiveBox()?->layout(self::tight());

        return $element;
    }

    /** 重建 + 重排（`scrollBy` 只标脏，帧末才生效 —— 这里手动推一帧） */
    public static function pump(Element $root): void
    {
        $root->rebuild();
        $root->effectiveBox()?->layout(self::tight());
    }

    public static function tight(): BoxConstraints
    {
        return BoxConstraints::tight(new Size(self::VIEW_W, self::VIEW_H));
    }

    public static function stateOf(Element $root): ?TableState
    {
        $hit = findElement(
            $root,
            static fn(Element $e): bool => $e instanceof StatefulElement && $e->state() instanceof TableState,
        );
        $state = $hit instanceof StatefulElement ? $hit->state() : null;

        return $state instanceof TableState ? $state : null;
    }

    public static function boxOf(Element $root): ?RenderTable
    {
        $box = $root->effectiveBoxOf(RenderTable::class);

        return $box instanceof RenderTable ? $box : null;
    }

    /** 窗口里实际造了几行（= 行区 `Column` 的直接子节点数） */
    public static function rowsInWindow(Element $root): int
    {
        $column = findElement($root, static fn(Element $e): bool => $e->widget() instanceof Column);
        $n = 0;
        $column?->visitChildren(static function (Element $child) use (&$n): void {
            $n++;
        });

        return $n;
    }

    /**
     * `RenderTable` 的两个子盒：[表头, 行区]
     *
     * @return list<RenderObject>
     */
    public static function kidsOf(?RenderTable $box): array
    {
        $kids = [];
        $box?->visitChildren(static function (RenderObject $child) use (&$kids): void {
            $kids[] = $child;
        });

        return $kids;
    }

    /** 某个盒是否在指定祖先的子树下（命中断言的归属判定） */
    public static function under(?RenderBox $hit, ?RenderObject $ancestor): bool
    {
        for ($node = $hit; $node !== null; $node = $node->parent()) {
            if ($node === $ancestor) {
                return true;
            }
        }

        return false;
    }

    /** 行区里所有 `Row` 元素，**按树序**：`[0]` = 表头，`[1]` = 第 0 行，… */
    public static function rowElements(Element $root): array
    {
        $out = [];
        $walk = static function (Element $el) use (&$walk, &$out): void {
            if ($el->widget() instanceof Row) {
                $out[] = $el;

                return;
            }

            $el->visitChildren($walk);
        };
        $walk($root);

        return $out;
    }

    /** @return list<float> 某个 `Row` 的子宽序列（两位小数；尺寸缺失记 -1） */
    public static function widthsOf(Element $rowEl): array
    {
        $box = $rowEl->renderObject();
        if (!$box instanceof RenderFlex) {
            return [];
        }

        $out = [];
        foreach ($box->children() as $kid) {
            $size = $kid->size();
            $out[] = $size === null ? -1.0 : \round($size->width, 2);
        }

        return $out;
    }

    /** 按行的身份标识找行盒（`SizedBox` 的 `identity()` 就是行下标） */
    public static function rowBoxOf(Element $root, int $index): ?RenderBox
    {
        $el = findElement(
            $root,
            static fn(Element $e): bool => $e->widget() instanceof SizedBox
                && $e->widget()->identity()?->value === $index,
        );
        $box = $el?->effectiveBox();

        return $box instanceof RenderBox ? $box : null;
    }

    public static function maxScrollV(Element $root): float
    {
        return self::boxOf($root)?->maxScroll(Axis::Vertical) ?? -1.0;
    }
}
