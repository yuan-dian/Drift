<?php
declare(strict_types=1);

namespace App\Watermark\Widgets;

use Drift\Rendering\Color;

/**
 * 配色（**逐值照抄 `原参考实现`**）。
 *
 * ★ 为什么不用 `ColorToken`（主题角色）：原文是一套**固定的品牌色**（紫色渐变
 *   主色 + 四个状态色），没有深色模式。套主题角色的话，深色主题下"上传区浅灰底
 *   + 深灰虚线"会变成"深底 + 浅线"，与原文的观感差很远。
 *   要支持主题时，正确的做法是**新增一组 `ColorToken`**（框架侧），而不是在
 *   应用里散着写 hex —— 但那是另一件事，不在本应用的范围内。
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

    /** 卡片边框 / 分隔线 */
    public static function border(): Color
    {
        return Color::fromHex('#E1E5E9');
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

    /** 主文字 */
    public static function text(): Color
    {
        return Color::fromHex('#333333');
    }

    /** 次文字 */
    public static function subText(): Color
    {
        return Color::fromHex('#555555');
    }

    /** 提示文字 */
    public static function hint(): Color
    {
        return Color::fromHex('#888888');
    }

    /** 序号徽章底 */
    public static function badge(): Color
    {
        return Color::fromHex('#667EEA');
    }

    /** 徽章上的字 */
    public static function badgeText(): Color
    {
        return Color::fromHex('#FFFFFF');
    }

    /** 已上传行的底 */
    public static function listRow(): Color
    {
        return Color::fromHex('#F5F7FA');
    }

    /** 成功：底 */
    public static function successBg(): Color
    {
        return Color::fromHex('#E8F5E9');
    }

    /** 成功：字 */
    public static function successText(): Color
    {
        return Color::fromHex('#2E7D32');
    }

    /** 失败：底 */
    public static function errorBg(): Color
    {
        return Color::fromHex('#FFEBEE');
    }

    /** 失败：字 */
    public static function errorText(): Color
    {
        return Color::fromHex('#C62828');
    }

    /** 信息：底 */
    public static function infoBg(): Color
    {
        return Color::fromHex('#E3F2FD');
    }

    /** 信息：字 */
    public static function infoText(): Color
    {
        return Color::fromHex('#1565C0');
    }

    /** 进行中：底 */
    public static function loadingBg(): Color
    {
        return Color::fromHex('#FFF3E0');
    }

    /** 进行中：字 */
    public static function loadingText(): Color
    {
        return Color::fromHex('#E65100');
    }
}
