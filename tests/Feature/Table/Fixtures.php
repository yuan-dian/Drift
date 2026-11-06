<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Scale;
use Drift\Input\CursorShape;
use Drift\Input\PointerEvent;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderFlex;
use Drift\Layout\RenderObject;
use Drift\Layout\RenderTable;
use Drift\Geometry\Size;
use Drift\Rendering\Color;
use Drift\Rendering\DisplayList;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RecordingCanvas;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Row;
use Drift\Widget\SizedBox;
use Drift\Widget\Stack;
use Drift\Widget\StatefulElement;
use Drift\Widget\Table;
use Drift\Widget\TableColumn;
use Drift\Widget\TableColumnWidth;
use Drift\Widget\TableSelection;
use Drift\Widget\TableSelectionMode;
use Drift\Widget\TableSort;
use Drift\Widget\TableState;
use Drift\Widget\Text;
use Drift\Widget\Widget;

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

    /**
     * 走 `Frame` 的推帧路径（**不是** `pump()`）。
     *
     * ★ 与 `pump()` 的区别是判据的分水岭：`pump()` 强制 rebuild + layout，
     *   于是"滚动该不该重建 / 该不该重排"这两个问题在它上面**恒为'该'**，
     *   计数器断言永远测不到。只有走 `Frame::run()` 才带上 settle 判据。
     */
    public static function frame(Element $root): void
    {
        $root->settle();
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

    /**
     * 某个文本内容所在盒的绝对 x（`null` = 没造出来）。
     *
     * ★ 用**绝对坐标**而不是"某行第几列"：列虚拟化之后，"第 2 列"在树里
     *   可能根本不存在，"第 2 列的屏幕位置"才是被测语义。
     */
    public static function textXOf(Element $root, string $content): ?float
    {
        $hit = findElement(
            $root,
            static fn(Element $e): bool => $e->widget() instanceof Text && $e->widget()->content() === $content,
        );
        $box = $hit?->effectiveBox();

        return $box === null ? null : RenderBox::absoluteOrigin($box)[0];
    }

    /** 行区 `Column` 的直接子（= 窗内各行） */
    public static function rowElsOf(Element $root): array
    {
        $column = findElement($root, static fn(Element $e): bool => $e->widget() instanceof Column);
        $out = [];
        $column?->visitChildren(static function (Element $child) use (&$out): void {
            $out[] = $child;
        });

        return $out;
    }

    /**
     * 模拟"按在第 k 行上"—— 走**行容器声明的 pointer**，不是直接调 State。
     *
     * ★ 直接调 `noteRowTap()` 会绕过"有没有挂 pointer"的判断，于是"纯展示表不挂交互"
     *   那条断言永远绿（它测的正是挂没挂）。
     */
    public static function tapRow(Element $root, int $index, PointerEvent $event): void
    {
        $rowEl = self::rowElsOf($root)[$index] ?? null;
        if ($rowEl === null) {
            return;
        }

        $handler = $rowEl->widget()->pointerHandlerValue();
        $handler?->__invoke($event, $rowEl);
    }

    /**
     * 把盒画到光栅上（结构断言抓不到平移/裁剪的错位，只有像素能）。
     *
     * ★ 清屏色用**洋红**而不是白：表格底本身就是纯白，白底画布会让
     *   "这里画了 Surface"与"这里什么都没画"无法区分 —— 一条恒真的断言等于没有。
     */
    public static function rasterOf(?RenderBox $box, int $w, int $h): RasterCanvas
    {
        $list = new DisplayList();
        $box?->paint(new RecordingCanvas($list));
        $canvas = new RasterCanvas($w, $h, Color::fromRgb(0xFF, 0x00, 0xFF), Scale::of(1.0));
        $list->replay($canvas);

        return $canvas;
    }

    /** 选中集 = 受控 + 上报（应用侧就是那个 `SelectionSink`） */
    public static function selectable(TableSelection $sel, ?TableSelectionMode $mode, SelectionSink $sink): Table
    {
        $table = Table::make(
            [
                TableColumn::make('id', 'ID')->field('id')->width(TableColumnWidth::fixed(60.0)),
                TableColumn::make('name', '姓名')->field('name'),
            ],
            self::rows(6),
        )->keyOf(static fn(array $row): int => $row['id'])
            ->rowHeight(self::ROW_H)
            ->headerHeight(self::HEADER_H)
            ->selection($sel)
            ->onSelectionChange($sink->receive(...));

        return $mode === null ? $table : $table->selectionMode($mode);
    }

    /**
     * 表头 `Row` 的直接子（= 各列表头格，按列序）。
     *
     * ★ 取**第一个** `Row`：`RenderTable` 把表头放第一个子，行区是第二个。
     *   用 `rowElements()` 取 `[0]` 也是一样的，但那边会走完整棵树。
     */
    public static function headerCellsOf(Element $root): array
    {
        $row = findElement($root, static fn(Element $e): bool => $e->widget() instanceof Row);
        $out = [];
        $row?->visitChildren(static function (Element $child) use (&$out): void {
            $out[] = $child;
        });

        return $out;
    }

    /**
     * 模拟"按在表头第 j 格上"—— 走表头格声明的 pointer，不是直接调 State。
     *
     * ⚠️ 这一层**验不出 `ownsPress`**：它绕过了按下路由（行点击那次就是这样漏的，
     *    只有真机点得出"没反应"）。捕获语义由 `DragSelectTest` 的真路由覆盖。
     */
    public static function tapHeader(Element $cellEl): void
    {
        $cellEl->widget()->pointerHandlerValue()?->__invoke(PointerEvent::down(30.0, 10.0), $cellEl);
    }

    /**
     * 三列，前两列 `sortable()`、第三列不可排（操作列 / 图片列就是这样）。
     *
     * `$sel` 非空时顺带接上受控选中 —— 行容器只在"有选中 / 有可编辑列"时才挂
     * pointer（`$interactive`），要验"指针落行区把光标从表头拽回来"就必须有它。
     */
    public static function sortable(
        TableSort $sort,
        SortSink $sink,
        ?TableSelection $sel = null,
        ?SelectionSink $selSink = null,
    ): Table {
        $table = Table::make(
            [
                TableColumn::make('id', 'ID')->field('id')->width(TableColumnWidth::fixed(60.0))->sortable(),
                TableColumn::make('name', '姓名')->field('name')->sortable(),
                TableColumn::make('city', '城市')->field('city'),
            ],
            self::rows(6),
        )->keyOf(static fn(array $row): int => $row['id'])
            ->rowHeight(self::ROW_H)
            ->headerHeight(self::HEADER_H)
            ->sort($sort)
            ->onSortChange($sink->receive(...));

        if ($sel !== null && $selSink !== null) {
            $table = $table->selection($sel)->onSelectionChange($selSink->receive(...));
        }

        return $table;
    }

    /**
     * 三列：前两列 `resizable()`（`name` 还带 min/max），第三列不可调。
     *
     * ★ `name` 的 min/max 是 clamp 断言的靶子：左拖停在 120、右拖停在 260。
     */
    public static function resizable(array $overrides, ResizeSink $sink): Table
    {
        return Table::make(
            [
                TableColumn::make('id', 'ID')->field('id')->width(TableColumnWidth::fixed(60.0))->resizable(),
                TableColumn::make('name', '姓名')->field('name')->resizable()->minWidth(120.0)->maxWidth(260.0),
                TableColumn::make('city', '城市')->field('city'),   // ← 不 resizable：不该有手柄
            ],
            self::rows(4),
        )->keyOf(static fn(array $row): int => $row['id'])
            ->rowHeight(self::ROW_H)
            ->headerHeight(self::HEADER_H)
            ->columnWidths($overrides)
            ->onColumnResize($sink->receive(...));
    }

    /** 表头行 Element（`Row` 本身，用来量列宽序列） */
    public static function headerRowOf(Element $root): ?Element
    {
        return findElement($root, static fn(Element $e): bool => $e->widget() instanceof Row);
    }

    /**
     * 从表头格的 `Stack` 里按**谓词**取子。
     *
     * ★ 不用下标：叠层是 `[内容, 分割线, 手柄?]`，第三层只在可调的列才有 ⇒
     *   下标会随列配置漂移（阶段 4 加分割线时，按 `$kids[1]` 取"手柄"
     *   当场取到了分割线）。
     */
    public static function stackKidOf(?Element $stackEl, callable $pick): ?Element
    {
        if ($stackEl === null || !$stackEl->widget() instanceof Stack) {
            return null;
        }

        $found = null;
        $stackEl->visitChildren(static function (Element $c) use (&$found, $pick): void {
            if ($found === null && $pick($c->widget())) {
                $found = $c;
            }
        });

        return $found;
    }

    /** 手柄 = 光标变 `SizeWE` 的那个子 */
    public static function handleOf(?Element $el): ?Element
    {
        return self::stackKidOf($el, static fn(Widget $w): bool => $w->cursorShape() === CursorShape::SizeWE);
    }

    /**
     * 分割线 = 表头格里**自己带底色**的那个子。
     *
     * ★ 内容格不设 `background`（表头底色是 `RenderTable` 画的），手柄刻意透明 ⇒
     *   "有底色"在表头格里唯一命中分割线。
     */
    public static function dividerOf(?Element $el): ?Element
    {
        return self::stackKidOf($el, static fn(Widget $w): bool => $w->mods()->fillColor() !== null);
    }

    /** 往手柄上打一个指针事件（走手柄声明的 pointer） */
    public static function fireOnHandle(?Element $handleEl, PointerEvent $event): void
    {
        $handleEl?->widget()->pointerHandlerValue()?->__invoke($event, $handleEl);
    }

    /** 光栅画面上某个像素的 `rgb(r,g,b,a)` 文本（断言 detail 用） */
    public static function pxOf(RasterCanvas $raster, int $x, int $y): string
    {
        $c = $raster->pixelAt($x, $y);

        return \sprintf('rgb(%d,%d,%d,%d)', $c->r, $c->g, $c->b, $c->a);
    }

    /** 元素的中心点（根坐标）——真路由点击要的是坐标，不是 Element */
    public static function centerOf(?Element $el): array
    {
        $box = $el?->effectiveBox();
        if ($box === null || $box->size() === null) {
            return [0.0, 0.0];
        }
        $origin = RenderBox::absoluteOrigin($box);

        return [$origin[0] + $box->size()->width / 2.0, $origin[1] + $box->size()->height / 2.0];
    }

    /**
     * 横向溢出的表：三列被 override 撑到 500+400+300 = 1200，视口 320 ⇒ maxScrollX = 880。
     *
     * ★ 用 `columnWidths()` 而不是宽 `fixed()`：这是真机上"用户拖宽过"的形态，
     *   顺带把第 ④ 层与横向溢出的交互钉在一起。
     */
    public static function wide(int $count): Table
    {
        return Table::make(
            [
                TableColumn::make('id', 'ID')->field('id')->width(TableColumnWidth::fixed(60.0)),
                TableColumn::make('name', '姓名')->field('name')->width(TableColumnWidth::flex(2.0)),
                TableColumn::make('city', '城市')->field('city')->width(TableColumnWidth::flex(1.0)),
            ],
            self::rows($count),
        )->keyOf(static fn(array $row): int => $row['id'])
            ->columnWidths(['id' => 500.0, 'name' => 400.0, 'city' => 300.0])
            ->rowHeight(self::ROW_H)
            ->headerHeight(self::HEADER_H);
    }
}
