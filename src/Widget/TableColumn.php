<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Layout\ColumnWidths;
use Drift\Text\TextAlign;

/**
 * 一列的定义（`Table` 的列身份 + 取值 + 宽度 + 对齐），链式配置。
 * `key` 必填且是唯一身份：选择集成员、Shift 连选锚、列宽覆盖键、排序上报键；
 * 用列序号做身份会让插列 / 隐藏列时上述状态集体错位。
 * `field()`（按字段名取）与 `cell()`（自定义闭包）二选一，都写 ⇒ 挂载期响亮失败。
 * 可变但被 `Table` 快照：`Table::make()` 会 clone 每个列，挂载后读快照
 * （列不是 Widget、没有封存位，所以由这里负责）。
 * @api
 */
final class TableColumn
{
    private const float DEFAULT_PADDING_X = 8.0;

    private string $title;

    private ?\Closure $cell = null;

    private ?string $field = null;

    /** `Ctrl+C` 时这一列贡献什么文本（`null` = 取 `field()` 的值，见 `copyTextOf()`） */
    private ?\Closure $copy = null;

    private TableColumnWidth $widthSpec;

    private ?float $minPx = null;

    private ?float $maxPx = null;

    private TextAlign $alignMode = TextAlign::Start;

    private float $paddingX = self::DEFAULT_PADDING_X;

    /**
     * 表头可点排序吗（默认**不可排**）。
     * 默认关而不是开：操作列 / `cell()` 拼出的派生值 / 图片列本就不该排，
     *   而"每个表头都能点"会让用户点了没反应 —— 那比"少一个功能"更差。
     */
    private bool $sortable = false;

    /** 表头右缘可拖动改宽吗（默认关，见 `resizable()`） */
    private bool $resizable = false;

    /**
     * 单元格可原地编辑吗（默认**不可**）。
     * 默认关，与 `sortable()` / `resizable()` 同一条纪律：能编辑就必须有人接收
     *   提交（`Table::onCellEdit()`），否则用户改完一按回车什么都没发生 ——
     *   那比"不能编辑"更糟。声明了却没接 ⇒ 挂载期响亮失败。
     */
    private bool $editable = false;

    private function __construct(
        private readonly string $colKey,
        string $title,
    ) {
        $this->title = $title;
        $this->widthSpec = TableColumnWidth::flex(1.0);
    }

    /**
     * @param string $key    列身份（唯一，且跨插列/隐藏列稳定）
     * @param string $title  表头文案
     */
    public static function make(string $key, string $title): self
    {
        if ($key === '') {
            Errors::report('TableColumn 的 key 不能为空 —— 它是选择/排序/列宽的唯一身份');
        }

        return new self($key, $title);
    }

    /** 关联数组行：按字段名取值（渲染为 `Text`，并记住字段名供排序上报） */
    public function field(string $name): static
    {
        $this->field = $name;

        return $this;
    }

    /**
     * 自定义取值（`fn(mixed $row): Widget`）—— 只收**一行**，不收列号
     * （列号会让一个闭包背负"画哪一列"，退化为 N 分支）。
     */
    public function cell(callable $builder): static
    {
        $this->cell = \Closure::fromCallable($builder);

        return $this;
    }

    /**
     * 复制成文本（`fn(mixed $row): string`）—— `Ctrl+C` 时这一列贡献什么。
     * 只对 `cell()` 列需要：`field()` 列默认取字段值，不必声明；
     * 没声明又没 `field()` ⇒ 空串（框架不猜"怎么把 Widget 变成文本"）。
     */
    public function copyText(callable $handler): static
    {
        $this->copy = \Closure::fromCallable($handler);

        return $this;
    }

    /**
     * 一行在这一列上的**复制文本**（`Ctrl+C` 用）。
     * 与 `buildCell()` 分开：那条路造 Widget（含 Fatal 守卫），复制只要字符串；
     * "纯 `cell()` 列没有文本形态"是合法状态，不该按错误处理。
     */
    public function copyTextOf(mixed $row, int $index): string
    {
        if ($this->copy !== null) {
            return (string) ($this->copy)($row);
        }

        if ($this->field === null) {
            return '';
        }

        return $this->textOf($row, $index);
    }

    public function width(TableColumnWidth $width): static
    {
        $this->widthSpec = $width;

        return $this;
    }

