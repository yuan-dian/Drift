<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Widget\Element;
use Drift\Widget\Modal;
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

    /**
     * 锚下方、**水平居中于锚点**（W8-2 Tooltip，2026-10-23）。
     *
     * ★ 为什么不复用 `BELOW` + 应用侧自己算偏移：落位算法在 `originFor()` 里，
     *   应用算不了（拿不到夹紧后的 `maxW`）。方向是**语义**，不是坐标。
     */
    public const BELOW_CENTER = 6;

    /** 锚上方、水平居中于锚点（Tooltip 首选） */
    public const ABOVE_CENTER = 7;

    /**
     * 客户区底部居中（Snackbar，2026-10-23）。
     *
     * ★ 与 `CENTER` 的区别：`CENTER` 是**盖满**客户区（模态靠子层自居中），
     *   本方向是**自尺寸 + 贴底**（一条横条）。两者在 `placeEntry()` 里走不同分支
     *   （`CENTER` 提前返回，本方向走常规的量 + 落位 + 夹取）。
     * ★ 贴底留白走 `$inset`（不是面板 padding）：padding 是内边距，改不了"离底多远"。
     */
    public const BOTTOM_CENTER = 8;

    private static int $nextId = 1;

    /** 进程内唯一，调试与 trace 用 */
    public int $id;

    /** 面板描述。★ 是 Widget 不是 Closure：StatefulWidget 自己订阅状态自己重建 */
    public Widget $panel;

    /**
     * 若本条目是**模态**，这里是那个 `Modal`（`null` = 不是模态）。
     *
     * ★ 为什么单独记一笔而不是事后 `$panel instanceof Modal` 反查：**"是不是模态"
     *   是入栈时的事实**（`showModal()` 决定的），不该靠类型反推 —— 将来
     *   `Sheet` / `Dialog` 也可能以模态入栈，那时反查就得改判据，而记账不用。
     *   同 `$scrim` / `$barrier` / `$focusTrap` 的口径：入栈方声明意图，条目只是记录。
     *
     * ★ 它同时让 `App` 层不必 `instanceof` 具体控件类（S1 第 10 步 4j-c）——
     *   但注意 `Modal` 这个**类型**仍在签名里（`OverlayManager::modal(): ?Modal`），
     *   那是返回值契约，不是"认识实现"。
     */
    public ?Modal $modal = null;

    /** 锚节点；null ⇒ 无锚（模态 / Toast） */
    public ?Element $anchor = null;

    /**
     * 点锚（客户区坐标）：非 null ⇒ 落位以这个**点**为准，不看 `$anchor` 的盒。
     *
     * ★ 右键菜单要的是"弹在光标处"（Windows / WPF `Placement=MousePoint` / Qt
     *   `exec(globalPos)`），不是"弹在那一行的盒下"。`AT_POINTER` 常量早就有，
     *   但以前 `anchorRect()` 只认盒 ⇒ 它退化成 `BELOW`、对大控件会弹到边角。
     * ★ `$anchor` 仍要留着：浮层的 owner 回收 / 锚点重建检测都靠它，点只影响几何。
     */
    public ?float $atX = null;

    public ?float $atY = null;

    /** 首选方向，OverlayEntry::* 常量 */
    public int $prefer = self::BELOW;

    /**
     * 面板宽度上限（逻辑像素；`INF` = 不额外限宽，W8-2 Tooltip）。
     *
     * ★ 为什么要这个字段：`placeEntry()` 给非居中层的约束是**无界**的，而
     *   `RenderPopupPanel` 只在"约束有界"时才夹宽 ⇒ 一段很长的 tooltip 文本
     *   会排出超过客户区的宽，随后 ⑤ 的夹紧**只挪 x 不改宽** ⇒ 右边溢出。
     *   限宽是**内容语义**（一行提示不该横穿屏幕），不是落位算法能猜的。
     */
    public float $maxWidth = \INF;

    /**
     * 贴边留白（逻辑像素；只有 `BOTTOM_CENTER` 用它，2026-10-23）。
     *
     * ★ 为什么不是面板的 `padding`：`padding` 是**内边距**（内容离边框多远），
     *   改不了"面板离客户区底边多远"。落位算法只知道 `maxH` 和面板高，
     *   所以"离底多远"必须由条目告诉它。
     */
    public float $inset = 0.0;

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

    /**
     * 上一次见过的**锚点 Widget**（W8-2，2026-10-24；身份比较 = "锚点重建过吗"）。
     *
     * ★ 为什么不存"锚点有没有脏"的标志：`Widget` 不可变 ⇒ 那个 `State` 一重建就
     *   造出一个**新实例**，`===` 天然是"重建过"的判据，且 O(1)、不用任何记账。
     *   （比 `State::$boxEpoch` 那种全局计数器更精确：那个是"全仓有没有换过盒"。）
     * ★ 用途只有一个：**锚点没重建 ⇒ 跳过签名求值**。签名函数要拼选项表，
     *   每帧都算一遍是白烧 CPU（30 项还好，5000 项远程列表就是每帧 5000 次拼接）。
     */
    public ?Widget $seenAnchor = null;

    /**
     * 上一次的面板内容签名（见 `Widget::overlaySignature()`）。
     *
     * ★ 与 `$seenAnchor` 配套：`seenAnchor` 管"要不要算"，本字段管"算出来变没变"。
     *   两道闸分开是刻意的 —— 合并成一个"变了没"的布尔会把"锚点重建但内容没变"
     *   和"锚点没重建"混成一件事，而前者需要重算、后者连算都不用算。
     */
    public string $lastSignature = '';

    /**
     * 本层是否**参与指针命中测试**（W8-2 Tooltip，2026-10-23）。
     *
     * ★ `false` = 指针完全看不见这一层，事件穿透到下面的层/主树 —— 对应 Win32 的
     *   `WS_EX_TRANSPARENT`。**Tooltip 必须用它**，否则会出现死循环：
     *   tooltip 面板一出现在指针附近 ⇒ `dispatchPointer()` 的浮层段命中它 ⇒
     *   `hoverTo(面板里的元素)` ⇒ 锚点悬停丢失 ⇒ 隐藏 ⇒ 指针又命中锚点 ⇒ 再显示。
     * ★ 与 `barrier` **正交**：`barrier` 管"层外的按下拦不拦"，本字段管"层内的命中
     *   算不算"。`barrier=false` 只解决"没命中时穿透"，解决不了"命中了就不放"。
     */
    public bool $hitTest = true;

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
