<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Async;

use Drift\Async\Failure;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * `Failure::codeOf()` —— 全链路唯一的 code 提取点。
 *
 * 判据钉三条：① `Failure` 自带的 code 优先（跨进程还原端带回来的）；
 * ② 普通异常回落 PHP 标准 `getCode()`；③ `0` 是「没设」不是一种分类。
 * 少任何一条，UI 拿到的 code 都会和源异常对不上，且**不报错**。
 */
final class FailureTest extends SmokeTestCase
{
    public function testFailureCarriesItsOwnCode(): void
    {
        $failure = new Failure('拿不到这一页', 'NOT_FOUND');

        check('Failure · errorCode() 保留构造时的分类', $failure->errorCode() === 'NOT_FOUND');
        check('Failure · message 不被改写', $failure->getMessage() === '拿不到这一页');
        check('Failure · codeOf() 优先读自带的 code', Failure::codeOf($failure) === 'NOT_FOUND');

        $this->flushChecks();
    }

    public function testThrowableCodeIsTheFallbackChannel(): void
    {
        check(
            'Failure · 普通异常取 getCode()（零配置也能分类）',
            Failure::codeOf(new \RuntimeException('HTTP 404', 404)) === '404'
        );
        check(
            'Failure · code=0 ⇒ 空串（不把「没设」当成一个真分类）',
            Failure::codeOf(new \RuntimeException('连接断了')) === ''
        );
        check(
            'Failure · 非 0 的 int code 归一成十进制字符串（跨 JSON 也是同一形状）',
            Failure::codeOf(new \RuntimeException('鉴权过期', 401)) === '401'
        );

        $this->flushChecks();
    }

    public function testFailureIsAThrowable(): void
    {
        $caught = null;
        try {
            throw new Failure('断网', 'OFFLINE');
        } catch (\Throwable $e) {
            $caught = $e;
        }

        check(
            'Failure · 能被 catch (\\Throwable) 接住（Future 的 catch 链不受影响）',
            $caught instanceof Failure && Failure::codeOf($caught) === 'OFFLINE',
            $caught === null ? '没接住' : \get_class($caught)
        );

        $this->flushChecks();
    }
}
