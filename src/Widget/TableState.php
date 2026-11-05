<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\Alignment;
use Drift\Geometry\Axis;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Input\CursorShape;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Input\TextKeymap;
use Drift\Layout\ColumnWidths;
use Drift\Layout\FlexAlign;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderTable;
use Drift\Layout\RenderTextField;
use Drift\Rendering\Color;
use Drift\Rendering\ColorToken;
use Drift\Rendering\MetricToken;
use Drift\Text\TextAlign;
use Drift\Text\TextChange;
use Drift\Text\TextDraft;
use Drift\Text\TextMetricsProvider;
use Drift\Text\TextStyle;

/**
 * `Table` 的 State：持滚动偏移 + 算这一帧造哪些行/列 + 分配列宽。
 *
 * 视口宽高取**本帧布局要拿到的约束**（`RenderBox::constraints()`）：build 期布局还没
 * 发生，首帧读到 `null` ⇒ 退回 `ctx` 当上界，再由 `ConstraintObserver` 在同一帧内
 * 拿到真值重造（不会"先画错一帧再跳"）。列宽必须在造行之前算出来。
 * 编辑态住这里而不是行里：行会被虚拟化回收，本类不随滚动消失。单元格编辑由本类
 * 实现 `EditableText`（不往格里塞 `TextField`）：焦点只一个节点，IME 与剪贴板环
 * 认 `focusedState() instanceof EditableText`。
 *
 * @internal 应用只认 `Table`
 */
final class TableState extends State implements Scrollable, EditableText, ConstraintObserver
{
    /** 纵向滚动状态机（偏移 + 动画 + 淡出）；横向是 `$laneX`。两者各一份偏移，但共用同一份淡出节律 */
    private ?ScrollLane $laneY = null;

    private ?ScrollLane $laneX = null;

    /** 光标格：行 / 列**下标**，不是 key（光标答"眼睛在哪"要留在原位，选择集才是身份） */
    private int $focusedRow = 0;

    private int $focusedCol = 0;

    /** 焦点在本表吗。失焦不画描边（画了就是撒谎），但保留光标格（Tab 出去再回来停在原处） */
    private bool $hasFocus = false;

    /** 本帧 build 用的视口宽（`-1` = 还没 build 过）；约束反馈拿它比 */
    private float $builtViewportW = -1.0;

    /**
     * 上一帧的几何快照（`null` = 还没 build 过）。只由 `build()` 写，别处只读。
     *
     * 键盘 / 指针必须在 build 之外当场响应，而列宽只有 build 里算得出来 ⇒ 只能读这份。
     */
    private ?TableGeometry $geometry = null;

    /**
     * 上一帧的文本度量与编辑样式。双击落插入符时编辑盒还没建出来，只能用量上一帧的。
     *
     * 样式须与 `InlineEditorBox::editorStyle()` 同源，否则插入符会落在相邻字符上。
     */
    private ?TextMetricsProvider $editorMetrics = null;

    private ?TextStyle $editorStyle = null;

    /** 滚动条淡出节律（懒建；两根轴共用一份，见 `$laneY`） */
    private ?ScrollBarFade $barFade = null;

    /** 上一帧造了几行 —— 虚拟化唯一的可观测量（画面与快慢都看不出虚拟化是否生效），不参与逻辑 */
    private int $createdRows = 0;

    /** 上一帧造了几列（与 `$createdRows` 分开：行窗口与列窗口是两个独立判据） */
    private int $createdColumns = 0;

    /** 拖拽列宽的起点 x（`null` = 没在拖）。记"起点 + 增量"而不是指针位置：后者会让列宽在按下瞬间缩掉一个手柄宽 */
    private ?float $resizeStartX = null;

    private float $resizeStartWidth = 0.0;

    /** 拖动中最后一次上报的宽度 —— 抬起/拖出窗口都报它（不回退，应用已采纳） */
    private float $resizeLastWidth = 0.0;

    /** 真的动过没有（纯点一下手柄不算"用户改了列宽"，不产生上报） */
    private bool $resizeMoved = false;

    /**
     * 悬停 / 正在拖的列分割线 key（`null` = 都没有）。只影响那条线的颜色，不影响布局。
     *
     * 拆两个字段：拖出表头后 hover 已为 false，但线仍该保持高亮。
     */
    private ?string $dividerHoverKey = null;

    private ?string $dividerDragKey = null;

    /** 选中行底色的透明度（0–255，约 14%）—— 半透明 `Accent`，不新增 `AccentContainer` token */
    private const SELECTED_ALPHA = 36;

    /** 列宽手柄的命中区宽（逻辑像素）—— 与 Excel 同量级 */
    private const RESIZE_HANDLE_W = 5.0;

    /** 表头列分割线宽（逻辑像素）—— 发丝线，1px 是 DPI 下的最小可辨宽度 */
    private const DIVIDER_W = 1.0;

    /** 鼠标悬停的行 key（纯 UI 态：没人从外部读写它） */
    private string|int|null $hoverKey = null;

    /**
     * Shift 连选的锚点（纯 UI 态）：行 key + 列 key（整行行为下 `col` 恒 `null`）。
     *
     * 存 key 不存下标：排序 / 过滤后行号会漂。
     *
     * @var array{row: string|int, col: ?string}|null
     */
    private ?array $anchor = null;

    /**
     * 拖动框选的起点格（按下那一刻的**下标**；`null` = 没按住）。
     *
     * 与 `$anchor` 分开：锚点是点击语义（跟数据走），拖动是手势（按下那一刻）。
     *
     * @var array{row: int, col: int}|null
     */
    private ?array $dragAnchor = null;

    /**
     * 正在拖到的格（`null` = 按住了但还没过阈值 ⇒ 仍是普通点击）。
     *
     * @var array{row: int, col: int}|null
     */
    private ?array $dragTo = null;

    /** 按下时的根坐标 y（判阈值用；拖动中不再需要） */
    private float $dragStartY = 0.0;

    /** 拖动起判阈值（逻辑像素）—— 手抖几像素不该把"点行"变成"选一片" */
    private const DRAG_SLOP = 4.0;

    /**
     * 正在编辑的格：**行 key + 列 key**（`null` = 没在编辑）。
     *
     * 不用下标：排序 / 过滤会在会话中途换行序，下标会把 A 的修改写进 B。
     *
     * @var array{row: string|int, col: string}|null
     */
    private ?array $editingRef = null;

    /**
     * 编辑中的草稿（`null` = 没在编辑）。必须住表上：行被虚拟化回收时草稿跟着蒸发。
     *
     * 文本 / 插入符 / 选区同源（`TextDraft`），分开存会出现"插入符与高亮不在同一处"。
     */
    private ?TextDraft $draft = null;

    /** 进入编辑时的原文本（判"值到底改没改"，同值不回调 —— G2：宁可少报） */
    private string $draftOrigin = '';

    /**
     * 正在执行 `ensureVisible()` 自己的滚动（`true` = 不算"用户滚动了表格"）。
     *
     * 不区分的话"进入编辑 → 把格子滚进视口 → 立刻提交"，编辑根本进不去。
     */
    private bool $revealScrollInFlight = false;

    /** 当前偏移（逻辑像素，≥ 0，已按上一帧内容长度夹过上界） */
    public function offsetY(): float
    {
        return $this->laneY?->offset() ?? 0.0;
    }

    /** 当前横向偏移（逻辑像素，≥ 0；上界 = 内容宽 − 视口宽，布局期夹取） */
    public function offsetX(): float
    {
        return $this->laneX?->offset() ?? 0.0;
    }

    /** 上一帧实际造出的行数（虚拟化的验收判据，不参与逻辑） */
    public function createdRows(): int
    {
        return $this->createdRows;
    }

    /** 上一帧实际造出的列数（**每个**造出的行里都这么多格；与 `createdRows()` 同职责） */
    public function createdColumns(): int
    {
        return $this->createdColumns;
    }

    /**
     * 滚动 Δ（**逻辑像素**；正 = 行区内容上移，即"往下看"）。
     *
     * 能不重建就不重建：窗口仍盖得住视口就只推给盒，滚出缓冲带才标脏重建。
     * 夹取与"回写盒夹过的值"收在 `ScrollLane::move()`，本方法只剩这层策略。
     */
    public function scrollBy(float $delta): void
    {
        $this->commitIfUserScrolled();

        if (!$this->lane(Axis::Vertical)->move($delta, $this->pusher(Axis::Vertical))) {
            return;   // 已在边界：不推、不重排（省一帧）
        }

        // 窗口盖不住视口 ⇒ 必须重建（否则上/下会露出没建的空行）
        if (!$this->windowCovers(Axis::Vertical)) {
            $this->markDirty();
        }
    }

    /** 该轴的"推给盒"回调：目标值 → 实际生效值。盒不在 ⇒ 原样返回（`build()` 会把字段递下去） */
    private function pusher(Axis $axis): \Closure
    {
        /** @var RenderTable|null $box */
        $box = $this->element()?->effectiveBoxOf(RenderTable::class);

        return static function (float $value) use ($box, $axis): float {
            return $box === null ? $value : $box->setScrollOffset($axis, $value);
        };
    }

    /**
     * 上一帧造的窗口是否**完整盖住**当前视口。
     *
     * 视口取盒的实测值（滚动期没有 `BuildContext`）；还没排过 ⇒ 判"盖不住"。
     * 两轴判据不同：竖向视口是行区（减表头），横向是全宽（表头与行区共享 x）。
     */
    private function windowCovers(Axis $axis): bool
    {
        /** @var RenderTable|null $box */
        $box = $this->element()?->effectiveBoxOf(RenderTable::class);
        if ($box === null) {
            return false;
        }

        $g = $this->geometry;
        if ($g === null) {
            return false;
        }

        if ($axis === Axis::Vertical) {
            $bodyH = $box->bodyHeight();
            if ($bodyH <= 0.0) {
                return false;
            }

            return $g->coversRows($this->offsetY(), $bodyH);
        }

        $w = $box->size()?->width ?? 0.0;
        if ($w <= 0.0) {
            return false;
        }

        return $g->coversColumns($this->offsetX(), $w);
    }

