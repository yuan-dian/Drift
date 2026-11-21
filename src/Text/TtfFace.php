<?php
declare(strict_types=1);

namespace Drift\Text;

use Drift\Errors;

/**
 * 真字体（TTF/OTF）的**度量面**：cmap 覆盖判定 + hmtx 步进 + typo 行度量。
 *
 * ```php
 * $face = TtfFace::fromFile('fonts/NotoSansSC-Regular.ttf', $bitmapFace);
 * ```
 *
 * ## 为什么需要它（§8.2 坑 6 的原话是「**一套**测量实现，两个后端」）
 *
 * 内置位图字模只覆盖**生成器扫到的那批字**（源码字面量口径）。表外字符退
 * `NotdefFontFace`，宽度按"宽/窄"猜 1.0 / 0.5 em —— 而真机（Skia 后端）的字形
 * 来自真 TTF、**全字集**。于是同一个字：画得出，宽度是猜的 ⇒ 长句错位、换行点
 * 算错、居中块偏。本类把"宽度"接回真字体，**漏字不再是宽度错**。
 *
 * ## 只做度量，不做光栅
 *
 * `rasterize()` **委托**给构造时给的字形源（`soft` 后端用 `BitmapFontFace`）；
 * 没给 ⇒ `null`（不静默画方框，与 `NotdefFontFace` 同一条纪律）。Skia 后端
 * 根本不走这条路 —— 它按 `codepoint` 直接画，不经过 `FontFace::rasterize()`。
 *
 * ## 两张表的取舍：行度量用 `sTypo`，不用 `hhea`
 *
 * Noto Sans SC 的 `hhea` 是 `ascent 1160 / descent 288`（**1.448 em**，给 CJK
 * 字形顶部留白），`OS/2` 的 `sTypo` 是 `880 / -120`（**1.0 em**）。
 * 用 hhea ⇒ 所有行高凭空 +45%，且与既有位图面（0.875 / 0.125）差一档；
 * 用 sTypo ⇒ 与位图面几乎重合，布局不突变。规范也把 `sTypo*` 定为"排版用"
 * （`fsSelection` bit 7 `USE_TYPO_METRICS` 就是为它存在的）。
 *
 * ## 诚实边界
 *
 * - **不做 kerning / ligature**（`GPOS` 不解析）⇒ 拉丁成对字母略宽；中文无 kern
 * - **不做 bidi / 断行禁则**（要 ICU）⇒ 现有 greedy 断行不变
 * - **不做字体回退**（`cmap` 没有就是没有：emoji / 扩展 B 仍缺）⇒ 回退链是下一步
 * - 字体文件**整份读进内存**（Noto Sans SC 约 11 MB）：桌面应用可接受，换来
 *   随机访问零 syscall；不解析 `glyf`/`loca`（那是光栅的事）
 *
 * @api
 */
final class TtfFace implements FontFace
{
    /** @var array<string, int> 表 tag → 文件内偏移（只留用到的 5 张） */
    private array $tables = [];

    private int $upem = 1000;

    private FontMetrics $metrics;

    private int $cmapFormat = 0;

    private int $cmapOffset = 0;

    private int $numHMetrics = 0;

    /**
     * ★ **不是 `readonly`**：字重 / 倾斜来自 `OS/2` 与 `head`，只能在 `parse()`
     *   里读出来 —— 而 `readonly` 只允许在构造器里写一次。字重错标的代价是
     *   回退链选错 face（`distanceTo()` 排序），所以宁可让它们可变也要"从文件读"。
     */
    private FontWeight $weight = FontWeight::Regular;

    private FontSlant $slant = FontSlant::Normal;

    /** @var list<FontFace> */
    private readonly array $glyphs;

    /**
     * @param list<FontFace> $glyphs 字形源（按序问，第一个 `hasGlyph()` 为真的画）
     */
    private function __construct(
        private readonly string $path,
        private readonly string $family,
        array $glyphs,
        private readonly string $data,
    ) {
        $this->glyphs = $glyphs;
    }

