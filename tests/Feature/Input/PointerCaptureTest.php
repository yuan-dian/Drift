<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Input;

use Drift\Dev\Smoke\App\OverlayLabApplication;
use Drift\Dev\Smoke\Overlay\FlatBox;
use Drift\Dev\Smoke\Overlay\RecordingSurface;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\PointerEvent;
use Drift\Input\WindowDeactivatedEvent;
use Drift\Layout\RenderBox;
use Drift\Widget\Button;
use Drift\Widget\Element;

/**
 * 指针捕获的**收口**：捕获期不命中测试、抬起/卸载/失活三条释放路径、
 * 以及"释放后事件必须回到正常命中"。
 *
 * ★ 与 `OverlayStackTest` 分工：那边判"浮层怎么叠、往哪落"，这边判"指针手势
 *   什么时候放手"。⑪（捕获中点浮层外）横跨两者，判据是**捕获被释放**，
 *   所以归这里。
 * ★ 窗口失活这块必须有：指针手势的收口信号**全部来自指针自己**（`up` / `leave`），
 *   而"拖到一半 Alt+Tab 出去"这两个都不会来 —— 指针没动（没有 `WM_MOUSEMOVE`），
 *   用户在别处松手（`WM_LBUTTONUP` 落在别的窗口）⇒ 捕获 / 竞技场 / 拖动 / 框选 /
 *   拇指全部卡在"进行中"。现象是"某个控件永久只收它的事件"。
 */
final class PointerCaptureTest extends SmokeTestCase
{
    /** @return array{0: OverlayLabApplication, 1: RecordingSurface, 2: BoxConstraints} */
    private static function boot(): array
    {
        $surface = new RecordingSurface();
        $surface->resize(320, 240, 1.0);
        $app = new OverlayLabApplication();
        $tight = BoxConstraints::tight(new Size(320.0, 240.0));
        $app->attach($surface, $tight);

        return [$app, $surface, $tight];
    }

    private static function button(OverlayLabApplication $app, string $command): ?Element
    {
        return findElement(
            $app->root(),
            static fn(Element $e): bool => $e->widget() instanceof Button && $e->widget()->commandName() === $command,
        );
    }

    /** @return array{0: float, 1: float} 「底部」按钮的中心 */
    private static function bottomCenter(OverlayLabApplication $app): array
    {
        $box = self::button($app, 'lab.bottom')?->effectiveBox();
        [$x, $y] = $box === null ? [0.0, 0.0] : RenderBox::absoluteOrigin($box);

        return [
            $x + ($box?->size()?->width ?? 0.0) / 2.0,
            $y + ($box?->size()?->height ?? 0.0) / 2.0,
        ];
    }

    public function testCaptureRoutesWithoutHitTestingAndReleasesOnUp(): void
    {
        [$app] = self::boot();
        $anchor = self::button($app, 'lab.anchor');
        [$cx, $cy] = self::bottomCenter($app);
        $app->capturePointer($anchor);

        $app->pointerLog = [];
        $app->dispatchInput(PointerEvent::move($cx + 5.0, $cy + 5.0));
        check(
            '指针捕获 · 捕获期 move 落在别的控件上 ⇒ 仍归捕获者（不做命中测试）',
            $app->pointerLog === ['move'] && $app->captureNode() === $anchor,
            'log=' . implode(',', $app->pointerLog),
        );

        $app->dispatchInput(PointerEvent::up($cx + 5.0, $cy + 5.0));
        check(
            '指针捕获 · 抬起 ⇒ 自动释放（不释放就是"事件永远发给死节点"）',
            $app->captureNode() === null && $app->pointerLog === ['move', 'up'],
            'log=' . implode(',', $app->pointerLog),
        );

        $app->pointerLog = [];
        $app->dispatchInput(PointerEvent::move($cx + 6.0, $cy + 6.0));
        check(
            '指针捕获 · 释放后 move 不再进捕获者（回到正常命中）',
            $app->pointerLog === [],
            'log=' . implode(',', $app->pointerLog),
        );
    }

    public function testOverlayTakesPrecedenceOverCapture(): void
    {
        [$app, , $tight] = self::boot();
        $anchor = self::button($app, 'lab.anchor');
        [$cx, $cy] = self::bottomCenter($app);
        $app->capturePointer($anchor);
        $app->openPanel(FlatBox::make(40.0, 20.0), $anchor);
        $app->frame(16.0, $tight);

        $app->pointerLog = [];
        $app->dispatchInput(PointerEvent::down($cx + 5.0, $cy + 5.0));
        check(
            '指针捕获 · 捕获中点浮层外 ⇒ 释放捕获 + 关浮层（浮层优先，down 不转发给捕获者）',
            $app->captureNode() === null && $app->overlays()->count() === 0 && $app->pointerLog === [],
            'cap=' . ($app->captureNode() === null ? 'null' : 'set')
            . ' depth=' . $app->overlays()->count()
            . ' log=' . implode(',', $app->pointerLog),
        );
    }

