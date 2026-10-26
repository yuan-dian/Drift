<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Input\SemanticsRole;

/**
 * 表格（P2 阶段 1：表头 + 虚拟行 + 定宽/弹性列 + 斑马行）。
 *
 * ```php
 * Table::make(
 *     [
 *         TableColumn::make('name', '姓名')->field('name')->width(TableColumnWidth::flex(2.0)),
 *         TableColumn::make('email', '邮箱')->field('email'),
 *         TableColumn::make('status', '状态')->cell(fn(User $u): Widget => Badge::make($u->status)),
 *     ],
 *     $users,                       // ★ 原始行，不做转换（见 §11.2）
 * )
 *     ->keyOf(fn(User $u): int => $u->id)   // ★ 必填
 *     ->rowHeight(44.0);
 * ```
 *
 * ## 数据怎么进来（三层，各管一段）
 *
 * ```
 * Subject（业务数据）→ SubjectBuilder::select(fn($s) => $s->visible()) 给出数组
 *                    → Table（只认数组 + 列定义 + keyOf）
 * ```
 *
 * `Table` **不认识 Store** —— 这是"业务层零 GUI 依赖"的镜像。变更传播靠
 * "重建 + 按绝对序号 diff"，三步成本都与总行数无关（数组 COW O(1) / 只造窗口内
 * 行 / 窗口内序号复用）⇒ 不需要 `ObservableList`、不需要 `dataChanged` 通知。
 *
 * ## 它自己就是滚动容器
 *
 * 不是 `ScrollView` 的子节点（`LazyColumn` 的那条纪律同源）：窗口取决于滚动偏移、
 * 偏移取决于视口，两者都在它这里。塞进外层滚动视口会拿到无界主轴 ⇒
 * `RenderTable` 会按"内容高"铺开、窗口变成全部行（虚拟化失效）。
 *
 * ## 诚实边界（阶段 2，`docs/DESIGN-TABLE-2026-10-26.md` §7）
 *
 * | 不做 | 理由 |
 * |---|---|
 * | 变长行高 | 虚拟化要 `first = floor(offset / extent)` 的 O(1) 换算 |
 * | 列合并 / 分组 / 树形 | 破坏"每行同构"，窗口不再是矩形 |
 * | 横向滚动 | 偏移通道已在（`RenderTable::maxScrollX()`），但要等阶段 5 接输入与横条；**列装不下时被裁**（不是无声失效：内容右侧看得到截断） |
 * | 排序 / 列宽拖拽 / 编辑 | 阶段 3~4、7；结构与字段都已预留（`TableColumn::keyName()` 是它们的身份） |
 * | 键盘焦点格 | 阶段 2b（裁决 5：整个表格占一个 Tab 位，方向键在 `TableState` 里移动焦点格） |
 * | 内置勾选列 / 全选 | `cell()` 已能表达（闭包捕获 selection）；内置列会同时牵连列宽、表头全选框、排序箭头与拖拽手柄 ⇒ 等阶段 4 一起裁决 |
 *
 * ★ **子项内不能有"随滚动保留的 UI 态"**：行 Element 在滚出窗口时被回收
 *   （虚拟化的定义）⇒ 单元格里正在编辑的草稿必须外置（同 `LazyColumn` 的纪律）。
 *
 * @api
 */
final class Table extends StatefulWidget
{
    private const DEFAULT_ROW_HEIGHT = 44.0;
    private const DEFAULT_HEADER_HEIGHT = 40.0;

    /** @var list<TableColumn> */
    private array $columns;

    /** @var list<mixed> */
    private array $rows;

    private float $rowHeightPx = self::DEFAULT_ROW_HEIGHT;

    private float $headerHeightPx = self::DEFAULT_HEADER_HEIGHT;

    /** 行身份（`fn(mixed $row): string|int`）—— 必填，见 `keyOf()` */
    private ?\Closure $keyMapper = null;

    private float $columnSpacingPx = 0.0;

    /**
     * 斑马行（奇数行加一层浅底）。
     *
     * ★ **默认开**：行多时没有斑马行的表格读起来是"一片字墙"，而设计上
     *   "不写也要能看"是本仓的既有判据（同 `TableColumn` 的默认 `flex(1)`）。
     *   要纯色调 `striped(false)`。
     */
    private int $stripedOn = 1;

