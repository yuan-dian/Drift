<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\Async\Future;
use Drift\Debug\Session\Recorder;
use Drift\Dev\Smoke\App\PageHostApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;

/**
 * 判据：**真实页面**（示例台的表格 / 异步页）必须能被无头挂起来并产生归因。
 *
 * ## 为什么这条判据存在
 *
 * `drift debug` 原先只能跑"构造器无参的冒烟夹具"。真实场景里最需要归因的几页
 * （`async-table` / `load-more` / `concurrent-fetch`）都要注入取数闭包，一个都
 * `new` 不出来 —— 也就是说"用真页面跑一轮"这件事在工具层面**到不了**，而工具
 * 自己不会因此报错，它只会安静地给你一份"什么都没发生"的报告。
 *
 * 于是钉两条：
 * 1. 路由 id 能建出真实页面（含依赖页），名字敲错要**列出候选**；
 * 2. 异步取数结算**确实产生一条可归因的 `kind=state` 边** —— 这是异步页的唯一
 *    看点。只挂起来不断言结算，等于证明"能挂载"，不能证明"能被解释"。
 */
final class PageHostTest extends SmokeTestCase
{
    private function constraints(): BoxConstraints
    {
        return BoxConstraints::tight(new Size(800.0, 600.0));
    }

    public function testRoutesExposeRealPages(): void
    {
        $routes = PageHostApplication::routes();

        check(
            '路由 id 里同时有同步表格与异步表格',
            \in_array('table', $routes, true) && \in_array('async-table', $routes, true),
            'routes=' . \implode(',', \array_slice($routes, 0, 8)),
        );
    }

    public function testUnknownRouteListsCandidates(): void
    {
        $message = '';
        try {
            PageHostApplication::forRoute('nope');
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        check(
            '未知路由报错并给出候选',
            \str_contains($message, 'nope') && \str_contains($message, 'table'),
            'message=' . $message,
        );
    }

    public function testAsyncPageSettlesIntoAnAttributableFrame(): void
    {
        $future = null;
        $app = PageHostApplication::forRoute('async-table', static function (int $page) use (&$future): Future {
            $future = Future::pending();

            return $future;
        });
        $app->attach(new SurfaceProbe(), $this->constraints());

        // ★ 与 `DebugSession::open()` 同一起点：先 attach 再装录制器。
        $recorder = Recorder::install();

        try {
            $app->frame(0.0, $this->constraints());      // 1：挂载 + 首帧，转圈
            $app->frame(16.0, $this->constraints());     // 2：螺旋转动

            check('取数真的被发起（页面在等）', $future instanceof Future);
            $future?->resolve(['items' => [
                ['id' => 1, 'name' => '行一', 'email' => 'a@b.c', 'city' => '城A'],
                ['id' => 2, 'name' => '行二', 'email' => 'd@e.f', 'city' => '城B'],
            ], 'total' => 2, 'source' => 'test']);

            $app->frame(32.0, $this->constraints());     // 3：数据落地那一帧

            $frame = $recorder->frame($recorder->lastFrameId());
            check('第三帧存在', $frame !== null);

            $roots = [];
            foreach ($frame?->causes ?? [] as $edge) {
                if ($edge->kind === 'state') {
                    $roots[] = $edge->site . ' ' . $edge->detail;
                }
            }
            $joined = \implode(' | ', $roots);
            check(
                '结算产生 kind=state 根因（AsyncCommand 的写入）',
                \str_contains($joined, 'AsyncCommand.php'),
                'roots=' . $joined,
            );
            check(
                '数据落地那一帧重建了成片节点',
                \count($frame?->rebuilt ?? []) > 10,
                'rebuilt=' . \count($frame?->rebuilt ?? []),
            );
        } finally {
            Recorder::uninstall();
        }
    }
}
