<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Input;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Input\FocusNode;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Widget\Text;
use Drift\Widget\TextField;

/**
 * 输入层数据模型：KeyChord / KeyEvent / FocusNode 声明（§9.1）。
 *
 * 判据（与 `tools/smoke.php` ㉔ 段前半一致）：
 *   ① `KeyChord` 是**值语义**（Ctrl+A ≠ Ctrl+C，同组合互相 `is()`）；
 *   ② `KeyEvent::character` 保留原字符、`chord` 归一化；`down` 不带文本
 *      （编辑键 / 加速键走 down，纯按键不该被当成文本输入）；
 *   ③ Widget 侧的 `focusOrder()/claimKeys()` 只在**声明了**才产出 `focusNode()`，
 *      没声明就是 null（"没要求聚焦"与"要聚焦但没顺序"不是一件事）。
 *
 * ★ 纯值对象与声明层，无树无帧 ⇒ Unit 层。
 */
final class KeyModelTest extends SmokeTestCase
{
    public function testKeyChordValueSemantics(): void
    {
        check(
            '输入层 · KeyChord 值语义（Ctrl+A ≠ Ctrl+C）',
            KeyChord::ctrl('A')->is(KeyChord::ctrl('A')) && !KeyChord::ctrl('A')->is(KeyChord::ctrl('C')),
        );
        check('输入层 · KeyChord::f(5) ⇒ F5', KeyChord::f(5)->key === 'F5' && KeyChord::f(5)->label() === 'F5');

        $this->flushChecks();
    }

    public function testKeyEventCharacterVsDown(): void
    {
        check(
            '输入层 · KeyEvent::character 保留原字符、chord 归一化',
            KeyEvent::character('井')->text === '井' && KeyEvent::character('井')->chord->key === '井',
        );
        check('输入层 · KeyEvent::down 不带文本（编辑键 / 加速键）', !KeyEvent::down(KeyChord::tab())->isText());

        $this->flushChecks();
    }

    public function testFocusNodeDeclaration(): void
    {
        $fn = FocusNode::make()->withOrder(3)->claiming([KeyChord::ctrl('A')]);
        check('输入层 · FocusNode 链式声明 + hasOrder', $fn->order === 3 && $fn->hasOrder() && !$fn->skip);
        check(
            '输入层 · FocusNode::claims 只认声明过的组合',
            $fn->claims(KeyChord::ctrl('A')) && !$fn->claims(KeyChord::ctrl('B')),
        );
        check('输入层 · FocusNode::skipped 是子树级标记', FocusNode::make()->skipped()->skip);

        $this->flushChecks();
    }

    public function testWidgetSideFocusDeclaration(): void
    {
        // Widget 侧声明 ⇒ focusNode()
        $declared = TextField::make()->focusOrder(2)->claimKeys([KeyChord::ctrl('A')]);
        check(
            'Widget · focusOrder/claimKeys 落到 focusNode()',
            $declared->focusNode()?->order === 2 && ($declared->focusNode()?->claims(KeyChord::ctrl('A')) ?? false),
        );
        check('Widget · 没声明任何焦点属性 ⇒ focusNode() 为 null', Text::make('x')->focusNode() === null);
        check('Widget · Text 不可聚焦（默认 false）', !Text::make('x')->isFocusable());
        check('Widget · TextField 天然可聚焦', TextField::make()->isFocusable());

        $this->flushChecks();
    }
}
