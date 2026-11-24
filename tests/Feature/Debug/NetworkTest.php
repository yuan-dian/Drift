<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\HttpRequestJob;
use Drift\App\Io\AsyncHttp;
use Drift\App\Io\HttpResponse;
use Drift\App\Io\HttpTransport;
use Drift\App\TaskDispatch;
use Drift\Async\Future;
use Drift\Debug\Session\Recorder;
use Drift\Dev\Tests\Support\RecordingTaskHost;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * S3c 判据：网络请求进同一条帧时间轴，且"谁发的、落地改了谁"可回答。
 *
 * ★ 为什么要两条路径都测：`AsyncHttp` 对大 body / multipart 走 `submitWorker`
 *   而不是 transport。只装一个 `RecordingTransport` 装饰器会漏掉这条路径 ——
 *   而上传恰恰是最常出问题的一类。这里用门面唯一入口的观察钩子一次覆盖两条。
 *
 * ★ 为什么用替身 transport / task host：真 socket 不稳定且带隐私数据；证据骨架
 *   要钉的是"帧号对齐 + 落地归因"，那与后端是 curl 还是替身无关。
 */
final class NetworkTest extends SmokeTestCase
{
    public function testInlinePathRecordsSkeletonAndSettlesOnFacade(): void
    {
        $recorder = Recorder::install();
        try {
            $transport = new PendingTransport();
            $http = new AsyncHttp(TaskDispatch::sync(), $transport);

            $recorder->beginFrame(0.0);
            $future = $http->get('https://api.example.com/rows.php');
            $recorder->endFrame([], 0.0);
            check('发起帧记下请求骨架', \count($recorder->networkRows()) === 1, '请求数=' . \count($recorder->networkRows()));
            $row = $recorder->networkRows()[0];
            check('骨架有 URL / host / 发起帧', $row->url === 'https://api.example.com/rows.php'
                && $row->host === 'api.example.com' && $row->startedAtFrame === 1, \json_encode($row->toArray()));
            check('发起者取业务调用点（跳过 IO 门面自身）', $row->initiator !== ''
                && !\str_contains($row->initiator, 'AsyncHttp.php'), 'initiator=' . $row->initiator);
            check('帧内路径标 inline', $row->path === 'inline', 'path=' . $row->path);
            check('结算前在途', $row->settledAtFrame === 0 && !$row->ok, \json_encode($row->toArray()));

            $recorder->beginFrame(16.0);
            $transport->settle(new HttpResponse(200, [], '{"rows":[]}', 'https://api.example.com/rows.php', 18.2));
            $recorder->endFrame([], 0.0);

            $settled = $recorder->networkAt($row->requestId);
            check('结算帧号对齐当前帧', $settled?->settledAtFrame === 2, 'settled=' . ($settled?->settledAtFrame ?? -1));
            check('pendingFrames 可复算', $settled?->pendingFrames() === 1, 'pending=' . ($settled?->pendingFrames() ?? -1));
            $json = \json_encode($settled?->toArray()) ?: '';
            check('记状态码 / 字节 / 耗时，不记 body', $settled?->status === 200
                && $settled?->bytes === \strlen('{"rows":[]}') && \abs(($settled?->elapsedMs ?? 0.0) - 18.2) < 0.001
                && !\str_contains($json, '{"rows":[]}'), $json);
            check('Future 正常结算，观测没改语义', $future->isDone(), 'done=' . \var_export($future->isDone(), true));
            check('窗口自报独立', $recorder->networkCoverage()['capacity'] === Recorder::NETWORK_CAPACITY,
                \json_encode($recorder->networkCoverage()));
        } finally {
            Recorder::uninstall();
        }

        $this->flushChecks();
    }

