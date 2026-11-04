<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 窗口宽度的**离散档**——响应式布局的判据。
 *
 * ★ 为什么给档位而不是连续宽度：`build()` 只在跨档时重跑（拖动窗口逐像素重建全树会卡）。
 *   一个"档位不变就滞后"的宽度数字暴露给 `build()` 等于埋坑；要精确宽度用布局层
 *   （`Expanded` / 比例），那本来就不需要 rebuild。
 *
 * 断点按桌面窗口调过：Compact < 600 / Medium < 1000 / Expanded < 1440 / Large ≥ 1440。
 *
 * @api
 */
enum WindowSize
{
    case Compact;
    case Medium;
    case Expanded;
    case Large;

    /** Compact 上界（不含）——窄窗，单栏 / 侧栏折叠 */
    public const COMPACT_MAX = 600.0;

    /** Medium 上界（不含）——侧栏可留但内容区吃紧 */
    public const MEDIUM_MAX = 1000.0;

    /** Expanded 上界（不含）——标准桌面窗 */
    public const EXPANDED_MAX = 1440.0;

    public static function fromWidth(float $width): self
    {
        return match (true) {
            $width < self::COMPACT_MAX => self::Compact,
            $width < self::MEDIUM_MAX => self::Medium,
            $width < self::EXPANDED_MAX => self::Expanded,
            default => self::Large,
        };
    }

    /** 窄窗：侧栏该折叠、双栏该改单栏 */
    public function isCompact(): bool
    {
        return $this === self::Compact;
    }

    /** 至少这么宽（`atLeast(WindowSize::Expanded)` = 够放三栏） */
    public function atLeast(self $minimum): bool
    {
        return $this->rank() >= $minimum->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Compact => 0,
            self::Medium => 1,
            self::Expanded => 2,
            self::Large => 3,
        };
    }
}
