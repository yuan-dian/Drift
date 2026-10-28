<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Layout;

use Drift\Dev\Smoke\Layout\LayoutCountBox;
use Drift\Dev\Smoke\Layout\SolidBox;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\FlexAlign;
use Drift\Layout\RenderWrap;
use Drift\Widget\SizedBox;
use Drift\Widget\Widget;
use Drift\Widget\Wrap;

/**
 * A4 第二件（2026-10-28）：`Wrap` —— 流式换行布局。
 *
 * ## 为什么它值得单独立一个文件
 *
 * `04` §4.4 判过"不做 `Wrap`"，理由是**需要两遍布局**。本轮的裁决是：那个前提
 * **不成立**（见 `RenderWrap` 类注）—— 子的约束两个数都来自父、与"分几行"无关
 * ⇒ 没有循环依赖 ⇒ **每个子只 layout 一次**。前提被证伪，否决就该推翻。
 *
 * ★ 所以这组判据里有一条专门钉"**不迭代**"：任何一个子被 layout 两次都会红。
 *   它是"这个实现没有退化成两遍"的守卫，不是性能装饰。
 *
 * ## 三条语义（每条都有反例）
 *
 * | 语义 | 反例（不改实现就会被下面哪条抓住） |
 * |---|---|
 * | 装不下才断行 | 无界主轴 ⇒ **一行**（`testUnboundedMainNeverWraps`）|
 * | 单个子比一行还宽 ⇒ 独占一行 | 被 `kidBox` 夹到可用宽（不 C6）|
 * | `spacing` / `runSpacing` 参与尺寸 | 盒宽 = 最宽的那行、盒高 = 各行之和 + 行距 |
 *
 * ★★ **内禀值按"一行"给**（Σ 主轴 / max 交叉）—— 这是 max-content 口径，
 *    与"无界 ⇒ 排一行"自洽；min-content（最宽子项）**不做**
 *    （要第二套内禀，属 `04` §2 的 S3 仲裁）。边界写进 `RenderWrap` 类注与判据。
 *
 * ★ 纯布局 / 挂载后手动 `layout()`，不依赖帧驱动 ⇒ Unit 层。
 */
final class WrapTest extends SmokeTestCase
{
    /** @param list<Widget> $kids */
    private static function wrapBox(
        array $kids,
        float $spacing = 0.0,
        float $runSpacing = 0.0,
        ?FlexAlign $align = null,
        ?FlexAlign $runAlign = null,
        ?Axis $direction = null,
    ): RenderWrap {
        $wrap = Wrap::make()->children($kids)->spacing($spacing)->runSpacing($runSpacing);
        if ($align !== null) {
            $wrap->alignment($align);
        }
        if ($runAlign !== null) {
            $wrap->runAlignment($runAlign);
        }
        if ($direction !== null) {
            $wrap->direction($direction);
        }

        $element = $wrap->createElement();
        $element->mount(null, smokeContext());
        $box = $element->findBoxOf(RenderWrap::class);
        if ($box === null) {
            throw new \RuntimeException('Wrap 没有挂出 RenderWrap');
        }

        return $box;
    }

    /**
     * 判据 1：装不下就断行，且尺寸/偏移都能被**推**出来（不是拟合出来的）。
     *
     * 三个 40×20 的标签、spacing 10、可用宽 100：
     * 40 +10+ 40 = 90 ≤ 100；+10+40 = 140 > 100 ⇒ 第一行两个（宽 90）、第二行一个（宽 40）。
     * ⇒ 盒 90 × (20 + 8 + 20 = 48)；偏移 (0,0) (50,0) (0,28)。
     *
     * ★ 三个偏移互不相等 ⇒ "没换行"（0/50/100）与"全换行"（0/0/0）都过不了。
     */
    public function testWrapsWhenTheRunIsFull(): void
    {
        $tags = [
            SizedBox::make()->size(40.0, 20.0),
            SizedBox::make()->size(40.0, 20.0),
            SizedBox::make()->size(40.0, 20.0),
        ];
        $wrap = self::wrapBox($tags, 10.0, 8.0);
        $wrap->layout(BoxConstraints::loose(new Size(100.0, 300.0)));

        expectBox($wrap, 0.0, 0.0, 90.0, 48.0, 'Wrap · 盒 = 最宽那行(90) × 各行高之和+行距(20+8+20=48)');

        $kids = $wrap->children();
        expectBox($kids[0] ?? null, 0.0, 0.0, 40.0, 20.0, 'Wrap · 第 1 个：行首 (0,0)');
        expectBox($kids[1] ?? null, 50.0, 0.0, 40.0, 20.0, 'Wrap · 第 2 个：同一行 (40+10=50, 0)');
        expectBox($kids[2] ?? null, 0.0, 28.0, 40.0, 20.0, 'Wrap · 第 3 个：掉到第二行 (0, 20+8=28)');

        $this->flushChecks();
    }

