<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\Rect;
use Drift\Layout\RenderTable;
use Drift\Rendering\ColorToken;
use Drift\Text\TextAlign;

/**
 * `Table` 的 State：**持纵向偏移 + 算这一帧造哪几行 + 分配列宽**。
 *
 * ## 为什么偏移能住 State
 *
 * §12.3 的判据是"承载它的 Element 会不会被回收"。被回收的是**行**，本 State
 * 挂在表格自己身上（滚动容器不随滚动消失）⇒ 成立。反过来，**行内**的 UI 态
 * （正在编辑的草稿）会被回收，必须外置。
 *
 * ## 为什么窗口在 build 期算
 *
 * 框架的 `build → settle → layout` 顺序决定了布局期拿不到"还没造的 Widget"。
 * 于是视口高度取**上一帧**盒的实测值（首帧用 `ctx->height`，它是任何嵌套视口的
 * 上界 ⇒ 首帧一定造多不少）。⇒ 窗口永远是可见范围的**超集**，不会白。
 *
 * 同一个理由让**列宽**也在 build 期算：行 Widget 是 `Row[SizedBox(宽)…]`，
 * 列宽得在造行之前就有。窗口 resize 时 flex 列宽会滞后一帧 —— 与 `LazyColumn`
 * 的窗口同一性质，真机验收过没有可见抖动。**不为此引入两遍布局**
 * （`docs/ARCH-REVIEW-2026-10-21.md:106` 已判 no-op）。
 *
 * ## 列宽三层（第 ③ 层留给阶段 4）
 *
 * ```
 * ① 策略初值   fixed → px；flex → 0（占位）
 * ② 分剩余     flex 列按权重吃 (视口宽 − 固定列和 − 间距和)
 * ③ 每列夹      minWidth / maxWidth
 * ④ 用户拖拽   widthOverrides[key]   ← 阶段 4，最优先但仍夹 ③
 * ```
 *
 * @internal 应用只认 `Table`
 */
final class TableState extends State implements Scrollable
{
    private float $offsetY = 0.0;

    /** 滚动条淡出节律（懒建；与 `ScrollViewState` / `LazyListState` 同一份实现） */
    private ?ScrollBarFade $barFade = null;

    /**
     * 上一帧造了几行 —— **验收判据**（§6 阶段 1 闸门 ①：10000 行只造约一屏）。
     *
     * ★ 它不参与任何逻辑，只暴露给断言。写成"可由外部读到的计数"是因为
     *   "虚拟化有没有生效"无法从画面或性能观察（画面一样、快慢取决于机器）。
     */
    private int $createdRows = 0;

    /**
     * 上一次 `build()` 用的视口宽 —— 与盒实测宽比对，不一致就再来一帧。
     *
     * ★★ 为什么必须比对：`BuildContext::$width` 是**窗口**宽（根 context 由
     *   `Application::attach()` 按窗口约束造，整棵树共用），而表格的实际可用宽
     *   还要减掉导航栏 / 卡片 padding / 兄弟节点。首帧没有盒 ⇒ `viewportEstimate()`
     *   只能拿窗口宽当上界 —— 对**高度**是无害的（多造几行），对**宽度**是**有害**
     *   的：flex 列按偏大的宽分完，行宽超出表盒 ⇒ 右边的列被裁掉（真机现象：
     *   5 列只看见前 3 列，滚动一下才对 —— 因为滚动触发的重建赶上了盒已存在）。
     *
     * ★ 为什么靠 `tickFrame()` 而不是布局回写：`wantsFrame()` 没有"有脏 State"
     *   这一条（脏标记本该被**同帧** settle 消费，落在 layout 之后的脏没人管）
     *   ⇒ 标脏之后必须让帧时钟重新上膛。`tickFrame()` 的返回值正好是那根线
     *   （§17.4.9 的第 ④ 判据），且它跑在 `Frame::run()` **之后**（迟到挂载补 tick）
     *   ⇒ 读得到本帧刚排出的盒尺寸。
     */
    private ?float $widthUsedInBuild = null;

    /** 当前偏移（逻辑像素，≥ 0，已按上一帧内容长度夹过上界） */
    public function offsetY(): float
    {
        return $this->offsetY;
    }

