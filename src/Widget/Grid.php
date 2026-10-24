<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;

/**
 * 等宽网格（W8-3 布局广度）。
 *
 * ```php
 * Grid::make(3)                        // 3 列
 *     ->spacing(12.0)                  // 列间距 + 行间距同值
 *     ->cellHeight(96.0)               // 可选：行高统一（不给则按内容）
 *     ->children([Card::make(...), ...]);
 * ```
 *
 * ## 为什么它是**组合**而不是新的渲染原语
 *
 * 方案册 `04` §4.4 否决过 `Wrap` / `Grid`，理由是"需要两遍布局 + 布局迭代"。
 * 那条理由对 **`Wrap` 成立、对 `Grid` 不成立** —— 差别在于"一行放几个"由谁决定：
 *
 * | 原语 | 一行放几个 | 需要什么 |
 * |---|---|---|
 * | `Wrap` | **测量之后才知道**（按子项自然宽贪心累加）| 两遍布局：先量全部子，再分行 |
 * | `Grid` | **应用给的**（`columns`）| 一个除法：`(可用宽 − 间距) / 列数` |
 *
 * 而"等宽列"这个除法 `Row` + `Expanded` 已经做了（`RenderFlex` 把剩余宽度按
 * `grow` 均分）。所以本类**没有渲染盒、没有 Element、没有布局迭代**：`build()`
 * 把子项切块，每块造一个 `Row`，格子用 `Expanded` 包住。整棵树里没有一个新节点
 * 类型 ⇒ 命中测试、裁剪、内禀尺寸、diff、AOT 全部沿用既有路径。
 *
 * ## 它补的是哪个洞：**末行不满**
 *
 * 手写 `Column` 套 `Row` 时，末行格子数不足会让 `Expanded` 把剩余宽度均分给
 * **剩下的那几个** ⇒ 末行的卡片比别的行宽。这个错很安静（画面"只是有点不对"），
 * 只有在"最后一行不满"时才出现，而数据量恰恰经常让它不满。
 *
 * 所以本类做两件事：**切块** + **末行补 `Spacer` 占位**。后者是它存在的全部理由。
 *
 * ## 诚实的边界
 *
 * - **不做跨列**（`columnSpan`）：那需要"跨列约束传播 + 回溯"，正是 §4.4 否决
 *   `Grid` 的那条理由。等真有需求再按 S3 仲裁开这个口子。
 * - **不做自动列数**（按可用宽 ÷ 最小格宽）：那就是 `Wrap` 的语义，走 `04` §4.4
 *   的推翻条件，不从这里偷偷引入。
 * - **不做竖向网格**（"列数"换成"行数"）：今天没有消费者，未被执行的分支等于未实现。
 * - **行高默认不统一**：不给 `cellHeight()` 时，每行高度 = 该行最高格（行内齐、
 *   跨行不齐）。要整齐的卡片墙就显式给 `cellHeight()`。
 *
 * @api
 */
final class Grid extends StatelessWidget
{
    private float $columnSpacing = 0.0;

    private float $rowSpacing = 0.0;

    /** `null` = 行高由内容决定（行内对齐到最高者） */
    private ?float $cellHeight = null;

    /** @var list<Widget> */
    private array $kids = [];

    private function __construct(private readonly int $columns)
    {
        parent::__construct();
    }

    /**
     * @param int $columns 列数（**必填**，与 `LazyColumn::itemExtent` 同一条纪律：
     *        列数是布局的唯一参数，写个默认值只会让"忘了给"变成"列数不对"）
     */
    public static function make(int $columns): self
    {
        if ($columns < 1) {
            // G1：0 列没有格子可放；负数同理。构造期响亮失败，不留到布局期。
            Errors::report(\sprintf(
                'Grid 的列数必须 ≥ 1（收到 %d）—— 列数是切块的分母',
                $columns,
            ));
        }

        return new self($columns);
    }

