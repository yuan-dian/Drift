<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\KeyResult;
use Drift\Input\SemanticsRole;

/**
 * 层级数据树：把递归树按"展开集合"压成线性 `TreeRow`，交给 `LazyColumn` 虚拟化绘制。
 *
 * 键盘（§58，与 WPF `TreeView` / ARIA `tree` / Qt `QTreeView` 同形）：整棵**只占一个
 * Tab 站**（选中行，没有就首个可见行），`↑↓` 在行间走（框架几何导航），`←→` 展开折叠，
 * Enter 选中。`←→` 归树自己（`keyboard()` + `KeyResult`），`↑↓` 用
 * `KeyResult::Declined` 还给框架。
 *
 * ★ 自己没有渲染盒（靠 `effectiveBox()` 向下穿透到 `LazyColumn`）：别给它套
 *   `padding()` / `background()`，不产盒的控件声明修饰会响亮失败；留白改在
 *   `LazyColumn` 那层用 `->padding()`，或外面套 `Card`。
 * ★ `LazyColumn` 自己就是滚动视口 ⇒ 必须放进有界高度（`Expanded` / `SizedBox`），
 *   放进无界高度会由 `RenderScrollView` 响亮报错。
 * @api
 */
final class TreeView extends StatelessWidget
{
    /**
     * 默认行高 —— 由箭头按钮的天然高决定（`IconButton` = 24 内边距 + 18 图标）。
     * 低于它时行内容会被 `LazyColumn` 的 `SizedBox` 夹住 ⇒ 箭头被裁。这不是
     * 可以随手改小的审美参数；要更密先解决按钮的紧凑档。
     */
    public const DEFAULT_ROW_HEIGHT = 42.0;

    /** 每层缩进（逻辑像素） */
    public const DEFAULT_INDENT = 18.0;

    /**
     * 叶子行占的箭头位宽度（= `IconButton` 的天然边长）。
     * 与 `DEFAULT_ROW_HEIGHT` 同源但不同义：那个是行高（可调大），这个是
     * 对齐用的空位（不能跟着调）。两者数值相等是因为箭头按钮是正方形；
     * 主题字号 / 间距一变测试就红，而不是静默错位。
     */
    public const ARROW_SLOT = 42.0;

    /** @var list<TreeNode> 根层节点 */
    private array $nodes = [];

    /** @var list<string> 展开的节点 id（受控；不在这里面的有子节点是折叠的） */
    private array $expanded = [];

    private ?string $selected = null;

    private ?\Closure $onToggle = null;

    private ?\Closure $onSelected = null;

    private float $rowHeight = self::DEFAULT_ROW_HEIGHT;

    private float $indent = self::DEFAULT_INDENT;

    private function __construct()
    {
        parent::__construct();
        // 无障碍：容器本身是"树"（集合族），行是 `Button`（交互族），各司其职；
        //   名字留给 `name()`：框架不知道这棵树叫什么。
        $this->semantics(SemanticsRole::Tree);
    }

    /**
     * @param list<TreeNode> $nodes 根层节点（顺序即显示顺序）
     * 校验在构造期而不是 `build()`：报错栈顶才会指向应用写 `TreeView::make(...)` 的那一行。
     */
    public static function make(array $nodes): self
    {
        TreeNode::assertNodes($nodes, 'TreeView::make()');

        $view = new self();
        $view->nodes = \array_values($nodes);

        return $view;
    }

    /** 整棵树的名字（读屏 / 检查器里那一条的名字） */
    public function name(string $name): static
    {
        return $this->semantics(SemanticsRole::Tree, $name);
    }

    /**
     * 已展开的节点 id（受控）。
     * 不在表里的有子节点一律折叠（不是"以前展开过就记着"）—— 受控控件
     * 没有"以前"，只有应用这一次给的表。要记住展开态就自己存。
     * @param list<string> $ids
     */
    public function expanded(array $ids): static
    {
        $this->mutate();
        $this->expanded = \array_values($ids);

        return $this;
    }

    /** 选中节点的 id（`null` = 没选） */
    public function selected(?string $id): static
    {
        $this->mutate();
        $this->selected = $id;

        return $this;
    }

    /**
     * 展开 / 折叠请求：收节点 id 与目标状态（不是"切换了"这个事件）。
     * 收目标状态而不是"让我翻一下"：受控控件不给应用留"猜当时状态"的机会。
     */
    public function onToggle(callable $handler): static
    {
        $this->mutate();
        $this->onToggle = \Closure::fromCallable($handler);

        return $this;
    }

    /** 选中请求：收节点 id。不给 ⇒ 标签按钮连 Tab 站都不占（没人接手） */
    public function onSelected(callable $handler): static
    {
        $this->mutate();
        $this->onSelected = \Closure::fromCallable($handler);

        return $this;
    }

