<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Input;

use Drift\App\Application;
use Drift\App\FocusManager;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\KeyResult;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\ComboBox;
use Drift\Widget\Element;
use Drift\Widget\Expanded;
use Drift\Widget\IconButton;
use Drift\Widget\IconName;
use Drift\Widget\Row;
use Drift\Widget\SizedBox;
use Drift\Widget\Slider;
use Drift\Widget\Widget;

/**
 * 方向键移焦点（`FocusManager::focusDirectional()`）。
 *
 * 这是 `08` §3 记了很久的"W8-4 未做"：日历 31 格 / 时刻表 144 格全是 `skipFocus()`，
 * 只挡 Tab 序，不该挡方向键。判据必须同时钉住三件事，少一件就能靠"永远不抢"或
 * "永远抢"蒙过去：
 *
 * 1. **几何最近的才是下一个** —— 只验"焦点变了"会让"按 ↓ 跳到最后一个"也绿；
 * 2. **`skipFocus()` 挡 Tab 不挡方向键** —— 两条链分开量；
 * 3. **声明了 `keyboard()` 的控件方向键归它** —— 否则表里按 ↓ 焦点会跑掉。
 */
final class DirectionalFocusTest extends SmokeTestCase
{
    private const W = 320.0;

    private const H = 240.0;

    private static function constraints(): BoxConstraints
    {
        return BoxConstraints::tight(new Size(self::W, self::H));
    }

    /** 真 Application + 表面（几何要排过版才问得到，所以走 frame 而不是裸 mount） */
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

    /** 焦点是谁：按钮取标签，其余取类名（判据里读得懂） */
    private function where(?FocusManager $focus): string
    {
        $element = $focus?->focused();
        if ($element === null) {
            return 'null';
        }

        $widget = $element->widget();

        return $widget instanceof Button ? $widget->label() : $widget::class;
    }

    private function labelOf(Application $app, string $label): ?Element
    {
        $root = $app->root();
        if ($root === null) {
            return null;
        }

        return findElement(
            $root,
            static fn(Element $e): bool => $e->widget() instanceof Button && $e->widget()->label() === $label,
        );
    }

    /** 竖列 A/B 下面接一个横排 C/D：一次布局同时量两个轴 */
    private function twoAxes(): Application
    {
        return $this->app(Column::make()->paddingAll(12.0)->children([
            Button::make('A'),
            Button::make('B'),
            Row::make()->children([Button::make('C'), Button::make('D')]),
        ]));
    }

    public function testNearestInDirectionWins(): void
    {
        $app = $this->twoAxes();
        $focus = $app->focus();
        $focus?->focusNext();

        check('方向键 · 起点是竖列第一项 A', $this->where($focus) === 'A', $this->where($focus));

        $focus?->dispatchKey(KeyEvent::down(KeyChord::down()));
        check(
            '方向键 · Down ⇒ 正下方最近的 B（不是整列的最后一项）',
            $this->where($focus) === 'B',
            $this->where($focus),
        );

        $focus?->dispatchKey(KeyEvent::down(KeyChord::down()));
        check(
            '方向键 · 再 Down ⇒ 横排左端 C（副轴偏移 0 者优先于 D）',
            $this->where($focus) === 'C',
            $this->where($focus),
        );

        check(
            '方向键 · C 正下方没有候选 ⇒ 不吃键（放行给加速键 / 滚动）',
            $focus?->dispatchKey(KeyEvent::down(KeyChord::down())) === false,
        );

        $focus?->dispatchKey(KeyEvent::down(KeyChord::right()));
        check('方向键 · Right ⇒ 同一行的 D', $this->where($focus) === 'D', $this->where($focus));

        $focus?->dispatchKey(KeyEvent::down(KeyChord::up()));
        check(
            '方向键 · 从 D 按 Up ⇒ 斜上最近的 B（不是同行的 C）',
            $this->where($focus) === 'B',
            $this->where($focus),
        );

        $focus?->dispatchKey(KeyEvent::down(KeyChord::left()));
        check(
            '方向键 · 从 B 按 Left ⇒ 左边空无一人 ⇒ 不吃键、焦点不动',
            $this->where($focus) === 'B',
            $this->where($focus),
        );

        $this->flushChecks();
    }

