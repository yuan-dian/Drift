<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\Rect;
use Drift\Input\CursorShape;
use Drift\Rendering\AnimatedValue;
use Drift\Rendering\Canvas;

/**
 * 渲染树的节点（§3.2 三树的第三棵）。
 *
 * ★ **布局是纯函数**（§14.1）：输入只有（子树、约束、主题、注入的度量），
 *   不读 OS、不读环境时钟、不读随机源；**位置无关** —— 从不读自身偏移来算布局，
 *   偏移是父定位子的结果（C1）。
 *
 * ★ Render 层**没有脏边界**（§13.3）：`relayout()` 永远全树。想要"只重排脏的
 *   那一块"，要等 M5 与脏矩形成对做 —— 单独做任一个，瓶颈只是搬到对面。
 *
 * ★★ **但"整帧画不画"是一条独立的、更粗的开关**（2026-10-30）：脏**矩形**没做，
 *   不代表"什么都没变就不用 blit 整块 framebuffer"。`$paintEpoch` 管的就是这条
 *   粗开关 —— 它是**全有或全无**（整帧重绘 / 整帧跳过），不改变"画的时候永远
 *   全量画"这条纪律。两者不冲突：脏矩形是"同一帧内只重绘一块"，`$paintEpoch`
 *   是"这一帧根本不开始"。
 *
 * @api
 */
abstract class RenderObject
{
    protected ?RenderObject $parent = null;

    /** @internal null = 本节点未声明光标形状（命中链会向上找更近的声明者，§9.1） */
    protected ?CursorShape $cursor = null;

    /**
     * 标"**像素变了**"的唯一入口（本层与子层的就地写点都调它）。
     *
     * ★★ 为什么不直接调 `markNeedsPaint()`（2026-11-01，真机 trace 抓到）：
     *   那是"让帧跑"，而**这一层要不要重录**是另一件事。`RenderBox` 覆写本方法
     *   去失效**最近的重绘边界**（`LayerCache` 按 `layoutEpoch` + 尺寸命中）；
     *   只标帧不失效边界时，命中缓存的边界**原样重放旧命令** ⇒ 帧跑了、
     *   `tickAnimations()` 也把进度从 0 推到 1（43 帧）、`paintOwnChrome()`
     *   一次都没被调到。现象就是"鼠标移上去颜色一丝不变"，而所有状态都是对的。
     *
     * ★ 判据是"**录进命令流里的东西**变了吗"：hover 叠色、滚动条 thumb 的 alpha
     *   都属于这一类；合成槽（alpha / 平移）不在缓存里，走 `setCompose()` 自己
     *   的 `invalidateEnclosingLayer()`。
     */
    protected function markPaintChanged(): void
    {
        self::markNeedsPaint();
    }

    /**
     * 悬停态与它的过渡进度 —— **不属于描述**的那部分状态（`08` §4.1）。
     *
     * ★ 状态由 `FocusManager::hoverTo()` 推（`App` 层只认 `acceptsHover()` 能力方法，
     *   不认具体盒类 —— I2 闸门）；怎么用是盒自己的事（`RenderButton` 叠一层状态色）。
     * ★ **两处都要标脏**（2026-11-01 修正 10-30 的判断）：这里标的是"目标变了、
     *   下一帧必须画"，`tickAnimations()` 标的是"斜坡推进了"。只标后者不够 ——
     *   那一刻帧时钟还没上膛（脏信号正是它上膛的依据）⇒ 谁也不会去 tick。
     * ★★ **换盒时要移交**（2026-11-01）：盒在每次重建时被换新（§13.2）⇒ 住盒字段
     *   上的状态会被任何一次 `setState()` 清零。那个坑的真机症状是"鼠标移上去
     *   不变色"，复现见 `tests/Feature/App/HoverPixelTest::testHoverSurvivesRebuild`。
     *   移交在 `inheritRuntimeState()`，**新增瞬态必须在那里补一行**（注释里已标）。
     */
    private bool $hovered = false;

    /**
     * 悬停态的**过渡进度**；`null` = 这个盒没有声明要用过渡。
     *
     * ★ 懒创建（第一次 `setHovered()` 才建）：本仓 95% 的盒对悬停无感，给每个
     *   节点常驻一个驱动器等于给全树加一份无用的分配。
     * ★ 起手值取**切换前**的状态 ⇒ 第一次 hover 也是从 0 淡上去，而不是
     *   "头一次直接跳到 1、第二次才有过渡"（那是最容易漏的一处不对称）。
     */
    private ?AnimatedValue $hoverAnim = null;

