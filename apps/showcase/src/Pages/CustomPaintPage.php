<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\DashPattern;
use Drift\Rendering\Gradient;
use Drift\Rendering\ImageHandle;
use Drift\Rendering\Paint;
use Drift\Rendering\Path;
use Drift\Rendering\Shadow;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\CustomPaint;
use Drift\Widget\CustomPainter;
use Drift\Widget\ScrollView;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 页 8「自绘 CustomPaint」—— 19 op 的逃生舱 + "用真实控件检验 19 op 够不够用"。
 *
 * 对照：design-v6 的 CustomPaint 决策（回植 2026-10-20 P2）。两个真实自绘控件：
 * 仪表盘（指针动画）与走势折线。**验证结论先写在这里**：
 *
 * | 想画的 | 19 op 现在 | 判定 |
 * |---|---|---|
 * | 表盘 / 指针 / 刻度 / 端点 / 网格 / 折线 | `ELLIPSE` + `LINE` + `RECT`（Core 层） | **够**，双后端都能出图 |
 * | 圆弧刻度 | 无 `ARC` op ⇒ 11 根线段拼出来（本例的做法） | **勉强够**：视觉对、程序啰嗦；要省事得加 op 或上 P4 的 `PATH` |
 * | 折线下面积填充 | Optional 的 `drawPath`（P4 已接 Skia 后端） | **够**（见 P4 画家） |
 * | 画布内文字（刻度数字） | `drawParagraph` 在，但 Paragraph 构造是重路径 | **绕开**：数字放控件层（`Text`），画家只管几何 |
 *
 * ★ **指针动画是画家 + 帧时钟**（§12.3 纯 UI 态）：相位住 `State::tickFrame()`
 *   （每帧一步，返回 true ⇒ 帧时钟不停，与 `Spinner` 同驱动）；画家只按值画。
 * ★ **`painter->version()` 有真实消费者**：HUD 每帧显示内容戳 —— "没人调的
 *   分支等价于未实现"，所以版本戳被这一行的 `Text` 真的读出来。
 */
final class CustomPaintPage extends StatefulWidget
{
    public function __construct()
    {
        parent::__construct();
    }

    public static function make(): self
    {
        return new self();
    }

    public function createState(): State
    {
        return new CustomPaintPageState();
    }
}

/**
 * 仪表盘画家：值 0..1 决定指针角度；`version()` = 值变了几次（内容戳）。
 *
 * ★ **方向表是字面量不是 `cos()/sin()`**：§14.1 纯函数 —— libm 的跨平台末位
 *   差异会让画面在多平台间不可比（`RenderSpinner` 同族先例），11 个方向写死。
 */
final class GaugePainter extends CustomPainter
{
    private float $value = 0.0;

    private int $ver = 0;

    public function value(float $v): void
    {
        $this->value = $v < 0.0 ? 0.0 : ($v > 1.0 ? 1.0 : $v);
        $this->ver++;
    }

    public function version(): int
    {
        return $this->ver;
    }

