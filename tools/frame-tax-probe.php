<?php
declare(strict_types=1);

/**
 * **稳态帧的"每帧固定税"** 量具：一帧里除了 settle/layout/paint，还剩多少成本、长在哪。
 *
 * 用法：`php tools/frame-tax-probe.php`
 *
 * ★ 为什么需要它（2026-11-01）：`Frame` 的 layout 与 paint 都有门控 —— 树没变时
 *   整段跳过。于是"一帧什么都没干"到底还要花多少，就成了唯一没人量的数：
 *   `Application::frame()` 里还有 `tickAnimations()`（全树）与 `focus->collect()`
 *   （全树）两遍**无条件**遍历。实测 2000 节点稳态帧 3.5ms，其中 focus 1.6ms、
 *   anim 0.9ms；而同一棵树的全量 layout 是 7.8ms ⇒ **空转一帧 = 全量重排的 45%**，
 *   区别是这个税**每帧都付**。
 *
 * ★ 判据不是"看着快"，是这张表：
 *   · `perFrame(us)` —— 稳态单帧（layout / paint 均被门控跳过）的真实成本；
 *   · `layout(us)` —— 同一棵树单次全量重排，作分母；
 *   · `分段` —— `SurfaceProfile::perCall()`，直接指出这一帧里谁在花钱；
 *   · `版本变化` —— 稳态帧里 `structureVersion` / `boxEpoch` / `markedCount` 必须全为
 *     `+0`，否则说明"稳态"这个前提本身没成立（帧在自激），数字不可信。
 *
 * ★ 与 `frame-paint-probe.php` 的分工：那个数**画了几次**（N1 泵帧分离的判据），
 *   这个数**没画的时候花了多少**。两者互补，不重叠。
 *
 * ## `--check`：从"报告"变成"判据"（2026-11-02）
 *
 * 原来这里写着「别拿它当门禁：墙钟数随机器浮动，`gate` 里放它只会变成一台随机
 * 失败的机器」—— **那句话对"绝对耗时"成立，对"自洽性"不成立**。`--check` 判的
 * 六条**没有一条是墙钟阈值**：
 *
 * | # | 判据 | 能红的真实事故 |
 * |---|---|---|
 * | ① | `SurfaceProfile::selfTest()` 通过 | 单位换算写错（曾把 ns 印成 ms，**1000×**） |
 * | ② | 每个分段 ≤ 帧时间 × 1.25 | 量具自相矛盾：单位错 / 同一段重复计时 |
 * | ③ | 分段里必须出现 `tickAnimations` | "没扫到"冒充"没有"（否定结论要自证扫到了东西） |
 * | ④ | 稳态帧 paint 全跳过（run=0 && skip=帧数） | paint 门控退化 ⇒ 每帧整棵重画 |
 * | ⑤ | `layout` 段 ≤ 全量重排的 5% | layout 门控退化 ⇒ 稳态帧变成全树重排 |
 * | ⑥ | `structureVersion/boxEpoch/markedCount` 全 `+0` | "稳态"前提不成立（帧在自激），
 *       后面所有数字都不可信 |
 *
 * ★ ⑤ 是**比值**不是毫秒：分子分母是同一棵树上的**同一种操作**，机器慢了两边一起
 *   慢 ⇒ 比值稳。这也是它能进闸门的唯一理由（`Scale` 那条纪律的同类：只比同量纲）。
 * ★ `--check` 只跑 500 行一个规模（30 帧），约 1s；不带参数仍是四档报告。
 *
 * ## `--pages`：把工作负载换成**真实页面**（2026-11-02）
 *
 * M5（脏矩形 / 脏边界）的触发条件一直写作"**重建帧**掉帧"，但分母从来是**合成的
 * 2000 节点树**（全量 `Column`，真实页面根本不会那么搭）。用合成树判"真实页面要不要
 * M5"，等于拿错分母。`--pages` 把根换成 `apps/showcase` 的 27 个真实页（发现规则与
 * `golden-raster --pages` 逐条相同），并**把三段分开报** —— 因为 M5 只能省 `layout`：
 * 它省不掉 `settle`（build + diff），也省不掉 `paint`。
 *
 * | 列 | 是什么 | 谁用它 |
 * |---|---|---|
 * | `打开(us)` | `attach()` 自带的那一帧（建整棵树 + 首次 shaping） | 页面首次打开的成本 |
 * | `重建帧 settle/layout/paint` | 每帧把根 `update()` 成等价新树（整页 `setState`） | 真实交互帧的形状 |
 * | `稳态(us)` | 树没变时每帧的固定税 | 同 `--check` 的分母 |
 * | `全量重排(us)` | 同一棵树单次 `layout()`（暖缓存） | **M5 的上限** |
 *
 * ★ **口径三条**（都踩过）：
 *   ① `attach()` **自己就跑一帧**，别再补 `frame(0)` —— 补了是双计（35ms 变 37.9ms）；
 *   ② 重建帧会造一整棵新树，GC 压力会污染后面的 `layout` 测量（500 行 8.0ms → 11.6ms）
 *      ⇒ 全量重排必须排在重建帧**之前**；
 *   ③ `SurfaceProfile::$ns` 是**纳秒**，表头印的是 us ⇒ 分段必须换算（`formatNs()` 的教训）。
 *
 * 判据（`--pages --check`，4 条）：① 扫到 ≥20 页（自证）· ② 最重页全量重排 ≤ 16.7ms
 * 预算 · ③ 每页暖态全量重排 ≤ 页面打开（缓存必须生效）· ④ 整页重建的 layout 与全量重排
 * **同量级**（钉住"M5 的上限 = 全量重排"这个前提；哪天 layout 变增量，这条会红）。
 *
 * ★ **故意没有**"稳态帧 paint 必须全跳过"这条判据：页面档里有每帧都在推进动画的页
 *   （`CustomPaintPage`），它的稳态帧本来就该画；而"动画在跑"与"门控退化"从这几个数上
 *   长得一模一样 ⇒ 硬判只会误伤，误伤的判据下一棒只会被关掉。门控完整性交给合成档 ④⑤。
 */

