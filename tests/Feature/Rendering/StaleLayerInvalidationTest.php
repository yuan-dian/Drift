<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Rendering;

use Drift\Core\Pipeline;
use Drift\Dev\Bench\FrameTaxApp;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Rendering\Color;
use Drift\Rendering\RasterCanvas;
use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\SizedBox;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 嵌套重绘边界下，就地改像素必须让**所有**外层缓存失效（否则重放旧命令 = 陈旧帧）。
 *
 * ★ 两条独立修复各占一条断言：
 *   ① 视觉维（background）走 `markPaintChanged()`，而非只标帧；
 *   ② 失效上溯到所有外层边界，不能遇到第一层就停 —— 外层缓存持内层命令的拷贝。
 * ★ 单一断言挡不住"只修一处"：任一处回退都会让帧 2 仍是红。
 */
final class StaleLayerInvalidationTest extends SmokeTestCase
{
    private const W = 200.0;

    public function testVisualChangeInvalidatesAllEnclosingBoundaries(): void
    {
        $tight = BoxConstraints::tight(new Size(self::W, self::W));
        $app = new FrameTaxApp(static fn(): Widget => new StaleNestWidget());
        $surface = new \Drift\Dev\Smoke\Frame\SurfaceProbe();
        $surface->resize((int) self::W, (int) self::W, 1.0);
        $app->attach($surface, $tight);

        $box = $app->root()->effectiveBox();
        $bit = self::findBitState($app->root());
        check('陈旧帧 · 找到内层 State', $bit !== null);
        if ($bit === null) {
            $this->flushChecks();

            return;
        }

        $pixel = static function () use ($box): Color {
            $canvas = new RasterCanvas((int) self::W, (int) self::W, Color::fromRgb(255, 255, 255), Scale::of(1.0));
            $box?->paintComposed($canvas);

            return $canvas->pixelAt(10, 10);
        };

        $before = $pixel();
        check('陈旧帧 · 帧 1 是红', $before->equals(Color::fromRgb(255, 0, 0)), $before->r . '/' . $before->g . '/' . $before->b);

        $bit->red = false;
        $bit->markDirty();
        $app->frame(32.0, $tight);

        $after = $pixel();
        check('陈旧帧 · 帧 2 变蓝（两层边界都失效）', $after->equals(Color::fromRgb(0, 0, 255)), $after->r . '/' . $after->g . '/' . $after->b);

        $this->flushChecks();
    }

    /**
     * 去掉全局 layoutEpoch 键后，同尺寸换内容必须靠"换盒 → 重排 → 上溯标脏"重录。
     * 只数 paint/replay：换文本像素要处理字形，计数已足以判"有没有重放旧命令"。
     */
    public function testSameSizeTextSwapForcesReRecord(): void
    {
        $tight = BoxConstraints::tight(new Size(self::W, self::W));
        $app = new FrameTaxApp(static fn(): Widget => new TextSwapRoot());
        $surface = new \Drift\Dev\Smoke\Frame\SurfaceProbe();
        $surface->resize((int) self::W, (int) self::W, 1.0);
        $app->attach($surface, $tight);
        $app->frame(16.0, $tight);

        $st = self::findTextSwapState($app->root());
        check('P1 · 找到文本 State', $st !== null);
        if ($st === null) {
            $this->flushChecks();

            return;
        }

        $paintBefore = Pipeline::current()->paintCount();
        $st->label = 'BBBB';   // 同字节数 ⇒ 同排版尺寸，尺寸判据不会变
        $st->markDirty();
        $app->frame(32.0, $tight);

        $painted = Pipeline::current()->paintCount() - $paintBefore;
        check('P1 · 同尺寸换内容触发重录（不许重放旧命令 = 陈旧帧）', $painted > 0, "paint=+{$painted}");

        $this->flushChecks();
    }

    private static function findTextSwapState(object $e): ?TextSwapState
    {
        if ($e instanceof \Drift\Widget\StatefulElement && $e->state() instanceof TextSwapState) {
            return $e->state();
        }
        for ($i = 0; $i < $e->childCount(); $i++) {
            $found = self::findTextSwapState($e->childAt($i));
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private static function findBitState(object $e): ?StaleBitState
    {
        if ($e instanceof \Drift\Widget\StatefulElement && $e->state() instanceof StaleBitState) {
            return $e->state();
        }
        for ($i = 0; $i < $e->childCount(); $i++) {
            $found = self::findBitState($e->childAt($i));
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}

/** @internal 内层可变色的叶 Widget */
final class StaleBitWidget extends StatefulWidget
{
    public function createState(): State
    {
        return new StaleBitState();
    }
}

/** @internal */
final class StaleBitState extends State
{
    public bool $red = true;

    public function build(BuildContext $ctx): Widget
    {
        return SizedBox::make()
            ->size(40.0, 40.0)
            ->background($this->red ? Color::fromRgb(255, 0, 0) : Color::fromRgb(0, 0, 255));
    }
}

/** @internal 外层与内层各一个边界 —— 与 ScrollView 套 Table 同形 */
final class StaleNestWidget extends StatefulWidget
{
    public function createState(): State
    {
        return new StaleNestState();
    }
}

/** @internal */
final class StaleNestState extends State
{
    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->children([
            Column::make()->children([new StaleBitWidget()])->repaintBoundary(true),
        ])->repaintBoundary(true);
    }
}

/** @internal 同尺寸换内容的叶 State —— 两层边界套住 */
final class TextSwapState extends State
{
    public string $label = 'AAAA';

    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->children([Text::make($this->label)])->repaintBoundary(true);
    }
}

/** @internal */
final class TextSwapWidget extends StatefulWidget
{
    public function createState(): State
    {
        return new TextSwapState();
    }
}

/** @internal */
final class TextSwapRootState extends State
{
    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->children([new TextSwapWidget()])->repaintBoundary(true);
    }
}

/** @internal */
final class TextSwapRoot extends StatefulWidget
{
    public function createState(): State
    {
        return new TextSwapRootState();
    }
}