    public function paint(Canvas $canvas, Size $size): void
    {
        $cx = $size->width / 2.0;
        $cy = $size->height * 0.5;
        $rx = $size->width * 0.45;
        $ry = $size->height * 0.45;
        if ($rx <= 0.0 || $ry <= 0.0) {
            return;
        }

        // 表盘（填充 + 描边）
        $face = new Rect($cx - $rx, $cy - $ry, $rx * 2.0, $ry * 2.0);
        // P3 的渐变消费者：表盘用径向渐变填（中心亮、边缘沉），不是纯色。
        $canvas->drawEllipse($face, Paint::fill(new Color(244, 246, 250, 255))->withGradient(
            Gradient::radial($cx, $cy - $ry * 0.35, max($rx, $ry), [
                new Color(255, 255, 255, 255),
                new Color(222, 229, 240, 255),
            ])
        ));
        $canvas->drawEllipse($face, Paint::stroke(new Color(70, 82, 104, 255), 2.0));

        // 刻度：11 根沿下半圆的线段（无 arc op ⇒ 线段拼弧）
        $dirs = self::directions();
        foreach ($dirs as $i => $d) {
            $major = $i % 5 === 0;
            $canvas->drawLine(
                $cx + $d[0] * $rx * ($major ? 0.70 : 0.82),
                $cy + $d[1] * $ry * ($major ? 0.70 : 0.82),
                $cx + $d[0] * $rx * 0.95,
                $cy + $d[1] * $ry * 0.95,
                Paint::stroke(
                    $major ? new Color(40, 52, 76, 255) : new Color(130, 140, 158, 255),
                    $major ? 2.0 : 1.0
                )
            );
        }

        // 指针 + 中心帽
        $needle = self::directionAt($this->value);
        $canvas->drawLine(
            $cx,
            $cy,
            $cx + $needle[0] * $rx * 0.72,
            $cy + $needle[1] * $ry * 0.72,
            Paint::stroke(new Color(200, 48, 48, 255), 3.0)
        );
        $canvas->drawEllipse(new Rect($cx - 5.0, $cy - 5.0, 10.0, 10.0), Paint::fill(new Color(40, 52, 76, 255)));
    }

    /**
     * 11 个方向（左水平 → 底 → 右水平，屏幕坐标 y 向下）。
     *
     * @return list<array{float, float}>
     */
    private static function directions(): array
    {
        $s1 = 0.30901699437494745;
        $s2 = 0.5877852522924731;
        $s3 = 0.8090169943749475;
        $s4 = 0.9510565162951535;

        return [
            [-1.0, 0.0], [-$s4, $s1], [-$s3, $s2], [-$s2, $s3], [-$s1, $s4],
            [0.0, 1.0],
            [$s1, $s4], [$s2, $s3], [$s3, $s2], [$s4, $s1], [1.0, 0.0],
        ];
    }

    /** t∈[0,1] 在方向表上线性插值（字面量混合，无三角函数） */
    private static function directionAt(float $t): array
    {
        $dirs = self::directions();
        $n = count($dirs) - 1;
        if ($t <= 0.0) {
            return $dirs[0];
        }
        if ($t >= 1.0) {
            return $dirs[$n];
        }
        $pos = $t * $n;
        $lo = (int) $pos;
        $f = $pos - $lo;
        if ($lo >= $n) {
            return $dirs[$n];
        }

        return [
            $dirs[$lo][0] * (1.0 - $f) + $dirs[$lo + 1][0] * $f,
            $dirs[$lo][1] * (1.0 - $f) + $dirs[$lo + 1][1] * $f,
        ];
    }
}

/** 走势画家：网格 + 12 点折线（线段逐段拼）+ 末点标记。只画 Core 层。 */
final class SparklinePainter extends CustomPainter
{
    /** @var list<float> 12 点的归一化序列（字面量，确定性） */
    private const SERIES = [0.20, 0.35, 0.28, 0.52, 0.44, 0.66, 0.58, 0.78, 0.70, 0.90, 0.82, 0.96];

    public function paint(Canvas $canvas, Size $size): void
    {
        $left = 8.0;
        $top = 8.0;
        $w = $size->width - $left * 2.0;
        $h = $size->height - $top * 2.0;
        if ($w <= 0.0 || $h <= 0.0) {
            return;
        }

        // 网格：3 横 + 3 竖
        for ($g = 1; $g < 3; $g++) {
            $y = $top + $h * ($g / 3.0);
            // P3 的虚线消费者：网格走虚线（图表里最常见的一档）
            $grid = Paint::stroke(new Color(226, 230, 238, 255), 1.0)->withDash(new DashPattern(4.0, 4.0));
            $canvas->drawLine($left, $y, $left + $w, $y, $grid);
            $x = $left + $w * ($g / 3.0);
            $canvas->drawLine($x, $top, $x, $top + $h, $grid);
        }

        // 折线：无 polyline op ⇒ 相邻两点一线段
        $n = count(self::SERIES);
        $stepX = $w / ($n - 1);
        for ($i = 1; $i < $n; $i++) {
            $canvas->drawLine(
                $left + $stepX * ($i - 1),
                $top + $h * (1.0 - self::SERIES[$i - 1]),
                $left + $stepX * $i,
                $top + $h * (1.0 - self::SERIES[$i]),
                Paint::stroke(new Color(30, 110, 220, 255), 2.0)
            );
        }

        // 末点标记
        $canvas->drawEllipse(
            new Rect($left + $w - 4.0, $top + $h * (1.0 - self::SERIES[$n - 1]) - 4.0, 8.0, 8.0),
            Paint::fill(new Color(30, 110, 220, 255))
        );
    }
}