    public function testWorkerPathRecordsToo(): void
    {
        $recorder = Recorder::install();
        try {
            $wire = [
                'status' => 201,
                'headers' => [],
                'body' => 'ok',
                'url' => 'https://api.example.com/upload',
                'elapsedMs' => 42.0,
            ];
            $host = new RecordingTaskHost([HttpRequestJob::class => $wire]);
            $http = new AsyncHttp(TaskDispatch::background($host), new PendingTransport());

            $recorder->beginFrame(0.0);
            // 已结算的 Future：onSettle 在门面挂载时补发 —— 这正是同步 dispatch 的常态。
            $big = \str_repeat('x', AsyncHttp::INLINE_BODY_LIMIT + 1);
            $http->post('https://api.example.com/upload', $big);
            $recorder->endFrame([], 0.0);

            $row = $recorder->networkRows()[0] ?? null;
            check('worker 路径也记了请求', $row !== null, '请求数=' . \count($recorder->networkRows()));
            check('路径标 worker（不是 inline）', $row?->path === 'worker', 'path=' . ($row?->path ?? '?'));
            check('补发的结算记下真实 status / 耗时', $row?->status === 201 && \abs(($row?->elapsedMs ?? 0.0) - 42.0) < 0.001,
                \json_encode($row?->toArray()));
        } finally {
            Recorder::uninstall();
        }

        $this->flushChecks();
    }

    public function testFailedRequestSettlesAsFailureNotInFlight(): void
    {
        $recorder = Recorder::install();
        try {
            $transport = new PendingTransport();
            $http = new AsyncHttp(TaskDispatch::sync(), $transport);
            $recorder->beginFrame(0.0);
            $http->get('https://api.example.com/missing')->catch(static function (\Throwable $e): void {
                // 失败必须有人接，否则 Future 的 Fatal 序列会 abort 整个进程 ——
                // 这与网络记录无关，是异步契约的一部分。
            });
            $id = $recorder->networkRows()[0]->requestId;
            $recorder->endFrame([], 0.0);

            $recorder->beginFrame(16.0);
            $transport->fail(new \RuntimeException('DNS 失败：不要记进证据（可能含主机名）'));
            $recorder->endFrame([], 0.0);

            $row = $recorder->networkAt($id);
            check('失败请求也结算，不永远挂在途', $row?->settledAtFrame === 2 && !$row?->ok, \json_encode($row?->toArray()));
            check('失败不记错误全文', !\str_contains(\json_encode($row?->toArray()) ?: '', 'DNS'), \json_encode($row?->toArray()));
        } finally {
            Recorder::uninstall();
        }

        $this->flushChecks();
    }

    public function testProgressScalarIsPreservedThroughSettlement(): void
    {
        $recorder = Recorder::install();
        try {
            $recorder->beginFrame(0.0);
            $id = Recorder::tapNetworkStart('POST', 'https://api.example.com/upload', 'worker');
            $recorder->endFrame([], 0.0);
            Recorder::tapNetworkProgress($id, 0.42);
            Recorder::tapNetworkSettle($id, 200, 10, 5.0, true);

            $row = $recorder->networkAt($id);
            check('进度标量结算后仍在（不被空串擦掉）', $row?->progress === '42%', 'progress=' . ($row?->progress ?? '?'));
        } finally {
            Recorder::uninstall();
        }

        $this->flushChecks();
    }

