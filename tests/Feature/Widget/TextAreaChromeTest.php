<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderTextArea;
use Drift\Layout\ScrollBarGeometry;
use Drift\Widget\TextArea;
use Drift\Widget\TextAreaState;
use Drift\Widget\StatefulElement;

/**
 * 多行输入框的**滚动条**（`RenderTextArea` 实现 `ScrollBarHost` 之后）。
 *
 * ★ 分三类：
 *   ① 有没有条（溢出才有、不溢出没有 —— 画了满轨就是撒谎）；
 *   ② 条在哪（贴右缘、随偏移下移、长度按占比缩）；
 *   ③ 条与文字的关系（文本右缘不越过条栏 ⇒ 条不压字）。
 *
 * ★ 第 ③ 类是**预留条栏宽**那条裁决的唯一判据。它测不出"预留得太少"，
 *   只能测出"没预留" —— 所以文本必须长到**折满一整行**，否则
 *   `maxRight` 落在半行处，删掉预留也照样绿。
 */
final class TextAreaChromeTest extends SmokeTestCase
{
    private const WIDTH = 240.0;

    /** 挂一棵 `TextArea` 树并布局，返回 [state, box] */
    private function mount(string $value, float $height = 200.0, int $minLines = 3): array
    {
        $area = TextArea::make()->value($value)->minLines($minLines);
        $root = $area->createElement();
        $root->mount(null, smokeContext());

        $state = $root instanceof StatefulElement ? $root->state() : null;
        $root->settle();
        $root->effectiveBox()?->layout(BoxConstraints::tight(new Size(self::WIDTH, $height)));

        $box = $state instanceof TextAreaState
            ? $state->element()?->findBox(static fn($c): bool => $c instanceof RenderTextArea)
            : null;

        return [$state, $box];
    }

    /** 够长、且每行都折满的 CJK 文本（拉丁词内无断点，折不了行） */
    private function longText(int $lines = 12): string
    {
        return str_repeat(str_repeat('中', 40) . "\n", $lines);
    }

    public function testScrollAxesIsVerticalOnly(): void
    {
        [, $box] = $this->mount("a\nb");

        $axes = $box?->scrollAxes() ?? [];

        check('TextArea 条 · 只报一根轴（竖向）', \count($axes) === 1 && $axes[0] === Axis::Vertical);
        check(
            'TextArea 条 · 横向问什么都不给（接口纪律：不认识的轴返回 0，不是报错）',
            ($box?->maxScroll(Axis::Horizontal) ?? -1) === 0.0
                && ($box?->appliedOffset(Axis::Horizontal) ?? -1) === 0.0
                && $box?->thumbRect(Axis::Horizontal) === null,
        );

        $this->flushChecks();
    }

    /** 内容装得下 ⇒ **没有条**（画一条满轨 thumb 是撒谎：它暗示能滚） */
    public function testNoBarWhenContentFits(): void
    {
        [, $box] = $this->mount('短', 200.0, 3);

        check('TextArea 条 · 内容装得下 ⇒ 没有 thumb', $box?->thumbRect(Axis::Vertical) === null);
        check('TextArea 条 · 装得下 ⇒ 可走距离 0', ($box?->thumbTravel(Axis::Vertical) ?? -1) === 0.0);

        $this->flushChecks();
    }

    /** 溢出 ⇒ 有条，贴右缘、长度按视口占比 */
    public function testBarAppearsAndHugsRightEdge(): void
    {
        [, $box] = $this->mount($this->longText(), 200.0, 3);

        $bar = $box?->thumbRect(Axis::Vertical);
        $size = $box?->size();
        $max = $box?->maxScroll(Axis::Vertical) ?? 0.0;
        $travel = $box?->thumbTravel(Axis::Vertical) ?? 0.0;

        check('TextArea 条 · 内容溢出 ⇒ 有 thumb', $bar !== null);
        check('TextArea 条 · 溢出 ⇒ 可滚上限 > 0', $max > 0.0, 'max=' . $max);

        $right = $bar === null || $size === null
            ? -1.0
            : $bar->left + $bar->width;

        check(
            'TextArea 条 · 贴右缘（右边距 ' . ScrollBarGeometry::MARGIN . 'px）',
            $size !== null && abs($right - ($size->width - ScrollBarGeometry::MARGIN)) < 0.01,
            'right=' . $right,
        );
        check(
            'TextArea 条 · 条短于轨道（内容越长条越短）',
            $bar !== null && $size !== null
                && $bar->height < $size->height - 2.0 * ScrollBarGeometry::MARGIN
                && $bar->height >= ScrollBarGeometry::MIN_THUMB,
            'h=' . ($bar?->height ?? -1),
        );
        check('TextArea 条 · 可走距离 > 0（拖得动）', $travel > 0.0, 'travel=' . $travel);

        $this->flushChecks();
    }

