<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Focus;

use Drift\App\Application;
use Drift\Core\Pipeline;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Widget;

/**
 * 焦点通道（§64）：控件层能把焦点**交给另一个** `Element`。
 *
 * 通道是 `Element::requestFocus($target)` → 管线上的 `FocusHost` → `FocusManager`。
 * 判据分四件事，缺一件就能靠"什么都不做"蒙过去：
 *
 * 1. **通**：请求 ⇒ 焦点过去，且**要了一帧**（焦点环是应用级叠加，没有脏信号）；
 * 2. **可聚焦表 ≠ Tab 序**：目标是 `skipFocus()` 的行（树的非活动行）时**仍要生效**
 *    —— 拿 Tab 序校验会把这类目标全拒掉，而它是本通道唯一的真实消费者；
 * 3. **拒**：禁用 / 未挂载的目标 ⇒ 静默不动，不崩；
 * 4. **无宿主**：没有 `FocusHost`（纯单测 / 帧外）⇒ 静默，不崩。
 */
final class FocusChannelTest extends SmokeTestCase
{
    private const W = 320.0;

    private const H = 240.0;

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

    private function two(bool $skipB = false, bool $disabledB = false): Widget
    {
        $b = Button::make('B');

        if ($skipB) {
            $b->skipFocus();
        }

        if ($disabledB) {
            $b->disabled();
        }

        return Column::make()->children([Button::make('A'), $b]);
    }

    private function button(Application $app, string $text): ?Element
    {
        $root = $app->root();
        if ($root === null) {
            return null;
        }

        return $root->findElement(
            static fn(Element $e): bool => $e->widget() instanceof Button && $e->widget()->label() === $text,
        );
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

    // ── ① 通 ─────────────────────────────────────────────────────────

    public function testRequestMovesFocusAndAsksForFrame(): void
    {
        $app = $this->app($this->two());
        $a = $this->button($app, 'A');
        $b = $this->button($app, 'B');
        $app->focus()?->focus($a);

        $before = $app->pipeline()->frameRequestCount();
        $a->requestFocus($b);

        check('通道 · 请求把焦点交给目标', $app->focus()?->focused() === $b, $this->where($app));
        check(
            '通道 · 移焦要了一帧（焦点环是叠加层，没有脏信号）',
            $app->pipeline()->frameRequestCount() > $before,
            'before=' . $before . ' after=' . $app->pipeline()->frameRequestCount(),
        );

        $this->flushChecks();
    }

    // ── ② 可聚焦表 ≠ Tab 序 ──────────────────────────────────────────

    public function testSkipFocusTargetIsStillReachable(): void
    {
        $app = $this->app($this->two(skipB: true));
        $a = $this->button($app, 'A');
        $b = $this->button($app, 'B');
        $app->focus()?->focus($a);

        // 前提自证：B 真的不在 Tab 序里（否则这条测不到东西）
        $inTabOrder = \in_array($b, $app->focus()?->order() ?? [], true);
        check('通道 · 前提：skipFocus 的 B 不在 Tab 序', $inTabOrder === false, '');

        $a->requestFocus($b);

        check(
            '通道 · skipFocus 的行照样能成为移焦目标（判据取可聚焦表，不是 Tab 序）',
            $app->focus()?->focused() === $b,
            $this->where($app),
        );

        $this->flushChecks();
    }

    // ── ③ 拒 ─────────────────────────────────────────────────────────

    public function testDisabledTargetIsRejected(): void
    {
        $app = $this->app($this->two(disabledB: true));
        $a = $this->button($app, 'A');
        $b = $this->button($app, 'B');
        $app->focus()?->focus($a);

        $a->requestFocus($b);

        check('通道 · 禁用的目标不动', $app->focus()?->focused() === $a, $this->where($app));

        $this->flushChecks();
    }

    public function testUnmountedTargetIsRejected(): void
    {
        $app = $this->app($this->two());
        $a = $this->button($app, 'A');
        $app->focus()?->focus($a);

        $orphanWidget = Button::make('孤儿');
        $orphanWidget->seal();
        $orphan = $orphanWidget->createElement();
        $a->requestFocus($orphan);

        check('通道 · 没挂载过的目标不动、也不崩', $app->focus()?->focused() === $a, $this->where($app));

        $this->flushChecks();
    }

    // ── ④ 无宿主 ─────────────────────────────────────────────────────

    public function testWithoutHostIsSilent(): void
    {
        $app = $this->app($this->two());
        $a = $this->button($app, 'A');
        $b = $this->button($app, 'B');
        $app->focus()?->focus($a);

        // 帧外 / 纯单测：`Pipeline::current()` 落在一份没有宿主的账本上
        $saved = Pipeline::current();
        Pipeline::enter(new Pipeline());

        $a->requestFocus($b);   // 不抛、不崩

        Pipeline::enter($saved);

        check('通道 · 没有宿主时静默无效（单测 / 帧外不炸）', $app->focus()?->focused() === $a, $this->where($app));

        $this->flushChecks();
    }

    // ── ⑤ 两个新查找原语 ─────────────────────────────────────────────

    public function testRootAndFindElement(): void
    {
        $app = $this->app($this->two());
        $a = $this->button($app, 'A');
        $b = $this->button($app, 'B');
        $root = $app->root();

        check('通道 · root() 沿父链到顶', $a->root() === $root, $a->root() === $root ? '' : 'root 不是应用根');

        $found = $root->findElement(
            static fn(Element $e): bool => $e->widget() instanceof Button && $e->widget()->label() === 'B',
        );
        check('通道 · findElement 按谓词找得到', $found === $b, '');

        $none = $root->findElement(static fn(Element $e): bool => false);
        check('通道 · findElement 找不到 ⇒ null', $none === null, '');

        // 先序且**含自己**：拿根自己当谓词命中
        check('通道 · findElement 含自己', $root->findElement(static fn(Element $e): bool => $e === $root) === $root, '');

        $this->flushChecks();
    }
}
