<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Layout\RenderBox;

/**
 * `Table` 阶段 1：固定表头 + 虚拟行 + 列宽三层。
 *
 * 四条验收闸门（`docs/guides/widgets.md` §6）在这里变成可证伪断言：
 * ① 10000 行只造一屏 ② 表头不随竖向滚动移动 ③ 表头与数据行逐像素对齐
 * ④ 未滚动时行区紧贴表头下方。
 *
 * ★ 反例自检：① 窗口改成"全部行" ⇒ ①红（11 → 10000）；② 表头也吃 offsetY
 *   ⇒ ②红；③ 两处各算一次列宽 ⇒ ③红（宽序列不等）；④ 把表头高算进内容坐标
 *   ⇒ ④红（第 1 行顶边 24 → 56）。
 */
final class Phase1Test extends SmokeTestCase
{
    public function testVirtualRowsHeaderAndColumnWidths(): void
    {
        $big = Fixtures::mount(Fixtures::build(10000));
        $state = Fixtures::stateOf($big);
        $box = Fixtures::boxOf($big);

        // ① 虚拟化：视口 240 − 表头 32 = 行区可见 208 ⇒ ceil(208/40) = 6 行
        //   ★ 2026-10-27 起是"**可见行 + 缓冲带**"（`Table::cacheExtent()`，默认 200）：
        //   没有缓冲带的话 `first = floor(offsetY/rowH)`，偏移一动窗口就变 ⇒ 滚动每帧
        //   都要重建，A 批第 1 项的收益当场归零。断言因此写成**上界**而不是等于 6：
        //   `ceil((208 + 2×200)/40) = 16` 是"窗口最多多大"，11 是当前实际值
        //   （缓冲只向下延伸：offset=0 时上方没有内容可建）。
        check(
            'Table · 10000 行只造一屏 + 缓冲（行区 208 / 行高 40 / 缓冲 200 ⇒ 11 行，不是 10000）',
            $state?->createdRows() === 11 && Fixtures::rowsInWindow($big) === 11,
            'created=' . ($state?->createdRows() ?? -1) . ' / 树内=' . Fixtures::rowsInWindow($big),
        );
        // ★ 契约断言（与具体缓冲值解耦）：窗口永远 ≤ 可见行 + 两侧缓冲
        check(
            'Table · 行窗口有上界（≤ 可见行 + 2×缓冲/行高，与缓冲取值无关）',
            ($state?->createdRows() ?? \PHP_INT_MAX) <= (int) \ceil((208.0 + 2.0 * 200.0) / 40.0),
            'created=' . ($state?->createdRows() ?? -1),
        );

        // ② 可滚上限按**完整行高**算：10000×40 − 208 = 399792
        //    （按窗长算会得到 32 —— 滚动条会假装"只有一屏"）
        $max = $box?->maxScroll(\Drift\Geometry\Axis::Vertical) ?? -1.0;
        check(
            'Table · maxScroll = 总行高 − 行区可见高（400000 − 208 = 399792，不是窗长）',
            $max === 399792.0,
            (string) $max,
        );

        // ③ 列宽三层：avail 320 / fixed 60 / flex 2:1 分 260 ⇒ [60, 173.33, 86.67]
        $rowEls = Fixtures::rowElements($big);
        $headerWidths = isset($rowEls[0]) ? Fixtures::widthsOf($rowEls[0]) : [];
        $firstRowWidths = isset($rowEls[1]) ? Fixtures::widthsOf($rowEls[1]) : [];

        check(
            'Table · 列宽三层：fixed 60 + flex 2:1 分 260 ⇒ [60, 173.33, 86.67]',
            \count($headerWidths) === 3
                && \abs($headerWidths[0] - 60.0) < 0.5
                && \abs($headerWidths[1] - 173.33) < 1.0
                && \abs($headerWidths[2] - 86.67) < 1.0,
            \json_encode($headerWidths),
        );

        // ④ 表头与数据行**逐像素对齐**（最容易错的一处：两处各算一次列宽必然漂）
        check(
            'Table · 表头与数据行的列宽逐像素一致（两处 Row 的子宽序列完全相同）',
            $headerWidths !== [] && $headerWidths === $firstRowWidths,
            \json_encode([$headerWidths, $firstRowWidths]),
        );

        // ⑤ 未滚：行区紧贴表头下方（"表头 + 行区堆叠"的等价性 —— 阶段 5 的退路证明）
        $kids = Fixtures::kidsOf($box);
        check(
            'Table · 未滚动时表头在 y=0、行区在 y=表头高（32）',
            isset($kids[0], $kids[1])
                && $kids[0]->offsetY() === 0.0
                && $kids[1]->offsetY() === 32.0,
            isset($kids[0], $kids[1])
                ? \sprintf('%s / %s', (string) $kids[0]->offsetY(), (string) $kids[1]->offsetY())
                : '子盒缺失',
        );

        // ⑥ 滚动 48：表头**不动**，行区上移。
        //   ★★ 断言从"行区盒 offsetY === 24"改成"**第 1 行的顶边落在盒内 y=24**"
        //   （2026-10-27）：加了缓冲带之后窗不再从第 1 行开始（`first = floor((offset−200)/40) = 0`），
        //   于是行区盒自己上移到 −16 —— 那个数只反映"窗起点在哪"，**不是**被测语义。
        //   被测语义是"内容坐标 c 落在盒内 y = 表头高 − offset + c"（与窗起点无关）：
        //   第 1 行（内容 y=40）⇒ 32 − 48 + 40 = 24。这也是**能证伪**的：窗起点算错、
        //   或表头高被算进内容坐标，这个数就变。
        $state?->scrollBy(48.0);
        Fixtures::pump($big);
        $scrolledKids = Fixtures::kidsOf(Fixtures::boxOf($big));
        check(
            'Table · 竖向滚动后表头不动（offsetY 恒 0）',
            isset($scrolledKids[0]) && $scrolledKids[0]->offsetY() === 0.0,
            isset($scrolledKids[0]) ? (string) $scrolledKids[0]->offsetY() : '缺失',
        );

        $row1 = Fixtures::rowBoxOf($big, 1);
        $row1Y = $row1 === null ? null : RenderBox::absoluteOrigin($row1)[1];
        check(
            'Table · 竖向滚动 48 后第 1 行顶边落在 y=24（= 表头高 32 − 已滚 8）',
            $row1Y === 24.0,
            $row1Y === null ? '第 1 行不在树里' : (string) $row1Y,
        );
        // ★ 缓冲带生效的直接证据：滚到 48 时**第 0 行仍在树里**（窗从 0 起，不是从 1 起）。
        //   没有缓冲带的话 first=1 ⇒ 第 0 行被卸掉 ⇒ 往上滚一格就要重建。
        check(
            'Table · 缓冲带生效：滚动 48 后第 0 行仍在树里（窗没跟着行边界走）',
            Fixtures::rowBoxOf($big, 0) !== null,
            '第 0 行=' . (Fixtures::rowBoxOf($big, 0) === null ? '已卸载' : '在树里'),
        );

        $this->flushChecks();
    }

