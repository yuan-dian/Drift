<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;

/**
 * 弹性盒（§13.1 的 `Column` / `Row` 渲染侧）。
 *
 * 三阶段：
 * 1. **问自然尺寸** —— 非弹性子拿 `0..主轴可用`，弹性子拿**无界主轴**（C4）；
 * 2. **分剩余空间** —— 主轴有界且有弹性子时，弹性子**整组拿走全部剩余**
 *    （可用主轴 − 非弹性子尺寸和 − 间距）按 flex 份额切分重排；弹性子第一遍
 *    量的自然尺寸**不是**要减掉的份额（2026-10-17 修：旧口径把它也减了 ⇒
 *    内容比视口长时拿 `tight(0)` 整块不可见、内容比剩余短时白留死空间）；
 * 3. **定位** —— 主轴顺序排、交叉轴按 `FlexAlign`（C1：父定位子）。
 *
 * ## 第 2 步有两条路径（W6 L4-2）
 *
 * | 判据 | 走哪条 | 公式 |
 * |---|---|---|
 * | 没有子声明 `shrink` / `basis`（**今天 100% 的用法**） | 上面那段老路径 | 份额 = `剩余 × grow / Σgrow`，从 0 起算 |
 * | 有子声明了（`$advanced`） | `allocateAdvanced()` | 起点（`basis` 或自然尺寸）→ `grow` 加剩余 → `shrink` 加权压缩（≤3 轮冻结） |
 *
 * ★ 两条路径对**纯 `grow` 子**是等价的（起点 0 ⇒ `0 + 剩余 × 份额` = 老公式），
 *   所以同一容器里混用不会漂移。
 * ★ 老路径保住的是**性能**：弹性子第一遍不量（layout 620→310µs）。新路径必须量
 *   —— 压缩的起点是"它本来要多大"，而这个数只有量了才知道。
 *
 * ★ **C4 的"无界高"不是实现细节而是契约**：量自然尺寸那遍给弹性子无界主轴，
 *   否则弹性子按"现在剩多少"量，flex 分配就建立在错误分母上（图形比例会随
 *   兄弟数量漂移）。`0` 是合法主轴尺寸，所以不能用"0 表示无界"—— 那是 GUI 仓
 *   三次踩坑的那一条（C2）。
 *
 * ★ **不是 `final`**：`RenderAppBar`（§12.1 的 chrome 顶条）继承它 —— 顶条
 *   就是"带底色的弹性行"，只覆写 `paint()`，不为一个新名字复制一套 flex。
 *
 * @api
 */
class RenderFlex extends RenderBox implements ChildContainer
{
    /** @var list<RenderBox> */
    private array $kids = [];

    /**
     * @param bool $vertical true = `Column`（主轴竖）；false = `Row`（主轴横）
     * @param float $spacing 主轴相邻子的间距（不含 padding）
     * @param FlexAlign $crossAlign 交叉轴对齐
     * @param Overflow $overflow **必填，无默认值** —— C5 原话："会超的容器必须
     *        显式声明"。写成构造参数而不是字段默认，是为了让漏声明编译不过（G1）。
     */
    public function __construct(
        // ★ **AOT：会被继承的类（这里是 `RenderAppBar`），字段不能是 private** ——
        //   tpc 按运行时类型查属性表，父类 `private` 不进子类表 ⇒ 子类实例上访问会
        //   动态创建属性（`Deprecated: Creation of dynamic property RenderAppBar::$vertical`，
        //   且值为 NULL ⇒ 布局拿到错值）。与 `RenderBox::$size` 同一条纪律。
        //   2026-10-16 真机跑 `users.exe` 时当场暴露。
        protected readonly bool $vertical,
        protected readonly float $spacing = 0.0,
        protected readonly EdgeInsets $padding = new EdgeInsets(),
        protected readonly FlexAlign $crossAlign = FlexAlign::Start,
        protected readonly Overflow $overflow = Overflow::Report,
    ) {
    }

