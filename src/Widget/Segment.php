<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Input\SemanticsRole;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderSegment;
use Drift\Rendering\ColorToken;
use Drift\Rendering\MetricToken;
use Drift\Rendering\SegmentedStyle;
use Drift\Text\TextRole;

/**
 * 分段控件的**一段**（`SegmentedControl` 的孩子，一般不单独用）。
 *
 * ```php
 * // 应用侧写整个控件，不写段：
 * SegmentedControl::make(['日', '周', '月'])->selected(1)
 *     ->onChanged(fn(int $i) => $this->setState(fn() => $this->range = $i));
 * ```
 *
 * ★ **受控 + 单段即焦点**：选中态由应用经 `SegmentedControl::selected()` 给，
 *   点击/空格只回调 `onChanged()`（值由应用在 `setState` 里改）—— 与
 *   `Checkbox` / `TextField` 同一条纪律，控件不自持"选中了谁"。
 *
 * ★ **为什么是独立控件而不是 `SegmentedControl` 内部的一个分支**：激活入口
 *   `Application::activateFocusedWidget()` 收的是**焦点控件**，整个分段控件
 *   一个焦点 ⇒ 激活时不知道点了哪段。一段一个焦点，命中测试（取最深盒）天然
 *   把焦点落到被点的那一段上。
 *
 * ★ **已选中再点不回调**（与 `Radio` / Win32 `BM_CLICK` 一致）：分段控件的
 *   选中是"单选"语义，重复触发只会换来一次无意义的 setState。
 *
 * ★ **语义复用 `Radio` 角色**（不新增 `Tab` 角色）：一段就是"一组里的一项"，
 *   与 `SemanticsRole` 的判据完全同构（`value` = 选中时自己的标签）。真正的
 *   TabBar（切页面，不只是切值）将来要加角色时再加 —— 今天加了就是
 *   "没有声明者的角色"（该类注释明令禁止）。
 *
 * @api
 */
final class Segment extends RenderBoxWidget
{
    private readonly string $label;

    /** 在组内的序号（`onChanged` 回调收它） */
    private readonly int $index;

    private bool $selected = false;

    private bool $enabled = true;

    private ?\Closure $onChanged = null;

    public function __construct(string $label, int $index)
    {
        parent::__construct();

        $this->label = $label;
        $this->index = $index;

        // §9.1：开关天然可聚焦（Tab 能到、Space 能选）
        $this->markFocusable();
        $this->refreshSemantics();
    }

    public static function make(string $label, int $index): self
    {
        return new self($label, $index);
    }

    /** 选中态（受控值，由 `SegmentedControl` 统一下发） */
    public function selected(bool $on): static
    {
        $this->mutate();
        $this->selected = $on;
        $this->refreshSemantics();

        return $this;
    }

    /** 禁用：字色降级、不响应激活（仍在 Tab 序里 —— 与 Win32 一致） */
    public function enabled(bool $enabled): static
    {
        $this->mutate();
        $this->enabled = $enabled;
        $this->refreshSemantics();

        return $this;
    }

    /** 点击/按键回调（无参 —— "选了哪段"由本段自己知道） */
    public function onChanged(callable $handler): static
    {
        $this->mutate();
        $this->onChanged = \Closure::fromCallable($handler);

        return $this;
    }

    public function index(): int
    {
        return $this->index;
    }

    public function isSelected(): bool
    {
        return $this->selected;
    }

    /**
     * 激活（`Application::activateFocusedWidget()` 的 `Segment` 分支唯一入口）。
     *
     * - 禁用 / 没接回调 / **已选中** ⇒ 什么都不做（不吞焦点变化）
     */
    public function activate(): void
    {
        if (!$this->enabled || $this->onChanged === null || $this->selected) {
            return;
        }

        $handler = $this->onChanged;
        $handler();
    }

    /**
     * 语义：`role=radio`、`name` = 标签、`value` = 选中时的自己的标签。
     *
     * ★ 不用三元表达式（`$this->selected ? $this->label : ''` 的两支类型不同
     *   会撞 tpc 的 `operator?:` C2678，只有 T2 构建看得到）—— 拆 if/else。
     */
    private function refreshSemantics(): void
    {
        $value = '';
        if ($this->selected) {
            $value = $this->label;
        }

        $this->semantics(SemanticsRole::Radio, $this->label, $value, $this->enabled);
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        // ★ 两个 if 都不用三元：`?:` 两支类型不同（Color vs Color 其实同类，
        //   但"按标志选一个已解析值"统一用 if 写，与 ToggleBase 逐字同构）
        $labelColor = $context->color(ColorToken::Muted);
        if ($this->enabled) {
            $labelColor = $context->color(ColorToken::Text);
        }

        $fill = $context->color(ColorToken::Muted);
        if ($this->enabled) {
            $fill = $context->color(ColorToken::Accent);
        }

        $style = new SegmentedStyle(
            $context->color(ColorToken::Chrome),
            $context->color(ColorToken::Border),
            $context->metric(MetricToken::BorderWidth),
            SegmentedStyle::DEFAULT_GAP,
            SegmentedStyle::DEFAULT_INSET,
            $fill,
            $context->color(ColorToken::Pane),
            $labelColor,
            $context->textSize(TextRole::Body),
        );

        return new RenderSegment($this->selected, $style, $this->label, $context->metrics());
    }
}