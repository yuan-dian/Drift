<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Rendering\Color;
use Drift\Rendering\ColorToken;
use Drift\Rendering\DisplayList;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RecordingCanvas;
use Drift\Layout\RenderTextArea;
use Drift\Widget\StatefulElement;
use Drift\Widget\TextArea;
use Drift\Widget\TextAreaState;

/**
 * 多行输入框的**滚动后坐标契约**：几何、命中、绘制三处必须同一套视口坐标。
 *
 * ★ 回归形态：滚动后光标画在文字上方（比文字多滚一段），视觉上点不到第 6/7 行。
 *   根因是 `caretRect()` 已扣滚动偏移，`paintContent()` 又 `translate(-applied())`。
 *   判据直接量**画出来的像素**，不读 `paintContent()` 的实现细节。
 */
final class TextAreaCaretPaintTest extends SmokeTestCase
{
    private const WIDTH = 240.0;

    private const HEIGHT = 120.0;

    /** @return array{0: ?TextAreaState, 1: ?RenderTextArea} */
    private function mount(string $value): array
    {
        $root = TextArea::make()->value($value)->minLines(1)->createElement();
        $root->mount(null, smokeContext());
        $state = $root instanceof StatefulElement ? $root->state() : null;
        $root->settle();
        $root->effectiveBox()?->layout(BoxConstraints::tight(new Size(self::WIDTH, self::HEIGHT)));

        return [
            $state instanceof TextAreaState ? $state : null,
            $state instanceof TextAreaState
                ? $state->element()?->findBox(static fn($c): bool => $c instanceof RenderTextArea)
                : null,
        ];
    }

    /**
     * 滚动后插入符画在**它自己的文字行上**（不是被平移两次）。
     *
     * ★ 判据形状：先算出一个偏移，使第 10 行的插入符落在视口 y≈60，而错误的
     *   "二次扣减"位置落在盒外（负 y）。再数插入符位置的整列像素：修好后是一整条，
     *   旧实现那里没有插入符 ⇒ 判据咬得住，不是恒真。
     */
    public function testCaretPixelsLandOnTheTextLineAfterScroll(): void
    {
        $lines = array_fill(0, 30, 'abcdefghij');
        [$state, $box] = $this->mount(implode("\n", $lines));
        $state?->beginEditing();
        $state?->moveCaret(10 * 11 + 10);   // 第 10 行行尾（列末不与字形重叠）
        $state?->element()?->settle();
        $state?->element()?->effectiveBox()?->layout(BoxConstraints::tight(new Size(self::WIDTH, self::HEIGHT)));
        $box = $state?->element()?->findBox(static fn($c): bool => $c instanceof RenderTextArea);

        $paragraph = $box?->textParagraph();
        check('TextArea 光标 · 前提：盒已排版出 30 行', $paragraph !== null && $paragraph->lineCount() === 30);
        if ($box === null || $paragraph === null || $state === null || $state->caretOffset() !== 10 * 11 + 10) {
            $this->flushChecks();

            return;
        }

        $lineTop = $paragraph->lineRect(10)->top;
        $target = $box->contentTop() + $lineTop - 60.0;
        $applied = $box->setScrollOffset(Axis::Vertical, $target);
        $caret = $box->caretRect();
        $wrongCenter = \round($caret->top + $caret->height * 0.5 - $applied);
        $size = $box->size();

        check(
            'TextArea 光标 · 前提：滚动已生效且二次扣减位置落在盒外（否则判据没牙）',
            $applied > 0.0 && \abs($caret->top - 60.0) < 1.0 && $wrongCenter < 0.0,
            'applied=' . $applied . ' caretTop=' . $caret->top . ' wrongCenter=' . $wrongCenter,
        );
        check('TextArea 光标 · 前提：盒尺寸可读', $size !== null);
        if ($size === null) {
            $this->flushChecks();

            return;
        }

        $list = new DisplayList();
        $box->paint(new RecordingCanvas($list));
        $canvas = new RasterCanvas((int) $size->width, (int) $size->height, Color::white(), Scale::of(1.0));
        $list->replay($canvas);

        $ink = smokeContext()->color(ColorToken::Text);
        $x = (int) \round($caret->left);
        $y0 = (int) \round($caret->top);
        $y1 = (int) \round($caret->top + $caret->height);
        $hits = 0;
        for ($y = $y0; $y < $y1; $y++) {
            if ($y >= 0 && $y < $canvas->height() && $canvas->pixelAt($x, $y)->equals($ink)) {
                $hits++;
            }
        }

        check(
            'TextArea 光标 · 滚动后插入符画在文字所在行（不是被 translate 再扣一次偏移）',
            $hits >= 8,
            "hits={$hits} x={$x} y={$y0}..{$y1} applied={$applied}",
        );

        $this->flushChecks();
    }

    /** 滚动后按行中点命中，落在第 5 / 第 6 行（用户报告"定位不到"的那两行） */
    public function testOffsetAtHitsVisibleLinesAfterScroll(): void
    {
        [, $box] = $this->mount(implode("\n", array_fill(0, 30, 'abcdefghij')));
        if ($box === null || $box->textParagraph() === null) {
            $this->flushChecks();

            return;
        }

        $applied = $box->setScrollOffset(Axis::Vertical, 60.0);
        $paragraph = $box->textParagraph();
        $lineHeight = $paragraph->lineRect(0)->height;
        $x = $box->contentLeft() + 1.0;

        foreach ([5, 6] as $line) {
            $y = $box->contentTop() + $paragraph->lineRect($line)->top - $applied + $lineHeight * 0.5;
            $offset = $box->offsetAt($x, $y);
            check(
                "TextArea 命中 · 滚过 {$applied}px 后点第 {$line} 行仍落在这一行",
                $paragraph->lineIndexAt($offset) === $line,
                'offset=' . $offset . ' hitLine=' . $paragraph->lineIndexAt($offset) . ' y=' . $y,
            );
        }

        $this->flushChecks();
    }
}