    public function addChild(RenderBox $child): void
    {
        $this->kids[] = $child;
        $this->adoptChild($child);
    }

    /**
     * C5 契约查询：返回本盒**实际生效**的溢出策略。
     *
     * ★ 必须覆写：`RenderBox::overflow()` 的默认值是 Clip，而本盒真正用的策略
     *   是构造参数 `$this->overflow`（默认 Report）—— 不覆写 ⇒ "查询说 Clip、
     *   行为是 Report"，W4（会超的容器声明溢出策略）的判定面整体说谎。
     *   2026-10-16 由 M2 conformance 夹具抓到（`tools/M2Conformance.php` W4）。
     */
    #[\Override]
    public function overflow(): Overflow
    {
        return $this->overflow;
    }

    /** `Overflow::Clip` 的**兑现**：布局期夹住尺寸，绘制期把超出的子项裁掉 */
    #[\Override]
    public function clipsOverflow(): bool
    {
        return $this->overflow === Overflow::Clip;
    }

    /** @internal 重建前清空 —— 否则同一次 rebuild 会把子盒追加两遍 */
    public function clearChildren(): void
    {
        $this->kids = [];
    }

    /** @return list<RenderBox> */
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
        $inner = $constraints->deflate($this->padding);

        $vertical = $this->vertical;
        $maxMain = $vertical ? $inner->maxHeight : $inner->maxWidth;
        $maxCross = $vertical ? $inner->maxWidth : $inner->maxHeight;
        $mainBounded = $vertical ? $inner->hasBoundedHeight() : $inner->hasBoundedWidth();

        // ★ 三个判据一次扫完（不能 `break`：`$needsNatural` 要看全部子）：
        //   · `$hasFlex`  —— 有 `grow` 子 ⇒ 老路径的"分剩余空间"要跑；
        //   · `$needsNatural` —— 有子要压缩却不知道它本来多宽 ⇒ 第一遍**不能跳过**
        //     弹性子（`04` §3.1：压缩的起点是"它本来要多大"）；
        //   · `$hasBasis` —— 有子直接声明了起点 ⇒ 走新公式。
        $hasFlex = false;
        $needsNatural = false;
        $hasBasis = false;
        foreach ($this->kids as $kid) {
            if ($kid->flex() > 0) {
                $hasFlex = true;
            }
            if (!\is_nan($kid->basis())) {
                $hasBasis = true;
            } elseif ($kid->shrink() > 0) {
                $needsNatural = true;
            }
        }

        // ★ **新公式的启用判据**（`04` §3.1）：有子声明 `shrink` 或 `basis`。
        //   两者都没有 ⇒ 纯 `grow` 容器（今天 100% 的用法）⇒ 走老路径，
        //   行为与性能**逐字节不变**（这是 620→310µs 那条快路径的守卫）。
        $advanced = $needsNatural || $hasBasis;

        // ── 第一遍：自然尺寸 ────────────────────────────────────────
        /** @var list<float> $mains 每个子的主轴尺寸 */
        $mains = [];
        $totalFlex = 0;

