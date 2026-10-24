<?php
declare(strict_types=1);

namespace Drift\Logging;

/**
 * 一个分类的记录句柄 —— 业务代码拿到的东西。
 *
 * ★ 为什么不直接给业务 `Log::info('layout', ...)`：分类写在**取句柄时**，
 *   写错分类（`layuot`）会当场暴露而不是静默进错桶；调用处也少一个易错参数。
 *   形态对齐 Monolog 的 `Logger` / PSR-3 的 `LoggerInterface`（PHP 生态事实标准）。
 * ★ **门槛不在这里做**（在 `Log`）：句柄每次调用都要先问 `Log` 该不该记，
 *   否则"改门槛"得遍历所有句柄。
 * ★ 句柄是纯转发，**不持有状态** ⇒ 多个 `Log::get('layout')` 返回的实例等价，
 *   可以随便缓存到字段里。
 */
final class CategoryLogger
{
    /** @internal 由 `Log::get()` 创建 */
    public function __construct(private readonly string $category)
    {
    }

    public function category(): string
    {
        return $this->category;
    }

    /** 业务运行的常规轨迹 */
    public function info(string $message, array $fields = []): void
    {
        Log::emit(Log::INFO, $this->category, $message, $fields);
    }

    /** 开发期细节，release 默认关 */
    public function debug(string $message, array $fields = []): void
    {
        Log::emit(Log::DEBUG, $this->category, $message, $fields);
    }

    /** 不该发生但没崩，值得看一眼 */
    public function warn(string $message, array $fields = []): void
    {
        Log::emit(Log::WARN, $this->category, $message, $fields);
    }

    /** 出错了但还在跑 */
    public function error(string $message, array $fields = []): void
    {
        Log::emit(Log::ERROR, $this->category, $message, $fields);
    }

    /** 级别由调用方给（收尾/桥接场景） */
    public function log(int $level, string $message, array $fields = []): void
    {
        Log::emit($level, $this->category, $message, $fields);
    }
}
