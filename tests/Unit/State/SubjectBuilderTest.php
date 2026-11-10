<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\State;

use Drift\Dev\Smoke\LeafProbe;
use Drift\Dev\Smoke\State\FakeStore;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\State\Equatable;
use Drift\State\Subject;
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

    /**
     * 值对象的门控（2026-11-03）：默认 `===` 挡不住"每次新建的值对象"，
     * 要值语义就得显式给 `equals()`。
     *
     * ## 为什么这条判据值钱
     *
     * `apps/*` 两个 `StatusBar` 各自手写了 100 行订阅器，**唯一**原因是
     * `SubjectBuilder` 的默认门控对值对象恒 false（`statusLine()` 每次 `new`
     * 一个 `StatusMessage`）。而手写的那 100 行里，订阅 / 退订 / 首帧 / 门控
     * 四件事与库里这份完全重复。
     *
     * ⇒ 缺的不是"订阅原语"，是**判等策略的注入口**。补齐之后应用侧才有理由删掉
     *   自己那份 —— 本用例同时钉住"默认行为没被改动"（否则是更贵的错：
     *   现有订阅者会静默少重建）。
     */
    public function testEqualsGatesValueObjects(): void
    {
        $ctx = smokeContext();
        $store = new ValueSubject();

        // ① 不给 equals ⇒ 默认 `===`，新实例永不相等 ⇒ 每次都重建（行为不变）
        $plainRuns = 0;
        $plain = SubjectBuilder::make($store)
            ->select(static fn (ValueSubject $s): Value => new Value($s->text()))
            ->builder(static function (mixed $v) use (&$plainRuns): Widget {
                $plainRuns++;

                return new LeafProbe(1.0);
            });
        $plainElement = $plain->createElement();
        $plainElement->mount(null, $ctx);

        $store->setText('同');
        check(
            'State · 未给 equals ⇒ 值对象虽内容相同也标脏（默认仍是 ===，行为未变）',
            $plainElement->state()->needsBuild(),
            '默认门控被改动了 ⇒ 现有订阅者会静默少重建'
        );
        $plainElement->unmount();

        // ② 给了 equals ⇒ 内容相同不重建，内容变了才重建
        $gatedRuns = 0;
        $gatedArgs = [];
        $gated = SubjectBuilder::make($store)
            ->select(static fn (ValueSubject $s): Value => new Value($s->text()))
            ->equals(static fn (Value $a, Value $b): bool => $a->equals($b))
            ->builder(static function (mixed $v) use (&$gatedRuns, &$gatedArgs): Widget {
                $gatedRuns++;
                $gatedArgs[] = $v->text;

                return new LeafProbe(1.0);
            });
        $gatedElement = $gated->createElement();
        $gatedElement->mount(null, $ctx);
        check(
            'State · 给了 equals ⇒ 首帧仍拿到值（门控不挡首帧）',
            $gatedRuns === 1 && $gatedArgs === ['同'],
            'runs=' . $gatedRuns . ' args=' . json_encode($gatedArgs)
        );

        $store->setText('同');   // 内容不变，但 select 会 new 一个新对象
        check(
            'State · 内容相同的新实例被 equals 挡住（不重建）',
            !$gatedElement->state()->needsBuild(),
            '值对象门控没生效 ⇒ 应用侧没理由删掉自己那份 100 行'
        );

        $store->setText('不同');
        check('State · 内容变了 ⇒ 标脏', $gatedElement->state()->needsBuild(), '没标脏');
        $gatedElement->rebuild();
        check(
            'State · 重建后拿到新值',
            $gatedRuns === 2 && $gatedArgs === ['同', '不同'],
            'runs=' . $gatedRuns . ' args=' . json_encode($gatedArgs)
        );
        $gatedElement->unmount();

        $this->flushChecks();
    }

    public function testWithoutSelectBuilderTakesNoArguments(): void    {
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

    /**
     * 2026-11-07：值对象 `implements Equatable` 后，不给 `equals()` 也按值门控。
     *
     * ★ 不能取代 `testEqualsGatesValueObjects()` ①：那边钉的是「未 opt-in 的普通
     *   值对象仍走 `===`」。两条合起来才证明默认行为没有被整体改掉。
     */
    public function testEquatableGatesByValueWithoutExplicitEquals(): void
    {
        $ctx = smokeContext();
        $store = new ValueSubject();
        $runs = 0;
        $args = [];
        $widget = SubjectBuilder::make($store)
            ->select(static fn(ValueSubject $s): EquatableValue => new EquatableValue($s->text()))
            ->builder(static function (mixed $v) use (&$runs, &$args): Widget {
                $runs++;
                $args[] = $v->text;

                return new LeafProbe(1.0);
            });
        $element = $widget->createElement();
        $element->mount(null, $ctx);
        check(
            'State · Equatable 首帧照常拿到值（门控不挡首帧）',
            $runs === 1 && $args === ['同'],
            'runs=' . $runs . ' args=' . json_encode($args)
        );

        $store->setText('同');
        check(
            'State · Equatable 内容相同的新实例被默认门控挡住',
            !$element->state()->needsBuild(),
            '默认门控没认 Equatable ⇒ 每次通知都重建'
        );

        $store->setText('不同');
        check('State · Equatable 内容变了 ⇒ 标脏', $element->state()->needsBuild(), '没标脏');
        $element->rebuild();
        check(
            'State · 重建后拿到新值',
            $runs === 2 && $args === ['同', '不同'],
            'runs=' . $runs . ' args=' . json_encode($args)
        );
        $element->unmount();
        $this->flushChecks();
    }

    /** 显式 `equals()` 仍是最高优先级的覆盖口。 */
    public function testExplicitEqualsStillOverridesEquatable(): void
    {
        $ctx = smokeContext();
        $store = new ValueSubject();
        $widget = SubjectBuilder::make($store)
            ->select(static fn(ValueSubject $s): EquatableValue => new EquatableValue($s->text()))
            ->equals(static fn(EquatableValue $a, EquatableValue $b): bool => false)
            ->builder(static fn(mixed $v): Widget => new LeafProbe(1.0));
        $element = $widget->createElement();
        $element->mount(null, $ctx);

        $store->setText('同');
        check(
            'State · 显式 equals() 覆盖默认门控（同内容也标脏）',
            $element->state()->needsBuild(),
            '覆盖口被默认门控吃掉了'
        );
        $element->unmount();
        $this->flushChecks();
    }

    /** `null → 值对象` 必须判为变化：只有两边都是 Equatable 才交给 `equals()`。 */
    public function testNullToEquatableStillCountsAsChange(): void
    {
        $ctx = smokeContext();
        $store = new MaybeSubject();
        $widget = SubjectBuilder::make($store)
            ->select(static fn(MaybeSubject $s): ?EquatableValue => $s->value())
            ->builder(static fn(mixed $v): Widget => new LeafProbe(1.0));
        $element = $widget->createElement();
        $element->mount(null, $ctx);

        $store->set(new EquatableValue('x'));
        check(
            'State · null → Equatable 判为变化（守卫没把 null 交给 equals）',
            $element->state()->needsBuild(),
            '守卫缺失会把 null 传给 equals() 或误判没变'
        );
        $element->rebuild();

        $store->set(new EquatableValue('x'));
        check(
            'State · 两边都是 Equatable 后才按值比',
            !$element->state()->needsBuild(),
            '值门控在这条路径上没生效'
        );
        $element->unmount();
        $this->flushChecks();
    }

    /**
     * 2026-11-08：readonly 不是值相等契约 —— 同内容新实例仍走 `===` ⇒ 标脏。
     *
     * ★ 与 lint 判据不同源：这里量的是 `SubjectBuilderState` 实际标不标脏，
     *   不读规则实现。`tests/` 整体不在 `lint.php` 扫描范围（`isToolchain()`），
     *   所以这里可以写最自然的「声明类型 + 体内 new」形态。
     */
    public function testReadonlyWithoutEquatableStillRebuilds(): void
    {
        $ctx = smokeContext();
        $store = new ValueSubject();
        $widget = SubjectBuilder::make($store)
            ->select(static fn(ValueSubject $s): ReadonlyValue => new ReadonlyValue($s->text()))
            ->builder(static fn(mixed $v): Widget => new LeafProbe(1.0));
        $element = $widget->createElement();
        $element->mount(null, $ctx);

        $store->setText('同');
        check(
            'State · 纯 readonly 值对象（无 Equatable）同内容新实例仍标脏',
            $element->state()->needsBuild(),
            'readonly 被当成值相等契约了 ⇒ lint 白名单与运行期门控不一致'
        );
        $element->unmount();
        $this->flushChecks();
    }

    /**
     * 2026-11-10：父重建换了 `select` 闭包 ⇒ 缓存值必须换语义重算，且后续
     * notify 的门控也得用新 select（旧实现把闭包 `use` 固化在 initState 里）。
     */
    public function testDidUpdateWidgetReselectsAfterSelectChange(): void
    {
        $ctx = smokeContext();
        $store = new FakeStore();
        $args = [];
        $builder = static function (mixed ...$a) use (&$args): Widget {
            $args = $a;

            return new LeafProbe(1.0);
        };

        $widget = SubjectBuilder::make($store)
            ->select(static fn (FakeStore $s): int => $s->status())
            ->builder($builder);
        $element = $widget->createElement();
        $element->mount(null, $ctx);
        check(
            'SubjectBuilder · 前置：首帧拿到旧 select 的值 0',
            $args === [0],
            'args=' . \json_encode($args)
        );

        // 静默改字段（不 notify）⇒ 新旧 select 输出必然不同，才测得出"重算"
        $store->silentTouch(7);
        $next = SubjectBuilder::make($store)
            ->select(static fn (FakeStore $s): int => $s->status() * 10)
            ->builder($builder);
        $element->update($next);
        check(
            'SubjectBuilder · 换 select ⇒ build 拿到新语义重算的 70（不是旧缓存 0）',
            $args === [70],
            'args=' . \json_encode($args)
        );

        $store->setStatus(8);
        check(
            'SubjectBuilder · 换 select 后 notify 门控也用新语义',
            $element->state()->needsBuild(),
            '没标脏 ⇒ 回调仍固化着旧 select'
        );
        $element->rebuild();
        check(
            'SubjectBuilder · 重建后拿到 8*10=80（不是旧语义的 8）',
            $args === [80],
            'args=' . \json_encode($args)
        );

        $element->unmount();
        $this->flushChecks();
    }

    /**
     * 2026-11-10：父重建换了 `Subject` ⇒ 订阅跟着换绑；旧 source 退订、
     * 新 source 接上，且 build 立刻拿到新 source 的当前值。
     */
    public function testDidUpdateWidgetRebindsAfterSourceChange(): void
    {
        $ctx = smokeContext();
        $first = new FakeStore();
        $second = new FakeStore();
        $args = [];
        $builder = static function (mixed ...$a) use (&$args): Widget {
            $args = $a;

            return new LeafProbe(1.0);
        };
        $select = static fn (FakeStore $s): int => $s->status();

        $widget = SubjectBuilder::make($first)->select($select)->builder($builder);
        $element = $widget->createElement();
        $element->mount(null, $ctx);
        check(
            'SubjectBuilder · 前置：首帧拿到旧 source 的值 0',
            $args === [0],
            'args=' . \json_encode($args)
        );

        $first->setStatus(1);
        $element->rebuild();   // 消费脏，保证 update 前 needsBuild=false

        $second->silentTouch(42);
        $next = SubjectBuilder::make($second)->select($select)->builder($builder);
        $element->update($next);
        check(
            'SubjectBuilder · 换 source ⇒ build 立刻拿到新 source 当前值 42',
            $args === [42],
            'args=' . \json_encode($args)
        );

        // 旧 source 若还挂着，这一下会触发回调去读新 source（静默改成 99）⇒ 标脏
        $second->silentTouch(99);
        $first->setStatus(2);
        check(
            'SubjectBuilder · 旧 source 已退订（僵尸通知不再标脏）',
            !$element->state()->needsBuild(),
            'needsBuild=true ⇒ 旧订阅还在'
        );

        $second->setStatus(100);
        check(
            'SubjectBuilder · 新 source 的通知能到',
            $element->state()->needsBuild(),
            '没标脏 ⇒ 订阅没换绑到新 source'
        );

        $element->unmount();
        $this->flushChecks();
    }
}