    /**
     * 接收旧盒的瞬态（盒换代时由 `Element::syncBox()` 调）。
     *
     * ★ 只允许**同类型**盒之间移交（调用方保证）：换了盒类，字段语义未必还成立
     *   （`RenderButton` 的悬停进度搬到 `RenderScrollView` 上无意义）。
     * ★★ **新增瞬态字段必须在这里补一行**。没补的后果是静默的（重建后状态归零，
     *   画面不更新、不报错）。`RenderScrollView::$scrollOffset` / `$barOpacity`
     *   不在此列 —— 它们由 State 当权威、重建时经构造参数递进来（"盒只是投影"）。
     *   悬停没有 State 持有者（`FocusManager` 握的是盒对象本身）⇒ 只能跨代保存。
     * ★ 不另建一个"瞬态槽"对象：2026-11-01 试过（`RenderRuntime`），那个新类在
     *   AOT 里没被翻译出 `.cc` ⇒ exe 启动即 `0xC0000005`（见 `PITFALLS.md`）。
     *
     * @internal
     */
    public function inheritRuntimeState(RenderObject $old): void
    {
        $this->hovered = $old->hovered;
        $this->hoverAnim = $old->hoverAnim;
    }

    /**
     * 渲染树**需要重绘**的绝对序号（跨整棵树，含浮层那棵第二根树）。
     *
     * ★★ **存在理由（2026-10-30，N1 泵帧分离）**：`Frame` 的 `settle` 与 `layout`
     *   两段早就有跳过判据，**只有 `paint` + `present` 无条件跑** —— 于是一个
     *   只有 IO watcher、没有任何画面变化的空转窗口，每秒仍在做 60 次整帧
     *   重绘 + 493MB/s 的 blit（[`EVENT-LOOP-2026-10-30.md`](../docs/EVENT-LOOP-2026-10-30.md) §2.1）。
     *   给 paint 加门控的前提是：**"画面变了"这件事必须有人说得出口**。
     *
     * ★★ 为什么不是"加在 `State::markDirty()` 上"（最初的设计）：`markDirty` 覆盖的
     *   只是**重建**那条路。全仓有一整族通道**刻意**绕过重建：
     *
     *   | 通道 | 改的是什么 | 为什么不重建 |
     *   |---|---|---|
     *   | `setCompose()` | 合成 alpha / 平移 | 动画每帧写，重建太贵（`05` §10 #9） |
     *   | `setScrollOffset()` | 子盒 offset | 重排整棵树 3.65ms（`RenderScrollView` 2026-10-27） |
     *   | `setBarOpacity()` | 滚动条淡出 | 同上 |
     *   | `tickAnimations()` | hover 过渡进度 | 无 State，动画住盒上 |
     *
     *   这四条**全部**依赖"paint 无条件跑"才正确（代码里都写着这句话）。把门控
     *   建在 `markDirty` 上 ⇒ 四条同时静默失效（滚动不动、动画静止、滚动条不淡出）。
     *   ⇒ 序号必须住**渲染树**，写点必须落在**盒自己的 setter** 上：谁改了像素
     *   相关字段，谁就在同一行标脏，不依赖"上游记得调"。
     *
     * ★ 与 `RenderBox::$layoutEpoch`（"渲染树有东西变了，必须重排"）同构：
     *   两个序号、两个含义、互不代偿。偏移只标 paint（不需要重排），换盒只标
     *   layout（重排完自然会画）—— 混用一个会让"只滚动"退化成"整树重排"。
     *
     * ★★ **多窗口下的已知代价**（诚实记账）：序号是**进程级**的，两个窗口共享
     *   一个计数器 ⇒ A 窗滚动会让空闲的 B 窗也重画一帧。这是**保守的正确**
     *   （多画，不少画），与现有的 `markedCount` / `boxEpoch` / `structureVersion`
     *   完全同构 —— 那三个也都在全局。要让"脏"按窗口分片，得先有窗口身份
     *   （`MULTI-WINDOW-2026-10-30.md` 的 M0/M1）⇒ 那是 M2 的债，不是这里的。
     */
    private static int $paintEpoch = 0;

    /**
     * 声明"**画面变了**，下一帧必须真的画"。
     *
     * ★ 只给本层的**就地写点**调：`setOffset()` / `setBase()` / `applyCompose()` /
     *   `setBarOpacity()` / `tickAnimations()`。业务代码不该碰它 —— 业务改状态走
     *   `setState()`，那条路有自己的信号（`State::markedCount()`）。
     * ★ 值没变**不要**调：静态自增很便宜，但一次多余的自增就是一次多余的整帧
     *   重绘。所有调用点都先做相等比较（`setCompose` 的 `applyCompose` 本来就有）。
     * ★ 用 `self::` 不用 `static::`：静态字段不参与继承分片，晚静态绑定会解析到
     *   子类（子类没有这个字段）⇒ AOT 下取到 `null` 再自增就是 1，序号从此失效。
     *
     * @internal
     */
    public static function markNeedsPaint(): void
    {
        self::$paintEpoch++;
    }

