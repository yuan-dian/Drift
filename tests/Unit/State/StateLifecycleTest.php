<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\State;

use Drift\Dev\Smoke\Tree\CounterState;
use Drift\Dev\Smoke\Tree\CounterWidget;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Widget\Element;

/**
 * `State` 的生命周期：**造 → 建 → 标脏 → 重建 → 换描述 → 回收**（§5.2 / §12.3）。
 *
 * ## 为什么这一组必须一条链走下来
 *
 * 六条判据共享同一个实例：`setState 只标脏` 靠 `builds` 没涨来证，`帧末重建后清脏`
 * 靠同一个 `builds` 涨到 2 来证，`重建不换 State` 靠同一个引用相等来证。
 * 拆成六个方法各建一个夹具，"只标脏"就退化成"新建的实例 builds=1"——**恒真**。
 *
 * ## 每条"写入了"都配一条"写入前是另一个值"
 *
 * - `bump()` 之前先断 `needsBuild() === false`（否则"标脏"没有对照）；
 * - `update()` 之前先断 State 手里**不是**新描述；
 * - `unmount()` 之前先断 `disposed === false`。
 *
 * 少这三条，"标脏 / 换描述 / dispose"在"从不改"的实现上照样绿。
 *
 * ★ 与 `SubjectBuilderTest` / `CellTest` / `ComputedTest` 分工：那三个判的是
 *   **订阅与门控**（谁在什么时候被通知），本文件只判 **State 自己的生命周期**。
 */
final class StateLifecycleTest extends SmokeTestCase
{
    /**
     * 挂一个 `CounterWidget`，返回 [Element, State]。
     *
     * ★ 夹具坏了（造出来的不是 `CounterState`）直接 `fail()`：后面全是属性读写，
     *   硬撑下去只会得到一串"读不存在的属性"的 warning + null 假绿。
     *
     * @return array{0: Element, 1: CounterState}
     */
    private static function mountCounter(): array
    {
        $element = (new CounterWidget())->createElement();
        $element->mount(null, smokeContext());
        $state = $element->state();
        if (!$state instanceof CounterState) {
            self::fail('CounterWidget 没造出 CounterState（夹具坏了）');
        }

        return [$element, $state];
    }

    /**
     * 挂载：State 就位、`build()` 经它出、且建出了一层子。
     *
     * ★ "造一次"只能验成"多次取到同一个"：`State` 的构造次数没有探针，
     *   标题不写"造一次"（写了就是过度承诺）。
     * ★ `seenWidth === 320.0` 是"`build()` 真的收到了渲染环境"的唯一证据 ——
     *   去掉它，`build()` 收到空 context 的实现也过。
     */
    public function testStateIsInPlaceAfterMount(): void
    {
        [$element, $state] = self::mountCounter();

        check(
            'State · 挂载后取到同一个 State（多次取不重造）',
            $element->state() === $state && $element->state() === $state
        );
        check(
            'State · build() 经 State 出，并收到渲染环境（builds=1 / 宽=320）',
            $state->builds === 1 && $state->seenWidth === 320.0,
            \sprintf('builds=%d seenWidth=%.1f', $state->builds, $state->seenWidth)
        );
        check(
            'State · 挂载时也建出了一层子（State + 子 = 2 个 Element）',
            countTree($element) === 2,
            'countTree=' . countTree($element)
        );
    }

    /**
     * `setState` **只标脏**，不立即重建；帧末 `rebuild()` 才跑 `build()` 并清脏。
     *
     * ★ 这条是 §5.2 的核心：立即重建会让"一帧内多次 setState"跑多次 build，
     *   而且重建发生在**栈中间**（盒还没排、绘制列表还没开）。
     * ★ 判据靠 `builds` 的**前后对照**（1 → 仍是 1 → 2），不是绝对值。
     */
    public function testSetStateOnlyMarksDirty(): void
    {
        [$element, $state] = self::mountCounter();

        check(
            'State · 前置：bump 之前不脏（否则下面"标脏"无对照）',
            $state->needsBuild() === false
        );

        $state->bump();
        check('State · setState 跑闭包', $state->bumps === 1, 'bumps=' . $state->bumps);
        check(
            'State · setState 只标脏，不立即重建（needsBuild=true 且 builds 仍是 1）',
            $state->needsBuild() === true && $state->builds === 1,
            \sprintf('needsBuild=%s builds=%d', $state->needsBuild() ? 'true' : 'false', $state->builds)
        );

        $element->rebuild();
        check(
            'State · 帧末重建后清脏（needsBuild=false 且 builds=2）',
            $state->needsBuild() === false && $state->builds === 2,
            \sprintf('needsBuild=%s builds=%d', $state->needsBuild() ? 'true' : 'false', $state->builds)
        );
        check(
            'State · 重建不换 State（纯 UI 态活过整个 build）',
            $element->state() === $state
        );
    }

    /**
     * 换描述（`Element::update()`）：State 跟着走，且拿到的是**整份新的** Widget。
     *
     * ★ 两条成对：只验"State 不换"会奖励"State 手里还攥着旧 Widget"（后续读配置
     *   全读旧的，而界面看不出来）。
     */
    public function testStateSurvivesWidgetUpdate(): void
    {
        [$element, $state] = self::mountCounter();
        $next = new CounterWidget();

        check(
            'State · 前置：update 之前 State 手里是旧描述（否则下面"换成新的"恒真）',
            $state->widget() !== $next
        );

        $element->update($next);
        check('State · 换新描述时 State 不换（状态活过更新）', $element->state() === $state);
        check('State · State 手里的 Widget 换成整份新的', $state->widget() === $next);
    }

    /**
     * 卸载 ⇒ `dispose()`（§12.3 回收纪律）。
     *
     * ★ 前置 `disposed === false` 不能省：`dispose()` 的默认实现什么都不做，
     *   "卸载时没调"和"调了但没效果"在只看 `true` 的断言下完全一样。
     */
    public function testUnmountDisposes(): void
    {
        [$element, $state] = self::mountCounter();

        check(
            'State · 前置：卸载前 disposed=false（否则下面那条恒真）',
            $state->disposed === false
        );

        $element->unmount();
        check('State · unmount 调 dispose()（§12.3 回收纪律）', $state->disposed === true);
    }
}
