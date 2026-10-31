<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * **一根轴**的滚动状态机：偏移 + 它的动画节律 + 它的滚动条淡出。
 *
 * ```text
 * scrollBy(Δ) ──▶ 夹下界 0 ──▶ 推给盒 ──▶ 回写盒夹过的值      ← 立即（thumb 拖动 / reveal）
 * glideBy(Δ)  ──▶ 目标 += Δ                                  ← 滚轮：摊成若干帧
 *   每帧 tick() ──▶ 本帧该到哪 ──▶ 仍交给 scrollBy(差值)
 * ```
 *
 * ★★ **为什么存在**（2026-10-31）：`ScrollViewState` / `LazyListState` / `TableState`
 *   是三个 `Scrollable` 实现，但"偏移怎么存、夹在哪、动画怎么收口、条什么时候亮"
 *   完全一样 —— 这三段原先各写了一遍。代价不是行数，是**同一条契约要改三处**：
 *   历史上 `b7257bf`（滚动不重建）与 `58305af`（滚轮平滑）两次提交都必须同时改
 *   三个 State，而漏掉一处**不报错**（漏"回写盒夹过的值" ⇒ 真机"拖着条画面不动"；
 *   漏"到边界收口" ⇒ 每帧白跑一次全量 paint，要多转一百多帧）。
 *
 * ★ **只收"每根轴都一样"的那部分**。三个 State 仍各自拥有：
 *   - 轴怎么判（`ScrollView` 的主轴 / `LazyList` 的朝向 / 表格的 `Axis` 参数）
 *   - 偏移改完之后还要做什么（`rememberAnchor()` / `markDirty()` / `commitIfUserScrolled()`）
 *   - 用哪个盒（`RenderScrollView` / `RenderTable`）
 *   ⇒ 那些是**策略**，用 `$push` / `$apply` 两个回调交回去。回调是**单一签名**
 *   （`float → float` / `float → void`），不做"一张表里混几种签名"（AOT 严格校验
 *   实参个数，混合签名只有走到那条分支才炸）。
 *
 * ★ **为什么是组合而不是基类**：`TableState` 需要**两根**轴，且两根共用**一份**
 *   `ScrollBarFade`（横条竖条同时淡出是对的 —— 它们提示的是同一件事）。
 *   基类表达不了"一个 State 有两根轴"，而"把 State 钉进构造参数"又会让同一套
 *   状态机在别的场景被手写第二遍。组合两个 `ScrollLane` + 注入同一个 fade 即可。
 *
 * ★ **偏移住这里，不住盒上**：盒是**每帧重造的**（`scrollOffset` 是 readonly
 *   构造参数）⇒ 住在盒上的进度每帧清零，动画永远走不完。见 `ScrollMotion`。
 *
 * ★ **`set()` 不夹取**：它只服务"播种 / 锚点还原 / build 期回夹"三种写入口，
 *   三者的值都由调用方算好（锚点还原的结果可能非零，而 build 期回夹的已经是
 *   盒夹过的值）。在这里再夹一次是**第二个真相** —— 而 `move()` 才是"用户增量"
 *   的入口，下界 0 只在那儿夹。
 *
 * @internal 只给 `Scrollable` 的三个实现用
 */
final class ScrollLane
{
    /** 当前偏移（逻辑像素，≥ 0；上界由盒在布局期夹） */
    private float $offset;

    /** 滚轮的动画节律（懒建：没被滚轮滚过的轴不建） */
    private ?ScrollMotion $motion = null;

    /**
     * 滚动条的显隐节律。
     *
     * ★ 可以注入：`TableState` 的两根轴传**同一个**实例（横竖同时淡出）。
     * ★ 不传则自建。`ScrollBarFade` 内部仍是懒的（`noteScroll()` 才建槽）⇒
     *   提前造对象不改任何行为：没滚过时 `opacity()` 恒 `1.0`、`tick()` 恒 `false`。
     */
    private ScrollBarFade $fade;

    public function __construct(float $start = 0.0, ?ScrollBarFade $fade = null)
    {
        $this->offset = $start;
        $this->fade = $fade ?? new ScrollBarFade();
    }

    /** 当前偏移（逻辑像素，≥ 0） */
    public function offset(): float
    {
        return $this->offset;
    }

    /** 滚动条的显隐节律（`build()` 取透明度用） */
    public function fade(): ScrollBarFade
    {
        return $this->fade;
    }

