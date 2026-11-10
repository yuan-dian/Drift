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
