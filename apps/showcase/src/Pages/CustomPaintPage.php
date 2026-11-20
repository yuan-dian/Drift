<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\ShowcasePage;
use App\Showcase\Pages\Painters\GaugePainter;
use App\Showcase\Pages\Painters\P4Painter;
use App\Showcase\Pages\Painters\SparklinePainter;
use Drift\Geometry\EdgeInsets;
use Drift\Rendering\Canvas;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\CustomPaint;
use Drift\Widget\ScrollView;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 自绘 CustomPaint—— 19 op 的逃生舱 + "用真实控件检验 19 op 够不够用"。
 *
 * 对照：design-v6 的 CustomPaint 决策（P2 回植）。两个真实自绘控件：
 * 仪表盘（指针动画）与走势折线。**验证结论先写在这里**：
 *
 * | 想画的 | 19 op 现在 | 判定 |
 * |---|---|---|
 * | 表盘 / 指针 / 刻度 / 端点 / 网格 / 折线 | `ELLIPSE` + `LINE` + `RECT`（Core 层） | **够**，双后端都能出图 |
 * | 圆弧刻度 | 无 `ARC` op ⇒ 11 根线段拼出来（本例的做法） | **勉强够**：视觉对、程序啰嗦；要省事得加 op 或上 P4 的 `PATH` |
 * | 折线下面积填充 | Optional 的 `drawPath`（P4 已接 Skia 后端） | **够**（见 P4 画家） |
 * | 画布内文字（刻度数字） | `drawParagraph` 在，但 Paragraph 构造是重路径 | **绕开**：数字放控件层（`Text`），画家只管几何 |
 *
 * @capability-gap: const|Drift\Rendering\DisplayList|OP_ARC 圆弧 op 仍未补（现用 11 根线段拼）
 *
 * ★ **指针动画是画家 + 帧时钟**（§12.3 纯 UI 态）：相位住 `State::tickFrame()`
 *   （每帧一步，返回 true ⇒ 帧时钟不停，与 `Spinner` 同驱动）；画家只按值画。
 * ★ **`painter->version()` 有真实消费者**：HUD 每帧显示内容戳 —— "没人调的
 *   分支等价于未实现"，所以版本戳被这一行的 `Text` 真的读出来。
 */
#[Route(id: 'paint', title: '自绘 Paint', icon: IconName::Brush)]
final class CustomPaintPage extends ShowcasePage
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