    /**
     * 网格里按 ↓ **不许横向漂移**：格子边贴边时，正下方与斜下方同分。
     *
     * ★ 这条是 2026-11-05 实测撞出来的：`axisGap()` 对"边贴边"返回 0（重叠即 0），
     *   于是正下方与贴着它的斜下方**分数完全一样**，先遍历到的（树序靠前）胜 ⇒
     *   每按一次 ↓ 往左偏一列并一路累积（日历实测 4→10→16→22→28，正确是 11→18→25）。
     * ★ 夹具必须用 `Expanded` 撑满：`Button` 按自然宽排时格子之间有缝，
     *   斜下方的 `axisGap()` 就不是 0 了 —— 那样测的是一个不存在的布局。
     */
    public function testGridDoesNotDriftSideways(): void
    {
        $rows = [];
        for ($r = 0; $r < 3; $r++) {
            $cells = [];
            for ($c = 0; $c < 3; $c++) {
                $cells[] = Expanded::make(Button::make((string) ($r * 3 + $c + 1)));
            }
            $rows[] = Row::make()->children($cells);
        }

        $app = $this->app(Column::make()->children($rows));
        $focus = $app->focus();
        $focus?->focus($this->labelOf($app, '3'));

        check('网格 · 起点是右上角的 3', $this->where($focus) === '3', $this->where($focus));

        $focus?->dispatchKey(KeyEvent::down(KeyChord::down()));
        check(
            '网格 · 从 3 按 ↓ ⇒ 正下方的 6（不是贴着的 5）',
            $this->where($focus) === '6',
            $this->where($focus),
        );

        $focus?->dispatchKey(KeyEvent::down(KeyChord::down()));
        check(
            '网格 · 再 ↓ ⇒ 9（两次都不偏列）',
            $this->where($focus) === '9',
            $this->where($focus),
        );

        $focus?->dispatchKey(KeyEvent::down(KeyChord::up()));
        $focus?->dispatchKey(KeyEvent::down(KeyChord::left()));
        check(
            '网格 · 从 6 按 ← ⇒ 同行的 5（横向也不偏行）',
            $this->where($focus) === '5',
            $this->where($focus),
        );

        $this->flushChecks();
    }

    /**
     * `skipFocus()` 的语义校验：只挡 Tab 序，不挡方向键。
     *
     * ★ 两条链必须分开量。用 `$order` 当候选集时这条会红（B 永远够不着）——
     *   那也是日历 / 时刻表此前完全没法用键盘选的原因。
     */
    public function testSkipFocusStillReachableByArrow(): void
    {
        $app = $this->app(Column::make()->children([
            Button::make('A'),
            Button::make('B')->skipFocus(),
            Button::make('C'),
        ]));
        $focus = $app->focus();
        $focus?->focusNext();

        $focus?->focusNext();
        check(
            'skipFocus · Tab 跳过 B 直接到 C（Tab 序不受影响）',
            $this->where($focus) === 'C',
            $this->where($focus),
        );

        $focus?->focus($this->labelOf($app, 'A'));
        $focus?->dispatchKey(KeyEvent::down(KeyChord::down()));
        check(
            'skipFocus · 方向键仍能到 B（"不进 Tab 序"不等于"够不着"）',
            $this->where($focus) === 'B',
            $this->where($focus),
        );

        $this->flushChecks();
    }

