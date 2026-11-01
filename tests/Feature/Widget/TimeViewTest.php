<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\App\FocusManager;
use Drift\App\Frame;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderScrollView;
use Drift\Widget\ActivationContext;
use Drift\Widget\Button;
use Drift\Widget\Element;
use Drift\Widget\LocalTime;
use Drift\Widget\TimeView;

/**
 * `TimeView`：时分秒列选择器（2026-10-29）。
 *
 * ## 判据钉什么
 *
 * ① **列数与列内取值**（24 / 60 / 60），② **打开就落在当前值附近**（不给初始偏移
 * 就得从 00 滚 9 格），③ **点一列只改那一段**（三列共用一个受控值，最容易写错
 * 的地方是"点分列把时也改了"），④ **受控**（点完画面不动），⑤ **选中态**，
 * ⑥ **不占 Tab 序**（144 格挤进 Tab 序是灾难），⑦ **几何**（列宽 56 / 行高 34）。
 *
 * ★ ② 是这一件唯一"不报错但很难用"的地方：偏移算错时画面照样画得出来，
 *   只是打开时看到的是 00 点那一屏。
 */
final class TimeViewTest extends SmokeTestCase
{
    // ── ① 列与取值 ─────────────────────────────────────────────────────

    public function testDefaultShowsHourAndMinuteColumns(): void
    {
        $root = $this->mount(TimeView::make(LocalTime::make(9, 5)));
        $this->frame($root);

        $labels = $this->labels($root);

        check('TimeView · 默认有「时」表头', \in_array('时', $labels, true), 'labels=' . \json_encode($labels));
        check('TimeView · 默认有「分」表头', \in_array('分', $labels, true));
        check('TimeView · 默认没有「秒」表头', !\in_array('秒', $labels, true));

        // 时列 00..23、分列 00..59 —— 数字标签两位补零
        check('TimeView · 时列可见范围内是两位补零的数字', $this->firstHourLabel($root) === '07', 'first=' . $this->firstHourLabel($root));
        check('TimeView · 分列也有格子（两列都在画）', \count($this->columnsOf($root)) === 2, 'cols=' . \json_encode(\array_keys($this->columnsOf($root))));
    }

    public function testWithSecondsAddsThirdColumn(): void
    {
        $root = $this->mount(TimeView::make(LocalTime::make(9, 5, 7))->withSeconds());
        $this->frame($root);

        check('TimeView · withSeconds 出「秒」表头', \in_array('秒', $this->labels($root), true));
        check('TimeView · 秒列的选中格是 07', $this->primaryLabels($root) === ['09', '05', '07'], 'primary=' . \json_encode($this->primaryLabels($root)));
    }

    // ── ② 初始位置 ─────────────────────────────────────────────────────

    public function testOpensNearCurrentValue(): void
    {
        $root = $this->mount(TimeView::make(LocalTime::make(9, 5)));
        $this->frame($root);

        // 9 - LEAD_ROWS(2) = 7 ⇒ 时列第一格是 07，不是 00
        check('TimeView · 打开时滚到 07（当前 09 上方留两行）', $this->firstHourLabel($root) === '07', 'first=' . $this->firstHourLabel($root));
        check('TimeView · 打开时看得见 09', $this->hasHourLabel($root, 9));
        check('TimeView · 打开时看不见 00（说明真的滚了）', !$this->hasHourLabel($root, 0));
    }

    public function testZeroOffsetAtMidnight(): void
    {
        $root = $this->mount(TimeView::make(LocalTime::midnight()));
        $this->frame($root);

        check('TimeView · 当前 00 时从第一格开始', $this->firstHourLabel($root) === '00', 'first=' . $this->firstHourLabel($root));
    }

    // ── ③ 点一列只改那一段 ─────────────────────────────────────────────

