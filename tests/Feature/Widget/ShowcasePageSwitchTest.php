<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use App\Showcase\Pages\ButtonsPage;
use App\Showcase\ShowcaseRoot;
use Drift\Dev\Smoke\App\ShowcasePageWiring;
use Drift\Dev\Smoke\Frame\RecordingSurface;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\LayoutTolerance;
use Drift\Routing\PageHost;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Theme\ThemeData;
use Drift\Widget\BuildContext;
use Drift\Widget\Element;
use Drift\Widget\NavButton;
use Drift\Widget\States;
use Drift\Widget\Widget;

/**
 * 示例台切页：外壳接 `PageHost` ⇒ 换页是隐藏，不是卸载重建。
 *
 * ★ 判据从**页面 State 的对象身份**看，不从画面看：两个实现都能在切回时画出
 *   同样的内容，只有"同一个 State 对象"能证明表单草稿 / 滚动位置没丢。
 */
final class ShowcasePageSwitchTest extends SmokeTestCase
{
    public function testSwitchingPagesKeepsPageState(): void
    {
        $root = $this->mount();
        $host = findElement($root, static fn (Element $e): bool => $e->widget() instanceof PageHost);
        check('示例台 · 外壳用 PageHost 管页（不是每次现 new 页）', $host !== null);

        $this->clickNav($root, '按钮 Button');
        $buttons = $this->pageState($root, ButtonsPage::class);
        check('示例台 · 点导航能切到按钮页并建出 State', $buttons !== null);

        $this->clickNav($root, '文本 Text');
        $this->clickNav($root, '按钮 Button');
        $back = $this->pageState($root, ButtonsPage::class);
        check('示例台 · 切走再切回是同一个 State（页面实例被缓存）', $back === $buttons);

        $this->flushChecks();
    }

    private function clickNav(Element $root, string $label): void
    {
        $nav = findElement(
            $root,
            static fn (Element $e): bool => $e->widget() instanceof NavButton && $e->widget()->label() === $label,
        );
        check('示例台 · 找到导航项 ' . $label, $nav !== null);
        $widget = $nav?->widget();
        if ($widget instanceof NavButton) {
            $handler = $widget->pressedHandler();
            $handler?->__invoke();
        }
        $root->settle();
    }

    /** 按类名找页面节点：单类页面取 State 身份，旧两件套取壳的类名。 */
    private function pageState(Element $root, string $pageClass): ?object
    {
        $element = findElement($root, static function (Element $e) use ($pageClass): bool {
            $state = States::of($e);
            if ($state !== null && get_class($state) === $pageClass) {
                return true;
            }

            return get_class($e->widget()) === $pageClass;
        });

        return $element?->state();
    }

    private function mount(): Element
    {
        Errors::reset();
        LayoutTolerance::reset();

        // 用无头装配表：PageHost 首帧会把全部页建出来，漏一页就 Fatal。
        $factories = ShowcasePageWiring::factories();

        $context = new BuildContext(
            940.0,
            600.0,
            ThemeData::light(),
            new DeterministicMetricsProvider(
                DeterministicFontManager::standard()->collection(),
                new DeterministicShaper(),
            ),
        );

        $root = (new ShowcaseRoot($factories))->createElement();
        $root->mount(null, $context);
        LayoutTolerance::withinWindow(static function () use ($root): void {
            (new \Drift\App\Frame(new RecordingSurface()))->run(
                $root,
                BoxConstraints::tight(new Size(940.0, 600.0)),
                0.0,
            );
        });

        return $root;
    }
}
