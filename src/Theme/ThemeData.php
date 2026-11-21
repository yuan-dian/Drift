<?php
declare(strict_types=1);

namespace Drift\Theme;

use Drift\Rendering\Color;
use Drift\Rendering\ColorToken;
use Drift\Rendering\MetricToken;
use Drift\Text\TextRole;
use Drift\Text\TextStyle;

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

    /**
     * 内置颜色表的驻留（`dark` × token）。
     *
     * ★ 不是为了省查表，而是为了**身份稳定**：`ColorToken::lightValue()` 每次 `new`
     *   一个 `Color`，不驻留的话同一个 token 每次取到不同实例，下游"同一份声明"的
     *   身份短路全部失效，只能回落逐字段比较。
     * ★★ **必须是静态，不能落实例属性**：`ThemeData` 是值对象，`AppScope::enter()`
     *   用 `!=` 判"多窗口主题是否一致"。实例缓存会让"配置相同、缓存冷热不同"的两个
     *   主题判**不等** ⇒ 第二个窗口晚开时报一条假的"主题不一致"。静态属性不参与
     *   `==`，值语义原样保留。
     *
     * @var array<string, Color>
     */
    private static array $defaultColors = [];

    public function color(ColorToken $token): Color
    {
        // 覆写值本身就是调用方给的实例 ⇒ 已经稳定，不必再驻留
        if (isset($this->colors[$token->name])) {
            return $this->colors[$token->name];
        }

        $key = ($this->dark ? 'd:' : 'l:') . $token->name;

        return self::$defaultColors[$key]
            ??= ($this->dark ? $token->darkValue() : $token->lightValue());
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

    /**
     * 字号 → 样式实例的驻留。
     *
     * ★ 存在的理由是**身份**：`Text::resolveStyle()` 每帧每文本都要一份样式；每次都
     *   `new` 的话 `RenderText::matchesConfiguration()` 只能逐字段比（9 项），而这里
     *   返回的实例相邻两帧是同一个 ⇒ 一次身份比较就够。
     * ★ 键是**字号**而不是角色：`TextStyle::of()` 只由字号决定，同字号的不同角色本就
     *   该共享。也正因如此它可以放静态（不依赖主题，`==` 的值语义不受影响，理由同
     *   `$defaultColors`）；字号取值天然离散（角色表 + 显式写死），不会无界增长。
     * ★ 键用 `(string)`，与 `TextStyle::cacheKey()` 同源：排版缓存本来就把这两个
     *   只差浮点尾数的字号当同一个键，这里保持同一边界。
     *
     * @var array<string, TextStyle>
     */
    private static array $stylesBySize = [];

    public function textStyle(TextRole $role): TextStyle
    {
        $size = $this->textSize($role);

        return self::$stylesBySize[(string) $size] ??= TextStyle::of($size);
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
