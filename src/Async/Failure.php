<?php
declare(strict_types=1);

namespace Drift\Async;

/**
 * 跨异步链路（含跨进程）保留下来的结构化错误。
 *
 * worker 只能把异常压成 JSON 标量，还原端造不回原来的异常类 —— `code` 就丢在
 * 这段边界上，UI 只剩 `getMessage()`，404 / 超时 / 断网只能靠字符串匹配分辨。
 * 本类是那段边界的载体，`codeOf()` 是全链路唯一的提取点。
 *
 * @internal 应用侧读 `AsyncValue::code()` 或配 `AsyncCommand::mapError()`，不必 instanceof
 */
final class Failure extends \RuntimeException
{
    /**
     * 任务跑过了自己声明的（或 Host 兜底的）墙钟上限，被杀掉。
     *
     * ★ 给 code 的理由：超时与"任务里抛了异常"在 UI 上是两种处置（前者可重试、
     *   后者要改代码）。没有 code 时它们只能靠消息文字区分 —— 而 `codeOf()` 对裸
     *   `RuntimeException` 归一成空串，等于把这条信息丢在边界上。
     */
    public const string CODE_TIMEOUT = 'drift/timeout';

    /** worker 进程死了（崩溃 / 被外部杀掉）—— 不是任务自己抛的错，重试通常有效 */
    public const string CODE_WORKER_DIED = 'drift/worker-died';

    /** 后端起不来 / 递不进去（环境问题：池满了、唤醒窗建不出来），重试可能有用 */
    public const string CODE_HOST_UNAVAILABLE = 'drift/host-unavailable';

    /**
     * 任务本身不满足后端要求（不是 `NamedJob` / 参数转不了 JSON / 注册表里没有）。
     *
     * ★ **重试无用**：这类错是装配写错了，再点一次还是同一句。UI 该画"配置错误"
     *   而不是"重试"按钮。
     */
    public const string CODE_JOB_REJECTED = 'drift/job-rejected';

    public function __construct(
        string $message,
        private readonly string $errorCode = '',
    ) {
        parent::__construct($message);
    }

    /** `''` 表示源异常没给分类，不表示「分类失败」 */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** 任意 Throwable → 归一化 code；普通异常走 PHP 标准的 `getCode()` */
    public static function codeOf(\Throwable $error): string
    {
        if ($error instanceof self) {
            return $error->errorCode();
        }

        $code = (string) $error->getCode();

        // 0 是「没设 code」的默认值，不该当成一个真分类交给 UI
        return $code === '' || $code === '0' ? '' : $code;
    }
}
