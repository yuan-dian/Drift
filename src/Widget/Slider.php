<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Input\CursorShape;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Input\SemanticsRole;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderSlider;
use Drift\Rendering\ColorToken;
use Drift\Rendering\MetricToken;
use Drift\Rendering\SliderStyle;

/**
 * 滑杆（W8-2）：连续取值的受控控件。
 *
 * ```php
 * Slider::make()
 *     ->range(0.0, 100.0)->step(5.0)
 *     ->value($this->volume)                       // 受控值
 *     ->onChanged(fn(float $v) => $this->setState(fn() => $this->volume = $v));
 * ```
 *
 * ## 受控值纪律
 *
 * 值住应用 State，本控件**只回调**（`onChanged(新值)`）。点轨道 / 拖拇指 / 方向键
 * 三条路都走同一个 `emit()` —— 它会夹到 `[min,max]`、并在**值真的变了**时才回调
 * （同一位置重复按下不产生无意义的 `setState`）。
 *
 * ★ **连续按键依赖应用立即改值**：`nudge()` 读的是 `$this->value`（这一帧的受控值）。
 *   应用在回调里同步 `setState` ⇒ 下一帧盒换新、值跟上。同一帧内连发多个方向键
 *   会各自基于同一个旧值 —— 实际不会发生（系统按键连发间隔 ≥ 30ms > 一帧）。
 *
 * ## 为什么它不用 `Draggable`
 *
 * 方案册原本写"drag 复用/最小扩展 `Application` 派发"，实际接线时否掉了
 * `Draggable` + `GestureArena` 这条路，三个硬理由：
 *
 * 1. **点轨道不跳值**：arena 把激活推迟到 `up`、把拖动推迟到 6px 阈值。
 *    Slider 的标准语义是"按下即跳到该位置"，这条直接做不到。
 * 2. **语义污染**：`DragController::drop()` 会派发 `DragTarget::onAccept(payload)`
 *    ⇒ 放在拖放区里的 Slider，松手会被当成"投放"。
 * 3. **拖动中 `hoverTo` 会刷新拖放区高亮** ⇒ 拖 Slider 时页面上的拖放区跟着亮。
 *
 * 实际走的是 `pointer($handler, ownsPress: true)`：按下归本控件、框架负责捕获与
 * 后续相位派发。**不引入第六个手势状态** —— `$captureNode` 的四条收口路径
 * （抬起 / 卸载 / 失焦 / 浮层外按下）全部现成。
 *
 * ## 键盘
 *
 * 方向键 / Home / End 走 `keyboard()`（W8-2 新开的缝），**不碰**
 * `Application::activateFocusedWidget()` 的 `instanceof` 塔。
 * `step <= 0` 时按区间的 1% 走（连续滑杆的常见手感）。
 *
 * @api
 */
final class Slider extends RenderBoxWidget implements SemanticsValueEditable
{
    /** 没显式给宽时的默认宽（与 `RenderSlider` 的无界兜底同值） */
    public const float DEFAULT_WIDTH = 160.0;

    private float $value = 0.0;

    private float $min = 0.0;

    private float $max = 100.0;

    /** `0` = 连续（不量化） */
    private float $step = 0.0;

    private float $width = self::DEFAULT_WIDTH;

    private string $label = '';

    private ?\Closure $onChanged = null;

    public function __construct()
    {
        parent::__construct();

        // §9.1：滑杆天然可聚焦（Tab 能到、方向键能调）
        $this->markFocusable();
        $this->refreshSemantics();
        $this->wireInput();
    }

    public static function make(): self
    {
        return new self();
    }

    /** 受控值（应用 State 给；越界由盒夹，不在这里夹 —— 语义值要原样可见） */
    public function value(float $value): static
    {
        $this->mutate();
        $this->value = $value;
        $this->refreshSemantics();

        return $this;
    }

    /** 取值区间（`min > max` 会被换过来，不报错 —— 与 `clamp` 同宽容） */
    public function range(float $min, float $max): static
    {
        $this->mutate();

        if ($min <= $max) {
            $this->min = $min;
            $this->max = $max;
        } else {
            $this->min = $max;
            $this->max = $min;
        }

        $this->refreshSemantics();

        return $this;
    }

    /** 步长；`<= 0` = 连续（键盘按区间的 1% 走） */
    public function step(float $step): static
    {
        $this->mutate();
        $this->step = $step;

        return $this;
    }

    /**
     * 禁用 / 恢复 —— `disabled()` 的别名（见 `ToggleBase::enabled()` 的同款说明）。
     *
     * ★ 状态只有一份（`Modifier::$disabled`）。禁用后**不进 Tab 序、不响应指针**。
     * ★ 外观由本类自己画（`paintsDisabledLook()`）：轨道/拇指换 `Muted`，
     *   不叠框架的 0.38 alpha。
     */
    public function enabled(bool $enabled): static
    {
        $this->disabled(!$enabled);
        $this->refreshSemantics();

        return $this;
    }

    public function isEnabled(): bool
    {
        return !$this->mods()->disabled();
    }

    public function paintsDisabledLook(): bool
    {
        return true;
    }

    /** 盒宽（叶子无内容 ⇒ 宽只能显式给；`<= 0` = 吃满父给的上限） */
    public function width(float $width): static
    {
        $this->mutate();
        $this->width = $width;

        return $this;
    }

    /** 可访问名（语义树的 name；不显示在界面上） */
    public function label(string $label): static
    {
        $this->mutate();
        $this->label = $label;
        $this->refreshSemantics();

        return $this;
    }

