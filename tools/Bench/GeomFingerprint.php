<?php
declare(strict_types=1);

namespace Drift\Dev\Bench;

use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderObject;
use Drift\Widget\Element;
use Drift\Widget\Widget;

/**
 * 已布局树的**几何指纹** —— 用来证明「AOT 产物算出的尺寸/位置与解释器逐值相同」。
 *
 * ★ 为什么需要它（2026-11-04）：微基准顺带撞到 tpc 的一个语义 bug ——
 *   **`int / int` 被编译成整数除法**（`7 / 2 === 3`，而 `int/float`、`float/int`、
 *   `float/float` 都正确）。`src/` 里有 27 处除法，静态抽查都是 float 操作数，
 *   但"抽查过"不是证据：截断是**静默**的，不崩不报，只会让几何值偏一点。
 *   ⇒ 只有把两侧的每个盒都取出来比，才能说"框架没踩"。
 *
 * ★ 指纹口径：每节点 `宽/高@x,y`，三位小数，按 DFS 顺序拼接后取 md5。
 *   三位小数是刻意的 —— 布局本身有 `LayoutTolerance::EPSILON` 的容差，
 *   记到亚像素第三位足以暴露"整数截断"这种量级（≥0.5px）的差，又不会被
 *   浮点末位噪声误报。
 *
 * @internal 量具专用，不进公共 API
 */
final class GeomFingerprint
{
    /**
     * @return array{hash: string, nodes: int, sumW: float, sumH: float}
     */
    public static function of(Element $root): array
    {
        $box = $root->effectiveBox();
        if ($box === null) {
            return ['hash' => '-', 'nodes' => 0, 'sumW' => 0.0, 'sumH' => 0.0];
        }

        $parts = [];
        $sumW = 0.0;
        $sumH = 0.0;
        $stack = [$box];
        while ($stack !== []) {
            /** @var RenderBox $b */
            $b = \array_pop($stack);
            $s = $b->size();
            if ($s === null) {
                $parts[] = '-';
            } else {
                $parts[] = \sprintf(
                    '%.3f/%.3f@%.3f,%.3f',
                    $s->width,
                    $s->height,
                    $b->offsetX(),
                    $b->offsetY()
                );
                $sumW += $s->width;
                $sumH += $s->height;
            }

            $b->visitChildren(static function (RenderObject $c) use (&$stack): void {
                if ($c instanceof RenderBox) {
                    $stack[] = $c;
                }
            });
        }

        return [
            'hash' => \md5(\implode('|', $parts)),
            'nodes' => \count($parts),
            'sumW' => \round($sumW, 3),
            'sumH' => \round($sumH, 3),
        ];
    }

    /**
     * 逐节点明细（**定位差异用，不是判据**）：每行 `类名 宽x高@x,y`，DFS 顺序。
     *
     * ★ 指纹只能告诉你"这一页不一样"，明细才能告诉你"哪一层的哪一轴开始不一样"。
     *   两者都要有：指纹适合当判据（一行一个值），明细适合破案。
     *
     * @return list<string>
     */
    public static function detail(Element $root): array
    {
        $box = $root->effectiveBox();
        if ($box === null) {
            return [];
        }

        $out = [];
        $stack = [$box];
        while ($stack !== []) {
            /** @var RenderBox $b */
            $b = \array_pop($stack);
            $s = $b->size();
            $out[] = ($s === null)
                ? \substr(\strrchr('\\' . $b::class, '\\') ?: '?', 1) . ' -'
                : \sprintf(
                    '%-22s %9.3f x %9.3f @ %.3f,%.3f',
                    \substr(\strrchr('\\' . $b::class, '\\') ?: '?', 1),
                    $s->width,
                    $s->height,
                    $b->offsetX(),
                    $b->offsetY()
                );

            $b->visitChildren(static function (RenderObject $c) use (&$stack): void {
                if ($c instanceof RenderBox) {
                    $stack[] = $c;
                }
            });
        }

        return $out;
    }

    /**
     * 27 个真实页逐个取指纹 —— **两侧跑同一份**，输出逐行可比。
     *
     * @return list<array{page: string, hash: string, nodes: int, sumW: float, sumH: float}>
     */
    public static function allPages(float $w = 1200.0, float $h = 800.0): array
    {
        $tight = BoxConstraints::tight(new Size($w, $h));

        $out = [];
        foreach (ShowcasePages::names() as $name) {
            $app = self::mount($name, $tight, $w, $h);
            $root = $app->root();
            $fp = $root === null
                ? ['hash' => '-', 'nodes' => 0, 'sumW' => 0.0, 'sumH' => 0.0]
                : self::of($root);

            $out[] = $fp + ['page' => $name];
        }

        return $out;
    }

    /** 单独挂一页（明细模式用）。 */
    public static function mountPage(string $name, float $w = 1200.0, float $h = 800.0): ?Element
    {
        return self::mount($name, BoxConstraints::tight(new Size($w, $h)), $w, $h)->root();
    }

    private static function mount(string $name, BoxConstraints $tight, float $w, float $h): FrameTaxApp
    {
        $app = new FrameTaxApp(static fn (): Widget => ShowcasePages::make($name));
        $surface = new SurfaceProbe();
        $surface->resize((int) $w, (int) $h, 1.0);
        // `attach()` 自己跑一帧（mount + settle + layout + paint）—— 尺寸就在这一帧定下。
        $app->attach($surface, $tight);

        return $app;
    }
}
