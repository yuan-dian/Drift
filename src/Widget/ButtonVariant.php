<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 按钮的固定变体（§15.4 命名规则）。
 *
 * ★ **变体工厂是 `make()` 的薄壳别名**，只许是固定变体、不许封装机制 ——
 *   §15.4 的删除测试：`Button::primary('x')` 必须等价于
 *   `Button::make('x')->variant(ButtonVariant::Primary)`。糖删掉后框架契约
 *   与测试原样完整（只是更啰嗦）。
 *
 * @api
 */
enum ButtonVariant
{
    case Default;
    case Primary;
    case Danger;

    /**
     * 扁平：**无底、无边框**，字色取 `Text`（侧栏 / 工具条那种"看起来像菜单项"的按钮）。
     *
     * ★ 2026-10-17 加：`Button` 的盒样式合并是"用户设了**非 null/非零**就用用户的"
     *   ⇒ `->border($c, 0.0)` 与 `->radius(0.0)` **表达不出"我要 0"**（静默回退主题值）。
     *   变体是那条合并规则之外的一条正路：语义在变体表里说清，不去改 `BoxStyle` 的
     *   "null = 未设"约定（那是 `@api` 值对象的语义变更，另立项）。
     */
    case Flat;
}
