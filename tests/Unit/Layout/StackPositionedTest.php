<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Layout;

use Drift\Dev\Smoke\Layout\LayoutCountBox;
use Drift\Dev\Smoke\Layout\SolidBox;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderStack;
use Drift\Geometry\Alignment;
use Drift\Widget\SizedBox;
use Drift\Widget\Stack;
use Drift\Widget\Widget;

/**
 * A4（2026-10-28）：`Stack` 的**层叠定位**（`->positioned()`）。
 *
 * ## 补的是什么
 *
 * `Stack` 原来只有 `StackAlign`（九个方位，2026-10-29 退休为 `Alignment`）与 `StackFit`（约束口径）⇒ 子只能
 * **贴九个方位之一**。"右上角标（距右 8、距顶 8）"、"铺满整层的蒙版"都写不出来。
 * 这不是锦上添花：叠层布局缺了定位就是半残的。
 *
 * ## 三条裁决（每条都有对应的判据）
 *
 * | # | 裁决 | 判据 |
 * |---|---|---|
 * | ① | 定位是**修饰字段**，不是 `Positioned` 包装节点 | `positioned()` 写 `Modifier`，不产新盒 |
 * | ② | 声明**两条对边** ⇒ 那一轴被**拉伸** | `testTwoOppositeEdgesStretchTheAxis` |
 * | ③ | 定位子**不撑大 `Stack`** | `testPositionedChildDoesNotGrowTheStack` / `testStackOfOnlyPositionedChildrenIsZero` |
 *
 * ★★ ③ 不是"少做了一点"，它是这套设计**能单遍跑完**的前提：
 *    "定位子多大"要减"本盒多宽"，而本盒宽度来自 `max(子)` ⇒ 互为前提。
 *    断开这条依赖（定位子不参与 `max`）之后，顺序变成
 *    `量非定位子 → 定本盒尺寸 → 排定位子`，**每个子仍只 layout 一次**
 *    （`testEveryChildStillLaidOutOnce`，含定位子）。
 *
 * ★ ③ 的代价必须写清楚：**只有定位子的 `Stack` 尺寸是 0**，那种写法要自己套
 *   `SizedBox`。这是上游的同款行为，但它是"写了没反应"的一类 ⇒ 钉成**契约**
 *   （`testStackOfOnlyPositionedChildrenIsZero`），不是留着等人踩。
 *
 * ★ 纯布局 / 挂载后手动 `layout()`，不依赖帧驱动 ⇒ Unit 层。
 */
final class StackPositionedTest extends SmokeTestCase
{
    /** @param list<Widget> $kids */
    private static function stackBox(array $kids, ?Alignment $align = null): RenderStack
    {
        $stack = Stack::make()->children($kids);
        if ($align !== null) {
            $stack->alignment($align);
        }

        $element = $stack->createElement();
        $element->mount(null, smokeContext());
        $box = $element->findBoxOf(RenderStack::class);
        if ($box === null) {
            throw new \RuntimeException('Stack 没有挂出 RenderStack');
        }

        return $box;
    }

    /** 100×80 的主内容 + 一个 20×10 的角标（两条边都声明） */
    private static function badgeStack(): array
    {
        return [
            SizedBox::make()->size(100.0, 80.0),
            SizedBox::make()->size(20.0, 10.0)->positioned(right: 8.0, top: 8.0),
        ];
    }

    /**
     * 判据 1：角标落在"距右 8、距顶 8"（**不是**贴右上角的 0,0，也不是居中）。
     *
     * ★ 三个候选值互不相等，证伪得了：
     *   贴右上（100−20=80）/ 居中（40）/ 距右 8（**72**）/ 忽略定位（按 TopLeft = 0）。
     */
    public function testInsetPlacesTheChildRelativeToDeclaredEdges(): void
    {
        $stack = self::stackBox(self::badgeStack());
        $stack->layout(BoxConstraints::loose(new Size(300.0, 300.0)));

        $kids = $stack->children();
        expectBox($stack, 0.0, 0.0, 100.0, 80.0, 'Stack 定位 · 本盒尺寸 = 主内容（角标不撑大它）');
        expectBox(
            $kids[1] ?? null,
            72.0,
            8.0,
            20.0,
            10.0,
            'Stack 定位 · 距右 8 / 距顶 8 ⇒ (100−8−20, 8) = (72, 8)，尺寸仍是 20×10',
        );

        $this->flushChecks();
    }

