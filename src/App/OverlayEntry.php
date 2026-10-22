<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Widget\Element;
use Drift\Widget\Widget;

/**
 * 一个浮层条目（arch-v2 `01` §2.1）。
 *
 * ★ **普通 class，不是 Widget**：Widget 是"不可变描述"（挂载即 seal），
 *   而条目要每帧被写（`x`/`y` 由定位算法算、`alive` 由生命周期改）——
 *   放进 Widget 会立刻撞 `mutate()` 的封存守卫。
 *
 * ★ 方向常量用 `int` + `===` 链，不用 enum：tpc 对 `switch` 生成 C2440，
 *   本仓全域是 `int $kind` + `===`（`ToggleBase` / `Curve::map()` 同形）。
 */
final class OverlayEntry
{
    /** 锚下方（菜单 / 下拉） */
    public const BELOW = 0;

    /** 锚上方 */
    public const ABOVE = 1;

    /** 锚右侧（子菜单） */
    public const RIGHT = 2;

    /** 锚左侧 */
    public const LEFT = 3;

    /** 客户区居中（模态） */
    public const CENTER = 4;

    /** 最近一次命中的指针位置（右键菜单） */
    public const AT_POINTER = 5;

    private static int $nextId = 1;

    /** 进程内唯一，调试与 trace 用 */
    public int $id;

    /** 面板描述。★ 是 Widget 不是 Closure：StatefulWidget 自己订阅状态自己重建 */
    public Widget $panel;

    /** 锚节点；null ⇒ 无锚（模态 / Toast） */
    public ?Element $anchor = null;

    /** 首选方向，OverlayEntry::* 常量 */
    public int $prefer = self::BELOW;

    /** 是否拦截浮层外的指针（**与 scrim 是两个参数**） */
    public bool $barrier = true;

    /** 是否画遮罩（变暗） */
    public bool $scrim = false;

    /** 点浮层外是否关闭 */
    public bool $dismissOnTapOutside = true;

    /** Esc 是否关闭 */
    public bool $dismissOnEsc = true;

    /** Tab 是否被限制在浮层内 */
    public bool $focusTrap = false;

    /** 归属者：它 unmount 时本条目自动 pop（§7 的结构性防线） */
    public ?Element $owner = null;

    /** 挂载后的根 Element（框架写） */
    public ?Element $element = null;

    /** 面板左上角在客户区的落点（框架每帧写） */
    public float $x = 0.0;
    public float $y = 0.0;

    /** 关闭后焦点回到哪（push 时快照，§6） */
    public ?Element $restoreFocus = null;

    public function __construct(Widget $panel)
    {
        $this->id = self::$nextId++;
        $this->panel = $panel;
    }
}
