<?php
declare(strict_types=1);

namespace Drift\Text;

use Drift\Errors;

/**
 * 点阵字模的 `FontFace` 实现（确定性参考实现的一部分）。
 *
 * ★ **位图即 em 盒**：每个字形的行数 == `unitsPerEm`，行数内的墨迹位置就是它相对
 *   基线的位置（基线在自上而下第 `ascent` 行处）。于是 `rasterize()` 不需要任何
 *   bearing 推导 —— `bearingY = -ascent × pixelSize`，纵向定位一处算清。
 *
 * ★ **步进 = 位图宽 / unitsPerEm**，单位是 em 的倍数（§8.2 坑 3 的归一化）。
 *   宽度不一的字模（如 `…`）自然得到不同步进，不需要单独的 advance 表。
 *
 * ★★ **2026-10-17 去掉了原来的 `+ 1` 间隙**（§24.2 #20）。那个间隙让**每个字多占
 *   1/unitsPerEm**：中文 `17/16 = 1.0625em`、拉丁 `6/8 = 0.75em`。而真字体
 *   （Noto Sans SC，实测 @1000px）中文正好 `1.0em`、拉丁平均 `0.55em`
 *   （`a` 0.563 / `i` 0.275 / `m` 0.926 / **空格 0.224**）⇒ 满屏字距偏松，
 *   `cross: Start` 那种"冒号后一个空洞"最刺眼。
 *   去掉后 advance **恰等于字身宽** ⇒ 位图首尾相接，既不重叠也不留缝。
 *   ★ 配套的数据改动：空格字模从 5 列收到 **2 列**（`0.25em` ≈ 真值 0.224）。
 *     空格是"松"的最大单源（旧值是真值的 3.3 倍），而它没有墨 ⇒ 收窄零风险。
 *
 * ★ **降采样产出灰度覆盖度**（`pixelSize < unitsPerEm` 时）：每个目标像素输出
 *   0..255 的面积覆盖率，**不是** 0/255 二值 —— 绘制侧 `RasterCanvas::blend()`
 *   按 alpha 混合 ⇒ 小字有抗锯齿边缘（§24.2 #17，2026-10-17）。
 *
 * @api
 */
final class BitmapFontFace implements FontFace
{
    /**
     * @param array<int, list<string>> $glyphs 码位 → 行位图（`#` 墨 / `.` 空）
     */
    public function __construct(
        private readonly string $family,
        private readonly FontWeight $weight,
        private readonly FontSlant $slant,
        private readonly FontMetrics $metrics,
        private readonly int $unitsPerEm,
        private readonly array $glyphs,
    ) {
        if ($unitsPerEm <= 0) {
            Errors::report('BitmapFontFace：unitsPerEm 必须为正，收到 ' . $unitsPerEm);
        }
    }

    /**
     * §13.5 的记忆化缓存：`码位@像素尺寸` → GlyphBitmap。
     *
     * ★ 为什么必须加（2026-10-18 实测）：`rasterize()` 是纯函数（`glyphs` 不可变，
     *   输入只有码位与像素尺寸），却是每帧 paint 的热点 —— 不带缓存时一个
     *   Card+Row+2Text 的列表行 paint 要 19.4ms（layout 只 0.2ms、settle 0.05ms），
     *   绝大部分花在**反复光栅同样的字**（同帧内 + 跨帧 100% 重复）。
     * ★ 键是**精确浮点字符串**（`码位@像素尺寸`）：量化到 1/64px 会让两个
     *   pixelSize 共用一份位图，边界像素可能不同 ⇒ 破坏"同输入同输出"的
     *   golden 承诺；float→string 是最短往返表示，相等才同键。
     * ★ GlyphBitmap 是 `final readonly`（不可变）⇒ 跨帧共享无别名风险。
     * ★ 上限防爆：字号动画（10→40 连续扫）会造出几千个尺寸键 ⇒ 超限整体清零
     *   （纯性能缓存，清了只回到"慢"，不会错）。
     *
     * @var array<string, GlyphBitmap>
     */
    private array $rasterCache = [];

    private const int RASTER_CACHE_MAX = 8192;

    /**
     * @param array<int, list<string>> $glyphs
     */
    public static function fromGlyphs(
        string $family,
        FontWeight $weight,
        FontSlant $slant,
        int $unitsPerEm,
        int $ascent,
        int $descent,
        int $lineGap,
        array $glyphs,
    ): self {
        return new self(
            $family,
            $weight,
            $slant,
            FontMetrics::fromUnits($ascent, $descent, $lineGap, $unitsPerEm),
            $unitsPerEm,
            $glyphs,
        );
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
        return $this->unitsPerEm;
    }

    public function hasGlyph(int $codepoint): bool
    {
        return isset($this->glyphs[$codepoint]);
    }

    public function glyphAdvance(int $codepoint): float
    {
        $rows = $this->glyphs[$codepoint] ?? null;
        if ($rows === null) {
            // 回退链本应先问 hasGlyph；走到这里说明选 face 的逻辑错了 —— 不静默
            Errors::report(
                sprintf('%s 未覆盖 U+%04X，步进按 0 计', $this->family, $codepoint),
                Errors::DIAGNOSTIC
            );
            return 0.0;
        }
        // ★ 不加 `+1`：advance 就是字身宽（见类头的 §24.2 #20 说明）。
        //   加 1 会让每个字多占 1/unitsPerEm，中文 +6.25%、拉丁 +36%。
        return strlen($rows[0]) / $this->unitsPerEm;
    }

