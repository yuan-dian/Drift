<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\Alignment;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;

/**
 * 层叠盒：子按 `Alignment`（非定位）或 `inset`（定位）摆放，非定位子撑出本盒尺寸。
 *
 * 单遍布局：先量非定位子求 max，再排定位子，不是两遍。
 * ★ **定位子不撑大本盒**：只有定位子的 `Stack` 尺寸是 0，那种写法要自己套 `SizedBox`。
 * ★ **子盒 offset 用内容盒坐标**：从 (0,0) 起算，padding 由 `paint()` 的 `translate()` 补，这里不许自己加 padding。
 *
 * @api
 */
final class RenderStack extends RenderBox implements ChildContainer
{
    use MultiChildBox;

    /** @var list<RenderBox> */
    private array $kids = [];

    private readonly Alignment $align;

    public function __construct(
        ?Alignment $align = null,
        private readonly StackFit $fit = StackFit::Loose,
    ) {
        // 默认 TopLeft：Stack 语义是"叠上去、默认贴左上"（与 `Align` 的 center 不同）。
        // 参数写成 `?Alignment = null` 而非方法调用作默认值：默认参数里不能调用静态方法（PHP 限制）。
        $this->align = $align ?? Alignment::topLeft();
    }

    /**
     * 新配置与本盒**完全等价**吗（对齐 / 撑满策略）？
     *
     * 契约同 `RenderFlex::matchesConfiguration()`：只比自己的构造参数。
     * 入参是 `?Alignment`：`null` 表示"默认 TopLeft"，必须在**解析后**比 ——
     * 拿 null 与对象比永远不等（复用静默失效，不报错）。
     */
    public function matchesConfiguration(?Alignment $align, StackFit $fit): bool
    {
        return $this->align->equals($align ?? Alignment::topLeft())
            && $this->fit === $fit;
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $expand = $this->fit === StackFit::Expand;
        $tightW = $expand && $constraints->hasBoundedWidth();
        $tightH = $expand && $constraints->hasBoundedHeight();

        // ① 子约束（同一条约束给所有子 —— `Stack` 不区分"主内容/角标"）
        $kidBox = $this->childConstraints($constraints, $tightW, $tightH);

        // ② 量 + ③ 求 max。`Expand` 的有界轴直接取父给的尺寸（不来自子）。
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

        // ④ 只定位、不重排。偏移相对**本盒尺寸**（不是约束上限），否则松约束下子被推出盒外。
        foreach ($this->kids as $kid) {
            if ($kid->hasInset()) {
                // 定位子在本盒尺寸已知后才 layout（每个子只 layout 一次）。
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

    // ── 内部 ────────────────────────────────────────────────────────

    /**
     * 三个 `StackFit` 给子的约束。
     *
     * 属性先落局部标量：AOT 下直接读 `$c->maxWidth` 会被推成 `php::Variant`，与 `BoxConstraints` 的 `float` 形参不匹配 ⇒ `C2440`（编译期报错）。
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
     * 声明两条对边 ⇒ 那一轴紧（拉伸）；只给一边 ⇒ 只收上限；都不给 ⇒ 退回 `Alignment`。
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

        // 属性先落局部标量：避免 AOT 下被推成 `php::Variant` 与 `float` 形参冲突。
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
            // 夹到 0：本仓不产生负偏移；子比可用宽还大时贴左边（放不下，非算错）。
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