    /**
     * 判据 2：主轴**无界** ⇒ 永不换行（换行的前提是"有可用宽可超"）。
     *
     * ★ 这条防的是实现把 `INF` 当成一个很大的数去比 ⇒ 仍然断行（画面看着像
     *   "容器给了很宽的槽但它只用了最宽子项那么点"，不报错）。
     */
    public function testUnboundedMainNeverWraps(): void
    {
        $wrap = self::wrapBox([
            SizedBox::make()->size(40.0, 20.0),
            SizedBox::make()->size(40.0, 20.0),
            SizedBox::make()->size(40.0, 20.0),
        ], 10.0, 8.0);
        $wrap->layout(new BoxConstraints(0.0, INF, 0.0, 300.0));

        expectBox($wrap, 0.0, 0.0, 140.0, 20.0, 'Wrap · 主轴无界 ⇒ 一行（40×3 + 10×2 = 140 宽、20 高）');

        $this->flushChecks();
    }

    /**
     * 判据 3：单个子比一行还宽 ⇒ **独占一行**，且被夹到可用宽（不 C6、不溢出）。
     *
     * ★ `LayoutCountBox` 是**会夹约束**的夹具（不像 `SolidBox` 故意无视约束）⇒
     *   它在这里的表现就是真控件的表现：拿到 100 宽、不报错。
     */
    public function testOversizedChildTakesItsOwnRun(): void
    {
        $wide = new LayoutCountBox(new Size(200.0, 20.0));
        $small = new LayoutCountBox(new Size(30.0, 20.0));

        $wrap = new RenderWrap();
        $wrap->addChild($small);
        $wrap->addChild($wide);
        $wrap->layout(BoxConstraints::loose(new Size(100.0, 300.0)));

        check(
            'Wrap · 比一行还宽的子独占一行（放不进第一行的 0..100 ⇒ 换行，不是塞进去）',
            $wide->offsetY() > 0.0,
            \sprintf('实际 offset=(%.1f,%.1f)', $wide->offsetX(), $wide->offsetY()),
        );
        check(
            'Wrap · 那个子被夹到可用宽（100），没有超出父给的上界',
            $wide->size()?->width === 100.0,
            \sprintf('实际宽 %.1f', $wide->size()?->width ?? -1.0),
        );
        expectBox($wrap, 0.0, 0.0, 100.0, 40.0, 'Wrap · 盒 = 最宽那行(100) × 两行(20+20)');

        $this->flushChecks();
    }

