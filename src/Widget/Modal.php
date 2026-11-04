<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\EdgeInsets;
use Drift\Input\SemanticsRole;

/**
 * 模态对话框：只描述形体，不管打开 / 关闭（生命周期在 `Application`）。
 * 它绝不自己关自己；唯一例外是右上角关闭按钮，其处理器由框架注入。
 * 打开时有焦点陷阱：Tab 只在最上层 `focusTrap` 条目及其上层循环，主树控件 Tab 不到。
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
     * 默认 320：模态内容通常两三行字，自然宽会窄到不像对话框；提示气泡式
     * 可显式 `->minWidth(200)`。
     */
    private float $minWidth = 320.0;

    /**
     * 标题行右侧是否画关闭按钮（`X`）。
     *
     * 默认 true：只能靠 ESC / 点遮罩关掉的对话框，对不看键盘的用户等于没有
     * 关闭手段。要纯"确认框"形态（两个按钮、没有 X）就显式 `->closeIcon(false)`。
     * 它服从 `dismissible`：两者都为真才画。
     */
    private bool $closeIcon = true;

    /**
     * 关闭按钮的处理器（框架注入；`null` = 没有 ⇒ 不画按钮）。
     *
     * 同一 `Modal` 实例可被 `showModal()` 开两次，第二次已 seal，故不走
     * `mutate()`（会当场抛）。与 `SnackbarPanel::make()` 的第二个参数同源。
     */
    private ?\Closure $onClose = null;

    public function __construct()
    {
        parent::__construct();
        // 无障碍：模态 = 对话框角色（可访问名 = `title()` 给的标题）
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
     * ★ `dismissible=false` 是"拒绝"：ESC 与遮罩点击被吞掉且模态不关，不能
     *   穿透到全局快捷键（否则"必须处理的对话框"会被加速键绕过）。`closeModal()`
     *   永远有效，不受此限。
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
