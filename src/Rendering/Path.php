<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Geometry\Rect;

/**
 * 矢量路径（`PATH` / `CLIP_PATH` 的载体，Extended 层）。
 *
 * **为什么不是不可变值对象**：路径在 `CustomPainter::paint()` 里**逐段构造**
 * （fluent `moveTo()->lineTo()...`），每段都返回新实例 = 每段一次全量拷贝，
 * 而一条路径只有几到几十段 —— 用不可变换来的"值语义"没有消费者，只换来
 * 分配。等真实用法证明需要共享/比较路径时，再收成 builder + immutable
 * snapshot（与 `DisplayList` 同思路：先用扁平数组）。
 *
 * ★ 它是**几何数据**，不是命令缓冲：没有 save/restore，没有变换 —— 变换在
 *   画布上（`translate()`），路径本身以绘制时坐标系为准。
 * ★ 裁剪路径（`pushClipPath`）复用同一个类型：填充路径与裁剪路径的几何表达
 *   完全一致，差别只在画布怎么用它。
 *
 * @api
 */
final class Path
{
    public const CMD_MOVE = 1;
    public const CMD_LINE = 2;
    public const CMD_QUAD = 3;
    public const CMD_CLOSE = 4;

    /**
     * 扁平命令表：`[cmd, x1, y1, x2, y2]` 每项五槽。
     * MOVE/LINE 用 x1,y1；QUAD 用 cx,cy = x1,y1 与终点 x2,y2；CLOSE 参数全 0。
     *
     * @var list<array{int, float, float, float, float}>
     */
    private array $commands = [];

    public static function create(): self
    {
        return new self();
    }

    public function moveTo(float $x, float $y): self
    {
        $this->commands[] = [self::CMD_MOVE, $x, $y, 0.0, 0.0];
        return $this;
    }

    public function lineTo(float $x, float $y): self
    {
        $this->commands[] = [self::CMD_LINE, $x, $y, 0.0, 0.0];
        return $this;
    }

    /** 二次贝塞尔：控制点 (cx,cy)，终点 (x,y) */
    public function quadTo(float $cx, float $cy, float $x, float $y): self
    {
        $this->commands[] = [self::CMD_QUAD, $cx, $cy, $x, $y];
        return $this;
    }

    public function close(): self
    {
        $this->commands[] = [self::CMD_CLOSE, 0.0, 0.0, 0.0, 0.0];
        return $this;
    }

    /**
     * 便捷：四条边的矩形路径（闭合）。
     *
     * ★ 只加直角矩形：圆角矩形交给 `drawRect(rect, paint, radius)`（已有的
     *   `ROUND_RECT` op），不在这里复制一份圆角几何。
     */
    public function addRect(Rect $rect): self
    {
        $this->moveTo($rect->left, $rect->top);
        $this->lineTo($rect->right(), $rect->top);
        $this->lineTo($rect->right(), $rect->bottom());
        $this->lineTo($rect->left, $rect->bottom());
        $this->close();
        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->commands === [];
    }

    public function commandCount(): int
    {
        return count($this->commands);
    }

    /**
     * 命令表副本（PHP 数组 COW：调用方不写就不拷贝）。
     *
     * @return list<array{int, float, float, float, float}>
     */
    public function commands(): array
    {
        return $this->commands;
    }
}
