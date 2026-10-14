<?php
declare(strict_types=1);

namespace Drift\Dev\Lint;

/**
 * 一条 lint 发现。
 *
 * 两级严重度沿用 GUI 仓已验证的机制（§16.6.1）：error 挡新债 / warn 留痕。
 */
final readonly class Diagnostic
{
    public const ERROR = 'error';
    public const WARN = 'warn';

    public function __construct(
        public string $rule,
        public string $severity,
        public string $file,
        public int $line,
        public string $message,
    ) {
    }

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }

    public function format(): string
    {
        return sprintf(
            '%s:%d  %-5s  %-22s  %s',
            $this->file,
            $this->line,
            $this->severity,
            $this->rule,
            $this->message
        );
    }
}
