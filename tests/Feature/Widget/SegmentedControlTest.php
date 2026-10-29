<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Smoke\App\SegmentedApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\Semantics;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Layout\RenderSegment;
use Drift\Layout\RenderSegmentedControl;
use Drift\Rendering\ColorToken;
use Drift\Rendering\DisplayList;
use Drift\Rendering\Paint;
use Drift\Rendering\RecordingCanvas;
use Drift\Rendering\SegmentedStyle;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Input\SemanticsRole;
use Drift\Text\TextRole;
use Drift\Text\TextStyle;
use Drift\Widget\ActivationContext;
use Drift\Widget\Element;
use Drift\Widget\Segment;
use Drift\Widget\SegmentedControl;
use Drift\Widget\SemanticsBuilder;

/**
 * 分段控件（㊺，W8-2 第一个控件，2026-10-23）。
 *
 * 五条判据：
 * ① 结构 = 多子盒容器（`Stack` 形态：kidList + 自己的 Element）；
 * ② 几何按**公式**推（等宽 = 最宽标签推进，不跑一次抄回来）；
 * ③ 墨迹/命令序：轨道 chrome 在段之前，选中块精确落在段内缩处；
 * ④ 激活走 Application 唯一入口，焦点落**段**（整组答不出"选了哪段"）；
 * ⑤ 反例全在位：越界选中 / 空标签 / 已选段 / 禁用段 / 空点击。
 *
 * ★ 段盒 = 标签 + 四边 inset（`RenderSegment::performLayout` 的不变量）：选中块按
 *   inset 内缩后画，药丸必须装得下标签。少了这一圈，选中态白字会溢出到轨道上。
 */
final class SegmentedControlTest extends SmokeTestCase
{
    private const LABELS = ['日', '周', '月'];

    /** @var array{equal: float, boxW: float, boxH: float, lineH: float, gap: float, inset: float} */
    private array $m = [];

    protected function setUp(): void
    {
        parent::setUp();

        $context = smokeContext();
        $style = TextStyle::of($context->textSize(TextRole::Body));
        $scale = new Scale(1.0);

        // 期望几何全部由度量现推（RenderText 的自然宽 = advance；行高 = layout(...)->height）
        $equal = 0.0;
        foreach (self::LABELS as $label) {
            $adv = $context->metrics()->advance($label, $style, $scale);
            if ($adv > $equal) {
                $equal = $adv;
            }
        }

        $this->m = [
            'equal' => $equal,
            'lineH' => $context->metrics()->layout(self::LABELS[0], $style, INF, $scale)->height,
            'inset' => SegmentedStyle::DEFAULT_INSET,
            'gap' => SegmentedStyle::DEFAULT_GAP,
            'boxW' => $equal + 2.0 * SegmentedStyle::DEFAULT_INSET,
            'boxH' => $context->metrics()->layout(self::LABELS[0], $style, INF, $scale)->height
                + 2.0 * SegmentedStyle::DEFAULT_INSET,
        ];
    }

    /** 段盒宽×3 + 缝×2 + 左右内距 10×2 */
    private function wantWidth(): float
    {
        return $this->m['boxW'] * 3.0 + $this->m['gap'] * 2.0 + 20.0;
    }

    /** 段盒高 + 上下内距 5×2 */
    private function wantHeight(): float
    {
        return $this->m['boxH'] + 10.0;
    }

    public function testMountBuildsStructuralBox(): void
    {
        $el = SegmentedControl::make(self::LABELS)->selected(1)->createElement();
        $el->mount(null, smokeContext());
        $box = $el->effectiveBox();

        check(
            'Seg · 挂载即建出 RenderSegmentedControl（结构节点产盒）',
            $box instanceof RenderSegmentedControl,
            $box === null ? 'box=null' : \get_class($box),
        );

        $kids = $box instanceof RenderSegmentedControl ? $box->children() : [];
        $ok = \count($kids) === 3;
        if ($ok) {
            foreach ($kids as $i => $kid) {
                if (!$kid instanceof RenderSegment || $kid->isSelected() !== ($i === 1)) {
                    $ok = false;
                    break;
                }
            }
        }
        check(
            'Seg · 三个段各是 RenderSegment，只有中段选中',
            $ok,
            'kids=' . \count($kids),
        );
    }

