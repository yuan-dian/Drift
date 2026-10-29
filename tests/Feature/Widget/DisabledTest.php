<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\App\FocusManager;
use Drift\Dev\Smoke\App\DisabledApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\PointerEvent;
use Drift\Input\SemanticsRole;
use Drift\Layout\RenderBox;
use Drift\Widget\Button;
use Drift\Widget\Element;
use Drift\Widget\SemanticsBuilder;
use Drift\Widget\Widget;

/**
 * `Widget::disabled()` —— 一个声明、四处消费（2026-10-29）。
 *
 * ## 为什么要四条判据而不是一条
 *
 * 禁用是**横切**能力，四处消费（命中 / 焦点 / 语义 / 绘制）里任何一处漏接，
 * 症状都不报错：漏命中 = "灰了但点得动"；漏焦点 = Tab 键跳进一个点不动的控件；
 * 漏语义 = 读屏说"按钮，可用"然后调用失败；漏绘制 = 用户根本看不出它被禁用了。
 * 所以每条消费点各钉一条，且**先钉基线**（未禁用的同一条链确实会触发）——
 * 否则"没反应"可能只是夹具没点中。
 *
 * ## 两条不显然的判据
 *
 * - **事件被吞而不是穿透**（`testClickIsSwallowedNotPassThrough`）：`hitTest()` 早退
 *   `null` ⇒ 由父容器按第 ③ 条接住。钉这条要求两层**几何上真的重叠**，所以夹具里
 *   先断言"这一层确实盖在那一层上面"，否则"没穿透"和"根本没命中"分不开。
 * - **禁用是子树级**（`testDisabledContainerBlocksChildren`）：与 `hitTest()` 的递归
 *   同构，不需要额外传"继承"参数。
 */
final class DisabledTest extends SmokeTestCase
{
    private float $t = 1000.0;

