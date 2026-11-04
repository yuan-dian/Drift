<?php
declare(strict_types=1);

namespace Probe\FrameTaxAot {

use Drift\Dev\Bench\FrameTaxMeasure;
use Drift\Dev\Bench\GeomFingerprint;
use Drift\Dev\Bench\ShowcasePages;
use Drift\Widget\Widget;

/**
 * 第 2 道门 —— **帧税三数在 tpc AOT(`-m bin`) 产物里的复量**。
 *
 * ## 它回答的那个问题
 *
 * `DEBT` §28.2 用「12k 全量重排 51ms / 8ms 线 = 6.2×」判「增量布局暂不立项」，
 * 而 §28.7 的诚实边界自己写着：**那是 PHP 解释器 + 软光栅，不是 AOT 生产**。
 * 于是这条裁决的分量取决于一个从未测过的比值 —— **AOT 比解释器快多少**。
 *
 * 实测（DEBT §34）：**AOT 反而慢 1.7–2.7×**。本探针现在跑两档：
 *
 * | 档 | 分母 | 回答什么 |
 * |---|---|---|
 * | **页面档**（27 个真实页） | `apps/showcase` 的 27 页 | 「真实页面还有多少余量」—— 裁决的另一根柱子 |
 * | **合成档**（12k 节点） | 500 / 3000 行的合成树 | 「大树的全量重排到底多贵」 |
 *
 * ## 为什么它测的是"同一把尺子"
 *
 * 编排在 `Drift\Dev\Bench\FrameTaxMeasure`、页面清单在 `Drift\Dev\Bench\ShowcasePages`，
 * `tools/frame-tax-probe.php` 与**本探针**调的是同一份代码。
 * ⇒ 两份数字可以直接对表，不存在"探针测的是另一份实现"这种自欺。
 *
 * ★ 唯一的差异是**运行时**：这边是 tpc 编译后的产物，那边是 PHP 解释器。
 *
 * ★★ 页面档**必须跑在合成段之前**：12k 档内存峰值 >128MB，先跑它会把 PHP 内存池
 *   撑大并打碎，页面段的数字就不再是"进程干净时"的数字。而解释器那边的 `--pages`
 *   本来就是**独立进程**只跑这一档 ⇒ **顺序对齐才算同一件事**。
 *
 * ## 判据：与解释器的 `--pages --check` / `--scale` 逐条同义
 *
 * | 本探针 | 对应解释器 | 判据 | 能红的真实事故 |
 * |---|---|---|---|
 * | P① | `--pages` ① | 扫到 ≥20 页 | 清单被改小，分母缩水 |
 * | P② | `--pages` ② | 最重页全量重排 ≤ 16.7ms | 真实页面掉出帧预算 |
 * | P③ | `--pages` ③ | 每页暖态全量重排 ≤ 页面打开 | 缓存没生效 |
 * | P④ | `--pages` ④ | 重建 layout ≥ 全量重排 / 2 | layout 已变增量 ⇒「M5 上限 = 全量重排」不成立 |
 * | P⑤ | `--pages` ⑥ | 每页 ≥3 节点、合计 ≥1000 | **页面渲染成空**（②是上界，抓不住） |
 * | S① | `--scale` ① | 大档节点数 ≥ 10000 | 档位没建起来 |
 * | S② | `--scale` ② | 四数齐全且非零 | 计时没接上 |
 * | S③ | `--scale` ③ | 大档/小档 `us/节点` ∈ [0.5, 2.0] | 某档被污染 |
 * | S④ | `--scale` ④ | 稳态帧 ≤ 8000µs | 固定税失控 |
 * | S⑤ | `--scale` ⑤ | `rebuild.layout ≥ layoutUs / 2` | layout 门控退化 |
 *
 * ★ **故意缺一条**：解释器的 `--pages` ⑤（清单 == 磁盘）这边**做不了** ——
 *   它要 `ReflectionClass` 造任意类，而 AOT 是静态编译。那一侧有、这一侧没有，
 *   是分工不是遗漏：清单是本探针的**输入**，它的正确性只能在能反射的那一侧验。
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

/**
 * 页面档：27 个真实 showcase 页，逐页量「打开 / 稳态 / 全量重排 / 重建帧」。
 *
 * ★ 参数与解释器 `php tools/frame-tax-probe.php --pages` **逐字相同**
 *   （1200×800 / 50 帧）⇒ 两张表可以逐列对。
 */
function pagesSection(): void
{
    /**
     * 帧预算（60fps）。判据 P② 的唯一阈值 —— 它不是"机器速度"，是**产品要求**。
     * ★ 与解释器侧同一个数（两处手写是因为 AOT 不能跨进程读配置；
     *   改一处必须改另一处，两张表的最重页读数就是校验）。
     */
    $budgetUs = 16700.0;
    /** ★ 50 帧：解释器 `--pages`（不带 `--check`）用的就是 50 ⇒ 对表要求一致 */
    $frames = 50;

    say('=== 页面档：27 个真实 showcase 页（清单 ShowcasePages，与解释器同一份）===');

    $names = ShowcasePages::names();
    $rows = [];
    foreach ($names as $name) {
        $rows[] = FrameTaxMeasure::run(
            $name,
            static function () use ($name): Widget {
                return ShowcasePages::make($name);
            },
            1200.0,
            800.0,
            $frames,
        );
    }

    // 按全量重排降序 —— 与解释器的 `usort` 同序，两表才对得上。
    // ★ 用插入排序而不是 `usort`：27 个元素，零依赖；闭包作比较器在 AOT 下没先例。
    $n = \count($rows);
    for ($i = 1; $i < $n; $i++) {
        $cur = $rows[$i];
        $j = $i - 1;
        while ($j >= 0 && (float) $rows[$j]['layoutUs'] < (float) $cur['layoutUs']) {
            $rows[$j + 1] = $rows[$j];
            $j--;
        }
        $rows[$j + 1] = $cur;
    }

    \printf(
        "%-18s %5s %11s %22s %11s %11s\n",
        'page',
        '节点',
        '打开(us)',
        '重建帧 settle/layout/paint',
        '稳态(us)',
        '全量重排(us)',
    );
    foreach ($rows as $r) {
        \printf(
            "%-18s %5d %11.1f %22s %11.1f %11.1f\n",
            (string) $r['label'],
            (int) $r['nodes'],
            (float) $r['coldUs'],
            \sprintf(
                '%d/%d/%d',
                (int) $r['rebuild']['settle'],
                (int) $r['rebuild']['layout'],
                (int) $r['rebuild']['paint'],
            ),
            (float) $r['perFrameUs'],
            (float) $r['layoutUs'],
        );
    }

    $worst = $n > 0 ? $rows[0] : null;
    if ($worst !== null) {
        say('');
        \printf(
            "最重页（按全量重排）：%s —— %.1fus，占 16.7ms 预算的 %.1f%%\n",
            (string) $worst['label'],
            (float) $worst['layoutUs'],
            100.0 * (float) $worst['layoutUs'] / $budgetUs,
        );
    }
    say('');

    // P① 页数（分母没缩水）
    if ($n >= 20) {
        ok('P①', '扫到 ' . (string) $n . ' 页（≥20）');
    } else {
        bad('P①', '只有 ' . (string) $n . ' 页 —— 分母缩水了');
    }

    // P② 最重页 ≤ 帧预算（产品要求，不是机器速度）
    if ($worst !== null) {
        $layout = (float) $worst['layoutUs'];
        if ($layout <= $budgetUs) {
            ok('P②', '最重页全量重排 ' . \sprintf('%.1f', $layout) . 'us ≤ 16.7ms 预算（余量 '
                . \sprintf('%.1f', $budgetUs / $layout) . '×）');
        } else {
            bad('P②', '最重页全量重排 ' . \sprintf('%.1f', $layout) . 'us > 16.7ms 预算 —— 真实页掉帧');
        }
    } else {
        bad('P②', '没有可判的页');
    }

    // P③ 暖 ≤ 冷 / P④ 重建 layout ≥ 全量/2 / P⑤ 节点自证 —— 一趟遍历收齐
    $warmBad = '';
    $premiseBad = '';
    $empty = '';
    $sumNodes = 0;
    foreach ($rows as $r) {
        $label = (string) $r['label'];
        $layout = (float) $r['layoutUs'];
        $cold = (float) $r['coldUs'];
        $rebuildLayout = (float) $r['rebuild']['layout'];
        $nodes = (int) $r['nodes'];
        $sumNodes += $nodes;

        if ($layout > $cold) {
            $warmBad .= ' ' . $label . '(暖' . \sprintf('%.1f', $layout) . ' > 冷' . \sprintf('%.1f', $cold) . ')';
        }
        if ($rebuildLayout < $layout / 2.0) {
            $premiseBad .= ' ' . $label . '(' . \sprintf('%.0f', $rebuildLayout) . ' < '
                . \sprintf('%.0f', $layout / 2.0) . ')';
        }
        if ($nodes < 3) {
            $empty .= ' ' . $label . '(' . (string) $nodes . ')';
        }
    }

    if ($warmBad === '') {
        ok('P③', '每页全量重排 ≤ 页面打开（暖缓存生效）');
    } else {
        bad('P③', '暖比冷还贵：' . $warmBad);
    }

    // P④ 「M5 的上限 = 全量重排」这个前提。方向别搞反：要防的是 layout 变**增量**
    //   （重建帧远小于全量）；反方向"重建比全量还贵"是正常的（新盒、缓存更冷）。
    if ($premiseBad === '') {
        ok('P④', '整页重建的 layout 与全量重排同量级（M5 结论的前提成立）');
    } else {
        bad('P④', '重建帧 layout 远小于全量重排（layout 可能已变增量）：' . $premiseBad);
    }

    // P⑤ 页面真的建起来了。★ 解释器原 4 条没有一条管这个：页面渲染成空 ⇒ 节点少
    //   ⇒ P② 反而**更绿**（上界判据）、P③④ 是比值照绿。下界取「合计 1000」：解释器侧
    //   当前 2208，留 55% 余量 —— 抓得住"大面积变空"，容得下单页改版。
    if ($empty === '' && $sumNodes >= 1000) {
        ok('P⑤', '每页 ≥3 节点、合计 ' . (string) $sumNodes . ' 节点（≥1000）—— 页面真的建起来了');
    } else {
        bad('P⑤', '页面没建起来：合计 ' . (string) $sumNodes . ' 节点（<1000）' . $empty);
    }

    say('  （解释器侧的 ⑤「清单==磁盘」这一侧做不了：要 ReflectionClass，AOT 是静态编译）');
    say('');
}

/**
 * 合成档：500 / 3000 行 ≡ 12k 节点，`--scale` 的同档位、同帧数。
 */
function syntheticSection(): void
{
    say('=== 合成档：12k 节点（与 --scale 同档位、同帧数）===');

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

    // S① 大档真的建起来了
    if ((int) $big['nodes'] >= 10000) {
        ok('S①', '大档真建起来了：' . (string) $big['nodes'] . ' 节点（≥10000）');
    } else {
        bad('S①', '大档只有 ' . (string) $big['nodes'] . ' 节点 —— 档位没建起来');
    }

    // S② 四数齐全且非零
    $cold = (float) $big['coldUs'];
    $steady = (float) $big['perFrameUs'];
    $full = (float) $big['layoutUs'];
    $rebuildLayout = (float) $big['rebuild']['layout'];
    if ($cold > 0.0 && $steady > 0.0 && $full > 0.0 && $rebuildLayout > 0.0) {
        ok('S②', '四数齐全且非零：冷 ' . (string) $cold . ' / 稳态 ' . (string) $steady
            . ' / 全量 ' . (string) $full . ' / 重建 layout ' . (string) $rebuildLayout . ' us');
    } else {
        bad('S②', '有数为零：冷=' . (string) $cold . ' 稳态=' . (string) $steady
            . ' 全量=' . (string) $full . ' 重建=' . (string) $rebuildLayout);
    }

    // S③ 归一化自洽
    $ratio = (float) $small['perNodeUs'] > 0.0
        ? (float) $big['perNodeUs'] / (float) $small['perNodeUs']
        : 0.0;
    if ($ratio >= 0.5 && $ratio <= 2.0) {
        ok('S③', '归一化自洽：' . \sprintf('%.3f', (float) $big['perNodeUs']) . ' / '
            . \sprintf('%.3f', (float) $small['perNodeUs']) . ' us每节点 = '
            . \sprintf('%.2f', $ratio) . '×（容许 0.5–2.0）');
    } else {
        bad('S③', '归一化不自洽：' . \sprintf('%.2f', $ratio) . '× —— 某档被污染了');
    }

    // S④ 稳态帧（产品要求，不是机器速度）
    if ($steady <= 8000.0) {
        ok('S④', '稳态帧 ' . \sprintf('%.1f', $steady) . 'us ≤ 8000us（8ms 产品要求）');
    } else {
        bad('S④', '稳态帧 ' . \sprintf('%.1f', $steady) . 'us > 8000us —— 固定税失控');
    }

    // S⑤ layout 仍是全量（脏路径没偷偷生效）
    if ($rebuildLayout >= $full / 2.0) {
        ok('S⑤', 'layout 仍是全量：重建帧 ' . \sprintf('%.1f', $rebuildLayout)
            . 'us ≥ 全量重排 ' . \sprintf('%.1f', $full) . 'us 的一半');
    } else {
        bad('S⑤', '重建帧 layout 只有全量的 '
            . \sprintf('%.1f%%', $full > 0.0 ? $rebuildLayout * 100.0 / $full : 0.0)
            . ' —— layout 门控退化了');
    }

    say('');
}

/**
 * 几何指纹段：27 页每个盒的 `宽/高@x,y` 逐值取 md5，**与解释器侧逐行 diff**。
 *
 * ★ 为什么这一段比性能段更重要：2026-11-04 的微基准顺带撞到 tpc 的语义 bug ——
 *   **`int / int` 被编译成整数除法**（`7 / 2 === 3`）。截断是**静默**的：
 *   不崩、不报错，只是几何值偏一点。性能慢 2× 是"可接受"，算错尺寸是"不可用"。
 *   ⇒ AOT 能不能上线的第一条件是**数值等价**，不是快。
 *
 * ★ 输出带 tab，但**别手工 diff** —— 判据在 `tools/aot-equivalence.php`：
 *   它跑两侧、逐页比、红了自动归因 + 定位，退出码说话（`DEBT` §37）。
 *   `bash build.sh` 末尾已经自动接上。这里的原始输出只给人和脚本取数用。
 */
function geomSection(): void
{
    // ★ 单页明细（定位用）：`GEOM_PAGE=AspectPage PROBE_SECTION=geom ...`
    //   指纹说"这页不一样"，明细才说"从哪一层的哪一轴开始不一样"（按深度缩进）。
    $page = \getenv('GEOM_PAGE');
    if ($page !== false && $page !== '') {
        $root = GeomFingerprint::mountPage($page);
        if ($root === null) {
            bad('G②', '这一页没建出根元素：' . $page);

            return;
        }
        foreach (GeomFingerprint::detail($root) as $line) {
            say($line);
        }

        return;
    }

    $rows = GeomFingerprint::allPages(1200.0, 800.0);

    say('=== 几何指纹：27 页逐值（与 tools/geom-fingerprint.php --tsv 应逐行相同）===');
    $total = 0;
    foreach ($rows as $r) {
        say(GeomFingerprint::tsvLine($r));
        $total += (int) $r['nodes'];
    }
    say('--- ' . (string) \count($rows) . ' 页 / ' . (string) $total . ' 节点 ---');
    say('');

    // ★ 只判**下界**，不判精确值：节点数会随页面内容正常变动，硬编码 2208 那样
    //   每改一页就红，红久了就没人看。要抓的是"页面渲染成空"（下界）。
    //   ★ 注意这里数的是 **RenderBox** 树，而 `frame-tax-probe --pages` 的 2208 是
    //     **Element** 树 —— 两个数天然不同（Element 有非绘制节点），别拿来互相比。
    if ($total >= 2000) {
        ok('G①', 'RenderBox 节点合计 ' . (string) $total . '（≥2000，页面真建起来了）');
    } else {
        bad('G①', 'RenderBox 节点合计 ' . (string) $total . ' < 2000（页面渲染成空或清单缩水）');
    }
}

function main(): void
{
    // ★ AOT 产物的默认 `memory_limit` 是 128MB，而解释器那边跑 `--scale` 时是 `-1`
    //   （无限制）—— **不把它对齐，两把尺子量的就不是同一件事**：
    //   12k 档在 AOT 下会直接 OOM（软光栅的 DisplayList + 12k 个 `php::Var` 装箱）。
    //   这个数不是"框架要 1GB"，是"12k 节点 + 软光栅要 >128MB"，本身就是个数据点。
    @\ini_set('memory_limit', '1024M');

    // ★★ 段选择（`PROBE_SECTION=pages|synthetic|geom`，或第一个命令行参数）。
    //   **不带 = 两段都跑，页面段在前** —— 那是"自洽判据"模式。
    //   要拿**能与历史对表的绝对值**，必须**一段一进程**：
    //   实测同一进程里先跑页面档，合成档 layout 从 88.4ms 涨到 104.1ms（**+18%**），
    //   原因是前一段把 PHP 内存池撑大/打碎了。这不是 AOT 的特性，是"进程历史"的污染。
    //   ⇒ 量具版的 fork：解释器那边本来就是两条命令、两个进程，这边也必须是。
    $mode = 'all';
    $env = \getenv('PROBE_SECTION');
    if ($env === 'pages' || $env === 'synthetic' || $env === 'geom') {
        $mode = $env;
    }

    say('=== 第 2 道门：帧税三数 on tpc AOT (-m bin) ===');
    say('php=' . \PHP_VERSION . '  口径=tpc AOT + 软光栅（SurfaceProbe / DisplayList）');
    say('memory_limit=' . (string) \ini_get('memory_limit'));
    say('mode=' . $mode);
    say('');

    // ★ 两段都跑时顺序不能反：页面档要"进程干净"，12k 档的内存峰值会污染它。
    //   （要绝对值的场合用 `PROBE_SECTION` 分开跑，别依赖这个顺序。）
    // ★ `geom` 段不进 `all`：它不出性能数字，只出一份可 diff 的指纹表。
    if ($mode === 'geom') {
        geomSection();
        \exit(Fit::$fail > 0 ? 1 : 0);
    }
    if ($mode === 'all' || $mode === 'pages') {
        pagesSection();
    }
    if ($mode === 'all' || $mode === 'synthetic') {
        syntheticSection();
    }

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
