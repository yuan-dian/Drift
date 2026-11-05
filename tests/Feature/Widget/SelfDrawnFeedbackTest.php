<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\App\Application;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderSegment;
use Drift\Layout\RenderSlider;
use Drift\Layout\RenderText;
use Drift\Layout\RenderToggle;
use Drift\Widget\Checkbox;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\SegmentedControl;
use Drift\Widget\Slider;
use Drift\Widget\Widget;

/**
 * 自绘控件的两相位反馈（`DEBT §48`）。
 *
 * ★ 三个控件的**形状都不是盒矩形**：开关只该罩本体、分段只该罩药丸、滑块只该罩
 *   拇指。所以本文件的骨干判据不是"变了没有"，而是**变在哪**——把三条的
 *   `paintInteraction()` 退回基类（整盒矩形），每一处都会有人喊"越界了"。
 * ★ 分段那条还带**方向**：选中段底色是 Accent ⇒ 叠白（变亮），未选中段压在
 *   轨道上 ⇒ 叠灰（变暗）。同一个"悬停"在两个底色上必须是两个方向，
 *   写死一个色就有一半红。
 */
final class SelfDrawnFeedbackTest extends SmokeTestCase
{
    private const W = 320;

    private const H = 160;

    private static function constraints(): BoxConstraints
    {
        return BoxConstraints::tight(new Size(self::W, self::H));
    }

    /** @return array{0: Application, 1: SurfaceProbe} */
    private function open(Widget $root): array
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
        $app->setRoot(Column::make()->paddingAll(20.0)->children([$root]));
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

    private static function lum(string $key): int
    {
        [$r, $g, $b] = \array_map('intval', \explode('/', $key));

        return $r + $g + $b;
    }

    /**
     * 变化的像素落在哪 —— `[minX, minY, maxX, maxY, 数量]`（空 = 全没变）。
     *
     * ★ 只此一个几何判据就够钉住"层画在谁的形状上"：整盒矩形会把 maxX 拉到
     *   容器右缘，什么都不画则数量为 0。
     *
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int}
     */
    private static function changedBounds(array $base, array $changed): array
    {
        $minX = self::W;
        $minY = self::H;
        $maxX = -1;
        $maxY = -1;
        $count = 0;

        foreach ($base as $i => $v) {
            if ($v === $changed[$i]) {
                continue;
            }
            $x = $i % self::W;
            $y = \intdiv($i, self::W);
            $minX = \min($minX, $x);
            $minY = \min($minY, $y);
            $maxX = \max($maxX, $x);
            $maxY = \max($maxY, $y);
            ++$count;
        }

        return [$minX, $minY, $maxX, $maxY, $count];
    }

    /** 变化区的平均亮度：按下必须比悬停深一档 */
    private static function meanLumOfChanged(array $base, array $changed): float
    {
        $sum = 0;
        $n = 0;
        foreach ($base as $i => $v) {
            if ($v === $changed[$i]) {
                continue;
            }
            $sum += self::lum($changed[$i]);
            ++$n;
        }

        return $n === 0 ? 0.0 : $sum / $n;
    }