/**
 * 值对象（只为"每次 select 都新建一个"这件事服务 —— 应用里的 `StatusMessage`
 * 就是这个形状）。
 */
final class Value
{
    public function __construct(public readonly string $text)
    {
    }

    public function equals(self $other): bool
    {
        return $this->text === $other->text;
    }
}

/** 能发任意文本的 `Subject`（`FakeStore` 只有 int 字段，够不着"值对象"这一面） */
final class ValueSubject extends Subject
{
    private string $text = '同';

    public function text(): string
    {
        return $this->text;
    }

    public function setText(string $text): void
    {
        $this->text = $text;
        $this->notifyChanged();
    }
}

/** 显式声明值相等契约的值对象（`StatusMessage` 在测试里的替身）。 */
final class EquatableValue implements Equatable
{
    public function __construct(public readonly string $text)
    {
    }

    public function equals(Equatable $other): bool
    {
        return $other instanceof self && $this->text === $other->text;
    }
}

/** 首帧为 null 的 Subject：验证 `sameValue()` 的「两边都 Equatable」守卫。 */
final class MaybeSubject extends Subject
{
    private ?EquatableValue $value = null;

    public function value(): ?EquatableValue
    {
        return $this->value;
    }

    public function set(?EquatableValue $value): void
    {
        $this->value = $value;
        $this->notifyChanged();
    }
}

/** 纯 readonly、不实现 Equatable：验证「不可变 ≠ 值相等」。 */
final readonly class ReadonlyValue
{
    public function __construct(public string $text)
    {
    }
}
