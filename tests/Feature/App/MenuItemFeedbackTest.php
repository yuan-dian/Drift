<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\Application;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderMenuItem;
use Drift\Rendering\Color;
use Drift\Widget\Column;
use Drift\Widget\Menu\MenuItem;
use Drift\Widget\Widget;

/**
 * 菜单项的两相位反馈（§DESIGN-INTERACTION-FEEDBACK-2026-11-05）。
 *
 * ★ `MenuItem extends Button` 却换成 `RenderMenuItem`，`Button` 解析出的两档状态色
 *   没跟过去 ⇒ 点下去**逐像素静默**。本文件的骨干是同一棵树上的三帧对照，
 *   三者两两不同；只断"变了"会让"两层写成同一个色"也绿。
 *
 * ★ 方向判据不接受"任意变化"：亮色主题 Hover = 0xECF0F5、Pressed = 0xDFE6EF
 *   （`ColorToken.php:70-71`），按下必须更暗。先证明**真的采到了**纯状态层区，
 *   再要求这些点全部变暗 ⇒ 两色写反 / 层次写反都会红。
 */
final class MenuItemFeedbackTest extends SmokeTestCase
{
    private const W = 240.0;

    private const H = 120.0;

    private const HOVER_KEY = '236/240/245';

    private static function constraints(): BoxConstraints
    {
        return BoxConstraints::tight(new Size(self::W, self::H));
    }

    /** @return array{0: Application, 1: SurfaceProbe} */
    private function open(Widget $item): array
    {
        // 用 setter 传根而不用构造器：匿名子类自建构造必须调 parent::__construct()
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
        $app->setRoot(Column::make()->paddingAll(20.0)->children([$item]));
        $probe = new SurfaceProbe();
        $probe->resize((int) self::W, (int) self::H, 1.0);
        $app->attach($probe, self::constraints());
        $app->frame(0.0, self::constraints());

        return [$app, $probe];
    }

    /** @return list<string> */
    private static function shot(Application $app, SurfaceProbe $probe, float $ts): array
    {
        $app->frame($ts, self::constraints());

        $raster = $probe->raster();
        if ($raster === null) {
            return [];
        }

        $pixels = [];
        for ($y = 0; $y < (int) self::H; $y += 2) {
            for ($x = 0; $x < (int) self::W; $x += 2) {
                $c = $raster->pixelAt($x, $y);
                $pixels[] = $c->r . '/' . $c->g . '/' . $c->b;
            }
        }

        return $pixels;
    }

    private static function diffPixels(array $a, array $b): int
    {
        if ($a === [] || \count($a) !== \count($b)) {
            return -1;
        }

        $changed = 0;
        foreach ($a as $i => $v) {
            if ($v !== $b[$i]) {
                ++$changed;
            }
        }

        return $changed;
    }

    private function settle(Application $app, float $from): void
    {
        for ($i = 1; $i <= 40; $i++) {
            $app->frame($from + $i * 16.0, self::constraints());
        }
    }

    private function menuBox(Application $app): RenderMenuItem
    {
        $box = $app->root()?->findBoxOf(RenderMenuItem::class);

        return $box instanceof RenderMenuItem ? $box : throw new \LogicException('菜单项盒没找到');
    }

    /** 三帧必须来自同一棵树、同一份主题 —— 分开建树会把"主题解析两次"混进差异 */
    public function testTwoPhaseFeedbackAndDirection(): void
    {
        [$app, $probe] = $this->open(MenuItem::make('菜单项'));
        $base = self::shot($app, $probe, 1000.0);

        $box = $this->menuBox($app);
        $box->setHovered(true);
        $this->settle($app, 1000.0);
        $hover = self::shot($app, $probe, 2000.0);

        $box->setPressed(true);
        $this->settle($app, 2000.0);
        $press = self::shot($app, $probe, 3000.0);

        $hoverVsBase = self::diffPixels($base, $hover);
        $pressVsBase = self::diffPixels($base, $press);
        $pressVsHover = self::diffPixels($hover, $press);

        check('菜单项反馈 · 悬停相对原色有变化', $hoverVsBase > 0, '变化像素=' . $hoverVsBase);
        check('菜单项反馈 · 按下相对原色有变化（修复前一个都不变）', $pressVsBase > 0, '变化像素=' . $pressVsBase);
        check('菜单项反馈 · 按下与悬停不是同一幅画', $pressVsHover > 0, '变化像素=' . $pressVsHover);

        $sampled = 0;
        $darker = 0;
        foreach ($hover as $i => $v) {
            if ($v !== self::HOVER_KEY) {
                continue;   // 只认状态层完整覆盖、且没有文字的点
            }
            ++$sampled;
            [$r, $g, $b] = \array_map('intval', \explode('/', $press[$i]));
            if ($r + $g + $b < 236 + 240 + 245) {
                ++$darker;
            }
        }

        check('菜单项反馈 · 真的采到状态层覆盖区（否则方向判据是空的）', $sampled > 0, '采样点=' . $sampled);
        // ★ 必须带 `$sampled > 0`：都为 0 时 `$darker === $sampled` 恒真
        check(
            '菜单项反馈 · Pressed 比 Hover 深一档',
            $sampled > 0 && $darker === $sampled,
            '变暗 ' . $darker . '/' . $sampled,
        );

        $this->flushChecks();
    }

