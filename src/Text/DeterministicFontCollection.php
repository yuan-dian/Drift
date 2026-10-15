<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 回退链的确定性实现（§8.1 第二层）。
 *
 * ★ 链是**能力顺序**（拉丁 → CJK），不是候选名单：`faceFor()` 沿链找第一个
 *   `hasGlyph()` 为真的 face，都不含才退到兜底 —— "某个字不显示"因此在结构上
 *   有一个确定答案（§8.2 坑 5）。
 *
 * ★ 家族解析分两档（§8.2 坑 1）：**逻辑家族**（`sans`/`serif`/`mono`）找不到时
 *   退默认链（落地名是平台配置的事）；**显式家族名**（`FontFamily::named()`）
 *   找不到就返回空 —— 名字是钉死的，没有替代品。
 *
 * @api
 */
final class DeterministicFontCollection implements FontCollection
{
    /**
     * @param array<string, list<FontFace>> $chains 家族名 → 回退链
     */
    public function __construct(
        private readonly array $chains,
        private readonly FontFace $notdef,
        private readonly string $defaultFamily = 'sans',
    ) {
    }

    public function resolve(FontFamily $family, FontWeight $weight, FontSlant $slant): array
    {
        $chain = $this->chains[$family->name] ?? null;
        if ($chain === null) {
            if (!$family->isLogical()) {
                return [];
            }
            $chain = $this->chains[$this->defaultFamily] ?? [];
        }

        // 排序键 = (倾斜是否匹配, 字重距离, 注册序)：注册序兜底 ⇒ 结果与 usort 稳定性无关
        $ranked = [];
        foreach ($chain as $index => $face) {
            $ranked[] = [
                $face->slant() === $slant ? 0 : 1,
                $face->weight()->distanceTo($weight),
                $index,
                $face,
            ];
        }
        usort($ranked, static function (array $a, array $b): int {
            return [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]];
        });

        $faces = [];
        foreach ($ranked as $entry) {
            $faces[] = $entry[3];
        }
        return $faces;
    }

    public function faceFor(int $codepoint, FontFamily $family, FontWeight $weight, FontSlant $slant): FontFace
    {
        foreach ($this->resolve($family, $weight, $slant) as $face) {
            if ($face->hasGlyph($codepoint)) {
                return $face;
            }
        }
        return $this->notdef;
    }

    public function notdef(): FontFace
    {
        return $this->notdef;
    }
}
