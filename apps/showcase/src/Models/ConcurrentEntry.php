<?php
declare(strict_types=1);

namespace App\Showcase\Models;

/**
 * 并发取数里**一条**的结果 —— 成功就带行数与数据来源，失败就带原因。
 *
 * ★ 失败与"没有这一页"是两件事：前者 `of()` 回实例且 `ok() === false`，
 *   后者调用方拿到 `null`。混成一个会让"没发这条请求"看起来像"这条失败了"。
 */
final readonly class ConcurrentEntry
{
    private function __construct(
        private bool $ok,
        private int $rowCount,
        private string $source,
        private string $error,
        private ?float $spentMs,
    ) {
    }

    /**
     * @param mixed $raw `{ok, value, error}`（`allSettled()` 的每条形态）
     * @param null|float $spentMs 失败的那条没有耗时（`then` 没跑到）
     */
    public static function of(mixed $raw, ?float $spentMs): self
    {
        $entry = \is_array($raw) ? $raw : [];
        $ok = ($entry['ok'] ?? false) === true;

        if (!$ok) {
            return new self(false, 0, '', (string) ($entry['error'] ?? '未知原因'), $spentMs);
        }

        $payload = $entry['value'] ?? null;
        $items = \is_array($payload) && \is_array($payload['items'] ?? null) ? $payload['items'] : [];

        return new self(
            true,
            \count($items),
            \is_array($payload) ? (string) ($payload['source'] ?? '') : '',
            '',
            $spentMs,
        );
    }

    public function ok(): bool
    {
        return $this->ok;
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    public function source(): string
    {
        return $this->source;
    }

    /** 失败原因；成功时是空串 */
    public function error(): string
    {
        return $this->error;
    }

    /** `null` = 没有耗时（失败了） */
    public function spentMs(): ?float
    {
        return $this->spentMs;
    }
}
