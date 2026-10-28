<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Widget\Each;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * Each（原方案的 ForEach —— PHP 保留字撞名）：key 驱动的最小列表。
 *
 * 判据（与 `tools/smoke.php` ㉑ 段一致）：
 *   ① keyFn 产出的 key 真的落到子 widget 上（不是"能调用"就算完）；
 *   ② key 相同 ⇒ diff 复用同一个 Element（State 不重置）；列表重排 ⇒ key 跟随 item
 *      而不是跟随槽位；
 *   ③ 空列表铺 0 个子，不塌不炸。
 *
 * ★ 重复 key / int 与字符串 key 撞车的**子进程**判据留在 `tools/smoke.php`：
 *   它们靠 `runCase(__FILE__, 'eachdup')` 起子进程验 `Errors` 上报与退出码，
 *   而 `runCase` 依赖 `__FILE__` 是 smoke 入口。
 */
final class EachTest extends SmokeTestCase
{
    /** @var list<array{id: int, name: string}> */
    private const USERS = [
        ['id' => 7, 'name' => '甲'],
        ['id' => 3, 'name' => '乙'],
        ['id' => 9, 'name' => '丙'],
    ];

    public function testKeyFnKeysReachChildWidgets(): void
    {
        $eachWidget = Each::make(fn(array $u): int => $u['id'])
            ->items(self::USERS, fn(array $u): Widget => Text::make($u['name'])->body());

        // keyFn 产出的 key 真的落到了子 widget 上 —— 不是"能调用"就算完
        $eachKids = $eachWidget->itemWidgets();
        check('Each · 每个 item 都带上了 keyFn 产出的 key', count($eachKids) === 3 && $eachKids[1]->identity()?->value === 3, var_export($eachKids[1]->identity()?->value, true));

        $this->flushChecks();
    }

    public function testMountLaysOutChildrenAndDiffReusesByKey(): void
    {
        $ctx = smokeContext();
        $eachWidget = Each::make(fn(array $u): int => $u['id'])
            ->items(self::USERS, fn(array $u): Widget => Text::make($u['name'])->body());

        // key 改变了 diff 身份：同位置换新控件但 key 相同 ⇒ 复用；key 不同 ⇒ 卸旧挂新
        $eachRoot = $eachWidget->createElement();
        $eachRoot->mount(null, $ctx);
        check('Each · 挂载铺出 3 个子 Element', $eachRoot->childCount() === 3);
        $firstKidElement = $eachRoot->childAt(0);
        $eachRoot->update(
            Each::make(fn(array $u): int => $u['id'])->items(self::USERS, fn(array $u): Widget => Text::make($u['name'])->body())
        );
        check(
            'Each · key 相同 ⇒ diff 复用同一个 Element（State 不重置）',
            $eachRoot->childAt(0) === $firstKidElement
        );

        $reordered = [['id' => 3, 'name' => '乙'], ['id' => 7, 'name' => '甲'], ['id' => 9, 'name' => '丙']];
        $eachRoot->update(
            Each::make(fn(array $u): int => $u['id'])->items($reordered, fn(array $u): Widget => Text::make($u['name'])->body())
        );
        check(
            'Each · 列表重排 ⇒ key 跟随 item（不是跟随槽位）',
            $eachRoot->childAt(0)?->widget()->identity()?->value === 3
                && $eachRoot->childAt(1)?->widget()->identity()?->value === 7
        );

        $this->flushChecks();
    }

    public function testEmptyListYieldsNoChildren(): void
    {
        $ctx = smokeContext();

        // 空列表 ⇒ 0 个子，不炸
        $emptyRoot = Each::make(fn(array $u): int => $u['id'])->items([], fn(array $u): Widget => Text::make('x'))->createElement();
        $emptyRoot->mount(null, $ctx);
        check('Each · 空列表铺出 0 个子（不塌、不炸）', $emptyRoot->childCount() === 0);

        $this->flushChecks();
    }

    /**
     * 同层重复 key ⇒ **Fatal**（绝不静默：静默的后果是 diff 复用错乱，R4）。
     *
     * ★ 必须子进程：`Errors::report()` 走 `abort()`（`exit(3)`），进程内 `try/catch`
     *   接不住 —— 写在同一进程里的现象是"smoke 静默截断、退出码仍 0"。
     *
     * @see tests/Support/fatal_cases.php `eachdup`
     */
    public function testDuplicateKeyFailsLoudly(): void
    {
        $log = \sys_get_temp_dir() . '/drift-eachdup-' . \getmypid() . '.log';
        @\unlink($log);

        [$code, $out] = runFatalCase('eachdup', $log);
        $text = \is_file($log) ? (string) \file_get_contents($log) : '';
        @\unlink($log);

        check(
            'Each · 同层重复 key ⇒ 退出码 3（不静默覆盖）',
            $code === 3,
            'code=' . $code . ' out=' . \trim($out)
        );
        check(
            'Each · 重复 key 写了日志（含「重复的 key」）',
            \str_contains($text, '重复的 key'),
            $text === '' ? '日志为空' : \mb_substr($text, 0, 120)
        );

        $this->flushChecks();
    }

    /**
     * key `1` 与 `'1'` **不**算重复 —— 与 `Key::same` 同口径，不做类型互转。
     *
     * ★★ 判据是 `code === 0` **且**输出含 `reached-end-without-fatal`，
     *   不是 `code !== 3`：后者会被**崩溃**满足（实测 255）—— 这条断言在 smoke 里
     *   就是这么绿了很久的（`ChainPage` 漏 `parent::__construct()`，见 `FlushCycleTest`）。
     */
    public function testIntAndStringKeysAreNotDuplicates(): void
    {
        [$code, $out] = runFatalCase('eachkeytag');

        check(
            'Each · key 1 与 \'1\' 不算重复（收敛：走到末尾且不崩）',
            $code === 0 && \str_contains($out, 'reached-end-without-fatal'),
            'code=' . $code . ' out=' . \trim($out)
        );

        $this->flushChecks();
    }
}
