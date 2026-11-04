<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\App\Frame;
use Drift\Core\Pipeline;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\BuildContext;
use Drift\Widget\Element;
use Drift\Widget\LayoutBuilder;
use Drift\Widget\Padding;
use Drift\Widget\SizedBox;
use Drift\Widget\Widget;

/**
 * `LayoutBuilder`：按**本容器**的真实约束选子树，且**同一帧**就选对。
 *
 * ## 为什么"同一帧"是主判据
 *
 * 它要根治的正是"先按窗口宽造一版、下一帧才发现不对"。所以判据不是"最终对了"，
 * 而是"**跑一帧之后**就是对的"——`Frame::run()` 返回 `true`（paint 发生在收敛之后）
 * 且首轮 builder 拿到的 `maxWidth` 已经是内容盒宽。
 *
 * ## 为什么窗口宽必须 ≠ 盒宽
 *
 * 两者相等时，用 `BuildContext::$width` 也能蒙对，整个夹具验不出东西。
 * 这里外面套 `paddingAll(50)`：帧约束 200 ⇒ builder 必须看到 100。
 */
final class LayoutBuilderTest extends SmokeTestCase
{
    /** @var list<BoxConstraints> builder 每次收到的约束（顺序即调用顺序） */
    private array $seen = [];

    /** 每帧的 builder 返回值宽度（< 150 走窄，否则走宽） */
    private float $narrowWidth = 10.0;

    private float $wideWidth = 20.0;

    private function builder(): \Closure
    {
        return function (BuildContext $ctx, BoxConstraints $c): Widget {
            $this->seen[] = $c;

            $narrow = $c->hasBoundedWidth() && $c->maxWidth < 150.0;

            return SizedBox::make()->size($narrow ? $this->narrowWidth : $this->wideWidth, 10.0);
        };
    }

    /** 造"帧约束 ≠ 盒约束"的树：外面 50 的内边距 */
    private static function tree(\Closure $builder): Element
    {
        $root = Padding::make(LayoutBuilder::make($builder))->paddingAll(50.0);
        $element = $root->createElement();
        $element->mount(null, smokeContext());

        return $element;
    }

    /** 壳（LayoutBuilder 的纯转发盒）的实测宽 —— builder 选出的子树有多宽 */
    private static function shellWidth(Element $root): float
    {
        $size = $root->childAt(0)?->childAt(0)?->effectiveBox()?->size();

        return $size === null ? -1.0 : $size->width;
    }

    public function testUsesOwnConstraintsInSameFrame(): void
    {
        $pipeline = Pipeline::current();
        $before = $pipeline->constraintObserverCount();

        $surface = new SurfaceProbe();
        $surface->resize(200, 200, 1.0);
        $frame = new Frame($surface);
        $root = self::tree($this->builder());
        check(
            'LayoutBuilder · 挂载即登记为约束观察者（否则永远没人问它约束）',
            $pipeline->constraintObserverCount() === $before + 1,
            'count=' . (string) $pipeline->constraintObserverCount() . ' 期望 ' . (string) ($before + 1),
        );

        // ── ① 首帧：约束未知 ⇒ 不调 builder（不拿窗口尺寸伪装成本盒约束） ──
        // ★ 用**松**约束：紧约束会把每个盒都撑满，几何就分不出 builder 选了哪个分支。
        $painted = $frame->run($root, BoxConstraints::loose(new Size(200.0, 200.0)), 16.0);
        check(
            'LayoutBuilder · 首帧 builder 只被调一次（不是先拿一个猜的值调一次）',
            \count($this->seen) === 1,
            'calls=' . (string) \count($this->seen),
        );
        check(
            'LayoutBuilder · builder 拿到的是**本盒**约束（200 − 2×50 = 100），不是帧约束',
            isset($this->seen[0]) && \abs($this->seen[0]->maxWidth - 100.0) < 0.01,
            isset($this->seen[0]) ? 'maxWidth=' . (string) $this->seen[0]->maxWidth : '一次都没调',
        );
        check(
            'LayoutBuilder · 首帧就画出窄版式（同帧收敛，不是下一帧才跳）',
            \abs(self::shellWidth($root) - $this->narrowWidth) < 0.01,
            'w=' . (string) self::shellWidth($root),
        );
        check('LayoutBuilder · 收敛完才 paint（run 返回 true）', $painted, $painted ? '' : '整帧被跳过');
        check(
            'LayoutBuilder · 首帧收敛用了 2 轮（未知 → 已知）',
            $pipeline->constraintRounds() === 2,
            'rounds=' . (string) $pipeline->constraintRounds(),
        );

        // ── ② 稳态：约束没变 ⇒ 不再调 builder，且只排 1 遍 ──
        $again = $frame->run($root, BoxConstraints::loose(new Size(200.0, 200.0)), 32.0);
        check(
            'LayoutBuilder · 相同约束不重复调 builder（相等门控）',
            \count($this->seen) === 1,
            'calls=' . (string) \count($this->seen),
        );
        check(
            'LayoutBuilder · 稳态只跑 1 轮 layout（不放大全树布局开销）',
            $pipeline->constraintRounds() === 1,
            'rounds=' . (string) $pipeline->constraintRounds(),
        );
        check('LayoutBuilder · 稳态整帧跳过（没有东西要画）', $again === false, $again ? '又画了一帧' : '');

        // ── ③ 变宽：约束变了 ⇒ 同帧切到宽版式 ──
        $resized = $frame->run($root, BoxConstraints::loose(new Size(400.0, 200.0)), 48.0);
        check(
            'LayoutBuilder · 约束变化后 builder 收到新值（400 − 100 = 300）',
            isset($this->seen[1]) && \abs($this->seen[1]->maxWidth - 300.0) < 0.01,
            isset($this->seen[1]) ? 'maxWidth=' . (string) $this->seen[1]->maxWidth : '没重调',
        );
        check(
            'LayoutBuilder · resize 后**同一帧**画出宽版式',
            \abs(self::shellWidth($root) - $this->wideWidth) < 0.01,
            'w=' . (string) self::shellWidth($root),
        );
        check('LayoutBuilder · resize 帧也收敛完才 paint', $resized, $resized ? '' : '整帧被跳过');
        check(
            'LayoutBuilder · resize 帧 2 轮收敛',
            $pipeline->constraintRounds() === 2,
            'rounds=' . (string) $pipeline->constraintRounds(),
        );

        // ── ④ 注销：卸载后不留在观察者表里（漏了就是泄漏 + 死子树被标脏） ──
        $root->unmount();
        check(
            'LayoutBuilder · 卸载后从观察者表移除',
            $pipeline->constraintObserverCount() === $before,
            'count=' . (string) $pipeline->constraintObserverCount() . ' 期望 ' . (string) $before,
        );

        $this->flushChecks();
    }

