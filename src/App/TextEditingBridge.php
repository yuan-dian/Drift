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
 * **文本编辑三侧的桥**：IME 光标 / 指针框选 / 编辑命令（S1 第 4 步拆出的
 * 最小片，验证「Application 持片、片经窄接口回调」的装配模式）。
 *
 * ```text
 * IME    组合根 ──imeCaretRect()──▶ 焦点里在编辑的输入框 ──▶ 平台 imeCaret()
 * 指针   down ──▶ beginTextSelection() ──move──▶ extendTextSelection() ──up/leave──▶ finishSelection()
 * 键盘   Ctrl+C/X/V ──▶ dispatchEditCommand()（第 ⓪ 环，在焦点链与加速键之前）
 * ```
 *
 * ★ **为什么从 Application 拆出来**：这三侧在 Application 3900 行里散成
 *   6 个方法 + 1 个字段，彼此只共享"焦点里在编辑的输入框"这一个判据
 *   （`editingText()`）。收成一片后，Application 只剩"持片 + 转交"，
 *   文本认知的演进（IME 合成、选区矩形、双击选词）都落在这一个文件里。
 *
 * ★ **它只经 `TextEditingHost` 摸宿主**（焦点链 + 剪贴板）：不碰帧循环、
 *   不碰浮层、不碰根 Element。焦点是渲染态且全局只能有一份（§9.1）⇒
 *   片不建焦点，只读；剪贴板是平台能力（§5.1）⇒ 片不持平台，只借。
 *
 * ★ **`$textSelect` 只记 State 不记盒**：编辑只标脏 ⇒ 帧末 flush 重建盒
 *   ⇒ 缓存盒等于拿上一帧的几何算这一帧的偏移。盒每步现取
 *   （`textSelectBox()`）。
 *
 * @internal S1 的拆分片，同 `FocusManager` —— 不是应用契约
 */
final class TextEditingBridge
{
    /** 进行中的指针框选手势（`null` = 没有；见类 docblock 的"只记 State"） */
    private ?EditableText $textSelect = null;

    public function __construct(private TextEditingHost $host)
    {
    }

    /**
     * IME 定位用的光标矩形（§8.4）—— **逻辑像素**的窗口坐标 `[x, y, h]`。
     *
     * ★ 返回 null = 无插入符（无焦点 / 焦点不在编辑态 / 光标矩形算不出来）。
     *   组合根把它喂给 `Platform::imeCaret()`；平台侧负责 × dpr（§6.1：
     *   **量化只发生在平台侧**）。
     *
     * ★ **必须加 padding**（arch-v2 `02` §4.1.1）：`caretRect()` 是**内容盒**
     *   坐标（插入符自己走 `paintContent()` 那条路，画得是对的），而
     *   `absoluteOrigin()` 给的是**边框盒**原点。漏了 ⇒ IME 候选框偏一个
     *   padding；而候选框是**独立原生窗口**，不在应用截图里 ⇒ 四类"只有
     *   真机能抓"的失败模式一条也抓不到它。
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
     * 焦点里**在编辑**的输入框（三侧共用的门控，判据只此一份）。
     *
     * ★ 焦点穿透（`focusedState()`）+ `EditableText` + `isEditing()`。
     *   IME 光标、框选起手、编辑命令全都走这里 ⇒ 不可能一个放行、另一个拦住。
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
     * 输入框的**真实字段盒**（穿过包装层现取，不缓存 —— 见 `$textSelect`）。
     *
     * ★ 有 `label()` 的字段 build() 出来是 `Column[Text, 字段]` ⇒ 焦点元素的
     *   `effectiveBox()` 是 Column 的盒；拿它算 x 偏移会整体错一个标签宽
     *   （2026-10-19 IME 候选框贴角的同一个坑）。
     *
     * ★★ 按 **`TextInputBox` 接口**找，不按具体类（2026-11-03）：原先写死
     *   `RenderTextField::class` ⇒ 多行框（`RenderTextArea`）**拖选静默失效**
     *   —— 点下去不动、拖不动、不报错。这正是 `EditableText` 接口存在的同一条理由：
     *   框架核心不认识具体控件。
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
     * down 落在输入框上（`focusHit()` 已成功 ⇒ 焦点就是它）⇒ 插入符落到
     * 指针处 + 记下框选手势（`move` 延伸、`up` / `leave` 收口）。
     *
     * ★ `moveCaret()` 折叠既有选区 —— "点一下 = 重新定位"（与各平台一致），
     *   选区靠随后的拖动重建，不继承上一轮。
     * ★ 收**盒内**坐标（盒自己再减内边距与滚动偏移）⇒ 先扣掉盒的绝对原点。
     * ★ `$y` 是必须的：多行框要知道落在哪一行（单行盒忽略它）。
     */
    public function beginTextSelection(float $x, float $y): void
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
        // ★ `offsetAtPoint()` 要的是**内容盒**坐标（它自己再减一次 `insets`）⇒
        //   先扣边框盒原点，再扣 padding（`02` §4.1.1）。漏了 ⇒ 插入符落点偏右
        //   一个 padding，点到的是后面几个字。
        $state->moveCaret($box->offsetAtPoint($x - $originX - $padLeft, $y - $originY));

        $this->textSelect = $state;
    }

    /**
     * 框选推进（`move`）：选区活动端延伸到指针处。
     *
     * ★ 取不到盒（控件卸载 / 焦点被挪走）⇒ 就地收口，不拖着坏手势。
     * ★ 无进行中的手势（`down` 不在输入框上）⇒ 直接返回（调用方不需要
     *   先问"有没有手势"）。
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
        // ★ 同 `beginTextSelection()`：`offsetAtPoint()` 收内容盒坐标。
        $state->extendSelectionTo($box->offsetAtPoint($x - $originX - $padLeft, $y - $originY));
    }

    /**
     * 收口框选手势（`up` / `leave` / ESC / 窗口失活）。
     *
     * ★ 只清手势，**不清选区** —— 选区住在字段里（`selectedText()`），
     *   up 之后 Ctrl+C 照样复制它（与各平台一致）。
     */
    public function finishSelection(): void
    {
        $this->textSelect = null;
    }

    /**
     * 编辑命令环（§9.1）：`Ctrl+C` 复制 / `Ctrl+X` 剪切 / `Ctrl+V` 粘贴。
     *
     * 位置在**最前面**（第 ⓪ 环，比焦点链和加速键都早）—— 不是特权，是因为它
     * 要平台剪贴板，而 `State` 拿不到平台（§5.1）⇒ 只能住 `App` 层。
     * 焦点里有个在编辑的输入框时，这三个组合归它。返回 `false` = 这一环没吃，
     * 事件**原样**继续往焦点链走 —— 四条门控任一不满足都放行（不吞键）：
     *
     *   ① 不是 C / X / V
     *   ② 焦点里没有在编辑的输入框（§5.1：`State` 拿不到平台 ⇒ 环住 `App`）
     *   ③ 平台没递剪贴板进来（`clipboard()` 返回 null）
     *   ④ C / X 没有选区；V 剪贴板没有文本
     *
     * ★ ④ 的设计：**没有剪贴板时 `Ctrl+C` 不是死键** —— 回落给加速键，应用
     *   照样能把它绑成"复制选中行"之类的命令（"声明独占却没人接"让键失效，
     *   正是这条规则想避免的）。
     * ★ 2026-11-03 起这里**不需要任何声明**：焦点链已排在加速键之前，"谁处理
     *   谁赢"；本环留在 ⓪ 的唯一理由是它要平台的剪贴板对象（见上）。
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
