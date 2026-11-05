<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\Rect;
use Drift\Layout\ExtentWindow;

/**
 * 表格**一帧的几何快照**（不可变值对象）。一次 build 算一个快照，
 * `null` 即"还没算过"（唯一空值表达）。列宽、行高、两个窗口、夹取后的偏移都在内。
 * 名字里的"帧" = 一次 build 的快照，与 `Frame::run()` 的帧循环无关。
 * 坐标系（改这个类必读）：坐标全在**内容坐标**里；纵向内容坐标是**行区**的
 * （行区 0 点是表头下沿，表头是钉住的 chrome，不参与滚动）。
 * `bodyH` = 视口高 − 表头高；`contentH` 含表头。
 * @internal 应用只认 `Table`
 */
final readonly class TableGeometry
{
    /**
     * 这一帧的列宽（逻辑像素，已含三层分配与用户拖拽覆盖）。
     * @var array<int, float>
     */
    public array $widths;

    /** 行高（定高是行虚拟化的前提） */
    public float $rowHeight;

    /** 表头高（`Table` 声明的值，不是盒实测值 —— 后者只有指针换算才该用） */
    public float $headerHeight;

    /** 列间距（`Row` 的 `spacing`，与格内 padding 不是一回事） */
    public float $spacing;

    /** 行数（这一帧的数据行数，不含表头） */
    public int $rowCount;

    /** 视口宽（上一帧盒实测；首帧是窗口宽这个上界） */
    public float $viewportW;

    /** 视口高（同上） */
    public float $viewportH;

    /** 内容宽 = `max(视口宽, 列宽和 + 间距和)` —— 至少铺满，右侧不留没底色的缝 */
    public float $contentW;

    /** 内容高（**含**表头）—— 滚动条与可滚上限按它算 */
    public float $contentH;

    /** 行区可见高 = 视口高 − 表头高（≥ 0） */
    public float $bodyH;

    /** 夹取后的横向偏移（上界 = `contentW − viewportW`） */
    public float $offsetX;

    /** 夹取后的纵向偏移（上界 = `总行高 − bodyH`） */
    public float $offsetY;

    /** 这一帧造的行窗口 `[rowFirst, rowLast)` */
    public int $rowFirst;

    public int $rowLast;

    /** 这一帧造的列窗口 `[colFirst, colLast)` */
    public int $colFirst;

    public int $colLast;

    /**
     * 被裁掉的**前缀列**在内容坐标里的位置。
     * ★ 行与表头都用它做左内边距（只造窗口列之后内容整体左移，必须补回来）。
     *   **两边必须用同一个数** —— 各算一次必然漂。
     */
    public float $colLeading;

    /** 行窗口在内容坐标里的范围（滚动期判"还盖不盖得住"） */
    private float $rowWindowStart;

    private float $rowWindowEnd;

    /** 列窗口在内容坐标里的范围（同上，横滚专用） */
    private float $colWindowStart;

    private float $colWindowEnd;

    /**
     * @param array<int, float> $widths
     */
    private function __construct(
        array $widths,
        float $rowHeight,
        int $rowCount,
        float $headerHeight,
        float $spacing,
        float $viewportW,
        float $viewportH,
        float $offsetX,
        float $offsetY,
        float $cache,
    ) {
        $this->widths = $widths;
        $this->rowHeight = $rowHeight;
        $this->rowCount = $rowCount;
        $this->headerHeight = $headerHeight;
        $this->spacing = $spacing;
        $this->viewportW = $viewportW;
        $this->viewportH = $viewportH;

        $totalRows = $rowCount * $rowHeight;
        $this->contentH = $headerHeight + $totalRows;
        $this->bodyH = \max(0.0, $viewportH - $headerHeight);

        // 内容宽至少铺满视口：固定列加起来不满时，右侧不能露出一条"没有底色"的缝
        $sum = 0.0;
        foreach ($widths as $w) {
            $sum += $w;
        }
        $this->contentW = \max($viewportW, $sum + $spacing * \max(0, \count($widths) - 1));

        // ★ 两轴的夹取都写在这里，且**结果写回快照**：State 里存的是"用户增量累加值"，
        //   而内容会变短（过滤 / 列宽被拖窄 / 窗口变宽）。旧值一旦长期大于新上限，
        //   之后每一笔增量都先拿去消耗这段**死区** —— 真机表现是"拖着条，画面不动"。
        $this->offsetY = \min(\max(0.0, $offsetY), \max(0.0, $totalRows - $this->bodyH));
        $this->offsetX = \min(\max(0.0, $offsetX), \max(0.0, $this->contentW - $viewportW));

        [$colFirst, $colEnd, $colLeading] = ExtentWindow::visible(
            $widths,
            $spacing,
            $this->offsetX,
            $viewportW,
            $cache,
        );
        $this->colFirst = $colFirst;
        $this->colLast = $colEnd;
        $this->colLeading = $colLeading;
        $this->colWindowStart = $colLeading;
        $this->colWindowEnd = $colLeading + ExtentWindow::extentOf($widths, $colFirst, $colEnd, $spacing);

        // 行窗口 = 可视行 ± **缓冲带**：没有缓冲带时 `first = floor(offsetY/rowH)`，
        //   偏移一动 `first` 就动 ⇒ 窗口每滚一行就变 ⇒ 退回"每帧重建"。
        //   缓冲带让"滚一小段不重建"成立，代价是窗口变大、每次 layout 更贵。
        $first = (int) \floor(\max(0.0, $this->offsetY - $cache) / $rowHeight);
        $last = (int) \ceil(($this->offsetY + $this->bodyH + $cache) / $rowHeight);
        $this->rowFirst = \max(0, \min($first, $rowCount));
        $this->rowLast = \max($this->rowFirst, \min($last, $rowCount));
        $this->rowWindowStart = $this->rowFirst * $rowHeight;
        $this->rowWindowEnd = $this->rowLast * $rowHeight;
    }

    /**
     * 算这一帧的几何。
     * @param array<int, float> $widths 这一帧分配好的列宽
     * @param float $rowHeight 行高（必须 > 0，`Table::rowHeight()` 已兜）
     * @param int $rowCount 数据行数
     * @param float $headerHeight 表头高
     * @param float $spacing 列间距
     * @param float $viewportW 视口宽
     * @param float $viewportH 视口高
     * @param float $offsetX 待生效的横向偏移（**未夹**，本方法负责夹）
     * @param float $offsetY 待生效的纵向偏移（**未夹**）
     * @param float $cache 窗口缓冲带
     */
    public static function compute(
        array $widths,
        float $rowHeight,
        int $rowCount,
        float $headerHeight,
        float $spacing,
        float $viewportW,
        float $viewportH,
        float $offsetX,
        float $offsetY,
        float $cache,
    ): self {
        return new self(
            $widths,
            $rowHeight,
            $rowCount,
            $headerHeight,
            $spacing,
            $viewportW,
            $viewportH,
            $offsetX,
            $offsetY,
            $cache,
        );
    }

    /** 一屏能装几行（PageUp/PageDown 步长）；`createdRows()` 含 cache 行，不能当可见行数。 */
    public function pageRows(): int
    {
        if ($this->rowHeight <= 0.0 || $this->bodyH <= 0.0) {
            return 1;
        }

        return max(1, (int) floor($this->bodyH / $this->rowHeight));
    }

    /** 这一帧造了几行（虚拟化的验收判据） */
    public function createdRows(): int
    {
        return $this->rowLast - $this->rowFirst;
    }

    /** 这一帧造了几列（与 `createdRows()` 同职责，**不合成一个数**：定高 vs 变宽是两个判据） */
    public function createdColumns(): int
    {
        return $this->colLast - $this->colFirst;
    }

    /**
     * 横向装不下吗（`false` = 没得滚 ⇒ 滚轮早退，不白标脏）。
     * 快照一旦存在就"知道"，故这里无"不知道 ⇒ 放行"分支：那分支归调用方
     * （`$geometry === null` 时放行），别在两处各判一次。
     */
    public function horizontalOverflows(): bool
    {
        return $this->contentW > $this->viewportW;
    }

    /**
     * 这一帧造的行窗口还盖得住视口吗（`offsetY`/`bodyH` 传**当前活值**）。
     * ★ 参数不取快照自己的 `offsetY`：用户可能刚滚过，快照里那个是上一帧的。
     */
    public function coversRows(float $offsetY, float $bodyH): bool
    {
        return $offsetY >= $this->rowWindowStart
            && $offsetY + $bodyH <= $this->rowWindowEnd;
    }

    /** 列窗口同理（`viewportW` 也取活值：窗口可能刚 resize） */
    public function coversColumns(float $offsetX, float $viewportW): bool
    {
        return $offsetX >= $this->colWindowStart
            && $offsetX + $viewportW <= $this->colWindowEnd;
    }

    /**
     * 内容坐标 x ⇒ 列下标（`null` = 落在最后一列右侧的空白里）。
     * 列间缝隙归**左**格：视觉上它是那一格的右内边距，点到那里仍算点那一格。
     */
    public function colAt(float $contentX): ?int
    {
        $x = 0.0;
        foreach ($this->widths as $index => $width) {
            if ($contentX < $x + $width + $this->spacing) {
                return $index;
            }

            $x += $width + $this->spacing;
        }

        return null;
    }

    /**
     * 行区内容坐标 y ⇒ 行下标（**夹到首/末行**，`null` = 没有行）。
     * 夹而不回 `null`：拖动框选靠"拖出边界 ⇒ 夹到末行"才能选到看不见的行。
     */
    public function rowAt(float $contentY): ?int
    {
        if ($this->rowCount === 0 || $this->rowHeight <= 0.0) {
            return null;
        }

        return \max(0, \min($this->rowCount - 1, (int) \floor($contentY / $this->rowHeight)));
    }

    /**
     * 某一格在**行区内容坐标**里的矩形（`null` = 列不存在）。
     * `y` 不含表头高：表头是钉住的 chrome，`offsetY` 也在这套坐标里。
     */
    public function cellRect(int $row, int $col): ?Rect
    {
        $width = $this->colWidth($col);
        if ($width === null) {
            return null;
        }

        $x = 0.0;
        for ($j = 0; $j < $col; $j++) {
            $x += $this->widths[$j] + $this->spacing;
        }

        return new Rect($x, $row * $this->rowHeight, $width, $this->rowHeight);
    }

    /** 第 `$col` 列的宽（`null` = 列不存在） */
    public function colWidth(int $col): ?float
    {
        if (!isset($this->widths[$col])) {
            return null;
        }

        return $this->widths[$col];
    }
}
