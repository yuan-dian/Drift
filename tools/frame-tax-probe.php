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
 * ★ 别拿它当门禁：墙钟数随机器浮动，`gate` 里放它只会变成一台"随机失败的机器"。
 */

require __DIR__ . '/autoload.php';

use Drift\App\Application;
use Drift\App\Frame;
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
foreach ([10, 50, 200, 500] as $rows) {
    $r = $measure($rows, 50);
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
            $parts[] = $k . '=' . \round($v / 1000.0, 2) . 'ms';
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
}