    /**
     * 当前选中集（`null` = **没启用选择**，此时点行不进选择逻辑）。
     *
     * ★ 受控：应用每次 build 递进来，控件只读、只回调新值（§11.4）。
     *   `null` 与 `TableSelection::none()` 不同 —— 前者连"选中"这个概念都不存在
     *   （纯展示表），后者是"启用了但当前没选"。这个区别决定行点击要不要改选择。
     */
    private ?TableSelection $selection = null;

    private TableSelectionMode $selectionMode = TableSelectionMode::Multiple;

    /** 选择变化上报（`fn(TableSelection $next): void`） */
    private ?\Closure $onSelectionChange = null;

    /** 行点击上报（`fn(mixed $row, int $index): void`）—— 与选择**互不替代**，见 `onRowTap()` */
    private ?\Closure $onRowTap = null;

    /**
     * @param list<TableColumn> $columns
     * @param list<mixed>       $rows
     */
    private function __construct(array $columns, array $rows)
    {
        parent::__construct();
        $this->columns = $columns;
        $this->rows = $rows;
        // §9 无障碍：表格是集合角色（滚轮路由走 Scrollable 接口，与语义无关）
        $this->semantics(SemanticsRole::Table);
    }

    /**
     * 唯一入口：列定义 + 行数据。
     *
     * ★ 只有这两个是必需的，其余走链式（本仓全部控件同形：`make()` 收必需、
     *   链式收可选）。命名参数风格在 tpc 里语法支持但 `src/`+`apps/` 零调用
     *   先例 ⇒ 不用未验证的语法写新控件的构造签名。
     *
     * ★★ **列对象会被 clone**：`TableColumn` 是可链式配置的（没有 Widget 那种
     *   `seal()`），调用方可能在 `make()` 之后继续改它、或把同一份列定义用于
     *   第二张表。不快照的话"挂载后的表跟着列对象变"，而这**不报错**。
     */
    public static function make(array $columns, array $rows): self
    {
        if ($columns === []) {
            Errors::report('Table 至少要有一列 —— columns 是空数组');
        }

        /** @var list<TableColumn> $snapshot */
        $snapshot = [];
        foreach ($columns as $index => $column) {
            if (!$column instanceof TableColumn) {
                Errors::report(sprintf(
                    'Table 的第 %d 个列定义不是 TableColumn（是 %s）',
                    $index,
                    \get_debug_type($column),
                ));

                continue;
            }

            $snapshot[] = clone $column;
        }

        $keys = [];
        foreach ($snapshot as $column) {
            $key = $column->keyName();
            if (isset($keys[$key])) {
                Errors::report(sprintf(
                    'Table 的列 key "%s" 重复 —— 它是选择/排序/列宽的唯一身份，必须唯一',
                    $key,
                ));
            }
            $keys[$key] = true;
        }

        return new self($snapshot, \array_values($rows));
    }

    /** 每行高度（逻辑像素）—— ★ **定高是虚拟化的前提**（同 `LazyColumn::itemExtent`） */
    public function rowHeight(float $px): static
    {
        if ($px <= 0.0) {
            Errors::report(sprintf('Table 的 rowHeight 必须 > 0，收到 %s', (string) $px));
        }

        $this->mutate();
        $this->rowHeightPx = $px;

        return $this;
    }

    /** 表头高度（逻辑像素） */
    public function headerHeight(float $px): static
    {
        if ($px <= 0.0) {
            Errors::report(sprintf('Table 的 headerHeight 必须 > 0，收到 %s', (string) $px));
        }

        $this->mutate();
        $this->headerHeightPx = $px;

        return $this;
    }

    /**
     * 行身份（`fn(mixed $row): string|int`）—— **必填**。
     *
     * ★ 三个消费者现在就存在：列宽覆盖的键、未来的选择集与 Shift 锚点；
     *   阶段 5/6 的列虚拟化与阶段 7 的"正在编辑哪一格"还要它。
     * ★ 比 `LazyList::keyOf()` 严（那边不配也能跑，只是锚点退化）：表格的 key
     *   是**状态的身份**，退化不是"跳一下"而是"选择错行"。
     * ★ 同一份数据内必须唯一；不校验是因为校验要 O(n)（同 `LazyList` 的裁决）。
     */
    public function keyOf(callable $keyOf): static
    {
        $this->mutate();
        $this->keyMapper = \Closure::fromCallable($keyOf);

        return $this;
    }

