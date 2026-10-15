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
}
