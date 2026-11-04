<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\Axis;
use Drift\Geometry\Rect;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderTextArea;
use Drift\Text\Paragraph;

/**
 * `TextArea` 的纯 UI 态（多行特有）。
 * 「焦点 / 通知 / 重画 / 编辑键」在 `EditableTextState`（与 `TextFieldState` 共用），
 * 本类只加**多行特有**的四件事：
 * ① `Enter` 换行、`Ctrl+Enter` 提交（单行里 `Enter` 无本职）；
 * ② 上下移行**按目标列**（单行只有一行，无"列"）；
 * ③ `Home` / `End` = 行首行尾（单行里即文本首尾）；
 * ④ 垂直滚动 + 插入符跟随（单行高度恒为一行，无滚动）。
 * ★ **目标列（goal column）**：连续上下移动时列要**保持**，不是从当前行尾重新出发。
 *   没有它：光标在长行右端按 ↓ 落短行行尾，再按 ↓ 停短列上，用户感觉"光标斜着跑"。
 *   任何其他编辑动作都把它清掉。
 * @api
 */
final class TextAreaState extends EditableTextState implements Scrollable
{
    /** 上下移行时保持的列（逻辑像素）；`null` = 本次上下移是序列的第一步 */
    private ?float $goalX = null;

    private ?ScrollLane $lane = null;

    /**
     * 上次对准过的**编辑位置**指纹：`[插入符, 选区起, 选区终]`。
     * ★ `null` = 还没对准过（首帧 / 刚退出编辑）。它是 `caretRevealOffset()` 的门控，
     *   也是"用户滚动"与"插入符跟随"能共存的开关。
     */
    private ?array $revealedAt = null;

    #[\Override]
    protected function controlledValue(): string
    {
        return $this->area()->valueText();
    }

    #[\Override]
    protected function adoptValue(Widget $widget): void
    {
        if (!$widget instanceof TextArea) {
            return;
        }

        $this->draft()->setText($widget->valueText());
    }

    #[\Override]
    protected function notifyChanged(string $text): void
    {
        $handler = $this->area()->changedHandler();
        if ($handler !== null) {
            $handler($text);
        }
    }

    // ── 行级按键 ────────────────────────────────────────────────────────

    #[\Override]
    protected function interceptKey(KeyEvent $event): bool
    {
        $chord = $event->chord;

        // 上下移是**唯一**保留目标列的键；其余任何键都把它清掉
        $vertical = $chord->key === 'Up' || $chord->key === 'Down';
        if (!$vertical) {
            $this->goalX = null;
        }

        if ($chord->key === 'Enter') {
            // ★ `Ctrl+Enter` = 提交，其余（含 `Shift+Enter`）= 换行。
            //   没接 `onSubmitted()` ⇒ **不吞** `Ctrl+Enter`（交给加速键），
            //   否则应用绑的"提交"命令会被没有消费者的控件挡掉。
            if ($chord->ctrl) {
                $handler = $this->area()->submittedHandler();
                if ($handler === null) {
                    return false;
                }
                $handler($this->text());

                return true;
            }

            if ($chord->alt) {
                return false;   // `Alt+Enter` 归应用
            }

            $this->settle($this->draft()->insert("\n"));

            return true;
        }

        if ($vertical) {
            $this->moveVertical($chord->key === 'Down' ? 1 : -1, $chord->shift);

            return true;
        }

        if ($chord->key === 'Home' || $chord->key === 'End') {
            $this->moveToLineEdge($chord->key === 'End', $chord->shift);

            return true;
        }

        return false;
    }

