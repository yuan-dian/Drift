<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Theme;

use Drift\Dev\Smoke\App\ThemeApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\ThemeChangedEvent;
use Drift\Widget\Text;

/**
 * 主题切换 ⇒ 界面**真的换表**（§9.2 / §24.2 #14）。
 *
 * `Element::applyContext()` 本就是"换一份渲染环境（主题切换的唯一入口）"，但此前
 * src/ 里**零生产调用者** ⇒ ThemeChangedEvent 只是被显式消费、不做任何事。
 * 现在接起来：先 `onSystemThemeChanged()` 换源、再**递归**下发 context。
 *
 * ★ "递归生效"才是判据：只换根不换子层，控件仍按旧主题取色（换表不换色）——
 *   那正是这条最容易看起来对、实际错的地方。
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
}
