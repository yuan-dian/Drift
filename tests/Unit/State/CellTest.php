<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\State;

use Drift\Core\Readable;
use Drift\Core\Tracker;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\State\Cell;
use Drift\State\Equatable;

/** 值对象：只按 id 相等（模拟 `TableSelection` 一类） */
final class CellProbeId implements Equatable
{
    public function __construct(public readonly int $id, public readonly string $label)
    {
    }

    public function equals(Equatable $other): bool
    {
        return $other instanceof self && $other->id === $this->id;
    }
}

/**
 * `Cell`：值 + 相等门控 + 通知 + 读即依赖（2026-11-21）。
 *
 * ★ 判据都不读私有字段：看"通知到没到 / 版本走没走 / 采集栈里有没有它"。
 * ★ 相等门控那条必须同时钉"同值不通知"与"不同值通知"：只测前者的话，
 *   把 `set()` 改成直接 return 也能过。
 */
final class CellTest extends SmokeTestCase
{
    public function testValueAndNotification(): void
    {
        $cell = Cell::of(1)->named('n');
        $hits = 0;
        $cell->subscribeFor(new \stdClass(), function () use (&$hits): void {
            $hits++;
        });

        check('Cell · 初值', $cell->get() === 1, 'v=' . \var_export($cell->get(), true));
        check('Cell · 是可被控件接收的 Readable', $cell instanceof Readable);

        $cell->set(2);
        check('Cell · 写新值 ⇒ 通知一次', $hits === 1, 'hits=' . $hits);
        check('Cell · 写后读到新值', $cell->get() === 2);

        $cell->set(2);
        check('Cell · 写同值 ⇒ 不通知（相等门控）', $hits === 1, 'hits=' . $hits);

        check('Cell · 版本随通知前进', $cell->version() === 1, 'version=' . $cell->version());

        $cell->update(static fn(int $n): int => $n + 10);
        check('Cell · update 按旧值算新值', $cell->get() === 12 && $hits === 2, 'hits=' . $hits);

        $this->flushChecks();
    }

    /**
     * 值对象门控：`Equatable` 自己回答"算不算同一个值"。
     *
     * ★ 没有这条，`TableSelection` 一类每次新建的实例都会通知 —— 选中集从 3 个
     *   变回 3 个（同一个集合）也重建一遍。
     */
    public function testEquatableGating(): void
    {
        $cell = Cell::of(new CellProbeId(1, 'a'));
        $hits = 0;
        $cell->subscribeFor(new \stdClass(), function () use (&$hits): void {
            $hits++;
        });

        $cell->set(new CellProbeId(1, 'b'));
        check('Cell · 值对象 equals 相等 ⇒ 不通知（标签不同也算同一个）', $hits === 0, 'hits=' . $hits);

        $cell->set(new CellProbeId(2, 'b'));
        check('Cell · 值对象不等 ⇒ 通知', $hits === 1, 'hits=' . $hits);

        $this->flushChecks();
    }

    public function testCustomEqualsAndDistinctOff(): void
    {
        $cell = Cell::of(['id' => 1, 'n' => 0]);
        $hits = 0;
        $cell->equalsBy(static fn(array $a, array $b): bool => $a['id'] === $b['id']);
        $cell->subscribeFor(new \stdClass(), function () use (&$hits): void {
            $hits++;
        });

        $cell->set(['id' => 1, 'n' => 99]);
        check('Cell · equalsBy 只看 id ⇒ 不通知', $hits === 0, 'hits=' . $hits);

        $cell->set(['id' => 2, 'n' => 99]);
        check('Cell · equalsBy 判定不等 ⇒ 通知', $hits === 1, 'hits=' . $hits);

        $plain = Cell::of(1)->distinct(false);
        $once = 0;
        $plain->subscribeFor(new \stdClass(), function () use (&$once): void {
            $once++;
        });
        $plain->set(1);
        check('Cell · distinct(false) ⇒ 写同值也通知', $once === 1, 'hits=' . $once);

        $this->flushChecks();
    }

    /**
     * build 期写自己的格子必须走 `State` 的 E-FLUSH-CYCLE 守卫。
     *
     * ★ 判据在子进程：Fatal 走 `exit(3)`，进程内接不住。
     * ★ 同时钉住"守卫不在 Tracker 上"：跨 State 的 build 期写是框架原有契约
     *   （`chainwrite`），Cell 不能把它一起误杀 —— 那边由 `ReactiveRebuildTest`
     *   的收敛用例覆盖。
     */
    public function testWritingOwnCellDuringBuildFails(): void
    {
        $log = \sys_get_temp_dir() . '/drift-cell-write-' . \getmypid() . '.log';
        @\unlink($log);

        [$code, $out] = runFatalCase('cell-buildwrite', $log);
        $text = \is_file($log) ? (string) \file_get_contents($log) : '';
        @\unlink($log);

        check(
            'Cell · build 期写自己读过的格子 ⇒ 当帧 Fatal',
            $code === 3,
            'code=' . $code . ' out=' . \trim($out),
        );
        check(
            'Cell · 走的是 E-FLUSH-CYCLE（不是新造的错误）',
            \str_contains($text, 'E-FLUSH-CYCLE'),
            $text === '' ? '日志为空' : \mb_substr($text, 0, 120),
        );

        $this->flushChecks();
    }

    public function testDiagnosticName(): void
    {
        $cell = Cell::of(1)->named('counter');
        check('Cell · named 返回自身（可串在 of 之后）', $cell->describe() === 'Cell(counter)');
        $this->flushChecks();
    }

    /**
     * 读即依赖：`get()` 记进采集帧，`peek()` 不记。
     *
     * ★ `peek()` 存在的理由是**让意图可见**（"这里读了但不跟"），所以它必须
     *   真的不记 —— 记了就等于没有 `peek`，只是换了个名字。
     */
    public function testGetRecordsAndPeekDoesNot(): void
    {
        $cell = Cell::of(7);

        Tracker::enter();
        try {
            $value = $cell->get();
        } finally {
            $read = Tracker::leave();
        }
        check(
            'Cell · get() 进采集栈',
            $value === 7 && \count($read) === 1 && \reset($read) === $cell,
            'read=' . \count($read),
        );

        Tracker::enter();
        try {
            $cell->peek();
        } finally {
            $read = Tracker::leave();
        }
        check('Cell · peek() 不进采集栈', $read === [], 'read=' . \count($read));

        $this->flushChecks();
    }
}
