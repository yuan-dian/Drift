<?php
declare(strict_types=1);

namespace Drift\Dev\Debug;

use Drift\DevTools\Protocol\Channel;

/**
 * 走进程自己的标准输入输出。宿主进程用的就是它。
 *
 * ## 空行被跳过
 *
 * 手敲 `nc` 时多按一个回车、管道的最后一行多一个换行，都不该变成"格式错误"。
 * 一个真正的空消息没有意义（消息顶层必须是对象），所以跳过是安全的。
 *
 * ## 写一行就 flush
 *
 * 没有 `fflush` 时，PHP 的解释器缓冲会把"已经发出去"变成"还在手里"，
 * 客户端等到超时。这不是理论问题：管道两端互相等对方先说话时，谁都不动。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class StdioTransport implements Channel
{
    /** @var resource */
    private $in;

    /** @var resource */
    private $out;

    private bool $closed = false;

    /**
     * @param resource $in
     * @param resource $out
     */
    public function __construct($in, $out)
    {
        $this->in = $in;
        $this->out = $out;
    }

    public static function standard(): self
    {
        return new self(\STDIN, \STDOUT);
    }

    public function read(): ?string
    {
        if ($this->closed) {
            return null;
        }

        while (true) {
            $line = \fgets($this->in);
            if ($line === false) {
                return null;
            }
            $line = \rtrim($line, "\r\n");
            if ($line !== '') {
                return $line;
            }
        }
    }

    public function write(string $line): void
    {
        if ($this->closed) {
            throw new \RuntimeException('标准输出已关闭');
        }
        if (\fwrite($this->out, $line . "\n") === false) {
            throw new \RuntimeException('写标准输出失败');
        }
        \fflush($this->out);
    }

    public function close(): void
    {
        $this->closed = true;
        if (\is_resource($this->out)) {
            \fflush($this->out);
        }
    }

    /** 管道对端就是起我的那个进程：它没有"地址"，只有父子关系。 */
    public function peer(): string
    {
        return 'stdio';
    }
}
