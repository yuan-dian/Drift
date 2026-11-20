<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Expanded;
use Drift\Widget\Row;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\TreeNode;
use Drift\Widget\TreeView;
use Drift\Widget\Widget;
use Drift\Widget\Wrap;

/**
 * 树 TreeView。
 *
 * ★ 这一页存在的理由同 `TablePage`：让"层级数据 + 虚拟化"在**生产形态**下被走通
 *   一次，同时把"受控"这件事演成看得见的东西 ——
 *   · 展开集合住本页 `State` ⇒ 上面两个按钮能直接改它（控件自持时应用**读不到**）；
 *   · 选中值也住本页 `State` ⇒ 右上角能显示"选中的是哪一个"；
 *   · 「展开全部 / 折叠全部」不是控件能力，是应用拿着数据的结果 —— 这正是
 *     受控的价值：搜索时自动展开命中路径、跨会话记住展开态，都是同一件事。
 *
 * ★ 页根**刻意不是** `ScrollView`（与其余文档页不同）：`TreeView` 内部就是
 *   `LazyColumn`，**自己就是滚动视口**。外面再套一层就是嵌套滚动 —— 内层永远
 *   "量到全部内容"，外层滚不动，两层都白搭。这里用 `Expanded` 给它有界高度。
 *
 * ★ 行高默认 42 不是审美参数：`IconButton` 的天然边长就是 42（`3×gap + 字号`）。
 *   调小会被 `LazyColumn` 的行盒静默裁掉，所以 `rowHeight()` 小于它**响亮失败**。
 */
final class TreePage extends StatefulWidget
{
    public function __construct()
    {
        parent::__construct();
    }

    public static function make(): self
    {
        return new self();
    }

    public function createState(): State
    {
        return new TreePageState();
    }
}

final class TreePageState extends State
{
    /**
     * 所有**有子**的节点 id（前序）。
     *
     * ★ 「展开全部」直接把它整个赋给 `$expanded`：控件只认 id 集合，不认"树长什么样"
     *   —— 这也是为什么 id 必须稳定（用路径而不是序号，否则重排后展开态会漂到
     *   别的节点上）。
     *
     * @var list<string>
     */
    private const BRANCHES = ['project', 'src', 'widget', 'docs', 'arch', 'apps', 'tests'];

    /** @var list<string> 展开的节点 id（受控 —— 不在表里的有子节点一律折叠） */
    private array $expanded = ['project', 'src', 'widget'];

    private ?string $selected = 'treeview';

    public function build(BuildContext $ctx): Widget
    {
        return Card::make(
            Column::make()->spacing(10.0)->padding(EdgeInsets::all(20.0))->children([
                Text::make('树 TreeView')->title(),
                Text::make(
                    '递归数据在控件里展平成定长行、交给 `LazyColumn`（只造视口覆盖的行）；'
                        . '展开集合与选中值都在本页 State —— 所以上面两个按钮能直接改展开态。'
                        . '每行只有一个 Tab 站（箭头用 skipFocus）；← 折叠自己、已折叠时跳到父行。'
                )->caption(),
                $this->toolbar(),
                Expanded::make($this->treePanel()),
            ])
        )->paddingAll(0.0);
    }

    /**
     * 展开/折叠全部 + 当前选中。
     *
     * ★ 三样都读 `$this` 的 State —— 这正是"受控"看得见的那一半：控件自己
     *   不知道"全部"是哪些节点，那是应用的数据。
     * ★ 「选中」提示紧跟在按钮后面而不是 `Spacer` 推到右缘：长中文串的固有宽度
     *   估得比实际窄，右对齐时会被推到框外（golden 图里就能看见半个字）。
     */
    private function toolbar(): Widget
    {
        return Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
            Button::make('展开全部')->onPressed(function (): void {
                $this->setState(function (): void {
                    $this->expanded = self::BRANCHES;
                });
            }),
            Button::make('折叠全部')->variant(ButtonVariant::Flat)->onPressed(function (): void {
                $this->setState(function (): void {
                    $this->expanded = [];
                });
            }),
            Text::make('当前选中：' . ($this->selected ?? '（无）'))->caption(),
        ]);
    }

    private function treePanel(): Widget
    {
        // ★ 白卡片只负责"框 + 内边距"：`TreeView` 自己没有渲染盒（靠
        //   `effectiveBox()` 向下穿透到 `LazyColumn`）⇒ 给它套 `padding()` 会
        //   响亮失败，留白要落在外面这层盒上。
        return Card::make(
            TreeView::make($this->nodes())
                ->expanded($this->expanded)
                ->selected($this->selected)
                ->onToggle(function (string $id, bool $open): void {
                    // ★ 收的是**目标状态**（`$open`），不是"翻一下"：受控控件不给
                    //   应用留"猜当时是什么状态"的机会 —— 两处各自推断就会有不一致的那一刻。
                    $this->setState(function () use ($id, $open): void {
                        $this->expanded = $this->withToggled($id, $open);
                    });
                })
                ->onSelected(function (string $id): void {
                    $this->setState(function () use ($id): void {
                        $this->selected = $id;
                    });
                })
        )->paddingAll(6.0);
    }

    /**
     * 一份像真实项目的目录（四层，够看出缩进）。
     *
     * ★ 每次 `build()` 都新建：`TreeNode` 是 readonly 值对象（同 `Option`），
     *   造它的成本和造一个数组差不多；换成缓存表反而要自己管失效。
     *
     * @return list<TreeNode>
     */
    private function nodes(): array
    {
        return [
            TreeNode::branch('project', 'drift2', [
                TreeNode::branch('src', 'src', [
                    TreeNode::branch('widget', 'Widget', [
                        TreeNode::make('treeview', 'TreeView.php'),
                        TreeNode::make('lazycolumn', 'LazyColumn.php'),
                        TreeNode::make('button', 'Button.php'),
                    ]),
                    TreeNode::make('layout', 'Layout'),
                    TreeNode::make('input', 'Input'),
                ]),
                TreeNode::branch('docs', 'docs', [
                    TreeNode::make('readme', 'README.md'),
                    TreeNode::branch('arch', 'architecture', [
                        TreeNode::make('a08', '08-控件面.md'),
                        TreeNode::make('a09', '09-主题与语义.md'),
                    ]),
                ]),
                TreeNode::make('composer', 'composer.json'),
            ]),
            TreeNode::branch('apps', 'apps', [
                TreeNode::make('hello', 'hello'),
                TreeNode::make('showcase', 'showcase'),
            ]),
            TreeNode::branch('tests', 'tests', [
                TreeNode::make('feature', 'Feature'),
                TreeNode::make('support', 'Support'),
            ]),
        ];
    }

    /**
     * 把 `$id` 按 `$open` 放进/移出展开集合。
     *
     * @return list<string>
     */
    private function withToggled(string $id, bool $open): array
    {
        $out = [];
        foreach ($this->expanded as $existing) {
            if ($existing !== $id) {
                $out[] = $existing;
            }
        }

        if ($open) {
            $out[] = $id;
        }

        return $out;
    }
}
