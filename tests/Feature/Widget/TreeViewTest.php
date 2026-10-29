<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\App\Frame;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\LazyFixtures;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\SemanticsRole;
use Drift\Layout\RenderButton;
use Drift\Widget\ActivationContext;
use Drift\Widget\Button;
use Drift\Widget\Element;
use Drift\Widget\IconButton;
use Drift\Widget\Row;
use Drift\Widget\TreeNode;
use Drift\Widget\TreeRow;
use Drift\Widget\TreeView;

/**
 * `TreeView`：层级数据树（§12.1 第 4/7 条，A3 第二件）。
 *
 * ## 判据钉什么
 *
 * ① **展平语义**（哪一行在、在哪一层、什么顺序）—— 纯结构，用 `visibleRows()`；
 * ② **虚拟化**（上千节点只造视口那几行）—— 数 Element；
 * ③ **受控**（点箭头 / 点标签只上报、画面不动）—— 与 `TabView` 同款正向判据；
 * ④ **对齐**（叶子与有子节点的标签起点相同）—— 守 `ARROW_SLOT` 与主题的一致性；
 * ⑤ **响亮失败**（空 id / 重复 id 走子进程）。
 *
 * ## 一条最容易漏的判据
 *
 * "展平成几行"对了不等于"**是哪几行**"。TabView 那次（2026-10-29）就是只钉了
 * 行数、没钉内容，M5（永远渲染第 0 页）全绿。这里每个展平断言都同时钉
 * **id 顺序**，不只钉条数。
 */
final class TreeViewTest extends SmokeTestCase
{
    // ── ① 展平语义 ──────────────────────────────────────────────────────

    public function testCollapsedByDefaultShowsRootLevelOnly(): void
    {
        $rows = $this->view([])->visibleRows();

        check('TreeView · 默认全折叠 ⇒ 只有根层 3 行', \count($rows) === 3, 'n=' . \count($rows));
        check(
            'TreeView · 根层的 id 顺序就是 `make()` 给的顺序（a, b, c）',
            $this->ids($rows) === ['a', 'b', 'c'],
            'ids=' . \implode(',', $this->ids($rows))
        );
        check('TreeView · 根层 depth 全是 0', $rows[0]->depth === 0 && $rows[2]->depth === 0);
        check(
            'TreeView · `a` 有子但没展开（`hasChildren` 与 `expanded` 是两件事）',
            $rows[0]->hasChildren && !$rows[0]->expanded
        );
        check('TreeView · `b` 是叶子', !$rows[1]->hasChildren);
    }

    public function testExpandedSplicesChildrenInPreOrder(): void
    {
        $rows = $this->view(['a'])->visibleRows();

        check(
            'TreeView · 展开 a ⇒ 前序插入它的两个子（a, a1, a2, b, c）',
            $this->ids($rows) === ['a', 'a1', 'a2', 'b', 'c'],
            'ids=' . \implode(',', $this->ids($rows))
        );
        check(
            'TreeView · `a` 的子 depth = 1、根层仍是 0（`b` 在子之后）',
            $rows[1]->depth === 1 && $rows[2]->depth === 1 && $rows[3]->depth === 0,
            \sprintf('a1=%d a2=%d b=%d', $rows[1]->depth, $rows[2]->depth, $rows[3]->depth)
        );
        check('TreeView · 展开标记落在 `a` 上', $rows[0]->expanded);
        check('TreeView · `a` 展开但 `a2` 没展开 ⇒ a2x 不在（展开不是递归的）', $this->ids($rows) === ['a', 'a1', 'a2', 'b', 'c']);
    }

    public function testNestedExpansionAddsDepth(): void
    {
        $rows = $this->view(['a', 'a2'])->visibleRows();

        check(
            'TreeView · 再展开 a2 ⇒ 三层（a, a1, a2, a2x, b, c）',
            $this->ids($rows) === ['a', 'a1', 'a2', 'a2x', 'b', 'c'],
            'ids=' . \implode(',', $this->ids($rows))
        );
        check('TreeView · 第三层 depth = 2', $rows[3]->depth === 2, 'depth=' . $rows[3]->depth);
    }

    public function testExpandedIdOfLeafHasNoEffect(): void
    {
        $rows = $this->view(['b'])->visibleRows();

        check(
            'TreeView · 展开集合里有叶子 id ⇒ 无副作用（叶子没有可展开的东西）',
            $this->ids($rows) === ['a', 'b', 'c'],
            'ids=' . \implode(',', $this->ids($rows))
        );
        check('TreeView · 叶子不因"被点名展开"而变成 expanded', !$rows[1]->expanded);
    }

