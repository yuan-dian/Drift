<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use App\Showcase\Pages\Base\StatefulShowcasePage;

use App\Showcase\ShowcaseCapabilities;
use Drift\Widget\CustomPaint;
use Drift\Widget\State;
use Drift\Widget\Text;
use App\Showcase\Pages\States\CustomPaintPageState;

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
final class CustomPaintPage extends StatefulShowcasePage
{
    public static function make(ShowcaseCapabilities $caps): self
    {
        return new self($caps);
    }

    public function createState(): State
    {
        return new CustomPaintPageState($this->caps);
    }
}

/**
 * 仪表盘画家：值 0..1 决定指针角度；`version()` = 值变了几次（内容戳）。
 *
 * ★ **方向表是字面量不是 `cos()/sin()`**：§14.1 纯函数 —— libm 的跨平台末位
 *   差异会让画面在多平台间不可比（`RenderSpinner` 同族先例），11 个方向写死。
 */
