<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\ScrollBarHost;
use Drift\Rendering\AnimatedValue;

/**
 * 滚动条的显隐节律：**刚滚过就全亮，停手一会儿淡下去**。
 *
 * ★ 为什么是独立小对象：`ScrollViewState` 与 `LazyListState` 是两份实现（一个
 *   全量、一个虚拟化），但"滚动条该多亮"完全一样。写在 State 里就得抄两遍，
 *   而两份淡出曲线一旦漂移，现象是"长列表的滚动条淡得慢一点"——没人会去查。
 *
 * ★ 为什么不住盒上（`RenderScrollView`）：盒是**每帧重造的**（`scrollOffset` 是
 *   readonly 构造参数）⇒ 住在盒上的进度每次滚动都被清零，淡出永远不会发生。
 *   进度必须住在**活过重建**的那一层，盒只是每帧把值读过去。
 *
 * ★ 淡出**不给 0**：完全消失之后用户不知道这块区域能滚。
 *
 * @internal 只给两个 `Scrollable` 实现用
 */
final class ScrollBarFade
{
    /** 停止滚动后多久开始淡下去（毫秒） */
    private const float IDLE_MS = 900.0;

    /** 空闲时的透明度 */
    private const float IDLE_OPACITY = 0.35;

    private ?AnimatedValue $fade = null;

    /** 距上次滚动的空闲时长（毫秒）；`-1` = 还没滚动过（不计时） */
    private float $idleMs = -1.0;

    /**
     * 记一次"刚滚过"。
     *
     * ★ 懒建槽、初值 **1.0**：第一次滚动不该先淡入一遍 —— 用户正在滚，
     *   滚动条本来就该在那儿。
     */
    public function noteScroll(): void
    {
        $fade = $this->fade;
        if ($fade === null) {
            $fade = new AnimatedValue(1.0);
            $this->fade = $fade;
        }

        $this->idleMs = 0.0;
        $fade->toward(1.0);
    }

    /** 当前透明度（`1.0` = 从没滚过 / 正在滚） */
    public function opacity(): float
    {
        $fade = $this->fade;

        return $fade === null ? 1.0 : $fade->value();
    }

    /**
     * 推进一帧，回答"**还要下一帧吗**"。
     *
     * ★ 空闲计时用 `lastDelta()` 而不是自己减时间戳：dt 的钳制（卡顿夹到 3 个
     *   半衰期）在 `AnimatedValue` 里，两处各算一遍迟早不一致。
     */
    public function tick(float $timestampMs): bool
    {
        $fade = $this->fade;
        if ($fade === null) {
            return false;
        }

        $busy = $fade->tick($timestampMs);

        if ($this->idleMs >= 0.0) {
            $this->idleMs += $fade->lastDelta();

            // ★★ "等 900ms"本身**也要帧**：滚动结束那一刻没有别的东西在跑动画，
            //   若这里返回 false，帧时钟当场落闸 ⇒ 之后一帧都不来 ⇒ `idleMs`
            //   永远停在 0 ⇒ 淡出**永不发生**（真机实测：滚动条停在全亮不动，
            //   而 smoke 里手推 tick 看不出问题）。所以计时期间必须自报在跑。
            //   代价：滚动后 900ms 内保持上膛（每帧全量 paint）—— 有界，可接受。
            if ($this->idleMs < self::IDLE_MS) {
                return true;
            }

            if ($fade->target() !== self::IDLE_OPACITY) {
                $fade->toward(self::IDLE_OPACITY);

                return true;   // 刚设了新目标：下一帧得有人推
            }
        }

        return $busy;
    }

    /**
     * 给 `State::tickFrame()` 的整段：推进 + 把当前值就地推给盒。
     *
     * ★ **两条路都要对**：本帧**会**重建时，新盒从 `build()` 拿到当帧的值；
     *   本帧**不**重建时（纯淡出帧），这里就地推给旧盒 —— 否则为了一个透明度
     *   变化每帧重建整棵滚动内容。
     * ★ 收在这里是为了两个 `Scrollable` 实现各只写一行（见类注释）。
     */
    public function tickFor(State $state, float $timestampMs): bool
    {
        $busy = $this->tick($timestampMs);

        $box = $state->element()?->effectiveBoxOf(ScrollBarHost::class);
        if ($box instanceof ScrollBarHost) {
            $box->setBarOpacity($this->opacity());
        }

        return $busy;
    }
}
