<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Async;

use Drift\Async\AsyncValue;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * `AsyncValue` —— 异步状态的唯一形状（N6）。
 *
 * ## 判据落在"四态互斥"与"取值不串"上
 *
 * 这个类小到没有分支可测，所以判据只问两件事：
 *  ① **四态互斥**：任一实例只有一个 `isXxx()` 为真（否则 builder 里
 *     `if (isLoading()) ... if (hasError()) ...` 的判断顺序会决定行为）；
 *  ② **取值不串**：`data` 态才拿得到值、`error` 态才拿得到消息，
 *     `dataOr()` 在没有数据时给 fallback（表格 / 列表最常用的读法）。
 *
 * ★ 不测"不可变"（没有可变方法可调，结构上就没有第二条路）。
 */
final class AsyncValueTest extends SmokeTestCase
{
    /**
     * `fold()` 各支的调用记录（观测面）。
     *
     * ★ 用属性而不是 `use (&$arr)` 引用捕获：闭包非 `static` 时自动绑定 `$this`，
     *   改 `$this->calls` 在 AOT 下是最保险的写法（引用捕获没在这套编译器上验证过）。
     *
     * @var list<string>
     */
    private array $calls = [];

    public function testFourStatesAreMutuallyExclusive(): void
    {
        $cases = [
            'idle' => [AsyncValue::idle(), true, false, false, false],
            'loading' => [AsyncValue::loading(), false, true, false, false],
            'data' => [AsyncValue::data(['a']), false, false, true, false],
            'error' => [AsyncValue::error('boom'), false, false, false, true],
        ];

        foreach ($cases as $name => [$value, $idle, $loading, $hasData, $hasError]) {
            check(
                'AsyncValue · ' . $name . ' 只认自己（四态互斥）',
                $value->isIdle() === $idle
                && $value->isLoading() === $loading
                && $value->hasData() === $hasData
                && $value->hasError() === $hasError,
                \sprintf(
                    'idle=%s loading=%s data=%s error=%s',
                    $value->isIdle() ? '1' : '0',
                    $value->isLoading() ? '1' : '0',
                    $value->hasData() ? '1' : '0',
                    $value->hasError() ? '1' : '0',
                ),
            );
        }

        $this->flushChecks();
    }

    public function testValueOnlyOnDataAndDataOrFallsBack(): void
    {
        $data = AsyncValue::data(['x', 'y']);
        check(
            'AsyncValue · data 态带值',
            $data->value() === ['x', 'y'] && $data->dataOr([]) === ['x', 'y'],
            'value=' . \json_encode($data->value()),
        );

        $loading = AsyncValue::loading();
        check(
            'AsyncValue · 非 data 态 value() 是 null、dataOr() 给 fallback',
            $loading->value() === null && $loading->dataOr(['fallback']) === ['fallback'],
            'value=' . \json_encode($loading->value()),
        );

        // ★ 关键：`dataOr()` 不能把"data 态里的 null"和"没数据"混成一种 ——
        //   前者应当原样返回 null，后者才给 fallback。
        $nullData = AsyncValue::data(null);
        check(
            'AsyncValue · data(null) 原样返回 null（不回落 fallback）',
            $nullData->hasData() && $nullData->dataOr('fallback') === null,
            'dataOr=' . \json_encode($nullData->dataOr('fallback')),
        );

        $this->flushChecks();
    }

    public function testErrorMessageOnlyOnError(): void
    {
        $error = AsyncValue::error('取数失败：HTTP 500');
        check(
            'AsyncValue · error 态带消息',
            $error->message() === '取数失败：HTTP 500',
            'message=' . $error->message(),
        );

        check(
            'AsyncValue · 其余三态消息为空串（不串到 data 的渲染里）',
            AsyncValue::idle()->message() === ''
            && AsyncValue::loading()->message() === ''
            && AsyncValue::data('v')->message() === '',
        );

        $this->flushChecks();
    }