require __DIR__ . '/autoload.php';

use Drift\Core\Pipeline;
use Drift\App\Application;use Drift\App\Frame;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Support\CheckItem;
use Drift\Dev\Support\CheckReport;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Rendering\FrameStage;
use Drift\Rendering\SurfaceProfile;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\State;
use Drift\Widget\Text;
use Drift\Widget\Widget;

// ── 参数 ──
$args = \array_slice($argv, 1);
/** `--json`：判据结果吐成机器可读文档（stdout 上只有它），见 `CheckReport` */
$json = \in_array('--json', $args, true);
/** ★ `--json` 隐含 `--check`：只有判据结果才有结构化输出，报告模式本来就只有表 */
$check = $json || \in_array('--check', $args, true);
/** `--pages`：把工作负载换成**真实 showcase 页面**（M5 的真分母，见文件头 §页面档） */
$pages = \in_array('--pages', $args, true);
/** `--check` 只跑一档（进闸门要快）；报告模式四档看趋势 */
$sizes = $check ? [500] : [10, 50, 200, 500];
$frames = $check ? 30 : 50;
/** @var array<string, mixed> 最后一档的结果（`--check` 判据读它） */
$last = [];

const W = 400.0;
const H = 300.0;
/** 页面档视口：与 `apps/bench` 的 `showcase（真实应用根）` 同量级（1600×1000 的窄版） */
const PW = 1200.0;
const PH = 800.0;

/**
 * 一个"根就是这个 Widget"的应用 —— 合成树与真实页面走**同一条挂载路径**。
 *
 * ★ 收成闭包而不是两个 Application 子类：两档的差别只在"根是谁"，其余
 *   （attach / frame / 计时）必须逐字相同，否则两档的数字不可比。
 */
$makeAppFor = static fn(\Closure $root): Application => new class($root) extends Application {
    public function __construct(private readonly \Closure $rootFactory)
    {
        parent::__construct();
    }

    public function buildRoot(): Widget
    {
        return ($this->rootFactory)();
    }

    public function title(): string
    {
        return 'tax';
    }
};

/**
 * 合成档的根：每行 = 一个 `Row` 带 3 个 `Text`，外层 `ScrollView` 给无界主轴（不会溢出）。
 *
 * ★ 用全量 `Column` 而不是 `LazyColumn`：后者只建可见项，节点数不随数据量变
 *   ⇒ 量不出"树大了每帧固定税怎么长"（那正是它的价值，不是缺陷）。
 */
