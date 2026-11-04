<?php
declare(strict_types=1);

namespace Drift\Async;

/**
 * `AsyncScope` 被取消时投进协程的哨兵异常。
 *
 * ★ 单独一个类型，是为了让业务能写 `catch (AsyncCancelled)` 做清理，
 *   而 `catch (\RuntimeException)` 不会把它和真正的业务失败混在一起。
 * ★ 它**不是错误**：`AsyncScope` 会把外层 `Future` 保持"已取消"而不是"失败"。
 *
 * @api
 */
final class AsyncCancelled extends \RuntimeException
{
}
