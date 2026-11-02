<?php
declare(strict_types=1);

namespace Drift\Bench;

use Drift\App\Frame;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Rendering\FrameStage;
use Drift\Rendering\SurfaceProfile;
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
 * ★★ **例外（2026-10-27）**：`$frameGates` 为真时**不再复刻**，直接调 `Frame::run()`
 *   —— 因为"跳过 layout"这类**判据**是管线的语义，复刻一份必然漂（`Frame` 加一条
 *   判据、这里忘同步 ⇒ 量出来的成本不是生产成本，而且错得很安静：数字照样有、
 *   只是偏大）。滚动帧那一档走这条路。
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
     * @param null|\Closure(Element): void $perFrame 每帧**在 settle 之前**、**计时区内**调一次
     *        （收到根 Element）。给"真实交互"用：滚动就是"改状态 + 本帧落盘"，
     *        把 `scrollBy()` 放在计时区外会把它的成本藏起来 —— 而它正是被测对象。
     * @param bool $frameGates 走**真 `Frame`**（含"跳过 layout"的判据）而不是每帧
     *        无条件 `$box->layout()`。
     *
     *        ★★ 为什么必须有这个开关（2026-10-27）：本方法原本每帧无条件排一次，
     *        测的是"layout 本身多贵"。而生产走 `Frame`，它在"没重建 + 约束没变 +
     *        盒没换代"时会**跳过 layout** —— 滚动帧正是这种形态 ⇒ 用直调
     *        `layout()` 的口径去量滚动，会把"生产里根本不会发生的重排"算进账
     *        （实测 3.65ms，占一半），结论必然是"优化无效"。
     *        ★ 分段改由 `SurfaceProfile` 出（`Frame` 自己 add 三段）；`paint`
     *        段**含** `beginFrame`/`endFrame` ⇒ 与旧口径的 `paint + present` 对应。
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
        ?\Closure $perFrame = null,
        bool $frameGates = false,
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

        // ★ `$frameGates` 为真 ⇒ 用**生产同一条**管线（`Frame::run()`）。
        //   分段靠 `SurfaceProfile`（默认关，这里显式打开）。
        $frame = $frameGates ? new Frame($surface) : null;
        if ($frameGates) {
            SurfaceProfile::$on = true;
            SurfaceProfile::reset();
        }

        $settle = [];
        $layout = [];
        $paint = [];
        $present = [];
        $total = [];

        for ($i = 0; $i < $frames; $i++) {
            $ts = (float) $i * 16.6667;

            // ★★ 真 `Frame` 分支（`$frameGates`）：settle / layout（含跳过判据）/
            //   paint / present 全在 `Frame::run()` 里，分段由它自己 add 到
            //   `SurfaceProfile`。每帧 reset 一次 ⇒ 拿到的是**稳态单帧**的三段。
            //   ★ `$perFrame`（输入派发）刻意放在 reset 之前：它既不属于 settle
            //   也不属于 layout，只应计入 `total`。
            if ($frameGates && $frame !== null) {
                $t0 = \hrtime(true);
                if ($i >= self::WARMUP) {
                    SurfaceProfile::reset();
                }
                if ($perFrame !== null) {
                    $perFrame($root);
                }
                $frame->run($root, $constraints, $ts);
                $t4 = \hrtime(true);

                if ($i >= self::WARMUP) {
                    $settle[] = (int) (SurfaceProfile::$ns[FrameStage::SETTLE] ?? 0);
                    $layout[] = (int) (SurfaceProfile::$ns[FrameStage::LAYOUT] ?? 0);
                    // `Frame` 的 paint 段**含** begin/endFrame ⇒ 对应旧口径的
                    // paint + present，所以 present 单列 0（不是漏了）。
                    $paint[] = (int) (SurfaceProfile::$ns[FrameStage::PAINT] ?? 0);
                    $present[] = 0;
                    $total[] = $t4 - $t0;
                }

                continue;
            }

            // ① settle（帧末统一重建，§5.2 裁决 3）
            $t0 = \hrtime(true);
            if ($perFrame !== null) {
                // ★ 交互要**在计时区内**：生产里它发生在输入派发之后、settle 之前，
                //   本帧的成本就是"改状态 + 落盘 + 重排 + 重画"的合计。
                $perFrame($root);
            }
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
