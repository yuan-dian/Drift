<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Input\KeyChord;

/**
 * 编辑会话的生命周期：进 / 提交 / 丢弃 / 失效。
 *
 * ★ 全部走真实路由（`FocusManager::dispatchKey()` → `TableState::handleFocusKey()`），
 *   不直接调 `beginEdit()`：那样验不出"焦点不在表上时不该进编辑"，也验不出
 *   "不可编辑的列必须把 Enter 放行"。
 * ★ 提交报的是 **row key** 不是行下标 —— 所以必须有一条"编辑中行序变了"的用例，
 *   否则"按下标写回"这个 bug 在静态数据上永远不显形。
 *
 * | 动作 | `editLog` | 编辑态 |
 * |---|---|---|
 * | 不可编辑列 Enter | 空 | 不进（键放行）|
 * | 可编辑列 Enter | 空 | 进 |
 * | 打字 + Enter | `[key, 'name', 新值]` | 退出，光标下移 |
 * | 打字 + Esc | 空 | 退出（草稿丢弃）|
 * | 不改直接 Enter | 空（同值不是改动）| 退出 |
 * | 打字 + 失焦 / 滚动 | 有 | 退出 |
 * | 编辑中行序变了 + Enter | 报**原 row key** | 退出 |
 * | 编辑中的行被删 | 空 | 会话自动结束 |
 */
final class EditTest extends SmokeTestCase
{
    /** ② 不可编辑的列：Enter 必须**放行**（吞掉就抢走了应用的自定义语义） */
    public function testEnterOnNonEditableColumnPassesThrough(): void
    {
        $s = EditSession::open();

        // 光标初始在 (0,0) = id 列（不可编辑）
        $ate = $s->chord(KeyChord::enter());

        check(
            'Table · 编辑：不可编辑列上 Enter ⇒ **不吃键**、不进编辑',
            $ate === false && !$s->isEditing(),
            'ate=' . ($ate ? 'y' : 'n') . ' editing=' . ($s->isEditing() ? 'y' : 'n'),
        );

        $this->flushChecks();
    }

    /** ③ 可编辑列：Enter 进编辑；编辑盒与静态文字的**左缘完全一致**（文字不跳） */
    public function testEnterEntersEditAndEditorAlignsWithText(): void
    {
        $s = EditSession::open();

        $s->chord(KeyChord::right());
        $ate = $s->chord(KeyChord::enter());
        $x = $s->editBoxX();

        check(
            'Table · 编辑：可编辑列上 Enter ⇒ 进编辑（光标格 (0,1)）',
            $ate && $s->isEditing() && $s->editingCell() === ['row' => 0, 'col' => 1],
            'ate=' . ($ate ? 'y' : 'n') . ' cell=' . \json_encode($s->editingCell()),
        );
        check(
            'Table · 编辑：编辑格画的是 `RenderTextField`，左缘 = 列起点 100 + 列内边距 8'
                . '（零内边距 ⇒ 进入编辑时文字一个像素都不动）',
            $x !== null && \abs($x - 108.0) < 0.01,
            'x=' . ($x === null ? 'null' : \sprintf('%.2f', $x)),
        );

        // ★★ 插入符**必须有宽度**：`caretRect()` 的宽就是 `caretWidth`，传 0 的后果是
        //   "进入编辑后看不见插入符" —— 不报错、不写日志，整图 diff 都是 0（2026-10-27
        //   真机取证才发现首版传了 `0.0`）。判据落在**可见性**上，不落在参数上。
        $caret = $s->caret();
        check(
            'Table · 编辑：插入符有宽度（> 0）—— 宽度为 0 等于"看不见插入符"，且不报错',
            $caret !== null && $caret->width > 0.0,
            'w=' . ($caret === null ? 'null' : \sprintf('%.2f', $caret->width)),
        );

        $this->flushChecks();
    }