        foreach ($this->kids as $index => $kid) {
            $flex = $kid->flex();
            $totalFlex += $flex;

            // ★★ 弹性子**不在第一遍量**（主轴有界时）：它的尺寸要等"份额"
            //   定下来才有意义，而份额又要等固定子节点量完。第一遍量的结果会被
            //   第二遍**完全覆盖**（`$mains` 回填、`mainSize` 在第二遍之后才求和）
            //   ⇒ 等于让整棵弹性子树每帧白排一遍 —— showcase / users 的外层
            //   `Row[导航, Expanded(ScrollView(页))]` 正是此形态。GUI 上游同款
            //   实测：这笔浪费接近整帧布局开销的一半（layout 620→310µs）。
            //   Flutter 的 RenderFlex 同构：第一遍只处理 flex == 0 的子节点。
            // ★ **主轴无界时例外**：第二遍不会跑（无空间可分），弹性子必须在
            //   这里拿自然尺寸，否则整棵子树一次都不排 ⇒ 尺寸停留上一帧。
            if ($flex > 0 && $mainBounded && !$needsNatural) {
                $mains[$index] = 0.0;   // 占位：第二遍回填
                continue;
            }

            // 主轴无界 ⇒ 无空间可分，所有子拿无界主轴（自然尺寸）；
            // 有界且非弹性 ⇒ 上限。
            // ★★ **`$needsNatural` 时弹性子也拿无界主轴** —— 这是 C4 契约的原文
            //   （"量自然尺寸那遍给弹性子无界主轴"），在这里才第一次真有后果：
            //   给 `0..$maxMain` 的话，内容比容器长的弹性子（正是要压缩的那种）
            //   第一遍就撞 C5 报"放不下" ⇒ `shrink` **永远用不上**。
            //   2026-10-23 写分配公式时发现，不是真机暴露的。
            // ★ 判据必须含 `shrink`：`flex()` 只是 `grow()` 的别名，而"要压缩的
            //   弹性子"恰恰是 `grow = 0, shrink > 0` 的那种 —— 只看 `$flex` 的话
            //   本意要保护的子拿不到无界主轴（夹具当场抓到：第一遍 maxWidth=100）。
            $elasticNatural = $needsNatural && ($flex > 0 || $kid->shrink() > 0);
            $childMainMax = ($mainBounded && !$elasticNatural) ? max(0.0, $maxMain) : INF;
            $kid->layout($this->childConstraints($kid, $childMainMax, $maxCross));
            $mains[$index] = $vertical ? $kid->size()->height : $kid->size()->width;
        }

        $gap = $this->spacing * max(0, count($this->kids) - 1);

        // ── 第二遍：flex 有界时分剩余空间 ────────────────────────────
        // ★ **分母只含非弹性子的自然尺寸**（"分剩余空间"的标准语义）：弹性子整组
        //   拿走全部剩余，第一遍量的自然尺寸不是要减掉的份额。旧口径
        //   `array_sum($mains)` 把弹性子自然尺寸也减了 ⇒ 双重扣减：内容比视口长时
        //   `Expanded` 拿 `tight(0)`（整块不可见，2026-10-17 users 页实证）、内容比
        //   剩余短时容器白留没人要的死空间。非弹性子溢出时 `$free < 0` ⇒ 弹性子拿 0、
        //   C5 溢出处理照旧 ⇒ 既有溢出行为不变。
        if ($mainBounded && $advanced) {
            // 新公式（`basis` / `shrink`）：起点 → `grow` 加剩余 → `shrink` 加权压缩
            $mains = $this->allocateAdvanced($mains, $inner, $maxMain, $gap, $totalFlex);
        } elseif ($hasFlex && $mainBounded && $totalFlex > 0) {
            $fixedMain = 0.0;
            foreach ($this->kids as $index => $kid) {
                if ($kid->flex() === 0) {
                    $fixedMain += $mains[$index];
                }
            }
            $free = $maxMain - $fixedMain - $gap;
            // 溢出 ⇒ 无空间可分。此时按 C5 的策略处理（下面统一判），flex 项先拿 0。
            $space = max(0.0, $free);

            foreach ($this->kids as $index => $kid) {
                $flex = $kid->flex();
                if ($flex === 0) {
                    continue;
                }
                $share = $space * $flex / $totalFlex;
                $tight = $this->tightMain($inner, $share, $kid);
                // 第一遍被跳过的弹性子在这里第一次也是唯一一次量 ⇒ `$mains` 占位回填
                $kid->layout($tight);
                $mains[$index] = $vertical ? $kid->size()->height : $kid->size()->width;
            }
        }

        $mainSize = array_sum($mains) + $gap;

