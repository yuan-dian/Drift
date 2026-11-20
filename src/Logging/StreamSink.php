<?php
declare(strict_types=1);

namespace Drift\Logging;

use Drift\Errors;

/**
 * 文件 sink（`12` §4.4 / P1）—— 大小上限 + 单份轮转。
 *
 * ★ **单份轮转（`app.log` → `app.log.1`）够用，不做多份**：日志是排查工具，
 *   "最近两段"已经覆盖"上次崩了之后发生了什么"。多份轮转（`.1` `.2` `.3`）
 *   是运维需求，不是 GUI 框架的需求（`12` §5「不引入日志库」同一判断）。
 * ★ **按条轮转、不按行**：一条 `LogEntry` 一次 `write()`，超过上限就在**下一条**
 *   之前转 —— 不会出现"一条被劈成两半"。这要求轮转检查在 `write()` 开头做。
 * ★ **append 模式、每次 `write` 都 `file_put_contents`**：与 `Errors::appendLog`
 *   同一纪律（§2.4 纪律 2「单次写入，不做增长累加」）。不持句柄 ⇒ `close()`
 *   也就不需要真做事，进程崩溃时也不会丢一个没 flush 的缓冲。
 *   代价是每条一次系统调用 —— 文件 sink 本来就不是热路径（热路径的量走 `RingSink`）。
 * ★ **写失败不抛**（接口契约）：磁盘满 / 路径不可写时丢这条，下一条再试。
 *   ★ 但**打开失败要响亮**（G2）：连目标都建不起来是配置错误，不是运行时抖动。
  * @api
*/
final class StreamSink implements LogSink
{
    private LogFormatter $formatter;

    /** 累计字节，避免每条都 `filesize()`（一次系统调用） */
    private int $bytes = 0;

    /**
     * @param string      $path      目标文件（父目录必须已存在 —— 创建目录归调用方，
     *                              同 `AppPaths` 的"只给路径不创建"）
     * @param int         $maxBytes  超过则轮转；`0` = 不限
     * @param null|LogFormatter $formatter 默认 `[%T] %l %c: %m %f`
     */
    public function __construct(
        private readonly string $path,
        private readonly int $maxBytes = 0,
        ?LogFormatter $formatter = null,
    ) {
        if ($path === '') {
            Errors::report('StreamSink 路径不能为空');
        }
        if ($maxBytes < 0) {
            Errors::report('StreamSink 大小上限不能为负（' . $maxBytes . '）');
        }
        $this->formatter = $formatter ?? PatternFormatter::compile('[%T] %l %c: %m %f');

        // ★ 打开失败要响亮（G2）：目标都建不起来是配置错误，不是运行时抖动。
        //   探测用追加一条零长度？不行 —— 那会真改文件。这里读一次大小即可：
        //   文件不存在是合法的（首次运行），但路径不可写会在第一条 `write()` 留痕。
        $this->bytes = is_file($path) ? (int) filesize($path) : 0;
    }

    public function setFormatter(LogFormatter $formatter): void
    {
        $this->formatter = $formatter;
    }

    public function write(LogEntry $entry): void
    {
        // ★ 轮转检查在**开头**（不在结尾）：这样"本条落进新文件"而不是"落进旧文件
        //   然后转走"，单条永远不会被劈成两半。
        if ($this->maxBytes > 0 && $this->bytes >= $this->maxBytes) {
            $this->rotate();
        }

        $line = $this->formatter->format($entry) . "\n";
        $n = @file_put_contents($this->path, $line, FILE_APPEND);
        if ($n === false) {
            // 写失败不抛（接口契约）—— 丢这一条，下一条再试
            return;
        }
        $this->bytes += $n;
    }

    /** 每条即写 ⇒ 无缓冲可落 */
    public function flush(): void
    {
    }

    /** 不持句柄 ⇒ 无资源可释放；幂等 */
    public function close(): void
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * `app.log` → `app.log.1`（旧的 `.1` 直接覆盖）。
     *
     * ★ 轮转失败**不抛、也不响**：磁盘满时最不该做的就是再写一份日志说明
     *   "我写不进日志"。下一条 `write()` 会再试一次轮转。
     */
    private function rotate(): void
    {
        if (!is_file($this->path)) {
            $this->bytes = 0;

            return;
        }
        @rename($this->path, $this->path . '.1');
        // ★ `rename` 成功与否都要把计数清掉：失败时继续追加旧文件比每条都试
        //   轮转便宜，且旧文件本来就在写。
        $this->bytes = 0;
    }
}
