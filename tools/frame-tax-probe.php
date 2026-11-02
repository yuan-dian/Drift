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
 * | ③ | 分段里必须出现 `anim` | "没扫到"冒充"没有"（否定结论要自证扫到了东西） |
 * | ④ | 稳态帧 paint 全跳过（run=0 && skip=帧数） | paint 门控退化 ⇒ 每帧整棵重画 |
 * | ⑤ | `layout` 段 ≤ 全量重排的 5% | layout 门控退化 ⇒ 稳态帧变成全树重排 |
 * | ⑥ | `structureVersion/boxEpoch/markedCount` 全 `+0` | "稳态"前提不成立（帧在自激），
 *       后面所有数字都不可信 |
 *
 * ★ ⑤ 是**比值**不是毫秒：分子分母是同一棵树上的**同一种操作**，机器慢了两边一起
 *   慢 ⇒ 比值稳。这也是它能进闸门的唯一理由（`Scale` 那条纪律的同类：只比同量纲）。
 * ★ `--check` 只跑 500 行一个规模（30 帧），约 1s；不带参数仍是四档报告。
 */

require __DIR__ . '/autoload.php';

use Drift\App\Application;use Drift\App\Frame;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
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
$check = \in_array('--check', $args, true);
/** `--check` 只跑一档（进闸门要快）；报告模式四档看趋势 */
$sizes = $check ? [500] : [10, 50, 200, 500];
$frames = $check ? 30 : 50;
/** @var array<string, mixed> 最后一档的结果（`--check` 判据读它） */
$last = [];

/**
 * 每行 = 一个 `Row` 带 3 个 `Text`，外层 `ScrollView` 给无界主轴（不会溢出）。
 *
 * ★ 用全量 `Column` 而不是 `LazyColumn`：后者只建可见项，节点数不随数据量变
 *   ⇒ 量不出"树大了每帧固定税怎么长"（那正是它的价值，不是缺陷）。
 */
$makeApp = static fn(int $rows): Application => new class($rows) extends Application {
    public function __construct(private readonly int $rows)
    {
        parent::__construct();
    }

    public function buildRoot(): Widget
    {
        $list = [];
        for ($r = 0; $r < $this->rows; $r++) {
            $list[] = Row::make()->children([
                Text::make('R' . (string) $r . 'A'),
                Text::make('R' . (string) $r . 'B'),
                Text::make('R' . (string) $r . 'C'),
            ]);
        }

        return ScrollView::make(Column::make()->children($list));
    }

    public function title(): string
    {
        return 'tax';
    }
};

const W = 400.0;
const H = 300.0;

$tight = BoxConstraints::tight(new Size(W, H));

/** @return array<string, mixed> */
$measure = static function (int $rows, int $frames) use ($makeApp, $tight): array {
    $app = $makeApp($rows);
    $surface = new SurfaceProbe();
    $surface->resize((int) W, (int) H, 1.0);
    $app->attach($surface, $tight);

    for ($i = 1; $i <= 10; $i++) { // 首帧要建整棵树，丢
        $app->frame($i * 16.0, $tight);
    }

    SurfaceProfile::$on = true;
    SurfaceProfile::reset();
    $paint0 = Frame::paintRunCount();
    $skip0 = Frame::paintSkipCount();
    $sv0 = Element::structureVersion();
    $be0 = State::boxEpoch();
    $mk0 = State::markedCount();

    $t0 = \hrtime(true);
    for ($i = 11; $i <= 10 + $frames; $i++) {
        $app->frame($i * 16.0, $tight);
    }
    $elapsed = \hrtime(true) - $t0;

    $sv = Element::structureVersion() - $sv0;
    $be = State::boxEpoch() - $be0;
    $mk = State::markedCount() - $mk0;
    $seg = SurfaceProfile::perCall();
    SurfaceProfile::$on = false;

    // 同一棵树单次全量重排（重建帧才付这一笔，作分母）
    $box = $app->root()?->effectiveBox();
    $layoutNs = 0;
    if ($box !== null) {
        $reps = 5;
        $lt = \hrtime(true);
        for ($k = 0; $k < $reps; $k++) {
            $box->layout($tight);
        }
        $layoutNs = (int) \round((\hrtime(true) - $lt) / $reps);
    }

    return [
        'rows' => $rows,
        'perFrameUs' => \round($elapsed / $frames / 1000.0, 1),
        'layoutUs' => \round($layoutNs / 1000.0, 1),
        'paint' => Frame::paintRunCount() - $paint0,
        'skip' => Frame::paintSkipCount() - $skip0,
        'seg' => $seg,
        'delta' => 'sv+' . $sv . ' box+' . $be . ' marked+' . $mk,
    ];
};

