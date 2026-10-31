<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Layout;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\FlexAlign;
use Drift\Widget\AspectRatio;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\SizedBox;
use Drift\Widget\Text;

/**
 * 定尺寸（`SizedBox`）与定比例（`AspectRatio`）：`mods` 的 `width`/`height`/`aspect`
 * 三个字段怎么变成盒几何。
 *
 * ## 与 `ProxyTest` 的分工
 *
 * | 文件 | 入口 | 覆盖的字段 |
 * |---|---|---|
 * | `ProxyTest` | 裸 `new RenderProxy()` + `setMods()` | `padding` |
 * | **本文件** | `Widget::createElement()` + `mount()` | `width` / `height` / `aspect` |
 *
 * ★ 两种入口都要有，缺一不可：
 *   裸盒验的是 `Modifier::tighten()` 与 `layout()` 的**字段解析**（纯几何）；
 *   Element 挂载验的是"Widget 上的 `->width()` **真的进了** `mods` 再进盒"。
 *   W5 之后几何住 `mods`、由 `Element::syncBox()` 递给盒 —— **裸造盒拿不到定值，
 *   量出来是 0×0**（smoke 原注释踩过这个坑），所以这里必须走挂载。
 *
 * ## 为什么"内禀宽"要单独一条
 *
 * `Row` / `Dock` 量**自然宽**走的是 `intrinsicWidth()`，不覆写那里，定尺寸在
 * "量"这一路**完全看不见**（静默）⇒ 只量盒尺寸不够，③ 必须单独钉。
 *
 * ## AspectRatio 用表驱动
 *
 * `BoxConstraints::applyAspect()` 的口径是**枚举性**的（两轴定死 / 一轴定死 /
 * 都松 / 都无界），将来加口径只加一行 case，不用再写一个方法。
 */
final class SizingTest extends SmokeTestCase
{
    private const EPS = 0.01;

    /** 内容（`Text('侧栏')`）在无界约束下的自然尺寸 —— "未定的轴透传"要拿它当对照 */
    private static function naturalSize(): Size
    {
        $el = Text::make('侧栏')->createElement();
        $el->mount(null, smokeContext());
        $box = $el->effectiveBox();
        $box?->layout(BoxConstraints::unbounded());

        return $box?->size() ?? new Size(0.0, 0.0);
    }

    /**
     * 定宽 ⇒ 盒宽 200 **且孩子被拉满 200**；未定的轴透传（高随内容）。
     *
     * ★ 两条必须一起：只验盒宽会奖励"只定盒不定孩子"的实现（内容缩在左边，
     *   看起来像定宽了，实际按钮/文字挤在左缘）。只看盒宽看不出这个差别。
     * ★ 未定轴用**内容自然高**当对照，而不是写死一个数 —— 字模变了判据不失效。
     */
    public function testWidthPinsBoxAndStretchesChild(): void
    {
        $natural = self::naturalSize();

        $el = SizedBox::make(Text::make('侧栏'))->width(200.0)->createElement();
        $el->mount(null, smokeContext());
        $box = $el->effectiveBox();
        $box?->layout(BoxConstraints::loose(new Size(940.0, 620.0)));

        $kids = childBoxes($box);
        check(
            'SizedBox · 定宽 ⇒ 盒宽 200 且孩子被拉满 200（只定盒不定孩子 = 内容缩在左边）',
            $box?->size() !== null && \abs($box->size()->width - 200.0) < self::EPS
                && ($kids[0] ?? null)?->size() !== null
                && \abs($kids[0]->size()->width - 200.0) < self::EPS,
            \sprintf(
                '盒 %.6g 孩子 %.6g 内容自然 %.6g',
                $box?->size()?->width ?? -1.0,
                $kids[0]?->size()?->width ?? -1.0,
                $natural->width,
            )
        );
        check(
            'SizedBox · 未定的轴透传（高随内容，不是 0 也不是约束上限）',
            $box?->size() !== null && \abs($box->size()->height - $natural->height) < self::EPS,
            \sprintf('实际高 %.6g 内容高 %.6g', $box?->size()?->height ?? -1.0, $natural->height)
        );
    }

