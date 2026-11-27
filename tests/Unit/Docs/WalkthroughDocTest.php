<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Docs;

use Drift\DevTools\Cli\Registry;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 任务型使用指南的闭合判据（A78）：指南只教真实存在的动词、不抄会漂的枚举事实。
 *
 * ★ CliDocTest 守的是参考型手册 cli.md 的生成块；ArchitectureDocTest 守正文纪律；
 *   这里守第三类页面特有的两处漂移：动词写成不存在的出口、把出口数抄进散文 / 架构图。
 */
final class WalkthroughDocTest extends SmokeTestCase
{
    private const string GUIDE = 'docs/guides/debug-walkthrough.md';
    private const string ROADMAP = 'docs/concepts/debugger-roadmap.md';
    private const string INDEX = 'docs/README.md';

    public function testGuideVerbsAllResolveToTheRegistry(): void
    {
        $guide = $this->read(self::GUIDE);
        $verbs = $this->guideVerbs($guide);

        // 反假绿：先确认提取器真的抓到了动词，再验闭合；否则"空集合 ⊆ Registry"恒真。
        check('上手指南 · 动词提取器抓到真实命令', \count($verbs) >= 5, 'verbs=' . \implode(',', $verbs));
        $known = Registry::all();
        foreach ($verbs as $verb) {
            check(
                '上手指南 · debug ' . $verb . ' 是真实出口',
                isset($known[$verb]),
                '未在 Registry 注册',
            );
        }
        $this->flushChecks();
    }

    public function testNoHardcodedExitCountInGuideOrArchitectureDiagram(): void
    {
        // 出口数只有一个真相源（Registry）；散文与架构图都不许抄一个数字。
        foreach ([self::GUIDE, self::ROADMAP] as $path) {
            check(
                basename($path) . ' 不写死出口数',
                $this->hardcodedCount($this->read($path)) === [],
                '命中 ' . \implode(',', $this->hardcodedCount($this->read($path))),
            );
        }
        $this->flushChecks();
    }

    public function testGuideIsLinkedFromTheDocIndex(): void
    {
        $index = $this->read(self::INDEX);
        check(
            '文档首页链接到上手指南（否则 doccheck 报孤儿页）',
            \str_contains($index, 'guides/debug-walkthrough.md'),
            '首页没有这条链接',
        );
        $this->flushChecks();
    }

    public function testCheckerBitesOnEachDrift(): void
    {
        $guide = $this->read(self::GUIDE);
        $roadmap = $this->read(self::ROADMAP);

        check(
            '变异：不存在的动词被咬住',
            $this->verbsNotInRegistry(\str_replace('debug brief', 'debug breif', $guide)) !== [],
            '写错动词后仍然全绿',
        );
        check(
            '变异：指南抄出口数被咬住',
            $this->hardcodedCount($guide . "\n共 23 个出口\n") !== [],
            '抄了出口数仍然全绿',
        );
        check(
            '变异：架构图写回写死数字被咬住',
            $this->hardcodedCount(\str_replace('CLI 出口', 'CLI 22 出口', $roadmap)) !== [],
            '写回 CLI 22 出口仍然全绿',
        );

        // 反假绿：正确的文书必须真的不命中，否则上面的"变异被咬"是恒真空壳。
        check('反假绿：原指南不含写死出口数', $this->hardcodedCount($guide) === [], '原文就命中');
        check('反假绿：原架构图不含写死出口数', $this->hardcodedCount($roadmap) === [], '原文就命中');
        $this->flushChecks();
    }

    /** @return list<string> 指南里 debug <动词> 的动词，按首次出现顺序 */
    private function guideVerbs(string $guide): array
    {
        \preg_match_all('/\bdebug\s+([a-z][a-z0-9-]*)/u', $guide, $m);

        return \array_values(\array_unique($m[1]));
    }

    /** @return list<string> 抽出指南动词里不在 Registry 的那些 */
    private function verbsNotInRegistry(string $guide): array
    {
        return \array_values(\array_diff($this->guideVerbs($guide), \array_keys(Registry::all())));
    }

    /** @return list<string> "CLI N 出口" / "共 N 个出口" 这类会漂的硬编码数字 */
    private function hardcodedCount(string $text): array
    {
        $hits = [];
        \preg_match_all('/CLI\s+\d+\s*出口|共\s*\d+\s*个出口|\d+\s*个调试出口/u', $text, $m);
        foreach ($m[0] as $hit) {
            $hits[] = \trim($hit);
        }

        return \array_values(\array_unique($hits));
    }

    private function read(string $relative): string
    {
        return (string) \file_get_contents(\dirname(__DIR__, 3) . '/' . $relative);
    }
}
