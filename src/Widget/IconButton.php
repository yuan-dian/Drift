<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 纯图标按钮（工具栏 / 顶条右侧 / 卡片操作位）。
 *
 * ```php
 * IconButton::of(IconName::Search, '搜索')->onPressed(fn() => $this->search());
 * IconButton::of(IconName::Close, '关闭')->variant(ButtonVariant::Flat);
 * ```
 *
 * ★ `$label` **不进视觉**，只作可访问名（UIA `Name`）—— 纯图标按钮没有可读文本，
 *   不传名等于对读屏用户隐形。这也是它**必须**有两个参数的原因。
 *
 * ★ 为什么是 `of()` 而不是 `make()`：`Button::make(string $label): self` 是父类签名，
 *   子类改成"图标 + 名字"会 **Fatal**（`Declaration ... must be compatible`）——
 *   PHP 对静态方法同样查 LSP。`Size::of()` / `TextStyle::of()` 是同款先例。
 *
 * ★ 继承 `Button` 拿到全部行为链（焦点 / 键盘激活 / `command()` / 悬停 / 语义），
 *   本类只改"画什么"：`labelVisible = false` ⇒ `RenderButton` 收到空标签，
 *   于是不建 `RenderText`、尺寸退化成 `图标 + 四边等距内边距`（正方形）。
 *
 * @api
 */
final class IconButton extends Button
{
    public function __construct(IconName $icon, string $label)
    {
        parent::__construct($label);
        // 构造期直接赋字段（不走 `->icon()`：那条路要 `mutate()`，而 mutate 是
        // "封存后不许改"的检查点，构造期不该触发它）
        $this->icon = $icon;
        $this->labelVisible = false;
    }

    public static function of(IconName $icon, string $label): self
    {
        return new self($icon, $label);
    }
}
