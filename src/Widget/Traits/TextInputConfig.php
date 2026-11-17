<?php
declare(strict_types=1);

namespace Drift\Widget\Traits;

use Drift\Input\SemanticsRole;

/**
 * 受控文本输入控件的**共有配置面**：`TextField` / `TextArea` 共用。
 *
 * 值 / 标签 / 占位符 / 两个回调 —— 受控纪律同一条：`value()` 是唯一文本来源，
 * `onChanged()` 是唯一写回出口。字段留在宿主（state 归各类），trait 只收读写方法。
 *
 * ★ 故意不收 `make()` / `createState()` / 构造：前者返回各类自己，后者各建不同 State。
 *   TextField 独有（error/helper/maxLength/obscure/formatters）与 TextArea 独有
 *   （minLines/lines）也不进 —— 塞进来会让单行框背上多行分支。
 */
trait TextInputConfig
{
    /** 当前文本（受控值）。外部换值 ⇒ State 采纳并重建 */
    public function value(string $text): static
    {
        $this->mutate();
        $this->value = $text;
        $this->semantics(SemanticsRole::TextField, null, $text);

        return $this;
    }

    /** 字段标签；画在字段上方（`null` = 不画） */
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

    /** 文本改动回调：`fn(string $newText) => void` */
    public function onChanged(callable $handler): static
    {
        $this->mutate();
        $this->onChanged = \Closure::fromCallable($handler);

        return $this;
    }

    /** 提交回调：`fn(string $text) => void`（单行 = 裸 Enter，多行 = Ctrl+Enter） */
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

    public function changedHandler(): ?\Closure
    {
        return $this->onChanged;
    }

    public function submittedHandler(): ?\Closure
    {
        return $this->onSubmitted;
    }

    /** 语义层写值（UIA `ValuePattern.SetValue`）—— 无 onChanged 则 false，不假装成功 */
    public function setSemanticsValue(string $value): bool
    {
        $handler = $this->onChanged;
        if ($handler === null) {
            return false;
        }
        $handler($value);

        return true;
    }
}
