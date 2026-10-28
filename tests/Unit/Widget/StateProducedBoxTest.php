<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Dev\Smoke\Layout\SolidBox;
use Drift\Dev\Smoke\State\LeafBoxState;
use Drift\Dev\Smoke\State\LeafBoxWidget;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderSpinner;
use Drift\Widget\SizedBox;
use Drift\Widget\Spinner;

/**
 * `createRenderObjectFromState()`：有态控件直接产盒（`02` §6.2）。
 *
 * 以前"有态"与"有盒"必须是两个类（`Spinner → SpinnerState → SpinnerBox`，第三类的
 * 唯一职责是把相位递进去）。现在 StatefulWidget 可以从 State 直接产盒 ⇒
 * 叶子控件两段就够，`SpinnerBox` 已删。
 *
 * ★ 夹具 `LeafBoxWidget` 数 `build()` 调用次数 —— 叶子路径下一次都不该调。
 *   反向自检（不覆写钩子 ⇒ build 必须被调）留在 smoke 的子进程用例里：
 *   它靠 `runCase(__FILE__, ...)` 起新进程，`__FILE__` 必须是入口脚本。
 * ★ 这条路的判据是"**没走** build"，所以三条正例必须一起看：
 *   有盒 + 无子 + 计数为 0。只验"有盒"的话，走 build 也能有盒。
 */
final class StateProducedBoxTest extends SmokeTestCase
{
    // ① 叶子路径：有盒、无子、build 零调用
    public function testLeafStateWidgetProducesBoxWithoutBuild(): void
    {
        LeafBoxWidget::resetBuildCalls();
        $root = LeafBoxWidget::make()->createElement();
        $root->mount(null, smokeContext());

        check(
            '叶子态控件 · 挂载即有盒（State 直接产盒，不经过 build）',
            $root->effectiveBox() instanceof SolidBox,
        );
        check(
            '叶子态控件 · 没有子节点（叶子语义：不产子）',
            $root->childCount() === 0,
            'childCount=' . $root->childCount(),
        );
        check(
            '叶子态控件 · `State::build()` 零调用',
            LeafBoxWidget::$buildCalls === 0,
            'calls=' . LeafBoxWidget::$buildCalls,
        );
    }

    // ② 直接产盒那条路上，修饰仍要落在这个盒上（漏了会撞 §11.7 守卫）
    public function testModsLandOnOwnBox(): void
    {
        $root = LeafBoxWidget::make()->paddingAll(8.0)->createElement();
        $root->mount(null, smokeContext());
        check(
            '叶子态控件 · 修饰落在自己身上（`setMods()` 没被漏掉 —— 漏了会撞 §11.7 守卫）',
            $root->effectiveBox()?->mods()?->padLeft() === 8.0,
        );
    }

    // ③ Spinner 也走这条路（它的 SpinnerBox 已删）—— 结构上不可能再走 build
    public function testSpinnerUsesLeafPath(): void
    {
        $root = Spinner::make()->diameter(30.0)->createElement();
        $root->mount(null, smokeContext());
        check(
            'Spinner · 走叶子路径（有盒、无子 ⇒ 不再需要 SpinnerBox）',
            $root->effectiveBox() instanceof RenderSpinner && $root->childCount() === 0,
            'box=' . ($root->effectiveBox() === null ? 'null' : $root->effectiveBox()::class)
            . ' childCount=' . $root->childCount(),
        );
    }

    // ④ `State::build()` 的默认实现（§6.3）：只为"直接产盒"的控件存在，返回零尺寸
    public function testStateDefaultBuildReturnsZeroSizedBox(): void
    {
        $built = (new LeafBoxState())->build(smokeContext());
        $root = $built->createElement();
        $root->mount(null, smokeContext());
        $root->effectiveBox()?->layout(BoxConstraints::loose(new Size(100.0, 100.0)));
        check(
            'State::build() 默认实现 · 返回零尺寸盒（不再抽象，叶子控件不需要它）',
            $built instanceof SizedBox
                && $root->effectiveBox()?->size()?->width === 0.0
                && $root->effectiveBox()?->size()?->height === 0.0,
        );
    }
}
