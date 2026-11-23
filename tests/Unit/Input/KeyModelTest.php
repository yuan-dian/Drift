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

        // ★ 单字母归一：壳的 keyNameFromVk 对 VK_A–VK_Z 给大写，大小写在 $shift 里。
        //   不归一 ⇒ "Ctrl+A" 有 'a'/'A' 两种写法，其中一种在真机上永远不命中，
        //   而单元测试与实现同源（都写小写）⇒ 一起绿。TableState 就这样失效过。
        check(
            '输入层 · 单字母键名归一成大写（`a` 与 `A` 是同一个 chord）',
            KeyChord::make('a', ctrl: true)->is(KeyChord::ctrl('A'))
                && KeyChord::ctrl('a')->key === 'A',
            'make("a")->key=' . KeyChord::make('a', ctrl: true)->key,
        );
        check(
            '输入层 · 归一不碰多字符键名（`Left` 不会变 `LEFT`）',
            KeyChord::left()->key === 'Left' && KeyChord::tab()->key === 'Tab',
        );

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

    /**
     * 输入模态分类：只有导航键切 `:focus-visible`，编辑键不切。
     *
     * ★ 这条把"鼠标点进输入框后按 Enter 冒蓝框"钉在**数据层**：即便应用层
     *   忘了用这个谓词，分类本身也先被咬住；反过来只测应用层会漏掉分类漂移。
     */
    public function testKeyEventFocusNavigationClassification(): void
    {
        $navigation = [
            KeyChord::tab(),
            KeyChord::shift('Tab'),
            KeyChord::left(),
            KeyChord::right(),
            KeyChord::up(),
            KeyChord::down(),
            KeyChord::pageUp(),
            KeyChord::pageDown(),
            KeyChord::home(),
            KeyChord::end(),
        ];
        foreach ($navigation as $chord) {
            check(
                '输入模态 · ' . $chord->label() . ' 是导航键（应切键盘焦点环）',
                KeyEvent::down($chord)->isFocusNavigation(),
            );
        }

        $editing = [
            KeyEvent::character('a'),
            KeyEvent::character('井'),
            KeyEvent::down(KeyChord::enter()),
            KeyEvent::down(KeyChord::backspace()),
            KeyEvent::down(KeyChord::delete()),
            KeyEvent::down(KeyChord::ctrl('A')),
            KeyEvent::down(KeyChord::ctrl('Left')),
            KeyEvent::down(KeyChord::escape()),
            KeyEvent::down(KeyChord::f(5)),
        ];
        foreach ($editing as $event) {
            check(
                '输入模态 · ' . $event->chord->label() . ' 不是导航键（编辑键不该冒蓝框）',
                !$event->isFocusNavigation(),
            );
        }

        $this->flushChecks();
    }

    public function testFocusNodeDeclaration(): void
    {
        $fn = FocusNode::make()->withOrder(3);
        check('输入层 · FocusNode 链式声明 + hasOrder', $fn->order === 3 && $fn->hasOrder() && !$fn->skip);
        check('输入层 · FocusNode::skipped 是子树级标记', FocusNode::make()->skipped()->skip);
        // 两个字段互不覆盖：`skipped()` 保住 order，`withOrder()` 保住 skip
        check(
            '输入层 · order 与 skip 互不覆盖',
            FocusNode::make()->withOrder(5)->skipped()->order === 5
                && FocusNode::make()->skipped()->withOrder(5)->skip,
        );

        $this->flushChecks();
    }

    public function testWidgetSideFocusDeclaration(): void
    {
        // Widget 侧声明 ⇒ focusNode()
        $declared = TextField::make()->focusOrder(2);
        check('Widget · focusOrder 落到 focusNode()', $declared->focusNode()?->order === 2);
        check('Widget · 没声明任何焦点属性 ⇒ focusNode() 为 null', Text::make('x')->focusNode() === null);
        check('Widget · Text 不可聚焦（默认 false）', !Text::make('x')->isFocusable());
        check('Widget · TextField 天然可聚焦', TextField::make()->isFocusable());

        $this->flushChecks();
    }
}
