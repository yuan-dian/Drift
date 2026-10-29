<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Input\SemanticsRole;

/**
 * 层级数据树（§12.1 第 4/7 条，A3 第二件，2026-10-29）。
 *
 * ```php
 * TreeView::make($this->tree)                       // list<TreeNode>
 *     ->expanded($this->openIds)                    // list<string>
 *     ->selected($this->currentId)                  // ?string
 *     ->onToggle(fn(string $id, bool $open) => $this->setState(fn() => $this->flipId($id)))
 *     ->onSelected(fn(string $id) => $this->setState(fn() => $this->currentId = $id));
 * ```
 *
 * ## 它解决的三个问题
 *
 * ① **递归数据 ⇒ 扁平渲染**。树是递归的，屏幕上的行是线性的；本控件把
 *    "哪一层、哪一个、展开没展开"压成 `TreeRow`，交给 `LazyColumn` 去画。
 * ② **虚拟化**。文件树 / 组织架构常年上千节点，全量 `Column` 是每帧线性成本。
 *    这里直接**组合 `LazyColumn`**（与 `LazyGrid` 同一条路），只造视口覆盖的行。
 * ③ **展开后的视口不跳**。`keyOf()` 用行的 `id` 做锚点 ⇒ 展开 / 折叠改变行数后，
 *    `LazyListState` 仍按"同一个节点"恢复滚动位置，而不是按序号硬夹。
 *
 * ## 三个岔口的裁决
 *
 * | 岔口 | 裁决 | 理由 |
 * |---|---|---|
 * | 展开集合：受控 vs 自持 | **受控**（`expanded()` + `onToggle()`） | 与 `TabView::selected` / `Table::columnWidths` 同族：本仓"屏幕上的样子"一律由应用声明。自持的话"展开全部 / 搜索时自动展开命中路径 / 跨会话记住展开态"三件事应用都做不到，只能再补一套反向同步 API |
 * | 自己当容器 vs 组合 | **`StatelessWidget` + `LazyColumn`** | 零新增 Element / RenderBox。树的全部状态（展开、选中）都在外面 ⇒ 它只是"展平 + 画一行"这个组合 |
 * | 数据形状 | 新值对象 `TreeNode`（`id`/`label`/`children`） | 与 `Option` 同族：`id` 是身份。展开集合与选中值都按 `id` 认，不按下标 —— 过滤 / 重排后下标会漂 |
 *
 * ## 行的构成
 *
 * ```
 * [ 缩进 depth×indent ][ 箭头（叶子：等宽空位） ][ 标签按钮 ]
 * ```
 *
 * - **箭头**：`IconButton`（折叠 `ArrowRight` / 展开 `ChevronDown`），`skipFocus()`
 *   —— 每行只留**一个** Tab 站（标签按钮），否则 12 个可见行就有 24 个站。
 * - **标签**：`Button`，选中项用 `Primary` 底。`onSelected()` 没给 ⇒ 标签按钮
 *   不占 Tab 站（没人接手这个变化，与 `TabView` 不给 `onChanged` 页签点不动同族）。
 * - **缩进**：`SizedBox(width: depth × indent)`。根层不建这个盒子（省一个 Element）。
 *
 * ## 有意的边界（不是缺陷，别当 bug 报）
 *
 * - **行高必须装得下箭头按钮**（`IconButton` 的天然边长 = `3×gap + (Body 字号+4)`
 *   = 24 + 18 = 42）⇒ 默认 `rowHeight = 42`。想更密的树要等"紧凑按钮档"，那是
 *   `Button` 的事，不在本控件里塞私货。
 * - **单选**。多选（`Ctrl`/`Shift` 加选）没有 —— 那要 `SelectionModel` 那层原语。
 * - **无展开动画**。`AnimatedValue` 已经在了，但"展开时行数变化 + 动画"是布局
 *   动画（不是样式动画），要另立原语。
 * - **方向键导航属 W8-4**。本控件不自己拦方向键：树的"↑↓ 移行、→ 展开、← 折叠"
 *   是一套跨控件的手感，归键盘子系统统一裁决。
 * - **不检测环**。`$a` 的 children 里放 `$a` 自己 ⇒ 展平无限递归。这与"给
 *   `Column` 塞自己"同类，是应用侧的数据错误，框架不替它兜。
 *
 * ★ **`TreeView` 自己没有渲染盒**（靠 §13.2 `effectiveBox()` 向下穿透到
 *   `LazyColumn`）。⇒ 别给它套 `padding()` / `background()`：不产盒的控件声明
 *   修饰会**响亮失败**（`Element` 挂载时报错并给出修法）。要留白 ⇒ 在
 *   `LazyColumn` 那层用 `->padding()`（见 `insets()`），或外面套 `Card`。
 *
 * ★ **`LazyColumn` 自己就是滚动视口** ⇒ 必须放进**有界高度**里
 *   （`Expanded` / `SizedBox`）。放进无界高度会由 `RenderScrollView` 响亮报错。
 *
 * @api
 */
