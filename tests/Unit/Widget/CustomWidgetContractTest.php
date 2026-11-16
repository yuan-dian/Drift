<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Layout\RenderBox;
use Drift\Widget\RenderBoxWidget;

/**
 * 自定义控件契约的静态对账：文档 §4 / §7 / §11 说的必须等于代码里的。
 *
 * ★ 文档与实现分开写就会漂移：本轮 review 抓到 §7 骨架塞了可选的 setInteraction、
 *   §3 漏了组合优先那一档。判据把两边钉在一起，改一边要改另一边。
 * ★ 只做静态对账，不实例化盒：挂载要 BuildContext / SurfaceProbe，那是 Feature 层的事。
 */
final class CustomWidgetContractTest extends SmokeTestCase
{
    private const string GUIDE = 'docs/guides/custom-widgets.md';

    private const string FIXTURE = 'tests/Feature/App/SelfDrawnFocusRingTest.php';

    public function testAbstractMethodSetMatchesGuide(): void
    {
        $abstract = [];
        foreach ((new \ReflectionClass(RenderBox::class))->getMethods() as $method) {
            if ($method->isAbstract()) {
                $abstract[] = $method->getName();
            }
        }
        sort($abstract);

        $expected = [
            'contentIntrinsicHeight', 'contentIntrinsicWidth', 'paintContent',
            'performLayout', 'visitChildren',
        ];

        check(
            '自定义控件 · RenderBox 的 abstract 集合 == 文档 §4 五件套',
            $abstract === $expected,
            'missing=' . implode(',', array_diff($expected, $abstract))
                . ' extra=' . implode(',', array_diff($abstract, $expected)),
        );
        check(
            '自定义控件 · paint() 保持 final（层序不可被子类覆写）',
            (new \ReflectionClass(RenderBox::class))->getMethod('paint')->isFinal(),
            'paint',
        );

        $this->flushChecks();
    }

    public function testOptionalHooksStayOptOutAndOverridable(): void
    {
        $ref = new \ReflectionClass(RenderBoxWidget::class);
        $src = (string) file_get_contents((string) $ref->getFileName());

        $hooks = [
            'paintsBoxChrome' => 'return false;',
            'paintsDisabledLook' => 'return false;',
            'defaultBoxStyle' => 'return null;',
        ];

        foreach ($hooks as $name => $needle) {
            $body = $this->braceBody($src, 'public function ' . $name . '(');
            check(
                "自定义控件 · {$name}() 默认 opt-out（改了会让 syncBox 消费错）",
                $body !== '' && str_contains($body, $needle),
                'body=' . substr($body, 0, 60),
            );
            $method = $ref->getMethod($name);
            check(
                "自定义控件 · {$name}() 仍是 public 非 final（开发者才能覆写）",
                $method->isPublic() && !$method->isFinal(),
                $name,
            );
        }

        $this->flushChecks();
    }

    public function testGuideNamesMatchLiveContracts(): void
    {
        $guide = (string) file_get_contents($this->path(self::GUIDE));
        $contracts = [
            'performLayout', 'contentIntrinsicWidth', 'contentIntrinsicHeight',
            'paintContent', 'visitChildren',
        ];

        foreach ($contracts as $name) {
            check(
                "自定义控件 · 指南写出真实契约 {$name}()",
                str_contains($guide, $name . '()'),
                $name,
            );
        }
        check(
            '自定义控件 · 指南不把可选的形状钩子写成必做契约',
            !preg_match('/(必做|必须实现)[^。\n]*setInteraction/u', $guide),
            'setInteraction',
        );

        $this->flushChecks();
    }

    public function testExtractorsCatchDrift(): void
    {
        $fake = "final class X\n{\n    public function a(): void {}\n    public function b(): void {}\n}\n";
        $got = $this->methodNames($this->braceBody($fake, 'final class X'));
        check(
            '自定义控件 · 方法名提取器能发现缺一个方法（判据不是恒绿空壳）',
            $got === ['a', 'b'],
            'got=' . implode(',', $got),
        );

        $md = "## 7. 骨架\n```php\nfunction inside() {}\n```\n\nsetInteraction(...) 是可选的\n\n## 8. 坑\n";
        $section = $this->section($md, '## 7.', '## 8.');
        preg_match('/```php\s*(.*?)```/s', $section, $m);
        $block = $m[1] ?? '';
        check(
            '自定义控件 · 代码块提取器停在 ``` 结束处（不把可选说明算进骨架）',
            str_contains($block, 'inside') && !str_contains($block, 'setInteraction'),
            'block=' . $block,
        );

        $this->flushChecks();
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }

    /** 按花括号配平截出签名后的类体 / 方法体；被测代码里没有含花括号的字符串。 */
    private function braceBody(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        if ($start === false) {
            return '';
        }
        $open = strpos($src, '{', $start);
        if ($open === false) {
            return '';
        }

        $depth = 0;
        for ($i = $open, $n = strlen($src); $i < $n; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $open, $i - $open + 1);
                }
            }
        }

        return '';
    }

    /** @return list<string> 排序去重后的方法名 */
    private function methodNames(string $body): array
    {
        preg_match_all('/\bfunction\s+(\w+)\s*\(/', $body, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);

        return $names;
    }

    private function section(string $src, string $from, string $to): string
    {
        $a = strpos($src, $from);
        if ($a === false) {
            return '';
        }
        $b = strpos($src, $to, $a);
        if ($b === false) {
            return '';
        }

        return substr($src, $a, $b - $a);
    }
}
