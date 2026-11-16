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
 *   · `steady`  —— 树没变时每帧的固定税（`--check` 判的就是它）。**逐帧采样取中位**，
 *                不是"总时间 / 帧数"（一帧撞上 GC 就能把整段抬走，实测 7.9→51.3µs）；
 *   · `rebuild` —— 每帧把根 `update()` 成一份等价新树（= 整页 `setState` 的形状），
 *                  分段是**每帧合计**（`SurfaceProfile::$ns`，不是 `perCall()`）；
 *   · `layout`  —— 同一棵树单次全量重排（暖缓存）。**这是 M5 想优化的那一笔**：
 *                  脏矩形能省的**上限**就是它，省不掉 settle 与 paint。
 *
 * ★★ **`layoutUs` / `rebuild` 是「中位数」，不是「平均」（2026-11-04 夜改）**：
 *   原来 layout 跑 5 次取平均、rebuild 跑 10 帧取平均。但 attach 之后第一次
 *   `layout()` 是首次强制重排，缓存最冷 —— 12k 节点树第一发 63.3ms vs 稳态 44.5ms
 *   （**+42%**），5 次平均里它占 1/5，把分母抬高 9–13%。而所有以 `layoutUs` 为分母的
 *   判据（帧预算余量、归一化比值、重建占比）都建在这个被抬高的数上。
 *   ⇒ 现在每段先跑几发**丢弃**（预热），再采 7 发 / 9 帧，主数取中位。
 *   同时给出 `layoutMinUs` / `layoutMaxUs` / `layoutSpread` 当**误差棒**。
 *   ⇒ **2026-11-04 之前记下的绝对值偏高 9–13%，横向对比要重跑。**
 *   ★ 剩下的抖动（同进程 20 次中位仍在 43–53ms 之间跳）去不掉：噪声源在进程级
 *     （CPU 频率 / 地址对齐），只能跨进程重复，而每档 45ms × 几十次付不起。
 *     所以误差棒要**印出来**，让人自己判断能不能当结论。
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

        // ★ 逐帧采样，再取中位。原来量的是"30 帧总时间 / 30"，只要其中一帧撞上 GC
        //   就能把整段抬走 —— 同一棵树同一天实测出 7.9 / 23.0 / 51.3µs 三个数（**6 倍**），
        //   而 `steady` 的定义本来就是"典型一帧"。逐帧采样加 30 次 `hrtime()` 调用，
        //   成本可以忽略，换来一个能复现的数。
        $frameUs = [];
        for ($i = 11; $i <= 10 + $frames; $i++) {
            $ft = \hrtime(true);
            $app->frame($i * 16.0, $tight);
            $frameUs[] = (\hrtime(true) - $ft) / 1000.0;
        }
        $steady = Samples::of($frameUs);

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
        //
        //   ★★ 2026-11-04 夜：口径从「5 次取平均」改成「2 发预热丢弃 + 7 发取中位」。
        //     理由不是洁癖，是**偏差**：attach 之后第一次 `layout()` 是首次强制重排，
        //     缓存最冷 —— 12k 节点树第一发 63.3ms vs 稳态 44.5ms（+42%）。
        //     5 次平均里它占 1/5，把分母抬高 9–13%，而**所有**以 `layoutUs` 为分母的
        //     判据（帧预算余量、归一化比值、重建占比）都建在这个被抬高的数上。
        //   ★ 为什么不靠"多测几次"消掉剩下的抖动：同进程 20 次的中位仍在 43–53ms
        //     之间跳，噪声源在进程级（CPU 频率 / 地址对齐），只能跨进程重复 ——
        //     每档 4.5s，`--scale` 又在 gate 里，付不起。这一棒只去掉**可去掉的偏差**，
        //     剩下的噪声用 min/max 标出来让人自己判断（`Samples::spread()`）。
        $rootEl = $app->root();
        $box = $rootEl?->effectiveBox();
        $layout = Samples::of([]);
        if ($box !== null) {
            // ★ 计数器叫 `$w` / `$s` 而不是 `$k`：下面 `foreach ($rebuild as $k => $v)`
            //   用的是**字符串**键，而 tpc 的变量类型是**静态**的 —— 同一个 `$k`
            //   先当 int 再当 string 会编不过（解释器不介意，AOT 介意）。
            for ($w = 0; $w < 2; $w++) {
                $box->layout($tight);
            }
            $us = [];
            for ($s = 0; $s < 7; $s++) {
                $lt = \hrtime(true);
                $box->layout($tight);
                $us[] = (\hrtime(true) - $lt) / 1000.0;
            }
            $layout = Samples::of($us);
        }

        // ③ 重建帧：每帧换一份等价新树（整页 setState 的形状）
        //   ★ 口径同 ②：第 0 帧丢弃（新树首帧最冷），取剩下 9 帧里**总和为中位的那一帧**。
        //     三段必须来自**同一帧** —— 各段各取中位会拼出一个不存在的帧
        //     （settle 来自第 3 帧、layout 来自第 7 帧），而这张表的用途正是
        //     "谁占大头"，拼出来的比例没有意义。
        $rebuild = ['settle' => 0, 'layout' => 0, 'paint' => 0];
        // 配对次数在本段之外读会混进 attach / 稳态帧；复杂度判据只要重建帧这一份。
        $pairs0 = Pipeline::current()->pairChecks();
        if ($rootEl !== null) {
            $warm = 1;
            $takes = 9;
            /** @var list<array{settle: int, layout: int, paint: int}> 纳秒 */
            $batch = [];
            /** @var list<float> */
            $totals = [];
            for ($i = 0; $i < $warm + $takes; $i++) {
                SurfaceProfile::reset();
                $rootEl->update($root());
                $app->frame((11 + $frames + $i) * 16.0, $tight);
                $one = ['settle' => 0, 'layout' => 0, 'paint' => 0];
                foreach (['settle', 'layout', 'paint'] as $k) {
                    $one[$k] = (int) (SurfaceProfile::$ns[$k] ?? 0);
                }
                if ($i >= $warm) {
                    $batch[] = $one;
                    $totals[] = (float) ($one['settle'] + $one['layout'] + $one['paint']);
                }
            }
            // 取"离中位最近的那一帧"而不是 sort 后取下标 —— 不用闭包、不用 usort，
            // tpc 下这两样都是额外风险（`docs/reference/aot-constraints.md` §1.2）。
            $medianTotal = Samples::of($totals)->median;
            $pick = 0;
            $best = \PHP_FLOAT_MAX;
            // ★ 循环变量必须换个新名字：tpc 给 `$one` 推成 `php::Array`（上面那次
            //   `$one[$k] = ...`），再在 foreach 里给它赋一个 `php::Var` 就编不过
            //   （报 `Cannot assign value to variable $one of type php::Array`）。
            //   解释器完全不介意 —— 这类错只有 AOT 会抓。
            foreach ($batch as $idx => $frame) {
                $gap = \abs((float) ($frame['settle'] + $frame['layout'] + $frame['paint']) - $medianTotal);
                if ($gap < $best) {
                    $best = $gap;
                    $pick = $idx;
                }
            }
            foreach ($rebuild as $k => $v) {
                // ★ 换成 µs：`SurfaceProfile::$ns` 是纳秒，而表头印的是 us。
                //   （这类"单位没换"在本仓出过 1000× 的事故，见 `formatNs()` 的注。）
                $rebuild[$k] = (int) \round($batch[$pick][$k] / 1000.0);
            }
        }

        $rebuildPairs = Pipeline::current()->pairChecks() - $pairs0;

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
            'perFrameUs' => \round($steady->median, 1),
            'perFrameMinUs' => \round($steady->min, 1),
            'perFrameMaxUs' => \round($steady->max, 1),
            'perFrameSpread' => \round($steady->spread(), 2),
            'perFrameText' => $steady->text(),
            // ★ 主数是中位，不是平均：平均会被冷发和离群拖着走（见 ② 的注）。
            'layoutUs' => \round($layout->median, 1),
            'layoutMinUs' => \round($layout->min, 1),
            'layoutMaxUs' => \round($layout->max, 1),
            'layoutSpread' => \round($layout->spread(), 2),
            'layoutText' => $layout->text(),
            'perNodeUs' => $nodes > 0 ? \round($layout->median / $nodes, 3) : 0.0,
            'coldUs' => \round(\array_sum($cold) / 1000.0, 1),
            'rebuildUs' => \round(\array_sum($rebuild), 1),   // 已经是 us（上面那次 foreach 换算过）
            'rebuild' => $rebuild,
            'rebuildPairs' => $rebuildPairs,
            'paint' => $paintRuns,
            'skip' => $skips,
            'seg' => $seg,
            'delta' => 'sv+' . $sv . ' box+' . $be . ' marked+' . $mk,
        ];
    }
}
