<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\WorkerProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `WorkerProcess`：同 exe 双角色的分流判据。
 *
 * ★ 只测纯判定与 UI 侧分流。`serveIf()` 的 worker 分支进的是阻塞行协议循环，
 *   单测里不能真起 —— 它的行为由 `ProcessWorker::handle()` 的协议测试与真机探针守。
 * ★ 每条都改 `$_SERVER['argv']`，`tearDown()` 恢复：测试进程自己的 argv 不能被污染，
 *   否则后续用例会把自己认成 worker。
 */
final class WorkerProcessTest extends TestCase
{
    /** @var array<mixed>|null */
    private mixed $savedArgv = null;

    protected function setUp(): void
    {
        $this->savedArgv = $_SERVER['argv'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->savedArgv === null) {
            unset($_SERVER['argv']);
        } else {
            $_SERVER['argv'] = $this->savedArgv;
        }
    }

    #[Test]
    public function executableReturnsArgvZeroOrEmpty(): void
    {
        $_SERVER['argv'] = ['/path/to/app.exe', '--flag'];
        self::assertSame('/path/to/app.exe', WorkerProcess::executable());

        $_SERVER['argv'] = [];
        self::assertSame('', WorkerProcess::executable());

        $_SERVER['argv'] = 'not-an-array';
        self::assertSame('', WorkerProcess::executable());
    }

    #[Test]
    public function isWorkerFindsSwitchAnywhere(): void
    {
        $_SERVER['argv'] = ['/path/to/app.exe'];
        self::assertFalse(WorkerProcess::isWorker());

        $_SERVER['argv'] = ['/app.exe', '--drift-worker'];
        self::assertTrue(WorkerProcess::isWorker());

        // 开关不在第 0 位也要认（启动器可能塞别的参数在前头）
        $_SERVER['argv'] = ['/app.exe', '--other', '--drift-worker'];
        self::assertTrue(WorkerProcess::isWorker());

        // 只认精确字符串：前缀 / 子串都不算
        $_SERVER['argv'] = ['/app.exe', '--drift-worker-x'];
        self::assertFalse(WorkerProcess::isWorker());
    }

    #[Test]
    public function serveIfDoesNotServeInUiRole(): void
    {
        // UI 角色不给 registry 也不该碰它 —— serveIf 应在扫描后立刻返回 false。
        $_SERVER['argv'] = ['/app.exe'];

        $served = WorkerProcess::serveIf([]);

        self::assertFalse($served);
    }

    #[Test]
    public function switchConstantIsStable(): void
    {
        // UI 侧拼命令与 worker 侧认身份共用它 ⇒ 值变了两边一起变，但协议字符串本身
        // 是与探针 / 启动器的约定，不能悄悄改。
        self::assertSame('--drift-worker', WorkerProcess::SWITCH);
    }
}
