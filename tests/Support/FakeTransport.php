<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Support;

use Drift\DevTools\Protocol\Channel;

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

    /**
     * `readable()` 答不答得出来。`false` = 假装自己是管道（Windows 匿名管道不支持
     * `stream_select`，真实实现一律回 `false`）。
     */
    public bool $canPoll = true;

    /** @param list<string> $pending */
    public function __construct(array $pending = [])
    {
        $this->pending = $pending;
    }

    public function read(): ?string
    {
        return \array_shift($this->pending);
    }

    /**
     * 队列非空就算可读 —— 内存里没有"还没到"这回事，所以这是精确答案，
     * 不是近似。队列里排着一个 `null`（EOF 哨兵）时也算可读：紧接着的
     * `read()` 会立刻交出那个 `null`。
     *
     * `$canPoll = false` 时恒 `false`：管道上问不出"现在可读吗"，
     * 拿它测"管道上退化"的那条判据。
     */
    public function readable(int $timeoutMs): bool
    {
        return $this->canPoll && $this->pending !== [];
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

    /**
     * 已发出的消息全部解成数组（按序，**含事件**）。
     *
     * 要看"客户端到底收到了什么"，这才是真相 —— 事件与响应挤在同一条通道上。
     *
     * @return list<array<string, mixed>>
     */
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

    /**
     * 只取**响应**（带 `id` 的那些），丢掉事件。
     *
     * 大多数判据关心的是"哪条命令得到哪个答复"，而事件是旁路。把它们混在一起
     * 会让每条判据都得先过滤一遍 —— 那种过滤写二十遍就会有一遍忘掉。
     *
     * @return list<array<string, mixed>>
     */
    public function replies(): array
    {
        return \array_values(\array_filter(
            $this->decoded(),
            static fn (array $row): bool => \array_key_exists('id', $row),
        ));
    }

    /**
     * 只取**事件**（带 `event` 的那些）。
     *
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        return \array_values(\array_filter(
            $this->decoded(),
            static fn (array $row): bool => \array_key_exists('event', $row),
        ));
    }
}
