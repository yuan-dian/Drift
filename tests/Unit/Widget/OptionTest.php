<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Widget\ComboBox;
use Drift\Widget\Option;
use Drift\Widget\Select;

/**
 * 选项表（`Option`，W8-2 的地基，2026-10-24）。
 *
 * 钉住两件事：
 * ① **映射形态的键类型收口** —— PHP 把 `'110000'` 转成 int，不收口回 string 的话
 *    `value()` 永远比不中、静默显示 placeholder，而报错位置在 `Option::make()`，
 *    与应用写的那行对不上；
 * ② **list 形态只收 `Option`** —— `[0 => 'x']` 与 `['x']` 在 PHP 里是**同一个数组**，
 *    "值 0"与"标签列表"在语言层面无法区分 ⇒ 裸值一律 Fatal，不猜。
 *
 * ★ 4 条反例走 `runFatalCase()`（`Errors::report()` 的 FATAL 落点是 `exit(3)`，
 *   进程内抓不到）。用例代码在 `tests/Support/fatal_cases.php`。
 */
final class OptionTest extends SmokeTestCase
{
    private function logPath(): string
    {
        return \sys_get_temp_dir() . '/drift-option-' . \getmypid() . '.log';
    }

    public function testEmptyAndPassThrough(): void
    {
        check('Option · 空表 ⇒ 空（不炸）', Option::normalize([]) === []);

        $objs = [Option::make('北京市', '110000')];
        check('Option · list<Option> 原样通过（实例身份不变）', Option::normalize($objs) === $objs);
    }

    public function testMapFormCoercesIntegerKeys(): void
    {
        $map = Option::normalize(['110000' => '北京市', '01' => '某地']);
        check(
            'Option · 映射形态：键 = 值、值 = 显示名',
            \count($map) === 2
                && $map[0]->label === '北京市' && $map[0]->value === '110000'
                && $map[1]->value === '01',
            'n=' . \count($map)
        );
        // ★ 反向自检的对象：把 `(string) $key` 去掉 ⇒ 键是 int 装不进 string 值域
        //   （TypeError ⇒ 整个进程崩），而 `['110000' => ...]` 是最常见的城市码写法。
        check(
            'Option · ★ 数字键被 PHP 转成 int ⇒ 必须收口回 string（否则这种写法根本装不进去）',
            $map[0]->value === '110000',
            'value=' . \var_export($map[0]->value, true)
        );
        check(
            'Select · 映射形态直接喂 ⇒ 受控值能命中（键类型收口的端到端证据）',
            Select::make(['110000' => '北京市'])->value('110000')->selectedIndex() === 0,
            'idx=' . Select::make(['110000' => '北京市'])->value('110000')->selectedIndex()
        );

        $combo = ComboBox::make(['110000' => '北京市'])->optionsValue();
        check(
            'ComboBox · 映射形态直接喂 ⇒ 选项表同样收口（与 Select 走同一处）',
            \count($combo) === 1 && $combo[0]->value === '110000',
            'value=' . \var_export($combo[0]->value ?? null, true)
        );
    }

    public function testFromRowsBuildsFromDatabaseShape(): void
    {
        $rows = [
            ['id' => 1, 'name' => '北京市'],
            ['id' => 2, 'name' => '上海市'],
        ];
        $from = Option::fromRows($rows, 'name', 'id');
        check(
            'Option · fromRows：行数组 ⇒ 选项表（label 取 name、value 取 id）',
            \count($from) === 2 && $from[0]->label === '北京市' && $from[1]->value === '2',
            'n=' . \count($from)
        );
        check(
            'Option · fromRows：数字 id 转成 string（与映射形态同一条收口）',
            $from[0]->value === '1',
            'value=' . \var_export($from[0]->value ?? null, true)
        );
        check(
            'Select · fromRows 直接喂 ⇒ 受控值能命中',
            Select::make(Option::fromRows($rows, 'name', 'id'))->value('2')->selectedIndex() === 1
        );
    }

    // 反例：list 里的裸值 ⇒ Fatal（身份不明确，猜就是替应用做决定）
    public function testListFormRejectsBareValues(): void
    {
        $log = $this->logPath();
        fresh($log);
        [$code, $out] = runFatalCase('optbare', $log);
        check(
            'Option · 反例：list 里的裸值 ⇒ Fatal（不猜身份）',
            $code === 3,
            '实际 ' . $code . ' / ' . $out
        );
        check(
            'Option · 反例：裸值 Fatal 时给出两种正确写法',
            \str_contains(logText($log), 'list 形态只收 Option') && \str_contains(logText($log), 'Option::of')
        );
    }

    // 反例：空字符串键 ⇒ Fatal（"没选"与"选了空"不能混）
    public function testEmptyKeyRejected(): void
    {
        $log = $this->logPath();
        fresh($log);
        [$code, $out] = runFatalCase('optemptykey', $log);
        check(
            'Option · 反例：空字符串键 ⇒ Fatal（不静默当一个真选项）',
            $code === 3,
            '实际 ' . $code . ' / ' . $out
        );
        check('Option · 反例：空键 Fatal 时说明了哨兵冲突', \str_contains(logText($log), '未选'));
    }

    // 反例：行里缺字段 ⇒ Fatal 并报出「哪一行、缺哪个字段、现有哪几列」
    //   （WPF `DisplayMemberPath` / antd `fieldNames` 拼错是**静默**的，这里不静默）
    public function testFromRowsMissingField(): void
    {
        $log = $this->logPath();
        fresh($log);
        [$code, $out] = runFatalCase('optnofield', $log);
        check(
            'Option · 反例：fromRows 缺字段 ⇒ Fatal（不静默变成整列空白）',
            $code === 3,
            '实际 ' . $code . ' / ' . $out
        );
        check(
            'Option · 反例：缺字段时报出字段名与现有列',
            \str_contains(logText($log), '没有显示名列') && \str_contains(logText($log), 'id')
        );
    }

    // 反例：行不是数组 ⇒ Fatal
    public function testFromRowsNonArrayRow(): void
    {
        $log = $this->logPath();
        fresh($log);
        [$code, $out] = runFatalCase('optbadrow', $log);
        check(
            'Option · 反例：fromRows 的行不是数组 ⇒ Fatal',
            $code === 3,
            '实际 ' . $code . ' / ' . $out
        );
    }
}
