<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;

/**
 * 弹性盒（`Column` / `Row` 的渲染侧）。
 *
 * 三阶段：问自然尺寸（弹性子拿无界主轴）、分剩余空间、按 `FlexAlign` 定位。
 *
 * 弹性子的"无界主轴"是契约：否则 flex 分配分母错，比例随兄弟数漂移；
 *   `0` 是合法主轴尺寸，不能用"0 表示无界"。不是 `final`（`RenderAppBar` 继承）。
 *
 * @api
 */
class RenderFlex extends RenderBox implements ChildContainer
{
    use MultiChildBox;

    /** @var list<RenderBox> */
    private array $kids = [];

    /**
     * @param bool $vertical true = `Column`（主轴竖）；false = `Row`（主轴横）
     * @param float $spacing 主轴相邻子的间距（不含 padding）
     * @param FlexAlign $crossAlign 交叉轴对齐
     * @param Overflow $overflow 必填、无默认值：会超的容器必须显式声明溢出策略
     */
    public function __construct(
        // ★ **AOT：会被继承的类字段不能是 `private`** —— tpc 按运行时类型查属性表，
        //   父类 `private` 不进子类表 ⇒ 子类访问会动态创建属性（值为 NULL ⇒ 布局拿错值）。
        protected readonly bool $vertical,
        protected readonly float $spacing = 0.0,
        protected readonly EdgeInsets $padding = new EdgeInsets(),
        protected readonly FlexAlign $crossAlign = FlexAlign::Start,
        protected readonly Overflow $overflow = Overflow::Report,
    ) {
    }

    /**
     * 新配置与本盒**完全等价**吗（方向 / 间距 / padding / 交叉对齐 / 溢出策略）？
     *
     * 只比这五个构造参数，不比子层 —— 子列表增删由
     * `MultiChildElement::adoptBoxes()` 发现并 `markNeedsLayout()`。
     * 任一项不同必须返回 `false`（父类契约：几何变了交回框架造新盒）。
     */
    public function matchesConfiguration(
        bool $vertical,
        float $spacing,
        EdgeInsets $padding,
        FlexAlign $crossAlign,
        Overflow $overflow,
    ): bool {
        return $this->vertical === $vertical
            && $this->spacing === $spacing
            && $this->padding->equals($padding)
            && $this->crossAlign === $crossAlign
            && $this->overflow === $overflow;
    }

    /**
     * 返回本盒**实际生效**的溢出策略。
     *
     * ★ 必须覆写：基类默认 Clip，而本盒用的是构造参数 `$this->overflow`（默认 Report）；
     *   不覆写 ⇒ 查询说 Clip、行为是 Report，声明溢出策略的判定面整体说谎。
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

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $inner = $constraints->deflate($this->padding);

        $vertical = $this->vertical;
        $maxMain = $vertical ? $inner->maxHeight : $inner->maxWidth;
        $maxCross = $vertical ? $inner->maxWidth : $inner->maxHeight;
        $mainBounded = $vertical ? $inner->hasBoundedHeight() : $inner->hasBoundedWidth();

        // 三个判据一次扫完（不能 `break`：`$needsNatural` 要看全部子）：
        //   `$hasFlex` 有 grow 子；`$needsNatural` 有子要压缩却不知本来多宽 ⇒ 第一遍不跳；
        //   `$hasBasis` 有子声明了起点 ⇒ 走新公式。
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

        // 新公式启用判据：有子声明 `shrink` 或 `basis`；都没有 ⇒ 纯 `grow`，走老路径。
        $advanced = $needsNatural || $hasBasis;

        // ── 第一遍：自然尺寸 ────────────────────────────────────────
        /** @var list<float> $mains 每个子的主轴尺寸 */
        $mains = [];
        $totalFlex = 0;

