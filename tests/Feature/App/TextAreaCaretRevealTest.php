<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\Dev\Smoke\App\TextAreaApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;

/**
 * 多行框的**插入符跟随**：插入符动 ⇒ 视口跟过去；用户自己滚 ⇒ 视口不许被拽回。
 *
 * ★★ 这两条是**一对**，缺一条就会写在另一条的代价上（2026-11-03 实测）：
 *   `revealCaret()` 原先在 `build()` 里**每帧无条件**跑。"插入符可见"永远成立，
 *   于是"用户拖条滚走看别处"在**下一次 rebuild** 就被推翻 —— 滚动条淡出动画、
 *   兄弟节点 setState、光标闪烁都算 rebuild，真机上是"滚一半弹回去"。
 *   把它删掉又会让插入符跑出视口。**正确的开关是"插入符变了没有"**，
 *   不是"要不要跟随"。
 *
 * ★ 另一处独立缺陷（同一轮实测发现）：`revealCaret()` 原先读盒的 `caretRect()`，
 *   而盒是**上一帧**的产物 —— 点进输入框的那一帧盒还是"未编辑"的
 *   （`caretOffset === null`），`caretRect()` 的兜底给出**文本末尾** ⇒
 *   视口跳到底，插入符在第 3 行。要等下一次 rebuild 才纠正（真机 = 闪一下）。
 *   ⇒ 现在的分工：**State 在 build 里回答"要不要跟随、跟到哪个偏移"**
 *     （`caretRevealOffset()`），**盒在 `performLayout()` 里执行**（那时段落与
 *     `maxScroll` 才是本帧的值）⇒ 同帧生效，不需要"等下一帧同源"。
 *
 * ★ 判据一律用**不变量**「插入符矩形落在视口内」，不赌字模、不赌行高具体值——
 *   文本是 40 个 CJK × 20 行（`TextAreaApplication`），折行由段落层决定。
 */
