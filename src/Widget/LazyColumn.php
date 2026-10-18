<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\EdgeInsets;

/**
 * **按视口造子层**的纵向列表（虚拟化，§13.1 / M5）。
 *
 * ```php
 * LazyColumn::make(56.0)                                  // 每行 56 逻辑像素
 *     ->items($users, fn(User $u): Widget => UserRow::make($u))
 *     ->overscan(56.0)                                    // 视口外多造一行
 * ```
 *
 * ★ **与 `Each` 的分工**：`Each` 是"全量列表" —— `items()` 在构建期把**每个**
 *   item 造成一个 Widget，1000 行 ⇒ 1000 个实例、1000 份布局。`LazyColumn`
 *   只造**视口覆盖到的那几行**，其余行既没有 Widget 也没有 Element 也没有盒。
 *   实测（`_build/bench-list3.php`，Card+Row 两段文本的行）：
 *
 *   | 行数 | `Each` 单帧（mount+layout） | `LazyColumn`（约 12 行可见） |
 *   |---|---|---|
 *   | 200 | ~17ms | ~2ms |
 *   | 1000 | ~96ms | ~2ms |
 *   | 2000 | ~200ms | ~2ms |
 *
 *   ⇒ 全量列表的成本**随行数线性增长**（约 0.076ms/行），虚拟列表与行数**无关**。
 *
 * ★★ **`itemExtent` 是必填的，不是可选的**：定高才能 O(1) 算出"第几行到第几行
 *   可见"（`first = floor(offset / extent)`）。变高行需要一份行高测量缓存 +
 *   数据变化时的失效策略 —— 那是另一个原语，今天**没有真实消费者**逼它
 *   （§24.2 #25 的教训：未被执行的分支等于未实现）。行高由 `SizedBox` 强制，
 *   内容比它高会被裁、比它矮会留白 —— 与 Flutter 的 `itemExtent` 同语义。
 *
 * ★★ **它自己就是滚动容器**（不是塞进 `ScrollView` 里）：窗口取决于滚动偏移，
 *   偏移取决于视口，两者都在它这里。⇒ 用法上**替换** `ScrollView + Column + Each`，
 *   而不是与它们叠加。塞进外层 `ScrollView` 会拿到无界高度 ⇒ 窗口无法计算
 *   （`RenderScrollView` 会响亮报错，见下）。
 *
 * ★ **行内不能有"随滚动保留的 UI 态"**：行 Element 在滚出窗口时被回收（这正是
 *   虚拟化的定义）。行内要跨滚动保留的东西放 Store（§12.3）。
 *
 * @api
 */
final class LazyColumn extends StatefulWidget
{
    /** 默认"视口外多造几行"= 0：窗口恰好覆盖视口，翻页时靠下一帧补齐 */
    private const DEFAULT_OVERSCAN = 0.0;

    /** @var list<mixed> */
    private array $items = [];

    private ?\Closure $builder = null;

    private float $overscanPx = self::DEFAULT_OVERSCAN;

    private EdgeInsets $insets;

    private function __construct(private readonly float $itemExtent)
    {
        parent::__construct();
        $this->insets = EdgeInsets::zero();
    }

    /**
     * @param float $itemExtent 每行的高度（逻辑像素，**必须 > 0**）
     *
     * ★ 0 / 负数 ⇒ 构造期就响亮失败：`first = floor(offset / 0)` 是除零，
     *   而"每行 0 高"本身也没有意义（永远只看得到第 0 行）。
     */
    public static function make(float $itemExtent): self
    {
        if ($itemExtent <= 0.0) {
            Errors::report(sprintf(
                'LazyColumn 的 itemExtent 必须 > 0（收到 %s）—— 行高是窗口计算的除数',
                (string) $itemExtent,
            ));
        }

        return new self($itemExtent);
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
     * 视口外多造多少像素的行（默认 0）。
     *
     * ★ 用途只有"滚动时少一帧白边"：窗口是按**上一帧**的视口算的，视口尺寸
     *   变化（窗口 resize / 布局变化）时那一帧会短一截。overscan ≥ 一行的行高
     *   就能盖住。**不做**"预测滚动方向"那类提前量 —— 没有消费者。
     */
    public function overscan(float $px): static
    {
        $this->mutate();
        $this->overscanPx = max(0.0, $px);

        return $this;
    }

    /**
     * 内边距（逻辑像素）。★ 与 `Column::padding()` 的差别只在**上下**：
     * 上内边距是"完整内容"的一部分（进 `sliceOrigin`），下内边距同理进总长度；
     * 左右是每行都要让开的横向空间。
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

    /** @return list<mixed> */
    public function itemsList(): array
    {
        return $this->items;
    }

    /** @internal 窗口内逐行调；应用只经 `items()` 递进来 */
    public function buildItem(mixed $item): Widget
    {
        if ($this->builder === null) {
            Errors::report('LazyColumn 没有 builder —— 必须调 items($items, $builder)');
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

    public function createState(): State
    {
        return new LazyColumnState();
    }
}