    /**
     * 按轴滚动（横条拖动、横向滚轮走这里；纵向仍走 `scrollBy()`）。
     *
     * 横向装得下就直接早退：否则每格滚轮都改一次 `offsetX` 并标脏，build 里又被夹回 0。
     * 横向不改行窗口但**会改列窗口**，所以与竖向同一条"盖得住才只推偏移"。
     */
    public function scrollByAxis(Axis $axis, float $delta): void
    {
        if ($axis === Axis::Vertical) {
            $this->scrollBy($delta);

            return;
        }

        $this->commitIfUserScrolled();

        if (!$this->horizontalOverflows()) {
            return;   // 横向没得滚：滚轮来了也不标脏
        }

        // 与竖向同一条纪律：盖得住只推偏移（0 重建），盖不住就标脏。
        if (!$this->lane(Axis::Horizontal)->move($delta, $this->pusher(Axis::Horizontal))) {
            return;
        }

        if (!$this->windowCovers(Axis::Horizontal)) {
            $this->markDirty();
        }
    }

    /** 横向**装得下**吗（`null` = 还没排过 ⇒ 放行：别把"不知道"当"装得下"而把该滚的表卡住） */
    private function horizontalOverflows(): bool
    {
        $g = $this->geometry;

        return $g === null || $g->horizontalOverflows();
    }

    /** 按轴滚动，**带动画**（滚轮专用）。横向"装得下"必须在起飞前判，否则 motion 会朝到不了的目标白转一帧 */
    public function glideByAxis(Axis $axis, float $delta): void
    {
        if ($axis === Axis::Horizontal && !$this->horizontalOverflows()) {
            return;
        }

        $this->lane($axis)->glide($delta);
    }

    /** 该轴的滚动状态机（懒建；两根轴注入同一份 `ScrollBarFade` ⇒ 横竖同时淡出） */
    private function lane(Axis $axis): ScrollLane
    {
        if ($axis === Axis::Vertical) {
            $lane = $this->laneY;
            if ($lane === null) {
                $lane = new ScrollLane(0.0, $this->bar(), $this);
                $this->laneY = $lane;
            }

            return $lane;
        }

        $lane = $this->laneX;
        if ($lane === null) {
            $lane = new ScrollLane(0.0, $this->bar(), $this);
            $this->laneX = $lane;
        }

        return $lane;
    }

    /** 推进一根轴的动画；返回"这一帧动了吗"。位移仍走 `scrollByAxis()`，动画不重写夹取与换批 */
    private function stepMotion(Axis $axis, float $timestampMs): bool
    {
        return $this->lane($axis)->tick($timestampMs, function (float $delta) use ($axis): void {
            $this->scrollByAxis($axis, $delta);
        });
    }

    /**
     * 把**内容坐标**里的矩形滚进可见区（键盘可达性要求）。
     *
     * 算术在 `RenderTable::revealDelta()`（那里知道表头占掉多少），本方法只找盒 + 转交。
     * 两轴视口定义不同，`revealDeltaX()` 是镜像而不是同一个函数的特例。
     */
    public function ensureVisible(Rect $target, float $margin = 8.0): void
    {
        /** @var RenderTable|null $box */
        $box = $this->element()?->effectiveBoxOf(RenderTable::class);
        if ($box === null) {
            return;
        }

        // 这是框架自己把格子带进视口，不是用户滚 ⇒ 不提交编辑。标记必须复位，
        // 否则"用户滚动提交编辑"永久失效且无声。
        $this->revealScrollInFlight = true;

        try {
            $dy = $box->revealDelta($target, $margin, $this->offsetY());
            if ($dy !== 0.0) {
                $this->scrollBy($dy);
            }

            $dx = $box->revealDeltaX($target, $margin, $this->offsetX());
            if ($dx !== 0.0) {
                $this->scrollByAxis(Axis::Horizontal, $dx);
            }
        } finally {
            $this->revealScrollInFlight = false;
        }
    }

    /**
     * 当前光标格（行 / 列**下标**；空格子表 ⇒ `null`）。
     *
     * @return array{row: int, col: int}|null
     */
    public function focusedCell(): ?array
    {
        if ($this->view()->rowList() === [] || $this->view()->columnList() === []) {
            return null;
        }

        return ['row' => $this->focusedRow, 'col' => $this->focusedCol];
    }

    /**
     * 焦点进出（`FocusManager` 驱动）。挂钩子而不是 Widget 字段：Widget 每次重建都是新实例。
     */
    #[\Override]
    public function didChangeFocus(bool $focused): void
    {
        if ($this->hasFocus === $focused) {
            return;
        }

        // 焦点走了 ⇒ 提交会话。顺序在改 `$hasFocus` 之前：回调里若应用重建了表，
        // 不会看到"半提交"的中间态。
        if (!$focused && $this->editingRef !== null) {
            $this->commitEditing();
        }

        // 失焦 ⇒ 拖动收不到 up，不清的话高亮一直挂到下一次点击。
        if (!$focused && $this->dragAnchor !== null) {
            $this->dragAnchor = null;
            $this->dragTo = null;
        }

        $this->setState(function () use ($focused): void {
            $this->hasFocus = $focused;
        });

        if ($focused) {
            // 光标可能在视口外（上次滚走过 / 行了变化）⇒ 进来就把它带回来
            $this->revealFocus();
        }
    }

    /**
     * 键盘：方向键移格、Home/End 到首末行。返回 `true` = 吃掉这个键。
     *
     * Enter / Space 与不可编辑列都不吃：吃了就把应用的"空格翻转选中"类语义堵死。
     * 已在边界时吃键但不重建。
     */
    public function handleFocusKey(KeyEvent $event): bool
    {
        // 焦点不一定在表上：`keyboard()` 沿父链找最近声明者，表内子控件（输入框 / 滑块）
        // 的按键会先飘到这儿来。焦点不在表上时方向键属于那个控件自己。
        if (!$this->hasFocus) {
            return false;
        }

        // 编辑中：键盘**整个**归草稿（方向键移插入符、Enter 提交、Esc 取消）。
        // 放在行列数检查之前 —— 有编辑格就一定有行列。
        if ($this->editingRef !== null) {
            return $this->editKey($event);
        }

        $rows = \count($this->view()->rowList());
        $cols = \count($this->view()->columnList());

        if ($rows === 0 || $cols === 0) {
            return false;   // 空格子表：方向键让给上层（页面滚动）
        }

        $chord = $event->chord;

        // 全表快捷键先于方向键（与光标在哪一格无关）；空表也要吃掉，别漏给上层滚页面。
        // ★ 键名必须**大写**：壳对 `A`–`Z` 给的就是大写。写成小写则真机上
        //   Ctrl+A / Ctrl+C 一直不生效，而单测喂小写 chord 全绿。
        if ($chord->is(KeyChord::ctrl('A'))) {
            $this->selectAll();

            return true;
        }

        if ($chord->is(KeyChord::ctrl('C'))) {
            return $this->copySelection();
        }

        // 进入编辑：`Enter` / `F2`，**只在当前列可编辑时才吃**（否则堵死应用语义）。
        // 双击进编辑属手势族，前置是时钟（`PointerEvent` 没有时间戳）。
        if ($chord->is(KeyChord::enter()) || $chord->is(KeyChord::f(2))) {
            return $this->beginEdit($this->focusedRow, $this->focusedCol);
        }

        $row = $this->focusedRow;
        $col = $this->focusedCol;

        // `KeyChord::is()` 是**全字段**比较 ⇒ 带 Shift 的方向键不会命中 `KeyChord::right()`。
        // 先摘掉 Shift 再比；只摘 Shift（Ctrl / Alt+方向键今天没有语义，摘了就是另一条行为）。
        $nav = $chord->shift ? KeyChord::make($chord->key) : $chord;

        // 首帧 geometry 未 settle ⇒ 步长退化成一行，别让按键静默失效。
        // 翻页步长只认行区可见高，不是渲染窗口行数（后者含 cache）。
        if ($nav->is(KeyChord::up())) {
            $row--;
        } elseif ($nav->is(KeyChord::down())) {
            $row++;
        } elseif ($nav->is(KeyChord::left())) {
            $col--;
        } elseif ($nav->is(KeyChord::right())) {
            $col++;
        } elseif ($nav->is(KeyChord::home())) {
            $row = 0;
        } elseif ($nav->is(KeyChord::end())) {
            $row = $rows - 1;
        } elseif ($nav->is(KeyChord::pageDown())) {
            $row += $this->geometry?->pageRows() ?? 1;
        } elseif ($nav->is(KeyChord::pageUp())) {
            $row -= $this->geometry?->pageRows() ?? 1;
        } else {
            return false;
        }

        $row = max(0, min($row, $rows - 1));
        $col = max(0, min($col, $cols - 1));

        // Shift+方向键 = **扩选**。锚点须是"移动之前"那一格，否则第一次 Shift+Down 只选一行。
        // 无修饰的移动不动选区（选区受控，应用持有）。
        if ($chord->shift) {
            $this->extendSelectionByKeyboard($row, $col);
        }

        if ($row === $this->focusedRow && $col === $this->focusedCol) {
            return true;
        }

        $this->setState(function () use ($row, $col): void {
            $this->focusedRow = $row;
            $this->focusedCol = $col;
        });

        $this->revealFocus();

        return true;
    }

