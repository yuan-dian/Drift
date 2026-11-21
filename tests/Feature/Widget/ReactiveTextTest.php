<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\State\Cell;
use Drift\Text\TextRole;
use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\ReactiveText;
use Drift\Widget\State;
use Drift\Widget\StatefulElement;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/** 页面只在挂载时 build 一次；格子变化只应重跑文本自身 */
final class ReactiveTextPageW extends StatefulWidget
{
    public function createState(): State
    {
        return new ReactiveTextPageS();
    }
}

final class ReactiveTextPageS extends State
{
    public static int $builds = 0;

    public Cell $count;

    public function initState(): void
    {
        $this->count = Cell::of(0)->named('count');
    }

    public function build(BuildContext $ctx): Widget
    {
        self::$builds++;

        return Column::make()->children([
            Text::make('静态标题'),
            Text::of($this->count, 'n=')->body()->size(18.0),
        ]);
    }
}

/**
 * `Text::of(Readable)`：无闭包的读点。
 *
 * ★ 判据不是"画面对不对"，而是"谁重建了"：值和显示都相同也可能是整页重建。
 */
final class ReactiveTextTest extends SmokeTestCase
{
    private static function reactiveState(Element $root): ?State
    {
        if ($root instanceof StatefulElement && $root->widget() instanceof ReactiveText) {
            return $root->state();
        }
        for ($i = 0; $i < $root->childCount(); $i++) {
            $child = $root->childAt($i);
            if ($child === null) {
                continue;
            }
            $found = self::reactiveState($child);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    public function testTextOfNarrowsRebuildAndKeepsStyle(): void
    {
        ReactiveTextPageS::$builds = 0;

        $element = (new ReactiveTextPageW())->createElement();
        $element->mount(null, smokeContext());
        $element->settle();

        $page = $element->state();
        \assert($page instanceof ReactiveTextPageS);
        check('文本读点 · 前置：页面挂载 build 一次', ReactiveTextPageS::$builds === 1);

        $reactive = self::reactiveState($element);
        \assert($reactive !== null);
        check('文本读点 · 只订到 1 个源', $reactive->reactiveDependencyCount() === 1);

        // 静态标题在前面，这里要找的是带 n= 的那一条
        $text = null;
        $walk = function (Element $e) use (&$walk, &$text): void {
            $w = $e->widget();
            if ($w instanceof Text && \str_starts_with($w->content(), 'n=')) {
                $text = $w;

                return;
            }
            for ($i = 0; $i < $e->childCount(); $i++) {
                $c = $e->childAt($i);
                if ($c !== null) {
                    $walk($c);
                }
            }
        };
        $walk($element);
        \assert($text instanceof Text);

        check('文本读点 · 前缀 + 值拼好', $text->content() === 'n=0', $text->content());
        check('文本读点 · 样式转发到内部 Text', $text->role() === TextRole::Body);
        check('文本读点 · 显式字号转发', $text->explicitSize() === 18.0);

        $page->count->set(3);
        $element->settle();

        check('文本读点 · 页面不重建', ReactiveTextPageS::$builds === 1, 'builds=' . ReactiveTextPageS::$builds);

        $after = null;
        $walk2 = function (Element $e) use (&$walk2, &$after): void {
            $w = $e->widget();
            if ($w instanceof Text && \str_starts_with($w->content(), 'n=')) {
                $after = $w;

                return;
            }
            for ($i = 0; $i < $e->childCount(); $i++) {
                $c = $e->childAt($i);
                if ($c !== null) {
                    $walk2($c);
                }
            }
        };
        $walk2($element);
        \assert($after instanceof Text);
        check('文本读点 · 文本自身更新', $after->content() === 'n=3', $after->content());

        $this->flushChecks();
    }
}
