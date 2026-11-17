<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Geometry\Rect;

/**
 * 盒的 chrome（底 → 边框 → 圆角）单点配方。
 *
 * `Modifier::paintBehind()` 与 5 个自绘 chrome 的渲染盒共用这一处。
 * 阴影**不在内**：Button 的 hover 阴影是动态的、PopupPanel 有 fallback，
 * 语义不同，仍由 `ShadowPainter` 各自处理。
 *
 * @internal
 */
final class BoxChrome
{
    /** `$radius === null` ⇒ 取 `$style->radius`（PopupPanel 的盒与面板矩形不同）。 */
    public static function paint(Canvas $canvas, Rect $rect, BoxStyle $style, ?float $radius = null): void
    {
        $r = $radius ?? $style->radius;

        $background = $style->backgroundPaint($rect->width, $rect->height);
        if ($background !== null) {
            $canvas->drawRect($rect, $background, $r);
        }

        if ($style->borderColor !== null && $style->borderWidth > 0.0) {
            $stroke = Paint::stroke($style->borderColor, $style->borderWidth);
            if ($style->borderDash !== null) {
                $stroke = $stroke->withDash($style->borderDash);
            }
            $canvas->drawRect($rect, $stroke, $r);
        }
    }
}