    /** ④⑤ 键入 ⇒ 草稿变；Enter ⇒ 提交并退出、光标下移、格子画新值 */
    public function testTypingThenEnterCommits(): void
    {
        $s = EditSession::open();

        $s->chord(KeyChord::right());
        $s->chord(KeyChord::enter());
        $s->type('X');

        $widget = $s->editWidget();
        check(
            'Table · 编辑：键入写进草稿（编辑盒文本 = "甲X"，插入符在末尾）',
            $widget !== null && $widget->text() === '甲X'
                && $widget->caretOffset() === \strlen('甲X'),
            $widget === null ? 'null' : $widget->text() . ' @' . $widget->caretOffset(),
        );

        $s->chord(KeyChord::enter());

        check(
            'Table · 编辑：Enter ⇒ 提交 [行 key 1, 列 name, "甲X"]，退出编辑，光标下移',
            $s->app->editLog === [[1, 'name', '甲X']]
                && !$s->isEditing()
                && $s->focusedCell() === ['row' => 1, 'col' => 1],
            'log=' . \json_encode($s->app->editLog) . ' cell=' . \json_encode($s->focusedCell()),
        );
        check(
            'Table · 编辑：提交后格子画的是**新值**（甲X），编辑盒已消失',
            $s->hasText('甲X') && $s->editBox() === null,
            'text=' . ($s->hasText('甲X') ? 'y' : 'n')
                . ' box=' . ($s->editBox() === null ? 'null' : '有'),
        );

        $this->flushChecks();
    }

    /** ⑥ Esc ⇒ 丢弃草稿、不回调、格子回旧值 */
    public function testEscapeDiscardsDraft(): void
    {
        $s = EditSession::open();

        $s->chord(KeyChord::right());
        $s->chord(KeyChord::enter());
        $s->type('Z');
        $s->chord(KeyChord::escape());

        check(
            'Table · 编辑：Esc ⇒ 退出编辑、**不回调**、格子回到旧值（甲）',
            $s->app->editLog === [] && !$s->isEditing() && $s->hasText('甲'),
            'log=' . \json_encode($s->app->editLog)
                . ' editing=' . ($s->isEditing() ? 'y' : 'n'),
        );

        $this->flushChecks();
    }

    /** ⑦ 没改就提交 ⇒ 不回调（G2：宁可少报，同值不是"改动"） */
    public function testUnchangedCommitDoesNotNotify(): void
    {
        $s = EditSession::open();

        $s->chord(KeyChord::right());
        $s->chord(KeyChord::enter());
        $s->chord(KeyChord::enter());

        check(
            'Table · 编辑：没改就 Enter ⇒ 不回调（同值不是"改动"）',
            $s->app->editLog === [] && !$s->isEditing(),
            'log=' . \json_encode($s->app->editLog),
        );

        $this->flushChecks();
    }

    /** ⑧ 失焦 ⇒ 提交（`FocusManager` 的落点就是 `didChangeFocus(false)`） */
    public function testBlurCommits(): void
    {
        $s = EditSession::open();

        $s->chord(KeyChord::right());
        $s->chord(KeyChord::enter());
        $s->type('W');

        $s->state?->didChangeFocus(false);
        $s->tick();

        check(
            'Table · 编辑：失焦 ⇒ 提交（焦点走人等于"点到别处"，草稿不能悬着）',
            $s->app->editLog === [[1, 'name', '甲W']] && !$s->isEditing(),
            'log=' . \json_encode($s->app->editLog),
        );

        $this->flushChecks();
    }

    /** ⑨ 用户滚动 ⇒ 提交（否则编辑中的行可能滚出窗口，用户对着看不见的格子打字） */
    public function testScrollCommits(): void
    {
        $s = EditSession::open();

        $s->chord(KeyChord::right());
        $s->chord(KeyChord::enter());
        $s->type('V');

        $s->state?->scrollBy(40.0);
        $s->tick();

        check(
            'Table · 编辑：用户滚动 ⇒ 提交',
            $s->app->editLog === [[1, 'name', '甲V']] && !$s->isEditing(),
            'log=' . \json_encode($s->app->editLog),
        );

        $this->flushChecks();
    }

