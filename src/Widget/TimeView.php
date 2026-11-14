<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\EdgeInsets;
use Drift\Input\SemanticsRole;
use Drift\Layout\ScrollBarGeometry;
use Drift\Widget\ScrollView;

/**
 * 时分秒列选择器（A3 第三件的后半，2026-10-29）。
 *
 * ```php
 * TimeView::make(LocalTime::make(9, 5))          // 受控：值住应用的 State
 *     ->onChanged(fn(LocalTime $t) => $this->setState(fn() => $this->time = $t));
 *
 * TimeView::make($this->time)->withSeconds();    // 出第三列（默认只有时 / 分）
 * ```
 *
 * ## 四个岔口
 *
 * | 岔口 | 裁决 | 理由 |
 * |---|---|---|
 * | 与 `CalendarView` 合成一个控件 vs **独立** | **独立** | `time-only` 的表单（"每天 02:00 跑批"）用不上一张 7×6 月历；合成后只能靠 `type` 分支写两套布局，等于内部还是两块。`DatePicker` 负责把它们并排 |
 * | 自己当渲染盒 vs 组合 | **`StatelessWidget` + `Row`/`Column`/`LazyColumn`** | 零新增 Element / RenderBox（同 `TreeView` / `CalendarView`）。三列定长数字，没有盒级才知道的东西 |
 * | 三列各一个 `LazyColumn` vs 144 个按钮全量 | **`LazyColumn`** | 24 + 60 + 60 = 144 格；虚拟化后每帧只造 3×7 格。`Each` 那种全量在 AOT 真机上首帧就要多花一次布局 |
 * | 滚动位置：从 0 开始 vs **打开就落在当前值** | **`initialOffset()`** | 打开时看得见 `09:05` 才有意义；从 `00` 开始要往下滚 9 格。为此给 `LazyList` 补了 `initialOffset()`（通用能力，不只为本类） |
 *
 * ## 受控
 *
 * `make($value)` 的 `LocalTime` 由应用给，点击只回调 `onChanged(新值)`（与
 * `CalendarView` / `TreeView` / `Select` 同纪律）。**`$value` 是必填的**：
 * 它同时是"高亮哪一格"和"打开时滚到哪儿"的锚 —— 没有锚的列选择器只能自己
 * 猜一个起点，而猜出来的起点和"提交时读到的值"是两份真相。
 *
 * ## 有意边界
 *
 * - **Tab 序只留"当前值那一格"**（roving tabindex，§57）：144 格挤进 Tab 序是灾难，
 *   一格都不留则键盘根本进不来。其余格子靠方向键（几何导航读可聚焦表，不读 Tab 序）。
 * - **秒默认不显示**：企业表单绝大多数到分。`withSeconds()` 才出第三列 ——
 *   值层（`LocalTime::$second`）始终在，切回来不丢。
 * - **不做"步进箭头" / "确定按钮"**：点即选 + 受控，没有中间态。要"确定"的
 *   交互是"应用拿 `onChanged` 自己决定什么时候提交"。
 * - **不解析输入**：这是列选择器，不是 `TextField`。
 *
 * @api
 */
final class TimeView extends StatelessWidget
{
    /** 每列**内容**宽：两位数字 + 两侧内边距（`Button::compact()`）+ 呼吸 */
    public const float COLUMN_WIDTH = 56.0;

    /** 每行高 —— 同时也是 `LazyColumn` 的 `itemExtent`（窗口计算的除数） */
    public const float ROW_HEIGHT = 34.0;

    /** 一屏看到几行：奇数不好看，6 行足够"上下都还能滚"的暗示 */
    public const int VISIBLE_ROWS = 6;

    /** 列与列之间的间隔 */
    public const float COLUMN_GAP = 4.0;

    /**
     * 视口右侧被滚动条占掉的宽。
     *
     * ★ 滚动条是**画在视口里**的（`ScrollBarGeometry::thumbRect()` 按渲染盒宽定位，
     *   画在 `宽 - 条粗 - 边距` 处），`->padding()` 只把内容挤窄、挪不动它 ——
     *   上一版就是这么错的：内容被推左、按钮却还声明 56 宽，条照样压在色块上。
     *   ⇒ 正确做法是**把条的宽度算进列盒**，让内容与条各占各的。
     * ★ 用常量而不是写死 9：条的粗细以后改了这里跟着走。
     */
    private const float BAR_GUTTER = ScrollBarGeometry::THICKNESS + 2.0 * ScrollBarGeometry::MARGIN;

