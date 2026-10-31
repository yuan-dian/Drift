<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Widget\TableGeometry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `TableGeometry` —— 一帧几何的**纯算术**。
 *
 * ★ 为什么这个测试文件本身是收益：这些判据（夹取 / 开窗 / 缝隙归属 / 行区坐标系）
 *   在 2026-10-31 之前**只能靠 UI 特征测试间接验** —— 得造一棵树、推指针、抓像素，
 *   然后从"画面对不对"反推"几何算得对不对"。现在入参全是数、出参全是数。
 *
 * ★ 基准几何（下文多处复用）：
 *   `widths = [100, 100, 100, 100]`、`rowH = 40`、`headerH = 32`、`spacing = 0`、
 *   `viewport = 240 × 240` ⇒ `bodyH = 208`、`contentW = 400`（列和 400 > 视口 240）。
 */
final class TableGeometryTest extends TestCase
{
    /** @var list<float> */
    private const W4 = [100.0, 100.0, 100.0, 100.0];

    /** @param list<float> $widths */
    private static function make(
        array $widths = self::W4,
        int $rowCount = 10,
        float $rowHeight = 40.0,
        float $headerHeight = 32.0,
        float $spacing = 0.0,
        float $viewportW = 240.0,
        float $viewportH = 240.0,
        float $offsetX = 0.0,
        float $offsetY = 0.0,
        float $cache = 0.0,
    ): TableGeometry {
        return TableGeometry::compute(
            $widths,
            $rowHeight,
            $rowCount,
            $headerHeight,
            $spacing,
            $viewportW,
            $viewportH,
            $offsetX,
            $offsetY,
            $cache,
        );
    }

    /**
     * 行区坐标系：`contentH` **含**表头，`bodyH` **不含**。
     *
     * ★ 这两个定义不同不是笔误：滚动上限按 `bodyH` 算（表头钉住不滚），滚动条按
     *   `contentH` 算（整表有多长）。合成一个数就会"未滚动时行区画到表头下面一整条"。
     */
    public function testBodyHeightExcludesHeaderWhileContentHeightIncludesIt(): void
    {
        $g = self::make(rowCount: 10, rowHeight: 40.0, headerHeight: 32.0, viewportH: 240.0);

        self::assertSame(432.0, $g->contentH, '内容高 = 表头 32 + 10×40');
        self::assertSame(208.0, $g->bodyH, '行区高 = 视口 240 − 表头 32');
    }

    /** 视口比表头还矮 ⇒ 行区高夹到 0（不是负数） */
    public function testBodyHeightNeverGoesNegative(): void
    {
        $g = self::make(rowCount: 10, headerHeight: 32.0, viewportH: 20.0);

        self::assertSame(0.0, $g->bodyH);
    }

    /**
     * 内容宽**至少铺满视口** —— 否则固定列不满时右侧露出一条没有底色的缝。
     */
    public function testContentWidthFillsViewportEvenWhenColumnsAreNarrow(): void
    {
        $narrow = self::make(widths: [60.0, 60.0], viewportW: 400.0);
        self::assertSame(400.0, $narrow->contentW, '列宽和 120 < 视口 400 ⇒ 撑到 400');

        $wide = self::make(widths: [100.0, 100.0, 100.0, 100.0], viewportW: 240.0);
        self::assertSame(400.0, $wide->contentW, '列宽和 400 > 视口 240 ⇒ 取 400');
    }

    /** 内容宽含列间距（n−1 份，不含两端的 gap） */
    public function testContentWidthCountsInnerGapsOnly(): void
    {
        $g = self::make(widths: [100.0, 100.0, 100.0], spacing: 10.0, viewportW: 100.0);

        self::assertSame(320.0, $g->contentW, '300 + 2×10');
    }

    /**
     * 纵向夹取上界 = 总行高 − 行区高（不是 − 视口高：表头不滚）。
     */
    public function testOffsetYClampsAgainstRowAreaNotViewport(): void
    {
        // 10×40 = 400 总行高，bodyH = 208 ⇒ 上界 192
        self::assertSame(192.0, self::make(offsetY: 999.0)->offsetY);
        self::assertSame(192.0, self::make(offsetY: 192.0)->offsetY);
        self::assertSame(100.0, self::make(offsetY: 100.0)->offsetY);
        self::assertSame(0.0, self::make(offsetY: -50.0)->offsetY, '负偏移夹到 0');
    }

    /** 行比视口还少 ⇒ 上界 0（滚不动） */
    public function testOffsetYIsZeroWhenRowsFitInViewport(): void
    {
        self::assertSame(0.0, self::make(rowCount: 2, offsetY: 500.0)->offsetY);
    }

    /** 横向夹取上界 = 内容宽 − 视口宽 */
    public function testOffsetXClampsAgainstContentWidth(): void
    {
        self::assertSame(160.0, self::make(offsetX: 999.0)->offsetX, '400 − 240');
        self::assertSame(0.0, self::make(offsetX: -1.0)->offsetX);
    }

