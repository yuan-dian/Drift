<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Support;

use Drift\Debug\Protocol\Channel;

/**
 * 内存里的一条通道：读队列 + 写记录。
 *
 * 用来把"协议循环"从"进程与管道"里拆出来测：宿主的行为（错误信封、方法分派、
 * 状态机）与"消息是怎么送到的"无关，混在一起测时，一次失败根本分不清是协议错
 * 还是平台错。
 *
 * `read()` 在队列耗尽时返回 `null` —— 与真实管道的 EOF 同义，宿主据此退出。
 */
final class FakeTransport implements Channel
{
    /** @var list<string> 写出去的消息（按序） */
    public array $sent = [];

    /** @var list<string|null> 待读的消息 */
    public array $pending = [];

    public bool $closed = false;

    /** @var null|callable(string): void 每写一条就回调（模拟对端应答） */
    public $onWrite = null;

    /** @param list<string> $pending */
    public function __construct(array $pending = [])
    {
        $this->pending = $pending;
    }

    public function read(): ?string
    {
        return \array_shift($this->pending);
    }

    public function write(string $line): void
    {
        $this->sent[] = $line;
        $onWrite = $this->onWrite;
        if ($onWrite !== null) {
            $onWrite($line);
        }
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function peer(): string
    {
        return 'fake';
    }

    /** @return list<array<string, mixed>> 已发出的消息解成数组 */
    public function decoded(): array
    {
        $out = [];
        foreach ($this->sent as $line) {
            /** @var array<string, mixed> $row */
            $row = \json_decode($line, true);
            $out[] = $row;
        }

        return $out;
    }
}
