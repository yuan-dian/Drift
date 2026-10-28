<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Layout\FlexAlign;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderProxy;
use Drift\Rendering\Color;
use Drift\Rendering\DisplayList;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RecordingCanvas;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\ScrollView;
use Drift\Widget\SizedBox;
use Drift\Widget\Text;

/**
 * `Card`：带内边距的盒样式控件（示例台"分组 / 层次"的地基）。
 *
 * ★ 每条判据都要能区分"有 Card"与"没有 Card"（否则是空转）：
 *   ① 内边距**真进**盒尺寸（少了它内容贴边，卡片退化成一个描边矩形）
 *   ② 内容原点 = padding（盒尺寸对、偏移对不上 ⇒ 内容画在角上）
 *   ③ 内禀宽含 padding（Row/Dock 量出的自然宽窄一圈 ⇒ 文字被挤）
 *   ④ 显式 `->background()` ⇒ 默认边框/圆角**整套让位**（§15.7 纪律 3）
 *   ⑤ 阴影三层外扩且由外向内加深（单层实心 = 描边，不是影子）
 *   ⑥ **结构节点套代理盒 / 产盒孩子不套壳**（两种孩子两条路，见文末两个方法）
 * ★ M2-5 起 `RenderCard` 已删，Card 是 `StatelessWidget` ⇒ `effectiveBox()` 拿到的
 *   是**子控件自己的盒**，样式住在它的 `mods` 上。判据因此从"是不是那个类"变成
 *   "样式有没有落到那个盒上"（后者才是真正要守的东西）。
 * ★ ⑥ 的两条来自 smoke 的 `cardproxy` 子进程用例 —— 它长期是**孤儿**（定义了没人跑），
 *   而它守的正是"Card(结构节点) 一点就炸"那个真回归。孤儿 ≠ 可删，已搬成两个正常方法。
 */
final class CardTest extends SmokeTestCase
{
    /** 盒挂上树、按无界约束排一遍，返回它的盒 */
    private static function laidOut(\Drift\Widget\Widget $widget): ?RenderBox
    {
        $el = $widget->createElement();
        $el->mount(null, smokeContext());
        $box = $el->effectiveBox();
        $box?->layout(BoxConstraints::unbounded());

        return $box;
    }

    /**
     * 把盒画到白底光栅上（先平移，好让"盒外"的阴影有地方落）。
     *
     * ★ 平移这一步不能省：不与 `spinCanvas()` 共用 —— 那个从 (0,0) 起画，
     *   阴影外扩的 1/2/3 层会落在画布外，测出来永远是"没影子"。
     */
    private static function paintAt(RenderBox $box, int $w, int $h, float $dx, float $dy): RasterCanvas
    {
        $list = new DisplayList();
        $rec = new RecordingCanvas($list);
        $rec->save();
        $rec->translate($dx, $dy);
        $box->paint($rec);
        $rec->restore();

        $canvas = new RasterCanvas($w, $h, Color::white(), Scale::of(1.0));
        $list->replay($canvas);

        return $canvas;
    }

