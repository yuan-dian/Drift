<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\State;

use Drift\Dev\Smoke\State\FakeStore;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;

/**
 * RT-3：一个订阅者抛异常，不妨碍其余订阅者收到通知（§11.3）。
 *
 * ★ 判据是"a、b、c 三个都跑了"——若实现用 `foreach` 直接调回调，B 抛异常后 C
 *   永远收不到，但**画面看起来一切正常**，只有这里能抓。
 * ★ 必须临时关掉 debug：`Recoverable` 在 debug 下会中止进程，那就测不着"隔离"了。
 *   关掉后异常必须仍然**响亮**（写 Errors 出口、级别 Recoverable），不能静默吞掉。
 */
final class SubscriptionIsolationTest extends SmokeTestCase
{
    public function testThrowingSubscriberIsIsolatedAndLogged(): void
    {
        $log = \sys_get_temp_dir() . '/drift-sub-iso-' . \getmypid() . '.log';
        $debugWas = Errors::isDebug();
        Errors::setDebug(false); // Recoverable 在 debug 会中止，先关掉才测得着"隔离"

        try {
            fresh($log);

            $ran = [];
            $ownerA = new FakeStore();
            $ownerB = new FakeStore();
            $ownerC = new FakeStore();
            $iso = new FakeStore();
            $iso->subscribeFor($ownerA, static function () use (&$ran): void { $ran[] = 'a'; });
            $iso->subscribeFor($ownerB, static function () use (&$ran): void {
                $ran[] = 'b';
                throw new \RuntimeException('订阅者 B 炸了');
            });
            $iso->subscribeFor($ownerC, static function () use (&$ran): void { $ran[] = 'c'; });
            $iso->notifyChanged();
            check(
                'State · 一个订阅者抛异常，其余仍收到通知（RT-3）',
                $ran === ['a', 'b', 'c'],
                '实际 ' . implode(',', $ran)
            );
            check('State · 订阅者异常没有静默（写了 Errors 出口）', str_contains(logText($log), '订阅者异常已隔离'));
            check('State · 异常报的是 Recoverable 级', str_contains(logText($log), 'RECOVERABLE'));

            $iso->unsubscribeAllOf($ownerC);
            $ran = [];
            $iso->notifyChanged();
            check('State · unsubscribeAllOf 退掉整个属主的订阅', $ran === ['a', 'b'], '实际 ' . implode(',', $ran));
        } finally {
            Errors::setDebug($debugWas);
            if (is_file($log)) {
                unlink($log);
            }
            // 别把临时路径留给后面的用例（否则它们会往一个已删的文件里写）
            Errors::setLogPath('');
        }

        $this->flushChecks();
    }
}
