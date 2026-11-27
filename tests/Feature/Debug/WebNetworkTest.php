<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\DevTools\Session\SessionQueries;
use Drift\Debug\Session\Thresholds;
use Drift\Dev\Debug\View\Web;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 网络视图判据：列表走查询面，横条贴帧条，详情一路跟到绘制。
 *
 * 页面最容易出的是"接口有了、图没接"：三种视图各自正确却对不上帧，
 * 于是看起来像请求在别的帧落地。判据因此既钉后端载荷，也钉页面的对齐方式。
 */
final class WebNetworkTest extends SmokeTestCase
{
    /** @param array<string, array<string, mixed>> $answers */
    private function web(array $answers): Web
    {
        $queries = new class($answers) implements SessionQueries {
            /** @param array<string, array<string, mixed>> $answers */
            public function __construct(private readonly array $answers)
            {
            }

            public function query(string $name, array $args = []): array
            {
                return $this->answers[$name] ?? [];
            }
        };

        return new Web($queries, 'fake', Thresholds::default(), __DIR__);
    }

    /** @return array<string, mixed> */
    private function body(array $response): array
    {
        $decoded = \json_decode($response['body'], true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function functionOf(string $js, string $name): string
    {
        $start = \strpos($js, $name);
        self::assertIsInt($start, "找不到 {$name}");
        $end = \strpos($js, "\nasync function ", $start + 1);
        if ($end === false) {
            $end = \strpos($js, "\nfunction ", $start + 1);
        }
        self::assertIsInt($end, "找不到 {$name} 的结尾");

        return \substr($js, $start, $end - $start);
    }

    public function testNetworkListIsForwardedVerbatim(): void
    {
        $queries = new class implements SessionQueries {
            /** @var array<int, array{string, array<string, mixed>}> */
            public array $seen = [];

            public function query(string $name, array $args = []): array
            {
                $this->seen[] = [$name, $args];

                return [
                    'rows' => [['request' => 3, 'method' => 'GET', 'url' => 'https://example.test/a']],
                    'window' => ['captured' => true, 'requests' => 1],
                    'total' => 1,
                ];
            }
        };
        $web = new Web($queries, 'fake', Thresholds::default(), __DIR__);

        $res = $web->route('GET', '/api/network', ['limit' => 25]);
        check('列表有回执', $res['status'] === 200, (string) $res['status']);
        check('列表走查询面并透传 limit', \count($queries->seen) === 1
            && ($queries->seen[0][0] ?? '') === 'network'
            && ($queries->seen[0][1]['limit'] ?? 0) === 25, \json_encode($queries->seen));

        $body = $this->body($res);
        check('请求骨架原样带出', ($body['rows'][0]['url'] ?? '') === 'https://example.test/a', $res['body']);
        check('覆盖窗口原样带出', ($body['window']['captured'] ?? false) === true, $res['body']);
        check('真实条数原样带出', ($body['total'] ?? 0) === 1, $res['body']);
    }

    public function testRequestLaneSharesTheFrameAxis(): void
    {
        $js = (string) \file_get_contents(\dirname(__DIR__, 3) . '/tools/Debug/View/web/app.js');
        $html = (string) \file_get_contents(\dirname(__DIR__, 3) . '/tools/Debug/View/web/index.html');

        check('泳道读 /api/network', \str_contains($js, "get('/api/network?limit=100')"), 'app.js 没有请求列表接线');
        check('请求条有容器', \str_contains($html, 'id="netlane"'), 'index.html 没有泳道');
        check('帧条与泳道同处一个横滚容器', \str_contains($html, 'id="axis"'), '两个轴分开滚会对不齐');
        check('每格一帧的口径常驻', \str_contains($html, '每格一帧，静止空帧已折叠'), $html);
        check('采集范围常驻', \str_contains($html, '仅统计经 Drift HTTP 客户端的请求'), $html);

        $place = $this->functionOf($js, 'function placeNetwork()');
        check('按帧条实际位置贴条', \str_contains($place, 'offsetLeft') && \str_contains($place, 'offsetWidth'), $place);
        check('窗口外的请求标虚线', \str_contains($place, "'outside'"), $place);
        check('结算帧在帧条上留标记', \str_contains($place, "'settled'"), $place);

        $render = $this->functionOf($js, 'function renderNetwork()');
        check('请求条点开详情', \str_contains($render, 'showRequest(Number(r.request))'), $render);
        check('空态区分没采与没有', \str_contains($render, '这份证据没有开网络采集')
            && \str_contains($render, '这段证据没有请求'), $render);
    }

    public function testRequestDetailFollowsTheChainToPaint(): void
    {
        $js = (string) \file_get_contents(\dirname(__DIR__, 3) . '/tools/Debug/View/web/app.js');
        $show = $this->functionOf($js, 'async function showRequest(id)');

        check('选中请求在泳道上高亮', \str_contains($show, 'markActiveRequest()'), $show);
        check('落地节点可点回控件树', \str_contains($show, 'node:${node}') && \str_contains($show, 'select(Number(node))'), $show);
        check('结算帧的重建数', \str_contains($show, 'summary.rebuilt'), $show);
        check('结算帧的因果数', \str_contains($show, 'summary.causes'), $show);
        check('跳回结算帧', \str_contains($show, 'loadFrame(frame)'), $show);
        check('读那一帧的绘制', \str_contains($show, 'get(`/api/paint?frame=${frame}`)'), $show);
    }
}
