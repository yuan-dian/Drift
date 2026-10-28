<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Unit;

use Drift\Layout\ColumnWidths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `ColumnWidths::resolve()` 的四层规则 —— 与 `tools/smoke.php` 里的 7 条 `check()`
 * 逐条对等，用来验证"纯单元迁 PHPUnit"这条路值不值得走。
 *
 * ★ 收益就在 `cases()`：smoke 里每条都要手抄一遍 `resolve(...)` 调用（
 *   期望值一次、detail 里又一次），加一条用例要写 6 行；这里是数据行。
 */
final class ColumnWidthsTest extends TestCase
{
    /** @param list<array{key: string, flex: bool, weight: float, px: float, min: ?float, max: ?float}> $specs */
    private static function spec(string $key, bool $flex, float $weight = 0.0, float $px = 0.0, ?float $min = null, ?float $max = null): array
    {
        return ['key' => $key, 'flex' => $flex, 'weight' => $weight, 'px' => $px, 'min' => $min, 'max' => $max];
    }

    /**
     * @return array<string, array{0: list<array<string, mixed>>, 1: array<string, float>, 2: float, 3: float, 4: list<float>}>
     */
    public static function cases(): array
    {
        $three = [self::spec('a', false, px: 60.0), self::spec('b', true, weight: 2.0), self::spec('c', true, weight: 1.0)];

        return [
            // ① 全固定：视口再宽也不拉伸
            '全固定列按声明取值' => [
                [self::spec('a', false, px: 40.0), self::spec('b', false, px: 60.0)],
                [], 320.0, 0.0, [40.0, 60.0],
            ],
            // ② flex 分剩余：自由宽 = 视口 − 已用，按权重切（400 − 60 ⇒ 340 按 2:1）
            'flex 按权重分剩余（60 固定 + 2:1）' => [$three, [], 400.0, 0.0, [60.0, 340.0 * 2 / 3, 340.0 / 3]],
            // ③ 列间距从视口里扣 n-1 份（320 − 2×10 ⇒ 自由 240）
            '列间距扣 n-1 份' => [$three, [], 320.0, 10.0, [60.0, 160.0, 80.0]],
            // ④ 用户拖出来的宽度最优先，且**不参与 flex 重算**（只剩 c 分 400−60−300）
            'override 优先且退出 flex 分配' => [$three, ['b' => 300.0], 400.0, 0.0, [60.0, 300.0, 40.0]],
            // ④ 指向已删除列的 override ⇒ 静默忽略（历史偏好，不是拼写错误）
            'override 指向不存在的列 ⇒ 静默忽略' => [$three, ['ghost' => 999.0], 400.0, 0.0, [60.0, 340.0 * 2 / 3, 340.0 / 3]],
            // ③ 夹取在分配**之后** ⇒ 夹高的列会让总和超过视口（调用方要重算内容宽）
            'min/max 在分配之后生效' => [
                [self::spec('a', false, px: 40.0, min: 80.0), self::spec('b', false, px: 100.0)],
                [], 320.0, 0.0, [80.0, 100.0],
            ],
            // ② 装不下：flex 拿 0，溢出交给裁剪（不报错）
            '固定列已超宽 ⇒ flex 拿 0' => [
                [self::spec('a', false, px: 400.0), self::spec('b', true, weight: 1.0)],
                [], 320.0, 0.0, [400.0, 0.0],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $specs
     * @param array<string, float> $overrides
     * @param list<float> $expected
     */
    #[DataProvider('cases')]
    public function testResolve(array $specs, array $overrides, float $viewportW, float $gap, array $expected): void
    {
        self::assertSame($expected, ColumnWidths::resolve($specs, $overrides, $viewportW, $gap));
    }

    public function testClampTreatsNullAsOpen(): void
    {
        self::assertSame(0.0, ColumnWidths::clamp(-5.0, null, null));
        self::assertSame(80.0, ColumnWidths::clamp(10.0, 80.0, null));
        self::assertSame(80.0, ColumnWidths::clamp(999.0, null, 80.0));
    }
}