    public function testUnmountedCaptureNodeReleasesWithDiagnostic(): void
    {
        [$app, , $tight] = self::boot();
        $app->capturePointer(self::button($app, 'lab.anchor'));
        // ★ DIAGNOSTIC **不落盘**（只进进程内环形缓冲）⇒ 读 `recentDiagnostics()`，
        //   读日志文件永远是空的（这条差点写成假绿）。先清空，否则别处留下的
        //   同名诊断会让这条恒真。
        Errors::reset();
        $app->root()->unmount();
        $app->frame(32.0, $tight);

        $found = false;
        foreach (Errors::recentDiagnostics() as $diag) {
            $text = is_array($diag) ? (string) ($diag['message'] ?? '') : (string) $diag;
            if (str_contains($text, '捕获者已卸载')) {
                $found = true;
            }
        }
        check(
            '指针捕获 · 捕获者卸载 ⇒ 自动释放并报 DIAGNOSTIC（不静默冻住）',
            $app->captureNode() === null && $found,
            'cap=' . ($app->captureNode() === null ? 'null' : 'set') . ' diag=' . ($found ? 'yes' : 'no'),
        );
    }

    public function testDeactivationReleasesCapture(): void
    {
        [$app] = self::boot();
        [$cx, $cy] = self::bottomCenter($app);
        $app->capturePointer(self::button($app, 'lab.anchor'));

        $app->pointerLog = [];
        $app->dispatchInput(PointerEvent::move($cx + 5.0, $cy + 5.0));
        $app->dispatchInput(new WindowDeactivatedEvent());
        check(
            '窗口失活 · 捕获被释放（否则回到窗口后只有那个节点收事件）',
            $app->captureNode() === null && $app->pointerLog === ['move'],
            'cap=' . ($app->captureNode() === null ? 'null' : 'set') . ' log=' . implode(',', $app->pointerLog),
        );

        $app->pointerLog = [];
        $app->dispatchInput(PointerEvent::move($cx + 6.0, $cy + 6.0));
        check(
            '窗口失活 · 释放后 move 回到正常命中（不再进原捕获者）',
            $app->pointerLog === [],
            'log=' . implode(',', $app->pointerLog),
        );
    }

    public function testDeactivationDoesNotCloseOverlays(): void
    {
        [$app, , $tight] = self::boot();
        $app->openPanel(FlatBox::make(40.0, 20.0), self::button($app, 'lab.anchor'));
        $app->frame(16.0, $tight);
        $depth = $app->overlays()->count();
        // ★ 反向守卫：以后有人"顺手"把关浮层加进失活路径，这条会红。
        //   W3 裁决：失焦 ≠ 放弃（`06` §10「关闭四来源」里没有这一条）。
        $app->dispatchInput(new WindowDeactivatedEvent());
        check(
            '窗口失活 · 浮层不关（失焦 ≠ 放弃：用户可能只是去别处看一眼）',
            $depth > 0 && $app->overlays()->count() === $depth,
            'before=' . $depth . ' after=' . $app->overlays()->count(),
        );
    }

    public function testDeactivationIsIdempotentAndSafeBeforeAttach(): void
    {
        [$app, , $tight] = self::boot();
        $app->openPanel(FlatBox::make(40.0, 20.0), self::button($app, 'lab.anchor'));
        $app->frame(16.0, $tight);
        $depth = $app->overlays()->count();

        $app->dispatchInput(new WindowDeactivatedEvent());
        // ★ 后半条钉的是代码形状：失活分支在"未挂载"守卫**之前**（窗口可能在拆卸
        //   途中失去前台，那一刻树已经没了，但手势状态仍要清）。
        $detachedOk = true;
        try {
            (new OverlayLabApplication())->dispatchInput(new WindowDeactivatedEvent());
        } catch (\Throwable $e) {
            $detachedOk = false;
        }
        check(
            '窗口失活 · 幂等，且未挂载时也安全（分支在"未挂载"守卫之前）',
            $app->captureNode() === null && $app->overlays()->count() === $depth && $detachedOk,
            'cap=' . ($app->captureNode() === null ? 'null' : 'set')
            . ' depth=' . $app->overlays()->count()
            . ' detached=' . ($detachedOk ? 'ok' : 'threw'),
        );
    }
}