$syntheticRoot = static fn(int $rows): \Closure => static function () use ($rows): Widget {
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

/**
 * 量一档：冷帧 / 稳态帧固定税 / 重建帧三段 / 全量重排 / 节点数。
 *
 * ★ 三段口径分工（别混）：
 *   · `cold`   —— 首帧（建整棵树）。页面档里它是"第一次打开这一页"的成本；
 *   · `steady` —— 树没变时每帧的固定税（`--check` 判的就是它）；
 *   · `rebuild`—— 每帧把根 `update()` 成一份等价新树（= 整页 `setState` 的形状），
 *                分段是**每帧合计**（`SurfaceProfile::$ns`，不是 `perCall()`）；
 *   · `fullLayout` —— 同一棵树单次全量重排（暖缓存）。**这是 M5 想优化的那一笔**：
 *                脏矩形能省的**上限**就是它，省不掉 settle 与 paint。
 *
 * @param \Closure(): Widget $root
 * @return array<string, mixed>
 */
$measure = static function (string $label, \Closure $root, float $w, float $h, int $frames) use ($makeAppFor): array {
    $tight = BoxConstraints::tight(new Size($w, $h));
    $app = $makeAppFor($root);
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
        for ($k = 0; $k < $reps; $k++) {
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
        'coldUs' => \round(\array_sum($cold) / 1000.0, 1),
        'rebuildUs' => \round(\array_sum($rebuild), 1),   // 已经是 us（上面那次 foreach 换算过）
        'rebuild' => $rebuild,
        'paint' => $paintRuns,
        'skip' => $skips,
        'seg' => $seg,
        'delta' => 'sv+' . $sv . ' box+' . $be . ' marked+' . $mk,
    ];
};

// ────────────── 页面档：真实 showcase 页面 ──────────────
//
// ★ 为什么要这一档（2026-11-02）：M5（脏矩形）的触发条件一直写作"重建帧掉帧"，
//   而**分母从来是合成的 2000 节点树**（全量 `Column`，真实页面根本不会那么搭）。
//   用合成树判"真实页面要不要 M5"，等于拿错分母。这一档把根换成 showcase 的
//   真页面，并且**分开报三段** —— 因为 M5 能省的只有 `layout` 那一段：
//   它省不掉 `settle`（build + diff）也省不掉 `paint`。
//
// ★ 页面的发现规则与 `golden-raster --pages` **逐条相同**（构造器无必填参数），
//   跳过的页**点名**——"少一张网"与"这张网本来就是空的"必须能分辨。
if ($pages) {
    /**
     * 帧预算（60fps）。页面档判据 ② 的唯一阈值 —— 它不是"机器速度"，是**产品要求**。
     * ★ 当前最重页只用到它的 1/10，所以 10× 的余量足以吸收机器抖动，不会误红。
     */
    $budgetUs = 16700.0;

    $pageFiles = \glob(\dirname(__DIR__) . '/apps/showcase/src/Pages/*.php') ?: [];
    \sort($pageFiles);

    $out = [];
    $skipped = [];
    foreach ($pageFiles as $file) {
        $name = \basename($file, '.php');
        $class = 'App\\Showcase\\Pages\\' . $name;
        if (!\class_exists($class)) {
            $skipped[] = $name . '（无类）';
            continue;
        }
        $ctor = (new \ReflectionClass($class))->getConstructor();
        if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
            $skipped[] = $name . '（构造器要参数）';
            continue;
        }

        $out[] = $measure($name, static fn(): Widget => new $class(), PW, PH, $frames);
    }

    \usort($out, static fn(array $a, array $b): int => $b['layoutUs'] <=> $a['layoutUs']);

    $json || \printf(
        "%-18s %5s %11s %22s %11s %11s\n",
        'page', '节点', '打开(us)', '重建帧 settle/layout/paint', '稳态(us)', '全量重排(us)',
    );
    foreach ($out as $r) {
        $json || \printf(
            "%-18s %5d %11.1f %22s %11.1f %11.1f\n",
            $r['label'],
            $r['nodes'],
            $r['coldUs'],
            \sprintf('%d/%d/%d', $r['rebuild']['settle'], $r['rebuild']['layout'], $r['rebuild']['paint']),
            $r['perFrameUs'],
            $r['layoutUs'],
        );
    }

    $worst = $out[0] ?? null;
    if (!$json) {
        echo "\n";
    }
    if ($worst !== null) {
        $share = 100.0 * $worst['layoutUs'] / $budgetUs;
        $json || \printf(
            "最重页（按全量重排）：%s —— %.1fus，占 16.7ms 预算的 %.1f%%\n",
            $worst['label'],
            $worst['layoutUs'],
            $share,
        );
        $json || \printf(
            "  它的重建帧：settle %dus / layout %dus / paint %dus ⇒ 即使 layout 全省，也只省掉 %.0f%%\n",
            $worst['rebuild']['settle'],
            $worst['rebuild']['layout'],
            $worst['rebuild']['paint'],
            $worst['rebuildUs'] > 0 ? 100.0 * $worst['rebuild']['layout'] / $worst['rebuildUs'] : 0.0,
        );
    }
    $json || \printf("页数 %d", \count($out));
    if ($skipped !== [] && !$json) {
        \printf("；跳过 %d：%s", \count($skipped), \implode('、', $skipped));
    }
    if (!$json) {
        echo "\n";
    }

    if (!$check) {
        exit(0);
    }

    // ── 页面档判据 ──
    //
    // ★ 这里**故意没有**"稳态帧 paint 必须全跳过"这条。页面档里有**每帧都在推进动画**
    //   的页（`CustomPaintPage`），它的稳态帧本来就该画、版本本来就该变；而"动画在跑"
    //   与"门控退化"从这几个数上**长得一模一样**，判据分不开 ⇒ 硬判只会误伤，误伤的
    //   判据下一棒只会被关掉。门控完整性交给合成档的 ④⑤（那里树是静止的，前提成立）。
    $items = [];
    $items[] = new CheckItem('①', \count($out) >= 20, \sprintf('扫到 %d 页（≥20 才算真扫到了）', \count($out)));

    $budgetOk = $worst !== null && $worst['layoutUs'] <= $budgetUs;
    $items[] = new CheckItem('②', $budgetOk, $worst === null
        ? '没有可判的页'
        : \sprintf(
            '最重页全量重排 %.1fus %s %.1fms 预算（余量 %.1f×）',
            $worst['layoutUs'],
            $budgetOk ? '≤' : '超过',
            $budgetUs / 1000.0,
            $worst['layoutUs'] > 0 ? $budgetUs / $worst['layoutUs'] : 0.0,
        ));

    // ③ 暖 ≤ 冷：全量重排用的是**暖缓存**，不可能比"页面打开那一帧"还贵。
    //    反了说明缓存没生效，或者两次量的根本不是同一棵树。
    $warmBad = [];
    foreach ($out as $r) {
        if ($r['layoutUs'] > $r['coldUs']) {
            $warmBad[] = \sprintf('%s(暖%.1f > 冷%.1f)', $r['label'], $r['layoutUs'], $r['coldUs']);
        }
    }
    $items[] = new CheckItem('③', $warmBad === [], $warmBad === []
        ? '每页全量重排 ≤ 页面打开（暖缓存生效）'
        : '暖比冷还贵：' . \implode(' ', $warmBad));

    // ④ **M5 结论的前提**：整页重建确实走"全量重排"。
    //   ★ 方向别搞反：要防的是"layout 哪天变成**增量**了" —— 那时重建帧的 layout
    //     会**远小于**全量重排，"M5 的上限 = 全量重排"这句话就不再成立。
    //     （反方向"重建比全量还贵"是正常的：重建要造新盒，缓存比稳态冷。）
    $premiseBad = [];
    foreach ($out as $r) {
        $floor = (int) \round($r['layoutUs'] / 2.0);
        if ($r['rebuild']['layout'] < $floor) {
            $premiseBad[] = \sprintf('%s(%d < %d)', $r['label'], $r['rebuild']['layout'], $floor);
        }
    }
    $items[] = new CheckItem('④', $premiseBad === [], $premiseBad === []
        ? '整页重建的 layout 与全量重排同量级（M5 结论的前提成立）'
        : '重建帧的 layout 远小于全量重排（layout 可能已变增量）：' . \implode(' ', $premiseBad));

    // 信息行（**不判**）：谁在每帧画、谁的版本在变 —— 动画与自激分不开，交给人看
    $busy = [];
    foreach ($out as $r) {
        if ($r['paint'] !== 0 || $r['delta'] !== 'sv+0 box+0 marked+0') {
            $busy[] = $r['label'] . '(' . $r['paint'] . ' 画/' . $r['delta'] . ')';
        }
    }
    if (!$json) {
        echo "\n  信息：每帧仍在画的页（动画或自激，需人看）——"
            . ($busy === [] ? '（无）' : \implode('、', $busy)) . "\n";
    }

    (new CheckReport(
        'frame-tax --pages',
        $items,
        \sprintf('4 项页面判据全过（%d 页 / %d 帧）', \count($out), $frames),
    ))->emit($json);
}

