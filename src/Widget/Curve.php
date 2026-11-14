<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 缓动曲线 —— 把**线性进度**映射成**缓动进度**（定义域与值域都是 `0..1`）。
 *
 * ```php
 * $c = AnimationController::make(240.0)->curve(Curve::easeOut());
 * ```
 *
 * ★ **端点必须不动**：`map(0) === 0.0`、`map(1) === 1.0`。这条不是审美 ——
 *   动画跑完停在 `1.0` 时若曲线给了 `0.9997`，控件就永远差那 0.3% 到不了终点
 *   （现象是"动画结束后元素还偏一像素"，且**没有任何报错**）。
 *
 * ★ **为什么是 `int $kind` + `switch` 而不是每根曲线一个类**：tpc 会把只有一个
 *   实现的虚方法**去虚化**（§24.2 #31），而"曲线"天然是**纯函数表**，没有多态
 *   需求。同 `ToggleBase` 的裁决：行为走 `int` 分支，形态才用继承。
 *
 * ★ **只用二次多项式**，不用三次贝塞尔 / `pow()`：`pow()` 在不同 libm 下末位有
 *   差异，会破坏 §14.1 的"同一棵树两个后端像素一致"（与 `RenderSpinner` 禁
 *   `cos/sin` 同一条纪律）。二次曲线的观感已经够用。
 *
 * @api
 */
final class Curve
{
    /** @internal 线性：`map(t) = t`（默认曲线 —— 不给曲线时行为可预测） */
    public const int LINEAR = 0;

    /** @internal 慢起快收：`t²`（"东西开始动了"） */
    public const int EASE_IN = 1;

    /** @internal 快起慢收：`1-(1-t)²`（"东西停下来了" —— 交互反馈的默认手感） */
    public const int EASE_OUT = 2;

    /** @internal 两头慢中间快（"从 A 到 B"） */
    public const int EASE_IN_OUT = 3;

    private function __construct(private readonly int $kind)
    {
    }

    public static function linear(): self
    {
        return new self(self::LINEAR);
    }

    public static function easeIn(): self
    {
        return new self(self::EASE_IN);
    }

    public static function easeOut(): self
    {
        return new self(self::EASE_OUT);
    }

    public static function easeInOut(): self
    {
        return new self(self::EASE_IN_OUT);
    }

    /** @internal 调试与夹具用（`Curve` 是不可变值，比较 kind 即比较曲线） */
    public function kind(): int
    {
        return $this->kind;
    }

    /**
     * 线性进度 ⇒ 缓动进度。
     *
     * ★ 入参**夹取**而不报错（同 `Progress` 的裁决）：帧时间戳抖动可能给出
     *   `1.0000001`，为它 Fatal 不值得；曲线是纯函数，夹取是它的定义域边界。
     */
    public function map(float $t): float
    {
        if ($t <= 0.0) {
            return 0.0;
        }

        if ($t >= 1.0) {
            return 1.0;
        }

        $inv = 1.0 - $t;
        $kind = $this->kind;

        // ★★ **不用 `switch`**：本仓 `src/` 此前零处 `switch`，而 tpc 给
        //   `switch ($this->kind)` 生成的属性读**不加 `php::toInt()`** ⇒
        //   `php::Int tmp = this_.attr(...)` ⇒ **C2440**（Variant → Int），
        //   只有 T2 构建看得见（解释器 smoke 全绿）。按 `ToggleBase` 的既有
        //   写法用 `===` 比较链 —— 那是已经被 AOT 编过的形状。
        if ($kind === self::EASE_IN) {
            return $t * $t;
        }

        if ($kind === self::EASE_OUT) {
            return 1.0 - $inv * $inv;
        }

        if ($kind === self::EASE_IN_OUT) {
            if ($t < 0.5) {
                return 2.0 * $t * $t;
            }

            return 1.0 - 2.0 * $inv * $inv;
        }

        return $t;
    }
}
