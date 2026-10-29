<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Rendering\AnimatedValue;

/**
 * 带**收口交付**的标量动画 —— `AnimatedValue` 之上只多一件事。
 *
 * ```text
 * toward(0.3) ──▶ 目标改成 0.3
 *   每帧 tick() ──▶ null = 这一帧没变化；float = 本帧该画的值
 * ```
 *
 * ★ 为什么存在（三份实现收成一份）：`ScrollMotion`（滚轮平滑）、`ScrollBarFade`
 *   （滚动条淡出）、`AnimatedOpacity`（隐式动画）都要"指数趋近 + 值变了才交付"。
 *   前两个各写了一遍，第三个再抄一遍就是三份 —— 而三份曲线一旦漂移，现象是
 *   "这个控件的动画比那个慢一点"，**没有任何报错**，也没人会去查。
 *
 * ★★ **收口帧必须交付**：`AnimatedValue::tick()` 在收敛时会把值**精确**落到
 *   `target`，但那一帧返回 `false`（"不用下一帧了"）。若这里跟着返回 `null`，
 *   最后一小段位移就**永远不会被施加** ⇒ 停在离目标 0.01 处。对 alpha 无感
 *   （`EPSILON` 就是按"1% 不可见"定的），对**位置**是硬伤：测试断 `=== 48.0`
 *   直接红。所以用 `$lastApplied` 记"上次交付的值"，只要值变了就再交一次 ——
 *   收口帧因此也交付。
 *
 * ★ 与 `AnimationController` 的分工是**语义**不是快慢：
 *   - `AnimatedScalar` = **追目标**（指数趋近，没有"总时长"概念）。隐式动画要的
 *     就是这个：用户随时可能再改目标，曲线必须能随时改向。
 *   - `AnimationController` = **放一段定时动画**（0..1 + `Curve` + 可反向 + 可
 *     `onCompleted`）。显式场景（转场、多段时序）用它。
 *   两套曲线同时存在是有意的：把它们合成一套，就得给"追目标"编一个假时长。
 *
 * @internal 只给 `ScrollMotion` / `AnimatedOpacity` 这类节律对象用
 */
final class AnimatedScalar
{
    private AnimatedValue $value;

    /** 上次交出去的值（判"这一帧的值变了没有"，见类注释 ★★） */
    private float $lastApplied;

    public function __construct(float $start = 0.0)
    {
        $this->value = new AnimatedValue($start);
        $this->lastApplied = $start;
    }

    /** 改目标（从**当前值**出发趋近；中途改目标不跳变） */
    public function toward(float $target): void
    {
        $this->value->toward($target);
    }

    /** 就地跳到某个值（不产生过渡）；同时把"已交付"对齐，下一帧不会重复交 */
    public function snapTo(float $value): void
    {
        $this->value->snapTo($value);
        $this->lastApplied = $value;
    }

    public function value(): float
    {
        return $this->value->value();
    }

    public function target(): float
    {
        return $this->value->target();
    }

    /** 还在跑吗（= 离目标还有距离） */
    public function isAnimating(): bool
    {
        return !$this->value->isSettled();
    }

    /**
     * 推进一帧。
     *
     * @return null|float 本帧应画的值；`null` = 这一帧没有新值（静止）
     */
    public function tick(float $timestampMs): ?float
    {
        $this->value->tick($timestampMs);

        $now = $this->value->value();
        if ($now === $this->lastApplied) {
            return null;
        }

        $this->lastApplied = $now;

        return $now;
    }
}
