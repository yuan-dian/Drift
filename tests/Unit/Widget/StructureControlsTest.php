<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\Overflow;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderFlex;
use Drift\Layout\RenderProxy;
use Drift\Widget\Center;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Expanded;
use Drift\Widget\Padding;
use Drift\Widget\Row;
use Drift\Widget\Spacer;

/**
 * 结构控件（Column / Row / Padding / Center / Spacer / Expanded）。
 *
 * 判据（与 `tools/smoke.php` ⑱ 段一致）：
 *   ① 结构节点只管"挂接 + 布局"，不画、不进语义、没有自己的盒样式存储、没有 build()；
 *   ② flex 分配：弹性子整组拿走全部剩余（分母只含非弹性子）—— 这是 2026-10-17 修的
 *      双重扣减病灶，A/B 是正面判据、C 是溢出行为不变守卫；
 *   ③ Center：tight 下推到正中、loose 下盒=内容尺寸且子不越界、带 padding 偏移按内容区算。
 *
 * ★ 纯布局，不依赖帧驱动 ⇒ Unit 层。
 */
final class StructureControlsTest extends SmokeTestCase
{
    private function flexHeights(?RenderBox $flexBox): array
    {
        $heights = [];
        $flexBox?->visitChildren(static function (RenderBox $kid) use (&$heights): void {
            $heights[] = $kid->size()?->height ?? -1.0;
        });

        return $heights;
    }

    public function testColumnBuildsRenderFlexAndChildren(): void
    {
        $ctx = smokeContext();
        $columnRoot = Column::make()->children([Spacer::make(), Spacer::make(3)])->createElement();
        $columnRoot->mount(null, $ctx);
        $columnFlex = $columnRoot->renderObject();
        check('结构控件 · Column 建出 RenderFlex', $columnFlex instanceof RenderFlex);
        check(
            '结构控件 · 结构节点的子 Box 也建了盒',
            $columnRoot->childCount() === 2 && $columnRoot->childAt(0)?->renderObject() !== null,
        );

        $this->flushChecks();
    }

    public function testEndToEndFlexDistribution(): void
    {
        $ctx = smokeContext();
        $columnRoot = Column::make()->children([Spacer::make(), Spacer::make(3)])->createElement();
        $columnRoot->mount(null, $ctx);
        $columnFlex = $columnRoot->renderObject();
        $columnFlex?->layout(BoxConstraints::tight(new Size(100.0, 60.0)));
        $columnHeight = [];
        $columnFlex?->visitChildren(static function (RenderBox $kid) use (&$columnHeight): void {
            $columnHeight[] = $kid->size()?->height ?? -1.0;
        });
        check(
            '结构控件 · 端到端 flex 分配（Spacer 1:3 ⇒ 15 / 45，不是各 30）',
            $columnHeight === [15.0, 45.0],
            '实际 ' . implode(' / ', $columnHeight),
        );

        $this->flushChecks();
    }

    public function testRowMainAxisIsHorizontal(): void
    {
        $ctx = smokeContext();
        $rowRoot = Row::make()->children([Spacer::make(), Spacer::make(3)])->createElement();
        $rowRoot->mount(null, $ctx);
        $rowFlex = $rowRoot->renderObject();
        $rowFlex?->layout(BoxConstraints::tight(new Size(120.0, 40.0)));
        $rowWidth = [];
        $rowFlex?->visitChildren(static function (RenderBox $kid) use (&$rowWidth): void {
            $rowWidth[] = $kid->size()?->width ?? -1.0;
        });
        check(
            '结构控件 · Row 主轴是横（1:3 分 120 ⇒ 30 / 90）',
            $rowWidth === [30.0, 90.0],
            '实际 ' . implode(' / ', $rowWidth),
        );

        $this->flushChecks();
    }

