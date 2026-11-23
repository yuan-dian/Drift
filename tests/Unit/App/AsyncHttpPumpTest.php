<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\Io\AsyncHttp;
use Drift\App\Io\HttpTransport;
use Drift\App\PumpHost;
use Drift\App\TaskDispatch;
use Drift\App\PumpHostHost;
use Drift\Async\Future;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * `PumpHost` 对异步 HTTP 的**接线面**：pumpIo 推进、hasPolledAsync 续帧。
 *
 * 钉两件会静默挂死的事：
 * - `io === null` 不能挡住 http pump（自定义 attach 可能出现这个组合）；
 * - 帧内后端有在途 ⇒ hasPolledAsync 为真 ⇒ 帧末继续上膛。
 */
final class AsyncHttpPumpTest extends SmokeTestCase
{
    public function testPumpRunsEvenWithoutIoPoller(): void
    {
        $transport = new CountingTransport();
        $http = new AsyncHttp(TaskDispatch::sync(), $transport);
        $pumps = new PumpHost(new StubPumpHost());
        $pumps->attach(null, null, $http); // io = null，http ≠ null

        $pumps->pumpIo();

        check('pumpIo · io 未挂载也推进 http（否则在途永不结算）', $transport->pumps === 1, 'pump 次数=' . $transport->pumps);
        check('hasPolledAsync · 无在途时为假', !$pumps->hasPolledAsync(), '有在途=' . ($pumps->hasPolledAsync() ? 'yes' : 'no'));

        $transport->inflight = 1;
        check('hasPolledAsync · 帧内有在途时为真（保持帧时钟上膛）', $pumps->hasPolledAsync(), '有在途=' . ($pumps->hasPolledAsync() ? 'yes' : 'no'));

        $this->flushChecks();
    }
}

/** 只计数的传输后端 */
final class CountingTransport implements HttpTransport
{
    public int $pumps = 0;

    public int $inflight = 0;

    public function submit(string $method, string $url, array $opts): Future
    {
        return Future::pending();
    }

    public function pump(): void
    {
        $this->pumps = $this->pumps + 1;
    }

    public function inFlight(): int
    {
        return $this->inflight;
    }
}

/** 最小宿主：pumpIo 只用它做 UI 线程断言 */
final class StubPumpHost implements PumpHostHost
{
    public function assertUiThread(string $where): void
    {
    }

    public function lastTimestampMs(): float
    {
        return 0.0;
    }

    public function lastInputMs(): float
    {
        return 0.0;
    }
}