        // ── C5：溢出策略是布局期行为，不是绘制期补丁 ────────────────
        // Report / Scroll 都在 handleOverflow 里中止了（后者是"不支持"，指路
        // ScrollView）；能走到这里的只有 Clip ⇒ 盒子自己夹到可用主轴，子项仍按
        // 自然位置排（超出部分交给裁剪）。
        $reported = $mainSize;
        // ★ 容差：等分子项求和会累积浮点误差（344/7×7 = 344.00000000000006），
        //   裸 `>` 会把"正好放下"报成溢出（2026-10-29 实测）。
        if ($mainBounded && $mainSize > $maxMain + LayoutTolerance::EPSILON) {
            $this->handleOverflow($mainSize, $maxMain, $mains);
            $reported = $maxMain;
        }

        $crossSize = 0.0;
        foreach ($this->kids as $kid) {
            $cross = $vertical ? $kid->size()->width : $kid->size()->height;
            $crossSize = max($crossSize, $cross);
        }

        // ★ 交叉轴定位按**盒自身的交叉尺寸**居中，不是按约束上限：
        //   上限宽松时（`AppBar` 从纵向 `Column` 拿到 0..320 的高）拿上限居中会把子项
        //   推到盒**外面** —— 标题落到 y=143 而盒高仅 44.8（2026-10-16 由预览抓出）。
        //   盒尺寸要**按 `RenderBox::layout` 同口径夹一次**：父把交叉轴定死（`tight`）
        //   时下界会顶上来，此时必须按夹后的尺寸居中（否则 `Center` 退回 0）。
        $size = new Size(
            $vertical
                ? min($crossSize + $this->padding->horizontal(), $constraints->maxWidth)
                : $reported + $this->padding->horizontal(),
            $vertical
                ? $reported + $this->padding->vertical()
                : min($crossSize + $this->padding->vertical(), $constraints->maxHeight),
        );
        $clamped = $constraints->constrain($size);
        $crossInner = $vertical
            ? $clamped->width - $this->padding->horizontal()
            : $clamped->height - $this->padding->vertical();
        $this->placeChildren($mains, max(0.0, $crossInner));