    /**
     * 控件声明了 `keyboard()` ⇒ 方向键是它的语义，框架不抢。
     *
     * ★ 滑块同时是"能靠方向键进来的目标"和"进来后方向键归它"—— 两半都要钉：
     *   只钉后半会漏"根本进不去"，只钉前半会让"滑块调不动值"也绿。
     */
    public function testKeyboardDeclarerKeepsArrows(): void
    {
        $moved = [];
        $app = $this->app(Row::make()->children([
            Button::make('A'),
            Slider::make()->value(50.0)->onChanged(static function (float $v) use (&$moved): void {
                $moved[] = $v;
            }),
        ]));
        $focus = $app->focus();
        $focus?->focusNext();

        $focus?->dispatchKey(KeyEvent::down(KeyChord::right()));
        $widget = $focus?->focused()?->widget();
        check(
            '键盘归属 · Right 从 A 进到滑块（方向键能进得去）',
            $widget instanceof Slider,
            $widget === null ? 'null' : $widget::class,
        );

        $ate = $focus?->dispatchKey(KeyEvent::down(KeyChord::right()));
        check(
            '键盘归属 · 滑块里的 Right 归它 ⇒ 值 +1、焦点不动（框架不抢）',
            $ate === true && $moved === [51.0] && $focus?->focused()?->widget() instanceof Slider,
            'ate=' . var_export($ate, true) . ' moved=' . json_encode($moved),
        );

        $this->flushChecks();
    }

    /**
     * 声明了 `keyboard()` 但**此刻不吃**这个方向键（`ComboBox` 面板关着）⇒ 仍然不逃。
     *
     * ★ 这条钉的是 `dispatchKey()` 里的 `$declared` 门控。没有它，"认领键盘"就只等于
     *   "认得这个键"，`Select` 面板关着返回 `false` 的语义（让位给框架的**激活**，
     *   不是让位给"焦点跑掉"）会被方向键接管 —— 下拉里按 ↓ 焦点就跑到下一个控件。
     * ★ 成对判据：同一个 ↓ 从上面的按钮按会移动（证明下方**有**候选），
     *   从下拉按不移动 —— 否则"没有候选"也能让这条绿。
     */
    public function testClosedComboDoesNotLeakArrows(): void
    {
        $app = $this->app(Column::make()->children([
            Button::make('A'),
            ComboBox::make(['110000' => '北京市', '310000' => '上海市']),
            Button::make('B'),
        ]));
        $focus = $app->focus();
        $focus?->focusNext();

        // 对照组：从 A 按 ↓ 能到下拉（下拉内部的可聚焦件，焦点真的进来了）
        $focus?->dispatchKey(KeyEvent::down(KeyChord::down()));
        $inside = $focus?->focused();
        check(
            '键盘归属 · 成对：A 按 Down 能进到下拉（下方确有候选）',
            $inside !== null && $inside !== $this->labelOf($app, 'A') && $inside !== $this->labelOf($app, 'B'),
            $inside === null ? 'null' : $inside->widget()::class,
        );

        $focus?->dispatchKey(KeyEvent::down(KeyChord::down()));
        check(
            '键盘归属 · 下拉面板关着 ⇒ Down 不把焦点带走（`$declared` 门控）',
            $focus?->focused() === $inside,
            'at=' . $this->where($focus),
        );

        $this->flushChecks();
    }

    /**
     * 同一行里**稍高的兄弟**不许截走方向键（§58）。
     *
     * ★ 实测的形状（树的每一行）：`[折叠箭头 42 宽/42 高] [标签 32.8 高]`，都垂直居中
     *   ⇒ 箭头与标签在横轴上**贴边**、在纵轴上**重叠** ⇒ 按 ↓ 时"下一行的标签"与
     *   "本行的箭头"同分，树序靠前者（箭头）胜 ⇒ **每个分支行都下不去**，按 ↑ 反过来
     *   又落到下一行的箭头。修法：副轴**贴边**按中心距计（`crossScore()`），重叠仍是 0。
     * ★ 判据必须用 `IconButton` + `Expanded`：`Button` 之间高度相同、又不会分泳道，
     *   测的是一个不存在的布局。
     */
    public function testTallerSiblingDoesNotStealArrowDirection(): void
    {
        $app = $this->app(Column::make()->children([
            Row::make()->children([
                IconButton::of(IconName::ArrowRight, '展开'),
                Expanded::make(Button::make('A')),
            ]),
            Row::make()->children([
                SizedBox::make()->width(42.0),
                Expanded::make(Button::make('B')),
            ]),
        ]));
        $focus = $app->focus();
        $focus?->focus($this->labelOf($app, 'A'));

        check('贴边分层 · 起手是 A', $this->where($focus) === 'A', $this->where($focus));

        $focus?->dispatchKey(KeyEvent::down(KeyChord::down()));
        check(
            '贴边分层 · ↓ 到下一行的 B（不是左侧贴边的箭头）',
            $this->where($focus) === 'B',
            $this->where($focus),
        );

        $focus?->dispatchKey(KeyEvent::down(KeyChord::up()));
        check(
            '贴边分层 · ↑ 回上一行的 A（不是同一泳道的箭头）',
            $this->where($focus) === 'A',
            $this->where($focus),
        );

        $this->flushChecks();
    }

