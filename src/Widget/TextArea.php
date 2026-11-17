<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Input\SemanticsRole;
use Drift\Widget\Traits\TextInputConfig;

/**
 * 多行输入框（§15.2 的单行版是 `TextField`）。
 *
 * ```php
 * TextArea::make()->value($note)->minLines(4)->label('备注')
 *     ->onChanged(fn(string $x) => $store->setNote($x))
 *     ->onSubmitted(fn(string $x) => $store->save());
 * ```
 *
 * ★ **为什么不是 `TextField(maxLines: n)`**：`RenderTextField` 的几何前提是
 *   "单行"（插入符 x 用 `advance(前缀)` 直接量得、选区是一个矩形、高度恒一行）。
 *   多行要"行内累加 + 行号 + 垂直偏移 + 每行一段选区"，塞进去会让**单行**也背上
 *   分支，而单行是绝大多数输入框。Qt（`QLineEdit` / `QPlainTextEdit`）与
 *   Web（`<input>` / `<textarea>`）也是两个；Flutter / WinUI 合成一个，是因为
 *   它们的盒本来就是多行几何 + `maxLines = 1` 的特例。
 *
 * ★ **受控**：与 `TextField` 同一条纪律 —— `value()` 是唯一文本来源，
 *   `onChanged()` 是唯一写回出口，没有控制器对象（§11.4.2）。
 *
 * ★ **`Enter` 是换行，不是提交**：多行框里 `Enter` 的本职就是换行；提交走
 *   `Ctrl+Enter`（`onSubmitted()`）。这是桌面惯例（GitHub / Slack / VS Code 的
 *   评论框、WinUI `TextBox` 的 `AcceptsReturn`），也是唯一不打架的分法 ——
 *   让 `Enter` 既换行又提交，用户没法在段落里换行。
 *
 * ★ **高度**：`minLines()` 是空文本时的托底。**框高要固定**（表单里的常态 ——
 *   否则一段长文本会把整页撑开、框内永远滚不起来）用 `lines()`：给定几行就是
 *   几行高，内容超出由内部滚动消化，右缘出现滚动条。
 *   ★ 名字刻意不叫 `maxLines`：那个名字在本仓已经属于 `TextStyle::maxLines`
 *   （折行时画省略号），而这里的语义是**布局高度**不是"截断文本"。
 *   （`QPlainTextEdit` 用 `setMaximumHeight` + 行高、Web 用 `<textarea rows>`
 *   与 CSS 高度分开表达，同一个道理。）
 *
 * @api
 */
final class TextArea extends StatefulWidget implements SemanticsValueEditable
{
    use TextInputConfig;

    private string $value = '';
    private ?string $label = null;
    private string $placeholder = '';
    private ?\Closure $onChanged = null;
    private ?\Closure $onSubmitted = null;
    private int $minLines = 3;
    private int $lines = 0;

    public function __construct()
    {
        parent::__construct();
        // §9.1：输入控件天然可聚焦
        $this->markFocusable();
        // §9 无障碍：与单行共用 `TextField` 角色（读屏对"可编辑文本"是同一个概念）
        $this->semantics(SemanticsRole::TextField);
    }

    public static function make(): self
    {
        return new self();
    }

    /** 至少显示几行高（空文本时的下限，默认 3） */
    public function minLines(int $lines): static
    {
        $this->mutate();
        $this->minLines = max(1, $lines);

        return $this;
    }

    /**
     * **固定框高**：恰好这么多行（`0` = 按内容长，默认）。
     *
     * ★ 它同时是上限与下限 —— 内容比它短也不缩（`<textarea rows>` 的语义）。
     * ★ 不加它的后果不是"少个选项"而是"滚动永远不发生"：在 `Column` 里
     *   `TextArea` 会被内容撑到自然高 ⇒ `maxScroll` 恒 0 ⇒ 右缘那条滚动条
     *   永远不出现。
     */
    public function lines(int $lines): static
    {
        $this->mutate();
        $this->lines = max(0, $lines);

        return $this;
    }

    public function minLinesValue(): int
    {
        return $this->minLines;
    }

    public function linesValue(): int
    {
        return $this->lines;
    }

    public function createState(): State
    {
        return new TextAreaState();
    }
}
