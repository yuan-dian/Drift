<?php
declare(strict_types=1);

namespace App\Showcase\Stores;

/**
 * 远程搜索机收到的事件。
 *
 * ★ int 常量 + `===` 而不是 enum with payload / 子类：tpc 对 `switch` 出 C2440，
 *   子类是去虚化缺陷面（与 `OptionStatus` 同一条纪律）。
 */
final class RemoteSearchEvent
{
    public const int QUERY = 0;

    public const int ARRIVE = 1;

    private function __construct(
        public readonly int $kind,
        public readonly int $attempt,
    ) {
    }

    /** 用户改了一次查询词 */
    public static function query(): self
    {
        return new self(self::QUERY, 0);
    }

    /** 某次查询的响应到达（带发起时的 attempt） */
    public static function arrive(int $attempt): self
    {
        return new self(self::ARRIVE, $attempt);
    }
}
