<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Input;

use Drift\Dev\Smoke\App\EditApplication;
use Drift\Dev\Smoke\App\FakeClipboard;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Widget\Element;
use Drift\Widget\StatefulElement;
use Drift\Widget\TextField;
use Drift\Widget\TextFieldState;

/**
 * 编辑命令环：`TextFieldState::handleKey()` 的四道门控（§9.1 让位规则 ②）。
 *
 * ## 夹具为什么要"另绑 Ctrl+C / Ctrl+V 加速键"
 *
 * 门控说到底是**"这次按键归谁"**。只验"剪贴板被写了"证明不了键没被吞 ——
 * 所以夹具同时绑了 `app.copy` / `app.paste`：
 * - 门控放行（环吃掉）⇒ `seen` 必须**不动**；
 * - 门控拦住（回落到加速键）⇒ `seen` 必须**开火**。
 *
 * 四道门控：
 * - ② 焦点不在输入框；
 * - ③ 平台没递剪贴板端口（`clipboard()` 返回 null）；
 * - ④ 剪贴板没内容（`hasText()` false）/ 无选区。
 *
 * ★ 指针框选（down/move/up/leave）见 `PointerSelectionTest`，同一个夹具的另一半。
 */
final class EditingCommandLoopTest extends SmokeTestCase
{
    /** 建一个带剪贴板的编辑应用，并让焦点落到第一个字段 */
    private function editApp(?FakeClipboard $clip): EditApplication
    {
        $app = new EditApplication();
        $app->clip = $clip;
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(300.0, 200.0)));

        return $app;
    }

    private function firstFieldState(EditApplication $app): ?TextFieldState
    {
        $el = findElement($app->rootElement(), fn(Element $e) => $e->widget() instanceof TextField);
        $state = $el instanceof StatefulElement ? $el->state() : null;

        return $state instanceof TextFieldState ? $state : null;
    }

    public function testClipboardRoundTripAndGates(): void
    {
        $editApp = $this->editApp(new FakeClipboard());
        $editApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
        $editState = $this->firstFieldState($editApp);
        check(
            '编辑命令 · Tab 焦点进第一个字段（编辑态同步开）',
            $editState instanceof TextFieldState && $editState->isEditing(),
            var_export($editState instanceof TextFieldState, true),
        );

        // —— 门控 ④（C/X 无选区）⇒ 整族回落给加速键
        $editApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('C')));
        check(
            '编辑命令 · 无选区（门控④）⇒ Ctrl+C 回落加速键（键没被吞）',
            $editApp->seen === ['app.copy'] && $editApp->clip->text === '',
            'seen=[' . implode(',', $editApp->seen) . '] clip=' . var_export($editApp->clip->text, true),
        );

        // —— Ctrl+A：让位后的键落点（不进加速键，全选发生）
        $editApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('A')));
        check(
            '编辑命令 · Ctrl+A 归输入框（加速键不吃，全选 [0,3]）',
            $editState?->selectionStart() === 0 && $editState?->selectionEnd() === 3
                && $editApp->seen === ['app.copy'],
            'sel=' . ($editState?->selectionStart() ?? -1) . '..' . ($editState?->selectionEnd() ?? -1),
        );

        // —— 环吃掉：复制（有选区 + 有剪贴板）
        $editApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('C')));
        check(
            '编辑命令 · 有选区 + 有剪贴板 ⇒ 复制进剪贴板，加速键不吃',
            $editApp->clip->text === 'abc' && $editApp->seen === ['app.copy'],
            'clip=' . var_export($editApp->clip->text, true) . ' seen=[' . implode(',', $editApp->seen) . ']',
        );

        // —— 剪切：写剪贴板 + 删掉选区（不等同于复制）
        $editApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('X')));
        check(
            '编辑命令 · 剪切 = 写剪贴板 + 删掉选区（字段空了）',
            $editApp->clip->text === 'abc' && $editState?->text() === '',
            'clip=' . var_export($editApp->clip->text, true) . ' text=' . var_export($editState?->text(), true),
        );

        // —— 粘贴：替换选区（此处无选区 ⇒ 插在插入符）
        $editApp->clip->text = 'XY';
        $editApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('V')));
        check(
            '编辑命令 · 粘贴把剪贴板内容写进字段（加速键不吃）',
            $editState?->text() === 'XY' && $editApp->seen === ['app.copy'],
            'text=' . var_export($editState?->text(), true) . ' seen=[' . implode(',', $editApp->seen) . ']',
        );

        $this->flushChecks();
    }

    public function testMissingClipboardFallsBack(): void
    {
        // —— 门控 ③（平台没递剪贴板）⇒ 有选区也回落
        $noClipApp = $this->editApp(null);
        $noClipApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
        $noClipApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('A')));
        $noClipApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('C')));
        check(
            '编辑命令 · 平台没递剪贴板（门控③）⇒ 有选区也回落给加速键',
            $noClipApp->seen === ['app.copy'],
            'seen=[' . implode(',', $noClipApp->seen) . ']',
        );

        // —— 门控 ④（V 的剪贴板空）⇒ 回落
        $emptyClipApp = $this->editApp(new FakeClipboard()); // text='' ⇒ hasText() false
        $emptyClipApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
        $emptyClipApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('V')));
        $emptyState = $this->firstFieldState($emptyClipApp);
        check(
            '编辑命令 · 剪贴板空（门控④）⇒ Ctrl+V 回落加速键，字段不动',
            $emptyClipApp->seen === ['app.paste'] && $emptyState?->text() === 'abc',
            'seen=[' . implode(',', $emptyClipApp->seen) . '] text=' . var_export($emptyState?->text(), true),
        );

        // —— 门控 ②（焦点不在输入框）⇒ 回落
        $idleApp = $this->editApp(new FakeClipboard());
        $idleApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('C')));
        check(
            '编辑命令 · 无焦点（门控②）⇒ 整族回落给加速键',
            $idleApp->seen === ['app.copy'],
            'seen=[' . implode(',', $idleApp->seen) . ']',
        );

        $this->flushChecks();
    }
}
