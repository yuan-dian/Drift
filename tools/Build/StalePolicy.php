<?php
declare(strict_types=1);

namespace Drift\Dev\Build;

/**
 * `drift-build` 的「这次 SKIP / FULL / INCREMENTAL」唯一裁决点。
 *
 * ★ 为什么独立成纯函数：原先那段 if/else 同时管 IO 与策略，既不能单测，也不能被
 *   对账器验证接线。判据必须与实现不同源。
 * ★ 裁决口径来自 T1：`drift-codegen` 每次删 tpc 缓存（"门不能信缓存"），把
 *   `[cached]` 当失败；T2 却默认信增量。同一个缓存不该有两套信任标准。
 */
final class StalePolicy
{
    public const SKIP = 'skip';
    public const FULL = 'full';
    public const INCREMENTAL = 'incremental';

    private function __construct()
    {
    }

    /**
     * @param string $previous 上次**成功**构建的输入指纹；'' = 没有清单
     * @param string $current 本次输入指纹
     * @param bool $productExists 上次产物是否还在
     * @param bool $forceFull --full
     * @param bool $allowIncremental --incremental（知情者显式 opt-in）
     */
    public static function decide(
        string $previous,
        string $current,
        bool $productExists,
        bool $forceFull = false,
        bool $allowIncremental = false,
    ): string {
        // 显式强制最高：usage 承诺 --full「不受短路影响」。
        if ($forceFull) {
            return self::FULL;
        }

        // 输入未变且产物在 ⇒ 根本不用构建。`--incremental` 是"允许"而非"强制构建"，
        // 不能压过短路，否则每次都白重链约 28s。
        if ($previous !== '' && $previous === $current && $productExists) {
            return self::SKIP;
        }

        // 输入变了 / 首次 / 产物没了：默认一律不信缓存，只有显式 opt-in 才放行增量。
        if ($allowIncremental) {
            return self::INCREMENTAL;
        }

        return self::FULL;
    }

    /** 供 `--dry` 与构建日志共用，避免同一句措辞写两遍。 */
    public static function label(string $decision): string
    {
        return match ($decision) {
            self::SKIP => '输入未变且产物仍在 ⇒ 跳过编译',
            self::FULL => '输入变了 / 首次构建 / 产物不在 ⇒ 自动全量（门不能信缓存）',
            self::INCREMENTAL => '--incremental 显式放行 ⇒ 走 tpc 增量（可能 stale，知情者自负）',
            default => '未知决策',
        };
    }
}