    /**
     * 行内容在行高里**垂直居中**。
     *
     * ★ 来源是同一批真机发现的问题：行容器是 `SizedBox(高)` 包 `Row`，
     *   `Row` 的交叉轴默认 `Start` ⇒ 文字贴顶、下方留一大片空 ⇒
     *   看起来像"行高买大了"。属性断言（行高 = 40）全都能过。
     */
    public function testRowContentIsVerticallyCentered(): void
    {
        $root = Fixtures::mount(Fixtures::build(2));
        $rows = Fixtures::rowElements($root);
        $flex = isset($rows[1]) ? $rows[1]->renderObject() : null;
        $cell = $flex instanceof \Drift\Layout\RenderFlex ? ($flex->children()[0] ?? null) : null;
        $h = $cell?->size()?->height;
        $y = $cell?->offsetY();

        check(
            'Table · 行内容在行高里垂直居中（不是贴顶 —— 贴顶看起来像"行高买大了"）',
            $h !== null && $y !== null && \abs($y - (Fixtures::ROW_H - $h) / 2.0) <= 1.0,
            $h === null ? '没有单元格' : \sprintf(
                'cell 高 %s / offsetY %s（期望 %s）',
                (string) $h,
                (string) $y,
                (string) ((Fixtures::ROW_H - $h) / 2.0),
            ),
        );

        $this->flushChecks();
    }
}
