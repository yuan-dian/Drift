<?php

declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\AppEntry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `AppEntry`：角色分流后 UI 角色走哪条路。
 *
 * ★ worker 分支进的是阻塞行协议循环，单测里不能真起 —— 由真机探针守
 *   （`watermark.exe --drift-worker` 不建窗、关 stdin 退出 0）。这里只验
 *   "UI 角色只调 `start()`、不碰后台表"这条**顺序契约**（UI 进程不该为了
 *   判角色去构造一次注册表）。
 * ★ 每条都改 `$_SERVER['argv']`，`tearDown()` 恢复：测试进程自己的 argv 不能被
 *   污染，否则后续用例会把自己认成 worker。
 */
final class AppEntryTest extends TestCase
{
    /** @var array<mixed>|null */
    private mixed $savedArgv = null;

    protected function setUp(): void
    {
        $this->savedArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['/app.exe'];
    }

    protected function tearDown(): void
    {
        if ($this->savedArgv === null) {
            unset($_SERVER['argv']);

            return;
        }
        $_SERVER['argv'] = $this->savedArgv;
    }

    #[Test]
    public function uiRoleCallsStartOnceAndNeverTouchesJobs(): void
    {
        $entry = new class extends AppEntry {
            public int $started = 0;

            public int $jobsAsked = 0;

            protected function jobs(): array
            {
                $this->jobsAsked++;

                return [];
            }

            protected function start(): void
            {
                $this->started++;
            }
        };

        $entry->run();

        self::assertSame(1, $entry->started);
        // UI 进程不为了判角色去构造一次注册表 —— 表留给 start() 装端口时取。
        self::assertSame(0, $entry->jobsAsked);
    }

    #[Test]
    public function jobsDefaultsToEmpty(): void
    {
        $entry = new class extends AppEntry {
            protected function start(): void
            {
            }

            /** @return array<string, mixed> */
            public function peek(): array
            {
                return $this->jobs();
            }
        };

        self::assertSame([], $entry->peek());
    }
}
