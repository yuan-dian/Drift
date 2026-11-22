<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\CancelSupport;
use Drift\App\HttpRequestJob;
use Drift\App\Io\AsyncHttp;
use Drift\App\Io\CurlMultiTransport;
use Drift\App\Io\HttpResponse;
use Drift\App\Io\HttpTransport;
use Drift\App\Job;
use Drift\App\TaskHost;
use Drift\Async\Future;
use Drift\Dev\Tests\Support\RecordingTaskHost;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * `AsyncHttp` 门面的**选路面**与**契约面**（无网络，纯替身）。
 *
 * 真 socket / AOT 真机并发在 `probes`（curl_multi 已验证 E2 + 本轮帧内泵探针）。
 * 这里钉三件会静默错的事：
 * - 小请求直达传输后端、pump / inFlight 委托给同一个后端；
 * - 大 body / multipart 分流到 HttpRequestJob，且回传数组重建出 HttpResponse；
 * - 选项名拼错当场抛 —— 与阻塞 Http 共用校验，不另立一份。
 */
final class AsyncHttpTest extends SmokeTestCase
{
    public function testSmallRequestGoesToTransportAndPumpDelegates(): void
    {
        $transport = new SpyTransport();
        $http = new AsyncHttp(null, $transport);

        $future = $http->get('http://example.test/x', ['headers' => ['X-T' => '1']]);
        check('小请求 · 返回尚未结算的 Future', !$future->isDone(), $future->isDone() ? 'done' : 'pending');
        check('小请求 · 直达传输后端', \count($transport->submitted) === 1, '提交数=' . \count($transport->submitted));
        check('小请求 · 方法与 URL 原样转交', ($transport->submitted[0]['method'] ?? '') === 'GET'
            && ($transport->submitted[0]['url'] ?? '') === 'http://example.test/x', \json_encode($transport->submitted[0] ?? null));

        $http->pump();
        check('pump · 委托给后端', $transport->pumps === 1, 'pump 次数=' . $transport->pumps);
        $http->inFlight();
        check('inFlight · 委托给后端', $transport->inFlightReads === 1, '读次数=' . $transport->inFlightReads);

        $this->flushChecks();
    }

    public function testLargeBodyIsRoutedToWorkerAndRebuilt(): void
    {
        $wire = [
            'status' => 200,
            'headers' => ['content-type' => 'application/json'],
            'body' => '{"ok":true}',
            'url' => 'http://example.test/upload',
            'elapsedMs' => 12.5,
        ];
        $host = new RecordingTaskHost([HttpRequestJob::class => $wire]);
        $transport = new SpyTransport();
        $http = new AsyncHttp($host, $transport);

        $big = \str_repeat('x', AsyncHttp::INLINE_BODY_LIMIT + 1);
        $got = null;
        $http->post('http://example.test/upload', $big)
            ->then(static function (HttpResponse $r) use (&$got): void { $got = $r; });

        check('大 body · 不进帧内后端', $transport->submitted === [], '帧内提交数=' . \count($transport->submitted));
        $jobs = $host->ofClass(HttpRequestJob::class);
        check('大 body · 走进程池 Job', \count($jobs) === 1, 'Job 数=' . \count($jobs));
        if ($jobs !== []) {
            check('大 body · args 可 JSON 化（callable 不进跨进程参数）',
                \json_encode($jobs[0]->args()) !== false, \json_last_error_msg());
            check('大 body · 非 multipart 的 opts 不含 onProgress',
                !isset($jobs[0]->args()['opts']['onProgress']), \json_encode($jobs[0]->args()['opts'] ?? null));
        }
        check('大 body · 重建出 HttpResponse', $got instanceof HttpResponse, $got === null ? 'null' : \get_debug_type($got));
        if ($got !== null) {
            check('大 body · 状态码 / body / 耗时保真', $got->status() === 200
                && $got->body() === '{"ok":true}' && $got->elapsedMs() === 12.5,
                \sprintf('%d / %s / %.1f', $got->status(), $got->body(), $got->elapsedMs()));
            check('大 body · 头归一（小写）', $got->header('Content-Type') === 'application/json', \json_encode($got->headers()));
        }

        $this->flushChecks();
    }

