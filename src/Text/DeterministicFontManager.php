<?php
declare(strict_types=1);

namespace Drift\Text;

use Drift\Errors;

/**
 * `FontManager` 的确定性实现（§8.1 第一层）。
 *
 * ★ §8.3 血账 1：字体初始化失败必须**响亮**（G2），不能等到绘制时才崩 ——
 *   所以 `registerFile()` / `registerBytes()` 在 M0 明确报 Fatal：**没实现就说
 *   没实现**，而不是静默忽略注册、留一个空字体栈到运行期才炸。
 *
 * @api
 */
final class DeterministicFontManager implements FontManager
{
    /** @var list<FontFace> */
    private array $faces = [];
    private ?FontCollection $collection = null;

    /**
     * 内置测试字体：拉丁 5×8 + CJK 16×16 子集，同属 `sans` 回退链。
     */
    public static function standard(): self
    {
        $manager = new self();
        $manager->registerFace(self::latinFace());
        $manager->registerFace(self::cjkFace());
        return $manager;
    }

    /**
     * 拉丁面：unitsPerEm = 32，ascent 28 / descent 4 ⇒ 度量 (0.875, 0.125, 0)
     *
     * ★ 2026-10-17 从 8 提到 32（§24.2 #21）：字模改由 `tools/font-gen-latin.py`
     *   生成，**位图宽 == 真 advance**。5×8 时代的固定 5 列让 `i` 与 `m` 同宽。
     *   32 是精度与体积的折中（`i` 0.275em = 8.8 列 ⇒ 9 列，量化误差 ±0.016em）。
     * ★ 度量与 CJK 面（14/16、2/16）**逐字段相同** ⇒ 两个面可以混排。
     */
    public static function latinFace(): BitmapFontFace
    {
        return BitmapFontFace::fromGlyphs(
            'sans',
            FontWeight::Regular,
            FontSlant::Normal,
            32,
            28,
            4,
            0,
            DeterministicFontData::latin(),
        );
    }

    /** CJK 面：unitsPerEm = 16，ascent 14 / descent 2 ⇒ 度量与拉丁面**逐字段相同** */
    public static function cjkFace(): BitmapFontFace
    {
        return BitmapFontFace::fromGlyphs(
            'sans',
            FontWeight::Regular,
            FontSlant::Normal,
            16,
            14,
            2,
            0,
            DeterministicFontData::cjk(),
        );
    }

    public function registerFace(FontFace $face): void
    {
        $this->faces[] = $face;
        $this->collection = null; // 链按注册序构成，注册后必须重建
    }

    public function registerFile(string $path, int $faceIndex = 0): void
    {
        Errors::report('DeterministicFontManager 不解析字体文件（M0 只带内置点阵字模）：' . $path);
    }

    public function registerBytes(string $bytes, int $faceIndex = 0): void
    {
        Errors::report('DeterministicFontManager 不解析字体字节（M0 只带内置点阵字模），收到 ' . strlen($bytes) . ' 字节');
    }

    public function faces(): array
    {
        return $this->faces;
    }

    public function collection(): FontCollection
    {
        if ($this->collection === null) {
            $chains = [];
            foreach ($this->faces as $face) {
                $chains[$face->familyName()][] = $face;
            }
            $this->collection = new DeterministicFontCollection($chains, new NotdefFontFace());
        }
        return $this->collection;
    }

    public function isReady(): bool
    {
        return $this->faces !== [];
    }
}