    /** 列与列之间的横向间距（默认 0：单元格自带左右内边距） */
    public function columnSpacing(float $px): static
    {
        if ($px < 0.0) {
            Errors::report(sprintf('Table 的 columnSpacing 不能为负，收到 %s', (string) $px));
        }

        $this->mutate();
        $this->columnSpacingPx = $px;

        return $this;
    }

    /** 斑马行开关（默认开；见 `$stripedOn`） */
    public function striped(bool $on = true): static
    {
        $this->mutate();
        $this->stripedOn = $on ? 1 : 0;

        return $this;
    }

    /**
     * 选中集（**受控**）—— 声明它才启用选择。
     *
     * ★ 不声明 ⇒ 行不可选（纯展示表）：点行只走 `onRowTap()`。这与"声明了但为空"
     *   是两件事 —— 后者是可选的表，只是现在一个都没选。
     * ★ 单选模式下调它只需一个 key：`TableSelection::of([$id])`。
     */
    public function selection(TableSelection $selection): static
    {
        $this->mutate();
        $this->selection = $selection;

        return $this;
    }

    /** 选择模式（默认多选）—— 只在声明了 `selection()` 后有意义 */
    public function selectionMode(TableSelectionMode $mode): static
    {
        $this->mutate();
        $this->selectionMode = $mode;

        return $this;
    }

    /**
     * 选择变化上报（`fn(TableSelection $next): void`）。
     *
     * ★ 控件**不自己改**选择：它算出"点完之后应该是什么"并交出去，应用决定采纳。
     *   不提供上报就等于"点了没反应"（而选中色是控件画的 ⇒ 会一直停在旧值）。
     *   ⇒ 声明了 `selection()` 却没声明它，`TableState` 挂载期响亮失败。
     */
    public function onSelectionChange(callable $handler): static
    {
        $this->mutate();
        $this->onSelectionChange = \Closure::fromCallable($handler);

        return $this;
    }

    /**
     * 行点击上报（`fn(mixed $row, int $index): void`）。
     *
     * ★ **与选择互不替代**：启用选择时，点行仍然会先按模式算选择变化并上报
     *   （这是表格该有的默认行为）；`onRowTap()` 是"还想干点别的"（打开详情 /
     *   导航）。只想处理点击、不要选择 ⇒ 不声明 `selection()`。
     */
    public function onRowTap(callable $handler): static
    {
        $this->mutate();
        $this->onRowTap = \Closure::fromCallable($handler);

        return $this;
    }

    public function createState(): State
    {
        return new TableState();
    }

    /** @return list<TableColumn> */
    public function columnList(): array
    {
        return $this->columns;
    }

    /** @return list<mixed> */
    public function rowList(): array
    {
        return $this->rows;
    }

    public function rowHeightPx(): float
    {
        return $this->rowHeightPx;
    }

    public function headerHeightPx(): float
    {
        return $this->headerHeightPx;
    }

    /** @internal 行身份（`null` = 没配 —— `TableState` 会响亮失败） */
    public function keyMapper(): ?\Closure
    {
        return $this->keyMapper;
    }

    public function columnSpacingPx(): float
    {
        return $this->columnSpacingPx;
    }

    public function isStriped(): bool
    {
        return $this->stripedOn === 1;
    }

    /** 当前选中集（`null` = 未启用选择） */
    public function selectionSet(): ?TableSelection
    {
        return $this->selection;
    }

    public function selectionModeValue(): TableSelectionMode
    {
        return $this->selectionMode;
    }

    /** @internal 点击后的新选中集往这里交（`null` = 应用没接） */
    public function selectionChangeHandler(): ?\Closure
    {
        return $this->onSelectionChange;
    }

    /** @internal 行点击回调（`null` = 不关心） */
    public function rowTapHandler(): ?\Closure
    {
        return $this->onRowTap;
    }
}
