<?php
declare(strict_types=1);

namespace Drift\Bench;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Theme\ThemeData;
use Drift\Widget\BuildContext;
use Drift\Widget\Element;
use Drift\Widget\Widget;

/**
 * M3：每阶段耗时分解（**三个基准里最重要的一个**）。
 *
 * 直接回答"v7 的 measure/place 拆分值不值得"：如果 layout 只占 15%，那套改动的
 * 收益上限就是 15%，而它要动 23 个文件。
 *
 * ── 为什么这里**复刻** `Frame::run()` 而不改它 ────────────────────────────
 * `src/App/Frame.php` 的四段（settle / layout / paint / present）是 DESIGN §5.2
 * 定死的管线。阶段 0 的目标是**零代码风险**，所以不在热路径上加计时代码，而是
 * 在量具里按同样的顺序把四段做一遍。
 *
 * ★★ **同步义务**：`Frame::run()` 的结构一旦变化（加段 / 改顺序 / 改 early return），
 *   这里必须同步改，否则 M3 量的就不是真实管线。改动时把 `src/App/Frame.php`
 *   和本文件一起看。
 *
 * ── 分母怎么取 ──────────────────────────────────────────────────────────
 * 无头 `RecordingCanvas` 的 `endFrame()` 只是计数（≈ 0ns），而真机 Skia 的 present
 * 是 29.9ms 里的大头。所以：
 *   · `present` 这一列在无头下**没有意义**，只用来确认它确实≈0；
 *   · 算占比时用 **`layout / (settle + layout + paint)`** —— 这是"PHP 侧可优化
 *     部分"的真实分母。真机的 present 成本属于另一个基准（需要窗口，见 `25` §3）。
 *
 * ★★ **paint 段必须是 record 而不是光栅化**（第一版用 `RasterCanvas`，实测踩到）：
 *   `RasterCanvas::beginFrame()` 会 `clear()` 一次 1280×800 全屏 —— 102 万像素的
 *   逐像素 PHP 循环 ⇒ showcase 的 paint 量到 **1078ms/帧**，而 layout 只有 0.87ms，
 *   占比算出来 0.1%，**结论完全反了**。光栅化在生产里是 Skia 的 C++ 干的，
 *   PHP 侧不参与 ⇒ 那 1078ms 是"只存在于测试路径"的成本，不是被优化对象。
 */
final class M3
{
    /** 前若干帧丢弃：首帧要 build 整棵树，后面才是稳态 */
    private const WARMUP = 10;

    /**
     * @param null|\Closure(): Widget $factory 给了就**每帧重建**（模拟"这一帧 State 重建了"）：
     *        在 settle 计时区内新建一份等价树并 `update()` 根，于是整棵树走
     *        `rebuild()` + `updateChildren()`。不给 ⇒ 干净帧（只有 settlePass 的
     *        无条件全遍，测的是"没有任何脏节点时每帧的固定税"）。
     *
     * @return array{
     *   label: string, nodes: int, frames: int,
     *   settle: int, layout: int, paint: int, present: int, total: int
     * }
     */
    public static function runTree(
        string $label,
        Widget $rootWidget,
        float $w,
        float $h,
        int $frames,
        ?\Closure $factory = null,
    ): array {
        $metrics = new DeterministicMetricsProvider(
            DeterministicFontManager::standard()->collection(),
            new DeterministicShaper(),
        );
        $ctx = new BuildContext($w, $h, ThemeData::light(), $metrics);

        $root = $rootWidget->createElement();
        $root->mount(null, $ctx);
        $nodes = self::countElements($root);

        $surface = new Surface();
        $surface->resize((int) $w, (int) $h, 1.0);

        $constraints = BoxConstraints::tight(new Size($w, $h));

        $settle = [];
        $layout = [];
        $paint = [];
        $present = [];
        $total = [];

        for ($i = 0; $i < $frames; $i++) {
            $ts = (float) $i * 16.6667;

            // ① settle（帧末统一重建，§5.2 裁决 3）
            $t0 = \hrtime(true);
            if ($factory !== null) {
                // ★ 重建要**在计时区内**：生产里 `build()` 就是 settle 的一部分，
                //   挪到外面会把"配对 + 重建"的成本藏起来。
                $root->update($factory());
            }
            $root->settle();
            $t1 = \hrtime(true);

            // ② layout
            $box = $root->effectiveBox();
            if ($box !== null) {
                $box->layout($constraints);
            }
            $t2 = \hrtime(true);

            // ③ paint（beginFrame 的画布分配算在绘制里 —— 它确实是绘制的固定成本）
            $canvas = $surface->beginFrame($ts);
            if ($box !== null) {
                $box->paint($canvas);
            }
            $t3 = \hrtime(true);

            // ④ present
            $surface->endFrame();
            $t4 = \hrtime(true);

            if ($i >= self::WARMUP) {
                $settle[] = $t1 - $t0;
                $layout[] = $t2 - $t1;
                $paint[] = $t3 - $t2;
                $present[] = $t4 - $t3;
                $total[] = $t4 - $t0;
            }
        }

        return [
            'label' => $label,
            'nodes' => $nodes,
            'frames' => \count($total),
            'settle' => Stats::median($settle),
            'layout' => Stats::median($layout),
            'paint' => Stats::median($paint),
            'present' => Stats::median($present),
            'total' => Stats::median($total),
        ];
    }

    /**
     * 数 Element 节点数（用于把耗时折算成"每节点多少纳秒"）。
     */
    public static function countElements(Element $e): int
    {
        $n = 1;
        $e->visitChildren(static function (Element $child) use (&$n): void {
            $n += self::countElements($child);
        });

        return $n;
    }

    /**
     * 一句话结论（写进报告用）。
     *
     * @param array{settle: int, layout: int, paint: int, present: int, total: int} $r
     */
    public static function verdict(array $r): string
    {
        $phpSide = $r['settle'] + $r['layout'] + $r['paint'];
        if ($phpSide <= 0) {
            return 'n/a';
        }

        $layoutPct = (float) $r['layout'] * 100.0 / (float) $phpSide;

        if ($layoutPct < 20.0) {
            return \sprintf('layout 占 %.1f%% ⇒ measure/place 收益上限 %.1f%%，推后', $layoutPct, $layoutPct);
        }
        if ($layoutPct > 40.0) {
            return \sprintf('layout 占 %.1f%% ⇒ measure/place 优先', $layoutPct);
        }

        return \sprintf('layout 占 %.1f%% ⇒ 灰区，按 Cell 结果决定先后', $layoutPct);
    }
}
