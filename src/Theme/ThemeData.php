<?php
declare(strict_types=1);

namespace Drift\Theme;

use Drift\Rendering\Color;
use Drift\Rendering\ColorToken;
use Drift\Rendering\MetricToken;
use Drift\Text\TextRole;

/**
 * 一份解析完成的主题。
 *
 * `color()` / `metric()` 是一次表查找，不向上遍历 —— 控件拿到的是解析后的值。
 * 显式样式与 token 的优先级只在这里解析一次，下游只读值。
 *
 * @api
 */
final class ThemeData
{
    /**
     * @param array<string, Color> $colors  覆盖层，键 = `ColorToken->name`
     * @param array<string, float> $metrics 覆盖层，键 = `MetricToken->name`
     * @param array<string, ThemeExtension> $extensions 应用侧自定义 token，键 = 扩展的类名
     *
     * 用 `->name` 而非 token 本身，只因为 PHP 数组键不能是对象；公共面没有字符串键。
     */
    private function __construct(
        private readonly bool $dark,
        private readonly array $colors = [],
        private readonly array $metrics = [],
        private readonly array $extensions = [],
        private readonly bool $highContrast = false,
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

    /**
     * 按一次系统外观快照解析主题。
     *
     * ★ HC 时优先用 `SystemAppearance` 里用户选的系统色（Win32 `GetSysColor()` 口径），
     *   `withHighContrast()` 的框架配色只作为系统色拿不到时的兜底。
     * ★ 不把 HC 塞进 `dark`：`isHighContrast()` 是独立标志，控件/应用可据此收敛装饰。
     */
    public static function forAppearance(SystemAppearance $appearance): self
    {
        $theme = self::forSystem($appearance->theme);
        if (!$appearance->highContrast) {
            return $theme;
        }

        $out = $theme->withHighContrast();
        if ($appearance->window !== null) {
            $out = $out->withColor(ColorToken::Pane, $appearance->window)
                ->withColor(ColorToken::Surface, $appearance->window);
        }
        if ($appearance->windowText !== null) {
            $out = $out->withColor(ColorToken::Text, $appearance->windowText)
                ->withColor(ColorToken::Muted, $appearance->windowText)
                ->withColor(ColorToken::Border, $appearance->windowText);
        }
        if ($appearance->buttonFace !== null) {
            $out = $out->withColor(ColorToken::Chrome, $appearance->buttonFace);
        }
        if ($appearance->hotlight !== null) {
            $out = $out->withColor(ColorToken::Accent, $appearance->hotlight);
        }

        return $out;
    }

    public function isDark(): bool
    {
        return $this->dark;
    }

    public function isHighContrast(): bool
    {
        return $this->highContrast;
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

    /** 点覆盖：改一个 token。同一 token 再次调用以本次为准（后写覆盖）。 */
    public function withColor(ColorToken $token, Color $color): self
    {
        $colors = $this->colors;
        $colors[$token->name] = $color;

        return new self($this->dark, $colors, $this->metrics, $this->extensions, $this->highContrast);
    }

    /** 点覆盖：改一个度量。同一 token 再次调用以本次为准（后写覆盖）。 */
    public function withMetric(MetricToken $token, float $value): self
    {
        $metrics = $this->metrics;
        $metrics[$token->name] = $value;

        return new self($this->dark, $this->colors, $metrics, $this->extensions, $this->highContrast);
    }

    /** 文本角色 → 字号。显式的 `->size()` 优先于角色；换肤只改这张表。 */
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
     * 换成高对比度：面覆盖，把有高对比形态的 token 全换掉。
     * `Hover` / `Pressed` 没有高对比形态（描边不是底色），保留原值。
     *
     * ★ 这是**框架自带**配色；系统跟随走 `forAppearance()`，那边用用户选的系统色。
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

        return new self($this->dark, $colors, $this->metrics, $this->extensions, true);
    }

    /** 挂一份应用侧自定义 token；同名扩展后写覆盖。 */
    public function withExtension(ThemeExtension $extension): self
    {
        $extensions = $this->extensions;
        $extensions[$extension->key()] = $extension;

        return new self($this->dark, $this->colors, $this->metrics, $extensions, $this->highContrast);
    }

    /** 按类名取扩展；未挂返回 `null`。参数只接受 `Foo::class` 这种编译期常量。 */
    public function extension(string $class): ?ThemeExtension
    {
        return $this->extensions[$class] ?? null;
    }

    /** @return array<string, ThemeExtension> */
    public function extensions(): array
    {
        return $this->extensions;
    }
}
