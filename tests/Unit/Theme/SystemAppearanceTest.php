<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Theme;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Platform\SystemColor;
use Drift\Platform\Win32\Win32Screen;
use Drift\Platform\Win32\Win32WindowHost;
use Drift\Rendering\Color;
use Drift\Rendering\ColorToken;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Theme\SystemAppearance;
use Drift\Theme\SystemTheme;
use Drift\Theme\ThemeData;
use Drift\Theme\ThemeExtension;
use Drift\Widget\BuildContext;

/**
 * 系统外观快照 → 主题映射 + 扩展缝的可执行对账。
 *
 * 只查结构事实：HC 与明暗正交、用户系统色真的覆盖 token、扩展挂得进也读得出、
 * 桥三处对账、活文档引用的判据文件真实存在。
 */
final class SystemAppearanceTest extends SmokeTestCase
{
    private const string ENTRY_DOC = 'docs/guides/text-and-theme.md';
    private const string STUB = 'cpp-src/win32/drift_win32.stub.php';
    private const string CC = 'cpp-src/win32/drift_win32.cc';
    private const string FAKES = 'tools/fakes/win32.php';

    public function testAppearanceKeepsHcOrthogonalToDarkMode(): void
    {
        $hcDark = new SystemAppearance(SystemTheme::Dark, true);

        check(
            '外观 · 暗色 + HC 是合法组合（HC 不塞进 SystemTheme）',
            $hcDark->theme === SystemTheme::Dark && $hcDark->highContrast,
            'orthogonal',
        );
        check(
            '外观 · 非 HC 快照不带系统色',
            SystemAppearance::light()->window === null
                && SystemAppearance::dark()->windowText === null,
            'non-hc-null',
        );
        check(
            '外观 · SystemTheme 仍是 Light/Dark 两态',
            count(SystemTheme::cases()) === 2,
            'cases',
        );

        $this->flushChecks();
    }

    public function testForAppearancePassesThroughWhenHcOff(): void
    {
        // 故意带上系统色：非 HC 时 forAppearance() 必须完全不读它们。
        $appearance = new SystemAppearance(
            SystemTheme::Dark, false,
            Color::fromRgb(1, 2, 3), Color::fromRgb(4, 5, 6),
            Color::fromRgb(7, 8, 9), Color::fromRgb(10, 11, 12),
        );
        $theme = ThemeData::forAppearance($appearance);

        check(
            '外观 · 非 HC 只按明暗选表',
            $theme->isDark() && !$theme->isHighContrast(),
            'pass-through',
        );
        check(
            '外观 · 非 HC 时系统色一个都不覆盖 token',
            $theme->color(ColorToken::Pane)->equals(ColorToken::Pane->darkValue())
                && $theme->color(ColorToken::Text)->equals(ColorToken::Text->darkValue()),
            'no-leak',
        );

        $this->flushChecks();
    }

    public function testHcUsesUserSystemColors(): void
    {
        $window = Color::fromRgb(0x10, 0x20, 0x30);
        $windowText = Color::fromRgb(0xA0, 0xB0, 0xC0);
        $buttonFace = Color::fromRgb(0x40, 0x50, 0x60);
        $hotlight = Color::fromRgb(0xD0, 0xE0, 0xF0);

        $theme = ThemeData::forAppearance(new SystemAppearance(
            SystemTheme::Light, true, $window, $windowText, $buttonFace, $hotlight,
        ));

        $pairs = [
            [ColorToken::Pane, $window],
            [ColorToken::Surface, $window],
            [ColorToken::Text, $windowText],
            [ColorToken::Muted, $windowText],
            [ColorToken::Border, $windowText],
            [ColorToken::Chrome, $buttonFace],
            [ColorToken::Accent, $hotlight],
        ];
        foreach ($pairs as [$token, $expected]) {
            check(
                "外观 · {$token->name} 用用户选的系统色",
                $theme->color($token)->equals($expected),
                $token->name,
            );
        }

        check(
            '外观 · HC 标志独立、明暗不被改成深色',
            $theme->isHighContrast() && !$theme->isDark(),
            'independent',
        );
        check(
            '外观 · 用户色赢过 withHighContrast() 的硬编码配色',
            !$theme->color(ColorToken::Border)->equals(
                ThemeData::light()->withHighContrast()->color(ColorToken::Border)
            ),
            'user-wins',
        );

        $this->flushChecks();
    }

