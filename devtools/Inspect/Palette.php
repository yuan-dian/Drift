<?php
declare(strict_types=1);

namespace Drift\DevTools\Inspect;

use Drift\Rendering\ColorToken;
use Drift\Rendering\Paint;
use Drift\Theme\ThemeData;

/**
 * 四层盒的配色。**只有这一处**知道"盒模型的四层长什么样"。
 *
 * ★ 色值不硬编码，全部从主题 token 派生：深色 / 浅色主题下不用各调一遍，
 *   而且 `src` 侧一个色值都不知道（A72：调试面在 `devtools/`，壳只接闭包）。
 * ★ 用 token 而不是照抄 Chrome 的橙/黄/绿/蓝：那套色在浅色主题上对比度刚好，
 *   在这个项目的深色面板上会糊成一片。
 * ★ 全部半透明、且只做**填充**：四层是拿来量厚度的，遮住内容就本末倒置。
 */
final class Palette
{
    /** 容器边界的普通态（淡，数量多） */
    public const int BOUNDS_CONTAINER = 64;

    /** 叶子 / 可命中控件（浓一点，才是要找的那些） */
    public const int BOUNDS_LEAF = 144;

    /** 选中链（自己 + 祖先）：满，只画少数几个 */
    public const int BOUNDS_CHAIN = 255;

    /** 四层填充 */
    public const int MARGIN_ALPHA = 72;

    public const int BORDER_ALPHA = 112;

    public const int PADDING_ALPHA = 80;

    public const int CONTENT_ALPHA = 56;

    /** margin 层：Chrome 用橙，这里取 `Warning`（同色系） */
    public static function margin(ThemeData $theme): Paint
    {
        return Paint::fill($theme->color(ColorToken::Warning)->withAlpha(self::MARGIN_ALPHA));
    }

    /** border 层：盒的边界，取 `Accent` */
    public static function border(ThemeData $theme): Paint
    {
        return Paint::fill($theme->color(ColorToken::Accent)->withAlpha(self::BORDER_ALPHA));
    }

    /** padding 层：`Success` */
    public static function padding(ThemeData $theme): Paint
    {
        return Paint::fill($theme->color(ColorToken::Success)->withAlpha(self::PADDING_ALPHA));
    }

    /** content 层：`Info` */
    public static function content(ThemeData $theme): Paint
    {
        return Paint::fill($theme->color(ColorToken::Info)->withAlpha(self::CONTENT_ALPHA));
    }

    /** 选中链的边界描边（满不透明、2px，压在填充之上） */
    public static function boundsChain(ThemeData $theme): Paint
    {
        return Paint::stroke($theme->color(ColorToken::Warning)->withAlpha(self::BOUNDS_CHAIN), 2.0);
    }

    /** 容器 / 叶子的边界描边：同一色系，浓淡由调用方给的档位决定 */
    public static function boundsPlain(ThemeData $theme, int $alpha): Paint
    {
        return Paint::stroke($theme->color(ColorToken::Accent)->withAlpha($alpha), 1.0);
    }
}