    /**
     * 上下移一行，**落到同一列**。
     * 到顶 / 到底 ⇒ 去文本首 / 文本末（与主流编辑器一致）。
     * 段落取自**上一次布局**的盒：上下移不改文本 ⇒ 那份几何仍正确；首帧前无盒则不动作。
     */
    private function moveVertical(int $delta, bool $extend): void
    {
        $paragraph = $this->paragraph();
        if ($paragraph === null) {
            return;
        }

        $draft = $this->draft();
        $offset = $draft->caretOffset();
        $line = $paragraph->lineIndexAt($offset);
        $target = $line + $delta;

        if ($target < 0) {
            $this->settle($extend ? $draft->extendSelectionTo(0) : $draft->moveCaret(0));

            return;
        }
        if ($target >= $paragraph->lineCount()) {
            $end = strlen($draft->text());
            $this->settle($extend ? $draft->extendSelectionTo($end) : $draft->moveCaret($end));

            return;
        }

        // 目标列在**第一次**上下移时定下来，序列内不再变 —— 这就是 goal column
        $x = $this->goalX ?? $paragraph->caretX($offset);
        $this->goalX = $x;

        $rect = $paragraph->lineRect($target);
        $hit = $paragraph->indexAt($x, $rect->top + $rect->height * 0.5);

        $this->settle($extend ? $draft->extendSelectionTo($hit) : $draft->moveCaret($hit));
    }

    /**
     * `Home` / `End`：**行首 / 行尾**；已在边界 ⇒ 跳到文本首 / 末。
     * 再按一次去全文两端：用户在多行框按 `Home` 常是"回到开头"的意图，只落行首会
     * 让人以为键坏了。
     */
    private function moveToLineEdge(bool $toEnd, bool $extend): void
    {
        $paragraph = $this->paragraph();
        if ($paragraph === null) {
            return;
        }

        $draft = $this->draft();
        $offset = $draft->caretOffset();
        $line = $paragraph->lines[$paragraph->lineIndexAt($offset)];
        $lineStart = $line->textStart;
        $lineEnd = $line->textStart + $line->textLength;

        if ($toEnd) {
            $target = $offset === $lineEnd ? strlen($draft->text()) : $lineEnd;
        } else {
            $target = $offset === $lineStart ? 0 : $lineStart;
        }

        $this->settle($extend ? $draft->extendSelectionTo($target) : $draft->moveCaret($target));
    }

    // ── 滚动（`Scrollable`）──────────────────────────────────────────────

    /**
     * 滚动 Δ（正 = 内容上移）。夹取与"回写盒夹过的值"在 `ScrollLane`（与
     * `ScrollView` / `LazyList` / `Table` 共用一份）。
     */
    public function scrollBy(float $delta): void
    {
        /** @var RenderTextArea|null $box */
        $box = $this->box();

        $this->lane()->move(
            $delta,
            static fn(float $value): float => $box === null
                ? $value
                : $box->setScrollOffset(Axis::Vertical, $value),
        );
    }

    /** 单轴容器：交叉轴什么都不做（`Scrollable` 的接口纪律） */
    public function scrollByAxis(Axis $axis, float $delta): void
    {
        if ($axis !== Axis::Vertical) {
            return;
        }

        $this->scrollBy($delta);
    }

    /** 滚轮专用：摊成若干帧（`ScrollLane::glide()`） */
    public function glideByAxis(Axis $axis, float $delta): void
    {
        if ($axis !== Axis::Vertical) {
            return;
        }

        $this->lane()->glide($delta);
    }

    /** 把**内容坐标**里的矩形滚进视口（`FocusManager::revealFocused()` 用） */
    public function ensureVisible(Rect $target, float $margin = 8.0): void
    {
        $box = $this->box();
        if ($box === null || !$box->hasSize()) {
            return;
        }

        $viewport = $box->viewportHeight();
        $offset = $box->appliedOffset(Axis::Vertical);
        // `$target` 是**内容坐标**（与滚动偏移无关）⇒ 直接和偏移比，不掺内边距
        if ($target->top - $margin < $offset) {
            $this->scrollBy($target->top - $margin - $offset);
        } elseif ($target->top + $target->height + $margin > $offset + $viewport) {
            $this->scrollBy($target->top + $target->height + $margin - $viewport - $offset);
        }
    }