    public function testPaddingEntersBoxSizeAndContentOrigin(): void
    {
        $textWidget = Text::make('卡片内容');
        $textSize = self::laidOut($textWidget)?->size();
        $box = self::laidOut(Card::make($textWidget));

        check(
            'Card · 样式落在子控件的 mods 上（不再是独立的 RenderCard 盒）',
            $box !== null && $box->mods()->hasVisual() && $box->mods()->hasPadding(),
        );
        check(
            'Card · 内边距真进盒尺寸（默认 16 ⇒ 盒 = 文字 + 32，两轴都是）',
            $box?->size() !== null && $textSize !== null
                && abs($box->size()->width - ($textSize->width + 32.0)) < 0.01
                && abs($box->size()->height - ($textSize->height + 32.0)) < 0.01,
            sprintf(
                '盒 %.6g×%.6g 文字 %.6g×%.6g',
                $box?->size()?->width ?? -1.0,
                $box?->size()?->height ?? -1.0,
                $textSize?->width ?? -1.0,
                $textSize?->height ?? -1.0,
            ),
        );
        // ★ M2-5 起内容原点不再靠"父盒平移子盒"，而是 `contentOriginX/Y()`（`02` §4.1.1）
        check(
            'Card · 内容原点 = padding（不是 0 —— 那内容就贴在描边上）',
            $box !== null
                && abs($box->contentOriginX() - 16.0) < 0.01
                && abs($box->contentOriginY() - 16.0) < 0.01,
            sprintf('contentOrigin %.6g,%.6g', $box?->contentOriginX() ?? -1.0, $box?->contentOriginY() ?? -1.0),
        );
        check(
            'Card · 内禀宽含 padding（Row/Dock 量自然宽不会窄一圈 ⇒ 文字不被挤）',
            $box?->intrinsicWidth(0.0) !== null && $textSize !== null
                && abs($box->intrinsicWidth(0.0) - ($textSize->width + 32.0)) < 0.01,
            sprintf('内禀宽 %.6g', $box?->intrinsicWidth(0.0) ?? -1.0),
        );
    }

    public function testEmptyCardOccupiesPaddingOnly(): void
    {
        // ★ 走 Element 挂载：`build()` 对"没内容"给的是 `SizedBox::make()`（纯转发盒），
        //   盒尺寸 = 内边距由基类 ⑤ 步加回。
        $box = self::laidOut(Card::make()->paddingAll(8.0));
        check(
            'Card · 无内容 ⇒ 尺寸 = 内边距（不崩、不产生 INF）',
            $box?->size() !== null
                && abs($box->size()->width - 16.0) < 0.01
                && abs($box->size()->height - 16.0) < 0.01,
            sprintf('%.6g×%.6g', $box?->size()?->width ?? -1.0, $box?->size()?->height ?? -1.0),
        );
    }

    public function testDefaultStyleHasBorderAndRoundedCorner(): void
    {
        $box = self::laidOut(Card::make(Text::make('卡片内容')));
        $size = $box?->size();
        if ($box === null || $size === null) {
            check('Card · 默认有边框（左边框中点不是白底 —— 有 1px 描边卡片才"立"得住）', false, 'no box');
            check('Card · 默认圆角（左上角外露白底 —— 直角矩形不是 LayUI 卡片形态）', false, 'no box');

            return;
        }
        $canvas = self::paintAt($box, (int) ceil($size->width) + 8, (int) ceil($size->height) + 8, 4.0, 4.0);
        $midY = (int) round(4.0 + $size->height / 2.0);
        $edge = $canvas->pixelAt(4, $midY);
        check(
            'Card · 默认有边框（左边框中点不是白底 —— 有 1px 描边卡片才"立"得住）',
            !$edge->equals(Color::white()),
            sprintf('rgb(%d,%d,%d)', $edge->r, $edge->g, $edge->b),
        );
        check(
            'Card · 默认圆角（左上角外露白底 —— 直角矩形不是 LayUI 卡片形态）',
            $canvas->pixelAt(4, 4)->equals(Color::white()),
            'p(4,4) 有墨',
        );
    }

