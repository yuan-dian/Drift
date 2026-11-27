<?php
declare(strict_types=1);

namespace Drift\DevTools\Protocol;

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

    /**
     * 等一会儿，看有没有数据可读。
     *
     * ## 为什么不给 `read()` 加超时参数
     *
     * 那会逼出一个三态返回值（读到 / 超时 / 对端关闭），而"超时"只能用 `false`
     * 或空串表达——空串不是合法消息（`Wire::decode()` 会把它报成格式错误），
     * `false` 又会和 `null` 抢语义。`Server::wait()` 已经因为同一个理由选了
     * "探测与读分开"，这里沿用。
     *
     * ## 管道上回 `false`，不抛
     *
     * Windows 的匿名管道连 `stream_select` 都不支持（见 {@see \Drift\DevTools\Host\SingleServer}），
     * 所以 `ProcessTransport` / `StdioTransport` 一律回 `false`。调用方据此退化成
     * 轮询或干脆不主动等——**不是**"这个通道坏了"。
     *
     * @param int $timeoutMs `0` 立即返回；`>0` 最多等这么久；`-1` 一直等（与
     *                       {@see Server::wait()} 同一个口径，别在这里发明第二种）
     *
     * @return bool `true` = 接下来 `read()` 不会阻塞
     */
    public function readable(int $timeoutMs): bool;

    /** 写一条消息（实现负责补行尾并立即 flush —— 缓冲区里的指令等于没发）。 */
    public function write(string $line): void;

    /** 关通道。幂等；关完之后 `read()` 应为 `null`、`write()` 应抛。 */
    public function close(): void;
}
