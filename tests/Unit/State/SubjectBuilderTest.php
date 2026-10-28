<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\State;

use Drift\Dev\Smoke\LeafProbe;
use Drift\Dev\Smoke\State\FakeStore;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\State\SubjectBuilder;
use Drift\Widget\Widget;

/**
 * `SubjectBuilder`：订阅 → select → 门控 → builder（§11.3.3）。
 *
 * ★ 门控比的是**两次 select 的输出**，不是 Store 的新旧值 —— 这条判据的关键是
 *   `FakeStore::silentTouch()`（改字段不 notify）与"同值 notify"两种情形要分开验：
 *   少了前者，"重建由通知驱动"测不出来（不通知也不重建时，两种情况长得一样）。
 */
final class SubjectBuilderTest extends SmokeTestCase
{
    public function testSelectGatesOnBuilderOutput(): void
    {
        $ctx = smokeContext();

        $store = new FakeStore();
        $builderRuns = 0;
        $builderArgs = [];
        $sub = SubjectBuilder::make($store)
            ->select(static function (FakeStore $s): int { return $s->status(); })
            ->builder(static function (mixed ...$args) use (&$builderRuns, &$builderArgs): Widget {
                $builderRuns++;
                $builderArgs = $args;

                return new LeafProbe(1.0);
            });
        $subElement = $sub->createElement();
        $subElement->mount(null, $ctx);
        check(
            'State · initState 在首帧 build 之前 ⇒ 首帧就拿到选中的值',
            $builderRuns === 1 && $builderArgs === [0],
            'runs=' . $builderRuns . ' args=' . json_encode($builderArgs)
        );

        // 同值再 notify：select 输出没变 ⇒ **连脏都不标**（门控在回调里就挡掉了）
        $store->setStatus(0);
        check(
            'State · 同值 notify 被门控挡住（两次 select 输出相等）',
            $builderRuns === 1 && !$subElement->state()->needsBuild(),
            'runs=' . $builderRuns
        );

        // 改了字段但不 notify ⇒ 订阅者收不到（重建由**通知**驱动，不是由字段变动驱动）
        $store->silentTouch(5);
        check(
            'State · 改字段但不 notify ⇒ 订阅者收不到',
            $builderRuns === 1 && !$subElement->state()->needsBuild(),
            'runs=' . $builderRuns
        );

        // notify 且 select 输出变了 ⇒ 标脏
        $store->setStatus(6);
        check('State · notify 后 select 输出变了 ⇒ 标脏', $subElement->state()->needsBuild(), '没有标脏');

        // 帧末 flush 的动作。★ flush 循环（走全树消费脏标记）本身属 App 层（#22）——
        // State 层只负责"标脏标对"，这里显式 rebuild 一次来证明链路是通的。
        $subElement->rebuild();
        $subChild = $subElement->childAt(0)?->widget();
        check(
            'State · 重建后 builder 拿到 select 的新值（不是 null、不是 Store）',
            $builderRuns === 2 && $builderArgs === [6] && $subChild instanceof LeafProbe,
            'runs=' . $builderRuns . ' args=' . json_encode($builderArgs)
        );

        // ② 卸载后退订 —— 没有"僵尸更新"，且**不依赖用户写 dispose()**
        $subElement->unmount();
        $store->setStatus(9);
        check(
            'State · unmount 后退订（订阅袋全退，无僵尸更新）',
            $builderRuns === 2 && !$subElement->state()->needsBuild(),
            'runs=' . $builderRuns
        );

        $this->flushChecks();
    }

    public function testWithoutSelectBuilderTakesNoArguments(): void
    {
        $ctx = smokeContext();

        $store = new FakeStore();

        // ③ 无 select ⇒ builder 收 0 个参数（★ 不是统一传 null）
        $plainRuns = 0;
        $plainArgs = null;
        $plain = SubjectBuilder::make($store)->builder(static function (mixed ...$args) use (&$plainRuns, &$plainArgs): Widget {
            $plainRuns++;
            $plainArgs = $args;

            return new LeafProbe(1.0);
        });
        $plainElement = $plain->createElement();
        $plainElement->mount(null, $ctx);
        check(
            'State · 无 select ⇒ builder 收 0 个参数',
            $plainRuns === 1 && $plainArgs === [],
            'args=' . json_encode($plainArgs)
        );
        $store->setStatus(11);
        check('State · 无 select ⇒ 任何 notify 都标脏', $plainElement->state()->needsBuild(), '没有标脏');
        $plainElement->rebuild();
        check(
            'State · 无 select ⇒ 重建后 builder 仍收 0 个参数',
            $plainRuns === 2 && $plainArgs === [],
            'args=' . json_encode($plainArgs)
        );
        $plainElement->unmount();

        $this->flushChecks();
    }
}
