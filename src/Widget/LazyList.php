<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\EdgeInsets;
use Drift\Input\SemanticsRole;

/**
 * **按视口造子层**的虚拟化列表基类（`LazyColumn` / `LazyRow` 的共同部分）。
 *
 * ★ **为什么有基类而不是两个控件各写一份**：虚拟化的难点全在 `LazyListState`
 *   里 —— 窗口换算、锚点保持、`sliceOrigin` 与 `offset` 的差、可滚上限按完整
 *   内容算。这一族每一条都踩过真机（见 `04` §7.3）。两份拷贝意味着每条修一次
 *   得改两处，而"两处各写一遍必然漂"是本仓的老账（`LazyGrid` 与 `Grid` 的
 *   `rowOf()` 就是为这条才共用）。⇒ 轴是**一个参数**，不是一次分叉。
 *
 * ★ **主轴 = 内容变长的那根轴**（列 → y，行 → x）；交叉轴永远是"另一根"。
 *   所有随轴而变的量都收在 `LazyListState` 的三个局部（`$start` / `$extent` /
 *   `$viewport`），基类本身只有 `vertical` 一个布尔。
 *
 * ★★ **`itemExtent` 是必填的，不是可选的**：定长才能 O(1) 算出"第几个到第几个
 *   可见"（`first = floor(offset / extent)`）。变长子项需要一份测量缓存 + 数据
 *   变化时的失效策略 —— 那是另一个原语，今天**没有真实消费者**逼它
 *   （§24.2 #25 的教训：未被执行的分支等于未实现）。子项主轴尺寸由 `SizedBox`
 *   强制，内容比它大被裁、比它小留白 —— 与 Flutter 的 `itemExtent` 同语义。
 *
 * ★★ **它自己就是滚动容器**（不是塞进 `ScrollView` 里）：窗口取决于滚动偏移，
 *   偏移取决于视口，两者都在它这里。⇒ 用法上**替换** `ScrollView + Column/Row +
 *   Each`，而不是与它们叠加。塞进外层滚动视口会拿到无界主轴 ⇒ 窗口无法计算
 *   （`RenderScrollView` 会响亮报错，见其 `performLayout()`）。
 *
 * ★ **子项内不能有"随滚动保留的 UI 态"**：子项 Element 在滚出窗口时被回收
 *   （这正是虚拟化的定义）。子项里要跨滚动保留的东西放 Store（§12.3）。
 *
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
        // §9 无障碍：虚拟列表也是一种"可滚动区域"，与 `ScrollView` 同角色
        $this->semantics(SemanticsRole::Scroll);
    }

    /**
     * 校验 `itemExtent`（两个子类的 `make()` 共用）。
     *
     * ★ 0 / 负数 ⇒ 构造期就响亮失败：`first = floor(offset / 0)` 是除零，
     *   而"每格 0 长"本身也没有意义（永远只看得到第 0 格）。
     * ★ 文案用 `static::class` 而不是写死名字 —— 子类改名/新增不必回来改这里，
     *   报错也不会指错控件（这是"报错要指对人"的同一族纪律）。
     */
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
     * 每个子项的**身份 key**（`fn(mixed $item): string|int`）—— 内容变了之后
     * 保持滚动位置用（`04` §7.3）。
     *
     * ★ **不配也能跑**，但"搜索把 10000 项筛成 30 项"时会跳：锚点只能按**序号**
     *   夹，而筛选后第 500 项已经不是原来那一项。这是**诚实的边界** —— 框架不能
     *   凭空知道"两批数据里哪一项是同一项"。
     *
     * ★ **只用于锚点**：子项 Element 的 key 仍然是**绝对序号**。改成身份 key 是
     *   Element 级 diff（跨内容复用子项 Element）的另一个话题，本步不做 —— 它不
     *   影响锚点，因为锚点是在算窗口**之前**恢复的。
     *
     * ★ key **必须唯一**（同一份 items 内）：锚点查找取第一个命中的。不校验是
     *   因为校验要 O(n)、而它在滚动路径上；重复 key 的代价是"锚点可能落到
     *   另一个同 key 的项"，不会崩。
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
     *
     * ★ 用途只有"滚动时少一帧白边"：窗口是按**上一帧**的视口算的，视口尺寸
     *   变化（窗口 resize / 布局变化）时那一帧会短一截。overscan ≥ 一格的尺寸
     *   就能盖住。**不做**"预测滚动方向"那类提前量 —— 没有消费者。
     */
    public function overscan(float $px): static
    {
        $this->mutate();
        $this->overscanPx = max(0.0, $px);

        return $this;
    }

    /**
     * 内边距（逻辑像素）。★ 与 `Column::padding()` 的差别只在**主轴两端**：
     * 主轴起始内边距是"完整内容"的一部分（进 `sliceOrigin`），末尾同理进总长度；
     * 交叉轴两端是每一格都要让开的空间。
     *
     * ★ 所以同一个 `padding()` 在纵向与横向下"进哪一侧"是不同的 —— 这不是含糊，
     *   而是"内边距属于内容"这条的必然结果（内容沿哪根轴变长，那根轴的两端就是
     *   内容的一部分）。
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