    /**
     * 内禀宽 == 定宽。
     *
     * ★ 这条是"`Row`/`Dock` 里也看得见"的唯一证据：那两个容器量自然宽走
     *   `intrinsicWidth()`，漏了这条等于没定尺寸（且**不报错**）。
     */
    public function testIntrinsicWidthReflectsPinnedWidth(): void
    {
        $el = SizedBox::make(Text::make('侧栏'))->width(200.0)->createElement();
        $el->mount(null, smokeContext());
        $box = $el->effectiveBox();

        check(
            'SizedBox · 内禀宽 == 定宽（Row/Dock 量自然宽也看得见；漏了这条等于没定）',
            $box?->intrinsicWidth(0.0) !== null && \abs($box->intrinsicWidth(0.0) - 200.0) < self::EPS,
            \sprintf('内禀宽 %.6g', $box?->intrinsicWidth(0.0) ?? -1.0)
        );
    }

    /**
     * 父更紧时听父：`tight(100)` 里塞 `width(200)` ⇒ 100，且**不许抛约束违规**。
     *
     * ★ 约束是父给的，子无权突破（§13.1 C2）。孩子也要跟着收到 100 ——
     *   否则孩子会按 200 排、溢出盒 100（裁掉右半，静默）。
     */
    public function testParentTighterThanDeclaredWins(): void
    {
        $el = SizedBox::make(Text::make('侧栏'))->width(200.0)->createElement();
        $el->mount(null, smokeContext());
        $box = $el->effectiveBox();
        $box?->layout(BoxConstraints::tight(new Size(100.0, 40.0)));

        $kids = childBoxes($box);
        check(
            'SizedBox · 父更紧时听父（tight 100 里 width 200 ⇒ 盒 100 且孩子 100，不抛约束违规）',
            $box?->size() !== null && \abs($box->size()->width - 100.0) < self::EPS
                && ($kids[0] ?? null)?->size() !== null
                && \abs($kids[0]->size()->width - 100.0) < self::EPS,
            \sprintf('盒 %.6g 孩子 %.6g', $box?->size()?->width ?? -1.0, $kids[0]?->size()?->width ?? -1.0)
        );
    }

    /**
     * 无子盒 ⇒ 尺寸就是定值（占位空隙的用法：`SizedBox::make()->width(12)->height(1)`）。
     *
     * ★ 用无界约束布局：没有孩子可参考，尺寸只能来自 `mods` —— 若实现把
     *   "没有孩子"当成 0×0 或 INF，这里立刻红。
     */
    public function testEmptySizedBoxTakesDeclaredSize(): void
    {
        $el = SizedBox::make()->size(24.0, 8.0)->createElement();
        $el->mount(null, smokeContext());
        $box = $el->effectiveBox();
        $box?->layout(BoxConstraints::unbounded());

        check(
            'SizedBox · 无子盒 ⇒ 尺寸 = 定值（占位空隙的用法）',
            $box?->size() !== null
                && \abs($box->size()->width - 24.0) < self::EPS
                && \abs($box->size()->height - 8.0) < self::EPS,
            \sprintf('%.6g×%.6g', $box?->size()?->width ?? -1.0, $box?->size()?->height ?? -1.0)
        );
    }

    /**
     * `applyAspect()` 的四种口径 + "比例作用于盒（含 padding）"。
     *
     * @return array<string, array{title:string, ratio:float, width:?float, height:?float, pad:?float, constraints:BoxConstraints, expect:array{float,float}}>
     */
    private static function aspectCases(): array
    {
        return [
            'loose' => [
                'title' => '宽铺满、高按比例（940 ÷ 2 = 470）',
                'ratio' => 2.0,
                'width' => null,
                'height' => null,
                'pad' => null,
                'constraints' => BoxConstraints::loose(new Size(940.0, 620.0)),
                'expect' => [940.0, 470.0],
            ],
            'cap-height' => [
                'title' => '高度不够时按高度回退（宽 200 要 100 高、只有 60 ⇒ 120×60）',
                'ratio' => 2.0,
                'width' => null,
                'height' => null,
                'pad' => null,
                'constraints' => new BoxConstraints(0.0, 200.0, 0.0, 60.0),
                'expect' => [120.0, 60.0],
            ],
            'explicit-width' => [
                'title' => '显式 width 优先（200 ÷ 2 = 100）',
                'ratio' => 2.0,
                'width' => 200.0,
                'height' => null,
                'pad' => null,
                'constraints' => BoxConstraints::loose(new Size(940.0, 620.0)),
                'expect' => [200.0, 100.0],
            ],
            'both-pinned' => [
                'title' => '两轴都定死 ⇒ 比例无自由度（200×80，不改成 100 高）',
                'ratio' => 2.0,
                'width' => 200.0,
                'height' => 80.0,
                'pad' => null,
                'constraints' => BoxConstraints::loose(new Size(940.0, 620.0)),
                'expect' => [200.0, 80.0],
            ],
            'padding-inside' => [
                'title' => '比例作用于**盒**（含 padding）：240 宽 ⇒ 120 高',
                'ratio' => 2.0,
                'width' => null,
                'height' => null,
                'pad' => 10.0,
                'constraints' => BoxConstraints::loose(new Size(240.0, 240.0)),
                'expect' => [240.0, 120.0],
            ],
        ];
    }

