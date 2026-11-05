<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\Dev\Smoke\App\FocusRingApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Rendering\Color;

/**
 * 焦点环（W8-5 续作五）：应用级叠加绘制 + `wantsFrame()` 的**第七判据**。
 *
 * ## 两个面，两个方法
 *
 * | 方法 | 面 | 坏法 |
 * |---|---|---|
 * | `testTabFocusRequestsFrameAndPaintsRing` | 键盘 Tab ⇒ 环出现 | 环不画 / 画了但要等下次输入 |
 * | `testPointerClickHidesRingAndRequestsFrame` | 指针点击 ⇒ 环消失 | 蓝框留在鼠标操作后（`:focus-visible` 的全部意义） |
 *
 * ## 第七判据为什么必须单独钉
 *
 * 焦点环是**应用级叠加**（`paintFocusRing`），不经过任何 `setState` ⇒ 没有
 * `wantsFrame()` 里那条 `focusRingTarget() !== paintedFocus`，键盘 Tab 换焦点后
 * 环要等**下一次鼠标移动**才出现。这是真机现象，**smoke 因为显式跑两帧看不见**
 * —— 所以"跑两帧后像素对"与"框架自己知道该跑一帧"必须分开断言：
 * 前者是 `③`，后者是 `①`。
 *
 * ★ 比的是 `focusRingTarget()` 而不是 `focused()`：指针模态下目标恒 `null`
 *   ⇒ 点击隐藏环也能立刻触发一帧（否则点击后环要等下次输入才消失，即 `⑥`）。
 *
 * ## 采样点为什么不是盒的左上角
 *
 * `FocusRingApplication` = `Column(padding 20)` + `Button` ⇒ 盒原点 (20,20)，焦点环是
 * 外扩 1px 的 2px 描边 ⇒ 上边覆盖 y=19,20。但 Button 圆角是主题 `Radius`（6）：
 * 圆角环在**直角顶点 (19,19) 不吃色**、弧线 (23,19) 才吃色（与 §49 牙 C 同形）。
 * 所以「环画出来了吗」用上边中点 (60,19) 量；「是不是圆角」由 (19,19)/(23,19)
 * 成对钉住 —— 只写"顶点不变"会把"整个角都空着"的实现判绿。不依赖字体度量。
 *
 * ★ 另两个采样点各钉一条会静默假绿的变异：`(21,20)` 在同心外扩（圆角 +1）下
 *   不吃色、去掉 +1 就吃色；`(60,30)` 是盒心，描边笔换成填充笔会把它染成 Accent。
 *
 * ## 与 smoke 的一处实质改进
 *
 * smoke 把 6 条写在一个顺序脚本里：`⑤`（点击后不是 Accent）**隐式依赖** 200 行外的
 * `③`（Tab 后是 Accent）已经证明"环画出来了"。拆成独立方法后这个前提没了 ——
 * 若环从来没画出来过，"点击后不是 Accent"就是**恒真**的。所以 `testPointerClick…`
 * 自己先断言一次"点击前是描边"，把隐式依赖显式化。
 */
final class FocusRingTest extends SmokeTestCase
{
    private const W = 300.0;

    private const H = 200.0;

    /** 上边中点：圆角环下仍必定吃色 */
    private const PROBE_X = 60;

    private const PROBE_Y = 19;

    /** 直角顶点 / 弧线：成对判据 */
    private const CORNER_X = 19;

    private const CORNER_Y = 19;

    private const ARC_X = 23;

    private const ARC_Y = 19;

    /** 弧线内侧 / 盒心：同心外扩与"描边不是填充"的哨兵 */
    private const ARC_INNER_X = 21;

    private const ARC_INNER_Y = 20;

    private const CENTER_X = 60;

    private const CENTER_Y = 30;

    private static function constraints(): BoxConstraints
    {
        return BoxConstraints::tight(new Size(self::W, self::H));
    }

    /** 起一个已跑过首帧的 app（首帧之后才有画布可读） */
    private static function open(): array
    {
        $app = new FocusRingApplication();
        $probe = new SurfaceProbe();
        $probe->resize((int) self::W, (int) self::H, 1.0);
        $app->attach($probe, self::constraints());
        $app->frame(0.0, self::constraints());

        return [$app, $probe];
    }

    /** 主题的 Accent 色（焦点环用它描边） */
    private static function accent(): Color
    {
        return Color::fromRgb(0x09, 0x69, 0xDA);
    }

    private static function pixel(?SurfaceProbe $probe, int $x = self::PROBE_X, int $y = self::PROBE_Y): ?Color
    {
        return $probe->raster()?->pixelAt($x, $y);
    }