    public function testHcFallsBackToFrameworkPaletteWithoutSystemColors(): void
    {
        $theme = ThemeData::forAppearance(new SystemAppearance(SystemTheme::Light, true));
        $framework = ThemeData::light()->withHighContrast();

        check('外观 · 无系统色时 HC 仍开启', $theme->isHighContrast(), 'hc');
        check(
            '外观 · 无系统色时退回框架 HC 配色',
            $theme->color(ColorToken::Border)->equals($framework->color(ColorToken::Border)),
            'fallback',
        );
        check(
            '外观 · Hover 没有 HC 形态，保留原值',
            $theme->color(ColorToken::Hover)->equals(ThemeData::light()->color(ColorToken::Hover)),
            'hover',
        );

        $this->flushChecks();
    }

    public function testExtensionRoundTrip(): void
    {
        $ext = new StubThemeExtension('one');
        $theme = ThemeData::light()->withExtension($ext);

        check(
            '扩展 · key() 是类名，挂进去按类名读得出',
            $ext->key() === StubThemeExtension::class
                && $theme->extension(StubThemeExtension::class) === $ext,
            'round-trip',
        );
        check(
            '扩展 · 未挂返回 null、原主题不受影响',
            ThemeData::light()->extension(StubThemeExtension::class) === null
                && $theme->extensions() === [StubThemeExtension::class => $ext],
            'isolated',
        );

        $extTwo = new StubThemeExtension('two');
        $over = $theme->withExtension($extTwo);
        check(
            '扩展 · 同类扩展后写覆盖，且不改原主题',
            $over->extension(StubThemeExtension::class) === $extTwo
                && $theme->extension(StubThemeExtension::class) === $ext,
            'last-write',
        );

        $this->flushChecks();
    }

    public function testBuildContextReadsExtensionFromResolvedTheme(): void
    {
        $ext = new StubThemeExtension('ctx');
        $ctx = new BuildContext(
            320.0, 240.0,
            ThemeData::light()->withExtension($ext),
            new DeterministicMetricsProvider(
                DeterministicFontManager::standard()->collection(),
                new DeterministicShaper(),
            ),
        );

        check(
            '扩展 · BuildContext::extension() 读的是已解析主题，不向上查找',
            $ctx->extension(StubThemeExtension::class) === $ext,
            'ctx',
        );
        check(
            '扩展 · 拼错的类名拿到 null（不是静默拿到别的东西）',
            $ctx->extension('Drift\Dev\Tests\Unit\Theme\NeverRegistered') === null,
            'miss',
        );

        $this->flushChecks();
    }

    public function testWin32ScreenAppearanceReadsFakeBridge(): void
    {
        \winSystemAppearanceFake(true, true, [
            SystemColor::WINDOW      => 0x112233,
            SystemColor::WINDOW_TEXT => 0xAABBCC,
            SystemColor::BUTTON_FACE => 0x445566,
            SystemColor::HOTLIGHT    => 0xDDEEFF,
        ]);

        $screen = new Win32Screen(new Win32WindowHost(), 0);
        $appearance = $screen->appearance();

        check(
            '外观 · Win32Screen 读到暗色 + HC',
            $appearance->theme === SystemTheme::Dark && $appearance->highContrast,
            'hc-dark',
        );
        check(
            '外观 · 四个槽号按 0xRRGGBB 拆成 Color',
            $appearance->window?->equals(Color::fromRgb(0x11, 0x22, 0x33)) === true
                && $appearance->windowText?->equals(Color::fromRgb(0xAA, 0xBB, 0xCC)) === true
                && $appearance->buttonFace?->equals(Color::fromRgb(0x44, 0x55, 0x66)) === true
                && $appearance->hotlight?->equals(Color::fromRgb(0xDD, 0xEE, 0xFF)) === true,
            'slots',
        );

        \winSystemAppearanceFake(false, false, []);
        $plain = $screen->appearance();
        check(
            '外观 · 非 HC 时不问系统色（恢复默认后不带颜色）',
            !$plain->highContrast && $plain->window === null,
            'non-hc',
        );

        $this->flushChecks();
    }

