<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Layout;

use Drift\Dev\Smoke\Layout\ConstraintSpy;
use Drift\Dev\Smoke\Layout\SolidBox;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Size;
use Drift\Layout\FlexAlign;
use Drift\Layout\Overflow;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderFlex;
use Drift\Layout\RenderProxy;

/**
 * RenderFlex 布局引擎（与 `tools/smoke.php` ⑮ / ⑮b / ⑮c + Clip 段一致）。
 *
 * 四个子主题各守一类不变量：
 *   ⑮  份额分配 + spacing + 交叉轴 + padding（纯 grow 快路径）；
 *   ⑮b basis / shrink 公式（allocateAdvanced：加权压缩 / 冻结迭代 / 增量语义）；
 *   ⑮c alignSelf 覆盖容器（END / CENTER / STRETCH / INHERIT 四态一起才有区分度）；
 *   Clip 允许子溢出但盒夹进约束（不撞 C6）。
 *
 * ★ 纯布局，不依赖帧驱动 ⇒ Unit 层。
 */
final class FlexTest extends SmokeTestCase
{
    private static function flexProxy(int $share): RenderProxy
    {
        $box = new RenderProxy();
        $box->setFlexIntent($share, 0, \NAN, RenderBox::ALIGN_INHERIT);

        return $box;
    }

    private static function flexIntent(int $grow, int $shrink, float $basis): RenderProxy
    {
        $box = new RenderProxy();
        $box->setFlexIntent($grow, $shrink, $basis, RenderBox::ALIGN_INHERIT);

        return $box;
    }

    public function testC4ElasticChildMeasuresOnce(): void
    {
        $elasticSpy = new ConstraintSpy(new Size(40.0, 30.0));
        $plainSpy = new ConstraintSpy(new Size(40.0, 10.0));
        $c4Column = new RenderFlex(true, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Clip);
        $c4Column->addChild($plainSpy);
        $c4Column->addChild(self::flexProxy(1));
        $c4Column->children()[1]->setChild($elasticSpy);
        $c4Column->layout(new BoxConstraints(0.0, 100.0, 0.0, 60.0));
        check(
            'Layout · C4 弹性子只在份额定下后量一次（拿紧主轴，不再白排一遍）',
            $elasticSpy->firstSeen !== null
                && $elasticSpy->firstSeen === $elasticSpy->lastSeen
                && $elasticSpy->firstSeen->maxHeight === 50.0,
            sprintf(
                '实际第一遍 maxHeight=%.1f（与 lastSeen 同一实例：%s）',
                $elasticSpy->firstSeen?->maxHeight ?? -1.0,
                $elasticSpy->firstSeen === $elasticSpy->lastSeen ? '是' : '否'
            )
        );
        check(
            'Layout · C4 非弹性子拿 0..可用（同一遍里两者不同）',
            $plainSpy->firstSeen !== null && !$plainSpy->firstSeen->hasInfiniteHeight() && $plainSpy->firstSeen->maxHeight === 60.0
        );

        $this->flushChecks();
    }

    public function testFlexSharesRemainingSpace(): void
    {
        $flexA = new RenderFlex(true, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Clip);
        $flexA->addChild(self::flexProxy(1));
        $flexA->addChild(self::flexProxy(2));
        $flexA->layout(BoxConstraints::tight(new Size(100.0, 60.0)));
        check(
            'Layout · flex 按份额分剩余空间（1:2 ⇒ 20 / 40）',
            $flexA->children()[0]->size()?->height === 20.0 && $flexA->children()[1]->size()?->height === 40.0,
            sprintf('实际 %.2f / %.2f', $flexA->children()[0]->size()?->height ?? -1.0, $flexA->children()[1]->size()?->height ?? -1.0)
        );

        $this->flushChecks();
    }

