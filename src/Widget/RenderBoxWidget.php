<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;
use Drift\Rendering\BoxStyle;

/**
 * 带渲染盒的控件（Widget → RenderBox 构造契约）。
 *
 * Widget 不持盒：挂载时造盒，配置更新先问 `updateRenderObject()` 能否复用，
 * 不等就换盒，因此不存在字段级半同步。结构节点不产盒，所以不放 `Widget` 基类。
 *
 * @api
 */
abstract class RenderBoxWidget extends Widget
{
    /**
     * 造本控件的渲染盒，只读 Widget 自己的字段（字段对齐由 lint 比对）。
     * 收 `BuildContext` 供需要环境解析的控件用；子盒由 Element 挂载，不在这里挂。
     */
    abstract public function createRenderObject(BuildContext $context): RenderBox;

    /**
     * 把新配置写进既有盒。true = 已就地更新可复用，false = 换新盒。
     *
     * 默认只复用零字段盒。覆写方必须：几何字段变 ⇒ 返回 false，不自己 `markNeedsLayout()`；
     * 样式 / 主题派生值变 ⇒ 返回 false；只有纯绘制量变化才返回 true，且不动 `$size`。
     * 复用路径保留悬停 / 按下运行时态，不需要 `inheritRuntimeState()`。
     *
     * @api 控件扩展点
     */
    public function updateRenderObject(RenderBox $box, BuildContext $context): bool
    {
        return $box->isConfigurationFree();
    }

    /**
     * 本控件自己画底 / 边 / 圆角吗（在 `paintOwnChrome()`）？
     *
     * true ⇒ `Element::syncBox()` 会剥掉盒 `$mods` 的视觉三字段，避免描边重复绘制
     * （抗锯齿描边不幂等，双画 golden 会差像素）。声明 true 必须在 `createRenderObject()`
     * 里把用户样式合并进 `BoxStyle`，否则 `->background()` 等变成死字段。
     *
     * @internal 只给 Element 用；应用不该调
     */
    public function paintsBoxChrome(): bool
    {
        return false;
    }

    /**
     * 本控件自己画禁用外观吗？
     *
     * true ⇒ `Element::syncBox()` 给盒 `$mods` 打 `withoutDim()`，不再乘
     * `RenderBox::DISABLED_ALPHA`。声明 true 必须在 `createRenderObject()` 里真的换色，
     * 否则会变成"点不动但看起来正常"。
     *
     * @internal 只给 Element 用；应用不该调
     */
    public function paintsDisabledLook(): bool
    {
        return false;
    }

    /**
     * 用户没声明盒样式时的默认样式（主题派生）；null = 没有默认。
     *
     * 在 `Element::syncBox()` 与用户样式合成；只对不自绘 chrome 的控件生效。
     * 判据是 `BoxStyle::isBlank()` 而非 `isNone()`：`->radius(4)` 也算设过。
     *
     * @internal 只给 Element 用；应用不该调
     */
    public function defaultBoxStyle(BuildContext $context): ?BoxStyle
    {
        return null;
    }
}
