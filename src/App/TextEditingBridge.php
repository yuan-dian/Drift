<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Geometry\Rect;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Layout\RenderBox;
use Drift\Layout\TextInputBox;
use Drift\Widget\EditableText;
use Drift\Widget\States;

/**
 * **文本编辑三侧的桥**：IME 光标 / 指针框选 / 编辑命令。三侧共用"焦点里在编辑的输入框"这一个判据（`editingText()`）。
 *
 * 片只经 `TextEditingHost` 摸宿主（焦点链 + 剪贴板），不建焦点、不持平台、只读。
 * `$textSelect` 只记 State 不记盒：盒每步现取（`textSelectBox()`），缓存会拿上一帧几何算这一帧偏移。
 *
 * @internal S1 的拆分片，同 `FocusManager` —— 不是应用契约
 */
final class TextEditingBridge
{
    /** 进行中的指针框选手势（`null` = 没有）。只记 State 不记盒（见 `textSelectBox()`）。 */
    private ?EditableText $textSelect = null;

    public function __construct(private TextEditingHost $host)
    {
    }

    /**
     * IME 光标矩形，逻辑像素窗口坐标 `[x, y, h]`；返回 null = 无插入符，喂给 `Platform::imeCaret()`（平台侧 × dpr）。
     * ★ **必须加 padding**：`caretRect()` 是内容盒坐标、`absoluteOrigin()` 给边框盒原点，漏了 ⇒ 候选框偏一个 padding，且它是独立原生窗口、只有真机能抓。
     */
    public function imeCaretRect(): ?array
    {
        $state = $this->editingText();
        if ($state === null) {
            return null;
        }

        $box = $this->host->focus()?->focused()?->effectiveBox();
        if ($box === null) {
            return null;
        }

        $local = $state->caretRect();   // 盒内坐标（含内边距）
        if (!$local instanceof Rect) {
            return null;
        }

        [$originX, $originY] = RenderBox::absoluteOrigin($box);
        $padLeft = $box->contentOriginX();
        $padTop = $box->contentOriginY();

        return [
            $originX + $padLeft + $local->left,
            $originY + $padTop + $local->top,
            $local->height,
        ];
    }

    /**
     * 焦点里**在编辑**的输入框（三侧共用的唯一门控：焦点穿透 + `EditableText` + `isEditing()` 缺一不可）。
     */
    private function editingText(): ?EditableText
    {
        $state = $this->host->focus()?->focusedState();
        if (!$state instanceof EditableText || !$state->isEditing()) {
            return null;
        }

        return $state;
    }

    /**
     * 输入框的**真实字段盒**（穿过包装层现取，不缓存）。
     * 有 `label()` 的字段 build 成 `Column[Text, 字段]`，焦点元素的 `effectiveBox()` 是 Column 的盒；拿它算 x 偏移会整体错一个标签宽。
     * ★ 按 **`TextInputBox` 接口**找，不按具体类：写死 `RenderTextField::class` ⇒ 多行框（`RenderTextArea`）拖选静默失效（点不动、不报错）。
     */
    private function textSelectBox(EditableText $state): (RenderBox&TextInputBox)|null
    {
        $focused = $this->host->focus()?->focused();
        if (States::of($focused) !== $state) {
            return null;
        }

        $box = $focused->findBox(static fn(RenderBox $b): bool => $b instanceof TextInputBox);

        /** @var (RenderBox&TextInputBox)|null $box */
        return $box;
    }

    /**
     * down 落在输入框上（`focusHit()` 已成功 ⇒ 焦点就是它）⇒ 按点击次数 / 是否按住 Shift 落选区，并记下框选手势（move 延伸、up/leave 收口）。
     * 四种落点：点一下折叠选区到指针；Shift+点活动端到指针（锚点不动）；双击选词（中文按连续汉字）；三击及以上选段。
     * ★ `offsetAtPoint()` 收内容盒坐标：先扣边框盒原点再扣 padding，漏了插入符落点偏右一个 padding。
     */
    public function beginTextSelection(float $x, float $y, int $clickCount = 1, bool $extend = false): void
    {
        $state = $this->editingText();
        if ($state === null) {
            return;
        }

        $box = $this->textSelectBox($state);
        if ($box === null) {
            return;
        }

        [$originX, $originY] = RenderBox::absoluteOrigin($box);
        $padLeft = $box->contentOriginX();
        $offset = $box->offsetAtPoint($x - $originX - $padLeft, $y - $originY);

        if ($clickCount >= 3) {
            $state->selectParagraphAt($offset);
        } elseif ($clickCount === 2) {
            $state->selectWordAt($offset);
        } elseif ($extend) {
            $state->extendSelectionTo($offset);
        } else {
            $state->moveCaret($offset);
        }

        $this->textSelect = $state;
    }

    /**
     * 框选推进（`move`）：选区活动端延伸到指针处；取不到盒或无进行中手势时直接收口/返回。
     */
    public function extendTextSelection(float $x, float $y): void
    {
        $state = $this->textSelect;
        if ($state === null) {
            return;
        }

        $box = $this->textSelectBox($state);
        if ($box === null) {
            $this->textSelect = null;

            return;
        }

        [$originX, $originY] = RenderBox::absoluteOrigin($box);
        $padLeft = $box->contentOriginX();
        $state->extendSelectionTo($box->offsetAtPoint($x - $originX - $padLeft, $y - $originY));
    }

    /**
     * 收口框选手势（`up` / `leave` / ESC / 窗口失活）。只清手势、不清选区：选区住在字段里，up 之后 Ctrl+C 照样复制它。
     */
    public function finishSelection(): void
    {
        $this->textSelect = null;
    }

    /**
     * 编辑命令环：`Ctrl+C` 复制 / `Ctrl+X` 剪切 / `Ctrl+V` 粘贴。位置在最前面（第 ⓪ 环），因它要平台剪贴板而 `State` 拿不到。
     * 返回 `false` = 这一环没吃，事件原样继续往焦点链走；四条门控任一不满足都放行（不吞键）：
     *   ① 不是 C/X/V ② 焦点里没有在编辑的输入框 ③ 平台没递剪贴板（`clipboard()` 为 null） ④ C/X 没有选区、V 没有文本。
     * 没有剪贴板时 `Ctrl+C` 不是死键，回落给加速键，应用可把它绑成"复制选中行"等命令。
     */
    public function dispatchEditCommand(KeyEvent $event): bool
    {
        $chord = $event->chord;

        $copy = $chord->is(KeyChord::ctrl('C'));
        $cut = $chord->is(KeyChord::ctrl('X'));
        $paste = $chord->is(KeyChord::ctrl('V'));

        if (!$copy && !$cut && !$paste) {
            return false;
        }

        $state = $this->editingText();
        if ($state === null) {
            return false;
        }

        $clipboard = $this->host->clipboard();
        if ($clipboard === null) {
            return false;
        }

        if ($paste) {
            if (!$clipboard->hasText()) {
                return false;
            }

            $state->replaceSelection($clipboard->readText());

            return true;
        }

        if (!$state->hasSelection()) {
            return false;
        }

        $clipboard->writeText($state->selectedText());

        if ($cut) {
            $state->deleteSelection();
        }

        return true;
    }
}
