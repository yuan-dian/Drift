<?php
declare(strict_types=1);

namespace Drift\Platform\X11;

use Drift\Platform\Dialog;

/**
 * X11 对话框端口：把原生模态交给 zenity / yad 独立进程。
 *
 * ★ 为什么不写进 C++ 桥：X11 的原生对话框不是 Xlib 调用，而是桌面工具包行为。
 *   GTK/Qt 自己跑内层循环，进程隔离天然避开"宿主消息泵被模态卡死"；工具不在
 *   机器上时 `X11Platform::dialog()` 返回 null，调用方按壳服务缺失处理。
 *
 * ★ 模态时间照 Win32 口径入账：PHP 这边整段阻塞都记进 blockedMs，读后即清。
 *   不清会把同一段模态反复从卡顿里扣掉，把真卡顿也洗绿。
 *
 * ★ 所有参数走 argv + escapeshellarg，不拼进 shell 表达式：标题/筛选名是用户
 *   文案，里面出现引号或分号不该变成命令注入。
 *
 * @internal 平台实现；应用只认 Drift\Platform\Dialog
 */
final class X11Dialog implements Dialog
{
    private const int DEFAULT_TIMEOUT_MS = 0;   // 0 = 等用户，真对话框不该被硬超时打断

    private float $blockedMs = 0.0;

    /** 机器上有可用 GUI 助手才返回真实现；纯 PHP 单测 / 裸服务器返回 null。 */
    public static function available(): bool
    {
        return self::helper() !== null;
    }

    /** @internal 只归 X11Platform::consumeModalBlockedMs() 汇总 */
    public function consumeModalBlockedMs(): float
    {
        $blocked = $this->blockedMs;
        $this->blockedMs = 0.0;

        return $blocked;
    }

    public function openFile(string $title, array $filters = []): ?string
    {
        $out = $this->modal(fn (): string => $this->runFileSelection($title, $filters, false, ''));
        $path = self::normalizeSinglePath($out);

        return $path === '' ? null : $path;
    }

    public function openFiles(string $title, array $filters = []): array
    {
        $joined = $this->modal(fn (): string => $this->runFileSelection($title, $filters, true, ''));

        return self::splitPathList($joined);
    }

    public function saveFile(string $title, string $defaultName = '', array $filters = []): ?string
    {
        $args = self::baseFileArgs($title, $filters);
        $args[] = '--save';
        $args[] = '--confirm-overwrite';
        if ($defaultName !== '') {
            $args[] = '--filename=' . $defaultName;
        }
        $out = $this->modal(fn (): string => $this->run($args)['out']);
        $path = self::normalizeSinglePath($out);

        return $path === '' ? null : $path;
    }

    public function alert(string $title, string $body): void
    {
        $this->modal(function () use ($title, $body): void {
            $this->run(['--info', '--title=' . $title, '--text=' . $body]);
        });
    }

    public function confirm(string $title, string $body): bool
    {
        return $this->modal(fn (): bool => $this->run([
            '--question', '--title=' . $title, '--text=' . $body,
        ])['code'] === 0);
    }

    /** 纯函数：把一次模态调用包进计时里（与 Win32Dialog 同口径）。 */
    private function modal(callable $call): mixed
    {
        $startedNs = \hrtime(true);
        try {
            return $call();
        } finally {
            $this->blockedMs += (float) (\hrtime(true) - $startedNs) / 1e6;
        }
    }

    /** @param list<array{string, string}> $filters */
    private function runFileSelection(string $title, array $filters, bool $multiple, string $default): string
    {
        $args = self::baseFileArgs($title, $filters);
        if ($multiple) {
            $args[] = '--multiple';
            // zenity / yad 默认用 '|' 拼接多选结果，和 Win32Dialog 的分隔口径不一致。
            // 这里传真实换行给助手，PHP 侧只需按行拆，不需要猜路径里有没有 '|'。
            $args[] = "--separator=\n";
        }
        if ($default !== '') {
            $args[] = '--filename=' . $default;
        }

        return $this->run($args)['out'];
    }

    /**
     * 筛选表 → zenity / yad 的 `--file-filter=` 参数。
     *
     * ★ 与 Win32 一样保留"显示名 \t 通配符"的可读中间形态，便于单测；但这里
     *   不拼接双 NUL，助手吃的是普通 argv。
     *
     * @param list<array{string, string}> $filters
     */
    public static function filterText(array $filters): string
    {
        $lines = [];
        foreach ($filters as $filter) {
            if (!\is_array($filter) || \count($filter) < 2) {
                continue;
            }
            $label = (string) ($filter[0] ?? '');
            $pattern = (string) ($filter[1] ?? '');
            if ($label === '' || $pattern === '') {
                continue;
            }
            $lines[] = $label . "\t" . $pattern;
        }
        if ($lines === []) {
            $lines[] = '所有文件 (*.*)' . "\t" . '*.*';
        }

        return \implode("\n", $lines);
    }

