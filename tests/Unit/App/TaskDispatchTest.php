<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\AppScope;
use Drift\App\Job;
use Drift\App\JobRunner;
use Drift\App\TaskDispatch;
use Drift\Dev\Tests\Support\RecordingTaskHost;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** 提交策略判据：三种策略互斥，`null` 不再决定行为。 */
final class TaskDispatchTest extends TestCase
{
    #[Test]
    public function 同步策略在当前栈跑任务本体(): void
    {
        $job = new class implements Job {
            public bool $ran = false;

            public function run(): mixed
            {
                $this->ran = true;

                return 42;
            }
        };
        $seen = null;
        TaskDispatch::sync()->submit($job)->then(static function (mixed $value) use (&$seen): void {
            $seen = $value;
        });

        self::assertTrue($job->ran);
        self::assertSame(42, $seen);
    }

    #[Test]
    public function 同步策略把抛错落成拒绝(): void
    {
        $job = new class implements Job {
            public function run(): mixed
            {
                throw new \RuntimeException('同步炸了');
            }
        };
        $error = null;
        TaskDispatch::sync()->submit($job)->catch(static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        self::assertInstanceOf(\RuntimeException::class, $error);
        self::assertSame('同步炸了', $error->getMessage());
    }

    #[Test]
    public function 拒绝策略不跑任务本体(): void
    {
        $job = new class implements Job {
            public bool $ran = false;

            public function run(): mixed
            {
                $this->ran = true;

                return 'never';
            }
        };
        $error = null;
        TaskDispatch::reject()->submit($job)->catch(static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        self::assertFalse($job->ran);
        self::assertInstanceOf(\RuntimeException::class, $error);
        self::assertStringContainsString('没有后台任务端口', $error->getMessage());
    }

    #[Test]
    public function 后台策略只交给端口一次(): void
    {
        $host = new RecordingTaskHost();
        $job = new class implements Job {
            public function run(): mixed
            {
                return 'local';
            }
        };
        $seen = null;
        $error = null;
        TaskDispatch::background($host)->submit($job)
            ->then(static function (mixed $value) use (&$seen): void { $seen = $value; })
            ->catch(static function (\Throwable $e) use (&$error): void { $error = $e; });

        self::assertSame([$job], $host->submitted);
        self::assertNull($seen, '端口结果没配时不应冒用本地 run() 结果');
        self::assertInstanceOf(\RuntimeException::class, $error);
    }

    #[Test]
    public function 作用域把三态翻成显式策略(): void
    {
        $scope = AppScope::make();
        self::assertTrue($scope->dispatch()->isSync());

        $scope->setTaskHost(null);
        self::assertTrue($scope->dispatch()->isReject());

        $host = new RecordingTaskHost();
        $scope->setTaskHost($host);
        self::assertTrue($scope->dispatch()->isBackground());
        self::assertSame($host, $scope->dispatch()->host());
    }

    #[Test]
    public function 旧提交壳保持原有空端口语义(): void
    {
        $job = new class implements Job {
            public bool $ran = false;

            public function run(): mixed
            {
                $this->ran = true;

                return 'sync';
            }
        };
        JobRunner::exec(null, $job);

        self::assertTrue($job->ran, 'JobRunner::exec(null) 仍是同步，不能借新语义变拒绝');
    }
}
