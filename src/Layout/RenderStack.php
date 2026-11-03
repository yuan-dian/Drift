<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\Alignment;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;

/**
 * 层叠盒（arch-v2 `04` §4，W6 L4-4）。
 *
 * ```php
 * Stack::make()
 *     ->alignment(Alignment::topRight())
 *     ->fit(StackFit::Loose)
 *     ->children([$background, $badge->positioned(right: 8.0, top: 8.0)]);
 * ```
 *
 * ## 五件事
 *
 * | # | 做什么 | 口径 |
 * |---|---|---|
 * | ① | 给子约束 | 按 `StackFit`（见 `StackFit` 的注释） |
 * | ② | 量每个子 | **每个子只 layout 一次**（没有第二遍） |
 * | ③ | 本盒尺寸 | `Loose`/`PassThrough` = `constrain(max(非定位子))`；`Expand` 的有界轴 = 父给的 `max` |
 * | ④ | 定位 | 非定位子按 `Alignment`；**定位子按 `inset`**（`layoutPositioned()`） |
 * | ⑤ | 定位子的约束 | 声明两条对边 ⇒ 那一轴**紧**（拉伸）；单边 ⇒ 只收上限 |
 *
 * ★ **`Stack` 不是两遍布局**：量完非定位子 → 求 max → 排定位子，是**同一遍里的
 *   三个阶段**。上一版方案册写"`Expand` 才需要第二遍 layout"，那是在给循环依赖打补丁
 *   （本盒尺寸来自子、子尺寸又来自本盒）—— 已改为"`Expand` 取父给的尺寸"。
 *
 * ★★ **定位子不撑大本盒**（`layoutPositioned()` 的注）：这是"套尺寸"与"按 inset
 *   摆位置"互为前提时的唯一解，也是本盒能单遍跑完的前提。代价是**只有定位子的
 *   `Stack` 尺寸是 0** ⇒ 那种写法要自己套 `SizedBox`。
 *
 * ★ **命中测试不在这里写**：`RenderBox::hitTest()` 已经是 `array_reverse($children)`
 *   ⇒ 最后画的先命中，所有容器都这样。`Stack` 什么都不用做（方案册上一版把这条
 *   当成 `Stack` 的特权，是错的）。
 *
 * ★ **不做 `Overflow` 参数**（`04` §4.2 的裁决）：本盒的 `mainSize = max(子)` ⇒
 *   子永远不会越过盒边界 ⇒ `Report` 永不触发、`Clip` 也无事可裁。加一个必填参数
 *   会得到"两个值都不生效"的假保障。
 *   ★ 2026-10-23 修正理由（结论不变）：`Modifier::clip` 现在已经**真的会裁**
 *   （`02` §11.11），所以"没有裁剪 API"不再是理由；真正的理由只剩上面那条几何
 *   事实。要给 `Stack` 的内容加裁剪，用 `Stack::make()->clip()`。
 *
 * ★ **子盒 offset 住内容盒坐标**（`02` §4.1.1）：本盒的 `performLayout()` 拿到的是
 *   内容盒约束，偏移从 `(0,0)` 起算；`(padLeft, padTop)` 由 `paint()` 的 `translate()`
 *   与 `contentOriginX/Y()` 各补一次 —— 这里**不许**自己加 padding。
 *
 * @api
 */
final class RenderStack extends RenderBox implements ChildContainer
{
    /** @var list<RenderBox> */
    private array $kids = [];

    private readonly Alignment $align;

    public function __construct(
        ?Alignment $align = null,
        private readonly StackFit $fit = StackFit::Loose,
    ) {
        // ★ 默认 **TopLeft**（保持 2026-10-26 起的既有行为）。注意这与 `Align` 的默认
        //   `center` **刻意不同**：`Stack` 的语义是"叠上去、默认贴左上"，改成 center
        //   会让所有既有 `Stack` 的静态内容整体偏移（golden 会集体漂移）。
        //   参数写成 `?Alignment = null` 而不是方法调用作默认值：默认参数里不能调用
        //   静态方法（PHP 层就不允许，与 AOT 无关）。
        $this->align = $align ?? Alignment::topLeft();
    }

    public function addChild(RenderBox $child): void
    {
        $this->kids[] = $child;
        $this->adoptChild($child);
    }

    /** @internal 重建前清空 —— 否则同一次 rebuild 会把子盒追加两遍 */
    public function clearChildren(): void
    {
        $this->kids = [];
    }

    /** @return list<RenderBox> */
    public function children(): array
    {
        return $this->kids;
    }

