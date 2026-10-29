<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Widget\LocalDate;
use Drift\Widget\YearMonth;

/**
 * `YearMonth` / `LocalDate`：日历的两个值对象（A3 第三件的前置，2026-10-29）。
 *
 * ## 判据钉什么
 *
 * ① **月长**（12 个月逐条 + 闰年四种边界），② **周几**（跨世纪的已知锚点），
 * ③ **翻月**（正向 / **反向跨年** —— 反向是唯一会暴露地板除写错的方向），
 * ④ **ISO 往返**，⑤ **比较**，⑥ **响亮失败**（13 月 / 非闰年 2-29 / 脏 ISO）。
 *
 * ★ 周几用"已知锚点"而不是自己算一遍：`weekdayOf()` 只有 Sakamoto 一个实现，
 *   拿它自己验自己等于没验。
 */
final class CalendarDateTest extends SmokeTestCase
{
    // ── ① 月长与闰年 ────────────────────────────────────────────────────

    public function testEveryMonthHasTheRightLength(): void
    {
        $expect = [1 => 31, 2 => 28, 3 => 31, 4 => 30, 5 => 31, 6 => 30,
            7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31];

        foreach ($expect as $month => $days) {
            $got = YearMonth::make(2026, $month)->daysInMonth();
            check("YearMonth · 2026-{$month} 有 {$days} 天", $got === $days, '实际 ' . $got);
        }
    }

    public function testLeapYearRules(): void
    {
        // 四条规则各一条锚点：普通闰 / 百年非闰 / 400 年闰 / 平年
        check('YearMonth · 2024 是闰年', YearMonth::isLeap(2024));
        check('YearMonth · 1900 不是闰年（百年非闰）', !YearMonth::isLeap(1900));
        check('YearMonth · 2000 是闰年（400 年闰）', YearMonth::isLeap(2000));
        check('YearMonth · 2026 不是闰年', !YearMonth::isLeap(2026));

        check('YearMonth · 2024-02 有 29 天', YearMonth::make(2024, 2)->daysInMonth() === 29);
        check('YearMonth · 1900-02 有 28 天', YearMonth::make(1900, 2)->daysInMonth() === 28);
        check('YearMonth · 2000-02 有 29 天', YearMonth::make(2000, 2)->daysInMonth() === 29);
    }

    // ── ② 周几（0 = 周一）──────────────────────────────────────────────

    public function testWeekdayAgainstKnownAnchors(): void
    {
        // 2026-10-29 是周四（本机当天）；1970-01-01 周四；2000-01-01 周六；
        // 2024-02-29 周四；2026-11-01 周日
        $anchors = [
            '2026-10-29' => 3,
            '2026-10-01' => 3,
            '1970-01-01' => 3,
            '2000-01-01' => 5,
            '2024-02-29' => 3,
            '2026-11-01' => 6,
        ];

        foreach ($anchors as $iso => $weekday) {
            $d = LocalDate::fromIso($iso);
            check("LocalDate · {$iso} 是周 {$weekday}（0 = 周一）", $d->weekday() === $weekday, '实际 ' . $d->weekday());
        }

        // 同一个算法也该被 `YearMonth::firstWeekday()` 走通（它是日历首行前导格数）
        check(
            'YearMonth · 2026-10 的 1 号是周四（前导 3 格）',
            YearMonth::make(2026, 10)->firstWeekday() === 3,
            '实际 ' . YearMonth::make(2026, 10)->firstWeekday()
        );
    }

    // ── ③ 翻月 ─────────────────────────────────────────────────────────

    public function testPlusMonthsAcrossYearsBothDirections(): void
    {
        $cases = [
            // [起点年, 起点月, 步数, 期望年, 期望月]
            [2026, 10, 1, 2026, 11],
            [2026, 12, 1, 2027, 1],
            [2026, 1, -1, 2025, 12],     // ★ 反向借位：正向全绿时它才红
            [2026, 1, -13, 2024, 12],
            [2026, 6, -6, 2025, 12],
            [2026, 1, -12, 2025, 1],
            [2026, 6, 0, 2026, 6],
            [2026, 6, 18, 2027, 12],
        ];

        foreach ($cases as [$y, $m, $n, $ey, $em]) {
            $got = YearMonth::make($y, $m)->plusMonths($n);
            check(
                "YearMonth · {$y}-{$m} 加 {$n} 月 ⇒ {$ey}-{$em}",
                $got->year === $ey && $got->month === $em,
                '实际 ' . $got->year . '-' . $got->month
            );
        }
    }

