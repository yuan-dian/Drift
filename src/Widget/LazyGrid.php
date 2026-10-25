<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\EdgeInsets;

/**
 * **按视口造行**的等宽网格（`Grid` 的懒版，W8-3）。
 *
 * ```php
 * LazyGrid::make(3, 96.0)                                  // 3 列、行节距 96
 *     ->spacing(12.0)                                      // 列间距 + 行间距
 *     ->items($cards, fn(Card $c): Widget => CardView::make($c));
 * ```
 *
 * ## 它不是新的渲染原语，而是 `LazyColumn` 套 `Grid`
 *
 * `LazyColumn` 已经解决"按滚动偏移只造视口覆盖的那几行"；`Grid` 已经解决"切块 +
 * 末行补 `Spacer`"。懒网格 = **把 items 先切块，每块造一个 `Row`**，交给
 * `LazyColumn` 当"一行" —— 视口计算、锚点恢复、`overscan`、`keyOf`、滚动全部
 * 继承，本类只做**分块**这一件事。
 *
 * ★ **分行规则与全量版共用 `Grid::chunk()` / `Grid::rowOf()`**：同一个页面在
 *   数据量变化时切换两种实现，格子宽必须逐像素一致，不能看见跳动。
 *
 * ## 行节距（`rowHeight`）是必填的，与 `LazyColumn::itemExtent` 同一条纪律
 *
 * 定高才能 O(1) 算出"第几行到第几行可见"。**它含行间距**（是"节距 / pitch"，
 * 不是"格子高"）：格子高 = `rowHeight − rowSpacing`。所以
 * `LazyGrid::make(3, 96.0)->spacing(12.0)` ⇒ 格子 84 高、行间空 12、每行占 96。
 *
 * ## 诚实的边界
 *
 * - **行距靠节距里的留白**，与 `LazyColumn` 的 `itemExtent` 完全一致（那边也没有
 *   独立的行间距参数）。想"卡片之间空 12"就 `spacing(12.0)`，别指望它长在行外面。
 * - **格子内容超过格子高会被裁**（`SizedBox` 紧高），与 `itemExtent` 同语义。
 * - **行内不能有"随滚动保留的 UI 态"**：行 Element 滚出窗口即回收（虚拟化的定义）。
 * - 跨列 / 自动列数 / 竖向网格：同 `Grid` 的边界，不做。
 *
 * @api
 */
final class LazyGrid extends StatelessWidget
{
    private float $columnSpacing = 0.0;

    private float $rowSpacing = 0.0;

    /** @var list<mixed> */
    private array $items = [];

    private ?\Closure $cellBuilder = null;

    private ?\Closure $keyMapper = null;

    private float $overscanPx = 0.0;

    private ?EdgeInsets $insets = null;

    private function __construct(
        private readonly int $columns,
        private readonly float $rowHeight,
    ) {
        parent::__construct();
    }

    /**
     * @param int $columns 列数（≥ 1）
     * @param float $rowHeight 行**节距**（含行间距；必须 > 0）
     */
    public static function make(int $columns, float $rowHeight): self
    {
        if ($columns < 1) {
            Errors::report(\sprintf(
                'LazyGrid 的列数必须 ≥ 1（收到 %d）—— 列数是切块的分母',
                $columns,
            ));
        }

        if ($rowHeight <= 0.0) {
            // 与 LazyColumn::make() 同一条守卫：行节距是窗口计算的除数
            Errors::report(\sprintf(
                'LazyGrid 的行节距必须 > 0（收到 %s）—— 它是窗口计算的除数',
                (string) $rowHeight,
            ));
        }

        return new self($columns, $rowHeight);
    }

    /** 列间距与行间距同值（行间距从行节距里扣） */
    public function spacing(float $value): static
    {
        $this->mutate();
        $this->columnSpacing = $value;
        $this->rowSpacing = $value;

        return $this;
    }

    public function columnSpacing(float $value): static
    {
        $this->mutate();
        $this->columnSpacing = $value;

        return $this;
    }

