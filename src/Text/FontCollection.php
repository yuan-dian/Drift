<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 逻辑家族 → 回退链（§8.1 第二层）。
 *
 * ★ 回退链是"拉丁 → CJK → emoji"这种**能力顺序**，不是"候选名单"：
 *   塑形器沿链找**第一个含该字形**的 face（`hasGlyph`），找不到才退到
 *   兜底 face —— 所以"某个字不显示"这类问题在结构上有一个确定答案。
 *
 * @api extension
 */
interface FontCollection
{
    /**
     * 按家族 + 字重 + 倾斜挑一条回退链（最匹配的在前）。
     *
     * @return list<FontFace>
     */
    public function resolve(FontFamily $family, FontWeight $weight, FontSlant $slant): array;

    /** 链上第一个含该字形的 face；都不含 ⇒ 返回兜底 face */
    public function faceFor(int $codepoint, FontFamily $family, FontWeight $weight, FontSlant $slant): FontFace;
}