    public function testClickingHourColumnKeepsMinuteAndSecond(): void
    {
        $seen = [];
        $root = $this->mount(
            TimeView::make(LocalTime::make(9, 5, 42))
                ->withSeconds()
                ->onChanged(static function (LocalTime $t) use (&$seen): void {
                    $seen[] = $t->iso();
                })
        );
        $this->frame($root);

        // 时列点 10（当前可见范围 07..14）；列起点 0 / 72 / 144（列盒 68 + 4 间隔）
        $this->buttonInColumn($root, 0.0, '10')?->activate(ActivationContext::none());
        check('TimeView · 点时列 10 ⇒ 10:05:42', $seen === ['10:05:42'], 'seen=' . \json_encode($seen));

        // 分列点 07（当前可见范围 03..10）—— 同时列也有 07，所以必须按列取
        $seen = [];
        $this->buttonInColumn($root, 72.0, '07')?->activate(ActivationContext::none());
        check('TimeView · 点分列 07 ⇒ 09:07:42', $seen === ['09:07:42'], 'seen=' . \json_encode($seen));
    }

    // ── ④ 受控 ─────────────────────────────────────────────────────────

    public function testReportsWithoutChangingPicture(): void
    {
        $seen = [];
        $root = $this->mount(
            TimeView::make(LocalTime::make(9, 5))
                ->onChanged(static function (LocalTime $t) use (&$seen): void {
                    $seen[] = $t->label();
                })
        );
        $this->frame($root);

        $this->buttonLabeled($root, '10')?->activate(ActivationContext::none());
        check('TimeView · 上报 10:05', $seen === ['10:05'], 'seen=' . \json_encode($seen));

        $this->frame($root);
        check('TimeView · 上报后高亮仍在 09（受控）', $this->primaryLabels($root) === ['09', '05'], 'primary=' . \json_encode($this->primaryLabels($root)));
    }

    // ── ⑤ 选中态 ───────────────────────────────────────────────────────

    public function testSelectedCellsArePrimary(): void
    {
        $root = $this->mount(TimeView::make(LocalTime::make(9, 5)));
        $this->frame($root);

        // 时列 09、分列 05 各一个 Primary；其余都是 Flat
        check('TimeView · 两列各有一个 Primary', $this->primaryLabels($root) === ['09', '05'], 'primary=' . \json_encode($this->primaryLabels($root)));
    }

    // ── ⑥ Tab 序 ───────────────────────────────────────────────────────

    public function testCellsAreNotFocusable(): void
    {
        $root = $this->mount(TimeView::make(LocalTime::make(9, 5))->withSeconds());
        $this->frame($root);

        check('TimeView · 三列都画出来了', $this->collectButtons($root) !== []);

        // ★ 判据走 `FocusManager`：`focusNode()` 为 null 不等于"不进 Tab 序"
        $fm = new FocusManager();
        $fm->collect([$root]);
        check('TimeView · 整块时刻表不占 Tab 站', \count($fm->order()) === 0, 'n=' . \count($fm->order()));
    }

    // ── ⑦ 几何 ─────────────────────────────────────────────────────────

    public function testColumnWidthAndRowHeight(): void
    {
        $root = $this->mount(TimeView::make(LocalTime::make(9, 5))->withSeconds());
        $this->frame($root);

        $columns = $this->columnsOf($root);
        check('TimeView · 三列各有格子', \count($columns) === 3, 'cols=' . \json_encode(\array_keys($columns)));

        // 列盒 68（内容 56 + 滚动条留白 12）+ 间隔 4 ⇒ 起点 0 / 72 / 144
        $xs = \array_keys($columns);
        \sort($xs);
        check('TimeView · 列起点是 0 / 72 / 144', $xs === ['0.0', '72.0', '144.0'], 'xs=' . \json_encode($xs));

        // 行高 34：同一列内相邻格子的 y 差
        $gaps = [];
        foreach ($columns as $cells) {
            $prev = null;
            foreach ($cells as $cell) {
                if ($prev !== null) {
                    $gaps[] = \round($cell[2] - $prev[2], 1);
                }
                $prev = $cell;
            }
        }

        $ok = $gaps !== [];
        foreach ($gaps as $gap) {
            if (\abs($gap - TimeView::ROW_HEIGHT) > 0.5) {
                $ok = false;
            }
        }

        check('TimeView · 行高是 34', $ok, 'gaps=' . \json_encode($gaps));
    }

