<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 多页签容器（§12.1 第 4/7 条）：一条页签条 + 一个**只显示当前页**的内容区。
 *
 * ```php
 * TabView::make()
 *     ->tab('概览', OverviewPage::make())
 *     ->tab('明细', DetailPage::make())
 *     ->selected($this->tab)
 *     ->onChanged(fn(int $i) => $this->setState(fn() => $this->tab = $i));
 * ```
 *
 * ## 它真正解决的不是"画页签"，是"切走再切回状态还在"
 *
 * 换页最笨的写法是把选中页直接塞进容器：
 *
 * ```php
 * Column::make()->children([$strip, $pages[$selected]])   // ← 别这样
 * ```
 *
 * 切一次页 ⇒ 旧页的 Element 被 unmount ⇒ State 被 dispose ⇒ **滚动位置、表格选中、
 * 表单草稿全没了**。应用自己绕要手搓一堆 `Offstage`（`apps/showcase` 的
 * `FeedbackPage::panel()` 今天就在这么干）。
 *
 * 本控件把这件事收口：**每一页都套 `Offstage`，只把选中那页留在树里**。隐藏页不
 * layout / 不 paint / flush 也走不到，但 Element 与 State 一直挂着 ⇒ 切回来原样。
 *
 * ★ 与 `PageHost`（`src/Routing/`）是**同一套机制**的两个入口：
 *
 * | | `PageHost` | `TabView` |
 * |---|---|---|
 * | 键 | 页名 `string`（来自 `RouteTable`） | 序号 `int`（来自 `->tab()` 的顺序） |
 * | 谁来选 | 订阅 `Subject`（外部 Store 驱动） | **受控**：`selected()` + `onChanged()` |
 * | 谁来造页 | 工厂 + 在 State 里缓存 | 应用直接给 |
 *
 *   两者**不共用代码**（共同部分只是那个三行的 `Offstage` 循环 —— 抽出来只会更难读），
 *   但**共用同一条契约**：保活靠 `Offstage`，不靠"缓存页面 Widget"。
 *
 * ★ **为什么不像 `PageHost` 那样缓存页面 Widget**：`PageHost` 缓存是因为它有**工厂**
 *   （`RouteTable::build()` 每次调用都可能返回一个带新参数的新实例）。`TabView` 没有
 *   工厂 —— 页面是应用当作**描述**给的，新实例只是"重新描述一遍"；`OffstageElement`
 *   对停着的 Element 做 `update()`，而 **State 挂在 Element 上，不随 Widget 走**。
 *
 * ## 三个岔口的裁决
 *
 * | 岔口 | 裁决 | 理由 |
 * |---|---|---|
 * | 受控 vs 自持 | **受控**（与 `SegmentedControl` / `Checkbox` 同纪律） | 自持 ⇒ "屏幕上哪一页"与"应用以为的当前页"变成两份真相 |
 * | 自己当容器 vs 组合 | **`StatelessWidget`**，不新增 Element / RenderBox | 没有局部 UI 态；它只是"条 + 保活内容区"这个组合 |
 * | 页签条 | 复用 `SegmentedControl`（v1） | 单选 + 每段一个焦点 + `activate()` 三件事都现成且已测 |
 *
 * ## 有意的边界（不是缺陷，别当 bug 报）
 *
 * - **页签条就是 `SegmentedControl`**：等宽胶囊。真 TabBar（内容宽度 + 与内容区相连
 *   的指示条）没做 —— 需要的人先提，别在这里等。
 * - **每个页签都是 Tab 序里的一站**（`SegmentedControl` 的既有裁决）。ARIA 惯例是
 *   "整条一个焦点站 + 方向键切页签"，那是键盘子系统的活（W8-4），本控件不自己拦方向键。
 * - **越界 `selected` ⇒ 全不选**（内容区空），与 `SegmentedControl` 同裁决：静默选中
 *   第一项是替用户做决定。
 * - **内容区占满剩余高度**（每页套 `Expanded`）。页面内部想 hug 照样 hug —— 盒给足了，
 *   内容撑不撑满是页面自己的选择。
 *
 * ★ **`TabView` 自己没有渲染盒**（与 `PageHost` 同形态，靠 §13.2 `effectiveBox()`
 *   向下穿透）。⇒ 别给它套 `padding()` / `background()`：不产盒的控件声明修饰会
 *   **响亮失败**（`Element` 挂载时报错并给出修法）。要留白 / 背景 ⇒ 套 `Card`，
 *   或在页面里做。
 *
 * @api
 */
final class TabView extends StatelessWidget
{
    /** @var list<string> 页签标签 */
    private array $labels = [];

    /** @var list<Widget> 页面（与 `$labels` **一一对应**） */
    private array $pages = [];

    private int $selected = 0;

    private ?\Closure $onChanged = null;

    private function __construct()
    {
        parent::__construct();
    }

    /** @api */
    public static function make(): self
    {
        return new self();
    }

    /**
     * 加一个页签（标签 + 页面**成对**）。
     *
     * ★ 为什么是"一个方法吃一对"而不是 `->labels([...])->pages([...])`：
     *   两张表是**能失同步**的（加标签忘加页面 ⇒ 越界 ⇒ 内容区空白，还不报错）。
     *   这里只有**一个写入口**，结构上就没法只写一半。
     */
    public function tab(string $label, Widget $page): static
    {
        $this->mutate();
        $this->labels[] = $label;
        $this->pages[] = $page;

        return $this;
    }

    /** 选中序号（越界 ⇒ 全不选；见类头） */
    public function selected(int $index): static
    {
        $this->mutate();
        $this->selected = $index;

        return $this;
    }

    /**
     * 选中变化回调：收**新序号**（不是"切换了"这个事件）。
     *
     * ★ 不给 ⇒ 页签点不动（受控控件的既有语义：`Segment` 没有 `onChanged` 就不响应
     *   激活）。这不是"忘了接"，是"没人接手这个变化"。
     */
    public function onChanged(callable $handler): static
    {
        $this->mutate();
        $this->onChanged = \Closure::fromCallable($handler);

        return $this;
    }

    /** 生效的选中序号；`-1` = 全不选（越界 / 空表） */
    public function selectedIndex(): int
    {
        if ($this->selected < 0 || $this->selected >= \count($this->labels)) {
            return -1;
        }

        return $this->selected;
    }

    /** @return list<string> */
    public function labels(): array
    {
        return $this->labels;
    }

    /** @return list<Widget> */
    public function pages(): array
    {
        return $this->pages;
    }

    public function tabCount(): int
    {
        return \count($this->labels);
    }

    public function build(BuildContext $ctx): Widget
    {
        $selected = $this->selectedIndex();

        $strip = SegmentedControl::make($this->labels)->selected($this->selected);
        if ($this->onChanged !== null) {
            $strip->onChanged($this->onChanged);
        }

        $kids = [$strip];

        // ★ 每一页都套 `Offstage` —— 保活的全部机制就在这一行。
        //   换成"只放选中页"就退化成换页即丢状态（类头讲的那件事）。
        // ★ `Expanded` 让内容区吃掉剩余高度：不给的话每页高度跟着内容变，
        //   切页签时整个面板会跳。
        $count = \count($this->pages);
        for ($i = 0; $i < $count; ++$i) {
            $kids[] = Offstage::make(Expanded::make($this->pages[$i]), $i === $selected);
        }

        return Column::make()->children($kids);
    }
}
