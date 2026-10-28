<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Smoke\ErrorBoundary\BoomPage;
use Drift\Dev\Smoke\ErrorBoundary\BoomState;
use Drift\Dev\Smoke\ErrorBoundary\ConstructBoom;
use Drift\Dev\Smoke\ErrorBoundary\LayoutBoomBox;
use Drift\Dev\Smoke\ErrorBoundary\PaintBoomBox;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderErrorBoundary;
use Drift\Rendering\Color;
use Drift\Rendering\DisplayList;
use Drift\Rendering\RecordingCanvas;
use Drift\Widget\ErrorBoundary;
use Drift\Widget\ErrorBoundaryElement;
use Drift\Widget\ErrorPlaceholder;
use Drift\Widget\StatefulElement;
use Drift\Widget\Text;
use Throwable;

/**
 * 错误边界容器 + 确定性字体的取墨面（O-12 A′：三层兜底）。
 *
 * ## 三层各管一段，判据不许混
 *
 * ① **构造期**：`createRenderObject()` 抛 ⇒ `rebuild()` 的 try 捕获。
 * ② **build 期**：`State::build()` 抛 ⇒ `settle()` 的 try 捕获；且 `settle()` 从
 *    "单遍"变"多轮循环"后，**每一遍**都得在边界内 —— 否则第 2 遍起异常直冒帧循环，
 *    边界粒度从"到节点"退化成"到整帧"且不报错。
 * ③ **渲染期**：盒自己兜底，`layout`/`paint` 都不冒泡。
 *
 * 第 ④ 层（无边界 ⇒ 帧循环 Fatal 序列）在 `tools/smoke.php` 用子进程验，
 * 依赖入口脚本是 smoke。
 *
 * ## 为什么落 Feature 层
 *
 * 判据含光栅取墨（占位真的画了东西 / CJK 真的上屏），不是纯值对象。
 */
final class ErrorBoundaryTest extends SmokeTestCase
{
    public function testConstructionFailureIsCaught(): void
    {
        $context = smokeContext();

        // ① 构造期：createRenderObject 抛 ⇒ rebuild 的 try 捕获
        $ebCtor = ErrorBoundary::make(ConstructBoom::make())->createElement();
        $ebCtor->mount(null, $context);
        check(
            '错误边界 · 构造期异常被容器捕获（不冒泡，窗口其余部分继续）',
            $ebCtor instanceof ErrorBoundaryElement && $ebCtor->hasCaught(),
        );
        check(
            '错误边界 · 捕获后子树换成占位（ErrorPlaceholder）',
            $ebCtor->childAt(0)?->widget() instanceof ErrorPlaceholder,
        );
        check(
            '错误边界 · 占位带错误原文（G2：不吞不改写）',
            str_contains((string) $ebCtor->caughtMessage(), 'construct boom'),
        );
        $ebCtor->effectiveBox()?->layout(BoxConstraints::tight(new Size(240.0, 80.0)));
        check(
            '错误边界 · 占位可布局出墨（Center+Text 真画了东西）',
            inkCount(spinCanvas($ebCtor->effectiveBox(), 240, 80), Color::white()) > 0,
        );

        $this->flushChecks();
    }

    /**
     * 字体边界：确定性测试字体覆盖示例用字 ⇒ 中文有墨。
     *
     * ★ 这条曾长期是**空断言**：探针盒从未 `layout()` ⇒ `lineCount() === 0` ⇒ 恒 0 墨，
     *   补字前也是 0 ⇒ 它一直通过却什么都没测到。`layout()` 那句是量具的自检前提。
     */
    public function testDeterministicFontCoversSampleText(): void
    {
        $context = smokeContext();

        $cjkProbe = Text::make('出错')->createElement();
        $cjkProbe->mount(null, $context);
        $cjkBox = $cjkProbe->effectiveBox();
        $cjkBox?->layout(BoxConstraints::tight(new Size(240.0, 80.0)));
        $cjkInk = inkCount(spinCanvas($cjkBox, 240, 80), Color::white());
        check(
            '字体边界 · 确定性测试字体覆盖示例用字 ⇒ 中文有墨',
            $cjkInk > 0,
            (string) $cjkInk,
        );

        $this->flushChecks();
    }

