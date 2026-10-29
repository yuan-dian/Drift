<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\Alignment;

/**
 * 把唯一的孩子摆在容器正中 —— `Align` 的 0 号特例。
 *
 * ```php
 * Center::make()->child($content);
 * ```
 *
 * ★ 居中是**字段**（`mods.align = Alignment::center()`），不是盒子里的开关 ——
 *   `RenderCenter` 在 M2-4 删除，由 `RenderBox::layout()` 的 ③（松下界）与
 *   ⑦（按锚点算偏移）施加。所以本类**没有自己的布局代码**，与
 *   `Align::make($x, Alignment::center())` 是同一个东西。
 *
 * ★ 2026-10-29 由"独立类"收敛为 `Align` 的子类：两者原本把 `kid` 那套管线
 *   （`make` / `child` / `kid` / `expandChildren` / `createRenderObject` /
 *   `createElement`）各抄一遍，抄漏一处就是"内容不显示、也不报错"。现在只有一份。
 *
 * @api
 */
final class Center extends Align
{
    public function __construct()
    {
        parent::__construct(Alignment::center());
    }

    /**
     * @param ?Alignment $alignment **忽略**：`Center` 恒为居中。参数保留是因为
     *   PHP 不允许子类方法比父类少参数（`Declaration must be compatible`，
     *   已实测 Fatal）—— 想换锚点请直接用 `Align`。
     */
    public static function make(?Widget $kid = null, ?Alignment $alignment = null): self
    {
        $self = new self();
        if ($kid !== null) {
            // `$kid` 是 `Align` 的 private 字段 ⇒ 走公开的 `child()`；
            // `mutate()` 在未挂载时只检查 sealed 标志，安全。
            $self->child($kid);
        }

        return $self;
    }
}
