<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Input;

use Drift\Core\Pipeline;
use Drift\App\FocusManager;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Button;

/**
 * 焦点链收集的**门控**（2026-11-01）：树的内容没变就不重收。
 *
 * ## 为什么必须有这个文件
 *
 * `FocusManager::collect()` 此前每帧全树扫一遍（2000 节点实测 1.6ms/帧，
 * 是稳态帧唯一的大头）。加门控的收益是把这笔税降到 ~0，代价是**引入一类新的
 * 静默**：判据漏一条 ⇒ 焦点表停在旧内容上 ⇒ Tab 跳进一个已禁用的控件、
 * 或跳不到新加的控件。而这类错误**不报错**，只是"有点怪"。
 *
 * 所以每条判据各钉一条用例，判据的三种来源正好对应三种失效：
 *   ① `Pipeline::current()->contentVersion()` —— 结构增删 / 任一 Widget 换过（声明可能变）；
 *   ② 根集合签名 —— 浮层开合（浮层不进主树，`contentVersion` 看不见）；
 *   ③ 陷阱开关 —— `roots` 相同而 `scope` 从空变非空。
 *
 * ★ 每条用例都**先钉基线**（第一次收集的数字），再改事实、再收一次。
 *   否则"第二次也是 2"分不清是"门控正确"还是"两次都没收成"。
 */
final class FocusCollectGateTest extends SmokeTestCase
{
    /**
     * @param list<bool> $disabled 每个字段是否禁用（长度 = 字段数）
     */
    private function tree(array $disabled = [false, false]): Element
    {
        $children = [];
        foreach ($disabled as $index => $off) {
            $field = Button::make('b' . (string) $index);
            if ($off) {
                $field->disabled();
            }
            $children[] = $field;
        }

        $root = Column::make()->spacing(8)->children($children)->createElement();
        $root->mount(null, smokeContext());
        $root->effectiveBox()?->layout(BoxConstraints::loose(new Size(300.0, 200.0)));

        return $root;
    }

    /** 把根换一份等价树（`update()` 是运行时改声明的唯一入口） */
    private function replace(Element $root, array $disabled): void
    {
        $children = [];
        foreach ($disabled as $index => $off) {
            $field = Button::make('b' . (string) $index);
            if ($off) {
                $field->disabled();
            }
            $children[] = $field;
        }

        $root->update(Column::make()->spacing(8)->children($children));
    }

    /** ① 声明变了（`disabled()`）但节点没增删 ⇒ `contentVersion` 必须放行 */
    public function testDisabledChangeIsRecollected(): void
    {
        $root = $this->tree();
        $fm = new FocusManager();
        $fm->collect([$root]);

        check('焦点门控 · 基线：两个字段都在 Tab 序里', \count($fm->order()) === 2, 'n=' . \count($fm->order()));

        $this->replace($root, [false, true]);
        $fm->collect([$root]);

        check(
            '焦点门控 · 运行时禁用第二个 ⇒ 重收后只剩 1 个（contentVersion 放行）',
            \count($fm->order()) === 1,
            'n=' . \count($fm->order()),
        );

        // 复位也要跟得上（门控不能是"变过一次就永远重收"）
        $this->replace($root, [false, false]);
        $fm->collect([$root]);

        check('焦点门控 · 复位后回到 2 个', \count($fm->order()) === 2, 'n=' . \count($fm->order()));

        $this->flushChecks();
    }

    /** ① 的一半：结构增删 */
    public function testStructureChangeIsRecollected(): void
    {
        $root = $this->tree();
        $fm = new FocusManager();
        $fm->collect([$root]);

        check('焦点门控 · 基线 2 个', \count($fm->order()) === 2, 'n=' . \count($fm->order()));

        $this->replace($root, [false, false, false]);
        $fm->collect([$root]);

        check('焦点门控 · 多一个字段 ⇒ 3 个（结构变放行）', \count($fm->order()) === 3, 'n=' . \count($fm->order()));

        $this->flushChecks();
    }

    /** ② 根集合签名：浮层开合时 `contentVersion` 看不见，必须靠签名 */
    public function testRootSetChangeIsRecollected(): void
    {
        $root = $this->tree();
        $fm = new FocusManager();
        $fm->collect([$root]);

        $all = $fm->order();
        check('焦点门控 · 基线 2 个', \count($all) === 2, 'n=' . \count($all));

        // 模拟"打开一个浮层并把它设成焦点陷阱"：树本身一个节点都没动
        $popup = $this->tree([false]);
        $fm->collect([$root, $popup], [$popup]);

        check(
            '焦点门控 · 陷阱只收浮层 ⇒ 1 个（根签名放行）',
            \count($fm->order()) === 1,
            'n=' . \count($fm->order()),
        );
        check('焦点门控 · 陷阱里收的确实是浮层那个字段', ($fm->order()[0] ?? null) !== $all[0]);

        // 关掉浮层：树仍然没动，只能靠签名回落
        $fm->collect([$root]);

        check('焦点门控 · 浮层关掉 ⇒ 回到主树的 2 个', \count($fm->order()) === 2, 'n=' . \count($fm->order()));

        $this->flushChecks();
    }

    /** ③ 陷阱开关：根集合相同、`scope` 从空变非空 */
    public function testTrapToggleAloneIsRecollected(): void
    {
        $root = $this->tree();
        $fm = new FocusManager();
        $fm->collect([$root]);

        $all = $fm->order();
        check('焦点门控 · 基线 2 个', \count($all) === 2, 'n=' . \count($all));

        // 同一个根：不收陷阱时 2 个；把根自己当陷阱 ⇒ 仍 2 个但 `trapped` 变了
        $fm->collect([$root], [$root]);

        check('焦点门控 · 根相同但开陷阱 ⇒ 仍收 2 个（不能因短路丢掉 trapped）', \count($fm->order()) === 2);

        // ★ 这条是真正的判据：`trapped` 决定 Tab 到末尾是否环绕。
        //   短路时若没更新 trapped，下面这次就会"停"而不是"绕"。
        $fm->focus($fm->order()[1]);
        $fm->focusNext();

        check(
            '焦点门控 · 陷阱里 Tab 到末尾会环绕（trapped 跟着签名更新了）',
            $fm->focused() === $fm->order()[0],
        );

        $this->flushChecks();
    }

    /** 稳态：树的签名没变 ⇒ 不重收，但已有表与焦点原样保留 */
    public function testStableTreeKeepsTableAndFocus(): void
    {
        $root = $this->tree();
        $fm = new FocusManager();
        $fm->collect([$root]);

        $first = $fm->order()[0] ?? null;
        $fm->focus($first);

        $fm->collect([$root]);

        check('焦点门控 · 稳态重收幂等（顺序不变、焦点不丢）', $fm->focused() === $first && $fm->order()[0] === $first);

        $this->flushChecks();
    }
}