    /**
     * 从文件读一张度量面。
     *
     * @param string $path 字体文件路径
     * @param list<FontFace> $glyphs 字形源（`soft` 后端传位图面；`skia` 后端传空列表）。
     *   ★ 收**列表**而不是单个 face：位图有两个面（拉丁 95 字 / CJK 864 字），
     *   upem 不同不能合并成一张，只能"按序问第一个有该字形的"。
     * @param string $family 逻辑家族名（`sans` / `serif` / `mono`）—— **不读 `name` 表**：
     *   Drift 的家族是逻辑名（§8.2 坑 1），字体文件里的 "Noto Sans SC" 不是它
     *
     * @return self|null 读不到 / 不是可用的 TTF ⇒ `null`（**已记 Diagnostic**，
     *   调用方据此降级到确定性位图面 —— 降级是有意的，但不是静默的）
     *
     * @api
     */
    public static function fromFile(string $path, array $glyphs = [], string $family = 'sans'): ?self
    {
        if (!is_file($path)) {
            Errors::report('TtfFace：字体文件不存在 —— ' . $path, Errors::DIAGNOSTIC);

            return null;
        }

        $data = file_get_contents($path);
        if ($data === false || strlen($data) < 12) {
            Errors::report('TtfFace：字体文件读不出来 —— ' . $path, Errors::DIAGNOSTIC);

            return null;
        }

        // 头部魔数：0x00010000 = TrueType、'OTTO' = CFF、'ttcf' = 集合（不支持）
        $magic = substr($data, 0, 4);
        if ($magic === 'ttcf') {
            Errors::report('TtfFace：字体集合（ttcf）不支持，请给单个 face —— ' . $path, Errors::DIAGNOSTIC);

            return null;
        }
        if ($magic !== "\x00\x01\x00\x00" && $magic !== 'OTTO' && $magic !== 'true') {
            Errors::report('TtfFace：不是可识别的 TTF/OTF（magic=' . bin2hex($magic) . '）—— ' . $path, Errors::DIAGNOSTIC);

            return null;
        }

        $face = new self($path, $family, $glyphs, $data);

        return $face->parse() ? $face : null;
    }

    /** 字体文件路径（诊断 / golden 绑定用） */
    public function path(): string
    {
        return $this->path;
    }

    public function familyName(): string
    {
        return $this->family;
    }

    public function weight(): FontWeight
    {
        return $this->weight;
    }

    public function slant(): FontSlant
    {
        return $this->slant;
    }

    public function metrics(): FontMetrics
    {
        return $this->metrics;
    }

    public function unitsPerEm(): int
    {
        return $this->upem;
    }

    public function hasGlyph(int $codepoint): bool
    {
        return $this->glyphId($codepoint) !== 0;
    }

    public function glyphAdvance(int $codepoint): float
    {
        $gid = $this->glyphId($codepoint);

        if ($gid === 0) {
            // 回退链本应先问 hasGlyph；走到这里说明选 face 的逻辑错了 —— 不静默
            Errors::report(
                sprintf('%s（%s）未覆盖 U+%04X，步进按 0 计', $this->family, $this->path, $codepoint),
                Errors::DIAGNOSTIC,
            );

            return 0.0;
        }

        return $this->advanceUnits($gid) / (float) $this->upem;
    }

    public function rasterize(int $codepoint, float $pixelSize): ?GlyphBitmap
    {
        foreach ($this->glyphs as $face) {
            if ($face->hasGlyph($codepoint)) {
                return $face->rasterize($codepoint, $pixelSize);
            }
        }

        // 字形源没有这个字（`skia` 后端本来就不该走到这里）⇒ 不画，不画方框
        return null;
    }

    // ─────────────────────────────── 解析 ───────────────────────────────

    private function parse(): bool
    {
        $data = $this->data;
        $numTables = $this->u16(4);

        for ($i = 0; $i < $numTables; $i++) {
            $rec = 12 + $i * 16;
            $tag = substr($data, $rec, 4);
            if ($tag === 'head' || $tag === 'hhea' || $tag === 'hmtx' || $tag === 'cmap' || $tag === 'OS/2') {
                $this->tables[$tag] = $this->u32($rec + 8);
            }
        }

        foreach (['head', 'hhea', 'hmtx', 'cmap'] as $required) {
            if (!isset($this->tables[$required])) {
                Errors::report('TtfFace：缺表 ' . $required . ' —— ' . $this->path, Errors::DIAGNOSTIC);

                return false;
            }
        }

        $head = $this->tables['head'];
        $this->upem = $this->u16($head + 18);
        if ($this->upem <= 0) {
            Errors::report('TtfFace：unitsPerEm 非法（' . $this->upem . '）—— ' . $this->path, Errors::DIAGNOSTIC);

            return false;
        }

        $hhea = $this->tables['hhea'];
        $this->numHMetrics = $this->u16($hhea + 34);
        if ($this->numHMetrics <= 0) {
            Errors::report('TtfFace：numberOfHMetrics 非法 —— ' . $this->path, Errors::DIAGNOSTIC);

            return false;
        }

        // 行度量：优先 OS/2 的 sTypo*（见类头"两张表的取舍"）
        $ascent = $this->i16($hhea + 4);
        $descent = -$this->i16($hhea + 6);
        $lineGap = $this->i16($hhea + 8);

        if (isset($this->tables['OS/2'])) {
            $os2 = $this->tables['OS/2'];
            $typoAscent = $this->i16($os2 + 68);
            $typoDescent = -$this->i16($os2 + 70);
            if ($typoAscent > 0 && $typoDescent > 0) {
                $ascent = $typoAscent;
                $descent = $typoDescent;
                $lineGap = $this->i16($os2 + 72);
            }

            $weightClass = $this->u16($os2 + 4);
            if ($weightClass >= 100 && $weightClass <= 900) {
                $mapped = FontWeight::from($weightClass - ($weightClass % 100));
                $this->weight = $mapped;
            }
        }

        $this->metrics = FontMetrics::fromUnits($ascent, $descent, $lineGap, $this->upem);

        // 倾斜：head.macStyle bit 1
        if (($this->u16($head + 44) & 0x0002) !== 0) {
            $this->slant = FontSlant::Italic;
        }

        return $this->selectCmap();
    }

