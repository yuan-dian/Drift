<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\Rect;
use Drift\Input\CursorShape;
use Drift\Input\PointerEvent;
use Drift\Layout\RenderTable;
use Drift\Rendering\Color;
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

    /**
     * 选中行的染色透明度（0–255，约 14%）。
     *
     * ★ 为什么是**半透明的 `Accent`** 而不是新加一个 `AccentContainer` 主题 token：
     *   压在表格底 `Surface` 上要得到"淡蓝"只需要 alpha，多一个 token 就得同时
     *   维护三套主题（light / dark / high-contrast）的三个值，而它们全都等于
     *   "把 Accent 按同一比例淡下去" —— 那是公式，不是设计决策。
     */
    private const SELECTED_ALPHA = 36;

    /**
     * 鼠标悬停在哪一行（**纯 UI 态**，住 State 而不是受控）。
     *
     * ★ 判据是"应用要不要读它、要不要从外部改它"：批量删除要读**选中集**，
     *   没人要读"鼠标现在悬停在第几行"（§11.4）。
     */
    private string|int|null $hoverKey = null;

    /**
     * Shift 连选的锚点（**纯 UI 态**）。
     *
     * ★ 存 key 而不是行号：排序/过滤后行号会漂，key 不会（这也是 `keyOf()` 必填的
     *   原因之一）。代价是 Shift 点击时要线性扫一次行找它的下标 —— 点击是低频操作，
     *   而 10000 行线性扫 ≈ 亚毫秒。
     */
    private string|int|null $anchorKey = null;

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

    /**
     * 行的底色：**选中 > 悬停 > 斑马**，都不适用才 `null`（透出表格底）。
     *
     * ★ 三态合成在**一处**决定：行容器只接收一个颜色，`cellBuilder` 完全不知道
     *   自己被选中（这是"加选择不用改每列 cell"的唯一办法，§4 裁决 4）。
     * ★ 不做多层叠加（选中色 + 悬停色）——叠加出来的颜色在两套主题下都不可控，
     *   而"选中"本来就该压过"悬停"。
     */
    private function rowBackground(int $index, string|int|null $key, BuildContext $ctx, bool $striped): ?Color
    {
        if ($key !== null) {
            $selection = $this->view()->selectionSet();
            if ($selection !== null && $selection->has($key)) {
                return $ctx->color(ColorToken::Accent)->withAlpha(self::SELECTED_ALPHA);
            }

            if ($this->hoverKey === $key) {
                return $ctx->color(ColorToken::Hover);
            }
        }

        return $striped && $index % 2 === 1 ? $ctx->color(ColorToken::Pane) : null;
    }

    /** 悬停进入/离开——只在**目标真的变了**时才 setState（指针在一行内移动不重建） */
    private function noteRowHover(string|int $key, bool $entered): void
    {
        if ($entered) {
            if ($this->hoverKey === $key) {
                return;
            }

            $this->setState(function () use ($key): void {
                $this->hoverKey = $key;
            });

            return;
        }

        // 别的行的"离开"消息不该清掉当前悬停行（快速划过时顺序不保证）
        if ($this->hoverKey !== $key) {
            return;
        }

        $this->setState(function (): void {
            $this->hoverKey = null;
        });
    }

    /**
     * 行被按下：先交 `onRowTap()`，再按模式算选择变化并交出去。
     *
     * ★ 两者**互不替代**（`Table::onRowTap()` 的 docblock）：启用选择的表也需要
     *   "点行打开详情"。
     * ★ 用 **Down** 而不是 Up 触发：列表/表格类控件的既有手感是"按下即选"
     *   （`Explorer` / `DataGrid` 同款）。拖拽场景下 Up 更稳，但阶段 2 没有行拖拽。
     */
    private function noteRowTap(mixed $row, int $index, PointerEvent $event): void
    {
        $view = $this->view();

        $tap = $view->rowTapHandler();
        if ($tap !== null) {
            $tap($row, $index);
        }

        $selection = $view->selectionSet();
        if ($selection === null) {
            return;
        }

        $keyOf = $view->keyMapper();
        $key = $keyOf === null ? null : $keyOf($row);
        if ($key === null) {
            return;
        }

        $next = $this->nextSelection($selection, $key, $index, $event);

        // 锚点：无修饰的点击把锚点挪到这里（Shift 的起点）；Shift 自身不动锚点
        if (!$event->ctrl && !$event->shift) {
            $this->anchorKey = $key;
        } elseif ($event->shift && $this->anchorKey === null) {
            $this->anchorKey = $key;
        }

        $notify = $view->selectionChangeHandler();
        $notify?->__invoke($next);
    }

    /**
     * 点完之后"应该"是什么选中集（受控：只是算出来，改不改由应用决定）。
     *
     * | 修饰键 | 单选 | 多选 |
     * |---|---|---|
     * | 无 | 替换为该行 | 替换为该行（`WPF DataGrid` / `ag-Grid` 的默认） |
     * | Ctrl | 同无修饰 | 反选该行 |
     * | Shift | 同无修饰 | 从锚点到该行**替换**为区间 |
     */
    private function nextSelection(
        TableSelection $current,
        string|int $key,
        int $index,
        PointerEvent $event,
    ): TableSelection {
        if ($this->view()->selectionModeValue() === TableSelectionMode::Single) {
            return $current->only([$key]);
        }

        if ($event->shift && $this->anchorKey !== null) {
            $anchorIndex = $this->keyIndex($this->anchorKey);
            if ($anchorIndex !== null) {
                return TableSelection::of($this->keysBetween($anchorIndex, $index));
            }
        }

        return $event->ctrl ? $current->toggle($key) : $current->only([$key]);
    }

    /** 某个 key 在**当前行序**里的下标（排序/过滤后行序会变 ⇒ 不能缓存行号） */
    private function keyIndex(string|int $key): ?int
    {
        $keyOf = $this->view()->keyMapper();
        if ($keyOf === null) {
            return null;
        }

        foreach ($this->view()->rowList() as $index => $row) {
            if ($keyOf($row) === $key) {
                return $index;
            }
        }

        return null;
    }

    /**
     * 两个行下标之间的所有 key（含两端）—— Shift 连选的区间。
     *
     * @return list<string|int>
     */
    private function keysBetween(int $from, int $to): array
    {
        $keyOf = $this->view()->keyMapper();
        if ($keyOf === null) {
            return [];
        }

        $rows = $this->view()->rowList();
        $low = min($from, $to);
        $high = max($from, $to);
        $keys = [];
        for ($i = $low; $i <= $high; $i++) {
            if (!isset($rows[$i])) {
                continue;
            }

            $key = $keyOf($rows[$i]);
            if ($key !== null) {
                $keys[] = $key;
            }
        }

        return $keys;
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

        // ★ 声明了选择却没接上报 = "点行改了选中但没人收到"（选中色停在旧值，
        //   而点一次就把差异放大一层）。这与 `Table` 的 docblock 承诺一致：响亮失败。
        if ($view->selectionSet() !== null && $view->selectionChangeHandler() === null) {
            Errors::report(
                'Table 声明了 selection() 却没有 onSelectionChange() —— 选择是受控的，'
                    . "\n" . '控件只算"点完之后该是什么"并交出去，应用不接就等于点了没反应。'
                    . "\n" . '修法：加上 ->onSelectionChange(fn(TableSelection $next) => ...)，'
                    . '或去掉 ->selection()（纯展示表不启用选择）。'
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
        $striped = $view->isStriped();
        $keyOf = $view->keyMapper();
        $selectable = $view->selectionSet() !== null;
        // ★ 交互行 = 可选中 或 有 tap 回调。两者都没有 ⇒ 纯展示表：
        //   不挂 hover（悬停高亮是"这里能点"的暗示，会误导）、不算 key（零成本）。
        $interactive = $selectable || $view->rowTapHandler() !== null;

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

            // ★★ 行身份只在**需要**时算：`keyOf` 是应用的回调，虚拟化表里逐行调它
            //   是 O(可见行) 但不是零 —— 纯展示表没有理由付这笔钱。
            $rowKey = $interactive && $keyOf !== null ? $keyOf($row) : null;

            $bg = $this->rowBackground($i, $rowKey, $ctx, $striped);
            if ($bg !== null) {
                $line = $line->background($bg);
            }

            if ($interactive) {
                // ★ 行级输入声明挂在**行容器**上（不是每个 cell）：`hover()` / `pointer()`
                //   沿父链找"最深的声明者" ⇒ cell 里的按钮自己声明时自然赢，行不抢。
                //
                // ★★ 必须 `ownsPress: true`：`pointer()` 的**捕获期**只在"按下归我"之后
                //   才建立（`InputRouter` 的按下路由：`ownsPress` ⇒ 捕获 + 派发；
                //   否则走 `focusHit` + `activateFocusedWidget()`，回调根本收不到 down）。
                //   行没有"激活"语义（它不是 `Activatable`、也不该占 Tab 位），
                //   所以只能走这一条。
                //   ⚠️ 曾担心"抢走 cell 里按钮的按下"：`InputRouter::pointerOwner()`
                //   现在遇到更近的可聚焦 / `Activatable` 控件就让位（2026-10-26 同批改的）
                //   ⇒ "整行可点 + 格内放按钮"两者能共存。
                // ★ 签名必须是**两参** `(PointerEvent, Element)`：捕获期派发固定给两个
                //   （`InputRouter::dispatchToCapture()` 的 `$handler($event, $node)`）。
                //   AOT 下只写一个参数会当场 Fatal（"expects exactly 1 argument, 2 given"），
                //   而它被捕获期路径的 try/catch 接住 ⇒ 现象是整页变错误占位，不是崩溃。
                $line = $line->pointer(
                    function (PointerEvent $event, Element $self) use ($row, $i): ?CursorShape {
                        if ($event->isDown() && $event->isPrimary()) {
                            $this->noteRowTap($row, $i, $event);
                        }

                        return null;
                    },
                    ownsPress: true,
                );

                if ($rowKey !== null) {
                    $line = $line->hover(function (bool $entered) use ($rowKey): void {
                        $this->noteRowHover($rowKey, $entered);
                    });
                }
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
