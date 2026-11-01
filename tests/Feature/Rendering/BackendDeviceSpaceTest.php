<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Rendering;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\DisplayList;
use Drift\Rendering\Paint;
use Drift\Rendering\Path;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RecordingCanvas;
use Drift\Rendering\SkiaCanvas;

/**
 * **双后端的"一致"到底指什么** —— 一份换算，两个观察者。
 *
 * ## 为什么不是"逐像素一致"（P1-1 原口径，2026-11-01 改判）
 *
 * `SkiaCanvas` 的类注释早就写死了两条**有意**差异：抗锯齿（软光栅只对圆角/字形做
 * 覆盖度，Skia 对所有边做）与描边（软光栅"外圈减内圈"，Skia 居中 `kStroke`）。
 * 既然差异是有意的，**逐像素相等就是个恒假命题** —— 写成测试只会得到一条
 * 要么永远红、要么被调宽容差调到没有牙的断言。真正会出事的不是"边缘像素差 1"，
 * 而是 **几何差了 1 个偏移 / 半像素**（历史上 `drawParagraph` 丢 offset、
 * 「先取整再变换 vs 先变换再取整」都是这一类）。
 *
 * ## 现在的口径（Chromium `fuzzy` / Skia `dm` / Servo 参考测的同一个思路）
 *
 * 同一个 `DisplayList` 回放给两个光栅器，各自观察**能观察到的那个面**：
 *
 * | 观察者 | 观察到的 | 读法 |
 * |---|---|---|
 * | `SkiaCanvas` | 桥收到的参数（**就是设备坐标**） | 假桥记录，`skiaCalls()` |
 * | `RasterCanvas` | 像素 | `inkBounds()` 取墨迹包围盒 |
 *
 * 两边都必须等于**同一个规格**：`round((逻辑值 + 偏移) × dpr)`。
 * ★ 规格在这里**用定义重算**（`self::dev()`），**不调** `Scale::deviceRect()` ——
 *   否则测试与被测共用一份错（那正是"23 个用例全绿但产品错"的形态）。
 *
 * ## 容差：只有一处，且是算出来的（不是"先铺一层宽容差"）
 *
 * - **矩形族（填充 / 圆角 / 椭圆 / 裁剪）= 精确相等（容差 0）**。别处常见
 *   "先给 1~2px 免得红"，本文件不做：2026-11-01 实测椭圆墨迹
 *   `[13,13,32,24]` 与规格**逐位相同**，所以不给它留余量 —— 留了就等于把
 *   "哪天真的差了 1px"也一起放过。
 * - **路径 = ±1**，理由是算得出来的，不是感觉：三角形最右是**一个顶点**
 *   （设备 x=23），中心采样在 y=7.5 时右端只到 22.47 ⇒ **最后一列没有像素中心
 *   落入**，墨迹到 21 是对的。硬边扫描线就该这样，不是缺陷。
 *
 * ## 已知有意差异（不在这里比，但在这里记着）
 *
 * 描边：软光栅把整圈画在设备矩形**之内**，Skia 居中 ⇒ 外沿会溢出半宽。
 * 本文件对描边行断的是"**两个数都对**"（桥矩形 == spec，软光栅墨迹 == spec）
 * 外加"内部是空的"（证明软光栅真的是内缩描边），**不**断两边像素相同。
 */
final class BackendDeviceSpaceTest extends SmokeTestCase
{
    private const SIZE = 64;

    private static function ink(): Color
    {
        return Color::fromRgb(200, 40, 40);
    }

    private static function knob(): Color
    {
        return Color::white();
    }

    /**
     * 规格的唯一来源：**先加偏移，再乘 dpr，最后一次取整**。
     *
     * ★ 这就是 `Scale::deviceX()` 的定义，这里照定义重写一遍 —— 抄定义是**故意**的
     *   （调用实现就等于不测它）。改实现的语义而这里没改 ⇒ 红。
     */
    private static function dev(float $v, float $delta, float $dpr): float
    {
        return \round(($v + $delta) * $dpr);
    }

