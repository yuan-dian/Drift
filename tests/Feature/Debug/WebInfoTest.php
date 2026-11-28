<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\DevTools\Session\SessionQueries;
use Drift\Debug\Session\Thresholds;
use Drift\DevTools\View\Web;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * web 的信息呈现判据：已经有的事实要露出来，不能只躺在 tooltip 或后端响应里。
 *
 * 这一层最容易"看起来全"：接口加了字段、页面没接，截图上除了一片空白什么都看不出。
 * 判据因此既钉后端载荷，也钉页面那几处接线。
 */
final class WebInfoTest extends SmokeTestCase
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

    /** 页面 JS 的一个函数段（用于钉住接线，而不是整份文件的字符串匹配）。 */
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

    public function testNodeCardCarriesConstraintAndCauseChains(): void
    {
        $boxModel = ['found' => true, 'node' => 7, 'size' => ['w' => 10, 'h' => 10]];
        $web = $this->web([
            'nodeContext' => [
                'node' => 7,
                'frame' => 2,
                'sections' => [
                    'geometry' => ['status' => 'ok', 'reason' => null, 'frame' => 2, 'live' => false, 'data' => $boxModel],
                    'layout' => [
                        'status' => 'ok', 'reason' => null, 'frame' => 2, 'live' => false,
                        'data' => ['found' => true, 'node' => 7, 'chain' => [['from' => 3, 'to' => 7, 'verified' => true]]],
                    ],
                    'causality' => [
                        'status' => 'ok', 'reason' => null, 'frame' => 2, 'live' => false,
                        'data' => ['node' => 7, 'frame' => 2, 'rebuilt' => true, 'trail' => [['kind' => 'state']]],
                    ],
                    'properties' => [
                        'status' => 'ok', 'reason' => null, 'frame' => null, 'live' => true,
                        'data' => [
                            'found' => true, 'node' => 7, 'widget' => 'Text', 'element' => 'StatelessElement',
                            'state' => null, 'liveFrame' => 2, 'redact' => true, 'redacted' => 1,
                            'fields' => [
                                ['owner' => 'Text', 'name' => 'content', 'value' => ['kind' => 'string', 'value' => 'hi']],
                            ],
                            'omitted' => 0, 'cut' => 0, 'cycles' => 0, 'costMs' => 0.125,
                        ],
                    ],
                ],
            ],
        ]);

        $body = $this->body($web->route('GET', '/api/node', ['node' => 7, 'frame' => 2]));
        check('节点卡带约束链', ($body['constraints']['chain'][0]['from'] ?? 0) === 3, \json_encode($body['constraints'] ?? []));
        check('节点卡带因果链', ($body['why']['trail'][0]['kind'] ?? '') === 'state', \json_encode($body['why'] ?? []));
        check('约束链按请求的帧取', ($body['constraints']['node'] ?? 0) === 7, \json_encode($body['constraints'] ?? []));
        check('新页面可读 section 信封', ($body['sections']['geometry']['status'] ?? '') === 'ok', \json_encode($body['sections'] ?? []));

        // 页面读的是行模型，不是编码值：`{kind,value}` 这种查询层形态不该漏到前端。
        $view = $body['propsView'] ?? [];
        check('属性面板带行模型', ($view['rows'][0]['name'] ?? '') === 'content', \json_encode($view));
        check('行模型把值说成人话', ($view['rows'][0]['value'] ?? '') === '"hi"', \json_encode($view['rows'][0] ?? []));
        check('行模型自报裁剪与脱敏', \count($view['notes'] ?? []) === 2, \json_encode($view['notes'] ?? []));
    }

    public function testRequestAddressResolvesThroughTheQuerySurface(): void
    {
        $seen = [];
        $queries = new class($seen) implements SessionQueries {
            /** @var array<int, array<string, mixed>> */
            public array $seen = [];

            /** @param array<int, array<string, mixed>> $seen */
            public function __construct(array $seen)
            {
                $this->seen = $seen;
            }

            public function query(string $name, array $args = []): array
            {
                $this->seen[] = [$name, $args];

                return ['rows' => [['request' => 9, 'method' => 'GET', 'url' => 'https://example.test/a']], 'total' => 1];
            }
        };
        $web = new Web($queries, 'fake', Thresholds::default(), __DIR__);

        $ok = $web->route('GET', '/api/request', ['request' => 9]);
        check('请求地址有回执', $ok['status'] === 200, (string) $ok['status']);
        check('请求号走查询面', \count($queries->seen) === 1
            && ($queries->seen[0][0] ?? '') === 'network'
            && ($queries->seen[0][1]['request'] ?? 0) === 9, \json_encode($queries->seen));
        check('回执是请求的详情', ($this->body($ok)['rows'][0]['url'] ?? '') === 'https://example.test/a', $ok['body']);

        check('缺请求号 400', $web->route('GET', '/api/request')['status'] === 400, '没有 request 参数却没拒绝');
    }

    /** 发现卡：metric / 判据 / 复算必须是可见节点，不能只在 tooltip 里。 */
    public function testFindingsShowTheirEvidenceInsteadOfHidingItInATooltip(): void
    {
        $js = (string) \file_get_contents(\dirname(__DIR__, 3) . '/devtools/View/web/app.js');
        $html = (string) \file_get_contents(\dirname(__DIR__, 3) . '/devtools/View/web/index.html');

        $render = $this->functionOf($js, 'function renderFindings(findings)');
        foreach (['f.metric', 'f.threshold', 'f.recheck'] as $field) {
            check("发现卡显示 {$field}", \str_contains($render, $field), $render);
        }
        check('严重度分级有标记', \str_contains($render, 'sev-') && \str_contains($render, 'SEVERITY'), $render);
        check('数值不再只进 tooltip', !\str_contains($render, 'f.metric · '), $render);

        $header = \substr($html, 0, (int) \strpos($html, '</header>'));
        check('顶栏不再摆指纹', !\str_contains($header, 'id="digest"'), $header);
        check('顶栏不再摆阈值', !\str_contains($header, 'id="thresholds"'), $header);
        $start = (int) \strpos($html, '<details id="evidence">');
        $end = (int) \strpos($html, '</details>', $start);
        $evidence = \substr($html, $start, $end - $start);
        check('指纹搬进可展开的证据口径', \str_contains($evidence, 'id="digest"'), $evidence);
        check('规则选择也进证据口径', \str_contains($evidence, 'id="ruleInfo"'), $evidence);
    }

    /** 地址 `frame:1 node:32` 的两个部分必须一起用：只选节点会拿当前帧的几何。 */
    public function testFindingAddressUsesFrameAndNodeTogether(): void
    {
        $js = (string) \file_get_contents(\dirname(__DIR__, 3) . '/devtools/View/web/app.js');
        $jump = $this->functionOf($js, 'function jump(address)');

        check('地址里的帧号被取用', \str_contains($jump, 'loadFrame(Number(f[1]))'), $jump);
        check('地址里的节点号被取用', \str_contains($jump, 'select(Number(n[1]))'), $jump);
        check('先换帧再选节点', \strpos($jump, 'loadFrame(Number(f[1]))') < \strpos($jump, 'select(Number(n[1]))'), $jump);
        check('跳历史帧就停跟随', \str_contains($jump, 'setFollow(false)'), $jump);
        check('请求地址有落地', \str_contains($jump, 'showRequest('), $jump);
    }

    /** 帧时间轴与全域搜索用的是后端现成查询，页面不另算。 */
    public function testFrameStripAndGlobalSearchAreWired(): void
    {
        $js = (string) \file_get_contents(\dirname(__DIR__, 3) . '/devtools/View/web/app.js');
        $html = (string) \file_get_contents(\dirname(__DIR__, 3) . '/devtools/View/web/index.html');

        check('时间轴读 /api/frames', \str_contains($js, "get('/api/frames?limit=120')"), 'app.js 没有帧列表接线');
        check('点帧跳到那一帧', \str_contains($js, 'loadFrame(Number(r.id))'), '帧条目没有点击接线');
        // 钉 fetch 的实参而不是注释里的 `domain=all` —— 注释留在文件里会让变异恒绿。
        check('搜索放到 all 域', \str_contains($js, '/api/find?domain=all&limit=20'), '搜索还被限制在 node 域');
        check('页面有帧时间轴容器', \str_contains($html, 'id="frameList"'), 'index.html 没有帧列表');
    }

    /** 无图时区分"这份证据没有"与"宿主还没拍到"，别让人以为坏了。 */
    public function testEmptyShotExplainsItsSource(): void
    {
        $js = (string) \file_get_contents(\dirname(__DIR__, 3) . '/devtools/View/web/app.js');
        $html = (string) \file_get_contents(\dirname(__DIR__, 3) . '/devtools/View/web/index.html');

        check('只读证据的文案', \str_contains($js, '这份证据没有画面通道'), 'app.js 没区分只读会话');
        check('自走宿主的文案', \str_contains($js, '宿主未拍到新帧'), 'app.js 没区分宿主未拍帧');
        check('空态文案可更新', \str_contains($html, 'id="noshotWhy"') && \str_contains($html, 'id="noshotHow"'), '空态没有可更新的文案节点');
    }
}
