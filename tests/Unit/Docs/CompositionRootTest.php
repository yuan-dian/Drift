<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Docs;

use Drift\App\Application;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * `14-组合根与装配.md` §4 的能力索引与 `Application` 真实公共面对账。
 *
 * ★ 不往 `Application.php` 的每个 public 方法上贴分组注释：那会造一整片新的腐烂面。
 *   索引放在文档里，由 Reflection 守「一个不漏、一个不多」；数量同样不写死。
 */
final class CompositionRootTest extends SmokeTestCase
{
    private const string DOC = 'docs/architecture/14-组合根与装配.md';

    public function testEveryPublicApplicationMethodIsIndexed(): void
    {
        [$actual, $indexed] = $this->sets();
        check(
            '组合根 · Application 的 public 方法全被能力索引覆盖',
            array_diff($actual, $indexed) === [],
            'missing=' . implode(',', array_diff($actual, $indexed)),
        );
        check(
            '组合根 · 索引条数与真实公共面一致',
            count($indexed) === count($actual),
            'indexed=' . count($indexed) . ' actual=' . count($actual),
        );

        $this->flushChecks();
    }

    public function testIndexHasNoGhostMethods(): void
    {
        [$actual, $indexed] = $this->sets();
        check(
            '组合根 · 能力索引里没有已删除 / 拼错的方法',
            array_diff($indexed, $actual) === [],
            'ghosts=' . implode(',', array_diff($indexed, $actual)),
        );

        $this->flushChecks();
    }

    public function testExtractorIsNotATautology(): void
    {
        $fake = '`alpha()`、`beta()` 与 `Gamma::delta()`、`STAGE_BUDGET_MS`、`src/App/Application.php`。';
        check(
            '对账 · 提取器只抓裸方法名，不误伤限定名 / 常量 / 路径',
            $this->extract($fake) === ['alpha', 'beta'],
            implode(',', $this->extract($fake)),
        );
        check(
            '对账 · 索引区块确实被切出来（不是拿全文凑数）',
            str_contains($this->indexSection(), '`__construct()`'),
            'section missing',
        );

        $this->flushChecks();
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function sets(): array
    {
        $reflection = new \ReflectionClass(Application::class);
        $actual = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === Application::class) {
                $actual[] = $method->getName();
            }
        }
        sort($actual);

        return [$actual, $this->extract($this->indexSection())];
    }

    private function indexSection(): string
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 3) . '/' . self::DOC);
        preg_match('/## 4\. 公共面能力索引(.*?)\n## 5\./su', $doc, $match);

        return $match[1] ?? '';
    }

    /** @return list<string> */
    private function extract(string $text): array
    {
        preg_match_all('/`([A-Za-z_][A-Za-z0-9_]*)\(\)`/u', $text, $match);
        $names = array_values(array_unique($match[1]));
        sort($names);

        return $names;
    }
}