    /**
     * 逻辑矩形 → 期望的设备盒 `[left, top, right, bottom]`。
     *
     * ★ 两条边各自取整（`Scale::quantizeRect` 的口径：不是"左边取整 + 宽度取整"），
     *   所以这里对 `right()/bottom()` 单独 `dev()`，不是 `left + width`。
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function devBox(Rect $r, float $dx, float $dy, float $dpr): array
    {
        return [
            self::dev($r->left, $dx, $dpr),
            self::dev($r->top, $dy, $dpr),
            self::dev($r->right(), $dx, $dpr),
            self::dev($r->bottom(), $dy, $dpr),
        ];
    }

    /**
     * 录**一次**、回放**两次**：返回 `[软光栅墨迹盒, 桥调用列表]`。
     *
     * ★ 偏移放进**命令流**（`save` + `translate` + … + `restore`），而不是直接调
     *   画布方法 —— 这样测的才是"同一份 DisplayList 两个光栅器"，也正是线上那条路。
     *
     * @return array{0: array{0: int, 1: int, 2: int, 3: int}|null, 1: list<array<int, mixed>>}
     */
    private static function observe(callable $draw, float $dx, float $dy, float $dpr): array
    {
        $list = new DisplayList();
        $recorder = new RecordingCanvas($list);
        $shifted = $dx !== 0.0 || $dy !== 0.0;
        if ($shifted) {
            $recorder->save();
            $recorder->translate($dx, $dy);
        }
        $draw($recorder);
        if ($shifted) {
            $recorder->restore();
        }

        $raster = new RasterCanvas(self::SIZE, self::SIZE, self::knob(), Scale::of($dpr));
        $list->replay($raster);

        skiaBridgeReset();
        $skia = new SkiaCanvas(self::SIZE, self::SIZE, self::knob(), Scale::of($dpr));
        $list->replay($skia);

        return [self::inkBox($raster), skiaCalls()];
    }

    /**
     * 软光栅的墨迹盒，统一成 `[left, top, right, bottom]`（**闭区间**，含两端像素）。
     *
     * ★ 设备矩形的 `right/bottom` 是开区间边界 ⇒ 最后一个墨点像素是 `right - 1`。
     *   这个换算只在这里做一次。
     *
     * @return array{0: int, 1: int, 2: int, 3: int}|null
     */
    private static function inkBox(RasterCanvas $canvas): ?array
    {
        $b = inkBounds($canvas, self::knob(), self::SIZE, self::SIZE);

        return $b === null ? null : [$b[0], $b[2], $b[1], $b[3]];
    }

    /**
     * 桥调用里第一个名为 `$op` 的元组；没有 ⇒ null。
     *
     * @param list<array<int, mixed>> $calls
     * @return array<int, mixed>|null
     */
    private static function bridgeOp(array $calls, string $op): ?array
    {
        foreach ($calls as $call) {
            if (($call[0] ?? null) === $op) {
                return $call;
            }
        }

        return null;
    }

    /** @param array{0: float, 1: float, 2: float, 3: float} $want */
    private static function fmt(array $want): string
    {
        return \sprintf('[%g, %g, %g, %g]', $want[0], $want[1], $want[2], $want[3]);
    }

