<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\Application;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderButton;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Widget;

/**
 * **悬停真的把像素改了** —— 从 `setHovered(true)` 一路断言到画布上的色差。
 *
 * ★ 为什么需要这个文件：`PaintArmsFrameTest` 只证明"脏了帧时钟会醒"，
 *   `HoverTest` 只证明"状态推到了盒上"。**两条都绿、真机上一个像素不变**
 *   （2026-11-01 实测：`tools/probe-motion.py` 悬停前后屏幕完全相同）。
 *   中间那段 —— 帧跑了、`tickAnimations()` 推进到 1、paint 也跑了 —— 没人验。
 *
 * ★★ 真机上那个"一个像素不变"有**两个独立成因**，本文件各有一条：
 *   ① `testHoverSurvivesRebuild`：盒每次重建换新（§13.2），悬停态住盒上被清零；
 *   ② `testHoverChangesPixelsInsideRepaintBoundary`：`LayerCache` 按 `layoutEpoch`
 *      命中 ⇒ 边界重放旧命令，`paintOwnChrome()` 根本没被调到。
 *   只测没有边界的极简树时 ② 不可能红 —— 真机页面几乎每页都有边界。
 *
 * ★ 判据必须成对：只有"变了"会奖励"每帧全屏重画"（`testSettledHoverIsStable`
 *   抓这个）；只有"稳态不变"会奖励"什么都不画"。
 */
final class HoverPixelTest extends SmokeTestCase
{
    private const W = 240.0;

    private const H = 120.0;

    private static function constraints(): BoxConstraints
    {
        return BoxConstraints::tight(new Size(self::W, self::H));
    }

    /** @return array{0: Application, 1: SurfaceProbe} */
    private function open(): array
    {
        $app = new class extends Application {
            public function buildRoot(): Widget
            {
                return Column::make()->paddingAll(20.0)->children([
                    Button::make('悬停我')->variant(ButtonVariant::Default),
                ]);
            }
        };
        $probe = new SurfaceProbe();
        $probe->resize((int) self::W, (int) self::H, 1.0);
        $app->attach($probe, self::constraints());
        $app->frame(0.0, self::constraints());

        return [$app, $probe];
    }