    /** 夹取下界（拖拽 / 压缩 / 计算都不会低于它） */
    public function minWidth(float $px): static
    {
        if ($px < 0.0) {
            Errors::report(sprintf('TableColumn 的 minWidth 不能为负，收到 %s', (string) $px));
        }

        $this->minPx = $px;

        return $this;
    }

    /** 夹取上界（flex 分到的空间再多也不超过它） */
    public function maxWidth(float $px): static
    {
        if ($px <= 0.0) {
            Errors::report(sprintf('TableColumn 的 maxWidth 必须 > 0，收到 %s', (string) $px));
        }

        $this->maxPx = $px;

        return $this;
    }

    /**
     * 单元格内的水平对齐。
     * 用 `TextAlign` 而非 `FlexAlign`：后者是交叉轴对齐（含 `Stretch`），
     * 表格要的是内容在格内的水平位置（`Start`/`Center`/`End` 是书写方向的函数）。
     * 实现是给单元格容器加 `Spacer` 而非改文本样式（`cell()` 给的是任意 Widget）；
     * 因此 `Start` 时零额外包装（绝大多数列），仅 `Center` / `End` 多一层 `Row`。
     */
    public function align(TextAlign $align): static
    {
        $this->alignMode = $align;

        return $this;
    }

    /** 单元格左右内边距（默认 8）—— 表头与单元格共用同一个值，否则文字不齐 */
    public function cellPaddingX(float $px): static
    {
        if ($px < 0.0) {
            Errors::report(sprintf('TableColumn 的 cellPaddingX 不能为负，收到 %s', (string) $px));
        }

        $this->paddingX = $px;

        return $this;
    }

    /**
     * 表头可点排序（默认关）。
     * 控件**不排序**：点击只上报"点完该是什么 `TableSort`"，重排由应用做
     * （`$row` 是任意值，比较规则全在应用侧）。
     * 与 `field()` / `cell()` **互不约束**：`cell()` 的派生列只要应用知道怎么比，一样能排。
     */
    public function sortable(bool $on = true): static
    {
        $this->sortable = $on;

        return $this;
    }

    /**
     * 表头右缘可以拖动改宽（默认关）。
     * 与 `sortable()` 同一条理由默认关：拖了必须有人接（`onColumnResize()`），
     * 不接就显示手柄 ⇒ 用户拖半天列宽不动，比"没有手柄"更糟（挂载期 Fatal）。
     * 拖出来的宽度最优先，但仍夹 `minWidth` / `maxWidth`；
     * `flex` 列被拖后变固定宽，其余 `flex` 列重新分剩余。
     */
    public function resizable(bool $on = true): static
    {
        $this->resizable = $on;

        return $this;
    }

    /**
     * 单元格可原地编辑（默认关）。
     * **只对 `field()` 列有效**：编辑要"起始文本"，而 `cell()` 给的是任意 Widget，
     * 框架既取不出它显示的文字，也不知道提交时写回哪个字段 ⇒ `cell()` 列上开它
     * 挂载期响亮失败并给修法（不是静默不生效）。
     * 提交的是**字符串**（`onCellEdit` 的第三参数）：表格不知列的数据类型，
     * 类型转换 / 校验是应用侧知识（与"控件不排序"同一条：数据所有权不分裂）。
     */
    public function editable(bool $on = true): static
    {
        $this->editable = $on;

        return $this;
    }

    public function keyName(): string
    {
        return $this->colKey;
    }

    public function titleText(): string
    {
        return $this->title;
    }

    /** 声明的字段名（`cell()` 的列为 `null`，排序上报用它） */
    public function fieldName(): ?string
    {
        return $this->field;
    }

    public function widthSpec(): TableColumnWidth
    {
        return $this->widthSpec;
    }

    public function alignMode(): TextAlign
    {
        return $this->alignMode;
    }

    public function paddingX(): float
    {
        return $this->paddingX;
    }

    /** 表头右缘可拖动改宽吗（见 `resizable()`） */
    public function isResizable(): bool
    {
        return $this->resizable;
    }

    /** 表头可点排序吗（见 `sortable()`） */
    public function isSortable(): bool
    {
        return $this->sortable;
    }

    /** 单元格可原地编辑吗（见 `editable()`） */
    public function isEditable(): bool
    {
        return $this->editable;
    }