    public function testAbiThreeWayAgreement(): void
    {
        $stub = (string) file_get_contents($this->path(self::STUB));
        $cc = (string) file_get_contents($this->path(self::CC));
        $fakes = (string) file_get_contents($this->path(self::FAKES));

        foreach (['drift_win_high_contrast', 'drift_win_system_color'] as $fn) {
            check("ABI · {$fn} stub 有声明", str_contains($stub, "function {$fn}("), 'stub');
            check("ABI · {$fn} C++ 有实现", str_contains($cc, "php_{$fn}("), 'cc');
            check("ABI · {$fn} 假桥有替身", str_contains($fakes, "function {$fn}("), 'fakes');
        }
        check(
            'ABI · WM_SYSCOLORCHANGE 已入队 kEvTheme',
            str_contains($cc, 'case WM_SYSCOLORCHANGE:'),
            'wm',
        );
        $slots = (new \ReflectionClass(SystemColor::class))->getConstants();
        check(
            'ABI · PHP 槽号 0..3 与 C++ switch 一致',
            count($slots) === 4 && $slots['WINDOW'] === 0 && $slots['HOTLIGHT'] === 3
                && str_contains($cc, 'case 0: index = COLOR_WINDOW;')
                && str_contains($cc, 'case 3: index = COLOR_HOTLIGHT;'),
            'slots',
        );

        $this->flushChecks();
    }

    public function testEntryDocReferencesOnlyRealTestFiles(): void
    {
        $src = (string) file_get_contents($this->path(self::ENTRY_DOC));
        $ghosts = array_values(array_filter(
            $this->testPaths($src),
            fn (string $p): bool => !is_file($this->path($p)),
        ));

        check(
            '文档 · 09 引用的判据文件都真实存在（防"文档说有判据、仓库里没有"）',
            $ghosts === [],
            'ghosts=' . implode(',', $ghosts),
        );

        $this->flushChecks();
    }

    public function testExtractorsCatchDrift(): void
    {
        $fake = '见 `tests/Unit/Theme/Ghost.php` 与 `tests/Unit/Theme/SystemAppearanceTest.php`';
        check(
            '判据 · 测试路径提取器抓得到幽灵路径（不是恒绿空壳）',
            $this->testPaths($fake) === [
                'tests/Unit/Theme/Ghost.php',
                'tests/Unit/Theme/SystemAppearanceTest.php',
            ],
            implode(',', $this->testPaths($fake)),
        );
        check(
            '判据 · ABI 对账能抓到缺实现（反例不恒绿）',
            !str_contains('function drift_win_high_contrast(): bool {}', 'php_drift_win_high_contrast('),
            'negative',
        );

        $this->flushChecks();
    }

    public function testDependencyExceptionDoesNotSpread(): void
    {
        $rule = (string) file_get_contents($this->path('tools/Lint/Rules/DependencyDirection.php'));
        preg_match_all("#'(src/Input/[^']+\.php)'\s*=>\s*\['Theme'\]#", $rule, $m);
        check(
            '分层 · Input→Theme 例外只登记 ThemeChangedEvent 一个文件（防例外扩散）',
            $m[1] === ['src/Input/ThemeChangedEvent.php'],
            'files=' . implode(',', $m[1]),
        );

        $event = (string) file_get_contents($this->path('src/Input/ThemeChangedEvent.php'));
        preg_match_all('/^use Drift\\\\Theme\\\\[A-Za-z]+;/m', $event, $refs);
        check(
            '分层 · ThemeChangedEvent 只借 SystemAppearance 一个 Theme 类型',
            $refs[0] === ['use Drift\Theme\SystemAppearance;'],
            'refs=' . implode(',', $refs[0]),
        );

        $this->flushChecks();
    }

    /** @return list<string> */
    private function testPaths(string $src): array
    {
        preg_match_all('#\btests/[A-Za-z0-9_/.-]+\.php\b#', $src, $m);

        return array_values(array_unique($m[0]));
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }
}

/** 测试用扩展：证明 key() 与读取口闭环。 */
final class StubThemeExtension implements ThemeExtension
{
    public function __construct(public string $name)
    {
    }

    public function key(): string
    {
        return self::class;
    }
}