    public function testSettledRequestAttributesLandedRebuild(): void
    {
        $recorder = Recorder::install();
        try {
            $constraints = \Drift\Geometry\BoxConstraints::loose(new \Drift\Geometry\Size(200.0, 120.0));
            $app = new CounterApplication();
            $app->attach(new \Drift\Dev\Smoke\Frame\SurfaceProbe(), $constraints);
            $app->frame(0.0, $constraints); // 挂载出 state 与其宿主元素

            $id = Recorder::tapNetworkStart('GET', 'https://api.example.com/rows.php', 'inline');
            // 手工帧边界：结算标记压住后立刻写状态 —— 这正是 Future 观察者在
            // resolve→links 之间压标记、用户 then() 里 setState 的真实时序。
            $recorder->beginFrame(16.0);
            Recorder::tapNetworkSettle($id, 200, 10, 5.0, true);
            CounterState::$last?->increment();
            $recorder->endFrame([], 0.0);

            $nodeId = CounterState::$last?->element()?->debugId ?? 0;
            $row = $recorder->networkAt($id);
            check('结算同帧的状态写入回填到 landed', $row !== null && \in_array($nodeId, $row->landed, true),
                'node=' . $nodeId . ' landed=' . \json_encode($row?->landed ?? []));

            // 找到写状态那一帧的边：帧号由 app 的帧 + 手工帧决定，遍历而非猜。
            $detail = '';
            foreach ($recorder->frames() as $frame) {
                foreach ($frame->causes as $edge) {
                    if ($edge->toId === $nodeId && \str_contains($edge->detail, 'request:')) {
                        $detail = $edge->detail;
                        break 2;
                    }
                }
            }
            // ★ detail 只带 request id，**不带 URL**。URL 带签名参数时每帧都不同，
            //   而 detail 进 `Digest::ofFrame()` ⇒ facts 指纹永远对不上；URL 里的
            //   token 还会绕过报文脱敏随证据导出。要 URL 就 `network(request:N)` 查。
            check('状态写入的因果边带上网络归因', \str_contains($detail, 'request:' . $id), 'detail=' . $detail);
            check('因果 detail 不夹带 URL（否则污染 facts 指纹）',
                !\str_contains($detail, '://') && !\str_contains($detail, 'api.example.com'), 'detail=' . $detail);
        } finally {
            Recorder::uninstall();
        }

        $this->flushChecks();
    }

    public function testSettleMarkerDoesNotLeakIntoNextFrame(): void
    {
        $recorder = Recorder::install();
        try {
            $constraints = \Drift\Geometry\BoxConstraints::loose(new \Drift\Geometry\Size(200.0, 120.0));
            $app = new CounterApplication();
            $app->attach(new \Drift\Dev\Smoke\Frame\SurfaceProbe(), $constraints);
            $app->frame(0.0, $constraints);

            $id = Recorder::tapNetworkStart('GET', 'https://api.example.com/rows.php', 'inline');
            // 帧 2：结算，但不写状态 —— landed 应为空
            $recorder->beginFrame(16.0);
            Recorder::tapNetworkSettle($id, 200, 10, 5.0, true);
            $recorder->endFrame([], 0.0);
            // 帧 3：无关的状态写入 —— 绝不能算到帧 2 那次结算头上
            CounterState::$last?->increment();
            $app->frame(32.0, $constraints);

            $row = $recorder->networkAt($id);
            check('结算帧没写状态 ⇒ landed 为空', $row !== null && $row->landed === [],
                'landed=' . \json_encode($row?->landed ?? []));

            // 帧 3 的边可以带 request 吗？帧 3 里没有新结算 ⇒ 不该带。
            $leaked = false;
            foreach ($recorder->frames() as $frame) {
                if ($frame->id <= 2) {
                    continue;
                }
                foreach ($frame->causes as $edge) {
                    if (\str_contains($edge->detail, 'request:')) {
                        $leaked = true;
                    }
                }
            }
            check('归因标记帧末清零，不污染下一帧的无状态结算', !$leaked);
        } finally {
            Recorder::uninstall();
        }

        $this->flushChecks();
    }

    public function testNetworkWindowTrimsIndependentlyAndReports(): void
    {
        $recorder = Recorder::install(2); // 帧窗口只有 2，网络窗口仍应是 500
        try {
            for ($i = 1; $i <= 3; ++$i) {
                $recorder->beginFrame((float) $i);
                Recorder::tapNetworkStart('GET', 'https://api.example.com/r' . $i, 'inline');
                $recorder->endFrame([], 0.0);
            }
            $coverage = $recorder->networkCoverage();
            check('网络窗口独立于帧窗口：帧被裁但请求留着', \count($recorder->networkRows()) === 3
                && $coverage['capacity'] === 500, \json_encode($coverage));
            check('裁剪账自报 from/to', $coverage['from'] === 1 && $coverage['to'] === 3, \json_encode($coverage));
        } finally {
            Recorder::uninstall();
        }

        $this->flushChecks();
    }