    /**
     * 跑一帧再拍像素（步长 2px：整幅比，不依赖单点落在哪）。
     *
     * ★★ **必须用同一个 probe**。早先的写法是"每次拍照 new 一个 SurfaceProbe 再
     *   `attach()`"，那会让布局序号与边界缓存都翻篇 ⇒ 缓存永远命中不了 ⇒
     *   `testHoverChangesPixelsInsideRepaintBoundary` 变成恒绿（拆掉修复也不红）。
     */
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
                $changed++;
            }
        }

        return $changed;
    }

    private function buttonBox(Application $app): RenderButton
    {
        $box = $app->root()?->findBoxOf(RenderButton::class);

        return $box instanceof RenderButton ? $box : throw new \LogicException('按钮盒没找到');
    }

    private function buttonElement(Application $app): ?Element
    {
        $root = $app->root();

        return $root === null ? null : findElement(
            $root,
            static fn (Element $e): bool => $e->widget() instanceof Button,
        );
    }

    /**
     * 悬停 ⇒ 跑够斜坡 ⇒ **画布上看得见**。
     *
     * ★ 断言的是"变化像素数 > 0"而不是具体色值：hover 叠色与浮起档位都会变像素，
     *   钉死色值等于把主题色表抄一份进测试（主题一改就问"谁是权威"）。
     */
    public function testHoverChangesPixels(): void
    {
        [$app, $probe] = $this->open();
        $before = self::shot($app, $probe, 1000.0);

        $this->buttonBox($app)->setHovered(true);
        for ($i = 1; $i <= 40; $i++) {
            $app->frame($i * 16.0, self::constraints());
        }

        $after = self::shot($app, $probe, 2000.0);
        $changed = self::diffPixels($before, $after);

        check(
            '悬停像素 · 悬停到收口后画布上有像素变了（真机症状：一个都不变）',
            $changed > 0,
            '变化像素数=' . $changed,
        );

        $this->flushChecks();
    }

    /**
     * **旁支重建时悬停不能丢** —— 真机症状的成因①。
     *
     * ★ 假设：`Element::update()` 走 `syncBox()` 造**新盒**（§13.2），而悬停态
     *   住**盒**上 ⇒ 任何一个兄弟节点 `setState()` 都会把按钮的盒换掉 ⇒ 新盒
     *   从 0 起、旧盒的斜坡丢在半路 ⇒ 画面回到原色。
     *
     * ★ 本方法用"每帧给同一按钮递新描述"来模拟兄弟重建（`update()` 与
     *   `rebuild()` 里给子层递新 widget 是同一条路）。
     */
    public function testHoverSurvivesRebuild(): void
    {
        [$app, $probe] = $this->open();
        $before = self::shot($app, $probe, 1000.0);

        $btnEl = $this->buttonElement($app);
        if ($btnEl === null) {
            check('悬停像素 · 按钮 Element 找得到', false);

            return;
        }

        $this->buttonBox($app)->setHovered(true);
        for ($i = 1; $i <= 40; $i++) {
            // 兄弟重建的等价物：父层每帧递一份新描述
            $btnEl->update(Button::make('悬停我')->variant(ButtonVariant::Default));
            $app->frame($i * 16.0, self::constraints());
        }

        $after = self::shot($app, $probe, 2000.0);
        $changed = self::diffPixels($before, $after);

        check(
            '悬停像素 · 期间有重建时悬停仍然可见（盒换代不许清零悬停态）',
            $changed > 0,
            '变化像素数=' . $changed . ' ⇒ 重建把悬停态换没了',
        );

        $this->flushChecks();
    }

    /**
     * 换代移交的是**整份瞬态**，不只是那个布尔。
     *
     * ★ 只搬 `hovered` 也能让上面那条绿（新盒判 `hovered=true` ⇒ `hoverProgress()`
     *   直接给 1.0 ⇒ 像素确实变了），但**过渡被跳过了**：每次重建都瞬跳到终态。
     *   本方法钉住"进度是连续的"：第二帧必须在 `(0,1)` 开区间内，之后单调不减。
     *   （第 0 帧必为 0：`AnimatedValue::tick()` 的首帧只记时间起点、不推进。）
     */
    public function testHoverProgressSurvivesRebuild(): void
    {
        [$app] = $this->open();

        $btnEl = $this->buttonElement($app);
        if ($btnEl === null) {
            check('悬停像素 · 按钮 Element 找得到', false);

            return;
        }

        $this->buttonBox($app)->setHovered(true);

        $seen = [];
        for ($i = 1; $i <= 10; $i++) {
            $btnEl->update(Button::make('悬停我')->variant(ButtonVariant::Default));
            $app->frame($i * 16.0, self::constraints());
            $seen[] = $this->buttonBox($app)->hoverProgress();
        }

        $seq = \implode(',', \array_map(static fn (float $v): string => \sprintf('%.3f', $v), $seen));

        check(
            '悬停像素 · 重建后进度从原处续上（不是 0 也不是瞬跳 1.0）',
            $seen[1] > 0.0 && $seen[1] < 1.0,
            '第二帧进度=' . $seen[1] . ' 序列=' . $seq,
        );

        $monotonic = true;
        for ($i = 1; $i < \count($seen); $i++) {
            if ($seen[$i] < $seen[$i - 1]) {
                $monotonic = false;

                break;
            }
        }
        check(
            '悬停像素 · 重建期间进度单调不减（被打回 0 就是又淡一次）',
            $monotonic,
            '序列=' . $seq,
        );

        $this->flushChecks();
    }

    /**
     * **被重绘边界（`RepaintBoundary`）包住的按钮，悬停同样要改像素。**
     *
     * ★★ 真机症状的成因②（2026-11-01 帧级 trace 抓到）：`LayerCache` 按
     *   `layoutEpoch` + 尺寸命中，而 hover 只推进全局 `paintEpoch` ⇒ 边界**原样
     *   重放旧命令**，`paintOwnChrome()` 一次都没被调到。实测：进度从 0 推到 1
     *   用了 43 帧，同一期间 `paint` 记录里从来没有那个盒的 id。
     *
     * ★ 边界必须有测试：没有边界的树每帧真画 ⇒ 这个坑在极简夹具里不可能红
     *   （`testHoverChangesPixels` 就是这么绿的），而真机页面几乎每页都有边界
     *   （表格 body 自己的 `setRepaintBoundary(true)`、滚动视图的内容层）。
     */
    public function testHoverChangesPixelsInsideRepaintBoundary(): void
    {
        [$app, $probe] = $this->open();
        $root = $app->root();
        $colBox = $root?->effectiveBox();
        if ($colBox === null) {
            check('悬停像素 · 根盒找得到', false);

            return;
        }

        // 把整列变成重绘边界 ⇒ 按钮的绘制命令录进它的缓存
        $colBox->setRepaintBoundary(true);
        $before = self::shot($app, $probe, 1000.0);

        $this->buttonBox($app)->setHovered(true);
        for ($i = 1; $i <= 40; $i++) {
            $app->frame($i * 16.0, self::constraints());
        }

        $after = self::shot($app, $probe, 2000.0);
        $changed = self::diffPixels($before, $after);

        check(
            '悬停像素 · 重绘边界内的按钮悬停也变（缓存不许把新命令吞掉）',
            $changed > 0,
            '变化像素数=' . $changed . ' ⇒ 边界命中缓存、重放了旧命令',
        );

        $this->flushChecks();
    }

    /** 收口之后不再空转：同样的帧反复跑，画面必须逐位相同 */
    public function testSettledHoverIsStable(): void
    {
        [$app, $probe] = $this->open();
        $this->buttonBox($app)->setHovered(true);
        for ($i = 1; $i <= 40; $i++) {
            $app->frame($i * 16.0, self::constraints());
        }

        $a = self::shot($app, $probe, 1000.0);
        for ($i = 41; $i <= 60; $i++) {
            $app->frame($i * 16.0, self::constraints());
        }

        $b = self::shot($app, $probe, 2000.0);

        check(
            '悬停像素 · 收口后画面稳定（不是每帧都在变）',
            self::diffPixels($a, $b) === 0,
            '变化像素数=' . self::diffPixels($a, $b),
        );

        $this->flushChecks();
    }
}
