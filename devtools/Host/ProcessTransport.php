<?php
declare(strict_types=1);

namespace Drift\DevTools\Host;

use Drift\DevTools\Protocol\Transport;

/**
 * 起一个子进程，把它的 stdin / stdout 当成通道。
 *
 * ## 为什么用数组命令而不是字符串
 *
 * 字符串命令行要过 shell：路径里有空格、参数里有引号，就得自己拼转义，而 Windows
 * 与 POSIX 的转义规则**不同**。数组形式直接交给 `proc_open`（配 `bypass_shell`），
 * 没有拼接这一步，也就没有"在这台机器上能跑"这类问题。
 *
 * ## stderr 直通
 *
 * 宿主的 stderr 不是协议的一部分（协议只用 stdout），所以直接接到本进程的 stderr：
 * 宿主里的 PHP 警告、页面抛的异常、`OutputGuard` 拦下的杂散输出都会原样出现在
 * 调用方的终端上。改成管道就得有人**持续**读，否则 64KB 之后宿主写 stderr 会阻塞
 * 到死 —— 那是"为了看见错误而引入的卡死"。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class ProcessTransport implements Transport
{
    /** @var array<int, resource> */
    private array $pipes;

    /** @var resource|null */
    private $process;

    private bool $closed = false;

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function __construct($process, array $pipes)
    {
        $this->process = $process;
        $this->pipes = $pipes;
    }

    /**
     * @param list<string> $command argv 形状：`[PHP_BINARY, 'devtools/bin/debug-host.php']`
     *
     * @throws \RuntimeException 进程起不来
     */
    public static function spawn(array $command, ?string $cwd = null): self
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => \STDERR,
        ];
        $pipes = [];
        $process = \proc_open($command, $descriptors, $pipes, $cwd, null, ['bypass_shell' => true]);
        if (!\is_resource($process)) {
            throw new \RuntimeException('起不来宿主进程：' . \implode(' ', $command));
        }

        return new self($process, $pipes);
    }

    public function read(): ?string
    {
        if ($this->closed) {
            return null;
        }

        $pipe = $this->pipes[1] ?? null;
        if ($pipe === null || !\is_resource($pipe)) {
            return null;
        }

        while (true) {
            $line = \fgets($pipe);
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
            throw new \RuntimeException('宿主进程的输入已关闭');
        }
        $pipe = $this->pipes[0] ?? null;
        if ($pipe === null || !\is_resource($pipe)) {
            throw new \RuntimeException('宿主进程的输入已关闭');
        }
        if (\fwrite($pipe, $line . "\n") === false) {
            throw new \RuntimeException('写宿主进程失败：它可能已经退出');
        }
        \fflush($pipe);
    }

    /**
     * 先关输入（宿主读到 EOF 会自己收尾退出），再等它退出。
     *
     * 不 `proc_terminate`：宿主正在写的最后一帧报告可能还没到。等退出码是
     * "确认它干净地结束了"的唯一办法；等不到就是宿主卡住了，那种卡住必须在
     * 调用方可见的地方发生，而不是被 `terminate` 掩盖成一次正常关闭。
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        if (isset($this->pipes[0]) && \is_resource($this->pipes[0])) {
            \fclose($this->pipes[0]);
        }
        if ($this->process !== null) {
            \proc_close($this->process);
            $this->process = null;
        }
        if (isset($this->pipes[1]) && \is_resource($this->pipes[1])) {
            \fclose($this->pipes[1]);
        }
        $this->pipes = [];
    }
}
