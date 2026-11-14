<?php
declare(strict_types=1);

namespace Drift\Widget\Traits;

use Drift\Input\SemanticsRole;

/**
 * `Widget` 的**语义面**：给无障碍（UIA）看的名字/角色/值/范围/可用性。
 *
 * ★ 只有一个写入口 `semantics(...)`（一次给全），其余是读侧 —— 分开写会让
 *   "给了名字但没给角色"变成合法状态，而那种控件在 UIA 客户端里**不可见**，
 *   不报错、不写日志。
 * ★ `semanticsRange()` 是为 `Slider` / `Progress` 这类"值有范围"的控件准备的
 *   第二个写入口（`RangeValuePattern` 要 `min`/`max`，`semantics()` 的参数表放不下）。
 *
 * @internal 与 `Widget` 同生共死
 */
trait WidgetSemantics
{
    /**
     * 声明"这个控件是什么"—— 角色 + 人读信息，供
     * `SemanticsBuilder` 建语义树（检查器 / 自动化 / 将来的朗读消费）。
     *
     * ```php
     * Button::make('保存', 'doc.save')          // 内部即 ->semantics(Button, '保存')
     * TextField::make()->label('邮箱')           // label 同时是可访问名
     * Modal::make()->title('确认删除')           // 标题即对话框名
     * ```
     *
     * ★★ **这是字段赋值，不是虚方法覆写**（§9 P3 陷阱）：tpc 有全程序去虚化缺陷，
     *   "基类给默认实现 + 子类覆写 `semantics()`" 会被**静默忽略** ⇒ 症状是"所有
     *   节点角色为空"且编译期不报错。控件要么在自己的构造/设值处调本方法
     *   （`Button` / `TextField::label()` / `Modal::title()` 即如此），要么由应用显式调。
     * ★ **不声明 = 不进语义树**（加性）：`Text` / `Row` / `Card` 这类结构与叶子
     *   一律不调它（判据见 `SemanticsRole` 的类注释）。
     * ★ `null` 参数 = "不动这一项"（便于分次补设，如先角色后取值）。
     *
     * @api
     */
    public function semantics(
        SemanticsRole $role,
        ?string $name = null,
        ?string $value = null,
        ?bool $enabled = null
    ): static {
        $this->mutate();
        $this->semRole = $role;
        if ($name !== null) {
            $this->semName = $name;
        }
        if ($value !== null) {
            $this->semValue = $value;
        }
        if ($enabled !== null) {
            $this->semEnabled = $enabled;
        }

        return $this;
    }

    /**
     * 声明取值区间（只对 `range` 族角色有意义：`Slider` / `Progress`）。
     *
     * ```php
     * $w->semantics(SemanticsRole::Slider, $label, '42', true)->semanticsRange(0, 100);
     * ```
     *
     * ★ 与 `semantics()` **正交**、可单独调用（`semantics()` 之后再链也行）。
     *   不塞进 `semantics()` 的 4 参签名，是因为只有少数控件需要区间。
     * ★ `UIA IRangeValueProvider` 必须有 `Minimum`/`Maximum`，就取自这里；
     *   不给 ⇒ 两者都是 0（读屏读到空区间，而不是被默认成 0..100）。
     *
     * @api
     */
    public function semanticsRange(float $min, float $max): static
    {
        $this->mutate();
        if ($min <= $max) {
            $this->semRangeMin = $min;
            $this->semRangeMax = $max;
        } else {
            $this->semRangeMin = $max;
            $this->semRangeMax = $min;
        }

        return $this;
    }

    /**
     * 声明"这个控件现在是什么状态"（§65）—— 选中 / 展开 / 层级。
     *
     * ```php
     * $row->semanticsState(selected: true, expanded: false, level: 2);
     * $leaf->semanticsState(level: 3);            // 叶子：不给 expanded ⇒ 客户端知道"不能展开"
     * ```
     *
     * ★★ `null` 与 `false` **不是一回事**：`null` = 这个控件没有该概念（UIA 侧
     *   不提供对应 Pattern，客户端据此知道"不可展开/不可选"）；`false` = 有概念且
     *   现在是关的。混用会让每个按钮都声称自己能展开。
     * ★ 与 `semantics()` / `semanticsRange()` 一样是**字段赋值**，不是虚方法。
     * ★ `level` 只在集合族里给（树行）；`0` = 不声明（UIA 不给 `Level` 属性）。
     *
     * @api
     */
    public function semanticsState(?bool $selected = null, ?bool $expanded = null, int $level = 0): static
    {
        $this->mutate();
        if ($selected !== null) {
            $this->semSelect = $selected ? 2 : 1;
        }
        if ($expanded !== null) {
            $this->semExpand = $expanded ? 2 : 1;
        }
        if ($level > 0) {
            $this->semLevel = $level;
        }

        return $this;
    }

    /** @internal `SemanticsBuilder` 读它；`null` = 不在语义树里 */
    public function semanticsRole(): ?SemanticsRole
    {
        return $this->semRole;
    }

    /** @internal `SemanticsBuilder` 读它（三态，见 `semanticsState()`） */
    public function semanticsSelectState(): int
    {
        return $this->semSelect;
    }

    /** @internal `SemanticsBuilder` 读它（三态，见 `semanticsState()`） */
    public function semanticsExpandState(): int
    {
        return $this->semExpand;
    }

    /** @internal `SemanticsBuilder` 读它（1-based；0 = 未声明） */
    public function semanticsLevel(): int
    {
        return $this->semLevel;
    }

    /** @internal `SemanticsBuilder` 读它 */
    public function semanticsName(): string
    {
        return $this->semName;
    }

    /** @internal `SemanticsBuilder` 读它 */
    public function semanticsValue(): string
    {
        return $this->semValue;
    }

    /** @internal `SemanticsBuilder` 读它 */
    public function semanticsEnabled(): bool
    {
        return $this->semEnabled;
    }

    /** @internal `SemanticsBuilder` 读它（`range` 族角色的取值下界） */
    public function semanticsRangeMin(): float
    {
        return $this->semRangeMin;
    }

    /** @internal `SemanticsBuilder` 读它（`range` 族角色的取值上界） */
    public function semanticsRangeMax(): float
    {
        return $this->semRangeMax;
    }
}