    public function testNetworkQueryReportsWindowAndMisses(): void
    {
        $recorder = Recorder::install();
        try {
            $query = new \Drift\Debug\Session\Query($recorder);
            $recorder->beginFrame(0.0);
            Recorder::tapNetworkStart('GET', 'https://api.example.com/ok', 'inline');
            $recorder->endFrame([], 0.0);

            $all = $query->network();
            check('network() 返回骨架行 + 窗口 + 总数', \count($all['rows']) === 1
                && $all['total'] === 1 && ($all['window']['capacity'] ?? 0) === 500, \json_encode($all));
            check('network(request) 单条可读', \count($query->network(1)['rows']) === 1);
            check('未知请求报 miss 而不是空成功', ($query->network(999)['miss'] ?? '') === 'unknown-request',
                \json_encode($query->network(999)));
        } finally {
            Recorder::uninstall();
        }

        $this->flushChecks();
    }

    /**
     * 空窗口的 miss 分类，以及"列表取最新"。
     *
     * 两处都是同源判据写了两遍的后果：`network()` 只比 `from`（空窗口 `from=0`，
     * 任何 id 都大于它 ⇒ 一律报 `unknown-request`，把"还没开始录"说成"这个 id
     * 不存在"）；`array_slice($rows, 0, …)` 取的是最旧的 N 条，窗口满 500 条时
     * 列表里全是几分钟前的请求。
     */
    public function testNetworkMissDistinguishesEmptyWindowAndTakesNewest(): void
    {
        $recorder = Recorder::install();
        try {
            $query = new \Drift\Debug\Session\Query($recorder);

            $empty = $query->network(5);
            check(
                '空窗口的 network() 报窗口外，不报 unknown-request',
                ($empty['miss'] ?? '') === 'outside-network-window',
                \json_encode($empty),
            );

            for ($i = 1; $i <= 5; ++$i) {
                $recorder->beginFrame((float) $i);
                Recorder::tapNetworkStart('GET', 'https://api.example.com/r' . $i, 'inline');
                $recorder->endFrame([], 0.0);
            }

            $newest = $query->network(null, 2);
            $ids = \array_column($newest['rows'], 'request');
            check(
                'network() 取最新的几条，不是最旧的',
                $ids === [4, 5] && $newest['total'] === 5,
                \json_encode($ids) . ' total=' . $newest['total'],
            );
        } finally {
            Recorder::uninstall();
        }

        $this->flushChecks();
    }

    public function testBodyCaptureTruncatesAndReportsSecretCount(): void
    {
        $big = \str_repeat('x', 100);
        $rec = \Drift\Debug\Session\NetworkBodyRecord::capture(
            7,
            '{"password":"' . $big . '"}',
            \str_repeat('y', 100),
            10,
        );
        check('超上限的请求体真被截断', \strlen($rec->requestBody) === 10 && $rec->request['truncated'] === true,
            'len=' . \strlen($rec->requestBody) . ' ' . \json_encode($rec->request));
        check('响应体同样截断', \strlen($rec->responseBody) === 10 && $rec->response['truncated'] === true,
            \json_encode($rec->response));
        check('原始字节数照报（不是截断后的长度）', $rec->request['bytes'] === \strlen('{"password":"' . $big . '"}')
            && $rec->response['bytes'] === 100, \json_encode($rec->request));
        // ★ 格式按**原文**判，不按截断后的片段判：片段 `{"password` 不是 JSON，
        //   但原文是。报 `text` 才是谎报（读者会以为原文就是纯文本）。片段这件事
        //   由 `truncated=true` 单独说，两者合起来才完整。
        check('格式按原文判，不因截断而谎报 text', $rec->request['format'] === 'json', $rec->request['format']);
        // ★ 顺序牙：脱敏必须在截断之前。若退回"先截断"，片段 `{"password` 后面
        //   没有值，正则匹配不到 ⇒ redacted=0，密码就会原样留在存档里。
        check('脱敏在截断之前：被截断的请求体也换掉了密码', $rec->request['redacted'] === 1,
            \json_encode($rec->request));

        $json = \Drift\Debug\Session\NetworkBodyRecord::capture(
            8,
            '{"user":"bob","password":"hunter2"}',
            '{"token":"abc","rows":[]}',
        );
        check('JSON 脱敏计数报真的换了几处', $json->request['redacted'] === 1 && $json->response['redacted'] === 1,
            \json_encode([$json->request, $json->response]));
        $decoded = \json_decode($json->responseBody, true);
        check('业务字段留在报文里（否则定位不了字段错）', \is_array($decoded)
            && ($decoded['rows'] ?? null) === []
            && !\str_contains($json->requestBody, 'hunter2'), $json->responseBody);

        $this->flushChecks();
    }