    // ── 选区：Shift+方向键 / Ctrl+A / Ctrl+C（格级矩形）────────────────────
    //
    // 三条入口（点击 / 拖动 / 键盘）都过 `TableGridPicker`，这里只递行序列并交出结果。

    /** `Shift+方向键`：从锚点扩到目标格并上报。首次进来先把锚点落成**移动之前**的光标格 */
    private function extendSelectionByKeyboard(int $row, int $col): void
    {
        $view = $this->view();
        $selection = $view->selectionSet();
        if ($selection === null) {
            return;
        }

        if ($this->anchor === null) {
            $this->anchor = $this->anchorAt($this->focusedRow, $this->focusedCol);
            if ($this->anchor === null) {
                return;
            }
        }

        $picked = TableGridPicker::pick(
            $selection,
            $this->rowKeysSnapshot(),
            $this->colKeysSnapshot(),
            $row,
            $col,
            $view->selectionModeValue(),
            $view->selectionBehaviorValue(),
            $this->anchor,
            false,
            true,
        );

        // Shift 不挪锚点（picker 返回的就是旧锚点；这里写回是为了"首次落锚"那条）
        $this->anchor = $picked[1];

        $view->selectionChangeHandler()?->__invoke($picked[0]);
    }

    /** `Ctrl+A`：整表全选。结果与当前都是空 ⇒ 不通知（空表连按十次不该产生十次 `setState`） */
    private function selectAll(): void
    {
        $view = $this->view();
        $selection = $view->selectionSet();
        if ($selection === null) {
            return;
        }

        $next = TableGridPicker::selectAll(
            $this->rowKeysSnapshot(),
            $this->colKeysSnapshot(),
            $view->selectionBehaviorValue(),
        );

        if ($next->isEmpty() && $selection->isEmpty()) {
            return;
        }

        // 全选之后没有"起点"：下一次 Shift+点 应该重新落锚，而不是从某个角落扩
        $this->anchor = null;

        $view->selectionChangeHandler()?->__invoke($next);
    }

    /**
     * `Ctrl+C`：把选区拼成 TSV 交给应用。返回 `true` = 吃掉了这个键。
     *
     * 没声明 `onCopy()` 或选区为空 ⇒ `false`（键放行给应用；"没反应"正是用户预期）。
     */
    private function copySelection(): bool
    {
        $view = $this->view();
        $copy = $view->copyHandler();
        if ($copy === null) {
            return false;
        }

        $selection = $view->selectionSet();
        if ($selection === null || $selection->isEmpty()) {
            return false;
        }

        $copy($this->selectionAsTsv($selection));

        return true;
    }

    /**
     * 选区 ⇒ TSV（行 `\n`、格 `\t`）。只输出选区覆盖到的格，整行都不在选区的行跳过 ——
     * 否则复制一块 2×2 会带出一堆空行，粘到 Excel 里就是一片空白。
     */
    private function selectionAsTsv(TableSelection $selection): string
    {
        $view = $this->view();
        $columns = $view->columnList();
        $keyOf = $view->keyMapper();
        if ($keyOf === null || $columns === []) {
            return '';
        }

        $colKeys = [];
        foreach ($columns as $column) {
            $colKeys[] = $column->keyName();
        }

        $lines = [];
        foreach ($view->rowList() as $index => $row) {
            $rowKey = $keyOf($row);
            if (!$selection->has($rowKey)) {
                continue;
            }

            $cells = [];
            foreach ($columns as $j => $column) {
                $cells[] = $selection->covers($rowKey, $colKeys[$j])
                    ? $column->copyTextOf($row, $index)
                    : '';
            }

            $lines[] = \implode("\t", $cells);
        }

        return \implode("\n", $lines);
    }

    /**
     * 当前行序的 key 序列（`null` = 该行没有身份，不压缩）。不缓存：行序会随排序 /
     * 过滤变，缓存就要在每处变更点失效，那是"两个字段互相矛盾"的温床。
     *
     * @return list<string|int|null>
     */
    private function rowKeysSnapshot(): array
    {
        $keyOf = $this->view()->keyMapper();
        if ($keyOf === null) {
            return [];
        }

        $keys = [];
        foreach ($this->view()->rowList() as $row) {
            $keys[] = $keyOf($row);
        }

        return $keys;
    }

    /** @return list<string> */
    private function colKeysSnapshot(): array
    {
        $keys = [];
        foreach ($this->view()->columnList() as $column) {
            $keys[] = $column->keyName();
        }

        return $keys;
    }

    /**
     * 某一格的锚点（行 key + 列 key；整行行为下 `col` 恒 `null`）。
     *
     * @return array{row: string|int, col: ?string}|null
     */
    private function anchorAt(int $row, int $col): ?array
    {
        $rowKey = $this->rowKeysSnapshot()[$row] ?? null;
        if ($rowKey === null) {
            return null;
        }

        if ($this->view()->selectionBehaviorValue() === TableSelectionBehavior::Rows) {
            return ['row' => $rowKey, 'col' => null];
        }

        $colKey = $this->colKeysSnapshot()[$col] ?? null;
        if ($colKey === null) {
            return null;
        }

        return ['row' => $rowKey, 'col' => $colKey];
    }

    // ── 单元格编辑 ────────────────────────────────────────────────────
    //
    // 控件只算"改成了什么"并交出去，写库由应用做。会话（编辑哪一格 / 草稿 / 插入符）
    // 住 State：它没有外部读者，也没有外部写者。

    /**
     * 正在编辑的格（**下标**；没在编辑 / 那一格已不存在 ⇒ `null`）。对外报下标、内部存 key。
     *
     * @return array{row: int, col: int}|null
     */
    public function editingCell(): ?array
    {
        if ($this->editingRef === null) {
            return null;
        }

        $row = $this->keyIndex($this->editingRef['row']);
        $col = $this->columnIndexOf($this->editingRef['col']);
        if ($row === null || $col === null) {
            return null;
        }

        return ['row' => $row, 'col' => $col];
    }

    /** 在编辑中（`EditableText` 的门控；`Application` 的剪贴板环靠它） */
    public function isEditing(): bool
    {
        return $this->editingRef !== null;
    }

    /**
     * 进入编辑（键盘：`Enter` / `F2`；指针：双击）。返回 `false` = 这一格不能编辑 ⇒ **键不吃**。
     *
     * @param float|null $caretX 指针的内容坐标 x（`null` = 键盘进入 ⇒ 插入符落末尾）。
     *                           指针进编辑必须"点在哪字之间就落哪儿"。
     */
    private function beginEdit(int $row, int $col, ?float $caretX = null): bool
    {
        // 已有会话 ⇒ 先收口：同一行的另一格被双击时，旧草稿不能悄悄丢掉。
        if ($this->editingRef !== null) {
            $this->commitEditing();
        }

        $view = $this->view();
        $rows = $view->rowList();
        $columns = $view->columnList();

        if (!isset($rows[$row], $columns[$col])) {
            return false;
        }

        $column = $columns[$col];
        if (!$column->isEditable()) {
            return false;
        }

        $keyOf = $view->keyMapper();
        if ($keyOf === null) {
            // `build()` 已经为"没配 keyOf"响亮失败过，这里只是不崩
            return false;
        }

        // 起始文本与静态渲染同源（同一个 `textOf()`）⇒ 进入编辑的瞬间文字不跳。
        $seed = $column->editableText($rows[$row], $row);
        $rowKey = $keyOf($rows[$row]);
        $columnKey = $column->keyName();
        $caret = $caretX === null ? null : $this->pointerCaretOffset($column, $seed, $col, $caretX);

        $this->setState(function () use ($seed, $rowKey, $columnKey, $caret): void {
            $this->draft = new TextDraft($seed);
            $this->draftOrigin = $seed;

            if ($caret !== null) {
                $this->draft->moveCaret($caret);
            }

            $this->editingRef = ['row' => $rowKey, 'col' => $columnKey];
        });

        // 编辑格可能在视口外（键盘把光标移到这里之后又滚走了）
        $this->revealFocus();

        return true;
    }

    /**
     * 提交：**值真的变了才回调**，然后无条件结束会话。
     *
     * 先清会话再回调（应用在回调里重建表看到的是干净状态）；不夹取不校验（应用侧知识）。
     */
    private function commitEditing(): void
    {
        $ref = $this->editingRef;
        $draft = $this->draft;

        if ($ref === null || $draft === null) {
            return;
        }

        $value = $draft->text();
        $changed = $value !== $this->draftOrigin;

        $this->editingRef = null;
        $this->draft = null;
        $this->draftOrigin = '';

        $this->markDirty();

        if (!$changed) {
            return;   // 没改 ⇒ 不回调（G2：宁可少报，不可错报）
        }

        $handler = $this->view()->cellEditHandler();
        if ($handler !== null) {
            $handler($ref['row'], $ref['col'], $value);
        }
    }

    /** 取消：丢弃草稿，不回调（`Esc`） */
    private function cancelEditing(): void
    {
        if ($this->editingRef === null) {
            return;
        }

        $this->editingRef = null;
        $this->draft = null;
        $this->draftOrigin = '';

        $this->markDirty();
    }

    /** 草稿变了 ⇒ 只重画（草稿不是应用数据，提交时才通知） */
    private function settleDraft(TextChange $change): void
    {
        if ($change === TextChange::None) {
            return;
        }

        $this->markDirty();
    }

    /** 用户滚动 ⇒ 提交编辑（框架自己的 `ensureVisible()` 不算，见字段注释） */
    private function commitIfUserScrolled(): void
    {
        if ($this->revealScrollInFlight || $this->editingRef === null) {
            return;
        }

        $this->commitEditing();
    }

