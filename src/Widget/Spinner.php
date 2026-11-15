<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderSpinner;
use Drift\Rendering\ColorToken;

/**
 * 加载指示器 —— `AsyncValue` 四态里 `loading` 那一态的画法（§A.2 样板引用的目标形态）。
 *
 * ```php
 * // ★ 四态用 `AsyncValue::fold()` 画（`AsyncCommand::value()` 给的）
 * 'loading' => static fn(AsyncValue $v): Widget => Center::make(Spinner::make()),
 * ```
 *
 * ★ 以前这里写的是 `LoadStatus::Loading => Spinner::make()` —— 那个枚举已于
 *   2026-10-30 被 `AsyncValue` 取代（它多带**载荷**与**消息**，且四态由
 *   `fold()` 强制写全）。
 *
 * ★ **为什么是 `StatefulWidget`**：动画相位是纯 UI 态（§11.5）且必须跨 build 活
 *   ⇒ 住 `State`；Widget 只持直径（§13.2）。
 *
 * ★ **两段而不是三段**（2026-10-23，`02` §6.2）：以前是
 *   `Spinner → SpinnerState → SpinnerBox → RenderSpinner`，因为
 *   `StatefulWidget` 自己不产盒。现在 `createRenderObjectFromState()` 让 State
 *   直接产盒 ⇒ `Spinner → SpinnerState → RenderSpinner`，`SpinnerBox` 删除。
 *   判据：**有态 + 有盒 + 无子**的控件走这条路；有子的（`TextField` /
 *   `ScrollView`）继续走 `build()`。
 *
 * ★ **动画驱动力已就位**（2026-10-18）：`SpinnerState::tickFrame()` 由
 *   `Application::frame()` 每帧遍历树调用（§17.4.9），相位随帧推进；它返回 true
 *   让帧末持续记下一帧的欠账 ⇒ 帧时钟不停 ⇒ 自己转，控件卸载后自动停。
 *   ★ 连带边界：§11.5 已记 —— 同步 IO 后端下 `loading` 态本身画不出来（同栈无帧
 *   可渲染）；装上 `ProcessTaskHost` 之后才真的看得见（见 `apps/users`）。
 *
 * @api
 */
final class Spinner extends StatefulWidget
{
    private float $diameter = 24.0;

    public static function make(): self
    {
        return new self();
    }

    /** 逻辑直径（DIP）。约束比它小 ⇒ 按实际盒尺寸缩小绘制，不报 C6 */
    public function diameter(float $diameter): static
    {
        $this->mutate();
        $this->diameter = $diameter;

        return $this;
    }

    public function createState(): State
    {
        return new SpinnerState();
    }

    /**
     * 从 State 直接产盒（`02` §6.2）：本控件**无子** ⇒ 走叶子路径。
     *
     * ★ 直径读自己的字段（不再是 `diameterValue()` —— 那个 accessor 只为
     *   `SpinnerState::build()` 存在，叶子路径落地后没有第二个读者）。
     * ★ 颜色在这里解析（§15.7 纪律 3 的合并点）：`Drift\Layout` 不必也不许碰 Theme。
     * ★ `$state` 的静态类型是 `State`（签名由基类定死，PHP 不允许收窄参数类型）
     *   ⇒ 与 `SpinnerState::spinner()` 同形地做一次 `instanceof`：配错不可能发生
     *   （框架按 `createState()` 配对），但 AOT 下不窄化就编译不过。
     */
    #[\Override]
    public function createRenderObjectFromState(BuildContext $context, State $state): ?RenderBox
    {
        if (!$state instanceof SpinnerState) {
            Errors::report('SpinnerState 只能配 Spinner（框架内部不变量被破坏）');
        }

        $step = $state instanceof SpinnerState ? $state->step() : 0;

        return new RenderSpinner(
            $this->diameter,
            $step,
            $context->color(ColorToken::Accent),
        );
    }
}
