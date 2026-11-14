<?php
declare(strict_types=1);

namespace Drift\Rendering;

/**
 * 阴影**档位**（`Widget::shadow()` 的取值口径）。
 *
 * ★ 单位是**黑色浓度**（0..1 的 alpha），不是像素高度：几何部分（模糊半径 /
 *   下移量）由 `Modifier::paintBehind()` 按浓度等比推出 —— 浓度越高，影子越大
 *   也越散。这样调用点只有一个数要调，不会出现"改大了模糊忘了改下移"。
 *
 * ★ 为什么不枚举：这三个值是连续量上的**约定刻度**，不是互斥状态机。写成
 *   `enum` 之后 `Elevation::Raised->value` 到处都是，而"两个档之间插一个"就要
 *   改 enum（AOT 侧未定义的 case 是直接段错误，本仓踩过）。常量最轻。
 *
 * ★ 默认不浮起：本仓视觉基线是 LayUI 风 —— 卡片靠 1px 边框立住，影子只在
 *   "真的浮在内容之上"的东西上（弹层 / 对话框 / 悬停的按钮）。
 *
 * @api
 */
final class Elevation
{
    /** 贴地（不画）：卡片、面板、分组块 */
    public const float Flat = 0.0;

    /** 抬起一档：悬停中的按钮、可拖拽的块 */
    public const float Raised = 0.06;

    /** 浮在内容之上：下拉面板、菜单、气泡 */
    public const float Floating = 0.12;

    /** 独占焦点：对话框、模态面板 */
    public const float Overlay = 0.18;
}