    /** 列盒宽 = 内容 + 条留白（条落在内容右侧的空白里，两侧各留一份边距） */
    private const float COLUMN_EXTENT = self::COLUMN_WIDTH + self::BAR_GUTTER;

    /** 打开时在当前值上方留几行 —— 让人一眼看出"上面还有更早的" */
    private const int LEAD_ROWS = 2;

    private const int KIND_HOUR = 0;
    private const int KIND_MINUTE = 1;
    private const int KIND_SECOND = 2;

    private readonly LocalTime $value;

    private bool $seconds = false;

    private ?\Closure $onChanged = null;

    private function __construct(LocalTime $value)
    {
        // ★ 必须调：`$mods` 在基类构造器里初始化（同 `CalendarView`）
        parent::__construct();
        $this->value = $value;
    }

    public static function make(LocalTime $value): self
    {
        return new self($value);
    }

    /** 出第三列（秒）。默认只有时 / 分 */
    public function withSeconds(): static
    {
        $this->mutate();
        $this->seconds = true;

        return $this;
    }

    /**
     * 整块的宽（2 列或 3 列）—— 并排布局的调用方（`DateTimePicker`）要按它算面板宽。
     *
     * ★ 列盒宽里含滚动条留白（`COLUMN_EXTENT`），所以不能拿 `COLUMN_WIDTH × 列数`
     *   估：那样算出来的宽度会让面板刚好短一截，`RenderFlex` 直接报"放不下"。
     */
    public static function widthOf(bool $withSeconds): float
    {
        $columns = $withSeconds ? 3 : 2;

        return $columns * self::COLUMN_EXTENT + ($columns - 1) * self::COLUMN_GAP;
    }

    public function onChanged(\Closure $handler): static
    {
        $this->mutate();
        $this->onChanged = $handler;

        return $this;
    }

    // ── 读的口径（`StatelessWidget` 自己读字段，不需要 `@internal` 访问器）──

    public function valueOf(): LocalTime
    {
        return $this->value;
    }

    public function showsSeconds(): bool
    {
        return $this->seconds;
    }

    public function onChangedHandler(): ?\Closure
    {
        return $this->onChanged;
    }

    public function build(BuildContext $ctx): Widget
    {
        $columns = [
            $this->column(self::KIND_HOUR, '时'),
            $this->column(self::KIND_MINUTE, '分'),
        ];

        if ($this->seconds) {
            $columns[] = $this->column(self::KIND_SECOND, '秒');
        }

        $body = Column::make()->spacing(2.0)->children([
            $this->headerRow(),
            Row::make()->spacing(self::COLUMN_GAP)->children($columns),
        ]);

        // ★ 横向响应式：TimeView 是定宽整体（时/分/秒列 + 滚动条留白），可用宽
        //   小于固有宽时**横向滚**而非溢出/裁字（成熟 GUI 的常规做法：Flutter 的
        //   time picker 等固定宽多列控件在窄容器里都让内容滚）。
        //
        // ★★ 外层 `SizedBox(widthOf())` **不可省**：横向 `ScrollView` 的宽 = 视口宽
        //   = 父给的上界 ⇒ 在 `Row` 里它会撑满剩余宽（实测 452），把 TimeView 从定宽
        //   212 变成"吃掉整行"，`DateTimePicker` 的面板随即溢出（756 > 452）。
        //   钉死到固有宽后：宽容器里取 212（不撑满），窄容器里被
        //   `Modifier::tighten()` 夹到父上界（208）⇒ 内层横滚 4px。两个需求同解。
        return SizedBox::make(ScrollView::make($body, false))
            ->width(self::widthOf($this->seconds));
    }

