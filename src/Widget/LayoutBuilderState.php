<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\BoxConstraints;

/**
 * `LayoutBuilder` 的 State：把「布局期的真实约束」回灌给 build 期。
 *
 * 时序（`Frame::run()` 的 ①→②→②c 循环）：
 * ```
 * ① settle   ← known === null ⇒ 零尺寸占位（不猜数字）
 * ② layout   ← 壳盒把收到的约束记进 `RenderBox::constraints()`
 * ②c 本类    ← 约束与 known 不同 ⇒ known = 新值 + markDirty ⇒ 同帧再来一轮
 * ```
 * 稳态 1 遍 layout；约束真变那帧 2 遍；收敛完才 paint（无撕裂中间态）。
 *
 * @api
 */
final class LayoutBuilderState extends State implements ConstraintObserver
{
    /** 上一轮 layout 记下的内容盒约束；`null` = 还没排过（**不编造**） */
    private ?BoxConstraints $known = null;

    public function initState(): void
    {
        $this->pipeline()->addConstraintObserver($this);
    }

    public function dispose(): void
    {
        $this->pipeline()->removeConstraintObserver($this);
    }

    public function build(BuildContext $ctx): Widget
    {
        $known = $this->known;

        // 约束未知：不调 builder、也不拿窗口尺寸顶替 —— 那正是本机制要根治的 bug
        // （把"不知道"伪装成一个具体数字）。同帧就会收敛，这个占位不会被画出来。
        if ($known === null) {
            return SizedBox::make();
        }

        $widget = $this->widget();
        if (!$widget instanceof LayoutBuilder) {
            // 框架不变量：StatefulElement 按 get_class 配对，配错不可能发生
            Errors::report('LayoutBuilderState 只能配 LayoutBuilder（框架内部不变量被破坏）');

            return SizedBox::make();
        }

        $built = ($widget->builderClosure())($ctx, $known);

        // 壳：给子树一个纯转发盒。有了它，`effectiveBox()->constraints()` 的落点
        // 与 builder 返回什么无关（否则会读到子树内部某层盒的约束，那是错的）。
        return SizedBox::make($built);
    }

    public function pollConstraintDrift(): ?string
    {
        $box = $this->element()?->effectiveBox();
        $now = $box === null ? null : $box->constraints();
        if ($now === null) {
            return null;   // 还没排过（例如整棵子树被跳过）⇒ 下一轮 layout 会记
        }

        $old = $this->known;
        if ($old !== null && $old->equals($now)) {
            return null;   // 相等门控：相同约束不重建（防震荡第一道闸）
        }

        $this->known = $now;
        $this->markDirty();

        if ($old === null) {
            return 'LayoutBuilder: 首轮 ' . self::describe($now);
        }

        return 'LayoutBuilder: ' . self::describe($old) . ' → ' . self::describe($now);
    }

    /** `min..max × min..max`，`INF` 显式写出来（不印成 inf / 大数字） */
    private static function describe(BoxConstraints $c): string
    {
        return self::edge($c->minWidth) . '..' . self::edge($c->maxWidth)
            . ' × ' . self::edge($c->minHeight) . '..' . self::edge($c->maxHeight);
    }

    private static function edge(float $v): string
    {
        return \is_infinite($v) ? 'INF' : \sprintf('%.1f', $v);
    }
}
