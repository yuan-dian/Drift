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
 * ★ `--check` 只跑 500 行一个规模（30 帧），约 1s；不带参数是五档报告（10/50/200/500
 *   行 + 12k 节点档），末档 ~12k 节点是 Raven「增量布局值不值得立项」的真分母。
 *
 * ## `--scale`：12k 节点档的尺子
 *
 * 跑 500 + 3000 两档，500 作同量纲基线；报告增印 `us/节点` 与重建帧三段。
 * 五条判据只认自证 / 比值 / 结构事实，判定线只印在信息行 —— 绝对耗时当红线
 * 会随机器抖动误报。三数与归一化值进 `--json` 判据 ② 的 data。
 *
 * ### 两档同进程：**不是**污染，是预热（2026-11-04 夜实测，结掉 §35.6 的挂账）
 *
 * §35.6 记下「同进程跑两段，后一段 +18%」，并推测 `--scale` 的 [500, 3000] 有同一个
 * 隐患。这一棒把它量掉，结论是**反的**：
 *
 * | 实验 | 结果 |
 * |---|---|
 * | `[3000, 3000]` 同进程 | 第二档 3.95 / 4.39 / 4.00，**比第一档 4.22 / 4.51 / 4.30 更快** |
 * | `[500, 3000]` 同进程 vs 3000 单进程 | 4.18 / 4.14 / 4.34 vs 4.03 / 4.11 / 4.33 —— **无差异** |
 * | 先跑 27 个真实页，再跑 3000 档 | 4.92 / 4.20 / 4.66 vs 干净进程 4.33 / 4.03 / 4.11 ⇒ **+13%** |
 *
 * ⇒ §35.6 的 +18% 不是"跑了两段"，是「**27 个形状各异的真实页** → 同形状合成树」：
 *   字体 shaping 缓存、文本度量缓存、分配器碎片都被那 27 页塞满了。
 *   而 `--scale` 的两档**同形状**（同一份 `syntheticRoot`），共享全部缓存 ⇒ 只会预热。
 *   ★ 那条污染路径**已经被结构切断**：页面档在 `if ($pages) { … exit(0); }` 里，
 *     走不到合成档。所以「一段一进程」这个方向**不立项** —— 它治的病在这里不存在，
 *     代价却是两个进程 + 一套跨进程传数。
 *   ⇒ 但 `--pages` 与 `--scale` 现在**互斥报错**：哪天有人把两者合回一个 `main()`，
 *     静默的 +13% 就会回来。
 *
 * ### 误差棒：主数改中位（2026-11-04 夜）
 *
 * 原来 `layoutUs` 是「5 次取平均」，而 attach 之后第一次 `layout()` 缓存最冷
 * （12k 节点树 63.3ms vs 稳态 44.5ms，+42%）⇒ 平均值被抬高 9–13%。
 * 现在每段先跑几发丢弃再采 7 发 / 9 帧取**中位**，并把 min/max 印在下一行。
 * ★ 比值判据 ③ 仍用单值，因为**两档都缩短了同样的比例**，比值本身是稳的；
 *   但它的绝对精度只有 ±20%（实测两档各测一次的比值在 0.93–1.35 之间跳），
 *   所以判据文本里带上两档的离散度 —— **别拿小数点后两位当结论**。
 *
 * ## `--pages`：把工作负载换成**真实页面**（2026-11-02）
 *
 * M5（脏矩形 / 脏边界）的触发条件一直写作"**重建帧**掉帧"，但分母从来是**合成的
 * 2000 节点树**（全量 `Column`，真实页面根本不会那么搭）。用合成树判"真实页面要不要
 * M5"，等于拿错分母。`--pages` 把根换成 `apps/showcase` 的 27 个真实页（**跳过规则** ——
 * 构造器无必填参数 —— 与 `golden-raster --pages` 逐条相同；**发现方式**已改为显式清单，
 * 理由见下），并**把三段分开报** —— 因为 M5 只能省 `layout`：
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
 * 判据（`--pages --check`，6 条）：① 扫到 ≥20 页（自证）· ② 最重页全量重排 ≤ 16.7ms
 * 预算 · ③ 每页暖态全量重排 ≤ 页面打开（缓存必须生效）· ④ 整页重建的 layout 与全量重排
 * **同量级**（钉住"M5 的上限 = 全量重排"这个前提；哪天 layout 变增量，这条会红）。
 * ⑤ **清单与磁盘一致** · ⑥ **页面真建起来了**（节点数自证）。
 *
 * ★ ⑤⑥ 是 2026-11-04 补的，补的原因是两条**已经存在的洞**，不是给新功能配的：
 *   · ⑤ 页面清单改成显式（`ShowcasePages`，AOT 不能用反射）之后，"加页忘填清单"会让
 *     这一页**静默地从分母里消失**；②③④ 全是比值或上界，**一个都不会红**。
 *   · ⑥ 原 4 条里**没有一条管"这页渲染成空"**：页面变空 ⇒ 节点少 ⇒ ② 更绿、③④ 是比值
 *     照绿。合成档有 ①（12k 真建起来了）兜这个，页面档当时只有"页数 ≥20"，不对称。
 *   ⇒ 两条都是「分母对不对」，与合成档 ① 是同一类自证。
 *
 * ★ **故意没有**"稳态帧 paint 必须全跳过"这条判据：页面档里有每帧都在推进动画的页
 *   （`CustomPaintPage`），它的稳态帧本来就该画；而"动画在跑"与"门控退化"从这几个数上
 *   长得一模一样 ⇒ 硬判只会误伤，误伤的判据下一棒只会被关掉。门控完整性交给合成档 ④⑤。
 */