    /**
     * zenity / yad 的筛选参数。纯函数，避免把用户文案塞进 shell。
     *
     * @return list<string>
     */
    public static function filterArgs(string $filterText): array
    {
        $args = [];
        foreach (\explode("\n", $filterText) as $line) {
            $parts = \explode("\t", $line, 2);
            if (\count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                continue;
            }
            // 用户已经写了 "Markdown (*.md)" 就不再补第二遍通配符。
            $label = \str_contains($parts[0], '(') ? $parts[0] : $parts[0] . ' (' . $parts[1] . ')';
            $args[] = '--file-filter=' . $label;
        }

        return $args;
    }

    /**
     * 助手的单选输出 → 单一路径。
     *
     * ★ zenity / yad 都会在路径后补一个换行；不剥掉会把 "/tmp/a.txt\n" 交给
     *   调用方，肉眼看着一样，严格比较/文件 API 全断。只剥行尾，不用 trim()：
     *   路径本身的首尾空格是合法字符。
     */
    public static function normalizeSinglePath(string $out): string
    {
        return \rtrim($out, "\r\n");
    }

    /** 助手输出的 `\n` 连接串 → 路径表；不去重、不排序，顺序即用户选择顺序。 */
    public static function splitPathList(string $joined): array
    {
        if ($joined === '') {
            return [];
        }
        $paths = [];
        foreach (\explode("\n", $joined) as $line) {
            $path = \rtrim($line, "\r");
            if ($path === '') {
                continue;
            }
            $paths[] = $path;
        }

        return $paths;
    }

    /** @param list<array{string, string}> $filters @return list<string> */
    private static function baseFileArgs(string $title, array $filters): array
    {
        $helper = self::helper();
        if ($helper === null) {
            return [];   // available() 已挡住；留空不会被调用到
        }
        $args = ['--file-selection', '--title=' . $title];
        foreach (self::filterArgs(self::filterText($filters)) as $filterArg) {
            $args[] = $filterArg;
        }

        return $args;
    }

    /**
     * 跑一次外部对话框，返回 ['code' => int, 'out' => string, 'err' => string]。
     *
     * ★ 默认无超时：真实用户可能停留很久，硬超时会把正常操作变"取消"。
     *   probe 通过 DRIFT_X11_DIALOG_TIMEOUT_MS 上界，避免 CI 卡在假助手上。
     *
     * @param list<string> $args
     * @return array{code:int, out:string, err:string}
     */
    private function run(array $args): array
    {
        $helper = self::helper();
        if ($helper === null || $args === []) {
            return ['code' => 1, 'out' => '', 'err' => 'no dialog helper'];
        }

        $argv = [$helper, ...$args];
        $command = \implode(' ', \array_map('escapeshellarg', $argv));
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = @\proc_open($command, $descriptors, $pipes);
        if (!\is_resource($process)) {
            return ['code' => 1, 'out' => '', 'err' => 'proc_open failed'];
        }
        \fclose($pipes[0]);

        $timeoutMs = (int) (\getenv('DRIFT_X11_DIALOG_TIMEOUT_MS') ?: self::DEFAULT_TIMEOUT_MS);
        $deadline = $timeoutMs > 0 ? \microtime(true) + $timeoutMs / 1000.0 : null;
        $status = \proc_get_status($process);
        while ($status['running']) {
            if ($deadline !== null && \microtime(true) >= $deadline) {
                \proc_terminate($process, 9);
                break;
            }
            \usleep(20000);
            $status = \proc_get_status($process);
        }

        $out = \stream_get_contents($pipes[1]);
        $err = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $closeCode = \proc_close($process);
        $code = $status['exitcode'] >= 0 ? (int) $status['exitcode'] : (int) $closeCode;

        return [
            'code' => $code,
            'out' => \is_string($out) ? $out : '',
            'err' => \is_string($err) ? $err : '',
        ];
    }

    /** 显式路径优先给 probe 注入假助手；否则按 GTK 生态常见顺序探测。 */
    private static function helper(): ?string
    {
        $override = \getenv('DRIFT_X11_DIALOG_BIN');
        if (\is_string($override) && $override !== '' && \is_executable($override)) {
            return $override;
        }
        foreach (['zenity', 'yad'] as $name) {
            $found = self::which($name);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private static function which(string $name): ?string
    {
        $path = \getenv('PATH');
        if (!\is_string($path) || $path === '') {
            return null;
        }
        foreach (\explode(\PATH_SEPARATOR, $path) as $dir) {
            $candidate = \rtrim($dir, '/\\') . \DIRECTORY_SEPARATOR . $name;
            if (@\is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