        return $size;
    }

    protected function contentIntrinsicWidth(float $height): float
    {
        return $this->vertical ? $this->intrinsicCross($height) : $this->intrinsicMain($height);
    }

    protected function contentIntrinsicHeight(float $width): float
    {
        return $this->vertical ? $this->intrinsicMain($width) : $this->intrinsicCross($width);
    }

    protected function paintContent(Canvas $canvas): void
    {
        $canvas->save();

        foreach ($this->kids as $kid) {
            $canvas->save();
            $canvas->translate($kid->offsetX(), $kid->offsetY());
            $kid->paintComposed($canvas);
            $canvas->restore();
        }

        $canvas->restore();
    }

    // ── 内部 ────────────────────────────────────────────────────────

    /**
     * 新分配公式：起点（`basis` 或自然尺寸）→ `grow` 加剩余 → `shrink` 加权压缩。
     *
     * 只在有子声明了 `shrink` 或 `basis` 时走到（`04` §3）；纯 `grow` 的容器仍走
     * `performLayout()` 里的老路径 —— 那条路径**逐字节同**现状（`$needsNatural`
     * 判据保住 620→310µs 的快路径：弹性子第一遍不量）。
     *
     * ★ **与老路径的唯一语义差别**：老路径的 `grow` 份额是"从 0 起算的总量"
     *   （`share = space * grow / totalGrow`），这里是"在起点之上的增量"
     *   （`start + free * grow / totalGrow`）。纯 `grow` 子的起点是 0 ⇒ 两者等价，
     *   所以同一容器里混用不会漂移。
     *
     * ★ **压缩下限是 0，不做 `min-content`**（`04` §3.4）：被压到 0 的子内容
     *   不可见但**不报错**，而且**今天没有保底手段** —— 册子原文说"要保底就设
     *   `width`"，但 `Modifier::tighten()` 是把 `width` **夹进**父给的区间，
     *   这里的紧约束 `(0, 0)` 会把 `width` 一起夹成 0（2026-10-23 夹具实证：
     *   写了 `width(60)` 的子照样被压到 0）。真要有下限得等 `min-content`，
     *   而它需要文本的最小宽度（`DeterministicMetricsProvider` 只有 max-content）。
     *
     * ★ **3 轮上限**（`04` §3.2）：每轮至少冻结一个子，否则收敛；真跑满 3 轮说明
     *   同一行里有 4 个以上子同时被压到 0 —— 布局已经不可用，剩下的交给 C5
     *   让用户看到"放不下：子项共需 X，可用只有 Y"，而不是"所有子都变 0 宽"。
     *
     * @param list<float> $mains 第一遍量到的自然主轴尺寸（`basis` 有效的子可能是占位 0）
     * @return list<float> 每个子最终的主轴尺寸（已按紧约束排过一次）
     */
    private function allocateAdvanced(
        array $mains,
        BoxConstraints $inner,
        float $maxMain,
        float $gap,
        int $totalGrow,
    ): array {
        // ── ① 起点 ──────────────────────────────────────────────────
        /** @var list<float> $sizes */
        $sizes = [];
        $sumStart = $gap;
        foreach ($this->kids as $index => $kid) {
            $b = $kid->basis();
            $start = \is_nan($b) ? $mains[$index] : $b;
            $sizes[$index] = $start;
            $sumStart += $start;
        }

        if ($sumStart <= $maxMain) {
            // ── ② 有剩余 ⇒ 按 `grow` 份额加在起点**之上** ────────────
            // ★ 分母只含 `grow`（不含 `shrink`）：CSS 的 `flex-grow` 与
            //   `flex-shrink` 是两套独立的权重，混在一个分母里会让"只压缩不放大"
            //   的子偷走放大份额。
            if ($totalGrow > 0) {
                $free = $maxMain - $sumStart;
                foreach ($this->kids as $index => $kid) {
                    $g = $kid->grow();
                    if ($g === 0) {
                        continue;
                    }
                    $sizes[$index] += $free * $g / $totalGrow;
                }
            }
        } else {
            // ── ③ 溢出 ⇒ 加权压缩 + 冻结迭代 ─────────────────────────
            /** @var array<int, int> $frozen 1 = 已压到 0，不再参与后续轮次 */
            $frozen = [];
            foreach ($this->kids as $index => $kid) {
                $frozen[$index] = 0;
            }

            for ($round = 0; $round < 3; ++$round) {
                // ★ 每轮从**当前** `$sizes` 重算总账，而不是"把冻结子的 basis 从
                //   overshoot 里扣掉"：后者要维护一份 overshoot 修正量，且冻结子
                //   的 `shrink × size` 已经自然归 0 ⇒ 重算更少出错（`04` §3.2 的
                //   公式与本实现在数学上等价）。
                $total = $gap;
                foreach ($sizes as $value) {
                    $total += $value;
                }
                $overshoot = $total - $maxMain;
                if ($overshoot <= 0.0) {
                    break;
                }

                $scaledSum = 0.0;
                foreach ($this->kids as $index => $kid) {
                    if ($frozen[$index] === 1) {
                        continue;
                    }
                    $s = $kid->shrink();
                    if ($s === 0) {
                        continue;
                    }
                    $scaledSum += (float) $s * $sizes[$index];
                }
                // 没有可压缩的子（或它们起点都是 0）⇒ 不在这里报错，交给 C5
                if ($scaledSum <= 0.0) {
                    break;
                }

                $newFrozen = false;
                foreach ($this->kids as $index => $kid) {
                    if ($frozen[$index] === 1) {
                        continue;
                    }
                    $s = $kid->shrink();
                    if ($s === 0) {
                        continue;
                    }
                    $cut = $overshoot * ((float) $s * $sizes[$index]) / $scaledSum;
                    $next = $sizes[$index] - $cut;
                    if ($next <= 0.0) {
                        $sizes[$index] = 0.0;
                        $frozen[$index] = 1;
                        $newFrozen = true;
                    } else {
                        $sizes[$index] = $next;
                    }
                }
                // 本轮没人被压到 0 ⇒ 加权公式一次到位，收敛
                if (!$newFrozen) {
                    break;
                }
            }
        }

        // ── ④ 按最终尺寸紧约束排一次 ────────────────────────────────
        // `$mains` 回填**实际**主轴尺寸：`width` 保底会让被压到 0 的子变大，
        // `placeChildren()` 的笔位与 `mainSize` 都必须按实际值走。
        /** @var list<float> $final */
        $final = [];
        foreach ($this->kids as $index => $kid) {
            $kid->layout($this->tightMain($inner, $sizes[$index], $kid));
            $final[$index] = $this->vertical ? $kid->size()->height : $kid->size()->width;
        }

        return $final;
    }

    /**
     * 解析某个子的交叉轴对齐：`alignSelf` 声明了就用它，否则跟容器的 `crossAlign`。
     *
     * ★ **`int` 哨兵而不是 enum**（`04` §2.4）：`alignSelf` 要表达"未设"
     *   （`ALIGN_INHERIT`），而 enum 没有"未设"这个 case —— 加一个 `Inherit` case
     *   会让 `placeChildren()` 的 `match` 多一条"不该走到"的分支。
     *
     * ★ **三处都要用它**（约束 / 紧主轴 / 定位）：只在 `placeChildren()` 里解析的话，
     *   `ALIGN_STRETCH` 会变成"偏移算成 0、尺寸却没拉满"—— 一个看起来实现了、
     *   实际没生效的分支（G2）。
     *
     * ★ `default` 兜 `ALIGN_START` 与任何非法值：非法值可能从 `setFlexIntent()`
     *   直接写进来（绕过 `Widget::alignSelf()`），退化为 `Start` 与"未设"同义。
     */
    private function resolveAlign(RenderBox $kid): FlexAlign
    {
        $self = $kid->alignSelf();
        if ($self === RenderBox::ALIGN_INHERIT) {
            return $this->crossAlign;
        }

        return match ($self) {
            RenderBox::ALIGN_CENTER  => FlexAlign::Center,
            RenderBox::ALIGN_END     => FlexAlign::End,
            RenderBox::ALIGN_STRETCH => FlexAlign::Stretch,
            default                  => FlexAlign::Start,
        };
    }

    /**
     * 单个子的约束：主轴 `0..$mainMax`（弹性且有界时由第二遍收紧），
     * 交叉轴 `Stretch` ⇒ 紧约束（拉满），否则上限界。
     */
    private function childConstraints(RenderBox $kid, float $mainMax, float $crossMax): BoxConstraints
    {
        $crossBounded = !is_infinite($crossMax);
        // ★ 按**子**解析，不是按容器：`alignSelf(STRETCH)` 要能单独拉满某一个子
        //   （`04` §2.4）。默认 `ALIGN_INHERIT` ⇒ 与容器一致 ⇒ 零行为变化。
        $stretch = $this->resolveAlign($kid) === FlexAlign::Stretch && $crossBounded;

        // ★ **`Stretch` 拉的是交叉轴，不是主轴**（2026-10-17 修正）：
        //   `Column` ⇒ 宽拉满、高自然；`Row` ⇒ 高拉满、宽自然。
        //   ★ 旧实现两个分支把主轴与交叉轴**写反了**（`Row` 给了紧宽、`Column`
        //     给了紧高）—— 与 `placeChildren()` 里 `Stretch => 0.0`（交叉轴偏移 0）
        //     以及 Flutter 的同名属性都相反。
        //   ★ 潜伏原因：`FlexAlign::Stretch` 在生产代码里**零用法**（示例台的三层
        //     卡片外壳是第一个），这条分支从未被执行过 —— 死代码不会报错，
        //     而写反的紧宽会把同级 `Expanded` 挤到 0 宽（真机第一帧 FATAL）。
        if ($this->vertical) {
            return $stretch
                ? new BoxConstraints($crossMax, $crossMax, 0.0, $mainMax)
                : new BoxConstraints(0.0, $crossMax, 0.0, $mainMax);
        }

        return $stretch
            ? new BoxConstraints(0.0, $mainMax, $crossMax, $crossMax)
            : new BoxConstraints(0.0, $mainMax, 0.0, $crossMax);
    }

    /** 主轴紧约束（flex 分配结果） */
    private function tightMain(BoxConstraints $inner, float $main, RenderBox $kid): BoxConstraints
    {
        $share = max(0.0, $main);
        // ★ 弹性子也要能被 `alignSelf(STRETCH)` 单独拉满交叉轴 ⇒ 按子解析
        $stretch = $this->resolveAlign($kid) === FlexAlign::Stretch;

        // ★ **AOT 类型推断**：三元的分支里直接放**属性读取**（`$inner->maxWidth`）会被
        //   推成 `php::Variant`，而 `BoxConstraints` 的形参是 `float` ⇒ `C2440`。
        //   先落局部标量再传（`typephp-gui-build` 技能里记的同型坑）。
        //   ★ 这一处由 `apps/users` 的**更大用法面**首次触达：AOT 只编译**可达**代码，
        //     而 `apps/hello`（Column/Row/Text）走不到带 `Stretch` 的这条分支。
        $crossMain = 0.0;
        if ($this->vertical
            && $stretch
            && $inner->hasBoundedWidth()) {
            $crossMain = $inner->maxWidth;
        }

        // ★ 水平同理：`Row` 的交叉轴是**高** —— 弹性子也要拉满（否则同一行里
        //   非弹性子被拉满、弹性子不被拉满，视觉上高度参差）。
        $crossCross = 0.0;
        if (!$this->vertical
            && $stretch
            && $inner->hasBoundedHeight()) {
            $crossCross = $inner->maxHeight;
        }

        if ($this->vertical) {
            return new BoxConstraints($crossMain, $inner->maxWidth, $share, $share);
        }

        return new BoxConstraints(
            $share,
            $share,
            $crossCross,
            $inner->maxHeight,
        );
    }

    /** @param list<float> $mains */
    private function placeChildren(array $mains, float $crossMax): void
    {
        // ★ padding **在这里施加**（不是 paint 期的 translate）—— 子盒的
        //   `offsetX()/offsetY()` 必须包含它，否则 `hitTest()` 与
        //   `Application::absoluteOrigin()` 拿到的坐标都比画出来的位置差一个
        //   padding（2026-10-17 实证：paint 画在 (24,24)、hitTest 判定 (0,0)）。
        //   ⇒ 命中区与绘制区错位；IME 候选框坐标 / 菜单锚点同受影响。
        $pen = $this->vertical ? $this->padding->top : $this->padding->left;

        foreach ($this->kids as $index => $kid) {
            $cross = $this->vertical ? $kid->size()->width : $kid->size()->height;
            // ★ `alignSelf` 覆盖容器的 `crossAlign`（`04` §2.4）
            $align = $this->resolveAlign($kid);
            $offset = match ($align) {
                FlexAlign::Center  => max(0.0, ($crossMax - $cross) / 2.0),
                FlexAlign::End     => max(0.0, $crossMax - $cross),
                FlexAlign::Stretch => 0.0,
                FlexAlign::Start   => 0.0,
            };

            if ($this->vertical) {
                $kid->setOffset($offset + $this->padding->left, $pen);
            } else {
                $kid->setOffset($pen, $offset + $this->padding->top);
            }

            $pen += $mains[$index] + $this->spacing;
        }
    }

    /**
     * @param array<int, float> $mains 每个子项在主轴上的自然尺寸（诊断用）
     */
    private function handleOverflow(float $mainSize, float $maxMain, array $mains = []): void
    {
        // ★ 溢出消息里带**子项构成**（几个、各是什么、各多宽、间距多少）：只说
        //   "共需 196 可用 180"的读者还得自己数哪一行。带上类名之后一眼能分开两种
        //   完全不同的病：**"控件太多"**（`Button + Button + Button`，该换行）与
        //   **"一个控件太宽"**（`Text 177.0 + Spacer 0.0`，该让它自己收窄）。
        //   示例台 25 页的窄窗欠债就是靠这个分类的。
        $detail = '';
        if ($mains !== []) {
            $parts = [];
            foreach ($mains as $index => $m) {
                $kid = $this->kids[$index] ?? null;
                $name = $kid === null ? '?' : (new \ReflectionClass($kid))->getShortName();
                $parts[] = \sprintf('%s %.1f', \preg_replace('/^Render/', '', $name), $m);
            }
            $detail = \sprintf(
                "\n" . '子项：%s（间距 %.1f，%d 个）',
                \implode(' + ', $parts),
                $this->spacing,
                \count($mains),
            );
        }

        match ($this->overflow) {
            Overflow::Clip => null, // 自己夹住（见 performLayout 的 min），子项照自然位置排
            // ★★ **窗口帧**里"内容比窗口宽"是合法状态（用户拖小了）：裁剪 + 留一条
            //   diagnostic，不中止。中止只留给纯布局（测试 / 快照）—— 那里溢出才是
            //   写错了。见 `LayoutTolerance` 的对照表（Flutter/Qt/WPF/CSS 都裁剪）。
            Overflow::Report => LayoutTolerance::$windowDriven
                ? LayoutTolerance::clipped(static::class, sprintf(
                    '%s 放不下：子项共需 %.1f，可用只有 %.1f（差 %.1f）—— 窗口比内容窄，超出部分已裁掉。'
                        . '%s'
                        . "\n" . '这不是崩溃：把窗口拖大即可恢复完整显示。'
                        . "\n" . '若想让它自己换行 / 收缩（而不是被裁），改用 Wrap，或给子项加 Flexible / Expanded。',
                    static::class,
                    $mainSize,
                    $maxMain,
                    $mainSize - $maxMain,
                    $detail,
                ))
                : Errors::report(sprintf(
                '%s 放不下：子项共需 %.1f，可用只有 %.1f（差 %.1f）。%s'
                    . "\n" . '修法：① 把窗口拖大，或让子项少占一些；'
                    . '② 允许裁剪则给该容器声明 overflow(Overflow::Clip)（默认策略 Report 会中止）。',
                static::class,
                $mainSize,
                $maxMain,
                $mainSize - $maxMain,
                $detail,
            )),
            // ★★ `Scroll` 在**容器上不支持**（2026-10-25，I4）：本枚举的 `Scroll` 是
            //   `RenderScrollView` 的行为声明；容器自己滚与 `ScrollView` 职责重叠，
            //   `04` §1058 已否决。⇒ 这不是"还没做"，是"别这么做" —— 消息必须**指路**，
            //   否则读者只会以为"等以后实现"。
            Overflow::Scroll => Errors::report(sprintf(
                '%s 不支持 overflow(Overflow::Scroll) —— 容器自己滚动与 ScrollView 职责重叠（已否决）。'
                    . "\n" . '修法：① 把内容包进 `ScrollView`（它持滚动偏移并做视口裁剪）；'
                    . '② 只想裁掉超出部分则用 `Overflow::Clip`。',
                static::class,
            )),
        };
    }

    /** 主轴内禀值：所有子同轴相加 + 间距 */
    private function intrinsicMain(float $otherAxis): float
    {
        $total = 0.0;
        foreach ($this->kids as $kid) {
            $total += $this->vertical ? $kid->intrinsicHeight($otherAxis) : $kid->intrinsicWidth($otherAxis);
        }

        return $total + $this->spacing * max(0, count($this->kids) - 1);
    }

    /** 交叉轴内禀值：所有子的最大者 */
    private function intrinsicCross(float $otherAxis): float
    {
        $max = 0.0;
        foreach ($this->kids as $kid) {
            $value = $this->vertical ? $kid->intrinsicWidth($otherAxis) : $kid->intrinsicHeight($otherAxis);
            $max = max($max, $value);
        }

        return $max;
    }
}
