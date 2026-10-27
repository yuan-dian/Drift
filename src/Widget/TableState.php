<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\Axis;
use Drift\Geometry\Rect;
use Drift\Input\CursorShape;
use Drift\Input\PointerEvent;
use Drift\Layout\FlexAlign;
use Drift\Layout\StackAlign;
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

    /**
     * 横向偏移（阶段 5）。
     *
     * ★ 与 `$offsetY` **分开存**而不是合成一个 `Point`：两者的夹取上限来自不同的
     *   量（`contentW − 视口宽` vs `行数 × 行高 − 行区高`），且纵向的坐标系是
     *   **行区**（不含表头）。合成一个结构体只会让"哪一轴用哪套夹取"藏起来。
     */
    private float $offsetX = 0.0;

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
     * 列宽拖拽的临时量（`null` = 当前没在拖）。
     *
     * ★ 是**手势进行中**的量，不是数据所有权 —— 拖出来的最终宽度照样交回应用
     *   （`onColumnResize`）。放在 `State` 里因为只有它能在 down 与 move 之间记住起点。
     * ★ 记"起始宽度 + 增量"而不是"列宽 = 指针位置"：指针按下的位置在手柄上（列的
     *   右缘），直接按位置算会让列宽瞬间缩掉一个手柄宽，视觉上"跳一下"。
     */
    private ?float $resizeStartX = null;

    private float $resizeStartWidth = 0.0;

    /** 拖动中最后一次上报的宽度 —— 抬起/拖出窗口都报它（不回退，应用已采纳） */
    private float $resizeLastWidth = 0.0;

    /** 真的动过没有（纯点一下手柄不算"用户改了列宽"，不产生上报） */
    private bool $resizeMoved = false;

    /**
     * 鼠标停在哪一列的分割线上 / 正在拖哪一列（`null` = 都没有）。
     *
     * ★ 只影响那条线的**颜色**，不影响布局 —— 所以不需要 key、不需要 Element 身份，
     *   重建时靠父链上的 `hover()` / `pointer()` 声明继续生效。
     * ★ 两个字段而不是一个：拖动时指针可能已经离开手柄（拖出表头），此时 hover
     *   已经 false，但线还该保持高亮 —— 合成一个字段会让拖动中的高亮闪断。
     */
    private ?string $dividerHoverKey = null;

    private ?string $dividerDragKey = null;

    /**
     * 选中行的染色透明度（0–255，约 14%）。
     *
     * ★ 为什么是**半透明的 `Accent`** 而不是新加一个 `AccentContainer` 主题 token：
     *   压在表格底 `Surface` 上要得到"淡蓝"只需要 alpha，多一个 token 就得同时
     *   维护三套主题（light / dark / high-contrast）的三个值，而它们全都等于
     *   "把 Accent 按同一比例淡下去" —— 那是公式，不是设计决策。
     */
    private const SELECTED_ALPHA = 36;

    /** 列宽手柄的命中区宽（逻辑像素）—— 与 Excel 同量级 */
    private const RESIZE_HANDLE_W = 5.0;

    /** 表头列分割线宽（逻辑像素）—— 发丝线，1px 是 DPI 下的最小可辨宽度 */
    private const DIVIDER_W = 1.0;

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

    /** 当前横向偏移（逻辑像素，≥ 0；上界 = 内容宽 − 视口宽，布局期夹取） */
    public function offsetX(): float
    {
        return $this->offsetX;
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
     * 按轴滚动（横条拖动走这里；滚轮 / 竖条仍走 `scrollBy()`）。
     *
     * ★ 两个轴共用同一份 `ScrollBarFade`：横条与竖条同时淡出是对的 —— 它们提示
     *   的是同一件事"这块区域还能滚"，分两套节律只会出现"一根亮一根暗"。
     * ★ 上界不在这里夹（`scrollBy()` 同一裁决）：横向内容宽要等 `resolveWidths()`
     *   算完才知道，那是 build 期的事。
     */
    public function scrollByAxis(Axis $axis, float $delta): void
    {
        if ($axis === Axis::Vertical) {
            $this->scrollBy($delta);

            return;
        }

        $next = max(0.0, $this->offsetX + $delta);

        if ($next === $this->offsetX) {
            return;
        }

        $this->bar()->noteScroll();

        $this->setState(function () use ($next): void {
            $this->offsetX = $next;
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

        $this->assertInteractivityDeclared($view, $columns);

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

        // ★ 横向夹取与纵向同一条纪律：State 只夹下界，上界按**本帧**算出的内容宽
        //   夹一次。列宽被拖窄 / 窗口变宽之后，这里当场回弹，不必等用户再滚一下。
        $offsetX = min(max(0.0, $this->offsetX), max(0.0, $contentW - $viewportW));

        // ★ 夹取结果**写回字段**（不经 `setState` ⇒ 不标脏、不多一帧）。
        //   理由不是洁癖：State 里存的是"用户增量累加值"，而内容会变短
        //   （过滤 / 列宽被拖窄 / 窗口变宽）。旧值一旦长期大于新上限，之后每一笔
        //   增量都先拿去消耗这段**死区** —— 真机表现是"拖着条，画面不动"，
        //   而条本身贴在末端，看起来像"拖不动"。写回之后增量立刻生效。
        $this->offsetY = $offsetY;
        $this->offsetX = $offsetX;

        $first = (int) \floor($offsetY / $rowH);
        $last = (int) \ceil(($offsetY + $bodyH) / $rowH);
        $first = max(0, min($first, $count));
        $last = max($first, min($last, $count));

        $headerKid = $this->headerRow($ctx, $columns, $widths, $contentW, $headerH, $spacing);
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
            $line = SizedBox::make(Row::make()->children($cells)->spacing($spacing)->cross(FlexAlign::Center))
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
            $offsetX,
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
    private function headerRow(
        BuildContext $ctx,
        array $columns,
        array $widths,
        float $contentW,
        float $headerH,
        float $spacing,
    ): Widget {
        $sort = $this->view()->sortState();

        $cells = [];
        foreach ($columns as $j => $column) {
            $cells[] = $this->headerCell($ctx, $column, $widths[$j], $sort, $j);
        }

        return SizedBox::make(Row::make()->children($cells)->spacing($spacing)->cross(FlexAlign::Center))
            ->width($contentW)
            ->height($headerH);
    }

    /**
     * 一格表头：标题 +（参与排序时的）方向箭头 +（可排时的）点击。
     *
     * ★ 箭头**只画在当前参与排序的列**上（不是所有可排序列都画个灰箭头）：
     *   "有箭头"就等于"这一列正在排序"，省掉一个"未激活态颜色"的决策，也让
     *   画面上只有一个视觉焦点。可排但未排的列靠指针变手形提示。
     * ★ 箭头在标题**右侧**、标题左对齐 ⇒ 箭头出现/消失时文字位置不动。
     */
    private function headerCell(
        BuildContext $ctx,
        TableColumn $column,
        float $width,
        TableSort $sort,
        int $columnIndex,
    ): Widget
    {
        $title = Text::make($column->titleText())->bold();
        $dir = $sort->directionOf($column->keyName());

        $content = $title;
        if ($dir !== null) {
            $icon = $dir === TableSortDirection::Ascending ? IconName::ArrowUp : IconName::ArrowDown;
            $content = Row::make()
                ->children([$title, Icon::make($icon, 12.0)->color($ctx->color(ColorToken::Accent))])
                ->spacing(4.0)
                ->cross(FlexAlign::Center);
        }

        $cell = $this->cellShell($content, $width, $column);

        // 叠层顺序 = 从下到上：内容 → 右缘分割线 → 手柄。
        //   ★ **每一列都叠**（原来只在 resizable 时叠）：分割线是"列边界"，每列都有；
        //     只有手柄才是可调的列才有。两者位置重合但职责分层 —— 线看得见、手柄按得到。
        //   ★ 分割线**必须是浮层**，不能当 `Row` 的兄弟节点：那 1px 会被列宽分配算进去
        //     （行是 `Row[SizedBox(宽)…]`，表头多出 n-1 个 1px ⇒ 表头与数据行错位）。
        //   ★ 手柄包在 `Stack` 里（子 vs 自己）：`InputRouter::pointerOwner()` 从命中点
        //     往上遇到**第一个**声明 ownsPress 的节点就返回 ⇒ 命中手柄时是手柄赢，
        //     命中格内其它地方是整格赢。**不需要改路由**。
        $overlay = [$cell, $this->headerDivider($ctx, $column)];

        if ($column->isResizable()) {
            $overlay[] = $this->resizeHandle($column, $width);
        }

        $cell = Stack::make()->alignment(StackAlign::TopRight)->children($overlay);

        if (!$column->isSortable()) {
            return $cell;
        }

        // ★ `ownsPress: true` 与行级点击同一条理由：表头既不可聚焦也不是
        //   `Activatable` ⇒ 默认路径走 `focusHit + activateFocusedWidget()`，
        //   回调根本收不到 down（`PITFALLS.md`「pointer() 的三种静默不生效」）。
        return $cell
            ->cursor(CursorShape::PointingHand)
            ->pointer(
                function (PointerEvent $event, Element $self) use ($column, $columnIndex): ?CursorShape {
                    if ($event->isDown() && $event->isPrimary()) {
                        $this->noteHeaderTap($column, $columnIndex);
                    }

                    return null;
                },
                ownsPress: true,
            );
    }

    /**
     * 表头列右缘的分割线（1px 发丝线，全表头高）。
     *
     * ★ **每列都画**，包括最后一列：最后一列的那条就是表格的右边框。内容宽铺不满
     *   视口时（`contentW` 被撑到视口宽、右侧留白）它也是"表格到此为止"的界。
     * ★ 平时 `Border` 色，鼠标停到该列右缘或正在拖该列时变 `Accent`：这就是"哪里
     *   能拖"的**事前**提示 —— 光标变 `SizeWE` 只在已经命中之后才出现，来不及定位。
     * ★ 高度取表头高而不是 `Stack` 的整体高：`Stack` 的尺寸取 `max(子)`，若内容
     *   比表头矮，线会跟着变矮，上下留下缺口。
     */
    private function headerDivider(BuildContext $ctx, TableColumn $column): Widget
    {
        return SizedBox::make()
            ->width(self::DIVIDER_W)
            ->height($this->view()->headerHeightPx())
            ->background($this->dividerColor($ctx, $column));
    }

    /**
     * 分割线取色：悬停 / 拖动该列 ⇒ `Accent`，否则 `Border`。
     *
     * ★ 用列 key 比对而不是列序号：拖动中被重建的是整个表头，序号会随 `columns`
     *   顺序稳定，但 key 是本仓一贯的"列身份"（`TableSort` / `columnWidths` 也用它）。
     */
    private function dividerColor(BuildContext $ctx, TableColumn $column): Color
    {
        $key = $column->keyName();
        if ($this->dividerHoverKey === $key || $this->dividerDragKey === $key) {
            return $ctx->color(ColorToken::Accent);
        }

        return $ctx->color(ColorToken::Border);
    }

    /**
     * 鼠标停到 / 离开某列的分割线。
     *
     * ★ 与 `noteRowHover()` 同一条纪律：**状态没变就不 setState**。表头有 N 列，
     *   鼠标横向扫过会连续 enter/leave N 次，每次都重建整表的话扫一遍就是 N 帧。
     */
    private function noteDividerHover(string $key, bool $entered): void
    {
        if ($entered) {
            if ($this->dividerHoverKey === $key) {
                return;
            }

            $this->setState(function () use ($key): void {
                $this->dividerHoverKey = $key;
            });

            return;
        }

        // 别的列的"离开"不该清掉当前悬停列（快速划过时顺序不保证）
        if ($this->dividerHoverKey !== $key) {
            return;
        }

        $this->setState(function (): void {
            $this->dividerHoverKey = null;
        });
    }

    /**
     * 列宽手柄：表头右缘一条 5px 的透明命中区。
     *
     * ★ **自己不画任何东西**：可见的那条线是 `headerDivider()`（每列都有）。手柄只
     *   负责命中区 + 光标 + 把 hover / 拖动上报给那条线取色。分开的理由：线要每列都
     *   有（视觉统一），命中区只能可调的列才有（不该拖的列不该出现可拖的暗示）。
     * ★ 用 `Stack(TopRight)` 做成**浮在格子上的一层**，不是格内第 N 个子节点：
     *   后者会被列宽分配算进去（内容宽被手柄吃掉 5px，而且越拖越窄）。
     * ★ 高度取表头高 ⇒ Stack 被撑满整条表头，命中区覆盖上下的完整高度。
     */
    private function resizeHandle(TableColumn $column, float $width): Widget
    {
        return SizedBox::make()
            ->width(self::RESIZE_HANDLE_W)
            ->height($this->view()->headerHeightPx())
            ->cursor(CursorShape::SizeWE)
            ->hover(function (bool $entered) use ($column): void {
                $this->noteDividerHover($column->keyName(), $entered);
            })
            ->pointer(
                function (PointerEvent $event, Element $self) use ($column, $width): ?CursorShape {
                    if ($event->isDown() && $event->isPrimary()) {
                        $this->resizeStartX = $event->x;
                        $this->resizeStartWidth = $width;
                        $this->resizeLastWidth = $width;
                        $this->resizeMoved = false;
                        $this->dividerDragKey = $column->keyName();

                        return null;
                    }

                    if ($event->isMove()) {
                        $this->dragResize($column, $event->x);

                        return null;
                    }

                    if ($event->isUp() || $event->isLeave()) {
                        $this->endResize($column);
                    }

                    return null;
                },
                ownsPress: true,
            );
    }

    /**
     * 拖动中：`起始宽 + 增量`，再过一遍 `clampWidth()`。
     *
     * ★ 增量用**事件坐标系**直接相减（不减 `absoluteOrigin`）：只有差值有意义，
     *   平移不影响。Slider 之所以要减原点，是因为它拿的是绝对位置。
     * ★ 夹在这里而不是交给应用：`minWidth` / `maxWidth` 是列自己的硬约束，应用拿到
     *   超出范围的值再夹一次等于同一件事做两遍。
     */
    private function dragResize(TableColumn $column, float $pointerX): void
    {
        $startX = $this->resizeStartX;
        if ($startX === null) {
            return;
        }

        $notify = $this->view()->columnResizeHandler();
        if ($notify === null) {
            return;
        }

        $width = $column->clampWidth($this->resizeStartWidth + ($pointerX - $startX));
        if ($width === $this->resizeLastWidth) {
            return;   // 夹住之后没变（拖到 min 还在继续拖）⇒ 不刷帧
        }

        $this->resizeLastWidth = $width;
        $this->resizeMoved = true;
        $notify($column->keyName(), $width, false);
    }

    /**
     * 抬起 / 拖出窗口：上报 `$finished = true`，清掉临时量。
     *
     * ★ 报**最后那个值**而不是按抬起位置重算：`leave`（拖出窗口）时指针已经在
     *   窗口外，位置没有意义；而应用早就采纳了拖动中的值，回退会让列"跳回去"。
     * ★ 纯点一下手柄（没 move）不上报：那不是"用户改了列宽"，报一次会把应用的
     *   "有变化才存盘"逻辑打成"每次点表头都存盘"。
     */
    private function endResize(TableColumn $column): void
    {
        if ($this->resizeStartX === null) {
            return;
        }

        $this->resizeStartX = null;
        $this->dividerDragKey = null;

        if (!$this->resizeMoved) {
            return;
        }

        $this->resizeMoved = false;

        $notify = $this->view()->columnResizeHandler();
        if ($notify !== null) {
            $notify($column->keyName(), $this->resizeLastWidth, true);
        }
    }

    /**
     * 表头被按下：算出"点完之后该是什么排序"并交出去（受控，不改自己的任何状态）。
     *
     * ★ 控件没有"当前排序"的私有副本 —— 箭头画的是 `$view->sortState()`，与
     *   选择一样：应用不采纳，画面就停在旧值（所以不接上报是挂载期 Fatal）。
     * ★ 上报带**列序号**（`int`）：应用若按"第几列"分派比较规则，直接拿它当数组
     *   下标即可，不必把 key 再翻译一遍。`TableSort` 里的列 key 仍然给出（那是跨插列
     *   稳定的身份）—— 两条通道用途不同，不是同一个东西的两种写法。
     */
    private function noteHeaderTap(TableColumn $column, int $columnIndex): void
    {
        $notify = $this->view()->sortChangeHandler();
        if ($notify === null) {
            return;
        }

        $notify($this->nextSort($column->keyName()), $columnIndex);
    }

    /**
     * 三态循环：无 → 升 → 降 → 无（`ag-Grid` / `WPF DataGrid` 同款）。
     *
     * ★ 点**非主键列**（含"它是次键"）⇒ 升格为主键升序，而不是在次键上循环：
     *   阶段 3 的 UI 只有单键（多键要 Shift+点，见 `TableSort` 的 docblock）。
     *   否则"点一下次键列，箭头出现在主键列上"会显得毫无反应。
     */
    private function nextSort(string $column): TableSort
    {
        $current = $this->view()->sortState();
        $dir = $current->directionOf($column);

        if ($dir === null || $current->primaryColumn() !== $column) {
            return TableSort::by($column, TableSortDirection::Ascending);
        }

        if ($dir === TableSortDirection::Ascending) {
            return TableSort::by($column, TableSortDirection::Descending);
        }

        return TableSort::none();
    }

    /**
     * 交互声明的响亮失败（排序 + 列宽）。
     *
     * 1. 有列 `sortable()` 但没接 `onSortChange()` ⇒ 表头可点、点了没反应，箭头停在旧值。
     * 2. `sort()` 指向的列不存在 ⇒ 箭头永远画不出来（列 key 拼错时**完全静默**）。
     * 3. 有列 `resizable()` 但没接 `onColumnResize()` ⇒ 手柄在、光标也变，拖半天
     *    列宽不动，**且不报错**。
     *
     * ★ 第 3 条不检查 `columnWidths()` 里的多余 key：那是**历史偏好**（列删了、
     *   存盘的旧值还在），静默忽略才是对的。第 2 条不同 —— 排序是当前状态，
     *   拼错就是拼错。两者的差别在"这是历史还是现在"。
     *
     * @param list<TableColumn> $columns
     */
    private function assertInteractivityDeclared(Table $view, array $columns): void
    {
        $keys = [];
        $sortable = null;
        $resizable = null;
        foreach ($columns as $column) {
            $keys[$column->keyName()] = true;
            if ($sortable === null && $column->isSortable()) {
                $sortable = $column->keyName();
            }
            if ($resizable === null && $column->isResizable()) {
                $resizable = $column->keyName();
            }
        }

        if ($resizable !== null && $view->columnResizeHandler() === null) {
            Errors::report(sprintf(
                'Table 的列 "%s" 声明了 resizable() 却没有 onColumnResize() —— 列宽是受控的，'
                    . "\n" . '控件只算"拖到了多宽"并交出去，应用不接就等于拖了没反应。'
                    . "\n" . '修法：加上 ->onColumnResize(fn(string $key, float $w, bool $done) => ...)，'
                    . "\n" . '或去掉 ->resizable()。',
                $resizable,
            ));
        }

        if ($sortable !== null && $view->sortChangeHandler() === null) {
            Errors::report(sprintf(
                'Table 的列 "%s" 声明了 sortable() 却没有 onSortChange() —— 排序是受控的，'
                    . "\n" . '控件只算"点完之后该是什么排序"并交出去，应用不接就等于点了没反应。'
                    . "\n" . '修法：加上 ->onSortChange(fn(TableSort $next) => ...)，或去掉 ->sortable()。',
                $sortable,
            ));
        }

        foreach ($view->sortState()->keys() as [$key, $_direction]) {
            if (!isset($keys[$key])) {
                Errors::report(sprintf(
                    'Table 的排序指向了不存在的列 "%s"。现有列：%s',
                    $key,
                    \implode(', ', \array_keys($keys)),
                ));
            }
        }
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
            return $this->paddedCell(Row::make()->children([Spacer::make(), $content])->cross(FlexAlign::Center), $width, $column);
        }

        if ($align === TextAlign::Center) {
            return $this->paddedCell(
                Row::make()->children([Spacer::make(), $content, Spacer::make()])->cross(FlexAlign::Center),
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

        // ④ 用户拖出来的宽度**最优先**，且**不参与 flex 分配**（拖过就变固定宽）。
        //   ★ 指向不存在的列时**静默忽略**（与 `sort()` 不同）：override 是"历史
        //     偏好"——列被删掉之后存盘的旧值还在，是正常情况，不是拼写错误。
        $overrides = $this->view()->columnWidthOverrides();

        foreach ($columns as $i => $column) {
            $override = $overrides[$column->keyName()] ?? null;
            if ($override !== null) {
                $widths[$i] = $override;
                $used += $override;

                continue;
            }

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

                // ★ 被 override 的列**不参与重算**：它的 `widthSpec()` 可能还是 flex，
                //   只按 isFlex() 判会让第 ④ 层被第 ② 层覆盖回去（真机表现：拖宽
                //   一列，松手后又弹回原值 —— 因为 override 只挡住了"初值"没挡住"重算"）。
                if (!$spec->isFlex() || isset($overrides[$column->keyName()])) {
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