    public function testSpacingAndNaturalMainAxisHeight(): void
    {
        $spaced = new RenderFlex(true, 8.0, new EdgeInsets(), FlexAlign::Start, Overflow::Clip);
        $spaced->addChild(new SolidBox(new Size(20.0, 10.0), 20.0, 10.0));
        $spaced->addChild(new SolidBox(new Size(20.0, 12.0), 20.0, 12.0));
        $spaced->addChild(new SolidBox(new Size(20.0, 14.0), 20.0, 12.0));
        $spaced->layout(BoxConstraints::loose(new Size(80.0, 100.0)));
        check(
            'Layout · spacing 落在相邻子之间（10 / 10+12+8）',
            $spaced->children()[0]->offsetY() === 0.0 && $spaced->children()[1]->offsetY() === 18.0,
            sprintf('实际 %.2f / %.2f', $spaced->children()[0]->offsetY(), $spaced->children()[1]->offsetY())
        );
        check(
            'Layout · 自然主轴高 = 各子相加 + 间距（10+12+14+2×8）',
            $spaced->size()?->height === 52.0,
            '实际 ' . (string) ($spaced->size()?->height ?? -1.0)
        );

        $this->flushChecks();
    }

    public function testCrossAxisCenter(): void
    {
        $centered = new RenderFlex(false, 0.0, new EdgeInsets(), FlexAlign::Center, Overflow::Clip);
        $centered->addChild(new SolidBox(new Size(30.0, 10.0), 30.0, 10.0));
        $centered->layout(BoxConstraints::tight(new Size(100.0, 40.0)));
        check(
            'Layout · 交叉轴 Center 把短的那轴推到中间',
            $centered->children()[0]->offsetY() === 15.0,
            '实际 ' . (string) $centered->children()[0]->offsetY()
        );

        $this->flushChecks();
    }

    public function testPaddingIntoSizeAndChildConstraints(): void
    {
        $padded = new RenderFlex(true, 0.0, EdgeInsets::all(6.0), FlexAlign::Start, Overflow::Clip);
        $padded->addChild(new SolidBox(new Size(10.0, 8.0), 10.0, 8.0));
        $padded->layout(BoxConstraints::loose(new Size(80.0, 80.0)));
        check(
            'Layout · padding 进尺寸（子 8 + 上下各 6）',
            $padded->size()?->height === 20.0 && $padded->size()?->width === 22.0,
            sprintf('实际 %.2f × %.2f', $padded->size()?->width ?? -1.0, $padded->size()?->height ?? -1.0)
        );
        check(
            'Layout · padding 减约束后子拿到的是内容区（不是 80 高）',
            $padded->children()[0]->size()?->height === 8.0
        );

        $this->flushChecks();
    }

    public function testShrinkChildMeasuresTwiceWithInfiniteAxis(): void
    {
        $naturalSpy = new ConstraintSpy(new Size(300.0, 20.0));
        $naturalSpy->setFlexIntent(0, 1, \NAN, RenderBox::ALIGN_INHERIT);
        $naturalRow = new RenderFlex(false, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Clip);
        $naturalRow->addChild($naturalSpy);
        $naturalRow->layout(BoxConstraints::tight(new Size(100.0, 40.0)));
        check(
            'Layout · shrink 子第一遍拿无界主轴（否则内容超容器先报 C5，shrink 用不上）',
            $naturalSpy->firstSeen !== null && $naturalSpy->firstSeen->hasInfiniteWidth(),
            $naturalSpy->firstSeen === null
                ? '一次都没量'
                : sprintf('第一遍 maxWidth=%.1f', $naturalSpy->firstSeen->maxWidth)
        );
        check(
            'Layout · shrink 子量两次（自然 300 + 紧约束 100），纯 grow 子只量一次',
            $naturalSpy->firstSeen !== $naturalSpy->lastSeen
                && $naturalSpy->lastSeen !== null
                && $naturalSpy->lastSeen->maxWidth === 100.0
                && $naturalSpy->size()?->width === 100.0,
            sprintf(
                '末次 maxWidth=%.1f，尺寸 %.1f',
                $naturalSpy->lastSeen?->maxWidth ?? -1.0,
                $naturalSpy->size()?->width ?? -1.0
            )
        );

        $this->flushChecks();
    }