    public function testUnknownExpandedIdIsIgnored(): void
    {
        $rows = $this->view(['nope'])->visibleRows();

        check(
            'TreeView · 展开集合里有不存在的 id ⇒ 忽略（不炸、不多行）',
            $this->ids($rows) === ['a', 'b', 'c'],
            'ids=' . \implode(',', $this->ids($rows))
        );
    }

    // ── ② 端到端：树里真的有这些行，且几何正确 ────────────────────────

    public function testRowsAreActuallyBuiltWithRowHeight(): void
    {
        $root = $this->mount($this->view(['a']));
        $this->frame($root);

        check(
            'TreeView · 展开 a ⇒ 树里建出 5 个行（`Row`）',
            $this->countRows($root) === 5,
            'n=' . $this->countRows($root)
        );

        $boxes = $this->rowBoxes($root);
        $heights = [];
        foreach ($boxes as $box) {
            $size = $box->size();
            $heights[] = $size === null ? -1.0 : $size->height;
        }
        check(
            'TreeView · 每行盒高都等于 rowHeight（42 —— 由 LazyColumn 的 itemExtent 强制）',
            $heights !== [] && \max($heights) - \min($heights) < 0.01 && \abs($heights[0] - TreeView::DEFAULT_ROW_HEIGHT) < 0.01,
            'heights=' . \implode(',', $heights)
        );
    }

    public function testIndentIsDepthTimesIndent(): void
    {
        $view = $this->view(['a', 'a2'])->indent(20.0);
        $root = $this->mount($view);
        $this->frame($root);

        // 行序：a(0) a1(1) a2(1) a2x(2) b(0) c(0)
        $indents = $this->indentWidths($root);
        check(
            'TreeView · 深度 0 的行**不建**缩进盒（首个子直接是箭头 / 叶子占位，宽 = ARROW_SLOT）',
            \count($indents) === 6
                && \abs($indents[0] - TreeView::ARROW_SLOT) < 0.01
                && \abs($indents[4] - TreeView::ARROW_SLOT) < 0.01,
            'indents=' . \implode(',', $indents)
        );
        check(
            'TreeView · 深度 1 / 2 的行缩进 = depth × 20（20 / 40）',
            \abs($indents[1] - 20.0) < 0.01 && \abs($indents[3] - 40.0) < 0.01,
            \sprintf('a1=%.1f a2x=%.1f', $indents[1], $indents[3])
        );

        // ★ 端到端：缩进要**真的推开标签**，不只是建了个空盒（建了但没生效是另一种错）
        $starts = $this->labelStarts($root);
        check(
            'TreeView · 标签 x 随深度递增（每层 +20）',
            \count($starts) === 6
                && \abs(($starts[1] - $starts[0]) - 20.0) < 0.51
                && \abs(($starts[3] - $starts[1]) - 20.0) < 0.51,
            'x=' . \implode(',', $starts)
        );
    }

    /**
     * ★ 叶子行与有子行的标签**起点相同** —— 这条守的是 `ARROW_SLOT`。
     *
     * 主题的字号 / 间距一变，`IconButton` 的天然宽就不再等于 `ARROW_SLOT`，
     * 叶子的标签会整体左移一截。画面上看着像"这棵树有点歪"，不像 bug。
     */
    public function testLeafLabelAlignsWithBranchLabel(): void
    {
        $root = $this->mount($this->view([]));
        $this->frame($root);

        // 第 0 行是 a（有子，带箭头）、第 1 行是 b（叶子，带空位）
        $starts = $this->labelStarts($root);
        check(
            'TreeView · 叶子行的标签起点 == 有子行的标签起点（`ARROW_SLOT` 与按钮天然宽一致）',
            \count($starts) === 3 && \abs($starts[0] - $starts[1]) < 0.51,
            'x: a=' . ($starts[0] ?? -1) . ' b=' . ($starts[1] ?? -1) . ' c=' . ($starts[2] ?? -1)
        );
    }

