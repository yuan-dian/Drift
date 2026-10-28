<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Input;

use Drift\App\FocusManager;
use Drift\Dev\Smoke\App\CursorApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\CursorShape;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Widget\Center;
use Drift\Widget\Element;
use Drift\Widget\Padding;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 光标形状（§9.1「一次命中，三方消费」的第 ③ 方）。
 *
 * 三方 = 事件路由 / 语义 / **光标形状**。前两方早就在（`focusHit()`；语义树是 M4 的活）；
 * 第 ③ 方此前**完全缺位** ⇒ 真机窗口的指针永远是箭头，**且不报错**
 * （§9.1 只把"光标形状"写进了名字，没有一行代码兑现它）。
 *
 * 本组只判**解释器面判得了的**：形状表（= 桥的 ABI）· 声明落到盒上 · 命中链取最近者 ·
 * 应用层出口。**"系统光标真的变成手形"只能真机判**（`GetCursorInfo`），
 * 不在这里假装 —— 那是 §20 纪律 1 的双向量具纪律。
 *
 * ★ 值表为什么不单独放 Unit：`CursorShape` 的 value 不是"自己的编号"，是桥的 `switch`
 *   能认的编号。它的正确性判据只有一条 —— "形状进到窗口壳里能被认出来"，而这条要到
 *   应用出口才闭合。拆成两个文件会在排查"光标不对"时多跳一次。
 */
final class CursorShapeTest extends SmokeTestCase
{
    /**
     * ★ 用 tight 约束：`Center` 的盒是**贴身**的（size = 孩子 size），loose 下盒只有
     *   文字那么大 ⇒ 命中点得算字体度量。tight 下盒铺满，命中点取 (10,10) 即可，
     *   与度量无关（§20 纪律 1：断言不该依赖它不打算测的东西）。
     */
    private function tight(Widget $root): Element
    {
        $el = $root->createElement();
        $el->mount(null, smokeContext());
        $el->effectiveBox()?->layout(BoxConstraints::tight(new Size(300.0, 200.0)));

        return $el;
    }

    // ① 形状表 = 桥的 ABI。改任一 value 等于改协议，必须响亮。
    public function testShapeTableIsBridgeAbi(): void
    {
        check('光标 · Default 是 0（= 桥的 IDC_ARROW，兜底值）', CursorShape::Default->value === 0);
        check(
            '光标 · IBeam=1 / PointingHand=2（★ value 就是桥的 ABI：改它等于改协议）',
            CursorShape::IBeam->value === 1 && CursorShape::PointingHand->value === 2,
        );
        $shapeValues = array_map(static fn (CursorShape $c): int => $c->value, CursorShape::cases());
        check(
            '光标 · 8 个 case、value 恰好是 0..7 且互不重复（桥的 switch 覆盖面）',
            $shapeValues === [0, 1, 2, 3, 4, 5, 6, 7],
            'values=' . implode(',', $shapeValues),
        );
        check(
            '光标 · from() 往返（桥只传编号，PHP 侧能还原形状）',
            CursorShape::from(2) === CursorShape::PointingHand,
        );
    }

    // ② 声明：Widget::cursor() ⇒ 盒上的 RenderObject::cursor()
    public function testDeclarationLandsOnBox(): void
    {
        check(
            '光标 · 没声明 ⇒ cursorShape() 为 null（"我不管，问上面"）',
            Text::make('x')->cursorShape() === null,
        );
        check(
            '光标 · Widget::cursor() 是声明（链式 + 可读回）',
            Text::make('x')->cursor(CursorShape::IBeam)->cursorShape() === CursorShape::IBeam,
        );

        $root = $this->tight(
            Center::make()->cursor(CursorShape::PointingHand)->child(Text::make('hit me'))
        );
        check(
            '光标 · 声明落到了盒上（Element::syncBox 是唯一落点）',
            $root->effectiveBox()?->cursor() === CursorShape::PointingHand,
        );
    }

    // ③ 命中链：最近声明者赢；自己没声明就上溯；整条链没人声明是 Default（不是 null）
    public function testHitChainResolvesNearest(): void
    {
        $fm = new FocusManager();

        // 内层声明盖过外层：Padding(Wait) > Center(PointingHand) > Text(无)
        $nest = $this->tight(
            Padding::make(Center::make()->cursor(CursorShape::PointingHand)->child(Text::make('deep')))
                ->cursor(CursorShape::Wait)
        );
        $nestHit = $nest->effectiveBox()?->hitTest(10.0, 10.0);
        check(
            '光标 · 命中链取**最近**声明者（内层 PointingHand 盖过外层 Wait）',
            $fm->cursorOf($nestHit) === CursorShape::PointingHand,
            'resolved=' . $fm->cursorOf($nestHit)->name . '｜hit=' . ($nestHit === null ? 'null' : $nestHit::class),
        );

        // 命中者自己没声明 ⇒ 沿父链上溯
        $outer = $this->tight(
            Padding::make(Center::make()->child(Text::make('deep')))->cursor(CursorShape::Wait)
        );
        $outerHit = $outer->effectiveBox()?->hitTest(10.0, 10.0);
        check(
            '光标 · 命中者自己没声明 ⇒ 沿父链上溯到声明者（Wait）',
            $fm->cursorOf($outerHit) === CursorShape::Wait,
            'resolved=' . $fm->cursorOf($outerHit)->name . '｜hit=' . ($outerHit === null ? 'null' : $outerHit::class),
        );

        $bare = $this->tight(Center::make()->child(Text::make('bare')));
        check(
            '光标 · 整条链没人声明 ⇒ Default（★ 不是 null：Default 是**明确决定**）',
            $fm->cursorOf($bare->effectiveBox()?->hitTest(10.0, 10.0)) === CursorShape::Default,
        );
        check('光标 · 没命中任何盒（点在根外）⇒ Default', $fm->cursorOf(null) === CursorShape::Default);
    }

    // ④ 应用面：dispatchInput() 的返回值**就是**喂给 WindowHost::setCursor() 的形状
    public function testApplicationExit(): void
    {
        $app = new CursorApplication();
        check(
            '光标 · 未挂载 ⇒ dispatchInput 返回 null（没有树就没有路由）',
            $app->dispatchInput(PointerEvent::down(1.0, 1.0)) === null,
        );

        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
        check(
            '光标 · 按键不吃光标（dispatchInput(KeyEvent) 返回 null，焦点链照常走）',
            $app->dispatchInput(KeyEvent::down(KeyChord::tab())) === null,
        );
        check(
            '光标 · 指针落在控件上 ⇒ dispatchInput 返回声明形状（第 ③ 方的出口）',
            $app->dispatchInput(PointerEvent::down(160.0, 120.0)) === CursorShape::PointingHand,
        );
        check(
            '光标 · 指针落在窗口外 ⇒ 返回 Default（壳据此把光标还原成箭头）',
            $app->dispatchInput(PointerEvent::down(-5.0, -5.0)) === CursorShape::Default,
        );
    }
}
