<?php
declare(strict_types=1);

namespace Drift\Rendering;

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
 * ★ **三档底色，职责不重叠**（2026-10-25 拆分，原先 `Pane` 一 token 两用）：
 *   - `Pane`  = **画布底**（最暗一档）—— 窗口 / 页面背景板。它只回答"这地方
 *     没有面板"，不回答"这儿放得下内容"。
 *   - `Surface` = **面板底**（最亮一档）—— 卡片 / 弹层 / 输入框以外的内容底。
 *     亮于画布 ⇒ "浮起来"；这正是阴影之外的第二种浮起手段（不要钱的层次）。
 *   - `Chrome` = **外壳底**（中间档）—— 顶条 / 按钮默认底 / 分段轨道。它
 *     是"结构件"，既不是画布也不是面板，所以夹在中间。
 *   ★ 拆分前 `Pane` 同时是画布底和面板底（#FFFFFF），后果是 AppBar(#F3F3F3)
 *     与应用自己挑的画布色（#F2F3F5 量级）**几乎没有色差** —— 顶条糊在背景里，
 *     只能靠 1px 边框说话。现在画布压深一档，三层自然分开。
 *   ★ `TextFieldBox` 仍用 `Pane`：输入区要"凹"，用最暗那档是刻意的（见该类注释）。
 *
 * 取值集合沿用 GUI 仓实测过的调色板；层次靠明度（`Pane` 最底、
 * `Surface` 最亮、`Hover`/`Pressed` 依次浮起），暗色表里 `Accent`/`Danger`
 * **各提亮一档** —— 直接沿用浅色值会"深蓝压深灰"看不清。
 *
 * ★ 状态色成对：`Success`/`Warning`/`Info`/`Danger` 是前景，`*Surface` 是容器底。
 *   只给一个色会被调用方各自配底，很快又散回 hex；成对给，换肤才是改一张表。
 *   `WarningSurface` 同时承载进行中状态：那是色系（橙），不是业务语义。
 *
 * @api
 */
enum ColorToken
{
    /** 画布底（最暗一档；窗口 / 页面背景板） */
    case Pane;
    /** 面板底（最亮一档；卡片 / 弹层 / 内容底） */
    case Surface;
    /** 外壳 / 顶条 / 按钮默认底（中间档） */
    case Chrome;
    /** 正文文字 */
    case Text;
    /** 次要文字 */
    case Muted;
    /** 边框与分割线 */
    case Border;
    /** 强调（链接 / 选中） */
    case Accent;
    /** 破坏性操作 / 失败 */
    case Danger;
    /** 成功前景 */
    case Success;
    /** 成功容器底 */
    case SuccessSurface;
    /** 警告前景（进行中状态也用这一档橙色） */
    case Warning;
    /** 警告容器底 */
    case WarningSurface;
    /** 信息前景 */
    case Info;
    /** 信息容器底 */
    case InfoSurface;
    /** 失败容器底 */
    case DangerSurface;
    /** 悬停态底 */
    case Hover;
    /** 按下态底 */
    case Pressed;

    public function lightValue(): Color
    {
        return match ($this) {
            self::Pane    => Color::fromRgb(0xE9, 0xEB, 0xEF),
            self::Surface => Color::fromRgb(0xFF, 0xFF, 0xFF),
            self::Chrome  => Color::fromRgb(0xF3, 0xF3, 0xF3),
            self::Text    => Color::fromRgb(0x1F, 0x23, 0x28),
            self::Muted   => Color::fromRgb(0x6E, 0x77, 0x81),
            self::Border  => Color::fromRgb(0xD8, 0xDE, 0xE4),
            self::Accent  => Color::fromRgb(0x09, 0x69, 0xDA),
            self::Danger  => Color::fromRgb(0xD1, 0x24, 0x2F),
            self::DangerSurface => Color::fromRgb(0xFF, 0xEB, 0xEE),
            self::Success => Color::fromRgb(0x2E, 0x7D, 0x32),
            self::SuccessSurface => Color::fromRgb(0xE8, 0xF5, 0xE9),
            self::Warning => Color::fromRgb(0xE6, 0x51, 0x00),
            self::WarningSurface => Color::fromRgb(0xFF, 0xF3, 0xE0),
            self::Info    => Color::fromRgb(0x15, 0x65, 0xC0),
            self::InfoSurface => Color::fromRgb(0xE3, 0xF2, 0xFD),
            self::Hover   => Color::fromRgb(0xEC, 0xF0, 0xF5),
            self::Pressed => Color::fromRgb(0xDF, 0xE6, 0xEF),
        };
    }

    public function darkValue(): Color
    {
        return match ($this) {
            self::Pane    => Color::fromRgb(0x1E, 0x1E, 0x1E),
            self::Surface => Color::fromRgb(0x33, 0x33, 0x33),
            self::Chrome  => Color::fromRgb(0x2B, 0x2B, 0x2B),
            self::Text    => Color::fromRgb(0xE6, 0xE6, 0xE6),
            self::Muted   => Color::fromRgb(0x9A, 0xA0, 0xA6),
            self::Border  => Color::fromRgb(0x3C, 0x3C, 0x3C),
            self::Accent  => Color::fromRgb(0x4C, 0x9A, 0xFF), // 提亮一档
            self::Danger  => Color::fromRgb(0xF8, 0x51, 0x49), // 同上
            self::DangerSurface => Color::fromRgb(0x47, 0x23, 0x2A),
            self::Success => Color::fromRgb(0x66, 0xD2, 0x8A),
            self::SuccessSurface => Color::fromRgb(0x20, 0x37, 0x2A),
            self::Warning => Color::fromRgb(0xF5, 0xB8, 0x5A),
            self::WarningSurface => Color::fromRgb(0x3A, 0x2F, 0x1D),
            self::Info    => Color::fromRgb(0x8A, 0xB4, 0xFF),
            self::InfoSurface => Color::fromRgb(0x20, 0x32, 0x4A),
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
            self::Surface => Color::fromRgb(0xFF, 0xFF, 0xFF),
            self::Chrome  => Color::fromRgb(0xFF, 0xFF, 0xFF),
            self::Text    => Color::fromRgb(0x00, 0x00, 0x00),
            self::Muted   => Color::fromRgb(0x00, 0x00, 0x00),
            self::Border  => Color::fromRgb(0x00, 0x00, 0x00),
            self::Accent  => Color::fromRgb(0x00, 0x00, 0xCC),
            self::Danger  => Color::fromRgb(0xCC, 0x00, 0x00),
            self::DangerSurface => Color::white(),
            self::Success => Color::fromRgb(0x00, 0x70, 0x2C),
            self::SuccessSurface => Color::white(),
            self::Warning => Color::fromRgb(0x8A, 0x4B, 0x00),
            self::WarningSurface => Color::white(),
            self::Info    => Color::fromRgb(0x00, 0x00, 0xCC),
            self::InfoSurface => Color::white(),
            self::Hover, self::Pressed => null,
        };
    }
}
