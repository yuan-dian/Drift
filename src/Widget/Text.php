<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;
use Drift\Layout\RenderText;
use Drift\Text\TextRole;

/**
 * 文本控件（§15.2）。
 *
 * ★ **fluent-first 的唯一表面**：完整控制可走构造器，但**文档只教链式一种**
 *   （避免双表面分叉，A12）。链式方法统一 `mutate()` 开头、`return $this` 收尾。
 *
 * ★ **哨兵三档定则**（§15.2，消灭 A2——把合法值当"未设置"）：
 *   - 枚举语义 ⇒ enum 里加显式 case（`TextRole::Unset`，于是 0 是合法取值）
 *   - float      ⇒ 负值域外（`$size = -1.0`；尺寸非负）
 *   - bool 三态  ⇒ `-1` 未设置 / `0` 否 / `1` 是
 *   - int 有合法负数域 ⇒ 域外负值
 *   ⇒ 判据：**哨兵必须能写成一个"有名字的东西"**。裸 `0` 只允许出现在
 *     "该取值本身无意义"处（`$maxLines = 0` 表示不限 —— "0 行"不存在）。
 *
 * ★ **`->size()` / `->bold()` 优先于 `->role()`**：优先级只在 `ThemeData`
 *   解析一次（§15.7 纪律 3），不在这里做二轮合并。
 *
 * @api
 */
final class Text extends RenderBoxWidget
{
    private string $content;
    private int $role;
    private float $size = -1.0;
    private int $bold = -1;
    private int $maxLines = 0;
    private int $ellipsize = 0;

    public function __construct(string $content)
    {
        parent::__construct();
        $this->content = $content;
        // §15.2 哨兵：enum case 本身可作属性默认值，取 `->value` 不行（tpc gen_stub 只接受
        // ConstFetch/ClassConstFetch）⇒ 移到构造函数，语义不变。
        $this->role = TextRole::Unset->value;
    }

    /** 唯一入口 —— 只吃必填项（§15.2 纪律 1） */
    public static function make(string $content): self
    {
        return new self($content);
    }

    public function title(): static   { $this->mutate(); $this->role = TextRole::Title->value;   return $this; }
    public function heading(): static { $this->mutate(); $this->role = TextRole::Heading->value; return $this; }
    public function body(): static    { $this->mutate(); $this->role = TextRole::Body->value;    return $this; }
    public function caption(): static { $this->mutate(); $this->role = TextRole::Caption->value; return $this; }
    public function error(): static   { $this->mutate(); $this->role = TextRole::Error->value;   return $this; }

    public function size(float $pt): static      { $this->mutate(); $this->size = $pt; return $this; }
    public function bold(bool $v = true): static { $this->mutate(); $this->bold = $v ? 1 : 0; return $this; }
    public function maxLines(int $n): static     { $this->mutate(); $this->maxLines = $n; return $this; }
    public function ellipsize(bool $v = true): static { $this->mutate(); $this->ellipsize = $v ? 1 : 0; return $this; }

    public function content(): string
    {
        return $this->content;
    }

    public function role(): TextRole
    {
        return TextRole::from($this->role);
    }

    /** 显式字号；`-1.0` = 没给 ⇒ 由角色的字号接管 */
    public function explicitSize(): ?float
    {
        return $this->size < 0.0 ? null : $this->size;
    }

    /** 三态：`null` = 没给 ⇒ 由角色决定 */
    public function explicitBold(): ?bool
    {
        return match ($this->bold) {
            -1 => null,
            0 => false,
            default => true,
        };
    }

    public function maxLinesValue(): int
    {
        return $this->maxLines;
    }

    public function ellipsizeValue(): bool
    {
        return $this->ellipsize === 1;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        $theme = $context->theme();
        $role = $this->role();

        // §15.7 纪律 3：主题 token 与显式样式只在这里合并一次
        $style = \Drift\Text\TextStyle::of($this->explicitSize() ?? $theme->textSize($role))
            ->withMaxLines($this->maxLinesValue())
            ->withEllipsize($this->ellipsizeValue());

        $bold = $this->explicitBold();
        if ($bold !== null) {
            $style = $style->withWeight($bold ? \Drift\Text\FontWeight::Bold : \Drift\Text\FontWeight::Regular);
        }

        return new RenderText(
            $this->content,
            $style,
            $theme->textColor($role),
            $context->metrics(),
        );
    }
}
