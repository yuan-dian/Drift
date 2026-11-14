<?php
declare(strict_types=1);

namespace App\Showcase;

use Drift\Rendering\Color;
use Drift\Theme\ThemeExtension;
use Drift\Widget\BuildContext;

/**
 * 示例台自己的主题扩展：品牌色 / 圆角 / 署名。
 *
 * ★ `of()` 把 `?ThemeExtension` 的转型收在一处，调用点零字符串、零 null；
 *   `defaults()` 让未挂扩展的上下文（golden / 单测）也能渲染。
 */
final readonly class ShowcaseBrand implements ThemeExtension
{
    public function __construct(
        public string $name,
        public Color $accent,
        public float $radius,
    ) {
    }

    public function key(): string
    {
        return self::class;
    }

    public static function defaults(): self
    {
        return new self('Drift 示例台', Color::fromRgb(0x00, 0x96, 0x88), 4.0);
    }

    public static function of(BuildContext $ctx): self
    {
        $ext = $ctx->extension(self::class);

        return $ext instanceof self ? $ext : self::defaults();
    }
}
