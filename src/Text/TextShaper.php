<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 塑形：run（文本 + 样式 + 家族）→ glyph 序列（§8.1 第三层）。
 *
 * ★ **设备分辨率塑形**（§6.1 / §8.2 坑 2）：`$scale` 是 dpr，塑形在设备像素上
 *   做（hinting / 亚像素定位才有意义），但**返回的 advance 已经换算回逻辑单位** ——
 *   布局全程只说逻辑，取整只发生在表面量化那一个点。
 *
 * ★ 一段文本会被切成**多个 run**（拉丁 → CJK → emoji 的回退链），每 run 一个
 *   face。调用方拿到的是 run 列表，不是一个"整体字形数组"。
 *
 * @api extension
 */
interface TextShaper
{
    /**
     * @return list<GlyphRun>
     */
    public function shape(string $text, TextStyle $style, FontCollection $collection, float $scale): array;
}
