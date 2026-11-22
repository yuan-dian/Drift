<?php
declare(strict_types=1);

namespace App\Watermark\Widgets;

use Drift\Rendering\Color;

/**
 * 品牌色（逐值照抄 `原参考实现`）。
 *
 * ★ 只留**品牌**：状态色已进框架的 `ColorToken` + `StatusTone`，换肤改主题表即可。
 *   原文是一套固定色、没有深色模式；把状态也留在这里，深色主题下就会得到
 *   "深底 + 浅绿状态块"这种没人配过的组合。
 * ★ 全部是 `static` 方法而不是常量：`Color` 是对象，PHP 8.1 虽允许对象常量，
 *   但 tpc 对 `new` 型常量初值未验过。方法每次 new 一个 `Color`（`final readonly`
 *   小对象），成本可忽略。
 */
final class Palette
{
    /** 页面底（原文 `linear-gradient(135deg, #667eea, #764ba2)` —— 这里用纯色） */
    public static function page(): Color
    {
        return Color::fromHex('#F0F2FA');
    }

    /** 卡片底 */
    public static function card(): Color
    {
        return Color::fromHex('#FFFFFF');
    }

    /** 上传区的虚线色 */
    public static function dropBorder(): Color
    {
        return Color::fromHex('#CCCCCC');
    }

    /** 主色（按钮 / 进度条） */
    public static function accent(): Color
    {
        return Color::fromHex('#667EEA');
    }

    /** 序号徽章底 */
    public static function badge(): Color
    {
        return Color::fromHex('#667EEA');
    }

    /** 通用列表行底（不是状态色） */
    public static function listRow(): Color
    {
        return Color::fromHex('#F5F7FA');
    }
}
