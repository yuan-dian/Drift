<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Core\Literal;
use Drift\Core\Readable;
use Drift\Errors;
use Drift\Input\KeyEvent;
use Drift\Input\SemanticsRole;

/**
 * 表格布局核心：列宽计算 + 单元格定位 + 虚拟行窗口。
 * 业务数据以数组进入，变更靠"重建 + 按绝对序号 diff"，与总行数无关。
 *
 * ★ 子项内不能有"随滚动保留的 UI 态"：行 Element 滚出窗口即被回收，
 *   编辑草稿必须住在 `TableState`（不随行回收）。
 *
 * @api
 */
final class Table extends StatefulWidget
{
    /** 窗口缓冲带（逻辑像素）—— 滚出余量才重建窗口 */
    private float $cacheExtentPx = 200.0;

    /** @var list<TableColumn> */
    private array $columns;

    /** @var list<mixed> */
    private array $rows;

    /**
     * `null` = 没显式设置 ⇒ 回落主题（§43）。
     *
     * ★ 用可空而不是"设个默认数再比大小"：后者必须记住"这个数是不是用户设的"，
     *   一旦主题与默认数恰好相等就分不清 —— 而两者相等恰恰是常态。
     */
    private ?float $rowHeightPx = null;

    private ?float $headerHeightPx = null;

    /** 行身份（`fn(mixed $row): string|int`）—— 必填 */
    private ?\Closure $keyMapper = null;

    private float $columnSpacingPx = 0.0;

    /** 斑马行（默认开） */
    private int $stripedOn = 1;

    /**
     * 选中集来源（`null` = 没启用选择）。
     *
     * `selection(值)` 包成 {@see Literal}，`selectionFrom(通道)` 收 {@see Readable}，
     * 内部统一从 `selectionSet()` 读。★ 当前调用方仍是 TableState ⇒ 读点没下沉；
     * 要拿到性能收益，得把 selection/focus/hover 三态一起移到细粒度读点壳。
     */
    private ?Readable $selectionSource = null;

    private TableSelectionMode $selectionMode = TableSelectionMode::Multiple;

    /** 选择单位：整行（默认）还是单格 */
    private TableSelectionBehavior $selectionBehavior = TableSelectionBehavior::Rows;

    /** 选择变化上报（`fn(TableSelection $next): void`） */
    private ?\Closure $onSelectionChange = null;

    /** 复制上报（`fn(string $text): void`）—— `Ctrl+C` 交 TSV */
    private ?\Closure $onCopy = null;

    /** 行点击上报（`fn(mixed $row, int $index): void`）—— 与选择互不替代 */
    private ?\Closure $onRowTap = null;

    /** 当前排序状态（受控，默认 `TableSort::none()`） */
    private TableSort $sortState;

    /** 排序变化上报（`fn(TableSort $next): void`） */
    private ?\Closure $onSortChange = null;

    /** 用户拖出的列宽（`列key ⇒ 像素`）—— 受控，按 key 存 */
    private array $columnWidths = [];

    private ?\Closure $onColumnResize = null;

    /** 单元格编辑提交（`fn(rowKey, columnKey, string $value)`） */
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

        $this->semantics(SemanticsRole::Table);

        // 整张表一个 Tab 位：焦点落在表上，光标格是表内状态（虚拟化会回收不可见行）。
        $this->markFocusable();

