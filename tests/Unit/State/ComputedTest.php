<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\State;

use Drift\Core\Computed;
use Drift\Dev\Smoke\State\VersionProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * `Computed`：按依赖版本缓存（`03` §4，W2/S1）。
 *
 * ★ 嵌套那条抓的是一个真会静默的坑：A 的版本取的是 `B.version()`，而 B 不重算
 *   就不进版本 ⇒ 若不先刷新下游，A 永远拿缓存（03 原稿的形状就是这样，靠这条
 *   夹具才发现）。所以"改最底层依赖 ⇒ 一次 A.get() 把整条链算完"必须存在。
 */
final class ComputedTest extends SmokeTestCase
{
    public function testCachesUntilDependencyVersionChanges(): void
    {
        $vprobe = new VersionProbe(3);
        $computedRuns = 0;
        $computed = new Computed(static function () use (&$computedRuns, $vprobe): int {
            $computedRuns++;

            return $vprobe->value() * 2;
        }, [$vprobe]);
        check(
            'Computed · 首次 get ⇒ 求值一次',
            $computed->get() === 6 && $computedRuns === 1,
            'runs=' . $computedRuns
        );
        check(
            'Computed · 无脏不重算（两次 get 之间不改依赖 ⇒ 闭包只跑一次）',
            $computed->get() === 6 && $computedRuns === 1,
            'runs=' . $computedRuns
        );
        check('Computed · 版本号没变就不变', $computed->version() === 1, 'version=' . $computed->version());

        $vprobe->bump(); // 版本 +1、值不动 ⇒ 必须重算
        check(
            'Computed · 依赖版本变 ⇒ 重算（闭包跑第二次）',
            $computed->get() === 6 && $computedRuns === 2,
            'runs=' . $computedRuns
        );
        check('Computed · 求值成功自己也进一版（可嵌套）', $computed->version() === 2, 'version=' . $computed->version());

        $this->flushChecks();
    }

    public function testNestedComputedRefreshesWholeChain(): void
    {
        // 嵌套：A 依赖 B，B 依赖 C；改 C ⇒ 一次 A.get() 把链算完
        $cProbe = new VersionProbe(1);
        $bRuns = 0;
        $computedB = new Computed(static function () use (&$bRuns, $cProbe): int {
            $bRuns++;

            return $cProbe->value() + 10;
        }, [$cProbe]);
        $aRuns = 0;
        $computedA = new Computed(static function () use (&$aRuns, $computedB): int {
            $aRuns++;

            return $computedB->get() * 2;
        }, [$computedB]);
        check(
            'Computed · 嵌套首次求值（A→B→C 一次算完）',
            $computedA->get() === 22 && $aRuns === 1 && $bRuns === 1,
            'a=' . var_export($computedA->get(), true) . ' aRuns=' . $aRuns . ' bRuns=' . $bRuns
        );
        $cProbe->bump();
        check(
            'Computed · 改最底层依赖 ⇒ 一次 A.get() 把整条链算完',
            $computedA->get() === 22 && $aRuns === 2 && $bRuns === 2,
            'aRuns=' . $aRuns . ' bRuns=' . $bRuns
        );

        $this->flushChecks();
    }
}
