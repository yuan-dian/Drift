<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\EdgeInsets;
use Drift\Input\SemanticsRole;

/**
 * 模态对话框（§A.3.7 / §12.1 第 6 条）。
 *
 * ```php
 * $this->application()->showModal(
 *     Modal::make()->title('确认删除')->child(
 *         Column::make()->spacing(16)->children([
 *             Text::make('删除后不可恢复，确定吗？')->body(),
 *             Row::make()->spacing(8)->children([...]),
 *         ])
 *     )
 * );
 * ```
 *
 * ★ **它只描述形体，不管打开**：打开 / 关闭归 `Application::showModal()` /
 *   `closeModal()`（§5.1 裁决 1 的原话："Application 管生命周期与落位，控件只管
 *   形体"）。所以本类**没有** `open()` —— 一个控件没法知道自己在哪棵树里。
 *
 * ★ **它绝不自己关自己**（§A.3.7 裁决）：按钮的 `->command()` 派发后由应用决定
 *   关不关；框架不替应用判断"这个命令意味着关闭"。
 *
 * ★ **`dismissible=false` 的语义是"拒绝"，不是"放行"**：ESC 与遮罩点击被**吞掉**
 *   且模态不关 —— 不能穿透到全局快捷键（否则"必须处理的对话框"会被 Ctrl+W
 *   之类的加速键绕过）。见 `Application::dispatchInput()`。
 *
 * ★ **没有焦点陷阱**（诚实边界）：模态打开时 Tab 仍能走到主树的控件上 ——
 *   `FocusManager::collect()` 收的是"主树 + 浮层"两棵树。做焦点陷阱要一套
 *   "容器级焦点作用域"（现在没有这个原语）；先用 `dismissible` + 遮罩挡住
 *   指针，键盘侧留给 M4 的完整 `EventLoop`。
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

    public function build(BuildContext $ctx): Widget
    {
        $kids = [];

        if ($this->title !== '') {
            $kids[] = Text::make($this->title)->heading();
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
}