/**
 * P4 画家：把 19 op 的 Optional / Extended 复合 op 当真实消费者用一遍
 * （"没人调的分支等价于未实现"，所以 P4 的每个 op 都要有一个真的绘制出口）。
 *
 * - `drawShadow`：卡片背后的软阴影（几何 + 偏移 + 模糊 + 浓度）
 * - `drawPath`：填充三角形（折线下填色现在能画了，不再等 P4）
 * - `pushLayer` / `popLayer`：半透明合成层
 * - `drawImage`：渐变 swatch（内存 RGBA，行优先 4 字节）
 * - `pushClipPath`：圆形路径裁剪（后续矩形只落在形状内）
 */
final class P4Painter extends CustomPainter
{
    /**
     * 生成一张 2D 渐变 swatch（行优先 RGBA），证明 drawImage 的字节串能过桥回画。
     *
     * ★★ 数组攒 + implode：`$rgba .= …` 是二进制串（每像素 4 字节、含 \0 与高位），
     *   AOT 下循环内累加会写坏多字节串 ⇒ 图像乱码 / 崩在无关代码（§2.4 纪律 2）。
     */
    private function makeSwatch(int $w, int $h): ImageHandle
    {
        $rows = [];
        for ($y = 0; $y < $h; $y++) {
            $px = [];
            for ($x = 0; $x < $w; $x++) {
                $t = $x / \max(1, $w - 1);
                $r = (int) (255.0 * $t);
                $g = (int) (120.0 + 80.0 * (1.0 - $t));
                $b = (int) (255.0 * (1.0 - $t));
                $px[] = \chr($r) . \chr($g) . \chr($b) . "\xFF";
            }
            $rows[] = \implode('', $px);
        }
        return ImageHandle::fromRgba(\implode('', $rows), $w, $h);
    }

    public function paint(Canvas $canvas, Size $size): void
    {
        // 软阴影：卡片背后（drawShadow，alpha = 浓度，不是额外乘算）
        $canvas->drawShadow(new Shadow(new Rect(10.0, 8.0, 130.0, 60.0), new Color(0, 0, 0, 60), 8.0, 3.0, 4.0, 6.0));

        // 填充三角形（drawPath，P4 新增；折线下填色现在能画）
        $tri = Path::create()
            ->moveTo(18.0, 14.0)
            ->lineTo(62.0, 14.0)
            ->lineTo(40.0, 58.0)
            ->close();
        $canvas->drawPath($tri, Paint::fill(new Color(40, 140, 220, 255)));

        // 合成层：半透明叠一层（pushLayer + popLayer）
        $canvas->pushLayer(0.5);
        $canvas->drawRect(new Rect(76.0, 16.0, 56.0, 46.0), Paint::fill(new Color(220, 60, 60, 255)));
        $canvas->popLayer();

        // 图像：渐变 swatch（drawImage，内存 RGBA 字节串过桥）
        $canvas->drawImage($this->makeSwatch(48, 48), null, new Rect(144.0, 15.0, 48.0, 48.0));

        // 路径裁剪：绿色横条只落在下方菱形内（pushClipPath + popClip）
        $clip = Path::create()
            ->moveTo(210.0, 78.0)
            ->quadTo(240.0, 48.0, 270.0, 78.0)
            ->quadTo(240.0, 108.0, 210.0, 78.0)
            ->close();
        $canvas->pushClipPath($clip);
        $canvas->drawRect(new Rect(206.0, 40.0, 68.0, 80.0), Paint::fill(new Color(120, 200, 120, 255)));
        $canvas->popClip();
    }
}

