<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\Dev\Smoke\App\TextAreaApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\PointerEvent;

/**
 * 多行输入框的滚动条**能拖**（端到端：命中 → 路由 → 偏移进盒）。
 *
 * ★ 为什么必须端到端：`RenderTextArea implements ScrollBarHost` 只保证"接口
 *   齐了"。`ScrollInputRouter` 沿父链找 `Scrollable`、再问它的 `ScrollBarHost`
 *   —— 这条链上任何一处认死 `RenderScrollView` 都是**静默失效**：条画得出来、
 *   抓不住。`TextEditingBridge` 写死 `RenderTextField` 时拖选就是这样坏的。
 *
 * ★ 与 `ScrollBarDragTest` 同一条判据形态：期望值**算**出来
 *   （`before + 30 × max / travel`），不量出来凑。
 */
final class TextAreaBarDragTest extends SmokeTestCase
{
    private static function constraints(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(300.0, 200.0));
    }

    private static function open(): TextAreaApplication
    {
        $app = new TextAreaApplication();
        $app->attach(new SurfaceProbe(), self::constraints());
        $app->frame(16.0, self::constraints());

        return $app;
    }

    public function testThumbDragScrollsTheTextArea(): void
    {
        $app = self::open();
        $box = $app->areaBox();
        $rect = $box?->thumbRect(Axis::Vertical);
        $travel = $box?->thumbTravel(Axis::Vertical) ?? 0.0;
        $max = $box?->maxScroll(Axis::Vertical) ?? 0.0;
        $before = $box?->appliedOffset(Axis::Vertical) ?? -1.0;

        $cx = ($rect?->left ?? 0.0) + ($rect?->width ?? 0.0) / 2.0;
        $cy = ($rect?->top ?? 0.0) + ($rect?->height ?? 0.0) / 2.0;

        $app->dispatchInput(PointerEvent::down($cx, $cy));
        $app->dispatchInput(PointerEvent::move($cx, $cy + 30.0));
        $app->dispatchInput(PointerEvent::up($cx, $cy + 30.0));
        // 偏移要推一帧才落到盒上（布局期同步）
        $app->frame(17.0, self::constraints());

        $expected = $before + ($travel > 0.0 ? 30.0 * $max / $travel : 0.0);
        $now = $app->areaBox()?->appliedOffset(Axis::Vertical) ?? -1.0;

        check(
            'TextArea 条 · thumb 下拖 30px ⇒ 文本真的滚了（按 maxScroll/thumbTravel 放大）',
            $rect !== null && \abs($now - $expected) < 0.51,
            \sprintf(
                'rect=%s travel=%.1f max=%.1f before=%.1f now=%.1f expect=%.1f',
                $rect === null ? 'null' : \sprintf('[%.1f,%.1f,%.1f,%.1f]', $rect->left, $rect->top, $rect->width, $rect->height),
                $travel,
                $max,
                $before,
                $now,
                $expected,
            ),
        );

        $this->flushChecks();
    }

    /**
     * 内容区按下归**文本**（焦点进了多行框），没有被 thumb 抢走。
     *
     * ★ 判据不是"选中了一段"：拖选链路自己还有自己的测试（`TextSelectionTest`），
     *   在这儿顺带测它只会把两件事的失败混在一起。也不拿偏移当判据 ——
     *   点击定位插入符后 `revealCaret()` 会把视口滚过去，那是编辑器的正常行为。
     */
    public function testBodyPressGoesToTheTextNotTheBar(): void
    {
        $app = self::open();

        $app->dispatchInput(PointerEvent::down(40.0, 40.0));
        $app->dispatchInput(PointerEvent::up(40.0, 40.0));
        $app->frame(17.0, self::constraints());

        $focused = $app->focus()?->focused();
        $areaElement = $app->areaState()?->element();

        check(
            'TextArea 条 · 内容区按下 ⇒ 焦点在多行框（按下没被 thumb 吞掉）',
            $focused !== null && $focused === $areaElement,
            'focused=' . ($focused === null ? 'null' : $focused::class),
        );

        $this->flushChecks();
    }
}
