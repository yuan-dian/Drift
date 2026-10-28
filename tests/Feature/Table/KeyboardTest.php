<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Dev\Smoke\App\TableScrollApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Layout\RenderTable;
use Drift\Rendering\ColorToken;
use Drift\Theme\ThemeData;

/**
 * 键盘光标格：**表 = 一个 Tab 位，格 = 表内光标**。
 *
 * ★ 为什么不用"每格一个焦点节点"：1000 行 × 5 列 = 5000 个 Tab 位，Tab 键就废了；
 *   而虚拟化会回收不可见行 ⇒ 焦点节点跟着消失（焦点丢在虚空里）。
 * ★ 光标格存**下标**、选择集存 **key**：前者是"眼睛在哪"（排序后留在原位置），
 *   后者是"哪些数据被选中"（排序后跟着数据走）。
 *
 * ★ 反例自检：光标格改存 key ⇒ End 之后排序会跳格；`focusRect()` 不看焦点 ⇒
 *   "没焦点却画框"那条红；边界不吞键 ⇒ "吃掉这个键"红（键会漏给上层）。
 */
final class KeyboardTest extends SmokeTestCase
{
    private static function app(): TableScrollApplication
    {
        $app = new TableScrollApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(Fixtures::VIEW_W, Fixtures::VIEW_H)));

        return $app;
    }

    public function testSingleTabStopAndInitialCell(): void
    {
        $app = self::app();
        $root = $app->root();
        $state = Fixtures::stateOf($root);
        $focus = $app->focus();

        check(
            'Table · 键盘：整张表只占**一个** Tab 位（焦点链里只有它自己）',
            $root->widget()->isFocusable() && \count($focus?->order() ?? []) === 1,
            'focusable=' . ($root->widget()->isFocusable() ? 'y' : 'n')
                . ' order=' . \count($focus?->order() ?? []),
        );
        check(
            'Table · 键盘：光标格初始 (0,0)；**没焦点不画框**（焦点在别处却有框就是撒谎）',
            $state?->focusedCell() === ['row' => 0, 'col' => 0]
                && Fixtures::boxOf($root)?->focusRect() === null,
            'cell=' . \json_encode($state?->focusedCell())
                . ' rect=' . (Fixtures::boxOf($root)?->focusRect() === null ? 'null' : '有'),
        );

        $this->flushChecks();
    }

    public function testArrowsMoveCursorAndClampAtEdge(): void
    {
        $app = self::app();
        $root = $app->root();
        $state = Fixtures::stateOf($root);
        $focus = $app->focus();
        $tight = BoxConstraints::tight(new Size(Fixtures::VIEW_W, Fixtures::VIEW_H));

        // ② 真实焦点链：Tab ⇒ 焦点落表上 ⇒ 第一格画出 300×40 的框
        $focus?->focusNext();
        $app->frame(16.0, $tight);
        $rect = Fixtures::boxOf($root)?->focusRect();
        check(
            'Table · 键盘：Tab 进来 ⇒ 焦点落在表上，第一格画出 300×40 的框（内容坐标 0,0）',
            $rect !== null
                && abs($rect->left) < 0.01 && abs($rect->top) < 0.01
                && abs($rect->width - 300.0) < 0.01 && abs($rect->height - 40.0) < 0.01,
            $rect === null ? 'null' : \sprintf('(%.1f,%.1f) %.1f×%.1f', $rect->left, $rect->top, $rect->width, $rect->height),
        );

        // ③ 方向键：走真 FocusManager ⇒ Widget::keyboard() 第一跳命中（焦点就在表上）
        $focus?->dispatchKey(KeyEvent::down(KeyChord::down()));
        $app->frame(17.0, $tight);
        $rect = Fixtures::boxOf($root)?->focusRect();
        check(
            'Table · 键盘：↓ ⇒ 光标行 +1（框顶 = 行高 40）',
            $state?->focusedCell() === ['row' => 1, 'col' => 0]
                && $rect !== null && abs($rect->top - 40.0) < 0.01,
            'cell=' . \json_encode($state?->focusedCell())
                . ' top=' . ($rect === null ? 'null' : \sprintf('%.1f', $rect->top)),
        );

        $focus?->dispatchKey(KeyEvent::down(KeyChord::right()));
        $app->frame(18.0, $tight);
        $rect = Fixtures::boxOf($root)?->focusRect();
        check(
            'Table · 键盘：→ ⇒ 光标列 +1（框左 = 第 1 列宽 300，间距 0）',
            $state?->focusedCell() === ['row' => 1, 'col' => 1]
                && $rect !== null && abs($rect->left - 300.0) < 0.01,
            'cell=' . \json_encode($state?->focusedCell())
                . ' left=' . ($rect === null ? 'null' : \sprintf('%.1f', $rect->left)),
        );

        // ④ 边界：吃掉键但不越界（不吃就漏给上层，越界就是"光标跑到不存在的列"）
        $ate = $focus?->dispatchKey(KeyEvent::down(KeyChord::right())) ?? false;
        $app->frame(19.0, $tight);
        check(
            'Table · 键盘：右边界再按 → ⇒ 吃掉这个键、光标不越界（仍 col 1）',
            $ate && $state?->focusedCell() === ['row' => 1, 'col' => 1],
            'ate=' . ($ate ? 'y' : 'n') . ' cell=' . \json_encode($state?->focusedCell()),
        );

        $this->flushChecks();
    }

    public function testEndScrollsVerticallyIntoView(): void
    {
        $app = self::app();
        $root = $app->root();
        $state = Fixtures::stateOf($root);
        $focus = $app->focus();
        $tight = BoxConstraints::tight(new Size(Fixtures::VIEW_W, Fixtures::VIEW_H));

        $focus?->focusNext();
        $app->frame(16.0, $tight);
        $focus?->dispatchKey(KeyEvent::down(KeyChord::down()));
        $focus?->dispatchKey(KeyEvent::down(KeyChord::right()));
        // ⑤ End ⇒ 末行 + 纵向 ensureVisible（100 行 × 40 ⇒ 行区 208，末端 offsetY = 3792）
        $focus?->dispatchKey(KeyEvent::down(KeyChord::end()));
        $app->frame(20.0, $tight);

        $box = Fixtures::boxOf($root);
        $rectEnd = $box?->focusRect();
        $offsetEnd = $box?->appliedOffset(Axis::Vertical) ?? -1.0;

        check(
            'Table · 键盘：End ⇒ 末行 99，并自动滚到能看见（offsetY = 3792，框底 ≤ 行区底）',
            $state?->focusedCell() === ['row' => 99, 'col' => 1]
                && abs($offsetEnd - 3792.0) < 0.01
                && $rectEnd !== null
                && $rectEnd->top >= $offsetEnd
                && $rectEnd->top + $rectEnd->height <= $offsetEnd + 208.0 + 0.01,
            'cell=' . \json_encode($state?->focusedCell())
                . ' offsetY=' . \sprintf('%.1f', $offsetEnd)
                . ' rectTop=' . ($rectEnd === null ? 'null' : \sprintf('%.1f', $rectEnd->top)),
        );

        $this->flushChecks();
    }

    public function testHorizontalEnsureVisibleAndBlur(): void
    {
        // ⑥ 横向 ensureVisible：→ 到第 2 列（x=300..600）⇒ 右缘 600 > 视口 320 ⇒ 滚到末端 280
        //    ★ 另一个实例：上一条里纵向已在末端，同一条断言里两个轴会互相掩盖
        $app = self::app();
        $tight = BoxConstraints::tight(new Size(Fixtures::VIEW_W, Fixtures::VIEW_H));
        $app->focus()?->focusNext();
        $app->frame(16.0, $tight);
        $app->focus()?->dispatchKey(KeyEvent::down(KeyChord::right()));
        $app->frame(17.0, $tight);

        $box = $app->root()->effectiveBoxOf(RenderTable::class);
        $box = $box instanceof RenderTable ? $box : null;
        $state = Fixtures::stateOf($app->root());
        check(
            'Table · 键盘：→ 到右边列 ⇒ 横向自动滚过去（offsetX = maxScrollX = 280）',
            $box?->appliedOffset(Axis::Horizontal) === 280.0,
            'offsetX=' . \sprintf('%.1f', $box?->appliedOffset(Axis::Horizontal) ?? -1.0),
        );

        // ⑦ 失焦：不画框，但光标格保留（Tab 出去再回来停在原处）
        $state?->didChangeFocus(false);
        $app->frame(18.0, $tight);
        check(
            'Table · 键盘：失焦 ⇒ 不画框，但光标格**保留**（Tab 回来停在原处）',
            Fixtures::boxOf($app->root())?->focusRect() === null
                && $state?->focusedCell() === ['row' => 0, 'col' => 1],
            'rect=' . (Fixtures::boxOf($app->root())?->focusRect() === null ? 'null' : '有')
                . ' cell=' . \json_encode($state?->focusedCell()),
        );

        // ⑦b 焦点在**表内子控件**上时让位：`keyboard()` 沿父链找最近声明者 ⇒
        //     单元格里的输入框按 → 也会先落到表的闭包。那时方向键必须归输入框。
        check(
            'Table · 键盘：焦点在表内子控件上（表本身失焦）⇒ 方向键让位（不吃、光标不动）',
            $state?->handleFocusKey(KeyEvent::down(KeyChord::down())) === false
                && $state->focusedCell() === ['row' => 0, 'col' => 1],
            'cell=' . \json_encode($state?->focusedCell()),
        );

        $this->flushChecks();
    }

    /** ⑧ 像素：框真的画在**格子内部**（2px 内描边；stroke 会向相邻格溢 1px） */
    public function testFocusRectIsTwoPxInsetStroke(): void
    {
        $app = self::app();
        $tight = BoxConstraints::tight(new Size(Fixtures::VIEW_W, Fixtures::VIEW_H));
        $app->focus()?->focusNext();
        $app->frame(16.0, $tight);

        $shot = Fixtures::rasterOf(Fixtures::boxOf($app->root()), 320, 240);
        $accent = ThemeData::light()->color(ColorToken::Accent);
        $surface = ThemeData::light()->color(ColorToken::Surface);

        check(
            'Table · 像素：光标框是 2px 内描边（顶边在 y=32..33、左竖边贴 x=0）',
            $shot->pixelAt(150, 32)->equals($accent)
                && $shot->pixelAt(150, 33)->equals($accent)
                && $shot->pixelAt(0, 50)->equals($accent),
            'top=' . Fixtures::pxOf($shot, 150, 32) . ' left=' . Fixtures::pxOf($shot, 0, 50),
        );
        check(
            'Table · 像素：框只占 2px —— 格内 y=34 已是内容底（不是一条实心色带）',
            !$shot->pixelAt(150, 34)->equals($accent)
                && $shot->pixelAt(150, 60)->equals($surface),
            'y34=' . Fixtures::pxOf($shot, 150, 34) . ' y60=' . Fixtures::pxOf($shot, 150, 60),
        );

        $this->flushChecks();
    }
}
