<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\App\Application;
use Drift\Async\Future;
use Drift\Dev\Smoke\App\AsyncTableApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;

/**
 * `AsyncTablePage`（示例台的异步表格页）—— **建树 + 三态**的冒烟。
 *
 * ## 为什么单独有这一个测试类
 *
 * `tools/golden-raster.php` 自动发现 `apps/showcase/src/Pages/*.php`，但只收
 * 「构造器无必填参数」的页；`AsyncTablePage` 要注入取数闭包 ⇒ 被跳过。
 * 于是这一页**没有任何基线**覆盖它的 `build()`，而它恰好有三个只有真建树
 * 才走到的风险点：
 *
 * | 风险点 | 表现 |
 * |---|---|
 * | `future === null` 分支 | 首帧还没 `initState` 完就 `build` ⇒ 读 null |
 * | `Table` 在 `Expanded` 里无界 | 布局期断言 / 高度为 0 |
 * | error 态漏 `catch` | 一次失败走 Fatal 序列，整个进程收摊 |
 *
 * ★ 这三条**单测都测不出来**（要真的挂树 + 真的跑帧）。所以这里用
 *   `AsyncTableApplication` 把页面当根，跑几帧看诊断缓冲。
 *
 * ★ 判据用 `Errors::recentDiagnostics()` 而不是"看有没有异常"：
 *   §1.2 的三步标准中止序列**不抛异常**，它写日志 + 退出进程 ——
 *   在解释器里表现为"用例莫名其妙没跑完"。缓冲是唯一看得见的痕迹。
 */
final class AsyncTablePageTest extends SmokeTestCase
{
    private static function attach(Application $app): void
    {
        $surface = new SurfaceProbe();
        $surface->resize(900, 640, 1.0);
        $app->attach($surface, BoxConstraints::tight(new Size(900.0, 640.0)));
    }

    /** 跑几帧（时间戳递增，否则帧时钟判"没前进"） */
    private static function frames(Application $app, int $count = 3): void
    {
        $constraints = BoxConstraints::tight(new Size(900.0, 640.0));
        for ($i = 0; $i < $count; $i++) {
            $app->frame($i * 16.0, $constraints);
        }
    }

    /**
     * 正常路径：进页面就请求第一页 → data 态画出表格 → 三帧无诊断。
     *
     * ★ 「只请求一次」这条同样是判据：`initState()` 里 `load(1)` + 首帧重建
     *   若重复提交，每帧都会多打一次后台请求（真实网络下 = 雪崩）。
     */
    public function testLoadsFirstPageAndRendersTable(): void
    {
        Errors::reset();

        $app = new AsyncTableApplication(static function (int $page): Future {
            return Future::resolved([
                'items' => [
                    ['id' => 1, 'name' => '用户 1', 'email' => 'user1@example.com', 'city' => '北京'],
                    ['id' => 2, 'name' => '用户 2', 'email' => 'user2@example.com', 'city' => '上海'],
                    ['id' => 3, 'name' => '用户 3', 'email' => 'user3@example.com', 'city' => '广州'],
                ],
                'total' => 137,
                'source' => '测试',
            ]);
        });

        self::attach($app);
        self::frames($app, 3);

        check('进页面即请求第 1 页', $app->requested === [1]);
        check('首帧之后树还在（页面能建树）', $app->root() !== null);
        check('三帧无诊断（无 RECOVERABLE / Fatal）', Errors::recentDiagnostics() === []);

        $this->flushChecks();
    }

    /**
     * 失败路径：`Future` 失败 ⇒ 页面落到 error 态（可重试），**不打断渲染管线**。
     *
     * ★ 这是 §11.4「UI 不因业务失败而消失」的判据。如果 `AsyncCommand`
     *   哪天漏了 `catch`，这条会以「进程直接没了」的形式失败 —— 不是断言红。
     */
    public function testRejectedFutureKeepsThePageAlive(): void
    {
        Errors::reset();

        $app = new AsyncTableApplication(static function (int $page): Future {
            return Future::rejected(new \RuntimeException('网络错误：测试注入'));
        });

        self::attach($app);
        self::frames($app, 3);

        check('失败后树还在（error 态渲染得出来）', $app->root() !== null);
        check('失败不产生诊断（AsyncCommand 的 catch 接住了）', Errors::recentDiagnostics() === []);
        check('失败不影响提交（只请求了 1 次，没有自动重试风暴）', $app->requested === [1]);

        $this->flushChecks();
    }
}
