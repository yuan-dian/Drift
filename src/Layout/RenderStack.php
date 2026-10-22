<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;

/**
 * 层叠盒（arch-v2 `04` §4，W6 L4-4）。
 *
 * ```php
 * Stack::make()
 *     ->alignment(StackAlign::TopRight)
 *     ->fit(StackFit::Loose)
 *     ->children([$background, $badge]);
 * ```
 *
 * ## 四件事
 *
 * | # | 做什么 | 口径 |
 * |---|---|---|
 * | ① | 给子约束 | 按 `StackFit`（见 `StackFit` 的注释） |
 * | ② | 量每个子 | **每个子只 layout 一次**（没有第二遍） |
 * | ③ | 本盒尺寸 | `Loose`/`PassThrough` = `constrain(max(子))`；`Expand` 的有界轴 = 父给的 `max` |
 * | ④ | 定位 | 按 `StackAlign` 相对**本盒尺寸**算偏移 |
 *
 * ★ **`Stack` 不是两遍布局**：量完全部子 → 求 max → 算偏移，是**同一遍里的两个阶段**。
 *   上一版方案册写"`Expand` 才需要第二遍 layout"，那是在给循环依赖打补丁
 *   （本盒尺寸来自子、子尺寸又来自本盒）—— 已改为"`Expand` 取父给的尺寸"。
 *
 * ★ **命中测试不在这里写**：`RenderBox::hitTest()` 已经是 `array_reverse($children)`
 *   ⇒ 最后画的先命中，所有容器都这样。`Stack` 什么都不用做（方案册上一版把这条
 *   当成 `Stack` 的特权，是错的）。
 *
 * ★ **不做 `Overflow` 参数**（`04` §4.2 的裁决）：`Overflow::Clip` 今天不真裁
 *   （`src/Rendering/` 里没有裁剪 API），而本盒的 `mainSize = max(子)` ⇒ `Report`
 *   永不触发。加一个必填参数会得到"两个值都不生效"的假保障。真裁剪归
 *   `Modifier::clip`（未排期）。
 *
 * ★ **子盒 offset 住内容盒坐标**（`02` §4.1.1）：本盒的 `performLayout()` 拿到的是
 *   内容盒约束，偏移从 `(0,0)` 起算；`(padLeft, padTop)` 由 `paint()` 的 `translate()`
 *   与 `contentOriginX/Y()` 各补一次 —— 这里**不许**自己加 padding。
 *
 * @api
 */
final class RenderStack extends RenderBox
{
    /** @var list<RenderBox> */
    private array $kids = [];

    public function __construct(
        private readonly StackAlign $align = StackAlign::TopLeft,
        private readonly StackFit $fit = StackFit::Loose,
    ) {
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
        $maxW = $constraints->maxWidth;
        $maxH = $constraints->maxHeight;
        $w = $tightW ? $maxW : 0.0;
        $h = $tightH ? $maxH : 0.0;

        foreach ($this->kids as $kid) {
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
            $kid->setOffset($this->alignX($kid, $own), $this->alignY($kid, $own));
        }

        return $own;
    }

    protected function contentIntrinsicWidth(float $height): float
    {
        $max = 0.0;
        foreach ($this->kids as $kid) {
            $max = \max($max, $kid->intrinsicWidth($height));
        }

        return $max;
    }

    protected function contentIntrinsicHeight(float $width): float
    {
        $max = 0.0;
        foreach ($this->kids as $kid) {
            $max = \max($max, $kid->intrinsicHeight($width));
        }

        return $max;
    }

    protected function paintContent(Canvas $canvas): void
    {
        // 绘制序 = `children` 顺序：后加的压在上面
        foreach ($this->kids as $kid) {
            $canvas->save();
            $canvas->translate($kid->offsetX(), $kid->offsetY());
            $kid->paintComposed($canvas);
            $canvas->restore();
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

        $align = $this->align;

        return match ($align) {
            StackAlign::TopCenter,
            StackAlign::Center,
            StackAlign::BottomCenter => $free / 2.0,
            StackAlign::TopRight,
            StackAlign::CenterRight,
            StackAlign::BottomRight => $free,
            default => 0.0,
        };
    }

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

        $align = $this->align;

        return match ($align) {
            StackAlign::CenterLeft,
            StackAlign::Center,
            StackAlign::CenterRight => $free / 2.0,
            StackAlign::BottomLeft,
            StackAlign::BottomCenter,
            StackAlign::BottomRight => $free,
            default => 0.0,
        };
    }
}