    /**
     * 进入编辑时的**起始文本**（与静态渲染**同源**：都走 `textOf()`）。
     * 同源是硬要求：两条取值路会让"进入编辑的瞬间文字跳一下"（显示 A、编辑器里 B），
     * 那种差异极难归因。`cell()` 列 ⇒ 响亮失败（见 `editable()`）：框架没有它的文本。
     */
    public function editableText(mixed $row, int $index): string
    {
        if ($this->cell !== null) {
            Errors::report(sprintf(
                'Table 的列 "%s" 声明了 editable()，但它用的是 cell() —— 单元格编辑需要'
                    . "\n" . '"起始文本 + 提交到哪个字段"，而 cell() 只给一个任意 Widget。'
                    . "\n" . '修法：该列改用 field("%s")（或 field(字段名)），或去掉 ->editable()。',
                $this->colKey,
                $this->field ?? '字段名',
            ));
        }

        if ($this->field === null) {
            Errors::report(sprintf(
                'Table 的列 "%s" 既没有 field() 也没有 cell() —— 它不知道要画什么。',
                $this->colKey,
            ));
        }

        return $this->textOf($row, $index);
    }

    /** 声明的 `minWidth()`（`null` = 不夹）。分配算术在 `Layout\ColumnWidths` */
    public function minWidthPx(): ?float
    {
        return $this->minPx;
    }

    /** 声明的 `maxWidth()`（`null` = 不夹） */
    public function maxWidthPx(): ?float
    {
        return $this->maxPx;
    }

    /** 把任意宽度夹进 `[minWidth, maxWidth]` */
    public function clampWidth(float $width): float
    {
        return ColumnWidths::clamp($width, $this->minPx, $this->maxPx);
    }

    /**
     * 造一格的**内容**（不含宽度与内边距 —— 那是 `TableState` 的事）。
     * @param mixed $row   一行原始数据（原样，不转换）
     * @param int   $index 行序号（**只为报错**：定位到第几行）
     */
    public function buildCell(mixed $row, int $index): Widget
    {
        if ($this->cell !== null && $this->field !== null) {
            Errors::report(sprintf(
                'Table 的列 "%s" 同时声明了 field() 与 cell() —— 二选一。'
                    . "\n" . '修法：关联数组行用 field()，对象行 / 派生值用 cell()。',
                $this->colKey,
            ));
        }

        if ($this->cell !== null) {
            return ($this->cell)($row);
        }

        if ($this->field === null) {
            Errors::report(sprintf(
                'Table 的列 "%s" 既没有 field() 也没有 cell() —— 它不知道要画什么。',
                $this->colKey,
            ));
        }

        return Text::make($this->textOf($row, $index));
    }

    /**
     * 按字段名取一行里的文本值。
     * ★ 缺字段是 **Fatal**，不是空字符串：`field('nmae')` 拼错会表现成"整列空白"，
     *   而空白没人来查。报错要带"第几行 / 缺哪个 / 有哪些"—— 缺一个还得翻代码。
     */
    private function textOf(mixed $row, int $index): string
    {
        $field = $this->field ?? '';

        if (!\is_array($row)) {
            Errors::report(sprintf(
                'Table 的列 "%s" 用了 field("%s")，但第 %d 行不是关联数组（%s）。'
                    . "\n" . '修法：对象行 / 派生值改用 cell(fn($row): Widget => ...)。',
                $this->colKey,
                $field,
                $index,
                \get_debug_type($row),
            ));
        }

        if (!\array_key_exists($field, $row)) {
            Errors::report(sprintf(
                'Table 的列 "%s" 取不到字段 "%s"（第 %d 行）。现有字段：%s',
                $this->colKey,
                $field,
                $index,
                \implode(', ', \array_map(static fn(int|string $k): string => (string) $k, \array_keys($row))),
            ));
        }

        $value = $row[$field];
        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        if (\is_bool($value)) {
            return $value ? '是' : '否';
        }

        if ($value === null) {
            return '';
        }

        Errors::report(sprintf(
            'Table 的列 "%s" 的字段 "%s"（第 %d 行）类型是 %s，不能直接当文本。'
                . "\n" . '修法：改用 cell(fn($row): Widget => Text::make(...)) 自己格式化。',
            $this->colKey,
            $field,
            $index,
            \get_debug_type($value),
        ));

        return '';
    }
}
