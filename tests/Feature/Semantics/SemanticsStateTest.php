<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Semantics;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\SemanticsNode;
use Drift\Input\SemanticsTree;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\SemanticsBuilder;
use Drift\Widget\SizedBox;
use Drift\Widget\TreeNode;
use Drift\Widget\TreeView;
use Drift\Widget\Widget;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 语义**状态**（§65）—— 选中 / 展开 / 层级 / 可聚焦。
 *
 * 与 `SemanticsTest` 的分工：那个验"节点存不存在、叫什么、在哪"，
 * 这个验"节点现在是什么状态"。分开是因为状态族有一套自己的坑：
 * **三态**（无此概念 / 否 / 是）一旦塌成 bool，每个节点都会对客户端
 * 声称自己可选、可展开。
 */
final class SemanticsStateTest extends SmokeTestCase
{
    /**
     * 挂载 + 布局 + 再 settle。
     *
     * ★ 第二次 `settle()` 不是保险：虚拟列表的行是**按视口**在布局期建的，
     *   只 settle 一次的话语义树里只有容器自己、没有行 —— 判据会静默变成
     *   "node missing"，看着像功能没做。
     */
    private function lay(Widget $root): Element
    {
        $el = $root->createElement();
        $el->mount(null, smokeContext());
        $el->settle();
        $el->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 240.0)));
        $el->settle();

        return $el;
    }

    /**
     * 按名字找节点（语义树是扁平前序，按 role 再按 name 筛）。
     */
    private function pick(SemanticsTree $tree, string $name): ?SemanticsNode
    {
        for ($i = 0; $i < $tree->count(); $i++) {
            $n = $tree->node($i);
            if ($n->name === $name) {
                return $n;
            }
        }

        return null;
    }

    // ① 三态编码：null ⇒ 0（无此概念），false ⇒ 1，true ⇒ 2
    public function testTriStateEncoding(): void
    {
        $plain = Button::make('普通');
        $off = Button::make('关')->semanticsState(selected: false, expanded: false);
        $on = Button::make('开')->semanticsState(selected: true, expanded: true);
        // ★ 调了 `semanticsState()` **但只给 level** —— 这才是三态的真用例
        //   （`$plain` 根本没调，验不到 null 分支）。
        $mix = Button::make('混')->semanticsState(level: 2);

        $tree = SemanticsBuilder::build($this->lay(Column::make()->children([$plain, $off, $on, $mix])));
        $a = $this->pick($tree, '普通');
        $b = $this->pick($tree, '关');
        $c = $this->pick($tree, '开');
        $m = $this->pick($tree, '混');

        check(
            '语义状态 · 不声明 ⇒ 0/0（无此概念，客户端据此不给 Pattern）',
            $a !== null && $a->selectState === 0 && $a->expandState === 0,
            $a === null ? 'node missing' : "sel={$a->selectState} exp={$a->expandState}",
        );
        check(
            '语义状态 · false ⇒ 1/1（有概念，现在是关的）',
            $b !== null && $b->selectState === 1 && $b->expandState === 1,
            $b === null ? 'node missing' : "sel={$b->selectState} exp={$b->expandState}",
        );
        check(
            '语义状态 · true ⇒ 2/2',
            $c !== null && $c->selectState === 2 && $c->expandState === 2,
            $c === null ? 'node missing' : "sel={$c->selectState} exp={$c->expandState}",
        );
        check(
            '语义状态 · 只给 level 的调用不动另两项（null = 不动，不是 false）',
            $m !== null && $m->selectState === 0 && $m->expandState === 0 && $m->level === 2,
            $m === null ? 'node missing'
                : "sel={$m->selectState} exp={$m->expandState} lvl={$m->level}",
        );

        $this->flushChecks();
    }

    // ② focusable 与 focused 正交：框架事实从外面喂，不由控件声明
    public function testFocusableIsFrameworkFact(): void
    {
        $el = $this->lay(Column::make()->children([
            Button::make('甲'),
            Button::make('乙'),
        ]));

        $kid = $el->findElement(
            static fn (Element $e): bool => $e->widget() instanceof Button && $e->widget()->label() === '甲'
        );
        check('语义状态 · 前置：拿得到子元素', $kid !== null, $kid === null ? 'null' : 'ok');
        if ($kid === null) {
            $this->flushChecks();

            return;
        }

        // 没喂可聚焦表 ⇒ 全报 false（纯单测的降级，而不是"默认可信"）
        $none = SemanticsBuilder::build($el, $kid);
        $a = $this->pick($none, '甲');
        $b = $this->pick($none, '乙');
        check(
            '语义状态 · 没给可聚焦表 ⇒ focusable 全 false，但 focused 照常（两者正交）',
            $a !== null && $a->focusable === false && $a->focused === true
                && $b !== null && $b->focusable === false,
            $a === null || $b === null ? 'node missing'
                : "a.focusable=" . var_export($a->focusable, true) . " a.focused=" . var_export($a->focused, true),
        );

        // 喂了 ⇒ 命中者 true
        $table = [spl_object_id($kid) => 1];
        $some = SemanticsBuilder::build($el, null, $table);
        $a2 = $this->pick($some, '甲');
        $b2 = $this->pick($some, '乙');
        check(
            '语义状态 · 给了可聚焦表 ⇒ 只有表里的节点报可聚焦',
            $a2 !== null && $a2->focusable === true && $b2 !== null && $b2->focusable === false,
            $a2 === null || $b2 === null ? 'node missing'
                : "a=" . var_export($a2->focusable, true) . " b=" . var_export($b2->focusable, true),
        );

        $this->flushChecks();
    }

    // ③ 禁用的控件报不可聚焦（否则读屏让用户 Tab 到一个死控件上）
    public function testDisabledIsNotFocusable(): void
    {
        $el = $this->lay(Column::make()->children([
            Button::make('活'),
            Button::make('死')->disabled(true),
        ]));

        $live = $el->findElement(
            static fn (Element $e): bool => $e->widget() instanceof Button && $e->widget()->label() === '活'
        );
        $dead = $el->findElement(
            static fn (Element $e): bool => $e->widget() instanceof Button && $e->widget()->label() === '死'
        );
        if ($live === null || $dead === null) {
            check('语义状态 · 前置：拿得到两个子元素', false, 'null');

            return;
        }

        $table = [spl_object_id($live) => 1, spl_object_id($dead) => 1];
        $tree = SemanticsBuilder::build($el, null, $table);
        $a = $this->pick($tree, '活');
        $b = $this->pick($tree, '死');
        check(
            '语义状态 · disabled ⇒ enabled=false 且 focusable=false（禁用与不可聚焦同源）',
            $a !== null && $a->focusable === true
                && $b !== null && $b->enabled === false && $b->focusable === false,
            $b === null ? 'node missing'
                : "dead.enabled=" . var_export($b->enabled, true) . " dead.focusable=" . var_export($b->focusable, true),
        );

        $this->flushChecks();
    }

    // ④ 树的行：角色是 treeitem（不是 button）、层级 1-based、展开/选中分得开
    public function testTreeRowsCarryStateAndLevel(): void
    {
        $view = TreeView::make([
            TreeNode::branch('project', 'drift', [
                TreeNode::branch('src', 'src', [
                    TreeNode::make('button', 'Button'),
                ]),
            ]),
        ])->expanded(['project', 'src'])->selected('src');

        $tree = SemanticsBuilder::build($this->lay(SizedBox::make()
            ->width(320.0)
            ->height(240.0)
            ->child($view)));

        $root = $this->pick($tree, 'drift');
        $src = $this->pick($tree, 'src');
        $leaf = $this->pick($tree, 'Button');

        check(
            '语义状态 · 树行角色是 treeitem（不是 button —— 否则读屏把树念成按钮列表）',
            $root !== null && $root->role === 'treeitem',
            $root === null ? 'node missing' : "role={$root->role}",
        );
        check(
            '语义状态 · 树行层级 1-based（根层 = 1，逐层 +1）',
            $root !== null && $root->level === 1
                && $src !== null && $src->level === 2
                && $leaf !== null && $leaf->level === 3,
            ($root === null || $src === null || $leaf === null) ? 'node missing'
                : "root={$root->level} src={$src->level} leaf={$leaf->level}",
        );
        check(
            '语义状态 · 分支行分得开"展开"与"折叠"（drift 展开 = 2）',
            $root !== null && $root->expandState === 2,
            $root === null ? 'node missing' : "exp={$root->expandState}",
        );
        check(
            '语义状态 · 叶子不给展开概念（0，不是 1）—— 客户端据此知道"不可展开"',
            $leaf !== null && $leaf->expandState === 0,
            $leaf === null ? 'node missing' : "exp={$leaf->expandState}",
        );
        check(
            '语义状态 · 选中只落在选中那行（src=2 / drift=1 / Button=1）',
            $src !== null && $src->selectState === 2
                && $root !== null && $root->selectState === 1
                && $leaf !== null && $leaf->selectState === 1,
            ($src === null || $root === null || $leaf === null) ? 'node missing'
                : "src={$src->selectState} root={$root->selectState} leaf={$leaf->selectState}",
        );

        $this->flushChecks();
    }

    /**
     * ⑥ 端到端：应用层真的把 `FocusManager` 的可聚焦表喂进语义树。
     *
     * ★ 这条不重复 ②：② 验的是"给了表就填对"，这里验的是**有没有人给**。
     *   `AppShell::pumpSemantics()` 里漏掉那一句的话，上面全绿、真机全 false ——
     *   "改了一处漏了另一处"的典型形状。
     */
    public function testApplicationFeedsFocusableTable(): void
    {
        $app = new class extends \Drift\App\Application {
            private Widget $testRoot;

            public function setRoot(Widget $widget): void
            {
                $this->testRoot = $widget;
            }

            public function buildRoot(): Widget
            {
                return $this->testRoot;
            }
        };
        $app->setRoot(Column::make()->children([
            Button::make('甲'),
            Button::make('乙'),
        ]));
        $tight = BoxConstraints::tight(new Size(320.0, 240.0));
        $probe = new \Drift\Dev\Smoke\Frame\SurfaceProbe();
        $probe->resize(320, 240, 1.0);
        $app->attach($probe, $tight);

        $seen = [];
        $app->setSemanticsSink(static function (array $nodes) use (&$seen): void {
            $seen = $nodes;
        });
        $app->frame(0.0, $tight);

        $byName = [];
        foreach ($seen as $n) {
            $byName[(string) ($n['name'] ?? '')] = $n;
        }
        check(
            '语义状态 · 应用层把可聚焦表喂进语义树（单测全绿也可能漏这一句）',
            isset($byName['甲']) && ($byName['甲']['focusable'] ?? false) === true
                && ($byName['乙']['focusable'] ?? false) === true,
            isset($byName['甲']) ? 'nodes=' . \count($seen) . ' a=' . json_encode($byName['甲']) : 'push 没发生',
        );

        $this->flushChecks();
    }

    // ⑦ 展开/折叠（`IExpandCollapseProvider` 的落地）：按 Key 交给控件，叶子拒
    public function testTreeSemanticsExpand(): void
    {
        $calls = [];
        $view = TreeView::make([
            TreeNode::branch('project', 'drift', [TreeNode::make('button', 'Button')]),
        ])->expanded([])->onToggle(static function (string $id, bool $open) use (&$calls): void {
            $calls[] = [$id, $open];
        });

        check(
            '语义状态 · 分支行 ⇒ 调 onToggle(id, true)（读屏的 Expand 与点箭头同一条回调）',
            $view->setSemanticsExpanded('tree-row:project', true) === true
                && $calls === [['project', true]],
            json_encode($calls),
        );

        $calls = [];
        check(
            '语义状态 · 叶子 ⇒ false 且一次都不调（不能"装作展开了"）',
            $view->setSemanticsExpanded('tree-row:button', true) === false && $calls === [],
            json_encode($calls),
        );
        check(
            '语义状态 · 不认识的 Key ⇒ false',
            $view->setSemanticsExpanded('menu:1', true) === false,
            'ok',
        );

        $bare = TreeView::make([TreeNode::branch('project', 'drift', [TreeNode::make('a', 'A')])]);
        check(
            '语义状态 · 没声明 onToggle ⇒ false（受控控件没有第二条路可走）',
            $bare->setSemanticsExpanded('tree-row:project', true) === false,
            'ok',
        );

        $this->flushChecks();
    }

    // ⑧ 应用层寻址：语义节点索引 → 命中 → 父链 → `SemanticsExpandable`
    public function testApplicationDispatchesExpand(): void
    {
        $calls = [];
        $app = new class extends \Drift\App\Application {
            private Widget $testRoot;

            public function setRoot(Widget $widget): void
            {
                $this->testRoot = $widget;
            }

            public function buildRoot(): Widget
            {
                return $this->testRoot;
            }
        };
        $app->setRoot(SizedBox::make()->width(320.0)->height(240.0)->child(
            TreeView::make([
                TreeNode::branch('project', 'drift', [
                    TreeNode::branch('docs', 'docs', [TreeNode::make('readme', 'README.md')]),
                ]),
            ])->expanded(['project'])->onToggle(static function (string $id, bool $open) use (&$calls): void {
                $calls[] = [$id, $open];
            })
        ));
        $tight = BoxConstraints::tight(new Size(320.0, 240.0));
        $probe = new \Drift\Dev\Smoke\Frame\SurfaceProbe();
        $probe->resize(320, 240, 1.0);
        $app->attach($probe, $tight);

        $seen = [];
        $app->setSemanticsSink(static function (array $nodes) use (&$seen): void {
            $seen = $nodes;
        });
        $app->frame(0.0, $tight);

        $idx = -1;
        foreach ($seen as $i => $n) {
            if (($n['name'] ?? '') === 'docs') {
                $idx = $i;
            }
        }
        check('语义状态 · 前置：语义树里有 docs 这一行', $idx >= 0, 'idx=' . $idx);
        if ($idx < 0) {
            $this->flushChecks();

            return;
        }

        check(
            '语义状态 · setSemanticsExpanded(索引) ⇒ 一路落到 TreeView::onToggle',
            $app->setSemanticsExpanded($idx, true) === true && $calls === [['docs', true]],
            json_encode($calls),
        );

        // 叶子行同一入口 ⇒ false（拒绝，而不是静默什么都不做）
        $leafIdx = -1;
        foreach ($seen as $i => $n) {
            if (($n['name'] ?? '') === 'README.md') {
                $leafIdx = $i;
            }
        }
        check(
            '语义状态 · 叶子行（有节点但 expandState=0）⇒ false',
            $leafIdx < 0 || $app->setSemanticsExpanded($leafIdx, true) === false,
            'leafIdx=' . $leafIdx,
        );

        $this->flushChecks();
    }

    // ⑤ 序列化：四个新键都在（跨 AOT 边界只带标量）
    public function testSerializesState(): void
    {
        $tree = SemanticsBuilder::build($this->lay(
            Button::make('X')->semanticsState(selected: true, expanded: false, level: 2)
        ), null, []);

        $arr = $tree->toArrays();
        $n = $arr['nodes'][0] ?? null;
        check(
            '语义状态 · toArrays 带 focusable/selectState/expandState/level 四键',
            $n !== null
                && \array_key_exists('focusable', $n)
                && ($n['selectState'] ?? null) === 2
                && ($n['expandState'] ?? null) === 1
                && ($n['level'] ?? null) === 2,
            $n === null ? 'no node' : json_encode($n),
        );

        $this->flushChecks();
    }
}