final class TreeView extends StatelessWidget
{
    /**
     * 默认行高 —— **由箭头按钮的天然高决定**（`IconButton` = 24 内边距 + 18 图标）。
     *
     * ★ 低于它时行内容会被 `LazyColumn` 的 `SizedBox` 夹住 ⇒ 箭头被裁。这不是
     *   可以随手改小的审美参数；要更密先解决按钮的紧凑档。
     */
    public const DEFAULT_ROW_HEIGHT = 42.0;

    /** 每层缩进（逻辑像素） */
    public const DEFAULT_INDENT = 18.0;

    /**
     * 叶子行占的箭头位宽度（= `IconButton` 的天然边长）。
     *
     * ★ 与 `DEFAULT_ROW_HEIGHT` 同源但**不同义**：那个是行高（可以调大），这个是
     *   对齐用的空位（**不能**跟着调）。两者今天数值相等是巧合般的必然 ——
     *   箭头按钮是正方形。测试里有一条断言守着"叶子行与有子行的标签起点相同"，
     *   主题字号 / 间距一变它就红，而不是静默错位。
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
        // §9 无障碍：容器本身是"树"（集合族）。行是 `Button`（交互族），各司其职
        // —— 名字留给 `name()`：框架不知道这棵树叫什么。
        $this->semantics(SemanticsRole::Tree);
    }

    /**
     * @param list<TreeNode> $nodes 根层节点（顺序即显示顺序）
     *
     * ★ 校验在**这里**（构造期）而不是 `build()`：报错的栈顶才会指向应用写
     *   `TreeView::make(...)` 的那一行。`build()` 期报错时栈里全是框架帧。
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
     *
     * ★ **不在表里的有子节点一律折叠**（不是"以前展开过就记着"）——受控控件
     *   没有"以前"，只有应用这一次给的这表。要记住展开态就自己存。
     *
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
     * 展开 / 折叠请求：收**节点 id** 与**目标状态**（不是"切换了"这个事件）。
     *
     * ★ 收目标状态而不是"让我翻一下"：受控控件不给应用留"猜当时是什么状态"的
     *   机会——两处各自推断就会有不一致的那一刻。
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
     * 行高（**必须装得下箭头按钮**，见 `DEFAULT_ROW_HEIGHT`）。
     *
     * ★ 只有**一条**守卫，不是"0 一条、<42 一条"。后者是死规则：`< 42` 已经完全
     *   覆盖 `≤ 0`，留着只会让人以为两条各管一件事（2026-10-29 变异删掉 `≤0` 那条
     *   仍然全绿 ⇒ 它就是被兜底的那条）。
     * ★ 这条守卫是一次变异逼出来的：把 `LazyColumn::make($this->rowHeight)` 硬编码
     *   成 28 时，测试**不是变红而是把进程打崩了**（42 高的行内容塞进 28 的盒子）。
     *   "崩"与"响亮的报错"是两件事 —— 崩掉时应用只看到进程没了、没有修法；
     *   而"小于 42 必然被裁"是设值时就能说清的事。
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
     *
     * ★ `public` 只是为了让同命名空间的测试与调试能读；**不属于公共 API**。
     *   应用侧要"可见行"说明它想自己做命中 / 滚动定位 —— 那时该新开一个方法，
     *   不是拿这个当契约。
     *
     * @return list<TreeRow>
     *
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

        // ★ 闭包**不捕获 `$this`**：`LazyColumn` 会被留在渲染树上，捕获本 Widget
        //   就会把整棵树（含它的 children）一起留住，而 Widget 不可变 ⇒ 留住的
        //   是一份不再更新的快照。与 `LazyGrid::build()` 同一条纪律。
        $selected = $this->selected;
        $onToggle = $this->onToggle;
        $onSelected = $this->onSelected;
        $indent = $this->indent;

        return LazyColumn::make($this->rowHeight)
            ->items($rows, static function (TreeRow $row) use ($selected, $onToggle, $onSelected, $indent): Widget {
                return self::rowWidget($row, $selected, $onToggle, $onSelected, $indent);
            })
            // ★ 锚点按**节点身份**，不按序号：展开一行会让它后面所有行的序号 +N，
            //   按序号恢复会把视口甩到别的节点上（"展开一下，列表跳走了"）。
            ->keyOf(static function (TreeRow $row) {
                return $row->id;
            });
    }

    /**
     * 一行：缩进 + 箭头 + 标签。
     *
     * ★ 静态方法（不吃 `$this`）：它是被 `LazyColumn` 留在树上的闭包调用的，
     *   实例方法会让"闭包 → 实例 → 树"这条引用链重新出现。
     */
    private static function rowWidget(
        TreeRow $row,
        ?string $selected,
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
            // ★ 叶子占**等宽空位**：不给的话叶子的标签会比同层有子节点的标签
            //   左移一个箭头宽，整棵树看着像"错位"（而实际是"少画了一个箭头"）。
            $kids[] = SizedBox::make()->width(self::ARROW_SLOT);
        }