    public function testGeometryFromFormulas(): void
    {
        $el = SegmentedControl::make(self::LABELS)->selected(1)->createElement();
        $el->mount(null, smokeContext());
        $box = $el->effectiveBox();
        $box?->layout(BoxConstraints::loose(new Size(400.0, 100.0)));
        $size = $box?->size();

        check(
            'Seg · 宽 = 段盒宽×3 + 缝×2 + 内距 20（公式推）',
            $size !== null && \abs($size->width - $this->wantWidth()) < 0.01,
            \sprintf('w=%.2f want=%.2f', $size?->width ?? -1.0, $this->wantWidth()),
        );
        check(
            'Seg · 高 = 段盒高 + 内距 10',
            $size !== null && \abs($size->height - $this->wantHeight()) < 0.01,
            \sprintf('h=%.2f want=%.2f', $size?->height ?? -1.0, $this->wantHeight()),
        );
        check(
            'Seg · 内禀宽 = 实排宽（等宽口径：最宽者乘段数，不是求和）',
            $box !== null && \abs($box->intrinsicWidth(INF) - $this->wantWidth()) < 0.01,
            \sprintf('intrinsic=%.2f want=%.2f', $box?->intrinsicWidth(INF) ?? -1.0, $this->wantWidth()),
        );

        // 段盒等宽 + offset 累积（内容盒坐标：0, equal+gap, 2*(equal+gap)）
        $kids = $box instanceof RenderSegmentedControl ? $box->children() : [];
        $offsets = [];
        $widths = [];
        foreach ($kids as $kid) {
            $offsets[] = $kid->offsetX();
            $widths[] = $kid->size()?->width ?? -1.0;
        }
        $boxW = $this->m['boxW'];
        $gap = $this->m['gap'];
        check(
            'Seg · 段盒等宽（= 最宽标签 + 2*inset）且 offset 累积',
            \count($offsets) === 3
                && \abs($widths[0] - $boxW) < 0.01 && \abs($widths[1] - $boxW) < 0.01 && \abs($widths[2] - $boxW) < 0.01
                && \abs($offsets[0]) < 0.01
                && \abs($offsets[1] - ($boxW + $gap)) < 0.01
                && \abs($offsets[2] - 2.0 * ($boxW + $gap)) < 0.01,
            \sprintf(
                'boxW=%.2f offsets=%s',
                $boxW,
                \implode(',', \array_map(static fn(float $v): string => \sprintf('%.2f', $v), $offsets))
            ),
        );
    }

    public function testPaintCommandOrderAndPillGeometry(): void
    {
        $context = smokeContext();
        $inset = $this->m['inset'];
        $boxW = $this->m['boxW'];
        $boxH = $this->m['boxH'];

        $el = SegmentedControl::make(self::LABELS)->selected(1)->createElement();
        $el->mount(null, $context);
        $box = $el->effectiveBox();
        $box?->layout(BoxConstraints::loose(new Size(400.0, 100.0)));

        // 命令序：轨道 fill + stroke 两条在前，选中块一条在后（chrome 不被段盖、也不裁自己）
        $list = new DisplayList();
        $box?->paint(new RecordingCanvas($list));
        $rects = [];
        for ($i = 0; $i < $list->count(); $i++) {
            if ($list->opAt($i) === DisplayList::OP_DRAW_RECT) {
                $rects[] = $i;
            }
        }
        check(
            'Seg · 命令序：轨道 fill+stroke 在前、选中块在后（共 3 条 drawRect）',
            \count($rects) === 3 && $rects[0] < $rects[1] && $rects[1] < $rects[2],
            'rects=[' . \implode(',', $rects) . ']',
        );

        // ★ 为什么不扫"accent Run 的精确边界"（第一版那么写，恒红）：抗锯齿让边缘
        //   像素是混合色，leftmost/rightmost 找不到纯 accent；且内容盒 offset 差一个
        //   左内距。几何改走**命令参数**精确断言，像素只断"墨落在该落的地方"。
        $accent = $context->color(ColorToken::Accent);
        $pillRect = null;
        $pillRadius = 0.0;
        for ($i = 0; $i < $list->count(); $i++) {
            if ($list->opAt($i) !== DisplayList::OP_DRAW_RECT) {
                continue;
            }
            $paint = $list->argAt($i, 1);
            if (!$paint instanceof Paint || !$paint->color->equals($accent)) {
                continue;
            }
            $pillRect = $list->argAt($i, 0);
            $pillRadius = (float) $list->argAt($i, 2);
        }
        $pillW = $boxW - 2.0 * $inset;
        $pillH = $boxH - 2.0 * $inset;
        check(
            'Seg · 选中块命令参数：rect = 段盒内缩 inset、radius = min(宽,高)/2、色 = Accent',
            $pillRect instanceof Rect
                && \abs($pillRect->left - $inset) < 0.01 && \abs($pillRect->top - $inset) < 0.01
                && \abs($pillRect->width - $pillW) < 0.01 && \abs($pillRect->height - $pillH) < 0.01
                && \abs($pillRadius - \min($pillW, $pillH) / 2.0) < 0.01,
            $pillRect instanceof Rect
                ? \sprintf('rect(%.1f,%.1f,%.1f,%.1f) r=%.2f', $pillRect->left, $pillRect->top, $pillRect->width, $pillRect->height, $pillRadius)
                : 'no accent rect',
        );

        // 药丸必须装得下标签：药丸宽 = 段盒宽 − 2*inset = 最宽标签宽；高同理 = 行高。
        // 少了这条，`equal` 就等于标签宽、药丸比标签窄 4 ⇒ 选中态白字每边溢出 1px
        // 落到轨道上（真机像素实测：字迹 12px / 药丸 10px）。
        check(
            'Seg · 药丸装得下标签：pill 宽 = 最宽标签、pill 高 = 行高',
            \abs($pillW - $this->m['equal']) < 0.01 && \abs($pillH - $this->m['lineH']) < 0.01 && $pillW >= $this->m['equal'],
            \sprintf('pillW=%.2f labelW=%.2f pillH=%.2f lineH=%.2f', $pillW, $this->m['equal'], $pillH, $this->m['lineH']),
        );
    }

