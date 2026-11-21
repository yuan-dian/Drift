<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Rendering;

use Drift\Dev\Bench\FrameTaxApp;
use Drift\Core\Pipeline;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderProxy;
use Drift\Layout\RenderText;
use Drift\State\Cell;
use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\LiveText;
use Drift\Widget\ReactiveText;
use Drift\Widget\SizedBox;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Widget;

/**
 * 高频值直写（LiveText）：值变只换盒，不重跑 build、不移动兄弟。
 *
 * ★ 判据可证伪：同一 Cell 分别喂 LiveText（leaf）与 ReactiveText（build），
 *   只有前者 rebuiltCount 不增。LiveText 被改回 build 路径 ⇒ 第一条红；
 *   兄弟盒被重建 ⇒ 第二条红；值没进渲染盒 ⇒ 第三条红。
 */
final class LiveTextTest extends SmokeTestCase
{
    private function frame(Widget $root): array
    {
        $tight = BoxConstraints::tight(new Size(240.0, 120.0));
        $app = new FrameTaxApp(static fn(): Widget => $root);
        $surface = new \Drift\Dev\Smoke\Frame\SurfaceProbe();
        $surface->resize(240, 120, 1.0);
        $app->attach($surface, $tight);
        $app->frame(16.0, $tight);

        return [$app, $tight];
    }

    private function findBox(object $node, string $class): ?RenderBox
    {
        if ($node instanceof \Drift\Widget\Element) {
            $box = $node->effectiveBox();
            if ($box instanceof $class) {
                return $box;
            }
        }
        if (!\method_exists($node, 'childCount')) {
            return null;
        }
        for ($i = 0; $i < $node->childCount(); $i++) {
            $found = $this->findBox($node->childAt($i), $class);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    public function testValueChangeRebuildsNothingAndKeepsSibling(): void
    {
        $cell = Cell::of(1)->named('count');
        [$app, $tight] = $this->frame(new LiveTextRoot($cell));

        $sibling = $this->findBox($app->root(), RenderProxy::class);
        check('直写 · 找到兄弟盒', $sibling !== null);
        $boxBefore = $this->findBox($app->root(), RenderText::class);
        check('直写 · 找到文本盒', $boxBefore instanceof RenderText);
        check('直写 · 初始值入盒', $boxBefore?->content() === '1');

        $before = Pipeline::current()->rebuiltCount();
        $cell->set(2);
        $app->frame(32.0, $tight);

        $rebuilt = Pipeline::current()->rebuiltCount() - $before;
        check('直写 · 值变零重建（Leaf 路径，不跑 build）', $rebuilt === 0, "rebuilt=+{$rebuilt}");

        $siblingAfter = $this->findBox($app->root(), RenderProxy::class);
        check('直写 · 兄弟盒身份不变（不移动兄弟）', $siblingAfter === $sibling);

        $boxAfter = $this->findBox($app->root(), RenderText::class);
        check('直写 · 新值入盒（内容真的更新）', $boxAfter?->content() === '2');
        check('直写 · 文本盒换掉（身份不同）', $boxAfter !== $boxBefore);

        $this->flushChecks();
    }

    public function testReactiveTextStillRebuildsAsControl(): void
    {
        $cell = Cell::of(1)->named('count');
        [$app, $tight] = $this->frame(new ReactiveTextRoot($cell));

        $before = Pipeline::current()->rebuiltCount();
        $cell->set(2);
        $app->frame(32.0, $tight);
        $rebuilt = Pipeline::current()->rebuiltCount() - $before;

        check('对照 · ReactiveText 值变确实重建（证明判据能区分两条路径）', $rebuilt > 0, "rebuilt=+{$rebuilt}");
        $this->flushChecks();
    }
}

/** 叶子响应式文本 + 一个兄弟盒，值从外部 Cell 注入。 */
final class LiveTextRoot extends StatelessWidget
{
    public function __construct(private readonly Cell $cell)
    {
        parent::__construct();
    }

    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->children([
            LiveText::of($this->cell),
            SizedBox::make()->size(240.0, 20.0),
        ]);
    }
}

/** 对照：同样结构，走 build 路径的 ReactiveText。 */
final class ReactiveTextRoot extends StatelessWidget
{
    public function __construct(private readonly Cell $cell)
    {
        parent::__construct();
    }

    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->children([
            ReactiveText::of($this->cell),
            SizedBox::make()->size(240.0, 20.0),
        ]);
    }
}
