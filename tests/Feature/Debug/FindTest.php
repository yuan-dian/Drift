<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\Application;
use Drift\Debug\Session\Query;
use Drift\Debug\Session\History;
use Drift\Debug\Session\Recorder;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\Padding;
use Drift\Widget\Row;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * S5 搜索判据：`find()` 的产物是**地址**，且"没搜到"的三种原因分得开。
 *
 * ## 钉什么
 *
 * - 命中集合 == 自身类型匹配的节点集合（**不按祖先路径命中**，那会把整棵子树
 *   都算进去：实测 `Table` 命中 346/364）；
 * - 每个命中地址能被对应查询直接解析（`node:12` → `boxModel`，`request:3` → `network`）；
 * - `scanned` / `matched` / `returned` 三个数各自成立且能对上；
 * - 截断、空窗口、已回收三种"没结果"各自自报，不合并成一句"没有命中"；
 * - 不做正则：`Padding|Text` 按字面找，找不到就是找不到，不猜"或"。
 *
 * ## 为什么用替身 transport 造请求
 *
 * 真 socket 不稳定；搜索要钉的是"命中的是什么、地址解析得开吗"，与后端无关。
 */
final class FindTest extends SmokeTestCase
{
    private function constraints(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(200.0, 120.0));
    }

    private function boot(): Application
    {
        $app = new FindApplication();
        $app->attach(new SurfaceProbe(), $this->constraints());
        $app->frame(0.0, $this->constraints());

        return $app;
    }

    public function testNodeDomainMatchesOwnTypeNotAncestors(): void
    {
        $app = $this->boot();
        $recorder = Recorder::install();
        $history = new History($recorder);
        try {
            // 节点只在录制器装着的时候发号：boot() 的首帧早于 install，得再推一帧。
            $app->frame(16.0, $this->constraints());
            $queries = new Query($recorder);

            $answer = $queries->find('Padding');

            // 独立复算：数一遍节点表里类型含 Padding 的节点。
            $expected = 0;
            foreach ($history->nodeIndex() as $id => $_meta) {
                if (\stripos($history->typeOf($id), 'Padding') !== false) {
                    ++$expected;
                }
            }
            check('命中集合 == 自身类型匹配的节点集合', $answer['total'] === $expected,
                'total=' . $answer['total'] . ' expected=' . $expected);
            check('没有命中一整棵子树', $answer['total'] < (int) $answer['domains']['node']['scanned'],
                'total=' . $answer['total'] . ' scanned=' . $answer['domains']['node']['scanned']);

            $first = $answer['matches'][0] ?? [];
            check('命中项带地址与位置', \str_starts_with((string) ($first['address'] ?? ''), 'node:')
                && \str_contains((string) ($first['text'] ?? ''), 'Column'),
                \json_encode($first));

            // 地址必须能直接喂给别的查询 —— 这就是"搜索的产物是地址"的可判形式。
            $id = (int) \substr((string) $first['address'], \strlen('node:'));
            $box = $queries->boxModel($id);
            check('命中地址能被 boxModel 解析', ($box['found'] ?? false) === true, \json_encode($box));
        } finally {
            Recorder::uninstall();
        }

        $this->flushChecks();
    }

    public function testRequestDomainMatchesMethodUrlAndStatus(): void
    {
        $this->boot();
        $recorder = Recorder::install();
        $history = new History($recorder);
        try {
            $queries = new Query($recorder);

            Recorder::tapNetworkStart('GET', 'https://api.example.com/rows.php', 'inline');
            Recorder::tapNetworkStart('POST', 'https://api.example.com/save.php', 'inline');

            $byUrl = $queries->find('rows', 'request');
            check('按 URL 片段命中一条', $byUrl['total'] === 1, 'total=' . $byUrl['total']);
            check('命中地址是 request:<id>', ($byUrl['matches'][0]['address'] ?? '') === 'request:1',
                \json_encode($byUrl['matches'][0] ?? []));

            $byMethod = $queries->find('post', 'request');
            check('按方法命中（默认不区分大小写）', $byMethod['total'] === 1, 'total=' . $byMethod['total']);

            $byHost = $queries->find('api.example.com', 'request');
            check('按主机命中两条', $byHost['total'] === 2, 'total=' . $byHost['total']);

            $id = (int) \substr((string) $byUrl['matches'][0]['address'], \strlen('request:'));
            $skeleton = $queries->network($id);
            check('命中地址能被 network 解析', $skeleton['total'] === 1, \json_encode($skeleton));

            check('请求域自报网络窗口',
                isset($byUrl['domains']['request']['window'])
                && ($byUrl['domains']['request']['window']['capacity'] ?? 0) === Recorder::NETWORK_CAPACITY,
                \json_encode($byUrl['domains']['request']['window'] ?? []));
        } finally {
            Recorder::uninstall();
        }

        $this->flushChecks();
    }

    public function testCaseLiteralAndDomainSelection(): void
    {
        $app = $this->boot();
        $recorder = Recorder::install();
        $history = new History($recorder);
        try {
            $app->frame(16.0, $this->constraints());
            $queries = new Query($recorder);

            check('默认不区分大小写', $queries->find('padding')['total'] > 0);
            check('区分大小写时小写不命中', $queries->find('padding', 'all', 50, true)['total'] === 0);

            // 不做正则：`|` 是字面字符。若有人把实现换成 preg_match，这条会红。
            check('竖线按字面找，不当"或"', $queries->find('Padding|Text')['total'] === 0);

            $onlyNode = $queries->find('a', 'node');
            check('只搜一个域时另一个域不出现', !isset($onlyNode['domains']['request']), \json_encode(\array_keys($onlyNode['domains'])));
            check('自报实际搜了哪个域', $onlyNode['domain'] === 'node', $onlyNode['domain']);
        } finally {
            Recorder::uninstall();
        }

        $this->flushChecks();
    }

    public function testCountsAndTruncationAreConsistent(): void
    {
        $app = $this->boot();
        $recorder = Recorder::install();
        $history = new History($recorder);
        try {
            $app->frame(16.0, $this->constraints());
            $queries = new Query($recorder);
            Recorder::tapNetworkStart('GET', 'https://a.example.com/one', 'inline');
            Recorder::tapNetworkStart('GET', 'https://a.example.com/two', 'inline');

            $full = $queries->find('example.com');
            $capped = $queries->find('example.com', 'all', 1);

            check('命中总数不受 limit 影响', $full['total'] === $capped['total'], 'full=' . $full['total'] . ' capped=' . $capped['total']);
            check('limit 只裁返回，且标截断', $capped['returned'] === 1 && $capped['truncated'] === true,
                'returned=' . $capped['returned'] . ' truncated=' . \var_export($capped['truncated'], true));
            check('没截断时不谎报截断', $full['truncated'] === false);

            $sumMatched = 0;
            $sumReturned = 0;
            foreach ($capped['domains'] as $one) {
                $sumMatched += (int) $one['matched'];
                $sumReturned += (int) $one['returned'];
            }
            check('逐域命中之和 == 总数', $sumMatched === $capped['total']);
            check('逐域返回之和 == 返回数', $sumReturned === $capped['returned']);
            check('scanned 不小于 matched', $capped['domains']['request']['scanned'] >= $capped['domains']['request']['matched']);

            // 空窗口与"搜了没命中"必须分开：前者 scanned=0，后者 scanned>0。
            check('空窗口自报 scanned=0', $queries->find('zzz', 'request')['domains']['request']['scanned'] === 2);
            check('搜了没命中时 scanned 仍是我扫过的数',
                $queries->find('zzz', 'node')['domains']['node']['scanned'] === \count($history->nodeIndex()));

            check('已回收节点数随答案自报',
                $capped['domains']['node']['evicted'] === \count($history->evictedNodes()));
        } finally {
            Recorder::uninstall();
        }

        $this->flushChecks();
    }

    /**
     * 已回收的节点**搜不到**，但"搜不到"不等于"没有过"。
     *
     * 这条用真实的回收路径（`recycleNodes`）摆状态，不用假造 `evicted` 数组 ——
     * 判据要能证明"回收之后 `find` 会漏掉它，同时把漏掉的原因报出来"。
     */
    public function testEvictedNodeIsNotSearchableButIsReported(): void
    {
        $app = $this->boot();
        $recorder = Recorder::install();
        $history = new History($recorder);
        try {
            $app->frame(16.0, $this->constraints());

            $set = static function (string $name, mixed $value) use ($recorder): void {
                $property = new \ReflectionProperty(Recorder::class, $name);
                $property->setValue($recorder, $value);
            };
            // 一个早就没再出现（s=1）、也不被任何帧 / 几何引用的节点。
            $set('nodes', [
                1 => ['p' => -1, 'c' => 'Gone', 'x' => false, 's' => 1],
                2 => ['p' => -1, 'c' => 'Live', 'x' => false, 's' => 200],
            ]);
            $set('evicted', []);
            $set('boxless', []);
            $set('geometryDigest', []);
            $set('frames', []);
            // ★ 几何 / 网络落地都算"引用"，会保住节点。真实场景里它们随窗口
            //   一起滚掉；夹具不滚窗口，就得显式清掉 —— 否则"回收"根本没发生，
            //   而判据会以为自己验过了。属性已降为即时内省（A55），不进 Recorder。
            $set('geometry', []);
            $set('network', []);
            $set('frameId', 200);

            $recycle = new \ReflectionMethod(Recorder::class, 'recycleNodes');
            $recycle->invoke($recorder);
            check('夹具真的回收了一个节点', $history->nodeStatus(1) === 'evicted');

            $queries = new Query($recorder);
            $gone = $queries->find('Gone');
            check('已回收的节点搜不到', $gone['total'] === 0, \json_encode($gone['matches']));
            check('但把"有节点被回收"报出来', (int) $gone['domains']['node']['evicted'] === 1,
                \json_encode($gone['domains']['node']));
            check('仍活着的节点照常搜到', $queries->find('Live')['total'] === 1);
        } finally {
            Recorder::uninstall();
        }

        $this->flushChecks();
    }

    public function testRejectsInputThatWouldFakeAResult(): void
    {
        $this->boot();
        $recorder = Recorder::install();
        $history = new History($recorder);
        try {
            $queries = new Query($recorder);

            $empty = '';
            try {
                $queries->find('   ');
                $empty = 'no-throw';
            } catch (\InvalidArgumentException $e) {
                $empty = $e->getMessage();
            }
            check('空 pattern 报错而不是匹配一切', $empty !== 'no-throw' && \str_contains($empty, 'pattern'), $empty);

            $domain = '';
            try {
                $queries->find('x', 'frame');
                $domain = 'no-throw';
            } catch (\InvalidArgumentException $e) {
                $domain = $e->getMessage();
            }
            check('未知域报错并列出可用域',
                \str_contains($domain, 'all') && \str_contains($domain, 'request'), $domain);

            $limit = '';
            try {
                $queries->find('x', 'all', 0);
                $limit = 'no-throw';
            } catch (\InvalidArgumentException $e) {
                $limit = $e->getMessage();
            }
            check('limit 非正报错', $limit !== 'no-throw', $limit);
        } finally {
            Recorder::uninstall();
        }

        $this->flushChecks();
    }
}

/** @internal 判据夹具：一棵类型可区分的固定树 */
final class FindApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Column::make()->children([
            Padding::make(Text::make('a'))->all(4),
            Padding::make(Row::make()->children([
                Text::make('b'),
                Text::make('c'),
            ]))->all(8),
        ]);
    }
}