    /** ⑩ 编辑期间**行序变了** ⇒ 提交仍落在原来那条数据上（会话认 key 不认下标） */
    public function testCommitSurvivesRowReorder(): void
    {
        $s = EditSession::open();

        $s->chord(KeyChord::right());
        $s->chord(KeyChord::enter());
        $s->type('S');

        // 反序（模拟排序换了行序）：id 1 从第 0 行跑到第 2 行
        $s->app->store->setRows(\array_reverse($s->app->store->rows));
        $s->tick();

        $s->chord(KeyChord::enter());

        check(
            'Table · 编辑：编辑中行序变了 ⇒ 提交仍写回**原来那条数据**（key=1，不是第 0 行）',
            $s->app->editLog === [[1, 'name', '甲S']]
                && ($s->app->store->rows[2]['name'] ?? null) === '甲S'
                && ($s->app->store->rows[0]['name'] ?? null) === '丙',
            'log=' . \json_encode($s->app->editLog) . ' rows=' . \json_encode($s->app->store->rows),
        );

        $this->flushChecks();
    }

    /** ⑪ 编辑中的行被删掉 ⇒ 会话当场结束（不能对着空气改字） */
    public function testDeletingRowEndsSession(): void
    {
        $s = EditSession::open();

        $s->chord(KeyChord::right());
        $s->chord(KeyChord::enter());

        $s->app->store->setRows([]);
        $s->tick();

        check(
            'Table · 编辑：编辑中的行被删 ⇒ 会话自动结束（不崩、不回调）',
            !$s->isEditing() && $s->app->editLog === [],
            'editing=' . ($s->isEditing() ? 'y' : 'n') . ' log=' . \json_encode($s->app->editLog),
        );

        $this->flushChecks();
    }

    /**
     * ⑫ `Ctrl+Backspace` / `Ctrl+Delete` 在表格**编辑中**也要通到草稿。
     *
     * ★ 键位表是共用的（`TextKeymap`），但表格有一条输入框没有的路：`editKey()` 的
     *   **收口键**（Esc / Enter / F2 / ↑ / ↓）判在 `TextKeymap` **之前**。谁哪天照
     *   Excel 补一条"空草稿时 Backspace 退出编辑"，`Ctrl+Backspace` 就会连带被吞 ——
     *   而 `TextKeymapTest` 直接调 `apply()`，看不见这一层。
     */
    public function testWordDeleteWorksInsideCellEditing(): void
    {
        $s = EditSession::open([['id' => 1, 'name' => 'hello world']]);

        $s->chord(KeyChord::right());
        $s->chord(KeyChord::enter());

        $entered = $s->editWidget();
        check(
            'Table · 编辑中：草稿是整串',
            $s->isEditing() && $entered !== null && $entered->text() === 'hello world',
            $entered === null ? 'null' : $entered->text(),
        );

        $ate = $s->chord(KeyChord::ctrl('Backspace'));
        $back = $s->editWidget();
        check(
            'Table · 编辑中：Ctrl+Backspace 删到词首（空格留着、插入符在 6）',
            $ate && $back !== null && $back->text() === 'hello ' && $back->caretOffset() === 6,
            ($ate ? 'ate' : 'passed') . ' '
                . ($back === null ? 'null' : $back->text() . ' @' . $back->caretOffset()),
        );

        $s->chord(KeyChord::home());
        $s->chord(KeyChord::ctrl('Delete'));
        $forward = $s->editWidget();
        check(
            'Table · 编辑中：Ctrl+Delete 删到词尾（剩一个空格，且没跳出编辑）',
            $forward !== null && $forward->text() === ' ' && $s->isEditing(),
            $forward === null ? 'null' : \json_encode($forward->text()),
        );

        $this->flushChecks();
    }
}