    public function testLabelsAreLeftAlignedNotCentered(): void
    {
        $root = $this->mount($this->view([]));
        $this->frame($root);

        // ★ 标签按钮被 `Expanded` 撑满整行剩余宽度（点行的空白也算点这一行），
        //   内容一旦居中，整棵树的标题就全飘到窗口中间 —— 缩进层级只能靠箭头位置
        //   读出来，看着不像树而像一段居中文字。这条是 AOT 真机取证抓出来的：
        //   上面那条"起点相等"测的是 Button 盒的起点，盒看着对、字却在中间。
        $centering = $this->labelCentering($root);
        check(
            'TreeView · 行标签内容左对齐（不是居中）',
            $centering === [false, false, false],
            'centers: ' . \json_encode($centering)
        );
    }

    // ── ③ 受控：只上报，不自持 ────────────────────────────────────────

    public function testArrowReportsToggleWithoutChangingTree(): void
    {
        $seen = [];
        $view = $this->view([], null, static function (string $id, bool $open) use (&$seen): void {
            $seen[] = [$id, $open];
        });

        $root = $this->mount($view);
        $this->frame($root);

        $arrows = $this->collectIconButtons($root);
        check('TreeView · 只有有子节点的行画箭头（3 行里 1 个）', \count($arrows) === 1, 'n=' . \count($arrows));

        $arrows[0]?->activate(ActivationContext::none());
        check(
            'TreeView · 点箭头 ⇒ onToggle 收到 (a, true)（目标状态，不是"切换了"）',
            $seen === [['a', true]],
            'seen=' . \json_encode($seen)
        );

        // 受控：上报完画面不动 —— 展开要应用改 `expanded()` 才发生
        check(
            'TreeView · 受控 ⇒ 点完箭头画面**没变**（仍 3 行）',
            $this->countRows($root) === 3,
            'rows=' . $this->countRows($root)
        );

        // 应用接手 ⇒ 换了 `expanded()` ⇒ 这才展开
        $root->update($this->view(['a']));
        $this->frame($root);
        check(
            'TreeView · 应用改了 `expanded()` ⇒ 这才展开（5 行）',
            $this->countRows($root) === 5,
            'rows=' . $this->countRows($root)
        );
    }

    public function testExpandedArrowReportsCollapse(): void
    {
        $seen = [];
        $view = $this->view(['a'], null, static function (string $id, bool $open) use (&$seen): void {
            $seen[] = [$id, $open];
        });

        $root = $this->mount($view);
        $this->frame($root);

        $arrows = $this->collectIconButtons($root);
        $arrows[0]?->activate(ActivationContext::none());
        check(
            'TreeView · 展开状态下点箭头 ⇒ onToggle 收到 (a, false)',
            $seen === [['a', false]],
            'seen=' . \json_encode($seen)
        );
    }

    public function testLabelReportsSelectionNotExpansion(): void
    {
        $seen = [];
        $view = $this->view([], static function (string $id) use (&$seen): void {
            $seen[] = $id;
        });

        $root = $this->mount($view);
        $this->frame($root);

        $labels = $this->collectLabelButtons($root);
        check('TreeView · 每行一个标签按钮（3 行 3 个）', \count($labels) === 3, 'n=' . \count($labels));

        $labels[1]?->activate(ActivationContext::none());
        check(
            'TreeView · 点标签 ⇒ onSelected 收到 id（`b`，不是下标、不是 label）',
            $seen === ['b'],
            'seen=' . \json_encode($seen)
        );
    }

    public function testSelectedRowUsesPrimaryVariant(): void
    {
        $root = $this->mount($this->view([], null, null, 'b'));
        $this->frame($root);

        $variants = $this->labelVariants($root);
        check(
            'TreeView · 选中行用 Primary 底、其余 Flat（`b` 是第 2 行）',
            $variants === ['flat', 'primary', 'flat'],
            'variants=' . \implode(',', $variants)
        );
    }

    public function testWithoutHandlersRowsAreInert(): void
    {
        $root = $this->mount($this->view([]));
        $this->frame($root);

        $labels = $this->collectLabelButtons($root);
        check(
            'TreeView · 不给 `onSelected` ⇒ 标签按钮连 Tab 站都不占（`skipFocus`）',
            $labels !== [] && $labels[0]->focusNode()?->skip === true,
            $labels === [] ? 'no labels' : ($labels[0]->focusNode()?->skip === true ? 'skipped' : 'focusable')
        );

        $rings = 0;
        foreach ($labels as $label) {
            if ($label->isFocusable() && $label->focusNode()?->skip !== true) {
                ++$rings;
            }
        }
        check('TreeView · 不给回调 ⇒ 0 个标签按钮进 Tab 序', $rings === 0, 'focusable=' . $rings);

        // 激活也不该抛（没人接手）
        $labels[0]->activate(ActivationContext::none());
        check('TreeView · 不给回调 ⇒ 激活无事发生（不抛）', $this->countRows($root) === 3);

        // 给了回调才占站
        $root2 = $this->mount($this->view([], static function (string $id): void {}));
        $this->frame($root2);
        $labels2 = $this->collectLabelButtons($root2);
        check(
            'TreeView · 给了 `onSelected` ⇒ 标签按钮进 Tab 序（成对判据）',
            $labels2 !== [] && $labels2[0]->isFocusable() && $labels2[0]->focusNode()?->skip !== true
        );
    }

