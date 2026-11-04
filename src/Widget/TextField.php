<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Input\SemanticsRole;

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
 *   `caretRect()`、**选区与编辑命令**都在 —— 选区是「锚点 + 活动端」两个字节偏移
 *   （`TextFieldState`，Shift+方向键扩展 / 不带 Shift 折叠），`Shift+Left/Right/
 *   Home/End`、`Ctrl+A`、`Esc` 吃选区已接通；`Ctrl+C/X/V`（剪贴板）由
 *   `Application` 的**编辑命令环**处理（§5.1：`State` 拿不到平台；环在加速键
 *   之前，门控不满足整族回落 —— 没有剪贴板时它们不是死键）。**指针框选同住
 *   那里**（`dispatchPointer` 三相位：down 落插入符 / move 延伸 / up·leave 收口）。
 *   **键盘 / 指针投递也已到位** —— `FocusManager`（`Drift\App`）收焦点链、
 *   做命中测试，把 `KeyEvent` 转成 `TextFieldState::handleKey()`。构造时已
 *   `markFocusable()` + 声明独占 `Ctrl+A`（IBeam 光标由 `TextFieldBox` 声明 ——
 *   `Element::syncBox()` 的落盒机制只认**自己产盒**的 Widget，`StatefulWidget`
 *   的声明落不到盒上）。
 *   **仍未到位**：IME 组合期（§9.1 路由链第 ② 环）—— 它要平台层，属 M3 之后。
 *   （`CursorShape` 已通：命中链解析 → `dispatchInput()` 返回 → 组合根喂
 *   `WindowHost::setCursor()`；字段自己声明 IBeam。）
 *
 * @api
 */
final class TextField extends StatefulWidget implements SemanticsValueEditable
{
    private string $value = '';
    private ?string $label = null;
    private string $placeholder = '';
    private string $error = '';
    private string $helper = '';
    private ?int $maxLength = null;
    private ?\Closure $onChanged = null;

    public function __construct()
    {
        parent::__construct();
        // §9.1：输入控件天然可聚焦 —— 声明后才会被 FocusManager 收进焦点链
        $this->markFocusable();
        // §9 无障碍：文本框进语义树（name 由 `label()` 给，value 由 `value()` 跟）
        $this->semantics(SemanticsRole::TextField);
        // ★ 不必声明"`Ctrl+A/Z/Y` 归我"：焦点链在加速键**之前**（2026-11-03），
        //   `TextKeymap` 认得这些键就吃、认不得就返回 `null` 落给加速键。
        //   声明式的 `claimKeys()` 已删（它列不全，也表达不了"只在编辑时抢"）。
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
        // §9：可访问"取值"跟随当前文本（读屏/自动化读它，而不是另存一份）
        $this->semantics(SemanticsRole::TextField, null, $text);

        return $this;
    }

    /** 字段标签；画在字段上方（`null` = 不画） */
    public function label(string $text): static
    {
        $this->mutate();
        $this->label = $text;
        // §9：label 就是可访问名 —— 在**设它的那一行**顺手给，不靠框架事后猜
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

    /**
     * 错误消息（空串 = 无错误）。非空 ⇒ 描边转 `Danger` + 下方红字，并抢占 `helper()` 的位置。
     *
     * ★ 受控：只显示应用给的那句，不自己跑校验（规则属业务状态）。
     */
    public function error(string $message): static
    {
        $this->mutate();
        $this->error = $message;

        return $this;
    }

    /** 辅助说明（空串 = 不显示）；有 `error()` 时让位 */
    public function helper(string $text): static
    {
        $this->mutate();
        $this->helper = $text;

        return $this;
    }

    /** 输入长度上限（码位）；`null` = 不限。只截键盘 / IME / 粘贴，不改外部 `value()` */
    public function maxLength(?int $max): static
    {
        $this->mutate();
        $this->maxLength = $max;

        return $this;
    }

    /**
     * 文本改动回调：`fn(string $newText) => void`。
     *
     * ★ 纯 UI 回调，零框架依赖（与 `Button::onPressed()` 同一个形状）——
     *   往哪写（Store / State 字段 / 局部变量）是调用方的事。
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

    public function errorText(): string
    {
        return $this->error;
    }

    public function helperText(): string
    {
        return $this->helper;
    }

    public function maxLengthValue(): ?int
    {
        return $this->maxLength;
    }

    public function changedHandler(): ?\Closure
    {
        return $this->onChanged;
    }

    /**
     * 语义层写值（UIA `ValuePattern.SetValue`）：等价于"用户把文本改成 `$value`"，
     * 走 `onChanged` 一条回写路（受控值归应用 State）。
     *
     * ★ 没接 `onChanged` ⇒ `false`：控件没有把值写出去的通道，假装成功会让读屏
     *   以为改成功了而画面不变。
     */
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
        return new TextFieldState();
    }
}