$json || \printf("%6s %14s %12s %7s %6s\n", 'rows', 'perFrame(us)', 'layout(us)', 'paint', 'skip');
$prev = null;
foreach ($sizes as $rows) {
    $r = $measure((string) $rows, $syntheticRoot($rows), W, H, $frames);
    $json || \printf(
        "%6d %14.1f %12.1f %7d %6d\n",
        (int) $r['label'],
        $r['perFrameUs'],
        $r['layoutUs'],
        $r['paint'],
        $r['skip'],
    );

    $parts = [];
    foreach ($r['seg'] as $k => $v) {
        if ($v >= 500) { // 只印 ≥0.5us 的段，噪声不进表
            // ★ 换算走 `SurfaceProfile::formatNs()`（唯一一份）：这里原来写的是
            //   `$v / 1000 . 'ms'`，而 `$v` 是**纳秒** ⇒ 每段被印成 1000 倍。
            $parts[] = $k . '=' . SurfaceProfile::formatNs((int) $v);
        }
    }
    $json || \printf("       分段: %s\n", $parts === [] ? '（全部 <0.5us）' : \implode('  ', $parts));
    $json || \printf("       版本: %s\n", $r['delta']);

    if ($prev !== null) {
        $dr = (int) $r['label'] - (int) $prev['label'];
        $json || \printf(
            "       ↗ 每行: 帧 %+.2f us/行, layout %+.2f us/行\n",
            ($r['perFrameUs'] - $prev['perFrameUs']) / $dr,
            ($r['layoutUs'] - $prev['layoutUs']) / $dr,
        );
    }
    $prev = $r;
    $last = $r;
}

