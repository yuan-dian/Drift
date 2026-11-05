<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\App\Application;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderTextArea;
use Drift\Layout\RenderTextField;
use Drift\Widget\Column;
use Drift\Widget\TextArea;
use Drift\Widget\TextField;
use Drift\Widget\Widget;

/**
 * 输入域的两相位反馈（`DEBT §49`）。
 *
 * ★ 输入井的底是 `Pane`，与主题 `Hover` 只差几级 ⇒ 别的控件那套**填充叠色**在亮
 *   主题下看不见，且 hover 变亮 / press 变暗与按钮的递进相反。所以这里的形状是
 *   **边框描边**：判据必须钉住「内部像素一个都不变」，只断"变了没有"的测试，
 *   把描边退回整盒填充也全绿。
 * ★ 两档色从**当前边框色**派生，`error()` 态的红不能被指针状态盖成灰。
 */
final class TextFieldFeedbackTest extends SmokeTestCase
{
    private const W = 320;

    private const H = 200;

    private static function constraints(): BoxConstraints
    {
        return BoxConstraints::tight(new Size(self::W, self::H));
    }

    /**
     * @param list<Widget> $children
     * @return array{0: Application, 1: SurfaceProbe}
     */
    private function open(array $children): array
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
        $app->setRoot(Column::make()->paddingAll(20.0)->children($children));
        $probe = new SurfaceProbe();
        $probe->resize(self::W, self::H, 1.0);
        $app->attach($probe, self::constraints());
        $app->frame(0.0, self::constraints());

