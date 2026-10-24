<?php
declare(strict_types=1);

namespace Drift\Logging;

use Drift\Errors;

/**
 * 轨迹记录器（`12`）—— 与 `Errors` **分工不同**：
 *
 *   `Errors` = 判决：不变式被破坏了？⇒ 中止 / 降级 / 留痕（G2，永不静默）
 *   `Log`    = 轨迹：发生了什么？⇒ 只写，**永不中止、永不抛**
 *
 * ★ 这条分工是整个设计的锚。把五级塞进 `Errors` 会毁掉"默认 Fatal"这条安全网，
 *   也会让"判决"和"记录"共用一个类（`12` §5 明确不做）。
 *
 * ★ **门槛只做一次比较**（§4.5）：级别比较 + 分类覆盖查表，都通过了才付
 *   构造 `LogEntry` 的成本（含 `wallMs`）。未达门槛连 `hrtime` 都不取。
 * ★ **写失败也不许抛**：`Log` 在每处 sink 调用上 catch —— 日志系统自己崩掉
 *   是最难查的一种故障，宁可丢一条。
 * ★ **环是 `Log` 自己的**（2000 条），与 `Errors::$ring`（100 条 Diagnostic）
 *   **并存**：后者是测试契约（78 处断言），见 `12` §4.6。
 *
 * @api
 */
final class Log
{
    /** 最啰嗦，release 默认关 */
    public const DEBUG = 1;
    /** 业务常规轨迹 */
    public const INFO = 2;
    /** 不该发生但没崩 */
    public const WARN = 3;
    /** 出错了但还在跑 */
    public const ERROR = 4;
    /** 永远开启 —— 学 Qt：fatal 级别不受任何门槛影响 */
    public const FATAL = 5;

    /** 环形缓冲容量（`12` §4.4） */
    public const RING_CAPACITY = 2000;

    /** 启动点（`%t` 的零点），一次性捕获 */
    private static ?int $startNs = null;

    private static bool $enabled = true;
    /** 全局门槛（不含 FATAL，它永远过） */
    private static int $threshold = self::INFO;
    /** @var array<string, int> 分类点名覆盖门槛 */
    private static array $categoryThresholds = [];
    /** @var list<LogSink> */
    private static array $sinks = [];
    /** @var array<string, CategoryLogger> */
    private static array $loggers = [];
    private static int $seq = 0;

    private function __construct()
    {
    }

    /**
     * 取一个分类的句柄。同名返回同一实例（纯转发，无状态）。
     */
    public static function get(string $category): CategoryLogger
    {
        return self::$loggers[$category] ??= new CategoryLogger($category);
    }

    // ── 门槛与开关 ──────────────────────────────────────────────

    public static function setThreshold(int $level): void
    {
        self::$threshold = $level;
    }

    /**
     * 单个分类点名覆盖门槛（Qt `QT_LOGGING_RULES` 的形状）。
     *
     * 例：`setCategoryThreshold('layout', Log::DEBUG)` —— 只开 layout 的 debug。
     */
    public static function setCategoryThreshold(string $category, int $level): void
    {
        self::$categoryThresholds[$category] = $level;
    }

    /** 清掉所有分类点名覆盖 */
    public static function clearCategoryThresholds(): void
    {
        self::$categoryThresholds = [];
    }

    /** 全关（测试 / release 收口）—— 关掉后零写入，连 entry 都不构造 */
    public static function disable(): void
    {
        self::$enabled = false;
    }

    public static function enable(): void
    {
        self::$enabled = true;
    }

    // ── sink ────────────────────────────────────────────────────

    public static function addSink(LogSink $sink): void
    {
        self::$sinks[] = $sink;
    }

    /** 移除全部 sink（测试隔离） */
    public static function clearSinks(): void
    {
        self::$sinks = [];
    }

    /** @return list<LogSink> */
    public static function sinks(): array
    {
        return self::$sinks;
    }

    /**
     * 收口：让所有 sink 落盘 / 送出仍在缓冲里的部分。
     *
     * ★ **进程退出路径**（组合根的 `atexit` / 析构）调这个，而不是逐个 sink。
     * ★ 顺序按注册序 —— 与 `emit()` 一致，避免"落盘顺序与产生顺序不一致"。
     * ★ 逐个 catch：一个 sink 收不了口不影响其他（同 `emit()` 的判断）。
     */
    public static function flush(): void
    {
        foreach (self::$sinks as $sink) {
            try {
                $sink->flush();
            } catch (\Throwable) {
                // 收口失败不得让业务挂掉（§4.5）
            }
        }
    }

    /**
     * 释放全部 sink 资源（关文件句柄等）。**幂等** —— 退出路径可能走多次。
     *
     * ★ 不清 `self::$sinks`：`close()` 之后再写是调用方的错（该响），
     *   而"悄悄少了一个 sink"是静默降级（G2 禁止）。
     */
    public static function close(): void
    {
        foreach (self::$sinks as $sink) {
            try {
                $sink->close();
            } catch (\Throwable) {
                // 同上
            }
        }
    }

    // ── 读回（devtools / 测试）──────────────────────────────────

    /**
     * 最近 N 条（时间正序）。**返回结构不是字符串** —— 读方自己决定怎么渲染。
     *
     * @return list<LogEntry>
     */
    public static function recent(): array
    {
        foreach (self::$sinks as $sink) {
            if ($sink instanceof RingSink) {
                return $sink->recent();
            }
        }

        return [];
    }

    // ── 内部 ────────────────────────────────────────────────────

    /**
     * @internal 由 `CategoryLogger` 转发。**绝不抛**。
     * @param array<string, scalar> $fields
     */
    public static function emit(int $level, string $category, string $message, array $fields = []): void
    {
        if (!self::$enabled) {
            return;
        }
        // 门槛一次比较（§4.5）：FATAL 永远过（学 Qt）
        if ($level < self::FATAL) {
            $floor = self::$categoryThresholds[$category] ?? self::$threshold;
            if ($level < $floor) {
                return;
            }
        }
        if (self::$sinks === []) {
            return;
        }

        self::$seq++;
        $entry = new LogEntry(
            self::$seq,
            $level,
            $category,
            $message,
            $fields,
            hrtime(true),
            self::nowWallMs(),
        );

        foreach (self::$sinks as $sink) {
            try {
                $sink->write($entry);
            } catch (\Throwable) {
                // 日志失败不得让业务挂掉（§4.5）—— 吞掉，但下一条仍会试
            }
        }
    }

    /** 启动点纳秒（`%t` 的零点） */
    public static function startNs(): int
    {
        return self::$startNs ??= hrtime(true);
    }

    /**
     * 级别常量 ⇒ 名字。`%l` 用。
     */
    public static function levelName(int $level): string
    {
        switch ($level) {
            case self::DEBUG:
                return 'DEBUG';
            case self::INFO:
                return 'INFO';
            case self::WARN:
                return 'WARN';
            case self::ERROR:
                return 'ERROR';
            case self::FATAL:
                return 'FATAL';
        }

        return 'L' . $level;
    }

    /** @internal 测试用：清空状态，避免用例互相污染 */
    public static function reset(): void
    {
        self::$enabled = true;
        self::$threshold = self::INFO;
        self::$categoryThresholds = [];
        self::$sinks = [];
        self::$loggers = [];
        self::$seq = 0;
        self::$startNs = null;
    }

    /**
     * 墙钟毫秒。**只在构造 entry 时调**（已过门槛），守 §4.5 的"`date()` 不进热路径"。
     */
    private static function nowWallMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
