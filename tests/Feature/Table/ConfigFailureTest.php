<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Widget\TableContract;

/**
 * `Table` 的**配置面响亮失败** —— 11 个"界面看着正常、某条交互永不发生"的入口。
 *
 * ## 为什么这一类必须单独成文件
 *
 * 它们判的不是几何、不是像素，而是**"错的时候有没有当场喊、喊的话能不能照着改"**。
 * 形态上全都是 `Errors::report()` → `exit(3)`，进程内接不住 ⇒ 只能起子进程
 * （`runFatalCase()` + `tests/Support/fatal_cases.php`）。
 *
 * ## 为什么值得 26 条断言
 *
 * 这一族的失效形态是本仓最贵的一类（G2）：数据绑定拼错 ⇒ 整列空白；`sortable()`
 * 忘接上报 ⇒ 表头可点、点了没反应；`editable()` 忘接 ⇒ 回车后什么都没发生而格子
 * 已经显示新值了。它们都**不报错、不写日志、看起来完全正常**，只有用户点到才发现。
 *
 * ## 两条被钉住的纪律
 *
 * ① **受控声明必须接上报**（`selection` / `sortable` / `resizable` / `editable`）——
 *   四个实例是同一个模式，故用一张表驱动，而不是抄四遍（`controlledCases()`）。
 * ② **报错文案必须带修法**：既说"接哪个上报"，也给"去掉声明"这条退路。
 *   第 ① 条新增一个受控能力时，第 ② 条自动覆盖 —— 这是本文件唯一的"扩展点"。
 *
 * ★ 断言落在**报错文本的具体指路词**上（`去掉 ->resizable()`），不是"日志非空"：
 *   后者在 `Errors` 多写一行废话时照样绿。
 * ★ 每条用例用**自己的日志路径**（`logPath($case)`）：共用一份时，前一个 case 的
 *   文本会让后一个 case 的 `str_contains` 恒真（假绿的经典来源）。
 */
final class ConfigFailureTest extends SmokeTestCase
{
    private function logPath(string $tag): string
    {
        return \sys_get_temp_dir() . '/drift-table-cfg-' . $tag . '-' . \getmypid() . '.log';
    }

    /**
     * 受控声明的四个实例：`[声明的方法, 上报的方法]`。
     *
     * ★ 扩展点：新加一个受控能力（比如 `columnReorder()` / `onColumnReorder`）只往
     *   这里加一行，`testControlledDeclarationsRequireAReport()` 三条断言全自动覆盖。
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function controlledCases(): array
    {
        return [
            'table-selection-noreport' => ['selection', 'onSelectionChange'],
            'table-sort-noreport' => ['sortable', 'onSortChange'],
            'table-resize-noreport' => ['resizable', 'onColumnResize'],
            'table-cell-edit-no-handler' => ['editable', 'onCellEdit'],
        ];
    }

    /** 日志里必须**同时**出现这些片段；缺哪个就报哪个（比"日志非空"能证伪得多）。 */
    private function checkLogContains(string $label, string $text, string ...$tokens): void
    {
        $missing = [];
        foreach ($tokens as $token) {
            if (!\str_contains($text, $token)) {
                $missing[] = $token;
            }
        }
        check(
            $label,
            $missing === [],
            '日志缺 ' . \implode(' / ', $missing) . '（实际 ' . \strlen($text) . ' 字节）'
        );
    }

    /**
     * 契约表里的每种受控能力都必须有一个 fatal case。
     *
     * ★ 这条是上一条的**反向锁**：`TableContract::capabilities()` 加一种能力而这里
     *   没加 case ⇒ 红。两边少一个都藏不住 —— 而漏 case 的后果是"新能力的静默失败
     *   永远测不到"，比漏实现更难发现。
     */
    public function testEveryCapabilityHasAFatalCase(): void
    {
        $covered = \array_column(self::controlledCases(), 0);
        $missing = \array_values(\array_diff(TableContract::capabilities(), $covered));

        check(
            'Table · 契约表的每种受控能力都有 fatal case'
                . ($missing === [] ? '' : '（缺：' . \implode(', ', $missing) . '）'),
            $missing === [],
        );
    }

    /**
     * 受控声明缺上报 ⇒ 四条纪律，一次覆盖四个实例。
     *
     * ★ 第三条断言（`去掉 ->xxx()`）是这一族的**共同契约**：只喊"你缺上报"而不给
     *   "纯展示就别声明"的退路，会让纯展示表被迫写一个空闭包 —— 那正是静默坑的另一种写法。
     */
    public function testControlledDeclarationsRequireAReport(): void
    {
        foreach (self::controlledCases() as $case => [$declared, $handler]) {
            $log = $this->logPath($case);
            fresh($log);
            [$code, $out] = runFatalCase($case, $log);
            $text = logText($log);

            check(
                'Table · ' . $declared . '() 没接 ' . $handler . '() ⇒ 挂载期 Fatal（退出码 3）',
                $code === 3,
                '实际 ' . $code . ' / ' . \trim($out)
            );
            $this->checkLogContains(
                'Table · ' . $declared . '() 缺上报的报错点名 ' . $handler . '()',
                $text,
                $handler
            );
            $this->checkLogContains(
                'Table · ' . $declared . '() 缺上报的报错给出"去掉 ' . $declared . '()"这条退路',
                $text,
                '去掉 ->' . $declared . '()'
            );
        }
    }

