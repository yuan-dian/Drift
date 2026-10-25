<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\EdgeInsets;
use Drift\Input\SemanticsRole;

/**
 * 模态对话框（§A.3.7 / §12.1 第 6 条）。
 *
 * ```php
 * // 组合根接线（§4.3：Widget 层够不到 Application）—— 页面拿到的是一个闭包
 * ($this->showModal)(Modal::make()->title('确认删除')->child(
 *     Column::make()->spacing(16)->children([
 *         Text::make('删除后不可恢复，确定吗？')->body(),
 *         Row::make()->spacing(8)->children([...]),
 *     ])
 * ));
 * ```
 *
 * ★ **`$this->application()` 不存在**：`State` 上没有这个访问器（§4.3 禁
 *   `Widget → App`）。本文档 2026-10-17 起就写着它，2026-10-23 在示例台上
 *   才被真机咬出来（点一下进程直接崩、smoke 全绿）。
 *
 * ★ **它只描述形体，不管打开**：打开 / 关闭归 `Application::showModal()` /
 *   `closeModal()`（§5.1 裁决 1 的原话："Application 管生命周期与落位，控件只管
 *   形体"）。所以本类**没有** `open()` —— 一个控件没法知道自己在哪棵树里。
 *
 * ★ **它绝不自己关自己**（§A.3.7 裁决）：按钮的 `->command()` 派发后由应用决定
 *   关不关；框架不替应用判断"这个命令意味着关闭"。
 *   ★ **右上角关闭按钮是唯一的例外，且它也不是"自己关"**（2026-10-25）：那个
 *     `IconButton` 的处理器由 `OverlayManager::showModal()` **注入**（`bindDismiss()`），
 *     与 `SnackbarPanel::make($s, $onDismiss)` 是同一条模式 —— 控件只声明"我这儿
 *     有个关的口子"，关的动作永远来自框架。所以 `Modal` 依然拿不到 `Application`。
 *
 * ★ **`dismissible=false` 的语义是"拒绝"，不是"放行"**：ESC 与遮罩点击被**吞掉**
 *   且模态不关 —— 不能穿透到全局快捷键（否则"必须处理的对话框"会被 Ctrl+W
 *   之类的加速键绕过）。见 `Application::dispatchInput()`。
 *   ★ 同一条语义管关闭按钮：`dismissible=false` ⇒ **不画关闭按钮**。画一个点了
 *     没反应的 X 比没有 X 更糟（用户会以为程序卡了）。
 *
 * ★ **有焦点陷阱**（W3 落地，`OverlayEntry::$focusTrap`）：模态打开时 Tab 只在
 *   "最上面那个 `focusTrap` 条目 + 压在它上面的层"里循环（`Application::focusTrapRoots()`），
 *   主树上的控件 Tab 不到。★ 本文档 2026-10-17 写的"没有焦点陷阱"是**当时的实况**，
 *   W3 之后已不成立 —— 以代码为准。
 *
 * @api
 */
final class Modal extends StatelessWidget
{
    private string $title = '';

    private ?Widget $body = null;

    private bool $dismissible = true;

    /**
     * 面板最小宽（逻辑像素）。
     *
     * ★ 默认 320：模态的**内容**通常是两三行字，自然宽会窄到不像对话框；
     *   最小宽是"对话框形态"的一部分，所以给默认值而不是逼每个调用点写。
     *   要更窄（提示气泡式）就显式 `->minWidth(200)`。
     */
    private float $minWidth = 320.0;

    /**
     * 标题行右侧是否画关闭按钮（`X`）。
     *
     * ★ 默认 **true**（2026-10-25）：一个只能靠 ESC / 点遮罩关掉的对话框，
     *   对不看键盘的用户等于**没有关闭手段** —— 这是可见性缺陷，不是审美偏好。
     *   要纯"确认框"形态（两个按钮、没有 X）就显式 `->closeIcon(false)`。
     * ★ 它服从 `dismissible`：见类注释。两个都为真才画。
     */
    private bool $closeIcon = true;

