<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\App\Application;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Widget\Button;
use Drift\Widget\Element;
use Drift\Widget\Expanded;
use Drift\Widget\TreeNode;
use Drift\Widget\TreeView;
use Drift\Widget\Widget;

/**
 * 树的键盘模型（§58）：**一个 Tab 站 + 方向键在行间走 + `←→` 展开折叠**。
 *
 * 与 WPF `TreeView` / ARIA `tree` / Qt `QTreeView` 同形。改前是 8 行 8 个 Tab 站
 * （200 行的树要按 200 次 Tab 才出得去），而 `←→` 会落到**相邻行的折叠箭头**上
 * （箭头不进 Tab 序、但在可聚焦表里）—— 一个用户看不见的站。
 *
 * 判据分五件事，少一件就能靠"全进 Tab 序"或"`←→` 什么都不做"蒙过去：
 *
 * 1. **只占一站**（选中行；没有就首行；只读树零站）；
 * 2. **`←→` 是层级语义**（折叠已展开的、展开折叠的，方向不对就一次都不上报）；
 * 3. **叶子吞掉 `←→`**（让位就是焦点漂到别人家的箭头）；
 * 4. **`↑↓` 仍归几何导航**（`KeyResult::Declined` 生效，树自己不造焦点模型）；
 * 5. **Enter 选中焦点所在行**。
 */
final class TreeKeyboardTest extends SmokeTestCase
{
    private const W = 420.0;

    private const H = 480.0;

    private static function constraints(): BoxConstraints
    {
        return BoxConstraints::tight(new Size(self::W, self::H));
    }

    private function app(Widget $root): Application
    {
        $app = new class extends Application {
            private Widget $testRoot;

            public function setRoot(Widget $widget): void
            {
                $this->testRoot = $widget;
            }

            public function buildRoot(): Widget
            {
                return $this->testRoot;
            }
        };
        $app->setRoot($root);
        $probe = new SurfaceProbe();
        $probe->resize((int) self::W, (int) self::H, 1.0);
        $app->attach($probe, self::constraints());
        $app->frame(0.0, self::constraints());

        return $app;
    }

    /** @param list<string> $expanded */
    private function tree(
        array $expanded,
        ?string $selected = null,
        ?\Closure $onSelected = null,
        ?\Closure $onToggle = null,
    ): TreeView {
        $view = TreeView::make([
            TreeNode::branch('project', 'drift', [
                TreeNode::branch('src', 'src', [
                    TreeNode::branch('widget', 'Widget', [
                        TreeNode::make('button', 'Button'),
                        TreeNode::make('table', 'Table'),
                    ]),
                    TreeNode::make('app', 'App'),
                ]),
                TreeNode::make('docs', 'docs'),
            ]),
            TreeNode::make('tests', 'tests'),
        ])->expanded($expanded)->selected($selected);

        if ($onSelected !== null) {
            $view->onSelected($onSelected);
        }
        if ($onToggle !== null) {
            $view->onToggle($onToggle);
        }

        return $view;
    }

    /** @return list<string> Tab 序里各站的名字（文档序） */
    private function stops(Application $app): array
    {
        $out = [];
        foreach ($app->focus()?->order() ?? [] as $element) {
            $widget = $element->widget();
            $out[] = $widget instanceof Button ? $widget->label() : $widget::class;
        }

        return $out;
    }

    private function where(Application $app): string
    {
        $element = $app->focus()?->focused();
        if ($element === null) {
            return 'null';
        }

        $widget = $element->widget();

        return $widget instanceof Button ? $widget->label() : $widget::class;
    }

    private function label(Application $app, string $text): ?Element
    {
        $root = $app->root();
        if ($root === null) {
            return null;
        }

        return findElement(
            $root,
            static fn(Element $e): bool => $e->widget() instanceof Button && $e->widget()->label() === $text,
        );
    }

    // ── ① 一个 Tab 站 ──────────────────────────────────────────────────

    public function testTreeIsOneTabStop(): void
    {
        $app = $this->app(Expanded::make($this->tree(
            ['project', 'src', 'widget'],
            'table',
            static function (string $id): void {},
            static function (string $id, bool $open): void {},
        )));

        $stops = $this->stops($app);
        check(
            '树 · 8 个可见行只占 1 个 Tab 站，且是**选中行**',
            $stops === ['Table'],
            'stops=' . \json_encode($stops),
        );

        $this->flushChecks();
    }

    public function testEntryFallsBackToFirstVisibleRow(): void
    {
        // 选中在折叠起来的分支里 ⇒ 那一行根本没被展平出来
        $app = $this->app(Expanded::make($this->tree(
            [],
            'table',
            static function (string $id): void {},
        )));

        $stops = $this->stops($app);
        check(
            '树 · 选中行不在可见行里 ⇒ 入口退回首行（不猜、不自动展开）',
            $stops === ['drift'],
            'stops=' . \json_encode($stops),
        );

        $this->flushChecks();
    }

    public function testReadOnlyTreeHasNoTabStop(): void
    {
        $app = $this->app(Expanded::make($this->tree(['project'])));

        check(
            '树 · 一个回调都不给 ⇒ 零站（占站 ⇔ 真按得动）',
            $this->stops($app) === [],
            'stops=' . \json_encode($this->stops($app)),
        );

        $this->flushChecks();
    }

    // ── ② ←→ 的层级语义 ───────────────────────────────────────────────