    public function testUnboundedIsNotWide(): void
    {
        $surface = new SurfaceProbe();
        $surface->resize(200, 200, 1.0);
        $frame = new Frame($surface);
        $root = self::tree($this->builder());

        // 无界（滚动视图 / Row 的子就是这样）：builder 必须看见"无界"，不能当成很宽
        $frame->run($root, BoxConstraints::unbounded(), 16.0);
        $first = $this->seen[0] ?? null;
        check(
            'LayoutBuilder · 无界约束如实传给 builder（不是编一个很大的有界值）',
            $first !== null && $first->hasInfiniteWidth() && $first->hasInfiniteHeight(),
            $first === null ? '一次都没调' : 'max=' . (string) $first->maxWidth . '×' . (string) $first->maxHeight,
        );

        $root->unmount();
        $this->flushChecks();
    }

    public function testOscillationIsBoundedAndDiagnosed(): void
    {
        $pipeline = Pipeline::current();
        Errors::reset();

        $surface = new SurfaceProbe();
        $surface->resize(200, 200, 1.0);
        $frame = new Frame($surface);
        $root = self::tree($this->builder());

        // 永远换一句新的 ⇒ 只能靠轮数上限刹住
        $endless = new StuckConstraintObserver(
            static fn(int $i): string => 'drift-' . (string) $i,
        );
        $pipeline->addConstraintObserver($endless);

        try {
            $frame->run($root, BoxConstraints::tight(new Size(200.0, 200.0)), 16.0);

            check(
                'LayoutBuilder · 永不收敛时停在轮数上限（不死循环）',
                $pipeline->constraintRounds() === 4,
                'rounds=' . (string) $pipeline->constraintRounds(),
            );
            $diag = \implode("\n", Errors::recentDiagnostics());
            check(
                'LayoutBuilder · 超限报 diagnostic 且带漂移来源',
                \str_contains($diag, '4 轮') && \str_contains($diag, 'drift-'),
                $diag === '' ? '没有任何诊断' : $diag,
            );
        } finally {
            $pipeline->removeConstraintObserver($endless);
        }

        // A→B→A：重复的漂移描述比"轮数用光"更早刹住
        Errors::reset();
        $flip = new StuckConstraintObserver(
            static fn(int $i): string => $i % 2 === 0 ? 'A' : 'B',
        );
        $pipeline->addConstraintObserver($flip);

        try {
            $frame->run($root, BoxConstraints::loose(new Size(200.0, 200.0)), 32.0);

            check(
                'LayoutBuilder · A→B→A 震荡在第 3 轮就被识别（不等轮数用光）',
                $pipeline->constraintRounds() === 3,
                'rounds=' . (string) $pipeline->constraintRounds(),
            );
            $diag = \implode("\n", Errors::recentDiagnostics());
            check(
                'LayoutBuilder · 震荡诊断写明"检测到震荡"并带最后一句漂移',
                \str_contains($diag, '检测到震荡') && \str_contains($diag, '：A。'),
                $diag === '' ? '没有任何诊断' : $diag,
            );
        } finally {
            $pipeline->removeConstraintObserver($flip);
        }

        $root->unmount();
        $this->flushChecks();
    }
}