    /** 列间距与行间距同值（最常用；要分开调就 `columnSpacing()` / `rowSpacing()`） */
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

    public function rowSpacing(float $value): static
    {
        $this->mutate();
        $this->rowSpacing = $value;

        return $this;
    }

    /**
     * 行高统一（逻辑像素）。不给 ⇒ 每行按该行内容高（行内齐、跨行不齐）。
     *
     * ★ 住 `SizedBox` 的 `mods.height`（紧约束）⇒ 格子高度**恰好**这么多，
     *   内容比它高会被裁（与 `LazyColumn` 的 `itemExtent` 同语义）。
     */
    public function cellHeight(float $value): static
    {
        $this->mutate();
        $this->cellHeight = $value;

        return $this;
    }

    /** @param list<Widget> $kids */
    public function children(array $kids): static
    {
        $this->mutate();
        $this->kids = $kids;

        return $this;
    }

    public function columns(): int
    {
        return $this->columns;
    }

    public function build(BuildContext $ctx): Widget
    {
        $rows = [];
        foreach (self::chunk($this->kids, $this->columns) as $cells) {
            $rows[] = self::rowOf($cells, $this->columns, $this->columnSpacing, $this->cellHeight);
        }

        return Column::make()->spacing($this->rowSpacing)->children($rows);
    }

    /**
     * 一行：每格 `Expanded`（等宽），**末行不满补 `Spacer`**。
     *
     * ★ `Spacer` 是"没有孩子的 `Expanded`"（同样是 `grow = 1`）⇒ 占位格子与真格子
     *   同权，末行格宽与首行**逐像素相同**。不补 ⇒ 末行格宽 = 可用宽 / 末行格数。
     *
     * ★ **懒版（`LazyGrid`）也走这里**：分行规则只有这一份。两处各写一遍就等于
     *   "全量网格和懒网格的格宽算法可以漂移"，而它们必须逐像素一致（同一个页面
     *   在数据量变化时切换两种实现，不该看见格子跳动）。
     *
     * @internal
     *
     * @param list<Widget> $cells 本行的格子（长度 ≤ `$columns`）
     */
    public static function rowOf(array $cells, int $columns, float $columnSpacing, ?float $cellHeight): Widget
    {
        $kids = [];
        foreach ($cells as $cell) {
            $kids[] = Expanded::make(self::cell($cell, $cellHeight));
        }

        $filled = \count($cells);
        while ($filled < $columns) {
            $kids[] = Spacer::make();
            $filled++;
        }

        return Row::make()->spacing($columnSpacing)->children($kids);
    }

    /**
     * 按列数切块（手写循环而不是 `array_chunk`：`array_chunk` 在 `src/` 里零用法，
     * AOT 面不为一个 8 行的循环多开一个函数）。
     *
     * ★ 元素类型是 `mixed` 而不是 `Widget`：**懒版 `LazyGrid` 切的是数据**（还没造
     *   成 Widget），而"一行放几个"这条规则与元素类型无关 ⇒ 只留一份实现。
     *
     * @internal
     *
     * @param list<mixed> $kids
     * @return list<list<mixed>>
     */
    public static function chunk(array $kids, int $columns): array
    {
        $rows = [];
        $current = [];
        foreach ($kids as $kid) {
            $current[] = $kid;
            if (\count($current) === $columns) {
                $rows[] = $current;
                $current = [];
            }
        }

        // ★ 尾块非空 ⇒ 就是"末行不满"那一行（唯一需要补 `Spacer` 的地方）
        if ($current !== []) {
            $rows[] = $current;
        }

        return $rows;
    }

    private static function cell(Widget $cell, ?float $cellHeight): Widget
    {
        if ($cellHeight === null) {
            return $cell;
        }

        // ★ 只定高、宽透传（`Expanded` 已经把宽给成紧的）⇒ 不能写 `size($w, $h)`
        return SizedBox::make($cell)->height($cellHeight);
    }
}