    /**
     * 滚动条不能压住色块。
     *
     * ★ 这条曾经是**真机才看出来**的缺陷：条画在视口里（`ScrollBarGeometry` 按盒宽
     *   定位），而格子声明满宽 ⇒ 条正好切在 Primary 块的右边缘上。它不报错、
     *   不影响点击，只是难看 —— 所以必须由几何断言钉住。
     */
    public function testScrollbarSitsRightOfCells(): void
    {
        $root = $this->mount(TimeView::make(LocalTime::make(9, 5))->withSeconds());
        $this->frame($root);

        $bars = $this->barLeftEdges($root);
        check('TimeView · 三列各画一根滚动条', \count($bars) === 3, 'bars=' . \json_encode($bars));

        $bad = [];
        foreach ($this->columnsOf($root) as $x => $cells) {
            [$button, $left, , $width] = $cells[0];
            $right = $left + $width;
            $bar = $bars[(string) $x] ?? null;
            if ($bar === null || $right > $bar + 0.01) {
                $bad[] = $x . ' ⇒ cellRight=' . $right . ' barLeft=' . \var_export($bar, true);
            }
            unset($button);
        }

        check('TimeView · 条落在色块右侧（不压数字）', $bad === [], \implode(' | ', $bad));
    }

    // ── ⑦b 响应式：窄容器横向滚，不溢出 ───────────────────────────────

    /**
     * 可用宽 < 固有宽（212）时，TimeView 应**横向滚**而不是把内容裁掉或溢出。
     *
     * ★ 这是「参考成熟 GUI」的判决落点：固定宽多列控件在窄容器里让内容滚，
     *   而不是压窄列宽（压窄会裁两位数字）。208 是 420px 极限窗 - 200 侧栏后的
     *   内容区宽，差 4px 正好逼出横向滚动。
     */
    public function testScrollsHorizontallyWhenNarrowerThanIntrinsic(): void
    {
        $root = $this->mount(TimeView::make(LocalTime::make(9, 5))->withSeconds());
        $this->frameAt($root, 208.0);
        $narrow = $this->horizontalMaxScroll($root);
        check('TimeView · 208 宽容器下出现横向滚动（不溢出）', $narrow > 0.0, 'maxScrollX=' . $narrow);

        $root2 = $this->mount(TimeView::make(LocalTime::make(9, 5))->withSeconds());
        $this->frameAt($root2, 240.0);
        $wide = $this->horizontalMaxScroll($root2);
        check('TimeView · 240 宽容器下不横向滚动（弹层尺寸不变）', $wide === 0.0, 'maxScrollX=' . $wide);
    }

    // ── 夹具 ───────────────────────────────────────────────────────────

    private function mount(TimeView $view): Element
    {
        $element = $view->createElement();
        $element->mount(null, smokeContext());

        return $element;
    }

    private function frame(Element $root): void
    {
        $this->frameAt($root, 240.0);
    }

    /** 用指定宽度的容器约束跑一帧（默认 240，窄回归用 208） */
    private function frameAt(Element $root, float $w): void
    {
        $surface = new SurfaceProbe();
        $surface->resize((int) $w, 280, 1.0);
        (new Frame($surface))->run($root, BoxConstraints::loose(new Size($w, 280.0)), 64.0);
    }

    /** 横向滚动视口的可滚上限（无则 0） */
    private function horizontalMaxScroll(Element $root): float
    {
        $out = 0.0;
        $walk = function (Element $el) use (&$walk, &$out): void {
            $box = $el->effectiveBox();
            if ($box instanceof RenderScrollView) {
                $out = \max($out, $box->maxScroll(Axis::Horizontal));
            }
            $el->visitChildren($walk);
        };
        $walk($root);

        return $out;
    }

    /** @return list<Button> 所有文字按钮（表头是 Text，收不到） */
    private function collectButtons(Element $root): array
    {
        $out = [];
        $walk = function (Element $el) use (&$walk, &$out): void {
            $widget = $el->widget();
            if ($widget instanceof Button) {
                $out[] = $widget;
            }
            $el->visitChildren($walk);
        };
        $walk($root);

        return $out;
    }