    // ── ④ 虚拟化 ──────────────────────────────────────────────────────

    /**
     * ★ 上千节点只造视口覆盖的行 —— 这是"用 `LazyColumn` 而不是 `Column`"的**全部理由**。
     *
     * 不断言具体数字（视口 240 高 / 42 行高 ⇒ 约 6 行，会随主题字号漂）：
     * 判"远小于总数"这个语义。
     */
    public function testLongTreeIsVirtualized(): void
    {
        $nodes = [];
        for ($i = 0; $i < 400; ++$i) {
            $nodes[] = TreeNode::make('n' . $i, '节点 ' . $i);
        }

        $view = TreeView::make($nodes);
        check('TreeView · 400 个根层节点全在 visibleRows 里', \count($view->visibleRows()) === 400);

        $root = $this->mount($view);
        $this->frame($root);

        $built = $this->countRows($root);
        check(
            'TreeView · 400 行只建出视口覆盖的少数几行（虚拟化生效）',
            $built > 0 && $built <= 10,
            'built=' . $built . '（400 行全量建的话是 400）'
        );
        check(
            'TreeView · Element 总数也远小于 400（不是"建了再藏"）',
            countTree($root) < 100,
            'elements=' . countTree($root)
        );
    }

    // ── ⑤ 锚点：展开后视口不跳 ────────────────────────────────────────

    /**
     * ★ `keyOf(行 id)` 的收益就在这一条：展开一个**在视口上方**的节点，它下面
     * 所有节点的行号都 +2；按行号恢复的话视口会往下跳两行（"展开一下，列表滑走了"）。
     *
     * ★ 不配 `keyOf` 的写法（按序号锚定）在这里会得到 offset 仍是 4200 ⇒ 顶部
     *   变成"节点 98"，而这条断言能把它区分出来。
     */
    public function testExpandingKeepsViewportOnSameNode(): void
    {
        $nodes = [];
        for ($i = 0; $i < 400; ++$i) {
            if ($i === 50) {
                $nodes[] = TreeNode::branch('n50', '节点 50', [
                    TreeNode::make('n50a', '子一'),
                    TreeNode::make('n50b', '子二'),
                ]);

                continue;
            }
            $nodes[] = TreeNode::make('n' . $i, '节点 ' . $i);
        }

        $root = $this->mount(TreeView::make($nodes));
        $state = LazyFixtures::stateOf($root);
        // 42 × 100 ⇒ 视口顶部正好是第 100 行（`节点 100`）
        $state?->scrollBy(4200.0);
        LazyFixtures::layout($root);
        $before = $this->firstRowLabel($root);

        $root->update(TreeView::make($nodes)->expanded(['n50']));
        LazyFixtures::layout($root);

        check(
            'TreeView · 展开视口上方的节点 ⇒ 视口仍停在同一个节点（锚点按节点 id，不按行号）',
            $before === '节点 100' && $this->firstRowLabel($root) === '节点 100',
            '前=' . $before . ' 后=' . $this->firstRowLabel($root)
        );
        check(
            'TreeView · 偏移跟着加了 2 行（4200 → 4284 = 2 × 42）',
            $state?->offset() === 4284.0,
            'offset=' . ($state?->offset() ?? -1.0)
        );
    }

    // ── ⑥ 语义 / 边界 / 响亮失败 ───────────────────────────────────────

    public function testSemanticsRoleAndName(): void
    {
        $view = $this->view([]);
        check('TreeView · 容器角色 = tree（集合族）', $view->semanticsRole() === SemanticsRole::Tree);

        $named = $this->view([])->name('项目文件');
        check('TreeView · `name()` 设进语义名', $named->semanticsName() === '项目文件', 'name=' . $named->semanticsName());
    }

