<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 滚动的**动画节律**：把"一次大位移"摊成"若干帧小位移"。
 *
 * ```text
 * glideBy(+48) ──▶ 目标 += 48
 *   每帧 tick() ──▶ 本帧该到哪（绝对位置）──▶ State 用 scrollBy(差值) 施加
 * ```
 *
 * ★ 为什么独立成类（与 `ScrollBarFade` 同源的理由）：`ScrollViewState` /
 *   `LazyListState` / `TableState` 是**三份实现**（全量 / 虚拟化 / 表格），但
 *   "滚轮该滑多久、怎么收口"完全一样。写在 State 里就得抄三遍，而三份曲线
 *   一旦漂移，现象是"长列表滑得比短列表快一点"——没人会去查。
 *
 * ★ 为什么不住盒上：盒是**每帧重造的**（`scrollOffset` 是 readonly 构造参数）
 *   ⇒ 住在盒上的进度每帧清零，动画永远走不完。进度必须住在活过重建的那一层。
 *
 * ★★ **它只回答"这一帧该到哪"，不碰偏移、不碰盒**。真正改偏移的是 State 调
 *   `scrollBy()` —— 于是夹取（`maxScroll`）、换窗（`markDirty`）、锚点
 *   （`rememberAnchor`）、提交编辑（`commitIfUserScrolled`）全部复用既有那一条路，
 *   动画不必重写任何一条。**"动画 = 把一次大增量拆成多次小增量"** 是这里唯一的新语义。
 *
 * ★ **曲线与"收口帧必须交付"已下沉到 `AnimatedScalar`**：
 *   `AnimatedOpacity` 落地时它成了第三个同样的需求 ⇒ 三份收成一份。
 *   本类现在只剩"位移是**相对**的"这一条滚动特有的语义。
 *
 * ★ **不做 fling（惯性）**：`arch-v2/04` §6.4 当年的结论仍成立 —— 没有触摸输入，
 *   滚轮是离散事件（一格就是一格，没有速度概念），触控板的惯性由 Win32 自己折进
 *   连续的 `WM_MOUSEHWHEEL`。**没有消费者的物理不是特性。**
 *   将来真接触摸时，fling 只是"给同一个 `AnimatedValue` 一个非零初速 + 换衰减曲线"，
 *   本类不用改形状。
 *
 * @internal 只给 `Scrollable` 的实现用（当前四个：全量 / 虚拟化 / 表格 / 多行输入）
 */
final class ScrollMotion
{
    /**
     * ★ 曲线与"收口帧交付"住在 `AnimatedScalar`（三份实现共用一份，见那边 ★★）：
     *   本类**只加**滚动特有的语义 —— 位移是**相对**的（`glideBy(Δ)`），因为滚轮
     *   事件天然是"再走一格"，而不是"走到第 96 像素"。
     */
    private AnimatedScalar $value;

    public function __construct(float $start = 0.0)
    {
        $this->value = new AnimatedScalar($start);
    }

    /**
     * 再加一段位移（**相对**；连续滚轮因此自然累加目标）。
     *
     * ★ 不在这里夹取：上限要等布局后才知道（`maxScroll` 每帧都可能变），
     *   夹取留在 `scrollBy()` 那一条路上 —— 一处夹取，三份实现都受益。
     */
    public function glideBy(float $delta): void
    {
        $this->value->toward($this->value->target() + $delta);
    }

    /**
     * 就地跳到某个值（不产生过渡）。
     *
     * 两处用：① 起飞前对齐"State 里那个被 `scrollBy()` / `ensureVisible()` 挪过的
     * 真实偏移"；② 滚到边界滚不动了就收口（否则每帧空推一次）。
     */
    public function snapTo(float $value): void
    {
        $this->value->snapTo($value);
    }

    /** 还在跑吗（= 离目标还有距离） */
    public function isAnimating(): bool
    {
        return $this->value->isAnimating();
    }

    /**
     * 推进一帧。
     *
     * @return null|float 本帧应到的**绝对**偏移；`null` = 这一帧没有新位置（静止）
     */
    public function tick(float $timestampMs): ?float
    {
        return $this->value->tick($timestampMs);
    }
}
