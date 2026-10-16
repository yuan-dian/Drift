<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 导航按钮（§12.2 外壳词汇：`NavButton::make('列表')->command('nav.users')`）。
 *
 * ★ **为什么是独立类型而不是 `Button` 的别名**：壳控件词汇要可被 lint /
 *   devtools 静态识别（"Root 里只允许壳控件 + PageHost + RouteTable"这条
 *   判据的前提是壳控件有名字），且激活态高亮（当前页对应项）将来有落点。
 *
 * ★ 行为就是 `Button`：链式 / 盒样式 / 焦点 / `command()` / `onPressed()`
 *   全部继承，本类不加机制 —— 加机制的应该是新原语，不是新名字。
 *
 * @api
 */
final class NavButton extends Button
{
    public static function make(string $label): self
    {
        return new self($label);
    }
}