    /** 偏移变大 ⇒ thumb 下移；到底 ⇒ 条底贴轨道底 */
    public function testBarTracksOffset(): void
    {
        [$state, $box] = $this->mount($this->longText(), 200.0, 3);

        $top = $box?->thumbRect(Axis::Vertical)?->top ?? -1.0;

        $state?->scrollBy(120.0);
        $box = $state?->element()?->findBox(static fn($c): bool => $c instanceof RenderTextArea);
        $moved = $box?->thumbRect(Axis::Vertical)?->top ?? -1.0;

        $max = $box?->maxScroll(Axis::Vertical) ?? 0.0;
        $state?->scrollBy($max);
        $box = $state?->element()?->findBox(static fn($c): bool => $c instanceof RenderTextArea);
        $bottomBar = $box?->thumbRect(Axis::Vertical);
        $size = $box?->size();

        check('TextArea 条 · 滚动后 thumb 下移', $moved > $top, 'top=' . $top . ' moved=' . $moved);
        check(
            'TextArea 条 · 滚到底 ⇒ 条底贴轨道底（不是悬在半空）',
            $bottomBar !== null && $size !== null
                && abs(($bottomBar->top + $bottomBar->height) - ($size->height - ScrollBarGeometry::MARGIN)) < 0.01,
            'barBottom=' . ($bottomBar === null ? -1 : $bottomBar->top + $bottomBar->height),
        );

        $this->flushChecks();
    }

    /**
     * `setScrollOffset()` 的返回值是**盒夹过的**值（`ScrollLane` 要靠它回写）。
     *
     * ★ 直接 `scrollBy(巨大值)` 测不出来：`ScrollLane` 会用返回值覆盖自己的字段，
     *   夹取坏了现象是"拖着条画面不动"（字段长期大于上限）。所以这里绕过 State，
     *   直接问盒。
     */
    public function testSetScrollOffsetClampsToMax(): void
    {
        [, $box] = $this->mount($this->longText(), 200.0, 3);

        $max = $box?->maxScroll(Axis::Vertical) ?? 0.0;
        $applied = $box?->setScrollOffset(Axis::Vertical, $max + 500.0) ?? -1.0;
        $taxed = $box?->setScrollOffset(Axis::Vertical, -80.0) ?? -1.0;

        check('TextArea 条 · 超上限 ⇒ 夹到 maxScroll', abs($applied - $max) < 0.01, 'applied=' . $applied);
        check('TextArea 条 · 负偏移 ⇒ 夹到 0', $taxed === 0.0, 'applied=' . $taxed);

        $this->flushChecks();
    }

    /**
     * **文本可用宽度扣掉了条栏**（预留条栏宽的唯一判据）。
     *
     * ★ 判据写成"段落宽 ≤ 内宽 − 条栏宽"的**算术**，不是"文本右缘没越过条"：
     *   后者结构上恒真（内边距本来就有 12px，条贴在最外 9px 里，删掉预留也压不到
     *   条）—— 实测删掉预留时那条判据**照样绿**。算术版才咬得住。
     * ★ 前提那一条同样必要：文本没折满时段落宽只是"内容有多长"，与可用宽无关。
     */
    public function testTextWidthExcludesTheBarLane(): void
    {
        [, $box] = $this->mount($this->longText(), 200.0, 3);

        $size = $box?->size();
        $left = $box?->contentLeft() ?? 0.0;
        $paragraph = $box?->textParagraph();
        $paraW = $paragraph?->width ?? -1.0;

        // `EdgeInsets::symmetric(h, v)` ⇒ 左右各 `$left`
        $avail = ($size?->width ?? 0.0) - 2.0 * $left
            - ScrollBarGeometry::THICKNESS - ScrollBarGeometry::MARGIN;

        check(
            'TextArea 条 · 前提：文本折满了行（段落宽接近可用宽，否则这条判据测不出东西）',
            $paraW > $avail - 20.0,
            'paraW=' . $paraW . ' avail=' . $avail,
        );
        check(
            'TextArea 条 · 文本可用宽度扣掉了条栏（文字不会跑到条下面）',
            $paraW <= $avail + 0.5,
            'paraW=' . $paraW . ' avail=' . $avail,
        );

        $this->flushChecks();
    }

