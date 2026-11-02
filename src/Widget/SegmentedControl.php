<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\EdgeInsets;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderSegmentedControl;
use Drift\Rendering\ColorToken;
use Drift\Rendering\MetricToken;
use Drift\Rendering\SegmentedStyle;
use Drift\Text\TextRole;

/**
 * 分段控件（iOS `UISegmentedControl` / WinUI `Segmented` 一类）：一组平级的
 * 视图切换，**选中恰好一段**。
 *
 * ```php
 * SegmentedControl::make(['日', '周', '月'])->selected($this->range)
 *     ->onChanged(fn(int $i) => $this->setState(fn() => $this->range = $i));
 * ```
 *
 * ## 形状从哪来（三层分工）
 *
 * | 层 | 谁 | 画什么 |
 * |---|---|---|
 * | Widget | `SegmentedControl`（本类，**结构节点**） | 建段孩子 + 轨道盒；**不进语义树**（结构不是语义） |
 * | Widget | `Segment`（每段一个，可聚焦） | 自己的选中块 + 标签；`role=radio` |
 * | Layout | `RenderSegmentedControl` / `RenderSegment` | 等宽布局 + 画墨 |
 *
 * ★ **为什么是结构节点（`Stack` 形态）而不是一个叶子控件**：激活入口
 *   `Application::activateFocusedWidget()` 收**焦点控件** —— 整个控件一个焦点
 *   就答不出"点了哪段"。一段一个焦点，命中测试（取最深盒）天然把焦点落到
 *   被点的那一段。形状上像 `Stack`：`kidList()` 给子层 + 自己的 Element 把子盒
 *   喂给多子渲染盒。
 *
 * ★ **受控**：`selected()` 由应用给，点击只回调 `onChanged(新序号)`（与
 *   `Checkbox` / `TextField` 同纪律）。控件不自持"选中了谁" —— 自持会让
 *   "提交时读到的值"与"屏幕上显示的值"分成两份真相。
 *
 * ★ **等宽段**（`RenderSegmentedControl` 类头有完整论证）：标签长短不齐时
 *   选中块尺寸恒定，视觉只表达"哪一段被选中"。
 *
 * ★ **越界选中 ⇒ 全不选**（不夹到 0）：应用换了标签表、旧下标越界时，
 *   "静默选中第一项"是替用户做决定；全不选是显式的"该设选中了"。
 *
 * ★ **今天没有的**（ v1 刻意不做，别当缺陷报）：
 *   - **方向键在段间移动焦点**：Tab 序本来就是一段一格（与 Win32 单选组一致），
 *     方向键路由是键盘子系统的活（W8-4）。
 *   - **悬停高亮**：`Widget::hover()` 回调框架照常派发，视觉态要有状态，
 *     v1 不加（`Button` 也没有 hover 视觉）。
 *   - **撑满授予宽度**：默认 hugs 内容（等宽段之和）。要撑满让父给紧约束
 *     （`Dock` 的 `center` 槽 / `Stack(Expand)`），或外面套 `Expanded`
 *     —— 但 Flex 给非弹性子的是**有界主轴**，套了也是 hugs，别依赖。
 *
 * @api
 */
final class SegmentedControl extends MultiChildRenderObjectWidget
{
    /** @var list<string> */
    private readonly array $labels;

    private int $selected = 0;

    private ?\Closure $onChanged = null;

    /** @param list<string> $labels 段标签（空表 = 空轨，不炸） */
    public function __construct(array $labels)
    {
        parent::__construct();

        $this->labels = array_values($labels);

        // 轨道水平/垂直内距：标签到轨边的距离。可用基类 `->padding()` 覆盖。
        $this->padding(EdgeInsets::symmetric(10.0, 5.0));
    }

    /** @param list<string> $labels */
    public static function make(array $labels): self
    {
        return new self($labels);
    }

    /** 选中段序号（越界 ⇒ 全不选；见类头） */
    public function selected(int $index): static
    {
        $this->mutate();
        $this->selected = $index;

        return $this;
    }

    /**
     * 整组禁用 / 恢复 —— `disabled()` 的别名。
     *
     * ★ 本类**不自己画禁用外观**，但照样声明 `paintsDisabledLook()`：禁用要下发给
     *   每个 `Segment`（各段换 `Muted`），若容器再乘一层 0.38，段里的灰字会被二次
     *   衰减。声明为真 = "这一层的禁用由子树表达，别在容器上叠 alpha"。
     * ★ 轨道背景在禁用时**不变**（与旧行为一致：只有段降级）。
     */
    public function enabled(bool $enabled): static
    {
        return $this->disabled(!$enabled);
    }

    public function isEnabled(): bool
    {
        return !$this->mods()->disabled();
    }

    public function paintsDisabledLook(): bool
    {
        return true;
    }

    /** 选中变化回调：收**新序号**（不是"切换了"这个事件） */
    public function onChanged(callable $handler): static
    {
        $this->mutate();
        $this->onChanged = \Closure::fromCallable($handler);

        return $this;
    }

    public function selectedIndex(): int
    {
        $count = count($this->labels);
        if ($this->selected < 0 || $this->selected >= $count) {
            return -1;
        }

        return $this->selected;
    }

    /**
     * 段孩子 —— **每次调用建一批新 `Segment`**（`build()`  idiom，不是缓存）。
     *
     * ★ 不能在建构器里建一次存着：`->selected()` / `->onChanged()` 都在
     *   `make()` **之后**调，缓存的孩子拿的是过期值，且不报错。
     * ★ 新实例 + 无 `Key` ⇒ `updateChildren()` 按位置复用 Element（焦点不丢），
     *   与 `StatelessWidget::build()` 每遍返新子树同一条路。
     *
     * @return list<Widget>
     */
    public function kidList(): array
    {
        $selected = $this->selectedIndex();
        $kids = [];
        $index = 0;

        foreach ($this->labels as $label) {
            $segment = Segment::make($label, $index)
                ->selected($index === $selected)
                ->enabled(!$this->mods()->disabled());

            if ($this->onChanged !== null) {
                $handler = $this->onChanged;
                $segment->onChanged(static function () use ($handler, $index): void {
                    $handler($index);
                });
            }

            $kids[] = $segment;
            $index++;
        }

        return $kids;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        $style = new SegmentedStyle(
            $context->color(ColorToken::Chrome),
            $context->color(ColorToken::Border),
            $context->metric(MetricToken::BorderWidth),
            SegmentedStyle::DEFAULT_GAP,
            SegmentedStyle::DEFAULT_INSET,
            $context->color(ColorToken::Accent),
            $context->color(ColorToken::Surface),
            $context->color(ColorToken::Text),
            $context->textSize(TextRole::Body),
        );

        return new RenderSegmentedControl($style);
    }
}