require __DIR__ . '/autoload.php';

use Drift\Core\Pipeline;
use Drift\App\Application;use Drift\App\Frame;
use Drift\Dev\Bench\FrameTaxApp;
use Drift\Dev\Bench\FrameTaxMeasure;
use Drift\Dev\Bench\ShowcasePages;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Support\CheckItem;
use Drift\Dev\Support\CheckReport;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Rendering\FrameStage;
use Drift\Rendering\SurfaceProfile;
use Drift\Widget\Widget;

// ── 参数 ──
$args = \array_slice($argv, 1);
/** `--json`：判据结果吐成机器可读文档（stdout 上只有它），见 `CheckReport` */
$json = \in_array('--json', $args, true);
/** `--scale`：12k 节点档 + 5 条自洽判据（独立入口，不改变 `--check` 的形状） */
$scale = \in_array('--scale', $args, true);
/** ★ `--json` 隐含 `--check`：只有判据结果才有结构化输出，报告模式本来就只有表 */
$check = !$scale && ($json || \in_array('--check', $args, true));
/** `--pages`：把工作负载换成**真实 showcase 页面**（M5 的真分母，见文件头 §页面档） */
$pages = \in_array('--pages', $args, true);
// ★ `--pages` 与 `--scale` 互斥，**同时给直接报错**：两者各换一套分母（27 个真实页 vs
//   500/3000 行的合成树），判据互不通用。而 `--pages` 分支末尾是 `exit(0)`，
//   ⇒ 同时给的时候 `--scale` 会被**静默丢掉**。静默丢参数比报错难发现得多。
if ($pages && $scale) {
    \fwrite(\STDERR, "不能同时给 --pages 与 --scale：两者各换一套分母，判据不通用\n");
    exit(2);
}
/** `--check` 只跑一档（进闸门要快）；`--scale` 跑 500 + 3000 两档，500 是同量纲基线 */
// `--check` 必须保持 500 行：那 6 条判据的前提是「静止的合成树」，换分母会一起失效。
$sizes = $scale ? [500, 3000] : ($check ? [500] : [10, 50, 200, 500, 3000]);
$frames = ($check || $scale) ? 30 : 50;
/** @var array<string, mixed> 最后一档的结果（`--check` 判据读它） */
$last = [];
/** @var array<int, array<string, mixed>> 按行数索引的全部结果（`--scale` 判据读它） */
$all = [];

