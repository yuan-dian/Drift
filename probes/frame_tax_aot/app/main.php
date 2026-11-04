<?php
declare(strict_types=1);

namespace Probe\FrameTaxAot {

use Drift\Dev\Bench\FrameTaxMeasure;

/**
 * 第 2 道门 —— **帧税三数在 tpc AOT(`-m bin`) 产物里的复量**。
 *
 * ## 它回答的那个问题
 *
 * `DEBT` §28.2 用「12k 全量重排 51ms / 8ms 线 = 6.2×」判「增量布局暂不立项」，
 * 而 §28.7 的诚实边界自己写着：**那是 PHP 解释器 + 软光栅，不是 AOT 生产**。
 * 于是这条裁决的分量取决于一个从未测过的比值 —— **AOT 比解释器快多少**。
 *
 * 三种可能的结果，每一种都有用：
 *
 * | 结果 | 含义 |
 * |---|---|
 * | AOT 快 3–5×，12k 全量重排掉到 8ms 线下 | 裁决**加强**：更不需要增量布局 |
 * | AOT 只快 1.2×，仍在线上 | 裁决维持，但**数字终于可信**了 |
 * | AOT 反而更慢 | 说明 AOT 某条路径有问题，比裁决本身更要紧 |
 *
 * ## 为什么它测的是"同一把尺子"
 *
 * 编排与树形状都在 `Drift\Dev\Bench\FrameTaxMeasure` 里，`tools/frame-tax-probe.php`
 * 与**本探针**调的是同一份代码（`sources` 直接引 `../../tools`）。
 * ⇒ 两份数字可以直接对表，不存在"探针测的是另一份实现"这种自欺。
 *
 * ★ 唯一的差异是**运行时**：这边是 tpc 编译后的产物，那边是 PHP 解释器。
 *   这正是要量的东西。
 *
 * ## 判据（与 `--scale` 逐条同义）
 *
 * | # | 判据 | 能红的真实事故 |
 * |---|---|---|
 * | ① | 大档节点数 ≥ 10000 | 档位没建起来，后面所有数字都是小的 |
 * | ② | 四数齐全且非零 | 计时没接上 / AOT 下 `hrtime` 或 `SurfaceProfile` 失效 |
 * | ③ | 归一化自洽：大档 / 小档的 `us/节点` ∈ [0.5, 2.0] | 某档被别的东西污染（GC / 预热不足） |
 * | ④ | 稳态帧 ≤ 8000µs | 固定税失控（本仓的老红线，产品要求不是机器速度） |
 * | ⑤ | `rebuild.layout ≥ layoutUs / 2` | layout 门控退化 ⇒ 重建帧不再全量重排 |
 *
 * ★ 五条**没有一条**拿绝对耗时当红线（④ 是产品要求，与 `--scale` 同一条）。
 */

final class Fit
{
    public static int $pass = 0;
    public static int $fail = 0;
}

function say(string $msg): void
{
    echo $msg . "\n";
    \flush();
}

function ok(string $tag, string $msg): void
{
    Fit::$pass++;
    say('[PASS] ' . $tag . ' ' . $msg);
}

function bad(string $tag, string $msg): void
{
    Fit::$fail++;
    say('[FAIL] ' . $tag . ' ' . $msg);
}

function main(): void
{
    // ★ AOT 产物的默认 `memory_limit` 是 128MB，而解释器那边跑 `--scale` 时是 `-1`
    //   （无限制）—— **不把它对齐，两把尺子量的就不是同一件事**：
    //   12k 档在 AOT 下会直接 OOM（软光栅的 DisplayList + 12k 个 `php::Var` 装箱）。
    //   这个数不是"框架要 1GB"，是"12k 节点 + 软光栅要 >128MB"，本身就是个数据点。
    @\ini_set('memory_limit', '1024M');

    say('=== 第 2 道门：帧税三数 on tpc AOT (-m bin) ===');
    say('php=' . \PHP_VERSION . '  口径=tpc AOT + 软光栅（SurfaceProbe / DisplayList）');
    say('memory_limit=' . (string) \ini_get('memory_limit'));
    say('');

    /** 与 `--scale` 同档位、同帧数 —— 两份数字必须能逐列对表 */
    $sizes = [500, 3000];
    $frames = 30;

    /** @var array<int, array<string, mixed>> */
    $got = [];
    foreach ($sizes as $rows) {
        $got[$rows] = FrameTaxMeasure::run(
            (string) $rows,
            FrameTaxMeasure::syntheticRoot($rows),
            400.0,
            300.0,
            $frames,
        );
    }

    \printf(
        "%6s %8s %12s %13s %13s %10s %6s %5s\n",
        'rows',
        'nodes',
        'cold(us)',
        'perFrame(us)',
        'layout(us)',
        'us/node',
        'paint',
        'skip',
    );
    foreach ($sizes as $rows) {
        $r = $got[$rows];
        \printf(
            "%6d %8d %12.1f %13.1f %13.1f %10.3f %6d %5d\n",
            $rows,
            (int) $r['nodes'],
            (float) $r['coldUs'],
            (float) $r['perFrameUs'],
            (float) $r['layoutUs'],
            (float) $r['perNodeUs'],
            (int) $r['paint'],
            (int) $r['skip'],
        );
        \printf(
            "       重建帧: settle %d / layout %d / paint %d us（合计 %s us）\n",
            (int) $r['rebuild']['settle'],
            (int) $r['rebuild']['layout'],
            (int) $r['rebuild']['paint'],
            (string) $r['rebuildUs'],
        );
        \printf("       版本: %s\n", (string) $r['delta']);
    }

    $small = $got[500];
    $big = $got[3000];

    if ((float) $small['perNodeUs'] > 0.0) {
        \printf(
            "       ↗ 每行: layout +%.3f us/行, 归一化 %.3f×\n",
            ((float) $big['perNodeUs'] - (float) $small['perNodeUs']),
            (float) $big['perNodeUs'] / (float) $small['perNodeUs'],
        );
    }
    say('');

    // ① 大档真的建起来了
    if ((int) $big['nodes'] >= 10000) {
        ok('①', '大档真建起来了：' . (string) $big['nodes'] . ' 节点（≥10000）');
    } else {
        bad('①', '大档只有 ' . (string) $big['nodes'] . ' 节点 —— 档位没建起来');
    }

    // ② 四数齐全且非零
    $cold = (float) $big['coldUs'];
    $steady = (float) $big['perFrameUs'];
    $full = (float) $big['layoutUs'];
    $rebuildLayout = (float) $big['rebuild']['layout'];
    if ($cold > 0.0 && $steady > 0.0 && $full > 0.0 && $rebuildLayout > 0.0) {
        ok('②', '四数齐全且非零：冷 ' . (string) $cold . ' / 稳态 ' . (string) $steady
            . ' / 全量 ' . (string) $full . ' / 重建 layout ' . (string) $rebuildLayout . ' us');
    } else {
        bad('②', '有数为零：冷=' . (string) $cold . ' 稳态=' . (string) $steady
            . ' 全量=' . (string) $full . ' 重建=' . (string) $rebuildLayout);
    }

    // ③ 归一化自洽
    $ratio = (float) $small['perNodeUs'] > 0.0
        ? (float) $big['perNodeUs'] / (float) $small['perNodeUs']
        : 0.0;
    if ($ratio >= 0.5 && $ratio <= 2.0) {
        ok('③', '归一化自洽：' . \sprintf('%.3f', (float) $big['perNodeUs']) . ' / '
            . \sprintf('%.3f', (float) $small['perNodeUs']) . ' us每节点 = '
            . \sprintf('%.2f', $ratio) . '×（容许 0.5–2.0）');
    } else {
        bad('③', '归一化不自洽：' . \sprintf('%.2f', $ratio) . '× —— 某档被污染了');
    }

    // ④ 稳态帧（产品要求，不是机器速度）
    if ($steady <= 8000.0) {
        ok('④', '稳态帧 ' . \sprintf('%.1f', $steady) . 'us ≤ 8000us（8ms 产品要求）');
    } else {
        bad('④', '稳态帧 ' . \sprintf('%.1f', $steady) . 'us > 8000us —— 固定税失控');
    }

    // ⑤ layout 仍是全量（脏路径没偷偷生效）
    if ($rebuildLayout >= $full / 2.0) {
        ok('⑤', 'layout 仍是全量：重建帧 ' . \sprintf('%.1f', $rebuildLayout)
            . 'us ≥ 全量重排 ' . \sprintf('%.1f', $full) . 'us 的一半');
    } else {
        bad('⑤', '重建帧 layout 只有全量的 '
            . \sprintf('%.1f%%', $full > 0.0 ? $rebuildLayout * 100.0 / $full : 0.0)
            . ' —— layout 门控退化了');
    }

    say('');
    say('--- 汇总：PASS ' . (string) Fit::$pass . ' / FAIL ' . (string) Fit::$fail . ' ---');

    if (Fit::$fail > 0) {
        \exit(1);
    }
}

}

/**
 * ★ Embed SAPI 要求**全局** `main()`（`probes/fiber_scope` 踩过同一条）：
 *   把实现放在命名空间里、只在这里开一个全局入口 —— 否则 tpc 报
 *   `The Embed SAPI requires a global main() function`。
 */
namespace {
    /**
     * 壳函数替身。真实实现在 `cpp-src/drift_win32.cc`，而本探针**不链接 `cpp-src`**
     * （只要布局/渲染，不要窗口）。解释器侧同一个 stub 在 `tools/autoload.php`，
     * 返回值也一致：**`1` 而不是 `0`** —— `0` 表示"未挂载"，`Application` 拿它当
     * "还没 attach" 的哨兵，用它顶替会让 UI 线程断言静默失效。
     *
     * ★ 探针是单线程的，"UI 线程"就是当前线程 ⇒ 固定非零值即可。
     */
    function drift_thread_id(): int
    {
        return 1;
    }

    function main(): void
    {
        \Probe\FrameTaxAot\main();
    }
}
