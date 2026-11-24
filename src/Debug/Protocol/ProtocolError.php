<?php
declare(strict_types=1);

namespace Drift\Debug\Protocol;

/**
 * 对端拒绝了一次调用。
 *
 * ## 为什么要有独立的异常类型，而不是 `\RuntimeException`
 *
 * "对端说不行"和"管道断了"是两件事，处理方式相反：前者是**结果**（参数不对、
 * 查询名写错、会话没开），调用方可以改参数重试；后者是**故障**，只能放弃。
 * 都抛 `\RuntimeException` 时，调用方只能靠匹配错误文本区分 —— 而文本会改。
 *
 * `$errorCode` 是一小撮稳定的词（`bad_request` / `unknown_method` / `not_open` …），
 * 客户端按它分支，人读 `getMessage()`。这是 LSP / DAP 的做法：码给机器，话给人。
 *
 * @api
 */
final class ProtocolError extends \RuntimeException
{
    /** 参数形状不对（缺字段、类型错、脚本读不回）。 */
    public const string BAD_REQUEST = 'bad_request';

    /** 方法名不在 {@see Methods::ALL} 里。 */
    public const string UNKNOWN_METHOD = 'unknown_method';

    /** 还没 `open` 就驱动或查询。 */
    public const string NOT_OPEN = 'not_open';

    /** `open` 之后又 `open`。 */
    public const string ALREADY_OPEN = 'already_open';

    /** 宿主内部抛了异常（查询失败、页面造不出来……），消息里是原始文本。 */
    public const string CALL_FAILED = 'call_failed';

    /** 响应形状不对：id 对不上、result 类型不对。这说明**协议实现**有 bug。 */
    public const string BAD_RESPONSE = 'bad_response';

    /** 对端在等待响应时关掉了管道。 */
    public const string CLOSED = 'closed';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
