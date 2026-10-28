<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\Dev\Smoke\App\ScrollApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\PointerEvent;

/**
 * 拖滚动条：**thumb 是绘制物，命中必须先于内容**。
 *
 * ## 两条判据的分工
 *
 * ① 在 thumb 上按下并下拖 30px ⇒ 偏移按 `maxScroll / thumbTravel` **放大**
 *    （手指走 30px，内容走 30×max/travel px —— 这是"小条拖长内容"的唯一换算）；
 * ② 在内容区同向拖同样的 30px ⇒ 偏移**不变**（内容区拖动不是拖条）。
 *
 * ★ 只有 ① 的话，"整个盒都能拖动"也绿；只有 ② 的话，"哪里都拖不动"也绿。
 *   两条一起才钉住"命中先分给 thumb"。
 *
 * ★ 期望值是**算**出来的（`before + 30 × max / travel`），不是量出来凑的：
 *   量出来的数字换个内容长度就废，而且会把"放大系数写错但碰巧接近"判成通过。
 */
final class ScrollBarDragTest extends SmokeTestCase
{
    private const W = 300.0;

    private const H = 200.0;

    private static function constraints(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(self::W, self::H));
    }

    private static function open(): ScrollApplication
    {
        $app = new ScrollApplication();
        $app->attach(new SurfaceProbe(), self::constraints());
        $app->frame(16.0, self::constraints());

        return $app;
    }

    public function testThumbDragAmplifiesByMaxScrollOverTravel(): void
    {
        $app = self::open();
        $box = $app->scrollBox();
        $rect = $box?->thumbRect(Axis::Vertical);
        $travel = $box?->thumbTravel(Axis::Vertical) ?? 0.0;
        $max = $box?->maxScroll(Axis::Vertical) ?? 0.0;
        $before = $box?->appliedOffset(Axis::Vertical) ?? -1.0;
        $cx = ($rect?->left ?? 0.0) + ($rect?->width ?? 0.0) / 2.0;
        $cy = ($rect?->top ?? 0.0) + ($rect?->height ?? 0.0) / 2.0;

        $app->dispatchInput(PointerEvent::down($cx, $cy));
        $app->dispatchInput(PointerEvent::move($cx, $cy + 30.0));
        $app->dispatchInput(PointerEvent::up($cx, $cy + 30.0));
        // ★ 必须推一帧：`scrollBy()` 改 State 的 offset，`appliedOffset()` 读渲染盒的
        //   （布局期才同步）—— 真机由帧循环自动完成，头侧要手动补。
        $app->frame(17.0, self::constraints());

        $expected = $before + ($travel > 0.0 ? 30.0 * $max / $travel : 0.0);
        $now = $app->scrollBox()?->appliedOffset(Axis::Vertical) ?? -1.0;
        check(
            'Scroll · 拖滚动条：thumb 下拖 30px ⇒ 偏移按 maxScroll/thumbTravel 放大',
            $rect !== null && \abs($now - $expected) < 0.51,
            \sprintf(
                'rect=%s travel=%.1f max=%.1f before=%.1f now=%.1f expect=%.1f',
                $rect === null ? 'null' : \sprintf('[%.1f,%.1f,%.1f,%.1f]', $rect->left, $rect->top, $rect->width, $rect->height),
                $travel,
                $max,
                $before,
                $now,
                $expected
            )
        );
    }

    public function testBodyDragIsNotABarDrag(): void
    {
        $app = self::open();
        $before = $app->scrollBox()?->appliedOffset(Axis::Vertical) ?? -1.0;

        // (40, 40) 在内容区（thumb 贴右侧 x≈92..97）⇒ 拖动不该改变偏移
        $app->dispatchInput(PointerEvent::down(40.0, 40.0));
        $app->dispatchInput(PointerEvent::move(40.0, 70.0));
        $app->dispatchInput(PointerEvent::up(40.0, 70.0));
        $app->frame(17.0, self::constraints());

        check(
            'Scroll · 内容区拖动不是拖条（偏移不变）',
            $app->scrollBox()?->appliedOffset(Axis::Vertical) === $before,
            'before=' . $before . ' now=' . ($app->scrollBox()?->appliedOffset(Axis::Vertical) ?? -1.0)
        );
    }
}
