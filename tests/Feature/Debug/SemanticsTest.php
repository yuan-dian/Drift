<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\Application;
use Drift\Debug\Session\EvidenceBag;
use Drift\DevTools\Session\Evidence;
use Drift\Debug\Session\RecordOptions;
use Drift\Debug\Session\Recorder;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\DevTools\App\DebugSession;
use Drift\DevTools\Session\History;
use Drift\DevTools\Session\QueryRouter;
use Drift\DevTools\Session\SemanticsQuery;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\SemanticsNode;
use Drift\Input\SemanticsTree;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Widget;

/**
 * 无障碍域判据（A77）：语义树是 L2 内省，字段来自生产导出，不进证据。
 */
final class SemanticsTest extends SmokeTestCase
{
    public function testLiveTreeIsBuiltFromTheProductionBuilder(): void
    {
        [$session, $app] = $this->live();
        try {
            $app->frame(16.0, $this->constraints());
            $answer = $session->query('semantics');

            check('读到语义树', ($answer['found'] ?? false) === true, \json_encode($answer));
            check('下标口径写明是 UIA 快照', ($answer['indexBasis'] ?? '') === 'uia-snapshot', \json_encode($answer));
            check('带 liveFrame 而不是伪称对齐历史帧', \is_int($answer['liveFrame'] ?? null), \json_encode($answer));

            $names = [];
            foreach ($answer['rows'] ?? [] as $row) {
                $names[] = (string) ($row['name'] ?? '');
                check('行没有伪造结构节点号', !\array_key_exists('node', $row), \json_encode($row));
            }
            check('按钮声明的名字原样进树', \in_array('甲', $names, true) && \in_array('乙', $names, true), \implode(',', $names));
        } finally {
            $session->close();
        }
        $this->flushChecks();
    }

    public function testEveryFieldComesFromTheProductionExport(): void
    {
        $tree = new SemanticsTree();
        $n = new SemanticsNode();
        $n->role = 'button';
        $n->type = 'Button';
        $n->key = 'k';
        $n->name = '名';
        $n->value = '值';
        $n->rangeMin = 1.5;
        $n->rangeMax = 9.5;
        $n->selectState = 2;
        $n->expandState = 1;
        $n->level = 3;
        $n->hint = '提示';
        $n->automationId = 'aid';
        $n->acceleratorKey = 'Ctrl+A';
        $n->scrollAxis = 2;
        $n->scrollOffset = 4.0;
        $n->scrollMax = 20.0;
        $n->scrollViewportW = 30.0;
        $n->scrollViewportH = 40.0;
        $n->x = 5;
        $n->y = 6;
        $n->w = 7;
        $n->h = 8;
        $tree->add($n);

        $query = $this->query();
        $got = $query->node($tree, 0);
        $export = $tree->toArrays()['nodes'][0];
        $diff = [];
        foreach ($export as $key => $value) {
            if (!\array_key_exists($key, $got) || $got[$key] !== $value) {
                $diff[] = $key;
            }
        }
        check('字段与生产导出逐项相同', $diff === [], '不同的：' . \implode(',', $diff));

        $rows = $query->tree($tree)['rows'];
        check('树行也带同一组字段', \array_keys($rows[0]) === \array_merge(['index'], \array_keys($export)), \json_encode(\array_keys($rows[0])));
        $this->flushChecks();
    }

    public function testTriStateIsNotSquashedIntoABool(): void
    {
        $tree = new SemanticsTree();
        foreach ([0, 1, 2] as $state) {
            $n = new SemanticsNode();
            $n->role = 'button';
            $n->selectState = $state;
            $n->expandState = $state === 2 ? 2 : 0;
            $tree->add($n);
        }

        $query = $this->query();
        $states = [];
        foreach ([0, 1, 2] as $i) {
            $states[] = $query->node($tree, $i)['selectState'];
        }
        check('0 / 1 / 2 三种取值都保留', $states === [0, 1, 2], \json_encode($states));
        check('展开态独立成键', ($query->node($tree, 2))['expandState'] === 2, '被压成 bool 了');
        $this->flushChecks();
    }

    public function testHitUsesTheProductionDeepestAndSkipsZeroArea(): void
    {
        $tree = new SemanticsTree();
        $parent = new SemanticsNode();
        $parent->role = 'table';
        $parent->x = 10;
        $parent->y = 10;
        $parent->w = 100;
        $parent->h = 100;
        $parent->depth = 0;
        $tree->add($parent);

        $zero = new SemanticsNode();
        $zero->role = 'button';
        $zero->x = 50;
        $zero->y = 50;
        $zero->w = 0;
        $zero->h = 0;
        $zero->parent = 0;
        $zero->depth = 1;
        $tree->add($zero);

        $child = new SemanticsNode();
        $child->role = 'button';
        $child->name = '内层';
        $child->x = 50;
        $child->y = 50;
        $child->w = 20;
        $child->h = 20;
        $child->parent = 0;
        $child->depth = 1;
        $tree->add($child);

        $query = $this->query();
        $hit = $query->hit($tree, 60.0, 60.0);
        check('重叠取最深', ($hit['index'] ?? -1) === 2 && ($hit['name'] ?? '') === '内层', \json_encode($hit));
        $miss = $query->hit($tree, 500.0, 500.0);
        check('点空了不是命中根节点', ($miss['found'] ?? true) === false && ($miss['reason'] ?? '') === 'no-semantic-hit', \json_encode($miss));
        $this->flushChecks();
    }

    public function testOfflineIsReportedNotReturnedAsAnEmptyTree(): void
    {
        $router = QueryRouter::offline(Recorder::install(RecordOptions::default()));
        foreach ([
            'semantics' => ['limit' => 10],
            'semanticsNode' => ['index' => 0],
            'semanticsHit' => ['x' => 1.0, 'y' => 1.0],
        ] as $name => $params) {
            $answer = $router->query($name, $params);
            check("{$name} 离线明确报不可用", ($answer['reason'] ?? '') === 'semantics-unavailable', \json_encode($answer));
            check("{$name} 不装成空树", ($answer['found'] ?? true) === false, \json_encode($answer));
        }
        $this->flushChecks();
    }

    public function testSemanticsIsNotAnEvidenceDomain(): void
    {
        $props = [];
        foreach ((new \ReflectionClass(EvidenceBag::class))->getProperties() as $property) {
            $props[] = $property->getName();
        }
        check('证据包没有语义段', !\in_array('semantics', $props, true), \implode(',', $props));
        check('域表没有语义', !\in_array('semantics', Evidence::ALL_DOMAINS, true), \implode(',', Evidence::ALL_DOMAINS));
        check('默认域也没有语义', !\in_array('semantics', Evidence::DEFAULT_DOMAINS, true), \implode(',', Evidence::DEFAULT_DOMAINS));
        $this->flushChecks();
    }

    private function query(): SemanticsQuery
    {
        // 直调域对象，绕过注入：这里钉的是查询形状，不是会话装配（后者由 live 判据钉）。
        return new SemanticsQuery(new History(Recorder::install(RecordOptions::default())));
    }

    /** @return array{DebugSession, Application} */
    private function live(): array
    {
        $app = new SemanticsApplication();
        $app->attach(new SurfaceProbe(), $this->constraints());
        $session = DebugSession::open($app, $this->constraints());

        return [$session, $app];
    }

    private function constraints(): BoxConstraints
    {
        return BoxConstraints::tight(new Size(320.0, 240.0));
    }
}

/** @internal 判据夹具：两个声明了语义的按钮 */
final class SemanticsApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Column::make()->children([
            Button::make('甲'),
            Button::make('乙'),
        ]);
    }
}
