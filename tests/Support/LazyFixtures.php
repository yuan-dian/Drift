<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Support;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Widget\Element;
use Drift\Widget\LazyColumn;
use Drift\Widget\LazyList;
use Drift\Widget\LazyListState;
use Drift\Widget\LazyRow;
use Drift\Widget\StatefulElement;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 滚动容器测试的共享夹具（`LazyColumn` / `LazyRow` / 锚点 / 缓冲带）。
 *
 * ★ 分成三个测试文件（虚拟化 / 锚点 / ensureVisible）是**按被测对象**切，
 *   不是按夹具切 —— 夹具是同一套，所以只能有一份。第二个使用者出现时下沉，
 *   和 `tests/Support/functions.php` 里的 `inkCount` / `spinCanvas` 同一条纪律。
 * ★ 视口一律 320×240（`smokeContext()` 的规格）。行高与总数由调用方给，
 *   因为"窗口该造几行"正是被测的量。
 */
final class LazyFixtures
{
    /** @return list<int> */
    public static function range(int $from, int $to): array
    {
        return range($from, $to - 1);
    }

    public static function column(int $count, float $extent, ?EdgeInsets $pad = null): LazyColumn
    {
        $widget = LazyColumn::make($extent)->items(
            self::range(0, $count),
            static fn(int $i): Widget => Text::make('行 ' . $i),
        );

        return $pad === null ? $widget : $widget->padding($pad);
    }

    public static function row(int $count, float $extent, ?EdgeInsets $pad = null): LazyRow
    {
        $widget = LazyRow::make($extent)->items(
            self::range(0, $count),
            static fn(int $i): Widget => Text::make('格 ' . $i),
        );

        return $pad === null ? $widget : $widget->padding($pad);
    }

    /** 挂树 + 按 320×240 布局 */
    public static function mount(LazyList $widget, float $width = 320.0, float $height = 240.0): Element
    {
        $element = $widget->createElement();
        $element->mount(null, smokeContext());
        $element->effectiveBox()?->layout(BoxConstraints::tight(new Size($width, $height)));

        return $element;
    }

    /** 重建后必须重新排 —— 盒每次 build 都换新，旧盒的 offset 不会自己更新 */
    public static function settle(Element $root, float $width = 320.0, float $height = 240.0): void
    {
        $root->settle();
        $root->effectiveBox()?->layout(BoxConstraints::tight(new Size($width, $height)));
    }

    /** 窗口里实际造了几个（= Column/Row 的直接子节点数） */
    public static function childCount(Element $root, string $containerClass): int
    {
        $container = self::find($root, $containerClass);
        $n = 0;
        $container?->visitChildren(static function (Element $child) use (&$n): void {
            $n++;
        });

        return $n;
    }

    public static function childBoxOf(Element $root, string $containerClass): ?RenderBox
    {
        return self::find($root, $containerClass)?->renderObject();
    }

    public static function stateOf(Element $root): ?LazyListState
    {
        $hit = findElement(
            $root,
            static fn(Element $e): bool => $e instanceof StatefulElement && $e->state() instanceof LazyListState,
        );
        $state = $hit instanceof StatefulElement ? $hit->state() : null;

        return $state instanceof LazyListState ? $state : null;
    }

    /**
     * 锚点用例的列表：固定行高 40、`$withKey` 决定有没有身份 key。
     *
     * ★ 行高 40 + 视口 240 ⇒ 6 行/窗；滚到 4000 时顶部是第 100 行（4000/40），
     *   "删掉第 1 行 ⇒ 3960" 这个减法才有唯一的正确值。
     */
    public static function keyed(array $items, bool $withKey): LazyColumn
    {
        $widget = LazyColumn::make(40.0)->items(
            $items,
            static fn(int $i): Widget => Text::make('行 ' . $i),
        );
        if ($withKey) {
            $widget->keyOf(static fn(int $i): string => 'k' . $i);
        }

        return $widget;
    }

    /** 锚点用例的统一收尾：settle + 重排（换内容后必须重排才看得出来） */
    public static function layout(Element $root): void
    {
        $root->settle();
        $root->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 240.0)));
    }

    /** 视口顶部那一行的文本（窗口第一行 = 文档序第一个 `Text`） */
    public static function topRow(Element $root): string
    {
        $text = findElement($root, static fn(Element $e): bool => $e->widget() instanceof Text)?->widget();

        return $text instanceof Text ? $text->content() : '?';
    }

    private static function find(Element $root, string $containerClass): ?Element
    {
        return findElement($root, static fn(Element $e): bool => $e->widget() instanceof $containerClass);
    }
}
