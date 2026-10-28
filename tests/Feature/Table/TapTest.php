<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Scale;
use Drift\Input\PointerEvent;
use Drift\Widget\BuildContext;
use Drift\Widget\InlineEditorBox;

/**
 * 表格的点击链：单击落光标格、双击进编辑、指针落插入符。
 *
 * ★ `TapTracker` 本身（四条规则）在 `Unit/Input/TapTrackerTest`；这里验的是**接线**
 *   ——`dispatchInput()` 有没有把 `clickCount` 标上、行容器有没有据此分流。
 * ★ 反向断言（"慢双击不进编辑"、"双击不可编辑列不进编辑"）单独看没有区分度：
 *   实现完全不标 `clickCount` 时它们**照样绿**。它们的价值靠正向那条
 *   `testDoubleClickEntersEdit` 撑着 —— 实测：去掉标注后只有正向那条与插入符那条红。
 * ★ 几何：表头 32 + 行高 40 ⇒ 行 1 的 y ∈ [72,112)；id 列 [0,100)、姓名列 [100,300)。
 */
final class TapTest extends SmokeTestCase
{
    public function testSingleClickMovesCursorToCell(): void
    {
        $s = EditSession::open();

        self::clickAt($s, 150.0, 92.0, 1000.0);

        check(
            'Table · 指针：单击 ⇒ 光标格落到**指针那一格**（行 1 / 列 1，以前列不动）',
            $s->focusedCell() === ['row' => 1, 'col' => 1],
            'cell=' . \json_encode($s->focusedCell()),
        );

        $this->flushChecks();
    }

    public function testDoubleClickEntersEdit(): void
    {
        $s = EditSession::open();

        self::clickAt($s, 150.0, 92.0, 1000.0);
        self::clickAt($s, 150.0, 92.0, 1100.0);

        check(
            'Table · 指针：双击可编辑格 ⇒ 进编辑（`editingCell()` 把 key 解析回行下标）',
            $s->isEditing() && $s->editingCell() === ['row' => 1, 'col' => 1],
            'editing=' . ($s->isEditing() ? 'y' : 'n')
                . ' cell=' . \json_encode($s->editingCell()),
        );

        $this->flushChecks();
    }

    public function testSlowDoubleClickDoesNotEdit(): void
    {
        $s = EditSession::open();

        self::clickAt($s, 150.0, 92.0, 1000.0);
        self::clickAt($s, 150.0, 92.0, 1600.0);

        check(
            'Table · 指针：两次点击隔 600ms ⇒ **不进编辑**（壳给的时间真的传到了判据里）',
            !$s->isEditing(),
            'editing=' . ($s->isEditing() ? 'y' : 'n'),
        );

        $this->flushChecks();
    }

    public function testDoubleClickOnNonEditableColumnOnlyMovesCursor(): void
    {
        $s = EditSession::open();

        // x=50 落在 id 列（不可编辑）
        self::clickAt($s, 50.0, 92.0, 1000.0);
        self::clickAt($s, 50.0, 92.0, 1100.0);

        check(
            'Table · 指针：双击**不可编辑**列 ⇒ 不进编辑，光标格仍落到该列',
            !$s->isEditing() && $s->focusedCell() === ['row' => 1, 'col' => 0],
            'editing=' . ($s->isEditing() ? 'y' : 'n')
                . ' cell=' . \json_encode($s->focusedCell()),
        );

        $this->flushChecks();
    }

    /**
     * 指针落插入符：文字左缘 = 列起点 100 + 内边距 8。
     *
     * 吸附按字符中点（与输入框同一个换算 `offsetAtX()`）⇒ 点字的左 1/4 插入符在字
     * **前**（x 仍是 108，文字一个像素没动），右 3/4 在字**后**（x = 108 + 字宽）。
     * 两半都测：只测一半时"永远取 0"与"永远取 len"都能过。
     */
    public function testDoubleClickPlacesCaretByGlyphHalf(): void
    {
        $s = EditSession::open();
        // 字宽要从度量取：中文字形宽度随主题字体变，写死 16 会在换字体时静默错位
        $probe = new BuildContext(Fixtures::VIEW_W, Fixtures::VIEW_H, $s->app->theme(), $s->app->metrics());
        $glyphW = $s->app->metrics()->advance('乙', InlineEditorBox::editorStyle($probe), new Scale(1.0));
        $split = 108.0 + $glyphW;

        $left = EditSession::open();
        self::clickAt($left, 108.0 + $glyphW * 0.25, 92.0, 1000.0);
        self::clickAt($left, 108.0 + $glyphW * 0.25, 92.0, 1100.0);
        $leftCaret = $left->caret();

        $right = EditSession::open();
        self::clickAt($right, 108.0 + $glyphW * 0.75, 92.0, 1000.0);
        self::clickAt($right, 108.0 + $glyphW * 0.75, 92.0, 1100.0);
        $rightCaret = $right->caret();

        check(
            'Table · 指针：双击字的**左半边** ⇒ 插入符落在字前（x=108，文字一个像素没动）',
            $leftCaret !== null && \abs($leftCaret->left - 108.0) < 0.01,
            'w=' . \sprintf('%.2f', $glyphW)
                . ' x=' . ($leftCaret === null ? 'null' : \sprintf('%.2f', $leftCaret->left)),
        );
        check(
            'Table · 指针：双击字的**右半边** ⇒ 插入符落在字后（x=108+字宽）',
            $rightCaret !== null && \abs($rightCaret->left - $split) < 0.01,
            'w=' . \sprintf('%.2f', $glyphW)
                . ' x=' . ($rightCaret === null ? 'null' : \sprintf('%.2f', $rightCaret->left)),
        );

        $this->flushChecks();
    }

    /** 按下 + 抬起成一次点击；`$at` 是**按下**的时间（链只看按下） */
    private static function clickAt(EditSession $s, float $x, float $y, float $at): void
    {
        $s->app->dispatchInput(PointerEvent::down($x, $y), $at);
        $s->app->dispatchInput(PointerEvent::up($x, $y), $at + 20.0);
        // 点完要推帧才看得到布局结果（`caretRect()` 取的是盒的当前位置）
        $s->tick();
    }
}
