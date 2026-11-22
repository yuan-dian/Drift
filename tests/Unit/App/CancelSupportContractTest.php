<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\CancelSupport;
use Drift\App\ProcessTaskHost;
use Drift\App\SyncTaskHost;
use Drift\App\TaskHost;
use Drift\App\ThreadTaskHost;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `cancel()` 能停多深 —— 四档能力声明与后端实现必须对得上。
 *
 * ## 为什么要有这条判据
 *
 * `Future::cancel()` 的文档写"尽力而为"，而"尽力"在三后端差得很远：进程池能杀掉
 * 在途那条连 worker，线程池一步都停不了，同步后端根本没有在跑的活。这个差异以前
 * 只活在一份 2026-10-30 的分析稿里 —— 应用想知道"按了停止之后后台还在不在烧 CPU"，
 * 只能去读三个后端的实现。
 *
 * | 判据 | 漏了会怎样 |
 * |---|---|
 * | ① 各档按"能省多少活"单调递增 | 拿 `value` 排序的调用点会得到反的结论 |
 * | ② 每个后端都答了，且答的是它自己那一档 | 界面对"停止"的承诺与实现不符 |
 * | ③ `describe()` 每档一句、互不相同且非空 | 界面照档位拼文案 ⇒ 加一档要改两处 |
 * | ④ `killsRunning()` 只认最高档 | 把"只不交付"说成"真停 CPU"⇒ 界面骗用户 |
 *
 * ★ 与渲染侧的 `CanvasCapabilityContractTest` 是同一种写法：能力位逐档登记，
 *   判据守"登记表与实现一致"。那边守的是 `Canvas` 的 `CAP_*`，这边守 `TaskHost`。
 */
final class CancelSupportContractTest extends TestCase
{
    #[Test]
    public function 三档按能省多少活单调递增(): void
    {
        self::assertTrue(
            CancelSupport::Killable->value > CancelSupport::QueuedOnly->value,
            'Killable（在途也能真停）是最高档，必须排在 QueuedOnly 之上'
        );
        self::assertTrue(
            CancelSupport::DetachedOnly->value < CancelSupport::QueuedOnly->value,
            'QueuedOnly 能撤排队，它必须排在 DetachedOnly 之上'
        );
        self::assertTrue(
            CancelSupport::AlreadyDone->value < CancelSupport::DetachedOnly->value,
            'AlreadyDone（没有在跑的活）必须是最低档'
        );

        self::assertTrue(CancelSupport::Killable->revokesQueued());
        self::assertTrue(CancelSupport::QueuedOnly->revokesQueued());
        self::assertFalse(CancelSupport::DetachedOnly->revokesQueued());
        self::assertFalse(CancelSupport::AlreadyDone->revokesQueued());
    }

    #[Test]
    public function killsRunning只认最高档(): void
    {
        self::assertTrue(CancelSupport::Killable->killsRunning());
        self::assertFalse(CancelSupport::QueuedOnly->killsRunning());
        self::assertFalse(CancelSupport::DetachedOnly->killsRunning());
        self::assertFalse(CancelSupport::AlreadyDone->killsRunning());
    }

    #[Test]
    public function 每个后端都声明了自己那一档(): void
    {
        $expected = [
            ProcessTaskHost::class => CancelSupport::Killable,
            ThreadTaskHost::class => CancelSupport::DetachedOnly,
            SyncTaskHost::class => CancelSupport::AlreadyDone,
        ];

        foreach ($expected as $class => $support) {
            /** @var TaskHost $host */
            $host = self::hostOf($class);

            self::assertSame(
                $support,
                $host->cancelSupport(),
                $class . ' 的取消能力声明与实现不符 —— 这条声明是界面决定"要不要说仍在后台运行"的唯一依据'
            );
        }
    }

    #[Test]
    public function 每档都有一句给用户看的话且互不相同(): void
    {
        $said = [];

        foreach (CancelSupport::cases() as $case) {
            $text = $case->describe();

            self::assertNotSame('', $text, $case->name . ' 没有文案 ⇒ 界面只能自己按档位拼一遍');
            self::assertArrayNotHasKey($text, $said, '两档说了同一句话 ⇒ 用户分不出差别');
            $said[$text] = true;
        }
    }

    /**
     * 造一个能回答 `cancelSupport()` 的实例（不真起 worker / 线程）。
     *
     * @param class-string<TaskHost> $class
     */
    private static function hostOf(string $class): TaskHost
    {
        if ($class === ProcessTaskHost::class) {
            // 空注册表 + 不 prewarm ⇒ 一条命令都不发，构造零副作用
            return new ProcessTaskHost([\PHP_BINARY, '-r', 'exit(0);'], []);
        }
        if ($class === ThreadTaskHost::class) {
            return new ThreadTaskHost(1);
        }

        return new SyncTaskHost();
    }
}
