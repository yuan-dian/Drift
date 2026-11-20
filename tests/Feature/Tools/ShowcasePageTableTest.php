<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Tools;

use Drift\Dev\Bench\ShowcasePages;
use Drift\Dev\Smoke\App\ShowcaseCaps;
use Drift\Widget\Widget;
use PHPUnit\Framework\TestCase;

/**
 * 量具的页表必须**真的产出可挂载 Widget**。
 *
 * ★ 为什么值得一条测试：页表是手写的 `页名 => 闭包`，单类页面（`Page` 是
 *   `State` 子类）漏了 `->host()` 时，PHP 的类型检查发生在**闭包返回那一刻**，
 *   而 bench 不在日常 `verify` 里 —— 结果就是"改了 33 页、报告全绿、bench 一跑
 *   就 TypeError"。2026-11-21 的迁移正是这么漏了 11 页。
 * ★ 只判"是不是 Widget"，不判长什么样：像素归 golden，这里挡的是接线。
 */
final class ShowcasePageTableTest extends TestCase
{
    public function testEveryBenchEntryBuildsAMountableWidget(): void
    {
        $caps = ShowcaseCaps::make();
        $broken = [];

        foreach (ShowcasePages::all() as $name => $make) {
            // 漏 host() 时 PHP 在**返回那一刻**报 TypeError ⇒ 收下来一起报，
            // 不然一次只看到一页，27 页要跑 27 轮。
            try {
                $page = $make($caps);
            } catch (\TypeError $e) {
                $broken[] = $name . '：' . $e->getMessage();
                continue;
            }

            if (!$page instanceof Widget) {
                $broken[] = $name . ' => ' . get_debug_type($page);
            }
        }

        self::assertSame(
            [],
            $broken,
            '页表条目不是 Widget（单类 Page 忘了 ->host()？）：' . implode('、', $broken),
        );
    }
}
