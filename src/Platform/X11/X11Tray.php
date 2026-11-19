<?php
declare(strict_types=1);

namespace Drift\Platform\X11;

use Drift\Platform\Tray;

/**
 * X11 托盘端口：用 yad --notification 承接 StatusIcon 宿主。
 *
 * ★ X11 的"托盘"是宿主协议（旧的 XEmbed / 新的 StatusNotifierItem），不是 Xlib
 *   自带能力。这里把协议交给 yad，与 zenity 对话框同一类工具：进程独立、不占
 *   宿主 X connection，宿主缺失时 install() 明确返回 false，调用方可重试/降级。
 *
 * ★ 单图标是 Tray 端口的既定语义；install() 更新时先移除旧进程，避免两个图标。
 *   remove() 幂等，未装过不是错误。
 *
 * @internal 平台实现；应用只认 Drift\Platform\Tray
 */
final class X11Tray implements Tray
{
    /** 内置图标名 → freedesktop 图标名；不收外部路径（随 exe 走的约定见 Tray 端口） */
    private const array ICONS = [
        'app' => 'application-x-executable',
        'info' => 'dialog-information',
        'warn' => 'dialog-warning',
        'error' => 'dialog-error',
    ];

    /** @var resource|null */
    private $process = null;

    public static function available(): bool
    {
        return self::helper() !== null;
    }

    public function install(string $tooltip, string $icon = 'app'): bool
    {
        $helper = self::helper();
        if ($helper === null) {
            return false;
        }

        $this->remove();
        $argv = [
            $helper,
            '--notification',
            '--image=' . (self::ICONS[$icon] ?? self::ICONS['app']),
            '--text=' . $tooltip,
        ];
        $command = \implode(' ', \array_map('escapeshellarg', $argv));
        $pipes = [];
        $process = @\proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!\is_resource($process)) {
            return false;
        }
        \fclose($pipes[0]);

        // yad 是前台进程；若宿主不存在它会在几十毫秒内退出。立刻回读一次，
        // 把"装上了但图标其实没起来"变成 install() 的 false，不留给调用方猜。
        \usleep(60000);
        $status = \proc_get_status($process);
        if (!$status['running']) {
            \proc_close($process);

            return false;
        }
        $this->process = $process;

        return true;
    }

    public function remove(): void
    {
        if (!\is_resource($this->process)) {
            return;
        }
        $process = $this->process;
        $this->process = null;

        \proc_terminate($process, 15);  // SIGTERM：让助手自己收尾
        for ($i = 0; $i < 25; ++$i) {
            if (!\proc_get_status($process)['running']) {
                break;
            }
            \usleep(20000);
        }
        if (\proc_get_status($process)['running']) {
            \proc_terminate($process, 9);  // 不追杀就留僵尸/孤儿图标
        }
        \proc_close($process);
    }

    private static function helper(): ?string
    {
        $override = \getenv('DRIFT_X11_TRAY_BIN');
        if (\is_string($override) && $override !== '' && \is_executable($override)) {
            return $override;
        }
        $path = \getenv('PATH');
        if (!\is_string($path) || $path === '') {
            return null;
        }
        foreach (\explode(\PATH_SEPARATOR, $path) as $dir) {
            $candidate = \rtrim($dir, '/\\') . \DIRECTORY_SEPARATOR . 'yad';
            if (@\is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
