<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;

/**
 * 流式换行盒（A4 第二件，2026-10-28）。
 *
 * ```php
 * Wrap::make()
 *     ->spacing(8.0)
 *     ->runSpacing(8.0)
 *     ->children([$tagA, $tagB, $tagC]);   // 放不下的自动掉到下一行
 * ```
 *
 * ## ★★ 为什么它**不需要**两遍布局（`04` §4.4 的否决前提已被证伪）
 *
 * `04` §4.4 当初判"`Wrap` 需要两遍布局"，原话是：先量所有子的自然尺寸 → 按可用宽
 * 分行 → **而分几行决定容器高度，容器高度又可能改变子的宽度（如果子依赖父宽）**
 * ⇒ 需要 `layout` 迭代。
 *
 * 那个循环在**本实现里不存在**，因为它被构造性地切断了：
 * 子拿到的约束是 `主轴 0..mainLimit、交叉轴 0..crossLimit`，两个数**都直接来自父**，
 * 与"最后分了几行"无关 ⇒ 子的尺寸不依赖容器高度 ⇒ 没有迭代。
 * （与 `RenderStack` 那次同类：册子说"`Expand` 需要第二遍"是给循环依赖打补丁，
 *   真正的解是"`Expand` 取父给的尺寸"。）
 *
 * ⇒ **每个子只 `layout()` 一次**。分行与定位是同一趟里的两个阶段。
 *
 * ## ★ 唯一真的需要第二遍的那件事：`Stretch`（**故意不做**）
 *
 * `alignment(FlexAlign::Stretch)` = "同一行里所有子项取该行的最高值"。
 * 行高来自子、子又要按行高排 ⇒ **正是 `04` §4.4 担心的那个环**。
 * 所以 `Wrap::alignment()` / `runAlignment()` 收到 `Stretch` 是**响亮失败**
 * （写清理由与修法），不是静默退化成 `Start`。
 * ⇒ 本盒只接受 `Start` / `Center` / `End`；这条边界有子进程判据守着。
 *
 * ## 四件事
 *
 * | # | 做什么 | 口径 |
 * |---|---|---|
 * | ① | 给子约束 | 主轴 `0..mainLimit`（**不**给 INF，否则不换行）、交叉轴 `0..crossLimit` |
 * | ② | 分行 | 贪心：装不下就断行；**单个子比一行还宽 ⇒ 独占一行**（被夹到 `mainLimit`）|
 * | ③ | 本盒尺寸 | 主轴 = 最宽的那一行；交叉轴 = 各行交叉尺寸之和 + `runSpacing` |
 * | ④ | 定位 | 行内按 `alignment`；整块按 `runAlignment` 在交叉轴上分布 |
 *
 * ★ **交叉轴溢出走 C6**（与 `RenderFlex` 的 `Overflow::Report` 同口径）：行数是内容
 *   决定的，父给的交叉上限被超过 ⇒ 响亮失败，不静默裁。要滚动就包 `ScrollView`
 *   （那时交叉轴无界 ⇒ 不触发）。
 *
 * ## 内禀值的已知边界（**没**解决 `04` §4.4 提到的那半个问题）
 *
 * `Wrap` 的"自然宽"有两个合法答案：**一行全排**（max-content = Σ子）与
 * **最宽子项**（min-content = max子）。Drift 每轴**只有一个**内禀 ⇒ 本盒按
 * **一行**给（与"无界主轴 ⇒ 排一行"的自洽口径一致，也满足
 * `intrinsicWidth(INF) === 实际排出来的宽`）。
 * ⇒ 在"父按内禀给宽"的场景（`RenderPopupPanel` 的 `minWidth`、`RenderDock` 的自然
 *   尺寸）会偏**宽**，但会被父 `constrain()` 夹回、不会 C6。真需要 min-content
 *   要引入第二套内禀 —— 那是 `04` §2 的 S3 仲裁，本轮不做（见文档 §14.2）。
 *
 * @api
 */
final class RenderWrap extends RenderBox
{
    /**
     * 断行判据的容差（0.0001 px）。
     *
     * ★ 必须给：浮点累加下"刚好等于可用宽"会算成 `90.000000000000014 > 90`
     *   ⇒ 本该放得下的子被挤到下一行。**不报错**，只是布局莫名其妙多一行。
     */
    private const EPS = 0.0001;