    /**
     * `fold()` 四态各走各的支，且**每支收到的都是同一个 `AsyncValue`**。
     *
     * ★ 判据有两层：
     *   ① **只有一支被调用** —— 判断顺序写错会导致两支都跑（典型症状：loading 时
     *      把上一次的旧数据也画出来）；
     *   ② **入参统一是 `AsyncValue`** —— 2026-10-30 的第一版是混合签名（`data` 收值、
     *      `idle` 零参），调用方给 `data` 写零参闭包时解释器静默通过、AOT 直接崩。
     *      统一签名之后这类错误**写不出来**，这条断言就是那个契约的钉子。
     */
    public function testFoldDispatchesToExactlyOneArm(): void
    {
        $cases = [
            'idle' => [AsyncValue::idle(), 'idle', 'I'],
            'loading' => [AsyncValue::loading(), 'loading', 'L'],
            'data' => [AsyncValue::data(['k' => 1]), 'data:{"k":1}', 'D'],
            'error' => [AsyncValue::error('boom'), 'error:boom', 'E'],
        ];

        foreach ($cases as $name => [$value, $expectedCall, $expectedResult]) {
            $this->calls = [];

            $result = $value->fold([
                'idle' => function (AsyncValue $v): string {
                    $this->calls[] = 'idle';

                    return 'I';
                },
                'loading' => function (AsyncValue $v): string {
                    $this->calls[] = 'loading';

                    return 'L';
                },
                'data' => function (AsyncValue $v): string {
                    $this->calls[] = 'data:' . \json_encode($v->value());

                    return 'D';
                },
                'error' => function (AsyncValue $v): string {
                    $this->calls[] = 'error:' . $v->message();

                    return 'E';
                },
            ]);

            check(
                'AsyncValue::fold · ' . $name . ' 只走自己那一支（入参是 AsyncValue）',
                $result === $expectedResult && $this->calls === [$expectedCall],
                'result=' . $result . ' calls=' . \json_encode($this->calls),
            );
        }

        $this->flushChecks();
    }

    /**
     * `carriedFrom()` —— 新状态**继承上一次的载荷**（Riverpod `copyWithPrevious`）。
     *
     * ## 这条钉的是什么
     *
     * 上一版"刷新 ⇒ `loading` ⇒ 载荷扔掉"的直接后果是**列表闪空**。判据分三层：
     *  ① 刷新中 `isLoading()` 与 `hasValue()` **同时**成立（两维模型，不是"第五态"）；
     *  ② `dataOr()` 拿得到旧值（消费点不必各自写 `$v->hasData() ? … : $last`）；
     *  ③ 三条不继承的情形各归各位：没旧值 / 自己是 `data` / `idle` 起手。
     *
     * ★ 绝不能拿 `$value !== null` 判"有没有"：`data(null)` 是合法值。
     *   下面那条 `data(null)` 的断言就是防止实现退化成"看值是不是 null"。
     */
    public function testCarriedFromKeepsPreviousPayload(): void
    {
        $old = AsyncValue::data(['rows' => 1]);
        $refreshing = AsyncValue::loading()->carriedFrom($old);

        check(
            'AsyncValue · 刷新中 = loading 且带旧值（hasData 仍为 false）',
            $refreshing->isLoading()
            && $refreshing->hasValue()
            && !$refreshing->hasData()
            && $refreshing->isRefreshing()
            && $refreshing->dataOr([]) === ['rows' => 1],
            \sprintf(
                'loading=%s hasValue=%s hasData=%s refreshing=%s value=%s',
                $refreshing->isLoading() ? '1' : '0',
                $refreshing->hasValue() ? '1' : '0',
                $refreshing->hasData() ? '1' : '0',
                $refreshing->isRefreshing() ? '1' : '0',
                \json_encode($refreshing->value()),
            ),
        );

        $firstLoad = AsyncValue::loading();
        check(
            'AsyncValue · 没有旧值 ⇒ carriedFrom 原样返回（首屏照旧整块转圈）',
            $firstLoad->carriedFrom(AsyncValue::idle()) === $firstLoad
            && !$firstLoad->hasValue()
            && !$firstLoad->isRefreshing(),
            'hasValue=' . ($firstLoad->hasValue() ? '1' : '0'),
        );

        $fresh = AsyncValue::data(['rows' => 2]);
        check(
            'AsyncValue · data 自带载荷 ⇒ 不覆盖（新数据当然盖掉旧的）',
            $fresh->carriedFrom($old) === $fresh && $fresh->value() === ['rows' => 2],
            'value=' . \json_encode($fresh->value()),
        );

        $failed = AsyncValue::error('取数失败：HTTP 500')->carriedFrom($old);
        check(
            'AsyncValue · 刷新失败 ⇒ 错误说得出来、旧值也还在；且不算 refreshing',
            $failed->hasError()
            && $failed->hasValue()
            && $failed->dataOr([]) === ['rows' => 1]
            && $failed->message() === '取数失败：HTTP 500'
            && !$failed->isRefreshing(),
            'error=' . ($failed->hasError() ? '1' : '0') . ' value=' . \json_encode($failed->value()),
        );

        // ★ 实现若把"有没有值"退化成 `$value !== null`，这条会红
        $nullOld = AsyncValue::data(null);
        $carriedNull = AsyncValue::loading()->carriedFrom($nullOld);
        check(
            'AsyncValue · data(null) 也算有值（不能被 null 判据吃掉）',
            $carriedNull->hasValue() && $carriedNull->dataOr('fallback') === null,
            'hasValue=' . ($carriedNull->hasValue() ? '1' : '0')
                . ' dataOr=' . \json_encode($carriedNull->dataOr('fallback')),
        );

        $this->flushChecks();
    }

