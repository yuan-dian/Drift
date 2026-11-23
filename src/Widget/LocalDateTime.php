<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;

/**
 * 日期 + 时刻 —— `DateTimePicker` 的受控值。
 *
 * ```php
 * $at = LocalDateTime::make(LocalDate::make(2026, 10, 29), LocalTime::make(9, 5));
 * $at->iso();       // "2026-10-29T09:05:00"
 * $at->label();     // "2026-10-29 09:05"
 * $at->withTime(LocalTime::make(18, 30));   // 只换时刻，日期不动
 * ```
 *
 * ★ **为什么是组合而不是三段整数**：`DatePicker` 与 `TimeView` 各自吃一半
 *   （`LocalDate` / `LocalTime`），`DateTimePicker` 把两个受控值并成一条通道
 *   给应用。拆成 `y/m/d/h/m/s` 六个字段的话，"日期那半变了"与"时刻那半变了"
 *   就只能靠应用自己比对，而两个面板本来就是两个独立的交互面。
 *
 * ★ **`fromIso()` 收 `T` 与空格两种分隔符**：HTTP / JS 序列化给 `T`，
 *   DB 的 `datetime` 列给空格。除此之外（含缺秒的 `2026-10-29T09:05`）一律炸 ——
 *   缺秒的状态下"秒是 0 还是没给"分不清，而这正是这条数据最容易错的地方。
 *
 * @api
 */
final class LocalDateTime
{
    public function __construct(
        public readonly LocalDate $date,
        public readonly LocalTime $time,
    ) {
    }

    public static function make(LocalDate $date, LocalTime $time): self
    {
        return new self($date, $time);
    }

    /** 解析 `YYYY-MM-DDTHH:MM:SS` 或 `YYYY-MM-DD HH:MM:SS` */
    public static function fromIso(string $iso): self
    {
        if (\strlen($iso) !== 19) {
            Errors::report(sprintf(
                '日期时间必须是 YYYY-MM-DDTHH:MM:SS 或 YYYY-MM-DD HH:MM:SS，给了 "%s"',
                $iso,
            ));
        }

        $sep = \substr($iso, 10, 1);

        if ($sep !== 'T' && $sep !== 't' && $sep !== ' ') {
            Errors::report(sprintf(
                '日期时间的日期与时刻之间只收 T / t / 空格，给了 "%s"',
                $iso,
            ));
        }

        return new self(
            LocalDate::fromIso(\substr($iso, 0, 10)),
            LocalTime::fromIso(\substr($iso, 11, 8)),
        );
    }

    /** 序列化形态：`2026-10-29T09:05:00`（恒带秒，无损） */
    public function iso(): string
    {
        return $this->date->iso() . 'T' . $this->time->iso();
    }

    /** 给人看的形态：`2026-10-29 09:05`（`$seconds` 为真时带秒） */
    public function label(bool $seconds = false): string
    {
        return $this->date->iso() . ' ' . $this->time->label($seconds);
    }

    public function withDate(LocalDate $date): self
    {
        return new self($date, $this->time);
    }

    public function withTime(LocalTime $time): self
    {
        return new self($this->date, $time);
    }

    public function equals(self $other): bool
    {
        return $this->date->equals($other->date) && $this->time->equals($other->time);
    }

    public function compareTo(self $other): int
    {
        $byDate = $this->date->compareTo($other->date);

        return $byDate !== 0 ? $byDate : $this->time->compareTo($other->time);
    }
}
