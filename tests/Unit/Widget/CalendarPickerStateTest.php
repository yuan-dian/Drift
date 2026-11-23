<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * `CalendarPickerState`：两个日期选择器共用的"触发框 + 月历浮层"骨架。
 *
 * ## 判据怎么落
 *
 * ① **结构**：`DatePickerState` / `DateTimePickerState` 都继承它，且**不再自持**
 *   `build()` / `panelFactory()` / `onPanelClosed()` 与三样私有态 —— 抄回来就红
 *   （第四份手抄正是这类"改一处漏一处"的来源）。
 * ② **正向**：骨架确实住在基类（`abstract class` + 三样私有态 + 三个方法），
 *   防止"子类删干净了、基类却是空壳"的假绿。
 * ③ 行为分叉（选完收 / 不收）由 `tests/Feature/Widget/DatePickerTest.php` 守。
 *
 * ★ 判据落在**代码**上（跳过 docblock）：注释里出现方法名不算违规。
 */
final class CalendarPickerStateTest extends SmokeTestCase
{
    /** @return array<string, array{0: string, 1: string}> 文件 => [类名, 源码] */
    private static function subclasses(): array
    {
        $out = [];

        foreach (['DatePicker.php' => 'DatePickerState', 'DateTimePicker.php' => 'DateTimePickerState'] as $file => $class) {
            $path = \repoRoot() . '/src/Widget/' . $file;
            if (\is_file($path)) {
                $out[$file] = [$class, (string) \file_get_contents($path)];
            }
        }

        return $out;
    }

    public function testSubclassesShareTheSkeleton(): void
    {
        $ownedMethods = ['build', 'panelFactory', 'onPanelClosed'];
        $ownedFields = ['month', 'dismiss', 'signal'];
        $bad = [];

        foreach (self::subclasses() as $file => [$class, $src]) {
            $code = self::codeOnly($src);

            if (\preg_match('/final[ \t]+class[ \t]+' . $class . '[ \t]+extends[ \t]+CalendarPickerState/', $code) !== 1) {
                $bad[] = $file . '（未继承 CalendarPickerState）';
            }

            foreach ($ownedMethods as $method) {
                if (\preg_match('/function[ \t]+' . $method . '\s*\(/', $code) === 1) {
                    $bad[] = $file . '（仍自持 ' . $method . '()）';
                }
            }

            foreach ($ownedFields as $field) {
                if (\preg_match('/private[ \t]+[?\\\\A-Za-z]*[ \t]*\$' . $field . '\b/', $code) === 1) {
                    $bad[] = $file . '（仍自持 $' . $field . ' 字段）';
                }
            }
        }

        \sort($bad);
        check(
            'DatePicker/DateTimePicker 共用 CalendarPickerState：不自持 build/panelFactory/onPanelClosed 与三样私有态',
            $bad === [],
            $bad === [] ? '' : \implode(', ', $bad),
        );

        $this->flushChecks();
    }

    public function testBaseReallyOwnsTheSkeleton(): void
    {
        $path = \repoRoot() . '/src/Widget/CalendarPickerState.php';
        check('骨架文件存在', \is_file($path));

        $code = self::codeOnly((string) \file_get_contents($path));
        $bad = [];

        if (\preg_match('/abstract[ \t]+class[ \t]+CalendarPickerState[ \t]+extends[ \t]+State/', $code) !== 1) {
            $bad[] = '不是 abstract class ... extends State';
        }

        foreach (['build', 'panelFactory', 'onPanelClosed'] as $method) {
            if (\preg_match('/function[ \t]+' . $method . '\s*\(/', $code) !== 1) {
                $bad[] = '缺 ' . $method . '()';
            }
        }

        foreach (['month', 'dismiss', 'signal'] as $field) {
            if (\preg_match('/private[ \t]+[?\\\\A-Za-z]*[ \t]*\$' . $field . '\b/', $code) !== 1) {
                $bad[] = '缺 $' . $field . ' 字段';
            }
        }

        check(
            '基类真的持有骨架：abstract + build/panelFactory/onPanelClosed + month/dismiss/signal',
            $bad === [],
            $bad === [] ? '' : \implode(', ', $bad),
        );

        $this->flushChecks();
    }

    /** 量具自证：确实扫到两个子类，防止路径写坏时上面两条恒绿 */
    public function testGaugeSeesBothSubclasses(): void
    {
        $found = \array_keys(self::subclasses());
        \sort($found);

        check(
            '量具自证：确实找到两个日期选择器子类',
            $found === ['DatePicker.php', 'DateTimePicker.php'],
            \implode(', ', $found),
        );

        $this->flushChecks();
    }

    private static function codeOnly(string $src): string
    {
        $keep = [];
        foreach (\explode("\n", $src) as $line) {
            $trim = \ltrim($line);
            if (\str_starts_with($trim, '*') || \str_starts_with($trim, '/*')
                || \str_starts_with($trim, '//') || \str_starts_with($trim, '#')) {
                continue;
            }
            $keep[] = $line;
        }

        return \implode("\n", $keep);
    }
}