    public function testExplicitBackgroundDisplacesBorderAndCorner(): void
    {
        $red = Color::fromRgb(0xFF, 0x00, 0x00);
        $box = self::laidOut(Card::make(Text::make('卡片内容'))->background($red));
        $size = $box?->size();
        if ($box === null || $size === null) {
            check('Card · 显式 background ⇒ 默认边框让位', false, 'no box');
            check('Card · 显式 background ⇒ 默认圆角也让位', false, 'no box');

            return;
        }
        $canvas = self::paintAt($box, (int) ceil($size->width) + 8, (int) ceil($size->height) + 8, 4.0, 4.0);
        check(
            'Card · 显式 background ⇒ 默认边框让位（边上就是纯底色，不是灰描边）',
            $canvas->pixelAt(4, (int) round(4.0 + $size->height / 2.0))->equals($red),
            sprintf(
                'rgb(%d,%d,%d)',
                $canvas->pixelAt(4, (int) round(4.0 + $size->height / 2.0))->r,
                $canvas->pixelAt(4, (int) round(4.0 + $size->height / 2.0))->g,
                $canvas->pixelAt(4, (int) round(4.0 + $size->height / 2.0))->b,
            ),
        );
        check(
            'Card · 显式 background ⇒ 默认圆角也让位（角上是纯底色 ⇒ 直角）',
            $canvas->pixelAt(4, 4)->equals($red),
        );
    }

    public function testShadowIsThreeLayersOutsideIn(): void
    {
        $box = self::laidOut(Card::make(Text::make('卡片内容'))->shadow(0.20));
        $size = $box?->size();
        if ($box === null || $size === null) {
            check('Card · 阴影三层外扩且由外向内加深', false, 'no box');

            return;
        }
        $canvas = self::paintAt($box, (int) ceil($size->width) + 24, (int) ceil($size->height) + 24, 12.0, 12.0);
        // 盒左边界 x=12 ⇒ 外扩 1/2/3 层分别落在 x=11 / 10 / 9
        $midY = (int) round(12.0 + $size->height / 2.0);
        $r = static fn (int $x): int => $canvas->pixelAt($x, $midY)->r;
        check(
            'Card · 阴影三层外扩且由外向内加深（r9 > r10 > r11 ⇒ 是过渡带不是描边）',
            $r(9) > $r(10) && $r(10) > $r(11) && $r(11) < 255,
            sprintf('r(9)=%d r(10)=%d r(11)=%d', $r(9), $r(10), $r(11)),
        );
        check(
            'Card · 阴影不越过第 3 层（x=8 仍是纯白 —— 影子有边界，不糊一片）',
            $canvas->pixelAt(8, $midY)->equals(Color::white()),
        );

        // 反例：shadow 默认 0 ⇒ 盒外纯白
        $plain = self::laidOut(Card::make(Text::make('卡片内容')));
        $plainSize = $plain?->size();
        if ($plain === null || $plainSize === null) {
            check('Card · shadow 默认 0 ⇒ 盒外纯白（不要影子时一个像素都不多画）', false, 'no box');

            return;
        }
        $plainCanvas = self::paintAt(
            $plain,
            (int) ceil($plainSize->width) + 24,
            (int) ceil($plainSize->height) + 24,
            12.0,
            12.0,
        );
        check(
            'Card · shadow 默认 0 ⇒ 盒外纯白（不要影子时一个像素都不多画）',
            $plainCanvas->pixelAt(11, (int) round(12.0 + $plainSize->height / 2.0))->equals(Color::white()),
        );
    }

    public function testContentFillsTightWidthConstraint(): void
    {
        // ★ 父把宽定死（`Column::cross(Stretch)`）时松掉紧约束 ⇒ 卡片撑满、内容缩在
        //   左边，观感像"没对齐"。与 `RenderPadding` 同口径：只 deflate，不放松。
        // ★ `Card` 不多一层盒 ⇒ `effectiveBox()` 就是**内容自己的盒**，"内容宽度"
        //   得往下再走一层才看得见（用 `Column::cross(Stretch)` —— 也正是示例台侧栏的形态）。
        $box = self::laidOut(Card::make(
            Column::make()->cross(FlexAlign::Stretch)->children([Text::make('卡片内容')]),
        ));
        $box?->layout(BoxConstraints::tight(new Size(300.0, 80.0)));
        $kid = childBoxes($box)[0] ?? null;
        check(
            'Card · 紧宽约束 ⇒ 内容撑满（300 − 2×16 = 268，不是文字自然宽）',
            $kid?->size() !== null && abs($kid->size()->width - 268.0) < 0.01,
            sprintf('内容宽 %.6g', $kid?->size()?->width ?? -1.0),
        );
    }

