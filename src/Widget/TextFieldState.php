<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\Rect;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderTextField;
use Drift\Text\Utf8;

/**
 * `TextField` 的纯 UI 态（§11.5 / §12.3）。
 *
 * ★ **为什么编辑缓冲在 `State` 而不是 Store**：§11.5 的判据是"必须跨 build 活、
 *   且只影响这一棵子树"—— 编辑中的草稿与插入符位置正是这一类（§12.3 的
 *   "选中 / 焦点 / **编辑中** / 滚动"）。落库是 `onChanged` 之后调用方的事。
 *
 * ★ **插入符偏移是字节偏移，且永远落在字符边界上**。`Utf8::decode()` 给出每个
 *   码位的 (字节偏移, 字节长度)，所有移动 / 删除都按它走 —— 于是不会把多字节
 *   字符切成半个（切了之后喂给排版器会得到非法 UTF-8）。
 *
 * ★ **编辑态与焦点**：`beginEditing()` / `endEditing()` 是**渲染态开关**（§9.1：
 *   "焦点是渲染态，不放业务值"），它决定插入符画不画。`FocusManager`（`Drift\App`）
 *   在焦点落到本字段时调 `beginEditing()`、走开时调 `endEditing()`；按键经
 *   `handleKey()` 翻译成编辑动作 —— 三者合起来就是"输入层最小集"的 widget 侧接口。
 *
 * @api
 */
final class TextFieldState extends State
{
    /** @internal 当前文本（受控：外部推新值经 `didUpdateWidget()` 采纳） */
    private string $buffer = '';

    /** @internal 插入符字节偏移；恒为字符边界 */
    private int $caret = 0;

    /** @internal 渲染态：在编辑中 ⇒ 画插入符 */
    private bool $editing = false;

    public function initState(): void
    {
        // 初值来自 widget 的 value()（§A.2.9 的受控形态：值由外部给）
        $this->buffer = $this->field()->valueText();
        $this->caret = strlen($this->buffer);
    }

    /**
     * 外部推了新值 ⇒ 采纳它，并把插入符移到末尾。
     *
     * ★ **同值不动**：否则调用方每帧重传同一个值都会把插入符打回末尾，
     *   用户正在中间插入时就"跟手输入不丢"这条承诺直接失效。
     */
    public function didUpdateWidget(Widget $widget): void
    {
        parent::didUpdateWidget($widget);

        if (!$widget instanceof TextField) {
            return;
        }

        $incoming = $widget->valueText();
        if ($incoming === $this->buffer) {
            return;
        }

        $this->buffer = $incoming;
        $this->caret = strlen($incoming);
    }

    /** 当前文本（**已包含未提交的编辑**，与 `TextField::valueText()` 可能不同） */
    public function text(): string
    {
        return $this->buffer;
    }

    public function caretOffset(): int
    {
        return $this->caret;
    }

    public function isEditing(): bool
    {
        return $this->editing;
    }

    /** 进入编辑态 ⇒ 插入符出现（M3 由 `FocusNode` 驱动） */
    public function beginEditing(): void
    {
        if ($this->editing) {
            return;
        }
        $this->editing = true;
        $this->markDirty();
    }

    /** 退出编辑态 ⇒ 插入符消失；文本保留（不回滚 —— 回滚是调用方的事） */
    public function endEditing(): void
    {
        if (!$this->editing) {
            return;
        }
        $this->editing = false;
        $this->markDirty();
    }

    /**
     * 在插入符处插入文本（IME 提交 / 直接键入的落点，§8.4）。
     *
     * 插入符移到插入内容的末尾。
     */
    public function insert(string $text): void
    {
        if ($text === '') {
            return;
        }

        $this->apply(
            substr($this->buffer, 0, $this->caret) . $text . substr($this->buffer, $this->caret),
            $this->caret + strlen($text),
        );
    }

    /** 退格：删掉插入符**前一个字符**（按码位，不是按字节） */
    public function deleteBackward(): void
    {
        $bounds = $this->boundaries();
        $previous = 0;
        foreach ($bounds as $bound) {
            if ($bound >= $this->caret) {
                break;
            }
            $previous = $bound;
        }

        if ($previous === $this->caret) {
            return; // 已在最前 ⇒ 无字符可删
        }

        $this->apply(
            substr($this->buffer, 0, $previous) . substr($this->buffer, $this->caret),
            $previous,
        );
    }

    /** Delete：删掉插入符**后一个字符** */
    public function deleteForward(): void
    {
        if ($this->caret >= strlen($this->buffer)) {
            return; // 已在最后 ⇒ 无字符可删
        }

        $next = strlen($this->buffer);
        foreach ($this->boundaries() as $bound) {
            if ($bound > $this->caret) {
                $next = $bound;
                break;
            }
        }

        $this->apply(
            substr($this->buffer, 0, $this->caret) . substr($this->buffer, $next),
            $this->caret,
        );
    }

    /**
     * 移动插入符（**按字节偏移**，会吸附到不大于它的最近字符边界）。
     *
     * 落点即字符内部 ⇒ 吸附而不是报错：输入层给的是"点到了哪个像素"，
     * 换算成字节偏移时天然可能落在字符中间。
     */
    public function moveCaret(int $offset): void
    {
        if ($offset < 0) {
            $offset = 0;
        }
        if ($offset > strlen($this->buffer)) {
            $offset = strlen($this->buffer);
        }

        $snapped = 0;
        foreach ($this->boundaries() as $bound) {
            if ($bound > $offset) {
                break;
            }
            $snapped = $bound;
        }

        if ($snapped === $this->caret) {
            return;
        }

        $this->caret = $snapped;
        $this->markDirty();
    }

