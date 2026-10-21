<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;
use Drift\Layout\RenderSizedBox;

/**
 * 定尺寸：把孩子的某一轴（或两轴）钉死成给定值。
 *
 * ```php
 * // 侧栏固定 200 宽、高随内容
 * SizedBox::make($sidebar)->width(200.0);
 *
 * // 两个块之间留 12px 空隙（`Spacer` 是 flex，这个是绝对值）
 * SizedBox::make()->width(12.0)->height(1.0);
 * ```
 *
 * ★ **`null` = 该轴透传**（不是哨兵）：只调 `->width()` 就是"只定宽、高随内容"。
 *   两个轴各自独立，这跟 §15.2 要禁的"拿 null 当缺省值"是两回事 —— 这里
 *   "不定这一轴"本身就是一个合法且常用的语义。
 *
 * ★ **为什么它不能由 `Padding` 顶替**：`Padding` 只能**加**内边距（尺寸 = 内容 + insets），
 *   永远**不能小于内容**。`Row` 里想让侧栏固定 200 而内容只有 148，`Padding` 只会
 *   变成 148 + 2×padding，是"内容驱动"的另一种写法，不是定尺寸。
 *
 * ★ **父约束更紧时听父的**（`tight(100)` 里塞 `width(200)` 得到 100，不报错）——
 *   约束是父给的，子无权突破（§13.1 C2）。
 *
 * @api
 */
final class SizedBox extends RenderBoxWidget
{
    private ?Widget $kid = null;

    private ?float $width = null;

    private ?float $height = null;

    public static function make(?Widget $kid = null): self
    {
        $self = new self();
        if ($kid !== null) {
            $self->kid = $kid;
        }

        return $self;
    }

    public function child(Widget $kid): static
    {
        $this->mutate();
        $this->kid = $kid;

        return $this;
    }

    public function kid(): ?Widget
    {
        return $this->kid;
    }

    public function width(float $value): static
    {
        $this->mutate();
        $this->width = $value;

        return $this;
    }

    public function height(float $value): static
    {
        $this->mutate();
        $this->height = $value;

        return $this;
    }

    /** 两轴一起定（等价于 `->width($w)->height($h)`，只是少一次 mutate） */
    public function size(float $width, float $height): static
    {
        $this->mutate();
        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderSizedBox($this->width, $this->height);
    }

    #[\Override]
    public function createElement(): Element
    {
        return new ProxyElement($this);
    }
}
