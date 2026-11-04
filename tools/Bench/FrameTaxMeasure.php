<?php
declare(strict_types=1);

namespace Drift\Dev\Bench;

use Drift\Core\Pipeline;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Rendering\SurfaceProfile;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 「一档」的测量编排：冷帧 / 稳态帧 / 全量重排 / 重建帧三段 / 节点数。
 *
 * ★ **本类是从 `tools/frame-tax-probe.php` 的闭包里原样搬出来的**，搬家的唯一目的是
 *   让 `probes/frame_tax_aot` 能编译**同一段代码**：那个探针要回答「AOT 口径下三数是多少」，
 *   如果它是另写一份编排，测出来的东西就不叫"复量"了。
 *   ⇒ 改动本类 = 同时改了「解释器尺子」与「AOT 尺子」，两边数字必须一起重跑。
 *
 * ★ 口径分工（别混）：
 *   · `cold`    —— 首帧（建整棵树）。页面档里它是"第一次打开这一页"的成本；
 *   · `steady`  —— 树没变时每帧的固定税（`--check` 判的就是它）；
 *   · `rebuild` —— 每帧把根 `update()` 成一份等价新树（= 整页 `setState` 的形状），
 *                  分段是**每帧合计**（`SurfaceProfile::$ns`，不是 `perCall()`）；
 *   · `layout`  —— 同一棵树单次全量重排（暖缓存）。**这是 M5 想优化的那一笔**：
 *                  脏矩形能省的**上限**就是它，省不掉 settle 与 paint。
 *
 * @internal 量具专用，不进公共 API
 */
final class FrameTaxMeasure
{
    /**
     * 合成档的根：每行 = 一个 `Row` 带 3 个 `Text`，外层 `ScrollView` 给无界主轴（不会溢出）。
     *
     * ★ 用全量 `Column` 而不是 `LazyColumn`：后者只建可见项，节点数不随数据量变
     *   ⇒ 量不出"树大了每帧固定税怎么长"（那正是它的价值，不是缺陷）。
     *
     * ★★ **树形状是历史数字的一部分**：`DESIGN.md` §13.5 那张表、`--scale` 的
     *   归一化比值、AOT 探针的复量，全都建立在"每行 4 个节点"上。改这里等于让
     *   所有既有读数不可比 —— 要改就先在文档里把这些数作废。
     */
    public static function syntheticRoot(int $rows): \Closure
    {
        return static function () use ($rows): Widget {
            $list = [];
            for ($r = 0; $r < $rows; $r++) {
                $list[] = Row::make()->children([
                    Text::make('R' . (string) $r . 'A'),
                    Text::make('R' . (string) $r . 'B'),
                    Text::make('R' . (string) $r . 'C'),
                ]);
            }

            return ScrollView::make(Column::make()->children($list));
        };
    }

