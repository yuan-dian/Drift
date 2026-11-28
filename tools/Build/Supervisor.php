<?php
declare(strict_types=1);

namespace Drift\Dev\Build;

/**
 * 子进程监督：实时透传输出、按「停滞」判死、连整棵进程树一起清。
 *
 * ★ 为什么输出走**文件**而不是管道：Windows 的匿名管道设不了非阻塞
 *   （`stream_set_blocking()` 直接返回 false，`stream_get_meta_data()['blocked']` 仍是 true），
 *   于是 `fread()` 会一直阻塞到管道 EOF —— 也就是进程退出那一刻。后果有两层：
 *   构建中一个字都看不到；看门狗循环根本没在转，配 1s 也会等满 30s。
 *   `drift-build` 历史上「11 分钟零输出」「看起来死了其实在编」都是这一条。
 *   文件没有这个问题：谁都能随时读，写到哪读到哪。
 *
 * ★ 为什么判**停滞**而不是总超时：真全量正常就要 200s+，总超时必然误杀；
 *   而卡死的特征是「进程还在、却什么也不再产出」。判据因此是「最后一次进展
 *   距现在多久」，不是「开工距现在多久」。
 * ★ 进展有两个来源，缺一个就会误判：
 *   ① 输出文件变长 —— tpc 正常时会刷 `Compiling [███]`；
 *   ② `$watchDirs` 下文件 mtime / 文件数 —— 收尾阶段 tpc 不打印，只在落盘。
 *   实测卡死时两者同时静默（`.obj` 全在、`cl.exe` 已退、只剩 `php.exe` 涨内存），
 *   所以「两者同时静默」才是死，不是「没有输出」就死。
 * ★ 杀必须 `/T`：tpc 会起 cl.exe / link.exe，只杀父进程留下的孙进程握着 PDB 句柄，
 *   下一次构建在链接阶段等锁 —— 「卡一次之后次次卡」就是这么来的。
 */
final class Supervisor
{
    public const DEFAULT_STALL_SECONDS = 300.0;

    /** 心跳间隔：长时间零输出时，得让人看得出「还活着、最后进展在几秒前」 */
    public const HEARTBEAT_SECONDS = 20.0;

    /** 目录进展的扫描间隔。太密会在几千个 `.obj` 上白烧 CPU，太疏会拖长误判窗口 */
    private const DIR_SCAN_SECONDS = 3.0;

    /** 孤儿兜底只认这些映像名：PID 会被复用，按号杀等于赌命 */
    public const TPC_IMAGES = ['php.exe', 'tpc.exe', 'php-cgi.exe'];

    /** @var resource */
    private $process;

    private bool $exited = false;

    private int $exitCode = -1;

    private int $pidCache = 0;

    /**
     * @param resource $process `proc_open()` 的句柄，**stdout/stderr 必须已重定向到下面两个文件**
     * @param list<string> $watchDirs 也算「有进展」的目录（构建产物落盘处）
     * @param (\Closure(string): void)|null $onOutput 收到新字节时回调（透传到终端）
     * @param (\Closure(float, float): void)|null $onHeartbeat (距最后进展秒, 已耗时秒)
     */
    public function __construct(
        $process,
        private string $outputFile,
        private string $errorFile,
        private float $stallSeconds = self::DEFAULT_STALL_SECONDS,
        private array $watchDirs = [],
        private ?\Closure $onOutput = null,
        private ?\Closure $onHeartbeat = null,
    ) {
        $this->process = $process;
    }

    /**
     * 跑到子进程结束或判停滞。
     *
     * @return array{exit: int, output: string, stalled: bool, elapsed: float, quiet: float}
     */
    public function run(): array
    {
        $startedAt = microtime(true);
        $progressAt = $startedAt;
        $lastBeat = $startedAt;
        $lastScan = $startedAt;
        $outOffset = 0;
        $errOffset = 0;
        $output = '';
        $stalled = false;
        $watched = $this->watchState();

        // ★ 扫描间隔必须跟着阈值缩放：阈值 1s 而 3s 才看一眼目录，中间两秒
        //   必然误判成停滞（判据 ③ 当初就是这么红的）。大阈值下仍取上限，
        //   免得在几千个 `.obj` 上每 50ms 走一遍文件树。
        $scanEvery = $this->stallSeconds > 0
            ? max(0.3, min(self::DIR_SCAN_SECONDS, $this->stallSeconds / 3))
            : self::DIR_SCAN_SECONDS;

        while (true) {
            $now = microtime(true);
            $chunk = $this->readFrom($this->outputFile, $outOffset)
                . $this->readFrom($this->errorFile, $errOffset);
            if ($chunk !== '') {
                $output .= $chunk;
                $progressAt = $now;
                if ($this->onOutput !== null) {
                    ($this->onOutput)($chunk);
                }
            }

            if (!$this->running()) {
                break;
            }

            if ($now - $lastScan >= $scanEvery) {
                $lastScan = $now;
                $state = $this->watchState();
                if ($state !== $watched) {
                    $watched = $state;
                    $progressAt = $now;
                }
            }

            // ★ 先查停滞再判活：进程「活着但没动静」正是要抓的那一类。
            if ($this->stallSeconds > 0 && $now - $progressAt > $this->stallSeconds) {
                $stalled = true;
                self::killTree($this->pid());
                $tail = $this->readFrom($this->outputFile, $outOffset)
                    . $this->readFrom($this->errorFile, $errOffset);
                if ($tail !== '') {
                    $output .= $tail;
                    if ($this->onOutput !== null) {
                        ($this->onOutput)($tail);
                    }
                }
                break;
            }

            if ($this->onHeartbeat !== null && $now - $lastBeat >= self::HEARTBEAT_SECONDS) {
                $lastBeat = $now;
                ($this->onHeartbeat)($now - $progressAt, $now - $startedAt);
            }

            usleep(50000);
        }

        $quiet = microtime(true) - $progressAt;
        $closed = proc_close($this->process);
        $exit = $this->exitCode >= 0 ? $this->exitCode : $closed;

        return [
            'exit' => $stalled ? ($exit === 0 ? 1 : $exit) : $exit,
            'output' => $output,
            'stalled' => $stalled,
            'elapsed' => microtime(true) - $startedAt,
            'quiet' => $quiet,
        ];
    }