        return [$app, $probe];
    }

    /** @return list<string> 逐像素，索引 = y * W + x */
    private static function shot(Application $app, SurfaceProbe $probe, float $ts): array
    {
        $app->frame($ts, self::constraints());

        $raster = $probe->raster();
        if ($raster === null) {
            return [];
        }

        $pixels = [];
        for ($y = 0; $y < self::H; $y++) {
            for ($x = 0; $x < self::W; $x++) {
                $c = $raster->pixelAt($x, $y);
                $pixels[] = $c->r . '/' . $c->g . '/' . $c->b;
            }
        }

        return $pixels;
    }

    private function settle(Application $app, float $from): void
    {
        for ($i = 1; $i <= 40; $i++) {
            $app->frame($from + $i * 16.0, self::constraints());
        }
    }

    private static function at(array $shot, int $x, int $y): string
    {
        return $shot[$y * self::W + $x] ?? '-';
    }

    /** 颜色距离：两档递进用「离原色多远」量，不猜亮/暗方向。 */
    private static function distance(string $a, string $b): float
    {
        [$ar, $ag, $ab] = \array_map('intval', \explode('/', $a));
        [$br, $bg, $bb] = \array_map('intval', \explode('/', $b));

        return (float) (\abs($ar - $br) + \abs($ag - $bg) + \abs($ab - $bb));
    }

    private static function redness(string $key): int
    {
        [$r, $g, $b] = \array_map('intval', \explode('/', $key));

        return $r - \max($g, $b);
    }

    /** 上边框中点（避开圆角）；两档色与错误态都在这里采样。 */
    private static function borderAt(RenderBox $box): array
    {
        [$ox, $oy] = \Drift\Layout\RenderBox::absoluteOrigin($box);
        $size = $box->size();

        // 盒原点可以是小数（多行框跟在单行后面 ⇒ 44.8）；描边落在四舍五入后的那一行。
        return [(int) $ox + (int) (($size?->width ?? 0.0) / 2.0), (int) \round($oy)];
    }

    /** 单行：反馈是描边，且两档递进。 */
    public function testSingleLineFeedbackIsAStrokeAndProgresses(): void
    {
        [$app, $probe] = $this->open([TextField::make()->value('hello')]);
        $box = $app->root()?->findBoxOf(RenderTextField::class);
        if (!$box instanceof RenderTextField) {
            throw new \LogicException('输入井盒没找到');
        }

        [$ox, $oy] = \Drift\Layout\RenderBox::absoluteOrigin($box);
        $size = $box->size();
        if ($size === null) {
            throw new \LogicException('输入井没排过版');
        }

        check(
            '声明 · 输入井接了两种状态（否则悬停根本不推进）',
            $box->acceptsHover() && $box->acceptsPress(),
            'hover=' . ($box->acceptsHover() ? 'Y' : 'N') . ' press=' . ($box->acceptsPress() ? 'Y' : 'N'),
        );

        $base = self::shot($app, $probe, 1000.0);
        $box->setHovered(true);
        $this->settle($app, 1000.0);
        $hover = self::shot($app, $probe, 2000.0);
        $box->setPressed(true);
        $this->settle($app, 2000.0);
        $press = self::shot($app, $probe, 3000.0);

        // ★ 照妖镜：整盒填充会连盒心的底色一起换掉。
        $cx = (int) $ox + (int) ($size->width / 2.0);
        $cy = (int) $oy + (int) ($size->height / 2.0);
        check(
            '形状 · 盒心一个像素都不变（退回整盒填充会红）',
            self::at($base, $cx, $cy) === self::at($hover, $cx, $cy),
            '盒心 base=' . self::at($base, $cx, $cy) . ' hover=' . self::at($hover, $cx, $cy),
        );

        // ★ 圆角照妖镜：radius 从自持 BoxStyle 取（6），弧线会穿过左上角 1..4 这片，
        //   而直角顶点本身在圆外 ⇒ 不变色。radius 传 0 时恰好反过来（顶点被染、弧线消失）。
        $cornerChanged = 0;
        for ($dy = 1; $dy <= 4; $dy++) {
            for ($dx = 1; $dx <= 4; $dx++) {
                if (self::at($base, (int) $ox + $dx, (int) $oy + $dy)
                    !== self::at($hover, (int) $ox + $dx, (int) $oy + $dy)) {
                    ++$cornerChanged;
                }
            }
        }
        check(
            '形状 · 直角顶点不吃状态层（radius=0 会红）',
            self::at($base, (int) $ox, (int) $oy) === self::at($hover, (int) $ox, (int) $oy),
            'base=' . self::at($base, (int) $ox, (int) $oy)
                . ' hover=' . self::at($hover, (int) $ox, (int) $oy),
        );
        check(
            '形状 · 状态层沿圆角弧走（radius=0 会红）',
            $cornerChanged > 0,
            '角区变化像素=' . $cornerChanged,
        );

        [$bx, $by] = self::borderAt($box);
        $b0 = self::at($base, $bx, $by);
        $b1 = self::at($hover, $bx, $by);
        $b2 = self::at($press, $bx, $by);
        check(
            '边框 · 悬停真的变了色',
            self::distance($b0, $b1) > 20.0,
            'base=' . $b0 . ' hover=' . $b1,
        );
        check(
            '两档 · Pressed 比 Hover 离原色更远（写反会红）',
            self::distance($b0, $b2) > self::distance($b0, $b1) + 20.0,
            'd(hover)=' . self::distance($b0, $b1) . ' d(press)=' . self::distance($b0, $b2),
        );

        $this->flushChecks();
    }

    /** 成对：两个输入域都接了，缺一个就有一条红。 */
    public function testBothInputKindsAcceptHover(): void
    {
        [$app, $probe] = $this->open([
            TextField::make()->value('one'),
            TextArea::make()->value('two'),
        ]);

        $base = self::shot($app, $probe, 1000.0);
        $one = $app->root()?->findBoxOf(RenderTextField::class);
        $many = $app->root()?->findBoxOf(RenderTextArea::class);
        if (!$one instanceof RenderTextField || !$many instanceof RenderTextArea) {
            throw new \LogicException('两个输入井盒没找齐');
        }

        $one->setHovered(true);
        $many->setHovered(true);
        $this->settle($app, 1000.0);
        $hover = self::shot($app, $probe, 2000.0);

        foreach (['单行' => $one, '多行' => $many] as $name => $box) {
            [$bx, $by] = self::borderAt($box);
            check(
                '成对 · ' . $name . ' 的边框吃到了悬停',
                self::distance(self::at($base, $bx, $by), self::at($hover, $bx, $by)) > 20.0,
                'base=' . self::at($base, $bx, $by) . ' hover=' . self::at($hover, $bx, $by),
            );
        }

        $this->flushChecks();
    }

    /** 错误态：反馈色从当前边框派生，红信号不能被指针状态盖掉。 */
    public function testErrorBorderStaysRedUnderHover(): void
    {
        [$app, $probe] = $this->open([TextField::make()->value('x')->error('必填')]);
        $box = $app->root()?->findBoxOf(RenderTextField::class);
        if (!$box instanceof RenderTextField) {
            throw new \LogicException('输入井盒没找到');
        }

        [$bx, $by] = self::borderAt($box);
        $base = self::shot($app, $probe, 1000.0);
        $b0 = self::at($base, $bx, $by);
        check('错误态 · 静止边框是红的', self::redness($b0) > 80, 'base=' . $b0);

        $box->setHovered(true);
        $this->settle($app, 1000.0);
        $hover = self::shot($app, $probe, 2000.0);
        $b1 = self::at($hover, $bx, $by);

        check('错误态 · 悬停仍有反馈', self::distance($b0, $b1) > 10.0, 'base=' . $b0 . ' hover=' . $b1);
        check(
            '错误态 · 悬停后仍是红色系（用固定主题色会红）',
            self::redness($b1) > 60,
            'hover=' . $b1 . ' 红度=' . self::redness($b1),
        );

        $this->flushChecks();
    }
}