final class TextAreaCaretRevealTest extends SmokeTestCase
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

    private function offset(TextAreaApplication $app): float
    {
        return $app->areaBox()?->appliedOffset(Axis::Vertical) ?? -1.0;
    }

    /** 插入符（**当前**插入符，不是盒上那个字段）是否落在视口内 */
    private function caretVisible(TextAreaApplication $app): bool
    {
        $box = $app->areaBox();
        $state = $app->areaState();
        if ($box === null || $state === null || !$box->hasSize()) {
            return false;
        }

        $caret = $box->caretRectAt($state->caretOffset());
        $top = $box->contentTop();
        $bottom = $top + $box->viewportHeight();

        return $caret->top >= $top - 0.5 && $caret->top + $caret->height <= $bottom + 0.5;
    }

    /**
     * 推 `$count` 帧；`$rebuild` 时每帧先标脏 —— 模拟"这一帧本节点要重建"。
     *
     * ★★ 为什么必须显式标脏（2026-11-03，判据自己先恒真过一次）：
     *   `scrollBy()` **不标脏**（三个 `Scrollable` 实现都是 —— 偏移就地推给盒，
     *   不走 State 状态），所以拖条之后这个 State 是干净的，`$app->frame()`
     *   推出来的都是**空帧**（不 build）⇒ "拖走之后偏移不动"**恒真**，
     *   与实现对错无关。
     *   真实世界里让它重建的是滚动条淡出动画（`ScrollBarFade`，900ms 空闲后开始
     *   淡）或者兄弟节点的 setState —— 那都是"碰巧有帧"，不该当判据的前提。
     *   ⇒ 想要"这一帧重建了"就明确标脏，别指望管线。
     */
    private function pump(TextAreaApplication $app, int $count, float $start, bool $rebuild = false): void
    {
        $state = $app->areaState();
        for ($i = 0; $i < $count; $i++) {
            if ($rebuild) {
                $state?->markDirty();
            }
            $app->frame($start + $i * 16.0, self::constraints());
        }
    }

    /** 按 thumb 拖动若干像素（正 = 往下滚） */
    private function dragThumb(TextAreaApplication $app, float $dy): void
    {
        $rect = $app->areaBox()?->thumbRect(Axis::Vertical);
        $cx = ($rect?->left ?? 0.0) + ($rect?->width ?? 0.0) / 2.0;
        $cy = ($rect?->top ?? 0.0) + ($rect?->height ?? 0.0) / 2.0;

        $app->dispatchInput(PointerEvent::down($cx, $cy));
        $app->dispatchInput(PointerEvent::move($cx, $cy + $dy));
        $app->dispatchInput(PointerEvent::up($cx, $cy + $dy));
        $this->pump($app, 2, 30.0);
    }

    /** 点进内容区 ⇒ 焦点 + 插入符 */
    private function clickBody(TextAreaApplication $app, float $x = 40.0, float $y = 40.0): void
    {
        $app->dispatchInput(PointerEvent::down($x, $y));
        $app->dispatchInput(PointerEvent::up($x, $y));
        $this->pump($app, 2, 17.0);
    }

    /**
     * ① 点进输入框 ⇒ 插入符**当帧**可见。
     *
     * ★ 修前：偏移 = `maxScroll`（视口在最底），插入符在文本开头 —— 用户点了一下，
     *   看见的是文档末尾，看不到自己的光标。这不是"边缘情况"：任何比视口高的
     *   内容、任何一次点入都会发生。
     */
    public function testClickingIntoTheBodyRevealsTheCaret(): void
    {
        $app = self::open();
        $max = $app->areaBox()?->maxScroll(Axis::Vertical) ?? 0.0;

        $this->clickBody($app);

        check(
            '插入符跟随 · 点进多行框 ⇒ 插入符在视口内（不是停在文本末尾）',
            $this->caretVisible($app),
            \sprintf('offset=%.1f max=%.1f caret=%d', $this->offset($app), $max, $app->areaState()?->caretOffset() ?? -1),
        );

        $this->flushChecks();
    }

    /**
     * ② 用户拖条滚走 ⇒ 之后的 rebuild **不许**把视口拽回插入符。
     *
     * ★ 判据形态是"重复帧"：只推一帧等于只测"没 rebuild 时不弹回"，
     *   而坏的那版也满足（它只在 build 里弹）。真实世界的 rebuild 来自动画 tick
     *   与兄弟 setState，是连续发生的。
     */
    public function testDraggingAwayIsNotUndoneByLaterRebuilds(): void
    {
        $app = self::open();
        $this->clickBody($app);

        $this->dragThumb($app, 120.0);
        $dragged = $this->offset($app);

        $this->pump($app, 4, 50.0, rebuild: true);
        $after = $this->offset($app);

        check(
            '插入符跟随 · 编辑中拖条滚走后 4 次重建 ⇒ 偏移不动（用户滚的视口不被插入符拽回）',
            \abs($after - $dragged) < 0.51 && $dragged > 100.0,
            \sprintf('拖后=%.1f 四次重建后=%.1f', $dragged, $after),
        );

        $this->flushChecks();
    }

    /**
     * ③ 拖走之后**打字** ⇒ 视口跟回插入符（跟随的另一半）。
     *
     * ★ 没有这条，② 可以由"干脆不跟随"满足 —— 两条必须成对。
     * ★ 这里**不额外标脏**：打字的输入落地就标了脏，那一帧的 build 就要把
     *   `caretRevealOffset()` 带下去并在 layout 当帧对准。
     */
    public function testTypingAfterScrollingBringsTheCaretBack(): void
    {
        $app = self::open();
        $this->clickBody($app);

        $this->dragThumb($app, 120.0);
        $dragged = $this->offset($app);

        $app->dispatchInput(KeyEvent::character('x'));
        $this->pump($app, 2, 60.0);

        check(
            '插入符跟随 · 拖走之后打字 ⇒ 视口跟回插入符（偏移变了且插入符可见）',
            $this->caretVisible($app) && \abs($this->offset($app) - $dragged) > 1.0,
            (static function () use ($app, $dragged): string {
                $box = $app->areaBox();
                $state = $app->areaState();
                $c = $box?->caretRectAt($state?->caretOffset() ?? 0);

                return \sprintf(
                    '拖后=%.1f 打字后=%.1f caret=%d | caretTop=%.1f top=%.1f bottom=%.1f h=%.1f viewport=%.1f',
                    $dragged,
                    $app->areaBox()?->appliedOffset(Axis::Vertical) ?? -1.0,
                    $state?->caretOffset() ?? -1,
                    $c?->top ?? -1.0,
                    $box?->contentTop() ?? -1.0,
                    ($box?->contentTop() ?? 0.0) + ($box?->viewportHeight() ?? 0.0),
                    $c?->height ?? -1.0,
                    $box?->viewportHeight() ?? -1.0,
                );
            })(),
        );

        $this->flushChecks();
    }

    /**
     * ④ 未编辑时拖条 + 重建 ⇒ 视口同样不许被拽回。
     *
     * ★ 这条同时钉住"未编辑不许跟随"：未编辑时 `caretOffset()` 在 draft 里可能是
     *   上次编辑留下的位置；若 build 里无条件跟随，拖条后一次 rebuild 就会
     *   跳回去（修前更糟：`caretRect()` 拿 `null` 兜底成文本末尾 ⇒ 跳到底部）。
     */
    public function testDraggingWhileNotEditingIsAlsoStable(): void
    {
        $app = self::open();

        $this->dragThumb($app, 60.0);
        $dragged = $this->offset($app);

        $this->pump($app, 4, 50.0, rebuild: true);
        $after = $this->offset($app);

        check(
            '插入符跟随 · 未编辑拖条后 4 次重建 ⇒ 偏移不动',
            \abs($after - $dragged) < 0.51 && $dragged > 1.0,
            \sprintf('拖后=%.1f 四次重建后=%.1f', $dragged, $after),
        );

        $this->flushChecks();
    }

    /**
     * ⑤ 编辑完退出、插入符**停在末尾**，然后滚走 ⇒ 重建不许把它拽回去。
     *
     * ★ ④ 的夹具里未编辑时插入符正好在 0（初始值），"跟随"与"没跟随"看不出差别
     *   （实测：去掉"未编辑早退"那一行，④ 仍然全绿）。真实症状是这一条：
     *   编辑到文末 → 失焦 → 滚上去看别处 → 一次 rebuild 就闪回底部。
     *   这也是"未编辑不许跟随"唯一的判据 —— `caretRect()` 在 `caretOffset === null`
     *   时兜底成文本末尾，正是修前"点一下就跳到底"的同一条根因。
     */
    public function testNotEditingWithCaretAtEndDoesNotYankTheViewport(): void
    {
        $app = self::open();
        $this->clickBody($app);

        $state = $app->areaState();
        $state?->moveCaretToEnd();
        $this->pump($app, 2, 40.0);

        $atEnd = $this->offset($app);
        $state?->endEditing();
        $this->pump($app, 2, 50.0);

        // 滚回去看开头
        $this->dragThumb($app, -200.0);
        $dragged = $this->offset($app);

        $this->pump($app, 4, 60.0, rebuild: true);
        $after = $this->offset($app);

        check(
            '插入符跟随 · 退出编辑（插入符停在末尾）后滚走 ⇒ 重建不把视口拽回末尾',
            $atEnd > $dragged + 1.0 && \abs($after - $dragged) < 0.51,
            \sprintf('末尾偏移=%.1f 滚回后=%.1f 四次重建后=%.1f', $atEnd, $dragged, $after),
        );

        $this->flushChecks();
    }
}
