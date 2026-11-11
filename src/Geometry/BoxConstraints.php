<?php
declare(strict_types=1);

namespace Drift\Geometry;

use Drift\Errors;

/**
 * 盒约束（§13.1 C2）。
 *
 * ★ **无界 = `INF`，永不拿 0 当哨兵**。GUI 仓为此踩过三次坑：`0..maxH` 的松上界
 *   被当成"可用高"吃满、合法取值被当哨兵 ⇒ 安静地错。
 *
 * ★ `constrain()` / `tighten()` 是**纯函数** —— 布局是纯函数（G3）的前提。
 *
 * @api
 */
final readonly class BoxConstraints
{
    public const INFINITY = INF;

    public function __construct(
        public float $minWidth = 0.0,
        public float $maxWidth = self::INFINITY,
        public float $minHeight = 0.0,
        public float $maxHeight = self::INFINITY,
    ) {
        if ($minWidth > $maxWidth || $minHeight > $maxHeight) {
            // C6：违反 ⇒ 响亮失败（Fatal 级，release 也中止）
            Errors::report(sprintf(
                'BoxConstraints 违反约束：min 不得大于 max（w %s..%s, h %s..%s）',
                (string) $minWidth,
                (string) $maxWidth,
                (string) $minHeight,
                (string) $maxHeight
            ));
        }
    }

    /** 无界：`0..INF` 两轴都不限（C2 的默认形态） */
    public static function unbounded(): self
    {
        return new self();
    }

    /** 紧约束：min == max == size（父级定死了子节点尺寸） */
    public static function tight(Size $size): self
    {
        return new self($size->width, $size->width, $size->height, $size->height);
    }

    /** 松约束：`0..size`（子节点可在范围内自选） */
    public static function loose(Size $size): self
    {
        return new self(0.0, $size->width, 0.0, $size->height);
    }

    /**
     * **只限宽**：宽 `0..$maxWidth`，高不限（W8-2 Tooltip，2026-10-23）。
     *
     * ★ 调用方不手写 `BoxConstraints::INFINITY`：只限宽的约束在这一个出口里写，
     *   避免 `INF` 与 `0` 两个哨兵混用。当年「外部类常量求值成 0」的 tpc 缺陷
     *   已由上游修复（见 `PITFALLS` 2026-11-11 勘误）；工厂与 Tooltip 尺寸断言
     *   保留作回归守卫。
     */
    public static function capWidth(float $maxWidth): self
    {
        return new self(0.0, $maxWidth, 0.0, self::INFINITY);
    }

    public function hasBoundedWidth(): bool
    {
        return !is_infinite($this->maxWidth);
    }

    public function hasBoundedHeight(): bool
    {
        return !is_infinite($this->maxHeight);
    }

    public function hasInfiniteWidth(): bool
    {
        return is_infinite($this->maxWidth);
    }

    public function hasInfiniteHeight(): bool
    {
        return is_infinite($this->maxHeight);
    }

    public function isTight(): bool
    {
        return $this->minWidth === $this->maxWidth && $this->minHeight === $this->maxHeight;
    }

    /** 把尺寸夹进约束 —— 纯函数 */
    public function constrain(Size $size): Size
    {
        return new Size(
            min(max($size->width, $this->minWidth), $this->maxWidth),
            min(max($size->height, $this->minHeight), $this->maxHeight)
        );
    }

    /** 把尺寸夹进约束，再收成紧约束 */
    public function tighten(Size $size): self
    {
        $width = min(max($size->width, $this->minWidth), $this->maxWidth);
        $height = min(max($size->height, $this->minHeight), $this->maxHeight);
        return new self($width, $width, $height, $height);
    }

    /**
     * 按宽高比求尺寸（W8-3 `AspectRatio`，2026-10-24）。
     *
     * 与 `tighten()` 同族：返回**紧**约束（比例一旦确定，盒尺寸就被定死）。
     *
     * 求解顺序：
     * ① 两轴都已紧 ⇒ 原样返回（`width` + `height` 都显式给了，比例没有自由度）；
     * ② 一轴已紧 ⇒ 另一轴由比例推出（**显式尺寸优先于比例**）；
     * ③ 两轴都松 ⇒ 先铺满有界的那轴，超出另一轴再回退（与成熟实现同款）；
     * ④ 两轴都无界 ⇒ 原样返回（比例给不出绝对尺寸）。
     *
     * ★ 末尾的 `min/max` 夹取可能让比例不严格 —— 比例要求的尺寸小于 `min` 时，
     *   **父给的下界优先**（与 `tighten()` 的"父更紧时听父的"同一条）。
     */
    public function applyAspect(float $ratio): self
    {
        if ($ratio <= 0.0 || is_nan($ratio) || is_infinite($ratio)) {
            return $this;
        }
        if ($this->isTight()) {
            return $this;
        }

        $minW = $this->minWidth;
        $maxW = $this->maxWidth;
        $minH = $this->minHeight;
        $maxH = $this->maxHeight;

        // ② 一轴已紧：另一轴 = 紧值 / 比例
        if ($minW === $maxW) {
            $w = $minW;
            $h = min(max($w / $ratio, $minH), $maxH);

            return new self($w, $w, $h, $h);
        }
        if ($minH === $maxH) {
            $h = $minH;
            $w = min(max($h * $ratio, $minW), $maxW);

            return new self($w, $w, $h, $h);
        }

        // ③ 两轴都松：有界的那轴铺满，超界再回退
        if (!is_infinite($maxW)) {
            $w = $maxW;
            $h = $w / $ratio;
            if ($h > $maxH) {
                $h = $maxH;
                $w = $h * $ratio;
            }
            if ($h < $minH) {
                $h = $minH;
                $w = $h * $ratio;
            }
        } elseif (!is_infinite($maxH)) {
            $h = $maxH;
            $w = $h * $ratio;
            if ($w > $maxW) {
                $w = $maxW;
                $h = $w / $ratio;
            }
            if ($w < $minW) {
                $w = $minW;
                $h = $w / $ratio;
            }
        } else {
            return $this;
        }

        $w = min(max($w, $minW), $maxW);
        $h = min(max($h, $minH), $maxH);

        return new self($w, $w, $h, $h);
    }

    /**
     * 去掉四边内边距后剩下的可用约束（`Padding` / `RenderFlex` 用；§13.1）。
     *
     * ★ min 同步缩小但不小于 0 —— 否则"内边距比约束还大"会造出 `min > max` 的
     *   非法约束，而那会撞 C6 的构造期检查，报的却是 padding 的错。
     */
    public function deflate(EdgeInsets $padding): self
    {
        $minWidth = max(0.0, $this->minWidth - $padding->horizontal());
        $minHeight = max(0.0, $this->minHeight - $padding->vertical());

        return new self(
            $minWidth,
            max($minWidth, $this->maxWidth - $padding->horizontal()),
            $minHeight,
            max($minHeight, $this->maxHeight - $padding->vertical()),
        );
    }

    /** 放开下界，保留上界 */
    public function loosen(): self
    {
        return new self(0.0, $this->maxWidth, 0.0, $this->maxHeight);
    }

    /** 把本约束夹进外层约束（子约束不得超出父约束） */
    public function enforce(BoxConstraints $outer): self
    {
        return new self(
            min(max($this->minWidth, $outer->minWidth), $outer->maxWidth),
            min(max($this->maxWidth, $outer->minWidth), $outer->maxWidth),
            min(max($this->minHeight, $outer->minHeight), $outer->maxHeight),
            min(max($this->maxHeight, $outer->minHeight), $outer->maxHeight)
        );
    }

    public function equals(BoxConstraints $other): bool
    {
        return $this->minWidth === $other->minWidth
            && $this->maxWidth === $other->maxWidth
            && $this->minHeight === $other->minHeight
            && $this->maxHeight === $other->maxHeight;
    }
}