const W = 400.0;
const H = 300.0;
/** 页面档视口：与 `apps/bench` 的 `showcase（真实应用根）` 同量级（1600×1000 的窄版） */
const PW = 1200.0;
const PH = 800.0;

/**
 * 一个"根就是这个 Widget"的应用 —— 合成树与真实页面走**同一条挂载路径**。
 *
 * ★ 两档的差别只在"根是谁"，其余（attach / frame / 计时）必须逐字相同，
 *   否则两档的数字不可比。
 * ★ 2026-11-04：原来是**匿名类**，搬进 `Drift\Dev\Bench\FrameTaxApp` 了。
 *   原因不是洁癖 —— `probes/frame_tax_aot` 要在 tpc 下编译同一段编排，
 *   而匿名类在 AOT 里没有先例（`docs/AOT-CONSTRAINTS.md` 零记录）。
 */
$makeAppFor = static fn(\Closure $root): Application => new FrameTaxApp($root);

/**
 * 合成档的根 —— 形状定义搬进 `FrameTaxMeasure::syntheticRoot()`。
 *
 * ★ 搬家的理由同 `$measure`：树形状是**历史数字的一部分**（§13.5 那张表、
 *   `--scale` 的归一化比值、AOT 探针的复量都建在它上面），必须只有一份。
 */
