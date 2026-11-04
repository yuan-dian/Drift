<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\Rect;
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
 * ★ **文本状态机不在本类**：缓冲 / 插入符 / 选区的字节数学（含"按码位删除、
 *   永不切半个汉字"）在 `Drift\Text\TextDraft`，与表格的单元格编辑**共用同一份**；
 *   "焦点 / 通知 / 重画"这套与行数无关的行为在 `EditableTextState`，与
 *   `TextAreaState` **共用同一份**。本类只剩**单行**特有的两件事：
 *   `build()` 出哪个盒、`caretRect()` 从哪个盒上取。
 *
 * ★ **编辑态与焦点**：`FocusManager`（`Drift\App`）在焦点落到本字段时调
 *   `beginEditing()`、走开时调 `endEditing()`；按键经 `handleKey()` 翻译成编辑
 *   动作 —— 三者合起来就是"输入层最小集"的 widget 侧接口。
 *
 * @api
 */
final class TextFieldState extends EditableTextState
{
    #[\Override]
    protected function controlledValue(): string
    {
        return $this->field()->valueText();
    }

    #[\Override]
    protected function adoptValue(Widget $widget): void
    {
        if (!$widget instanceof TextField) {
            return;
        }

        // ★ **同值不动**由 `TextDraft::setText()` 保证：否则调用方每帧重传同一个值
        //   都会把插入符打回末尾，用户正在中间插入时就"跟手输入不丢"这条承诺直接失效。
        $this->draft()->setText($widget->valueText());
    }

    #[\Override]
    protected function notifyChanged(string $text): void
    {
        $handler = $this->field()->changedHandler();
        if ($handler !== null) {
            $handler($text);
        }
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
    #[\Override]
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

        $error = $field->errorText();
        $invalid = $error !== '';

        $box = TextFieldBox::make(
            $this->text(),
            $field->placeholderText(),
            $this->isEditing() ? $this->caretOffset() : null,
            // 选区恒给（相等即"无选区"）；焦点走开时 endEditing() 已清掉
            $this->selectionStart(),
            $this->selectionEnd(),
            $invalid,
        );

        $kids = [];
        $label = $field->labelText();
        if ($label !== null) {
            // 标签在字段上方：`Column` 负责间距，`Text` 负责排版 —— 字段盒不必
            // 知道"标签"这件事（它只管"一个输入井"）。
            $kids[] = Text::make($label)->caption();
        }
        $kids[] = $box;

        // 提示行：错误抢占辅助文本的位置（同一块地只放一句话）；计数恒在右端。
        $note = $invalid ? $error : $field->helperText();
        $max = $field->maxLengthValue();
        $counter = $max === null ? null : Utf8::length($this->text()) . '/' . $max;

        if ($note !== '' || $counter !== null) {
            $line = [];
            if ($note !== '') {
                $text = Text::make($note)->maxLines(1)->ellipsize();
                $line[] = Expanded::make($invalid ? $text->error() : $text->caption());
            } else {
                $line[] = Spacer::make();
            }
            if ($counter !== null) {
                $line[] = Text::make($counter)->caption();
            }
            $kids[] = Row::make()->spacing(8.0)->children($line);
        }

        return \count($kids) === 1 ? $box : Column::make()->spacing(4.0)->children($kids);
    }

    #[\Override]
    protected function maxLength(): ?int
    {
        return $this->field()->maxLengthValue();
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