    /** 上一帧实际造出的行数（虚拟化的验收判据，不参与逻辑） */
    public function createdRows(): int
    {
        return $this->createdRows;
    }

    public function scrollBy(float $delta): void
    {
        $next = max(0.0, $this->offsetY + $delta);

        if ($next === $this->offsetY) {
            return;   // 已在边界：不标脏（省一次重建）
        }

        $this->bar()->noteScroll();

        $this->setState(function () use ($next): void {
            $this->offsetY = $next;
        });
    }

    /**
     * 把**内容坐标**里的矩形滚进可见行区（`04` §6.3 的键盘可达性要求）。
     *
     * ★ 形状与 `LazyListState::ensureVisible()` 逐字同构：算术都在
     *   `RenderTable::revealDelta()`（那边知道表头占掉多少），本方法只"找盒 + 转交"。
     * ★ 阶段 1 没有单元格焦点 ⇒ 无调用方；阶段 2 的 `Widget::keyboard()` 一接就有。
     */
    public function ensureVisible(Rect $target, float $margin = 8.0): void
    {
        /** @var RenderTable|null $box */
        $box = $this->element()?->effectiveBoxOf(RenderTable::class);
        if ($box === null) {
            return;
        }

        $delta = $box->revealDelta($target, $margin, $this->offsetY);
        if ($delta !== 0.0) {
            $this->scrollBy($delta);
        }
    }

    /** 滚动条的显隐节律（懒建：没滚过的表不建，滚动条恒亮） */
    private function bar(): ScrollBarFade
    {
        $bar = $this->barFade;
        if ($bar === null) {
            $bar = new ScrollBarFade();
            $this->barFade = $bar;
        }

        return $bar;
    }

    #[\Override]
    public function tickFrame(float $timestampMs): bool
    {
        $reflow = $this->reflowIfWidthDrifted();
        $bar = $this->barFade;

        return $reflow || ($bar !== null && $bar->tickFor($this, $timestampMs));
    }

    /**
     * 盒实测宽 ≠ 上次 build 用的宽 ⇒ 标脏并要一帧（见 `$widthUsedInBuild`）。
     *
     * ★ 只在这一帧的 `Frame::run()` 之后调才有意义：那时盒已按**真实约束**排过。
     *   本帧 layout 之前的 tick（⓸）读到的还是上一帧的尺寸 —— 差值判断因此可能
     *   多要一帧，但**不会少**（差值仍然成立，下一帧再纠正一次就收敛）。
     * ★ 阈值 0.5：亚像素抖动（DPR 换算）不该引来重建。
     */
    private function reflowIfWidthDrifted(): bool
    {
        $used = $this->widthUsedInBuild;
        if ($used === null) {
            return false;
        }

        $size = $this->element()?->effectiveBoxOf(RenderTable::class)?->size();
        if ($size === null || \abs($size->width - $used) <= 0.5) {
            return false;
        }

        $this->markDirty();

        return true;
    }

