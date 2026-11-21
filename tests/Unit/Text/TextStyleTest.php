<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Text;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Text\FontFamily;
use Drift\Text\FontSlant;
use Drift\Text\FontWeight;
use Drift\Text\TextAlign;
use Drift\Text\TextStyle;

/**
 * `TextStyle` 的两条热路径语义：`equals()` 覆盖全字段、`with*()` 没变就返回 `$this`。
 *
 * 它们都在"每帧每个文本"上跑，所以两条都是性能判据；但漏了都是**静默错**：
 * `equals()` 漏一维 ⇒ 那一维改了样式却复用旧盒；`copy()` 短路判错 ⇒ 派生出一份
 * "看起来新、其实旧"的样式。
 */
final class TextStyleTest extends SmokeTestCase
{
    /**
     * `equals()` 与 `cacheKey()` **互为判据**：改任何一个字段，两者必须同时说"不等"。
     *
     * ★ 走两个独立实现而不是只断言 `equals() === false`：这样"逐字段比较漏了一维"
     *   会被 `cacheKey()` 抓住，反之亦然 —— 单一实现的话，"漏"和"对"没有区别。
     */
    public function testEqualsAgreesWithCacheKeyOnEveryField(): void
    {
        $base = TextStyle::of(14.0);
        $wrong = [];

        foreach (self::variants($base) as $name => $variant) {
            $byEquals = $base->equals($variant);
            $byKey = $base->cacheKey() === $variant->cacheKey();
            if ($byEquals !== $byKey) {
                $wrong[] = \sprintf('%s（equals=%s / cacheKey 相同=%s）', $name, self::bool($byEquals), self::bool($byKey));
            }
            if ($byEquals) {
                $wrong[] = $name . '（改了却仍判等）';
            }
        }

        check(
            'TextStyle · equals() 覆盖全部字段（与 cacheKey 互为判据）',
            $wrong === [],
            \implode('；', $wrong)
        );
    }

    /** 两个**独立构造**的同值样式必须判等（不能比身份） */
    public function testIndependentlyConstructedEqualStylesAreEqual(): void
    {
        $a = TextStyle::of(14.0)->withMaxLines(2);
        $b = TextStyle::of(14.0)->withMaxLines(2);

        check(
            'TextStyle · 同值不同实例判等',
            $a !== $b && $a->equals($b) && $b->equals($a),
        );
    }

    /**
     * `with*()` 传入**当前值** ⇒ 返回 `$this`（零分配）。
     *
     * ★ 这是 `Text::resolveStyle()` 默认路径零分配的前提：每帧都会调
     *   `withMaxLines(0)->withEllipsize(false)`，参数通常与默认值相同。
     */
    public function testWithUnchangedValueReturnsSameInstance(): void
    {
        $base = TextStyle::of(14.0);
        $same = [
            'withSize' => $base->withSize(14.0),
            'withWeight' => $base->withWeight(FontWeight::Regular),
            'withSlant' => $base->withSlant(FontSlant::Normal),
            'withLineHeight' => $base->withLineHeight(1.2),
            'withLetterSpacing' => $base->withLetterSpacing(0.0),
            'withAlign' => $base->withAlign(TextAlign::Start),
            'withMaxLines' => $base->withMaxLines(0),
            'withEllipsize' => $base->withEllipsize(false),
            'withFamily' => $base->withFamily($base->family),
        ];

        $wrong = [];
        foreach ($same as $name => $result) {
            if ($result !== $base) {
                $wrong[] = $name;
            }
        }

        check(
            'TextStyle · 传入当前值 ⇒ 返回同一个实例（默认派生路径零分配）',
            $wrong === [],
            '走了 new：' . \implode(',', $wrong)
        );

        check(
            'TextStyle · 传入新值 ⇒ 返回新实例',
            $base->withMaxLines(2) !== $base && $base->withSize(16.0) !== $base,
        );
    }

    /**
     * 每个字段一行"只改它"的变体。
     *
     * @return array<string, TextStyle>
     */
    private static function variants(TextStyle $base): array
    {
        return [
            'family' => $base->withFamily(FontFamily::serif()),
            'size' => $base->withSize(16.0),
            'weight' => $base->withWeight(FontWeight::Bold),
            'slant' => $base->withSlant(FontSlant::Italic),
            'lineHeight' => $base->withLineHeight(1.5),
            'letterSpacing' => $base->withLetterSpacing(1.0),
            'align' => $base->withAlign(TextAlign::Center),
            'maxLines' => $base->withMaxLines(2),
            'ellipsize' => $base->withEllipsize(true),
        ];
    }

    private static function bool(bool $v): string
    {
        return $v ? '真' : '假';
    }
}