        foreach ($this->kids as $index => $kid) {
            $flex = $kid->flex();
            $totalFlex += $flex;

            // ★ 弹性子**不在第一遍量**（主轴有界时）：份额要等固定子量完，第一遍
            //   量的值会被第二遍完全覆盖 ⇒ 白排。但**主轴无界时例外**：第二遍不跑，
            //   弹性子必须在这里拿自然尺寸，否则整棵子树一次都不排 ⇒ 尺寸停留上一帧。
            if ($flex > 0 && $mainBounded && !$needsNatural) {
                $mains[$index] = 0.0;   // 占位：第二遍回填
                continue;
            }

            // 主轴无界 ⇒ 无空间可分，所有子拿无界主轴（自然尺寸）；
            // 有界且非弹性 ⇒ 上限。
            // `$needsNatural` 时弹性子也拿无界主轴：给 `0..$maxMain` 的话，要压缩的
            // 弹性子第一遍就撞溢出 ⇒ `shrink` 永远用不上。判据必须含 `shrink`
            // （`flex()` 只是 `grow()` 别名，"要压缩"的是 `grow=0, shrink>0`）。
            $elasticNatural = $needsNatural && ($flex > 0 || $kid->shrink() > 0);
            $childMainMax = ($mainBounded && !$elasticNatural) ? max(0.0, $maxMain) : INF;
            $kid->layout($this->childConstraints($kid, $childMainMax, $maxCross));
            $mains[$index] = $vertical ? $kid->size()->height : $kid->size()->width;
        }

        $gap = $this->spacing * max(0, count($this->kids) - 1);

        // ── 第二遍：flex 有界时分剩余空间 ────────────────────────────
        // 分母只含非弹性子的自然尺寸：弹性子整组拿走全部剩余，其第一遍自然尺寸
        //   不是要减掉的份额（旧口径双重扣减 ⇒ `Expanded` 拿 0 整块不可见 / 或白留死空间）。
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
            // 溢出 ⇒ 无空间可分，flex 项先拿 0，下面统一按溢出策略处理。
            $space = max(0.0, $free);