    public function onChanged(callable $handler): static
    {
        $this->mutate();
        $this->onChanged = \Closure::fromCallable($handler);

        return $this;
    }

    /**
     * 语义层写值（UIA `RangeValuePattern.SetValue`）：转 `emit()` —— 它会夹到
     * `[min,max]`、并只在**值真的变了**时回调（与点轨道/拖拇指同一条路）。
     *
     * ★ 非数字 / 禁用 ⇒ `false`（读屏据此知道没生效）。
     */
    public function setSemanticsValue(string $value): bool
    {
        if ($this->mods()->disabled() || !is_numeric($value)) {
            return false;
        }
        $this->emit((float) $value);

        return true;
    }

    /**
     * 语义：`role=slider`、`value` = 读数。
     *
     * ★ 读数取整（`%.0f`）：滑杆的语义读数通常是"42"而不是"42.37"，与
     *   `Progress` 的百分读数同一档。要精确读数由应用在旁边放 `Text`。
     */
    private function refreshSemantics(): void
    {
        $this->semantics(SemanticsRole::Slider, $this->label, \sprintf('%.0f', $this->value), !$this->mods()->disabled())
            ->semanticsRange($this->min, $this->max);
    }

    /**
     * 接上两条输入缝（`pointer` 接管按下、`keyboard` 接管方向键）。
     *
     * ★ 回调里判 `mods()->disabled()` 而不是"禁用时不装"：`disabled()` 可以在构造
     *   之后调（builder 风格），装了再判才跟得上。
     * ★ 禁用时**仍然接管按下**（吞掉），这是对的：禁用控件不该被激活。
     */
    private function wireInput(): void
    {
        $this->pointer(function (PointerEvent $event, Element $self): ?CursorShape {
            if ($this->mods()->disabled()) {
                return null;
            }

            // up / leave 只收口不改值（值已经在 down / move 里跟手了）
            if ($event->isUp() || $event->isLeave()) {
                return null;
            }

            $box = $self->effectiveBox();
            if (!$box instanceof RenderSlider) {
                return null;   // 还没排过 / 被别的盒包住 ⇒ 这一帧不跟手
            }

            // ★ 客户区坐标 → 盒内坐标：减 `absoluteOrigin`（**不要手抄父链累加**）
            $origin = RenderBox::absoluteOrigin($box);
            $this->emit($box->valueAt($event->x - $origin[0]));

            return null;
        }, ownsPress: true);

        $this->keyboard(function (KeyEvent $event, Element $self): bool {
            return $this->nudge($event);
        });
    }

    /** 方向键 / Home / End ⇒ 新值。返回 `true` = 吃掉这个键 */
    private function nudge(KeyEvent $event): bool
    {
        if ($this->mods()->disabled()) {
            return false;
        }

        $chord = $event->chord;

        $step = $this->step;
        if ($step <= 0.0) {
            $step = ($this->max - $this->min) / 100.0;
        }

        if ($chord->is(KeyChord::left()) || $chord->is(KeyChord::down())) {
            $this->emit($this->value - $step);

            return true;
        }

        if ($chord->is(KeyChord::right()) || $chord->is(KeyChord::up())) {
            $this->emit($this->value + $step);

            return true;
        }

        if ($chord->is(KeyChord::home())) {
            $this->emit($this->min);

            return true;
        }

        if ($chord->is(KeyChord::end())) {
            $this->emit($this->max);

            return true;
        }

        return false;
    }

    /**
     * 夹到区间 + **值真的变了才回调**。
     *
     * ★ 不量化：量化住 `RenderSlider::valueAt()`（指针路径）。键盘给的
     *   `value ± step` 天然落在步长格点上（`step <= 0` 时是连续值，不该被量化）。
     */
    private function emit(float $value): void
    {
        $next = $value;
        if ($next < $this->min) {
            $next = $this->min;
        }
        if ($next > $this->max) {
            $next = $this->max;
        }

        $handler = $this->onChanged;
        if ($handler === null) {
            return;
        }

        if (\abs($next - $this->value) < 1e-9) {
            return;   // 同位置重复按下 / 已经到头：不产生无意义的 setState
        }

        $handler($next);
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        // ★ 按标志选已解析值一律 if/else（`?:` 两支类型不同会撞 tpc 的 C2678）
        $accent = $context->color(ColorToken::Muted);
        $border = $context->color(ColorToken::Border);
        if (!$this->mods()->disabled()) {
            $accent = $context->color(ColorToken::Accent);
            $border = $context->color(ColorToken::Surface);
        }

        $style = new SliderStyle(
            $context->color(ColorToken::Chrome),
            $accent,
            $border,
            SliderStyle::DEFAULT_TRACK_HEIGHT,
            SliderStyle::DEFAULT_THUMB_RADIUS,
            $context->metric(MetricToken::BorderWidth),
        );

        // 禁用态给 null ⇒ `acceptsHover()` 为假 ⇒ 连状态都不推（与 `ToggleBase` 同判据）
        $hoverOverlay = null;
        $pressedOverlay = null;
        if (!$this->mods()->disabled()) {
            $hoverOverlay = $context->color(ColorToken::Hover);
            $pressedOverlay = $context->color(ColorToken::Pressed);
        }

        $box = new RenderSlider(
            $this->value,
            $this->min,
            $this->max,
            $this->step,
            $this->width,
            $style,
            $hoverOverlay,
            $pressedOverlay,
        );

        return $box;
    }
}