    /**
     * 脱敏可以关，但关掉必须自报。
     *
     * 关的理由：本地排查"密码到底传对没有"需要看原文（DevTools 就是这么用的）。
     * 但 `redacted: 0` 有两种含义 —— "扫过、没发现" 与 "根本没扫"。不带
     * `redact` 位，读者会把明文当成"已检查且干净"，那是一次自报说谎。
     */
    public function testRedactionCanBeTurnedOffAndSaysSo(): void
    {
        $off = \Drift\Debug\Session\NetworkBodyRecord::capture(
            9,
            '{"password":"hunter2"}',
            '{"token":"abc"}',
            \Drift\Debug\Session\NetworkBodyRecord::MAX_BYTES,
            false,
        );
        check('关掉脱敏时明文原样保留', \str_contains($off->requestBody, 'hunter2'), $off->requestBody);
        check(
            '关掉脱敏时 meta 记 redact=false（0 处 ≠ 扫过没发现）',
            $off->request['redact'] === false && $off->request['redacted'] === 0,
            \json_encode($off->request),
        );

        $text = \implode("\n", \Drift\Dev\Debug\View\Network::body([
            'found' => true,
            'request' => 9,
            'requestBody' => $off->requestBody,
            'requestMeta' => $off->request,
            'responseBody' => $off->responseBody,
            'responseMeta' => $off->response,
        ]));
        check(
            '视图对未脱敏的报文说"含明文"，不报"脱敏 0 处"',
            \str_contains($text, '含明文') && !\str_contains($text, '脱敏 0 处'),
            $text,
        );

        $this->flushChecks();
    }

    public function testBodyQueryMissesAreDistinct(): void
    {
        $recorder = Recorder::install();
        try {
            $query = new \Drift\Debug\Session\Query($recorder);
            $before = $query->networkBody(1);
            check('从未开始的请求报窗口外，不是 body 丢了', ($before['miss'] ?? '') === 'outside-network-window',
                \json_encode($before));

            $transport = new PendingTransport();
            $http = new AsyncHttp(TaskDispatch::sync(), $transport);
            $recorder->beginFrame(0.0);
            $http->get('https://api.example.com/x');  // id=1，结算后有 body
            $recorder->endFrame([], 0.0);
            $recorder->beginFrame(16.0);
            $transport->settle(new HttpResponse(200, [], 'ok', 'https://api.example.com/x', 1.0));
            $recorder->endFrame([], 0.0);
            check('真的存进一条报文，窗口才非空', ($query->networkBody(1)['found'] ?? false) === true,
                \json_encode($query->networkBody(1)));

            $unknown = $query->networkBody(999);
            check('窗口内但从没这个请求报 unknown', ($unknown['miss'] ?? '') === 'unknown-request',
                \json_encode($unknown));

            $recorder->beginFrame(32.0);
            $http->get('https://api.example.com/inflight');  // id=2，有骨架但没结算 ⇒ 没 body
            $recorder->endFrame([], 0.0);
            check('骨架在但没存报文报 body-not-retained', ($query->networkBody(2)['miss'] ?? '') === 'body-not-retained',
                \json_encode($query->networkBody(2)));
            $transport->futures[1]->resolve(new HttpResponse(200, [], 'ok', 'https://api.example.com/inflight', 1.0));
        } finally {
            Recorder::uninstall();
        }

        $this->flushChecks();
    }

