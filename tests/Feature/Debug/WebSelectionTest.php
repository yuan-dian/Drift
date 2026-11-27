<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\DevTools\Session\SessionQueries;
use Drift\Debug\Session\Thresholds;
use Drift\Dev\Debug\View\Web;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * web 与检查器共用一份选中：宿主 `query('highlight')` 是真相，页面只是镜像。
 *
 * 页面自己记一份选中看似更快，但真窗口拾取或别的出口改了高亮时，那份缓存永远
 * 不知道；而两边的"我现在选了谁"一旦分叉，节点卡和真机描边就会指向不同东西。
 */
final class WebSelectionTest extends SmokeTestCase
{
    /** @param array<string, mixed> $highlight */
    private function web(?\Closure $control, array $highlight): Web
    {
        $queries = new class($highlight) implements SessionQueries {
            /** @param array<string, mixed> $highlight */
            public function __construct(private readonly array $highlight)
            {
            }

            public function query(string $name, array $args = []): array
            {
                return $name === 'highlight' ? $this->highlight : [];
            }
        };

        return new Web($queries, 'fake', Thresholds::default(), __DIR__, $control);
    }

    /** @return array<string, mixed> */
    private function body(array $response): array
    {
        $decoded = \json_decode($response['body'], true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testSelectionIsReadFromTheHost(): void
    {
        $writable = $this->body($this->web(static fn(): array => [], ['node' => 7, 'onScreen' => true])->route('GET', '/api/highlight'));
        check('读的是宿主查询的选中', ($writable['highlight']['node'] ?? 0) === 7, \json_encode($writable));
        check('报告干预面有无', ($writable['controllable'] ?? false) === true, \json_encode($writable));

        $readonly = $this->body($this->web(null, ['node' => 7])->route('GET', '/api/highlight'));
        check('离线包也读得到选中', ($readonly['highlight']['node'] ?? 0) === 7, \json_encode($readonly));
        check('离线包不可写', ($readonly['controllable'] ?? true) === false, \json_encode($readonly));
    }

    public function testCanvasClickWritesHostHighlight(): void
    {
        $calls = [];
        $control = static function (string $action, array $body) use (&$calls): array {
            $calls[] = [$action, $body];

            return ['highlight' => ['node' => (int) ($body['node'] ?? 0), 'onScreen' => false]];
        };
        $web = $this->web($control, ['node' => 0]);

        $ok = $web->route('POST', '/api/ctl', [], ['action' => 'highlight', 'node' => 12]);
        check('高亮请求被接受', $ok['status'] === 200, (string) $ok['status']);
        check('只调了一次干预面', \count($calls) === 1, \json_encode($calls));
        check('动作是 highlight', ($calls[0][0] ?? '') === 'highlight', \json_encode($calls));
        check('节点号原样到宿主', ($calls[0][1]['node'] ?? 0) === 12, \json_encode($calls));
        check('回执给宿主侧的真相', ($this->body($ok)['highlight']['node'] ?? 0) === 12, $ok['body']);

        foreach ([['node' => -1], ['node' => '12']] as $bad) {
            $rejected = $web->route('POST', '/api/ctl', [], ['action' => 'highlight'] + $bad);
            check('非法节点 400', $rejected['status'] === 400, (string) $rejected['status']);
        }
        check('非法请求没碰到宿主', \count($calls) === 1, \json_encode($calls));
    }

    public function testPickIsForwardedToTheLiveWindow(): void
    {
        $calls = [];
        $control = static function (string $action, array $body) use (&$calls): array {
            $calls[] = [$action, $body];

            return ['armed' => true];
        };
        $web = $this->web($control, ['node' => 0]);

        $ok = $web->route('POST', '/api/ctl', [], ['action' => 'pick', 'enabled' => true]);
        check('拾取布防被接受', $ok['status'] === 200, (string) $ok['status']);
        check('布防参数原样到宿主', \count($calls) === 1
            && ($calls[0][0] ?? '') === 'pick'
            && ($calls[0][1]['enabled'] ?? null) === true, \json_encode($calls));

        $bad = $web->route('POST', '/api/ctl', [], ['action' => 'pick', 'enabled' => 'yes']);
        check('非法 enabled 400', $bad['status'] === 400, (string) $bad['status']);
        check('非法请求没碰到宿主', \count($calls) === 1, \json_encode($calls));
    }

    public function testReadOnlySessionRefusesBothInterventions(): void
    {
        $web = $this->web(null, ['node' => 0]);
        foreach ([
            ['action' => 'highlight', 'node' => 1],
            ['action' => 'pick', 'enabled' => true],
        ] as $body) {
            $response = $web->route('POST', '/api/ctl', [], $body);
            check('没有干预面回 501', $response['status'] === 501, (string) $response['status']);
        }
    }

    /** 页面那一半是 JS：钉住"点击写宿主、轮询读宿主、轮询不回写"这三条接线。 */
    public function testPageMirrorsHostInsteadOfKeepingItsOwnTruth(): void
    {
        $js = (string) \file_get_contents(\dirname(__DIR__, 3) . '/tools/Debug/View/web/app.js');
        $html = (string) \file_get_contents(\dirname(__DIR__, 3) . '/tools/Debug/View/web/index.html');

        check('点击画布写宿主高亮', \str_contains($js, "{ action: 'highlight', node }"), 'app.js 没有 highlight 动作');
        check('轮询读宿主选中', \str_contains($js, "get('/api/highlight')"), 'app.js 没有读 /api/highlight');

        $start = \strpos($js, 'async function pollSelection()');
        self::assertIsInt($start, '找不到 pollSelection');
        $end = \strpos($js, "\nasync function ", $start + 1);
        self::assertIsInt($end, '找不到 pollSelection 的结尾');
        $poll = \substr($js, $start, $end - $start);
        check('轮询只改镜像不回写', !\str_contains($poll, 'post('), $poll);

        check('真机拾取按钮存在', \str_contains($html, 'data-act="pick"'), '拾取按钮不在页面里');
        check('拾取动作发到宿主', \str_contains($js, "{ action: 'pick', enabled: true }"), 'app.js 没有真机拾取接线');
    }
}