    /**
     * 选一张 cmap 子表：**优先 (3,10) format 12**（全 Unicode），退 (3,1) format 4
     * （BMP）。两者都不在 ⇒ 失败（不猜）。
     */
    private function selectCmap(): bool
    {
        $cmap = $this->tables['cmap'];
        $count = $this->u16($cmap + 2);

        $format12 = null;
        $format4 = null;

        for ($i = 0; $i < $count; $i++) {
            $rec = $cmap + 4 + $i * 8;
            $platform = $this->u16($rec);
            $encoding = $this->u16($rec + 2);
            $offset = $cmap + $this->u32($rec + 4);
            $format = $this->u16($offset);

            if ($platform !== 3) {
                continue;
            }
            if ($encoding === 10 && $format === 12 && $format12 === null) {
                $format12 = $offset;
            }
            if ($encoding === 1 && $format === 4 && $format4 === null) {
                $format4 = $offset;
            }
        }

        if ($format12 !== null) {
            $this->cmapFormat = 12;
            $this->cmapOffset = $format12;

            return true;
        }
        if ($format4 !== null) {
            $this->cmapFormat = 4;
            $this->cmapOffset = $format4;

            return true;
        }

        Errors::report('TtfFace：没有可用的 cmap 子表（要 (3,10)f12 或 (3,1)f4）—— ' . $this->path, Errors::DIAGNOSTIC);

        return false;
    }

    // ─────────────────────────────── 查表 ───────────────────────────────

    private function glyphId(int $codepoint): int
    {
        if ($codepoint < 0 || $codepoint > 0x10FFFF) {
            return 0;
        }

        return $this->cmapFormat === 12
            ? $this->glyphIdFormat12($codepoint)
            : $this->glyphIdFormat4($codepoint);
    }

    /** format 12：nGroups 个 (startChar, endChar, startGlyph) 区间，二分 */
    private function glyphIdFormat12(int $codepoint): int
    {
        $offset = $this->cmapOffset;
        $groups = $this->u32($offset + 12);

        $lo = 0;
        $hi = $groups - 1;

        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $group = $offset + 16 + $mid * 12;
            $start = $this->u32($group);
            $end = $this->u32($group + 4);

            if ($codepoint < $start) {
                $hi = $mid - 1;
            } elseif ($codepoint > $end) {
                $lo = $mid + 1;
            } else {
                return $this->u32($group + 8) + ($codepoint - $start);
            }
        }

        return 0;
    }

    /**
     * format 4：BMP 分段表。二分找"第一个 endCode >= cp"的段，再判 startCode 与
     * `idRangeOffset` 的间接跳转（spec 的经典结构，见 OpenType 5.2.2）。
     */
    private function glyphIdFormat4(int $codepoint): int
    {
        if ($codepoint > 0xFFFF) {
            return 0;
        }

        $offset = $this->cmapOffset;
        $segX2 = $this->u16($offset + 6);
        $segments = intdiv($segX2, 2);

        $endBase = $offset + 14;
        $startBase = $endBase + $segX2 + 2; // 跳过 endCode 后的 reservedPad
        $deltaBase = $startBase + $segX2;
        $rangeBase = $deltaBase + $segX2;

        $lo = 0;
        $hi = $segments - 1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($codepoint > $this->u16($endBase + $mid * 2)) {
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        if ($lo >= $segments) {
            return 0;
        }
        if ($codepoint < $this->u16($startBase + $lo * 2)) {
            return 0;
        }

        $delta = $this->u16($deltaBase + $lo * 2);
        $rangeOffset = $this->u16($rangeBase + $lo * 2);

        if ($rangeOffset === 0) {
            return ($codepoint + $delta) & 0xFFFF;
        }

        $address = $rangeBase + $lo * 2 + $rangeOffset + ($codepoint - $this->u16($startBase + $lo * 2)) * 2;
        if ($address + 1 >= strlen($this->data)) {
            return 0;
        }

        $gid = $this->u16($address);

        return $gid === 0 ? 0 : (($gid + $delta) & 0xFFFF);
    }

    /** hmtx：前 numberOfHMetrics 项各 4 字节（advance + lsb），之后只有 lsb ⇒ 沿用最后一项 */
    private function advanceUnits(int $glyphId): int
    {
        $index = $glyphId < $this->numHMetrics ? $glyphId : $this->numHMetrics - 1;

        return $this->u16($this->tables['hmtx'] + $index * 4);
    }

    private function u16(int $offset): int
    {
        return (ord($this->data[$offset]) << 8) | ord($this->data[$offset + 1]);
    }

    private function i16(int $offset): int
    {
        $value = $this->u16($offset);

        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    private function u32(int $offset): int
    {
        return (ord($this->data[$offset]) << 24)
            | (ord($this->data[$offset + 1]) << 16)
            | (ord($this->data[$offset + 2]) << 8)
            | ord($this->data[$offset + 3]);
    }
}
