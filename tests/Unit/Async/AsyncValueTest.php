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
     * 缺任何一个键都**当场抛**，而不是等真走到那一态才炸。
     *
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
