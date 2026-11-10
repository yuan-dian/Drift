<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\Job;
use Drift\App\ProcessWorker;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * worker 错误行的 `code` 字段 —— 结构化错误跨进程不丢的**源头**。
 *
 * 只测协议形状，不起子进程：`ProcessWorker::handle()` 收一行 JSON、回一行 JSON。
 * 少了 `code`，下游 `ProcessTaskHost` 还原出来的异常就只剩消息，UI 又得靠字符串
 * 匹配分辨 404 / 超时 —— 而全链路其它判据照样全绿。
 */
final class WorkerProtocolTest extends SmokeTestCase
{
    /** @param \Closure(array<string, mixed>): Job $factory */
    private static function reply(string $line, \Closure $factory): array
    {
        $raw = ProcessWorker::handle($line, ['job' => $factory]);
        $msg = \json_decode($raw, true);

        return \is_array($msg) ? $msg : [];
    }

    private static function okJob(): Job
    {
        return new class implements Job {
            public function run(): mixed
            {
                return ['n' => 1];
            }
        };
    }

    public function testSuccessReplyKeepsProtocolShape(): void
    {
        $msg = self::reply(
            '{"id":7,"job":"job","arg":{}}',
            static fn(array $arg): Job => self::okJob()
        );

        check(
            'ProcessWorker · 成功回复仍是 {id,ok,value}（错误码改动没碰成功路径）',
            ($msg['id'] ?? null) === 7 && ($msg['ok'] ?? null) === true && ($msg['value'] ?? null) === ['n' => 1],
            \json_encode($msg, \JSON_UNESCAPED_UNICODE) ?: ''
        );

        $this->flushChecks();
    }

    public function testThrownJobErrorCodeIsInReply(): void
    {
        $factory = static function (array $arg): Job {
            return new class implements Job {
                public function run(): mixed
                {
                    throw new \RuntimeException('接口挂了', 502);
                }
            };
        };

        $msg = self::reply('{"id":9,"job":"job","arg":{}}', $factory);

        check(
            'ProcessWorker · 任务抛异常 ⇒ 回复带 ok=false 且 id 原样',
            ($msg['ok'] ?? null) === false && ($msg['id'] ?? null) === 9,
            \json_encode($msg, \JSON_UNESCAPED_UNICODE) ?: ''
        );
        check(
            'ProcessWorker · 错误行带 code（源异常的 getCode 跨进程不丢）',
            ($msg['code'] ?? null) === '502',
            \json_encode($msg, \JSON_UNESCAPED_UNICODE) ?: ''
        );
        check(
            'ProcessWorker · error 仍是能画的消息字符串（不是异常对象）',
            \is_string($msg['error'] ?? null) && \str_contains((string) ($msg['error'] ?? ''), '接口挂了'),
            \json_encode($msg, \JSON_UNESCAPED_UNICODE) ?: ''
        );

        $this->flushChecks();
    }

    public function testProtocolErrorsHaveEmptyCode(): void
    {
        $bad = self::reply('这不是 JSON', static fn(array $arg): Job => self::okJob());
        $unknown = self::reply('{"id":3,"job":"nope","arg":{}}', static fn(array $arg): Job => self::okJob());

        check(
            'ProcessWorker · 协议层错误没有源异常 ⇒ code 为空串（不假装知道分类）',
            ($bad['code'] ?? null) === '' && ($unknown['code'] ?? null) === '',
            \json_encode(['bad' => $bad, 'unknown' => $unknown], \JSON_UNESCAPED_UNICODE) ?: ''
        );

        $this->flushChecks();
    }
}