    /**
     * 量一档。
     *
     * @param \Closure(): Widget $root 建根（每次调用给一棵等价新树）
     *
     * @return array<string, mixed>
     */
    public static function run(string $label, \Closure $root, float $w, float $h, int $frames): array
    {
        $tight = BoxConstraints::tight(new Size($w, $h));
        $app = new FrameTaxApp($root);
        $surface = new SurfaceProbe();
        $surface->resize((int) $w, (int) $h, 1.0);

        // ① 冷帧 = **页面打开**：
        //   ★ `attach()` 自己就跑一帧（mount + settle + layout + paint），不需要再补
        //     `frame(0)` —— 补了就是**双计**（实测 TablePage 从 35ms 变 37.9ms，
        //     而且两帧的缓存状态不同，加起来没有意义）。
        SurfaceProfile::$on = true;
        SurfaceProfile::reset();
        $app->attach($surface, $tight);
        $cold = SurfaceProfile::$ns;

        for ($i = 1; $i <= 10; $i++) { // 再跑十帧预热，之后才是稳态
            $app->frame($i * 16.0, $tight);
        }

        SurfaceProfile::reset();
        $paint0 = Pipeline::current()->paintRunCount();
        $skip0 = Pipeline::current()->paintSkipCount();
        $sv0 = Pipeline::current()->structureVersion();
        $be0 = Pipeline::current()->layoutEpoch();
        $mk0 = Pipeline::current()->markedCount();

        $t0 = \hrtime(true);
        for ($i = 11; $i <= 10 + $frames; $i++) {
            $app->frame($i * 16.0, $tight);
        }
        $elapsed = \hrtime(true) - $t0;

        $sv = Pipeline::current()->structureVersion() - $sv0;
        $be = Pipeline::current()->layoutEpoch() - $be0;
        $mk = Pipeline::current()->markedCount() - $mk0;
        $seg = SurfaceProfile::perCall();
        // ★ 这两笔必须**现在**取：后面还要跑重建帧（每帧必画 10 次），
        //   放到 return 处取会把它们算进"稳态"（实测 10/30，看起来像门控退化）。
        $paintRuns = Pipeline::current()->paintRunCount() - $paint0;
        $skips = Pipeline::current()->paintSkipCount() - $skip0;

        // ② 同一棵树单次全量重排（暖缓存）—— M5 的分母。
        //   ★ 必须排在重建帧**之前**：重建帧每帧造一整棵新树（几百个对象），
        //     留下的垃圾会把 GC 压力带进后面每一次分配 —— 实测把 500 行的
        //     `layout(us)` 从 8.0ms 抬到 11.6ms（+45%），量具自己污染了自己的读数。
        $rootEl = $app->root();
        $box = $rootEl?->effectiveBox();
        $layoutNs = 0;
        if ($box !== null) {
            $reps = 5;
            $lt = \hrtime(true);
            // ★ 计数器叫 `$rep` 而不是 `$k`：下面 `foreach ($rebuild as $k => $v)`
            //   用的是**字符串**键，而 tpc 的变量类型是**静态**的 —— 同一个 `$k`
            //   先当 int 再当 string 会编不过（解释器不介意，AOT 介意）。
            for ($rep = 0; $rep < $reps; $rep++) {
                $box->layout($tight);
            }
            $layoutNs = (int) \round((\hrtime(true) - $lt) / $reps);
        }

        // ③ 重建帧：每帧换一份等价新树（整页 setState 的形状）
        $rebuild = ['settle' => 0, 'layout' => 0, 'paint' => 0];
        if ($rootEl !== null) {
            $reps = 10;
            for ($i = 0; $i < $reps; $i++) {
                SurfaceProfile::reset();
                $rootEl->update($root());
                $app->frame((11 + $frames + $i) * 16.0, $tight);
                foreach (['settle', 'layout', 'paint'] as $k) {
                    $rebuild[$k] += (int) (SurfaceProfile::$ns[$k] ?? 0);
                }
            }
            foreach ($rebuild as $k => $v) {
                // ★ 换成 µs：`SurfaceProfile::$ns` 是纳秒，而表头印的是 us。
                //   （这类"单位没换"在本仓出过 1000× 的事故，见 `formatNs()` 的注。）
                $rebuild[$k] = (int) \round($v / $reps / 1000.0);
            }
        }

        SurfaceProfile::$on = false;

        // ④ 节点数（页面档要它解释排名：是"页大"还是"页慢"）
        $nodes = 0;
        if ($rootEl !== null) {
            $stack = [$rootEl];
            while ($stack !== []) {
                $e = \array_pop($stack);
                $nodes++;
                $e->visitChildren(static function (Element $c) use (&$stack): void {
                    $stack[] = $c;
                });
            }
        }

        return [
            'label' => $label,
            'nodes' => $nodes,
            'perFrameUs' => \round($elapsed / $frames / 1000.0, 1),
            'layoutUs' => \round($layoutNs / 1000.0, 1),
            'perNodeUs' => $nodes > 0 ? \round($layoutNs / 1000.0 / $nodes, 3) : 0.0,
            'coldUs' => \round(\array_sum($cold) / 1000.0, 1),
            'rebuildUs' => \round(\array_sum($rebuild), 1),   // 已经是 us（上面那次 foreach 换算过）
            'rebuild' => $rebuild,
            'paint' => $paintRuns,
            'skip' => $skips,
            'seg' => $seg,
            'delta' => 'sv+' . $sv . ' box+' . $be . ' marked+' . $mk,
        ];
    }
}
