<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Input\SemanticsTree;
use Drift\Widget\Element;
use Drift\Widget\SemanticsBuilder;
use Drift\Widget\Table;
use Drift\Widget\TableSelection;
use Drift\Widget\TableSelectionBehavior;

/**
 * 表格行的语义（§66）—— 读屏能逐行问「这行选中了吗」。
 *
 * ★★ 判据反同源：选中集由测试**显式构造**（`TableSelection::of()`），
 *   绝不用 `TableState::$focusedRow` 当"选中"。光标 ≠ 选中；若实现改成
 *   "光标在哪就报哪行选中"，②③ 立刻红。§60「中键当右键」是同一个形状：
 *   判据与实现同源时一起错、一起绿。
 * ★ 只验 PHP 侧语义树。UIA 客户端侧的 `IsSelected` / `Select()` 由
 *   `tools/probe-uia-state.py` 在 AOT 真机上验（dev 壳冻结，验不了 C++ 改动）。
 */
final class RowSemanticsTest extends SmokeTestCase
{
    /** 两次 settle：行是 build 期按视口造的，只 settle 一次可能还没有行（§65 同坑）。 */
    private function lay(Table $table): Element
    {
        $el = $table->createElement();
        $el->mount(null, smokeContext());
        $el->settle();
        $el->effectiveBox()?->layout(Fixtures::tight());
        $el->settle();

        return $el;
    }

    /** @return list<array{name:string,key:string,sel:int,parent:int,role:string}> */
    private function rows(SemanticsTree $tree): array
    {
        $out = [];
        for ($i = 0; $i < $tree->count(); $i++) {
            $n = $tree->node($i);
            if ($n->role !== 'row') {
                continue;
            }
            $out[] = [
                'name' => $n->name,
                'key' => $n->key,
                'sel' => $n->selectState,
                'parent' => $n->parent,
                'role' => $n->role,
            ];
        }

        return $out;
    }

    /** @return array<string, int> name ⇒ selectState */
    private function states(SemanticsTree $tree): array
    {
        $out = [];
        foreach ($this->rows($tree) as $r) {
            $out[$r['name']] = $r['sel'];
        }

        return $out;
    }

    private function table(TableSelection $sel, ?TableSelectionBehavior $behavior = null): Table
    {
        $t = Fixtures::selectable($sel, null, new SelectionSink());
        if ($behavior !== null) {
            $t->selectionBehavior($behavior);
        }

        return $t;
    }

    private function dump(array $s): string
    {
        return (string) json_encode($s, JSON_UNESCAPED_UNICODE);
    }

    // ① 前置自证：可选中表真的产出了行节点，角色/名字/key/父链都对
    public function testRowsExistWithRoleAndStableKey(): void
    {
        $tree = SemanticsBuilder::build($this->lay($this->table(TableSelection::of([3]))));
        $rows = $this->rows($tree);

        check(
            '表格语义 · 前置：视口内产出了行节点（扫描自证扫到了东西）',
            \count($rows) >= 3,
            'rows=' . \count($rows),
        );
        check(
            '表格语义 · 行角色是 row、名字是「行 N」（不是 button / 布局盒）',
            $rows !== [] && $rows[0]['role'] === 'row' && $rows[0]['name'] === '行 1',
            $rows === [] ? 'no rows' : $rows[0]['name'] . '/' . $rows[0]['role'],
        );
        check(
            '表格语义 · 每行导出非空 key（C++ 按 key 跨帧比选中态，空 key = 事件永不触发）',
            $rows !== [] && $rows[0]['key'] !== '',
            $rows === [] ? 'no rows' : 'key=' . $rows[0]['key'],
        );

        $tableIdx = -1;
        for ($i = 0; $i < $tree->count(); $i++) {
            if ($tree->node($i)->role === 'table') {
                $tableIdx = $i;
                break;
            }
        }
        check(
            '表格语义 · 行挂在 table 节点下（UIA 树层级），不是散在根上',
            $tableIdx >= 0 && $rows !== [] && $rows[0]['parent'] === $tableIdx,
            'tableIdx=' . $tableIdx . ' rowParent=' . ($rows[0]['parent'] ?? 'none'),
        );

        $this->flushChecks();
    }

    // ② 核心：按数据 key 判选中，不是按光标位置
    public function testSelectedFollowsDataKeyNotCaret(): void
    {
        $tree = SemanticsBuilder::build($this->lay($this->table(TableSelection::of([3]))));
        $s = $this->states($tree);

        check(
            '表格语义 · 选中 id=3 ⇒ 只有「行 4」是 2（拿 focusedRow 冒充会报「行 1」）',
            ($s['行 4'] ?? null) === 2
                && ($s['行 1'] ?? null) === 1
                && ($s['行 2'] ?? null) === 1
                && ($s['行 3'] ?? null) === 1,
            $this->dump($s),
        );

        $this->flushChecks();
    }

    // ③ 多选 + 换集：值变了语义跟着变（不是只在首帧算一次）
    public function testSelectionChangeIsVisible(): void
    {
        $a = $this->states(SemanticsBuilder::build($this->lay($this->table(TableSelection::of([1, 4])))));
        $b = $this->states(SemanticsBuilder::build($this->lay($this->table(TableSelection::of([0])))));

        check(
            '表格语义 · 多选 [1,4] ⇒ 行 2 / 行 5 是 2，其余 1',
            ($a['行 2'] ?? null) === 2 && ($a['行 5'] ?? null) === 2
                && ($a['行 1'] ?? null) === 1 && ($a['行 3'] ?? null) === 1,
            $this->dump($a),
        );
        check(
            '表格语义 · 换集 [0] ⇒ 只有行 1 是 2，行 2 / 行 5 退回 1',
            ($b['行 1'] ?? null) === 2 && ($b['行 2'] ?? null) === 1
                && ($b['行 5'] ?? null) === 1,
            $this->dump($b),
        );

        $this->flushChecks();
    }

    // ④ 纯展示表：一个 Row 节点都不该有（否则每行都声称"可选中却点不动"）
    public function testPlainTableDeclaresNoRows(): void
    {
        $tree = SemanticsBuilder::build($this->lay(Fixtures::build(6)));
        $rows = $this->rows($tree);
        check(
            '表格语义 · 纯展示表不声明行（selected:false 会挂 Pattern = 假承诺）',
            $rows === [],
            'rows=' . \count($rows),
        );
        $this->flushChecks();
    }

    // ⑤ 格级选择：行节点还在，但不报整行选中（画面只亮两格，读屏不能念整行）
    public function testCellBehaviorDoesNotClaimWholeRow(): void
    {
        $tree = SemanticsBuilder::build($this->lay(
            $this->table(TableSelection::ofCells([2], ['id']), TableSelectionBehavior::Cells)
        ));
        $rows = $this->rows($tree);
        $allZero = $rows !== [];
        foreach ($rows as $r) {
            if ($r['sel'] !== 0) {
                $allZero = false;
            }
        }
        check(
            '表格语义 · 格级行为下行节点存在但 selectState=0（不挂 SelectionItemPattern）',
            $allZero && \count($rows) >= 3,
            'rows=' . \count($rows) . ' states=' . $this->dump($this->states($tree)),
        );
        $this->flushChecks();
    }
}
