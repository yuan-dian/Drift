<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\EdgeInsets;
use Drift\Input\SemanticsRole;

/**
 * **按视口造子层**的虚拟化列表基类（`LazyColumn` / `LazyRow` 的共同部分）。
 * 主轴 = 内容变长的那根轴（列 → y，行 → x），交叉轴永远是另一根；
 * 随轴而变的量都收在 `LazyListState`，基类本身只有 `vertical` 一个布尔。
 * ★ **`itemExtent` 是必填的，不是可选的**：定长才能 O(1) 算"第几个到第几个可见"
 *   （`first = floor(offset / extent)`）。变长子项需测量缓存 + 失效策略，是另一个
 *   原语；子项主轴尺寸由 `SizedBox` 强制，比它大被裁、比它小留白。
 * ★ **它自己就是滚动容器**（不塞进 `ScrollView`）：窗口取决于滚动偏移、偏移取决于
 *   视口，两者都在它这里。用法上**替换** `ScrollView + Column/Row + Each`，叠加外层
 *   滚动视口会拿到无界主轴 ⇒ 窗口无法计算。
 * ★ **子项内不能有"随滚动保留的 UI 态"**：子项 Element 滚出窗口时被回收（虚拟化的
 *   定义），要跨滚动保留的东西放 Store。
 * @api
 */
abstract class LazyList extends StatefulWidget
{
    /** 默认"视口外多造几格"= 0：窗口恰好覆盖视口，翻页时靠下一帧补齐 */
    private const DEFAULT_OVERSCAN = 0.0;

    /** @var list<mixed> */
    private array $items = [];

    private ?\Closure $builder = null;

    /** 每个子项的**身份 key**（`null` = 没配，锚点只能按序号夹） */
    private ?\Closure $keyMapper = null;

    private float $overscanPx = self::DEFAULT_OVERSCAN;

    private float $initialOffsetPx = 0.0;

    private float $viewportPx = 0.0;

    private EdgeInsets $insets;

    /**
     * @param float $itemExtent 每格的主轴尺寸（逻辑像素，**必须 > 0**）
     * @param bool  $vertical   true = 纵向（`LazyColumn`）/ false = 横向（`LazyRow`）
     */
    protected function __construct(
        private readonly float $itemExtent,
        private readonly bool $vertical,
    ) {
        parent::__construct();
        $this->insets = EdgeInsets::zero();
        // 虚拟列表也是一种"可滚动区域"，与 `ScrollView` 同角色，故标注滚动语义
        $this->semantics(SemanticsRole::Scroll);
    }

    /** 校验 `itemExtent`（两个子类的 `make()` 共用）。 */
    protected static function checkExtent(float $itemExtent): void
    {
        if ($itemExtent <= 0.0) {
            Errors::report(sprintf(
                '%s 的 itemExtent 必须 > 0（收到 %s）—— 它是窗口计算的除数',
                static::class,
                (string) $itemExtent,
            ));
        }
    }

    /**
     * @param list<mixed> $items
     * @param callable(mixed): Widget $builder
     */
    public function items(array $items, callable $builder): static
    {
        $this->mutate();
        $this->items = $items;
        $this->builder = \Closure::fromCallable($builder);

        return $this;
    }

    /**
     * 每个子项的**身份 key**（`fn(mixed $item): string|int`）—— 内容变化后保持滚动位置用。
     * 不配也能跑，但"搜索把 10000 项筛成 30 项"时会跳：锚点只能按**序号**夹，筛选后
     * 第 500 项已不是原来那一项（框架无法凭空知道两批数据里哪一项相同）。
     * 只用于锚点：子项 Element 的 key 仍是**绝对序号**（身份 key 是另一个话题）。
     * key **必须唯一**（同一份 items 内）：锚点查找取第一个命中的，重复 key 的代价是
     * 锚点可能落到另一个同 key 的项，不会崩。
     */
    public function keyOf(callable $keyOf): static
    {
        $this->mutate();
        $this->keyMapper = \Closure::fromCallable($keyOf);

        return $this;
    }

    /** @internal 锚点保持用（`null` = 没配） */
    public function keyMapper(): ?\Closure
    {
        return $this->keyMapper;
    }

    /**
     * 视口外多造多少像素（默认 0）。
     * 用途只有"滚动时少一帧白边"：窗口按**上一帧**视口算，视口尺寸变化那一帧会短一截，
     * overscan ≥ 一格尺寸就能盖住。
     */
    public function overscan(float $px): static
    {
        $this->mutate();
        $this->overscanPx = max(0.0, $px);

        return $this;
    }

    /**
     * **初始**滚动偏移（逻辑像素，`0` = 从第一项开始）。
     * 打开列表就落在第 N 项上用（如 `TimeView` 打开时看得见 09 点）。只收像素不收序号，
     * 因为它要能表达"第 3 项滚了一半"这种位置。
     * **只读一次**（`LazyListState::initState()`）：之后改它没有效果，运行期改位置走
     * `scrollBy()` / `ensureVisible()`。
     */
    public function initialOffset(float $px): static
    {
        $this->mutate();
        $this->initialOffsetPx = max(0.0, $px);

        return $this;
    }

    /**
     * 声明"我的视口沿主轴是 N 像素"（`0` = 不声明，退回量盒 / 窗口）。
     * 只在"外层给的主轴是无界的"时需要：`BuildContext` 主轴在弹层里是无限大 ⇒
     * 窗口换算会把全部格子造出来（虚拟化静默失效）。不能用 `Widget::height()` 顶替
     * （本类不产渲染盒，`Element::syncBox()` 会响亮失败）。优先级低于上一帧盒的实测值。
     */
    public function viewportExtent(float $px): static
    {
        $this->mutate();
        $this->viewportPx = max(0.0, $px);

        return $this;
    }

    /** @internal */
    public function viewportExtentPx(): float
    {
        return $this->viewportPx;
    }

    /** @internal */
    public function initialOffsetPx(): float
    {
        return $this->initialOffsetPx;
    }

    /**
     * 内边距（逻辑像素）。与 `Column::padding()` 的差别只在**主轴两端**：主轴起始
     * 内边距是"完整内容"的一部分（进 `sliceOrigin`），末尾进总长度；交叉轴两端是每格
     * 都要让开的空间。故同一个 `padding()` 在纵向与横向下"进哪一侧"不同。
     */
    public function padding(EdgeInsets $value): static
    {
        $this->mutate();
        $this->insets = $value;

        return $this;
    }

    public function itemExtent(): float
    {
        return $this->itemExtent;
    }

    /** 滚动主轴（纵向 = 内容沿 y 变长） */
    public function isVertical(): bool
    {
        return $this->vertical;
    }

    /** 报错文案用的人话名字（`LazyColumn` / `LazyRow`） */
    public function kindName(): string
    {
        return $this->vertical ? 'LazyColumn' : 'LazyRow';
    }

    /** @return list<mixed> */
    public function itemsList(): array
    {
        return $this->items;
    }

    /** @internal 窗口内逐项调；应用只经 `items()` 递进来 */
    public function buildItem(mixed $item): Widget
    {
        if ($this->builder === null) {
            Errors::report($this->kindName() . ' 没有 builder —— 必须调 items($items, $builder)');
        }

        return ($this->builder)($item);
    }

    public function overscanPx(): float
    {
        return $this->overscanPx;
    }

    public function insets(): EdgeInsets
    {
        return $this->insets;
    }
}