    public function testPixelsLandWhereExpected(): void
    {
        $context = smokeContext();
        $gap = $this->m['gap'];
        $boxW = $this->m['boxW'];

        $el = SegmentedControl::make(self::LABELS)->selected(1)->createElement();
        $el->mount(null, $context);
        $box = $el->effectiveBox();
        $box?->layout(BoxConstraints::loose(new Size(400.0, 100.0)));

        $canvas = spinCanvas($box, 240, 60);
        $mid = (int) \round($this->wantHeight() / 2.0);
        $accent = $context->color(ColorToken::Accent);
        $chrome = $context->color(ColorToken::Chrome);
        $border = $context->color(ColorToken::Border);
        $originX = $box->contentOriginX(); // 左内距 10 —— 子盒 offset 住内容盒，像素坐标要加它

        // 段间缝（2px）在选中块两侧都必须是轨底：选中块不越段界
        $gapBad = [];
        foreach ([0, 1] as $gi) {
            $gx = (int) \floor($originX + ($gi + 1) * $boxW + $gi * $gap);
            for ($g = 0; $g < (int) $gap; $g++) {
                if (!$canvas->pixelAt($gx + $g, $mid)->equals($chrome)) {
                    $gapBad[] = ($gx + $g);
                }
            }
        }
        check(
            'Seg · 段间缝（2px）全是轨底 ⇒ 选中块不越段界',
            $gapBad === [],
            'bad=' . \implode(',', $gapBad),
        );

        // 选中块里有真 accent 墨（排除"命令录对了却没画出来"）
        $ink = 0;
        for ($py = 0; $py < 60; $py++) {
            for ($px = 0; $px < 240; $px++) {
                if ($canvas->pixelAt($px, $py)->equals($accent)) {
                    $ink++;
                }
            }
        }
        check('Seg · 选中块有真 accent 墨（≥ 15 px）', $ink >= 15, 'accentPx=' . $ink);

        // 未选段（日/月）中行零 accent：填充只画在选中段上
        $unselBad = [];
        foreach ([0, 2] as $ui) {
            $x0 = (int) \floor($originX + $ui * ($boxW + $gap));
            $x1 = (int) \ceil($originX + $ui * ($boxW + $gap) + $boxW);
            for ($x = $x0; $x < $x1; $x++) {
                if ($canvas->pixelAt($x, $mid)->equals($accent)) {
                    $unselBad[] = $x;
                }
            }
        }
        check('Seg · 未选段中行零 accent（填充只画在选中段）', $unselBad === [], 'bad=' . \implode(',', $unselBad));

        // 轨道 chrome：中行 x=0 描边、x=1 轨底（chrome 真的画了）
        check(
            'Seg · 轨道 chrome：中行 x=0 描边、x=1 轨底（chrome 真的画了）',
            $canvas->pixelAt(0, $mid)->equals($border) && $canvas->pixelAt(1, $mid)->equals($chrome),
            \sprintf('x0=%s x1=%s', $canvas->pixelAt(0, $mid)->toArgb(), $canvas->pixelAt(1, $mid)->toArgb()),
        );
    }

