<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Tests\Support\Semantics;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\SemanticsRole;
use Drift\Layout\RenderDivider;
use Drift\Layout\RenderProgress;
use Drift\Rendering\Color;
use Drift\Rendering\ColorToken;
use Drift\Widget\Column;
use Drift\Widget\Divider;
use Drift\Widget\Progress;
use Drift\Widget\SemanticsBuilder;
use Drift\Widget\Text;

/**
 * 装饰 / 读数控件：Divider（分割线）与 Progress（进度条）。
 *
 * 判据（与 `tools/smoke.php` ㉛ 段一致）：
 *   ① 形状只用 Core 层 op ⇒ 软光栅也画得出，preview / golden / smoke 不炸；
 *   ② 布局 = 控件内禀尺寸（吃满授予 / 固定宽 / 无界回落 MIN_WIDTH）；
 *   ③ 像素精确（Divider 一整根线、Progress 墨水分界落在 400*value）；
 *   ④ 语义：Divider 纯装饰不进树，Progress 进树且 value = "42%"。
 *
 * ★ 像素断言走共享 `spinCanvas()`（tests/Support/functions.php）；
 *   语义断言走共享 `Semantics::element()`（tests/Support/Semantics.php）。
 */
final class DividerProgressTest extends SmokeTestCase
{
    public function testDividerMountsRenderDivider(): void
    {
        $ctx = smokeContext();
        $dvRoot = Divider::make()->createElement();
        $dvRoot->mount(null, $ctx);
        $dvBox = $dvRoot->effectiveBox();
        check('Divider · 挂载即建出渲染盒', $dvBox instanceof RenderDivider);

        $this->flushChecks();
    }

    public function testDividerLayout(): void
    {
        $ctx = smokeContext();

        // 宽度语义：默认吃满授予（一根线只有横贯可用宽度时才叫"分割"）
        $fillDv = Divider::make()->createElement();
        $fillDv->mount(null, $ctx);
        $fillDv->effectiveBox()?->layout(BoxConstraints::loose(new Size(400.0, 20.0)));
        check(
            'Divider · 宽松(400×20) ⇒ 吃满宽度、高 = 线粗 1',
            $fillDv->effectiveBox()?->size()?->width === 400.0
                && $fillDv->effectiveBox()?->size()?->height === 1.0,
        );

        $fixedDv = Divider::make()->thickness(2.0)->width(120.0)->createElement();
        $fixedDv->mount(null, $ctx);
        $fixedDv->effectiveBox()?->layout(BoxConstraints::loose(new Size(400.0, 20.0)));
        check(
            'Divider · width(120) + thickness(2) ⇒ 固定宽、粗跟着走',
            $fixedDv->effectiveBox()?->size()?->width === 120.0
                && $fixedDv->effectiveBox()?->size()?->height === 2.0,
        );

        // ★ 无界不回退 INF（§14 / RenderDock 教训：INF 进坐标链 = 无声劣化）
        $infDv = Divider::make()->createElement();
        $infDv->mount(null, $ctx);
        $infDv->effectiveBox()?->layout(new BoxConstraints(0.0, INF, 0.0, INF));
        $infSize = $infDv->effectiveBox()?->size();
        check(
            'Divider · 无界约束 ⇒ 回落 MIN_WIDTH(24)，不是 INF',
            $infSize !== null && $infSize->width === 24.0 && is_finite($infSize->width),
        );

        $this->flushChecks();
    }

    public function testDividerPixels(): void
    {
        $ctx = smokeContext();
        $fillDv = Divider::make()->createElement();
        $fillDv->mount(null, $ctx);
        $fillDv->effectiveBox()?->layout(BoxConstraints::loose(new Size(400.0, 20.0)));

        // 墨迹：整根线一条不落（400 宽 1 高，1 像素行全是有色 ⇒ 400 个非白点）
        $dvCanvas = spinCanvas($fillDv->effectiveBox(), 400, 4);
        $dvInk = 0;
        for ($x = 0; $x < 400; $x++) {
            if (!$dvCanvas->pixelAt($x, 0)->equals(Color::white())) {
                $dvInk++;
            }
        }
        check('Divider · 画出来的线是一整根（400 px 无断点）', $dvInk === 400, 'ink=' . $dvInk);
        check(
            'Divider · 线粗 1 ⇒ 只占一行像素（相邻行留白）',
            $dvCanvas->pixelAt(7, 1)->equals(Color::white()),
        );

        $this->flushChecks();
    }

    public function testDividerIsDecorative(): void
    {
        $semDv = SemanticsBuilder::build(
            Semantics::element(
                Column::make()->children([
                    Text::make('账号'),
                    Divider::make(),
                    Text::make('小张'),
                ]),
            ),
        );
        check('Divider · 纯装饰 ⇒ 不进语义树（只有两个 Text，没有 role）', $semDv->count() === 0, 'count=' . $semDv->count());

        $this->flushChecks();
    }

