<?php
declare(strict_types=1);

namespace Drift;

/**
 * 框架唯一的错误出口（§1.2）。
 *
 * G2「绝不静默」的落地：每个机制要么生效、要么响亮失败。
 * 三级 —— Fatal 在 debug 与 release 都中止；Recoverable 只在 debug 中止、
 * release 降级继续；Diagnostic 只记日志。
 *
 * 中止不是裸 abort()：桌面应用没有控制台，闪退等于另一种静默。
 * 走 §1.2 的三步标准序列（写日志 → 原生错误框 → 固定退出码）。
 */
final class Errors
{
    /** 框架不变式被破坏，继续跑必然得到错误结果 */
    public const FATAL = 3;
    /** 契约被违反但可安全降级 */
    public const RECOVERABLE = 2;
    /** 值得知道，不是错误 */
    public const DIAGNOSTIC = 1;

    /** 环形缓冲容量（§1.2 中止序列 ①） */
    private const RING_SIZE = 100;
    /** 固定退出码，可被启动器 / CI 观测（§1.2 中止序列 ③） */
    private const EXIT_CODE = 3;
    /** 栈深度上限 —— 中止路径不做无界回溯 */
    private const STACK_DEPTH = 16;

    private static bool $debug = true;
    private static string $logPath = '';
    /** @var list<string> 最近 RING_SIZE 条 Diagnostic，按时间正序 */
    private static array $ring = [];
    /** @var null|callable(string, string): void */
    private static $fatalBox = null;
    /** 中止序列本身不可再中止 */
    private static bool $aborting = false;

    private function __construct()
    {
    }

    /**
     * 报告一条错误。
     *
     * ★ 单参调用 = Fatal：与 §15.2 的用法一致，且 G2 要求默认走最响的一档
     *   而不是最静的一档 —— 忘了写级别应当是"太吵"，不是"被吞掉"。
     */
    public static function report(string $message, int $level = self::FATAL): void
    {
        if ($level === self::DIAGNOSTIC) {
            self::$ring[] = $message;
            if (count(self::$ring) > self::RING_SIZE) {
                array_shift(self::$ring);
            }
            return;
        }

        if ($level === self::RECOVERABLE && !self::$debug) {
            // release 降级继续，但必须留痕（否则就是静默）
            self::appendLog('RECOVERABLE', $message);
            return;
        }

        self::abort($level === self::RECOVERABLE ? 'RECOVERABLE' : 'FATAL', $message);
    }

    /**
     * 组合根注入原生错误框（§1.2 配套约束 1）。
     *
     * ★ 收 callable 而不是 Platform 实例：Errors 不依赖 Platform 类型，
     *   "平台名只出现在组合根"（§5.1）才不被破坏。
     */
    public static function installFatalBox(callable $box): void
    {
        self::$fatalBox = $box;
    }

    public static function setDebug(bool $debug): void
    {
        self::$debug = $debug;
    }

    public static function isDebug(): bool
    {
        return self::$debug;
    }

    /** @internal 测试与多实例场景改日志落点 */
    public static function setLogPath(string $path): void
    {
        self::$logPath = $path;
    }

    public static function logPath(): string
    {
        return self::$logPath !== '' ? self::$logPath : getcwd() . '/Errors.log';
    }

    /** @internal devtools / 测试读环形缓冲（时间正序） */
    public static function recentDiagnostics(): array
    {
        return self::$ring;
    }

    /** @internal 测试用：清空状态，避免用例互相污染 */
    public static function reset(): void
    {
        self::$ring = [];
        self::$fatalBox = null;
        self::$aborting = false;
        self::$logPath = '';
    }

    /**
     * §1.2 的三步标准中止序列，顺序不可换。
     */
    private static function abort(string $label, string $message): void
    {
        if (self::$aborting) {
            return;
        }
        self::$aborting = true;

        // ① 写 Errors.log：错误 + 栈 + 最近 100 条 Diagnostic 的环形缓冲
        self::appendLog($label, $message . "\n" . self::stack() . self::ringDump());

        // ② 弹原生错误框 —— 不依赖渲染栈，因为渲染栈可能正是崩掉的那一环。
        //    引导阶段（Platform 未建立）没有 fatalBox，退化到"写日志 + 中止"（§24.3）。
        if (self::$fatalBox !== null) {
            (self::$fatalBox)('Drift ' . $label, $message);
        }

        // ③ 固定退出码
        exit(self::EXIT_CODE);
    }

    private static function stack(): string
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::STACK_DEPTH);
        $lines = [];
        foreach ($frames as $i => $frame) {
            $lines[] = sprintf(
                '  #%d %s%s%s:%s',
                $i,
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'] ?? '',
                $frame['line'] ?? '?'
            );
        }
        return implode("\n", $lines) . "\n";
    }

    private static function ringDump(): string
    {
        if (self::$ring === []) {
            return "  (无 Diagnostic)\n";
        }
        $lines = ['  --- 最近 ' . count(self::$ring) . ' 条 Diagnostic ---'];
        foreach (self::$ring as $entry) {
            $lines[] = '  · ' . $entry;
        }
        return implode("\n", $lines) . "\n";
    }

    private static function appendLog(string $label, string $body): void
    {
        // ★ 单次写入，不做增长累加（§2.4 纪律 2）。
        //   日志写失败不再递归报告 —— 中止序列本身不可再中止。
        file_put_contents(
            self::logPath(),
            sprintf("[%s] %s %s\n", date('Y-m-d H:i:s'), $label, $body),
            FILE_APPEND
        );
    }
}
