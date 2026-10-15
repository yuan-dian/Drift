<?php
declare(strict_types=1);

namespace Drift\Theme;

use Drift\Rendering\Color;
use Drift\Text\TextRole;

/**
 * 一份解析完成的主题（§11.5 / §15.7）。
 *
 * ★ **`ThemeData` 是"取值来源"，不是查找入口**：`color(ColorToken)` 是一次
 *   **表查找**，没有向上遍历、没有特异性权重 —— §18 已否决 CSS 层叠。
 *
 * ★ **合并只发生一次**：显式样式与主题 token 的优先级在 `ThemeData` 里解析完，
 *   下游只读值（§15.7 纪律 3）。
 *
 * @api
 */
final class ThemeData
{
    /**
     * @param array<string, Color> $colors  覆盖层，键 = `ColorToken->name`
     * @param array<string, float> $metrics 覆盖层，键 = `MetricToken->name`
     *
     * ★ 用 `->name` 而不是 token 本身，只因为 **PHP 的数组键不能是对象**；
     *   这是内部细节，**公共面没有字符串键**（§16.4 否决字符串注册表）。
     */
    private function __construct(
        private readonly bool $dark,
        private readonly array $colors = [],
        private readonly array $metrics = [],
    ) {
    }

    public static function light(): self
    {
        return new self(false);
    }

    public static function dark(): self
    {
        return new self(true);
    }

    public static function forSystem(SystemTheme $theme): self
    {
        return $theme === SystemTheme::Dark ? self::dark() : self::light();
    }

    public function isDark(): bool
    {
        return $this->dark;
    }

    public function color(ColorToken $token): Color
    {
        return $this->colors[$token->name]
            ?? ($this->dark ? $token->darkValue() : $token->lightValue());
    }

    public function metric(MetricToken $token): float
    {
        return $this->metrics[$token->name] ?? $token->baselineValue();
    }

    /** 单个颜色的点覆盖（§15.7：点是 `BoxStyle` 的粒度，这里是“改一张表里的一个键”） */
    public function withColor(ColorToken $token, Color $color): self
    {
        return new self($this->dark, $this->colors + [$token->name => $color], $this->metrics);
    }

    public function withMetric(MetricToken $token, float $value): self
    {
        return new self($this->dark, $this->colors, $this->metrics + [$token->name => $value]);
    }

    /**
     * 文本语义角色 → 字号（§15.2 / §15.7 纪律 3）。
     *
     * ★ **合并只发生在这里一次**：控件只说角色，字号取自这张表 ⇒ 换肤改表，
     *   调用点一个字都不动。
     * ★ 显式的 `->size()` 优先于角色（§15.7 纪律 3：优先级在 `ThemeData` 解析一次）。
     */
    public function textSize(TextRole $role): float
    {
        return match ($role) {
            TextRole::Title   => $this->metric(MetricToken::FontSizeTitle),
            TextRole::Heading => $this->metric(MetricToken::FontSizeHeading),
            TextRole::Body    => $this->metric(MetricToken::FontSize),
            TextRole::Caption => $this->metric(MetricToken::FontSizeSmall),
            TextRole::Error   => $this->metric(MetricToken::FontSizeSmall),
            TextRole::Unset   => $this->metric(MetricToken::FontSize),
        };
    }

    /** 文本语义角色 → 颜色。`Error` 走 `Danger`，其余按主次分 */
    public function textColor(TextRole $role): Color
    {
        return match ($role) {
            TextRole::Error => $this->color(ColorToken::Danger),
            TextRole::Caption => $this->color(ColorToken::Muted),
            TextRole::Title, TextRole::Heading, TextRole::Body, TextRole::Unset
                => $this->color(ColorToken::Text),
        };
    }

    /**
     * 换成高对比度（§9.2 / §A.3.3）：**面覆盖**，把有高对比形态的 token 全换掉。
     *
     * 没有高对比形态的（当前是 `Hover` / `Pressed`）**保留原值** —— 它们的
     * 高对比形态是描边不是底色，换底色只会让两者无法区分。
     */
    public function withHighContrast(): self
    {
        $colors = $this->colors;
        foreach (ColorToken::cases() as $token) {
            $value = $token->highContrastValue();
            if ($value !== null) {
                $colors[$token->name] = $value;
            }
        }

        return new self($this->dark, $colors, $this->metrics);
    }
}