    /**
     * ★ 两个方向各挂一棵树：受控控件只在应用给了新展开集合后才变形状，
     *   同一棵树连按两次测的是"应用没更新"，不是控件的语义。
     */
    public function testLeftCollapsesAnExpandedBranch(): void
    {
        $asked = [];
        $app = $this->app(Expanded::make($this->tree(
            ['project', 'src', 'widget'],
            'widget',
            null,
            static function (string $id, bool $open) use (&$asked): void {
                $asked[] = [$id, $open];
            },
        )));

        $app->focus()?->focus($this->label($app, 'Widget'));
        check(
            '树 · ← 收起已展开的分支 ⇒ 上报 (widget, false)',
            $app->focus()?->dispatchKey(KeyEvent::down(KeyChord::left())) === true
                && $asked === [['widget', false]],
            'asked=' . \json_encode($asked),
        );

        check(
            '树 · ← 之后焦点不漂（仍在 Widget 行）',
            $this->where($app) === 'Widget',
            $this->where($app),
        );

        $this->flushChecks();
    }

    public function testRightExpandsACollapsedBranch(): void
    {
        $asked = [];
        $app = $this->app(Expanded::make($this->tree(
            ['project', 'src'],
            'widget',
            null,
            static function (string $id, bool $open) use (&$asked): void {
                $asked[] = [$id, $open];
            },
        )));

        $app->focus()?->focus($this->label($app, 'Widget'));
        $app->focus()?->dispatchKey(KeyEvent::down(KeyChord::right()));
        check(
            '树 · → 展开已折叠的分支 ⇒ 上报 (widget, true)',
            $asked === [['widget', true]],
            'asked=' . \json_encode($asked),
        );

        // 方向不对就一次都不该上报（受控控件不给应用"猜当时状态"的机会）
        $asked = [];
        $app2 = $this->app(Expanded::make($this->tree(
            ['project', 'src', 'widget'],
            'widget',
            null,
            static function (string $id, bool $open) use (&$asked): void {
                $asked[] = [$id, $open];
            },
        )));
        $app2->focus()?->focus($this->label($app2, 'Widget'));
        $app2->focus()?->dispatchKey(KeyEvent::down(KeyChord::right()));
        check('树 · 已展开的分支再按 → ⇒ 不上报（不重复 toggle）', $asked === [], 'asked=' . \json_encode($asked));

        $this->flushChecks();
    }

    // ── ③ 叶子吞掉 ←→ ────────────────────────────────────────────────

    public function testLeafSwallowsHorizontalArrows(): void
    {
        $app = $this->app(Expanded::make($this->tree(
            ['project', 'src', 'widget'],
            'table',
            static function (string $id): void {},
            static function (string $id, bool $open): void {},
        )));

        $app->focus()?->focus($this->label($app, 'Table'));
        check(
            '树 · 叶子行按 ← 吃掉键、焦点不动（否则会漂到相邻行的折叠箭头）',
            $app->focus()?->dispatchKey(KeyEvent::down(KeyChord::left())) === true
                && $this->where($app) === 'Table',
            $this->where($app),
        );

        check(
            '树 · 叶子行按 → 同上',
            $app->focus()?->dispatchKey(KeyEvent::down(KeyChord::right())) === true
                && $this->where($app) === 'Table',
            $this->where($app),
        );

        $this->flushChecks();
    }

    // ── ④ ↑↓ 仍归几何导航 ────────────────────────────────────────────

    /**
     * ★ 从**分支行**起步：这一条同时钉住 `crossScore()`（行内箭头与标签贴边）。
     *   没有它，`↓` 会落到自己这一行的箭头上，整棵树键盘下不去。
     */
    public function testVerticalArrowsWalkVisibleRows(): void
    {
        $app = $this->app(Expanded::make($this->tree(
            ['project', 'src', 'widget'],
            'widget',
            static function (string $id): void {},
            static function (string $id, bool $open): void {},
        )));

        $app->focus()?->focus($this->label($app, 'Widget'));

        $app->focus()?->dispatchKey(KeyEvent::down(KeyChord::down()));
        check(
            '树 · 分支行按 ↓ ⇒ 下一行（Button），不是自己这一行的箭头',
            $this->where($app) === 'Button',
            $this->where($app),
        );

        $app->focus()?->dispatchKey(KeyEvent::down(KeyChord::up()));
        check('树 · 再按 ↑ ⇒ 回 Widget 行', $this->where($app) === 'Widget', $this->where($app));

        $this->flushChecks();
    }

    // ── ⑤ Enter 选中 ─────────────────────────────────────────────────

    public function testEnterSelectsTheFocusedRow(): void
    {
        $picked = [];
        $app = $this->app(Expanded::make($this->tree(
            ['project', 'src', 'widget'],
            'widget',
            static function (string $id) use (&$picked): void {
                $picked[] = $id;
            },
            static function (string $id, bool $open): void {},
        )));

        $app->focus()?->focus($this->label($app, 'Table'));
        // ★ 走**完整派发**：激活是第 ③ 环的活，`dispatchKey()` 只管 ①（焦点链）
        $app->dispatchInput(KeyEvent::down(KeyChord::enter()));

        check(
            '树 · Enter 选中焦点所在行（上报 id，不是 label / 下标）',
            $picked === ['table'],
            'picked=' . \json_encode($picked),
        );

        $this->flushChecks();
    }
}
