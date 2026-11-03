<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Input\SemanticsRole;

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
 * ★ **高度**：`minLines()` 只保证空文本时不塌成一行。真正的可见高度由外层给
 *   （`SizedBox` / `Expanded`）—— 不做 `maxLines` 截断：那个语义在本仓已经属于
 *   `TextStyle::maxLines`（画省略号），两个同名不同义的东西迟早被混用。
 *
 * @api
 */
final class TextArea extends StatefulWidget implements SemanticsValueEditable
{
    private string $value = '';
    private ?string $label = null;
    private string $placeholder = '';
    private ?\Closure $onChanged = null;
    private ?\Closure $onSubmitted = null;
    private int $minLines = 3;

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

    /** 当前文本（受控值） */
    public function value(string $text): static
    {
        $this->mutate();
        $this->value = $text;
        $this->semantics(SemanticsRole::TextField, null, $text);

        return $this;
    }

    /** 字段标签；画在框上方（`null` = 不画） */
    public function label(string $text): static
    {
        $this->mutate();
        $this->label = $text;
        $this->semantics(SemanticsRole::TextField, $text);

        return $this;
    }

    /** 空值时显示的提示文本 */
    public function placeholder(string $text): static
    {
        $this->mutate();
        $this->placeholder = $text;

        return $this;
    }

    /** 至少显示几行高（空文本时的下限，默认 3） */
    public function minLines(int $lines): static
    {
        $this->mutate();
        $this->minLines = max(1, $lines);

        return $this;
    }

    /**
     * 文本改动回调：`fn(string $newText) => void`。
     *
     * ★ 与 `TextField::onChanged()` 同形 —— 换行也算文本改动（多行框里换行就是
     *   编辑，没有"提交"的含义需要区分）。
     */
    public function onChanged(callable $handler): static
    {
        $this->mutate();
        $this->onChanged = \Closure::fromCallable($handler);

        return $this;
    }

    /**
     * `Ctrl+Enter` 提交回调：`fn(string $text) => void`。
     *
     * ★ 不接 ⇒ `Ctrl+Enter` **什么都不做**（不吞键、也不换行）：它归
     *   `activateFocused()` 之后的加速键。多行框默认不该拦一个没有消费者的组合。
     */
    public function onSubmitted(callable $handler): static
    {
        $this->mutate();
        $this->onSubmitted = \Closure::fromCallable($handler);

        return $this;
    }

    public function valueText(): string
    {
        return $this->value;
    }

    public function labelText(): ?string
    {
        return $this->label;
    }

    public function placeholderText(): string
    {
        return $this->placeholder;
    }

    public function minLinesValue(): int
    {
        return $this->minLines;
    }

    public function changedHandler(): ?\Closure
    {
        return $this->onChanged;
    }

    public function submittedHandler(): ?\Closure
    {
        return $this->onSubmitted;
    }

    /** 语义层写值（UIA `ValuePattern.SetValue`）—— 与 `TextField` 同一条回写路 */
    public function setSemanticsValue(string $value): bool
    {
        $handler = $this->onChanged;
        if ($handler === null) {
            return false;
        }
        $handler($value);

        return true;
    }

    public function createState(): State
    {
        return new TextAreaState();
    }
}