    /**
     * 行高（必须装得下箭头按钮，见 `DEFAULT_ROW_HEIGHT`）。
     * 只有一条守卫（`< 42` 已覆盖 `≤ 0`），不留第二条死规则。
     */
    public function rowHeight(float $px): static
    {
        $this->mutate();

        if ($px < self::ARROW_SLOT) {
            Errors::report(\sprintf(
                'TreeView::rowHeight(%s) 小于箭头按钮的天然高 %s ⇒ 行内容必然被裁（箭头画不全），而裁切不报错。'
                    . "\n" . '行高只能往大了调（≥ %s）；要更密的树得先有按钮的紧凑档。'
                    . "\n" . '★ 它同时是 LazyColumn 的 itemExtent（窗口计算的除数）⇒ 0 与负数连算都算不了。',
                (string) $px,
                (string) self::ARROW_SLOT,
                (string) self::ARROW_SLOT,
            ));
        }

        $this->rowHeight = $px;

        return $this;
    }

    /** 每层缩进（逻辑像素；0 = 全部顶格） */
    public function indent(float $px): static
    {
        $this->mutate();
        $this->indent = \max(0.0, $px);

        return $this;
    }

    /** @return list<TreeNode> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /** @return list<string> */
    public function expandedIds(): array
    {
        return $this->expanded;
    }

    public function selectedId(): ?string
    {
        return $this->selected;
    }

    /**
     * 可见行（把递归树按"展开集合"压成线性）。
     * `public` 只是为了让同命名空间的测试与调试能读；不属于公共 API。
     * 应用侧要"可见行"说明它想自己命中 / 滚动定位 —— 那时该新开一个方法。
     * @return list<TreeRow>
     * @internal
     */
    public function visibleRows(): array
    {
        $set = [];
        foreach ($this->expanded as $id) {
            $set[$id] = true;
        }

        $this->assertUniqueIds($this->nodes, []);

        return $this->flatten($this->nodes, 0, $set);
    }

    public function build(BuildContext $ctx): Widget
    {
        $rows = $this->visibleRows();

        // 闭包不捕获 `$this`：`LazyColumn` 会留在渲染树上，捕获本 Widget
        //   就会把整棵树（含 children）一起留住，而 Widget 不可变 ⇒ 留住的
        //   是一份不再更新的快照。与 `LazyGrid::build()` 同一条纪律。
        $selected = $this->selected;
        $activeId = $this->activeRowId($rows);
        $onToggle = $this->onToggle;
        $onSelected = $this->onSelected;
        $indent = $this->indent;

        return LazyColumn::make($this->rowHeight)
            ->items($rows, static function (TreeRow $row) use ($selected, $activeId, $onToggle, $onSelected, $indent): Widget {
                return self::rowWidget($row, $selected, $activeId, $onToggle, $onSelected, $indent);
            })
            // 锚点按节点身份，不按序号：展开一行会让它后面所有行的序号 +N，
            //   按序号恢复会把视口甩到别的节点上（"展开一下，列表跳走了"）。
            ->keyOf(static function (TreeRow $row) {
                return $row->id;
            });
    }

    /**
     * 一行：缩进 + 箭头 + 标签。
     * 静态方法（不吃 `$this`）：它是被 `LazyColumn` 留在树上的闭包调用的，
     * 实例方法会让"闭包 → 实例 → 树"这条引用链重新出现。
     */
    private static function rowWidget(
        TreeRow $row,
        ?string $selected,
        ?string $activeId,
        ?\Closure $onToggle,
        ?\Closure $onSelected,
        float $indent,
    ): Widget {
        $kids = [];

        if ($row->depth > 0) {
            $kids[] = SizedBox::make()->width((float) $row->depth * $indent);
        }

        if ($row->hasChildren) {
            $arrow = IconButton::of(
                $row->expanded ? IconName::ChevronDown : IconName::ArrowRight,
                $row->expanded ? '折叠' : '展开',
            )->variant(ButtonVariant::Flat)->skipFocus();

            if ($onToggle !== null) {
                $id = $row->id;
                $open = $row->expanded;
                $arrow->onPressed(static function () use ($id, $open, $onToggle): void {
                    $onToggle($id, !$open);
                });
            }

            $kids[] = $arrow;
        } else {
            // 叶子占等宽空位：不给的话叶子的标签会比同层有子节点的标签
            //   左移一个箭头宽，整棵树看着像"错位"。
            $kids[] = SizedBox::make()->width(self::ARROW_SLOT);
        }

        // `alignLeft()` 不是可选的审美：标签按钮被 `Expanded` 撑满剩余宽度
        //   （点行的空白也算点这一行），默认居中会让整棵树看着像居中的段落。
        $label = Button::make($row->label)
            ->alignLeft()
            ->variant($row->id === $selected ? ButtonVariant::Primary : ButtonVariant::Flat);

        // roving tabindex（§57 的纪律，§58 落到树上）：整棵只留**一行**进 Tab 序，
        //   其余靠方向键走。全留 ⇒ 200 行的树要按 200 次 Tab 才出得去；
        //   全不留 ⇒ 键盘根本进不来。`skipFocus()` 只挡 Tab 序，方向键照样够得着。
        if ($row->id !== $activeId) {
            $label->skipFocus();
        }

        if ($onSelected !== null) {
            $labelId = $row->id;
            $label->onPressed(static function () use ($labelId, $onSelected): void {
                $onSelected($labelId);
            });
        }

        // ★ 每一行都挂：焦点可以靠方向键落到任意一行，不只活动行。
        $label->keyboard(self::rowKeyHandler($row, $onToggle));

        $kids[] = Expanded::make($label);

        // 交叉轴交给 `Row` 的默认对齐（`Center`）：箭头 42 高、标签按钮约 35 高，
        //   各自按自然高居中；强加 `Stretch` 会把标签按钮拉成 42 高，
        //   `Primary` 底的选中条会高过文字。
        return Row::make()->children($kids);
    }

