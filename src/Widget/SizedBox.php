<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;
use Drift\Layout\RenderProxy;

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
 * ## W5：`width` / `height` 不再住这里
 *
 * 它们继承自 `Widget`，写进 `mods`；夹取规则搬进 `Modifier::tighten()`（与原来
 * `RenderSizedBox` 的逐轴夹取**逐行同构**，含"父更紧 ⇒ 听父的"）。于是本类只剩
 * 便利方法 `size()` 与"壳"的职责：`createRenderObject()` 给纯转发盒 `RenderProxy`。
 *
 * @api
 */
final class SizedBox extends NullableChildRenderObjectWidget
{
    public static function make(?Widget $kid = null): self
    {
        $self = new self();
        if ($kid !== null) {
            $self->kid = $kid;
        }

        return $self;
    }

    public function kid(): ?Widget
    {
        return $this->kid;
    }

    /**
     * 零尺寸 —— "这一块这一帧不存在"。
     *
     * ★ `build()` 必须返回 Widget，条件渲染没有 `null` 可交；而空 `Text` 会在
     *   `Column` 里占一行高度。这是表达"不显示"的唯一写法，给它一个自解释的名字。
     * ★ **不能叫 `shrink()`**：那个名字在 `WidgetModifiers` 里是 flex 收缩权重
     *   （`shrink(int $weight)`），含义完全不同。
     */
    public static function zero(): self
    {
        return self::make()->size(0.0, 0.0);
    }

    /** 两轴一起定（等价于 `->width($w)->height($h)`，只是少一次 mutate） */
    public function size(float $width, float $height): static
    {
        return $this->boxSize($width, $height);
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderProxy();
    }
}