    public function testEmptyTreeDoesNothing(): void
    {
        $view = TreeView::make([]);
        check('TreeView · 空树 ⇒ visibleRows 空（不炸）', $view->visibleRows() === []);

        $root = $this->mount($view);
        $this->frame($root);
        check('TreeView · 空树 ⇒ 建出 0 行', $this->countRows($root) === 0, 'rows=' . $this->countRows($root));
    }

    public function testRowHeightRejectsNonPositive(): void
    {
        $log = \sys_get_temp_dir() . '/drift-treeview-' . \getmypid() . '.log';
        fresh($log);
        [$code, $out] = runFatalCase('treebadrow', $log);
        check('TreeView · rowHeight(0) ⇒ Fatal（它是窗口计算的除数）', $code === 3, '实际 ' . $code . ' / ' . $out);
        check('TreeView · rowHeight 的报错点名 itemExtent 与除数', \str_contains(logText($log), 'itemExtent'));
    }

    /**
     * ★ 行高**小于箭头天然高** ⇒ Fatal（不是"有点挤"）。
     *
     * 这条是一次变异逼出来的：把 `LazyColumn::make($this->rowHeight)` 硬编码成 28 时，
     * 测试**不是变红而是把进程打崩了**（42 高的行内容塞进 28 的盒子）。崩 = 应用只看到
     * 进程没了、没有修法；而"必然被裁"是能在设值时就说清的事。
     */
    public function testRowHeightShorterThanArrowFailsLoudly(): void
    {
        $log = \sys_get_temp_dir() . '/drift-treeview-' . \getmypid() . '.log';
        fresh($log);
        [$code, $out] = runFatalCase('treeshortrow', $log);
        check('TreeView · rowHeight(28) ⇒ Fatal（装不下 42 高的箭头）', $code === 3, '实际 ' . $code . ' / ' . $out);
        check(
            'TreeView · 报错给出"至少多少"与"要更密先解决按钮紧凑档"',
            \str_contains(logText($log), '42') && \str_contains(logText($log), '裁'),
            'log=' . \substr(logText($log), 0, 160)
        );
    }

    public function testEmptyNodeIdFailsLoudly(): void
    {
        $log = \sys_get_temp_dir() . '/drift-treeview-' . \getmypid() . '.log';
        fresh($log);
        [$code, $out] = runFatalCase('treeemptyid', $log);
        check('TreeView · 空 id ⇒ Fatal（否则这一行点了永远没反应）', $code === 3, '实际 ' . $code . ' / ' . $out);
        check('TreeView · 空 id 的报错说清"展开与选中都靠它认"', \str_contains(logText($log), 'id'));
    }

    public function testDuplicateNodeIdFailsLoudly(): void
    {
        $log = \sys_get_temp_dir() . '/drift-treeview-' . \getmypid() . '.log';
        fresh($log);
        [$code, $out] = runFatalCase('treedupid', $log);
        check('TreeView · 重复 id ⇒ Fatal（否则展开一个、两个都展开）', $code === 3, '实际 ' . $code . ' / ' . $out);
        check('TreeView · 重复 id 的报错说清"两个节点的 id 都是"', \str_contains(logText($log), '两个节点'));
    }

    // ── 夹具 ────────────────────────────────────────────────────────────

    /**
     * a（有子：a1 叶、a2 有子：a2x 叶）/ b（叶）/ c（叶）
     *
     * 三层都用到，且**有子与叶子交错** —— "只展根层"和"展开插错位置"都能被抓住。
     *
     * @param list<string> $expanded
     */
    private function view(
        array $expanded,
        ?\Closure $onSelected = null,
        ?\Closure $onToggle = null,
        ?string $selected = null,
    ): TreeView {
        $nodes = [
            TreeNode::branch('a', '甲', [
                TreeNode::make('a1', '甲一'),
                TreeNode::branch('a2', '甲二', [TreeNode::make('a2x', '甲二之一')]),
            ]),
            TreeNode::make('b', '乙'),
            TreeNode::make('c', '丙'),
        ];

        $view = TreeView::make($nodes)->expanded($expanded)->selected($selected);

        if ($onSelected !== null) {
            $view->onSelected($onSelected);
        }
        if ($onToggle !== null) {
            $view->onToggle($onToggle);
        }

        return $view;
    }

    private function mount(TreeView $view): Element
    {
        $element = $view->createElement();
        $element->mount(null, smokeContext());

        return $element;
    }

    private function frame(Element $root): void
    {
        $surface = new SurfaceProbe();
        $surface->resize(320, 240, 1.0);
        (new Frame($surface))->run($root, BoxConstraints::tight(new Size(320.0, 240.0)), 64.0);
    }