    /**
     * 编辑中的键位（**只在这一格可编辑时才有机会跑到**）。
     *
     * 字符 / 退格 / 方向 / Home / End / Shift+方向 / Ctrl+A 走 `TextKeymap`（与 `TextField`
     * 同一张表）；表格特有的收口只剩：Enter / ↑ / ↓ / F2 **提交**，Esc **取消**。
     */
    private function editKey(KeyEvent $event): bool
    {
        $draft = $this->draft;
        if ($draft === null) {
            return false;
        }

        $chord = $event->chord;

        if ($chord->is(KeyChord::escape())) {
            $this->cancelEditing();

            return true;
        }

        // ★ 提交 + 下移（Excel 的"回车往下走"）：连打几十行时手不用回键盘上半区。
        //   `F2` 只提交不移动 —— 它本来就是"进出编辑"的开关键。
        if ($chord->is(KeyChord::enter())) {
            $this->commitEditing();
            $this->moveFocusTo($this->focusedRow + 1, $this->focusedCol);

            return true;
        }

        if ($chord->is(KeyChord::f(2))) {
            $this->commitEditing();

            return true;
        }

        if ($chord->is(KeyChord::up())) {
            $this->commitEditing();
            $this->moveFocusTo($this->focusedRow - 1, $this->focusedCol);

            return true;
        }

        if ($chord->is(KeyChord::down())) {
            $this->commitEditing();
            $this->moveFocusTo($this->focusedRow + 1, $this->focusedCol);

            return true;
        }

        $change = TextKeymap::apply($draft, $event);

        if ($change !== null) {
            $this->settleDraft($change);

            return true;
        }

        return false;   // 其余（含加速键）放行 —— 编辑中也要能按 Ctrl+S
    }

    /** 把光标格移到 `(row, col)`（夹取后）；不动选择，只动光标 */
    private function moveFocusTo(int $row, int $col): void
    {
        $rows = \count($this->view()->rowList());
        $cols = \count($this->view()->columnList());

        if ($rows === 0 || $cols === 0) {
            return;
        }

        $row = max(0, min($row, $rows - 1));
        $col = max(0, min($col, $cols - 1));

        if ($row === $this->focusedRow && $col === $this->focusedCol) {
            return;
        }

        $this->setState(function () use ($row, $col): void {
            $this->focusedRow = $row;
            $this->focusedCol = $col;
        });

        $this->revealFocus();
    }

    /** 列 key ⇒ 可见列下标（`null` = 这一列已经不存在了） */
    private function columnIndexOf(string $key): ?int
    {
        foreach ($this->view()->columnList() as $index => $column) {
            if ($column->keyName() === $key) {
                return $index;
            }
        }

        return null;
    }

    // ── `EditableText`（让 IME / 剪贴板环认识表格的编辑会话）──────────────
    //
    // ★ 为什么实现这个接口而不是在单元格里放一个 `TextField`：
    //   1. **焦点节点**：`TextField` 自带 `markFocusable()`，放进虚拟化的行里
    //      会随行回收一起消失（焦点丢在虚空的同一个坑，见阶段 2b 的裁决）；
    //      而且没有"程序化聚焦某个后代"的通道（`autoFocus()` 只在焦点空着时
    //      按文档序接管，指定不了具体哪一个）。
    //   2. **IME / 剪贴板**：`Application` 的环靠 `focusedState() instanceof
    //      EditableText && isEditing()` 门控 —— 表格自己实现它，两个环一行不改
    //      就能用在单元格上（`caretRect()` 给候选窗定位、`Ctrl+C/V` 走选区）。
    //   3. 文本状态机与 `TextField` 共用 `TextDraft` ⇒ 不存在第二套字节数学。

    /**
     * 插入符矩形（**表盒内容坐标**；`null` = 不在编辑 / 还没出盒）。
     *
     * ★ 与 `TextFieldState::caretRect()` 的分工完全一致：只给几何，不假装知道
     *   自己在屏幕上的位置。差别是这里要**跨一层**换算：插入符在编辑盒里，
     *   而 `TextEditingBridge` 加的是表盒的原点 ⇒ 先把编辑盒的原点减掉。
     */
    public function caretRect(): ?Rect
    {
        if ($this->draft === null) {
            return null;
        }

        $element = $this->element();
        if ($element === null) {
            return null;
        }

        /** @var RenderTable|null $tableBox */
        $tableBox = $element->effectiveBoxOf(RenderTable::class);
        if ($tableBox === null) {
            return null;
        }

        // ★ 取的是**编辑盒**（`InlineEditorBox` 产出的 `RenderTextField`）：
        //   编辑中表内至多一个这样的盒（编辑格唯一），所以"第一个"就是它。
        /** @var RenderTextField|null $editorBox */
        $editorBox = $element->findBox(
            static fn(RenderBox $candidate): bool => $candidate instanceof RenderTextField,
        );
        if ($editorBox === null) {
            return null;   // 编辑行被滚出窗口 / 还没 build 出盒
        }

        $local = $editorBox->caretRect();
        [$tableX, $tableY] = RenderBox::absoluteOrigin($tableBox);
        [$editorX, $editorY] = RenderBox::absoluteOrigin($editorBox);

        return new Rect(
            $editorX - $tableX - $tableBox->contentOriginX() + $local->left,
            $editorY - $tableY - $tableBox->contentOriginY() + $local->top,
            $local->width,
            $local->height,
        );
    }

    /**
     * `EditableText` 的按键入口。
     *
     * ★ 实际上**走不到**：`Table` 声明了 `keyboard()`，而 `FocusManager::dispatchKey()`
     *   先问 `keyboard()` 再问 `EditableText`（同一个对象，`handleFocusKey()` 已经
     *   转给 `editKey()`）。实现它是接口要求，也让"将来某张表不声明 keyboard()"
     *   时行为不变。
     */
    public function handleKey(KeyEvent $event): bool
    {
        return $this->editKey($event);
    }

    public function moveCaret(int $offset): void
    {
        $draft = $this->draft;
        if ($draft !== null) {
            $this->settleDraft($draft->moveCaret($offset));
        }
    }

    public function extendSelectionTo(int $offset): void
    {
        $draft = $this->draft;
        if ($draft !== null) {
            $this->settleDraft($draft->extendSelectionTo($offset));
        }
    }

    /**
     * 选中 `$offset` 所在的词（接口要求）。
     *
     * ★ 表格的**指针**多击走 `selectInEditingDraft()`（偏移要从格子几何换算），
     *   不走这里 —— 那条路算完偏移之后调的正是本方法（同一个落点，一份实现）。
     *   本方法留在公开面上，是因为 `TableState` 也是 `EditableText`，而 IME /
     *   将来任何"按偏移选一个单位"的调用方都不该再学一份格子几何。
     */
    public function selectWordAt(int $offset): void
    {
        $draft = $this->draft;
        if ($draft !== null) {
            $this->settleDraft($draft->selectWordAt($offset));
        }
    }

    /** 选中 `$offset` 所在的段（接口要求；表格里的"段"是单元格文本按 `\n` 切的一段） */
    public function selectParagraphAt(int $offset): void
    {
        $draft = $this->draft;
        if ($draft !== null) {
            $this->settleDraft($draft->selectParagraphAt($offset));
        }
    }

    public function hasSelection(): bool
    {
        return $this->draft?->hasSelection() ?? false;
    }

    public function selectedText(): string
    {
        return $this->draft?->selectedText() ?? '';
    }

    public function replaceSelection(string $text): void
    {
        $draft = $this->draft;
        if ($draft !== null) {
            $this->settleDraft($draft->replaceSelection($text));
        }
    }

    public function deleteSelection(): void
    {
        $draft = $this->draft;
        if ($draft !== null) {
            $this->settleDraft($draft->deleteSelection());
        }
    }

    /** 把光标格滚进视口（用上一帧列宽算位置；还没布局过 ⇒ 什么也不做） */
    private function revealFocus(): void
    {
        $rect = $this->currentFocusRect();
        if ($rect !== null) {
            $this->ensureVisible($rect);
        }
    }

    /**
     * 光标格在**行区内容坐标**里的矩形（`null` = 还没布局过 / 空格子表）。
     *
     * ★ 用上一帧的几何（`$geometry`）：列宽只在 build 里算得出来，而键盘移动必须
     *   **当场**把格子滚进视口（等帧末 build 再滚会先闪一帧空白）。列宽刚被拖动时
     *   也一样 —— 拖动与键盘不同时发生。
     * ★ 这里只是"取快照 + 问它"，算术全在 `TableGeometry::cellRect()`。
     */
    private function currentFocusRect(): ?Rect
    {
        $g = $this->geometry;
        if ($g === null) {
            return null;
        }

        return $g->cellRect($this->focusedRow, $this->focusedCol);
    }

    /** 有没有哪一列声明了 `editable()`（决定行要不要挂 pointer —— 见 `$interactive`） */
    private function hasEditableColumn(array $columns): bool
    {
        foreach ($columns as $column) {
            if ($column->isEditable()) {
                return true;
            }
        }

        return false;
    }

    /**
     * 客户区 x ⇒ **行区内容坐标** x（`null` = 还没布局过）。
     *
     * ★ 与 `caretRect()` 的换算**方向相反**，两条都要写清楚：
     *   - 盒子 → 内容：`absoluteOrigin()` 已经含了滚动平移 ⇒ 直接减原点与内边距；
     *   - 指针 → 内容：指针是**视口**坐标，不受滚动影响 ⇒ 减原点与内边距之后
     *     还要**加回** `offsetX`。
     *   抄错一条的现象是"滚过之后点的格子和落下的插入符差一个滚动量"。
     */
    private function pointerContentX(float $clientX): ?float
    {
        $tableBox = $this->element()?->effectiveBoxOf(RenderTable::class);
        if ($tableBox === null) {
            return null;
        }

        [$tableX] = RenderBox::absoluteOrigin($tableBox);

        return $clientX - $tableX - $tableBox->contentOriginX() + $this->offsetX();
    }

