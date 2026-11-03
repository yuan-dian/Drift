<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Input;

use Drift\App\FocusManager;
use Drift\Dev\Smoke\App\AccelApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Widget\Element;
use Drift\Widget\StatefulElement;
use Drift\Widget\Table;
use Drift\Widget\TableColumn;
use Drift\Widget\TableSelection;
use Drift\Widget\TextField;
use Drift\Widget\TextFieldState;

/**
 * 按键路由的**顺序**：焦点链 vs 加速键（§9.1）。
 *
 * ## 2026-11-03：顺序反过来，判据跟着翻
 *
 * 此前是「加速键先、靠 `FocusNode::claimKeys()` 白名单给个别控件开口子」。
 * 白名单是**逐键列举**的 ⇒ 列不全就是死键，而且表达不了"只在编辑时才抢"。
 * 现在改成「**焦点链先，谁处理谁赢**」：
 *
 * 1. 焦点控件认得这个键 ⇒ 它吃，加速键**收不到**；
 * 2. 焦点控件返回 `false`（认不得）⇒ 落加速键；
 * 3. 焦点控件认领但**此刻不处理**（表格不在编辑态）⇒ 落加速键。
 *
 * 三条必须**成对**验：只验第 1 条漏掉"控件把所有键都吞了"；只验第 2 条
 * 漏掉"控件根本收不到键"（`Table` 的 `Ctrl+A` 就死在这里很久）。
 */
