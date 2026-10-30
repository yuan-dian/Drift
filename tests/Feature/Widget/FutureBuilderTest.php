<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Async\AsyncValue;
use Drift\Async\Future;
use Drift\Dev\Smoke\App\FutureBuilderApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;

/**
 * `FutureBuilder` —— 把 `Future` 的四态映射成 UI（N6）。
 *
 * ## 这一组钉的是什么
 *
 * ① **首帧是 loading**（Future 未结算时）—— 用户要看的"加载中"真的出现；
 * ② **resolve 之后同帧变 data** —— `then` 在帧内（`pumpTasks`/直接结算）跑，
 *    `setState` 由紧随的 settle 消费 ⇒ 不空一帧；
 * ③ **reject 之后变 error 且带消息** —— 失败有可画的东西（不是永远转圈）；
 * ④ **已结算的 Future 首帧直接 data** —— 不白闪一帧 loading（这是
 *    `Future::chain()` 已结算分支与 `initState` 同步订阅共同保证的）；
 * ⑤ **同一 Future 不重复订阅**（`didUpdateWidget` 只在换 Future 时重订）。
 *
 * ★ 不测"卸载后迟到回调"：那条需要把子树换掉才能触发 `dispose`，而本夹具的根
 *   固定是 `FutureBuilder`。它由 `generation` 代号守住（代码审查 + 变异），
 *   自动化成本高于收益。
 */
final class FutureBuilderTest extends SmokeTestCase
{
    private const W = 400.0;

    private const H = 300.0;

    /** @return array{0: FutureBuilderApplication, 1: BoxConstraints} */
    private static function boot(?Future $future): array
    {
        $surface = new SurfaceProbe();
        $surface->resize((int) self::W, (int) self::H, 1.0);
        $constraints = BoxConstraints::tight(new Size(self::W, self::H));

        $app = new FutureBuilderApplication($future);
        $app->attach($surface, $constraints);
        $app->frame(16.0, $constraints);

        return [$app, $constraints];
    }

    /** builder 最后一次收到的快照（`null` = 一次都没被调，说明根本挂了） */
    private static function last(FutureBuilderApplication $app): ?AsyncValue
    {
        $snapshots = $app->snapshots;

        return $snapshots === [] ? null : $snapshots[\count($snapshots) - 1];
    }

    public function testStartsLoadingThenBecomesData(): void
    {
        $future = Future::pending();
        [$app, $constraints] = self::boot($future);

        $first = self::last($app);
        check(
            'FutureBuilder · 首帧是 loading（Future 还没结算）',
            $first !== null && $first->isLoading(),
            $first === null ? 'builder 一次都没被调（没挂上树）' : 'state=' . \json_encode([
                $first->isIdle(), $first->isLoading(), $first->hasData(), $first->hasError(),
            ]),
        );

        $future->resolve(['a', 'b']);
        $app->frame(32.0, $constraints);

        $last = self::last($app);
        check(
            'FutureBuilder · resolve 后重建成 data，且值原样到达',
            $last !== null && $last->hasData() && $last->value() === ['a', 'b'],
            $last === null ? 'builder 没被调' : 'value=' . \json_encode($last->value()),
        );

        $this->flushChecks();
    }

    public function testRejectBecomesErrorWithMessage(): void
    {
        $future = Future::pending();
        [$app, $constraints] = self::boot($future);

        $future->reject(new \RuntimeException('取数失败：HTTP 500'));
        $app->frame(32.0, $constraints);

        $last = self::last($app);
        check(
            'FutureBuilder · reject 后是 error 且消息可画',
            $last !== null && $last->hasError() && $last->message() === '取数失败：HTTP 500',
            $last === null ? 'builder 没被调' : 'message=' . $last->message(),
        );

        $this->flushChecks();
    }

    public function testAlreadyResolvedFutureSkipsLoadingFlash(): void
    {
        // ★ `Future::resolved()` 在构造那一瞬已结算 ⇒ `initState` 里 `then` 同步跑
        //   ⇒ 首帧构建时快照已经是 data（不会先画一帧 spinner 再跳）。
        [$app] = self::boot(Future::resolved('v'));

        $first = self::last($app);
        check(
            'FutureBuilder · 已结算 Future 首帧直接 data（不白闪 loading）',
            $first !== null && $first->hasData() && $first->value() === 'v',
            $first === null ? 'builder 没被调' : 'value=' . \json_encode($first->value()),
        );

        $this->flushChecks();
    }

    /**
     * `future: null` ⇒ `idle`（对应 Flutter `FutureBuilder(future: null)` 的
     * `ConnectionState.none`）。
     *
     * ★ 这一条钉的是"四态里的 idle 不是摆设"：2026-10-30 之前 `FutureBuilder` 的
     *   `$future` 不可空、快照恒从 `loading` 起 ⇒ `AsyncValue::idle()` 在生产代码里
     *   **零消费者**，而每个"点按钮才加载"的页面只能自己写
     *   `$future === null ? 占位 : FutureBuilder(...)`。现在那一层由控件自己承担。
     */
    public function testNullFutureIsIdleNotLoading(): void
    {
        [$app] = self::boot(null);

        $first = self::last($app);
        check(
            'FutureBuilder · future 为 null 时是 idle（不是 loading —— 画转圈会骗用户）',
            $first !== null && $first->isIdle(),
            $first === null ? 'builder 没被调' : 'state=' . \json_encode([
                $first->isIdle(), $first->isLoading(), $first->hasData(), $first->hasError(),
            ]),
        );

        $this->flushChecks();
    }

    public function testStableTreeDoesNotRebuildEveryFrame(): void
    {
        $future = Future::pending();
        [$app, $constraints] = self::boot($future);

        $before = \count($app->snapshots);

        // 跑几帧（根树不变 ⇒ 不该 markDirty ⇒ 不该每帧重建）
        $app->frame(32.0, $constraints);
        $app->frame(48.0, $constraints);

        check(
            'FutureBuilder · 稳定态不每帧重建（builder 调用数不随帧增长）',
            \count($app->snapshots) === $before,
            'snapshots=' . \count($app->snapshots) . ' before=' . $before,
        );

        // 结算之后每帧不该"重新变回 loading"——若实现每帧重订阅并重置快照，
        // 这里会读到 loading（那是"转圈不消失"的经典症状）。
        $future->resolve('done');
        $app->frame(64.0, $constraints);

        $last = self::last($app);
        check(
            'FutureBuilder · 结算后保持 data（不会被后续帧冲回 loading）',
            $last !== null && $last->hasData(),
        );

        $this->flushChecks();
    }
}
