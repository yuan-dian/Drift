<?php
declare(strict_types=1);

namespace Drift\Theme;

use Drift\Rendering\Color;

/**
 * 一次系统外观快照：明暗 + 高对比度 + 用户在系统里选的颜色。
 *
 * ★ HC 是**独立轴**，不塞进 `SystemTheme`（对齐 WinUI `ThemeDictionaries` 的
 *   Light / Dark / HighContrast 三键与 CSS `forced-colors`）：暗色 + HC 是合法组合。
 * ★ 系统色只在 HC 时有值；非 HC 传 null，`ThemeData::forAppearance()` 不读它们。
 *
 * @api
 */
final readonly class SystemAppearance
{
    public function __construct(
        public SystemTheme $theme,
        public bool $highContrast,
        public ?Color $window = null,
        public ?Color $windowText = null,
        public ?Color $buttonFace = null,
        public ?Color $hotlight = null,
    ) {
    }

    public static function light(): self
    {
        return new self(SystemTheme::Light, false);
    }

    public static function dark(): self
    {
        return new self(SystemTheme::Dark, false);
    }
}