    /** @internal `Frame` 的"跳过 paint"判据 */
    public static function paintEpoch(): int
    {
        return self::$paintEpoch;
    }

    public function parent(): ?RenderObject
    {
        return $this->parent;
    }

    /**
     * 本节点**自己**声明的光标形状（`null` = 没声明，§9.1）。
     *
     * ★ 它**不是**最终生效的形状 —— 生效值由命中链从命中的盒**向上**找最近的
     *   声明者（`FocusManager::cursorOf()`）。放在 `RenderObject` 而不是
     *   `RenderBox` 上：§9.1 原文就是"`RenderObject` 声明 `cursor`"。
     *
     * @api
     */
    public function cursor(): ?CursorShape
    {
        return $this->cursor;
    }

    /**
     * @internal Element 造盒时把 Widget 的声明落上来（唯一写点：`Element::syncBox()`）
     */
    public function setCursor(?CursorShape $shape): void
    {
        $this->cursor = $shape;
    }

    /**
     * 这个**位置**要什么光标（`null` = 不表态，回落到 `cursor()` 的父链声明）。
     *
     * ★ 与 `cursor()` 的分工：`cursor()` 是"整个控件一个形状"，本方法是"同一个
     *   控件里按位置变"（富文本的链接只在几个字上是手型）。
     * ★ 坐标是**本盒的局部坐标**（与 `hitTest()` 同口径）—— 调用方负责减
     *   `absoluteOrigin()`，盒不自己沿父链换算（那是重复实现）。
     * ★ 默认 `null`：绝大多数盒没有按位置变的需求，声明式 `cursor()` 够用；
     *   覆写的盒必须**快速返回**（每次 move 都会问一次）。
     *
     * @api
     */
    public function cursorAt(float $x, float $y): ?CursorShape
    {
        return null;
    }

    /**
     * 本盒作为**浮层面板**时，面板自身的矩形（盒坐标系；`null` = 本盒不是面板）。
     *
     * ★ 存在的理由：`App` 层有两处要问"这是不是面板 / 面板多大"，以前靠
     *   `instanceof RenderPopupPanel` —— 框架核心认识具体盒类，与 `Activatable`
     *   同一条纪律（§4.3 依赖方向：`App` 不该认识 `Layout` 的具体类）。
     *   收成能力方法后，`hitsModalPanel()` 问 `panelRect() !== null`，
     *   `OverlayLayout` 问矩形本身，两处都不再需要 `use RenderPopupPanel`。
     *
     * ★ **为什么返回矩形而不是布尔**：`OverlayLayout` 要的是"含投影留白内缩
     *   `elevation` 之后的面板矩形"，布尔表达不了。一个方法同时满足两种问法 ——
     *   布尔用 `!== null`，几何用返回值。两个方法（`isPanel()` + `panelRect()`）
     *   会逼出"两者不一致"的可能。
     *
     * ★ 默认 `null`：绝大多数盒不是面板，不表态。
     *
     * @api
     */
    public function panelRect(): ?Rect
    {
        return null;
    }

    /**
     * 这个盒接不接受"悬停态"（默认 `false`）。
     *
     * ★ 为什么是能力方法而不是 `instanceof RenderButton`：`FocusManager` 在 `App` 层，
     *   按 I2 闸门不得认识 `Layout` 的具体类。它沿**盒父链**问这个方法，第一个回答
     *   `true` 的就是"该变色的那个盒" —— 与 `panelRect()`（4j-c）同一条纪律。
     * ★ 默认 `false`：布局容器（`Column`/`Row`/`Padding`）不接，否则整列都会变色。
     * ★ 覆写者通常写成"声明了状态色才接"（见 `RenderButton`），这样没声明状态的盒
     *   连一次 `setHovered` 都不会收到。
     *
     * @api
     */
    public function acceptsHover(): bool
    {
        return false;
    }