final class CustomPaintPageState extends State
{
    /** 每帧指针走的行程（0..1） */
    private const STEP = 0.004;

    private GaugePainter $gauge;

    private float $phase = 0.0;

    private bool $rising = true;

    public function initState(): void
    {
        $this->gauge = new GaugePainter(0.0);
    }

    public function tickFrame(float $timestampMs): bool
    {
        // ★ 步长固定（读注入的时间戳就引入帧率依赖 ⇒ 同输入不同墨，G3 破防）；
        //   "我还要下一帧" ⇒ 帧时钟不停（Spinner 同款驱动）。
        $this->setState(function (): void {
            $this->phase += $this->rising ? self::STEP : -self::STEP;
            if ($this->phase >= 1.0) {
                $this->phase = 1.0;
                $this->rising = false;
            }
            if ($this->phase <= 0.0) {
                $this->phase = 0.0;
                $this->rising = true;
            }
            $this->gauge->value($this->phase);
        });

        return true;
    }

    public function build(BuildContext $ctx): Widget
    {
        // ★ 每帧重建 ⇒ Widget 不可变 ⇒ 新 CustomPaint 整体换新画家指针
        //   （§13.2 字段对齐由 lint 比对）；HUD 这一行是 version() 的真实消费者。
        return Card::make(ScrollView::make($this->body()))->paddingAll(0.0);
    }

    private function body(): Widget
    {
        return Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
            Text::make('自绘 CustomPaint —— 19 op 逃生舱')->title(),
            Text::make(
                '19 op 覆盖不到的控件从这里出去：画家拿到已对齐盒原点的画布，直接调 Canvas 的 op 画。'
                . '下面两个控件只画 Core 层（椭圆 / 线段 / 矩形）⇒ Skia 与软光栅双后端都能出图。'
            )->caption(),

            // ── P4 复合 op 真实消费者 ──
            //   刻意排在前面：默认窗口高度下这张卡就完整可见（真机探针无需滚动
            //   就能判"6 个 op 是否都出图"），滚动后才见后面的动画控件。
            Card::make(
                Column::make()->spacing(8.0)->children([
                    Text::make('P4 · 复合 op（drawShadow / drawPath / pushLayer / drawImage / pushClipPath）')->heading(),
                    new CustomPaint(new P4Painter(), 300.0, 170.0),
                    Text::make('阴影 + 填充三角 + 半透明合成层 + 渐变 swatch 图像 + 圆形路径裁剪。')->caption(),
                ])
            ),

            // ── 仪表盘：指针每帧扫动 ──
            Card::make(
                Column::make()->spacing(8.0)->children([
                    Text::make('仪表盘（画家 + 帧时钟动画）')->heading(),
                    new CustomPaint($this->gauge, 300.0, 180.0),
                    Text::make('椭圆表盘 + 11 根刻度线段拼弧（无 arc op）+ 指针线段 + 中心帽。')->caption(),
                ])
            ),

            // ── 走势折线 ──
            Card::make(
                Column::make()->spacing(8.0)->children([
                    Text::make('走势折线（静态）')->heading(),
                    new CustomPaint(new SparklinePainter(), 300.0, 140.0),
                    Text::make('网格 + 12 点折线逐段拼 + 末点椭圆标记。折线下填色用 P4 的 drawPath（见上）')->caption(),
                ])
            ),

            // ── 版本戳 HUD：painter->version() 的真实消费者 ──
            Card::make(
                Column::make()->spacing(4.0)->children([
                    Text::make('painter version = ' . $this->gauge->version())->body(),
                    Text::make('内容戳：指针值每变一次 +1。脏矩形 / 重绘调度（M6 候选）的消费位。')->caption(),
                ])
            ),
        ]);
    }
}