    /**
     * ⑥ 结构节点（`ScrollView` / `TextField`）**自己不产盒** ⇒ Card 必须先套一层
     * `SizedBox`（盒是 `RenderProxy`）再写样式。
     *
     * ★ 这条**真的炸过**：M2-5（2026-10-22）把 Card 从"自己有盒"改成"注入孩子 mods"
     *   时漏了结构节点 —— §11.7 守卫当场 FATAL，示例台 7 页一点就炸，而当时四档闸门
     *   全绿（用例面没有 Card(结构节点)）。与 `fatal_cases.php` 的 `modstateful`
     *   是同一件事的**正反两面**：那边验"直接写样式 ⇒ 响亮失败"，这边验"套了壳就没事"。
     * ★ 三条一起才算验到：中间是 SizedBox + 它的盒是 RenderProxy + 样式落在代理盒上。
     *   只看前两条的话，"套了壳但样式写到了别处"照样过。
     * ★ 取盒走 `$mid->renderObject()`，**不要** `$sv->parent()`：ScrollView 是**有态**
     *   控件，盒要等一帧才建，仅 `mount` 之后为 null —— 第一版就错在这儿，
     *   后两条恒 0（不是实现错，是取盒路径错）。
     */
    public function testStructureChildGetsWrappedInProxy(): void
    {
        $el = Card::make(ScrollView::make(Text::make('x')))->createElement();
        $el->mount(null, smokeContext());

        $mid = $el->childAt(0);
        $proxy = $mid?->renderObject();
        $midWidget = $mid?->widget();

        check(
            'Card · 结构节点（ScrollView）被套一层 SizedBox 代理（不产盒的控件不能直接写样式）',
            $midWidget instanceof SizedBox,
            '中间节点是 ' . ($midWidget === null ? 'null' : $midWidget::class),
        );
        check(
            'Card · 代理节点的盒就是 RenderProxy',
            $proxy instanceof RenderProxy,
            '盒是 ' . ($proxy === null ? 'null' : $proxy::class),
        );
        check(
            'Card · 样式落在代理盒上（不是落在 ScrollView 身上 —— 它没有盒）',
            $proxy !== null && $proxy->mods() !== null && $proxy->mods()->hasVisual(),
            'hasVisual=' . ($proxy?->mods()?->hasVisual() === true ? 'true' : 'false'),
        );
    }

    /**
     * ⑥ 的另一半：**产盒**的孩子不套壳，样式直接落它自己（M2-5 之前的行为逐字不变）。
     *
     * ★ 判据必须落在**控件类型**上。只看"盒有没有视觉样式"的话，哪天有人给所有孩子
     *   都套壳，`RenderProxy` 照样有样式 ⇒ 假绿（2026-10-23 真写出过这一版，
     *   靠 lint 的 `instanceof` 缺 `use` 才抓住）。
     */
    public function testBoxProducingChildIsStyledDirectly(): void
    {
        $el = Card::make(Text::make('y'))->createElement();
        $el->mount(null, smokeContext());

        $kid = $el->childAt(0);
        $box = $kid?->renderObject();
        $kidWidget = $kid?->widget();

        check(
            'Card · 产盒孩子（Text）**不套壳**：直接子节点就是 Text 自己',
            $kidWidget instanceof Text,
            '直接子是 ' . ($kidWidget === null ? 'null' : $kidWidget::class),
        );
        check(
            'Card · 样式落在 Text 自己的盒上',
            $box !== null && $box->mods() !== null && $box->mods()->hasVisual(),
            'hasVisual=' . ($box?->mods()?->hasVisual() === true ? 'true' : 'false'),
        );
    }
}
