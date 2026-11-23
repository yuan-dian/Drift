<?php
declare(strict_types=1);

namespace Drift\Widget\Menu;

use Drift\Geometry\EdgeInsets;
use Drift\Input\KeyChord;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderMenuItem;
use Drift\Rendering\ColorToken;
use Drift\Text\TextRole;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;

/**
 * 菜单项（§9.1 菜单行；§A.3.1 用法）。
 *
 * ```php
 * MenuItem::make('刷新')->chord(KeyChord::ctrl('R'))->command('users.refresh')
 * MenuItem::make('删除')->command('user.del')->arg(CommandArg::id($id))
 * MenuItem::separator()
 * ```
 *
 * ★ **它是一种按钮**（`extends Button`，同 `NavButton` 的先例）：命令 / 参数 /
 *   `onPressed` / 焦点 / 键盘激活整套复用 —— 于是"菜单项被键盘选中"不需要
 *   第二条激活路径（`InputRouter::activateFocused()` 认 `Button` 就够）。
 *
 * ★ **`chord` 显示的诚实边界**：它是**声明**（我这条菜单项对应哪个加速键），
 *   本轮由 `RenderMenuItem` 在行的右端显示；换行 / 截断规则未细化。
 *   它**不等于**注册加速键 —— 注册在 `Application::accelerators()`（§9.1 第 ① 环）。
 *
 * @api
 */
final class MenuItem extends Button
{
    private ?KeyChord $chord = null;

    private bool $separator = false;

    public function __construct(string $label)
    {
        parent::__construct($label);
    }

    public static function make(string $label): self
    {
        return new self($label);
    }

    /** 分隔线（一条 1px 横线，不可点、不进焦点链） */
    public static function separator(): self
    {
        $self = new self('');
        $self->separator = true;

        return $self;
    }

    /** 本项对应的加速键（**声明 + 显示**；注册归 `Application::accelerators()`） */
    public function chord(KeyChord $chord): static
    {
        $this->mutate();
        $this->chord = $chord;

        return $this;
    }

    public function chordValue(): ?KeyChord
    {
        return $this->chord;
    }

    public function isSeparator(): bool
    {
        return $this->separator;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        $theme = $context->theme();

        return new RenderMenuItem(
            $this->label(),
            $this->chord?->label(),
            $this->separator,
            $theme->color(ColorToken::Text),
            $theme->color(ColorToken::Muted),
            $theme->color(ColorToken::Border),
            EdgeInsets::symmetric(10.0, 5.0),
            $theme->textSize(TextRole::Body),
            $context->metrics(),
            $this->resolveHoverOverlay($context),
            $this->resolvePressedOverlay($context),
        );
    }

    /**
     * @internal 本项**不自绘** chrome（`RenderMenuItem` 只画标签 / 快捷键 / 分隔线），
     *   底 / 边 / 圆角交给 `RenderBox::paint()` 的 ①。
     *
     * ★ 从 `Button` 继承来的 `true` 是错的：那是"声明了自绘却不画"的形状，
     *   `Element::syncBox()` 会把视觉三字段剥掉 ⇒ 用户写的 `->background()`
     *   **静默消失**（菜单项看着正常，属性没了）。
     */
    public function paintsBoxChrome(): bool
    {
        return false;
    }
}