    /**
     * 用户声明的盒样式必须真的画出来。
     *
     * ★ `MenuItem` 继承 `Button::paintsBoxChrome() === true`，但 `RenderMenuItem` 并不
     *   自绘 chrome ⇒ `Element::syncBox()` 把 `background` 剥掉，用户的 `->background()`
     *   **静默失效**。这条判据钉的是像素，不是"声明对不对"。
     * ★ 成对：同一个采样点上，不带 `background()` 的菜单项必须**不是**这个色 ——
     *   否则"整块画布本来就是绿的"也能绿。
     */
    public function testUserBackgroundReachesPixels(): void
    {
        $mark = new Color(10, 200, 30);

        [$plain, $plainProbe] = $this->open(MenuItem::make('菜单项'));
        $box = $this->menuBox($plain);
        $size = $box->size();
        if ($size === null) {
            throw new \LogicException('菜单项没排过版');
        }

        [$ox, $oy] = boxOrigin($box);
        $x = (int) ($ox + $size->width - 3.0);
        $y = (int) ($oy + $size->height / 2.0);

        $before = $plainProbe->raster()?->pixelAt($x, $y);

        [$painted, $paintedProbe] = $this->open(MenuItem::make('菜单项')->background($mark));
        $after = $paintedProbe->raster()?->pixelAt($x, $y);

        check(
            '菜单项样式 · 不带 background ⇒ 采样点不是那个色（成对前提）',
            $before !== null && !($before->r === 10 && $before->g === 200 && $before->b === 30),
            describeColor($before),
        );
        check(
            '菜单项样式 · ->background() 真的画到像素上（修复前被 syncBox 剥掉）',
            $after !== null && $after->r === 10 && $after->g === 200 && $after->b === 30,
            describeColor($after),
        );

        $this->flushChecks();
    }

    /** 未声明反馈色的盒：推态只标脏，状态层一条命令都不发 */
    public function testUndeclaredBoxPaintsNoFeedback(): void
    {
        [$app, $probe] = $this->open(MenuItem::make('菜单项'));
        $base = self::shot($app, $probe, 1000.0);

        $rootBox = $app->root()?->effectiveBox();
        if (!$rootBox instanceof RenderBox) {
            throw new \LogicException('根盒没找到');
        }

        check('未声明反馈 · 根盒不接受悬停', !$rootBox->acceptsHover());
        check('未声明反馈 · 根盒不接受按下', !$rootBox->acceptsPress());

        $rootBox->setHovered(true);
        $rootBox->setPressed(true);
        $this->settle($app, 1000.0);
        $after = self::shot($app, $probe, 3000.0);

        $diff = self::diffPixels($base, $after);
        check('未声明反馈 · 推态之后像素零变化（状态层一条命令都不发）', $diff === 0, '变化像素=' . $diff);

        $this->flushChecks();
    }

    /** 声明是成对的；分隔线不可点 ⇒ 两相位都不该接 */
    public function testDeclarationIsPairwise(): void
    {
        [$app] = $this->open(MenuItem::make('菜单项'));
        $box = $this->menuBox($app);
        check('声明成对 · 普通菜单项接受悬停', $box->acceptsHover());
        check('声明成对 · 普通菜单项接受按下', $box->acceptsPress());

        [$sepApp] = $this->open(MenuItem::separator());
        $separator = $this->menuBox($sepApp);
        check('声明成对 · 分隔线是不可点的', $separator->isSeparator());
        check('声明成对 · 分隔线不接受悬停', !$separator->acceptsHover());
        check('声明成对 · 分隔线不接受按下', !$separator->acceptsPress());

        $this->flushChecks();
    }
}