    /** @return list<string> 所有按钮标签 + 表头文字 */
    private function labels(Element $root): array
    {
        $out = [];
        $walk = function (Element $el) use (&$walk, &$out): void {
            $widget = $el->widget();
            if ($widget instanceof Button) {
                $out[] = $widget->label();
            } elseif ($widget instanceof \Drift\Widget\Text) {
                $out[] = $widget->content();
            }
            $el->visitChildren($walk);
        };
        $walk($root);

        return $out;
    }

    /** @return list<array{Button,float,float,float}> [按钮, x, y, 宽]（绝对原点） */
    private function cells(Element $root): array
    {
        $out = [];
        $walk = function (Element $el) use (&$walk, &$out): void {
            $widget = $el->widget();
            if ($widget instanceof Button) {
                $box = $el->effectiveBox();
                if ($box !== null) {
                    [$x, $y] = boxOrigin($box);
                    $out[] = [$widget, $x, $y, $box->size()?->width ?? 0.0];
                }
            }
            $el->visitChildren($walk);
        };
        $walk($root);

        return $out;
    }

    /**
     * 每列的滚动条左边缘（绝对 x）。
     *
     * @return array<string, float> 列盒 x 起点 ⇒ 条左边缘
     */
    private function barLeftEdges(Element $root): array
    {
        $out = [];
        $walk = function (Element $el) use (&$walk, &$out): void {
            $box = $el->effectiveBox();
            if ($box instanceof RenderScrollView) {
                $bar = $box->thumbRect(Axis::Vertical);
                if ($bar !== null) {
                    [$x] = boxOrigin($box);
                    $out[\sprintf('%.1f', $x)] = $x + $bar->left;
                }
            }
            $el->visitChildren($walk);
        };
        $walk($root);

        return $out;
    }

    /** @return array<string, list<array{Button,float,float}>> x 起点 ⇒ 该列的格子 */
    private function columnsOf(Element $root): array
    {
        $columns = [];
        foreach ($this->cells($root) as $cell) {
            // ★ key 用 `%.1f` 字符串：`"56"` 会被 PHP 规范成 int，`"56.0"` 不会
            $columns[\sprintf('%.1f', $cell[1])][] = $cell;
        }

        return $columns;
    }

    /** @return list<string> */
    private function primaryLabels(Element $root): array
    {
        $out = [];
        foreach ($this->collectButtons($root) as $button) {
            if ($button->variantValue()->name === 'Primary') {
                $out[] = $button->label();
            }
        }

        return $out;
    }

    private function buttonLabeled(Element $root, string $label): ?Button
    {
        foreach ($this->collectButtons($root) as $button) {
            if ($button->label() === $label) {
                return $button;
            }
        }

        return null;
    }

    /** 指定列（x 起点）里标签为 `$label` 的格子 —— 三列会有同名标签，必须按列取 */
    private function buttonInColumn(Element $root, float $x, string $label): ?Button
    {
        foreach ($this->cells($root) as $cell) {
            if (\abs($cell[1] - $x) < 0.5 && $cell[0]->label() === $label) {
                return $cell[0];
            }
        }

        return null;
    }

    /** 时列里有没有这个钟点（按"标签 == 两位补零"判定，避开分列同名的撞车） */
    private function hasHourLabel(Element $root, int $hour): bool
    {
        $label = \str_pad((string) $hour, 2, '0', STR_PAD_LEFT);

        return $this->buttonLabeled($root, $label) !== null;
    }

    /** 时列第一格（x 最小那一列里 y 最小的按钮） */
    private function firstHourLabel(Element $root): string
    {
        $first = null;
        foreach ($this->cells($root) as $cell) {
            if ($cell[1] > 0.5) {
                continue;   // 只在第一列（x = 0）里找
            }
            if ($first === null || $cell[2] < $first[2]) {
                $first = $cell;
            }
        }

        return $first === null ? '(none)' : $first[0]->label();
    }
}
