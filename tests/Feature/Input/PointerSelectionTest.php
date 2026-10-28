<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Input;

use Drift\Dev\Smoke\App\EditApplication;
use Drift\Dev\Smoke\App\FakeClipboard;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\CursorShape;
use Drift\Input\PointerEvent;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderTextField;
use Drift\Widget\Element;
use Drift\Widget\StatefulElement;
use Drift\Widget\TextField;
use Drift\Widget\TextFieldState;

/**
 * 指针框选：down 落插入符 / move 延伸 / up·leave 收口（§A.2.9 的指针侧）。
 *
 * ## 坐标必须从盒树反推
 *
 * 夹具是 `padding(24)` 里的字段，几何写死会在任何 padding 变动时静默失准 ——
 * 所以先断言"盒原点 == 24"（夹具自检），再用 `absoluteOrigin()` 取真实起点。
 * ★ 不要在这里手抄累加：`Padding::all(24)` 的字段盒原点手算会算成 (0,0)，
 *   7 条框选/光标断言全红（2026-10-22 实测）。
 *
 * ## 收口的两种形态都要验
 *
 * `up`（手势正常结束）与 `leave`（指针离开）之后都不许再延伸 —— 后者是
 * "指针没有捕获"的诚实边界：控件拿不到盒子外的 move，光看 `up` 会漏掉。
 */
final class PointerSelectionTest extends SmokeTestCase
{
    public function testPointerSelectionLifecycle(): void
    {
        $selApp = new EditApplication();
        $selApp->clip = new FakeClipboard();
        $selApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(300.0, 200.0)));
        $selEl = findElement($selApp->rootElement(), fn(Element $e) => $e->widget() instanceof TextField);
        $state = $selEl instanceof StatefulElement ? $selEl->state() : null;
        $selState = $state instanceof TextFieldState ? $state : null;
        $selBox = $selEl?->effectiveBox();
        check(
            '框选 · 夹具就绪（字段盒在 padding 24 处，宽度由内容定）',
            $selBox instanceof RenderTextField && RenderBox::absoluteOrigin($selBox) === [24.0, 24.0],
            'origin=' . implode(',', $selBox === null ? [] : RenderBox::absoluteOrigin($selBox)),
        );
        [$bx, $by] = RenderBox::absoluteOrigin($selBox);
        $bw = $selBox->size()->width;   // 字段盒宽（点"右端"必须点在盒内 —— 盒外焦点落不上）

        $selApp->dispatchInput(PointerEvent::down($bx + 1.0, $by + 1.0));
        check(
            '框选 · down 落左边缘 ⇒ 插入符 0、无选区（点一下不继承旧选区）',
            $selState?->caretOffset() === 0 && $selState?->hasSelection() === false,
            'caret=' . ($selState?->caretOffset() ?? -1) . ' sel=' . var_export($selState?->hasSelection(), true),
        );

        $selApp->dispatchInput(PointerEvent::move($bx + 200.0, $by + 4.0));
        check(
            '框选 · 向右拖 ⇒ 选区延伸到末尾（[0,3]，selectedText 完整）',
            $selState?->selectionStart() === 0 && $selState?->selectionEnd() === 3
                && $selState?->selectedText() === 'abc',
            'sel=' . ($selState?->selectionStart() ?? -1) . '..' . ($selState?->selectionEnd() ?? -1),
        );

        $selApp->dispatchInput(PointerEvent::up($bx + 200.0, $by + 4.0));
        $selApp->dispatchInput(PointerEvent::move($bx + 250.0, $by + 4.0));
        check(
            '框选 · up 收口后再 move 不延伸（手势结束，选区留在字段里）',
            $selState?->selectionStart() === 0 && $selState?->selectionEnd() === 3
                && $selState?->selectedText() === 'abc',
            'sel=' . ($selState?->selectionStart() ?? -1) . '..' . ($selState?->selectionEnd() ?? -1),
        );

        // 反向：点在右端（折叠）⇒ 向左拖 ⇒ 活动端在左
        $selApp->dispatchInput(PointerEvent::down($bx + $bw - 1.0, $by + 4.0));
        check(
            '框选 · 再点一次 ⇒ 旧选区折叠、插入符移到点中处',
            $selState?->caretOffset() === 3 && $selState?->hasSelection() === false,
            'caret=' . ($selState?->caretOffset() ?? -1) . ' sel=' . var_export($selState?->hasSelection(), true),
        );
        $selApp->dispatchInput(PointerEvent::move($bx + 1.0, $by + 4.0));
        check(
            '框选 · 反向拖动同样成选区（活动端在左，[0,3]）',
            $selState?->selectionStart() === 0 && $selState?->selectionEnd() === 3,
            'sel=' . ($selState?->selectionStart() ?? -1) . '..' . ($selState?->selectionEnd() ?? -1),
        );
        $selApp->dispatchInput(PointerEvent::leave($bx + 1.0, $by + 4.0));
        $selApp->dispatchInput(PointerEvent::move($bx + 200.0, $by + 4.0));
        check(
            '框选 · leave 收口后再 move 不延伸（指针无捕获的诚实边界）',
            $selState?->selectionStart() === 0 && $selState?->selectionEnd() === 3
                && $selState?->selectedText() === 'abc',
            'sel=' . ($selState?->selectionStart() ?? -1) . '..' . ($selState?->selectionEnd() ?? -1),
        );

        // §9.1「一次命中，三方消费」的第 ③ 方：指针移进字段 ⇒ IBeam
        $selCursor = $selApp->dispatchInput(PointerEvent::move($bx + 2.0, $by + 2.0));
        check(
            '光标 · 指针移入字段 ⇒ IBeam（§9.1 三方消费第三项）',
            $selCursor === CursorShape::IBeam,
            var_export($selCursor, true),
        );

        $this->flushChecks();
    }
}