        // 键盘回调走 `(KeyEvent, Element)` 两参；经 Element 找 State（光标格要跨重建存活，
        // 而 Widget 每次 setState 都是新实例）。
        $this->keyboard(function (KeyEvent $event, Element $node): bool {
            $state = States::of($node);

            return $state instanceof TableState && $state->handleFocusKey($event);
        });
    }

    /**
     * 唯一入口：列定义 + 行数据（其余走链式）。
     *
     * ★ 列对象会被 clone：调用方可能在 `make()` 之后继续改它或复用于第二张表，
     *   不快照的话挂载后的表会跟着列对象变，且不报错。
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

    /**
     * 每行高度（逻辑像素）—— 定高是虚拟化的前提。
     *
     * ★ 不调这个方法 ⇒ 跟随主题 `MetricToken::RowHeight`（§43）。
     *   显式值是**单表覆盖**：同一份主题下某些表要紧凑，不该去改全局表。
     */
    public function rowHeight(float $px): static
    {
        if ($px <= 0.0) {
            Errors::report(sprintf('Table 的 rowHeight 必须 > 0，收到 %s', (string) $px));
        }

        $this->mutate();
        $this->rowHeightPx = $px;

        return $this;
    }

    /** 表头高度（逻辑像素）；未设置 ⇒ 跟随主题 `MetricToken::HeaderHeight` */
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
     * 窗口缓冲带（逻辑像素；默认 200）—— 可视范围外多建一段，
     * 滚出这段余量才重建窗口；`0` 合法（等价旧行为）。
     */
    public function cacheExtent(float $px): static
    {
        if ($px < 0.0) {
            Errors::report(sprintf('Table 的 cacheExtent 不能为负，收到 %s', (string) $px));
        }

        $this->mutate();
        $this->cacheExtentPx = $px;

        return $this;
    }

    /**
     * 行身份（`fn(mixed $row): string|int`）—— 必填。
     *
     * ★ 表格的 key 是**状态的身份**：退化不是"跳一下"而是"选择错行"。
     */
    public function keyOf(callable $keyOf): static
    {
        $this->mutate();
        $this->keyMapper = \Closure::fromCallable($keyOf);

        return $this;
    }

    /** 列间横向间距（默认 0：单元格自带左右内边距） */
    public function columnSpacing(float $px): static
    {
        if ($px < 0.0) {
            Errors::report(sprintf('Table 的 columnSpacing 不能为负，收到 %s', (string) $px));
        }

        $this->mutate();
        $this->columnSpacingPx = $px;

        return $this;
    }

    /** 斑马行开关（默认开） */
    public function striped(bool $on = true): static
    {
        $this->mutate();
        $this->stripedOn = $on ? 1 : 0;

        return $this;
    }

    /**
     * 选中集（受控，值形态）—— 声明它才启用选择。
     * 不声明 = 纯展示表（点行只走 `onRowTap()`）；声明了为空 = 可选的表。
     *
     * ★ 包成 {@see Literal} 走与通道相同的读取路径：切换两种形态不改控件内部。
     *   两种形态目前都由 TableState 读，收窄重建粒度要等细粒度读点壳。
     */
    public function selection(TableSelection $selection): static
    {
        $this->mutate();
        $this->selectionSource = new Literal($selection);

        return $this;
    }

    /**
     * 选中集（**通道形态**）—— 收 `Readable`，与 `selection(值)` 互斥（后调覆盖）。
     *
     * ```php
     * Table::make($cols, $rows)->selectionFrom($this->selection); // Cell<TableSelection>
     * ```
     *
     * ★ 当前 TableState 仍通过 `selectionSet()` 读 ⇒ 读点还在整表 State。
     *   本方法只提供入口；真正收窄要连三态读点一起下沉。
     */
    public function selectionFrom(Readable $source): static
    {
        $this->mutate();
        $this->selectionSource = $source;

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
     * 选择的单位（默认 `Rows`）—— 只在声明了 `selection()` 后有意义。
     * 整行选区与格矩形是同一种值（`TableRange`，列端点为 `null` 即整行）。
     */
    public function selectionBehavior(TableSelectionBehavior $behavior): static
    {
        $this->mutate();
        $this->selectionBehavior = $behavior;

        return $this;
    }

    /**
     * 复制上报（`fn(string $text): void`）—— `Ctrl+C` 交选区为 **TSV**。
     * 文本来源：`copyText()` 优先，其次 `field()` 字段值，都没有 ⇒ 空串。
     * 不声明 ⇒ 不吃 `Ctrl+C`（让给应用自己的快捷键）。
     */
    public function onCopy(callable $handler): static
    {
        $this->mutate();
        $this->onCopy = \Closure::fromCallable($handler);

        return $this;
    }

    /**
     * 选择变化上报（`fn(TableSelection $next): void`）。
     * 控件不自己改选择：算出下一态交出去，应用决定采纳。
     * 声明了 `selection()` 却没声明它 ⇒ `TableState` 挂载期响亮失败。
     */
    public function onSelectionChange(callable $handler): static
    {
        $this->mutate();
        $this->onSelectionChange = \Closure::fromCallable($handler);

        return $this;
    }

    /**
     * 行点击上报（`fn(mixed $row, int $index): void`）。
     * 与选择互不替代：启用选择时仍先按模式算选择并上报；
     * 只想处理点击、不要选择 ⇒ 不声明 `selection()`。
     */
    public function onRowTap(callable $handler): static
    {
        $this->mutate();
        $this->onRowTap = \Closure::fromCallable($handler);

        return $this;
    }

    /**
     * 当前排序状态（受控）—— 只画表头箭头与决定点击后的下一态。
     * 控件不重排 `$rows`：数值/日期/拼音/空值排序全是应用知识。
     */
    public function sort(TableSort $sort): static
    {
        $this->mutate();
        $this->sortState = $sort;

        return $this;
    }

    /**
     * 排序变化上报（`fn(TableSort $next): void`）—— 与 `selection()` 同为受控。
     * 任一列声明了 `sortable()` 却没接它 ⇒ `TableState` 挂载期响亮失败。
     */
    public function onSortChange(callable $handler): static
    {
        $this->mutate();
        $this->onSortChange = \Closure::fromCallable($handler);

        return $this;
    }

    /**
     * 用户拖出的列宽（受控，第 ④ 层，最优先但仍夹 `min/maxWidth`）。
     * 按 key 存：插列/换序后宽度仍落在原来那一列上；空数组 = 全按声明算。
     */
    public function columnWidths(array $overrides): static
    {
        $this->mutate();
        $this->columnWidths = $overrides;

        return $this;
    }

    /**
     * 列宽变化上报（`fn(string $key, float $width, bool $finished): void`）。
     * `$finished` = 本次拖动结束（抬起/拖出窗口），应用可"拖中只 setState、结束才写盘"。
     * 任一列声明 `resizable()` 却没接它 ⇒ 挂载期响亮失败。
     */
    public function onColumnResize(callable $handler): static
    {
        $this->mutate();
        $this->onColumnResize = \Closure::fromCallable($handler);

        return $this;
    }

    /**
     * 单元格编辑提交（`fn(string|int $rowKey, string $columnKey, string $value): void`）。
     * 提交的是"行身份 + 列身份 + 新文本"而非下标：排序/过滤换序后也不会写错行。
     * 值是字符串（类型转换归应用）；只在值真变了时回调；声明 `editable()` 却没接 ⇒ 挂载期失败。
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

    /** `null` = 跟随主题（解析在 `TableState` 的 build 期做，那里才有 ctx） */
    public function rowHeightPx(): ?float
    {
        return $this->rowHeightPx;
    }

    public function headerHeightPx(): ?float
    {
        return $this->headerHeightPx;
    }

    public function cacheExtentPx(): float
    {
        return $this->cacheExtentPx;
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

    /**
     * 当前选中集（`null` = 未启用选择）。
     *
     * ★ `get()` 在调用方的 build 期登记读点。目前调用方是 TableState（整表重建）；
     *   细粒度读点壳尚未实现，别把这里的读取当成已下沉。
     */
    public function selectionSet(): ?TableSelection
    {
        if ($this->selectionSource === null) {
            return null;
        }
        $value = $this->selectionSource->get();

        return $value instanceof TableSelection ? $value : null;
    }

    /** @internal 预留给细粒度读点壳：自行 `get()` 把读点留在壳的 State（值形态是 `Literal`） */
    public function selectionReadable(): ?Readable
    {
        return $this->selectionSource;
    }

    public function selectionModeValue(): TableSelectionMode
    {
        return $this->selectionMode;
    }

    public function selectionBehaviorValue(): TableSelectionBehavior
    {
        return $this->selectionBehavior;
    }

    /** @internal `Ctrl+C` 往这里交 TSV（`null` = 应用没接 ⇒ 不吃这个键） */
    public function copyHandler(): ?\Closure
    {
        return $this->onCopy;
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
