<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\State;

use Drift\Dev\Smoke\LeafProbe;
use Drift\Dev\Smoke\State\FakeStore;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Widget;

/**
 * `State` 的资源生命周期契约：统一清理袋 + 命名订阅槽（2026-11-10）。
 *
 * ★ 判据不与实现同源：读的是「清理跑没跑 / 顺序对不对 / 通知到没到」，
 *   不读 `State` 的私有字段。
 * ★ 每条都配了前置断言：只看"跑了"在"从不跑"的实现上会假绿。
 */
final class StateDisposersTest extends SmokeTestCase
{
    public function testOnDisposeRunsInOrderOnUnmount(): void
    {
        [$element, $state] = self::mount();

        check(
            'State · 前置：挂载后清理还没跑（否则下面"unmount 才跑"恒真）',
            $state->cleanups === []
        );

        $element->unmount();
        check(
            'State · unmount 跑全部 onDispose，按登记序（FIFO）',
            $state->cleanups === ['first', 'second'],
            'cleanups=' . \implode(',', $state->cleanups)
        );

        $this->flushChecks();
    }

    public function testWatchAndWatchSlotBothDroppedOnUnmount(): void
    {
        [$element, $state] = self::mount();

        $state->watchSource->setStatus(1);
        $state->firstSource->setStatus(1);
        check(
            'State · 前置：两类订阅都接到通知',
            $state->watchHits === 1 && $state->slotHits === 1,
            \sprintf('watch=%d slot=%d', $state->watchHits, $state->slotHits)
        );

        $element->unmount();
        $state->watchSource->setStatus(2);
        $state->firstSource->setStatus(2);
        check(
            'State · unmount 后 watch() 与 watchSlot() 都退订（无僵尸更新）',
            $state->watchHits === 1 && $state->slotHits === 1,
            \sprintf('watch=%d slot=%d', $state->watchHits, $state->slotHits)
        );

        $this->flushChecks();
    }

    public function testWatchSlotRebindsWithoutZombieOrAccumulation(): void
    {
        [$element, $state] = self::mount();

        $state->firstSource->setStatus(1);
        check('State · 前置：初始槽接到通知', $state->slotHits === 1);

        $second = new FakeStore();
        $state->nextSource = $second;
        $element->update(new DisposerProbeWidget());
        $state->firstSource->setStatus(2);
        check(
            'State · 换 source ⇒ 旧订阅先退（僵尸通知不再到）',
            $state->slotHits === 1,
            'slotHits=' . $state->slotHits
        );

        $second->setStatus(3);
        check('State · 换 source ⇒ 新订阅接上（通知能到）', $state->slotHits === 2);

        // 再换一次：若同名槽是"追加"而非"替换"，这一下会留下两条订阅 ⇒ +2
        $third = new FakeStore();
        $state->nextSource = $third;
        $element->update(new DisposerProbeWidget());
        $state->firstSource->setStatus(3);
        $second->setStatus(4);
        check(
            'State · 连续换绑：前两个 source 都不再触发',
            $state->slotHits === 2,
            'slotHits=' . $state->slotHits
        );

        $third->setStatus(5);
        check(
            'State · 连续换绑不累加订阅（一次通知恰好 +1）',
            $state->slotHits === 3,
            'slotHits=' . $state->slotHits
        );

        $element->unmount();
        $third->setStatus(6);
        check('State · unmount 后当前槽也退订', $state->slotHits === 3, 'slotHits=' . $state->slotHits);

        $this->flushChecks();
    }

    /** @return array{0: \Drift\Widget\Element, 1: DisposerProbeState} */
    private static function mount(): array
    {
        $element = (new DisposerProbeWidget())->createElement();
        $element->mount(null, smokeContext());
        $state = $element->state();
        if (!$state instanceof DisposerProbeState) {
            self::fail('夹具坏了：没造出 DisposerProbeState');
        }

        return [$element, $state];
    }
}

/** @internal 夹具：只有 State，没有任何业务逻辑。 */
final class DisposerProbeWidget extends StatefulWidget
{
    public function createState(): State
    {
        return new DisposerProbeState();
    }
}

/**
 * @internal 夹具：把清理袋与订阅槽的行为摊成可读计数。
 *
 * 这些字段不被 `build()` 读 —— 所以断言不会自证。
 */
final class DisposerProbeState extends State
{
    /** @var list<string> 清理执行顺序 */
    public array $cleanups = [];

    public int $watchHits = 0;
    public int $slotHits = 0;

    public FakeStore $watchSource;
    public FakeStore $firstSource;

    /** 换描述时递给 State 的新 source（模拟父重建换了数据源） */
    public ?FakeStore $nextSource = null;

    private ?FakeStore $bound = null;

    public function initState(): void
    {
        $this->onDispose(function (): void {
            $this->cleanups[] = 'first';
        });
        $this->onDispose(function (): void {
            $this->cleanups[] = 'second';
        });

        $this->watchSource = new FakeStore();
        $this->watch($this->watchSource, function (): void {
            $this->watchHits++;
        });

        $this->firstSource = new FakeStore();
        $this->bound = $this->firstSource;
        $this->watchSlot('source', $this->firstSource, function (): void {
            $this->slotHits++;
        });
    }

    public function didUpdateWidget(Widget $widget): void
    {
        parent::didUpdateWidget($widget);

        // 与 `SubjectBuilderState` 同款：只在 source 真变了才重绑
        if ($this->nextSource === null || $this->nextSource === $this->bound) {
            return;
        }

        $this->bound = $this->nextSource;
        $this->watchSlot('source', $this->bound, function (): void {
            $this->slotHits++;
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        return new LeafProbe($ctx->width);
    }
}
