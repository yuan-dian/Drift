<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Input\SemanticsRole;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderToggle;
use Drift\Rendering\ColorToken;
use Drift\Rendering\MetricToken;
use Drift\Rendering\ToggleStyle;
use Drift\Text\TextRole;

/**
 * 开关类控件的共同基座：`Checkbox` / `Radio` / `Toggle`（开关）。
 *
 * ★★ **为什么不叫 `Switch`**：`switch` 是 PHP 保留字，`final class Switch` 直接
 *   语法错。开关控件因此叫 `Toggle`（语义也更准：它就是一个开关），基类叫
 *   `ToggleBase`。`Checkbox` / `Radio` 不继承 `Toggle` —— 它们是**兄弟**，
 *   继承一个只表达"共性"的抽象基座。
 *
 * ```php
 * Checkbox::make('记住我')->checked($this->remember)->onChanged(fn(bool $v) => $this->remember = $v);
 * Radio::make('中')->value('m')->group($this->size)->onChanged(fn(string $v) => $this->size = $v);
 * Toggle::make('启用通知')->checked($this->notify)->onChanged(fn(bool $v) => $this->notify = $v);
 * ```
 *
 * ★★ **受控控件，不自持状态**：`checked` / `group` 由应用给，点击只回调
 *   `onChanged(新值)`，值本身由应用在 `setState` 里改。理由与 `TextField` 一致 ——
 *   控件自持状态会让"表单提交时读到的值"与"屏幕上显示的值"分成两份真相。
 *
 * ★★ **一个基类 + 一个渲染盒**（`int $kind` 分支），不是三套三层：三者布局同构、
 *   命中同构、只有 `paint()` 里的几个图元不同。三个子类会撞 tpc 去虚化
 *   （`11` C6：静态类型已知时子类覆写被静默忽略）—— 与 `RenderSpinner` 用方向表
 *   而不是 `cos()/sin()` 是同一种取舍。
 *
 * ★ **字段是 `protected` 不是 `private`**：会被继承的类，tpc 按**运行时类型**查属性表，
 *   父类 `private` 不进子类属性表 ⇒ 子类实例上访问会动态建属性（值与语义全丢）。
 *   与 `Button` / `Element::$box` / `RenderBox::$size` 同一条纪律（2026-10-16 真机踩过）。
 *
 * ★ **激活走 `Application::activateFocusedWidget()`**（键盘 Space/Enter 与指针按下
 *   的唯一入口），与 `Button` 同一条路 —— 控件自己不装点击监听器。
 *
 * @api
 */
abstract class ToggleBase extends RenderBoxWidget implements Activatable
{
    protected readonly string $label;

    /** `RenderToggle::KIND_*` */
    protected readonly int $kind;

    protected readonly SemanticsRole $role;

    protected bool $on = false;

    protected ?\Closure $onChanged = null;

    /**
     * 单选钮的值与所属组。
     *
     * ★ 住在**基类**而不是 `Radio`：`activate()` 要读它，而"基类调子类字段"在
     *   tpc 下是安全的（字段偏移查运行时类），"基类调子类**方法**"不是（去虚化）。
     */
    protected string $radioValue = '';

    protected string $radioGroup = '';

    public function __construct(string $label, int $kind, SemanticsRole $role)
    {
        parent::__construct();
        $this->label = $label;
        $this->kind = $kind;
        $this->role = $role;

        // §9.1：开关天然可聚焦（Tab 能到、Space 能切）
        $this->markFocusable();
        $this->refreshSemantics();
    }

    /** 选中态（受控值） */
    public function checked(bool $on): static
    {
        $this->mutate();
        $this->on = $on;
        $this->refreshSemantics();

        return $this;
    }

    /**
     * 禁用 / 恢复。
     *
     * ★ 这是 `disabled()` 的**别名**（`enabled(false)` ≡ `disabled()`）——
     *   2026-10-29 收敛：状态只有一份（`Modifier::$disabled`），不再有 `$enabled` 字段。
     *   保留这个名字是因为它在 `Checkbox` / `Radio` / `Toggle` 上已经写了半年，
     *   且"enabled(false)"读起来比"disabled()"更贴近表单语义。
     * ★ 行为随之收紧：**不再留在 Tab 序里**（旧注写的"与 Win32 一致"是错的 ——
     *   `WS_DISABLED` 的控件 `SetFocus` 直接失败），也不响应指针命中。
     * ★ 外观不变：`paintsDisabledLook()` 声明为真 ⇒ 标签换 `ColorToken::Muted`，
     *   且不叠框架那层 0.38 alpha。
     */
    public function enabled(bool $enabled): static
    {
        return $this->disabled(!$enabled);
    }

