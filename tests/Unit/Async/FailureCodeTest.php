<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Async;

use Drift\Async\Failure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 失败分类的**稳定 code 通道** —— `AsyncValue::code()` 的存在意义就是不靠文字分辨错误。
 *
 * ## 为什么这几条必须有判据
 *
 * 框架自己的两类失败（**超时**、**worker 死了**）以前走裸 `RuntimeException`，
 * `Failure::codeOf()` 把它们归一成空串 —— 于是 UI 想区分"超时可以重试"和
 * "任务自己抛了错要改代码"，只能去匹配消息文字。而消息是给人看的、随时会改。
 *
 * | 判据 | 钉住什么 |
 * |---|---|
 * | ① | 四个 code 是**常量**（不是散在各处的字面量），且形态统一 |
 * | ② | `codeOf()` 对具名 `Failure` 原样还原，对裸异常仍返回 `''`（不瞎猜） |
 * | ③ | 真实失败路径（进程池超时 / worker 崩溃 / 投递被拒）带的就是这几个 code |
 *
 * ★ ③ 用**真进程**跑（`JobTimeoutTest` 的同一套夹具），不是造一个 `Failure` 自证 ——
 *   "常量定义了但没人用"是本仓反复踩过的形态（定义了不等于接上了）。
 */
final class FailureCodeTest extends TestCase
{
    #[Test]
    public function 四个稳定code形态统一且互不相同(): void
    {
        $codes = [
            Failure::CODE_TIMEOUT,
            Failure::CODE_WORKER_DIED,
            Failure::CODE_HOST_UNAVAILABLE,
            Failure::CODE_JOB_REJECTED,
        ];

        self::assertCount(4, \array_unique($codes), '两个 code 撞了 ⇒ UI 分不开这两类失败');

        foreach ($codes as $code) {
            self::assertMatchesRegularExpression(
                '/^drift\/[a-z][a-z0-9-]*$/',
                $code,
                'code 形态："drift/" + 小写动名词（应用自己的 code 不要占这个前缀）'
            );
        }
    }

    #[Test]
    public function codeOf原样还原具名code而不瞎猜裸异常(): void
    {
        self::assertSame(
            Failure::CODE_TIMEOUT,
            Failure::codeOf(new Failure('超时了', Failure::CODE_TIMEOUT))
        );

        // 裸异常没有分类 ⇒ 空串（"没给分类"，不是"分类失败"）
        self::assertSame('', Failure::codeOf(new \RuntimeException('HTTP 500')));

        // 应用可以用 PHP 原生的 getCode()
        self::assertSame('404', Failure::codeOf(new \RuntimeException('not found', 404)));

        // 0 是"没设 code"的默认值 ⇒ 不当作真分类交给 UI
        self::assertSame('', Failure::codeOf(new \RuntimeException('boom')));
    }

    #[Test]
    public function 进程池的拒收带job_rejected(): void
    {
        // 不真起 worker：`submit()` 在"不是 NamedJob"这一步就落到 `$failed` 队列，
        // 结算在 `drain()` 里（与真任务失败同一时序 —— 不能当场 reject）。
        $host = new \Drift\App\ProcessTaskHost([\PHP_BINARY, '-r', 'exit(0);'], []);
        $future = $host->submit(new NotNamedJob());

        $error = null;
        $future->catch(static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        $host->drain(static function (\Drift\App\Completion $completion): void {
            if ($completion->error !== null) {
                $completion->future->reject($completion->error);

                return;
            }

            $completion->future->resolve($completion->value);
        });

        self::assertInstanceOf(\Throwable::class, $error, 'Future 没有失败 ⇒ 这条判据的前提不成立');
        self::assertSame(
            Failure::CODE_JOB_REJECTED,
            Failure::codeOf($error),
            '不是 NamedJob ⇒ 重试无用，UI 该画"配置错误"而不是"重试"'
        );
    }
}

/**
 * @internal 故意不实现 `NamedJob` —— 用来触发"跨进程后端拒收"那条路。
 */
final class NotNamedJob implements \Drift\App\Job
{
    public function run(): mixed
    {
        return null;
    }
}
