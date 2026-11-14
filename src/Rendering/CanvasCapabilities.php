<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Errors;

/**
 * 画布能力位掩码：每个 `CAP_*` 表示"该后端能处理这个 op"，位清 0 ⇒ 响亮失败而非静默丢像素。
 *
 * 分层与各后端支持情况以 `RasterCanvas::supportedCapabilities()` /
 * `SkiaCanvas::capabilities()` 的返回值为准；本类只定义位，不重复列矩阵。
 * `RECT` / `TEXT` / `SAVE` / `CLIP_RECT` 等原语无条件支持，不在掩码里。
 *
 * @api
 */
final class CanvasCapabilities
{
    // ── Core：所有后端必须实现，缺一个即缺陷 ──
    public const CAP_ELLIPSE = 1 << 0;
    public const CAP_LINE = 1 << 1;

    // ── Extended：Skia 级必须；Raster 允许降级近似 ──
    public const CAP_GRADIENT = 1 << 2;
    public const CAP_DASH = 1 << 3;
    public const CAP_CLIP_PATH = 1 << 4;
    // Extended：Raster 补齐扫描线填充后，两边都有，缺了它控件画不出来。
    // Raster 是硬边、无抗锯齿、只做 Miter+Butt，与 Skia 的质量差写在各方法 docblock。
    public const CAP_PATH = 1 << 5;
    // Extended：Raster 的 alpha 乘数栈能近似层语义。与真离屏的差异是"层内重叠子更透"。
    public const CAP_LAYER = 1 << 6;

    /** 模糊背景：Raster 近似为不模糊。缺了它结构仍对，只少一层糊 ⇒ 算 Extended。 */
    public const CAP_BACKDROP = 1 << 9;

    // ── 其余位 ──
    // 两个后端都支持：Skia 走原生 codec，Raster 走内存 RGBA 最近邻 blit（确定性）。
    public const CAP_IMAGE = 1 << 7;
    // 仅 Skia 支持：Raster 遇到 drawShadow 响亮失败（E-OP-UNSUPPORTED）。
    public const CAP_SHADOW = 1 << 8;

    /**
     * 全量位掩码。
     *
     * ★ 必须平铺成叶子常量的一次 `|`，不能引用中间 mask：tpc 对"常量链"会生成
     *   引用未声明临时量的坏代码（C2065）。中间 mask 不进任何编译进产物的表达式。
     */
    public const ALL_MASK = self::CAP_ELLIPSE | self::CAP_LINE
        | self::CAP_GRADIENT | self::CAP_DASH | self::CAP_CLIP_PATH
        | self::CAP_PATH | self::CAP_IMAGE | self::CAP_SHADOW | self::CAP_LAYER
        | self::CAP_BACKDROP;

    /** 线段的 Fill 风格检查（两个后端同一口径）：Fill 语义不存在，静默按 1px 画最难查。 */
    public static function assertStrokePaint(Paint $paint, string $op): void
    {
        if (!$paint->isStroke()) {
            Errors::report(sprintf(
                '「%s 只支持描边」—— 收到的是填充风格的画笔。两条修法：'
                . '① 改用 Paint::stroke(颜色, 宽度)；② 要实心形状就用 drawRect 或 Path。',
                $op,
            ));
        }
    }

    /** 后端遇到未实现 op 时的失败消息（E-OP-UNSUPPORTED）：现象 + op 名 + 两条修法。 */
    public static function unsupportedMessage(string $op, string $backend, string $tier): string
    {
        return sprintf(
            '「%s 不支持 %s」—— 绘制时发起了这个操作（%s 层），但当前后端没有实现它。'
            . '两条修法：① 换用实现了该层的后端（如 Skia 后端）；'
            . '② 改用已支持的操作，发起前用画布的 capabilities() 查一次能力位。',
            $backend,
            $op,
            $tier,
        );
    }
}