    /** 访问序 = 绘制序 = 层叠序（后加的在上） */
    public function visitChildren(callable $visitor): void
    {
        foreach ($this->kids as $kid) {
            $visitor($kid);
        }
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $expand = $this->fit === StackFit::Expand;
        $tightW = $expand && $constraints->hasBoundedWidth();
        $tightH = $expand && $constraints->hasBoundedHeight();

        // ① 子约束（同一条约束给所有子 —— `Stack` 不区分"主内容/角标"）
        $kidBox = $this->childConstraints($constraints, $tightW, $tightH);

        // ② 量 + ③ 求 max。`Expand` 的有界轴直接取父给的尺寸（**不来自子**）
        // ★★ **定位子（有 `inset`）不参与本盒尺寸** —— 见 `layoutPositioned()` 的注。
        //   这是"套尺寸"与"按 inset 摆位置"之间循环依赖的唯一解：本盒尺寸先由
        //   非定位子定下来，定位子才拿得到"距右 8"里的"右"是谁。
        $maxW = $constraints->maxWidth;
        $maxH = $constraints->maxHeight;
        $w = $tightW ? $maxW : 0.0;
        $h = $tightH ? $maxH : 0.0;

        foreach ($this->kids as $kid) {
            if ($kid->hasInset()) {
                continue;
            }
            $kid->layout($kidBox);
            $kidSize = $kid->size();
            if ($kidSize === null) {
                continue;
            }
            if (!$tightW) {
                $w = \max($w, $kidSize->width);
            }
            if (!$tightH) {
                $h = \max($h, $kidSize->height);
            }
        }

        $own = $constraints->constrain(new Size($w, $h));

        // ④ 只定位、不重排。偏移相对**本盒尺寸**（不是约束上限）—— 与
        //   `RenderFlex` 的交叉轴居中同一条纪律：拿上限算会在松约束下把子推出盒外。
        foreach ($this->kids as $kid) {
            if ($kid->hasInset()) {
                // ★ 定位子在**本盒尺寸已知之后**才第一次（也是唯一一次）layout
                $this->layoutPositioned($kid, $own);

                continue;
            }

            $kid->setOffset($this->alignX($kid, $own), $this->alignY($kid, $own));
        }

        return $own;
    }

    protected function contentIntrinsicWidth(float $height): float
    {
        $max = 0.0;
        foreach ($this->kids as $kid) {
            if ($kid->hasInset()) {
                continue; // 与 `performLayout()` 同口径：定位子不撑大本盒
            }
            $max = \max($max, $kid->intrinsicWidth($height));
        }

        return $max;
    }

    protected function contentIntrinsicHeight(float $width): float
    {
        $max = 0.0;
        foreach ($this->kids as $kid) {
            if ($kid->hasInset()) {
                continue;
            }
            $max = \max($max, $kid->intrinsicHeight($width));
        }

        return $max;
    }

    protected function paintContent(Canvas $canvas): void
    {
        // 绘制序 = `children` 顺序：后加的压在上面
        foreach ($this->kids as $kid) {
            $this->paintChildAt($canvas, $kid);
        }
    }

    // ── 内部 ────────────────────────────────────────────────────────

    /**
     * 三个 `StackFit` 给子的约束。
     *
     * ★ **属性先落局部标量**：AOT 下在分支里直接读 `$c->maxWidth` 会被推成
     *   `php::Variant`，而 `BoxConstraints` 的形参是 `float` ⇒ `C2440`
     *   （`RenderFlex::tightMain` 同型坑，2026-10-16 真机暴露过）。
     */
    private function childConstraints(BoxConstraints $c, bool $tightW, bool $tightH): BoxConstraints
    {
        $maxW = $c->maxWidth;
        $maxH = $c->maxHeight;

        if ($tightW || $tightH) {
            // Expand：有界轴紧、无界轴 0..INF（退化为 Loose，绝不产生 INF 尺寸）
            $minW = 0.0;
            $w = INF;
            if ($tightW) {
                $minW = $maxW;
                $w = $maxW;
            }

            $minH = 0.0;
            $h = INF;
            if ($tightH) {
                $minH = $maxH;
                $h = $maxH;
            }

            return new BoxConstraints($minW, $w, $minH, $h);
        }

        if ($this->fit === StackFit::PassThrough) {
            return $c;
        }

        return new BoxConstraints(0.0, $maxW, 0.0, $maxH);
    }