    /**
     * 缺 `keyOf` ⇒ Fatal。
     *
     * ★ 若退化成"按序号当身份"，数据一变选中行就指向另一行 —— 界面上完全正常，
     *   这是 `Table` 阶段 2~4（列宽覆盖 / 选择集 / Shift 锚点）全部依赖它。
     */
    public function testMissingKeyOfIsRejected(): void
    {
        $log = $this->logPath('nokey');
        fresh($log);
        [$code, $out] = runFatalCase('table-nokey', $log);
        check('Table · 缺 keyOf ⇒ 挂载期 Fatal（退出码 3）', $code === 3, '实际 ' . $code . ' / ' . \trim($out));
        $this->checkLogContains(
            'Table · 缺 keyOf 的报错给出调用形态与理由（唯一身份）',
            logText($log),
            'keyOf(',
            '唯一身份'
        );
    }

    /** `fixed(0)` ⇒ Fatal：0 宽列会让 flex 分配出现除零 / 零宽格。 */
    public function testZeroColumnWidthIsRejected(): void
    {
        $log = $this->logPath('width0');
        fresh($log);
        [$code, $out] = runFatalCase('table-width-zero', $log);
        check('TableColumnWidth · fixed(0) ⇒ 构造期 Fatal（退出码 3）', $code === 3, '实际 ' . $code . ' / ' . \trim($out));
        $this->checkLogContains(
            'TableColumnWidth · fixed(0) 的报错说明正数约束并回显收到的值',
            logText($log),
            '正数',
            '收到 0'
        );
    }

    /**
     * `field()` 与 `cell()` 同时声明 ⇒ Fatal。
     *
     * ★ "后写的赢"是静默坑：改一行绑定代码，取值路从字段切到闭包而界面无差异。
     */
    public function testFieldAndCellAreMutuallyExclusive(): void
    {
        $log = $this->logPath('fieldcell');
        fresh($log);
        [$code, $out] = runFatalCase('table-field-and-cell', $log);
        check('TableColumn · field + cell 同时声明 ⇒ 挂载期 Fatal（退出码 3）', $code === 3, '实际 ' . $code . ' / ' . \trim($out));
        $this->checkLogContains(
            'TableColumn · field + cell 的报错点名两条取值路并要求二选一',
            logText($log),
            'field()',
            'cell()',
            '二选一'
        );
    }

    /**
     * 字段拼错 ⇒ Fatal，且报出"第几行 / 缺哪个 / 现有哪些"。
     *
     * ★ 这是本族**信息量**最满的一条：三个片段缺一不可 —— 只说"缺字段"仍然要靠猜，
     *   而 WPF `DisplayMemberPath` / antd `dataIndex` 拼错时是静默整列空白。
     */
    public function testMissingFieldNamesRowAndAvailableFields(): void
    {
        $log = $this->logPath('nofield');
        fresh($log);
        [$code, $out] = runFatalCase('table-missing-field', $log);
        check('TableColumn · field 拼错 ⇒ 挂载期 Fatal（不是整列空白）', $code === 3, '实际 ' . $code . ' / ' . \trim($out));
        $this->checkLogContains(
            'TableColumn · 缺字段的报错带"缺哪个 / 第几行 / 现有字段"',
            logText($log),
            'nmae',
            '第 0 行',
            '现有字段：name'
        );
    }

    /**
     * `sort()` 指向不存在的列 ⇒ Fatal 并列出已有列。
     *
     * ★ 失效形态：列 key 拼错 ⇒ 排序箭头永远画不出来，但排序本身（应用侧）照做 ——
     *   用户看到的是"数据变了、箭头没动"。
     */
    public function testSortOnUnknownColumnListsExisting(): void
    {
        $log = $this->logPath('sortunknown');
        fresh($log);
        [$code, $out] = runFatalCase('table-sort-unknown-column', $log);
        check('Table · sort() 指向不存在的列 ⇒ 挂载期 Fatal（退出码 3）', $code === 3, '实际 ' . $code . ' / ' . \trim($out));
        $this->checkLogContains(
            'Table · 列 key 拼错时报"不存在的列"并列出已有列',
            logText($log),
            '不存在的列',
            'nope',
            '现有列：'
        );
    }

    /**
     * `TableSort::by('')` ⇒ Fatal，并指向 `none()`。
     *
     * ★ 空列 key 是列的**身份**缺失，不是"没排序"的另一种写法 —— 后者有
     *   `TableSort::none()`。这条断言钉住报错里那句修法：不给退路的话，应用会写
     *   `by('')` 当"取消排序"，而它每次都炸。
     */
    public function testEmptySortColumnRejected(): void
    {
        $log = $this->logPath('sortempty');
        fresh($log);
        [$code, $out] = runFatalCase('table-sort-empty-column', $log);
        check('TableSort · 空列 key ⇒ Fatal（退出码 3）', $code === 3, '实际 ' . $code . ' / ' . \trim($out));
        $this->checkLogContains(
            'TableSort · 空列 key 的报错说明"不能为空"并指向 TableSort::none()',
            logText($log),
            '不能为空',
            'TableSort::none()'
        );
    }

    /**
     * `cell()` 列声明 `editable()` ⇒ Fatal。
     *
     * ★ 结构上无从谈起：编辑要"起始文本 + 提交到哪个字段"，而 `cell()` 只给一个
     *   任意 Widget。静默接受的话，回车后草稿被丢弃、界面回滚，没有任何提示。
     */
    public function testCellEditOnCellColumnRequiresField(): void
    {
        $log = $this->logPath('editoncell');
        fresh($log);
        [$code, $out] = runFatalCase('table-cell-edit-on-cell-column', $log);
        check('Table · cell() 列声明 editable() ⇒ 挂载期 Fatal（退出码 3）', $code === 3, '实际 ' . $code . ' / ' . \trim($out));
        $this->checkLogContains(
            'Table · cell() 列可编辑的报错给出"改用 field()"的修法',
            logText($log),
            '改用 field(',
            '去掉 ->editable()'
        );
    }
}
