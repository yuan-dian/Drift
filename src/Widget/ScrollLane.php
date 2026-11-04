<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 一根轴的滚动状态机：偏移 + 动画节律 + 滚动条淡出。
 * ★ 偏移住这里不住盒上：盒每帧重造，住盒上进度每帧清零，动画永远走不完。
 * `set()` 不夹取：只服务播种 / 锚点还原 / build 期回夹，值由调用方算好；
 *   `move()` 才是用户增量入口，下界 0 只在那儿夹。
 * @internal 只给 `Scrollable` 的实现用（当前四个：全量 / 虚拟化 / 表格 / 多行输入）
 */
final class ScrollLane
{
    /** 当前偏移（逻辑像素，≥ 0；上界由盒在布局期夹） */
    private float $offset;

    /** 滚轮的动画节律（懒建：没被滚轮滚过的轴不建） */
    private ?ScrollMotion $motion = null;

    /**
     * 滚动条的显隐节律。
     * 可注入：`TableState` 的两根轴传**同一个**实例（横竖同时淡出）；不传则自建。
     * `ScrollBarFade` 内部仍是懒的（`noteScroll()` 才建槽）⇒ 提前造对象不改任何行为。
     */
    private ScrollBarFade $fade;

    /**
     * @param float $start 初始偏移
     * @param ScrollBarFade|null $fade 共享的淡出节律（`TableState` 两根轴传同一个）
     * @param State|null $owner 宿主 —— **只为一件事**：滚过之后叫醒帧时钟（见 `wake()`）
     */
    public function __construct(
        float $start = 0.0,
        ?ScrollBarFade $fade = null,
        private readonly ?State $owner = null,
    ) {
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
     * 三个写入口：① `initState()` 播种初始偏移；② `didUpdateWidget()` 按锚点还原；
     * ③ `build()` 把盒夹过的值写回活字段。三处都不是"用户增量" ⇒ 不走 `move()`。
     */
    public function set(float $value): void
    {
        $this->offset = $value;
    }

    /**
     * 推一段 Δ：夹下界 0 → 交给 `$push` 推给盒 → 回写**盒夹过的值**。
     * ★ 必须回写盒夹过的值（不是 `$next`）：内容变短时 State 的"增量累加值"
     *   会长期大于上限，之后每笔增量都先消耗死区 —— 真机"拖着条，画面不动"。
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
        $this->wake();

        return true;
    }

    /**
     * 滚轮：起飞前对齐，然后累加目标（细节见 `Scrollable::glideByAxis()`）。
     * 起飞前对齐：motion 不在跑时先 `snapTo($offset)` —— 这个 offset 可能刚被
     *   `move()` / `ensureVisible()` / 盒夹取改过，motion 名义值已过期，不对齐会
     *   从旧位置滑到新目标、画面跳一下。在跑时不对齐：连续滚轮要让目标累加。
     * 这里也要 `noteScroll()`：动画首帧可能不推进，条要现在就亮，否则要等第二帧。
     */
    public function glide(float $delta): void
    {
        $motion = $this->motion();
        if (!$motion->isAnimating()) {
            $motion->snapTo($this->offset);
        }
        $motion->glideBy($delta);

        $this->fade->noteScroll();
        $this->wake();
    }

    /**
     * 推进这一帧的动画；返回"这一帧有动画吗"。
     * 位移仍交给 `$apply`（State 自己的滚动入口）：夹取、换窗、锚点、提交全部复用
     * 既有那一条路，动画不重写任何一条。
     * **到边界就地收口**：`$apply` 之后偏移没变 ⇒ `snapTo()`；不收口 motion 会朝
     *   到不了的目标衰减，多转上百帧（每帧白跑一次 paint）。
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

    /**
     * 告诉宿主"我要帧" —— `move()` / `glide()` 真的动了之后调。
     * ★ 必须有：滚过后 `ScrollBarFade` 要在 900ms 后把条淡出，那段等待**本身要帧**。
     *   帧时钟只 tick 动效活跃表里的节点；`move()` 只 `noteScroll()`，且
     *   `fade->toward(1.0)` 在"本来就全亮"时同值早退 ⇒ 拖条后 `tickFrame()` 一次都不来，
     *   **条永远全亮**：滚轮会淡、拖条不会淡，用户能看见的不一致。
     * 用 `requestFrame()` 而非 `markDirty()`：滚动**不该重建**，`requestFrame()` 正是
     * "要帧但不要重建"的原语。
     */
    private function wake(): void
    {
        $this->owner?->requestFrame();
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