    /**
     * 判据 2：声明**两条对边** ⇒ 那一轴被拉伸（CSS 的 `left:0;right:0`）。
     *
     * ★ 反例同场：**同一层不声明定位 ⇒ 0×0**（`SizedBox` 没有孩子、松约束下自然
     *   尺寸就是 0）。没有这条对照，"90×75"可能是"这盒子本来就这么大"。
     * ★ 分四次调用（每边一次）⇒ 顺带钉住"多次 `positioned()` 是叠加、不是覆盖"
     *   （与 `paddingOnly()` 的 `null = 不动这一边` 同口径）。
     */
    public function testTwoOppositeEdgesStretchTheAxis(): void
    {
        $overlay = SizedBox::make()
            ->positioned(left: 4.0)
            ->positioned(right: 6.0)
            ->positioned(top: 2.0)
            ->positioned(bottom: 3.0);

        $stack = self::stackBox([SizedBox::make()->size(100.0, 80.0), $overlay]);
        $stack->layout(BoxConstraints::loose(new Size(300.0, 300.0)));

        expectBox(
            $stack->children()[1] ?? null,
            4.0,
            2.0,
            90.0,
            75.0,
            'Stack 定位 · 四条边都声明 ⇒ 拉伸成 (100−4−6) × (80−2−3) = 90×75',
        );

        // 对照：不声明定位 ⇒ 松约束下是 0×0（证明上面那 90×75 是拉伸出来的）
        $plain = self::stackBox([SizedBox::make()->size(100.0, 80.0), SizedBox::make()]);
        $plain->layout(BoxConstraints::loose(new Size(300.0, 300.0)));
        expectBox(
            $plain->children()[1] ?? null,
            0.0,
            0.0,
            0.0,
            0.0,
            'Stack 定位 · 对照：同一层不声明定位 ⇒ 0×0（不是 90×75）',
        );

        $this->flushChecks();
    }

    /**
     * 判据 3：只声明**一条边** ⇒ 那一轴**不拉伸**（尺寸仍由内容定）。
     *
     * ★ 与判据 2 是一对：只给 `top` ⇒ 高 0；`top`+`bottom` ⇒ 高 75。
     *   少这条，"两条边才拉伸"会被"只要定位就拉伸"蒙混过去。
     */
    public function testSingleEdgeDoesNotStretch(): void
    {
        $stack = self::stackBox([SizedBox::make()->size(100.0, 80.0), SizedBox::make()->positioned(top: 8.0)]);
        $stack->layout(BoxConstraints::loose(new Size(300.0, 300.0)));

        expectBox(
            $stack->children()[1] ?? null,
            0.0,
            8.0,
            0.0,
            0.0,
            'Stack 定位 · 只声明 top ⇒ 高仍是自然尺寸 0（不是拉伸到 72）',
        );

        $this->flushChecks();
    }

    /**
     * 判据 4：没声明的那一轴退回 `Alignment`（不是默认贴左上）。
     *
     * ★ `TopRight` + 只声明 `top` ⇒ x = 100−20 = **80**（对齐给的），y = 8（定位给的）。
     *   两个来源必须**各管一轴** —— 混成一边的表现是"`alignment()` 突然失效"。
     */
    public function testUndeclaredAxisFallsBackToAlignment(): void
    {
        $content = new SolidBox(new Size(100.0, 80.0), 100.0, 80.0);
        $badge = new SolidBox(new Size(20.0, 10.0), 20.0, 10.0);
        $badge->setStackIntent(\NAN, 8.0, \NAN, \NAN);   // 只声明 top

        $stack = new RenderStack(Alignment::topRight());
        $stack->addChild($content);
        $stack->addChild($badge);
        $stack->layout(BoxConstraints::loose(new Size(300.0, 300.0)));

        check(
            'Stack 定位 · 未声明的横轴退回 Alignment（TopRight ⇒ x = 100−20 = 80，y 仍是定位给的 8）',
            $badge->offsetX() === 80.0 && $badge->offsetY() === 8.0,
            \sprintf('实际 (%.1f, %.1f)', $badge->offsetX(), $badge->offsetY()),
        );

        $this->flushChecks();
    }

    /**
     * 判据 5：定位子**不撑大 `Stack`**（内禀值口径）。
     *
     * ★ 用内禀值而不是"排出来的尺寸"来证：定位子的内禀 200×200 若算进来，
     *   这里就是 200 —— 而布局期它被约束夹住（甚至可能 C6）⇒ 尺寸那条路证不了。
     */
    public function testPositionedChildDoesNotGrowTheStack(): void
    {
        $content = new SolidBox(new Size(70.0, 30.0), 70.0, 30.0);
        $overlay = new SolidBox(new Size(10.0, 10.0), 200.0, 200.0);
        $overlay->setStackIntent(0.0, 0.0, 0.0, 0.0);

        $stack = new RenderStack();
        $stack->addChild($content);
        $stack->addChild($overlay);

        check(
            'Stack 定位 · 内禀值只算非定位子（200×200 的定位子不进来 ⇒ 仍是 70×30）',
            $stack->intrinsicWidth(0.0) === 70.0 && $stack->intrinsicHeight(0.0) === 30.0,
            \sprintf('实际 %.1f × %.1f', $stack->intrinsicWidth(0.0), $stack->intrinsicHeight(0.0)),
        );

        $this->flushChecks();
    }