    public function build(BuildContext $ctx): Widget
    {
        $view = $this->view();
        $columns = $view->columnList();
        $rows = $view->rowList();
        $count = \count($rows);
        $rowH = $view->rowHeightPx();
        $headerH = $view->headerHeightPx();
        $spacing = $view->columnSpacingPx();

        if ($view->keyMapper() === null) {
            Errors::report(
                'Table 没有 keyOf —— 必须调 keyOf(fn(mixed $row): string|int => ...)。'
                    . "\n" . '理由：它是列宽覆盖、选择集、Shift 锚点的唯一身份（阶段 2~4 全靠它）。'
            );
        }

        // 完整内容高（含表头）—— 滚动条与可滚上限都按它算
        $contentH = $headerH + $count * $rowH;

        $viewportH = $this->viewportEstimate($ctx, true);
        // ★★ 偏移的坐标系是**行区**，不是整表：表头是固定 chrome，不参与滚动。
        //   把表头算进内容坐标会让"内容 0 点"和"盒 0 点"差一个 headerH，
        //   于是 `body.offsetY = headerH − (offsetY − sliceOrigin)` 里两个量
        //   各带一次 headerH —— 未滚动时行区会画到表头下方 headerH 处（错位一整条）。
        //   ⇒ 行区可见高 = 视口高 − 表头高，可滚上限 = 总行高 − 行区可见高。
        $bodyH = max(0.0, $viewportH - $headerH);
        $totalRows = $count * $rowH;
        $offsetY = min(max(0.0, $this->offsetY), max(0.0, $totalRows - $bodyH));

        $viewportW = $this->viewportEstimate($ctx, false);
        $widths = $this->resolveWidths($columns, $viewportW);
        $this->widthUsedInBuild = $viewportW;

        $sum = 0.0;
        foreach ($widths as $w) {
            $sum += $w;
        }
        // ★ 内容宽至少铺满视口：固定列加起来不满时，右侧不能露出一条"没有底色"的缝
        $contentW = max($viewportW, $sum + $spacing * max(0, \count($columns) - 1));

        // 窗口：行区内容坐标 [offsetY, offsetY+bodyH) 换成行号区间
        $first = (int) \floor($offsetY / $rowH);
        $last = (int) \ceil(($offsetY + $bodyH) / $rowH);
        $first = max(0, min($first, $count));
        $last = max($first, min($last, $count));

        $headerKid = $this->headerRow($columns, $widths, $contentW, $headerH, $spacing);
        $zebra = $ctx->color(ColorToken::Pane);
        $striped = $view->isStriped();

        $lines = [];
        for ($i = $first; $i < $last; $i++) {
            $row = $rows[$i];
            $cells = [];
            foreach ($columns as $j => $column) {
                $cells[] = $this->cellShell($column->buildCell($row, $i), $widths[$j], $column);
            }

            // ★ key = **绝对序号**（不是窗内序号）：窗口滑动一格时第 k 行仍是第 k 行
            //   ⇒ diff 只增删两端各一个 Element，中间全部复用（同 LazyListState）。
            $line = SizedBox::make(Row::make()->children($cells)->spacing($spacing))
                ->width($contentW)
                ->height($rowH)
                ->key($i);

            if ($striped && $i % 2 === 1) {
                $line = $line->background($zebra);
            }

            $lines[] = $line;
        }

        $this->createdRows = $last - $first;

        $opacity = 1.0;
        $bar = $this->barFade;
        if ($bar !== null) {
            $opacity = $bar->opacity();
        }

        // 窗的起点 = 行区内容坐标里"第 first 行"的 y —— 与 `RenderScrollView` 的
        // `sliceOrigin` 同一含义（**行区**坐标，不含表头高；见上面 $bodyH 的注释）。
        return TableBox::make(
            0.0,
            $offsetY,
            $contentW,
            $headerH,
            $contentH,
            $first * $rowH,
            $headerKid,
            Column::make()->children($lines),
            $opacity,
        );
    }

    /**
     * 表头一行：**与数据行同宽同内边距**（否则文字不齐，而"不齐"看起来像设计问题）。
     *
     * ★ 表头不加斑马、不参与选择 —— 它是 chrome，`RenderTable` 单独给它底色与
     *   裁剪区（不随竖向滚动）。
     *
     * @param list<TableColumn> $columns
     * @param array<int, float> $widths
     */
    private function headerRow(array $columns, array $widths, float $contentW, float $headerH, float $spacing): Widget
    {
        $cells = [];
        foreach ($columns as $j => $column) {
            $cells[] = $this->cellShell(Text::make($column->titleText())->bold(), $widths[$j], $column);
        }

        return SizedBox::make(Row::make()->children($cells)->spacing($spacing))
            ->width($contentW)
            ->height($headerH);
    }

    /**
     * 一格的容器：**定宽 + 左右内边距 + 可选对齐**。
     *
     * ★ 对齐用 `Spacer` 推开而不是改文本样式：`cell()` 给的是任意 Widget，
     *   改不到它内部的 `Text`。★ `Start`（绝大多数列）**零额外包装**。
     */
    private function cellShell(Widget $content, float $width, TableColumn $column): Widget
    {
        $align = $column->alignMode();

        if ($align === TextAlign::End) {
            return $this->paddedCell(Row::make()->children([Spacer::make(), $content]), $width, $column);
        }

        if ($align === TextAlign::Center) {
            return $this->paddedCell(
                Row::make()->children([Spacer::make(), $content, Spacer::make()]),
                $width,
                $column,
            );
        }

        return $this->paddedCell($content, $width, $column);
    }

