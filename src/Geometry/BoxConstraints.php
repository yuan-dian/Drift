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
     * ★ 为什么要有这个工厂，而不是调用方自己 `new BoxConstraints(0, $w, 0, BoxConstraints::INFINITY)`：
     *   **AOT 侧那行是坏的** —— `BoxConstraints::INFINITY` 作为**外部类常量引用**
     *   在 tpc 生成的 C++ 里求值成 `0`，于是 `maxHeight = 0` ⇒ 子节点被夹成
     *   高度 0 ⇒ **面板宽高全 0、画出来什么都没有，而解释器（smoke）全绿**。
     *   走类内 `self::INFINITY`（与 `unbounded()` / `loose()` 同一条已被验证的
     *   路径）就正常。2026-10-23 真机实测：`placed x=268 y=161.6 w=0 h=0`。
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