    /**
     * 直接设偏移（**不夹取**、不碰动画、不碰淡出）。
     *
     * 三个写入口：① `initState()` 播种初始偏移；② `didUpdateWidget()` 按锚点还原；
     * ③ `build()` 把盒夹过的值写回活字段。三处都不是"用户增量" ⇒ 不走 `move()`。
     */
    public function set(float $value): void
    {
        $this->offset = $value;
    }

    /**
     * 推一段 Δ：**夹下界 0** → 交给 `$push` 推给盒 → 回写**盒夹过的值**。
     *
     * ★ `$push` 是"策略交回"：盒不在（首帧之前 / 盒被换掉）时调用方传"原样返回"，
     *   于是 `build()` 会把字段递下去；盒在时传 `setScrollOffset()`。
     * ★★ **必须回写盒夹过的值**（不是 `$next`）：内容变短时 State 里那个"增量累加值"
     *   会长期大于上限，之后每笔增量都先消耗死区 —— 真机表现是"拖着条，画面不动"。
     * ★ 顺序是"先亮条、后推盒"：`noteScroll()` 只碰淡出状态，与推盒无交互，
     *   但反过来会在"这一帧先按旧空闲时长淡一点、再被滚动打回全亮"之间抖动。
     *
     * @param callable(float): float $push 目标值 → 实际生效值
     * @return bool 真的动了吗（`false` = 已在边界 / 被盒夹回原值，调用方**不要**标脏）
     */
    public function move(float $delta, callable $push): bool
    {
        $next = max(0.0, $this->offset + $delta);

        if ($next === $this->offset) {
            return false;   // 已在边界：不推、不重排（省一帧）
        }

        $this->fade->noteScroll();
        $this->offset = $push($next);

        return true;
    }

    /**
     * 滚轮：**起飞前对齐**，然后累加目标（细节见 `Scrollable::glideByAxis()`）。
     *
     * ★ 起飞前对齐：motion 不在跑时先 `snapTo($offset)` —— 这个 offset 可能刚被
     *   `move()` / `ensureVisible()` / 盒夹取改过，motion 里那个名义值已经过期；
     *   不对齐就会"从旧位置滑到新目标"，画面跳一下。
     * ★ **在跑时不对齐**：连续滚轮必须让目标累加（对齐会打断正在跑的动画）。
     * ★ 这里也要 `noteScroll()`：动画可能首帧还不推进（`AnimatedScalar` 首帧只记
     *   起点）⇒ 条要现在就亮，否则"滚了但条没反应"要等第二帧。
     */
    public function glide(float $delta): void
    {
        $motion = $this->motion();
        if (!$motion->isAnimating()) {
            $motion->snapTo($this->offset);
        }
        $motion->glideBy($delta);

        $this->fade->noteScroll();
    }

    /**
     * 推进这一帧的动画；返回"这一帧有动画吗"。
     *
     * ★ 位移**仍然交给 `$apply`**（State 自己的滚动入口）⇒ 夹取、换窗、锚点、
     *   提交编辑全部复用既有那一条路，动画不重写任何一条。
     * ★★ **到边界就地收口**：`$apply` 之后偏移没变 ⇒ `snapTo()`。不收口的话
     *   motion 会继续朝一个**到不了**的目标指数衰减：终会停，但要多转一百多帧
     *   （实测 81 帧 vs 190 帧），每帧都白跑一次 `tickFrame` + 一次全量 paint，
     *   滚动条也跟着多亮一百多帧。
     *
     * @param callable(float): void $apply 本帧的 Δ（**正** = 内容上移 / 左移）
     * @return bool 这一帧有动画在跑（调用方据此聚合进 `animating`）
     */
    public function tick(float $timestampMs, callable $apply): bool
    {
        $motion = $this->motion;
        if ($motion === null) {
            return false;   // 没被滚轮滚过 ⇒ 不建槽、不占帧
        }

        $next = $motion->tick($timestampMs);
        if ($next === null) {
            return false;
        }

        $before = $this->offset;
        $apply($next - $before);

        if ($this->offset === $before) {
            $motion->snapTo($before);
        }

        return true;
    }

    /** 滚动的动画节律（懒建：没被滚轮滚过的轴不建） */
    private function motion(): ScrollMotion
    {
        $motion = $this->motion;
        if ($motion === null) {
            $motion = new ScrollMotion($this->offset);
            $this->motion = $motion;
        }

        return $motion;
    }
}
