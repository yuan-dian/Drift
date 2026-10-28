<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Input;

use Drift\Dev\Smoke\App\TfApplication;
use Drift\Dev\Smoke\App\TfLabeledApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Rendering\Color;
use Drift\Rendering\RasterCanvas;
use Drift\Widget\TextField;
use Drift\Geometry\Scale;

/**
 * 坐标链与 IME 定位（§8.4）：paint 画在哪 ⇒ hitTest 就得命中哪。
 *
 * 判据（与 `tools/smoke.php`"坐标链一致性"+"IME 定位"两段一致）：
 *   ① 2026-10-17 病灶：padding 原先只在 `paint()` 期 `translate()` 施加，不进子盒
 *      `offsetX/offsetY()` ⇒ 命中区与绘制区错位整个 padding（画在 24、命中从 0 起）。
 *      修法 = padding 移到**布局期**施加。判据是"命中起点 == 墨迹起点"（两视图同解）；
 *   ② `Application::imeCaretRect()` 是框架侧唯一算坐标的一段 ⇒ 必须含容器 padding、
 *      沿父链累积 y；无焦点 ⇒ null（桥收 (-1,-1,0)）；
 *   ③ 回归（2026-10-19 真机 bug）：**带 label** 的字段 build() 返回
 *      `Column[Text, 字段盒]`，焦点元素的 `effectiveBox()` 是 Column 的盒 ——
 *      若 `caretRect()` 拿它判 instanceof `RenderTextField` 就会 null ⇒ 候选框贴屏幕左上角。
 *
 * ★ 需要 Application + 光栅取墨，故 Feature 层。
 */
final class CoordinateChainTest extends SmokeTestCase
{
    /** 墨迹 bbox（含端点）："不等于透明"即墨（与 smoke `deviceInkBounds` 同实现） */
    private function deviceInkBounds(RasterCanvas $canvas): ?array
    {
        $clear = Color::transparent();
        $minX = PHP_INT_MAX;
        $maxX = -1;
        $minY = PHP_INT_MAX;
        $maxY = -1;

        for ($y = 0; $y < $canvas->height(); $y++) {
            for ($x = 0; $x < $canvas->width(); $x++) {
                if (!$canvas->pixelAt($x, $y)->equals($clear)) {
                    $minX = min($minX, $x);
                    $maxX = max($maxX, $x);
                    $minY = min($minY, $y);
                    $maxY = max($maxY, $y);
                }
            }
        }

        return $maxX < 0 ? null : [$minX, $maxX, $minY, $maxY];
    }

    public function testHitAreaMatchesPaintedArea(): void
    {
        $coordApp = new TfApplication();
        $coordApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(300.0, 200.0)));
        $coordApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
        $coordBox = $coordApp->rootElement()?->effectiveBox();
        $coordCanvas = new RasterCanvas(300, 200, null, Scale::of(1.0));
        $coordBox?->paint($coordCanvas);
        [$cMinX, , $cMinY, ] = $this->deviceInkBounds($coordCanvas) ?? [-1, -1, -1, -1];

        // ★ 扫的是**字段盒自己**的命中起点 —— 不能扫"第一个非 null 命中"：根盒
        //   （RenderFlex）覆盖整片客户区，(0,0) 就命中它自己 ⇒ 判据恒真（空转）。
        $coordTfBox = findElement($coordApp->rootElement(), fn($el) => $el->widget() instanceof TextField)
            ?->effectiveBox();
        $coordHitX = null;
        $coordHitY = null;
        for ($cy = 0; $cy < 200 && $coordHitX === null; $cy++) {
            for ($cx = 0; $cx < 300; $cx++) {
                if ($coordBox?->hitTest((float) $cx, (float) $cy) === $coordTfBox) {
                    $coordHitX = $cx;
                    $coordHitY = $cy;
                    break;
                }
            }
        }
        check(
            '坐标链 · padding 容器内：hitTest 命中起点 == 墨迹起点（两视图同解）',
            $coordHitX === $cMinX && $coordHitY === $cMinY,
            sprintf('hit=(%s,%s) ink=(%s,%s)', $coordHitX, $coordHitY, $cMinX, $cMinY)
        );
        check(
            '坐标链 · 墨迹起点 == 容器 padding（24px 真的施加了）',
            $cMinX === 24 && $cMinY === 24,
            sprintf('ink=(%s,%s)', $cMinX, $cMinY)
        );

        $this->flushChecks();
    }

    public function testImeCaretRectAlongParentChain(): void
    {
        $imeApp = new TfApplication();
        $imeApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(300.0, 200.0)));
        check(
            'IME · 无焦点 ⇒ imeCaretRect() = null（＝无插入符，桥收 (-1,-1,0)）',
            $imeApp->imeCaretRect() === null,
            var_export($imeApp->imeCaretRect(), true)
        );

        $imeApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
        check(
            'IME · 焦点在第一个字段 ⇒ 窗口坐标含容器 padding（24+29, 24+4）',
            $imeApp->imeCaretRect() === [53.0, 28.0, 16.8],
            '(' . implode(', ', $imeApp->imeCaretRect() ?? []) . ')'
        );

        $imeApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
        check(
            'IME · 焦点在第二个字段 ⇒ y 沿父链累积（+32.8 = 字段高 + spacing）',
            $imeApp->imeCaretRect() === [52.0, 60.8, 16.8],
            '(' . implode(', ', $imeApp->imeCaretRect() ?? []) . ')'
        );

        $this->flushChecks();
    }

    public function testLabeledFieldStillGivesCaretRect(): void
    {
        // ★ 回归夹具（2026-10-19 真机 bug）：**带 label** 的字段必须同样给出光标矩形。
        $imeLabeledApp = new TfLabeledApplication();
        $imeLabeledApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(300.0, 200.0)));
        $imeLabeledApp->frame(16.0, BoxConstraints::loose(new Size(300.0, 200.0)));
        $imeLabeledApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
        $labeledCaret = $imeLabeledApp->imeCaretRect();
        check(
            'IME · 有 label 的字段也给出光标矩形（穿过 Column 包装找真实字段盒）',
            $labeledCaret !== null && $labeledCaret[0] > 0.0 && $labeledCaret[1] > 0.0,
            var_export($labeledCaret, true)
        );

        $this->flushChecks();
    }
}
