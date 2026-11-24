<?php
declare(strict_types=1);

namespace Drift\Dev\Debug;

/**
 * 会话发现文件 `.drift/session.json`：宿主把「怎么连上我」写在这，观察者读它。
 *
 * ## 文件只回答一个问题：怎么连上
 *
 * 六个字段：`id` / `pid` / `port` / `token` 连接与诊断要用，`target` / `startedAt`
 * 给人看。**没有 `state`** —— 活的运行状态只有 `state` 查询一个出口。写进文件就是
 * 两处真相，而文件那份只在启动与退出时更新，必然滞后；多窗口时还得每次状态变化
 * 重写文件（多进程并发写同一个文件）。
 *
 * ## 为什么是数组，即使现在只有一个会话
 *
 * 多窗口之后同一进程会有多个条目。格式变更的代价不在改代码，在「老版本写的文件
 * 新版本读不了」这类兼容尾巴。现在写成数组多花五分钟，那时省一次迁移。
 *
 * ## 路径取当前目录，不向上找项目根
 *
 * 向上找能让「在子目录里 attach」也工作，但它换来一个新问题：`.drift` 可能不在你
 * 看的目录里，报错就必须解释「我找到的是哪个根」。而当前目录的缺点是 attach 走错
 * 目录时找不到 —— 那句话一报，用户 `cd` 一下就好。简单的东西别为罕见的便利变复杂。
 *
 * ## 只有写者清僵尸
 *
 * 宿主被强杀时来不及注销，条目会留在文件里。清理放在 {@see register()} 里顺手做
 * （写者有锁），观察者只读不写 —— 让读者也写文件会引入「两个进程改同一个文件」，
 * 而那正是本该避免的并发面。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class Discovery
{
    /** 发现文件自己的版本，与协议版本无关。 */
    public const PROTOCOL = 1;

    private function __construct(private readonly string $file)
    {
    }

    /** @param string $dir 会话目录（通常是 `getcwd()`） */
    public static function inDirectory(string $dir): self
    {
        return new self(\rtrim($dir, '/\\') . '/.drift/session.json');
    }

    public function path(): string
    {
        return $this->file;
    }

    /**
     * 读出全部条目（含已死的僵尸，调用方自己用 {@see live()} 过滤）。
     *
     * @return list<array{id: string, pid: int, port: int, token: string, target: string, startedAt: string}>
     *
     * @throws \RuntimeException 文件坏了，或是别的版本写的
     */
    public function sessions(): array
    {
        $raw = $this->slurp();
        if ($raw === null || \trim($raw) === '') {
            return [];
        }

        $data = \json_decode($raw, true);
        if (!\is_array($data)) {
            throw new \RuntimeException(
                "发现文件读不懂：{$this->file}\n"
                . '可能上一个宿主被中途杀死。删掉这个文件再试。',
            );
        }

        $protocol = (int) ($data['protocol'] ?? 0);
        if ($protocol !== self::PROTOCOL) {
            throw new \RuntimeException(\sprintf(
                "发现文件是协议 %d 写的，本版本只认 %d：%s\n"
                . '（多半是另一个版本的 drift 留在这。删掉这个文件，或换回那个版本。）',
                $protocol,
                self::PROTOCOL,
                $this->file,
            ));
        }

        $sessions = $data['sessions'] ?? null;

        return \is_array($sessions) ? \array_values(\array_map([self::class, 'normalize'], $sessions)) : [];
    }

    /** @return list<array{id: string, pid: int, port: int, token: string, target: string, startedAt: string}> */
    public function live(): array
    {
        return \array_values(\array_filter(
            $this->sessions(),
            static fn(array $entry): bool => self::alive($entry['pid']) !== false,
        ));
    }

    /**
     * 登记本进程，返回登记 id（注销时用）。
     *
     * 顺手清掉 pid 已经不在了的旧条目 —— 宿主只有被强杀才留得下僵尸。
     *
     * @param int|null $pid 只给测试用；默认就是本进程
     */
    public function register(string $target, string $token, int $port, ?int $pid = null): string
    {
        $id = \bin2hex(\random_bytes(4));
        $entry = [
            'id' => $id,
            'pid' => $pid ?? (int) \getmypid(),
            'port' => $port,
            'token' => $token,
            'target' => $target,
            'startedAt' => \gmdate('c'),
        ];

        $this->mutate(static function (array $sessions) use ($entry): array {
            // `!== false`：判不出来的（null）留着，宁可留一条僵尸也不能删掉活的。
            $kept = \array_filter(
                $sessions,
                static fn(array $e): bool => self::alive($e['pid']) !== false,
            );
            $kept[] = $entry;

            return \array_values($kept);
        });

        return $id;
    }

    /** 注销一条。数组空了就删文件 —— 留着空文件只会让下一个观察者多问一次。 */
    public function unregister(string $id): void
    {
        $this->mutate(static function (array $sessions) use ($id): array {
            return \array_values(\array_filter(
                $sessions,
                static fn(array $e): bool => $e['id'] !== $id,
            ));
        });
    }

    /**
     * 连不上时说什么。比「Connection refused」多一件事：进程还在不在。
     *
     * 三种答案分开报，因为下一步动作不同 —— 已退出要重启，还活着要等或查端口，
     * 判不出来就直说判不出来（编一个理由比不报更糟）。
     *
     * @param array{id: string, pid: int, port: int, token: string, target: string, startedAt: string} $entry
     */
    public function diagnose(array $entry): string
    {
        $alive = self::alive($entry['pid']);
        if ($alive === false) {
            return "宿主已退出（pid {$entry['pid']}）";
        }
        if ($alive === true) {
            return \sprintf(
                '连不上 127.0.0.1:%d，但 pid %d 还在 —— 可能刚起来还没监听，或端口被别的东西占了',
                $entry['port'],
                $entry['pid'],
            );
        }

        return \sprintf('连不上 127.0.0.1:%d（pid %d），没能确认进程是否还在', $entry['port'], $entry['pid']);
    }

    /**
     * pid 还活着吗。判不出来返回 `null` —— 调用方必须区分「死了」与「不知道」。
     *
     * POSIX 用 `posix_kill($pid, 0)`（信号 0 只做存在性检查）；Windows 没有 posix，
     * 退到 `tasklist`。两样都没有时返回 `null`，绝不猜。
     */
    public static function alive(int $pid): ?bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (\function_exists('posix_kill')) {
            if (@\posix_kill($pid, 0)) {
                return true;
            }

            // EPERM(1)：进程在，只是不归我管。其余（ESRCH）才是真没了。
            return \function_exists('posix_get_last_error') && \posix_get_last_error() === 1;
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            return self::aliveOnWindows($pid);
        }

        return null;
    }

    private static function aliveOnWindows(int $pid): ?bool
    {
        $out = [];
        $code = 0;
        @\exec('tasklist /FI "PID eq ' . $pid . '" /NH /FO CSV 2>NUL', $out, $code);
        if ($code !== 0 || $out === []) {
            // 命令本身跑不了（被 disable_functions 挡了、PATH 里没有）。别拿「查不到」
            // 冒充「不存在」—— 那会把活着的宿主报成已退出。
            return null;
        }

        foreach ($out as $line) {
            // escape 显式给空串：PHP 8.4 起不传会告警（默认值要改）。
            $cols = \str_getcsv($line, ',', '"', '');
            if ((int) ($cols[1] ?? 0) === $pid) {
                return true;
            }
        }

        return false;
    }

    /**
     * 加锁读改写。`flock` 而不是「写临时文件再 rename」：rename 在 Windows 上目标
     * 被读者打开时会失败，而 flock 两边都用就不会撞。
     *
     * @param callable(list<array<string, mixed>>): list<array<string, mixed>> $change
     */
    private function mutate(callable $change): void
    {
        $dir = \dirname($this->file);
        if (!\is_dir($dir) && !@\mkdir($dir, 0700, true) && !\is_dir($dir)) {
            throw new \RuntimeException("建不了目录：{$dir}");
        }

        $handle = @\fopen($this->file, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("打不开发现文件：{$this->file}");
        }

        /** @var null|list<array<string, mixed>> $next null = 中途抛了，别碰文件 */
        $next = null;
        try {
            \flock($handle, \LOCK_EX);
            $raw = \stream_get_contents($handle);
            $sessions = [];
            if (\is_string($raw) && \trim($raw) !== '') {
                $data = \json_decode($raw, true);
                // 坏文件在这里不抛：register 是宿主启动路径，为一个陈旧的破文件
                // 起不来太亏。直接当空的重建。
                if (\is_array($data) && (int) ($data['protocol'] ?? 0) === self::PROTOCOL && \is_array($data['sessions'] ?? null)) {
                    $sessions = \array_values(\array_map([self::class, 'normalize'], $data['sessions']));
                }
            }

            $next = \array_values($change($sessions));
            if ($next !== []) {
                $json = \json_encode(
                    ['protocol' => self::PROTOCOL, 'sessions' => $next],
                    \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
                );
                \ftruncate($handle, 0);
                \rewind($handle);
                \fwrite($handle, $json . "\n");
                \fflush($handle);
            }
        } finally {
            // 解锁交给 fclose —— 显式 LOCK_UN 之后再 fclose 也行，但漏一条路径就是死锁。
            @\fclose($handle);
        }

        if ($next === null) {
            return;
        }

        if ($next === []) {
            // 空的时候删文件。删不掉（还被某个读者开着）也无所谓：空数组对读者
            // 与不存在的文件是同一件事。
            @\unlink($this->file);

            return;
        }

        // 含 token，别给同机其他用户看。POSIX 才有意义，Windows 上静默失败。
        @\chmod($this->file, 0600);
    }

    private function slurp(): ?string
    {
        if (!\is_file($this->file)) {
            return null;
        }

        $handle = @\fopen($this->file, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            \flock($handle, \LOCK_SH);
            $raw = \stream_get_contents($handle);
        } finally {
            @\fclose($handle);
        }

        return \is_string($raw) ? $raw : null;
    }

    /**
     * 补齐字段类型。文件是外部输入（可能是人手改的、别的版本写的），别让
     * 缺字段在这里变成下游的 TypeError。
     *
     * @param mixed $entry
     *
     * @return array{id: string, pid: int, port: int, token: string, target: string, startedAt: string}
     */
    private static function normalize($entry): array
    {
        $entry = \is_array($entry) ? $entry : [];

        return [
            'id' => (string) ($entry['id'] ?? ''),
            'pid' => (int) ($entry['pid'] ?? 0),
            'port' => (int) ($entry['port'] ?? 0),
            'token' => (string) ($entry['token'] ?? ''),
            'target' => (string) ($entry['target'] ?? ''),
            'startedAt' => (string) ($entry['startedAt'] ?? ''),
        ];
    }
}