$syntheticRoot = static fn(int $rows): \Closure => FrameTaxMeasure::syntheticRoot($rows);

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
$measure = static fn(string $label, \Closure $root, float $w, float $h, int $frames): array
    => FrameTaxMeasure::run($label, $root, $w, $h, $frames);

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
//
// ★ 2026-11-04：遍历的**不再是磁盘，是 `ShowcasePages` 的显式清单**。
//   原因是 `probes/frame_tax_aot` 要复量这一档，而 AOT 是静态编译 ——
//   `new $class()` 造任意类这条路走不通。磁盘扫描只留下一个用处：
//   **判据 ⑤ 拿它跟清单对差集**（"加页忘填清单"会静默少一页，必须红）。
if ($pages) {
    /**
     * 帧预算（60fps）。页面档判据 ② 的唯一阈值 —— 它不是"机器速度"，是**产品要求**。
     * ★ 当前最重页只用到它的 1/10，所以 10× 的余量足以吸收机器抖动，不会误红。
     */
    $budgetUs = 16700.0;

    /**
     * 磁盘上「能用」的页 —— **只有解释器算得出来**（要 `class_exists` + 反射构造器）。
     *
     * @return array{usable: list<string>, skipped: list<string>}
     */
    $scanDisk = static function (): array {
        $usable = [];
        $skipped = [];
        $files = \glob(\dirname(__DIR__) . '/apps/showcase/src/Pages/*.php') ?: [];
        \sort($files);
        foreach ($files as $file) {
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
            $usable[] = $name;
        }

        return ['usable' => $usable, 'skipped' => $skipped];
    };

    $disk = $scanDisk();
    $skipped = $disk['skipped'];

    $out = [];
    foreach (ShowcasePages::names() as $name) {
        $out[] = $measure($name, static fn(): Widget => ShowcasePages::make($name), PW, PH, $frames);
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
    $json || \printf("清单 %d 页（磁盘可用 %d）", \count($out), \count($disk['usable']));
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

    // ⑤ 清单 == 磁盘。★ 显式清单（`ShowcasePages`）的唯一代价就是漂移：
    //   加一页忘了填，它从分母里静默消失，而 ②（上界）③④（比值）**全都照绿**。
    //   ⇒ 这条判据是那份代价的对价，不是锦上添花。
    $listed = ShowcasePages::names();
    $missing = \array_values(\array_diff($disk['usable'], $listed));  // 磁盘有、清单无
    $extra = \array_values(\array_diff($listed, $disk['usable']));    // 清单有、磁盘无
    $items[] = new CheckItem('⑤', $missing === [] && $extra === [], $missing === [] && $extra === []
        ? \sprintf('清单与磁盘一致（%d 页）', \count($listed))
        : \sprintf(
            '清单漂移：磁盘有清单无 [%s]；清单有磁盘无 [%s]',
            \implode(' ', $missing),
            \implode(' ', $extra),
        ));

    // ⑥ 页面真的建起来了。★ 原 4 条里**没有一条**管这件事：页面渲染成空 ⇒ 节点少 ⇒
    //   ② 更绿（上界）、③④ 是比值照绿。合成档有 ①（12k 真建起来了）兜这个，这边当时没有。
    //   下界取「总 1000 节点」：当前 2208，留 55% 余量 —— 抓得住"大面积变空"，容得下单页改版。
    $sumNodes = 0;
    $empty = [];
    foreach ($out as $r) {
        $sumNodes += (int) $r['nodes'];
        if ((int) $r['nodes'] < 3) {
            $empty[] = \sprintf('%s(%d)', $r['label'], (int) $r['nodes']);
        }
    }
    $built = $empty === [] && $sumNodes >= 1000;
    $items[] = new CheckItem('⑥', $built, $built
        ? \sprintf('每页 ≥3 节点、合计 %d 节点（≥1000）—— 页面真的建起来了', $sumNodes)
        : \sprintf(
            '页面没建起来：合计 %d 节点（<1000）%s',
            $sumNodes,
            $empty === [] ? '' : '；空页 ' . \implode(' ', $empty),
        ));

    // 信息行（**不判**）：谁在每帧画、谁的版本在变 —— 动画与自激分不开，交给人看
    $busy = [];
    foreach ($out as $r) {
        if ($r['paint'] !== 0 || $r['delta'] !== 'sv+0 box+0 marked+0') {
            $busy[] = $r['label'] . '(' . $r['paint'] . ' 画/' . $r['delta'] . ')';
        }
    }
    // 信息行（**不判**）：离散度大的页 —— 那几页的读数不宜当基线。
    //   ★ 为什么印而不判：离散是机器的属性（调度 / 频率 / GC），判它等于判机器，
    //     会变成一台随机失败的机器。但**不印**更糟 —— 读者会把一次抖动的读数
    //     当成"这页变慢了"。所以印出来，让人自己决定要不要重跑。
    $noisy = [];
    foreach ($out as $r) {
        $spread = (float) ($r['layoutSpread'] ?? 1.0);
        if ($spread > 1.6) {
            $noisy[] = \sprintf('%s(%.2f×)', $r['label'], $spread);
        }
    }
    if (!$json) {
        echo "\n  信息：每帧仍在画的页（动画或自激，需人看）——"
            . ($busy === [] ? '（无）' : \implode('、', $busy)) . "\n";
        echo "  信息：重排离散 >1.6× 的页（读数带抖动，勿当基线）——"
            . ($noisy === [] ? '（无）' : \implode('、', $noisy)) . "\n";
    }

    (new CheckReport(
        'frame-tax --pages',
        $items,
        \sprintf('6 项页面判据全过（%d 页 / %d 帧）', \count($out), $frames),
    ))->emit($json);
}

$json || \printf("%6s %8s %10s %14s %12s %10s %7s %6s\n", 'rows', 'nodes', 'cold(us)', 'perFrame(us)', 'layout(us)', 'us/node', 'paint', 'skip');
$prev = null;
foreach ($sizes as $rows) {
    $r = $measure((string) $rows, $syntheticRoot($rows), W, H, $frames);
    $all[(int) $r['label']] = $r;
    $json || \printf(
        "%6d %8d %10.1f %14.1f %12.1f %10.3f %7d %6d\n",
        (int) $r['label'],
        $r['nodes'],
        $r['coldUs'],
        $r['perFrameUs'],
        $r['layoutUs'],
        $r['perNodeUs'],
        $r['paint'],
        $r['skip'],
    );
    $json || \printf(
        "       重排: %s\n",
        (string) ($r['layoutText'] ?? ''),
    );
    $json || \printf(
        "       重建帧: settle %d / layout %d / paint %d us（合计 %.1f us）\n",
        $r['rebuild']['settle'],
        $r['rebuild']['layout'],
        $r['rebuild']['paint'],
        $r['rebuildUs'],
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

    if ($prev !== null && (int) $r['label'] > (int) $prev['label']) {
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

// ── `--scale`：12k 档的五条自洽判据 ──
// 必须排在 `!$check` 的 exit 之前：`--scale` 不置 `$check`，晚了就永远跑不到。
if ($scale) {
    $big = $all[3000] ?? null;
    $base = $all[500] ?? null;
    $nodes = $big['nodes'] ?? 0;
    $cold = $big['coldUs'] ?? 0.0;
    $steady = $big['perFrameUs'] ?? 0.0;
    $full = $big['layoutUs'] ?? 0.0;
    $rebuildLayout = $big['rebuild']['layout'] ?? 0;
    $perNode = $big['perNodeUs'] ?? 0.0;
    $basePerNode = $base['perNodeUs'] ?? 0.0;

    if (!$json) {
        \printf("\n判定线（信息，不作判据）：全量重排 8000us / 稳态帧 8000us / 帧预算 16700us\n");
        \printf("口径：PHP 解释器 + 软光栅；AOT 未复量\n");
    }

    $scaleItems = [];
    $scaleItems[] = new CheckItem('①', $nodes >= 10000, \sprintf(
        '12k 档真建起来了：%d 节点（≥10000）',
        $nodes,
    ));

    $fourOk = $cold > 0 && $steady > 0 && $full > 0 && $rebuildLayout > 0;
    $scaleItems[] = new CheckItem('②', $fourOk, \sprintf(
        '四数齐全且非零：冷 %.1f / 稳态 %.1f / 全量 %.1f / 重建 layout %d us',
        $cold,
        $steady,
        $full,
        $rebuildLayout,
    ), [
        'nodes' => $nodes,
        'coldUs' => $cold,
        'perFrameUs' => $steady,
        'layoutUs' => $full,
        'rebuildLayoutUs' => $rebuildLayout,
        'usPerNode' => $perNode,
        'baseUsPerNode' => $basePerNode,
    ]);

    $ratio = $basePerNode > 0 ? $perNode / $basePerNode : 0.0;
    $linearOk = $ratio >= 0.5 && $ratio <= 2.0;
    // ★ 离散度印在判据里、**不进判据**：它是机器的属性。但这个数是 ③ 的全部可信度来源 ——
    //   两档各测一次时的比值在 1.00–1.35 之间跳（实测，真实值 ~1.05），
    //   不把离散摆出来，读者会把 1.35 当成"超线性增长"。
    $scaleItems[] = new CheckItem('③', $linearOk, \sprintf(
        '归一化自洽：%.3f / %.3f us每节点 = %.2f×（容许 0.5–2.0；离散 %.2f× / %.2f×）',
        $perNode,
        $basePerNode,
        $ratio,
        (float) ($big['layoutSpread'] ?? 1.0),
        (float) ($base['layoutSpread'] ?? 1.0),
    ));

    $steadyOk = $steady > 0 && $steady <= 8000.0;
    $scaleItems[] = new CheckItem('④', $steadyOk, \sprintf(
        '稳态帧 %.1fus %s 8000us（8ms 产品要求）',
        $steady,
        $steadyOk ? '≤' : '超过',
    ));

    $premiseOk = $full > 0 && $rebuildLayout >= $full / 2.0;
    $scaleItems[] = new CheckItem('⑤', $premiseOk, \sprintf(
        'layout 仍是全量：重建帧 %dus %s 全量重排 %.1fus 的一半',
        $rebuildLayout,
        $premiseOk ? '≥' : '<',
        $full,
    ));

    (new CheckReport(
        'frame-tax --scale',
        $scaleItems,
        \sprintf('5 项判据全过（%d 节点 / %d 帧 / %.3f us每节点）', $nodes, $frames, $perNode),
    ))->emit($json);
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