    /**
     * 一行的键盘：`←→` 展开折叠，其余键 `Declined` 交回框架。
     *
     * ★ `←→` 必须 `Handled` 而不是让位：行标签满宽、右边空无一物，左边就是折叠箭头
     *   ⇒ 让位之后按 ← 会落到那个**看不见的站**上（实测：分支行落到自己那一行的箭头，
     *   叶子行落到下一行的箭头）。层级语义在树里，箭头只是给鼠标看的。
     * ★ `↑↓` 必须 `Declined`：那是几何导航的活（`FocusManager::focusDirectional`），
     *   树自己算"下一行是谁"等于再造一套焦点模型。
     */
    private static function rowKeyHandler(TreeRow $row, ?\Closure $onToggle): \Closure
    {
        $id = $row->id;
        $hasKids = $row->hasChildren;
        $open = $row->expanded;

        return static function (KeyEvent $event) use ($id, $hasKids, $open, $onToggle): KeyResult {
            $chord = $event->chord;
            $right = $chord->is(KeyChord::right());
            $left = $chord->is(KeyChord::left());

            if (!$left && !$right) {
                return KeyResult::Declined;
            }

            if ($hasKids && $onToggle !== null) {
                if ($right && !$open) {
                    $onToggle($id, true);
                } elseif ($left && $open) {
                    $onToggle($id, false);
                }
            }

            return KeyResult::Handled;
        };
    }

    /**
     * 唯一进 Tab 序的那一行：选中的（若可见）⇒ 否则首个可见行。
     *
     * 没有任何回调 ⇒ `null`（整棵不占站：进去了也没事可做）。
     * ★ 不猜"第一个选中的分支"之类：树是受控的，活动行只能由应用给的值决定，
     *   塞时间 / 树形推断进来判据就变成"跑一次一个样"。
     *
     * @param list<TreeRow> $rows
     */
    private function activeRowId(array $rows): ?string
    {
        if ($this->onSelected === null && $this->onToggle === null) {
            return null;
        }

        foreach ($rows as $row) {
            if ($row->id === $this->selected) {
                return $row->id;
            }
        }

        return $rows === [] ? null : $rows[0]->id;
    }

    /**
     * 展平（递归）。
     * 不用引用参数累加（`array &$out`）：`src/` 里没有引用参数的先例，
     * 而 tpc 对它的支持未实测 ⇒ 用"返回列表再合并"的纯函数式路。树深通常 < 10。
     * @param list<TreeNode>    $nodes
     * @param array<string,bool> $expanded
     * @return list<TreeRow>
     */
    private function flatten(array $nodes, int $depth, array $expanded): array
    {
        $out = [];

        foreach ($nodes as $node) {
            // `expanded` 必须同时要求"有子"：叶子被点名展开时它没有可展开的
            //   东西，若沿用集合里的 true，行会画出"已展开"的箭头状态。
            $hasKids = $node->hasChildren();
            $open = $hasKids && isset($expanded[$node->id]);
            $out[] = new TreeRow($node->id, $node->label, $depth, $hasKids, $open);

            if (!$open) {
                continue;
            }

            foreach ($this->flatten($node->children, $depth + 1, $expanded) as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * 重复 id 校验（递归）。
     * 为什么必须响亮失败：展开与选中都按 `id` 认节点 ⇒ 两个节点同 id 时
     * "展开一个、两个都展开"，"选中一个、两个都高亮" —— 这是静默的错。
     * 代价是每次 build 一趟 O(n)（与展平同量级，微秒级），换来首次渲染就炸。
     * @param list<TreeNode>     $nodes
     * @param array<string,bool> $seen
     * @return array<string,bool>
     */
    private function assertUniqueIds(array $nodes, array $seen): array
    {
        foreach ($nodes as $node) {
            if (isset($seen[$node->id])) {
                Errors::report(\sprintf(
                    'TreeView 的树里有两个节点的 id 都是 %s —— id 是展开与选中的身份，重复会"展开一个、两个都展开"（且不报错）。',
                    \var_export($node->id, true),
                ));
            }

            $seen[$node->id] = true;
            $seen = $this->assertUniqueIds($node->children, $seen);
        }

        return $seen;
    }
}
