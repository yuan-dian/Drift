<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\EdgeInsets;
use Drift\Input\CursorShape;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderTextField;
use Drift\Rendering\BoxStyle;
use Drift\Rendering\Color;
use Drift\Rendering\ColorToken;
use Drift\Rendering\MetricToken;
use Drift\Text\TextRole;
use Drift\Text\TextStyle;

/**
 * **原地编辑**的文本盒：只有文字 + 插入符 + 选区，**没有输入"井"的 chrome**。
 *
 * ## 为什么不是复用 `TextFieldBox`
 *
 * `TextFieldBox` 是一个**输入井**（`Pane` 底色 + 边框 + 圆角 + 主题内边距）——
 * 它在表单里是对的，在表格单元格里是错的：单元格已经有自己的列内边距与行高，
 * 再套一层井会得到"双重内边距 + 文字在进入编辑的瞬间横跳几个像素"。
 * 而表格编辑的正确观感是 **Excel / ag-Grid 那种**：文字一个像素都不动，
 * 只是多出插入符与选区。
 *
 * ⇒ 两者的差别是**视觉契约**不同，不是参数不同（同 `Button` 的 `Chrome` 与
 * `TextButton` 分家的理由）。共用的那一层（`RenderTextField` 的排版 / 插入符
 * 几何 / 选区绘制）已经下沉在盒里了，这里只负责喂"无装饰"的样式。
 *
 * ## 三个几何量必须同源（调用方的义务）
 *
 * 插入符偏移与选区起止**取自同一个 `TextDraft` 的同一次读**：分开取时很容易
 * 只更新其中两个（改了草稿却传了上一帧的选区），错误形态是"插入符与高亮不在
 * 同一个位置"—— 画面上很难看出是参数错。
 *
 * ★ 不把 `TextDraft` 本身传进来：Widget 是**值快照**（每次 build 都是新实例），
 *   塞一个可变状态机进去会让"这个盒子代表哪一帧"变得说不清。
 *
 * @internal 应用不直接用它（表格的编辑会话在 `TableState` 里）
 */
final class InlineEditorBox extends RenderBoxWidget
{
    private function __construct(
        private readonly string $text,
        private readonly int $caretOffset,
        private readonly int $selectionStart,
        private readonly int $selectionEnd,
    ) {
        parent::__construct();
        // 编辑中的格子给 I 形光标（"这里能改字"的唯一提示）。声明在**自己产盒**的
        // Widget 上 —— `StatefulWidget` 那边的 `cursor()` 是静默无效的
        // （`Element::syncBox()` 只认自己产盒的）。
        $this->cursor(CursorShape::IBeam);
    }

    /** @internal */
    public static function make(string $text, int $caretOffset, int $selectionStart, int $selectionEnd): self
    {
        return new self($text, $caretOffset, $selectionStart, $selectionEnd);
    }

    /**
     * 编辑盒的文本样式 —— **唯一来源**。
     *
     * ★ 建盒（`createRenderObject()`）与"建盒之前算插入符"（表格双击：指针 x ⇒
     *   字符边界，见 `TableState::pointerCaretOffset()`）必须用同一个样式，
     *   否则量出来的宽度与实际排版差一点点 ⇒ 插入符落在相邻字符上。
     * ★ 单行（`maxLines(1)`）：`RenderTextField` 的插入符几何依赖它。
     *
     * @internal
     */
    public static function editorStyle(BuildContext $context): TextStyle
    {
        return TextStyle::of($context->textSize(TextRole::Body))->withMaxLines(1);
    }

    public function text(): string
    {
        return $this->text;
    }

    public function caretOffset(): int
    {
        return $this->caretOffset;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderTextField(
            $this->text,
            self::editorStyle($context),
            $context->textColor(TextRole::Body),
            '',                                   // 无占位符：单元格里没有"空值提示"这回事
            $context->textColor(TextRole::Caption),
            BoxStyle::none(),                     // ★ 无 chrome：底色/边框/圆角一律不画
            EdgeInsets::zero(),                   // ★ 零内边距：文字位置与静态格逐像素一致
            $this->caretOffset,
            $context->color(ColorToken::Text),
            // ★★ 插入符宽度**必须 > 0**：`RenderTextField::caretRect()` 的宽度就是它，
            //   传 0 的后果是"进入编辑后看不见插入符"——**画面里少一个像素级的东西，
            //   不报错、不写日志**，只有真机看图才发现（首版传了 `0.0`，1920×1017 的
            //   整图 diff 都是 0）。取值跟输入井同一个 token，换主题一起变。
            $context->metric(MetricToken::BorderWidth),
            // 选区高亮与 `TextFieldBox` 同一条配方（不透明混色，不用 alpha ——
            // 软/硬两个后端对半透明的处理不一致，§14.1 的后端等价性不能挂在运气上）
            Color::lerp(
                $context->color(ColorToken::Accent),
                $context->color(ColorToken::Pane),
                0.72,
            ),
            $this->selectionStart,
            $this->selectionEnd,
            $context->metrics(),
        );
    }
}
