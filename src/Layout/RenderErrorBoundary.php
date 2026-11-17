<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Point;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;
use Throwable;

/**
 * 错误边界的渲染盒（O-12 A′ 的第 ③ 层：渲染期兜底）。
 *
 * ★ **布局对父透明**（纯代理）：约束原样下传，尺寸取孩子的 —— 边界不该改变
 *   健康时的几何。只有异常时才退到"授予区内一块自然尺寸的占位地"。
 *
 * ★ **兜底绘制不含文本**：`RenderBox` 拿不到 `TextMetricsProvider`（§14.2 的
 *   注入点在 `BuildContext`，只到 Widget 层）⇒ 画一个醒目叉框底。完整文案占位
 *   由 `ErrorPlaceholder`（Element 层换子树）负责 —— 两条路互补，不重复。
 *
 * ★ **`paintBroken` 不自动复位**：渲染期异常意味着这棵子树的结构已经不可信，
 *   本盒从此只画兜底标记。要恢复只能重新挂载（应用级决定，不是框架偷偷重试）。
 *
 * ★ **必须留痕**（2026-11-11 补）：Element 层的 `ErrorBoundaryElement` 只包住
 *   `rebuild` / `settlePass`，**包不住 layout / paint** —— 那两段抛出的异常此前
 *   被这里吞掉且零留痕，症状就是"某个方块变成红叉，日志什么都没有"。红叉能看见
 *   不代表能排查：没有异常文本与行号，等于把定位成本推给下一个人。
 */
final class RenderErrorBoundary extends RenderProxyBox
{
    private bool $paintBroken = false;

    /** 同一个坏盒每帧都会再抛一次 —— 只留第一条痕迹 */
    private bool $reported = false;

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $child = $this->child;
        if ($child === null) {
            return $constraints->constrain(new Size(0.0, 0.0));
        }

        try {
            $child->layout($constraints);

            return $constraints->constrain($child->size());
        } catch (Throwable $e) {
            $this->markBroken($e, '布局');

            // 兜底地：自然尺寸（不动用约束上限 —— INF 约束会画出无限大的盒）
            return $constraints->constrain(new Size(160.0, 48.0));
        }
    }

    #[\Override]
    protected function paintContent(Canvas $canvas): void
    {
        if ($this->paintBroken) {
            $this->paintBrokenMark($canvas);

            return;
        }

        try {
            parent::paintContent($canvas);
        } catch (Throwable $e) {
            $this->markBroken($e, '绘制');
            $this->paintBrokenMark($canvas);
        }
    }

    /** 置坏并**留一次痕**（G2：兜底不等于静默） */
    private function markBroken(Throwable $e, string $phase): void
    {
        $this->paintBroken = true;
        if ($this->reported) {
            return;
        }
        $this->reported = true;

        Errors::report(sprintf(
            '错误边界吞下%s期异常：%s —— 该子树只画兜底标记，重新挂载才能恢复',
            $phase,
            Errors::describe($e),
        ), Errors::DIAGNOSTIC);
    }

    private function paintBrokenMark(Canvas $canvas): void
    {
        $size = $this->size;
        if ($size === null) {
            return; // 没排过 ⇒ 无墨
        }

        $rect = Rect::fromOrigin(new Point(0.0, 0.0), $size);

        // 醒目的红底叉框：不用主题 token（主题本身可能就是崩掉的那一环）
        $canvas->drawRect($rect, Paint::fill(Color::fromArgb(0xFFFFF0F0)));
        $canvas->drawRect($rect, Paint::stroke(Color::fromArgb(0xFFD32F2F), 2.0));

        $midX = $size->width / 2.0;
        $midY = $size->height / 2.0;
        $arm = min($midX, $midY) * 0.5;
        $canvas->drawRect(
            Rect::fromLtrb($midX - $arm, $midY - $arm, $midX + $arm, $midY + $arm),
            Paint::stroke(Color::fromArgb(0xFFD32F2F), 2.0),
        );
    }
}