    public function testFlexDenominatorElasticTakesRemaining(): void
    {
        $ctx = smokeContext();

        // A：非弹性子 100 + 弹性子自然 500，视口 300 ⇒ 弹性子拿 200（旧口径 300−600<0 ⇒ 0）
        $fxA = Column::make()->children([
            Padding::make(Spacer::make())->all(50),
            Expanded::make(Padding::make(Spacer::make())->all(250)),
        ])->createElement();
        $fxA->mount(null, $ctx);
        $fxABox = $fxA->renderObject();
        $fxABox?->layout(BoxConstraints::tight(new Size(100.0, 300.0)));
        check(
            'Flex · 弹性内容比视口长 ⇒ 拿全部剩余（300−100=200；旧口径拿 0 整块不可见）',
            $this->flexHeights($fxABox) === [100.0, 200.0],
            '实际 ' . implode(' / ', $this->flexHeights($fxABox)),
        );

        // B：非弹性子 100 + 弹性子自然 40，视口 300 ⇒ 弹性子拿 200（旧口径 300−140=160，白留 40 死空间）
        $fxB = Column::make()->children([
            Padding::make(Spacer::make())->all(50),
            Expanded::make(Padding::make(Spacer::make())->all(20)),
        ])->createElement();
        $fxB->mount(null, $ctx);
        $fxBBox = $fxB->renderObject();
        $fxBBox?->layout(BoxConstraints::tight(new Size(100.0, 300.0)));
        check(
            'Flex · 弹性内容比剩余短 ⇒ 填满剩余（200；旧口径只拿 160 白留死空间）',
            $this->flexHeights($fxBBox) === [100.0, 200.0],
            '实际 ' . implode(' / ', $this->flexHeights($fxBBox)),
        );

        // C：两个非弹性子 400 > 视口 300 ⇒ free<0 ⇒ 弹性子拿 0、盒夹到 300（C5 溢出照旧）
        $fxC = Column::make()->children([
            Padding::make(Spacer::make())->all(100),
            Padding::make(Spacer::make())->all(100),
            Expanded::make(Padding::make(Spacer::make())->all(50)),
        ])->overflow(Overflow::Clip)->createElement();
        $fxC->mount(null, $ctx);
        $fxCBox = $fxC->renderObject();
        $fxCBox?->layout(BoxConstraints::tight(new Size(100.0, 300.0)));
        check(
            'Flex · 非弹性子溢出（400>300）⇒ 弹性子拿 0、盒夹到 300（既有溢出行为不变）',
            $this->flexHeights($fxCBox) === [200.0, 200.0, 0.0] && $fxCBox?->size()?->height === 300.0,
            'kids=' . implode(' / ', $this->flexHeights($fxCBox)) . ' box=' . (string) ($fxCBox?->size()?->height ?? -1.0),
        );

        $this->flushChecks();
    }

    public function testPaddingGeometry(): void
    {
        $ctx = smokeContext();
        $padRoot = Padding::make(Spacer::make())->all(10.0)->createElement();
        $padRoot->mount(null, $ctx);
        $padBox = $padRoot->renderObject();
        check('结构控件 · Padding 建出 RenderProxy（内距走 mods）', $padBox instanceof RenderProxy);
        $padBox?->layout(BoxConstraints::loose(new Size(80.0, 80.0)));
        check(
            '结构控件 · Padding 进盒尺寸（孩子 0 + 上下各 10）',
            $padBox?->size()?->height === 20.0,
            '实际 ' . (string) ($padBox?->size()?->height ?? -1.0),
        );

        $this->flushChecks();
    }

    public function testCenterPushesChildToCenter(): void
    {
        $ctx = smokeContext();
        $centerRoot = Center::make()->child(Spacer::make())->createElement();
        $centerRoot->mount(null, $ctx);
        $centerBox = $centerRoot->renderObject();
        check(
            '结构控件 · Center 建出 RenderProxy（居中走 mods）',
            $centerBox instanceof RenderProxy && $centerBox->mods()->hasCenter(),
        );
        $centerBox?->layout(BoxConstraints::tight(new Size(100.0, 100.0)));
        $centerKid = null;
        $centerBox?->visitChildren(static function (RenderBox $kid) use (&$centerKid): void {
            $centerKid = $kid;
        });
        check(
            '结构控件 · Center 把孩子推到正中（tight 100×100 里 offset 50,50）',
            $centerKid !== null && $centerKid->offsetX() === 50.0 && $centerKid->offsetY() === 50.0,
            sprintf('offset (%.6g, %.6g)', $centerKid?->offsetX() ?? -1.0, $centerKid?->offsetY() ?? -1.0),
        );

        $this->flushChecks();
    }

