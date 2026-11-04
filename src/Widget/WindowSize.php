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
 * ★ 断点写成字面量，**不在 enum 里声明 `const`**：tpc 把 enum 内任何 `self::X` 一律编译成
 *   `php::getEnumCase()`（`typephp/src/Parser/ClassConstantFetchTrait.php`），非 case 的常量
 *   会让 AOT 版启动即 `0xC0000005`（解释器下全绿，`php -l` / smoke / phpunit 都抓不到）。
 *
 * @api
 */
enum WindowSize
{
    case Compact;
    case Medium;
    case Expanded;
    case Large;

    public static function fromWidth(float $width): self
    {
        return match (true) {
            $width < 600.0 => self::Compact,
            $width < 1000.0 => self::Medium,
            $width < 1440.0 => self::Expanded,
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