    public function rasterize(int $codepoint, float $pixelSize): ?GlyphBitmap
    {
        $rows = $this->glyphs[$codepoint] ?? null;
        if ($rows === null || $pixelSize <= 0.0) {
            return null;
        }

        // ★ 记忆化（§13.5）：同一帧里同一字形被反复光栅化（滚动列表、多处同字号），
        //   跨帧更是 100% 重复 —— 缓存把这些全部变成一次查表。
        $key = $codepoint . '@' . $pixelSize;
        $cached = $this->rasterCache[$key] ?? null;
        if ($cached !== null) {
            return $cached;
        }

        $bitmap = $this->rasterizeUncached($rows, $pixelSize);
        if (count($this->rasterCache) >= self::RASTER_CACHE_MAX) {
            $this->rasterCache = [];   // 纯性能缓存：超限整体清零，只回到慢、不错
        }
        $this->rasterCache[$key] = $bitmap;

        return $bitmap;
    }

    /** 真正的缩放 / 覆盖度计算。记忆化由调用方负责，本函数保持纯函数 */
    private function rasterizeUncached(array $rows, float $pixelSize): GlyphBitmap
    {
        $sourceHeight = count($rows);
        $sourceWidth = strlen($rows[0]);
        $scale = $pixelSize / $this->unitsPerEm;
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));

        // §2.4 纪律 2：循环里不许 .= 累加（增长累加器在多字节下会**堆损坏**，
        // 且崩在无关代码）⇒ 先落数组，出循环一次 implode。
        // ★ 故意用两个变量名（`$coverage` 保持 array、`$bitmap` 是 string）：
        //   同一个变量从 array 改判成 string 会让 AOT 的类型钉死自找麻烦。
        $coverage = [];

        if ($scale >= 1.0) {
            // 放大 / 1:1：最近邻（整数倍时逐像素复制，非整数倍时块状放大）
            for ($y = 0; $y < $height; $y++) {
                $row = $rows[min($sourceHeight - 1, (int) floor($y * $sourceHeight / $height))];
                for ($x = 0; $x < $width; $x++) {
                    $column = min($sourceWidth - 1, (int) floor($x * $sourceWidth / $width));
                    $coverage[] = $row[$column] === '#' ? "\xFF" : "\x00";
                }
            }
        } else {
            // ★ 降采样：**面积覆盖度平均 → 直接输出灰度**（0..255，非二值）。
            //   最近邻降采样会**整行/整列丢笔画**（16→12 恰好丢 4/16 行），12px 的说明
            //   文字因此糊成一团（2026-10-16 由预览抓出）。覆盖度平均把"1px 细线"折算
            //   成 1/(16/12)=0.75 的覆盖度 ⇒ 过 0.5 阈值 ⇒ 保住笔画。
            //   ★★ 2026-10-17 修锯齿：覆盖度算出后**直接量化成 0..255 输出**，不再
            //     二值化成 0x00/0xFF —— GlyphBitmap 契约本就是"连续覆盖度"，
            //     RasterCanvas::blend() 也按 alpha 混合（alpha=色alpha×覆盖率），
            //     抗锯齿信息过去在**本函数出口**被倒掉。实证：users 页预览从 5 种
            //     纯色变 187 种（边缘连续过渡）。skia 后端不走本路径（真 TTF +
            //     SkFont::kAntiAlias），不受影响。
            //   ★ 放大路径完全不动 ⇒ 既有位图逐字节不变（零回归）。
            for ($y = 0; $y < $height; $y++) {
                $sy0 = $y * $sourceHeight / $height;
                $sy1 = ($y + 1) * $sourceHeight / $height;
                $syLast = (int) ceil($sy1);
                if ($syLast > $sourceHeight) {
                    $syLast = $sourceHeight;
                }
                for ($x = 0; $x < $width; $x++) {
                    $sx0 = $x * $sourceWidth / $width;
                    $sx1 = ($x + 1) * $sourceWidth / $width;
                    $sxLast = (int) ceil($sx1);
                    if ($sxLast > $sourceWidth) {
                        $sxLast = $sourceWidth;
                    }

                    $ink = 0.0;
                    for ($sy = (int) floor($sy0); $sy < $syLast; $sy++) {
                        $overY = min($sy1, $sy + 1.0) - max($sy0, (float) $sy);
                        if ($overY <= 0.0) {
                            continue;
                        }
                        $row = $rows[$sy];
                        for ($sx = (int) floor($sx0); $sx < $sxLast; $sx++) {
                            if ($row[$sx] !== '#') {
                                continue;
                            }
                            $overX = min($sx1, $sx + 1.0) - max($sx0, (float) $sx);
                            if ($overX > 0.0) {
                                $ink += $overX * $overY;
                            }
                        }
                    }

                    $area = ($sx1 - $sx0) * ($sy1 - $sy0);
                    $coverage[] = $area > 0.0 ? chr((int) round(min(1.0, $ink / $area) * 255)) : "\x00";
                }
            }
        }
        $bitmap = implode('', $coverage);

        return new GlyphBitmap(
            $width,
            $height,
            0,
            -(int) round($this->metrics->ascent * $pixelSize),
            $bitmap,
        );
    }
}
