<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\State;

use Drift\Core\Computed;
use Drift\Core\Readable;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\State\Cell;

/**
 * `Computed::of()`：求值期自动收集依赖（2026-11-21）。
 *
 * ★ 旧版的两个失败模式在这里各有一条判据：
 *   ① 漏写依赖 ⇒ 永不重算（这里靠"没写也重算"反过来钉）；
 *   ② 写了不相干的依赖 ⇒ 白重算（"不是依赖就不该重算"）。
 * ★ 分支那条是真会静默的：`$flag ? $a : $b` 换了分支之后，依赖集合必须跟着换，
 *   否则"切回来时永远拿缓存"。
 */
final class ComputedAutoDepsTest extends SmokeTestCase
{
    public function testCollectsDependenciesFromReads(): void
    {
        $a = Cell::of(1)->named('a');
        $b = Cell::of(2)->named('b');
        $sum = Computed::of(fn(): int => $a->get() + $b->get());

        check('Computed 自动依赖 · 首次求值', $sum->get() === 3, 'v=' . $sum->get());
        check('Computed 自动依赖 · 无脏不重算', $sum->get() === 3 && $sum->runs() === 1, 'runs=' . $sum->runs());

        $a->set(10);
        check('Computed 自动依赖 · 改依赖 ⇒ 重算', $sum->get() === 12 && $sum->runs() === 2, 'runs=' . $sum->runs());

        $b->set(20);
        check('Computed 自动依赖 · 改第二个依赖也算', $sum->get() === 30 && $sum->runs() === 3, 'runs=' . $sum->runs());

        $this->flushChecks();
    }

    public function testIgnoresCellsThatWereNotRead(): void
    {
        $used = Cell::of(1);
        $unused = Cell::of(100);
        $computed = Computed::of(static fn(): int => $used->get() * 2);

        check('Computed 自动依赖 · 前置', $computed->get() === 2 && $computed->runs() === 1);

        $unused->set(999);
        check(
            'Computed 自动依赖 · 没读过的格子变了 ⇒ 不重算',
            $computed->get() === 2 && $computed->runs() === 1,
            'runs=' . $computed->runs()
        );

        $used->set(3);
        check('Computed 自动依赖 · 读过的变了 ⇒ 重算', $computed->get() === 6 && $computed->runs() === 2);

        $this->flushChecks();
    }

    public function testBranchSwitchRebindsDependencies(): void
    {
        $flag = Cell::of(true);
        $left = Cell::of(1);
        $right = Cell::of(100);
        $pick = Computed::of(static fn(): int => $flag->get() ? $left->get() : $right->get());

        check('Computed 自动依赖 · 分支 A', $pick->get() === 1 && $pick->runs() === 1);

        $right->set(200);   // 当前分支没读它 ⇒ 不是依赖
        check(
            'Computed 自动依赖 · 未选中的分支改了 ⇒ 不重算',
            $pick->get() === 1 && $pick->runs() === 1,
            'runs=' . $pick->runs()
        );

        $flag->set(false);
        check(
            'Computed 自动依赖 · 换分支 ⇒ 读到另一个值',
            $pick->get() === 200 && $pick->runs() === 2,
            'runs=' . $pick->runs()
        );

        $left->set(5);      // 换分支后它不再是依赖
        check(
            'Computed 自动依赖 · 换分支后旧依赖不再触发重算',
            $pick->get() === 200 && $pick->runs() === 2,
            'runs=' . $pick->runs()
        );

        $this->flushChecks();
    }

    public function testNestedComputedChainsRefresh(): void
    {
        $c = Cell::of(1);
        $b = Computed::of(static fn(): int => $c->get() + 10);
        $a = Computed::of(static fn(): int => $b->get() * 2);

        check('Computed 自动依赖 · 嵌套首算', $a->get() === 22 && $b->runs() === 1);

        $c->set(2);
        check(
            'Computed 自动依赖 · 改最底层 ⇒ 一次读把整链算完',
            $a->get() === 24 && $a->runs() === 2 && $b->runs() === 2,
            'a=' . $a->runs() . ' b=' . $b->runs()
        );

        $this->flushChecks();
    }

    /**
     * `sources()` 必须展平到可订阅的源：嵌套 `Computed` 没有推送通道，
     * 控件只能订它底下的格子。
     */
    public function testSourcesFlattenToCells(): void
    {
        $c = Cell::of(1);
        $b = Computed::of(static fn(): int => $c->get() + 1);
        $a = Computed::of(static fn(): int => $b->get() * 2);
        $a->get();

        $sources = $a->sources();
        check(
            'Computed 自动依赖 · sources() 展平成格子（不是中间派生值）',
            \count($sources) === 1 && \reset($sources) === $c,
            'n=' . \count($sources)
        );

        $this->flushChecks();
    }
}
