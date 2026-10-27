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

    /** 上箭头（升序 / 上移） */
    case ArrowUp = 'arrow-up';

    /** 下箭头（降序 / 下移） */
    case ArrowDown = 'arrow-down';

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

    // ── 导航 / 分类用（2026-10-25 加：21 个页面若共用 10 个图标就失去区分度）──

    /** 2×2 方格（布局 / 网格） */
    case Grid = 'grid';

    /** 三条长短不一的横线（文本 / 富文本） */
    case TextLines = 'text-lines';

    /** 方框（画布 / 容器 / 宽高比） */
    case Square = 'square';

    /** 左侧圆点 + 右侧横线（列表） */
    case ListBullets = 'list-bullets';

    /** 铃（通知 / 反馈） */
    case Bell = 'bell';

    /** 三角（播放 / 动画） */
    case Play = 'play';

    /** 方框 + 山形（图片） */
    case Image = 'image';

    /** 两条轨道 + 两个把手（滑杆 / 调节） */
    case Sliders = 'sliders';

    /** 圆 + 感叹号（提示 / 信息） */
    case Info = 'info';

    /** 斜置的笔（自绘 / 编辑） */
    case Brush = 'brush';
}
