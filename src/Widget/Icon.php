<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;
use Drift\Layout\RenderIcon;
use Drift\Rendering\Color;
use Drift\Rendering\ColorToken;

/**
 * 矢量图标（`08` §4.2）—— 无字体、无位图，图形数据住 `IconSet`。
 *
 * ```php
 * Icon::make(IconName::Menu);                       // 16px，次要文字色
 * Icon::make(IconName::Search, 20.0)->color($c);    // 显式尺寸与颜色
 * ```
 *
 * ★ **不进语义树**（不给 `semantics()`）：图标是装饰 —— 它要么是按钮内容
 *   （可访问名由按钮给），要么纯装点。与 `Text` 同一条纪律（`SemanticsRole`
 *   类注释："叶子内容不给角色"）。
 * ★ **尺寸是逻辑像素**，与 `BoxConstraints` 同单位；`size` 同时决定线宽
 *   （默认 `max(1, size × 0.09)`，16px ⇒ 1.44px）。
 * ★ 用 `Path` 而不是 `Text` + 图标字体：见 `IconName` 的类注释（字模生成扫不到
 *   私有区码点 ⇒ 真机零宽隐形）。
 *
 * @api
 */
final class Icon extends RenderBoxWidget
{
    private float $size = 16.0;

    private ?Color $color = null;

    /** `0` = 按尺寸自动（见类注释）；显式给才覆盖 */
    private float $strokeWidth = 0.0;

    public function __construct(private readonly IconName $name)
    {
        parent::__construct();
    }

    public static function make(IconName $name, float $size = 16.0): self
    {
        return (new self($name))->size($size);
    }

    /** 边长（逻辑像素；图标是正方形） */
    public function size(float $size): static
    {
        $this->mutate();
        $this->size = $size;

        return $this;
    }

    /** 颜色（`null` = 主题的次要文字色 `Muted`） */
    public function color(?Color $color): static
    {
        $this->mutate();
        $this->color = $color;

        return $this;
    }

    /** 线宽（`0` = 按尺寸自动） */
    public function strokeWidth(float $width): static
    {
        $this->mutate();
        $this->strokeWidth = $width;

        return $this;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderIcon(
            IconSet::path($this->name, $this->size),
            $this->color ?? $context->color(ColorToken::Muted),
            $this->strokeWidth > 0.0 ? $this->strokeWidth : max(1.0, $this->size * 0.09),
            $this->size,
        );
    }
}
