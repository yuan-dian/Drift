<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\BoxConstraints;

/**
 * 按「本容器实际拿到的约束」选子树 —— **最后手段**。
 *
 * ```php
 * // 宽窄两套版式
 * LayoutBuilder::make(static fn(BuildContext $ctx, BoxConstraints $c): Widget =>
 *     $c->hasBoundedWidth() && $c->maxWidth < 600.0 ? NarrowNav::make() : WideNav::make()
 * );
 * ```
 *
 * ## 先确认不是「布局层该干的事」
 *
 * 列宽分配 / 虚拟化行数 / 表格列数，正解是**布局层**在 `performLayout()` 里
 * 一次性收齐子尺寸再分配（父知道所有子多大，比 build 期猜更准）。用本类顶替
 * 只是把布局算不出来的东西推迟到 build 期 —— 那是能力不足的认证，不是常规手段
 * （SwiftUI `GeometryReader` 被官方文档自认「仅作最后手段」，同一个坑）。
 *
 * ## 三条边界
 *
 * ★ **`maxWidth` / `maxHeight` 可能是 `INF`**（滚动视图主轴 / `Row` 的子）：
 *   按尺寸分支前必须 `hasBoundedWidth()` / `hasBoundedHeight()`。把 `INF`
 *   当"很宽"是 Flutter / Compose 最常见的坑，且**不报错、只是版式错**。
 * ★ **零帧**：约束在本帧 layout 之后经 `ConstraintObserver` 回灌，同帧内收敛
 *   （稳态 1 遍 layout，约束真变那帧 2 遍）—— 不会先画一版错的再跳。
 * ★ builder 里 `setState()` 合法（它在 build 期），但**对自己**标脏仍报
 *   E-FLUSH-CYCLE（既有纪律，不为此开后门）。
 *
 * ★ 本控件自己不产盒（走 `build()`），要加内边距请外面套 `Padding`。
 *
 * @api
 */
final class LayoutBuilder extends StatefulWidget
{
    /** @var \Closure(BuildContext, BoxConstraints): Widget */
    private \Closure $builder;

    private function __construct(\Closure $builder)
    {
        $this->builder = $builder;

        parent::__construct();
    }

    /** @param \Closure(BuildContext, BoxConstraints): Widget $builder */
    public static function make(\Closure $builder): self
    {
        return new self($builder);
    }

    /** @internal 只有 `LayoutBuilderState::build()` 调 */
    public function builderClosure(): \Closure
    {
        return $this->builder;
    }

    public function createState(): State
    {
        return new LayoutBuilderState();
    }
}