    /** 从 `$offset` 起读新增字节；不足一个完整行也照读（进度条靠 `\r` 原地刷新） */
    private function readFrom(string $file, int &$offset): string
    {
        clearstatcache(true, $file);
        $size = @filesize($file);
        if (!is_int($size) || $size <= $offset) {
            return '';
        }
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return '';
        }
        fseek($handle, $offset);
        $chunk = (string) stream_get_contents($handle);
        fclose($handle);
        $offset += strlen($chunk);

        return $chunk;
    }

    private function running(): bool
    {
        if ($this->exited) {
            return false;
        }
        $status = proc_get_status($this->process);
        if ($this->pidCache === 0) {
            $this->pidCache = (int) ($status['pid'] ?? 0);
        }
        if ($status['running']) {
            return true;
        }
        // ★ 只记第一次读到的退出码：之后再用 proc_get_status 会把它读成 -1。
        $this->exited = true;
        $this->exitCode = (int) ($status['exitcode'] ?? -1);

        return false;
    }

    private function pid(): int
    {
        if ($this->pidCache === 0) {
            $this->pidCache = (int) (proc_get_status($this->process)['pid'] ?? 0);
        }

        return $this->pidCache;
    }

    /** 目录进展摘要：「文件数 + 最大 mtime」任一变化都算动过 */
    private function watchState(): string
    {
        $parts = [];
        foreach ($this->watchDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $count = 0;
            $newest = 0;
            try {
                $walk = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($walk as $file) {
                    if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                        continue;
                    }
                    $count++;
                    $mtime = $file->getMTime();
                    if ($mtime > $newest) {
                        $newest = $mtime;
                    }
                }
            } catch (\UnexpectedValueException) {
                // 目录正在被重建：这一轮跳过，下一轮再看
                continue;
            }
            $parts[] = $dir . ':' . $count . ':' . $newest;
        }

        return implode('|', $parts);
    }

    /**
     * 杀掉整棵进程树。
     *
     * ★ Windows 用 `taskkill /T`：`/T` 是唯一能连到 cl.exe / link.exe 的手段。
     * ★ POSIX 没有 /T，按父进程关系逐层清；本仓 Linux 门只编探针，够用。
     */
    public static function killTree(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            exec('taskkill /T /F /PID ' . $pid . ' 2>NUL');

            return;
        }
        exec('pkill -TERM -P ' . $pid . ' 2>/dev/null');
        exec('kill -TERM ' . $pid . ' 2>/dev/null');
        usleep(150000);
        exec('pkill -KILL -P ' . $pid . ' 2>/dev/null');
        exec('kill -KILL ' . $pid . ' 2>/dev/null');
    }

    /**
     * 这个 PID 还活着吗？**按映像名核对**，不只看号在不在。
     *
     * @param list<string> $images
     */
    public static function pidAlive(int $pid, array $images = self::TPC_IMAGES): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $lines = [];
            exec('tasklist /FI "PID eq ' . $pid . '" /NH /FO CSV 2>NUL', $lines);
            foreach ($lines as $line) {
                foreach ($images as $image) {
                    if (stripos($line, '"' . $image . '"') !== false) {
                        return true;
                    }
                }
            }

            return false;
        }

        $comm = @file_get_contents('/proc/' . $pid . '/comm');
        if (!is_string($comm)) {
            return false;
        }
        $name = trim($comm);
        foreach (['php', 'tpc'] as $image) {
            if ($name === $image || str_starts_with($name, $image)) {
                return true;
            }
        }

        return false;
    }

    public static function rememberPid(string $pidFile, int $pid): void
    {
        if ($pid <= 0) {
            return;
        }
        @file_put_contents($pidFile, $pid . "\n");
    }

    public static function forgetPid(string $pidFile): void
    {
        @unlink($pidFile);
    }

    /**
     * 清掉上一次构建留下的孤儿（本进程被 SIGTERM 直接截断时，`shutdown` 钩子跑不到，
     * 只有下次启动时收拾）。
     *
     * @param list<string> $images
     * @return int|null 被清掉的 PID；没有孤儿返回 null
     */
    public static function reclaimStale(string $pidFile, array $images = self::TPC_IMAGES): ?int
    {
        if (!is_file($pidFile)) {
            return null;
        }
        $raw = trim((string) file_get_contents($pidFile));
        self::forgetPid($pidFile);
        if (!ctype_digit($raw)) {
            return null;
        }
        $pid = (int) $raw;
        // ★ 兜底中的兜底：pid 文件被写坏的极端情况不能变成"构建脚本自杀"。
        if ($pid === getmypid()) {
            return null;
        }
        if (!self::pidAlive($pid, $images)) {
            return null;
        }

        self::killTree($pid);
        // 等它真消失：立刻往下走的话，新一轮构建会赶上上一轮还在释放 PDB 句柄
        for ($i = 0; $i < 40; $i++) {
            if (!self::pidAlive($pid, $images)) {
                break;
            }
            usleep(50000);
        }

        return $pid;
    }
}
