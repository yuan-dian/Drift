<?php
declare(strict_types=1);

namespace Drift\Text;

use Drift\Geometry\Scale;

/**
 * ★ **布局确定性的注入点**（§8.1 最后一层 / §14.2）。
 *
 * Widget 层问度量、不问平台 —— 于是测试可以喂固定度量，golden 可以复现。
 * "一套测量、两个后端"就落在这里：`SkParagraph` 真测量 / 确定性参考实现，
 * 两者由 conformance 套件互校。
 *
 * ★ 缓存归**实现内部**，键 = `(text, style, maxWidth, scale)`（§7.1）。
 *   接口上不暴露缓存，因为它是实现细节，不是契约。
 *
 * @api extension
 */
interface TextMetricsProvider
{
    /**
     * 排版（断行 + 对齐 + 行高）。
     *
     * @param float $maxWidth 逻辑单位；`INF` = 不限宽（不折行）
     */
    public function layout(string $text, TextStyle $style, float $maxWidth, Scale $scale): Paragraph;

    /**
     * 富文本排版（W8-5）：span 列表 → 段落。
     *
     * ★ 返回的 `Paragraph` 里 `cluster` 是**全文**字节偏移 ⇒ `indexAt()` 仍是
     *   全文偏移（光标 / 选中不用二次换算）。
     *
     * @param list<TextSpan> $spans
     * @param float $maxWidth 逻辑单位；`INF` = 不限宽（不折行）
     */
    public function layoutSpans(array $spans, TextStyle $style, float $maxWidth, Scale $scale): Paragraph;

    /** 单行宽度（逻辑单位）—— 不折行时的便捷入口 */
    public function advance(string $text, TextStyle $style, Scale $scale): float;
}