    public function testMultipartIsRoutedToWorker(): void
    {
        $host = new RecordingTaskHost([HttpRequestJob::class => []]);
        $transport = new SpyTransport();
        $http = new AsyncHttp($host, $transport);

        // multipart 必须有真文件（Http::buildMultipart 会 is_file）—— 用测试文件自身。
        $file = __FILE__;
        $http->request('POST', 'http://example.test/upload', ['files' => ['f' => $file]]);

        check('multipart · 不进帧内后端', $transport->submitted === [], '帧内提交数=' . \count($transport->submitted));
        check('multipart · 走进程池 Job', \count($host->ofClass(HttpRequestJob::class)) === 1, 'Job 数=' . \count($host->ofClass(HttpRequestJob::class)));

        $this->flushChecks();
    }

    public function testUnknownOptionRejectedBySharedValidation(): void
    {
        $http = new AsyncHttp(null, new SpyTransport());
        $threw = null;
        try {
            $http->get('http://example.test/x', ['timeOut' => 1.0]);
        } catch (\InvalidArgumentException $e) {
            $threw = $e;
        }

        check('选项校验 · 与 Http 共用，拼错当场抛', $threw instanceof \InvalidArgumentException, $threw === null ? '没抛' : '抛了');

        $this->flushChecks();
    }

    public function testOnProgressRequiresMultipart(): void
    {
        $http = new AsyncHttp(null, new SpyTransport());
        $threw = null;
        try {
            // 大 body 非 multipart 也想报进度 —— 门面要当场挡，不能静默吞掉回调。
            $http->post('http://example.test/x', \str_repeat('x', AsyncHttp::INLINE_BODY_LIMIT + 1), [
                'onProgress' => static function (float $f): void {},
            ]);
        } catch (\InvalidArgumentException $e) {
            $threw = $e;
        }

        check('进度校验 · 非 multipart 带 onProgress 当场抛', $threw instanceof \InvalidArgumentException, $threw === null ? '没抛' : '抛了');

        $this->flushChecks();
    }

    public function testWorkerTimeoutFollowsHttpRequestTimeout(): void
    {
        $host = new RecordingTaskHost([HttpRequestJob::class => []]);
        $http = new AsyncHttp($host, new SpyTransport());

        $big = \str_repeat('x', AsyncHttp::INLINE_BODY_LIMIT + 1);
        $http->post('http://example.test/x', $big, ['timeout' => 60.0]);
        $jobs = $host->ofClass(HttpRequestJob::class);
        $timeoutMs = $jobs === [] ? 0 : (int) ($jobs[0]->args()['timeoutMs'] ?? 0);

        check('worker 上限 · 跟随 opts.timeout（60s + 10s 余量），不被默认 30s 误杀',
            $timeoutMs === 70_000, 'timeoutMs=' . $timeoutMs);

        $this->flushChecks();
    }

    public function testSetTaskHostUpdatesSameFacade(): void
    {
        $t1 = new SpyTransport();
        $http = new AsyncHttp(null, $t1);
        $host = new RecordingTaskHost([HttpRequestJob::class => []]);
        $http->setTaskHost($host);

        $big = \str_repeat('x', AsyncHttp::INLINE_BODY_LIMIT + 1);
        $http->post('http://example.test/x', $big);

        check('setTaskHost · 同一实例更新后大请求走新 host', \count($host->ofClass(HttpRequestJob::class)) === 1,
            'Job 数=' . \count($host->ofClass(HttpRequestJob::class)));

        $this->flushChecks();
    }

