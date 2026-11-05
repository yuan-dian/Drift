<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\Application;
use Drift\Dev\Smoke\App\ScrollKeyApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Layout\RenderScrollView;

/**
 * 翻页键 → **滚动容器**（`§56` 的裁决：方向键归焦点，翻页键归滚动）。
 *
 * ★ 输入一律 `KeyChord::make('PageDown')`（壳层送进来的裸键名），**绝不用**
 *   `KeyChord::pageDown()` 构造 —— 同源构造会让"工厂键名写错"两边一起绿。
 * ★ 判据落在**帧后盒偏移**（`appliedOffset()`）：偏移没进盒 ⇒ "按了没反应"，
 *   而 `dispatchKey()` 的返回值可能照样是 `true`（静默失败）。
 * ★ 焦点为空那一条是主场景：长文页没有任何可聚焦项，键盘用户除了滚轮没别的路。
 */
final class ScrollKeyTest extends SmokeTestCase
{
    private const W = 300.0;

    private const H = 200.0;

    private float $clock = 16.0;

    private static function constraints(): BoxConstraints
    {
        return BoxConstraints::tight(new Size(self::W, self::H));
    }

    private function open(bool $horizontal = false, bool $withButton = false, bool $declares = false): ScrollKeyApplication
    {
        $app = new ScrollKeyApplication($horizontal, $withButton, $declares);
        $app->attach(new SurfaceProbe(), self::constraints());
        $app->frame($this->clock, self::constraints());

        return $app;
    }

    private function press(Application $app, string $key): bool
    {
        $this->clock += 16.0;
        $ate = $app->focus()?->dispatchKey(KeyEvent::down(KeyChord::make($key))) ?? false;
        $app->frame($this->clock, self::constraints());

        return $ate;
    }

    private static function offset(?RenderScrollView $box, Axis $axis = Axis::Vertical): float
    {
        return $box === null ? -1.0 : $box->appliedOffset($axis);
    }

    public function testNoFocusPagesTheScrollView(): void
    {
        $app = $this->open();
        $box = $app->scrollBox();
        check('翻页键 · 无焦点起点为 0', self::offset($box) === 0.0, 'offset=' . self::offset($box));

        $ate = $this->press($app, 'PageDown');
        check(
            '翻页键 · 无焦点 ⇒ PageDown 滚一页（= 视口高 200）',
            $ate && self::offset($app->scrollBox()) === self::H,
            'ate=' . ($ate ? 'y' : 'n') . ' offset=' . self::offset($app->scrollBox()),
        );

        $this->press($app, 'PageDown');
        check(
            '翻页键 · 连续两页 ⇒ 400（不是回到 0、也不是只走一格）',
            self::offset($app->scrollBox()) === self::H * 2,
            'offset=' . self::offset($app->scrollBox()),
        );

        $this->press($app, 'End');
        $max = $app->scrollBox()?->maxScroll(Axis::Vertical) ?? -1.0;
        check(
            '翻页键 · End ⇒ 贴底（== maxScroll）',
            $max > 0.0 && self::offset($app->scrollBox()) === $max,
            'offset=' . self::offset($app->scrollBox()) . ' max=' . $max,
        );

        $this->press($app, 'Home');
        check('翻页键 · Home ⇒ 回到 0', self::offset($app->scrollBox()) === 0.0, 'offset=' . self::offset($app->scrollBox()));

        $this->press($app, 'PageUp');
        check(
            '翻页键 · 已在顶部按 PageUp ⇒ 夹在 0（不出现负偏移）',
            self::offset($app->scrollBox()) === 0.0,
            'offset=' . self::offset($app->scrollBox()),
        );
    }

    public function testFocusInsideScrollStillPagesWithoutMovingFocus(): void
    {
        $app = $this->open(withButton: true);
        $app->focus()?->focusNext();
        $before = $app->focus()?->focused();
        check('翻页键 · 焦点落在滚动内容里的按钮上', $before !== null);

        $ate = $this->press($app, 'PageDown');
        check(
            '翻页键 · 焦点在滚动内容里 ⇒ 仍然翻页（Button 不声明 keyboard）',
            $ate && self::offset($app->scrollBox()) === self::H,
            'ate=' . ($ate ? 'y' : 'n') . ' offset=' . self::offset($app->scrollBox()),
        );
        check(
            '翻页键 · 翻页不动焦点（翻的是内容，不是选择）',
            $app->focus()?->focused() === $before,
            'focused 变了',
        );
    }

    public function testDeclaredKeyboardKeepsPageKeys(): void
    {
        $app = $this->open(withButton: true, declares: true);
        $app->focus()?->focusNext();
        check('翻页键 · 声明者在场且焦点已在它的子树里', $app->focus()?->focused() !== null);

        $ate = $this->press($app, 'PageDown');
        check(
            '翻页键 · 链上有 keyboard() 声明者 ⇒ 框架整组让开（一个像素都不滚）',
            !$ate && self::offset($app->scrollBox()) === 0.0,
            'ate=' . ($ate ? 'y' : 'n') . ' offset=' . self::offset($app->scrollBox()),
        );
    }

    /**
     * 两栏时翻**主视口**，不是树序第一个（`showcase` 的侧栏也是 `ScrollView`）。
     *
     * ★ 这条是本轮唯一"设计口径"判据：树序第一个会翻侧栏，而侧栏在视觉上根本
     *   不该跟着 PageDown 动。判据要同时看**两块盒**：主区动了、侧栏没动。
     */
    public function testTwoPanePagesTheLargestViewport(): void
    {
        $app = new ScrollKeyApplication(twoPane: true);
        $app->attach(new SurfaceProbe(), self::constraints());
        $app->frame($this->clock, self::constraints());

        $boxes = $app->scrollBoxes();
        check('翻页键 · 两栏夹具真的有两块滚动盒（侧栏在前、主区在后）', \count($boxes) === 2, 'n=' . \count($boxes));
        if (\count($boxes) !== 2) {
            $this->flushChecks();

            return;
        }

        $ate = $this->press($app, 'PageDown');
        check(
            '翻页键 · 两栏 ⇒ 翻主视口（PageDown 只动大那块）',
            $ate && self::offset($boxes[1]) === self::H && self::offset($boxes[0]) === 0.0,
            'ate=' . ($ate ? 'y' : 'n')
                . ' 侧栏=' . self::offset($boxes[0]) . ' 主区=' . self::offset($boxes[1]),
        );
    }

    public function testHorizontalContainerPagesOnItsOwnAxis(): void
    {
        $app = $this->open(horizontal: true);
        $ate = $this->press($app, 'PageDown');
        check(
            '翻页键 · 横向容器 ⇒ 走横向轴（页长 = 视口宽 300，竖向不动）',
            $ate && self::offset($app->scrollBox(), Axis::Horizontal) === self::W
                && self::offset($app->scrollBox()) === 0.0,
            'ate=' . ($ate ? 'y' : 'n')
                . ' x=' . self::offset($app->scrollBox(), Axis::Horizontal)
                . ' y=' . self::offset($app->scrollBox()),
        );
    }
}
