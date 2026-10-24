<?php
declare(strict_types=1);

namespace Drift\Logging;

/**
 * 一条日志的结构化快照 —— 交给 formatter / sink 的**唯一**载荷。
 *
 * ★ 为什么是不可变值对象：一条 entry 会被 N 个 sink 与 N 个 formatter 各自读一遍，
 *   共享实例时任何一方的修改都会污染其他方。`readonly` 让"谁也不能改"由类型保证。
 * ★ 字段全 `scalar`（不含嵌套）：`12` §5 明确不做嵌套字段 —— 无真实需求，
 *   且 formatter 要遍历嵌套就得递归，AOT 下多一处逃逸面。
 *
 * ★ **`wallMs` 为什么必须存在**（本类最关键的一个决定）：`%T`（墙钟 `HH:MM:SS`）
 *   是 **pattern** 占位符，由 `LogFormatter::format(LogEntry)` 渲染 —— 但 formatter
 *   签名只有 entry，拿不到"现在几点"。若把 `date()` 放进 `format()`，`Ring` 里
 *   的 2000 条**事后**重放给 devtools 时会全部渲染成"当下"，`%T` 就是假的。
 *   真实墙钟必须在**构造 entry 的那一刻**捕获一次。相对时间则不存（`%t` 由
 *   `monotonic` 与启动点算出），守 `12` §4.5 的"`date()` 不进热路径"。
 */
final readonly class LogEntry
{
    /**
     * @param array<string, scalar> $fields 结构化字段，按插入序（`%f` 照此展开）
     */
    public function __construct(
        /** 全局单调序号，从 1 起（`%s`） */
        public int $seq,
        /** 级别常量，见 `Log` */
        public int $level,
        /** 点分分类（Qt `QLoggingCategory` 的形状） */
        public string $category,
        /** 常量消息，不含字段（`%m`） */
        public string $message,
        /** 结构化字段（`%f`） */
        public array $fields,
        /** 单调时钟纳秒（`%t` 由它减启动点算出） */
        public int $monotonicNs,
        /** 墙钟毫秒（`%T`）—— 见类注释：必须在构造时捕获 */
        public int $wallMs,
    ) {
    }
}
