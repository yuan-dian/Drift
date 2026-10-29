<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\Axis;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Input\CursorShape;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Layout\ColumnWidths;
use Drift\Layout\ExtentWindow;
use Drift\Layout\FlexAlign;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderTable;
use Drift\Layout\RenderTextField;
use Drift\Layout\StackAlign;
use Drift\Rendering\Color;
use Drift\Rendering\ColorToken;
use Drift\Text\TextAlign;
use Drift\Text\TextChange;
use Drift\Text\TextDraft;
use Drift\Text\TextMetricsProvider;
use Drift\Text\TextStyle;

/**
 * `Table` 的 State：**持纵向偏移 + 算这一帧造哪几行 + 分配列宽**。
 *
 * ## 为什么偏移能住 State
 *
 * §12.3 的判据是"承载它的 Element 会不会被回收"。被回收的是**行**，本 State
 * 挂在表格自己身上（滚动容器不随滚动消失）⇒ 成立。反过来，**行内**的 UI 态
 * （正在编辑的草稿）会被回收，必须外置 —— 阶段 7a 的草稿正是外置到**本类**
 * （`$draft`），而不是放进行里。
 *
 * ## 单元格编辑（阶段 7a）：本类**就是**那个文本面
 *
 * 编辑中的表格实现 `EditableText`（不是往单元格里塞一个 `TextField`）：
 * 焦点仍然只有表这一个节点（一格一个 Tab 位会被虚拟化打碎），而 IME 候选窗与
 * 剪贴板环靠 `focusedState() instanceof EditableText` 门控 —— 表格自己实现它，
 * 那两个环一行不改就能用在单元格上。文本状态机与 `TextField` 共用 `TextDraft`。
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
final class TableState extends State implements Scrollable, EditableText
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

    /**
     * 光标格（阶段 2b）：行 / 列 **下标**，不是 key。
     *
     * ★★ 与选择集故意用两种身份：选择回答"哪些数据被选中"，要跟着数据走
     *   （排序后选中的还是那几条）；光标回答"眼睛在哪"，要留在原位置
     *   （排序后那一行内容变了，光标还停在那儿 —— 与 Excel / 资源管理器一致）。
     *   混成一个状态会让**移动光标**触发 `onSelectionChange`，语义就脏了。
     */
    private int $focusedRow = 0;

    private int $focusedCol = 0;

    /**
     * 焦点是否在本表 —— 失焦**不画**描边，但光标格保留。
     *
     * ★ 保留光标是为了"Tab 出去再 Tab 回来停在原处"；不画描边是因为"焦点在
     *   别处却有焦点框"就是撒谎（`ScrollBarGeometry::thumbRect()` 同一条纪律）。
     */
    private bool $hasFocus = false;

    /**
     * 上一帧的列宽 —— `focusRect()` 要在 build **之外**算光标格位置（键盘移动后
     * 立刻 `ensureVisible()`），而列宽只有 build 里才算得出来。
     *
     * ★ 空数组 = 还没布局过 ⇒ 没有可信位置，`ensureVisible` 让开（同
     *   `RenderTable::revealDelta()` 对"没尺寸"的裁决）。
     */
    private array $widthsInBuild = [];

    /**
     * 上一帧的文本度量与编辑样式（阶段 7b）。
     *
     * ★ 为什么留一份：**双击落插入符**要在 `beginEdit()` 那一刻把"指针 x"翻成
     *   "第几个字符之间"，而那一刻编辑盒还不存在（它下一帧才建出来）。能用的
     *   度量只有上一帧 build 里拿到的那份 —— 与 `$widthsInBuild` 同一个理由
     *   （几何只在 build 里可得，而键盘/指针动作必须**当场**响应）。
     * ★ 样式取 `InlineEditorBox::editorStyle()`（唯一来源）：量宽与实际排版必须是
     *   同一个样式，否则插入符会落在相邻字符上。
     */
    private ?TextMetricsProvider $editorMetrics = null;

    private ?TextStyle $editorStyle = null;

    /** 滚动条淡出节律（懒建；与 `ScrollViewState` / `LazyListState` 同一份实现） */
    private ?ScrollBarFade $barFade = null;

    /** 滚轮的动画节律 —— **两根轴各一个**（表的横竖互不干扰） */
    private ?ScrollMotion $motionY = null;

    private ?ScrollMotion $motionX = null;

    /**
     * 上一帧造了几行 —— **验收判据**（§6 阶段 1 闸门 ①：10000 行只造约一屏）。
     *
     * ★ 它不参与任何逻辑，只暴露给断言。写成"可由外部读到的计数"是因为
     *   "虚拟化有没有生效"无法从画面或性能观察（画面一样、快慢取决于机器）。
     */
    private int $createdRows = 0;

    /**
     * 上一帧造的行窗口在**行区内容坐标**里的范围 `[start, end)`（= `first*rowH` 到 `last*rowH`）。
     *
     * ★ 存在的理由（2026-10-27，A 批第 1 项）：滚动不再 `setState()`，于是
     *   "窗还盖不盖得住视口"必须**在 build 之外**能判 —— 盖得住就只推偏移
     *   （0 重建），盖不住才标脏。没有这两个数，滚出缓冲带之后行区下方就是
     *   一片空白（且不报错：盒照样画，只是没行）。
     */
    private float $windowStart = 0.0;

    private float $windowEnd = 0.0;

    /**
     * 上一帧造了几列（与 `$createdRows` 同一职责：虚拟化唯一的可观测量）。
     *
     * ★ 不与 `$createdRows` 合成"造了几个格"：行和列的窗口是**两个独立判据**
     *   （定高 vs 变宽），合成一个数就分不清是哪一侧失效。
     */
    private int $createdColumns = 0;

    /**
     * 上一帧的列窗口在**内容坐标 x** 里的范围 `[start, end)`。
     *
     * ★ 与 `$windowStart/$windowEnd`（行）成对，但**不共用**：横滚会改列窗口而
     *   不影响行窗口，两轴的"盖不盖得住"必须各判一次（见 `windowCovers()`）。
     */
    private float $colWindowStart = 0.0;

    private float $colWindowEnd = 0.0;

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
     * 上一次 `build()` 算出的**内容宽**（`null` = 还没 build 过）。
     *
     * ★ 唯一的消费者是 `scrollByAxis(Horizontal)` 的"要不要标脏"早退：横向偏移
     *   累加却永远被夹回 0 的表，每来一格滚轮就白重建一次（触控板每秒几十格）。
     * ★ 它**不参与任何坐标计算**（列宽真相仍只有 `resolveWidths()` 一处）——
     *   这里存的只是 build 手边本来就有、顺手留下的那个数。
     */
    private ?float $contentWInBuild = null;

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

    /**
     * 拖动框选的起点行（`null` = 没按住）。
     *
     * ★ 与 `$anchorKey` 分开存：锚点是**点击语义**的（Shift 连选用 key，跟着数据走），
     *   拖动是**手势**（按下那一刻的行下标）。两者绝大多数时候指向同一行，但
     *   "按下后没拖"必须只是普通点击 —— 用同一个字段会让松手时的收口分不清。
     */
    private ?int $dragAnchorRow = null;

    /**
     * 正在拖到的行（`null` = 按住了但还没过阈值 ⇒ 仍是普通点击）。
     *
     * ★ 三个状态用一个 `null` 表达（没按 / 按下未拖 / 在拖），比再开一个 bool 少
     *   一处"两个字段互相矛盾"的可能。
     */
    private ?int $dragRow = null;

    /** 按下时的根坐标 y（判阈值用；拖动中不再需要） */
    private float $dragStartY = 0.0;

    /** 拖动起判阈值（逻辑像素）—— 手抖几像素不该把"点行"变成"选一片" */
    private const DRAG_SLOP = 4.0;

    /**
     * 正在编辑的格：**行 key + 列 key**（`null` = 没在编辑）。阶段 7a。
     *
     * ★★ 不用下标（光标格用下标）：编辑是一个**跨多帧的会话**，而排序 / 过滤会在
     *   会话中途换行序 —— 下标会指向另一条数据（把 A 的修改写进 B）。key 不会。
     *   这与"光标是位置、选择是身份"是同一个分工的第三种用法：
     *   **编辑是身份**（我改的是哪条数据、哪个字段）。
     * ★ 列也用 key：插列 / 换序之后仍落在原来那一列上。
     *
     * @var array{row: string|int, col: string}|null
     */
    private ?array $editingRef = null;

    /**
     * 编辑中的草稿（`null` = 没在编辑）。
     *
     * ★★ 草稿**必须住在表上**，不能住在行里：虚拟化会在行滚出窗口时回收它的
     *   Element（`Table` 类注释已裁决），草稿跟着蒸发。表自己不随滚动消失
     *   ⇒ 草稿在这里跨帧存活，甚至"滚出去再滚回来"也不丢。
     * ★ 它同时是插入符与选区的家（`TextDraft` 三者同源）—— 分开存会让
     *   "插入符与高亮不在同一处"这种画面上看不出病因的 bug 成为可能。
     */
    private ?TextDraft $draft = null;

    /** 进入编辑时的原文本（判"值到底改没改"，同值不回调 —— G2：宁可少报） */
    private string $draftOrigin = '';

    /**
     * 正在执行 `ensureVisible()` 自己的滚动（`true` = 不算"用户滚动了表格"）。
     *
     * ★ 存在的理由：用户滚动要**提交**编辑（否则编辑中的行可能滚出窗口，
     *   用户继续打字就是对着看不见的格子打），而 `ensureVisible()` 内部也走
     *   `scrollBy()` —— 不区分的话"进入编辑 → 把格子滚进视口 → 立刻提交"，
     *   编辑根本进不去。
     */
    private bool $revealScrollInFlight = false;

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

    /** 上一帧实际造出的列数（**每个**造出的行里都这么多格；与 `createdRows()` 同职责） */
    public function createdColumns(): int
    {
        return $this->createdColumns;
    }

    /**
     * 滚动 Δ（**逻辑像素**；正 = 行区内容上移，即"往下看"）。
     *
     * ★★ **能不重建就不重建**（2026-10-27，A 批第 1 项）：偏移只改两个数，
     *   不改"有哪些行"。窗口仍盖得住视口 ⇒ 推给盒（就地改 + 标重排）即可；
     *   只有滚出缓冲带（`Table::cacheExtent()`）才标脏重建。
     *   实测（AOT，`bench` 滚动帧档）：滚动帧 11.68ms → **7.21ms**，
     *   其中 `settle`（重建）6.74 → 0.83ms（−88%）；`layout`/`paint` 略涨
     *   （2.83→3.65 / 2.19→2.70），那是缓冲带把窗从 395 节点撑到 475 的代价。
     *
     * ★ 回写**盒夹过的值**（不是 `$next`）：内容变短时 State 里那个"增量累加值"
     *   会长期大于上限，之后每笔增量都先消耗死区 —— 真机表现是"拖着条，画面不动"。
     */
    public function scrollBy(float $delta): void
    {
        $this->commitIfUserScrolled();

        $next = max(0.0, $this->offsetY + $delta);

        if ($next === $this->offsetY) {
            return;   // 已在边界：不推、不重排（省一帧）
        }

        $this->bar()->noteScroll();

        $this->offsetY = $this->pushOffset(Axis::Vertical, $next);

        // 窗口盖不住视口 ⇒ 必须重建（否则上/下会露出没建的空行）
        if (!$this->windowCovers(Axis::Vertical)) {
            $this->markDirty();
        }
    }

    /**
     * 把偏移推给盒并拿回**实际生效值**（`ScrollBarHost::setScrollOffset()`）。
     *
     * ★ 盒不在（首帧之前 / 盒被换掉）⇒ 原样返回：`build()` 会把字段递下去。
     * ★ 两个轴共用这一处 —— 夹取与"标重排"只写一遍。
     */
    private function pushOffset(Axis $axis, float $value): float
    {
        /** @var RenderTable|null $box */
        $box = $this->element()?->effectiveBoxOf(RenderTable::class);
        if ($box === null) {
            return $value;
        }

        return $box->setScrollOffset($axis, $value);
    }

    /**
     * 上一帧造的窗口是否**完整盖住**当前视口（按轴各判一次）。
     *
     * ★ 视口取**盒的**实测值而不是 build 里的估算：滚动期没有 `BuildContext`，
     *   而盒是唯一可靠口径 ⇒ 判据与布局同源。
     * ★ 还没排过（盒无 size）⇒ 判"盖不住"（宁可多重建一次，也不要露空行/空列）。
     * ★★ 两轴**不共用**一个判据：行窗口与列窗口的范围是两对字段，横滚只动列窗口。
     *   竖向的视口是行区（减表头），横向的视口是全宽（表头与行区共享 x）——
     *   这两个定义不同，不是同一个式子的两个实例。
     */
    private function windowCovers(Axis $axis): bool
    {
        /** @var RenderTable|null $box */
        $box = $this->element()?->effectiveBoxOf(RenderTable::class);
        if ($box === null) {
            return false;
        }

        if ($axis === Axis::Vertical) {
            $bodyH = $box->bodyHeight();
            if ($bodyH <= 0.0) {
                return false;
            }

            return $this->offsetY >= $this->windowStart && $this->offsetY + $bodyH <= $this->windowEnd;
        }

        $w = $box->size()?->width ?? 0.0;
        if ($w <= 0.0) {
            return false;
        }

        return $this->offsetX >= $this->colWindowStart && $this->offsetX + $w <= $this->colWindowEnd;
    }

    /**
     * 按轴滚动（横条拖动、横向滚轮走这里；纵向仍走 `scrollBy()`）。
     *
     * ★ 两个轴共用同一份 `ScrollBarFade`：横条与竖条同时淡出是对的 —— 它们提示
     *   的是同一件事"这块区域还能滚"，分两套节律只会出现"一根亮一根暗"。
     * ★ 上界不在这里夹（`scrollBy()` 同一裁决）：横向内容宽要等 `resolveWidths()`
     *   算完才知道，那是 build 期的事。
     * ★ 但**无横向溢出时这里直接早退**：不早退的话，每一格滚轮都会改一次
     *   `offsetX`、标一次脏，然后 build 里被夹回 0 —— 触控板每秒几十格就是
     *   几十次白重建。`contentWInBuild` 是上一帧 build 留下的（`null` = 还没
     *   排过 ⇒ 放行，别拿"不知道"当"没有"）。
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

        $next = max(0.0, $this->offsetX + $delta);

        if ($next === $this->offsetX) {
            return;
        }

        $this->bar()->noteScroll();

        // ★★ 横向**不改行窗口**（行是定高的，横滚只平移），但**会改列窗口**
        //   （阶段 6）：滚过缓冲带之后必须换一批列，否则视口边缘露出一截没造的空白。
        //   所以这里与竖向同一条纪律 —— 盖得住才只推偏移（0 重建），盖不住就标脏。
        $this->offsetX = $this->pushOffset(Axis::Horizontal, $next);

        if (!$this->windowCovers(Axis::Horizontal)) {
            $this->markDirty();
        }
    }

    /**
     * 横向**装得下**吗（`null` = 还没排过 ⇒ 放行，别拿"不知道"当"没有"）。
     *
     * ★ 抽出来是因为它有两个消费者：`scrollByAxis()`（立即滚）与
     *   `glideByAxis()`（动画滚）。两处各写一遍就是"两个真相"，迟早一处改一处漏。
     */
    private function horizontalOverflows(): bool
    {
        $contentW = $this->contentWInBuild;
        $viewportW = $this->widthUsedInBuild;

        return $contentW === null || $viewportW === null || $contentW > $viewportW;
    }

    /**
     * 按轴滚动，**带动画**（滚轮专用；细节见 `Scrollable::glideByAxis()`）。
     *
     * ★ 横向的"装得下就早退"必须在**起飞前**判：否则 motion 会朝一个到不了的目标
     *   跑，每帧 `scrollByAxis()` 都早退 ⇒ 得等 `stepMotion()` 发现"推不动"才收口，
     *   白转一帧。
     */
    public function glideByAxis(Axis $axis, float $delta): void
    {
        if ($axis === Axis::Horizontal && !$this->horizontalOverflows()) {
            return;
        }

        $motion = $this->motionFor($axis);
        if (!$motion->isAnimating()) {
            $motion->snapTo($axis === Axis::Vertical ? $this->offsetY : $this->offsetX);
        }
        $motion->glideBy($delta);

        $this->bar()->noteScroll();
    }

    /** 该轴的动画节律（懒建：没被滚轮滚过的轴不建） */
    private function motionFor(Axis $axis): ScrollMotion
    {
        if ($axis === Axis::Vertical) {
            $motion = $this->motionY;
            if ($motion === null) {
                $motion = new ScrollMotion($this->offsetY);
                $this->motionY = $motion;
            }

            return $motion;
        }

        $motion = $this->motionX;
        if ($motion === null) {
            $motion = new ScrollMotion($this->offsetX);
            $this->motionX = $motion;
        }

        return $motion;
    }

    /**
     * 推进一根轴的动画；返回"这一帧动了吗"。
     *
     * ★ 位移仍然交给 `scrollByAxis()` —— 夹取、列窗口换批、提交编辑全在那一条路上，
     *   动画不重写任何一条。
     */
    private function stepMotion(Axis $axis, float $timestampMs): bool
    {
        $motion = $axis === Axis::Vertical ? $this->motionY : $this->motionX;
        if ($motion === null) {
            return false;
        }

        $next = $motion->tick($timestampMs);
        if ($next === null) {
            return false;
        }

        $before = $axis === Axis::Vertical ? $this->offsetY : $this->offsetX;
        $this->scrollByAxis($axis, $next - $before);
        $after = $axis === Axis::Vertical ? $this->offsetY : $this->offsetX;

        // 推不动了（到边界 / 内容变短被夹）⇒ 就地收口；理由与实测帧数见
        // `ScrollViewState::tickFrame()`（三份实现同一契约，此处不重复展开）。
        if ($after === $before) {
            $motion->snapTo($before);
        }

        return true;
    }

    /**
     * 把**内容坐标**里的矩形滚进可见区（`04` §6.3 的键盘可达性要求）。
     *
     * ★ 形状与 `LazyListState::ensureVisible()` 逐字同构：算术都在
     *   `RenderTable::revealDelta()`（那边知道表头占掉多少），本方法只"找盒 + 转交"。
     * ★ **两个轴各算一次**（阶段 2b 起有消费者）：纵向的视口是行区（减表头），
     *   横向的视口是全宽（表头与行区共享 x）。两轴的视口定义不同 ⇒ 不能用同一个
     *   函数，`revealDeltaX()` 是镜像而不是特例。
     */
    public function ensureVisible(Rect $target, float $margin = 8.0): void
    {
        /** @var RenderTable|null $box */
        $box = $this->element()?->effectiveBoxOf(RenderTable::class);
        if ($box === null) {
            return;
        }

        // ★ 这里面的滚动是**框架自己**在把格子带进视口，不是用户滚 ⇒ 不能提交编辑
        //   （见 `$revealScrollInFlight` 的注释）。`finally` 是必须的：`revealDelta()`
        //   理论上不会抛，但标记一旦漏复位，"用户滚动提交编辑"就永久失效且无声。
        $this->revealScrollInFlight = true;

        try {
            $dy = $box->revealDelta($target, $margin, $this->offsetY);
            if ($dy !== 0.0) {
                $this->scrollBy($dy);
            }

            $dx = $box->revealDeltaX($target, $margin, $this->offsetX);
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
     * 焦点进出（`FocusManager` 驱动）。
     *
     * ★ 这是 `State` 钩子而不是 Widget 字段：Widget 每次重建都是新实例，
     *   `TextFieldState` 的同一条理由（2026-10-24 实测：盒里的 `$focused`
     *   一重建就丢）。
     */
    #[\Override]
    public function didChangeFocus(bool $focused): void
    {
        if ($this->hasFocus === $focused) {
            return;
        }

        // ★ 焦点走了 ⇒ 编辑会话**提交**（各平台的"点到别处就落库"）。
        //   顺序在改 `$hasFocus` 之前：提交回调里若应用重建了表，`$hasFocus`
        //   还没被写脏，不会出现"半提交"的中间态。
        if (!$focused && $this->editingRef !== null) {
            $this->commitEditing();
        }

        // ★ 失焦 ⇒ 拖动多半已经断在半路（捕获在"失焦"这条收口路径上被释放，
        //   收不到 up）。不在这里清，拖动高亮会一直挂到下一次点击。
        if (!$focused && $this->dragRow !== null) {
            $this->dragAnchorRow = null;
            $this->dragRow = null;
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
     * ★ 只吃这四个方向 + 首末键。**Enter/Space 不吃**：表格没有"激活格"语义，
     *   编辑（阶段 7）才需要，届时由 `TableColumn::editable()` 决定 —— 现在吃
     *   掉就等于提前占位，把"空格翻转选中"这类应用自定义语义堵死。
     * ★ 已在边界时**吃掉键但不重建**：否则每按一次边界键就白画一帧。
     */
    public function handleFocusKey(KeyEvent $event): bool
    {
        // ★ 焦点不一定在表上：`keyboard()` 沿父链找**最近声明者**（2026-10-24 为
        //   ComboBox 开的缝），于是表内子控件（单元格里的输入框 / 滑块）的按键
        //   会先飘到这儿来。焦点不在表上时，方向键属于那个控件自己
        //   （输入框里 → 是移光标，不是移格子）。
        if (!$this->hasFocus) {
            return false;
        }

        // ★★ 编辑中：键盘**整个**归草稿（方向键移插入符、Enter 提交、Esc 取消）。
        //   放在行列数检查之前 —— 有编辑格就一定有行列。
        if ($this->editingRef !== null) {
            return $this->editKey($event);
        }

        $rows = \count($this->view()->rowList());
        $cols = \count($this->view()->columnList());

        if ($rows === 0 || $cols === 0) {
            return false;   // 空格子表：方向键让给上层（页面滚动）
        }

        $chord = $event->chord;

        // ★ 进入编辑：`Enter` / `F2`（Excel 与资源管理器都吃这两个）。
        //   **只在当前列可编辑时才吃** —— 不可编辑的列上把 Enter 吞掉，等于
        //   堵死应用自定义语义（"空格翻转选中"同一条纪律，见方法注释）。
        //   ★ 双击进入编辑属手势族，前置是**时钟**（`PointerEvent` 没有时间戳，
        //     判断不了两次点击的间隔）⇒ 不在本阶段，见 `DESIGN-TABLE` §18。
        if ($chord->is(KeyChord::enter()) || $chord->is(KeyChord::f(2))) {
            return $this->beginEdit($this->focusedRow, $this->focusedCol);
        }

        $row = $this->focusedRow;
        $col = $this->focusedCol;

        if ($chord->is(KeyChord::up())) {
            $row--;
        } elseif ($chord->is(KeyChord::down())) {
            $row++;
        } elseif ($chord->is(KeyChord::left())) {
            $col--;
        } elseif ($chord->is(KeyChord::right())) {
            $col++;
        } elseif ($chord->is(KeyChord::home())) {
            $row = 0;
        } elseif ($chord->is(KeyChord::end())) {
            $row = $rows - 1;
        } else {
            return false;
        }

        $row = max(0, min($row, $rows - 1));
        $col = max(0, min($col, $cols - 1));

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

    // ── 单元格编辑（阶段 7a）────────────────────────────────────────────
    //
    // 形状与选择 / 排序 / 列宽一致：**控件只算"改成了什么"并交出去**，写库由应用做。
    // 不同的是"会话"这一段（正在编辑哪一格、草稿、插入符）住在 State 里 ——
    // 它没有外部读者，也没有外部写者（程序化进入编辑要 `TableController`，
    // 与"聚焦某格"是同一条预留通道，见 `DESIGN-TABLE` §5）。

    /**
     * 当前正在编辑的格（**下标**；没在编辑 ⇒ `null`）。
     *
     * ★ 对外报下标、对内存 key：调用方（断言 / 将来的无障碍桥）关心的是"屏幕上
     *   哪一格"，而会话本身关心"哪条数据的哪个字段"。
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
     * ★ 返回 `bool` 而不是 `void`：调用方（`handleFocusKey`）必须据此决定
     *   "吃掉这个键还是放行给应用" —— 不可编辑的列上吞掉 Enter，就把
     *   "回车触发某个命令"这类自定义语义堵死了。
     *
     * @param float|null $caretX **指针的内容坐标 x**（`null` = 键盘进入 ⇒ 插入符落末尾）。
     *                          指针进编辑必须"点在哪个字之间就落哪儿"，否则用户还得
     *                          再按一次方向键 —— 那是双击进编辑唯一比 F2 强的地方。
     */
    private function beginEdit(int $row, int $col, ?float $caretX = null): bool
    {
        // ★ 已有会话 ⇒ **先收口**：双击同一行的另一格时，旧草稿不能悄悄丢掉
        //   （`noteRowTap` 只对"点到别的行"提交，同一行是留到这里的）。
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

        // ★ 起始文本与静态渲染同源（`TableColumn::editableText()` 走同一个
        //   `textOf()`）⇒ 进入编辑的瞬间文字不跳。
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
     * ★ 先清会话再回调：应用在回调里重建表时看到的是"没在编辑"的干净状态，
     *   不会出现"回调里又读到草稿"的半个状态。
     * ★ 不夹取 / 不校验：类型转换与合法性是应用侧知识（与"控件不排序"同源）。
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
     * 与 `TextField` 的差别只有"收口键"：编辑键（字符 / 退格 / 方向 / Home / End /
     * Shift+方向 / Ctrl+A）逐字同义，而 `Enter` / `↑` / `↓` / `F2` **提交**、
     * `Esc` **取消** —— 单元格编辑必须有"改完走人"和"算了"两条出路，
     * 输入框里这两件事由应用自己安排。
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

        if ($chord->is(KeyChord::backspace())) {
            $this->settleDraft($draft->deleteBackward());

            return true;
        }

        if ($chord->is(KeyChord::delete())) {
            $this->settleDraft($draft->deleteForward());

            return true;
        }

        if ($chord->is(KeyChord::left())) {
            $this->settleDraft($draft->moveCaret($draft->previousBoundary()));

            return true;
        }

        if ($chord->is(KeyChord::right())) {
            $this->settleDraft($draft->moveCaret($draft->nextBoundary()));

            return true;
        }

        if ($chord->is(KeyChord::home())) {
            $this->settleDraft($draft->moveCaret(0));

            return true;
        }

        if ($chord->is(KeyChord::end())) {
            $this->settleDraft($draft->moveCaretToEnd());

            return true;
        }

        if ($chord->is(KeyChord::ctrl('A'))) {
            $this->settleDraft($draft->selectAll());

            return true;
        }

        if ($chord->is(KeyChord::shift('Left'))) {
            $this->settleDraft($draft->extendSelectionTo($draft->previousBoundary()));

            return true;
        }

        if ($chord->is(KeyChord::shift('Right'))) {
            $this->settleDraft($draft->extendSelectionTo($draft->nextBoundary()));

            return true;
        }

        if ($chord->is(KeyChord::shift('Home'))) {
            $this->settleDraft($draft->extendSelectionTo(0));

            return true;
        }

        if ($chord->is(KeyChord::shift('End'))) {
            $this->settleDraft($draft->extendSelectionTo(\strlen($draft->text())));

            return true;
        }

        if ($event->isText()) {
            $this->settleDraft($draft->insert($event->text));

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
    //      而且没有任何"程序化聚焦后代"的通道（本仓无 `GlobalKey`、无 autoFocus）。
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
     * ★ 用上一帧的列宽（`$widthsInBuild`）：列宽只在 build 里算得出来，而键盘
     *   移动必须**当场**把格子滚进视口（等帧末 build 再滚会先闪一帧空白）。
     *   列宽刚被拖动时也一样 —— 拖动与键盘不同时发生。
     */
    private function currentFocusRect(): ?Rect
    {
        $widths = $this->widthsInBuild;
        if ($widths === []) {
            return null;
        }

        $rowH = $this->view()->rowHeightPx();
        $spacing = $this->view()->columnSpacingPx();

        return $this->cellRect($this->focusedRow, $this->focusedCol, $widths, $rowH, $spacing);
    }

    /**
     * 某一格的内容坐标（列宽前缀和 + 间距；`y` 是**行区**坐标，不含表头）。
     *
     * @param array<int, float> $widths
     */
    private function cellRect(int $row, int $col, array $widths, float $rowH, float $spacing): ?Rect
    {
        if (!isset($widths[$col])) {
            return null;
        }

        $x = 0.0;
        for ($j = 0; $j < $col; $j++) {
            $x += $widths[$j] + $spacing;
        }

        return new Rect($x, $row * $rowH, $widths[$col], $rowH);
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

        return $clientX - $tableX - $tableBox->contentOriginX() + $this->offsetX;
    }

    /**
     * 内容坐标 x ⇒ 列下标（`null` = 落在最后一列右侧的空白里 / 还没布局过）。
     *
     * ★ 列间缝隙归**左**格：视觉上它是那一格的右内边距，点到那里仍算点那一格。
     * ★ 列宽取上一帧的（`$widthsInBuild`）—— 与 `currentFocusRect()` 同一个理由。
     */
    private function columnAtX(float $contentX): ?int
    {
        $widths = $this->widthsInBuild;
        if ($widths === []) {
            return null;
        }

        $spacing = $this->view()->columnSpacingPx();
        $x = 0.0;

        foreach ($widths as $index => $width) {
            if ($contentX < $x + $width + $spacing) {
                return $index;
            }

            $x += $width + $spacing;
        }

        return null;
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
        $rect = $this->cellRect(0, $col, $this->widthsInBuild, 0.0, $this->view()->columnSpacingPx());
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
    public function tickFrame(float $timestampMs): bool
    {
        $busy = $this->reflowIfWidthDrifted();

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
        // ★ 拖动中的区间**压过**受控选中集：选区是受控的（应用收到上报才会变），
        //   而拖动中刻意不逐帧上报 ⇒ 不叠这一层的话高亮不跟手。
        if ($this->dragRow !== null && $this->dragAnchorRow !== null) {
            $low = \min($this->dragAnchorRow, $this->dragRow);
            $high = \max($this->dragAnchorRow, $this->dragRow);
            if ($index >= $low && $index <= $high) {
                return $ctx->color(ColorToken::Accent)->withAlpha(self::SELECTED_ALPHA);
            }
        }

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

        $this->dragAnchorRow = $index;
        $this->dragRow = null;
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
        if ($this->dragAnchorRow === null) {
            return;
        }

        $row = $this->pointerRowAt($event->y);
        if ($row === null) {
            return;
        }

        if ($this->dragRow === null) {
            if (\abs($event->y - $this->dragStartY) < self::DRAG_SLOP) {
                return;   // 还在"手抖"范围内：这一次仍然是点击
            }

            $this->dragRow = $row;
            $this->markDirty();

            return;
        }

        if ($row !== $this->dragRow) {
            $this->dragRow = $row;
            $this->markDirty();
        }

        $this->dragScrollAt($event->y);
    }

    /**
     * 松手：把"锚点到终点"变成一次选择上报。
     *
     * ★ 复用 `TableRowPicker` 的 **Shift 语义**（锚点到目标替换为区间）—— 拖动就是
     *   "连续的 Shift 点击"，所以这里不该有新的一份区间算法。
     * ★ 拖动中**不**上报（选择是受控的，逐帧上报等于让应用每帧 setState 一次）；
     *   高亮靠 `$dragRow` 自己画（见 `rowBackground()`），松手才通知一次。
     */
    private function endDrag(): void
    {
        $anchor = $this->dragAnchorRow;
        $to = $this->dragRow;

        $this->dragAnchorRow = null;
        $this->dragRow = null;

        if ($to === null) {
            return;   // 只是点击：down 时已办完，没有要擦的、也没有要报的
        }

        $this->markDirty();   // 擦掉拖动高亮

        if ($anchor === null || $to === $anchor) {
            return;
        }

        $view = $this->view();
        $selection = $view->selectionSet();
        $keyOf = $view->keyMapper();
        if ($selection === null || $keyOf === null) {
            return;
        }

        /** @var list<string|int|null> $rowKeys */
        $rowKeys = [];
        foreach ($view->rowList() as $each) {
            $rowKeys[] = $keyOf($each);
        }

        $picked = TableRowPicker::pick(
            $selection,
            $rowKeys,
            $to,
            $view->selectionModeValue(),
            $rowKeys[$anchor] ?? null,
            false,
            true,
        );

        $this->anchorKey = $picked[1];

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

        $step = $this->view()->rowHeightPx() * 0.5;
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
            + $this->offsetY;
    }

    /**
     * 根坐标 y ⇒ 行下标（拖出上下边界时夹到首/末行）。
     *
     * ★ 用 `RenderTable::headerHeight()` 而不是 build 期的 `headerHeightPx()`：
     *   表头可能被约束压过，量出来的才是真布局。
     */
    private function pointerRowAt(float $clientY): ?int
    {
        $contentY = $this->pointerContentY($clientY);
        if ($contentY === null) {
            return null;
        }

        $rowH = $this->view()->rowHeightPx();
        $count = \count($this->view()->rowList());
        if ($rowH <= 0.0 || $count === 0) {
            return null;
        }

        return \max(0, \min($count - 1, (int) \floor($contentY / $rowH)));
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
        //   列宽"算出来，而这套几何在 build 之外也可得（`$widthsInBuild`）。
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
        /** @var list<string|int|null> $rowKeys */
        $rowKeys = [];
        foreach ($view->rowList() as $each) {
            $rowKeys[] = $keyOf($each);
        }

        if (($rowKeys[$index] ?? null) === null) {
            return;
        }

        $picked = TableRowPicker::pick(
            $selection,
            $rowKeys,
            $index,
            $view->selectionModeValue(),
            $this->anchorKey,
            $event->ctrl,
            $event->shift,
        );

        // 锚点只改字段、不标脏：它不参与渲染（只决定下一次 Shift 的起点）
        $this->anchorKey = $picked[1];

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
            $this->settleDraft($draft->selectParagraphAt($offset));

            return;
        }

        $this->settleDraft($draft->selectWordAt($offset));
    }

    /**
     * 某个 key 在**当前行序**里的下标（排序/过滤后行序会变 ⇒ 不能缓存行号）。
     *
     * ★ 选择那一路已改走 `TableRowPicker`（它按整份行序算）；这里只剩编辑会话的
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

        // 指针落插入符要"建盒之前"量宽（见 `$editorMetrics` 的注释）
        $this->editorMetrics = $ctx->metrics();
        $this->editorStyle = InlineEditorBox::editorStyle($ctx);

        // ★ 编辑会话的**存活检查**（阶段 7a）：行被删 / 列被删 / 换了数据源之后，
        //   `editingRef` 会指向不存在的 key —— 那种"编辑一个不存在的格子"必须
        //   当场结束，否则用户继续打字就是对着空气改（提交时也找不到行）。
        //   ★ 这里是 build 期直接改字段（同偏移夹取）：不标脏、不多一帧。
        if ($this->editingRef !== null
            && ($this->keyIndex($this->editingRef['row']) === null
                || $this->columnIndexOf($this->editingRef['col']) === null)) {
            $this->editingRef = null;
            $this->draft = null;
            $this->draftOrigin = '';
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
        // `focusRect()` 在 build 之外算光标位置要用它（见字段注释）
        $this->widthsInBuild = $widths;

        $sum = 0.0;
        foreach ($widths as $w) {
            $sum += $w;
        }
        // ★ 内容宽至少铺满视口：固定列加起来不满时，右侧不能露出一条"没有底色"的缝
        $contentW = max($viewportW, $sum + $spacing * max(0, \count($columns) - 1));
        $this->contentWInBuild = $contentW;

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

        // 光标格跟着行列数夹（行被删 / 列被删之后不能停在不存在的位置）。
        // 与偏移写回同一条纪律：build 期直接写字段，不经 `setState`（不标脏）。
        $this->focusedRow = $count === 0 ? 0 : min($this->focusedRow, $count - 1);
        $this->focusedCol = $columns === [] ? 0 : min($this->focusedCol, \count($columns) - 1);

        // ★ 只在**有焦点**时给框：失焦却画焦点框就是撒谎（见字段注释）。
        //   没焦点 ⇒ `null`，盒那一侧不需要知道焦点概念。
        $focusRect = $this->hasFocus
            ? $this->cellRect($this->focusedRow, $this->focusedCol, $widths, $rowH, $spacing)
            : null;

        // ★★ 列窗口（阶段 6）：只造看得见的列。窗口的**内容坐标**端点要留给外面判
        //   "横滚之后还盖不盖得住"（`scrollByAxis(Horizontal)`），否则滚过缓冲带
        //   之后视口边缘就是一截没造的空白。
        //   ★ 与行窗口共用同一个 `cacheExtent`：语义相同（"滚这么多以内不重建"），
        //     多开一个旋钮只让调用方多一个要猜的数。
        $cache = $view->cacheExtentPx();
        [$colFirst, $colEnd, $colLeading] = ExtentWindow::visible(
            $widths,
            $spacing,
            $offsetX,
            $viewportW,
            $cache,
        );
        $this->createdColumns = $colEnd - $colFirst;
        $this->colWindowStart = $colLeading;
        $this->colWindowEnd = $colLeading + ExtentWindow::extentOf($widths, $colFirst, $colEnd, $spacing);

        // ★★ 窗口 = 可视行 ± **缓冲带**（`cacheExtent`，2026-10-27 起）。
        //   没有缓冲带时 `first = floor(offsetY/rowH)`：偏移一动 `first` 就动
        //   ⇒ 窗口每滚一行就变 ⇒ 又退回"每帧重建"（本项优化当场归零）。
        //   缓冲带让"滚一小段不重建"成立，代价是窗口变大、每次 layout 更贵
        //   （行区 layout 是全树 O(n)）⇒ 取值是权衡，见 `Table::$cacheExtentPx`。
        $first = (int) \floor(max(0.0, $offsetY - $cache) / $rowH);
        $last = (int) \ceil(($offsetY + $bodyH + $cache) / $rowH);
        $first = max(0, min($first, $count));
        $last = max($first, min($last, $count));

        // ★ 记下窗口的**内容坐标**范围 —— 滚动期靠它判"要不要重建"（见 `$windowStart`）
        $this->windowStart = $first * $rowH;
        $this->windowEnd = $last * $rowH;

        $headerKid = $this->headerRow($ctx, $columns, $widths, $contentW, $headerH, $spacing, $colFirst, $colEnd, $colLeading);
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
        for ($i = $first; $i < $last; $i++) {
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
            for ($j = $colFirst; $j < $colEnd; $j++) {
                $cells[] = $this->cellShell(
                    $this->cellContent($row, $i, $columns[$j], $rowKey),
                    $widths[$j],
                    $columns[$j],
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
            if ($colLeading > 0.0) {
                $rowWidget = $rowWidget->padding(EdgeInsets::only(left: $colLeading));
            }

            $line = SizedBox::make($rowWidget)
                ->width($contentW)
                ->height($rowH)
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
            $focusRect,
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
        int $colFirst,
        int $colEnd,
        float $colLeading,
    ): Widget {
        $sort = $this->view()->sortState();

        $cells = [];
        for ($j = $colFirst; $j < $colEnd; $j++) {
            $cells[] = $this->headerCell($ctx, $columns[$j], $widths[$j], $sort, $j);
        }

        $row = Row::make()->children($cells)->spacing($spacing)->cross(FlexAlign::Center);

        // ★ 与数据行**同一个** `$colLeading`、同一处写法（阶段 1 闸门的第 3 条：
        //   表头与行区各算一次必然漂）。这里是唯一允许出现第二遍的地方，
        //   所以刻意把 `$cells` 的构造也写成同形 —— 两边结构一样才不会各自漂。
        if ($colLeading > 0.0) {
            $row = $row->padding(EdgeInsets::only(left: $colLeading));
        }

        return SizedBox::make($row)
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
        $editable = null;
        foreach ($columns as $column) {
            $keys[$column->keyName()] = true;
            if ($sortable === null && $column->isSortable()) {
                $sortable = $column->keyName();
            }
            if ($resizable === null && $column->isResizable()) {
                $resizable = $column->keyName();
            }
            if ($editable === null && $column->isEditable()) {
                $editable = $column->keyName();
            }

            // ★ `cell()` 列不能编辑，**在挂载期就说**：等到用户按 Enter 才报，
            //   那时候他已经在等结果了（"能不能编辑"是结构问题，不是运行期问题）。
            if ($column->isEditable() && $column->fieldName() === null) {
                Errors::report(sprintf(
                    'Table 的列 "%s" 声明了 editable()，但它没有 field() —— 单元格编辑要'
                        . "\n" . '"起始文本 + 提交到哪个字段"，而 cell() 只给一个任意 Widget。'
                        . "\n" . '修法：该列改用 field("字段名")，或去掉 ->editable()。',
                    $column->keyName(),
                ));
            }
        }

        if ($editable !== null && $view->cellEditHandler() === null) {
            Errors::report(sprintf(
                'Table 的列 "%s" 声明了 editable() 却没有 onCellEdit() —— 编辑是受控的，'
                    . "\n" . '控件只算"改成了什么"并交出去，应用不接就等于改完没人收（回车后什么都没发生）。'
                    . "\n" . '修法：加上 ->onCellEdit(fn($rowKey, string $col, string $value) => ...)，'
                    . "\n" . '或去掉 ->editable()。',
                $editable,
            ));
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
    /**
     * 把列声明摊平成 `Layout\ColumnWidths` 的入参。
     *
     * ★ 只是搬字段，不含决策 —— 四层分配规则（override 最优先 / flex 吃剩余 /
     *   逐列夹）全在纯函数里，那里能单测。
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
