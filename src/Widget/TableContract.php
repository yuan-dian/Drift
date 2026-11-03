<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;

/**
 * `Table` 的**声明契约**：哪些写法在挂载期就该响亮失败，以及失败时说哪句话。
 *
 * ## 为什么从 `TableState` 搬出来
 *
 * 这些检查原本散在 `TableState::assertInteractivityDeclared()` 和 `build()` 开头：
 * 「声明了能力却没接上报」一共四个实例（selection / sortable / resizable /
 * editable），**逐段手写四遍**。而 `ConfigFailureTest` 早就用一张表驱动这四个
 * case —— 同一个模式，测试侧收口了、生产侧没收。
 *
 * 后果不是"难看"，是**加第五种能力时要改五处，漏掉检查的那处不报错**：用户
 * 声明了能力、点下去没反应、界面完全正常。这是本仓最贵的一族（G2）。
 *
 * ## 这张表是唯一一份
 *
 * `capabilities()` 是能力名单，每条走 `read()` 的 `match` 取"声明 / 上报 / 修法"。
 * 加一种能力：`capabilities()` 加一项 ⇒ `match` 没有对应分支会当场
 * `UnhandledMatchError`（不是静默跳过），再往 `tests/Support/fatal_cases.php`
 * 加一个 case ⇒ `ConfigFailureTest` 断言两边一一对应。
 *
 * @internal 应用只认 `Table`
 */
final class TableContract
{
    /**
     * 受控能力（名字与"列上声明用的方法名"一致，报错文本直接用它）。
     *
     * @return list<string>
     */
    public static function capabilities(): array
    {
        return ['selection', 'sortable', 'resizable', 'editable'];
    }

    /**
     * 挂载期检查。任何一条不满足 ⇒ `Errors::report()`（退出码 3）。
     *
     * ★ 顺序有讲究：**结构问题先报**（`cell()` 列声明 editable）—— 它比"忘了接
     *   上报"更彻底，用户改完前者可能根本不需要后者。
     *
     * @param list<TableColumn> $columns
     */
    public static function assert(Table $view, array $columns): void
    {
        self::assertKeyOf($view);
        self::assertEditableColumnsHaveField($columns);

        foreach (self::capabilities() as $capability) {
            [$declared, $owner, $reported, $handler, $fix] = self::read($capability, $view, $columns);
            if (!$declared || $reported) {
                continue;
            }

            Errors::report(sprintf(
                'Table %s声明了 %s() 却没有 %s() —— 该能力是受控的：控件只算"操作完该是什么"'
                    . "\n" . '并交出去，应用不接就等于操作了没反应（界面看着完全正常，只有用户点了才发现）。'
                    . "\n" . '修法：加上 ->%s，或去掉 ->%s()。',
                $owner === null ? '' : sprintf('的列 "%s" ', $owner),
                $capability,
                $handler,
                $fix,
                $capability,
            ));
        }

        self::assertSortTargetsExist($view, $columns);
    }

    /**
     * 一种能力的四个事实：声明了吗 / 声明者是谁 / 接上报了吗 / 缺的话怎么改。
     *
     * @param list<TableColumn> $columns
     * @return array{0: bool, 1: string|null, 2: bool, 3: string, 4: string}
     */
    private static function read(string $capability, Table $view, array $columns): array
    {
        return match ($capability) {
            // 表级声明：没有"哪一列"，文案里就不带列名。
            'selection' => [
                $view->selectionSet() !== null,
                null,
                $view->selectionChangeHandler() !== null,
                'onSelectionChange',
                'onSelectionChange(fn(TableSelection $next) => ...)',
            ],
            'sortable' => self::columnCapability($columns,
                static fn(TableColumn $c): bool => $c->isSortable(),
                $view->sortChangeHandler() !== null,
                'onSortChange',
                'onSortChange(fn(TableSort $next) => ...)'),
            'resizable' => self::columnCapability($columns,
                static fn(TableColumn $c): bool => $c->isResizable(),
                $view->columnResizeHandler() !== null,
                'onColumnResize',
                'onColumnResize(fn(string $key, float $w, bool $done) => ...)'),
            'editable' => self::columnCapability($columns,
                static fn(TableColumn $c): bool => $c->isEditable(),
                $view->cellEditHandler() !== null,
                'onCellEdit',
                'onCellEdit(fn($rowKey, string $col, string $value) => ...)'),
        };
    }

    /**
     * 列级能力：只报**第一个**声明它的列（同一个能力在多列上声明是合法的，
     * 报第一个就够用户定位）。
     *
     * @param list<TableColumn> $columns
     * @param \Closure(TableColumn): bool $declares
     * @return array{0: bool, 1: string|null, 2: bool, 3: string, 4: string}
     */
    private static function columnCapability(
        array $columns,
        \Closure $declares,
        bool $reported,
        string $handler,
        string $fix,
    ): array {
        foreach ($columns as $column) {
            if ($declares($column)) {
                return [true, $column->keyName(), $reported, $handler, $fix];
            }
        }

        return [false, null, $reported, $handler, $fix];
    }

    /**
     * `keyOf` 必填：它是列宽覆盖 / 选择集 / Shift 锚点的**唯一身份**。
     *
     * ★ 退化成"按序号当身份"时，数据一变选中行就指向另一行 —— 界面上完全正常。
     */
    private static function assertKeyOf(Table $view): void
    {
        if ($view->keyMapper() !== null) {
            return;
        }

        Errors::report(
            'Table 没有 keyOf —— 必须调 keyOf(fn(mixed $row): string|int => ...)。'
                . "\n" . '理由：它是列宽覆盖、选择集、Shift 锚点的唯一身份（阶段 2~4 全靠它）。'
        );
    }

    /**
     * `cell()` 列不能编辑：编辑要"起始文本 + 提交到哪个字段"，而 `cell()` 只给
     * 一个任意 Widget。
     *
     * ★ 在挂载期就说 —— 等到用户按 Enter 才报，那时候他已经在等结果了。
     *
     * @param list<TableColumn> $columns
     */
    private static function assertEditableColumnsHaveField(array $columns): void
    {
        foreach ($columns as $column) {
            if (!$column->isEditable() || $column->fieldName() !== null) {
                continue;
            }

            Errors::report(sprintf(
                'Table 的列 "%s" 声明了 editable()，但它没有 field() —— 单元格编辑要'
                    . "\n" . '"起始文本 + 提交到哪个字段"，而 cell() 只给一个任意 Widget。'
                    . "\n" . '修法：该列改用 field("字段名")，或去掉 ->editable()。',
                $column->keyName(),
            ));
        }
    }

    /**
     * `sort()` 指向不存在的列 ⇒ 排序箭头永远画不出来，而应用侧照做 ——
     * 用户看到的是"数据变了、箭头没动"。
     *
     * ★ 反过来**不**检查 `columnWidths()` 里的多余 key：那是**历史偏好**（列删了、
     *   存盘的旧值还在），静默忽略才是对的。两条的差别在"这是历史还是现在"。
     *
     * @param list<TableColumn> $columns
     */
    private static function assertSortTargetsExist(Table $view, array $columns): void
    {
        $known = [];
        foreach ($columns as $column) {
            $known[$column->keyName()] = true;
        }

        foreach ($view->sortState()->keys() as [$key, $_direction]) {
            if (isset($known[$key])) {
                continue;
            }

            Errors::report(sprintf(
                'Table 的排序指向了不存在的列 "%s"。现有列：%s',
                $key,
                \implode(', ', \array_keys($known)),
            ));
        }
    }
}
