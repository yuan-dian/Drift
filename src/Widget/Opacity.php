<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;
use Drift\Layout\RenderProxy;

/**
 * 不透明度（`1.0` = 不透明）：整棵子树一起变淡，**不重建、不重排**。
 *
 * ```php
 * Opacity::make(0.6, $card)
 * ```
 *
 * ## 为什么还要一个控件（`->opacity()` 修饰符不是已经能用了）
 *
 * 修饰符写的是**盒**上的合成槽，而一个盒只有一个槽 —— 两层 `->opacity()` 套在
 * 同一棵子树上时**后写的覆盖先写的**（不是相乘）。最容易踩的形态：
 *
 * ```php
 * $inner = Card::make()->opacity(0.5);      // 想要半透明
 * $outer = SizedBox::make($inner)->opacity(0.9);
 * // ⇒ 实际是 0.9，那个 0.5 静默消失
 * ```
 *
 * 本控件给每次包装**自己的盒**（`RenderProxy`），于是合成槽各归各的 ⇒ 可以嵌套，
 * 也可以和子控件自己的 `->opacity()` 叠加。这是 `RenderProxy` 的既有用法
 * （`SizedBox` / `Center` / `Padding` 都这么干：**修饰是字段，不是节点**）。
 *
 * ## 它怎么画
 *
 * `alpha < 1` ⇒ `RenderBox::paintComposed()` 走 `pushLayer()`（真开层，整棵子树
 * 一起乘一次 alpha）；`alpha == 1` ⇒ 零开销快路，一条命令都不多。
 *
 * ★ 要**动**的不透明度别用它，用 `AnimatedOpacity` —— 后者每帧 `setCompose()`
 *   直接写盒、不重建不重排；而本控件的值只在 rebuild 时生效。
 *
 * @api
 */
final class Opacity extends RenderBoxWidget
{
    private function __construct(private readonly Widget $kid)
    {
        parent::__construct();
    }

    public static function make(float $alpha, Widget $kid): self
    {
        $self = new self($kid);
        // ★ 写进 `mods`（不是字段）：`RenderBox::setMods()` 读一次去初始化盒上的
        //   合成参数 —— 与 `SizedBox::size()` 同一条路，不另开真相。
        $self->opacity($alpha);

        return $self;
    }

    /** 被包装的孩子（原样挂进 `RenderProxy`，不复制、不包装） */
    public function kid(): Widget
    {
        return $this->kid;
    }

    #[\Override]
    public function expandChildren(): array
    {
        return [$this->kid];
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
