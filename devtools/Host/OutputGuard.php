<?php
declare(strict_types=1);

namespace Drift\DevTools\Host;

/**
 * 把"杂散输出"从协议通道上赶走。
 *
 * ## 为什么必须有
 *
 * 宿主进程的 stdout 是协议本身。页面代码里一句遗留的 `echo`、一个没关掉的
 * `var_dump`、一条 PHP 警告（`display_errors=1` 时打到 stdout），都会让客户端读到
 * "消息不是合法 JSON"，而且**报错位置离原因隔了一条管道** —— 看到的是"协议坏了"，
 * 真凶是某个页面的调试语句。
 *
 * 这是所有"用 stdio 说话"的协议（LSP、DAP）都踩过的坑，做法也一致：
 * 把 fd 1 留给协议，其余输出改道 stderr。
 *
 * 两道闸：
 *
 * - **输出缓冲**：`ob_start` 接住 `echo` / `print` / `printf`，转投 stderr 并吞掉。
 *   分块大小取 1，使长驻进程不会把杂散输出攒到退出时才吐。
 * - **错误处理器**：警告 / 提示转投 stderr 并返回 `true`，阻止 PHP 自己的默认输出。
 *
 * `fwrite(STDOUT, …)` **不**经过输出缓冲，所以协议自己的写不受影响 —— 这正是
 * 传输实现坚持用 `fwrite` 而不是 `echo` 的原因。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class OutputGuard
{
    /** @var null|callable(string): void */
    private static $sink = null;

    private static int $level = 0;

    private static bool $active = false;

    /**
     * @param null|callable(string): void $sink 收杂散文本的地方（默认 stderr）
     */
    public static function start(?callable $sink = null): void
    {
        if (self::$active) {
            return;
        }
        self::$active = true;
        self::$sink = $sink;

        self::$level = \ob_get_level();
        \ob_start(static function (string $buffer, int $phase): string {
            // CLEAN 相位（`ob_end_clean` / `ob_clean`）说明这段内容已经被丢弃，
            // 再转一次就等于把同一段输出打两遍。
            if (($phase & \PHP_OUTPUT_HANDLER_CLEAN) === 0) {
                self::emit($buffer);
            }

            return '';
        }, 1);

        \set_error_handler(static function (int $no, string $message, string $file = '', int $line = 0): bool {
            self::emit(\sprintf("PHP 警告[%d]：%s（%s:%d）\n", $no, $message, $file, $line));

            return true;
        });
    }

    /** 还原缓冲与错误处理器。幂等。 */
    public static function stop(): void
    {
        if (!self::$active) {
            return;
        }
        self::$active = false;
        self::$sink = null;

        \restore_error_handler();
        while (\ob_get_level() > self::$level) {
            \ob_end_clean();
        }
    }

    private static function emit(string $text): void
    {
        if ($text === '') {
            return;
        }
        $sink = self::$sink;
        if ($sink !== null) {
            $sink($text);

            return;
        }
        @\fwrite(\STDERR, $text);
    }
}