if (!$check) {
    exit(0);
}

// ── `--check`：六条自洽判据（全是比值 / 结构事实，没有一条是墙钟阈值）──
//
// ★ 每条**先算结论再打印**（不能"先 ok 后 ✗"）：变异自检时第一版就是先无条件印
//   `ok ②`、再在末尾补一行 ✗ —— 同一份输出里两种说法并存，正是本仓最恨的绿壳。
$perFrameNs = (int) \round($last["perFrameUs"] * 1000.0);
$seg = $last["seg"];
/** @var list<CheckItem> */
$items = [];

$why = SurfaceProfile::selfTest();
$items[] = new CheckItem("①", $why === [], $why === [] ? "量具契约（formatNs 三档 + perCall 平均语义）" : \implode("；", $why));

$worst = ["k" => "", "ns" => 0];
foreach ($seg as $k => $ns) {
    if ($ns > $worst["ns"]) {
        $worst = ["k" => (string) $k, "ns" => (int) $ns];
    }
}
$segOk = $perFrameNs > 0 && $worst["ns"] <= $perFrameNs * 1.25;
$items[] = new CheckItem("②", $segOk, \sprintf(
    "分段自洽：最大段 %s=%s %s 整帧 %s（%.0f%%）",
    $worst["k"],
    SurfaceProfile::formatNs($worst["ns"]),
    $segOk ? "≤" : "超过",
    SurfaceProfile::formatNs($perFrameNs),
    $perFrameNs > 0 ? 100.0 * $worst["ns"] / $perFrameNs : 0.0,
));

$items[] = new CheckItem("③", isset($seg[FrameStage::TICK_ANIMATIONS]), isset($seg[FrameStage::TICK_ANIMATIONS])
    ? "扫到了 tickAnimations（" . SurfaceProfile::formatNs((int) $seg[FrameStage::TICK_ANIMATIONS]) . "）"
    : "分段里没有 tickAnimations —— 没扫到这一段，\"它不贵\"这个结论不成立");

$paintOk = $last["paint"] === 0 && $last["skip"] === $frames;
$items[] = new CheckItem("④", $paintOk, \sprintf(
    "稳态帧 paint %s（run=%d skip=%d，期望 0/%d）",
    $paintOk ? "全跳过" : "没被门控跳过",
    $last["paint"],
    $last["skip"],
    $frames,
));

$layoutSeg = (int) ($seg[FrameStage::LAYOUT] ?? 0);
$fullNs = (int) \round($last["layoutUs"] * 1000.0);
$ratio = $fullNs > 0 ? $layoutSeg / $fullNs : 0.0;
$layoutOk = $fullNs > 0 && $ratio <= 0.05;
$items[] = new CheckItem("⑤", $layoutOk, \sprintf(
    "稳态帧 %s重排：layout 段 %s / 全量 %s（%.2f%%，上限 5%%）",
    $layoutOk ? "不" : "在",
    SurfaceProfile::formatNs($layoutSeg),
    SurfaceProfile::formatNs($fullNs),
    $ratio * 100.0,
));

$items[] = new CheckItem("⑥", $last["delta"] === "sv+0 box+0 marked+0", $last["delta"] === "sv+0 box+0 marked+0"
    ? "稳态前提成立（" . $last["delta"] . "）"
    : "\"稳态\"前提不成立：" . $last["delta"] . "（帧在自激，上面的数字不可信）");

(new CheckReport(
    'frame-tax',
    $items,
    \sprintf('6 项判据全过（%s 行 / %d 帧）', $last['label'], $frames),
))->emit($json);