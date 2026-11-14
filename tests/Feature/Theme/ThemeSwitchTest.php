<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Theme;

use Drift\App\Application;
use Drift\App\AppScope;
use Drift\Dev\Smoke\App\ThemeApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\ThemeChangedEvent;
use Drift\Theme\SystemAppearance;
use Drift\Theme\SystemTheme;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 主题切换 ⇒ 界面**真的换表**（§9.2 / §24.2 #14）。
 *
 * 两条形态各一条判据：无快照时应用钩子换源；带外观快照时框架自己换。
 * ★ "递归生效"才是判据：只换根不换子层，控件仍按旧主题取色（换表不换色）。
 */
final class ThemeSwitchTest extends SmokeTestCase
{
    public function testThemeChangedEventSwitchesWholeTree(): void
    {
        $thApp = new ThemeApplication();
        $thApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(300.0, 200.0)));
        $thRoot = $thApp->rootElement();
        check(
            '主题 · 派发前：根 context 是浅色（对照，证明"没事件就不变"）',
            $thRoot?->context()->theme()->isDark() === false,
            $thRoot?->context()->theme()->isDark() ? 'dark' : 'light'
        );

        $thApp->dispatchInput(new ThemeChangedEvent());
        check(
            '主题 · onSystemThemeChanged() 钩子被调 ⇒ 应用换了主题源',
            $thApp->currentTheme()->isDark() === true,
            $thApp->currentTheme()->isDark() ? 'dark' : 'light'
        );
        check(
            '主题 · 根 Element 的 context 换成深色（applyContext 下发）',
            $thRoot?->context()->theme()->isDark() === true,
            $thRoot?->context()->theme()->isDark() ? 'dark' : 'light'
        );

        // ★ 递归生效才是"界面换表" —— 只换根不换子层，控件仍按旧主题取色（换表不换色）
        $thText = findElement($thRoot, fn($el) => $el->widget() instanceof Text);
        check(
            '主题 · 子层 context 也换了（applyContext 递归下发 —— 换表**且**换色）',
            $thText?->context()->theme()->isDark() === true,
            $thText?->context()->theme()->isDark() ? 'dark' : 'light'
        );

        $this->flushChecks();
    }

    public function testAppearanceSnapshotSwitchesWholeTreeWithoutHook(): void
    {
        // 不覆写 theme() 的应用（与 apps/showcase 同形）：框架写 AppScope 即生效。
        $app = new ScopeThemeApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(300.0, 200.0)));
        $root = $app->rootElement();

        check(
            '外观 · 派发前是浅色、非 HC（对照）',
            $root?->context()->theme()->isDark() === false
                && $root?->context()->theme()->isHighContrast() === false,
            'before',
        );

        $app->dispatchInput(new ThemeChangedEvent(
            new SystemAppearance(SystemTheme::Dark, true),
        ));

        check(
            '外观 · 带快照时框架自己换主题（暗色 + HC）',
            $app->theme()->isDark() && $app->theme()->isHighContrast(),
            'switched',
        );
        check(
            '外观 · 根 context 跟着换（applyNewContext 下发）',
            $root?->context()->theme()->isDark() === true
                && $root?->context()->theme()->isHighContrast() === true,
            'root',
        );

        $text = findElement($root, fn($el) => $el->widget() instanceof Text);
        check(
            '外观 · 子层 context 也换了（递归下发，不是只换根）',
            $text?->context()->theme()->isHighContrast() === true,
            'child',
        );

        $this->flushChecks();
    }
}

/** 不覆写 theme() 的应用：走 AppScope 默认路径（系统跟随写 scope 即生效）。 */
final class ScopeThemeApplication extends Application
{
    public function __construct(?AppScope $scope = null)
    {
        parent::__construct([], $scope);
    }

    public function rootElement(): ?Element
    {
        return $this->root;
    }

    public function buildRoot(): Widget
    {
        return Column::make()->children([
            Text::make('hello'),
        ]);
    }
}
