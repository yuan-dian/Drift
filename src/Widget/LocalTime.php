<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;

/**
 * 一天里的一个时刻（时 / 分 / 秒）—— `TimeView` / `DateTimePicker` 的受控值（2026-10-29）。
 *
 * ```php
 * $t = LocalTime::make(9, 5);          // 09:05:00
 * $t->label();                          // "09:05"
 * $t->label(true);                      // "09:05:00"
 * $t->iso();                            // "09:05:00" —— 给 DB / HTTP 用
 * LocalTime::fromIso('24:00');          // 响亮失败：小时只有 0..23
 * ```
 *
 * ★ **秒默认是 0，但字段保留**：企业表单绝大多数只到分，显示层由
 *   `TimeView::withSeconds()` 决定画不画第三列；值层留着秒是为了"以后要秒"
 *   不用改契约（`LocalDate` 当初反过来把 `day` 留给 `YearMonth`，那条是
 *   "占位字段"，这条是"真可能非 0 的值"）。
 *
 * ★ **不做夏令时 / 时区**：同 `YearMonth` 的理由 —— 墙上时钟的一个时刻就是
 *   三段整数，时区是它彻底用不上又必须先钉住的东西。
 *
 * @api
 */
final class LocalTime
{
    public function __construct(
        public readonly int $hour,
        public readonly int $minute,
        public readonly int $second = 0,
    ) {
        if ($hour < 0 || $hour > 23) {
            Errors::report(sprintf('LocalTime 的小时必须在 0..23，给了 %d', $hour));
        }

        if ($minute < 0 || $minute > 59) {
            Errors::report(sprintf('LocalTime 的分钟必须在 0..59，给了 %d', $minute));
        }

        if ($second < 0 || $second > 59) {
            Errors::report(sprintf('LocalTime 的秒必须在 0..59，给了 %d', $second));
        }
    }

    public static function make(int $hour, int $minute, int $second = 0): self
    {
        return new self($hour, $minute, $second);
    }

    public static function midnight(): self
    {
        return new self(0, 0, 0);
    }

    /**
     * 解析 `HH:MM` 或 `HH:MM:SS`（长度与分隔符都严格看）。
     *
     * ★ 两种长度都收：`<input type=time>` 与多数 API 给的是 `09:05`，DB 的
     *   `time` 列给的是 `09:05:00`。除此之外一律炸 —— `9:05`、`09:5`、
     *   `09-05` 都是脏数据，不该被猜。
     */
    public static function fromIso(string $iso): self
    {
        $len = \strlen($iso);

        if ($len !== 5 && $len !== 8) {
            Errors::report(sprintf('时间必须是 HH:MM 或 HH:MM:SS 形态，给了 "%s"', $iso));
        }

        if (\substr($iso, 2, 1) !== ':') {
            Errors::report(sprintf('时间必须是 HH:MM 或 HH:MM:SS 形态，给了 "%s"', $iso));
        }

        if ($len === 8 && \substr($iso, 5, 1) !== ':') {
            Errors::report(sprintf('时间必须是 HH:MM 或 HH:MM:SS 形态，给了 "%s"', $iso));
        }

        $second = $len === 8 ? (int) \substr($iso, 6, 2) : 0;

        return new self((int) \substr($iso, 0, 2), (int) \substr($iso, 3, 2), $second);
    }

    /** 序列化形态：恒 `HH:MM:SS`（无损） */
    public function iso(): string
    {
        return \sprintf('%02d:%02d:%02d', $this->hour, $this->minute, $this->second);
    }

    /** 给人看的形态：默认 `HH:MM`，`$seconds` 为真时 `HH:MM:SS` */
    public function label(bool $seconds = false): string
    {
        if ($seconds) {
            return $this->iso();
        }

        return \sprintf('%02d:%02d', $this->hour, $this->minute);
    }

    /**
     * 距离当天 0 点的秒数 —— 比较与"步进"共用一个口径。
     */
    public function totalSeconds(): int
    {
        return $this->hour * 3600 + $this->minute * 60 + $this->second;
    }

    public function equals(self $other): bool
    {
        return $this->hour === $other->hour
            && $this->minute === $other->minute
            && $this->second === $other->second;
    }

    public function compareTo(self $other): int
    {
        return $this->totalSeconds() <=> $other->totalSeconds();
    }
}
