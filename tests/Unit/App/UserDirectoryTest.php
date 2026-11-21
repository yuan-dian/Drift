<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use App\Showcase\Models\UserDirectory;
use Drift\Widget\TableSort;
use Drift\Widget\TableSortDirection;
use PHPUnit\Framework\TestCase;

/**
 * 用户目录的纯逻辑判据 —— **不挂树、不 mount 页面**。
 *
 * ★ 这组测试存在的理由本身就是判据：排序链、稳定性、派生列规则原先埋在
 *   `TablePage` 里，只能靠反射进私有方法验（页面一改测试就碎）。搬进
 *   `UserDirectory` 之后它们成了可独立验证的领域规则 —— 这是"分层补一层"
 *   唯一诚实的收益，代码量并不会因此减少。
 */
final class UserDirectoryTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function rows(): array
    {
        return [
            ['id' => 1, 'name' => 'B', 'email' => 'b@x', 'city' => '北京'],
            ['id' => 2, 'name' => 'A', 'email' => 'a@x', 'city' => '北京'],
            ['id' => 3, 'name' => 'C', 'email' => 'c@x', 'city' => '上海'],
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private static function ids(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row['id'];
        }

        return $ids;
    }

    public function testNoSortKeepsOriginalOrder(): void
    {
        $dir = new UserDirectory(self::rows());

        self::assertSame([1, 2, 3], self::ids($dir->sortedBy(TableSort::none())));
    }

    public function testUnknownColumnIsIgnoredInsteadOfThrowing(): void
    {
        $dir = new UserDirectory(self::rows());

        // 未注册比较器的列 key ⇒ 链为空 ⇒ 原序（控件可以送来任何 key，不能崩）
        self::assertSame([1, 2, 3], self::ids($dir->sortedBy(TableSort::by('nope'))));
    }

    public function testWholeKeyChainDecidesTies(): void
    {
        $dir = new UserDirectory(self::rows());

        // 主键（city）相等才轮到次键（name）：上海组在前，北京组内 A 在 B 前
        self::assertSame([3, 2, 1], self::ids($dir->sortedBy(TableSort::by('city')->thenBy('name'))));

        // 主键降序只翻主键方向，次键仍是升序
        self::assertSame(
            [2, 1, 3],
            self::ids($dir->sortedBy(TableSort::by('city', TableSortDirection::Descending)->thenBy('name'))),
        );
    }

    public function testSortIsStableWithinEqualKeys(): void
    {
        $rows = [
            ['id' => 4, 'name' => 'd', 'email' => 'd@x', 'city' => '北京'], // 4 % 3 = 1 正常
            ['id' => 3, 'name' => 'c', 'email' => 'c@x', 'city' => '北京'], // 3 % 3 = 0 停用
            ['id' => 1, 'name' => 'a', 'email' => 'a@x', 'city' => '北京'], // 正常
            ['id' => 6, 'name' => 'f', 'email' => 'f@x', 'city' => '北京'], // 停用
        ];

        $dir = new UserDirectory($rows);
        $sorted = $dir->sortedBy(TableSort::by('status'));

        // 正常组在前且保持原序（4 → 1），停用组在后且保持原序（3 → 6）
        self::assertSame([4, 1, 3, 6], self::ids($sorted));
    }

    public function testStatusColumnJudgementIsShared(): void
    {
        $dir = UserDirectory::seed(12);

        // 排序结果与 isDisabled() 必须同源：画面上写"停用"的行就该排在后半段
        $sorted = $dir->sortedBy(TableSort::by('status'));
        $seenDisabled = false;
        foreach ($sorted as $row) {
            $disabled = UserDirectory::isDisabled((int) $row['id']);
            if (!$disabled && $seenDisabled) {
                self::fail('正常行排在了停用行之后 —— 派生列的判据与排序规则漂移了');
            }
            $seenDisabled = $seenDisabled || $disabled;
        }

        self::assertTrue($seenDisabled, '12 行里应当存在停用行（否则这条判据没被走到）');
    }

    public function testUpdateReportsWhetherRowExisted(): void
    {
        $dir = new UserDirectory(self::rows());

        self::assertTrue($dir->update(2, 'name', '改名'));
        self::assertSame('改名', $dir->rows()[1]['name']);

        // 没这一行 ⇒ false（页面据此决定要不要推数据版本，别白重建）
        self::assertFalse($dir->update(999, 'name', '幽灵'));
    }

    public function testSeedIsDeterministic(): void
    {
        self::assertSame(UserDirectory::seed(5)->rows(), UserDirectory::seed(5)->rows());
    }

    /** 结构判据：排序算法不许再回到页面里（回去了就只剩反射能测） */
    public function testPageStaysFreeOfSortAlgorithm(): void
    {
        $src = (string) file_get_contents(
            \dirname(__DIR__, 3) . '/apps/showcase/src/Pages/TablePage.php'
        );

        self::assertStringNotContainsString('function mergeSort', $src);
        self::assertStringNotContainsString('function comparators', $src);
        self::assertStringNotContainsString('function sortedRows', $src);
    }
}