    // ── ④ ISO 往返 ─────────────────────────────────────────────────────

    public function testIsoRoundTripPadsZeros(): void
    {
        check('LocalDate · 2026-1-5 序列化成 2026-01-05', LocalDate::make(2026, 1, 5)->iso() === '2026-01-05');
        check(
            'LocalDate · 2026-01-05 往返不变',
            LocalDate::fromIso('2026-01-05')->iso() === '2026-01-05'
        );
        check('LocalDate · 闰日 2024-02-29 合法', LocalDate::fromIso('2024-02-29')->iso() === '2024-02-29');

        $d = LocalDate::fromIso('2026-10-29');
        check('LocalDate · yearMonth() 摘出 2026-10', $d->yearMonth()->equals(YearMonth::make(2026, 10)));
        check('LocalDate · equals 同日为真', $d->equals(LocalDate::make(2026, 10, 29)));
        check('LocalDate · equals 差一天为假', !$d->equals(LocalDate::make(2026, 10, 28)));
    }

    // ── ⑤ 比较 ─────────────────────────────────────────────────────────

    public function testCompareToOrdersByCalendarOrder(): void
    {
        check(
            'LocalDate · 跨年比较 2025-12-31 < 2026-01-01',
            LocalDate::make(2025, 12, 31)->compareTo(LocalDate::make(2026, 1, 1)) < 0
        );
        check(
            'LocalDate · 跨月比较 2026-09-30 < 2026-10-01',
            LocalDate::make(2026, 9, 30)->compareTo(LocalDate::make(2026, 10, 1)) < 0
        );
        // 补零的意义：字符串序就是日序（2026-10-9 若不补零会排到 2026-10-10 后面）
        check(
            'LocalDate · 补零后 2026-10-09 < 2026-10-10',
            LocalDate::make(2026, 10, 9)->compareTo(LocalDate::make(2026, 10, 10)) < 0
        );
        check(
            'LocalDate · 同一天比较为 0',
            LocalDate::make(2026, 10, 29)->compareTo(LocalDate::make(2026, 10, 29)) === 0
        );
    }

    // ── ⑥ 响亮失败 ─────────────────────────────────────────────────────

    public function testInvalidMonthFailsLoudly(): void
    {
        $log = \sys_get_temp_dir() . '/drift-date-' . \getmypid() . '.log';
        fresh($log);
        [$code, $out] = runFatalCase('ymbadmonth', $log);
        check('YearMonth · 13 月 ⇒ Fatal（不静默滚动到次年 1 月）', $code === 3, '实际 ' . $code . ' / ' . $out);
        check('YearMonth · 报错点名 1..12', \str_contains(logText($log), '1..12'), 'log=' . \substr(logText($log), 0, 160));
    }

    public function testNonexistentDayFailsLoudly(): void
    {
        $log = \sys_get_temp_dir() . '/drift-date-' . \getmypid() . '.log';
        fresh($log);
        [$code, $out] = runFatalCase('datebadday', $log);
        check('LocalDate · 2026-02-29 ⇒ Fatal（非闰年没有这天）', $code === 3, '实际 ' . $code . ' / ' . $out);
        check(
            'LocalDate · 报错说清"那月只有几天"',
            \str_contains(logText($log), '28'),
            'log=' . \substr(logText($log), 0, 160)
        );
    }

    public function testMalformedIsoFailsLoudly(): void
    {
        $log = \sys_get_temp_dir() . '/drift-date-' . \getmypid() . '.log';
        fresh($log);
        [$code, $out] = runFatalCase('datebadiso', $log);
        check('LocalDate · "2026/10/29" ⇒ Fatal（不做宽松解析）', $code === 3, '实际 ' . $code . ' / ' . $out);
        check(
            'LocalDate · 报错给出 YYYY-MM-DD 形态',
            \str_contains(logText($log), 'YYYY-MM-DD'),
            'log=' . \substr(logText($log), 0, 160)
        );
    }
}