    /** @var list<RenderBox> */
    private array $kids = [];

    public function __construct(
        private readonly Axis $direction = Axis::Horizontal,
        private readonly float $spacing = 0.0,
        private readonly float $runSpacing = 0.0,
        private readonly FlexAlign $alignment = FlexAlign::Start,
        private readonly FlexAlign $runAlignment = FlexAlign::Start,
    ) {
    }

    public function addChild(RenderBox $child): void
    {
        $this->kids[] = $child;
        $this->adoptChild($child);
    }

    /** @internal 重建前清空 —— 否则同一次 rebuild 会把子盒追加两遍 */
    public function clearChildren(): void
    {
        $this->kids = [];
    }

    /** @return list<RenderBox> 访问序 = 分行序 = 绘制序 */
    public function children(): array
    {
        return $this->kids;
    }

    public function visitChildren(callable $visitor): void
    {
        foreach ($this->kids as $kid) {
            $visitor($kid);
        }
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $horizontal = $this->direction === Axis::Horizontal;

        // ★ 先落局部标量：AOT 下在分支里直接读属性会被推成 `php::Variant`，
        //   与 `BoxConstraints` 的 `float` 形参不匹配 ⇒ `C2440`（`RenderStack`
        //   / `RenderFlex::tightMain` 同型坑）。
        $mainLimit = $horizontal ? $constraints->maxWidth : $constraints->maxHeight;
        $crossLimit = $horizontal ? $constraints->maxHeight : $constraints->maxWidth;

        // ① 子约束：主轴**夹到可用宽**（给 INF 就永远不换行了）
        $kidBox = $horizontal
            ? new BoxConstraints(0.0, $mainLimit, 0.0, $crossLimit)
            : new BoxConstraints(0.0, $crossLimit, 0.0, $mainLimit);

        // ── ② 分行（每个子只在这里 layout 一次）─────────────────────
        // ★ 三张**标量**平行数组（不是"每行一个数组"的混合结构）：AOT 下
        //   `list<array{float,float,list<RenderBox>}>` 会被推成 `php::Var`，
        //   而行是布局热路径。行 = `$this->kids[$from .. 下一行的 from)`。
        /** @var list<float> $runMain 每行占的主轴长度 */
        $runMain = [];
        /** @var list<float> $runCross 每行的交叉轴尺寸（= 该行最高子项） */
        $runCross = [];
        /** @var list<int> $runFrom 每行第一个子在 `$this->kids` 里的下标 */
        $runFrom = [];

        $curMain = 0.0;
        $curCross = 0.0;
        $curFrom = 0;

        foreach ($this->kids as $index => $kid) {
            $kid->layout($kidBox);
            $size = $kid->size();

            // ★ 没有尺寸的盒按 0 参与分行（不打断行的连续性）—— 它本来也画不出来
            $m = 0.0;
            $c = 0.0;
            if ($size !== null) {
                $m = $horizontal ? $size->width : $size->height;
                $c = $horizontal ? $size->height : $size->width;
            }

            if ($index === $curFrom) {
                // 一行的第一个子：**永远放进去**（哪怕它自己就比一行还宽 ⇒ 独占一行，
                //   且已被 `kidBox` 夹到 `mainLimit`）
                $curMain = $m;
                $curCross = $c;

                continue;
            }

            if ($curMain + $this->spacing + $m > $mainLimit + self::EPS) {
                $runMain[] = $curMain;
                $runCross[] = $curCross;
                $runFrom[] = $curFrom;

                $curMain = $m;
                $curCross = $c;
                $curFrom = $index;

                continue;
            }

            $curMain += $this->spacing + $m;
            if ($c > $curCross) {
                $curCross = $c;
            }
        }

        if (\count($this->kids) > $curFrom) {
            $runMain[] = $curMain;
            $runCross[] = $curCross;
            $runFrom[] = $curFrom;
        }

        // ── ③ 本盒尺寸：主轴取最宽的那行，交叉轴是各行之和 ────────────
        $runs = \count($runMain);
        $boxMain = 0.0;
        $crossSum = 0.0;
        for ($i = 0; $i < $runs; ++$i) {
            if ($runMain[$i] > $boxMain) {
                $boxMain = $runMain[$i];
            }
            $crossSum += $runCross[$i];
        }
        if ($runs > 1) {
            $crossSum += $this->runSpacing * ($runs - 1);
        }

        $measured = $horizontal
            ? new Size($boxMain, $crossSum)
            : new Size($crossSum, $boxMain);
        // ★ 交叉轴超上界 ⇒ 交给基类 ⑥ 的 C6 响亮报（与 `RenderFlex` 的 Report 同口径）
        $box = $constraints->constrain($measured);

        // ── ④ 定位 ─────────────────────────────────────────────────
        // 整块在交叉轴上的分布：按**夹取之后**的盒尺寸算余量（与 `RenderFlex` 的
        //   交叉轴居中同一条纪律：拿父的上限算会把子推出盒外）。
        $boxCross = $horizontal ? $box->height : $box->width;
        $slack = $boxCross - $crossSum;
        if ($slack < 0.0) {
            $slack = 0.0;
        }
        $penCross = match ($this->runAlignment) {
            FlexAlign::Center => $slack / 2.0,
            FlexAlign::End    => $slack,
            default           => 0.0,
        };

        for ($i = 0; $i < $runs; ++$i) {
            $from = $runFrom[$i];
            $to = $i + 1 < $runs ? $runFrom[$i + 1] : \count($this->kids);

            $penMain = 0.0;
            for ($j = $from; $j < $to; ++$j) {
                $kid = $this->kids[$j];
                $size = $kid->size();
                $m = 0.0;
                $c = 0.0;
                if ($size !== null) {
                    $m = $horizontal ? $size->width : $size->height;
                    $c = $horizontal ? $size->height : $size->width;
                }

                // 行内交叉轴：按**本行**的最高子项算（不是按盒，也不是按父的上限）
                $free = $runCross[$i] - $c;
                if ($free < 0.0) {
                    $free = 0.0;
                }
                $offset = match ($this->alignment) {
                    FlexAlign::Center => $free / 2.0,
                    FlexAlign::End    => $free,
                    default           => 0.0,
                };

                if ($horizontal) {
                    $kid->setOffset($penMain, $penCross + $offset);
                } else {
                    $kid->setOffset($penCross + $offset, $penMain);
                }

                $penMain += $m + $this->spacing;
            }

            $penCross += $runCross[$i] + $this->runSpacing;
        }

        return $box;
    }

