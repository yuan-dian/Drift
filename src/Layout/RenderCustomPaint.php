<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Widget\CustomPainter;

/**
 * 自绘盒（`CustomPaint` 的渲染侧，2026-10-20 P2）：19 op 逃生舱的**尺寸仲裁者**。
 *
 * 布局只有一条职责：回答"这块自绘区多大"；绘画只有一句：把画布和尺寸递给画家。
 * 它刻意不做子节点、不做盒样式 —— 组合（`Card` / `Padding` / `SizedBox`）比
 * 开关便宜（§13.1 的合成传统：`RenderSizedBox` 只定尺寸、装饰走盒样式控件）。
 *
 * ## 尺寸（逐轴，与 `RenderSizedBox` 同源的夹取规则）
 *
 * - 给定轴：夹进父的 `[min, max]`（父更紧听父 —— 约束是父给的，子无权突破，C2）
 * - 未给定轴：吃满约束上限；上限不限 ⇒ **响亮失败**（自绘区不能无穷大）
 *
 * @internal 控件侧入口是 `CustomPaint`（@api）；本类只被它构造。
 */
final class RenderCustomPaint extends RenderBox
{
    public function __construct(
        private readonly CustomPainter $painter,
        private readonly ?float $width = null,
        private readonly ?float $height = null,
    ) {
    }

    /**
     * 画家内容戳直通（`CustomPainter::version()` 的框架侧出口）。
     *
     * 今天没有框架消费者（全树重画）；留给脏矩形 / 重绘调度（M6 候选）与诊断
     * HUD（示例台自绘页正在显示它）。
     */
    public function painterVersion(): int
    {
        return $this->painter->version();
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        // ★ AOT 纪律：属性读取先落局部标量（三元/?? 分支里直接读属性会被推成
        //   Variant，与 float 形参不匹配 ⇒ C2440；2026-10-20 P0 同族注释）。
        $wantW = $this->width;
        $wantH = $this->height;

        // 未给定且上限不限 ⇒ 这一轴没有答案（自绘区不能无穷大）
        if ($wantW === null && !$constraints->hasBoundedWidth()) {
            Errors::report(
                'CustomPaint 没有指定宽度，父容器也没有限制宽度（宽度不限）。' . "\n"
                . '两条修法：① 给 CustomPaint 一个明确的宽度（如 new CustomPaint($painter, 240.0, 160.0)）；' . "\n"
                . '② 换一个会限制宽度的父容器（如卡片、固定宽的行），别让这一轴无限。'
            );
        }
        if ($wantH === null && !$constraints->hasBoundedHeight()) {
            Errors::report(
                'CustomPaint 没有指定高度，父容器也没有限制高度（高度不限）。' . "\n"
                . '两条修法：① 给 CustomPaint 一个明确的高度（如 new CustomPaint($painter, 240.0, 160.0)）；' . "\n"
                . '② 换一个会限制高度的父容器（如卡片、固定高的行），别让这一轴无限。'
            );
        }

        $boxW = $constraints->maxWidth;
        if ($wantW !== null) {
            $w = $wantW;
            if ($w > $constraints->maxWidth) {
                $w = $constraints->maxWidth;   // 父更紧 ⇒ 听父
            }
            if ($w < $constraints->minWidth) {
                $w = $constraints->minWidth;
            }
            $boxW = $w;
        }

        $boxH = $constraints->maxHeight;
        if ($wantH !== null) {
            $h = $wantH;
            if ($h > $constraints->maxHeight) {
                $h = $constraints->maxHeight;
            }
            if ($h < $constraints->minHeight) {
                $h = $constraints->minHeight;
            }
            $boxH = $h;
        }

        return $constraints->constrain(new Size($boxW, $boxH));
    }

    public function paint(Canvas $canvas): void
    {
        $size = $this->size;
        if ($size === null) {
            return;   // 未参与本轮布局（如父还没 layout）⇒ 不画
        }

        $this->painter->paint($canvas, $size);
    }

    public function intrinsicWidth(float $height): float
    {
        if ($this->width !== null) {
            return $this->width;
        }

        // 未给定 ⇒ 自然尺寸没有意见（0）。要参与 Row/Dock 的自然宽度量就给值
        return 0.0;
    }

    public function intrinsicHeight(float $width): float
    {
        if ($this->height !== null) {
            return $this->height;
        }

        return 0.0;
    }

    /** 无子节点（组合留给外层控件：`Card` / `Padding` / `SizedBox` 比开关便宜） */
    public function visitChildren(callable $visitor): void
    {
    }
}
