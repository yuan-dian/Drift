<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\Axis;
use Drift\Layout\FlexAlign;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderWrap;

/**
 * 流式换行容器（A4 第二件，2026-10-28）：**放不下的子自动掉到下一行**。
 *
 * ```php
 * Wrap::make()
 *     ->spacing(8.0)
 *     ->runSpacing(8.0)
 *     ->children([$tagA, $tagB, $tagC]);
 * ```
 *
 * ## 与 `Row` / `Grid` 的分工
 *
 * | 容器 | 一行放几个 | 谁决定 |
 * |---|---|---|
 * | `Row` | 全放（放不下 ⇒ 按 `Overflow` 处理） | 应用 |
 * | `Grid` | `columns` 个 | **应用**（`(可用宽 − 间距) / 列数`）|
 * | **`Wrap`** | **量完才知道**（贪心累加，装不下就断行） | 布局 |
 *
 * ⇒ `Wrap` 是"子项数量运行时才知道 + 必须自动换行"那一类需求的唯一答案
 * （标签云 / 自适应工具栏）。`04` §4.4 的推翻条件正是这个场景。
 *
 * ## ★ 不做 `Stretch`（响亮失败）
 *
 * `alignment(FlexAlign::Stretch)`（同一行里所有子项取该行最高值）会让"行高来自子"
 * 与"子按行高排"互为前提 ⇒ 正是 `04` §4.4 拒绝 `Wrap` 时担心的那个迭代。
 * 所以收到 `Stretch` 是 **Fatal + 修法**，不是静默当 `Start`。
 * ⇒ `Start` / `Center` / `End` 都可用，且**不引入任何迭代**。
 *
 * ★ **结构节点，不是 `BoxWidget`**：样式（背景 / 圆角 / 内距）由 `Widget` 基类的
 *   修饰字段提供 —— W5 之后修饰是**字段**，任何控件都有。
 *
 * @api
 */
final class Wrap extends MultiChildRenderObjectWidget
{
    /** @var list<Widget> */
    private array $kids = [];
    private Axis $direction = Axis::Horizontal;
    private float $gap = 0.0;
    private float $runGap = 0.0;
    private FlexAlign $align = FlexAlign::Start;
    private FlexAlign $runAlign = FlexAlign::Start;

    public static function make(): self
    {
        return new self();
    }

    /** @param list<Widget> $kids 顺序即分行序（贪心累加，装不下就断行） */
    public function children(array $kids): static
    {
        $this->mutate();
        $this->kids = $kids;

        return $this;
    }

    /** `Horizontal` = 横向排、往下换行（默认）；`Vertical` = 竖着排、往右换列 */
    public function direction(Axis $value): static
    {
        $this->mutate();
        $this->direction = $value;

        return $this;
    }

    /** 同一行内相邻子项的间距 */
    public function spacing(float $value): static
    {
        $this->mutate();
        $this->gap = $value;

        return $this;
    }

    /** 行与行之间的间距 */
    public function runSpacing(float $value): static
    {
        $this->mutate();
        $this->runGap = $value;

        return $this;
    }

    /**
     * 行内子项在**交叉轴**上的对齐。
     *
     * ★ `Stretch` 会 Fatal（它需要第二遍布局 —— 见类注释）。
     */
    public function alignment(FlexAlign $value): static
    {
        $this->mutate();
        $this->rejectStretch($value, 'alignment');
        $this->align = $value;

        return $this;
    }

    /**
     * 整块（所有行）在**交叉轴**上的分布（盒子比内容高时才有区别）。
     *
     * ★ `Stretch` 会 Fatal（同上）。
     */
    public function runAlignment(FlexAlign $value): static
    {
        $this->mutate();
        $this->rejectStretch($value, 'runAlignment');
        $this->runAlign = $value;

        return $this;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderWrap($this->direction, $this->gap, $this->runGap, $this->align, $this->runAlign);
    }

    /** @return list<Widget> */
    public function kidList(): array
    {
        return $this->kids;
    }

    /**
     * ★ `Stretch` 是**响亮失败**而不是"退化成 `Start`"。
     *
     * 静默退化的表现是"`->alignment(Stretch)` 写了没反应"，而它背后是一个真的
     * 设计决定（要不要为它引入布局迭代）。把这个决定**交还给人**，不给默认值。
     */
    private function rejectStretch(FlexAlign $value, string $setter): void
    {
        if ($value === FlexAlign::Stretch) {
            Errors::report(\sprintf(
                'Wrap::%s(FlexAlign::Stretch) 尚未实现：它要求"同一行的子项都取本行最高值"，'
                    . '而"行高来自子项"与"子项按行高排"互为前提 ⇒ 需要第二遍 layout'
                    . '（正是 `04` §4.4 拒绝 Wrap 时担心的那个迭代）。'
                    . "\n" . '修法：① 用 Center / End；② 或给子项显式定高（`->boxSize(null, 32.0)`）。',
                $setter,
            ));
        }
    }
}