    protected function contentIntrinsicWidth(float $height): float
    {
        return $this->direction === Axis::Horizontal
            ? $this->intrinsicMain($height)
            : $this->intrinsicCross($height);
    }

    protected function contentIntrinsicHeight(float $width): float
    {
        return $this->direction === Axis::Horizontal
            ? $this->intrinsicCross($width)
            : $this->intrinsicMain($width);
    }

    protected function paintContent(Canvas $canvas): void
    {
        foreach ($this->kids as $kid) {
            $canvas->save();
            $canvas->translate($kid->offsetX(), $kid->offsetY());
            $kid->paintComposed($canvas);
            $canvas->restore();
        }
    }

    // ── 内部 ────────────────────────────────────────────────────────

    /**
     * 主轴内禀 = **一行全排**（Σ子 + `spacing`）。
     *
     * ★ 这是 max-content 口径的选择说明，不是唯一真理 —— 见类注释的"内禀值边界"。
     */
    private function intrinsicMain(float $cross): float
    {
        $sum = 0.0;
        $n = 0;
        foreach ($this->kids as $kid) {
            $sum += $this->direction === Axis::Horizontal
                ? $kid->intrinsicWidth($cross)
                : $kid->intrinsicHeight($cross);
            ++$n;
        }

        return $sum + $this->spacing * \max(0, $n - 1);
    }

    /** 交叉轴内禀 = 一行里**最高**的那个（Σ 是主轴的事） */
    private function intrinsicCross(float $main): float
    {
        $max = 0.0;
        foreach ($this->kids as $kid) {
            $value = $this->direction === Axis::Horizontal
                ? $kid->intrinsicHeight($main)
                : $kid->intrinsicWidth($main);
            if ($value > $max) {
                $max = $value;
            }
        }

        return $max;
    }
}
