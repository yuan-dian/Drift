<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 单行输入框（§15.2 / §A.2.9）。
 *
 * ```php
 * // 官方订阅形态（§11.4.2）：值由外部给，改动经 onChanged 回写
 * SubjectBuilder::make($store)
 *     ->select(fn(UserStore $s): string => $s->query())
 *     ->builder(fn(string $q): Widget =>
 *         TextField::make()->value($q)->label('搜索')->placeholder('输入姓名')
 *             ->onChanged(fn(string $x) => $store->setQuery($x)));
 * ```
 *
 * ★ **受控**：`value()` 是**唯一**的文本来源，`onChanged()` 是**唯一**的写回出口。
 *   与 Flutter 的 `TextEditingController` 不同 —— Drift 不提供控制器对象
 *   （§23.2 O-16 删掉了绑定原语 `Bound`；§11.4.2 的四层论据说明"用户自己持有的
 *   显式对象"在 PHP 里不成立）。
 *
 * ★ **它自己不是盒**：有编辑态 ⇒ 必须是 `StatefulWidget`，而 `StatefulWidget`
 *   不产盒（`Element::syncBox()` 只认 `RenderBoxWidget`）。盒由 `TextFieldBox`
 *   造、渲染态由 `TextFieldState` 持 —— 三段分工见 `TextFieldBox` 的类注释。
 *
 * ★ **今天的边界**（诚实声明，不是待办清单）：编辑缓冲、插入符几何、IME 用的
 *   `caretRect()` 都在；**键盘 / 指针投递也已到位** —— `FocusManager`（`Drift\App`）
 *   收焦点链、做命中测试，把 `KeyEvent` 转成 `TextFieldState::handleKey()`。声明面
 *   就是基类的 `->focusOrder()` / `->skipFocus()` / `->claimKeys()`（`TextField`
 *   天然 `isFocusable()`，构造时已 `markFocusable()`）。
 *   **仍未到位**：IME 组合期（§9.1 路由链第 ② 环）、`AcceleratorTable`（第 ① 环）、
 *   `CursorShape`（命中三方消费的第三项）—— 那些要平台层，属 M3 之后。
 *
 * @api
 */
final class TextField extends StatefulWidget
{
    private string $value = '';
    private ?string $label = null;
    private string $placeholder = '';
    private ?\Closure $onChanged = null;

    public function __construct()
    {
        parent::__construct();
        // §9.1：输入控件天然可聚焦 —— 声明后才会被 FocusManager 收进焦点链
        $this->markFocusable();
    }

    /** 唯一入口 —— 必填项由链式给（`value()` 才是必填，`make()` 不吃参数，§A.2.9） */
    public static function make(): self
    {
        return new self();
    }

    /** 当前文本（受控值）。外部换值 ⇒ `TextFieldState` 采纳并重建 */
    public function value(string $text): static
    {
        $this->mutate();
        $this->value = $text;

        return $this;
    }

    /** 字段标签；画在字段上方（`null` = 不画） */
    public function label(string $text): static
    {
        $this->mutate();
        $this->label = $text;

        return $this;
    }

    /** 空值时显示的提示文本 */
    public function placeholder(string $text): static
    {
        $this->mutate();
        $this->placeholder = $text;

        return $this;
    }

    /**
     * 文本改动回调：`fn(string $newText) => void`。
     *
     * ★ 纯 UI 回调，零框架依赖（与 `Button::onPressed()` 同一个形状）——
     *   往哪写（Store / `Cell` / 局部变量）是调用方的事。
     */
    public function onChanged(callable $handler): static
    {
        $this->mutate();
        $this->onChanged = \Closure::fromCallable($handler);

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

    public function createState(): State
    {
        return new TextFieldState();
    }
}