    /**
     * `fold()` 的**路由规则**：刷新中（`loading` 且有旧值）走 `data` 支。
     *
     * ★ 名字描述的是"现在该画哪一块"，不是"后端在哪个阶段"。`loading` 支因此被
     *   收窄成**首屏**。这条规则若不存在，页面只能自己判 `isRefreshing()`，
     *   而不判的页面**看不到任何异常**（列表闪空只是"慢"，不是"错"）。
     */
    public function testFoldRoutesRefreshingToDataArm(): void
    {
        $this->calls = [];

        $arms = [
            'idle' => function (AsyncValue $v): string {
                $this->calls[] = 'idle';

                return 'I';
            },
            'loading' => function (AsyncValue $v): string {
                $this->calls[] = 'loading';

                return 'L';
            },
            'data' => function (AsyncValue $v): string {
                $this->calls[] = 'data:' . \json_encode($v->value());

                return 'D';
            },
            'error' => function (AsyncValue $v): string {
                $this->calls[] = 'error';

                return 'E';
            },
        ];

        $refreshing = AsyncValue::loading()->carriedFrom(AsyncValue::data(['n' => 7]));
        $result = $refreshing->fold($arms);

        check(
            'AsyncValue::fold · 刷新中走 data 支（旧值照旧画出来）',
            $result === 'D' && $this->calls === ['data:{"n":7}'],
            'result=' . $result . ' calls=' . \json_encode($this->calls),
        );

        $this->calls = [];
        $firstLoad = AsyncValue::loading()->fold($arms);
        check(
            'AsyncValue::fold · 首屏（无旧值）仍走 loading 支',
            $firstLoad === 'L' && $this->calls === ['loading'],
            'result=' . $firstLoad . ' calls=' . \json_encode($this->calls),
        );

        // ★ 失败**不**路由：错误必须让用户看见，哪怕旧内容还在
        $this->calls = [];
        $failed = AsyncValue::error('boom')->carriedFrom(AsyncValue::data(['n' => 7]))->fold($arms);
        check(
            'AsyncValue::fold · 带旧值的 error 仍走 error 支（失败不许被旧内容盖住）',
            $failed === 'E' && $this->calls === ['error'],
            'result=' . $failed . ' calls=' . \json_encode($this->calls),
        );

        $this->flushChecks();
    }

    /**
     * 缺任何一个键都**当场抛**，而不是等真走到那一态才炸。
     * ★ 为什么这条重要：手写 `if/elseif/else` 只有三个出口，漏了 `error` 就落进
     *   `else` 用兜底值画一张空表 —— 用户看到"没有数据"，真相是请求失败。那类
     *   bug 的复现条件是"网络出错"，恰好在开发期最不容易遇到。
     */
    public function testFoldRejectsIncompleteArms(): void
    {
        $full = [
            'idle' => static fn(AsyncValue $v): string => 'I',
            'loading' => static fn(AsyncValue $v): string => 'L',
            'data' => static fn(AsyncValue $v): string => 'D',
            'error' => static fn(AsyncValue $v): string => 'E',
        ];

        foreach (\array_keys($full) as $missing) {
            $arms = $full;
            unset($arms[$missing]);

            $message = null;

            try {
                AsyncValue::idle()->fold($arms);
            } catch (\InvalidArgumentException $e) {
                $message = $e->getMessage();
            }

            check(
                'AsyncValue::fold · 缺 \'' . $missing . '\' 支当场抛（不静默画空白）',
                $message !== null && \str_contains($message, $missing),
                $message === null ? '没抛 —— 会静默落进 data 兜底' : '抛了：' . $message,
            );
        }

        check('AsyncValue::fold · 四支齐全时不抛', AsyncValue::idle()->fold($full) === 'I');

        $this->flushChecks();
    }
}
