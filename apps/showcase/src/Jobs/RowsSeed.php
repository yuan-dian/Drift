<?php
declare(strict_types=1);

namespace App\Showcase\Jobs;

/**
 * 表格行的**数据形状定义**（纯函数，没有 IO、没有 Drift 依赖）。
 *
 * ## 为什么单独抽出来
 *
 * 同一页数据有两个来源要用：
 *
 * | 谁 | 场景 |
 * |---|---|
 * | `FetchRowsJob`（local 档） | 零依赖演示（`usleep` 造慢） |
 * | `tools/app-workers/showcase-mock.php`（http 档） | 真 HTTP（`php -S` 造慢） |
 *
 * 如果两边各写一份生成逻辑，改一个字段就要改两处，而且**很容易只改一处** ——
 * 之后 `local` 模式能画表、`http` 模式画空白，排查方向会完全跑偏（看着像"异步坏了"，
 * 其实是 mock 少给了字段）。抽成一份后，两边只共享"行长什么样"，
 * "怎么慢"仍然各自实现（这正是它们该不同的地方）。
 * HTTP 档取数在 `ShowcaseApplication::fetchRowsOverHttp()`，本类只供载荷形状。
 *
 * ★ 不放进 `src/`：它是示例台的业务数据，不是框架能力。
 * ★ `usleep` **不在**这里 —— 本类只造数据，耗时由调用方决定。
 */
final class RowsSeed
{
    /** 总行数 —— 故意不是 20 的整数倍，让"最后一页不满"这个边界真的出现 */
    public const TOTAL = 137;

    /**
     * 取一页行（页码从 1 开始；越界返回空数组，不抛）。
     *
     * ★ 越界**不抛异常**是刻意的：真实后端对"翻过头"通常返回空列表 + 总数，
     *   页面据此显示"这一页没有数据"并留在当前页 —— 抛异常会让 UI 进 error 态，
     *   用户看到"加载失败"却不知道为什么，这是最常见的错误建模。
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public static function page(int $page, int $perPage): array
    {
        if ($page < 1 || $perPage < 1) {
            return ['items' => [], 'total' => self::TOTAL];
        }

        $cities = self::cities();
        $start = ($page - 1) * $perPage;
        $end = \min($start + $perPage, self::TOTAL);

        $rows = [];
        for ($i = $start + 1; $i <= $end; $i++) {
            $rows[] = self::row($i, $cities);
        }

        return ['items' => $rows, 'total' => self::TOTAL];
    }

    /**
     * 一行的字段（列定义在 `AsyncTablePage::columns()`，两边必须对得上）。
     *
     * @param list<string> $cities
     *
     * @return array<string, mixed>
     */
    private static function row(int $i, array $cities): array
    {
        return [
            'id' => $i,
            'name' => '用户 ' . $i,
            'email' => 'user' . $i . '@example.com',
            'city' => $cities[$i % \count($cities)],
        ];
    }

    /**
     * 城市池 —— 用中文而不是 `city-3`，因为这一列在示例台里是用来**看字形**的
     * （等宽/字重/截断问题只有在真汉字下才暴露）。
     *
     * @return list<string>
     */
    private static function cities(): array
    {
        return ['北京', '上海', '广州', '深圳', '杭州'];
    }
}
