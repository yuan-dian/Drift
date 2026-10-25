<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 内置图标的标识 —— **只有名字，图形数据在 `IconSet`**。
 *
 * ★ 为什么是枚举而不是"字符串名字"：拼错 `'serach'` 会静默画不出东西（空 Path
 *   在 `paintContent()` 里直接 return），而枚举拼错是编译期/静态检查能抓的。
 * ★ 为什么不用图标字体：本仓的软光栅靠**预生成字模**（`DeterministicFontManager`），
 *   图标码点是私有区字符，字模生成脚本扫的是源码里的中文字面量 ⇒ 扫不到
 *   ⇒ 真机"零宽隐形"（不画 tofu、不报错、少一截）。矢量 Path 绕开整条链。
 * ★ 为什么不用位图：图标要在 12/16/20/24/32 多个尺寸上清晰，位图在 DPI 缩放下糊。
 *
 * @api
 */
enum IconName: string
{
    /** 三条横线（汉堡菜单） */
    case Menu = 'menu';

    /** 叉（关闭 / 清除） */
    case Close = 'close';

    /** 勾（确认 / 选中） */
    case Check = 'check';

    /** 左箭头（返回 / 上一页） */
    case ArrowLeft = 'arrow-left';

    /** 右箭头（进入 / 下一页） */
    case ArrowRight = 'arrow-right';

    /** 下折角（展开 / 更多） */
    case ChevronDown = 'chevron-down';

    /** 加号（新建 / 放大） */
    case Plus = 'plus';

    /** 减号（收起 / 缩小） */
    case Minus = 'minus';

    /** 放大镜（搜索）—— 唯一带曲线的图标 */
    case Search = 'search';

    /** 三个点（更多操作） */
    case More = 'more';
}