    public function testAspectSolverCases(): void
    {
        foreach (self::aspectCases() as $key => $case) {
            $widget = AspectRatio::make($case['ratio'], Text::make('封面'));
            if ($case['width'] !== null) {
                $widget->width($case['width']);
            }
            if ($case['height'] !== null) {
                $widget->height($case['height']);
            }
            if ($case['pad'] !== null) {
                $widget->paddingAll($case['pad']);
            }

            $el = $widget->createElement();
            $el->mount(null, smokeContext());
            $box = $el->effectiveBox();
            $box?->layout($case['constraints']);

            [$ew, $eh] = $case['expect'];
            check(
                'AspectRatio · ' . $case['title'],
                $box?->size() !== null
                    && \abs($box->size()->width - $ew) < self::EPS
                    && \abs($box->size()->height - $eh) < self::EPS,
                \sprintf(
                    '[%s] 盒 %.6g×%.6g（期望 %.6g×%.6g）',
                    $key,
                    $box?->size()?->width ?? -1.0,
                    $box?->size()?->height ?? -1.0,
                    $ew,
                    $eh,
                )
            );
        }
    }

    /**
     * 集成：侧栏定宽 + 扁平导航项（示例台的真实组合）。
     *
     * 链条：`SizedBox(200)` → `Card`（padding 10）→ `Column(cross: Stretch)` → 扁平 `Button`。
     * 判据 180 = 200 − 2×10 ⇒ 同时钉住三件事：定宽生效、padding 只吃一次、
     * `Stretch` 把按钮拉满整行（"整行可点"）。
     *
     * ★ 只验 SizedBox 或只验 Flat 都发现不了这条链断在哪 —— 它是唯一一条
     *   跨控件组合的判据，删了就没有别的地方能证明"侧栏真的排成了侧栏"。
     */
    public function testSidebarWidthReachesNestedFlatButton(): void
    {
        $el = SizedBox::make(
            Card::make(
                Column::make()->cross(FlexAlign::Stretch)->children([
                    Button::make('导航项一')->variant(ButtonVariant::Flat),
                ])
            )->paddingAll(10.0)
        )->width(200.0)->createElement();
        $el->mount(null, smokeContext());
        $box = $el->effectiveBox();
        $box?->layout(BoxConstraints::loose(new Size(940.0, 600.0)));

        // ★ 2026-10-31：`Card` 重新**是一层盒**（`RenderProxy`）⇒ 链条是
        //   SizedBox → Card(代理盒) → Column → Button，**四层**。
        //   ★ 必须按链条逐层取，不能"取第一个孩子"就算了：少取一层会量到 Column 的宽
        //     （也是 180），断言照样绿 —— 名义上量按钮、实际量容器，是最典型的假绿。
        $cardBox = childBoxes($box)[0] ?? null;
        $col = childBoxes($cardBox)[0] ?? null;
        $buttons = childBoxes($col);
        check(
            '集成 · 侧栏链条是 SizedBox → Card → Column → Button（四层，Card 自己是一层盒）',
            $cardBox !== null && $col !== null && \count($buttons) === 1,
            \sprintf('Card 盒 %s / Column 盒 %s / 按钮数 %d', $cardBox === null ? 'null' : 'ok', $col === null ? 'null' : 'ok', \count($buttons)),
        );
        check(
            '集成 · 侧栏 SizedBox(200) ⇒ 导航项宽 180（整行可点，右边缘齐）',
            ($buttons[0] ?? null)?->size() !== null
                && \abs($buttons[0]->size()->width - 180.0) < self::EPS,
            \sprintf('导航项宽 %.6g', $buttons[0]?->size()?->width ?? -1.0)
        );
    }
}