    /**
     * 缓冲带让窗口**比可视行多** —— 这是"滚一小段不重建"的前提。
     *
     * ★ 没有缓冲带时窗口跟着偏移逐行滑动 ⇒ 每滚一行就整棵重建（实测把
     *   `settle` 从 0.83ms 拉回 6.74ms）。
     */
    public function testRowWindowGrowsWithCacheExtent(): void
    {
        $tight = self::make(rowCount: 100, cache: 0.0);
        // bodyH 208 / 40 = 5.2 ⇒ ceil = 6
        self::assertSame([0, 6], [$tight->rowFirst, $tight->rowLast]);
        self::assertSame(6, $tight->createdRows());

        $cached = self::make(rowCount: 100, cache: 200.0);
        // last = ceil((0 + 208 + 200)/40) = ceil(10.2) = 11
        self::assertSame([0, 11], [$cached->rowFirst, $cached->rowLast]);
        self::assertSame(11, $cached->createdRows());
    }

    /** 窗口端点被行数夹住（偏移到底时 last 不能越过行数） */
    public function testRowWindowClampsToRowCount(): void
    {
        $g = self::make(rowCount: 10, cache: 400.0);

        self::assertSame(0, $g->rowFirst);
        self::assertSame(10, $g->rowLast);
        self::assertSame(10, $g->createdRows());
    }

    /** 空表：窗口是空区间，`rowAt()` 回 null（不是 0） */
    public function testEmptyTableHasEmptyWindows(): void
    {
        $g = self::make(rowCount: 0);

        self::assertSame([0, 0], [$g->rowFirst, $g->rowLast]);
        self::assertSame(0, $g->createdRows());
        self::assertNull($g->rowAt(0.0));
    }

    /**
     * 列窗口：偏移落在第 2 列中间 ⇒ 窗口从第 2 列起，`colLeading` 是它左边被裁掉的长度。
     */
    public function testColumnWindowStartsAtFirstVisibleColumn(): void
    {
        // offsetX 150、视口 100、cache 0 ⇒ from=150 to=250
        $g = self::make(widths: [100.0, 100.0, 100.0, 100.0], viewportW: 100.0, offsetX: 150.0);

        self::assertSame(1, $g->colFirst, '第 0 列 [0,100) 全在 150 之前');
        self::assertSame(100.0, $g->colLeading, '第 1 列从 100 开始');
        // 第 2 列 [200,300) 跨过 250 仍在窗内；第 3 列起点 300 ≥ to=250 ⇒ 不进窗
        self::assertSame(3, $g->colLast);
        self::assertSame(2, $g->createdColumns(), '只造第 1、2 列');
    }

    /**
     * 缝隙归**左**格：`[60, 70)` 这段 10px 是第 0 列的右内边距，点到它仍算第 0 列。
     *
     * ★ 归右格的话，拖选会在每两列之间漏一格 —— 而"漏一格"在画面上只表现为
     *   "框少了一列"，很难联想到缝隙归属。
     * ★ 最后一列的**尾部** gap 也归它（`colAt` 的上界是 140，而 `contentW` 是 130）：
     *   那个 gap 在视觉上是"这一格的右内边距"，不属于"右侧空白"。
     */
    public function testColAtGivesGapToTheLeftCell(): void
    {
        $g = self::make(widths: [60.0, 60.0], spacing: 10.0, viewportW: 60.0);

        self::assertSame(0, $g->colAt(0.0));
        self::assertSame(0, $g->colAt(59.9));
        self::assertSame(0, $g->colAt(60.0), '缝隙起点仍归左格');
        self::assertSame(0, $g->colAt(69.9));
        self::assertSame(1, $g->colAt(70.0), '第 1 列从 70 开始');
        self::assertSame(1, $g->colAt(139.9), '尾部 gap 归最后一列');
        self::assertNull($g->colAt(140.0), '最后一列的 gap 之后不属于任何列');
    }

    /** `rowAt` **夹到首/末行**（拖动要靠它才能选到看不见的行），不是回 null */
    public function testRowAtClampsInsteadOfReturningNull(): void
    {
        $g = self::make(rowCount: 10, rowHeight: 40.0);

        self::assertSame(0, $g->rowAt(-999.0), '拖到上方 ⇒ 首行');
        self::assertSame(0, $g->rowAt(0.0));
        self::assertSame(0, $g->rowAt(39.9));
        self::assertSame(1, $g->rowAt(40.0), '行边界归下一行');
        self::assertSame(9, $g->rowAt(9999.0), '拖到下方 ⇒ 末行');
    }

