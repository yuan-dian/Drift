<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;

/**
 * 年月 —— `CalendarView` / `DatePicker` 里"正在显示哪个月"（A3 第三件，2026-10-29）。
 *
 * ```php
 * $m = YearMonth::make(2026, 10);
 * $m->daysInMonth();   // 31
 * $m->firstWeekday();  // 3（0 = 周一 ⇒ 2026-10-01 是周四）
 * $m->plusMonths(1);   // 2026-11
 * ```
 *
 * **为什么不用 `\DateTimeImmutable`**：日历只要"这月几天 / 一号是周几"，
 * 时区与夏令时一个都用不上 —— 而 `DateTime` 恰恰这两样都得先显式钉住，忘了就
 * 跨时区漂一天；翻月又是点击级操作，值对象比"构造 + 一串方法调用过 `php::Var`"
 * 便宜得多。
 *
 * ★ 纯值对象，`public readonly` 即终态（同 `Option`）。构造期校验：年 / 月越界
 *   一律**响亮失败** —— 日历不会自己把 13 月"滚动"成次年 1 月。
 *
 * @api
 */
final class YearMonth
{
    /** 平年每月天数（2 月见 `daysInMonth()`） */
    private const DAYS = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    /** Sakamoto 公式的月份偏移表（1..12） */
    private const SAKAMOTO = [0, 3, 2, 5, 0, 3, 5, 1, 4, 6, 2, 4];

    public function __construct(
        public readonly int $year,
        public readonly int $month,
    ) {
        if ($year < 1 || $year > 9999) {
            Errors::report(sprintf('YearMonth 的年份必须在 1..9999，给了 %d', $year));
        }

        if ($month < 1 || $month > 12) {
            Errors::report(sprintf('YearMonth 的月份必须在 1..12，给了 %d', $month));
        }
    }

    public static function make(int $year, int $month): self
    {
        return new self($year, $month);
    }

    public static function isLeap(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }

    public function daysInMonth(): int
    {
        if ($this->month === 2 && self::isLeap($this->year)) {
            return 29;
        }

        return self::DAYS[$this->month - 1];
    }

    /** 本月 1 号是周几（0 = 周一 … 6 = 周日）—— 日历首行的前导格数就是它 */
    public function firstWeekday(): int
    {
        return self::weekdayOf($this->year, $this->month, 1);
    }

    /**
     * 某天是周几（0 = 周一 … 6 = 周日）。
     *
     * Sakamoto：`%` 对被除数非负时结果正常，本类年份下限为 1 ⇒ 不需要负数兜底。
     */
    public static function weekdayOf(int $year, int $month, int $day): int
    {
        $ym = $month < 3 ? $year - 1 : $year;

        $sunday0 = ($ym + intdiv($ym, 4) - intdiv($ym, 100) + intdiv($ym, 400)
            + self::SAKAMOTO[$month - 1] + $day) % 7;

        return ($sunday0 + 6) % 7;
    }

    /**
     * 加减月份（跨年自动进位 / 借位）。
     *
     * ★ 走"总月数 + 整除"而不是"月号加完再逐级修正"：后者只在**反向跨年**
     *   （2026-01 减 1 月 ⇒ 2025-12）时暴露，正向测试全绿。
     * ★ **不需要负数兜底**：年份下限是 1 ⇒ `$total` 恒为正，`intdiv` 的向零取整
     *   与地板除同结果。2026-10-29 变异实测：写一版负数分支，删掉它仍然全绿
     *   ⇒ 那是死规则，已删。极端负数（`$total <= 0`）由构造器的年份校验响亮拒绝。
     */
    public function plusMonths(int $n): self
    {
        $next = $this->tryPlusMonths($n);

        if ($next !== null) {
            return $next;
        }

        Errors::report(sprintf(
            'YearMonth 加 %d 月后超出 1..9999 年（从 %d 年 %d 月）',
            $n,
            $this->year,
            $this->month,
        ));

        return $this;   // 不可达：`Errors::report()` 默认 FATAL
    }

    /**
     * 同 `plusMonths()`，但越界返回 `null` 而不是响亮失败。
     *
     * ★ 给"翻页"用：`CalendarView` 的年视图一页翻 12 年，从 5 年往前翻就是
     *   越界 —— 那是**到头了**，不是错误。把"越界"当 FATAL 会让用户多点两次
     *   箭头就把进程打死。
     */
    public function tryPlusMonths(int $n): ?self
    {
        $total = $this->year * 12 + ($this->month - 1) + $n;

        $year = intdiv($total, 12);

        if ($year < 1 || $year > 9999) {
            return null;
        }

        return new self($year, $total - $year * 12 + 1);
    }

    public function equals(self $other): bool
    {
        return $this->year === $other->year && $this->month === $other->month;
    }

    /** 给人看的标题：`2026 年 10 月`（不补零 —— 中文习惯） */
    public function label(): string
    {
        return $this->year . ' 年 ' . $this->month . ' 月';
    }
}