    /**
     * 关闭按钮的处理器（框架注入；`null` = 没有 ⇒ 不画按钮）。
     *
     * ★ 为什么是"注入"而不是"Modal 自己调 closeModal"：`Widget` 层不认识
     *   `Drift\App`（§4.3）。与 `SnackbarPanel::make()` 的第二个参数同源。
     * ★ 为什么**不走 `mutate()`**：同一个 `Modal` 实例可以被 `showModal()`
     *   开两次，第二次时它已 seal ⇒ 走 `mutate()` 会当场抛。注入是框架的
     *   内部动作（"这次显示接哪根线"），不是用户改样式。
     */
    private ?\Closure $onClose = null;

    public function __construct()
    {
        parent::__construct();
        // §9 无障碍：模态 = 对话框角色（可访问名 = `title()` 给的标题）
        $this->semantics(SemanticsRole::Dialog);
    }

    public static function make(): self
    {
        return new self();
    }

    /** 标题行（空串 ⇒ 不画标题行，也不留空位） */
    public function title(string $text): static
    {
        $this->mutate();
        $this->title = $text;
        $this->semantics(SemanticsRole::Dialog, $text);

        return $this;
    }

    public function child(Widget $body): static
    {
        $this->mutate();
        $this->body = $body;

        return $this;
    }

    /**
     * 能否被"用户动作"关掉（ESC / 点遮罩）。
     *
     * ★ **只管这两条**：`closeModal()` 永远有效（应用自己关不受此限）。
     */
    public function dismissible(bool $value = true): static
    {
        $this->mutate();
        $this->dismissible = $value;

        return $this;
    }

    /**
     * 标题行右侧画不画关闭按钮（`X`）。默认 `true`，见字段注释。
     *
     * ★ 与 `dismissible` 是**与**的关系：`dismissible(false)` 时不画，
     *   不管这里设什么。
     */
    public function closeIcon(bool $value = true): static
    {
        $this->mutate();
        $this->closeIcon = $value;

        return $this;
    }

    public function minWidth(float $value): static
    {
        $this->mutate();
        $this->minWidth = $value;

        return $this;
    }

    /** 应用侧读它（`Application::dispatchInput()` 的 ESC / 遮罩分支） */
    public function isDismissible(): bool
    {
        return $this->dismissible;
    }

    public function titleText(): string
    {
        return $this->title;
    }

    public function body(): ?Widget
    {
        return $this->body;
    }

    /**
     * 注入关闭按钮的处理器（框架内部用）。
     *
     * @internal `OverlayManager::showModal()` 每次显示时接一次线
     */
    public function bindDismiss(\Closure $onClose): void
    {
        $this->onClose = $onClose;
    }

    /** 关闭按钮此刻是否真的会画（`closeIcon` ∧ `dismissible` ∧ 已注入处理器） */
    public function showsCloseIcon(): bool
    {
        return $this->closeIcon && $this->dismissible && $this->onClose !== null;
    }

    public function build(BuildContext $ctx): Widget
    {
        $kids = [];

        if ($this->title !== '') {
            $kids[] = $this->buildTitleRow();
        }

        if ($this->body !== null) {
            $kids[] = $this->body;
        }

        // 两者都没给 ⇒ 一个只有边框的空面板（不报错：形状合法，只是没内容）
        return ModalHost::make(
            Center::make(
                PopupPanel::make(
                    Column::make()->spacing(12.0)->children($kids),
                )
                    ->padding(EdgeInsets::all(20.0))
                    ->minWidth($this->minWidth),
            ),
        );
    }

    /**
     * 标题行：左标题 + 右关闭按钮（有按钮时）。
     *
     * ★ 为什么是 `Row` 而不是把 `IconButton` 并进 Column：Column 的交叉轴是
     *   `Stretch` 语义之外靠内容宽 —— 按钮会掉到标题**下面**去。Row 里
     *   `Spacer` 把按钮推到行尾，标题仍是行首，一行搞定。
     * ★ 按钮用 `Flat` 变体：标题区不该再出现第二个实心块。
     */
    private function buildTitleRow(): Widget
    {
        $title = Text::make($this->title)->heading();

        if (!$this->showsCloseIcon()) {
            return $title;
        }

        return Row::make()->children([
            $title,
            Spacer::make(),
            IconButton::of(IconName::Close, '关闭')
                ->variant(ButtonVariant::Flat)
                ->onPressed($this->onClose),
        ]);
    }
}
