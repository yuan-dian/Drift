<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Rendering\Canvas;

/**
 * 单子代理盒：`Padding` / `Expanded` 的共同基座。
 *
 * ★ 它**不定义布局策略** —— 只回答"我的唯一孩子是谁、怎么把画布递过去"。
 *   于是"加内边距"与"参与 flex"是两个薄子类，而不是一个带 5 个分支的盒子。
 *
 * @api
 */
abstract class RenderProxyBox extends RenderBox
{
    protected ?RenderBox $child = null;

    public function setChild(RenderBox $child): void
    {
        $this->child = $child;
        $this->adoptChild($child);
    }

    public function visitChildren(callable $visitor): void
    {
        if ($this->child !== null) {
            $visitor($this->child);
        }
    }

    protected function contentIntrinsicWidth(float $height): float
    {
        return $this->child?->intrinsicWidth($height) ?? 0.0;
    }

    protected function contentIntrinsicHeight(float $width): float
    {
        return $this->child?->intrinsicHeight($width) ?? 0.0;
    }

    protected function paintContent(Canvas $canvas): void
    {
        if ($this->child === null) {
            return;
        }

        $this->paintChildAt($canvas, $this->child);
    }
}