    public function testBodyQueryAndViewKeepRequestIdAndRedactSecrets(): void
    {
        $recorder = Recorder::install();
        try {
            $transport = new PendingTransport();
            $http = new AsyncHttp(TaskDispatch::sync(), $transport);
            $query = new \Drift\Debug\Session\Query($recorder);

            $recorder->beginFrame(0.0);
            $http->post(
                'https://api.example.com/login',
                '{"user":"bob","password":"hunter2"}',
            );
            $recorder->endFrame([], 0.0);
            $id = $recorder->networkRows()[0]->requestId;

            $recorder->beginFrame(16.0);
            $transport->settle(new HttpResponse(
                200,
                [],
                '{"data":{"token":"abc123","rows":[{"name":"ok"}]}}',
                'https://api.example.com/login',
                7.5,
            ));
            $recorder->endFrame([], 0.0);

            $answer = $query->networkBody($id);
            check('报文查询命中且保留 requestId（元信息不覆盖 id）', ($answer['found'] ?? false) === true
                && ($answer['request'] ?? 0) === $id
                && \is_array($answer['requestMeta'] ?? null), \json_encode($answer));
            check('请求体脱敏但业务字段保留', \str_contains($answer['requestBody'] ?? '', '"user": "bob"')
                && \str_contains($answer['requestBody'] ?? '', '[REDACTED]')
                && !\str_contains($answer['requestBody'] ?? '', 'hunter2'), $answer['requestBody'] ?? '');
            check('响应体脱敏但 rows 保留（200 但字段错要看得见）', \str_contains($answer['responseBody'] ?? '', '"rows"')
                && \str_contains($answer['responseBody'] ?? '', '[REDACTED]')
                && !\str_contains($answer['responseBody'] ?? '', 'abc123'), $answer['responseBody'] ?? '');
            check('脱敏处数如实报出', ($answer['requestMeta']['redacted'] ?? 0) === 1
                && ($answer['responseMeta']['redacted'] ?? 0) === 1, \json_encode($answer));

            $lines = \Drift\Dev\Debug\View\Network::body($answer);
            $text = \implode("\n", $lines);
            check('视图输出带上 id 与脱敏元信息', \str_contains($text, "请求 #{$id} 报文")
                && \str_contains($text, '脱敏 1 处') && \str_contains($text, '"name": "ok"'), $text);

            $miss = $query->networkBody($id, 'response', 8 * 1024);
            check('part=response 只给响应，不泄露请求体', !\array_key_exists('requestBody', $miss)
                && \array_key_exists('responseBody', $miss), \json_encode(\array_keys($miss)));
        } finally {
            Recorder::uninstall();
        }

        $this->flushChecks();
    }

    public function testNoRecorderIsNoOp(): void
    {
        Recorder::uninstall();
        $transport = new PendingTransport();
        $http = new AsyncHttp(TaskDispatch::sync(), $transport);
        $future = $http->get('https://api.example.com/x');
        check('未装录制器时门面照常工作', !$future->isDone() && \count($transport->futures) === 1);
        $transport->settle(new HttpResponse(200, [], 'ok', 'https://api.example.com/x', 1.0));
        check('未装录制器时 Future 正常结算', $future->isDone());

        $this->flushChecks();
    }
}

/** 可控结算的传输替身：submit 返回 pending，由测试决定何时 resolve / reject。 */
final class PendingTransport implements HttpTransport
{
    /** @var list<Future> */
    public array $futures = [];

    public function submit(string $method, string $url, array $opts): Future
    {
        return $this->futures[] = Future::pending();
    }

    public function settle(HttpResponse $response): void
    {
        $this->futures[array_key_last($this->futures)]?->resolve($response);
    }

    public function fail(\Throwable $error): void
    {
        $this->futures[array_key_last($this->futures)]?->reject($error);
    }

    public function pump(): void
    {
    }

    public function inFlight(): int
    {
        return \count($this->futures);
    }
}
