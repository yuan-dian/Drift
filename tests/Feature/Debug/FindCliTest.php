<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * `debug find` 的出口契约：地址拿得出来、截断说得清、参数错不装成"没命中"。
 *
 * ## 为什么要跑真子进程
 *
 * 搜索的价值在**能被抄走**：用户看到 `node:19` 就应当能把它贴进 `debug node`。
 * 那要求输出是文本、退出码是 0，而这两样只有真跑进程才看得见。参数错（空
 * pattern / 未知域）必须是 2 而不是"空结果 + 0"—— 后者会让脚本把"我搜错了"
 * 当成"这里没有"，一路绿下去。
 */
final class FindCliTest extends SmokeTestCase
{
    private const array DRIVE = ['--page=table', '--frames=1', '--click=400,300'];

    public function testPrintsAddressesThatCanBeCopied(): void
    {
        [$code, $out, $err] = $this->find([...self::DRIVE, '--pattern=Table']);
        check('正常搜索退出 0', $code === 0, $err);
        check('输出带地址', \str_contains($out, 'node:'), $out);
        check('表头自报扫过多少候选', \str_contains($out, '扫过 节点'), $out);
        // 命中项后面紧跟的是位置，不是行号：地址 + 路径才是"能抄走"的一行。
        check('命中行带类型路径', \str_contains($out, ' > '), $out);
        $this->flushChecks();
    }

    public function testLimitSelfReportsTruncation(): void
    {
        [$code, $out, $err] = $this->find([...self::DRIVE, '--pattern=Table', '--limit=1']);
        check('截断仍退出 0', $code === 0, $err);
        check('截断要点名还剩多少', \str_contains($out, '截断：命中'), $out);
        $this->flushChecks();
    }

    public function testCaseSensitivityIsOptIn(): void
    {
        [, $loose] = $this->find([...self::DRIVE, '--pattern=table', '--limit=3']);
        [, $strict] = $this->find([...self::DRIVE, '--pattern=table', '--limit=3', '--case-sensitive']);
        check('默认不区分大小写', !\str_contains($loose, '没有命中'), $loose);
        check('--case-sensitive 后小写不命中', \str_contains($strict, '没有命中'), $strict);
        $this->flushChecks();
    }

    public function testJsonCarriesEnoughForAMachineConsumer(): void
    {
        [$code, $out] = $this->find([...self::DRIVE, '--pattern=Table', '--json']);
        check('--json 退出 0', $code === 0, $out);
        $raw = \json_decode($out, true);
        self::assertIsArray($raw);
        check('带 label 与 pattern', ($raw['label'] ?? '') !== '' && ($raw['pattern'] ?? '') === 'Table', $out);
        check('带三个计数', \array_key_exists('total', $raw) && \array_key_exists('returned', $raw)
            && \array_key_exists('truncated', $raw), $out);
        check('matches 是地址列表', \str_starts_with((string) ($raw['matches'][0]['address'] ?? ''), 'node:'), $out);
        $this->flushChecks();
    }

    public function testBadInputExitsTwoInsteadOfEmptyResult(): void
    {
        [$missing, , $missingErr] = $this->find(self::DRIVE);
        check('缺 pattern 退出 2', $missing === 2, (string) $missing);
        check('缺 pattern 打印用法', \str_contains($missingErr, '用法'), $missingErr);

        [$domain, , $domainErr] = $this->find([...self::DRIVE, '--pattern=x', '--domain=frame']);
        check('未知域退出 2', $domain === 2, (string) $domain);
        check('未知域列出可用值', \str_contains($domainErr, 'all') && \str_contains($domainErr, 'request'), $domainErr);
        $this->flushChecks();
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function find(array $args): array
    {
        $root = \dirname(__DIR__, 3);
        $proc = \proc_open(
            \array_merge([\PHP_BINARY, $root . '/devtools/bin/debug-find.php'], $args),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($proc, 'debug-find 起不来');
        \fclose($pipes[0]);
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return [\proc_close($proc), $out, $err];
    }
}
