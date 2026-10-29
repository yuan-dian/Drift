<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Input;

use Drift\App\FocusManager;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Layout\RenderFlex;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\StatefulElement;
use Drift\Widget\TextField;
use Drift\Widget\TextFieldState;

/**
 * 焦点路由：命中 → 焦点 → 按键（§9.1）—— 让 TextField 真能被用户驱动的那一环。
 *
 * 判据（与 `tools/smoke.php` ㉔ 段后半一致）：
 *   ① 文档序收集；没焦点时 `dispatchKey` 不吃键（不能"没焦点也改文本"）；
 *   ② Tab 前进 / Shift+Tab 后退 / 到末尾就停（**不环绕**：环绕会让焦点在
 *      看不见的地方打转，用户找不到）；
 *   ③ 焦点落下即进编辑态、离开即退出；打字/退格/方向键/Home/End 真的作用在文本与插入符上；
 *   ④ 点一下字段 ⇒ 命中测试（最深优先）把焦点交给它。
 *
 * ★ 需要真 Element 树与布局，故 Feature 层；不需要帧循环。
 */
final class FocusRoutingTest extends SmokeTestCase
{
    /**
     * 一棵可交互的树：两个字段上下排（spacing 8）。
     *
     * @return array{0: \Drift\Widget\Element, 1: FocusManager, 2: \Drift\Layout\RenderBox|null, 3: \Drift\Widget\Element|null, 4: \Drift\Widget\Element|null, 5: TextFieldState|null}
     */
    private function twoFields(): array
    {
        $ctx = smokeContext();
        $root = Column::make()->spacing(8)->children([
            TextField::make()->value('ab'),
            TextField::make()->value('cd'),
        ])->createElement();
        $root->mount(null, $ctx);
        $box = $root->effectiveBox();
        $box?->layout(BoxConstraints::loose(new Size(300.0, 200.0)));

        $fm = new FocusManager();
        $fm->collect([$root]);

        $first = $fm->order()[0] ?? null;
        $second = $fm->order()[1] ?? null;
        $firstState = $first instanceof StatefulElement ? $first->state() : null;

        return [$root, $fm, $box, $first, $second, $firstState instanceof TextFieldState ? $firstState : null];
    }

    public function testCollectAndNoFocusSwallowsNothing(): void
    {
        [, $fm] = $this->twoFields();

        check('输入层 · 焦点链收进 2 个字段（文档序）', count($fm->order()) === 2);
        check('输入层 · 没焦点时 dispatchKey 不吃键', !$fm->dispatchKey(KeyEvent::character('X')));

        $this->flushChecks();
    }

    public function testTabCycleAndEditingStates(): void
    {
        [, $fm, , $inFirst, $inSecond, $inFirstState] = $this->twoFields();

        $fm->dispatchKey(KeyEvent::down(KeyChord::tab()));
        check('输入层 · Tab ⇒ 焦点落到第一个字段', $fm->focused() === $inFirst);
        check(
            '输入层 · 焦点落下即进入编辑态（插入符出现）',
            $inFirstState instanceof TextFieldState && $inFirstState->isEditing(),
        );

        $fm->dispatchKey(KeyEvent::character('X'));
        check('输入层 · 打字 ⇒ 文本真的变了', $inFirstState?->text() === 'abX');

        $fm->dispatchKey(KeyEvent::down(KeyChord::backspace()));
        check('输入层 · 退格 ⇒ 删掉一个字符', $inFirstState?->text() === 'ab');

        $fm->dispatchKey(KeyEvent::down(KeyChord::left()));
        check('输入层 · 左方向键 ⇒ 插入符左移一位', $inFirstState?->caretOffset() === 1);
        $fm->dispatchKey(KeyEvent::down(KeyChord::right()));
        check(
            '输入层 · 右方向键 ⇒ 插入符右移一位（★ 在边界上不是原地不动）',
            $inFirstState?->caretOffset() === 2,
        );
        $fm->dispatchKey(KeyEvent::down(KeyChord::home()));
        check('输入层 · Home ⇒ 行首', $inFirstState?->caretOffset() === 0);
        $fm->dispatchKey(KeyEvent::down(KeyChord::end()));
        check('输入层 · End ⇒ 行尾', $inFirstState?->caretOffset() === 2);

        $fm->dispatchKey(KeyEvent::down(KeyChord::tab()));
        check('输入层 · Tab ⇒ 焦点移到第二个字段', $fm->focused() === $inSecond);
        check('输入层 · 焦点离开 ⇒ 前一个字段退出编辑态', $inFirstState !== null && !$inFirstState->isEditing());

        $fm->dispatchKey(KeyEvent::down(KeyChord::tab()));
        check('输入层 · 到末尾 Tab 就停（不环绕）', $fm->focused() === $inSecond);
        $fm->dispatchKey(KeyEvent::down(KeyChord::shift('Tab')));
        check('输入层 · Shift+Tab ⇒ 回到第一个字段', $fm->focused() === $inFirst);

        $this->flushChecks();
    }

    public function testHitTestPicksDeepestAndGrantsFocus(): void
    {
        [$inRoot, , $inBox, $inFirst, , $inFirstState] = $this->twoFields();

        // 命中测试 → 焦点
        check('输入层 · hitTest 越界 ⇒ null', $inBox?->hitTest(-1.0, 5.0) === null);
        check(
            '输入层 · hitTest 落在第一个字段的盒上（最深优先）',
            $inBox?->hitTest(10.0, 10.0) === $inFirstState?->element()?->effectiveBox(),
        );

        $fmHit = new FocusManager();
        $fmHit->collect([$inRoot]);
        $fmHit->dispatchPointer(PointerEvent::down(10.0, 10.0), $inRoot);
        check(
            '输入层 · 点一下字段 ⇒ 它拿到焦点并进入编辑态',
            $fmHit->focused() === $inFirst && $inFirstState !== null && $inFirstState->isEditing(),
            sprintf(
                'focused=first:%s null:%s｜editing=%s｜order=%d｜hit=%s',
                $fmHit->focused() === $inFirst ? 'y' : 'n',
                $fmHit->focused() === null ? 'y' : 'n',
                $inFirstState?->isEditing() ? 'y' : 'n',
                count($fmHit->order()),
                $inBox?->hitTest(10.0, 10.0) === null ? 'null' : 'box',
            ),
        );

        $this->flushChecks();
    }

