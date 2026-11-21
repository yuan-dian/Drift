<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use App\Showcase\ShowcaseRoot;
use Drift\Dev\Smoke\App\ShowcasePageWiring;
use Drift\Dev\Smoke\Frame\RecordingSurface;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\LayoutTolerance;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Theme\ThemeData;
use Drift\Widget\BuildContext;
use Drift\Widget\Element;
use Drift\Widget\NavButton;

/**
 * 示例台外壳的响应式：窄窗档侧栏收成图标栏。
 *
 * ★ 为什么要在**外壳**上量：`narrow-audit.php` 逐页审，审的是"内容区有多宽够不够"，
 *   不审外壳自己。侧栏折叠是否真的发生、折叠后外壳是否还溢出，只有这里能钉。
 */
final class ShowcaseShellResponsiveTest extends SmokeTestCase
{
    public function testCompactCollapsesNavToIcons(): void
    {
        $narrow = $this->renderShell(420.0, 600.0);
        check(
            '外壳 · 420 宽（Compact）⇒ 侧栏不再用 NavButton（收成图标栏）',
            findElement($narrow, static fn(Element $e): bool => $e->widget() instanceof NavButton) === null,
        );
        check(
            '外壳 · 420 宽下整棵外壳不裁剪（侧栏让出的宽度真的够用）',
            Errors::recentDiagnostics() === [],
            \implode(' / ', \array_slice(Errors::recentDiagnostics(), 0, 2)),
        );

        $wide = $this->renderShell(940.0, 600.0);
        check(
            '外壳 · 940 宽（Medium）⇒ 侧栏回到 NavButton（宽档行为不变）',
            findElement($wide, static fn(Element $e): bool => $e->widget() instanceof NavButton) !== null,
        );
    }

    /** 挂载并跑一帧外壳，返回根 Element */
    private function renderShell(float $width, float $height): Element
    {
        Errors::reset();
        LayoutTolerance::reset();

        // 本判据只看外壳布局结构，不跑业务 ⇒ 页面用无头装配表（能力空转、取数 pending）。
        // PageHost 首帧会把 34 页全建出来，所以必须覆盖所有需要依赖的页。
        $context = new BuildContext(
            $width,
            $height,
            ThemeData::light(),
            new DeterministicMetricsProvider(
                DeterministicFontManager::standard()->collection(),
                new DeterministicShaper(),
            ),
        );

        $root = (new ShowcaseRoot(ShowcasePageWiring::factories()))->createElement();
        $root->mount(null, $context);

        LayoutTolerance::withinWindow(static function () use ($root, $width, $height): void {
            (new \Drift\App\Frame(new RecordingSurface()))->run(
                $root,
                BoxConstraints::tight(new Size($width, $height)),
                0.0,
            );
        });

        return $root;
    }
}
