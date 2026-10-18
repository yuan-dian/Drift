<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 手势识别器（§9.1 手势消歧）—— **无状态判定函数 + arena 成员资格**，
 * 不是每控件一份定时器。
 *
 * ```php
 * GestureRecognizer::tap($onWin);         // 点击：不主动 claim，靠 arena 关闭默认胜
 * GestureRecognizer::drag(6.0, $onWin);   // 拖动：位移过阈值 ⇒ 主动 claim
 * ```
 *
 * ★ `predicate` 只回答"当前位移下我是否要**主动**抢占"：Drag 用过阈值判定，
 *   Tap 恒 `false`（它不抢，靠 `up` 时 arena 的默认胜出）。**胜负时机与让位**
 *   归 `GestureArena`，本类只提供"判定 + 胜利回调"两件事。
 * ★ 住 `Drift\Input`（§4.3：只依赖闭包与标量，不碰 `Widget` / `App`）。
 *
 * @internal 实现细节；路由入口见 §9.1，消费方是 `Application`。
 */
final class GestureRecognizer
{
    private function __construct(
        private string $kind,
        private \Closure $predicate,
        private \Closure $onWin,
    ) {
    }

    /** 点击：`up` 时若无人 claim ⇒ arena 默认让它胜（激活类手势走这条） */
    public static function tap(\Closure $onWin): self
    {
        return new self('tap', static fn (float $x, float $y, float $dx, float $dy): bool => false, $onWin);
    }

    /**
     * 拖动：位移平方超过阈值平方 ⇒ 主动 claim（`tap` 因此让位）。
     *
     * @param float $thresholdPx 阈值（**逻辑**像素，平方比较免开方）
     */
    public static function drag(float $thresholdPx, \Closure $onWin): self
    {
        $sq = $thresholdPx * $thresholdPx;

        return new self(
            'drag',
            static fn (float $x, float $y, float $dx, float $dy): bool => $dx * $dx + $dy * $dy > $sq,
            $onWin,
        );
    }

    public function kind(): string
    {
        return $this->kind;
    }

    /** @internal arena 调：当前位移下是否主动抢占 */
    public function claims(float $x, float $y, float $dx, float $dy): bool
    {
        return ($this->predicate)($x, $y, $dx, $dy);
    }

    /** @internal arena 调：胜出（带回触发时坐标，供 begin / 激活使用） */
    public function win(float $x, float $y): void
    {
        ($this->onWin)($x, $y);
    }
}