    /**
     * Tab 聚焦 ⇒ ① 框架自己请求一帧、② 之前无焦点时不画、③ 下一帧画出 Accent 描边、
     * ④ 画完立刻回落（判据有界）。
     *
     * ★ ①②③④ 缺一不可：
     *   只验 ③ 会奖励"每帧无条件画环"（无焦点也画，`②` 才抓得住）；
     *   只验 ①③ 会奖励"`wantsFrame()` 恒为真"（`④` 才抓得住）；
     *   只验 ③④ 会奖励"环要等下次输入才出现"（`①` 才抓住）。
     * ★★ ② 的**诚实边界**：它验的是"首帧不画"。此时不画由两道条件共同保证 ——
     *   `keyboardFocusVisible === false`（还没输入过）**和** `focused() === null`。
     *   想单独验"键盘模态已激活但焦点为空 ⇒ 不画"这个组合，当前**造不出来**：
     *   `FocusManager::focus()` 只收 `Element`、没有 `blur()`/`clear()`，公开 API
     *   无法在键盘模态下清空焦点。这是**能力缺口，不是判据缺口** —— 所以标题
     *   只承诺"首帧不画"，不写"无焦点不画"。
     *   能打红 ② 的现实变异是"忽略模态、无焦点就画在第一个可聚焦控件上"（第 32 批 M2）。
     */
    public function testTabFocusRequestsFrameAndPaintsRing(): void
    {
        [$app, $probe] = self::open();

        $before = self::pixel($probe);
        $app->dispatchInput(KeyEvent::down(KeyChord::tab()));
        $wantsAfterTab = $app->wantsFrame();

        $app->frame(1.0, self::constraints());
        $after = self::pixel($probe);
        $afterCorner = self::pixel($probe, self::CORNER_X, self::CORNER_Y);
        $afterArc = self::pixel($probe, self::ARC_X, self::ARC_Y);
        $arcInner = self::pixel($probe, self::ARC_INNER_X, self::ARC_INNER_Y);
        $center = self::pixel($probe, self::CENTER_X, self::CENTER_Y);

        check(
            '焦点环 · 焦点变了但还没画 ⇒ wantsFrame 为真（第七判据；否则环要等下次输入）',
            $wantsAfterTab === true,
            'wantsFrame=' . ($wantsAfterTab ? 'y' : 'n')
        );
        check(
            '焦点环 · 首帧（无输入、无焦点）(19,19) 不是描边（不是"一启动就画"）',
            $before !== null && !$before->equals(self::accent()),
            'rgb=' . describeColor($before)
        );
        check(
            '焦点环 · Tab 聚焦后上边中点是 Accent 描边',
            $after !== null && $after->equals(self::accent()),
            'rgb=' . describeColor($after)
        );
        check(
            '焦点环 · 直角顶点 (19,19) 不吃色（环跟随控件圆角，不是方环）',
            $afterCorner !== null && !$afterCorner->equals(self::accent()),
            'rgb=' . describeColor($afterCorner)
        );
        check(
            '焦点环 · 弧线 (23,19) 吃色（圆角处真有描边，不是整个角都空着）',
            $afterArc !== null && $afterArc->equals(self::accent()),
            'rgb=' . describeColor($afterArc)
        );
        check(
            '焦点环 · 弧线内侧 (21,20) 不吃色（圆角做了 +1 同心外扩）',
            $arcInner !== null && !$arcInner->equals(self::accent()),
            'rgb=' . describeColor($arcInner)
        );
        check(
            '焦点环 · 盒心 (60,30) 不是 Accent（只描边，没有把盒填掉）',
            $center !== null && !$center->equals(self::accent()),
            'rgb=' . describeColor($center)
        );
        check(
            '焦点环 · 画完后悔回落（wantsFrame 的第七判据有界）',
            $app->wantsFrame() === false,
            'wantsFrame=' . ($app->wantsFrame() ? 'y' : 'n')
        );
    }

    /**
     * 指针点击 ⇒ 环消失（`:focus-visible`），且"隐藏"本身也请求一帧。
     *
     * ★ 前置断言"点击前是描边"不是重复：没有它，环从来没画出来过时
     *   "点击后不是描边"就是恒真的（见类头注）。
     * ★ ⑥ 单独存在：环消失靠的是"目标变 null"而不是 `setState`，若不请求一帧，
     *   用户点完鼠标还要晃一下才看到蓝框消失。
     */
    public function testPointerClickHidesRingAndRequestsFrame(): void
    {
        [$app, $probe] = self::open();

        // 前置：先 Tab 聚焦，让环真的画出来（否则下面那条恒真）
        $app->dispatchInput(KeyEvent::down(KeyChord::tab()));
        $app->frame(1.0, self::constraints());
        $ringOn = self::pixel($probe);
        check(
            '焦点环 · 前置：Tab 聚焦后环已画出（否则"点击后消失"恒真）',
            $ringOn !== null && $ringOn->equals(self::accent()),
            'rgb=' . describeColor($ringOn)
        );

        // 点 (5,5)：落在 padding 区（Button 原点 20,20）⇒ 不进按钮，但足以切到指针模态
        $app->dispatchInput(PointerEvent::down(5.0, 5.0));
        $wantsAfterClick = $app->wantsFrame();
        $app->frame(2.0, self::constraints());
        $ringOff = self::pixel($probe);

        check(
            '焦点环 · 指针点击后隐藏（:focus-visible —— 鼠标操作后不该再看到蓝框）',
            $ringOff !== null && !$ringOff->equals(self::accent()),
            'rgb=' . describeColor($ringOff)
        );
        check(
            '焦点环 · "隐藏"本身也请求一帧（目标 null，不靠下次输入才消失）',
            $wantsAfterClick === true,
            'wantsFrame=' . ($wantsAfterClick ? 'y' : 'n')
        );
    }
}
