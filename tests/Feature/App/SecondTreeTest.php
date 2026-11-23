<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\Dev\Smoke\App\EchoApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\ThemeChangedEvent;
use Drift\Theme\SystemAppearance;
use Drift\Theme\SystemTheme;
use Drift\Widget\SizedBox;
use Drift\Widget\WindowSize;

/**
 * 浮层是**第二棵树** —— 应用级的"每棵树都要走"必须只有一处出口。
 *
 * 这条债的形态不是"代码难看"，是**反复踩到的静默 bug**：菜单里的 Spinner 不转、
 * 菜单项 Tab 不到、换了主题菜单不变色。三者的成因完全相同（只遍历了主树），
 * 修法也相同（在每处再补一个 `foreach`）—— 于是下一处又漏。
 *
 * 现在收敛成 `AppShell::treeRoots()`：主题下发 / 窗口档下发 / 焦点链 / 动画发现
 * 四处都问它。判据两条：
 * - 行为：浮层树真的跟着换（主题 / 档位）；
 * - 结构：`Application` 里"遍历浮层"的 `foreach` 只有一处（再补一处就红，删光也红）。
 */
final class SecondTreeTest extends SmokeTestCase
{
    private static function attached(): EchoApplication
    {
        $app = new EchoApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
        $app->showMenu(SizedBox::make()->width(80.0)->height(40.0));

        return $app;
    }

    public function testThemeChangeReachesOverlayTree(): void
    {
        $app = self::attached();
        $entry = $app->overlays()->all()[0] ?? null;
        $element = $entry?->element;

        check(
            '第二棵树 · 浮层树挂上了（对照：没有这棵树，下面两条恒真）',
            $element !== null && !$element->context()->theme()->isHighContrast(),
            'overlay missing',
        );

        $app->dispatchInput(new ThemeChangedEvent(new SystemAppearance(SystemTheme::Dark, true)));

        check(
            '第二棵树 · 主题变了，浮层树的 context 也换了（不是只换主树）',
            $element?->context()->theme()->isHighContrast() === true,
            'overlay theme unchanged',
        );

        $this->flushChecks();
    }

    public function testWindowTierChangeReachesOverlayTree(): void
    {
        $app = self::attached();
        $element = $app->overlays()->all()[0]->element ?? null;

        check(
            '第二棵树 · 起始档是 Compact（320 宽，对照）',
            $element?->context()->windowSize() === WindowSize::Compact,
            'tier before',
        );

        // 900 宽 ⇒ 跨到 Medium：`adoptWindowSize()` 要给每一棵树都下发
        $app->repaintForResize(BoxConstraints::tight(new Size(900.0, 700.0)));

        check(
            '第二棵树 · 跨档后浮层树也拿到新档（不是只换主树）',
            $element?->context()->windowSize() === WindowSize::Medium,
            'tier after',
        );

        $this->flushChecks();
    }

    public function testOverlayWalkHasASingleExitPoint(): void
    {
        // 运行时住 `AppShell`；`OverlayLayout` 另有自己的遍历（布局要看每层尺寸），不算出口。
        $source = (string) \file_get_contents(repoRoot() . '/src/App/AppShell.php');

        // ★ 只数"遍历浮层条目"这一种写法。`Frame::run()` 的两处参数是**条目本身**
        //   （绘制要看 barrier / scrim / ancestor），不是"每棵树都要走"，不该算进来。
        $walks = 0;
        foreach (explode("\n", $source) as $line) {
            $code = ltrim($line);
            if (str_starts_with($code, '*') || str_starts_with($code, '//')) {
                continue;   // 注释里点名这段历史写法不算遍历
            }

            if (str_contains($line, 'foreach (') && str_contains($line, 'overlays()->all()')) {
                $walks++;
            }
        }

        check(
            '第二棵树 · 遍历浮层只有一个出口（`treeRoots()`）—— 多一处就是下一次漏浮层的入口',
            $walks === 1,
            "遍历浮层的 foreach 有 {$walks} 处",
        );

        $this->flushChecks();
    }
}