    /** 开关：光晕只罩控件本体，绝不铺到标签上 */
    public function testToggleHaloStopsAtTheLabel(): void
    {
        [$app, $probe] = $this->open(Checkbox::make('记住我')->onChanged(static function (bool $v): void {
        }));

        $rootElement = $app->root();
        $box = $rootElement?->findBoxOf(RenderToggle::class);
        if (!$box instanceof RenderToggle) {
            throw new \LogicException('开关盒没找到');
        }

        // 标签是**渲染孩子**（`RenderToggle` 自己 new 的），没有对应的 Element ⇒
        // 只能在渲染树上拿。它的左缘就是"光晕不许越过"的那条线。
        $labelBox = null;
        foreach (childBoxes($box) as $child) {
            if ($child instanceof RenderText) {
                $labelBox = $child;

                break;
            }
        }
        if ($labelBox === null) {
            throw new \LogicException('标签盒没找到');
        }

        [$ox] = boxOrigin($box);
        [$labelX] = boxOrigin($labelBox);

        $base = self::shot($app, $probe, 1000.0);
        $box->setHovered(true);
        $this->settle($app, 1000.0);
        $hover = self::shot($app, $probe, 2000.0);
        $box->setPressed(true);
        $this->settle($app, 2000.0);
        $press = self::shot($app, $probe, 3000.0);

        [$minX, , $maxX, , $count] = self::changedBounds($base, $hover);

        check('开关反馈 · 悬停真的画了东西', $count > 0, '变化像素=' . $count);
        check(
            '开关反馈 · 变化区从控件左侧外扩（不是从盒左缘起）',
            $count > 0 && $minX < (int) $ox,
            'minX=' . $minX . ' ox=' . (int) $ox,
        );
        // ★ 这条是"整盒矩形"的照妖镜：退回基类时 maxX 会一直铺到标签右边
        check(
            '开关反馈 · 绝不铺到标签上（整行铺色会红）',
            $count > 0 && $maxX < (int) $labelX,
            'maxX=' . $maxX . ' 标签起点=' . (int) $labelX,
        );
        check(
            '开关反馈 · Pressed 比 Hover 深一档',
            self::meanLumOfChanged($base, $press) < self::meanLumOfChanged($base, $hover),
            'press=' . \round(self::meanLumOfChanged($base, $press), 2)
                . ' hover=' . \round(self::meanLumOfChanged($base, $hover), 2),
        );

        $this->flushChecks();
    }

    /**
     * 分段：反馈只在本段，且**方向跟着底色**。
     *
     * 选中段是 Accent 实底 ⇒ 叠白 ⇒ 变亮；未选中段压在 Chrome 轨道上 ⇒ 叠灰 ⇒
     * 变暗。同一句"悬停"必须是两个方向 —— 写死一个色，必然有一半红。
     */
    public function testSegmentFeedbackFollowsSegmentFill(): void
    {
        [$app, $probe] = $this->open(
            SegmentedControl::make(['日', '周', '月'])->selected(0)->onChanged(static function (int $i): void {
            }),
        );

        $segments = self::segmentBoxes($app->root());
        check('分段反馈 · 三段都造出了盒', \count($segments) === 3, '实际=' . \count($segments));
        if (\count($segments) !== 3) {
            $this->flushChecks();

            return;
        }

        $base = self::shot($app, $probe, 1000.0);

        // ① 选中段（Accent 底）：叠白 ⇒ 变亮
        $selected = $segments[0];
        [$sx, $sy] = boxOrigin($selected);
        $size = $selected->size();
        if ($size === null) {
            throw new \LogicException('分段没排过版');
        }

        $selected->setHovered(true);
        $this->settle($app, 1000.0);
        $hoverSelected = self::shot($app, $probe, 2000.0);
        $selected->setHovered(false);
        $this->settle($app, 2000.0);

        [$minX, , $maxX, , $count] = self::changedBounds($base, $hoverSelected);
        check(
            '分段反馈 · 选中段的悬停不越出本段',
            $count > 0 && $minX >= (int) $sx && $maxX <= (int) ($sx + $size->width),
            '范围 ' . $minX . '..' . $maxX . ' 段 ' . (int) $sx . '..' . (int) ($sx + $size->width),
        );
        check(
            '分段反馈 · 选中段（Accent 底）叠白 ⇒ 变亮',
            $count > 0 && self::meanLumOfChanged($base, $hoverSelected) > self::baselineLum($base, $hoverSelected),
            '变化区均值=' . \round(self::meanLumOfChanged($base, $hoverSelected), 2)
                . ' 原色均值=' . \round(self::baselineLum($base, $hoverSelected), 2),
        );

        // ★ 药丸四边内缩 2px + 圆角：段盒的左上角**不在**药丸里 ⇒ 不该吃到状态层。
        //   把 `paintInteraction()` 退回基类（整盒矩形）这一条立刻红。
        $cornerIndex = ((int) $sy + 1) * self::W + (int) $sx + 1;
        check(
            '分段反馈 · 段盒左上角（药丸内缩外）不吃状态层',
            isset($base[$cornerIndex], $hoverSelected[$cornerIndex])
                && $base[$cornerIndex] === $hoverSelected[$cornerIndex],
            '角点 原色=' . ($base[$cornerIndex] ?? '?') . ' 悬停=' . ($hoverSelected[$cornerIndex] ?? '?'),
        );

        // ② 未选中段（Chrome 轨道底）：叠灰 ⇒ 变暗
        $idle = $segments[1];
        $idle->setHovered(true);
        $this->settle($app, 3000.0);
        $hoverIdle = self::shot($app, $probe, 4000.0);

        check(
            '分段反馈 · 未选中段（轨道底）叠灰 ⇒ 变暗',
            self::changedBounds($base, $hoverIdle)[4] > 0
                && self::meanLumOfChanged($base, $hoverIdle) < self::baselineLum($base, $hoverIdle),
            '变化区均值=' . \round(self::meanLumOfChanged($base, $hoverIdle), 2)
                . ' 原色均值=' . \round(self::baselineLum($base, $hoverIdle), 2),
        );

        $this->flushChecks();
    }

