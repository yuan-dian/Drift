<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\Dev\Debug\Discovery;
use Drift\Dev\Debug\Source;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 「应用先跑着，观察者后到」的发现规则。
 *
 * 真实业务不会先在命令行里写出目标类名：当前目录正好有一个活会话就该接上。
 * 多个不猜、显式来源不覆盖 —— 挑错了会让人对着另一个应用的结构找问题。
 */
final class SourceAutoAttachTest extends SmokeTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = \sys_get_temp_dir() . '/drift-auto-attach-' . \bin2hex(\random_bytes(5));
        \mkdir($this->dir . '/.drift', 0700, true);
    }

    protected function tearDown(): void
    {
        @\unlink($this->dir . '/.drift/session.json');
        @\rmdir($this->dir . '/.drift');
        @\rmdir($this->dir);
        parent::tearDown();
    }

    private function discovery(): Discovery
    {
        return Discovery::inDirectory($this->dir);
    }

    /** @param list<array{0: string, 1: int}> $sessions id => 端口 */
    private function seed(array $sessions): void
    {
        $rows = \array_map(
            static fn(array $s): array => [
                'id' => $s[0],
                'pid' => (int) \getmypid(),
                'port' => $s[1],
                'token' => 't',
                'target' => 'table',
                'startedAt' => '',
            ],
            $sessions,
        );
        \file_put_contents(
            $this->dir . '/.drift/session.json',
            \json_encode(['protocol' => Discovery::PROTOCOL, 'sessions' => $rows]),
        );
    }

    public function testOneLiveSessionNeedsNoFlag(): void
    {
        $this->seed([['a1', 51234]]);

        check(
            '一个活会话直接接',
            Source::autoAttach(false, null, null, null, $this->discovery()) === true,
        );
    }

    public function testNoSessionKeepsTheLocalPath(): void
    {
        check(
            '没有活会话就照原路跑',
            Source::autoAttach(false, null, null, null, $this->discovery()) === false,
        );
    }

    public function testExplicitSourceIsNeverOverridden(): void
    {
        $this->seed([['a1', 51234]]);

        check(
            '给了目标就自己跑',
            Source::autoAttach(false, null, 'table', null, $this->discovery()) === false,
        );
        check(
            '给了证据包就离线读',
            Source::autoAttach(false, 'evidence.json', null, null, $this->discovery()) === false,
        );
        check(
            '给了会话号就按点名的连',
            Source::autoAttach(false, null, null, 'a1', $this->discovery()) === false,
        );
        check(
            '显式 --attach 保持为真',
            Source::autoAttach(true, null, null, null, $this->discovery()) === true,
        );
    }

    public function testTwoSessionsRefuseToGuess(): void
    {
        $this->seed([['a1', 51234], ['b2', 51235]]);

        try {
            Source::autoAttach(false, null, null, null, $this->discovery());
            check('多个会话必须拒绝', false, '没有抛异常');
        } catch (\RuntimeException $e) {
            check('列出候选 id', \str_contains($e->getMessage(), 'a1') && \str_contains($e->getMessage(), 'b2'), $e->getMessage());
            check('说清怎么点名', \str_contains($e->getMessage(), '--session=<id>'), $e->getMessage());
        }
    }
}
