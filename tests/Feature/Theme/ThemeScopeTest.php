<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Theme;

use Drift\Dev\Smoke\Theme\ThemeSpy;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Layout\RenderFlex;
use Drift\Theme\ThemeData;
use Drift\Theme\ThemeScope;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Text;

/**
 * ThemeScope：面覆盖（§2446 / §3171）。
 *
 * ## "覆盖不外溢"与"最内层赢"是两条独立判据
 *
 * 前者防"设一次主题全树变色"（scope 泄漏成全局），后者防"外层盖住内层"
 * （引入特异性权重那一套）。只有一半的话，另一条挂了看不出来。
 *
 * ## 切主题的两种兑现时机必须分开验
 *
 * - **有状态**节点：只标脏、等帧末 —— 事件中途重建会让同一回调里读到两代树。
 * - **无状态**节点：**立即**重建 —— 它的盒是按 ctx 算的，不重算就是"换表不换色"。
 *
 * 后者还分叶子（自己就是盒）与容器（盒里还要装子盒）两种，**不重复**：
 * 容器验的是 §13.2 的顺序（先换盒、再挂子）—— 反了会让新盒为空，整棵子树静默消失。
 */
final class ThemeScopeTest extends SmokeTestCase
{
    public function testScopeOverridesAndDoesNotLeak(): void
    {
        $context = smokeContext();

        // ① 子树看到的是覆盖后的主题，外层不受影响
        $lightRoot = ThemeScope::make(ThemeData::light(), new ThemeSpy())->createElement();
        $lightRoot->mount(null, $context);
        $lightSpy = $lightRoot->childAt(0)?->state();
        check('ThemeScope · 外层是浅色 ⇒ 子层看到浅色', $lightSpy !== null && !$lightSpy->sawDark);

        $darkRoot = ThemeScope::make(ThemeData::dark(), new ThemeSpy())->createElement();
        $darkRoot->mount(null, $context);
        $darkSpy = $darkRoot->childAt(0)?->state();
        check('ThemeScope · 子树看到覆盖后的暗色', $darkSpy !== null && $darkSpy->sawDark);
        check('ThemeScope · 覆盖不外溢（外层 ctx 仍是浅色）', !$context->theme()->isDark());

        $this->flushChecks();
    }

    /** ② 嵌套 ⇒ 最内层赢，没有特异性权重（§2446 原话"谁生效查父链"） */
    public function testInnermostScopeWins(): void
    {
        $context = smokeContext();

        $nested = ThemeScope::make(
            ThemeData::light(),
            ThemeScope::make(ThemeData::dark(), new ThemeSpy()),
        )->createElement();
        $nested->mount(null, $context);
        $nestedSpy = $nested->childAt(0)?->childAt(0)?->state();
        check('ThemeScope · 嵌套 ⇒ 最内层赢（不算特异性权重）', $nestedSpy !== null && $nestedSpy->sawDark);

        $this->flushChecks();
    }

    /** ③ 切换主题 ⇒ 有状态子层**只标脏**，帧末才兑现 */
    public function testStatefulChildDefersToFrameEnd(): void
    {
        $context = smokeContext();

        $scopedChild = new ThemeSpy(); // Widget 不可变 ⇒ 复用一个实例（换主题不换控件）
        $switchRoot = ThemeScope::make(ThemeData::light(), $scopedChild)->createElement();
        $switchRoot->mount(null, $context);
        $switchSpy = $switchRoot->childAt(0)?->state();
        check('ThemeScope · 切换前是浅色', $switchSpy !== null && !$switchSpy->sawDark);
        $switchRoot->update(ThemeScope::make(ThemeData::dark(), $scopedChild));
        check(
            'ThemeScope · 切主题 ⇒ 有状态子层先只标脏（§5.2 裁决 3，不在事件中途重建）',
            $switchSpy !== null && $switchSpy->needsBuild() && $switchSpy->builds === 1
        );
        $switchRoot->settle(); // 帧末：与 Frame.run 走同一条 settle()
        check(
            'ThemeScope · 帧末兑现 ⇒ 子层看到暗色，且 State 没换（切主题不丢状态）',
            $switchSpy !== null && $switchSpy->sawDark && $switchSpy->builds === 2
                && $switchRoot->childAt(0)?->state() === $switchSpy
        );

        $this->flushChecks();
    }