    /**
     * `lines()` 定的是**布局高度**，不随内容变 —— 滚动条的全部消费者。
     *
     * ★ 三条缺一不可：
     *   ① 长内容 ⇒ 高度钉在给定行数、溢出可滚（否则条永远不出现）；
     *   ② 短内容 ⇒ 高度**不缩**（`<textarea rows>` 语义，不是"最多几行"）；
     *   ③ 不给 ⇒ 高度随内容长（既有行为，`Column` 里的默认）。
     */
    public function testLinesFixesTheFrameHeight(): void
    {
        [, $tall] = $this->mountWithLines($this->longText(12), 0);
        [, $short] = $this->mountWithLines('一行', 0);
        [, $fixedTall] = $this->mountWithLines($this->longText(12), 4);
        [, $fixedShort] = $this->mountWithLines('一行', 4);

        $tallH = $tall?->size()?->height ?? -1.0;
        $shortH = $short?->size()?->height ?? -1.0;
        $fixedTallH = $fixedTall?->size()?->height ?? -1.0;
        $fixedShortH = $fixedShort?->size()?->height ?? -1.0;

        check('TextArea 条 · 前提：不限高时高度随内容（tall > short）', $tallH > $shortH, 'tall=' . $tallH . ' short=' . $shortH);
        check(
            'TextArea 条 · lines(4) + 长内容 ⇒ 高度钉住且可滚',
            \abs($fixedTallH - $fixedShortH) < 0.01 && ($fixedTall?->maxScroll(Axis::Vertical) ?? 0.0) > 0.0,
            'fixedTall=' . $fixedTallH . ' fixedShort=' . $fixedShortH,
        );
        check('TextArea 条 · lines(4) + 短内容 ⇒ 高度不缩', \abs($fixedShortH - $fixedTallH) < 0.01);

        $this->flushChecks();
    }

    /** `mount()` 的 `lines()` 版（固定框高；`0` = 不限高）。**宽松**约束 —— 高度是它自己的决定 */
    private function mountWithLines(string $value, int $lines): array
    {
        $area = TextArea::make()->value($value)->minLines(1)->lines($lines);
        $root = $area->createElement();
        $root->mount(null, smokeContext());

        $state = $root instanceof StatefulElement ? $root->state() : null;
        $root->settle();
        $root->effectiveBox()?->layout(BoxConstraints::loose(new Size(self::WIDTH, 999.0)));

        $box = $state instanceof TextAreaState
            ? $state->element()?->findBox(static fn($c): bool => $c instanceof RenderTextArea)
            : null;

        return [$state, $box];
    }

    /** 滚动后条全亮，停手一会儿淡到 0.35；盒跟着收到值 */
    public function testFadeRunsToIdleOpacity(): void
    {
        [$state, $box] = $this->mount($this->longText(), 200.0, 3);

        // 滚动会 `noteScroll()`（条打回全亮）；重建一帧让 `build()` 把值递给新盒
        $state?->scrollBy(60.0);
        $state?->element()?->settle();
        $box = $state?->element()?->findBox(static fn($c): bool => $c instanceof RenderTextArea);
        $lit = $box?->barOpacity() ?? -1.0;

        // 推 2 秒：超过 900ms 空闲门限 + 淡出动画本身
        $t = 0.0;
        for ($i = 0; $i < 60; $i++) {
            $t += 33.0;
            $state?->tickFrame($t);
        }

        // `tickFor()` 把值就地推给**当前**盒（纯淡出帧不重建）
        $pushed = $box?->barOpacity() ?? -1.0;

        check('TextArea 条 · 刚滚过 ⇒ 全亮', $lit === 1.0, 'opacity=' . $lit);
        check(
            'TextArea 条 · 停手 ~2s ⇒ 淡到 0.35（不给 0：完全消失就没人知道这儿能滚）',
            abs($pushed - 0.35) < 0.01,
            'opacity=' . $pushed,
        );

        $this->flushChecks();
    }
}