    /**
     * `keyboard()` 的三态回答（§58）：`Declined` 交回框架导航，`false` 仍归自己。
     *
     * ★ 两条必须成对：只钉 `Declined` 会漏"`false` 变成了 `Declined`"（`Select` 面板
     *   关着按 ↓ 焦点就跑了），只钉 `false` 会漏"`Declined` 没生效"（树按 ↓ 不动）。
     */
    public function testDeclinedHandsTheKeyBackToTheFramework(): void
    {
        $declined = $this->app(Column::make()
            ->keyboard(static fn(KeyEvent $e): KeyResult => KeyResult::Declined)
            ->children([Button::make('A'), Button::make('B')]));
        $f1 = $declined->focus();
        $f1?->focus($this->labelOf($declined, 'A'));
        $f1?->dispatchKey(KeyEvent::down(KeyChord::down()));
        check(
            '三态 · `Declined` ⇒ 交回几何导航（A 按 ↓ 到 B）',
            $this->where($f1) === 'B',
            $this->where($f1),
        );

        $owned = $this->app(Column::make()
            ->keyboard(static fn(KeyEvent $e): KeyResult => KeyResult::Owned)
            ->children([Button::make('A'), Button::make('B')]));
        $f2 = $owned->focus();
        $f2?->focus($this->labelOf($owned, 'A'));
        $f2?->dispatchKey(KeyEvent::down(KeyChord::down()));
        check(
            '三态 · `Owned` ⇒ 键仍归自己（A 按 ↓ 不动）',
            $this->where($f2) === 'A',
            $this->where($f2),
        );

        // 老的 `bool` 声明一条都不用改：`false` ≡ `Owned`
        $legacy = $this->app(Column::make()
            ->keyboard(static fn(KeyEvent $e): bool => false)
            ->children([Button::make('A'), Button::make('B')]));
        $f3 = $legacy->focus();
        $f3?->focus($this->labelOf($legacy, 'A'));
        $f3?->dispatchKey(KeyEvent::down(KeyChord::down()));
        check(
            '三态 · `false` 仍是 `Owned`（存量声明语义不变）',
            $this->where($f3) === 'A',
            $this->where($f3),
        );

        $this->flushChecks();
    }

    /**
     * 焦点空着时：**陷阱内**（浮层）方向键落到链首，主树不猜。
     *
     * ★ 浮层既然声明了焦点陷阱，焦点空着就只能靠 Tab 进；给方向键第二个入口是
     *   划算的（菜单一开就能上下走）。主树里"按一下键焦点凭空出现"是意外，不做。
     */
    public function testEmptyFocusEntryOnlyInsideTrap(): void    {
        $root = Column::make()->children([Button::make('A'), Button::make('B')])->createElement();
        $root->mount(null, smokeContext());

        $trapped = new FocusManager();
        $trapped->collect([$root], [$root]);
        $ate = $trapped->dispatchKey(KeyEvent::down(KeyChord::down()));
        check(
            '空焦点 · 陷阱内 Down ⇒ 落到链首 A',
            $ate && $this->where($trapped) === 'A',
            'ate=' . var_export($ate, true) . ' at=' . $this->where($trapped),
        );

        $loose = new FocusManager();
        $loose->collect([$root]);
        $ate = $loose->dispatchKey(KeyEvent::down(KeyChord::down()));
        check(
            '空焦点 · 主树不猜：不吃键、焦点仍为空',
            $ate === false && $loose->focused() === null,
            'ate=' . var_export($ate, true) . ' at=' . $this->where($loose),
        );

        $this->flushChecks();
    }
}
