<?php
declare(strict_types=1);

namespace Drift;

use Drift\Logging\Log;
use Drift\Logging\PatternFormatter;

/**
 * 框架唯一的错误出口（§1.2）。
 *
 * G2「绝不静默」的落地：每个机制要么生效、要么响亮失败。
 * 三级 —— Fatal 在 debug 与 release 都中止；Recoverable 只在 debug 中止、
 * release 降级继续；Diagnostic 只记日志。
 *
 * 中止不是裸 abort()：桌面应用没有控制台，闪退等于另一种静默。
 * 走 §1.2 的三步标准序列（写日志 → 原生错误框 → 固定退出码）。
 *
 * ★ **为什么是 `@api`（2026-10-15 补标，2026-11-20 改接线）**：§1.2 的配套约束 1
 *   要求在别的一切之前装 fatal box；现在由平台 `hooks()` 端口
 *   （`src/Platform/<os>/*PlatformHooks`）调本方法，`WindowSet` 构造时触发、
 *   `run()` 的 finally 统一卸，组合根不再直接调。不标它，平台钩子那段样板就是非法的
 *   （lint 的 `internal-leak` 正是在 P0 落地时抓出来的）。
 *
 * ★ 公共面按**调用者**分三档，别混用：
 *   · **业务代码**（`apps/<app>/src`）：只用 `report()` —— 报告一条错误。
 *   · **平台钩子**（`src/Platform/<os>/*PlatformHooks`）：只调 `installFatalBox()` —— §1.2 第 ② 步。
 *   · **工具与自检**（`tools/`、测试）：`setDebug` / `setLogPath` / `logPath` /
 *     `recentDiagnostics` / `reset` —— 它们存在的理由是"让错误出口可被观测"，
 *     业务代码调它们只会让日志路径变成两个来源。
 *
 * @api
 */
final class Errors
{
    /** 框架不变式被破坏，继续跑必然得到错误结果 */
    public const int FATAL = 3;
    /** 契约被违反但可安全降级 */
    public const int RECOVERABLE = 2;
    /** 值得知道，不是错误 */
    public const int DIAGNOSTIC = 1;

    /** 环形缓冲容量（§1.2 中止序列 ①） */
    private const int RING_SIZE = 100;
    /** 固定退出码，可被启动器 / CI 观测（§1.2 中止序列 ③） */
    private const int EXIT_CODE = 3;
    /** 栈深度上限 —— 中止路径不做无界回溯 */
    private const int STACK_DEPTH = 16;

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
     * 异常的紧凑描述 `消息（文件:行）`，供各处错误上报拼进自己的前缀。
     *
     * ★ 只收这一截后缀：前缀文案与级别是调用方的语义，不在这里决定。
     *   文件名用 `basename` 而非全路径 —— 日志里全路径既长又泄露部署目录。
     */
    public static function describe(\Throwable $e): string
    {
        return sprintf('%s（%s:%d）', $e->getMessage(), basename($e->getFile()), $e->getLine());
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
            // ★ 桥 ①：**同时**落一份到 `Log`（WARN 级，带时间/级别/分类）。
            //   `self::$ring` 这边**一字不动** —— 它是测试契约（78 处断言
            //   "没有 diagnostic"），改了会一次搞红一大片。
            Log::get('framework')->warn($message);
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
     * 组合根注入原生错误框（§1.2 配套约束 1）。**传 `null` = 卸下**。
     *
     * ★ 收 callable 而不是 Platform 实例：Errors 不依赖 Platform 类型，
     *   "平台名只出现在组合根"（§5.1）才不被破坏。
     *
     * ★★ **退出前必须卸下**（2026-10-30，真机取证）：注入的闭包几乎必然捕获
     *   `$platform`（错误框要用它），而这里是**静态属性** ⇒ 整个 `Win32Platform`
     *   （含渲染表面、窗口句柄、对话框端口）会活到 PHP 的 shutdown 才被释放。
     *   AOT 产物在那时解引用已被销毁的类元数据 ⇒ `php8ts.dll+0x24b30` 访问冲突
     *   （进程已经关完窗、状态也写完了，最后一步崩；每次关窗都可能弹 WER）。
     *   实测：只卸这一个 ⇒ 单窗关窗 8 次里 6 次崩；与 `ResizeHookState::$pump`
     *   一起卸 ⇒ **0 次**。所以"关窗后偶发崩溃"不是玄学，是对象活得太久。
     *
     * @api 组合根的两处注入点之一（另一处是 `ResizeHookState::$pump`）
     */
    public static function installFatalBox(?callable $box): void
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
        //   （外加 `Log` 的全级别环 —— 桥 ②，见 `logDump()`）
        self::appendLog($label, $message . "\n" . self::stack() . self::ringDump() . self::logDump());

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

    /**
     * 桥 ②：`Log` 的全级别环（带时间/级别/分类）也进中止报告。
     *
     * ★ 用 `PatternFormatter` 而不是自己拼：格式随 sink 的配置走，
     *   这里只保证"dump 里看得见最近发生了什么"。
     * ★ 独立于 `ringDump()` —— 两个环语义不同（见 `12` §4.6），
     *   合并会让"没有 Diagnostic"与"什么都没记"混成一种。
     */
    private static function logDump(): string
    {
        $recent = Log::recent();
        if ($recent === []) {
            return "  (无 Log)\n";
        }
        $fmt = PatternFormatter::compile('  · %T %l %c: %m %f');
        $lines = ['  --- 最近 ' . count($recent) . ' 条 Log ---'];
        foreach ($recent as $entry) {
            $lines[] = $fmt->format($entry);
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
