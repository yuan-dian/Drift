<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Theme;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Rendering\Color;
use Drift\Rendering\ColorToken;
use Drift\Rendering\MetricToken;
use Drift\Theme\ThemeData;

/**
 * 主题能力的可执行对账：点覆盖语义、能力边界、权威文档的防漂移。
 *
 * 判据不列文案黑名单，只查结构事实：后写覆盖真的生效、ThemeData 仍是封闭值对象、
 * 09 文档引用的源码路径存在、幽灵基类不被引为现存类、token 表与文档计数一致。
 */
final class ThemeCapabilityTest extends SmokeTestCase
{
    /** @var list<string> 主题能力的权威活文档 */
    private const array ENTRY_DOCS = [
        'docs/guides/text-and-theme.md',
    ];

    public function testWithColorIsLastWriteWins(): void
    {
        $base = ThemeData::light();
        $first = $base->withColor(ColorToken::Accent, Color::fromRgb(1, 2, 3));
        $second = $first->withColor(ColorToken::Accent, Color::fromRgb(9, 8, 7));

        check(
            '主题 · withColor 同一 token 第二次覆盖生效（旧 + 合并会静默无效）',
            $second->color(ColorToken::Accent)->equals(Color::fromRgb(9, 8, 7)),
            'second',
        );
        check(
            '主题 · withColor 返回新实例、原主题不变',
            $second !== $first
                && $first->color(ColorToken::Accent)->equals(Color::fromRgb(1, 2, 3))
                && $base->color(ColorToken::Accent)->equals(ColorToken::Accent->lightValue()),
            'immutable',
        );

        $this->flushChecks();
    }

    public function testWithMetricIsLastWriteWins(): void
    {
        $base = ThemeData::light();
        $first = $base->withMetric(MetricToken::Gap, 20.0);
        $second = $first->withMetric(MetricToken::Gap, 40.0);

        check(
            '主题 · withMetric 同一 token 第二次覆盖生效（旧 + 合并会静默无效）',
            $second->metric(MetricToken::Gap) === 40.0,
            'second',
        );
        check(
            '主题 · withMetric 返回新实例、原主题不变',
            $first->metric(MetricToken::Gap) === 20.0
                && $base->metric(MetricToken::Gap) === 8.0,
            'immutable',
        );

        $this->flushChecks();
    }

    public function testPointOverrideAfterHighContrastStillWins(): void
    {
        $hc = ThemeData::light()->withHighContrast();
        $custom = Color::fromRgb(1, 2, 3);
        $over = $hc->withColor(ColorToken::Accent, $custom);

        check(
            '主题 · withHighContrast 之后再 withColor 仍生效（HC 已占该键，+ 会吞掉它）',
            $over->color(ColorToken::Accent)->equals($custom),
            'after-hc',
        );
        check(
            '主题 · 该次点覆盖不改动 HC 那份实例',
            !$hc->color(ColorToken::Accent)->equals($custom),
            'hc-unchanged',
        );

        $this->flushChecks();
    }

    public function testHighContrastSkipsTokensWithoutHcForm(): void
    {
        $base = ThemeData::light();
        $hc = $base->withHighContrast();

        check(
            '主题 · Hover 没有 HC 形态 ⇒ 保留原值（不与 Pressed 撞车）',
            $hc->color(ColorToken::Hover)->equals($base->color(ColorToken::Hover)),
            'hover',
        );
        check(
            '主题 · Pressed 没有 HC 形态 ⇒ 保留原值',
            $hc->color(ColorToken::Pressed)->equals($base->color(ColorToken::Pressed)),
            'pressed',
        );
        check(
            '主题 · 有 HC 形态的 Border 确实被换掉',
            !$hc->color(ColorToken::Border)->equals($base->color(ColorToken::Border)),
            'border',
        );

        $this->flushChecks();
    }

    public function testThemeDataIsClosedValueObject(): void
    {
        $ref = new \ReflectionClass(ThemeData::class);
        check(
            '主题 · ThemeData 仍是 final（能力边界：应用侧不能继承扩展）',
            $ref->isFinal(),
            'final',
        );
        check(
            '主题 · 构造器仍是 private（只能经 light()/dark()/forSystem() 建）',
            $ref->getConstructor()?->isPrivate() === true,
            'private-ctor',
        );
        check(
            '主题 · SystemTheme 只有 Light/Dark（HC 不塞进明暗枚举）',
            count(\Drift\Theme\SystemTheme::cases()) === 2,
            'system-theme-cases',
        );

        $this->flushChecks();
    }