    /**
     * ④ 无状态控件**立即**兑现：它的盒是按 ctx 算的，不重算就是"换表不换色"。
     *
     * ★ ④ 与 ④b **不重复**：这里测叶子（自己就是盒），④b 测容器（盒里还要装子盒）。
     */
    public function testStatelessWidgetRebuildsImmediately(): void
    {
        $context = smokeContext();

        $scopedButton = Button::make('确定');
        $buttonScope = ThemeScope::make(ThemeData::light(), $scopedButton)->createElement();
        $buttonScope->mount(null, $context);
        $lightBox = $buttonScope->childAt(0)?->renderObject();
        check('ThemeScope · scope 内按钮先取到浅色表', $lightBox?->boxStyle()->background !== null);
        $buttonScope->update(ThemeScope::make(ThemeData::dark(), $scopedButton));
        $darkBox = $buttonScope->childAt(0)?->renderObject();
        check(
            'ThemeScope · 切主题 ⇒ 无状态控件的盒立即重算（不是下一帧）',
            $darkBox !== null && $darkBox !== $lightBox
                && $darkBox->boxStyle()->background !== $lightBox->boxStyle()->background
        );

        // ④b **容器**也要立即换盒**且子盒已挂上**（§13.2：先换盒、再挂子）。
        //    顺序反了（先 rebuild 后 syncBox）会让新盒为空 ⇒ 容器整棵子树静默消失。
        $scopedColumn = Column::make()->children([Text::make('a'), Text::make('b')]);
        $columnScope = ThemeScope::make(ThemeData::light(), $scopedColumn)->createElement();
        $columnScope->mount(null, $context);
        $columnScope->update(ThemeScope::make(ThemeData::dark(), $scopedColumn));
        $columnBox = $columnScope->childAt(0)?->renderObject();
        check(
            'ThemeScope · 切主题 ⇒ 容器的盒立即重算**且子盒已挂上**（§13.2 顺序）',
            $columnBox instanceof RenderFlex && count($columnBox->children()) === 2,
            sprintf(
                'box=%s kids=%s',
                $columnBox === null ? 'null' : $columnBox::class,
                $columnBox instanceof RenderFlex ? (string) count($columnBox->children()) : 'n/a',
            ),
        );

        $this->flushChecks();
    }

    /** ⑤ 端到端：同一按钮在两份表下取到不同底色（§15.7 纪律 3 只解析一次） */
    public function testSameButtonResolvesDifferentColors(): void
    {
        $context = smokeContext();

        $darkButton = Button::make('确定')->createRenderObject(
            (new \Drift\Widget\BuildContext(320.0, 240.0, ThemeData::dark(), $context->metrics()))
        );
        $lightButton = Button::make('确定')->createRenderObject(
            (new \Drift\Widget\BuildContext(320.0, 240.0, ThemeData::light(), $context->metrics()))
        );
        check(
            'ThemeScope · 同一按钮在两份表下取到不同底色（§15.7 纪律 3 只解析一次）',
            $darkButton->boxStyle()->background !== $lightButton->boxStyle()->background
        );

        // ⑤ ThemeScope 是结构节点：W5 起也有 `background()`（修饰是字段），但不产 BoxStyle
        check(
            'ThemeScope · 结构节点也有 background()，但不产 BoxStyle',
            method_exists(ThemeScope::class, 'background') && !method_exists(ThemeScope::class, 'box')
        );

        $this->flushChecks();
    }
}
