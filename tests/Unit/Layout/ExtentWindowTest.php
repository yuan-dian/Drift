<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Layout;

use Drift\Layout\ExtentWindow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 列窗口的纯算术（`Layout\ExtentWindow`）。
 *
 * ★ 为什么值得先单测：`Table` 的列虚拟化坏了，现象是"视口边缘露空白"或
 *   "横向越滚越偏"——两者都**不报错**。这里先红，就不必往 2400 行的
 *   `TableState` 里查。
 *
 * ★ 与行虚拟化的区别是本类的存在理由：行高固定 ⇒ 闭式除法；列宽可变 ⇒ 只能累加。
 *   两处根因不同，抽不成一个函数（见类注释）。
 */
final class ExtentWindowTest extends TestCase
{
    /**
     * @return array<string, array{0: list<float>, 1: float, 2: float, 3: float, 4: float, 5: array{0: int, 1: int, 2: float}}>
     */
    public static function visibleCases(): array
    {
        $four = [150.0, 150.0, 150.0, 150.0];

        return [
            // 视口 320 / 段长 150 ⇒ 第 0、1 段可见，第 2 段刚探头（起于 300 < 320）
            '四段各 150 / 视口 320 ⇒ [0,3) 前置 0' => [$four, 0.0, 0.0, 320.0, 0.0, [0, 3, 0.0]],
            // 偏移 300 ⇒ 从第 2 段起，且前置是累加出来的 300
            '偏移 300 ⇒ 从第 2 段起、前置 300（累加而不是除法）' => [$four, 0.0, 300.0, 320.0, 0.0, [2, 4, 300.0]],
            // ★ 上面那条 offset 恰好等于 leading，**区分不出**"累加"与"直接拿偏移"
            //   （变异实测：只改 leading 时它仍绿）。这条刻意错开：偏移落在第 1 段
            //   中间 ⇒ 前置必须是 150，不是 250。
            '偏移 250（落在段中间）⇒ 前置是段起点 150，不是偏移本身' => [$four, 0.0, 250.0, 320.0, 0.0, [1, 4, 150.0]],
            // gap 也占位置：150 + 10 + 150 = 310 < 320，第三段起于 320 不探头
            '段间 gap 计入位置（150+10+150 ⇒ 视口 320 只覆盖 2 段）' => [$four, 10.0, 0.0, 320.0, 0.0, [0, 2, 0.0]],
            // 缓冲带把**两端**都放宽：from=250 落到第 1 段，to=450 覆盖到第 3 段
            '缓冲带把窗口两端都放宽（off=300/cache=50 ⇒ [1,3)）' => [$four, 0.0, 300.0, 100.0, 50.0, [1, 3, 150.0]],
            '空列表 ⇒ [0,0) 不炸' => [[], 0.0, 0.0, 320.0, 0.0, [0, 0, 0.0]],
            // 退化输入：零长段 + 零 gap ⇒ 全落在 from 之前，退回**全造**（宁可少省，不露空）
            '零长段全部落在偏移之前 ⇒ 退回全造（不露空段）' => [[0.0, 0.0], 0.0, 50.0, 100.0, 0.0, [0, 2, 0.0]],
        ];
    }

    /**
     * @param list<float> $extents
     * @param array{0: int, 1: int, 2: float} $expected
     */
    #[DataProvider('visibleCases')]
    public function testVisible(array $extents, float $gap, float $offset, float $viewport, float $cache, array $expected): void
    {
        self::assertSame($expected, ExtentWindow::visible($extents, $gap, $offset, $viewport, $cache));
    }

    /**
     * @return array<string, array{0: list<float>, 1: int, 2: int, 3: float, 4: float}>
     */
    public static function extentCases(): array
    {
        return [
            '单段 ⇒ 只有它自己' => [[150.0, 150.0], 0, 1, 0.0, 150.0],
            // 内部 gap 算进去，两端的 gap 不算（调用方自己补前置/后置）
            '两段 ⇒ 含中间那一个 gap' => [[150.0, 150.0, 150.0], 1, 3, 10.0, 310.0],
            '空区间 ⇒ 0（不是负数）' => [[150.0], 0, 0, 10.0, 0.0],
        ];
    }

    /** @param list<float> $extents */
    #[DataProvider('extentCases')]
    public function testExtentOf(array $extents, int $first, int $end, float $gap, float $expected): void
    {
        self::assertSame($expected, ExtentWindow::extentOf($extents, $first, $end, $gap));
    }
}
