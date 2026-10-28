<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\Dev\Smoke\App\TfApplication;
use Drift\Dev\Smoke\App\ZeroViewportApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\LayoutTolerance;
use Drift\Widget\AppBar;
use Drift\Widget\NavButton;

/**
 * 窗口尺寸变化下的三条路径：拖动 resize / 零视口（最小化）/ 窄窗口。
 *
 * ★ 这三条都是"用户拖窗口时才会出现"的瞬时态，共同判据是**不许弹 Fatal**：
 *   - 拖动 resize 走 `repaintForResize()`：只重排 + 重绘，不碰任务/动画/语义，
 *     且**不自己读时钟**（复用上一帧时间戳），因为平台壳在模态尺寸循环内同步调回它。
 *   - 最小化给 0×0：不构成"可布局的一帧" ⇒ 跳过，且**根盒保持旧尺寸**（这才是
 *     "还原后能正常重排"的前提）。
 *   - 窗口比顶条窄：裁剪（右侧导航项暂时不可见），不是编程错误。
 */
final class WindowFrameTest extends SmokeTestCase
{
    public function testRepaintForResizeLaysOutWithNewConstraints(): void
    {
        $resizeApp = new TfApplication();
        $resizeSurface = new SurfaceProbe();
        $resizeApp->attach($resizeSurface, BoxConstraints::loose(new Size(300.0, 200.0)));
        $resizeApp->frame(16.0, BoxConstraints::loose(new Size(300.0, 200.0)));
        $resizeFramesBefore = $resizeSurface->frames;
        $resizeTsBefore = $resizeSurface->lastTimestamp;
        $resizeApp->repaintForResize(BoxConstraints::tight(new Size(420.0, 260.0)));
        check(
            'Resize · repaintForResize 按新约束重排（根盒宽 = 420，不是旧的 300）',
            ($resizeApp->root()?->effectiveBox()?->size()?->width ?? -1.0) === 420.0,
            'rootW=' . ($resizeApp->root()?->effectiveBox()?->size()?->width ?? -1.0)
        );
        check(
            'Resize · repaintForResize 只跑一帧、时间戳复用上一帧（不自己读时钟）',
            $resizeSurface->frames === $resizeFramesBefore + 1
                && $resizeSurface->ends === $resizeFramesBefore + 1
                && $resizeSurface->lastTimestamp === $resizeTsBefore,
            'frames=' . $resizeSurface->frames . ' ends=' . $resizeSurface->ends
                . ' ts=' . var_export($resizeSurface->lastTimestamp, true)
        );

        $this->flushChecks();
    }

    public function testZeroViewportIsNotALayoutableFrame(): void
    {
        // 用户实测（2026-10-25）：最小化 ⇒ `GetClientRect` 给 0×0 ⇒ `tight(0,0)` 拿去
        // 布局 ⇒ `RenderText 实际 0.0 × 86.4，最多只能用 0.0 × 0.0` 当场 C6 Fatal。
        // ★ 判据不是"没崩"，而是三件事：帧被**计数跳过**、表面**一帧都没起**、根盒
        //   **保持旧尺寸**（不被 0 覆盖）—— 后者才是"还原后能正常重排"的前提。
        $zeroApp = new ZeroViewportApplication();
        $zeroSurface = new SurfaceProbe();
        $zeroApp->attach($zeroSurface, BoxConstraints::tight(new Size(320.0, 240.0)));
        $zeroApp->frame(16.0, BoxConstraints::tight(new Size(320.0, 240.0)));
        $zeroRootW = $zeroApp->root()?->effectiveBox()?->size()?->width ?? -1.0;
        $zeroFramesBefore = $zeroSurface->frames;
        $zeroApp->frame(32.0, BoxConstraints::tight(new Size(0.0, 0.0)));
        check(
            '零视口 · frame(tight(0,0)) 跳过布局与绘制（不 C6，表面不起帧，根盒保持 320）',
            $zeroRootW === 320.0
                && $zeroApp->zeroViewportFrames() === 1
                && $zeroSurface->frames === $zeroFramesBefore
                && ($zeroApp->root()?->effectiveBox()?->size()?->width ?? -1.0) === $zeroRootW,
            'frames=' . $zeroSurface->frames . '/' . $zeroFramesBefore
                . ' zero=' . $zeroApp->zeroViewportFrames()
                . ' rootW=' . ($zeroApp->root()?->effectiveBox()?->size()?->width ?? -1.0)
        );
        $zeroApp->repaintForResize(BoxConstraints::tight(new Size(0.0, 0.0)));
        check(
            '零视口 · repaintForResize(tight(0,0)) 与 frame() 同一条判据（表面仍不起帧）',
            $zeroSurface->frames === $zeroFramesBefore,
            'frames=' . $zeroSurface->frames,
        );
        $zeroApp->frame(48.0, BoxConstraints::tight(new Size(200.0, 120.0)));
        check(
            '零视口 · 还原后按新尺寸重排（早退不留死状态）',
            ($zeroApp->root()?->effectiveBox()?->size()?->width ?? -1.0) === 200.0,
            'rootW=' . ($zeroApp->root()?->effectiveBox()?->size()?->width ?? -1.0),
        );

        $this->flushChecks();
    }