    /**
     * 判据 6：**只有**定位子的 `Stack` 尺寸是 0 —— 这是**契约**，不是缺陷。
     *
     * ★ 上游同行为（定位子不参与 `Stack` 的尺寸）。钉在这里是因为它是"写了没反应"
     *   的一类：全用 `positioned()` 的蒙版层会让整个 `Stack` 塌成 0×0，而画面上
     *   **什么都看不到、也不报错**。修法是自己套 `SizedBox`（文档里写明的）。
     *
     * ★★ 这一条是**跑出来才看清的**：最初用 `SolidBox` 当那个子，结果撞 C6 Fatal
     *   （"画出的尺寸超出可用空间"）—— 因为 `SolidBox` **故意无视约束**。真控件会
     *   被夹到 0 ⇒ 静默消失。两种后果都源自同一条契约，这里取真控件那条。
     */
    public function testStackOfOnlyPositionedChildrenIsZero(): void
    {
        // ★ 用**会夹约束**的夹具（`LayoutCountBox`），不用 `SolidBox`：后者故意
        //   无视约束（它是 C6 的正向夹具）⇒ 在这里会撞"尺寸超出可用空间"的 Fatal，
        //   而真控件（文本 / 代理盒）是**静默被压到 0** —— 那才是用户会遇到的画面。
        $kid = new LayoutCountBox(new Size(20.0, 10.0));
        $kid->setStackIntent(\NAN, 8.0, \NAN, \NAN);

        $stack = new RenderStack();
        $stack->addChild($kid);
        $stack->layout(BoxConstraints::loose(new Size(300.0, 300.0)));

        expectBox($stack, 0.0, 0.0, 0.0, 0.0, 'Stack 定位 · 只有定位子 ⇒ 本盒 0×0（契约：定位子不撑大它）');
        check(
            'Stack 定位 · 那个子被压到 0×0（不是 20×10）⇒ 画面上什么都没有，也不报错',
            $kid->size()?->width === 0.0 && $kid->size()?->height === 0.0,
            \sprintf('实际 %.1f × %.1f', $kid->size()?->width ?? -1.0, $kid->size()?->height ?? -1.0),
        );

        $this->flushChecks();
    }

    /**
     * 判据 7：定位子也**只 layout 一次** —— "定位是第二趟"不能退化成"两遍布局"。
     *
     * ★ 这条守住 `RenderStack` 类注里"每个子只 layout 一次"那条不变量：定位子的
     *   layout 只是被**挪后**（等本盒尺寸定下来），不是多跑一遍。
     */
    public function testEveryChildStillLaidOutOnce(): void
    {
        $plain = new LayoutCountBox(new Size(30.0, 10.0));
        $pos = new LayoutCountBox(new Size(20.0, 10.0));
        $pos->setStackIntent(\NAN, 8.0, 8.0, \NAN);

        $stack = new RenderStack();
        $stack->addChild($plain);
        $stack->addChild($pos);
        $stack->layout(BoxConstraints::loose(new Size(300.0, 300.0)));

        check(
            'Stack 定位 · 定位子也只 layout 一次（两趟 ≠ 两遍）',
            $plain->layoutCalls === 1 && $pos->layoutCalls === 1,
            \sprintf('实际 %d / %d 次', $plain->layoutCalls, $pos->layoutCalls),
        );

        $this->flushChecks();
    }

    /**
     * 判据 8：命中测试跟着定位走（**画在哪就点得到哪**）。
     *
     * ★ 偏移只写进 `setOffset()` 的话，绘制与命中共用同一份 ⇒ 这条看着"必然"。
     *   但它防的是另一件事：**定位被算进尺寸/内距**的写法（画对、命中错位），
     *   以及"定位子被当成非定位子排了"（命中到主内容而不是角标）。
     */
    public function testHitTestFollowsThePositionedChild(): void
    {
        $stack = self::stackBox(self::badgeStack());
        $stack->layout(BoxConstraints::loose(new Size(300.0, 300.0)));
        $kids = $stack->children();

        check(
            'Stack 定位 · 点在角标上（75,12）命中角标（在上层）',
            ($stack->hitTest(75.0, 12.0) ?? null) === ($kids[1] ?? null),
            '实际命中 ' . ($stack->hitTest(75.0, 12.0) === ($kids[1] ?? null) ? '角标' : '别的'),
        );
        check(
            'Stack 定位 · 点在主内容上（5,5）命中主内容（不是角标）',
            ($stack->hitTest(5.0, 5.0) ?? null) === ($kids[0] ?? null),
            '实际命中 ' . ($stack->hitTest(5.0, 5.0) === ($kids[0] ?? null) ? '主内容' : '别的'),
        );

        $this->flushChecks();
    }
}
