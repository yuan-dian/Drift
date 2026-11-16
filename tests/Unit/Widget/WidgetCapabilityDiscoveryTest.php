<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Widget\Card;
use Drift\Widget\Flex;
use Drift\Widget\SizedBox;
use Drift\Widget\Traits\WidgetModifiers;
use Drift\Widget\Widget;

/**
 * 控件能力「可发现性」的静态对账：权威入口文档说的必须等于代码里有的。
 *
 * ★ 文档与实现分开写就会漂移：本轮抓到 `08-控件面.md` 教一套已删的 `BoxWidget`
 *   谱系、指向不存在的 `src/Widget/BoxWidget.php`，应用注释还宣称圆角容器做不出。
 *   判据不列文案黑名单，只查结构事实：引用的源码路径存在、幽灵类不被引为现存类、
 *   修饰能力确实挂在所有 Widget 上。
 * ★ 只覆盖 `00-README.md` 指定的「加新控件」入口与三个示例台注释；历史设计稿
 *   （`docs/arch-v2/`、`REVIEW-2026-10-*`）是当时的事实记录，不进这条判据。
 */
final class WidgetCapabilityDiscoveryTest extends SmokeTestCase
{
    /** @var list<string> `docs/README.md` 指向的「想加一个新控件」入口 */
    private const array ENTRY_DOCS = [
        'docs/guides/widgets.md',
        'docs/guides/custom-widgets.md',
    ];

    /** @var list<string> 会直接误导使用者的示例台注释 */
    private const array APP_COMMENTS = [
        'apps/showcase/src/ShowcaseRoot.php',
        'apps/showcase/src/Pages/LayoutPage.php',
        'apps/showcase/src/Pages/FormPage.php',
    ];

    public function testEntryDocsReferenceOnlyRealSourceFiles(): void
    {
        foreach (self::ENTRY_DOCS as $doc) {
            $src = (string) file_get_contents($this->path($doc));
            $ghosts = array_values(array_filter(
                $this->srcPaths($src),
                static fn (string $p): bool => !is_file(dirname(__DIR__, 3) . '/' . $p),
            ));

            check(
                "能力可发现性 · {$doc} 引用的 src 路径都真实存在（幽灵路径会教错开发者）",
                $ghosts === [],
                'ghosts=' . implode(',', $ghosts),
            );
        }

        $this->flushChecks();
    }

    public function testGhostBaseClassIsNotTaughtAsLive(): void
    {
        check(
            '能力可发现性 · src/Widget/BoxWidget.php 不存在（该基类已删）',
            !is_file($this->path('src/Widget/BoxWidget.php')),
            'BoxWidget.php',
        );
        check(
            '能力可发现性 · Drift\\Widget\\BoxWidget 不是可加载的类',
            !class_exists('Drift\\Widget\\BoxWidget'),
            'class_exists',
        );

        foreach (array_merge(self::ENTRY_DOCS, self::APP_COMMENTS) as $file) {
            $src = (string) file_get_contents($this->path($file));
            $usages = $this->ghostUsages($src);
            check(
                "能力可发现性 · {$file} 不把 BoxWidget 当现存基类用",
                $usages === [],
                'usages=' . implode(',', $usages),
            );
        }

        $this->flushChecks();
    }

    public function testDecorationCapabilityIsOnEveryWidget(): void
    {
        check(
            '能力可发现性 · SizedBox 存在（示例台曾写「框架暂无此控件」）',
            class_exists(SizedBox::class),
            'SizedBox',
        );

        $traits = $this->traitsOf(Widget::class);
        check(
            '能力可发现性 · Widget 基类合入 WidgetModifiers（修饰对所有控件可用）',
            in_array(WidgetModifiers::class, $traits, true),
            'traits=' . implode(',', $traits),
        );

        $ref = new \ReflectionClass(WidgetModifiers::class);
        foreach (['background', 'border', 'radius', 'gradient', 'shadow', 'padding', 'paddingAll'] as $name) {
            $method = $ref->getMethod($name);
            check(
                "能力可发现性 · WidgetModifiers::{$name}() 是 public 非 static",
                $method->isPublic() && !$method->isStatic(),
                $name,
            );
        }

        foreach ([SizedBox::class, Card::class, Flex::class] as $class) {
            check(
                "能力可发现性 · {$class} 拿得到 ->background()/->radius()（通用圆角容器做得出）",
                method_exists($class, 'background') && method_exists($class, 'radius'),
                $class,
            );
        }

        $this->flushChecks();
    }

    public function testExtractorsCatchDrift(): void
    {
        $fake = "见 `src/Widget/BoxWidget.php:25` 与 `src/Widget/Real.php`；\n"
            . "Button extends BoxWidget、BoxWidget::background、abstract class BoxWidget\n";
        check(
            '能力可发现性 · src 路径提取器抓得到幽灵路径（判据不是恒绿空壳）',
            $this->srcPaths($fake) === ['src/Widget/BoxWidget.php', 'src/Widget/Real.php'],
            implode(',', $this->srcPaths($fake)),
        );
        check(
            '能力可发现性 · 幽灵类提取器抓得到三种当现存类的写法',
            count($this->ghostUsages($fake)) === 3,
            implode(',', $this->ghostUsages($fake)),
        );
        check(
            '能力可发现性 · 幽灵类提取器不误伤 RenderBoxWidget',
            $this->ghostUsages('RenderBoxWidget::createRenderObject() / extends RenderBoxWidget') === [],
            'false-positive',
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
            '/`BoxWidget`（抽象）/u',
        ];
        $hits = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $src, $m) > 0) {
                $hits = array_merge($hits, $m[0]);
            }
        }

        return array_values(array_unique($hits));
    }

    /** @return list<string> */
    private function traitsOf(string $class): array
    {
        $out = [];
        $ref = new \ReflectionClass($class);
        while ($ref !== false) {
            foreach ($ref->getTraitNames() as $trait) {
                $out[] = $trait;
                foreach ($this->traitsOfTrait($trait) as $nested) {
                    $out[] = $nested;
                }
            }
            $ref = $ref->getParentClass();
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    private function traitsOfTrait(string $trait): array
    {
        return (new \ReflectionClass($trait))->getTraitNames();
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }
}
