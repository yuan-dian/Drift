<?php
declare(strict_types=1);

namespace Drift\Theme;

use Drift\Rendering\Color;

/**
 * 语义颜色角色（§11.5 / §15.7）。
 *
 * ★ **是枚举，不是字符串键**：§16.4 已否决"字符串注册表"（GUI 仓的
 *   `Theme::colors()` 返回 `array<string,int>`，取色时写错键要等运行期才发现）。
 *   枚举 ⇒ 拼错是编译错。
 *
 * ★ **为什么要这一层**：控件只说角色（`->error()`），不说颜色。换肤改的是
 *   **这张表**，不是每个调用点。
 *
 * 取值集合沿用 GUI 仓实测过的调色板（9 键）：层次靠明度（`Pane` 最底、
 * `Chrome` 面板、`Hover`/`Pressed` 依次浮起），暗色表里 `Accent`/`Danger`
 * **各提亮一档** —— 直接沿用浅色值会"深蓝压深灰"看不清。
 *
 * @api
 */
enum ColorToken
{
    /** 画布底（最底层） */
    case Pane;
    /** 外壳 / 面板（比 Pane 高一层） */
    case Chrome;
    /** 正文文字 */
    case Text;
    /** 次要文字 */
    case Muted;
    /** 边框与分割线 */
    case Border;
    /** 强调（链接 / 选中） */
    case Accent;
    /** 破坏性操作 */
    case Danger;
    /** 悬停态底 */
    case Hover;
    /** 按下态底 */
    case Pressed;

    public function lightValue(): Color
    {
        return match ($this) {
            self::Pane    => Color::fromRgb(0xFF, 0xFF, 0xFF),
            self::Chrome  => Color::fromRgb(0xF3, 0xF3, 0xF3),
            self::Text    => Color::fromRgb(0x1F, 0x23, 0x28),
            self::Muted   => Color::fromRgb(0x6E, 0x77, 0x81),
            self::Border  => Color::fromRgb(0xD8, 0xDE, 0xE4),
            self::Accent  => Color::fromRgb(0x09, 0x69, 0xDA),
            self::Danger  => Color::fromRgb(0xD1, 0x24, 0x2F),
            self::Hover   => Color::fromRgb(0xEC, 0xF0, 0xF5),
            self::Pressed => Color::fromRgb(0xDF, 0xE6, 0xEF),
        };
    }

    public function darkValue(): Color
    {
        return match ($this) {
            self::Pane    => Color::fromRgb(0x1E, 0x1E, 0x1E),
            self::Chrome  => Color::fromRgb(0x2B, 0x2B, 0x2B),
            self::Text    => Color::fromRgb(0xE6, 0xE6, 0xE6),
            self::Muted   => Color::fromRgb(0x9A, 0xA0, 0xA6),
            self::Border  => Color::fromRgb(0x3C, 0x3C, 0x3C),
            self::Accent  => Color::fromRgb(0x4C, 0x9A, 0xFF), // 提亮一档
            self::Danger  => Color::fromRgb(0xF8, 0x51, 0x49), // 同上
            self::Hover   => Color::fromRgb(0x33, 0x33, 0x33),
            self::Pressed => Color::fromRgb(0x3F, 0x3F, 0x3F),
        };
    }

    /**
     * 高对比度取值（§9.2）；`null` = 该角色**没有**高对比形态。
     *
     * ★ 不是"每个角色都必须有一个值" —— `Hover` / `Pressed` 的高对比形态
     *   通常是**描边**而不是换底色，硬给一个值只会得到"悬停变全黑、按下也是
     *   全黑、两者无法区分"。⇒ 没有就说没有，`withHighContrast()` 会跳过它们。
     */
    public function highContrastValue(): ?Color
    {
        return match ($this) {
            self::Pane    => Color::fromRgb(0xFF, 0xFF, 0xFF),
            self::Chrome  => Color::fromRgb(0xFF, 0xFF, 0xFF),
            self::Text    => Color::fromRgb(0x00, 0x00, 0x00),
            self::Muted   => Color::fromRgb(0x00, 0x00, 0x00),
            self::Border  => Color::fromRgb(0x00, 0x00, 0x00),
            self::Accent  => Color::fromRgb(0x00, 0x00, 0xCC),
            self::Danger  => Color::fromRgb(0xCC, 0x00, 0x00),
            self::Hover, self::Pressed => null,
        };
    }
}
