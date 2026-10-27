<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Input\KeyEvent;
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
 * | 横向滚动的**滚轮** | 已通（阶段 5b）：壳把 `WM_MOUSEHWHEEL` 与 Shift+竖轮都翻成 `Axis::Horizontal` ⇒ 横条、横轮、Shift+竖轮三条入口一致 |
 * | **控件内**排序 | 数据所有权不分裂：控件画箭头 + 上报意图，重排由应用做（§13） |
 * | 多列排序的 **UI** | `TableSort::thenBy()` 与"每列各画箭头"结构上已成立，只是点表头只上报单键（Shift+点留给后续） |
 * | 列宽拖拽 / 单元格编辑 | 列宽已做（阶段 4）；单元格编辑已做（阶段 7a，`TableColumn::editable()` + `onCellEdit()`）|
 * | 键盘光标格的**扩展选择** | 方向键移格已做（阶段 2b）；Ctrl/Shift + 方向键扩选要先把选择与光标的关系定死 |
 * | 表头列高亮 | 光标格有框，表头**不**跟着高亮 —— 那是"当前列"的第二种表达，等编辑（阶段 7）有消费者再加 |
 * | 内置勾选列 / 全选 | `cell()` 已能表达（闭包捕获 selection）；内置列会同时牵连列宽、表头全选框、排序箭头与拖拽手柄 ⇒ 等阶段 4 一起裁决 |
 *
 * ★ **子项内不能有"随滚动保留的 UI 态"**：行 Element 在滚出窗口时被回收
 *   （虚拟化的定义）⇒ 单元格里正在编辑的**草稿住在 `TableState`**（不随行回收），
 *   插入符与选区跟着草稿一起（`TextDraft`，与 `TextField` 共用同一份状态机）。
 *
 * ## 单元格编辑（阶段 7a）
 *
 * `TableColumn::editable()` 声明可编辑的列（**只对 `field()` 列**），`Enter` / `F2`
 * 进入编辑；`Enter` / `↑` / `↓` 提交并移动、`Esc` 取消、失焦或滚动提交。
 * 提交经 `onCellEdit(fn($rowKey, $columnKey, string $value))` 交给应用。
 *
 * ★ **编辑会话认 key 不认下标**：排序 / 过滤换了行序之后，编辑器跟着**数据**走
 *   （光标格仍用下标 —— 它是"眼睛在哪"，编辑是"我改的是哪条数据"）。
 * ★ **双击进入编辑不在本阶段**：它要判断两次点击的间隔，而 `PointerEvent` 没有
 *   时间戳（手势族缺时钟，见 `GAP-ANALYSIS` P2-13）。
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

    /** 当前排序状态（**受控**，默认 `TableSort::none()`） */
    private TableSort $sortState;

    /** 排序变化上报（`fn(TableSort $next): void`） */
    private ?\Closure $onSortChange = null;

    /**
     * 用户拖出来的列宽（`列key ⇒ 逻辑像素`）—— **受控**，第 ④ 层宽度。
     *
     * ★ 按 **key** 存而不是列序号：插列/换序之后拖出来的宽度仍落在原来那一列上。
     * ★ 空数组 = 全部按 `TableColumn::width()` 的声明算（第 ①②③ 层）。
     */
    private array $columnWidths = [];

    private ?\Closure $onColumnResize = null;

    /** 单元格编辑提交（`fn(string|int $rowKey, string $columnKey, string $value): void`） */
    private ?\Closure $onCellEdit = null;

    /**
     * @param list<TableColumn> $columns
     * @param list<mixed>       $rows
     */
    private function __construct(array $columns, array $rows)
    {
        parent::__construct();
        $this->columns = $columns;
        $this->rows = $rows;
        $this->sortState = TableSort::none();
        // §9 无障碍：表格是集合角色（滚轮路由走 Scrollable 接口，与语义无关）
        $this->semantics(SemanticsRole::Table);

        // ★ 整张表**一个** Tab 位，不是每格一个（阶段 2b 的核心裁决）：
        //   1000 行 × 5 列 = 5000 个焦点节点，Tab 键就废了；而虚拟化会把不可见行
        //   回收 ⇒ 焦点节点跟着消失（焦点丢在虚空里）。所以"光标格"是表内状态，
        //   焦点本身仍落在表上。
        $this->markFocusable();

        // ★ 声明两参：`FocusManager::dispatchKey()` 给的是 `(KeyEvent, Element)`。
        //   只写一参在 AOT 下当场 Fatal（"expects exactly 1 argument"），且被捕获期
        //   的 try/catch 接住 ⇒ 现象是整页变错误占位（阶段 2 的同一坑）。
        // ★ 经 Element 找 State 而不是让闭包捕 `$this`：光标格必须跨重建存活，
        //   而 Widget 每次 setState 都是新实例（`State::didChangeFocus` 同一理由）。
        $this->keyboard(function (KeyEvent $event, Element $node): bool {
            $state = States::of($node);

            return $state instanceof TableState && $state->handleFocusKey($event);
        });
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

    /**
     * 当前排序状态（**受控**）—— 只用来画表头箭头与决定点击后的下一态。
     *
     * ★ 控件**不重排 `$rows`**：`$row` 是任意值，"数字按数值 / 日期按时间戳 /
     *   中文按拼音 / 空值排前还是排后"全是应用侧知识。控件画箭头 + 上报意图，
     *   应用重排后把新数组传回来（`docs/DESIGN-TABLE-2026-10-26.md` §13）。
     * ★ 多键（`thenBy()`）在结构上已成立：`directionOf()` 对每个参与键都返回方向
     *   ⇒ 每列各画自己的箭头。阶段 3 的 UI 只上报单键（Shift+点表头留给后续）。
     */
    public function sort(TableSort $sort): static
    {
        $this->mutate();
        $this->sortState = $sort;

        return $this;
    }

    /**
     * 排序变化上报（`fn(TableSort $next): void`）—— 与 `selection()` 同为受控。
     *
     * ★ 任一列声明了 `sortable()` 却没接它 ⇒ `TableState` 挂载期响亮失败：
     *   表头可点却不接上报 = 点了没反应，箭头还停在旧值（差异每点一次放大一层）。
     */
    public function onSortChange(callable $handler): static
    {
        $this->mutate();
        $this->onSortChange = \Closure::fromCallable($handler);

        return $this;
    }

    /**
     * 用户拖出来的列宽（**受控**，第 ④ 层，最优先但仍夹 `minWidth`/`maxWidth`）。
     *
     * ```php
     * ->columnWidths(['name' => 220.0])
     * ```
     *
     * ★ 与 `selection()` / `sort()` 同一条哲学：控件不持有这份数据。列宽是**用户
     *   偏好**，应用要能存盘（下次开窗恢复）、要能"重置列宽"——住在控件 `State`
     *   里就再也读不出来了（本仓没有 `GlobalKey`）。
     * ★ 值不在这里夹：`resolveWidths()` 统一走 `TableColumn::clampWidth()`。
     *   在这里夹会让"存盘的宽度"与"应用看到的宽度"不一致。
     *
     * @param array<string, float> $overrides 列 key ⇒ 逻辑像素
     */
    public function columnWidths(array $overrides): static
    {
        $this->mutate();
        $this->columnWidths = $overrides;

        return $this;
    }

    /**
     * 列宽变化上报（`fn(string $key, float $width, bool $finished): void`）。
     *
     * ★ `$finished` = 这一次拖动**结束了**（抬起 / 拖出窗口）。拖动中每次指针移动
     *   都会回调，`$finished` 为 `false` ⇒ 应用可以"拖动中只 `setState`，
     *   结束时才写盘"，不必自己猜抬起时机。
     * ★ 单回调三参数，不是 `onColumnResize` + `onColumnResizeEnd` 两个：两个回调
     *   会造出"必须先接前者才能接后者"的隐式依赖，而应用通常只想要其中一个。
     * ★ 任一列声明了 `resizable()` 却没接它 ⇒ 挂载期响亮失败（同 `sortable()`）。
     */
    public function onColumnResize(callable $handler): static
    {
        $this->mutate();
        $this->onColumnResize = \Closure::fromCallable($handler);

        return $this;
    }

    /**
     * 单元格编辑提交（`fn(string|int $rowKey, string $columnKey, string $value): void`）。
     *
     * ★ **提交的是"一行身份 + 列身份 + 新文本"**，不是行下标：
     *   - 行 key（`keyOf()` 的返回值）⇒ 编辑期间排序/过滤换了行序也不会写错行；
     *   - 列 key ⇒ 插列/换序之后仍落在原来那一列上。
     * ★ 值是**字符串**：表格不知道列的数据类型（int / float / 日期 / 枚举），
     *   类型转换与校验是应用侧知识。这与"控件不排序"同源：数据所有权不分裂。
     * ★ 只在**值真的变了**时回调（同 G2：宁可少报，不可错报）。`Esc` 取消不回调。
     * ★ 任一列声明了 `editable()` 却没接它 ⇒ 挂载期响亮失败（同 `sortable()`）。
     *
     * ```php
     * ->onCellEdit(function (int $id, string $col, string $value) use ($store): void {
     *     $store->rename($id, $col, $value);   // 写库由应用决定
     * })
     * ```
     */
    public function onCellEdit(callable $handler): static
    {
        $this->mutate();
        $this->onCellEdit = \Closure::fromCallable($handler);

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

    /** 当前排序状态（永不为 `null`：没排序时是 `TableSort::none()`） */
    public function sortState(): TableSort
    {
        return $this->sortState;
    }

    /** @internal 点击表头后的下一态往这里交（`null` = 应用没接） */
    /** 用户拖出来的列宽（`列key ⇒ 像素`）；空数组 = 全按声明算 */
    public function columnWidthOverrides(): array
    {
        return $this->columnWidths;
    }

    public function columnResizeHandler(): ?\Closure
    {
        return $this->onColumnResize;
    }

    public function sortChangeHandler(): ?\Closure
    {
        return $this->onSortChange;
    }

    public function cellEditHandler(): ?\Closure
    {
        return $this->onCellEdit;
    }
}