    public function isEnabled(): bool
    {
        return !$this->mods()->disabled();
    }

    /** 点击/按键回调：收**新值**（不是"切换了"这个事件） */
    public function onChanged(callable $handler): static
    {
        $this->mutate();
        $this->onChanged = \Closure::fromCallable($handler);

        return $this;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function isChecked(): bool
    {
        return $this->on;
    }

    /**
     * 禁用外观由本类自己画（标签换 `ColorToken::Muted`）。
     *
     * ★ 声明为真 ⇒ `Element::syncBox()` 会给盒子的 `Modifier` 打上 `withoutDim()`，
     *   不再叠框架那层 0.38 alpha。否则"灰"再"淡"一次，浅底上几乎看不见。
     */
    public function paintsDisabledLook(): bool
    {
        return true;
    }

    /**
     * 激活（`Activatable`）—— 键盘 Enter/Space 与指针按下的唯一落点。
     *
     * - 禁用 / 没接回调 ⇒ 什么都不做（**不**吞掉焦点变化）
     * - 单选钮已选中 ⇒ 不重复回调（与 HTML radio / Win32 `BM_CLICK` 一致）
     *
     * ★ 换值不需要 `$context` 里的任何东西（不派发命令、不用 element）—— 参数是
     *   接口契约带来的，不是本类需要。`ActivationContext::none()` 即可直接调用。
     */
    public function activate(ActivationContext $context): void
    {
        if ($this->mods()->disabled() || $this->onChanged === null) {
            return;
        }

        $handler = $this->onChanged;

        if ($this->kind === RenderToggle::KIND_RADIO) {
            if ($this->on) {
                return;
            }
            $handler($this->radioValue);

            return;
        }

        $handler(!$this->on);
    }

    /**
     * 语义快照的 `value` 字段：开关是 `checked`/`unchecked`，单选钮是**自己的值**。
     *
     * ★ 不用虚方法（去虚化会把子类覆写静默吞掉）—— 按 `kind` 分支，与 `activate()` 同源。
     * ★★ **不用三元表达式**：`$this->on ? $this->radioValue : ''` 的两支类型不同
     *   （`php::Var` vs 字面量），tpc 生成的 C++ 会撞 `operator?:` 没有重载
     *   （C2678，只有 T2 构建才看得到）。拆成 if/else 就没有类型统一问题。
     */
    final protected function refreshSemantics(): void
    {
        $enabled = !$this->mods()->disabled();

        if ($this->kind === RenderToggle::KIND_RADIO) {
            if ($this->on) {
                $this->semantics($this->role, $this->label, $this->radioValue, $enabled);

                return;
            }
            $this->semantics($this->role, $this->label, '', $enabled);

            return;
        }

        if ($this->on) {
            $this->semantics($this->role, $this->label, 'checked', $enabled);

            return;
        }

        $this->semantics($this->role, $this->label, 'unchecked', $enabled);
    }

    /**
     * §15.7 纪律 3：变体 → token → 值，**只在这里合并一次**。
     * 经 `BuildContext` 取主题（Widget 层唯一被允许碰主题的入口），Render 层只收值。
     */
    public function createRenderObject(BuildContext $context): RenderBox
    {
        // ★ 两个三元表达式都拆成 if/else：`?:` 的两支只要类型不同（或编译器认不出
        //   相同），生成的 C++ 就会撞 C2678。T1 的解释器面完全看不出来。
        $labelColor = $context->color(ColorToken::Muted);
        if (!$this->mods()->disabled()) {
            $labelColor = $context->color(ColorToken::Text);
        }

        $boxSize = 16.0;
        if ($this->kind === RenderToggle::KIND_SWITCH) {
            $boxSize = 18.0;
        }

        $style = new ToggleStyle(
            $context->color(ColorToken::Accent),
            $context->color(ColorToken::Border),
            $context->color(ColorToken::Surface),
            $context->color(ColorToken::Surface),
            $labelColor,
            $context->color(ColorToken::Muted),
            $boxSize,
            $context->metric(MetricToken::Gap),
            $context->textSize(TextRole::Body),
        );

        return new RenderToggle(
            $this->kind,
            $this->on,
            $this->label,
            $style,
            $context->metrics(),
        );
    }
}