    public function testAppBarClipsInsteadOfFatalWhenWindowNarrow(): void
    {
        // 实测回归：窗口拖到可用宽 322、顶条自然宽 327 ⇒ 原 `Overflow::Report` 直接
        // 弹 "RenderFlex 溢出" Fatal，拖动缩窗被中断。顶条放不下是**用户行为**，
        // 正确语义是裁剪（右侧导航项暂时不可见）。
        Errors::reset();
        $barEl = AppBar::make()->title('用户管理')->children([
            NavButton::make('列表'),
            NavButton::make('注册'),
            NavButton::make('设置'),
        ])->createElement();
        $barEl->mount(null, smokeContext());
        $barEl->settle();
        // ★ 约束必须**窄于顶条自然宽**（实测自然宽 ≈ 327：标题 + 3 个导航 + 菜单）。
        //   320 已接近，取 280 保证稳定溢出 —— 否则夹具对回归不敏感（反例仍绿）。
        $barEl->effectiveBox()?->layout(BoxConstraints::tight(new Size(100.0, 220.0)));
        $barDiag = Errors::recentDiagnostics();
        check(
            'AppBar · 可用宽 100 < 自然宽 ⇒ 裁剪（无溢出 Fatal）',
            $barDiag === []
                && ($barEl->effectiveBox()?->size()?->width ?? -1.0) === 100.0,
            'diag=' . count($barDiag) . ' rootW=' . ($barEl->effectiveBox()?->size()?->width ?? -1.0)
        );

        $this->flushChecks();
    }

    public function testPreviewingDowngradesHardChecksToClipping(): void
    {
        // 用户拖窗口边缘时中间尺寸是**瞬时态**——「窗口比内容小」是合法状态，不是
        // 编程错误。`LayoutTolerance::$previewing` 期间 C5/C6 的硬校验一律降级为裁剪
        // （RenderFlex / RenderDock / RenderBox 三处都读它）；松手后复位，真溢出照旧报。
        Errors::reset();
        LayoutTolerance::$previewing = true;

        try {
            $tolApp = new TfApplication();
            $tolApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(300.0, 200.0)));
            $tolApp->frame(16.0, BoxConstraints::loose(new Size(300.0, 200.0)));
            $tolApp->repaintForResize(BoxConstraints::tight(new Size(80.0, 50.0)));   // 远小于内容
            $tolDiag = Errors::recentDiagnostics();
        } finally {
            LayoutTolerance::$previewing = false;
        }

        check(
            'Resize · 预览期极小尺寸不弹 Fatal（C5/C6 降级为裁剪）',
            $tolDiag === [],
            'diag=' . count($tolDiag) . ' ' . implode(' | ', array_slice($tolDiag, 0, 2))
        );

        $this->flushChecks();
    }
}