    /**
     * 矩形族的四行：填充 / 描边 / 椭圆 / 裁剪，覆盖"偏移为零 / 偏移为整 / 偏移带半像素 / dpr=2"。
     *
     * 每一行都断**三件**：桥收到的设备盒 == 规格、软光栅的墨迹盒 == 规格、
     * 以及（仅描边）"内部是空的"。
     */
    public function testRectFamilyAgreesOnOneDeviceBox(): void
    {
        // [名字, 逻辑矩形, dx, dy, dpr, 桥 op, 画法]（容差恒 0，见类注释）
        $cases = [
            ['填充 · 无偏移 dpr1', new Rect(8.0, 6.0, 20.0, 16.0), 0.0, 0.0, 1.0, 'draw_rect', 'fill'],
            ['填充 · 整偏移（偏移只许加一次）', new Rect(8.0, 6.0, 20.0, 16.0), 7.0, 5.0, 1.0, 'draw_rect', 'fill'],
            ['填充 · 半像素偏移（先加后取整，不是先取整再加）', new Rect(1.5, 0.5, 10.0, 10.0), 0.5, 0.5, 1.0, 'draw_rect', 'fill'],
            ['填充 · dpr2（dpr 乘在"和"上）', new Rect(8.0, 6.0, 20.0, 16.0), 3.5, 2.5, 2.0, 'draw_rect', 'fill'],
            ['填充 · 圆角不影响外接盒', new Rect(8.0, 6.0, 20.0, 16.0), 7.0, 5.0, 1.0, 'draw_rect', 'radius'],
            ['椭圆 · 整偏移（内切 ⇒ 外接盒同规格）', new Rect(6.0, 8.0, 20.0, 12.0), 7.0, 5.0, 1.0, 'draw_ellipse', 'ellipse'],
        ];

        foreach ($cases as [$name, $rect, $dx, $dy, $dpr, $op, $kind]) {
            $want = self::devBox($rect, $dx, $dy, $dpr);
            $draw = match ($kind) {
                'ellipse' => static fn(Canvas $c) => $c->drawEllipse($rect, Paint::fill(self::ink())),
                'radius' => static fn(Canvas $c) => $c->drawRect($rect, Paint::fill(self::ink()), 6.0),
                default => static fn(Canvas $c) => $c->drawRect($rect, Paint::fill(self::ink())),
            };
            [$inkBox, $calls] = self::observe($draw, $dx, $dy, $dpr);

            $call = self::bridgeOp($calls, $op);
            $bridge = $call === null ? null : [
                (float) $call[1],
                (float) $call[2],
                (float) $call[1] + (float) $call[3],
                (float) $call[2] + (float) $call[4],
            ];
            check(
                '双后端 · ' . $name . ' · **Skia 桥**收到的设备盒 == 规格',
                $bridge !== null && $bridge === $want,
                '桥 ' . ($bridge === null ? '无 ' . $op . ' 调用' : self::fmt($bridge)) . ' ｜ 规格 ' . self::fmt($want),
            );

            // 软光栅的墨迹盒是闭区间 ⇒ 与开区间的规格差一格
            // ★ 必须落成 int：`round()` 给的是 float，而 `inkBounds()` 给的是 int，
            //   严格比较会因类型不同而假红（本文件第一版就栽在这上面）。
            $wantInk = [(int) $want[0], (int) $want[1], (int) $want[2] - 1, (int) $want[3] - 1];
            check(
                '双后端 · ' . $name . ' · **软光栅**的墨迹盒 == 规格',
                $inkBox === $wantInk,
                '墨迹 ' . ($inkBox === null ? '无墨' : self::fmt($inkBox)) . ' ｜ 规格 ' . self::fmt($wantInk),
            );
        }

        $this->flushChecks();
    }