    public function testCenterLooseConstraintsKeepChildInside(): void
    {
        $ctx = smokeContext();
        // 松约束：盒 = 内容尺寸，子**不许**被推出盒外（2026-10-23 修）
        $centerLooseRoot = Center::make()->child(Spacer::make())->createElement();
        $centerLooseRoot->mount(null, $ctx);
        $centerLooseBox = $centerLooseRoot->renderObject();
        $centerLooseBox?->layout(BoxConstraints::loose(new Size(100.0, 100.0)));
        $centerLooseKid = null;
        $centerLooseBox?->visitChildren(static function (RenderBox $kid) use (&$centerLooseKid): void {
            $centerLooseKid = $kid;
        });
        check(
            '结构控件 · Center 松约束：盒 = 内容尺寸，子偏移 0（不被推出盒外）',
            $centerLooseBox?->size()?->width === 0.0
                && $centerLooseKid !== null
                && $centerLooseKid->offsetX() === 0.0
                && $centerLooseKid->offsetY() === 0.0,
            sprintf(
                '盒 %.6g×%.6g，子 offset (%.6g, %.6g)',
                $centerLooseBox?->size()?->width ?? -1.0,
                $centerLooseBox?->size()?->height ?? -1.0,
                $centerLooseKid?->offsetX() ?? -1.0,
                $centerLooseKid?->offsetY() ?? -1.0,
            ),
        );

        $this->flushChecks();
    }

    public function testCenterWithPaddingOffsetsByContentArea(): void
    {
        $ctx = smokeContext();
        // 带 padding：偏移按「内容区 − 内容」算（2026-10-23 修）
        $centerPadRoot = Center::make()->child(Spacer::make())->paddingAll(10.0)->createElement();
        $centerPadRoot->mount(null, $ctx);
        $centerPadBox = $centerPadRoot->renderObject();
        $centerPadBox?->layout(BoxConstraints::tight(new Size(100.0, 100.0)));
        $centerPadKid = null;
        $centerPadBox?->visitChildren(static function (RenderBox $kid) use (&$centerPadKid): void {
            $centerPadKid = $kid;
        });
        check(
            '结构控件 · Center + padding(10)：偏移按内容区 80 算 ⇒ (80−0)/2 = 40',
            $centerPadKid !== null && $centerPadKid->offsetX() === 40.0 && $centerPadKid->offsetY() === 40.0,
            sprintf('offset (%.6g, %.6g)', $centerPadKid?->offsetX() ?? -1.0, $centerPadKid?->offsetY() ?? -1.0),
        );

        $this->flushChecks();
    }

    /**
     * 结构节点不该有**自己的盒样式存储**。
     *
     * ★ 原文是「不是 `BoxWidget`」—— `BoxWidget` 在 S1（2026-10-28）已删（它那三个
     *   方法与 `Widget` 的修饰面重复，且构成了第二份样式存储）。判据改成表达本意：
     *   **结构节点的样式只能走 `$mods`**（走 `paintBehind()` 的装饰层），不能自带
     *   一份 `BoxStyle` 让子类去自绘 —— 那会让"结构节点没有绘制样式"这条纪律重新
     *   变成口头约定。
     */
    public function testStructureNodesHaveNoOwnStyleStorage(): void
    {
        $stray = [];
        foreach ([Column::class, Row::class, Spacer::class] as $cls) {
            foreach ((new \ReflectionClass($cls))->getProperties() as $prop) {
                if ((string) $prop->getType() === \Drift\Rendering\BoxStyle::class) {
                    $stray[] = $cls . '::$' . $prop->getName();
                }
            }
        }

        check(
            '结构控件 · Column / Row / Spacer 没有自己的 BoxStyle 存储（样式只能走 mods）',
            $stray === [],
            \implode(', ', $stray),
        );

        $this->flushChecks();
    }

    public function testStructureNodesHaveNoBuild(): void
    {
        check(
            '结构控件 · Column / Row 上没有 build()（结构节点形态）',
            !method_exists(Column::class, 'build') && !method_exists(Row::class, 'build'),
        );

        $this->flushChecks();
    }

    public function testUpdateSwapsBox(): void
    {
        $ctx = smokeContext();
        // 换 children：Element 复用，盒重造（§13.2）
        $growRoot = Column::make()->children([Spacer::make()])->createElement();
        $growRoot->mount(null, $ctx);
        $firstBox = $growRoot->renderObject();
        $growRoot->update(Column::make()->children([Spacer::make(2)]));
        check(
            '结构控件 · update 换描述 ⇒ 换盒（§13.2：不存在字段级半同步）',
            $growRoot->renderObject() !== null && $growRoot->renderObject() !== $firstBox,
        );

        $this->flushChecks();
    }
}
