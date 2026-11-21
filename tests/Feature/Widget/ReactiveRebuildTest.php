<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\State\Cell;
use Drift\Widget\Bind;
use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\State;
use Drift\Widget\StatefulElement;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/** 页面级：build 里直接读格子（粒度 = 整页） */
final class ReadPageW extends StatefulWidget
{
    public function createState(): State
    {
        return new ReadPageS();
    }
}

final class ReadPageS extends State
{
    public static int $builds = 0;

    public Cell $count;

    public function initState(): void
    {
        $this->count = Cell::of(0)->named('count');
    }

    public function build(BuildContext $ctx): Widget
    {
        self::$builds++;

        return Column::make()->children([Text::make('n=' . $this->count->get())]);
    }
}

/** 细粒度：只有 `Bind` 里读格子（粒度 = 那一段） */
final class BindPageW extends StatefulWidget
{
    public function createState(): State
    {
        return new BindPageS();
    }
}

final class BindPageS extends State
{
    public static int $pageBuilds = 0;

    public Cell $count;

    public int $bindBuilds = 0;

    public function initState(): void
    {
        $this->count = Cell::of(0)->named('count');
    }

    public function build(BuildContext $ctx): Widget
    {
        self::$pageBuilds++;

        return Column::make()->children([
            Text::make('静态标题'),
            Bind::make(function (): Widget {
                $this->bindBuilds++;

                return Text::make('b=' . $this->count->get());
            }),
        ]);
    }
}

/** 分支依赖：读哪一格由 `flag` 决定（验依赖集合会跟着换） */
final class BranchPageW extends StatefulWidget
{
    public function createState(): State
    {
        return new BranchPageS();
    }
}

final class BranchPageS extends State
{
    public Cell $flag;
    public Cell $left;
    public Cell $right;
    public int $bindBuilds = 0;

    public function initState(): void
    {
        $this->flag = Cell::of(true);
        $this->left = Cell::of(1);
        $this->right = Cell::of(100);
    }

    public function build(BuildContext $ctx): Widget
    {
        return Bind::make(function (): Widget {
            $this->bindBuilds++;

            return Text::make($this->flag->get() ? 'L' . $this->left->get() : 'R' . $this->right->get());
        });
    }
}

/** 跨 State 写：子的 build 写父读过的格子 —— 框架原有契约，不能被 Cell 误杀 */
final class CrossProbe
{
    public Cell $cell;
    public bool $writeOnce = false;
    public int $writes = 0;
    public int $parentBuilds = 0;
    public int $childBuilds = 0;

    public function __construct()
    {
        $this->cell = Cell::of(0);
    }
}

final class CrossParentW extends StatefulWidget
{
    public function __construct(public readonly CrossProbe $probe)
    {
        parent::__construct();
    }

    public function createState(): State
    {
        return new CrossParentS();
    }
}

final class CrossParentS extends State
{
    public function build(BuildContext $ctx): Widget
    {
        $parent = $this->widget();
        $probe = $parent instanceof CrossParentW ? $parent->probe : new CrossProbe();
        $probe->parentBuilds++;

        return Column::make()->children([
            Text::make('v=' . $probe->cell->get()),
            new CrossChildW($probe),
        ]);
    }
}

final class CrossChildW extends StatefulWidget
{
    public function __construct(public readonly CrossProbe $probe)
    {
        parent::__construct();
    }

    public function createState(): State
    {
        return new CrossChildS();
    }
}

final class CrossChildS extends State
{
    public function build(BuildContext $ctx): Widget
    {
        $child = $this->widget();
        $probe = $child instanceof CrossChildW ? $child->probe : new CrossProbe();
        $probe->childBuilds++;
        if ($probe->writeOnce && $probe->writes === 0) {
            $probe->writes++;
            $probe->cell->set(1);
        }

        return Text::make('子');
    }
}

/**
 * 自动依赖的重建面（2026-11-21）。
 *
 * ★ 判据是"**谁**重跑了多少次"，不是画面：画面相同也可能是整页重建。
 * ★ 每条都带前置断言（挂载后计数先归零），否则"从不重建"的实现也会假绿。
 */
final class ReactiveRebuildTest extends SmokeTestCase
{
    private static function mount(StatefulWidget $widget): StatefulElement
    {
        $element = $widget->createElement();
        $element->mount(null, smokeContext());
        $element->settle();

        return $element;
    }

    public function testPageLevelReadRebuildsThePage(): void
    {
        ReadPageS::$builds = 0;

        $element = self::mount(new ReadPageW());
        $state = $element->state();
        \assert($state instanceof ReadPageS);

        check('依赖重建 · 前置：挂载时页面 build 过一次', ReadPageS::$builds === 1, 'builds=' . ReadPageS::$builds);
        check('依赖重建 · 页面订到 1 个源', $state->reactiveDependencyCount() === 1);

        $state->count->set(1);
        $element->settle();
        check(
            '依赖重建 · 页面读了格子 ⇒ 值变后整页重建一次',
            ReadPageS::$builds === 2,
            'builds=' . ReadPageS::$builds
        );

        $element->settle();
        check(
            '依赖重建 · 没有新变化 ⇒ 不重复重建（收敛）',
            ReadPageS::$builds === 2,
            'builds=' . ReadPageS::$builds
        );

        $this->flushChecks();
    }