    /**
     * 推进这一帧的**滚动动画**与**滚动条淡出**。
     * 顺序：先滚动、后淡出（`scrollBy()` 里 `noteScroll()` 把条打回全亮，反过来会抖动）。
     * 插入符跟随不在这里：本方法跑在 settle **之前**，盒还是上一帧的，故在盒的
     * `performLayout()` 里做。
     */
    #[\Override]
    public function tickFrame(float $timestampMs): bool
    {
        $lane = $this->lane();

        $busy = $lane->tick($timestampMs, function (float $delta): void {
            $this->scrollBy($delta);
        });

        if ($lane->fade()->tickFor($this, $timestampMs)) {
            $busy = true;
        }

        return $busy;
    }

    // ── 渲染 ────────────────────────────────────────────────────────────

    /**
     * 插入符矩形（**盒内坐标，已扣滚动偏移**）—— IME 候选窗的几何来源。
     * 与 `TextFieldState::caretRect()` 同契约：只给几何，不假装知道屏幕位置
     * （沿父链累积平移属平台层）。
     * 未挂盒 / 未布局 ⇒ `null`：壳收到"无插入符"会把候选框贴左上角，编造 `(0,0)` 更坏。
     */
    #[\Override]
    public function caretRect(): ?Rect
    {
        $box = $this->box();
        if ($box === null || !$box->hasSize()) {
            return null;
        }

        return $box->caretRect();
    }

    public function build(BuildContext $ctx): Widget
    {
        $area = $this->area();

        $box = TextAreaBox::make(
            $this->text(),
            $area->placeholderText(),
            $this->isEditing() ? $this->caretOffset() : null,
            $this->selectionStart(),
            $this->selectionEnd(),
            $area->minLinesValue(),
            $area->linesValue(),
            $this->lane()->offset(),
            $this->lane()->fade()->opacity(),
            $this->caretRevealOffset(),
        );

        $label = $area->labelText();
        if ($label === null) {
            return $box;
        }

        return Column::make()->spacing(4.0)->children([
            Text::make($label)->caption(),
            $box,
        ]);
    }

    /**
     * 这一帧要不要把插入符滚进视口；要的话，跟到哪个偏移。
     * 返回 `null` = 不跟随（用户在滚 / 不在编辑态）。
     * 指纹（插入符 + 选区两端）是"用户滚动"与"插入符跟随"能共存的开关：
     * 没有它，任何一次 rebuild 都会把视口拽回插入符，用户"滚一半弹回去"。
     * 选区两端也进指纹：`Ctrl+A` / Shift+方向键可能只动选区不动插入符，
     * 活动端跑出视口同样要跟随。未编辑 ⇒ 指纹清空（下次进编辑必然重新对准）。
     * 这里只改字段、**不标脏**（build 里标脏会被 E-FLUSH-CYCLE 拦）。
     */
    private function caretRevealOffset(): ?int
    {
        if (!$this->isEditing()) {
            $this->revealedAt = null;

            return null;
        }

        /** @var array{0: int, 1: int, 2: int} $now 插入符 / 选区起 / 选区终 */
        $now = [$this->caretOffset(), $this->selectionStart(), $this->selectionEnd()];
        if ($now === $this->revealedAt) {
            return null;   // 编辑位置没动 ⇒ 这一帧的 rebuild 与"跟随"无关（是用户在滚）
        }

        $this->revealedAt = $now;

        return $now[0];
    }

    // ── 内部 ────────────────────────────────────────────────────────────

    private function lane(): ScrollLane
    {
        $lane = $this->lane;
        if ($lane === null) {
            $lane = new ScrollLane(0.0, null, $this);
            $this->lane = $lane;
        }

        return $lane;
    }

    private function paragraph(): ?Paragraph
    {
        return $this->box()?->textParagraph();
    }

    /** 渲染盒（穿过 label 包装层找） */
    private function box(): ?RenderTextArea
    {
        $box = $this->element()?->findBox(
            static fn(RenderBox $candidate): bool => $candidate instanceof RenderTextArea,
        );

        /** @var RenderTextArea|null $box */
        return $box;
    }

    private function area(): TextArea
    {
        $widget = $this->widget();
        if (!$widget instanceof TextArea) {
            // 框架不变量：StatefulElement 按 get_class 配对，配错不可能发生
            Errors::report('TextAreaState 只能配 TextArea（框架内部不变量被破坏）');
        }

        /** @var TextArea $widget */
        return $widget;
    }
}