    /**
     * 排一个**定位子**（`inset` 声明了至少一条边）：约束按边收紧，偏移按边算。
     *
     * ## 为什么定位子不参与本盒尺寸
     *
     * "定位子有多大"取决于"距左 / 距右各多少"，而这两个数要减的是**本盒宽度**；
     * 本盒宽度又来自 `max(子)`。两边互为前提 ⇒ 要么两遍 layout（本盒明令不是，
     * `StackTest::testEachChildLaidOutOnce` 盯着），要么**断开这条依赖**。
     *
     * 断法与上游一致：**定位子不撑大 `Stack`**。于是顺序是
     * `量非定位子 → 定本盒尺寸 → 排定位子`，依赖消失，且每个子仍只 layout 一次
     * （定位子只是在**稍后**那一趟里被量，不是被量两次）。
     *
     * ★ 这条的代价要写清楚：**只有定位子的 `Stack` 尺寸是 0**（没有非定位子撑它，
     *   而松约束下 `constrain(0,0)` 就是 0）⇒ 那种写法必须自己套 `SizedBox`。
     *   这是上游的同款行为，不是本仓的缺陷 —— 但它是"写了没反应"的一类，
     *   所以钉在 `StackPositionedTest` 里当**契约**（不是缺陷）。
     *
     * ## 每条边的三种形态
     *
     * | 声明 | 那一轴的约束 | 偏移 |
     * |---|---|---|
     * | 两边都给 | **紧** `盒宽 − 左 − 右`（拉伸，CSS 的 `left:0;right:0`） | 左 |
     * | 只给一边 | 上限收掉那一边的距离 | 给了的那边 |
     * | 两边都不给 | 上限 = 盒宽 | 退回 `Alignment` |
     *
     * ★ **两边都给时子被拉伸**是这三条里唯一"用户没写尺寸却得到尺寸"的形态，
     *   也是 `Positioned` 相对"只能贴九个方位"多出来的真正价值。
     *
     * ★ `Modifier::tighten()` 在 `layout()` 的 ① 步还会再夹一次 ⇒ 用户显式
     *   `->width()` 时**显式尺寸赢**（与 `basis` 优先于自然尺寸同口径）。
     */
    private function layoutPositioned(RenderBox $kid, Size $own): void
    {
        $l = $kid->insetLeft();
        $r = $kid->insetRight();
        $t = $kid->insetTop();
        $b = $kid->insetBottom();

        $hasL = !\is_nan($l);
        $hasR = !\is_nan($r);
        $hasT = !\is_nan($t);
        $hasB = !\is_nan($b);

        // ★ 全部先落**局部标量**：AOT 下在分支里直接读属性会被推成 `php::Variant`，
        //   与 `BoxConstraints` 的 `float` 形参不匹配 ⇒ `C2440`（`RenderFlex::tightMain`
        //   与 `RenderStack::childConstraints` 同型坑）。
        $ownW = $own->width;
        $ownH = $own->height;

        // ── ① 约束 ────────────────────────────────────────────────
        $minW = 0.0;
        $maxW = $ownW;
        if ($hasL && $hasR) {
            $span = $ownW - $l - $r;
            if ($span < 0.0) {
                $span = 0.0;   // 两条边把空间吃光 ⇒ 0 宽，不产生负约束
            }
            $minW = $span;
            $maxW = $span;
        } elseif ($hasL) {
            $span = $ownW - $l;
            $maxW = $span < 0.0 ? 0.0 : $span;
        } elseif ($hasR) {
            $span = $ownW - $r;
            $maxW = $span < 0.0 ? 0.0 : $span;
        }

        $minH = 0.0;
        $maxH = $ownH;
        if ($hasT && $hasB) {
            $span = $ownH - $t - $b;
            if ($span < 0.0) {
                $span = 0.0;
            }
            $minH = $span;
            $maxH = $span;
        } elseif ($hasT) {
            $span = $ownH - $t;
            $maxH = $span < 0.0 ? 0.0 : $span;
        } elseif ($hasB) {
            $span = $ownH - $b;
            $maxH = $span < 0.0 ? 0.0 : $span;
        }

        $kid->layout(new BoxConstraints($minW, $maxW, $minH, $maxH));

        // ── ② 偏移（要等子尺寸定下来才敢算"距右"）──────────────────
        $kidSize = $kid->size();
        if ($kidSize === null) {
            return;
        }

        $x = $this->alignX($kid, $own);
        if ($hasL) {
            $x = $l;
        } elseif ($hasR) {
            // ★ 夹到 0：与 `alignX()` 同口径（本仓不产生负偏移）。子比可用宽还大时
            //   贴左边，代价是右边那条距离被牺牲 —— 那是"放不下"，不是"算错"。
            $rest = $ownW - $r - $kidSize->width;
            $x = $rest < 0.0 ? 0.0 : $rest;
        }

        $y = $this->alignY($kid, $own);
        if ($hasT) {
            $y = $t;
        } elseif ($hasB) {
            $rest = $ownH - $b - $kidSize->height;
            $y = $rest < 0.0 ? 0.0 : $rest;
        }

        $kid->setOffset($x, $y);
    }

    /**
     * 非定位子的水平落点。
     *
     * ★ 2026-10-29 由"两个 `match` 枚举九宫格"塌成一次乘法：`Alignment` 的
     *   `x ∈ -1..1` 直接就是"自由空间的哪一段"（`-1 ⇒ 0`、`0 ⇒ 半`、`+1 ⇒ 全`），
     *   枚举的 9 个分支原本只是这 3 个系数的查表。**顺带能表达中间值**
     *   （`Alignment(0.5, 0)` = 起点与中心之间），枚举做不到。
     */
    private function alignX(RenderBox $kid, Size $own): float
    {
        $kidSize = $kid->size();
        if ($kidSize === null) {
            return 0.0;
        }

        $free = $own->width - $kidSize->width;
        if ($free <= 0.0) {
            // 子比盒宽（`PassThrough` 下可能）⇒ 贴左边，不产生负偏移
            return 0.0;
        }

        return $this->align->resolveX($free);
    }

    /** 见 {@see alignX()} */
    private function alignY(RenderBox $kid, Size $own): float
    {
        $kidSize = $kid->size();
        if ($kidSize === null) {
            return 0.0;
        }

        $free = $own->height - $kidSize->height;
        if ($free <= 0.0) {
            return 0.0;
        }

        return $this->align->resolveY($free);
    }
}