    public function testProgressValueClamping(): void
    {
        // value 夹取：0/负/NaN→0，0.37→370，≥1→满 1000
        $pgCases = [
            [0.0, 0], [-0.5, 0], [NAN, 0], [0.0004, 0], [0.0005, 1],
            [0.37, 370], [0.999, 999], [1.0, 1000], [1.5, 1000], [1.0 + 1e-9, 1000],
        ];
        $pgBad = [];
        foreach ($pgCases as [$in, $want]) {
            $pg = Progress::make($in);
            // PHP 8.1+ 私有属性可直接读，无需 setAccessible（8.5 已废弃）。
            $got = (new \ReflectionProperty(Progress::class, 'permille'))->getValue($pg);
            if ($got !== $want) {
                $pgBad[] = sprintf('%s→%s(want %d)', (string) $in, (string) $got, $want);
            }
        }
        check('Progress · value 夹取：0/负/NaN→0，0.37→370，≥1→满 1000', $pgBad === [], implode(' ', $pgBad));

        $this->flushChecks();
    }

    public function testProgressValueMutation(): void
    {
        $pg2 = Progress::make(0.0)->value(0.42);
        $pp = new \ReflectionProperty(Progress::class, 'permille');
        check('Progress · value() 改值 ⇒ 千分比跟着改（0 → 420）', $pp->getValue($pg2) === 420);

        $this->flushChecks();
    }

    public function testProgressLayout(): void
    {
        $ctx = smokeContext();

        $pgRoot = Progress::make(0.42)->createElement();
        $pgRoot->mount(null, $ctx);
        $pgBox = $pgRoot->effectiveBox();
        check('Progress · 挂载即建出渲染盒', $pgBox instanceof RenderProgress);
        $pgBox?->layout(BoxConstraints::loose(new Size(400.0, 40.0)));
        check(
            'Progress · 吃满授予宽度（400），高 = 条高 8',
            $pgBox?->size()?->width === 400.0 && $pgBox?->size()?->height === 8.0,
        );

        $pgFixed = Progress::make(0.42)->height(12.0)->width(240.0)->createElement();
        $pgFixed->mount(null, $ctx);
        $pgFixed->effectiveBox()?->layout(BoxConstraints::loose(new Size(400.0, 40.0)));
        check(
            'Progress · width(240) + height(12) ⇒ 伏笔：定宽不给 Row 抢',
            $pgFixed->effectiveBox()?->size()?->width === 240.0
                && $pgFixed->effectiveBox()?->size()?->height === 12.0,
        );

        $this->flushChecks();
    }

    public function testProgress42PercentPixels(): void
    {
        $ctx = smokeContext();
        $pgBox = Progress::make(0.42)->createElement();
        $pgBox->mount(null, $ctx);
        $pgBox->effectiveBox()?->layout(BoxConstraints::loose(new Size(400.0, 40.0)));

        // 像素：42% ⇒ 填充端点精确落在 400*0.42 = 168
        $pgCanvas = spinCanvas($pgBox->effectiveBox(), 400, 8);
        $accent = $ctx->color(ColorToken::Accent);
        $border = $ctx->color(ColorToken::Border);
        $pgBadPx = [];
        for ($x = 0; $x < 400; $x++) {
            $want = $x < 168 ? $accent : $border;
            if (!$pgCanvas->pixelAt($x, 4)->equals($want)) {
                $pgBadPx[] = $x;
            }
        }
        check(
            'Progress · 42% 的墨水分界精确（x<168 accent，x>=168 轨道色）',
            $pgBadPx === [],
            'bad=' . count($pgBadPx) . ' 首个=' . (isset($pgBadPx[0]) ? (string) $pgBadPx[0] : '-'),
        );

        $this->flushChecks();
    }

    public function testProgressZeroOnlyTrack(): void
    {
        $ctx = smokeContext();
        $accent = $ctx->color(ColorToken::Accent);

        $pgZero = Progress::make(0.0)->createElement();
        $pgZero->mount(null, $ctx);
        $pgZeroBox = $pgZero->effectiveBox();
        $pgZeroBox?->layout(BoxConstraints::loose(new Size(400.0, 40.0)));
        $zeroCanvas = spinCanvas($pgZeroBox, 400, 8);
        $zeroAccent = 0;
        for ($x = 0; $x < 400; $x++) {
            if ($zeroCanvas->pixelAt($x, 4)->equals($accent)) {
                $zeroAccent++;
            }
        }
        check('Progress · 0% 只画轨道（accent 像素 0）', $zeroAccent === 0, 'accentPx=' . $zeroAccent);

        $this->flushChecks();
    }

    public function testProgressSemantics(): void
    {
        $pgTree = SemanticsBuilder::build(
            Semantics::element(
                Column::make()->children([
                    Progress::make(0.42),
                ]),
            ),
        );
        $pgIdx = -1;
        for ($i = 0; $i < $pgTree->count(); $i++) {
            if ($pgTree->node($i)->role === SemanticsRole::Progress->value) {
                $pgIdx = $i;
            }
        }
        check(
            'Sem · Progress：role=progress、value=42%（进度读数进语义树）',
            $pgIdx >= 0 && $pgTree->node($pgIdx)->value === '42%',
            'idx=' . $pgIdx,
        );

        $this->flushChecks();
    }
}
