<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\DevTools\Cli\Doc;
use Drift\DevTools\Cli\OptionSet;
use Drift\DevTools\Cli\Registry;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 手册是声明的视图：枚举事实由生成块给出，散文只负责语义。
 * 这里的判据咬的是"改了声明忘了改手册"和"手册写了不存在的参数"两类漂移。
 */
final class CliDocTest extends SmokeTestCase
{
    public function testManualIsClosedOverTheDeclarations(): void
    {
        $manual = $this->manual();
        $problems = Doc::problems($manual);
        check('调试手册与注册表 / schema 闭合', $problems === [], \implode(' | ', $problems));
        check('生成器幂等', Doc::upsert($manual) === $manual, '再生成一次却改了内容');
        $this->flushChecks();
    }

    public function testGeneratedBlockCoversEveryDeclaration(): void
    {
        $block = Doc::block();
        foreach (Registry::all() as $verb => $entry) {
            check("生成块含出口 {$verb}", \str_contains($block, '`' . $verb . '`') && \str_contains($block, '`' . $entry->script . '`'));
        }
        foreach (OptionSet::driver() as $opt) {
            check("生成块含共用词 --{$opt->name}", \str_contains($block, '`--' . $opt->name . '`'));
        }
        check('driver / protocol 都显式标出', \str_contains($block, 'driver') && \str_contains($block, 'protocol'));
        $this->flushChecks();
    }

    public function testCheckerBitesWhenTheManualDrifts(): void
    {
        $manual = $this->manual();
        $cases = [
            '删生成块标记' => \str_replace(Doc::START, '', $manual),
            '篡改生成块一行' => \str_replace('| `brief` | `debug-brief.php` |', '| `briefx` | `debug-brief.php` |', $manual),
            '散文漏一个出口' => $this->mutate($manual, static fn(string $p): string => \str_replace('`network` 看', '看', $p)),
            '散文写错参数名' => $this->mutate($manual, static fn(string $p): string => \str_replace('`--screen=WxH`', '`--screeen=WxH`', $p)),
        ];
        foreach ($cases as $label => $candidate) {
            check("{$label} 被咬住", Doc::problems($candidate) !== [], '变异后仍然全绿');
        }
        $this->flushChecks();
    }

    private function mutate(string $manual, callable $change): string
    {
        $prose = Doc::prose($manual);
        self::assertNotNull($prose, '散文段找不到');
        $body = Doc::withoutBlock($prose);
        $next = $change($prose);
        self::assertNotSame(Doc::withoutBlock($next), $body, '变异没有真的改到散文');

        return \str_replace($prose, $next, $manual);
    }

    private function manual(): string
    {
        return (string) \file_get_contents(\dirname(__DIR__, 3) . '/docs/reference/cli.md');
    }
}