    /** 格矩形 = 列宽前缀和 + 间距；`y` 是**行区**坐标（不含表头） */
    public function testCellRectUsesPrefixSumAndRowAreaCoordinates(): void
    {
        $g = self::make(widths: [60.0, 60.0], spacing: 10.0, rowHeight: 40.0, headerHeight: 32.0);

        $rect = $g->cellRect(2, 1);
        self::assertNotNull($rect);
        self::assertSame(70.0, $rect->left, '第 1 列 x = 60 + 间距 10');
        self::assertSame(80.0, $rect->top, '第 2 行 y = 2×40（不含表头 32）');
        self::assertSame(60.0, $rect->width);
        self::assertSame(40.0, $rect->height);

        self::assertNull($g->cellRect(0, 5), '列不存在 ⇒ null');
    }

    /**
     * `covers*()` 的边界：正好盖住算盖住，多一像素就算盖不住。
     *
     * ★ 判错的方向是"盖不住却说盖得住" —— 那会让滚出缓冲带之后**不重建**，
     *   视口边缘露出一截空白且不报错。
     */
    public function testCoversIsInclusiveAtTheExactEdge(): void
    {
        // 窗口 [0, 11×40=440)，bodyH 208
        $g = self::make(rowCount: 100, cache: 200.0);

        self::assertTrue($g->coversRows(0.0, 208.0));
        self::assertTrue($g->coversRows(232.0, 208.0), '232 + 208 = 440，正好到端点');
        self::assertFalse($g->coversRows(233.0, 208.0), '多 1px 就盖不住');
        self::assertFalse($g->coversRows(-1.0, 208.0), '偏移为负 ⇒ 上方露白');
    }

    /** 列窗口同理：`viewportW` 是**活值**（窗口可能刚 resize），不取快照自己的 */
    public function testCoversColumnsUsesPassedViewport(): void
    {
        // 4×100，视口 240，缓冲带 100 ⇒ 窗口 [0, 400)
        $g = self::make(viewportW: 240.0, cache: 100.0);

        self::assertTrue($g->coversColumns(0.0, 240.0));
        self::assertTrue($g->coversColumns(160.0, 240.0), '160 + 240 = 400');
        self::assertFalse($g->coversColumns(161.0, 240.0));

        // 同一个快照，窗口变宽 400 ⇒ 立刻盖不住（这正是 resize 后要重建的理由）
        self::assertFalse($g->coversColumns(0.0, 400.5));
    }

    /** 横向装不下 = 内容宽 > 视口宽（滚轮早退的唯一判据） */
    public function testHorizontalOverflowIsStrictlyGreater(): void
    {
        self::assertTrue(self::make(viewportW: 240.0)->horizontalOverflows(), '400 > 240');
        self::assertFalse(self::make(viewportW: 400.0)->horizontalOverflows(), '400 = 400 ⇒ 不用滚');
        self::assertFalse(self::make(viewportW: 500.0)->horizontalOverflows());
    }

    /**
     * 数据提供器：一次把"夹取 + 开窗"的常见组合钉死，加用例只要一行。
     *
     * @param list<float> $widths
     * @param array{0: float, 1: float, 2: int, 3: int, 4: int} $expected [offsetX, offsetY, rowFirst, rowLast, colFirst]
     *
     * @return array<string, array{0: list<float>, 1: int, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float, 8: float, 9: float, 10: array<int, float|int>}>
     */
    public static function layoutCases(): array
    {
        return [
            '没滚动：窗口从头开始' => [
                self::W4, 10, 40.0, 32.0, 0.0, 240.0, 240.0, 0.0, 0.0, 0.0,
                [0.0, 0.0, 0, 6, 0],
            ],
            '滚到底：横向 160、纵向 192' => [
                self::W4, 10, 40.0, 32.0, 0.0, 240.0, 240.0, 999.0, 999.0, 0.0,
                [160.0, 192.0, 4, 10, 1],
            ],
            '空表：什么窗口都没有' => [
                self::W4, 0, 40.0, 32.0, 0.0, 240.0, 240.0, 0.0, 0.0, 0.0,
                [0.0, 0.0, 0, 0, 0],
            ],
            '没有列：列窗口是空区间' => [
                [], 10, 40.0, 32.0, 0.0, 240.0, 240.0, 0.0, 0.0, 0.0,
                [0.0, 0.0, 0, 6, 0],
            ],
        ];
    }

    /**
     * @param list<float> $widths
     * @param array<int, float|int> $expected
     */
    #[DataProvider('layoutCases')]
    public function testLayoutTable(
        array $widths,
        int $rowCount,
        float $rowHeight,
        float $headerHeight,
        float $spacing,
        float $viewportW,
        float $viewportH,
        float $offsetX,
        float $offsetY,
        float $cache,
        array $expected,
    ): void {
        $g = TableGeometry::compute(
            $widths,
            $rowHeight,
            $rowCount,
            $headerHeight,
            $spacing,
            $viewportW,
            $viewportH,
            $offsetX,
            $offsetY,
            $cache,
        );

        self::assertSame($expected[0], $g->offsetX);
        self::assertSame($expected[1], $g->offsetY);
        self::assertSame($expected[2], $g->rowFirst);
        self::assertSame($expected[3], $g->rowLast);
        self::assertSame($expected[4], $g->colFirst);
    }
}