\printf("%6s %14s %12s %7s %6s\n", 'rows', 'perFrame(us)', 'layout(us)', 'paint', 'skip');
$prev = null;
foreach ($sizes as $rows) {
    $r = $measure($rows, $frames);
    \printf(
        "%6d %14.1f %12.1f %7d %6d\n",
        $r['rows'],
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
    \printf("       分段: %s\n", $parts === [] ? '（全部 <0.5us）' : \implode('  ', $parts));
    \printf("       版本: %s\n", $r['delta']);

    if ($prev !== null) {
        $dr = $r['rows'] - $prev['rows'];
        \printf(
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
/** @var list<array{0: string, 1: bool, 2: string}> [编号, 通过?, 说明] */
$items = [];

$why = SurfaceProfile::selfTest();
$items[] = ["①", $why === [], $why === [] ? "量具契约（formatNs 三档 + perCall 平均语义）" : \implode("；", $why)];

$worst = ["k" => "", "ns" => 0];
foreach ($seg as $k => $ns) {
    if ($ns > $worst["ns"]) {
        $worst = ["k" => (string) $k, "ns" => (int) $ns];
    }
}
$segOk = $perFrameNs > 0 && $worst["ns"] <= $perFrameNs * 1.25;
$items[] = ["②", $segOk, \sprintf(
    "分段自洽：最大段 %s=%s %s 整帧 %s（%.0f%%）",
    $worst["k"],
    SurfaceProfile::formatNs($worst["ns"]),
    $segOk ? "≤" : "超过",
    SurfaceProfile::formatNs($perFrameNs),
    $perFrameNs > 0 ? 100.0 * $worst["ns"] / $perFrameNs : 0.0,
)];

$items[] = ["③", isset($seg["anim"]), isset($seg["anim"])
    ? "扫到了 anim（" . SurfaceProfile::formatNs((int) $seg["anim"]) . "）"
    : "分段里没有 anim —— 没扫到 tickAnimations，\"它不贵\"这个结论不成立"];

$paintOk = $last["paint"] === 0 && $last["skip"] === $frames;
$items[] = ["④", $paintOk, \sprintf(
    "稳态帧 paint %s（run=%d skip=%d，期望 0/%d）",
    $paintOk ? "全跳过" : "没被门控跳过",
    $last["paint"],
    $last["skip"],
    $frames,
)];

$layoutSeg = (int) ($seg["layout"] ?? 0);
$fullNs = (int) \round($last["layoutUs"] * 1000.0);
$ratio = $fullNs > 0 ? $layoutSeg / $fullNs : 0.0;
$layoutOk = $fullNs > 0 && $ratio <= 0.05;
$items[] = ["⑤", $layoutOk, \sprintf(
    "稳态帧 %s重排：layout 段 %s / 全量 %s（%.2f%%，上限 5%%）",
    $layoutOk ? "不" : "在",
    SurfaceProfile::formatNs($layoutSeg),
    SurfaceProfile::formatNs($fullNs),
    $ratio * 100.0,
)];

$items[] = ["⑥", $last["delta"] === "sv+0 box+0 marked+0", $last["delta"] === "sv+0 box+0 marked+0"
    ? "稳态前提成立（" . $last["delta"] . "）"
    : "\"稳态\"前提不成立：" . $last["delta"] . "（帧在自激，上面的数字不可信）"];

$fail = [];
foreach ($items as [$no, $ok, $text]) {
    if ($ok) {
        \printf("  ok    %s %s\n", $no, $text);
        continue;
    }
    $fail[] = $no . " " . $text;
    \printf("  FAIL  %s %s\n", $no, $text);
}

if ($fail !== []) {
    \fwrite(STDERR, \sprintf("\n--- FAIL（%d 条）---\n", \count($fail)));
    exit(1);
}

\printf("\n--- 6 项判据全过（%d 行 / %d 帧）---\n", $last["rows"], $frames);
exit(0);