    public function testKidListAstraySelectionAndEmptyLabels(): void
    {
        // kidList 单元：selected() 在 make() 之后调也必须拿得到值（不能建构器里建一次缓存）
        $fresh = SegmentedControl::make(self::LABELS)->selected(1)->kidList();
        check(
            'Seg · kidList 每次建新段：make() 之后调的 selected() 进得去',
            \count($fresh) === 3 && $fresh[1]->isSelected()
                && !$fresh[0]->isSelected() && !$fresh[2]->isSelected()
        );

        $out = SegmentedControl::make(self::LABELS)->selected(5);
        $noneSelected = true;
        foreach ($out->kidList() as $kid) {
            if ($kid->isSelected()) {
                $noneSelected = false;
            }
        }
        check(
            'Seg · 越界选中 ⇒ selectedIndex() = -1 且一段都不选中（不夹到 0）',
            $out->selectedIndex() === -1 && $noneSelected,
            'idx=' . $out->selectedIndex(),
        );

        $empty = SegmentedControl::make([]);
        check('Seg · 空标签表 ⇒ kidList 空、不炸', $empty->kidList() === []);

        $emptyEl = $empty->createElement();
        $emptyEl->mount(null, smokeContext());
        $emptyEl->effectiveBox()?->layout(BoxConstraints::loose(new Size(400.0, 100.0)));
        $emptySize = $emptyEl->effectiveBox()?->size();
        check(
            'Seg · 空轨 = 只剩内距（20×10），不炸不出 INF',
            $emptySize !== null && $emptySize->width === 20.0 && $emptySize->height === 10.0,
            $emptySize === null ? 'size=null' : \sprintf('%.1fx%.1f', $emptySize->width, $emptySize->height),
        );
    }

    public function testSegmentActivateOnlyFiresWhenUnselected(): void
    {
        // 段控件自己的 activate()：未选才回调；已选 / 禁用都不回调（反例在位）
        $fired = 0;
        $fire = static function () use (&$fired): void {
            $fired++;
        };
        Segment::make('周', 1)->selected(false)->onChanged($fire)->activate(ActivationContext::none());
        Segment::make('周', 1)->selected(true)->onChanged($fire)->activate(ActivationContext::none());
        Segment::make('周', 1)->selected(false)->enabled(false)->onChanged($fire)->activate(ActivationContext::none());
        check('Seg · activate()：未选段才回调；已选/禁用都不回调', $fired === 1, 'fired=' . $fired);
    }

    public function testNothingSelectedMeansNoAccentInk(): void
    {
        $context = smokeContext();
        // 全不选 ⇒ 整幅零 accent（没有"默认选中第一项"这回事）
        $el = SegmentedControl::make(self::LABELS)->selected(5)->createElement();
        $el->mount(null, $context);
        $box = $el->effectiveBox();
        $box?->layout(BoxConstraints::loose(new Size(400.0, 100.0)));
        $canvas = spinCanvas($box, 240, 60);
        $accent = $context->color(ColorToken::Accent);

        $count = 0;
        for ($y = 0; $y < 60; $y++) {
            for ($x = 0; $x < 240; $x++) {
                if ($canvas->pixelAt($x, $y)->equals($accent)) {
                    $count++;
                }
            }
        }
        check('Seg · 全不选 ⇒ 整幅零 accent', $count === 0, 'accentPx=' . $count);
    }

    public function testSemanticsIsRadioPerSegment(): void
    {
        // 三个 radio 节点；容器本身不给角色（结构不是语义）
        $sem = SemanticsBuilder::build(Semantics::element(SegmentedControl::make(self::LABELS)->selected(2)));

        $radioCount = 0;
        for ($i = 0; $i < $sem->count(); $i++) {
            if ($sem->node($i)->role === SemanticsRole::Radio->value) {
                $radioCount++;
            }
        }
        check(
            'Seg · 语义：三个 radio 节点（容器本身不给角色）',
            $radioCount === 3,
            'count=' . $radioCount,
        );

        $moon = $sem->findByRoleAndName(SemanticsRole::Radio->value, '月');
        check(
            'Seg · 语义：name = 标签、value = 选中项的标签',
            $moon >= 0 && $sem->node($moon)->value === '月',
            'idx=' . $moon,
        );
    }

