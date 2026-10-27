<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Text\TextAlign;

/**
 * 一列的定义（`Table` 的列身份 + 取值 + 宽度 + 对齐）。
 *
 * ```php
 * TableColumn::make('name', '姓名')
 *     ->field('name')                       // 关联数组行：按字段名取
 *     ->width(TableColumnWidth::flex(2.0))
 *     ->minWidth(80.0)
 *     ->align(TextAlign::End);
 *
 * TableColumn::make('status', '状态')
 *     ->cell(fn(User $u): Widget => Badge::make($u->status))   // 对象行 / 派生值：给闭包
 *     ->align(TextAlign::Center);
 * ```
 *
 * ## `key` 必填，而且是**唯一身份**
 *
 * 它同时是：选择集的成员判定、Shift 连选的锚、列宽覆盖的键、（阶段 3）排序上报的
 * 那个 key。用**列序号**做身份的话，插一列 / 隐藏一列会让上述状态集体错位
 * （`Each` 的 key 纪律同源，`04` §16.6.1）。
 *
 * ## `field()` 与 `cell()` 二选一，**不能都写**
 *
 * - `field('name')` ⇒ 默认渲染 `Text::make(行['name'])`，并**记住字段名**
 *   （阶段 3 的排序上报要它）。
 * - `cell(fn($row))` ⇒ 完全自定义（对象行、格式化、Badge、两列拼接）。
 *
 * 两个都写 ⇒ 挂载期响亮失败。"后写的赢"看起来更方便，但结果是"我改了
 * `field()` 却没生效"这种**静默**问题（先 `cell()` 后 `field()` 会丢掉闭包）。
 *
 * ★ 为什么不学 `Option::fromRows()` 做一层"行 → TableRow"的适配：`Option`
 *   只有 label+value 两个字段、几十条、一次性转换无损；表格行有几十个字段、
 *   上万条、每次 build 都在 —— 那层转换会把虚拟化的收益原样吃掉，而且要么丢
 *   未声明的字段（排序键/详情要的），要么做成通用 bag（等于没转）。
 *   完整论证见 `docs/DESIGN-TABLE-2026-10-26.md` §11.2。
 *
 * ## 可变，但被 `Table` 快照
 *
 * 链式配置需要 setter；而列对象常被复用（同一份列定义造两张表）或 `make()`
 * 之后继续改。⇒ `Table::make()` 会 **clone 每个列**，挂载后的表读的是快照。
 * 这是"列不是 Widget、没有封存位"的补丁，写在这里是因为它是**列自己的契约**。
 *
 * @api
 */
final class TableColumn
{
    private const DEFAULT_PADDING_X = 8.0;

    private string $title;

    private ?\Closure $cell = null;

    private ?string $field = null;

    private TableColumnWidth $widthSpec;

    private ?float $minPx = null;

    private ?float $maxPx = null;

    private TextAlign $alignMode = TextAlign::Start;

    private float $paddingX = self::DEFAULT_PADDING_X;

    /**
     * 表头可点排序吗（默认**不可排**）。
     *
     * ★ 默认关而不是开：有些列不该排（操作列、`cell()` 拼出来的派生值、图片列），
     *   而"每个表头都能点"会让用户点了没反应 —— 那比"少一个功能"更差。
     */
    private bool $sortable = false;

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
     * 自定义取值（`fn(mixed $row): Widget`）—— 只收**一行**，不收列号。
     *
     * ★ 不收列号是有意的：列号会让同一个闭包承担"我该画哪一列"的职责 ⇒
     *   一个闭包 N 个分支，正是"先做简单版、后面重写"的起点。
     */
    public function cell(callable $builder): static
    {
        $this->cell = \Closure::fromCallable($builder);

        return $this;
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
     *
     * ★ 用 `TextAlign` 而不是 `FlexAlign`：后者是**交叉轴**对齐（含 `Stretch`），
     *   表格要的是"内容在这一格里的水平位置"。复用已有的 `TextAlign` 语义
     *   （`Start` / `Center` / `End` 是书写方向的函数，不是屏幕方向的）。
     *
     * ★ 实现是"给单元格容器加一个 `Spacer`"而不是改文本样式：`cell()` 里
     *   给的是任意 Widget，改不到它内部的 `Text`。★ 因此 `Start` 时**零额外
     *   包装**（绝大多数列），只有 `Center` / `End` 才多一层 `Row`。
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
     *
     * ★ 控件**不排序**：点击只上报"点完之后该是什么 `TableSort`"，重排由应用做
     *   （`$row` 是任意值，数字/日期/拼音/空值的比较规则全是应用侧知识）。
     * ★ `sortable()` 与 `field()` / `cell()` **互不约束**：`cell()` 的派生列只要
     *   应用知道怎么比，一样能排。
     */
    public function sortable(bool $on = true): static
    {
        $this->sortable = $on;

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

    /** 声明的字段名（`cell()` 的列为 `null`；阶段 3 的排序上报用它） */
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

    /** 表头可点排序吗（见 `sortable()`） */
    public function isSortable(): bool
    {
        return $this->sortable;
    }

    /** 把任意宽度夹进 `[minWidth, maxWidth]`（三层宽度里的第 ② 层） */
    public function clampWidth(float $width): float
    {
        $w = max(0.0, $width);

        if ($this->minPx !== null) {
            $w = max($this->minPx, $w);
        }

        if ($this->maxPx !== null) {
            $w = min($this->maxPx, $w);
        }

        return $w;
    }

    /**
     * 造一格的**内容**（不含宽度与内边距 —— 那是 `TableState` 的事）。
     *
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
     *
     * ★ 缺字段是 **Fatal**，不是空字符串：`field('nmae')` 这种拼错会表现成
     *   "整列空白"，而空白不会有人来查。报错要带"第几行 / 缺哪个 / 有哪些"
     *   —— 三个信息缺一个都还得再去翻代码。
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