    /** @param list<TreeRow> $rows @return list<string> */
    private function ids(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $row->id;
        }

        return $out;
    }

    /** 树里 `Row` 的个数 = 行数（`TreeView` 是唯一造 `Row` 的地方） */
    private function countRows(Element $root): int
    {
        return $this->countWidgets($root, Row::class);
    }

    private function countWidgets(Element $root, string $class): int
    {
        $n = 0;
        $walk = function (Element $el) use (&$walk, &$n, $class): void {
            if ($el->widget() instanceof $class) {
                ++$n;
            }
            $el->visitChildren($walk);
        };
        $walk($root);

        return $n;
    }

    /** @return list<\Drift\Layout\RenderBox> 每个行的 Box（`Row` 自己的盒） */
    private function rowBoxes(Element $root): array
    {
        $out = [];
        $walk = function (Element $el) use (&$walk, &$out): void {
            if ($el->widget() instanceof Row) {
                $box = $el->effectiveBox();
                if ($box !== null) {
                    $out[] = $box;
                }
            }
            $el->visitChildren($walk);
        };
        $walk($root);

        return $out;
    }

    /**
     * 每个行**首个孩子**（缩进 `SizedBox`，深度 0 时没有 ⇒ 记 0）的宽度。
     *
     * @return list<float>
     */
    private function indentWidths(Element $root): array
    {
        $out = [];
        $walk = function (Element $el) use (&$walk, &$out): void {
            if ($el->widget() instanceof Row) {
                $first = $el->childAt(0);
                $box = $first?->effectiveBox();
                $size = $box?->size();
                $out[] = $size === null ? -1.0 : $size->width;

                return;
            }
            $el->visitChildren($walk);
        };
        $walk($root);

        return $out;
    }

    /**
     * 每个标签按钮的绝对 x（标签的左沿）。
     *
     * @return list<float>
     */
    private function labelStarts(Element $root): array
    {
        $out = [];
        $walk = function (Element $el) use (&$walk, &$out): void {
            $widget = $el->widget();
            if ($widget instanceof Button && !$widget instanceof IconButton) {
                $box = $el->effectiveBox();
                if ($box !== null) {
                    $out[] = boxOrigin($box)[0];
                }
            }
            $el->visitChildren($walk);
        };
        $walk($root);

        return $out;
    }

    /** @return list<bool> 每行标签按钮的内容是否居中（`false` = 左对齐） */
    private function labelCentering(Element $root): array
    {
        $out = [];
        $walk = function (Element $el) use (&$walk, &$out): void {
            $widget = $el->widget();
            if ($widget instanceof Button && !$widget instanceof IconButton) {
                $box = $el->effectiveBox();
                if ($box instanceof RenderButton) {
                    $out[] = $box->centersContent();
                }
            }
            $el->visitChildren($walk);
        };
        $walk($root);

        return $out;
    }

    /** 视口顶部那一行的标签（文档序第一个标签按钮 —— 窗口第一行） */
    private function firstRowLabel(Element $root): string
    {
        $hit = findElement(
            $root,
            static fn(Element $e): bool => $e->widget() instanceof Button && !$e->widget() instanceof IconButton,
        );
        $button = $hit?->widget();

        return $button instanceof Button ? $button->label() : '?';
    }

    /** @return list<IconButton> */
    private function collectIconButtons(Element $root): array
    {
        $out = [];
        $walk = function (Element $el) use (&$walk, &$out): void {
            $widget = $el->widget();
            if ($widget instanceof IconButton) {
                $out[] = $widget;
            }
            $el->visitChildren($walk);
        };
        $walk($root);

        return $out;
    }

    /** @return list<Button> 行标签按钮（排除箭头） */
    private function collectLabelButtons(Element $root): array
    {
        $out = [];
        $walk = function (Element $el) use (&$walk, &$out): void {
            $widget = $el->widget();
            if ($widget instanceof Button && !$widget instanceof IconButton) {
                $out[] = $widget;
            }
            $el->visitChildren($walk);
        };
        $walk($root);

        return $out;
    }

    /** @return list<string> 每行标签按钮的变体（小写枚举名） */
    private function labelVariants(Element $root): array
    {
        $out = [];
        foreach ($this->collectLabelButtons($root) as $button) {
            $out[] = \strtolower($button->variantValue()->name);
        }

        return $out;
    }
}