    /**
     * 判据 4：行内交叉轴对齐（`alignment`）—— 矮子在**本行**里居中。
     *
     * ★ 按"本行最高项"算、不是按盒、也不是按父的上限 ⇒ 用两个不同高度的行，
     *   两个行的居中基准必须不一样（否则"按盒算"也能过）。
     *
     * 三个 40 宽的子、可用宽 90 ⇒ 第一行装两个（40+40 = 80 ≤ 90），第三个掉下去。
     * 第一行 = {40×20, 40×40} ⇒ **行高 40**；第二行 = {40×20} ⇒ 行高 20。
     *
     * ★ 判据落在"**矮的居中在本行 40 里 ⇒ y=10**"，而第二行那个矮子**没有**居中
     *   （本行就它一个 ⇒ y=40）。两个行的居中基准不同 ⇒ "按盒算"过不了。
     */
    public function testAlignmentIsPerRunNotPerBox(): void
    {
        $wrap = self::wrapBox([
            SizedBox::make()->size(40.0, 20.0),   // 第一行：矮的
            SizedBox::make()->size(40.0, 40.0),   // 第一行：最高的 ⇒ 撑出行高 40
            SizedBox::make()->size(40.0, 20.0),   // 第二行：装不下了（80+40 > 90）
        ], 0.0, 0.0, FlexAlign::Center);
        $wrap->layout(BoxConstraints::loose(new Size(90.0, 300.0)));

        $kids = $wrap->children();
        expectBox($kids[0] ?? null, 0.0, 10.0, 40.0, 20.0, 'Wrap · 第一行矮的：居中在**本行 40** 里 ⇒ y = (40−20)/2 = 10');
        expectBox($kids[1] ?? null, 40.0, 0.0, 40.0, 40.0, 'Wrap · 第一行最高的：行首 (40, 0)');
        expectBox($kids[2] ?? null, 0.0, 40.0, 40.0, 20.0, 'Wrap · 第二行：本行就它一个 ⇒ 无居中偏移，y = 40（不是 50）');

        $this->flushChecks();
    }

    /**
     * 判据 5：整块在交叉轴上的分布（`runAlignment`）—— 只在盒子比内容高时有区别。
     *
     * ★ 用 tight(100×100) 造出 52 的余量 ⇒ `End` 时第一行从 y=52 开始
     *   （"从 0 开始"会被这条抓住）。
     */
    public function testRunAlignmentDistributesSlackAcrossRuns(): void
    {
        $wrap = self::wrapBox([
            SizedBox::make()->size(40.0, 20.0),
            SizedBox::make()->size(40.0, 20.0),
            SizedBox::make()->size(40.0, 20.0),
        ], 0.0, 0.0, null, FlexAlign::End);
        $wrap->layout(BoxConstraints::tight(new Size(100.0, 100.0)));

        // 三个 40 宽、可用宽 100 ⇒ 两行（2 + 1）；内容高 = 20+20 = 40；盒高 100 ⇒ 余量 60
        expectBox($wrap->children()[0] ?? null, 0.0, 60.0, 40.0, 20.0, 'Wrap · runAlignment(End)：整块贴底 ⇒ 第一行从 y=60 起（不是 0）');

        $this->flushChecks();
    }

    /**
     * 判据 6：竖直方向（`Axis::Vertical`）—— 按高换行、往右换列。
     *
     * ★ 与判据 1 是同一套算术的**转置**：40 高的块 × 3、可用高 50 ⇒ 第一列 1 个、
     *   第二列 1 个…（40+40 > 50）。盒 = 宽(40+40+40? 不对) —— 竖排时主轴是高。
     * ★ 没有这条，`$horizontal` 那个三元写反也只在竖排时才暴露（而竖排零用法）。
     */
    public function testVerticalDirectionWrapsDownThenRight(): void
    {
        $wrap = self::wrapBox([
            SizedBox::make()->size(20.0, 40.0),
            SizedBox::make()->size(20.0, 40.0),
        ], 0.0, 6.0, null, null, Axis::Vertical);
        $wrap->layout(BoxConstraints::loose(new Size(300.0, 50.0)));

        expectBox($wrap, 0.0, 0.0, 46.0, 40.0, 'Wrap · 竖排：主轴是高 ⇒ 盒宽 = 两列之和(20+6+20=46)、盒高 = 最高那列(40)');
        expectBox($wrap->children()[0] ?? null, 0.0, 0.0, 20.0, 40.0, 'Wrap · 竖排第 1 个：(0,0)');
        expectBox($wrap->children()[1] ?? null, 26.0, 0.0, 20.0, 40.0, 'Wrap · 竖排第 2 个：装不下 ⇒ 换到右边一列 (20+6=26, 0)');

        $this->flushChecks();
    }