    /** 滑块：光晕跟着拇指走（不是整条轨道） */
    public function testSliderHaloFollowsThumb(): void
    {
        [$app, $probe] = $this->open(
            Slider::make()->value(50.0)->width(200.0)->onChanged(static function (float $v): void {
            }),
        );

        $box = $app->root()?->findBoxOf(RenderSlider::class);
        if (!$box instanceof RenderSlider) {
            throw new \LogicException('滑块盒没找到');
        }

        [$ox] = boxOrigin($box);
        $thumb = $ox + $box->thumbX();

        $base = self::shot($app, $probe, 1000.0);
        $box->setHovered(true);
        $this->settle($app, 1000.0);
        $hover = self::shot($app, $probe, 2000.0);
        $box->setPressed(true);
        $this->settle($app, 2000.0);
        $press = self::shot($app, $probe, 3000.0);

        [$minX, , $maxX, , $count] = self::changedBounds($base, $hover);

        check('滑块反馈 · 悬停真的画了东西', $count > 0, '变化像素=' . $count);
        check(
            '滑块反馈 · 变化区以拇指为心（不是整条轨道）',
            $count > 0 && $minX > (int) ($thumb - 30.0) && $maxX < (int) ($thumb + 30.0),
            '范围 ' . $minX . '..' . $maxX . ' 拇指=' . (int) $thumb,
        );
        // ★ 层压在拇指**之下**：圆心像素三帧同色。把 `paintContent` 的拇指挪回
        //   状态层之前（或把状态层改成整盒矩形）这一条就红。
        $center = \intdiv(self::H, 2) * self::W + (int) $thumb;
        check(
            '滑块反馈 · 拇指本体不被染色（状态层在拇指之下）',
            isset($base[$center], $hover[$center]) && $base[$center] === $hover[$center],
            '圆心 原色=' . ($base[$center] ?? '?') . ' 悬停=' . ($hover[$center] ?? '?'),
        );

        $this->flushChecks();
    }

    /** @return list<RenderSegment> 按文档序，去重 */
    private static function segmentBoxes(?Element $root): array
    {
        if ($root === null) {
            return [];
        }

        $out = [];
        $seen = [];
        $walk = function (Element $el) use (&$walk, &$out, &$seen): void {
            $box = $el->effectiveBox();
            if ($box instanceof RenderSegment) {
                $id = \spl_object_id($box);
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $out[] = $box;
                }
            }
            $el->visitChildren($walk);
        };
        $walk($root);

        return $out;
    }

    /** 变化区在**原帧**里的平均亮度（与 `meanLumOfChanged` 同一批点） */
    private static function baselineLum(array $base, array $changed): float
    {
        $sum = 0;
        $n = 0;
        foreach ($base as $i => $v) {
            if ($v === $changed[$i]) {
                continue;
            }
            $sum += self::lum($v);
            ++$n;
        }

        return $n === 0 ? 0.0 : $sum / $n;
    }
}
