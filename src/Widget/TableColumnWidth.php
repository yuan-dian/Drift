<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;

/**
 * 一列的**宽度策略**（`Table` 的第一层宽度来源）。
 *
 * ```php
 * TableColumn::make('name', '姓名')->width(TableColumnWidth::flex(2.0))
 * TableColumn::make('id',   'ID'  )->width(TableColumnWidth::fixed(72.0))
 * ```
 *
 * ## 三层宽度的第一层（另外两层不在这里）
 *
 * | 层 | 谁给 | 在哪 |
 * |---|---|---|
 * | ① 策略初值 | **本类** | `TableColumn::width()` |
 * | ② 每列上下夹 | `TableColumn::minWidth()` / `maxWidth()` | `TableColumn::clampWidth()` |
 * | ③ 用户拖出来的实际值 | 阶段 4 的 `TableState::$widthOverrides` | `TableState` |
 *
 * 顺序是 ①②③（③ 最优先，但仍被 ② 夹）。**分三层不是形式**：策略是"设计意图"，
 * 夹是"这列再挤也不能小于 80"，拖拽是"用户此刻的要求" —— 三者来源不同，
 * 合成一处会让"改窗口后拖过的列变回策略值"这类问题无解。
 *
 * ## 为什么是值对象而不是 enum
 *
 * `enum` 装不下参数（`fixed(72)` 与 `fixed(120)` 是两个值），而 `FIXED+float`
 * 这种"枚举 + 参数"的写法每次取值都要再判一次。这里用私有构造 + 静态工厂，
 * 与 `WindowMaterial` 同一形态。
 *
 * ★ **`intrinsic()` 阶段 1 不提供**（2026-10-26 裁决）：它要"先造 cell 再量
 *   max-content 再重造 cell"，是两遍构造；而虚拟化下只能量可见行 ⇒ 滚动时
 *   列宽会**漂**。它的价值（省配置）小于它引入的不确定性，且今天没有真实消费者
 *   （`UsersPage` 用 `fixed` + `flex` 已足够）。形态已定 ⇒ 将来加它只是多一个
 *   静态工厂 + `TableState::resolveWidths()` 里多一个分支，**不改任何结构**。
 *
 * @api
 */
final class TableColumnWidth
{
    private const int FIXED = 0;
    private const int FLEX = 1;

    private function __construct(
        private readonly int $kind,
        private readonly float $amount,
    ) {
    }

    /** 固定像素宽（小于内容时内容被裁，大于内容时留白） */
    public static function fixed(float $px): self
    {
        if ($px <= 0.0) {
            Errors::report(sprintf('TableColumnWidth::fixed() 必须是正数，收到 %s', (string) $px));
        }

        return new self(self::FIXED, $px);
    }

    /**
     * 按权重分**剩余空间**（`flex(2)` 拿 `flex(1)` 的两倍）。
     *
     * ★ 剩余 = 视口宽 − 固定列总和 − 列间距总和。剩余为负（固定列已经超宽）
     *   ⇒ flex 列拿 0，溢出交给裁剪（阶段 1 无横滚，见 `Table` 类注释的诚实边界）。
     */
    public static function flex(float $weight = 1.0): self
    {
        if ($weight <= 0.0) {
            Errors::report(sprintf('TableColumnWidth::flex() 的权重必须 > 0，收到 %s', (string) $weight));
        }

        return new self(self::FLEX, $weight);
    }

    public function isFlex(): bool
    {
        return $this->kind === self::FLEX;
    }

    /** 固定像素值（非 fixed 时无意义，返回 0） */
    public function px(): float
    {
        return $this->kind === self::FIXED ? $this->amount : 0.0;
    }

    /** flex 权重（非 flex 时无意义，返回 0） */
    public function weight(): float
    {
        return $this->kind === self::FLEX ? $this->amount : 0.0;
    }
}
