<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 多页签容器：一条页签条 + 一个只显示当前页的内容区。
 *
 * 每页都套 `Offstage`，只把选中页留在树里 —— 切走再切回，滚动位置、
 * 表单草稿等 State 原样保留（保活靠 `Offstage`，不靠缓存页面 Widget）。
 * 受控控件：`selected()` 设当前页，`onChanged()` 接变化回调；不给回调页签点不动。
 * ★ 自己没有渲染盒：别套 `padding()` / `background()`，不产盒的控件声明修饰会报错。
 * 越界 `selected` ⇒ 全不选（内容区空）。
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
     * ★ 两表必须同步：只写了标签忘写页面 ⇒ 越界 ⇒ 内容区空白且不报错。
     * 这里只有一个写入口，结构上没法只写一半。
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
     * 不给 ⇒ 页签点不动（受控控件语义：`Segment` 没有 `onChanged` 就不响应激活）。
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

        // 每页套 `Offstage`：只把选中页留在树里，其余 Element / State 一直挂着 ⇒ 切回原样。
        // ★ 换成"只放选中页"就退化成换页即丢状态（滚动、表单草稿全没）。
        // 套 `Expanded` 让内容区吃满剩余高度；否则切页签时面板会跳。
        $count = \count($this->pages);
        for ($i = 0; $i < $count; ++$i) {
            $kids[] = Offstage::make(Expanded::make($this->pages[$i]), $i === $selected);
        }

        return Column::make()->children($kids);
    }
}