    public function testBindNarrowsRebuildToItsSubtree(): void
    {
        BindPageS::$pageBuilds = 0;

        $element = self::mount(new BindPageW());
        $state = $element->state();
        \assert($state instanceof BindPageS);
        $state->bindBuilds = 0;

        check(
            'Bind · 前置：挂载时页面也 build 过一次',
            BindPageS::$pageBuilds === 1,
            'page=' . BindPageS::$pageBuilds
        );

        $state->count->set(1);
        $element->settle();
        check(
            'Bind · 读点在 Bind 里 ⇒ 页面不重建',
            BindPageS::$pageBuilds === 1,
            'page=' . BindPageS::$pageBuilds
        );
        check('Bind · 只有 Bind 那一段重跑', $state->bindBuilds === 1, 'bind=' . $state->bindBuilds);

        $this->flushChecks();
    }

    public function testBranchSwitchRebindsDependencySet(): void
    {
        $element = self::mount(new BranchPageW());
        $state = $element->state();
        \assert($state instanceof BranchPageS);
        $state->bindBuilds = 0;

        // 前置：当前读 flag + left
        check(
            '依赖重建 · 分支页订到 2 个源（flag + left）',
            self::bindStateOf($element)?->reactiveDependencyCount() === 2,
            'n=' . (self::bindStateOf($element)?->reactiveDependencyCount() ?? -1)
        );

        $state->right->set(200);
        $element->settle();
        check('依赖重建 · 未选中分支变了 ⇒ Bind 不重跑', $state->bindBuilds === 0, 'bind=' . $state->bindBuilds);

        $state->flag->set(false);
        $element->settle();
        check('依赖重建 · 换分支 ⇒ Bind 重跑一次', $state->bindBuilds === 1, 'bind=' . $state->bindBuilds);

        $state->left->set(5);
        $element->settle();
        check(
            '依赖重建 · 换分支后旧依赖退订（改了也不重跑）',
            $state->bindBuilds === 1,
            'bind=' . $state->bindBuilds
        );

        $this->flushChecks();
    }

    public function testUnmountCancelsSubscriptions(): void
    {
        $element = self::mount(new BindPageW());
        $state = $element->state();
        \assert($state instanceof BindPageS);
        $bindState = self::bindStateOf($element);
        \assert($bindState instanceof State);

        check('依赖重建 · 前置：Bind 有订阅', $bindState->reactiveDependencyCount() === 1);

        $element->unmount();
        check(
            '依赖重建 · 卸载即退订（不留幽灵重建）',
            $bindState->reactiveDependencyCount() === 0,
            'n=' . $bindState->reactiveDependencyCount()
        );

        $state->count->set(9);   // 卸载后写：不该有任何异常
        check('依赖重建 · 卸载后写格子不报错', true);

        $this->flushChecks();
    }

    public function testCrossStateCellWriteDuringBuildConverges(): void
    {
        $probe = new CrossProbe();
        $element = self::mount(new CrossParentW($probe));

        check(
            '跨 State 写 · 前置：父读、子已建',
            $probe->parentBuilds === 1 && $probe->childBuilds === 1 && $probe->writes === 0,
            'p=' . $probe->parentBuilds . ' c=' . $probe->childBuilds,
        );

        $probe->writeOnce = true;
        $child = null;
        $walk = function (Element $e) use (&$walk, &$child): void {
            if ($e->widget() instanceof CrossChildW) {
                $child = $e;
            }
            for ($i = 0; $i < $e->childCount(); $i++) {
                $next = $e->childAt($i);
                if ($next !== null) {
                    $walk($next);
                }
            }
        };
        $walk($element);
        \assert($child instanceof StatefulElement);
        $child->state()?->markDirty();
        $element->settle();

        check(
            '跨 State 写 · 子 build 写父的格子 ⇒ 父跟着重建一次且收敛',
            $probe->writes === 1 && $probe->parentBuilds >= 2,
            'writes=' . $probe->writes . ' p=' . $probe->parentBuilds,
        );

        $before = $probe->parentBuilds;
        $element->settle();
        check(
            '跨 State 写 · 没有新写入 ⇒ 不再重建',
            $probe->parentBuilds === $before,
            'p=' . $probe->parentBuilds,
        );

        $this->flushChecks();
    }

    /** 找到页面里那个 `Bind` 的 State（结构断言用） */
    private static function bindStateOf(Element $root): ?State
    {
        $found = null;
        $walk = function (Element $element) use (&$walk, &$found): void {
            if ($element instanceof StatefulElement && $element->widget() instanceof Bind) {
                $found = $element->state();
            }
            for ($i = 0; $i < $element->childCount(); $i++) {
                $child = $element->childAt($i);
                if ($child !== null) {
                    $walk($child);
                }
            }
        };
        $walk($root);

        return $found;
    }
}