    /** 行间距：从行节距里扣掉（`格子高 = 节距 − 行间距`） */
    public function rowSpacing(float $value): static
    {
        $this->mutate();
        $this->rowSpacing = $value;

        return $this;
    }

    /**
     * @param list<mixed> $items
     * @param callable(mixed): Widget $builder
     */
    public function items(array $items, callable $builder): static
    {
        $this->mutate();
        $this->items = $items;
        $this->cellBuilder = \Closure::fromCallable($builder);

        return $this;
    }

    /**
     * 每行的身份 key（`fn(mixed $item): string|int`）—— 内容变了之后保持滚动位置。
     *
     * ★ 传的是**该行第一个 item**，不是"一行"：应用只知道 item 的身份，不知道
     *   分块。语义 = "行的身份 = 行首项的身份"（`Grid` 的切块是纯函数 ⇒ 同一批
     *   items 切出来的行首项稳定）。
     */
    public function keyOf(callable $keyOf): static
    {
        $this->mutate();
        $this->keyMapper = \Closure::fromCallable($keyOf);

        return $this;
    }

    /** 视口外多造多少像素的行（默认 0），透传给 `LazyColumn` */
    public function overscan(float $px): static
    {
        $this->mutate();
        $this->overscanPx = $px;

        return $this;
    }

    /** 内边距，透传给 `LazyColumn`（上下进总长度、左右每行让开） */
    public function padding(EdgeInsets $value): static
    {
        $this->mutate();
        $this->insets = $value;

        return $this;
    }

    public function columns(): int
    {
        return $this->columns;
    }

    public function rowHeight(): float
    {
        return $this->rowHeight;
    }

    /** 格子高 = 行节距 − 行间距（`> 0` 由 `build()` 守卫） */
    public function cellHeight(): float
    {
        return $this->rowHeight - $this->rowSpacing;
    }

    public function build(BuildContext $ctx): Widget
    {
        if ($this->cellBuilder === null) {
            Errors::report('LazyGrid 没有 builder —— 必须调 items($items, $builder)');
        }

        $cellHeight = $this->cellHeight();
        if ($cellHeight <= 0.0) {
            Errors::report(\sprintf(
                'LazyGrid 的行节距 %s 减去行间距 %s 后没有剩余高度（格子高 %s）'
                    . ' —— 行节距必须大于行间距',
                (string) $this->rowHeight,
                (string) $this->rowSpacing,
                (string) $cellHeight,
            ));
        }

        // ★ 只在 build() 里取局部量，闭包**不捕获 `$this`**：`LazyColumn` 会被留在
        //   渲染树上，闭包若捕获本 Widget 就会把整个（可能已过期的）构建期对象
        //   一起留住（`Widget` 不可变 ⇒ 留住的是一份不再更新的快照）。
        $builder = $this->cellBuilder;
        $columns = $this->columns;
        $columnSpacing = $this->columnSpacing;
        $chunks = Grid::chunk($this->items, $columns);

        $column = LazyColumn::make($this->rowHeight)
            ->items($chunks, function (array $chunk) use ($builder, $columns, $columnSpacing, $cellHeight): Widget {
                $cells = [];
                foreach ($chunk as $item) {
                    $cells[] = $builder($item);
                }

                // ★ 与全量版同一份分行规则（含末行补 `Spacer`）—— 末行不满时格宽
                //   仍与首行相同；不共用就会漂移。
                return Grid::rowOf($cells, $columns, $columnSpacing, $cellHeight);
            })
            ->overscan($this->overscanPx);

        $keyMapper = $this->keyMapper;
        if ($keyMapper !== null) {
            // ★ 不写 `: string|int` 返回类型：`LazyListState` 那边本来就 `(string)`
            //   强转，而**闭包的联合返回类型**在 AOT 侧没有先例（`Key` 的联合类型是
            //   构造形参，不是返回值）⇒ 不为一个装饰性的类型标注赌 codegen。
            $column->keyOf(function (array $chunk) use ($keyMapper) {
                return $keyMapper($chunk[0]);
            });
        }

        if ($this->insets !== null) {
            $column->padding($this->insets);
        }

        return $column;
    }
}