            foreach ($this->kids as $index => $kid) {
                $flex = $kid->flex();
                if ($flex === 0) {
                    continue;
                }
                $share = $space * $flex / $totalFlex;
                $tight = $this->tightMain($inner, $share, $kid);
                // 第一遍被跳过的弹性子在此第一次也是唯一一次量 ⇒ `$mains` 占位回填。
                $kid->layout($tight);
                $mains[$index] = $vertical ? $kid->size()->height : $kid->size()->width;
            }
        }

        $mainSize = array_sum($mains) + $gap;

        // ── 溢出策略是布局期行为，不是绘制期补丁 ─────────────────────
        // Report / Scroll 都在 handleOverflow 里中止（后者"不支持"，指路 ScrollView）；
        // 走到这里的只有 Clip ⇒ 盒子夹到可用主轴，子项按自然位置排，超出交给裁剪。
        $reported = $mainSize;
        // 容差：等分子项求和会累积浮点误差（344/7×7 ≈ 344.00000000000006），
        //   裸 `>` 会把"正好放下"误报成溢出。
        if ($mainBounded && $mainSize > $maxMain + LayoutTolerance::EPSILON) {
            $this->handleOverflow($mainSize, $maxMain, $mains);
            $reported = $maxMain;
        }

        $crossSize = 0.0;
        foreach ($this->kids as $kid) {
            $cross = $vertical ? $kid->size()->width : $kid->size()->height;
            $crossSize = max($crossSize, $cross);
        }

        // 交叉轴按**盒自身的交叉尺寸**居中，不是约束上限：上限宽松时拿上限居中会把
        //   子项推到盒外（标题落到 y=143 而盒高仅 44.8）。盒尺寸按 `RenderBox::layout`
        //   同口径夹一次：父定死交叉轴时下界顶上来，须按夹后尺寸居中（否则 `Center` 退回 0）。
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

    // ── 内部 ────────────────────────────────────────────────────────

    /**
     * 新分配公式：起点（`basis`/自然尺寸）→ `grow` 加剩余 → `shrink` 加权压缩。
     * 只在声明 `shrink`/`basis` 时走到；纯 `grow` 走老路径（弹性子第一遍不量），
     * 与老路径唯一差别是 `grow` 在起点之上加增量（纯 `grow` 子起点 0 ⇒ 等价）。
     *
     * 压缩下限是 0，不做 `min-content`：被压到 0 的子不可见但**不报错**，今天无保底
     * （`Modifier::tighten()` 会把 `width` 一起夹成 0）。3 轮上限：每轮至少冻结一个子，
     * 跑满 3 轮说明一行 4 个以上子被压到 0，交给溢出策略而非"所有子都变 0 宽"。
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
            // 分母只含 `grow`（不含 `shrink`）：二者是独立权重，混在一个分母里会
            //   让"只压缩不放大"的子偷走放大份额。
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
                // 每轮从**当前** `$sizes` 重算总账，而非维护一份 overshoot 修正量：
                //   冻结子的 `shrink × size` 已自然归 0，重算更少出错。
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
                // 没有可压缩的子（或起点都是 0）⇒ 不在这里报错，交给溢出策略。
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
     * 用 `int` 哨兵而非 enum：`alignSelf` 要表达"未设"（`ALIGN_INHERIT`），
     *   enum 没有这个 case，会逼出一条"不该走到"的分支。
     * 三处都要用它（约束 / 紧主轴 / 定位）：只在 `placeChildren()` 解析的话，
     *   `STRETCH` 会变成"偏移算 0、尺寸没拉满"的假实现。
     * `default` 兜 `ALIGN_START` 与非法值（非法值可能从 `setFlexIntent()` 直接写进来）。
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
        // 按**子**解析而非按容器：`alignSelf(STRETCH)` 要能单独拉满某一个子；
        //   默认 `ALIGN_INHERIT` ⇒ 与容器一致，零行为变化。
        $stretch = $this->resolveAlign($kid) === FlexAlign::Stretch && $crossBounded;

        // `Stretch` 拉的是交叉轴不是主轴：`Column` ⇒ 宽拉满、`Row` ⇒ 高拉满。
        // 旧实现把主轴与交叉轴写反了（且与 `placeChildren()` 的 `Stretch=>0.0` 相反）；
        //   `Stretch` 在生产代码里长期零用法，写反的死分支不会报错，却会把同级
        //   `Expanded` 挤到 0 宽（真机第一帧 FATAL）。
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
        // 弹性子也要能被 `alignSelf(STRETCH)` 单独拉满交叉轴 ⇒ 按子解析。
        $stretch = $this->resolveAlign($kid) === FlexAlign::Stretch;

        // AOT 类型推断：三元分支里直接放属性读取（`$inner->maxWidth`）会被推成
        //   `php::Variant`，而形参是 `float` ⇒ `C2440`。先落局部标量再传。
        $crossMain = 0.0;
        if ($this->vertical
            && $stretch
            && $inner->hasBoundedWidth()) {
            $crossMain = $inner->maxWidth;
        }

        // 水平同理：`Row` 的交叉轴是**高**，弹性子也要拉满，否则同行高度参差。
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
        // padding **在这里施加**（不是 paint 期的 translate）：子盒的 `offset` 必须
        //   包含它，否则 `hitTest()` 与 `absoluteOrigin()` 拿到的坐标比画出的差一个
        //   padding ⇒ 命中区与绘制区错位（IME 候选框 / 菜单锚点同受影响）。
        $pen = $this->vertical ? $this->padding->top : $this->padding->left;

        foreach ($this->kids as $index => $kid) {
            $cross = $this->vertical ? $kid->size()->width : $kid->size()->height;
            // `alignSelf` 覆盖容器的 `crossAlign`。
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
        // 溢出消息带**子项构成**：只说"共需 X 可用 Y"还得自己数；带上类名一眼能分清
        //   "控件太多"（该换行）与"一个控件太宽"（该让它自己收窄）两种病。
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
            // 窗口帧里"内容比窗口宽"是合法状态（用户拖小了）：裁剪 + 留诊断，不中止；
            //   中止只留给纯布局（测试 / 快照）—— 那里溢出才是写错了。
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
            // `Scroll` 在**容器上不支持**：本枚举的 `Scroll` 是 `RenderScrollView` 的
            //   行为声明，容器自己滚与 `ScrollView` 职责重叠（已否决）。这不是"还没做"、
            //   是"别这么做" —— 消息必须指路，否则读者以为"等以后实现"。
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
