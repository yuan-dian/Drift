<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\EdgeInsets;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderProxy;

/**
 * 内边距（§13.1 几何面 —— 不是盒样式，所以它不继承 `BoxWidget`）。
 *
 * ```php
 * Padding::make($child)->paddingAll(8);
 * ```
 *
 * ★ **W5 之后它只是一个"壳"**（arch-v2 `02` §11）：`padding()` 继承自 `Widget`、
 *   写进 `mods`；`createRenderObject()` 给一个纯转发盒 `RenderProxy`。内距由
 *   `RenderBox::layout()` 的 ②⑤ 步施加、`paint()` 的 `translate()` 换坐标 ——
 *   与 `Text::make('hi')->paddingAll(8)` 走**同一条路**。这正是 W5 要的：
 *   修饰是字段，不是节点。
 *
 * ★ `all` / `symmetric` / `only` 三个便利方法保留：`apps/` 与 `LazyColumnState`
 *   在用，而且这里的 `only()` 是"没给的边就是 0"，与 `Widget::paddingOnly()` 的
 *   "`null` = 不动那一边"语义不同 —— 合并会静默改掉调用点的意思。
 *
 * @api
 */
final class Padding extends RenderBoxWidget
{
    public function __construct(private readonly Widget $kid)
    {
        parent::__construct();
    }

    public static function make(Widget $kid): self
    {
        return new self($kid);
    }

    public function all(float $value): static
    {
        return $this->padding(EdgeInsets::all($value));
    }

    public function symmetric(float $horizontal = 0.0, float $vertical = 0.0): static
    {
        return $this->padding(EdgeInsets::symmetric($horizontal, $vertical));
    }

    public function only(float $left = 0.0, float $top = 0.0, float $right = 0.0, float $bottom = 0.0): static
    {
        return $this->padding(EdgeInsets::only($left, $top, $right, $bottom));
    }

    public function child(): Widget
    {
        return $this->kid;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderProxy();
    }

    #[\Override]
    public function createElement(): Element
    {
        return new ProxyElement($this);
    }
}
