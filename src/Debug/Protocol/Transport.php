<?php
declare(strict_types=1);

namespace Drift\Debug\Protocol;

/**
 * 一条双向的消息通道：一行进、一行出。
 *
 * 抽象只到这层：管道的两端是子进程的 stdio、loopback socket，还是测试里的一对
 * 内存数组，协议本身不关心。**不**在这一层做重连、缓冲、批量 —— 那些都是
 * "通道会坏"的补偿，而本地管道的坏法只有一种：对端没了（`read()` 返回 `null`）。
 *
 * @api
 */
interface Transport
{
    /**
     * 读一条消息（**不含**行尾）；对端关闭返回 `null`。
     *
     * 实现可以阻塞：调用方是"发一条、等一条"的从属进程，没有别的事可做。
     *
     * @throws \RuntimeException 通道本身坏了（不是"对端正常关闭"）
     */
    public function read(): ?string;

    /** 写一条消息（实现负责补行尾并立即 flush —— 缓冲区里的指令等于没发）。 */
    public function write(string $line): void;

    /** 关通道。幂等；关完之后 `read()` 应为 `null`、`write()` 应抛。 */
    public function close(): void;
}