    public function testSetTransportRejectedWhileInFlight(): void
    {
        $t1 = new SpyTransport();
        $http = new AsyncHttp(null, $t1);
        $http->get('http://example.test/x'); // t1 记一条在途（SpyTransport::inFlight 返回提交数）

        $threw = null;
        try {
            $http->setTransport(new SpyTransport());
        } catch (\LogicException $e) {
            $threw = $e;
        }

        check('setTransport · 有在途请求时拒绝换后端（否则旧请求无人 pump）', $threw instanceof \LogicException, $threw === null ? '没抛' : '抛了');

        $this->flushChecks();
    }

    public function testMultipartProgressReachesRootFutureSink(): void
    {
        // N6 第二条分流的进度链：门面把 opts.onProgress 挂到 JobRunner 返回的**根**
        // Future 上（submitWorker），worker 经 JobProgress 报进度时应触发它。
        // 这里用可控 Future 替身验证转接，真机那半由 probe-async ⑥⑦⑧ 兜。
        $host = new ProgressTaskHost();
        $http = new AsyncHttp($host, new SpyTransport());
        $got = [];

        $http->request('POST', 'http://example.test/upload', [
            'files' => ['f' => __FILE__],
            'onProgress' => static function (float $f) use (&$got): void { $got[] = $f; },
        ]);

        check('进度 · Job 的 opts 不带 callable（过不了进程边界）',
            !isset($host->job()->args()['opts']['onProgress']),
            \json_encode($host->job()->args()['opts'] ?? null));
        check('进度 · 还没报时 sink 安静', $got === [], \json_encode($got));

        $host->future()->reportProgress(0.5);
        $host->future()->reportProgress(1.0);
        check('进度 · 根 Future 报进度触发门面 sink', $got === [0.5, 1.0], \json_encode($got));

        $this->flushChecks();
    }

    public function testNoCurlCloseInAotPath(): void
    {
        $file = (string) (new \ReflectionClass(CurlMultiTransport::class))->getFileName();
        $source = (string) \file_get_contents($file);

        check('AOT · 帧内后端不调 curl_close（对象属性 handle 传进去会原生崩溃），'
            . '症状是请求成功但界面永久 loading',
            !\str_contains($source, '\\curl_close('),
            '源码里出现了 curl_close 调用');
        check('AOT · remove handle 仍在（资源释放靠它 + 对象回收）',
            \str_contains($source, '\\curl_multi_remove_handle('),
            '漏了 curl_multi_remove_handle');

        $this->flushChecks();
    }
}

/**
 * 只记账的传输后端：submit 返回一条永不自动结算的 Future（由测试控制）。
 */
final class SpyTransport implements HttpTransport
{
    /** @var list<array{method: string, url: string, opts: array<string, mixed>}> */
    public array $submitted = [];

    public int $pumps = 0;

    public int $inFlightReads = 0;

    public function submit(string $method, string $url, array $opts): Future
    {
        $this->submitted[] = ['method' => $method, 'url' => $url, 'opts' => $opts];

        return Future::pending();
    }

    public function pump(): void
    {
        $this->pumps = $this->pumps + 1;
    }

    public function inFlight(): int
    {
        $this->inFlightReads = $this->inFlightReads + 1;

        return \count($this->submitted);
    }
}

/**
 * 暴露提交的任务与 Future，供测试手动报进度 —— 只用于进度转接判据。
 */
final class ProgressTaskHost implements TaskHost
{
    private ?Job $submitted = null;

    private ?Future $future = null;

    public function submit(Job $job): Future
    {
        $this->submitted = $job;
        $this->future = Future::pending();

        return $this->future;
    }

    public function job(): ?Job
    {
        return $this->submitted;
    }

    public function future(): Future
    {
        return $this->future ?? throw new \LogicException('还没有提交任务');
    }

    public function cancelSupport(): CancelSupport
    {
        return CancelSupport::AlreadyDone;
    }

    public function pending(): int
    {
        return 1;
    }

    public function needsPolling(): bool
    {
        return true;
    }

    public function drain(callable $onEach, ?int $budgetMs = null): void
    {
    }
}