        // ★ `alignLeft()` 不是可选的审美：标签按钮被 `Expanded` 撑满剩余宽度
        //   （点行的空白也算点这一行），默认居中会让整棵树看着像居中的段落 ——
        //   缩进层级只能从箭头位置看出来，标题全在窗口中间飘。
        $label = Button::make($row->label)
            ->alignLeft()
            ->variant($row->id === $selected ? ButtonVariant::Primary : ButtonVariant::Flat);

        if ($onSelected === null) {
            // 没人接手选中 ⇒ 这一行不该占 Tab 站（每个可见行都占的话，键盘用户
            // 要按 Tab 穿过整棵树才能出去）。
            $label->skipFocus();
        } else {
            $labelId = $row->id;
            $label->onPressed(static function () use ($labelId, $onSelected): void {
                $onSelected($labelId);
            });
        }

        $kids[] = Expanded::make($label);

        // ★ 交叉轴交给 `Row` 的默认对齐（`Center`）：箭头 42 高、标签按钮约 35 高，
        //   两者各自按自然高居中 —— 强加 `Stretch` 会把标签按钮拉成 42 高，
        //   `Primary` 底的选中条就会比文字高一圈。
        return Row::make()->children($kids);
    }

    /**
     * 展平（递归）。
     *
     * ★ 不用引用参数累加（`array &$out`）：`src/` 里没有一处引用参数的先例，
     *   而 tpc 对它的支持没有实测过 ⇒ 用"返回列表再合并"这条纯函数式路。
     *   树深通常 < 10，合并成本可以忽略。
     *
     * @param list<TreeNode>    $nodes
     * @param array<string,bool> $expanded
     * @return list<TreeRow>
     */
    private function flatten(array $nodes, int $depth, array $expanded): array
    {
        $out = [];

        foreach ($nodes as $node) {
            // ★ `expanded` 必须**同时**要求"有子"：叶子被点名展开时它没有可展开的
            //   东西，若沿用集合里的 true，行会画出"已展开"的箭头状态（而点它什么
            //   都不会发生）。叶子恒为 `false` 才是诚实的。
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
     *
     * ★ 为什么必须响亮失败：展开集合与选中值都按 `id` 认节点 ⇒ 两个节点同 id 时
     *   "展开一个、两个都展开"，"选中一个、两个都高亮"。这是**静默的错**，
     *   画面上看不出来是 bug，只像"这个控件有点怪"。
     * ★ 代价是每次 build 一趟 O(n)（与展平同量级、都在纯 PHP 里，微秒级），
     *   换来的是这类数据错误在第一次渲染就炸，而不是上线后靠人发现。
     *
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