    /**
     * 内容坐标 x ⇒ 列下标（`null` = 落在最后一列右侧的空白里 / 还没布局过）。
     *
     * ★ 语义（缝隙归左格）在 `TableGeometry::colAt()`；这里只是"取快照 + 问它"。
     */
    private function columnAtX(float $contentX): ?int
    {
        $g = $this->geometry;

        return $g === null ? null : $g->colAt($contentX);
    }

    /**
     * 指针 x ⇒ 这一格文本里的插入点（字节偏移，恒字符边界）。
     *
     * 三步：① 内容坐标 x 减到**格内**；② 再减掉文字在这一格里的**起点**（对齐方式
     * 决定它：左对齐 = 内边距、右对齐 = 宽 − 内边距 − 文字宽、居中 = 居中）；
     * ③ 交给 `RenderTextField::offsetAtX()`（与输入框**同一个**换算）。
     *
     * ★ ② 漏了就会"右对齐的列点哪儿都落末尾"—— 而单元格编辑最常见的对齐恰恰是
     *   右对齐（数字列）。
     * ★ 没有度量（还没 build 过）⇒ 落末尾：宁可"点哪都到末尾"，也不猜一个可能
     *   差半个字的偏移。
     */
    private function pointerCaretOffset(TableColumn $column, string $seed, int $col, float $contentX): int
    {
        $metrics = $this->editorMetrics;
        $style = $this->editorStyle;
        if ($metrics === null || $style === null) {
            return \strlen($seed);
        }

        // 行号/行高只影响 y，这里只取 x 与宽
        $g = $this->geometry;
        if ($g === null) {
            return \strlen($seed);
        }

        $rect = $g->cellRect(0, $col);
        if ($rect === null) {
            return \strlen($seed);
        }

        $scale = new Scale(1.0);
        $pad = $column->paddingX();
        $textW = $metrics->advance($seed, $style, $scale);

        $textX = match ($column->alignMode()) {
            TextAlign::End => $rect->width - $pad - $textW,
            TextAlign::Center => ($rect->width - $textW) * 0.5,
            default => $pad,
        };

        return RenderTextField::offsetAtX($seed, $style, $contentX - $rect->left - $textX, $metrics, $scale);
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
    public function initState(): void
    {
        $this->pipeline()->addConstraintObserver($this);
    }

    #[\Override]
    public function dispose(): void
    {
        $this->pipeline()->removeConstraintObserver($this);
    }

    #[\Override]
    public function tickFrame(float $timestampMs): bool
    {
        $busy = false;

        // ★ 两轴各推一次：横竖互不干扰（各自的 `ScrollMotion`）。
        if ($this->stepMotion(Axis::Vertical, $timestampMs)) {
            $busy = true;
        }
        if ($this->stepMotion(Axis::Horizontal, $timestampMs)) {
            $busy = true;
        }

        $bar = $this->barFade;
        if ($bar !== null && $bar->tickFor($this, $timestampMs)) {
            $busy = true;
        }

        return $busy;
    }

    /**
     * 布局后自检：视口宽与 build 时用的不一样 ⇒ 本帧重算列宽并重造。
     *
     * ★★ 为什么必须比对：`BuildContext::$width` 是**窗口**宽（根 context 由
     *   `Application::attach()` 按窗口约束造，整棵树共用），而表格的实际可用宽
     *   还要减掉导航栏 / 卡片 padding / 兄弟节点。首帧没有盒 ⇒ `viewportEstimate()`
     *   只能拿窗口宽当上界，而 flex 列按偏大的宽分完，行宽超出表盒 ⇒ 右边的列被
     *   裁掉（真机现象：5 列只看见前 3 列）。本帧收敛之后这件事**当帧**就修正了，
     *   不再有"滚动一下才对"。
     * ★ 阈值 0.5：亚像素抖动（DPR 换算）不该引来重建。
     */
    public function pollConstraintDrift(): ?string
    {
        $constraints = $this->element()?->effectiveBoxOf(RenderTable::class)?->constraints();
        if ($constraints === null) {
            return null;
        }

        if (\is_infinite($constraints->maxWidth)) {
            return null;   // 无界：视口跟随内容，没有可比的数
        }

        $now = max(0.0, $constraints->maxWidth);
        $used = $this->builtViewportW;
        if ($used >= 0.0 && \abs($now - $used) <= 0.5) {
            return null;
        }

        $this->markDirty();

        return \sprintf(
            'Table: 视口宽 %s → %.1f',
            $used < 0.0 ? '未定' : \sprintf('%.1f', $used),
            $now,
        );
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
        // ★ 格级行为下，拖动与选中都按**格**染（见 `cellTint()`）—— 整行不再染，
        //   否则"选了 2 格"会表现成"整行都选中了"，那是另一套语义。
        if ($this->view()->selectionBehaviorValue() === TableSelectionBehavior::Rows) {
            // ★ 拖动中的区间**压过**受控选中集：选区是受控的（应用收到上报才会变），
            //   而拖动中刻意不逐帧上报 ⇒ 不叠这一层的话高亮不跟手。
            if ($this->dragTo !== null && $this->dragAnchor !== null) {
                $low = \min($this->dragAnchor['row'], $this->dragTo['row']);
                $high = \max($this->dragAnchor['row'], $this->dragTo['row']);
                if ($index >= $low && $index <= $high) {
                    return $ctx->color(ColorToken::Accent)->withAlpha(self::SELECTED_ALPHA);
                }
            }

            if ($key !== null) {
                $selection = $this->view()->selectionSet();
                if ($selection !== null && $selection->has($key)) {
                    return $ctx->color(ColorToken::Accent)->withAlpha(self::SELECTED_ALPHA);
                }
            }
        }

        if ($key !== null && $this->hoverKey === $key) {
            return $ctx->color(ColorToken::Hover);
        }

        return $striped && $index % 2 === 1 ? $ctx->color(ColorToken::Pane) : null;
    }

    /**
     * 一格的选中底色（**只在格级行为下非 `null`**）。
     *
     * ★ 拖动中的矩形自己算（拖动刻意不逐帧上报，见 `endDrag()`），受控选区走
     *   `TableSelection::covers()` —— 两者优先级与整行行为一致：拖动压过受控。
     * ★ 列 key 由调用方递进来（build 期已经有），这里不再查一次列序。
     */
    private function cellTint(
        int $index,
        int $col,
        string|int|null $rowKey,
        string $colKey,
        BuildContext $ctx,
    ): ?Color {
        if ($this->view()->selectionBehaviorValue() !== TableSelectionBehavior::Cells) {
            return null;
        }

        if ($this->dragTo !== null && $this->dragAnchor !== null) {
            $rowLow = \min($this->dragAnchor['row'], $this->dragTo['row']);
            $rowHigh = \max($this->dragAnchor['row'], $this->dragTo['row']);
            $colLow = \min($this->dragAnchor['col'], $this->dragTo['col']);
            $colHigh = \max($this->dragAnchor['col'], $this->dragTo['col']);
            if ($index >= $rowLow && $index <= $rowHigh && $col >= $colLow && $col <= $colHigh) {
                return $ctx->color(ColorToken::Accent)->withAlpha(self::SELECTED_ALPHA);
            }
        }

        if ($rowKey === null) {
            return null;
        }

        $selection = $this->view()->selectionSet();
        if ($selection !== null && $selection->covers($rowKey, $colKey)) {
            return $ctx->color(ColorToken::Accent)->withAlpha(self::SELECTED_ALPHA);
        }

        return null;
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
     * 行容器的指针入口：**点击**交给 `noteRowTap()`，**拖动框选**在这里收。
     *
     * ★★ 拖动不需要另挂一个节点：行容器 `ownsPress: true` 已经建立了捕获，
     *   随后的 move / up 经 `dispatchToCapture()` 直达**同一个**回调（`InputRouter`），
     *   而 `$event->x/$y` 始终保持**根坐标** —— 所以"拖到了第几行"可以自己反算，
     *   不必要求指针底下正好是目标行的盒子（拖动时它是按下那一行）。
     */
    private function noteRowPointer(mixed $row, int $index, PointerEvent $event): void
    {
        if ($event->isMove()) {
            $this->extendDrag($event);

            return;
        }

        if ($event->isUp()) {
            $this->endDrag();

            return;
        }

        if (!$event->isDown() || !$event->isPrimary()) {
            return;
        }

        $contentX = $this->pointerContentX($event->x);
        $col = $contentX === null
            ? $this->focusedCol
            : ($this->columnAtX($contentX) ?? $this->focusedCol);

        $this->dragAnchor = ['row' => $index, 'col' => $col];
        $this->dragTo = null;
        $this->dragStartY = $event->y;

        $this->noteRowTap($row, $index, $event);
    }

    /**
     * 拖动中：过了阈值才"算拖"，然后按根坐标反算行。
     *
     * ★ 拖出视口上下边界时顺手滚半行 —— **move 驱动**而不是定时器：拖动时指针
     *   本来就在动。定时器版能做到"指针停在边缘不动也持续滚"，代价是多一条常驻
     *   的每帧路径（`Frame` 的 ticker 收口），本阶段不换。
     */
    private function extendDrag(PointerEvent $event): void
    {
        if ($this->dragAnchor === null) {
            return;
        }

        $row = $this->pointerRowAt($event->y);
        if ($row === null) {
            return;
        }

        // ★ 列同样要反算（格级矩形靠它）：拖出表格右缘 / 落在空白里 ⇒ 停在当前列，
        //   而不是"整个矩形消失"。
        $contentX = $this->pointerContentX($event->x);
        $col = $contentX === null
            ? $this->dragAnchor['col']
            : ($this->columnAtX($contentX) ?? $this->dragAnchor['col']);

        if ($this->dragTo === null) {
            if (\abs($event->y - $this->dragStartY) < self::DRAG_SLOP) {
                return;   // 还在"手抖"范围内：这一次仍然是点击
            }

            $this->dragTo = ['row' => $row, 'col' => $col];
            $this->markDirty();

            return;
        }

        if ($row !== $this->dragTo['row'] || $col !== $this->dragTo['col']) {
            $this->dragTo = ['row' => $row, 'col' => $col];
            $this->markDirty();
        }

        $this->dragScrollAt($event->y);
    }

    /**
     * 松手：把"锚点到终点"变成一次选择上报。
     *
     * ★ 复用 `TableGridPicker` 的 **Shift 语义**（锚点到目标替换为区间）—— 拖动就是
     *   "连续的 Shift 点击"，所以这里不该有新的一份区间算法。
     * ★ 拖动中**不**上报（选择是受控的，逐帧上报等于让应用每帧 setState 一次）；
     *   高亮靠 `$dragTo` 自己画（见 `rowBackground()` / `cellTint()`），松手才通知一次。
     */
    private function endDrag(): void
    {
        $anchor = $this->dragAnchor;
        $to = $this->dragTo;

        $this->dragAnchor = null;
        $this->dragTo = null;

        if ($to === null) {
            return;   // 只是点击：down 时已办完，没有要擦的、也没有要报的
        }

        $this->markDirty();   // 擦掉拖动高亮

        if ($anchor === null || $to === $anchor) {
            return;
        }

        $view = $this->view();
        $selection = $view->selectionSet();
        if ($selection === null) {
            return;
        }

        // ★ 锚点由**下标**换回 key：拖动起点是手势（下标），而选区是身份（key）
        $anchorCell = $this->anchorAt($anchor['row'], $anchor['col']);
        if ($anchorCell === null) {
            return;
        }

        $picked = TableGridPicker::pick(
            $selection,
            $this->rowKeysSnapshot(),
            $this->colKeysSnapshot(),
            $to['row'],
            $to['col'],
            $view->selectionModeValue(),
            $view->selectionBehaviorValue(),
            $anchorCell,
            false,
            true,
        );

        $this->anchor = $picked[1];

        $view->selectionChangeHandler()?->__invoke($picked[0]);
    }

    /** 拖到行区上下边缘**之外**时滚半行（见 `extendDrag()` 的"move 驱动"） */
    private function dragScrollAt(float $clientY): void
    {
        $box = $this->element()?->effectiveBoxOf(RenderTable::class);
        $size = $box?->size();
        if ($box === null || $size === null) {
            return;
        }

        [, $tableY] = RenderBox::absoluteOrigin($box);
        $originY = $tableY + $box->contentOriginY();

        // 行区上边界是表头**下沿**（表头不参与滚动，拖进去不该滚）
        $top = $originY + $box->headerHeight();
        $bottom = $originY + $size->height;

        // ★ 读上一帧解析好的行高（`$geometry`）：Widget 侧现在可能是 `null`（跟随主题），
        //   而指针回调在 build 之外、没有 ctx 可回落（§43）。
        $rowHeight = $this->geometry?->rowHeight ?? 0.0;
        if ($rowHeight <= 0.0) {
            return;
        }

        $step = $rowHeight * 0.5;
        if ($clientY < $top) {
            $this->scrollBy(-$step);
        } elseif ($clientY > $bottom) {
            $this->scrollBy($step);
        }
    }

    /**
     * 根坐标 y ⇒ 行区**内容坐标**（`null` = 盒还不可用）。
     *
     * ★ 与 `pointerContentX()` 的差别只有"减表头高"这一处：行区内容坐标的 `y = 0`
     *   是第 0 行顶部，而表头是钉住的 chrome（`$offsetY` 也在这套坐标里）。
     */
    private function pointerContentY(float $clientY): ?float
    {
        $tableBox = $this->element()?->effectiveBoxOf(RenderTable::class);
        if ($tableBox === null) {
            return null;
        }

        [, $tableY] = RenderBox::absoluteOrigin($tableBox);

        return $clientY
            - $tableY
            - $tableBox->contentOriginY()
            - $tableBox->headerHeight()
            + $this->offsetY();
    }

    /**
     * 根坐标 y ⇒ 行下标（拖出上下边界时夹到首/末行）。
     *
     * ★ 用 `RenderTable::headerHeight()` 而不是 build 期的 `headerHeightPx()`：
     *   表头可能被约束压过，量出来的才是真布局。
     * ★ 夹到首/末行的语义在 `TableGeometry::rowAt()`（拖动要靠它才能选到看不见的行）。
     */
    private function pointerRowAt(float $clientY): ?int
    {
        $contentY = $this->pointerContentY($clientY);
        if ($contentY === null) {
            return null;
        }

        $g = $this->geometry;
        if ($g === null) {
            return null;
        }

        return $g->rowAt($contentY);
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
        // ★ 点到**别的行** ⇒ 提交编辑（各平台"点到别处就落库"）。
        //   同一行**不**提交：行容器用 `ownsPress` 抢走了按下，拿不到"点在格内还是
        //   格外"的可靠判据 —— 而误提交（用户只是想挪一下鼠标）比"再按一次 Esc"
        //   更烦人。双击同一行的**另一格**由 `beginEdit()` 先收口（见那边）。
        if ($this->editingRef !== null) {
            $editingRow = $this->keyIndex($this->editingRef['row']);
            if ($editingRow !== null && $editingRow !== $index) {
                $this->commitEditing();
            }
        }

        // ★ 光标格跟着指针走（**行 + 列**，阶段 7b）：列要靠"根坐标减行区原点再累加
        //   列宽"算出来，而这套几何在 build 之外也可得（`$geometry`）。
        //   以前只落行（"资源管理器同款"）：那时没有消费者要列 —— 双击进编辑有了。
        $contentX = $this->pointerContentX($event->x);
        $col = $contentX === null
            ? $this->focusedCol
            : ($this->columnAtX($contentX) ?? $this->focusedCol);

        if ($this->focusedRow !== $index || $this->focusedCol !== $col) {
            $this->setState(function () use ($index, $col): void {
                $this->focusedRow = $index;
                $this->focusedCol = $col;
            });
        }

        // ★ 双击 / 三击（阶段 7b + 9）：**放在选择之前** —— 本方法尾部有三处早退
        //   （没配 `selection()` / 没有 `keyOf` 的结果），双击不该被它们吃掉。
        //   ★ 判据从 `isDoubleClick()`（`=== 2`）改成了 `clickCount >= 2`：既然分流
        //     要按"在不在这一格编辑"分（而不是按第几下），就必须让第 3、4 下也进来。
        //     旧注释担心的"用 ≥ 会把刚进的编辑收口再重开"仍然成立 —— 但它由
        //     `handleMultiClick()` 里的 `isEditingCell()` 挡住，不再是计数的事。
        //   ★ 不可编辑的列 ⇒ `beginEdit()` 自己返回 false，这里什么都不做（单击
        //     只落光标格）—— 与"Enter 在不可编辑列上放行"是同一条纪律。
        if ($contentX !== null && $event->clickCount >= 2) {
            $this->handleMultiClick($index, $col, $contentX, $event->clickCount);
        }

        $view = $this->view();

        $tap = $view->rowTapHandler();
        if ($tap !== null) {
            $tap($row, $index);
        }

        $selection = $view->selectionSet();
        $keyOf = $view->keyMapper();
        if ($selection === null || $keyOf === null) {
            return;
        }

        // ★ 整份行序都要（区间是按行下标切的），而不只是被点那一行的 key。
        //   一次 O(行数) 的摊平，比"先找 key 下标、再找区间"各扫一遍还少一遍。
        $rowKeys = $this->rowKeysSnapshot();
        if (($rowKeys[$index] ?? null) === null) {
            return;
        }

        $picked = TableGridPicker::pick(
            $selection,
            $rowKeys,
            $this->colKeysSnapshot(),
            $index,
            $col,
            $view->selectionModeValue(),
            $view->selectionBehaviorValue(),
            $this->anchor,
            $event->ctrl,
            $event->shift,
        );

        // 锚点只改字段、不标脏：它不参与渲染（只决定下一次 Shift 的起点）
        $this->anchor = $picked[1];

        $notify = $view->selectionChangeHandler();
        $notify?->__invoke($picked[0]);
    }

    /**
     * 双击 / 三击的分流（**选词 / 选段**，阶段 9）。
     *
     * ## 为什么不是"三击选段"单独一条分支
     *
     * 连击计数是**累加**的（`TapTracker` 不封顶），所以同一格上第 2、3、4 下都会
     * 走到这里。真正要分的不是"第几下"，而是**当下在不在编辑**：
     *
     * | 状态 | 双击 | 三击 |
     * |---|---|---|
     * | 还没编辑 | 进编辑 + 落插入符（阶段 7b 的既有手感，不变） | 进编辑 + **选中整段** |
     * | 已在**这一格**编辑 | **选中词** | **选中整段** |
     *
     * ## ★ 已在编辑时**不重开**：这里以前有一条静默丢字的路径
     *
     * 旧实现无条件 `beginEdit()`，而它的第一句是 `commitEditing()`：把用户刚打的字
     * 当成"提交"发给应用，若应用没把值写回行数据，`editableText()` 又会取回旧文本
     * ⇒ **双击一下，刚输入的内容消失**。没有报错、没有日志（G2 禁止的半静默）。
     * 现在"同一格"直接走选区，连草稿都不重建。
     *
     * ★ 不可编辑的列 / 拿不到指针内容坐标 ⇒ 什么都不做（与单击同一条纪律）。
     */
    private function handleMultiClick(int $row, int $col, float $contentX, int $count): void
    {
        if ($this->isEditingCell($row, $col)) {
            $this->selectInEditingDraft($col, $contentX, $count);

            return;
        }

        if ($count === 2) {
            $this->beginEdit($row, $col, $contentX);

            return;
        }

        // 三击及以上、还没在编辑 ⇒ 进编辑后立刻整段选中（"三击选中单元格"）
        if ($this->beginEdit($row, $col, $contentX)) {
            $this->selectInEditingDraft($col, $contentX, $count);
        }
    }

    /**
     * 当前编辑会话是不是**正好这一格**。
     *
     * ★ 不能拿行下标比：`editingRef` 存的是 **key**（排序后行下标会变），
     *   所以两边都按 key 解析一次（`keyIndex()` 那条路只答"还在不在"）。
     */
    private function isEditingCell(int $row, int $col): bool
    {
        $ref = $this->editingRef;
        if ($ref === null) {
            return false;
        }

        $view = $this->view();
        $rows = $view->rowList();
        $columns = $view->columnList();
        $keyOf = $view->keyMapper();
        if ($keyOf === null || !isset($rows[$row], $columns[$col])) {
            return false;
        }

        return $keyOf($rows[$row]) === $ref['row'] && $columns[$col]->keyName() === $ref['col'];
    }

    /**
     * 按指针位置选中词（双击）或段（三击）。
     *
     * ★ 偏移必须**从指针重算**，不能用草稿当前的插入符：那一下双击发生在词的
     *   中间，而插入符还停在上一次点击的位置 —— 用后者会"选到另一个词"，
     *   且离得近时看不出错。
     * ★ 换算走 `pointerCaretOffset()`（与双击进编辑同一个函数）：两份换算的表现是
     *   "选中的词比指针所在的那个偏一个字符"。
     * ★ 换算完就交给 `selectWordAt()` / `selectParagraphAt()`（接口上的同一族动作）
     *   —— **"几击 ⇒ 哪个单位"这条映射在这里，落点只有一份**。
     */
    private function selectInEditingDraft(int $col, float $contentX, int $count): void
    {
        $draft = $this->draft;
        $columns = $this->view()->columnList();
        if ($draft === null || !isset($columns[$col])) {
            return;
        }

        $offset = $this->pointerCaretOffset($columns[$col], $draft->text(), $col, $contentX);

        if ($count >= 3) {
            $this->selectParagraphAt($offset);

            return;
        }

        $this->selectWordAt($offset);
    }

    /**
     * 某个 key 在**当前行序**里的下标（排序/过滤后行序会变 ⇒ 不能缓存行号）。
     *
     * ★ 选择那一路已改走 `TableGridPicker`（它按整份行序算）；这里只剩编辑会话的
     *   "这个 key 还在不在"用途。
     */
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
     * 一帧的总装：**校验 → 编辑前置 → 几何 → 表头 ＋ 行区 → 装盒**。
     *
     * ★ 这里只写流程。原先 229 行把校验、几何、装配混在一段里，改任何一段都要
     *   跨屏读；四段各自成为方法之后，"加一个阶段"有明确的落点。
     */
    public function build(BuildContext $ctx): Widget
    {
        $view = $this->view();
        $columns = $view->columnList();

        TableContract::assert($view, $columns);
        $this->prepareEditor($ctx);

        $viewportW = $this->viewportEstimate($ctx, false);
        $this->builtViewportW = $viewportW;
        $g = $this->settleGeometry($ctx, $view, $columns, $viewportW);

        return TableBox::make(
            $g->offsetX,
            $g->offsetY,
            $g->contentW,
            $g->headerHeight,
            $g->contentH,
            // 窗的起点 = 行区内容坐标里"第 rowFirst 行"的 y —— 与 `RenderScrollView`
            // 的 `sliceOrigin` 同一含义（**行区**坐标，不含表头高；见 `TableGeometry::$bodyH`）。
            $g->rowFirst * $g->rowHeight,
            $this->headerRow($ctx, $columns, $g),
            $this->buildBody($ctx, $view, $columns, $g),
            $this->barOpacity(),
            // ★ 只在**有焦点**时给框：失焦却画焦点框就是撒谎（见字段注释）。
            //   没焦点 ⇒ `null`，盒那一侧不需要知道焦点概念。
            $this->hasFocus ? $g->cellRect($this->focusedRow, $this->focusedCol) : null,
        );
    }

    /**
     * 建盒之前的编辑前置：量宽（指针落插入符要用）＋ 会话存活检查。
     *
     * ★ 存活检查（阶段 7a）：行被删 / 列被删 / 换了数据源之后，`editingRef` 会指向
     *   不存在的 key —— "编辑一个不存在的格子"必须当场结束，否则用户继续打字就是
     *   对着空气改（提交时也找不到行）。
     * ★ 这里是 build 期直接改字段（同偏移夹取）：不标脏、不多一帧。
     */
    private function prepareEditor(BuildContext $ctx): void
    {
        // 指针落插入符要"建盒之前"量宽（见 `$editorMetrics` 的注释）
        $this->editorMetrics = $ctx->metrics();
        $this->editorStyle = InlineEditorBox::editorStyle($ctx);

        $ref = $this->editingRef;
        if ($ref === null) {
            return;
        }

        if ($this->keyIndex($ref['row']) !== null && $this->columnIndexOf($ref['col']) !== null) {
            return;
        }

        $this->editingRef = null;
        $this->draft = null;
        $this->draftOrigin = '';
    }

    /**
     * 这一帧的几何快照，并把夹取结果写回活字段。
     *
     * ★★ 一帧的几何**一次算完**（`TableGeometry`）：列宽和、内容宽高、行区高、
     *   两轴夹取、行/列两个窗口 —— 全在里面。**这里不再有第二处算术**：
     *   指针映射 / 键盘 reveal / 窗口判定 / 表头构建全部问这个快照。
     *   `MEMORY.md` 的"坐标两族别抄错"就是从"各处各算一遍"长出来的。
     *
     * ★ 夹取结果**写回活字段**（不经 `setState` ⇒ 不标脏、不多一帧）。
     *   理由不是洁癖：State 里存的是"用户增量累加值"，而内容会变短
     *   （过滤 / 列宽被拖窄 / 窗口变宽）。旧值一旦长期大于新上限，之后每一笔
     *   增量都先拿去消耗这段**死区** —— 真机表现是"拖着条，画面不动"，
     *   而条本身贴在末端，看起来像"拖不动"。写回之后增量立刻生效。
     *   ★ 走 `set()` 而不是 `move()`：这是"布局算出来的真值"，不是用户增量，
     *     在 `move()` 里再夹一次下界 0 就是第二个真相（见 `ScrollLane::set()`）。
     *
     * @param list<TableColumn> $columns
     */
    private function settleGeometry(BuildContext $ctx, Table $view, array $columns, float $viewportW): TableGeometry
    {
        // ★ 行高/表头高的**唯一解析点**（§43）：显式值 > 主题 > 表里的 baseline。
        //   解析放在 build 期是因为只有这里才有 ctx —— `Table` 是 Widget，
        //   它拿不到自己所在子树的环境，也不该拿（否则同一份 Widget 不能被两处复用）。
        $g = TableGeometry::compute(
            $this->resolveWidths($columns, $viewportW),
            $view->rowHeightPx() ?? $ctx->metric(MetricToken::RowHeight),
            \count($view->rowList()),
            $view->headerHeightPx() ?? $ctx->metric(MetricToken::HeaderHeight),
            $view->columnSpacingPx(),
            $viewportW,
            $this->viewportEstimate($ctx, true),
            $this->offsetX(),
            $this->offsetY(),
            $view->cacheExtentPx(),
        );
        $this->geometry = $g;

        $this->lane(Axis::Vertical)->set($g->offsetY);
        $this->lane(Axis::Horizontal)->set($g->offsetX);

        $this->createdRows = $g->createdRows();
        $this->createdColumns = $g->createdColumns();

        // 光标格跟着行列数夹（行被删 / 列被删之后不能停在不存在的位置）。
        // 与偏移写回同一条纪律：build 期直接写字段，不经 `setState`（不标脏）。
        $this->focusedRow = $g->rowCount === 0 ? 0 : \min($this->focusedRow, $g->rowCount - 1);
        $this->focusedCol = $columns === [] ? 0 : \min($this->focusedCol, \count($columns) - 1);

        return $g;
    }

    /**
     * 行区：只造窗口内的行，每行 = 格子 ＋ 行容器装饰。
     *
     * @param list<TableColumn> $columns
     */
    private function buildBody(BuildContext $ctx, Table $view, array $columns, TableGeometry $g): Widget
    {
        $rows = $view->rowList();
        $spacing = $g->spacing;
        $striped = $view->isStriped();
        $keyOf = $view->keyMapper();
        $selectable = $view->selectionSet() !== null;
        // ★ 交互行 = 可选中 或 有 tap 回调 或 **有可编辑列**。都没有 ⇒ 纯展示表：
        //   不挂 hover（悬停高亮是"这里能点"的暗示，会误导）、不算 key（零成本）。
        // ★ 可编辑列必须算进来（阶段 7b）：双击进编辑走的是**行容器**的 pointer
        //   （`ownsPress`），行不可交互 ⇒ 声明了 `editable()` 却怎么点都进不去，
        //   而且**不报错** —— 只有 Tab + Enter 能用。这是"声明了就得生效"的底线。
        $interactive = $selectable
            || $view->rowTapHandler() !== null
            || $this->hasEditableColumn($columns);

        $lines = [];
        for ($i = $g->rowFirst; $i < $g->rowLast; $i++) {
            $row = $rows[$i];
            $rowKey = null;

            // ★★ 行身份在**需要**时算：`keyOf` 是应用的回调，虚拟化表里逐行调它
            //   是 O(可见行) 但不是零 —— 纯展示表没有理由付这笔钱。
            //   ★ 编辑中的表也要算：编辑格的定位靠 row key（见 `$editingRef`），
            //     而"纯展示 + 可编辑"的表 `$interactive` 是 false。
            if (($interactive || $this->editingRef !== null) && $keyOf !== null) {
                $rowKey = $keyOf($row);
            }

            $cells = [];
            for ($j = $g->colFirst; $j < $g->colLast; $j++) {
                $cells[] = $this->cellShell(
                    $this->cellContent($row, $i, $columns[$j], $rowKey),
                    $g->widths[$j],
                    $columns[$j],
                    // ★ 格级选中的底色**按格**加在这一层：`cellBuilder` 完全不知道
                    //   自己被选中（"加选择不用改每列 cell"这条裁决仍然成立）。
                    $this->cellTint($i, $j, $rowKey, $columns[$j]->keyName(), $ctx),
                );
            }

            // ★ key = **绝对序号**（不是窗内序号）：窗口滑动一格时第 k 行仍是第 k 行
            //   ⇒ diff 只增删两端各一个 Element，中间全部复用（同 LazyListState）。
            $rowWidget = Row::make()->children($cells)->spacing($spacing)->cross(FlexAlign::Center);

            // ★★ 被裁掉的**前缀列**用左内边距撑住（阶段 6）：只造窗口列之后，行内
            //   内容会整体左移 —— 必须补回 `colLeading`，否则视口里的列位与"全造"
            //   时不同（横向越滚越偏，且表头/行区各补一次必然漂）。
            //   ★ 用内边距而不是插一个 `SizedBox` 占位：`Row` 的 `spacing` 会给
            //     "占位 ↔ 第一个格"之间**再**加一个 gap ⇒ 每个窗口都多偏 `spacing`。
            //   ★ 恰好 0 时不加（绝大多数表全部列可见）⇒ 结构与本阶段之前逐位相同。
            if ($g->colLeading > 0.0) {
                $rowWidget = $rowWidget->padding(EdgeInsets::only(left: $g->colLeading));
            }

            $line = SizedBox::make($rowWidget)
                ->width($g->contentW)
                ->height($g->rowHeight)
                ->key($i);

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
                        $this->noteRowPointer($row, $i, $event);

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

        return Column::make()->children($lines);
    }

    /** 滚动条的淡出透明度（`barFade` 懒建 ⇒ 没有就是 1.0）。 */
    private function barOpacity(): float
    {
        return $this->barFade?->opacity() ?? 1.0;
    }

    /**
     * 表头一行：**与数据行同宽同内边距**（否则文字不齐，而"不齐"看起来像设计问题）。
     *
     * ★ 表头不加斑马、不参与选择 —— 它是 chrome，`RenderTable` 单独给它底色与
     *   裁剪区（不随竖向滚动）。
     *
     * @param list<TableColumn> $columns
     */
    private function headerRow(BuildContext $ctx, array $columns, TableGeometry $g): Widget
    {
        $sort = $this->view()->sortState();

        $cells = [];
        for ($j = $g->colFirst; $j < $g->colLast; $j++) {
            $cells[] = $this->headerCell($ctx, $columns[$j], $g->widths[$j], $sort, $j, $g->headerHeight);
        }

        $row = Row::make()->children($cells)->spacing($g->spacing)->cross(FlexAlign::Center);

        // ★ 与数据行**同一个** `colLeading`、同一处写法（阶段 1 闸门的第 3 条：
        //   表头与行区各算一次必然漂）。这里刻意与 `buildBody()` 写成同形 ——
        //   两边结构一样才不会各自漂。
        if ($g->colLeading > 0.0) {
            $row = $row->padding(EdgeInsets::only(left: $g->colLeading));
        }

        return SizedBox::make($row)
            ->width($g->contentW)
            ->height($g->headerHeight);
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
        float $headerHeight,
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
        $overlay = [$cell, $this->headerDivider($ctx, $column, $headerHeight)];

        if ($column->isResizable()) {
            $overlay[] = $this->resizeHandle($column, $width, $headerHeight);
        }

        $cell = Stack::make()->alignment(Alignment::topRight())->children($overlay);

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
    private function headerDivider(BuildContext $ctx, TableColumn $column, float $headerHeight): Widget
    {
        return SizedBox::make()
            ->width(self::DIVIDER_W)
            ->height($headerHeight)
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
    private function resizeHandle(TableColumn $column, float $width, float $headerHeight): Widget
    {
        return SizedBox::make()
            ->width(self::RESIZE_HANDLE_W)
            ->height($headerHeight)
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
     * 一格的内容：**正在编辑的那一格换成原地编辑器**，其余照列定义造。
     *
     * ★ 换的是**内容**、不换容器：宽度 / 内边距 / 对齐仍由 `cellShell()` 给 ⇒
     *   进入编辑时文字一个像素都不动（`InlineEditorBox` 零内边距就是为了这个）。
     * ★ 判"是不是这一格"用 **row key + 列 key**，不用下标：排序换了行序之后
     *   编辑器跟着**数据**走，而不是留在第 N 行。
     */
    private function cellContent(mixed $row, int $index, TableColumn $column, string|int|null $rowKey): Widget
    {
        $draft = $this->draft;
        $ref = $this->editingRef;

        if ($draft !== null && $ref !== null && $rowKey !== null
            && $ref['row'] === $rowKey
            && $ref['col'] === $column->keyName()) {
            return InlineEditorBox::make(
                $draft->text(),
                $draft->caretOffset(),
                $draft->selectionStart(),
                $draft->selectionEnd(),
            );
        }

        return $column->buildCell($row, $index);
    }

    /**
     * 一格的容器：**定宽 + 左右内边距 + 可选对齐**。
     *
     * ★ 对齐用 `Spacer` 推开而不是改文本样式：`cell()` 给的是任意 Widget，
     *   改不到它内部的 `Text`。★ `Start`（绝大多数列）**零额外包装**。
     */
    private function cellShell(Widget $content, float $width, TableColumn $column, ?Color $tint = null): Widget
    {
        $align = $column->alignMode();

        if ($align === TextAlign::End) {
            return $this->paddedCell(Row::make()->children([Spacer::make(), $content])->cross(FlexAlign::Center), $width, $column, $tint);
        }

        if ($align === TextAlign::Center) {
            return $this->paddedCell(
                Row::make()->children([Spacer::make(), $content, Spacer::make()])->cross(FlexAlign::Center),
                $width,
                $column,
                $tint,
            );
        }

        return $this->paddedCell($content, $width, $column, $tint);
    }

    /** 内边距 + 定宽（`0` 内边距时省掉一层 `Padding`）+ 可选选中底色 */
    private function paddedCell(Widget $inner, float $width, TableColumn $column, ?Color $tint = null): Widget
    {
        $pad = $column->paddingX();
        if ($pad <= 0.0) {
            $box = SizedBox::make($inner)->width($width);
        } else {
            $box = SizedBox::make(Padding::make($inner)->symmetric($pad, 0.0))->width($width);
        }

        // ★ 底色加在**格子这一层**（不是内层内容）：内边距与对齐都在它里面，
        //   选中色因此正好铺满"这一格"，不会溢到列间缝隙。
        return $tint === null ? $box : $box->background($tint);
    }

    /**
     * 把列声明摊平成 `Layout\ColumnWidths` 的入参 —— 三层列宽的 ①②③，
     * 第 ④ 层（阶段 4 的 `widthOverrides`）最优先但仍夹 ③。
     *
     * ★ 只是搬字段，不含决策 —— 四层分配规则（override 最优先 / flex 吃剩余 /
     *   逐列夹）全在纯函数里，那里能单测。
     *
     * @param list<TableColumn> $columns
     * @return array<int, float>
     */
    private function resolveWidths(array $columns, float $viewportW): array
    {
        $specs = [];
        foreach ($columns as $column) {
            $spec = $column->widthSpec();
            $specs[] = [
                'key' => $column->keyName(),
                'flex' => $spec->isFlex(),
                'weight' => $spec->isFlex() ? $spec->weight() : 0.0,
                'px' => $spec->isFlex() ? 0.0 : $spec->px(),
                'min' => $column->minWidthPx(),
                'max' => $column->maxWidthPx(),
            ];
        }

        return ColumnWidths::resolve(
            $specs,
            $this->view()->columnWidthOverrides(),
            $viewportW,
            $this->view()->columnSpacingPx(),
        );
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
    /**
     * 视口主轴长度：取**本帧布局要拿到的约束**（`RenderBox::constraints()`）。
     *
     * ★ 以前读"上一帧盒的 size"：首帧没有盒 ⇒ 只能拿窗口尺寸当上界。约束是布局的
     *   **入参**，与 `RenderTable` 自己算视口用的是同一份数（`max*` 有界时即视口）。
     * ★ 无界 ⇒ 退回 `ctx`（浮层里主轴无界，`RenderTable` 那时跟随内容）。
     */
    private function viewportEstimate(BuildContext $ctx, bool $vertical): float
    {
        $window = $vertical ? $ctx->height : $ctx->width;

        $constraints = $this->element()?->effectiveBoxOf(RenderTable::class)?->constraints();
        if ($constraints !== null) {
            $limit = $vertical ? $constraints->maxHeight : $constraints->maxWidth;
            if (!\is_infinite($limit) && $limit > 0.0) {
                return $limit;
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