    public function testKeyboardTabSpaceActivationChain(): void
    {
        // ★★ 激活链（键盘）：Tab 一段一格 + Space ⇒ Application 的 Segment 分支。
        //   A 组尾段预选中、B 组中段预选中、C 组整组禁用 —— 三个反例各占几步。
        // ★ 六步（不是八步）：C 组的两段整组禁用，2026-10-29 起**不在 Tab 序里**
        $app = new SegmentedApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
        for ($step = 0; $step < 6; $step++) {
            $app->dispatchInput(KeyEvent::down(KeyChord::tab()));
            $app->dispatchInput(KeyEvent::down(KeyChord::make('Space')));
        }
        check(
            'Seg · Tab+Space 六步：未选段回调、已选段不回调',
            $app->events === [['a', 0], ['a', 1], ['b', 0], ['b', 2]],
            \json_encode($app->events),
        );

        // 反例（新行为）：整组禁用的两段**不占 Tab 位** —— 八次 Tab 只走六格，末尾停住
        $seqApp = new SegmentedApplication();
        $seqApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
        $seqFm = $seqApp->focus();
        $visited = [];
        for ($step = 0; $step < 8; $step++) {
            $seqApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
            $visited[] = $seqFm?->focused()?->widget()->semanticsName() ?? '';
        }
        check(
            'Seg · 禁用组不在 Tab 序：八次 Tab = 日→周→月→小→中→大→大→大',
            $visited === ['日', '周', '月', '小', '中', '大', '大', '大'],
            \json_encode($visited, \JSON_UNESCAPED_UNICODE),
        );
    }

    public function testPointerActivationLandsOnSegment(): void
    {
        // 指针侧：down 即激活（与 Enter 同入口）。命中测试取最深盒 ⇒ 焦点落**段**上
        //   （若忘写 markFocusable，焦点落不上去 ⇒ 不激活 ⇒ 这几条红）。
        $app = new SegmentedApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));

        $day = findElement($app->rootElement(), static function (Element $e): bool {
            $w = $e->widget();

            return $w instanceof Segment && $w->index() === 0;
        });
        $dayBox = $day?->effectiveBox();
        [$dx, $dy] = $dayBox === null ? [0.0, 0.0] : boxOrigin($dayBox);
        $dw = $dayBox?->size()?->width ?? 0.0;
        $dh = $dayBox?->size()?->height ?? 0.0;
        $app->dispatchInput(PointerEvent::down($dx + $dw / 2.0, $dy + $dh / 2.0));
        check(
            'Seg · 指针按下段中心 ⇒ 选中该段',
            $app->events === [['a', 0]],
            \json_encode($app->events),
        );

        $app->dispatchInput(PointerEvent::down(4.0, 4.0)); // 根 Column 的 padding 区：无焦点可落
        check(
            'Seg · 点空白（无焦点可落）⇒ 不误触发上一个焦点段',
            $app->events === [['a', 0]],
            \json_encode($app->events),
        );

        $moon = findElement($app->rootElement(), static function (Element $e): bool {
            $w = $e->widget();

            return $w instanceof Segment && $w->index() === 2;
        });
        $moonBox = $moon?->effectiveBox();
        [$mx, $my] = $moonBox === null ? [0.0, 0.0] : boxOrigin($moonBox);
        $mw = $moonBox?->size()?->width ?? 0.0;
        $mh = $moonBox?->size()?->height ?? 0.0;
        $app->dispatchInput(PointerEvent::down($mx + $mw / 2.0, $my + $mh / 2.0));
        check(
            'Seg · 点已选段（月，A 组预选中）⇒ 不重复回调',
            $app->events === [['a', 0]],
            \json_encode($app->events),
        );

        $off = findElement($app->rootElement(), static function (Element $e): bool {
            $w = $e->widget();

            return $w instanceof Segment && $w->semanticsName() === '开';
        });
        $offBox = $off?->effectiveBox();
        [$ox, $oy] = $offBox === null ? [0.0, 0.0] : boxOrigin($offBox);
        $ow = $offBox?->size()?->width ?? 0.0;
        $oh = $offBox?->size()?->height ?? 0.0;
        $app->dispatchInput(PointerEvent::down($ox + $ow / 2.0, $oy + $oh / 2.0));
        check(
            'Seg · 点禁用段（开，C 组整组禁用）⇒ 不回调',
            $app->events === [['a', 0]],
            \json_encode($app->events),
        );
    }
}