    /** 表头：`时 分 秒` —— 三列纯数字没有表头分不清哪列是哪列 */
    private function headerRow(): Widget
    {
        $cells = [
            $this->headerCell('时'),
            $this->headerCell('分'),
        ];

        if ($this->seconds) {
            $cells[] = $this->headerCell('秒');
        }

        return Row::make()->spacing(self::COLUMN_GAP)->children($cells);
    }

    private function headerCell(string $label): Widget
    {
        // ★ 宽按**列盒**算（与数字列起点对齐），文字在**内容区**里居中（与数字对齐）
        return SizedBox::make(
            Center::make(Text::make($label)->caption())
                ->padding(EdgeInsets::only(0.0, 0.0, self::BAR_GUTTER, 0.0))
        )->width(self::COLUMN_EXTENT);
    }

    /**
     * 一列（`$kind` 决定这列是时 / 分 / 秒）。
     *
     * ★ 列内容与偏移都**按值**算好再交给 `LazyColumn`：`items()` 的 builder 会被
     *   留在渲染树上，捕获 `$this` 会让整个 Widget 跟着活到列表卸载之后
     *   （同 `TreeView` 的 `rowWidget()` 那条纪律）。
     */
    private function column(int $kind, string $title): Widget
    {
        $value = $this->value;
        $on = $this->onChanged;
        $max = match ($kind) {
            self::KIND_HOUR => 23,
            default => 59,
        };
        $current = $this->partOf($value, $kind);

        $cells = [];
        for ($i = 0; $i <= $max; $i++) {
            $cells[] = $i;
        }

        return SizedBox::make(
            LazyColumn::make(self::ROW_HEIGHT)
                ->items($cells, static function (int $n) use ($kind, $current, $value, $on, $title): Widget {
                    return self::cell($kind, $n, $current, $value, $on, $title);
                })
                ->keyOf(static fn(int $n): string => (string) $n)
                // ★ 打开就看得见当前值，上面还留两行 —— 从 0 开始要滚 9 格
                ->initialOffset(max(0, $current - self::LEAD_ROWS) * self::ROW_HEIGHT)
                // ★ 视口必须**自己声明**：外层 `SizedBox` 定的是布局尺寸，而窗口
                //   换算读的是 `BuildContext`（浮层里那根轴是无限大）⇒ 不声明就会把
                //   60 格全造出来。见 `LazyList::viewportExtent()`。
                ->viewportExtent(self::VISIBLE_ROWS * self::ROW_HEIGHT)
        )->width(self::COLUMN_EXTENT)->height(self::VISIBLE_ROWS * self::ROW_HEIGHT);
    }

    /** 这个格子点下去要改哪一段 —— 三列共用一套，只差 `$kind` */
    private static function cell(
        int $kind,
        int $n,
        int $current,
        LocalTime $value,
        ?\Closure $on,
        string $title,
    ): Widget {
        $cell = Button::make(\str_pad((string) $n, 2, '0', STR_PAD_LEFT))
            ->compact()
            ->width(TimeView::COLUMN_WIDTH)
            ->variant($n === $current ? ButtonVariant::Primary : ButtonVariant::Flat);

        // ★ **roving tabindex**（§57）：每列只有"当前值那一格"进 Tab 序。
        //   144 格全进是灾难（按 144 次 Tab 才出得去）；一格不进则整块时刻表
        //   对键盘是一块空白 —— 实测 `focusNext()` 直接落空，焦点压根进不来。
        if ($on === null || $n !== $current) {
            $cell->skipFocus();
        }

        // ★ 无障碍：同样的 "05"，在时列和在分列是完全不同的东西
        $cell->semantics(SemanticsRole::Button, $title . ' ' . $n);

        if ($on !== null) {
            $cell->onPressed(static function () use ($on, $kind, $n, $value): void {
                $on(match ($kind) {
                    TimeView::KIND_HOUR => new LocalTime($n, $value->minute, $value->second),
                    TimeView::KIND_MINUTE => new LocalTime($value->hour, $n, $value->second),
                    default => new LocalTime($value->hour, $value->minute, $n),
                });
            });
        }

        return $cell;
    }

    private function partOf(LocalTime $value, int $kind): int
    {
        return match ($kind) {
            self::KIND_HOUR => $value->hour,
            self::KIND_MINUTE => $value->minute,
            default => $value->second,
        };
    }
}