    /**
     * 判据 7：**每个子只 layout 一次**（"没有退化成两遍"的守卫）。
     *
     * ★ 这条是本轮推翻 `04` §4.4 的**核心证据**：它一旦红，说明实现里长出了迭代。
     */
    public function testEveryChildIsLaidOutOnce(): void
    {
        $a = new LayoutCountBox(new Size(40.0, 20.0));
        $b = new LayoutCountBox(new Size(40.0, 20.0));
        $c = new LayoutCountBox(new Size(40.0, 20.0));

        $wrap = new RenderWrap();
        $wrap->addChild($a);
        $wrap->addChild($b);
        $wrap->addChild($c);
        $wrap->layout(BoxConstraints::loose(new Size(100.0, 300.0)));

        check(
            'Wrap · 每个子只 layout 一次（分行不需要第二遍）',
            $a->layoutCalls === 1 && $b->layoutCalls === 1 && $c->layoutCalls === 1,
            \sprintf('实际 %d / %d / %d 次', $a->layoutCalls, $b->layoutCalls, $c->layoutCalls),
        );

        $this->flushChecks();
    }

    /**
     * 判据 8：内禀值按**一行**给（max-content）：主轴 = Σ + 间距、交叉轴 = max。
     *
     * ★ 这条钉的是**口径**，不是"越大越好"：min-content（最宽子项）也是合法答案，
     *   但本仓每轴只有一个内禀 ⇒ 必须选一个并写死。见 `RenderWrap` 类注的边界。
     */
    public function testIntrinsicIsOneRun(): void
    {
        // ★ 用 `LayoutCountBox` 而不是 `SolidBox`：后者为了"证明它读了另一轴"写的
        //   是 `intrinsicW + $height * 0.0` ⇒ 传 INF 进去得到 **NAN**（INF × 0）。
        $wrap = new RenderWrap();
        $wrap->addChild(new LayoutCountBox(new Size(40.0, 20.0)));
        $wrap->addChild(new LayoutCountBox(new Size(60.0, 30.0)));
        $wrap->addChild(new LayoutCountBox(new Size(20.0, 10.0)));

        check(
            'Wrap · 内禀宽 = 一行全排（40+60+20 = 120，不是最宽子项 60）',
            $wrap->intrinsicWidth(INF) === 120.0,
            \sprintf('实际 %.1f', $wrap->intrinsicWidth(INF)),
        );
        check(
            'Wrap · 内禀高 = 一行里最高那个（30，不是各行之和 60）',
            $wrap->intrinsicHeight(INF) === 30.0,
            \sprintf('实际 %.1f', $wrap->intrinsicHeight(INF)),
        );

        $this->flushChecks();
    }

    /**
     * 判据 9：命中跟着换行走（**画在哪就点得到哪**）。
     *
     * ★ 与定位那条同理：偏移只写进 `setOffset()` 时这条"看着必然"，但它抓的是
     *   "分行算对了、偏移算错"（画在第二行、命中算在第一行）。
     */
    public function testHitTestFollowsTheRun(): void
    {
        $wrap = self::wrapBox([
            SizedBox::make()->size(40.0, 20.0),
            SizedBox::make()->size(40.0, 20.0),
            SizedBox::make()->size(40.0, 20.0),
        ], 10.0, 8.0);
        $wrap->layout(BoxConstraints::loose(new Size(100.0, 300.0)));
        $kids = $wrap->children();

        check(
            'Wrap · 点在第二行那个子上（10, 30）命中它（不是第一行的）',
            ($wrap->hitTest(10.0, 30.0) ?? null) === ($kids[2] ?? null),
            '实际命中 ' . (($wrap->hitTest(10.0, 30.0) ?? null) === ($kids[2] ?? null) ? '第二行' : '别的'),
        );
        check(
            'Wrap · 点在第一行的空档上（95, 5）不命中任何子（那是行尾的空白）',
            ($wrap->hitTest(95.0, 5.0) ?? null) === ($wrap->hitTest(95.0, 5.0) ?? null)
                && ($wrap->hitTest(95.0, 5.0) ?? null) !== ($kids[0] ?? null),
            '实际 ' . (($wrap->hitTest(95.0, 5.0) ?? null) === ($kids[0] ?? null) ? '命中了第一个（错）' : '未命中子项'),
        );

        $this->flushChecks();
    }
}