    public function testSettleAdoptsNewBoxIntoParent(): void
    {
        [$inRoot, , $inBox, , , $inFirstState] = $this->twoFields();

        // ★ 帧末重建（§5.2 裁决 3）：脏 StatefulElement 造了新盒后，**父容器必须换上新盒**
        //   （§13.2 + C1 父管挂接）。漏掉这一步是**静默**的 —— 新盒确实造出来了，但祖先
        //   容器仍握着旧盒对象，`layout()/paint()` 走的是重建前那棵渲染树 ⇒ 画面纹丝不动、
        //   也不报错。首个暴露者就是 `TextField`：`--focus` 曾是**空操作**（插入符画不出来）。
        //   ⇒ 这条断言的作用是"量具双向自检"（§20 纪律 1）：`Element::settle()` 里去掉
        //   `adoptBoxes()` 它必须变红。
        $inBoxBefore = $inBox?->children()[0] ?? null;
        $inFirstState?->markDirty();          // 等价于事件中途的 setState：只标脏
        $inRoot->settle();                    // 帧末统一重建
        $inBoxNow = $inRoot->effectiveBox();
        check(
            '输入层 · 帧末重建后父容器换上了新盒（§13.2 挂接，不是握着旧盒）',
            $inBoxNow instanceof RenderFlex
                && ($inBoxNow->children()[0] ?? null) === $inFirstState?->element()?->effectiveBox()
                && ($inBoxNow->children()[0] ?? null) !== $inBoxBefore,
            sprintf(
                'before=%s after=%s effective=%s',
                $inBoxBefore === null ? 'null' : $inBoxBefore::class . '#' . spl_object_id($inBoxBefore),
                ($inBoxNow?->children()[0] ?? null) === null ? 'null' : ($inBoxNow->children()[0])::class . '#' . spl_object_id($inBoxNow->children()[0]),
                $inFirstState?->element()?->effectiveBox() === null ? 'null' : spl_object_id($inFirstState->element()->effectiveBox()),
            ),
        );

        $this->flushChecks();
    }

    public function testSkipFocusAndFocusOrder(): void
    {
        $inContext = smokeContext();

        // skipFocus / focusOrder
        $skipRoot = Column::make()->children([
            TextField::make()->value('a'),
            Column::make()->skipFocus()->children([TextField::make()->value('b')]),
        ])->createElement();
        $skipRoot->mount(null, $inContext);
        $fmSkip = new FocusManager();
        $fmSkip->collect([$skipRoot]);
        check('输入层 · skipFocus 整棵子树不进 Tab 序', count($fmSkip->order()) === 1);

        // ★★ 2026-10-29：`skipFocus()` **只该动 Tab 序**，不该让控件点不动。
        //   早先 `walk()` 用的是同一张表（skip ⇒ 连 `byId` 都不登记）⇒ 加了
        //   `skipFocus()` 的按钮点了毫无反应。`TreeView` 的行箭头就是这么静默失效的：
        //   画面上箭头好好地在那儿，日志里什么也没有。
        $tops = [];
        $skipRoot->visitChildren(static function (Element $child) use (&$tops): void {
            $tops[] = $child;
        });
        $inner = [];
        ($tops[1] ?? null)?->visitChildren(static function (Element $child) use (&$inner): void {
            $inner[] = $child;
        });
        $landed = $fmSkip->focusHit(($inner[0] ?? null)?->effectiveBox(), $skipRoot);
        check(
            '输入层 · skipFocus 的控件**仍然点得动**（跳过 Tab 序 ≠ 禁用）',
            $landed === true,
            'focusHit=' . var_export($landed, true),
        );

        $orderRoot = Column::make()->children([
            TextField::make()->value('first')->focusOrder(2),
            TextField::make()->value('second')->focusOrder(1),
        ])->createElement();
        $orderRoot->mount(null, $inContext);
        $fmOrder = new FocusManager();
        $fmOrder->collect([$orderRoot]);
        $orderedFirst = $fmOrder->order()[0] ?? null;
        $orderedState = $orderedFirst instanceof StatefulElement ? $orderedFirst->state() : null;
        check(
            '输入层 · focusOrder 稳定排序（1 排在 2 前面）',
            $orderedState instanceof TextFieldState && $orderedState->text() === 'second',
        );

        $this->flushChecks();
    }

    public function testClaimKeysMakesChordExclusive(): void
    {
        $inContext = smokeContext();

        // claims：焦点独占键（§9.1 让位规则 ②）
        $claimRoot = TextField::make()->claimKeys([KeyChord::ctrl('A')])->createElement();
        $claimRoot->mount(null, $inContext);
        $fmClaim = new FocusManager();
        $fmClaim->collect([$claimRoot]);
        $fmClaim->focusNext();
        check(
            '输入层 · claimKeys ⇒ 焦点独占 Ctrl+A、不独占 Ctrl+C',
            $fmClaim->claims(KeyChord::ctrl('A')) && !$fmClaim->claims(KeyChord::ctrl('C')),
        );

        $this->flushChecks();
    }
}
