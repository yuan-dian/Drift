<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Layout;

use Drift\Dev\Smoke\Layout\ImpureBox;
use Drift\Dev\Smoke\Layout\OffsetBox;
use Drift\Dev\Smoke\Layout\SolidBox;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\LayoutSelfCheck;
use Drift\Layout\Overflow;
use Drift\Layout\RenderBox;

/**
 * `RenderBox` 的**基类契约**（`04` §5 / §12.2 #14，§13.1 的 C1 / C3 / C5）。
 *
 * ## 为什么单独一个文件
 *
 * `FlexTest` / `StackTest` / `SizingTest` / `DockTest` 测的都是**某个容器的排法**；
 * 这里测的是**所有盒共享的那一层**：约束由谁定、尺寸存在哪、内禀读哪一轴、
 * 子怎么被遍历到、父的平移有没有落到设备像素、默认溢出策略是什么。
 * 这一层坏了，上面每个容器的测试会一起错，但**每条都只错一点点**，
 * 表现是"某个容器偏了几像素"，很难定位到这里。
 *
 * ## 与 `tests/Support/` 里夹具的分工
 *
 * | 夹具 | 用途 |
 * |---|---|
 * | `SolidBox` | 纯盒（只读约束）、显式声明 `Overflow::Clip` |
 * | `ImpureBox` | 读上一帧 `size()` ⇒ 不纯；**不覆写** `overflow()` ⇒ 用它验基类默认值 |
 * | `OffsetBox` | 定位单个子、把"给了子什么约束"暴露成可观测量 |
 *
 * ## 两条从 smoke 搬过来时**修正**的假绿
 *
 * ① 原标题"父给子的是自己那份" —— 夹具给的是**无界**，恰好是"自己那份"的反面。
 *    真实语义是 C1 的另一半：**约束由父决定，且可以不同于父自己收到的**。
 *    判据改成三连：子拿无界 + 子按自己声明排 + 父自己确实是 tight。
 * ② 原"默认溢出策略 = Clip"用的是 `SolidBox`，而它**显式覆写**了 `overflow()`
 *    ⇒ 那条断言只是"夹具返回夹具写死的值"。改用不覆写的 `ImpureBox`，
 *    并同时钉住 `clipsOverflow() === false`（与 `overflow()` 的 Clip **故意相反**）。
 */
final class RenderBoxContractTest extends SmokeTestCase
{
    private const EPS = 0.0001;