    /**
     * 描边：两个数都对 + **内部必须是空的**。
     *
     * ★ 这是本文件唯一断"已知有意差异"的地方：软光栅把整圈画在设备矩形之内
     *   （外圈减内圈），Skia 居中（外沿溢出半宽）。所以不比像素，只比**各自该对的数**
     *   —— 桥矩形 == 外接规格、软光栅墨迹 == 外接规格、并且中心像素是背景
     *   （少了最后这条，"描边画成实心"也能过前两条）。
     */
    public function testStrokeRectKeepsItsHole(): void
    {
        $rect = new Rect(10.0, 12.0, 24.0, 20.0);
        $dx = 6.0;
        $dy = 4.0;
        $dpr = 1.0;
        $want = self::devBox($rect, $dx, $dy, $dpr);

        $raster = new RasterCanvas(self::SIZE, self::SIZE, self::knob(), Scale::of($dpr));
        $list = new DisplayList();
        $recorder = new RecordingCanvas($list);
        $recorder->save();
        $recorder->translate($dx, $dy);
        $recorder->drawRect($rect, Paint::stroke(self::ink(), 2.0));
        $recorder->restore();
        $list->replay($raster);

        $inkBox = self::inkBox($raster);
        $wantInk = [(int) $want[0], (int) $want[1], (int) $want[2] - 1, (int) $want[3] - 1];
        check(
            '双后端 · 描边 · 软光栅外沿 == 规格（整圈画在设备矩形之内）',
            $inkBox === $wantInk,
            '墨迹 ' . ($inkBox === null ? '无墨' : self::fmt($inkBox)) . ' ｜ 规格 ' . self::fmt($wantInk),
        );

        $cx = (int) (($want[0] + $want[2]) / 2.0);
        $cy = (int) (($want[1] + $want[3]) / 2.0);
        $center = $raster->pixelAt($cx, $cy);
        check(
            '双后端 · 描边 · 内部是空的（否则"描边画成实心"也能过上面那条）',
            $center->equals(self::knob()),
            '中心 (' . $cx . ',' . $cy . ') = rgb(' . $center->r . ',' . $center->g . ',' . $center->b . ')',
        );

        skiaBridgeReset();
        $skia = new SkiaCanvas(self::SIZE, self::SIZE, self::knob(), Scale::of($dpr));
        $list->replay($skia);
        $call = self::bridgeOp(skiaCalls(), 'draw_rect');
        $bridge = $call === null ? null : [
            (float) $call[1],
            (float) $call[2],
            (float) $call[1] + (float) $call[3],
            (float) $call[2] + (float) $call[4],
        ];
        check(
            '双后端 · 描边 · Skia 桥收到的设备盒 == 同一份规格',
            $bridge !== null && $bridge === $want,
            '桥 ' . ($bridge === null ? '无 draw_rect' : self::fmt($bridge)) . ' ｜ 规格 ' . self::fmt($want),
        );

        $this->flushChecks();
    }

    /**
     * 线段端点：四个标量各自走一次"加偏移 + 乘 dpr + 取整"。
     *
     * ★ 这一行专治"端点算了、宽度也乘了 dpr"（宽度**不**乘：两个后端的统一口径，
     *   见 `Canvas::drawEllipse` 的 docblock）。
     */
    public function testLineEndpointsUseTheSameRounding(): void
    {
        $cases = [
            ['无偏移', 2.0, 3.0, 40.0, 45.0, 0.0, 0.0, 1.0],
            ['整偏移', 2.0, 3.0, 40.0, 45.0, 5.0, 7.0, 1.0],
            ['半像素 + dpr2', 1.5, 2.5, 20.5, 30.5, 0.5, 0.5, 2.0],
        ];

        foreach ($cases as [$name, $x1, $y1, $x2, $y2, $dx, $dy, $dpr]) {
            $want = [
                self::dev($x1, $dx, $dpr),
                self::dev($y1, $dy, $dpr),
                self::dev($x2, $dx, $dpr),
                self::dev($y2, $dy, $dpr),
            ];
            [, $calls] = self::observe(
                static fn(Canvas $c) => $c->drawLine($x1, $y1, $x2, $y2, Paint::stroke(self::ink(), 2.0)),
                $dx,
                $dy,
                $dpr,
            );
            $call = self::bridgeOp($calls, 'draw_line');
            $bridge = $call === null ? null : [
                (float) $call[1],
                (float) $call[2],
                (float) $call[3],
                (float) $call[4],
            ];
            check(
                '双后端 · 线段 · ' . $name . ' · 端点 == 规格',
                $bridge !== null && $bridge === $want,
                '桥 ' . ($bridge === null ? '无 draw_line' : self::fmt($bridge)) . ' ｜ 规格 ' . self::fmt($want),
            );
            check(
                '双后端 · 线段 · ' . $name . ' · 宽度**不**乘 dpr（两个后端的统一口径）',
                $call !== null && (float) $call[6] === 2.0,
                '桥收到宽度 ' . ($call === null ? 'N/A' : (string) $call[6]),
            );
        }

        $this->flushChecks();
    }