final class KeyRoutingPriorityTest extends SmokeTestCase
{
    public function testFocusChainWinsWhenItHandlesTheKey(): void
    {
        $app = new AccelApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));

        // 没焦点 ⇒ 加速键命中
        $app->dispatchInput(KeyEvent::down(KeyChord::ctrl('R')));
        check(
            '顺序 · 无焦点时加速键照常命中（Ctrl+R ⇒ accel.fire）',
            $app->seen === ['accel.fire'],
            'seen=[' . implode(',', $app->seen) . ']',
        );

        // 焦点落到输入框；输入框认领 Ctrl+A ⇒ 全选，加速键不触发
        $app->dispatchInput(KeyEvent::down(KeyChord::tab()));
        $app->dispatchInput(KeyEvent::down(KeyChord::ctrl('A')));

        $state = $this->fieldState($app);
        check(
            '顺序 · 焦点在输入框 ⇒ Ctrl+A 归它（全选发生、accel.selectAll 不触发）',
            $app->seen === ['accel.fire'] && $state instanceof TextFieldState
                && $state->selectionStart() === 0 && $state->selectionEnd() === 3,
            'seen=[' . implode(',', $app->seen) . '] sel=' . ($state instanceof TextFieldState
                ? $state->selectionStart() . '..' . $state->selectionEnd()
                : 'n/a'),
        );

        // 输入框不认 Ctrl+R ⇒ 仍然落给加速键（焦点优先 ≠ 焦点通吃）
        $app->dispatchInput(KeyEvent::down(KeyChord::ctrl('R')));
        check(
            '顺序 · 输入框认不得的 Ctrl+R 仍然落给加速键',
            $app->seen === ['accel.fire', 'accel.fire'],
            'seen=[' . implode(',', $app->seen) . ']',
        );

        $this->flushChecks();
    }

    /**
     * 表格是"认领但看状态"的那个形状 —— 也是本轮顺序裁决动的第一因。
     *
     * ★ 此前 `Table` 没声明 `claimKeys()` ⇒ 非编辑态的 `Ctrl+A`（全选所有行）
     *   一直是**死码**：应用只要绑了 `Ctrl+A`，表里按了就没反应，而且不报错。
     *   这条判据钉的是"表真的收到了键"，而不是"表声明了要这个键"。
     */
    public function testTableReceivesKeysItHandles(): void
    {
        $picked = [];
        $table = Table::make(
            [
                TableColumn::make('name', '名称')->field('name'),
                TableColumn::make('age', '年龄')->field('age'),
            ],
            [['name' => '甲', 'age' => '1'], ['name' => '乙', 'age' => '2']],
        )
            // `keyOf` 不是可选的：选择 / 键盘全选都要行身份（缺了是 FATAL，不是静默）
            ->keyOf(static fn(array $row): string => (string) $row['name'])
            ->selection(TableSelection::none())
            ->onSelectionChange(static function (TableSelection $next) use (&$picked): void {
                $picked[] = $next;
            });

        $root = $table->createElement();
        $root->mount(null, smokeContext());
        $fm = new FocusManager();
        $fm->collect([$root]);
        $fm->focusNext();

        $ate = $fm->dispatchKey(KeyEvent::down(KeyChord::ctrl('A')));
        $last = $picked === [] ? null : $picked[\count($picked) - 1];
        check(
            '顺序 · 表格非编辑态收得到 Ctrl+A（全选所有行 ⇒ 回调收到两行）',
            $ate && $last instanceof TableSelection && \count($last->keys()) === 2,
            'ate=' . var_export($ate, true) . ' picked=' . \count($picked) . ' keys='
                . ($last instanceof TableSelection ? \count($last->keys()) : 'n/a'),
        );

        // 反例：表不认的键必须放行（否则应用加速键在表里全废）
        check(
            '顺序 · 表格认不得的 Ctrl+R 放行',
            !$fm->dispatchKey(KeyEvent::down(KeyChord::ctrl('R'))),
        );

        $this->flushChecks();
    }

    /**
     * 表格**编辑态**才是本轮改动的原始动机：`Ctrl+Z` 必须撤销单元格草稿，
     * 而**非编辑态**必须放行给应用（"撤销删除行"那种）。
     *
     * ★★ 这一对正是 `claimKeys()` 做不到的事：静态声明要么永远抢（非编辑态也抢），
     *   要么永远不抢（编辑态也没份）。要"进编辑才抢"就得让键归**处理者**而不是
     *   归**声明**——所以判据必须同时钉住两半，只钉一半都能靠"永远抢"或
     *   "永远不抢"蒙过去。
     */
    public function testTableCellUndoFollowsEditingState(): void
    {
        $commits = [];
        $table = Table::make(
            [TableColumn::make('name', '名称')->field('name')->editable()],
            [['name' => '甲']],
        )
            ->keyOf(static fn(array $row): string => (string) $row['name'])
            ->onCellEdit(static function (string $rowKey, string $col, string $value) use (&$commits): void {
                $commits[] = $value;
            });

        $root = $table->createElement();
        $root->mount(null, smokeContext());
        $fm = new FocusManager();
        $fm->collect([$root]);
        $fm->focusNext();

        // ① 非编辑态：Ctrl+Z 不归表 ⇒ 返回 false（放行给加速键）
        check(
            '编辑态 · 非编辑时 Ctrl+Z 放行（应用才能把它绑成撤销删除行）',
            !$fm->dispatchKey(KeyEvent::down(KeyChord::ctrl('Z'))),
        );

        // ② 对照轮：进编辑 → 全选替成 X → 提交 ⇒ 回调收到 X（证明链路会回调）
        $fm->dispatchKey(KeyEvent::down(KeyChord::enter()));
        $fm->dispatchKey(KeyEvent::down(KeyChord::ctrl('A')));
        $fm->dispatchKey(KeyEvent::character('X'));
        $fm->dispatchKey(KeyEvent::down(KeyChord::enter()));
        check(
            '编辑态 · 对照：不撤销时提交收到 X（对话真的会回调）',
            $commits === ['X'],
            'commits=' . json_encode($commits, JSON_UNESCAPED_UNICODE),
        );

        // ③ 撤销轮：同样的输入，中途 Ctrl+Z ⇒ 草稿回到「甲」⇒ 值没变 ⇒ 不回调
        $fm->dispatchKey(KeyEvent::down(KeyChord::enter()));
        $fm->dispatchKey(KeyEvent::down(KeyChord::ctrl('A')));
        $fm->dispatchKey(KeyEvent::character('X'));
        $ate = $fm->dispatchKey(KeyEvent::down(KeyChord::ctrl('Z')));
        $fm->dispatchKey(KeyEvent::down(KeyChord::enter()));
        check(
            '编辑态 · 编辑中 Ctrl+Z 撤销草稿（表吃掉它、提交回原值 ⇒ 不回调）',
            $ate && $commits === ['X'],
            'ate=' . var_export($ate, true) . ' commits=' . json_encode($commits, JSON_UNESCAPED_UNICODE),
        );

        $this->flushChecks();
    }

    private function fieldState(AccelApplication $app): mixed
    {
        $el = findElement($app->rootElement(), static fn(Element $e): bool => $e->widget() instanceof TextField);

        return $el instanceof StatefulElement ? $el->state() : null;
    }
}