    public function testEntryDocsReferenceOnlyRealSourceFiles(): void
    {
        foreach (self::ENTRY_DOCS as $doc) {
            $src = (string) file_get_contents($this->path($doc));
            $ghosts = array_values(array_filter(
                $this->srcPaths($src),
                static fn (string $p): bool => !is_file(dirname(__DIR__, 3) . '/' . $p),
            ));

            check(
                "主题 · {$doc} 引用的 src 路径都真实存在（幽灵路径会教错开发者）",
                $ghosts === [],
                'ghosts=' . implode(',', $ghosts),
            );
        }

        $this->flushChecks();
    }

    public function testGhostBaseClassIsNotTaughtAsLive(): void
    {
        foreach (self::ENTRY_DOCS as $doc) {
            $src = (string) file_get_contents($this->path($doc));
            $usages = $this->ghostUsages($src);
            check(
                "主题 · {$doc} 不把 BoxWidget 当现存基类用",
                $usages === [],
                'usages=' . implode(',', $usages),
            );
        }

        $this->flushChecks();
    }

    public function testTokenTablesMatchDocumentedCount(): void
    {
        $src = (string) file_get_contents($this->path('docs/guides/text-and-theme.md'));
        $colorCases = array_column(ColorToken::cases(), 'name');
        $metricCases = array_column(MetricToken::cases(), 'name');

        preg_match_all('/ColorToken::(\w+)/', $src, $colors);
        preg_match_all('/MetricToken::(\w+)/', $src, $metrics);

        check(
            '主题 · 文档引用的 ColorToken 成员都是真实枚举值',
            $colors[1] !== [] && array_diff($colors[1], $colorCases) === [],
            'tokens=' . implode(',', $colors[1]),
        );
        check(
            '主题 · 文档引用的 MetricToken 成员都是真实枚举值',
            $metrics[1] !== [] && array_diff($metrics[1], $metricCases) === [],
            'tokens=' . implode(',', $metrics[1]),
        );

        $this->flushChecks();
    }

    public function testExtractorsCatchDrift(): void
    {
        $fake = "见 `src/Theme/Ghost.php` 与 `src/Theme/ThemeData.php`；\n"
            . "Button extends BoxWidget、BoxWidget::background、abstract class BoxWidget\n"
            . "`ColorToken`：**10 个**\n";
        check(
            '主题 · src 路径提取器抓得到幽灵路径（判据不是恒绿空壳）',
            $this->srcPaths($fake) === ['src/Theme/Ghost.php', 'src/Theme/ThemeData.php'],
            implode(',', $this->srcPaths($fake)),
        );
        check(
            '主题 · 幽灵类提取器抓得到三种当现存类的写法',
            count($this->ghostUsages($fake)) === 3,
            implode(',', $this->ghostUsages($fake)),
        );
        check(
            '主题 · 幽灵类提取器不误伤 RenderBoxWidget',
            $this->ghostUsages('RenderBoxWidget::createRenderObject() / extends RenderBoxWidget') === [],
            'false-positive',
        );
        check(
            '主题 · 文档计数提取器抓得到数字',
            $this->documentedCount($fake, 'ColorToken') === 10,
            'count',
        );

        $this->flushChecks();
    }

    /** @return list<string> */
    private function srcPaths(string $src): array
    {
        preg_match_all('#\bsrc/[A-Za-z0-9_/.-]+\.php\b#', $src, $m);

        return array_values(array_unique($m[0]));
    }

    /** @return list<string> 把 BoxWidget 当现存类的三种写法；\b 天然排除 RenderBoxWidget */
    private function ghostUsages(string $src): array
    {
        $patterns = [
            '/\bBoxWidget::/',
            '/extends\s+BoxWidget\b/',
            '/abstract class BoxWidget\b/',
        ];
        $hits = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $src, $m) > 0) {
                $hits = array_merge($hits, $m[0]);
            }
        }

        return array_values(array_unique($hits));
    }

    /** 读 "`XxxToken`：**N 个**" 里的 N；缺失返回 -1 让判据咬红，不静默放行 */
    private function documentedCount(string $src, string $name): int
    {
        if (preg_match('/`' . preg_quote($name, '/') . '`：\*\*(\d+) 个\*\*/u', $src, $m) !== 1) {
            return -1;
        }

        return (int) $m[1];
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }
}
