<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Errors;

/**
 * 画布能力位掩码（D2：op 分级进 `Canvas` 契约，2026-10-20 采纳）。
 *
 * **为什么是位掩码而不是 tier 常量**：CustomPaint 拿到画布时只需要一次
 * "这个 op 能不能画"的答案（构建期/首帧查一次，不在每像素、每次绘制时查）。
 * 位掩码让"按 op 断言"与"按层断言"（`CORE_MASK` / `EXTENDED_MASK` /
 * `OPTIONAL_MASK`）用同一份数据表达。
 *
 * **分层与位语义**（D2 表，跨平台确定性的契约形状）：
 *
 * | 层 | op | 所有后端 | RasterCanvas | SkiaCanvas |
 * |---|---|---|---|---|
 * | Core | ELLIPSE / LINE | **必须实现**（缺一个就是缺陷） | 必须（golden 用它） | 必须 |
 * | Extended | GRADIENT / DASH / CLIP_PATH / PATH | Skia 级必须 | **可降级近似**（渐变→分带、虚线→实线；路径硬边无抗锯齿且只做 Miter+Butt；路径裁剪 = 每行 x 区间掩码） | 必须 |
 * | Optional | IMAGE / SHADOW / LAYER | 仅 Skia 级 | **报 E-OP-UNSUPPORTED** | 必须 |
 *
 * **位置位 = "该后端能处理这个 op"**（Extended 的近似也算处理 —— 输出质量
 * 档位见各后端方法注释与 `BACKPORT-19OP.md` §5 的 R2 取舍；金色基线只用
 * Raster 的 Core 层，P3/P4 的 op 用结构 golden）。
 * **位清 0 = 画布遇到该 op 会响亮失败**（不是静默丢像素 —— 那正是
 * "矩形全对、内容没了"这类最难查的 bug 形状）。
 *
 * ★ `RECT` / `ROUND_RECT` / `TEXT` / `SAVE` / `CLIP_RECT` 等**不在位掩码里**：
 *   它们是 7-op 时代的原语，所有后端（含将来的平台后端）无条件支持，没有
 *   "能力问题"。位掩码只覆盖 19 op 回植新增的面。
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
    // ★ `CAP_PATH` 在 W1/L3-1 从 Optional 提到 Extended：Raster 补齐了扫描线
    //   填充后，两边都有 ⇒ 按"能力等价"（`05` §2.2）它不再是可以缺的。
    //   提档的语义是"缺了它控件画不出来"，不是"实现质量相同" —— Raster 这一份
    //   是硬边、无抗锯齿、只做 Miter+Butt（`05` §3.3 / §3.4），差异写在 docblock。
    public const CAP_PATH = 1 << 5;

    // ── Optional：仅 Skia 级后端；Raster 响亮失败 ──
    public const CAP_IMAGE = 1 << 6;
    public const CAP_SHADOW = 1 << 7;
    public const CAP_LAYER = 1 << 8;

    /**
     * 全量位掩码。
     *
     * ★ **平铺成叶子常量的一次 `|`，不引用中间 mask**：tpc 对"常量链"
     *   （`ALL = CORE | EXT | OPT`，而 CORE 自己又是两个叶子的 `|`）会生成
     *   引用未声明临时量的坏代码（2026-10-20 bench 构建 C2065 实证，
     *   RecordingCanvas::capabilities 挂掉）。中间 mask 保留供人查阅与
     *   `tierOf()` 用，但**不进任何编译进产物的表达式**。
     */
    public const ALL_MASK = self::CAP_ELLIPSE | self::CAP_LINE
        | self::CAP_GRADIENT | self::CAP_DASH | self::CAP_CLIP_PATH
        | self::CAP_PATH | self::CAP_IMAGE | self::CAP_SHADOW | self::CAP_LAYER;

    /**
     * 后端遇到**自己没实现**的 op 时的失败消息（E-OP-UNSUPPORTED）。
     *
     * 措辞纪律：现象 + op 名 + 两条修法；不写内部编号、不写术语。
     *
     * @param string $op      方法名，如 `drawPath`
     * @param string $backend 后端名，如 `RasterCanvas`
     * @param string $tier    `Core` / `Extended` / `Optional`
     */
    /**
     * 线段的 Fill 风格检查（两个后端同一口径）。
     *
     * 线段没有内外圈概念 ⇒ Fill 语义不存在；静默按 1px 画正是最难查的错。
     */
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