    public function testWeightedShrinkCompression(): void
    {
        $evenRow = new RenderFlex(false, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Clip);
        $evenRow->addChild(self::flexIntent(0, 1, 100.0));
        $evenRow->addChild(self::flexIntent(0, 1, 100.0));
        $evenRow->addChild(self::flexIntent(0, 1, 100.0));
        $evenRow->layout(BoxConstraints::tight(new Size(240.0, 40.0)));
        $evenWidths = [];
        foreach ($evenRow->children() as $kid) {
            $evenWidths[] = $kid->size()?->width ?? -1.0;
        }
        check(
            'Layout · shrink 加权压缩（三个 basis 100 进 240 ⇒ 各 80）',
            $evenWidths === [80.0, 80.0, 80.0],
            '实际 ' . implode(' / ', array_map(static fn (float $v): string => sprintf('%.1f', $v), $evenWidths))
        );

        $this->flushChecks();
    }

    public function testShrinkFreezeIteration(): void
    {
        $freezeA = self::flexIntent(0, 100, 10.0);
        $freezeB = self::flexIntent(0, 10, 200.0);
        $freezeRow = new RenderFlex(false, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Clip);
        $freezeRow->addChild($freezeA);
        $freezeRow->addChild($freezeB);
        $freezeRow->layout(BoxConstraints::tight(new Size(100.0, 40.0)));
        check(
            'Layout · shrink 冻结迭代（A 压到 0 冻结，B 两轮后拿到 100）',
            $freezeA->size()?->width === 0.0 && $freezeB->size()?->width === 100.0,
            sprintf('实际 %.1f / %.1f', $freezeA->size()?->width ?? -1.0, $freezeB->size()?->width ?? -1.0)
        );

        $this->flushChecks();
    }

    public function testBasisOverridesNatural(): void
    {
        $basisBox = new RenderProxy();
        $basisBox->setFlexIntent(0, 0, 80.0, RenderBox::ALIGN_INHERIT);
        $basisBox->setChild(new SolidBox(new Size(50.0, 10.0), 50.0, 10.0));
        $basisRow = new RenderFlex(false, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Clip);
        $basisRow->addChild($basisBox);
        $basisRow->layout(BoxConstraints::tight(new Size(100.0, 40.0)));
        check(
            'Layout · basis 优先于自然尺寸（内容 50、basis 80 ⇒ 拿 80）',
            $basisBox->size()?->width === 80.0,
            '实际 ' . (string) ($basisBox->size()?->width ?? -1.0)
        );

        $this->flushChecks();
    }

    public function testBasisPlusGrowIsIncremental(): void
    {
        $mixA = self::flexIntent(1, 0, 40.0);
        $mixB = self::flexIntent(1, 0, 40.0);
        $mixRow = new RenderFlex(false, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Clip);
        $mixRow->addChild($mixA);
        $mixRow->addChild($mixB);
        $mixRow->layout(BoxConstraints::tight(new Size(160.0, 40.0)));
        check(
            'Layout · basis + grow 是增量语义（起点 40 各加 40 ⇒ 80 / 80）',
            $mixA->size()?->width === 80.0 && $mixB->size()?->width === 80.0,
            sprintf('实际 %.1f / %.1f', $mixA->size()?->width ?? -1.0, $mixB->size()?->width ?? -1.0)
        );

        $this->flushChecks();
    }