    /** @internal `FocusManager::hoverTo()` 推状态（唯一写点） */
    public function setHovered(bool $hovered): void
    {
        // ★ 同值早退：`WM_MOUSEMOVE` 每帧都在推，不早退就会把已收口的过渡
        //   反复打回"在跑"⇒ 帧时钟永不落闸（空闲空转）。
        if ($this->hovered === $hovered) {
            return;
        }

        // 槽按**切换前**的状态起手 ⇒ 第一次 hover 也是从 0 淡上去。
        $anim = $this->hoverAnim;
        if ($anim === null) {
            $anim = new AnimatedValue($this->hovered ? 1.0 : 0.0);
            $this->hoverAnim = $anim;
        }

        $this->hovered = $hovered;
        $anim->toward($hovered ? 1.0 : 0.0);

        // ★★ **必须标脏**（2026-11-01）：`paintEpoch` 是**唯一**能让帧时钟看见
        //   "像素变了"的信号（`Application::needsFrameNow()` 读它）。此前这里
        //   靠"`tickAnimations()` 会标"—— 但 `tickAnimations()` **只在帧里跑**，
        //   而帧时钟此刻还没上膛 ⇒ 死锁：没有脏 ⇒ 不跑帧 ⇒ 不 tick ⇒ 永不脏。
        //   真机后果：悬停过渡**一次都推进不了**（`tick` 从未被调用），滚动条
        //   淡出同理（`tools/probe-motion.py` 连读 5 条红才抓到）。
        // ★★ 走 `markPaintChanged()` 而不是 `markNeedsPaint()`（同日第二次修正）：
        //   前者还负责让**重绘边界丢掉缓存**。只标帧时，`LayerCache` 按
        //   `layoutEpoch` 命中 ⇒ 边界重放旧命令 ⇒ 帧跑了、进度推到 1、
        //   `paintOwnChrome()` 一次都没被调到（真机 trace：43 个 tick 帧里
        //   paint 记录中从来没有这个盒的 id）。
        $this->markPaintChanged();
    }

    /**
     * 本盒当前是否悬停（给覆写了 `paintContent()` 的盒读）。
     *
     * ★ 只有 `acceptsHover()` 返回 `true` 的盒会收到 `true` —— 其余恒 `false`。
     */
    public function hovered(): bool
    {
        return $this->hovered;
    }

    /**
     * 悬停**过渡进度**（`0..1`）—— 与 `hovered()` 的差别只在这条斜坡上。
     *
     * ★ 没起过过渡（从未被 hover 过 / 不需要过渡）⇒ 直接给 0 或 1，与旧
     *   `hovered()` 的第二元完全一致 ⇒ 调用方换成它不会有行为突变。
     */
    public function hoverProgress(): float
    {
        $anim = $this->hoverAnim;
        if ($anim === null) {
            return $this->hovered ? 1.0 : 0.0;
        }

        return $anim->value();
    }

    /**
     * 推进本节点自己的动画，回答"**还要下一帧吗**"（`Application::tickSubtree` 调）。
     *
     * ★ 没有槽 ⇒ `false`，一次判空就返回：这是每帧每节点的一次方法调用，
     *   别在里面做别的。
     */
    public function tickAnimations(float $timestampMs): bool
    {
        $anim = $this->hoverAnim;
        if ($anim === null) {
            return false;
        }

        $before = $anim->value();
        $more = $anim->tick($timestampMs);

        // ★★ **必须标脏**（2026-10-30，N1）：hover 过渡住盒上、没有 `State` ⇒
        //   推进时不会 `setState()`。此前无害，因为 paint 无条件跑；加了门控之后，
        //   不标就是"鼠标移上去、颜色不动"（而且 `wantsFrame()` 还是 true，
        //   帧照跑、就是不画 —— 最难查的那种静默）。
        // ★ 判"**值变了**"而不是"`tick()` 返回 true"：`tick()` 在收口的那一帧返回
        //   `false`，而那一帧的值恰恰是最后一步、必须画出来。拿返回值当判据会把
        //   终点值丢掉（画面停在 0.93 而不是 1.0）。
        if ($anim->value() !== $before) {
            $this->markPaintChanged();
        }

        return $more;
    }

    /**
     * @internal 挂载子节点：把 subordinate 的父指到本节点。
     *
     * Element 建树时用；做成方法而不是公开 `$parent =`，是为了让"改父"有唯一落点。
     */
    public function adoptChild(RenderObject $child): void
    {
        $child->parent = $this;
    }

    /**
     * 遍历子渲染对象 —— 布局与绘制都走这个入口，**不直接摸具体字段**。
     *
     * 于是"单子盒"与"多子盒"对父层是同一个接口（§3.3 的 flush 只认这里）。
     */
    abstract public function visitChildren(callable $visitor): void;

    /** 两阶段之二（§13.1）：父级已 translate 到本盒原点 */
    abstract public function paint(Canvas $canvas): void;
}
