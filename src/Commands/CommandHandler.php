<?php
declare(strict_types=1);

namespace Drift\Commands;

/**
 * 命令处理器（§11.4.3）。
 *
 * ★ **必须是具名类的实例，不能是闭包**（2026-10-14 裁决，§23.2 O-15 方案 E）。
 *   闭包三条全不满足：**不可枚举**（devtools 列不出来）、**不可单测**、
 *   **静态不可达**（"死命令 lint"要能看出这个 handler 处理哪些名字）。
 *   具名类的实例三条全满足：`get_class($h)` 可枚举 · 实例可单测 ·
 *   `new UserCommands(...)` 字面量在调用点静态可见。
 *
 * ★ 这与 §17.2 "TaskHost 收 `Job` 类而非 `callable`"是**同一条设计语言**：
 *   **凡是要跨边界的东西都是类。**
 *
 * @api
 */
interface CommandHandler
{
    /**
     * @param string $name 被派发的命令名
     * @return bool true = "我处理了，停止冒泡"；false = "不是我管，继续"
     *
     * @api
     */
    public function handle(string $name, CommandArg $arg): bool;
}