    public function moveCaretToEnd(): void
    {
        $this->moveCaret(strlen($this->buffer));
    }

    /**
     * 处理一次按键（§9.1 路由链第 ③ 环落到这里）。
     *
     * ★ **映射规则**（把 `KeyEvent` 翻译成编辑动作）：
     *   · `Backspace` / `Delete` ⇒ 按**码位**删前 / 删后
     *   · `Left` / `Right` ⇒ 移到前 / 后一个**码位边界**
     *     （多字节字符一步跨过去，不会停在半个字符里）
     *   · `Home` / `End` ⇒ 行首 / 行尾
     *   · 带文本的按键 ⇒ 在插入符处插入
     *   · 其余（含加速键）⇒ **返回 false**，让上层继续找别的消费者
     *
     * ★ 返回 `bool` 而不是 `void`：§9.1 的路由链要能问"这一环吃掉了没有"。
     *   返回 `false` 时**不改任何状态**（不偷偷吞键）。
     */
    public function handleKey(KeyEvent $event): bool
    {
        $chord = $event->chord;

        if ($chord->is(KeyChord::backspace())) {
            $this->deleteBackward();

            return true;
        }

        if ($chord->is(KeyChord::delete())) {
            $this->deleteForward();

            return true;
        }

        if ($chord->is(KeyChord::left())) {
            // ★ 不能写成 moveCaret($this->caret - 1) 的镜像：moveCaret 吸附到
            //   "不大于偏移的最近边界" ⇒ 向后要自己找**下一个**边界，
            //   否则 caret 在边界上时 +1 会吸附回原地（按了没反应）。
            $previous = 0;
            foreach ($this->boundaries() as $bound) {
                if ($bound >= $this->caret) {
                    break;
                }
                $previous = $bound;
            }
            $this->moveCaret($previous);

            return true;
        }

        if ($chord->is(KeyChord::right())) {
            $next = strlen($this->buffer);
            foreach ($this->boundaries() as $bound) {
                if ($bound > $this->caret) {
                    $next = $bound;
                    break;
                }
            }
            $this->moveCaret($next);

            return true;
        }

        if ($chord->is(KeyChord::home())) {
            $this->moveCaret(0);

            return true;
        }

        if ($chord->is(KeyChord::end())) {
            $this->moveCaretToEnd();

            return true;
        }

        if ($event->isText()) {
            $this->insert($event->text);

            return true;
        }

        return false;
    }

    /**
     * 插入符矩形（**盒内坐标**）。
     *
     * ★ 这是 IME 定位候选窗的几何来源（§8.4：`caretRect()` 不是可选项，是 IME
     *   能用的前提）。窗口坐标要沿父链累积平移，属平台层 —— 这里只给几何，
     *   不假装知道自己在屏幕上的位置。
     *
     * ★ **它读的是"最近一次 build 出来的盒"**：编辑方法只标脏，重建发生在帧末
     *   （§5.2 裁决 3）。所以连续 `moveCaret()` 之后不 settle 就读，会拿到上一帧
     *   的几何 —— 平台侧应当在**帧时**读（那时盒已同步），别在事件处理中途读。
     *
     * @return Rect|null `null` = 还没挂载出盒，或本字段没有渲染盒
     */
    public function caretRect(): ?Rect
    {
        // ★ 必须**穿过包装层**找 `RenderTextField`：有 label 时 `build()` 返回
        //   `Column[Text(标签), 字段盒]`，`effectiveBox()` 给的是 `Column` 的盒。
        //   用它判类型 ⇒ `null` ⇒ 壳收到"无插入符" ⇒ 候选框贴屏幕左上角
        //   （2026-10-19 实测：带 label 的字段全错，无 label 的全对）。
        $box = $this->element()?->findBox(
            static fn(RenderBox $candidate): bool => $candidate instanceof RenderTextField,
        );

        return $box?->caretRect();
    }

    public function build(BuildContext $ctx): Widget
    {
        $field = $this->field();

        $box = TextFieldBox::make(
            $this->buffer,
            $field->placeholderText(),
            $this->editing ? $this->caret : null,
        );

        $label = $field->labelText();
        if ($label === null) {
            return $box;
        }

        // 标签在字段上方：`Column` 负责间距，`Text` 负责排版 —— 字段盒不必
        // 知道"标签"这件事（它只管"一个输入井"）。
        return Column::make()->spacing(4.0)->children([
            Text::make($label)->caption(),
            $box,
        ]);
    }

    /**
     * 改缓冲 + 通知外部（§11.3.1）。
     *
     * ★ **无变化就不通知、不重建** —— 发假通知会让调用方以为用户改了东西
     *   （G2：宁可少报，不可错报）。
     */
    private function apply(string $nextText, int $nextCaret): void
    {
        if ($nextText === $this->buffer) {
            return;
        }

        $this->buffer = $nextText;
        $this->caret = $nextCaret;

        $handler = $this->field()->changedHandler();
        if ($handler !== null) {
            $handler($nextText);
        }

        $this->markDirty();   // 值已改完，只标脏（与 Cell::setIfChanged 同路）
    }

    /** @return list<int> 所有合法插入点的字节偏移（含 0 与末尾），升序 */
    private function boundaries(): array
    {
        $bounds = [0];
        foreach (Utf8::decode($this->buffer) as $glyph) {
            $bounds[] = $glyph[1] + $glyph[2];
        }

        return $bounds;
    }

    private function field(): TextField
    {
        $widget = $this->widget();
        if (!$widget instanceof TextField) {
            // 框架不变量：StatefulElement 按 get_class 配对，配错不可能发生
            Errors::report('TextFieldState 只能配 TextField（框架内部不变量被破坏）');
        }

        /** @var TextField $widget */
        return $widget;
    }
}