    /**
     * 路径顶点：`packPath()` 是同一公式**手抄最多**的地方（六个标量两处），
     * 这里直接解包桥收到的 `float32` 坐标来对。
     */
    public function testPathVerticesUseTheSameRounding(): void
    {
        $dx = 3.0;
        $dy = 2.0;
        $dpr = 2.0;
        $tri = Path::create()->moveTo(4.0, 5.0)->lineTo(20.0, 5.0)->lineTo(4.0, 20.0)->close();

        [$inkBox, $calls] = self::observe(
            static fn(Canvas $c) => $c->drawPath($tri, Paint::fill(self::ink())),
            $dx,
            $dy,
            $dpr,
        );

        $call = self::bridgeOp($calls, 'draw_path');
        $floats = $call === null ? [] : \array_values(\unpack('f*', (string) $call[3]));

        // 每个命令 4 个 float，前两槽是 x/y
        $vertices = [
            [$floats[0] ?? null, $floats[1] ?? null],
            [$floats[4] ?? null, $floats[5] ?? null],
            [$floats[8] ?? null, $floats[9] ?? null],
        ];
        $wantVertices = [
            [self::dev(4.0, $dx, $dpr), self::dev(5.0, $dy, $dpr)],
            [self::dev(20.0, $dx, $dpr), self::dev(5.0, $dy, $dpr)],
            [self::dev(4.0, $dx, $dpr), self::dev(20.0, $dy, $dpr)],
        ];
        check(
            '双后端 · 路径 · 三个顶点 == 规格（设备坐标，偏移只加一次）',
            $vertices === $wantVertices,
            '桥 ' . \json_encode($vertices) . ' ｜ 规格 ' . \json_encode($wantVertices),
        );

        // 三角形的包围盒：软光栅是硬边扫描线、Skia 有抗锯齿 ⇒ 只放到 ±1
        $wantInk = self::devBox(new Rect(4.0, 5.0, 16.0, 15.0), $dx, $dy, $dpr);
        $ok = $inkBox !== null
            && \abs($inkBox[0] - $wantInk[0]) <= 1
            && \abs($inkBox[1] - $wantInk[1]) <= 1
            && \abs($inkBox[2] - ($wantInk[2] - 1)) <= 1
            && \abs($inkBox[3] - ($wantInk[3] - 1)) <= 1;
        check(
            '双后端 · 路径 · 软光栅墨迹落在同一盒内（±1：硬边 vs 抗锯齿）',
            $ok,
            '墨迹 ' . ($inkBox === null ? '无墨' : self::fmt($inkBox)) . ' ｜ 规格 ' . self::fmt($wantInk),
        );

        $this->flushChecks();
    }

    /**
     * **量具自检**：这四行断言不是恒真的 —— 把偏移漏掉 / 顺序反过来，算出来的盒子必须变。
     *
     * ★ 没有这条，"规格"与"实现"一起错的时候本文件会全绿（本仓的老病：判据恒真）。
     */
    public function testTheSpecIsNotVacuous(): void
    {
        // ★ 数字要挑"取整会翻面"的：1.2 + 0.4 = 1.6 ⇒ 2，而 1.2 自己 ⇒ 1。
        //   第一版用 1.5 / 0.5（1.5+0.5=2 与 round(1.5)=2 撞上了）⇒ 这条自检恒真。
        $rect = new Rect(1.2, 0.7, 9.0, 10.0);
        $withOffset = self::devBox($rect, 0.4, 0.4, 1.0);
        $withoutOffset = self::devBox($rect, 0.0, 0.0, 1.0);
        check(
            '量具自检 · 漏掉偏移会算出不同的盒子（否则"偏移"那几行是恒真的）',
            $withOffset !== $withoutOffset,
            self::fmt($withOffset) . ' vs ' . self::fmt($withoutOffset),
        );

        $roundThenAdd = [
            \round($rect->left * 1.0) + \round(0.4 * 1.0),
            \round($rect->top * 1.0) + \round(0.4 * 1.0),
        ];
        check(
            '量具自检 · "先取整再加"与"先加再取整"结果不同（顺序判据有牙）',
            $roundThenAdd !== [$withOffset[0], $withOffset[1]],
            \json_encode($roundThenAdd) . ' vs [' . $withOffset[0] . ',' . $withOffset[1] . ']',
        );

        $this->flushChecks();
    }
}