    private static function loose(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(1000.0, 1000.0));
    }

    /**
     * "布局是纯函数"这条判据**自己要有牙**。
     *
     * ★ 只拿 `SolidBox` 排两遍是**恒真**的：它的 `performLayout()` 只读约束，
     *   任何实现都会返回同一个尺寸。必须配一个真的不纯的盒（读上一帧 `size()`），
     *   证明"同约束两次 ⇒ 同尺寸"这句话在反例上会红。
     */
    public function testLayoutIsPureOnlyWhenTheBoxIs(): void
    {
        $constraints = self::loose();

        $impure = new ImpureBox();
        $first = $impure->layout($constraints);
        $second = $impure->layout($constraints);
        check(
            'C1 布局纯函数 · 反例（读上一帧 size 的盒）两次结果**不同** ⇒ 这条判据有牙',
            !$first->equals($second)
                && \abs($second->width - ($first->width + 1.0)) < self::EPS,
            \sprintf('第一次 %.4g，第二次 %.4g（应差 1）', $first->width, $second->width),
        );

        $pure = new SolidBox(new Size(10.0, 6.0), 24.0, 7.0);
        $a = $pure->layout($constraints);
        $b = $pure->layout($constraints);
        check(
            'C1 布局纯函数 · 纯盒（只读约束）同约束两次 ⇒ 逐字段相同',
            $a->equals($b),
            \sprintf('第一次 %.4g×%.4g，第二次 %.4g×%.4g', $a->width, $a->height, $b->width, $b->height),
        );
    }

    /**
     * 自校验开关（`LayoutSelfCheck`）自己也会空转 —— 采样没落到这棵树、
     * 快照漏了字段、`$on` 从没被翻开，表现全是**静默**的。
     *
     * ⇒ 一条夹具同时驱两个方向：纯盒必须 0 不一致（**不误报**），
     * 不纯盒必须 > 0（**真在守**）。
     *
     * ★ `SAMPLE_EVERY = 16` ⇒ 循环次数必须是 16 的倍数才采得到样。
     */
    public function testSelfCheckIsArmedAndQuietOnPureBoxes(): void
    {
        $constraints = self::loose();
        LayoutSelfCheck::$on = true;

        try {
            LayoutSelfCheck::reset();
            $pure = new SolidBox(new Size(40.0, 20.0), 40.0, 20.0);
            for ($i = 0; $i < 32; $i++) {
                $pure->layout($constraints);
                LayoutSelfCheck::maybeCheck($pure, $constraints);
            }
            $pureChecks = LayoutSelfCheck::$checks;
            $pureMismatches = LayoutSelfCheck::$mismatches;

            LayoutSelfCheck::reset();
            $impure = new ImpureBox();
            for ($i = 0; $i < 64; $i++) {
                $impure->layout($constraints);
                LayoutSelfCheck::maybeCheck($impure, $constraints);
            }
            $impureChecks = LayoutSelfCheck::$checks;
            $impureMismatches = LayoutSelfCheck::$mismatches;
            $lastDiff = LayoutSelfCheck::$recent === []
                ? null
                : LayoutSelfCheck::$recent[\count(LayoutSelfCheck::$recent) - 1];
        } finally {
            // ★ 必须落回 false：开关留着会让后续所有测试每 16 帧多排一次树。
            LayoutSelfCheck::$on = false;
        }

        check(
            '自校验 · 纯盒：真采样到了（checks > 0）且 0 不一致（不误报）',
            $pureChecks > 0 && $pureMismatches === 0,
            \sprintf('checks=%d mismatches=%d', $pureChecks, $pureMismatches),
        );
        check(
            '自校验 · 不纯盒：真采样到了且 > 0 不一致（开关打开时它真的在守）',
            $impureChecks > 0 && $impureMismatches > 0,
            \sprintf('checks=%d mismatches=%d', $impureChecks, $impureMismatches),
        );
        check(
            '自校验 · 不一致条目指名了类与字段（不是只有个数）',
            $lastDiff !== null
                && $lastDiff['class'] !== ''
                && $lastDiff['field'] !== '',
            $lastDiff === null ? 'recent 为空' : $lastDiff['class'] . ' · ' . $lastDiff['field'],
        );

        // 真机上 `users.exe` 是独立进程，`Errors` 的环形缓冲外面一条都读不到
        //   ⇒ "mismatches === 0" 会退化成"只能选择相信"。落盘出口必须真的落。
        $report = \getcwd() . '/build/layout.check.out';
        $reportMismatches = -1;
        if (\is_file($report)) {
            $text = (string) \file_get_contents($report);
            if (\preg_match('/^mismatches (\d+)$/m', $text, $m) === 1) {
                $reportMismatches = (int) $m[1];
            }
        }
        check(
            '自校验 · 报告文件真的落盘（含 mismatches 行，且与内存计数同值）',
            $reportMismatches > 0 && $reportMismatches === $impureMismatches,
            \sprintf('报告 %d / 内存 %d（文件 %s）', $reportMismatches, $impureMismatches, $report),
        );
    }

    /**
     * 尺寸是 `layout()` 的**产物**，不是构造时就有。
     *
     * ★ 必须两侧都断：只断"排完有尺寸"的话，一个在构造器里就把 `size` 填好的
     *   实现同样能过 —— 而那种实现会让"没排就画"静默成功。
     */
    public function testSizeIsStoredOnlyAfterLayout(): void
    {
        $box = new SolidBox(new Size(10.0, 6.0), 24.0, 7.0);
        check(
            'C1 尺寸 · 排之前 hasSize=false 且 size()=null（产物而非构造物）',
            $box->hasSize() === false && $box->size() === null,
            'hasSize=' . ($box->hasSize() ? 'true' : 'false') . ' size=' . ($box->size() === null ? 'null' : '有'),
        );

        $box->layout(self::loose());
        $size = $box->size();
        check(
            'C1 尺寸 · 排之后 hasSize=true 且尺寸 = performLayout 的返回值',
            $box->hasSize() === true
                && $size !== null
                && \abs($size->width - 10.0) < self::EPS
                && \abs($size->height - 6.0) < self::EPS,
            $size === null ? 'size() 仍是 null' : \sprintf('%.4g×%.4g', $size->width, $size->height),
        );
    }

    /**
     * C3：内禀测量要**读另一轴**，不能把自身尺寸回抄。
     *
     * ★ `SolidBox` 的内禀（24 / 7）刻意与盒尺寸（10 / 6）不同 —— 相等的话
     *   "回抄"与"真量"两种实现给出同一个数，判据就分不开。
     */
    public function testIntrinsicReadsTheOtherAxis(): void
    {
        $box = new SolidBox(new Size(10.0, 6.0), 24.0, 7.0);
        $box->layout(self::loose());
        $size = $box->size();

        check(
            'C3 内禀宽 = 构造给的值（24），且**不等于**自身宽（10）⇒ 不是回抄尺寸',
            \abs($box->intrinsicWidth(0.0) - 24.0) < self::EPS
                && $size !== null
                && \abs($size->width - 10.0) < self::EPS,
            \sprintf('intrinsicWidth=%.4g 自身宽=%.4g', $box->intrinsicWidth(0.0), $size?->width ?? -1.0),
        );
        check(
            'C3 内禀高 = 构造给的值（7），且**不等于**自身高（6）',
            \abs($box->intrinsicHeight(0.0) - 7.0) < self::EPS
                && $size !== null
                && \abs($size->height - 6.0) < self::EPS,
            \sprintf('intrinsicHeight=%.4g 自身高=%.4g', $box->intrinsicHeight(0.0), $size?->height ?? -1.0),
        );
    }

    /**
     * C1 单向数据流：**约束由父决定**，且可以不同于父自己收到的那份。
     *
     * ★ 这条原在 smoke 里叫"父给子的是自己那份" —— 与夹具行为相反（给的是无界）。
     *   三连判据才能同时证伪两个方向：
     *   ① 子拿到无界（不是父那份 tight 100×50）；
     *   ② 子按**自己声明**排成 8×5（若父把自己的 tight 传下去，子会被拉成 100×50）；
     *   ③ 父自己确实是 tight 100×50（否则 ① 无从谈起）。
     */
    public function testParentDecidesTheConstraintsGivenToItsChild(): void
    {
        $child = new SolidBox(new Size(8.0, 5.0), 8.0, 5.0);
        $holder = (new OffsetBox(new Size(100.0, 50.0)))->at(20.0, 10.0)->child($child);
        $own = BoxConstraints::tight(new Size(100.0, 50.0));
        $holder->layout($own);

        $seen = $holder->seenChildConstraints;
        $childSize = $child->size();

        check(
            'C1 约束由父定 · 父自己收到 tight(100×50)',
            $own->isTight() && \abs($own->maxWidth - 100.0) < self::EPS,
            \sprintf('maxWidth=%.4g tight=%s', $own->maxWidth, $own->isTight() ? 'true' : 'false'),
        );
        check(
            'C1 约束由父定 · 给子的是无界，**不是**父自己那份（父可另给一份）',
            $seen !== null && $seen->hasInfiniteWidth() && $seen->hasInfiniteHeight(),
            $seen === null ? 'seenChildConstraints 为 null（子没被排）' : \sprintf(
                'maxWidth=%.4g maxHeight=%.4g',
                $seen->maxWidth,
                $seen->maxHeight,
            ),
        );
        check(
            'C1 约束由父定 · 子按自己声明排成 8×5（父若透传 tight，子会被拉成 100×50）',
            $childSize !== null
                && \abs($childSize->width - 8.0) < self::EPS
                && \abs($childSize->height - 5.0) < self::EPS,
            $childSize === null ? '子没排' : \sprintf('%.4g×%.4g', $childSize->width, $childSize->height),
        );
    }

    /**
     * C1 另一半：**偏移由父写**，子不自定位；`absoluteOrigin()` 一路累加上去。
     *
     * ★ 两层嵌套：只验一层的话，"绝对原点"直接等于 `offset` 也能过 ——
     *   而真实的 bug 恰好在"父链累加"这一步（W5 之后子盒 offset 住内容盒坐标）。
     */
    public function testParentPositionsChildAndAbsoluteOriginAccumulates(): void
    {
        $leaf = new SolidBox(new Size(4.0, 4.0), 4.0, 4.0);
        $mid = (new OffsetBox(new Size(50.0, 50.0)))->at(5.0, 5.0)->child($leaf);
        $top = (new OffsetBox(new Size(80.0, 80.0)))->at(1.0, 1.0)->child($mid);
        $top->layout(BoxConstraints::tight(new Size(80.0, 80.0)));

        check(
            'C1 父定位子 · 偏移是父写的（leaf 相对 mid 是 5,5；mid 相对 top 是 1,1）',
            \abs($leaf->offsetX() - 5.0) < self::EPS
                && \abs($leaf->offsetY() - 5.0) < self::EPS
                && \abs($mid->offsetX() - 1.0) < self::EPS
                && \abs($mid->offsetY() - 1.0) < self::EPS,
            \sprintf('leaf=(%.4g,%.4g) mid=(%.4g,%.4g)', $leaf->offsetX(), $leaf->offsetY(), $mid->offsetX(), $mid->offsetY()),
        );

        [$ax, $ay] = RenderBox::absoluteOrigin($leaf);
        check(
            'C1 父定位子 · absoluteOrigin 沿父链累加（4,4 不是 leaf 自己的 5,5）',
            \abs($ax - 6.0) < self::EPS && \abs($ay - 6.0) < self::EPS,
            \sprintf('绝对原点 (%.4g, %.4g)', $ax, $ay),
        );
    }

    /**
     * `visitChildren()` 必须**递归**。
     *
     * ★ 只断"遍历到 1 个"会被"只返回直接子"的实现骗过；两层才能证伪。
     * ★ 顺序也钉住：父先于子（深度优先），否则"父链上先算父"的调用方会算错。
     */
    public function testVisitChildrenRecursesDepthFirst(): void
    {
        $leaf = new SolidBox(new Size(4.0, 4.0), 4.0, 4.0);
        $mid = (new OffsetBox(new Size(50.0, 50.0)))->at(5.0, 5.0)->child($leaf);
        $top = (new OffsetBox(new Size(80.0, 80.0)))->at(1.0, 1.0)->child($mid);
        $top->layout(BoxConstraints::tight(new Size(80.0, 80.0)));

        /** @var list<RenderBox> $seen */
        $seen = [];
        $top->visitChildren(static function (RenderBox $box) use (&$seen): void {
            $seen[] = $box;
        });

        check(
            'C1 遍历 · 两层都到（mid 与 leaf，共 2 个）',
            \count($seen) === 2,
            '实际 ' . \count($seen) . ' 个',
        );
        check(
            'C1 遍历 · 深度优先且父先于子（mid 在 leaf 之前）',
            ($seen[0] ?? null) === $mid && ($seen[1] ?? null) === $leaf,
            '顺序：' . \implode(', ', \array_map(static fn(RenderBox $b): string => $b::class, $seen)),
        );
    }

    /**
     * 父的平移必须落到**设备像素**上。
     *
     * ★ 两侧判据：偏移处有墨 + 原点**没有**墨。只断"有墨"的话，
     *   一个完全忽略偏移、把子画在左上角的实现照样有墨（第 10 种假绿形态）。
     */
    public function testPaintAppliesTheParentTranslate(): void
    {
        $child = new SolidBox(new Size(8.0, 5.0), 8.0, 5.0);
        $holder = (new OffsetBox(new Size(100.0, 50.0)))->at(20.0, 10.0)->child($child);
        $holder->layout(BoxConstraints::tight(new Size(100.0, 50.0)));

        $canvas = spinCanvas($holder, 40, 30);

        $atOffset = $canvas->pixelAt(21, 11);
        $atOrigin = $canvas->pixelAt(0, 0);
        check(
            'C1 绘制 · 子画在父给的偏移处（21,11 有墨）',
            $atOffset->r < 128,
            \sprintf('(21,11) r=%d', $atOffset->r),
        );
        check(
            'C1 绘制 · 原点没墨（偏移真的施加了，不是画在左上角）',
            $atOrigin->r >= 250,
            \sprintf('(0,0) r=%d', $atOrigin->r),
        );
    }

    /**
     * 基类默认溢出策略 = `Clip`，而 `clipsOverflow()` 默认 **false**。
     *
     * ★ 两者**故意相反**：`overflow()` 是"放不下时怎么办"的契约查询（默认值只是
     *   "不会超的容器"这个乐观假设），而裁剪要付每帧 `save/pushClip/popClip/restore`
     *   的代价 ⇒ 必须由真正会超的容器显式打开（`RenderScrollView` 自己 `pushClip()`）。
     *   所以这条判据不是"钉一个值"，而是"钉住这个不一致**是有意的**"。
     *
     * ★ 用 `ImpureBox`（**不覆写** `overflow()`）验默认值。原 smoke 用的是
     *   `SolidBox`，它显式返回 `Clip` ⇒ 那条断言只是夹具自证。
     */
    public function testOverflowDefaultIsClipButClipsOverflowIsFalse(): void
    {
        $plain = new ImpureBox();

        check(
            'C5 默认 · 不覆写的盒 overflow() = Clip（"不会超"的乐观假设）',
            $plain->overflow() === Overflow::Clip,
            '实际 ' . $plain->overflow()->name,
        );
        check(
            'C5 默认 · 同一盒 clipsOverflow() = false（与上面的 Clip 故意相反，裁剪要显式打开）',
            $plain->clipsOverflow() === false,
            '实际 ' . ($plain->clipsOverflow() ? 'true' : 'false'),
        );
    }
}