    public function testAlignSelfOverridesContainer(): void
    {
        // 父 Center、子 alignSelf(END) ⇒ 子贴交叉轴末端
        $selfEnd = new SolidBox(new Size(30.0, 10.0), 30.0, 10.0);
        $selfEnd->setFlexIntent(0, 0, \NAN, RenderBox::ALIGN_END);
        $selfEndRow = new RenderFlex(false, 0.0, new EdgeInsets(), FlexAlign::Center, Overflow::Clip);
        $selfEndRow->addChild($selfEnd);
        $selfEndRow->layout(BoxConstraints::tight(new Size(100.0, 40.0)));
        check(
            'Layout · alignSelf(END) 覆盖父的 Center ⇒ 子贴交叉轴末端（40−10=30）',
            $selfEnd->offsetY() === 30.0,
            '实际 ' . (string) $selfEnd->offsetY()
        );

        // 父 Start、子 alignSelf(CENTER) ⇒ 居中
        $selfCenter = new SolidBox(new Size(30.0, 10.0), 30.0, 10.0);
        $selfCenter->setFlexIntent(0, 0, \NAN, RenderBox::ALIGN_CENTER);
        $selfCenterRow = new RenderFlex(false, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Clip);
        $selfCenterRow->addChild($selfCenter);
        $selfCenterRow->layout(BoxConstraints::tight(new Size(100.0, 40.0)));
        check(
            'Layout · alignSelf(CENTER) 在 Start 容器里居中（(40−10)/2=15）',
            $selfCenter->offsetY() === 15.0,
            '实际 ' . (string) $selfCenter->offsetY()
        );

        // 子 alignSelf(STRETCH) ⇒ 尺寸拉满交叉轴（不是只改偏移）
        $selfStretch = new SolidBox(new Size(30.0, 10.0), 30.0, 10.0);
        $selfStretch->setFlexIntent(0, 0, \NAN, RenderBox::ALIGN_STRETCH);
        $selfStretchRow = new RenderFlex(false, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Clip);
        $selfStretchRow->addChild($selfStretch);
        $selfStretchRow->layout(BoxConstraints::tight(new Size(100.0, 40.0)));
        check(
            'Layout · alignSelf(STRETCH) 真的拉满交叉轴（子高 10 ⇒ 40，不是只改偏移）',
            $selfStretch->size()?->height === 40.0 && $selfStretch->offsetY() === 0.0,
            sprintf('实际高 %.1f，偏移 %.1f', $selfStretch->size()?->height ?? -1.0, $selfStretch->offsetY())
        );

        // 未设（ALIGN_INHERIT）⇒ 跟容器
        $inheritKid = new SolidBox(new Size(30.0, 10.0), 30.0, 10.0);
        $inheritRow = new RenderFlex(false, 0.0, new EdgeInsets(), FlexAlign::End, Overflow::Clip);
        $inheritRow->addChild($inheritKid);
        $inheritRow->layout(BoxConstraints::tight(new Size(100.0, 40.0)));
        check(
            'Layout · 未设 alignSelf ⇒ 跟容器的 crossAlign（End 容器 ⇒ 30）',
            $inheritKid->offsetY() === 30.0,
            '实际 ' . (string) $inheritKid->offsetY()
        );

        $this->flushChecks();
    }

    public function testClipAllowsChildOverflowButBoxClips(): void
    {
        $clipColumn = new RenderFlex(true, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Clip);
        $clipColumn->addChild(new SolidBox(new Size(10.0, 40.0), 10.0, 40.0));
        $clipColumn->addChild(new SolidBox(new Size(10.0, 40.0), 10.0, 40.0));
        $clipColumn->layout(BoxConstraints::tight(new Size(10.0, 50.0)));
        check(
            'Layout · Clip 允许子溢出但自己夹进约束（不撞 C6）',
            $clipColumn->size()?->height === 50.0 && $clipColumn->children()[1]->offsetY() === 40.0,
            sprintf('实际 %.2f / %.2f', $clipColumn->size()?->height ?? -1.0, $clipColumn->children()[1]->offsetY())
        );

        $this->flushChecks();
    }
}