    public function testBuildFailureIsCaught(): void
    {
        $context = smokeContext();

        // ② build 期：State::build 抛 ⇒ settle 的 try 捕获
        $ebBuild = ErrorBoundary::make(BoomPage::make())->createElement();
        $ebBuild->mount(null, $context);
        check(
            '错误边界 · 健康时完全透明（孩子就是原页面）',
            $ebBuild->childAt(0)?->widget() instanceof BoomPage && !$ebBuild->hasCaught(),
        );
        $boomState = $ebBuild->childAt(0) instanceof StatefulElement ? $ebBuild->childAt(0)->state() : null;
        if ($boomState instanceof BoomState) {
            $boomState->boom(); // 标脏 ⇒ 帧末重建 ⇒ 第二次 build 抛
        }
        $ebBuild->settle();
        check(
            '错误边界 · build 期异常被 settle() 捕获（帧末重建路径）',
            $ebBuild->hasCaught() && str_contains((string) $ebBuild->caughtMessage(), 'build boom'),
        );
        check(
            '错误边界 · build 期捕获后也换占位',
            $ebBuild->childAt(0)?->widget() instanceof ErrorPlaceholder,
        );
        // settle 变成多轮循环后，这条才真的有意义：异常不许从第 2 遍起绕过边界
        check(
            '错误边界 · 多轮收敛下仍到节点（异常没冒到帧循环）',
            !str_contains(implode('|', Errors::recentDiagnostics()), '帧循环：未捕获异常'),
            '环形缓冲里出现了帧循环级未捕获异常',
        );

        $this->flushChecks();
    }

    /**
     * ③ 渲染期：盒自己兜底。
     *
     * ★ loose 下才看得到"自然尺寸"：tight 的 constrain 会把下界提到上限，
     *   fallback 的 160×48 会被撑成 200×200 —— 那是约束语义不是兜底失效。
     *   两条必须成对存在，单独一条都读不出"兜底到底有没有生效"。
     */
    public function testRenderFailureFallsBack(): void
    {
        $rebLayout = new RenderErrorBoundary();
        $rebLayout->setChild(new LayoutBoomBox());
        $rebLayout->layout(BoxConstraints::loose(new Size(200.0, 200.0)));
        check(
            '错误边界 · 渲染期 layout 异常 ⇒ 兜底自然尺寸（160×48，不放大到 INF）',
            $rebLayout->size()?->width === 160.0 && $rebLayout->size()?->height === 48.0,
            sprintf('实际 %.6g × %.6g', $rebLayout->size()?->width ?? -1, $rebLayout->size()?->height ?? -1),
        );

        $rebTight = new RenderErrorBoundary();
        $rebTight->setChild(new LayoutBoomBox());
        $rebTight->layout(BoxConstraints::tight(new Size(200.0, 200.0)));
        check(
            '错误边界 · tight 约束下兜底地被提到上限（constrain 语义，非失效）',
            $rebTight->size()?->width === 200.0,
        );

        $rebPaint = new RenderErrorBoundary();
        $rebPaint->setChild(new PaintBoomBox());
        $rebPaint->layout(BoxConstraints::tight(new Size(100.0, 60.0)));
        $paintThrew = false;
        try {
            $rebPaint->paint(new RecordingCanvas(new DisplayList()));
        } catch (Throwable) {
            $paintThrew = true;
        }
        check(
            '错误边界 · 渲染期 paint 异常 ⇒ 盒兜底（paint 不冒泡）',
            !$paintThrew,
        );
        check(
            '错误边界 · paint 兜底有墨（红底叉框真的画了）',
            inkCount(spinCanvas($rebPaint, 100, 60)) > 0,
        );

        $this->flushChecks();
    }

    /**
     * **没有** `ErrorBoundary` 时，帧末重建抛的异常必须走 Fatal 序列。
     *
     * ★ 这是"三层兜底"的另一半：上一批验的是"有边界 ⇒ 兜住"，这里验"没边界 ⇒
     *   不许吞"。吞了的后果是窗口冻结在上一帧且不报错（G2 明令禁止的半静默）——
     *   界面上只看得出"点了没反应"。
     *
     * @see tests/Support/fatal_cases.php `noboundary`
     */
    public function testUncaughtFrameExceptionFailsLoudly(): void
    {
        $log = \sys_get_temp_dir() . '/drift-noboundary-' . \getmypid() . '.log';
        @\unlink($log);

        [$code, $out] = runFatalCase('noboundary', $log);
        $text = \is_file($log) ? (string) \file_get_contents($log) : '';
        @\unlink($log);

        check(
            '错误边界 · 无边界时帧末异常 ⇒ 退出码 3（不吞）',
            $code === 3,
            'code=' . $code . ' out=' . \trim($out)
        );
        check(
            '错误边界 · 无边界时写了日志（不是只弹个框）',
            $text !== '' && \str_contains($text, 'FATAL'),
            $text === '' ? '日志为空' : \mb_substr($text, 0, 140)
        );

        $this->flushChecks();
    }
}