    private function constraints(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(400.0, 400.0));
    }

    private function boot(): DisabledApplication
    {
        $app = new DisabledApplication();
        $app->attach(new SurfaceProbe(), $this->constraints());
        $app->frame($this->t, $this->constraints());

        return $app;
    }

    /** 在 `$label` 按钮中心按一下（down + up 同点），推进一帧 */
    private function tap(DisabledApplication $app, string $label): bool
    {
        $pt = $app->centerOf($label);
        if ($pt === null) {
            return false;
        }

        $app->dispatchInput(PointerEvent::down($pt[0], $pt[1]));
        $app->dispatchInput(PointerEvent::up($pt[0], $pt[1]));
        $this->t += 16.0;
        $app->frame($this->t, $this->constraints());

        return true;
    }

    /** 某个盒在窗口坐标里的矩形 `[l, t, r, b]`（没排过 ⇒ null） */
    private function rectOf(DisabledApplication $app, string $label): ?array
    {
        $box = $app->boxOf($label);
        $size = $box?->size();
        if ($box === null || $size === null) {
            return null;
        }

        [$x, $y] = RenderBox::absoluteOrigin($box);

        return [$x, $y, $x + $size->width, $y + $size->height];
    }

    private static function contains(array $rect, array $pt): bool
    {
        return $pt[0] >= $rect[0] && $pt[0] <= $rect[2] && $pt[1] >= $rect[1] && $pt[1] <= $rect[3];
    }

    // ── ① 声明面 ───────────────────────────────────────────────────────

    public function testDisabledIsOffByDefault(): void
    {
        $plain = Button::make('x');

        check('disabled · 默认 false（既有控件零变化）', $plain->mods()->disabled() === false);
        check('disabled · 默认时整份修饰仍是 isDefault', $plain->mods()->isDefault() === true);

        $plain->disabled();
        check('disabled() · 置位', $plain->mods()->disabled() === true);
        check('disabled() · 不再是 isDefault（rebuild 会重写盒）', $plain->mods()->isDefault() === false);

        $plain->disabled(false);
        check('disabled(false) · 复位（不是"声明过就永久禁用"）', $plain->mods()->disabled() === false);
    }

    // ── ② 命中 ─────────────────────────────────────────────────────────

    public function testBaselineButtonStillFires(): void
    {
        $app = $this->boot();

        check('disabled · 夹具点得中（中心非空）', $this->tap($app, 'enabled'), 'label=enabled');
        check('disabled · 基线：没禁用的按钮照常触发', $app->pressed === ['enabled'], 'pressed=' . \json_encode($app->pressed));
    }

    public function testClickingDisabledButtonDoesNothing(): void
    {
        $app = $this->boot();

        $this->tap($app, 'off');
        check('disabled · 点禁用按钮不触发 onPressed', $app->pressed === [], 'pressed=' . \json_encode($app->pressed));
    }

    public function testRestoredButtonFires(): void
    {
        $app = $this->boot();

        $this->tap($app, 'back');
        check('disabled · `->disabled(false)` 的按钮照常触发', $app->pressed === ['back'], 'pressed=' . \json_encode($app->pressed));
    }

    public function testDisabledContainerBlocksChildren(): void
    {
        $app = $this->boot();

        $this->tap($app, 'inner');
        check('disabled · 容器禁用 ⇒ 里面的按钮点不动', $app->pressed === [], 'pressed=' . \json_encode($app->pressed));
    }

    public function testClickIsSwallowedNotPassThrough(): void
    {
        $app = $this->boot();

        // ★ 前置自检：`over` 必须真的盖在 `under` 上，否则这条用例证明不了"吞"
        $over = $this->rectOf($app, 'over');
        $under = $this->rectOf($app, 'under');
        $pt = $app->centerOf('over');

        check(
            'disabled · 夹具自检：禁用的 over 与 under 几何重叠',
            $over !== null && $under !== null && $pt !== null && self::contains($under, $pt),
            'over=' . \json_encode($over) . ' under=' . \json_encode($under) . ' pt=' . \json_encode($pt),
        );

        $this->tap($app, 'over');

        check(
            'disabled · 点到禁用的上层 ⇒ 被吞，不穿透到下面的 under',
            $app->pressed === [],
            'pressed=' . \json_encode($app->pressed),
        );
    }

    // ── ③ 焦点 ─────────────────────────────────────────────────────────

    public function testDisabledNotInTabOrder(): void
    {
        $app = $this->boot();

        $fm = new FocusManager();
        $fm->collect([$app->root()]);

        $labels = [];
        foreach ($fm->order() as $element) {
            $widget = $element->widget();
            if ($widget instanceof Button) {
                $labels[] = $widget->label();
            }
        }

        check('disabled · Tab 序里有可用的按钮（基线）', \in_array('enabled', $labels, true), 'order=' . \json_encode($labels));
        check('disabled · 禁用的按钮不进 Tab 序', !\in_array('off', $labels, true), 'order=' . \json_encode($labels));
        check('disabled · 禁用容器里的按钮也不进 Tab 序', !\in_array('inner', $labels, true), 'order=' . \json_encode($labels));
        check('disabled · 被上层盖住的 under 仍在 Tab 序（它自己没禁用）', \in_array('under', $labels, true));
    }

    // ── ④ 语义 ─────────────────────────────────────────────────────────

    public function testSemanticsReportsDisabled(): void
    {
        $app = $this->boot();
        $sem = SemanticsBuilder::build($app->root());
        $role = SemanticsRole::Button->value;

        $enabledIdx = $sem->findByRoleAndName($role, 'enabled');
        $offIdx = $sem->findByRoleAndName($role, 'off');
        $innerIdx = $sem->findByRoleAndName($role, 'inner');

        check('disabled · 语义树里三个按钮都在（禁用≠从语义树消失）', $enabledIdx >= 0 && $offIdx >= 0 && $innerIdx >= 0,
            'enabled=' . $enabledIdx . ' off=' . $offIdx . ' inner=' . $innerIdx);
        check('disabled · 可用按钮 enabled=true', $enabledIdx >= 0 && $sem->node($enabledIdx)->enabled === true);
        check('disabled · 禁用按钮 enabled=false（UIA IsEnabled 的来源）', $offIdx >= 0 && $sem->node($offIdx)->enabled === false);
        check('disabled · 禁用容器里的按钮 enabled=false', $innerIdx >= 0 && $sem->node($innerIdx)->enabled === false);
    }

    // ── ⑤ 绘制 ─────────────────────────────────────────────────────────

    public function testDisabledDimsByAlpha(): void
    {
        $app = $this->boot();

        $off = $app->boxOf('off');
        $on = $app->boxOf('enabled');

        check(
            'disabled · 禁用盒的合成 alpha = DISABLED_ALPHA',
            $off !== null && \abs($off->composeOpacity() - RenderBox::DISABLED_ALPHA) < 1e-6,
            'v=' . \var_export($off?->composeOpacity(), true),
        );
        check(
            'disabled · 可用盒保持 1.0（没有顺手影响到别人）',
            $on !== null && \abs($on->composeOpacity() - 1.0) < 1e-6,
            'v=' . \var_export($on?->composeOpacity(), true),
        );
    }

    public function testDisabledMultipliesExplicitOpacity(): void
    {
        $root = Button::make('x')->opacity(0.5)->disabled();
        $el = $root->createElement();
        $el->mount(null, smokeContext());
        $el->settle();
        $el->effectiveBox()?->layout(BoxConstraints::loose(new Size(120.0, 40.0)));

        $box = $el->effectiveBox();
        $expected = 0.5 * RenderBox::DISABLED_ALPHA;

        check(
            'disabled · 与显式 opacity 是乘算（0.5 × 0.38）',
            $box !== null && \abs($box->composeOpacity() - $expected) < 1e-6,
            'v=' . \var_export($box?->composeOpacity(), true) . ' want=' . $expected,
        );
    }
}
