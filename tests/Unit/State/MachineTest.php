<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\State;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\State\Effects;
use Drift\State\Machine;
use Drift\State\Seq;

/** 探针状态：int 常量 + 互斥不可变值（OptionStatus 同形，避开 enum-with-payload 的 AOT 坑） */
final class MachineProbeState
{
    public const int IDLE = 0;

    public const int WAITING = 1;

    public const int DONE = 2;

    private function __construct(
        public readonly int $kind,
        public readonly int $attempt,
        public readonly string $query,
    ) {
    }

    public static function idle(): self
    {
        return new self(self::IDLE, 0, '');
    }

    public static function waiting(int $attempt, string $query): self
    {
        return new self(self::WAITING, $attempt, $query);
    }

    public static function done(string $query): self
    {
        return new self(self::DONE, 0, $query);
    }
}

final class MachineProbeEvent
{
    public const int QUERY = 0;

    public const int ARRIVED = 1;

    private function __construct(
        public readonly int $kind,
        public readonly int $attempt,
        public readonly string $query,
    ) {
    }

    public static function query(string $query): self
    {
        return new self(self::QUERY, 0, $query);
    }

    public static function arrived(int $attempt, string $query): self
    {
        return new self(self::ARRIVED, $attempt, $query);
    }
}

/**
 * `Machine` / `Seq` / `Effects`：纯 step + attempt fence（2026-11-21）。
 *
 * ★ 判据都从**外部可见结果**读（状态、命令、副作用计数），不碰私有字段。
 * ★ fence 那条必须同时钉"过期的被挡"与"当前的落地"：只测挡，把 step 写成
 *   永远 return new Effects($s, []) 也能过。
 */
final class MachineTest extends SmokeTestCase
{
    /** @return \Closure(mixed, mixed, Seq): Effects */
    private function step(): \Closure
    {
        return static function (mixed $s, mixed $e, Seq $seq): Effects {
            $state = $s;
            $event = $e;

            if ($event->kind === MachineProbeEvent::QUERY) {
                return new Effects(
                    MachineProbeState::waiting($seq->next(), $event->query),
                    ['schedule:' . $event->query, 'track:' . $event->query],
                );
            }
            // ★ 这一行就是 attempt fence：号不对的答案什么也不做。
            if ($state->kind === MachineProbeState::WAITING
                && $event->attempt === $state->attempt) {
                return new Effects(
                    MachineProbeState::done($state->query),
                    ['land:' . $event->query],
                );
            }

            return new Effects($state, []);
        };
    }

    public function testSeqIsMonotonicAndPerInstance(): void
    {
        $a = new Seq();
        $b = new Seq();

        check('Seq · 从 1 起', $a->next() === 1);
        check('Seq · 单调', $a->next() === 2 && $a->next() === 3);
        check('Seq · 另一个实例独立计数', $b->next() === 1);
    }

    public function testStepAdvancesStateAndReturnsCommandsInOrder(): void
    {
        $m = new Machine(MachineProbeState::idle(), $this->step());

        $fx = $m->dispatch(MachineProbeEvent::query('北'));
        check('Machine · 状态换成 Waiting', $m->state()->kind === MachineProbeState::WAITING);
        check('Machine · attempt 由 Seq 发出', $m->state()->attempt === 1);
        check(
            'Effects · 命令按序原样交回（不合并、不丢弃）',
            $fx->commands === ['schedule:北', 'track:北'],
            'cmds=[' . \implode(',', $fx->commands) . ']',
        );
        check('Effects · 新状态与 Machine 状态一致', $fx->state === $m->state());
    }

    public function testStaleAttemptIsFencedWhileCurrentLands(): void
    {
        $m = new Machine(MachineProbeState::idle(), $this->step());
        $m->dispatch(MachineProbeEvent::query('北'));          // attempt 1
        $m->dispatch(MachineProbeEvent::query('北京'));        // attempt 2 —— 作废 1

        $stale = $m->dispatch(MachineProbeEvent::arrived(1, '北'));
        check('Machine · 过期答案被挡：状态不动', $m->state()->kind === MachineProbeState::WAITING);
        check('Machine · 过期答案被挡：零命令', $stale->commands === []);

        $current = $m->dispatch(MachineProbeEvent::arrived(2, '北京'));
        check('Machine · 当前答案落地：状态 Done', $m->state()->kind === MachineProbeState::DONE);
        check('Machine · 当前答案落地：带查询词', $m->state()->query === '北京');
        check(
            'Machine · 当前答案落地：恰好一条 land 命令',
            $current->commands === ['land:北京'],
            'cmds=[' . \implode(',', $current->commands) . ']',
        );
    }

    public function testMachineDoesNotExecuteCommands(): void
    {
        $ran = [];
        $step = static function (mixed $s, mixed $e, Seq $seq) use (&$ran): Effects {
            return new Effects($s, [function () use (&$ran): void {
                $ran[] = 'executed';
            }]);
        };

        $m = new Machine(MachineProbeState::idle(), $step);
        $fx = $m->dispatch(MachineProbeEvent::query('x'));
        check('Machine · dispatch 只交回命令，不执行', $ran === []);

        ($fx->commands[0])();
        check('Machine · 调用方执行才产生副作用', $ran === ['executed']);
    }

    public function testMachineOwnsItsSequence(): void
    {
        $m1 = new Machine(MachineProbeState::idle(), $this->step());
        $m2 = new Machine(MachineProbeState::idle(), $this->step());

        $m1->dispatch(MachineProbeEvent::query('a'));
        $m2->dispatch(MachineProbeEvent::query('b'));
        check('Machine · 两台机器各自发号，互不串号', $m1->state()->attempt === 1 && $m2->state()->attempt === 1);
    }
}
