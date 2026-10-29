<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;

/**
 * 一个日历日期（年 / 月 / 日）—— `CalendarView` / `DatePicker` 的受控值（2026-10-29）。
 *
 * ```php
 * $d = LocalDate::make(2026, 10, 29);
 * $d->iso();        // "2026-10-29" —— 给 DB / HTTP 用
 * $d->weekday();    // 3（0 = 周一）
 * LocalDate::fromIso('2026-02-29');   // 响亮失败：2026 不是闰年
 * ```
 *
 * ★ 与 `YearMonth` 分开（而不是 day 恒为 1 的"月"）：日历标题要的是"月"，
 *   选中值要的是"天"，一个类型兼两职时 `day` 就成了"永远是 1 的占位符"，
 *   而 `plusMonths()` 之后它还是不是 1 只能靠口头约定。
 *
 * ★ `fromIso()` **不做宽松解析**（不补零、不猜 `2026/10/29`、不把 `2026-13-01`
 *   滚成 2027-01-01）：数据脏就该在边界炸，而不是在日历上静默错一个月。
 *
 * @api
 */
final class LocalDate
{
    public function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly int $day,
    ) {
        $ym = new YearMonth($year, $month);

        if ($day < 1 || $day > $ym->daysInMonth()) {
            Errors::report(sprintf(
                '%d 年 %d 月只有 %d 天，给了日 = %d',
                $year,
                $month,
                $ym->daysInMonth(),
                $day,
            ));
        }
    }

    public static function make(int $year, int $month, int $day): self
    {
        return new self($year, $month, $day);
    }

    /** 解析 `YYYY-MM-DD`（长度与分隔符都严格看） */
    public static function fromIso(string $iso): self
    {
        if (strlen($iso) !== 10 || substr($iso, 4, 1) !== '-' || substr($iso, 7, 1) !== '-') {
            Errors::report(sprintf('日期必须是 YYYY-MM-DD 形态，给了 "%s"', $iso));
        }

        return new self(
            (int) substr($iso, 0, 4),
            (int) substr($iso, 5, 2),
            (int) substr($iso, 8, 2),
        );
    }

    /** 序列化形态：`2026-10-29` */
    public function iso(): string
    {
        return sprintf('%04d-%02d-%02d', $this->year, $this->month, $this->day);
    }

    public function yearMonth(): YearMonth
    {
        return new YearMonth($this->year, $this->month);
    }

    /** 周几（0 = 周一 … 6 = 周日） */
    public function weekday(): int
    {
        return YearMonth::weekdayOf($this->year, $this->month, $this->day);
    }

    public function equals(self $other): bool
    {
        return $this->year === $other->year
            && $this->month === $other->month
            && $this->day === $other->day;
    }

    /** 同月同日比较（<0 / 0 / >0）—— 日历排序与范围判断都靠它 */
    public function compareTo(self $other): int
    {
        return $this->iso() <=> $other->iso();
    }
}