    /** 内边距 + 定宽（`0` 内边距时省掉一层 `Padding`） */
    private function paddedCell(Widget $inner, float $width, TableColumn $column): Widget
    {
        $pad = $column->paddingX();
        if ($pad <= 0.0) {
            return SizedBox::make($inner)->width($width);
        }

        return SizedBox::make(Padding::make($inner)->symmetric($pad, 0.0))->width($width);
    }

    /**
     * 三层列宽的 ①②③（第 ④ 层 = 阶段 4 的 `widthOverrides`，最优先但仍夹 ③）。
     *
     * @param list<TableColumn> $columns
     * @return array<int, float>
     */
    private function resolveWidths(array $columns, float $viewportW): array
    {
        $n = \count($columns);
        $gap = $this->view()->columnSpacingPx() * max(0, $n - 1);
        $avail = max(0.0, $viewportW - $gap);

        /** @var array<int, float> $widths */
        $widths = [];
        $used = 0.0;
        $flexSum = 0.0;

        foreach ($columns as $i => $column) {
            $spec = $column->widthSpec();
            if ($spec->isFlex()) {
                $widths[$i] = 0.0;   // 占位：等固定列量完才知道剩余
                $flexSum += $spec->weight();

                continue;
            }

            $widths[$i] = $spec->px();
            $used += $spec->px();
        }

        // ② 分剩余。剩余为负（固定列已超宽）⇒ flex 列拿 0，溢出交给裁剪 ——
        //   不在这里报错：阶段 1 的裁决是"装不下就裁"（有横滚之后这里才会变）。
        if ($flexSum > 0.0) {
            $free = max(0.0, $avail - $used);

            foreach ($columns as $i => $column) {
                $spec = $column->widthSpec();
                if (!$spec->isFlex()) {
                    continue;
                }

                $widths[$i] = $free * $spec->weight() / $flexSum;
            }
        }

        // ③ 每列夹（★ 夹完总和会变，所以 build 里重算 contentW）
        foreach ($columns as $i => $column) {
            $widths[$i] = $column->clampWidth($widths[$i]);
        }

        return $widths;
    }

    /**
     * 视口尺寸的估计。
     *
     * ★ 优先取**上一帧**盒的实测值（精确）；首帧没有盒 ⇒ 退回窗口的对应尺寸
     *   （上界：任何嵌套视口都不会比窗口更大）。**不读环境时钟、不问平台**
     *   （G3：帧的输入只有约束与树）。
     *
     * ★ 超过 2 倍窗口 ⇒ 上一帧的盒尺寸可疑（如窗口刚从小变大），打一条
     *   Diagnostic 而不改逻辑 —— 与 `LazyListState::viewportEstimate()` 同一条。
     */
    private function viewportEstimate(BuildContext $ctx, bool $vertical): float
    {
        $window = $vertical ? $ctx->height : $ctx->width;

        /** @var RenderTable|null $box */
        $box = $this->element()?->effectiveBoxOf(RenderTable::class);
        if ($box !== null) {
            $size = $box->size();
            if ($size !== null) {
                $viewport = $vertical ? $size->height : $size->width;
                if ($viewport > 0.0) {
                    if ($window > 0.0 && $viewport > $window * 2.0) {
                        Errors::report(sprintf(
                            'Table 的视口估计 %s 超过窗口%s %s 的 2 倍 —— 上一帧的盒尺寸可疑。'
                                . "\n" . '修法：确认窗口尺寸变化后盒已重排；必要时首帧用 ctx 尺寸兜底。',
                            (string) $viewport,
                            $vertical ? '高' : '宽',
                            (string) $window,
                        ), Errors::DIAGNOSTIC);
                    }

                    return $viewport;
                }
            }
        }

        return max(0.0, $window);
    }

    private function view(): Table
    {
        $widget = $this->widget();
        if (!$widget instanceof Table) {
            // 框架不变量：StatefulElement 按 get_class 配对，配错不可能发生
            Errors::report('TableState 只能配 Table（框架内部不变量被破坏）');
        }

        return $widget;
    }
}
