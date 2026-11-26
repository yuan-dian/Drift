<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 外部工作域查询：请求与后端任务怎么落到帧上。
 *
 * 两个子域共用同一套形状，所以合成一类：
 *
 * | | 网络 | 异步任务 |
 * |---|---|---|
 * | 主体 | `NetworkRecord`（请求） | `AsyncRecord`（job） |
 * | 窗口 | 500 条 | 200 条 |
 * | 附件 | 报文（200 条，默认不带出） | 无 |
 * | 落到哪 | `landed[]` + 因果 detail | `landed[]` + 因果 detail |
 * | 缺省 | 开（骨架）/ 关（报文） | 默认关 |
 *
 * 两边都要回答同一件事：**这段外部工作在第几帧落到哪个节点上**。抓包工具回答
 * "发了什么、回了什么"，它们给不了第三层 —— 那正是这里的定位。
 *
 * ## 窗口自报是硬义务
 *
 * 三个窗口都比帧窗口窄，且异步默认关。查不到时不能让读者以为是"那段时间没发生"：
 * 要么被裁掉了，要么录制器当时没装。所以 `window` 随答案一起给，`captured` 与
 * "空集合"分开报 —— 两者的下一步动作相反（一个是补采集，一个是改关键词）。
 *
 * `missReason()` 是网络两个查询的**唯一**判据。早先 `network()` 只比 `from`
 * （空窗口 `from=0`，任何 id 都大于它 ⇒ 一律报 `unknown-request`），`networkBody()`
 * 先看 `requests === 0` —— 同一个请求，两个查询给两种答案。
 *
 * @internal 门面 {@see Query} 的实现细节，不是应用开发者的 API 面
 */
final class TrafficQuery
{
    public function __construct(private readonly Recorder $recorder)
    {
    }

    /**
     * 网络请求骨架：请求 → 帧对齐 → 落地节点，与帧因果共用同一条时间轴。
     *
     * ## 为什么是骨架而不是报文
     *
     * 记 body / header 既撑爆证据包又碰隐私，而且抓包做得更好 —— 证据的定位是定位，
     * 不是副本。要看报文时用 {@see self::networkBody()} 显式问一次。
     *
     * @return array{rows: list<array<string, mixed>>, window: array<string, mixed>, total: int}
     */
    public function network(?int $request = null, int $limit = 50): array
    {
        $window = $this->recorder->networkCoverage();
        if ($request !== null) {
            $record = $this->recorder->networkAt($request);
            if ($record === null) {
                return [
                    'rows' => [],
                    'window' => $window,
                    'total' => 0,
                    'miss' => $this->missReason($request, $window),
                ];
            }

            return ['rows' => [$record->toArray()], 'window' => $window, 'total' => 1];
        }

        $rows = \array_map(
            static fn(NetworkRecord $r): array => $r->toArray(),
            $this->recorder->networkRows(),
        );
        $total = \count($rows);

        return [
            // 取**最新**的 N 条：查网络面板的人要看刚发的请求。早先 `array_slice($rows, 0, …)`
            // 取的是最旧的 —— 窗口满 500 条时，列表里全是几分钟前的东西。
            'rows' => $limit > 0 ? \array_slice($rows, -$limit) : $rows,
            'window' => $window,
            'total' => $total,
        ];
    }

    /**
     * 按 requestId 取报文（骨架的可选附件）。
     *
     * ## 为什么不并进 network()
     *
     * 报文默认不出现——体积、隐私、协议 8MB 上限三条理由。把它做成独立查询，
     * `network()` 保持默认轻量可导出；要看 body 时显式问一次，还带窗口自报。
     *
     * ## 截断是分两层的
     * 存档时单条最多 `MAX_BYTES`（64KB），查询时再按 `$maxBytes` 截一次——
     * 后者是协议护栏，防止"一条 64KB × 50 条"撑爆单条消息。两层都报截断，
     * 读者知道看到的是片段而不是全部。
     *
     * @return array<string, mixed>
     */
    public function networkBody(int $request, string $part = 'both', int $maxBytes = 8 * 1024): array
    {
        $window = $this->recorder->bodyCoverage();
        $part = \in_array($part, ['both', 'request', 'response'], true) ? $part : 'both';
        $maxBytes = \max(0, $maxBytes);
        if (($window['captured'] ?? false) !== true) {
            // 未开启报文预算时，空窗口不是"抓了但为空"：读者必须能分辨这两种事实。
            return [
                'request' => $request,
                'found' => false,
                'miss' => 'bodies-not-captured',
                'window' => $window,
            ];
        }
        $record = $this->recorder->bodyAt($request);
        if ($record === null) {
            $skeleton = $this->recorder->networkAt($request);

            return [
                'request' => $request,
                'found' => false,
                // 骨架在 ⇒ 报文只是没存/被裁（`body-not-retained`）；骨架不在 ⇒
                // 连请求本身都查不到，这时才用**网络**窗口判"窗口外还是没这条"。
                // 用报文窗口判会错：报文窗口(200)比网络窗口(500)小，网络里还在的
                // 请求会被说成"窗口外"。
                'miss' => $skeleton === null
                    ? $this->missReason($request, $this->recorder->networkCoverage())
                    : 'body-not-retained',
                'window' => $window,
            ];
        }

        return ['found' => true] + $record->select($part, $maxBytes) + ['window' => $window];
    }

    /**
     * 异步任务时间线：任务 → 派发 → 结算 → 落地节点，与帧因果共用同一条时间轴。
     *
     * ## 这里记录的是"后端调度过的工作"
     *
     * `AsyncScope::sleep()` 与裸 `Future` 是等待点，不是 job。把它们列进来会让
     * 队列深度失去意义 —— 排队等 worker 与 await 一个定时器根本不是一回事。
     *
     * @return array{rows: list<array<string, mixed>>, window: array<string, mixed>, total: int, frame: int, captured: bool}
     */
    public function async(?int $job = null, int $limit = 50): array
    {
        $window = $this->recorder->asyncCoverage();
        $captured = ($window['captured'] ?? false) === true;
        // 挂起任务的 pendingFrames 要有一个"问的这一刻"，不能拿 0 当现在。
        $frame = $this->recorder->frameId() > 0 ? $this->recorder->frameId() : $this->recorder->lastFrameId();

        if ($job !== null) {
            $record = $this->recorder->asyncAt($job);
            if ($record === null) {
                return [
                    'rows' => [],
                    'window' => $window,
                    'total' => 0,
                    'frame' => $frame,
                    'captured' => $captured,
                    'miss' => $this->asyncMissReason($job, $window),
                ];
            }

            return [
                'rows' => [$record->select($frame)],
                'window' => $window,
                'total' => 1,
                'frame' => $frame,
                'captured' => $captured,
            ];
        }

        $rows = \array_map(
            static fn(AsyncRecord $r): array => $r->select($frame),
            $this->recorder->asyncRows(),
        );
        $total = \count($rows);

        return [
            // 取最新的 N 条：看异步面板的人要先看到刚提交的工作。
            'rows' => $limit > 0 ? \array_slice($rows, -$limit) : $rows,
            'window' => $window,
            'total' => $total,
            'frame' => $frame,
            'captured' => $captured,
        ];
    }

    /**
     * 请求查不到时是"窗口外"还是"根本没有这条"。
     *
     * @param array<string, mixed> $window 需含 `requests` 与 `from`
     */
    public function missReason(int $request, array $window): string
    {
        return (int) ($window['requests'] ?? 0) === 0 || $request < (int) ($window['from'] ?? 0)
            ? 'outside-network-window'
            : 'unknown-request';
    }

    /** @param array<string, mixed> $window */
    public function asyncMissReason(int $job, array $window): string
    {
        if (($window['captured'] ?? false) !== true) {
            return 'async-not-captured';
        }

        return (int) ($window['jobs'] ?? 0) === 0 || $job < (int) ($window['from'] ?? 0)
            ? 'outside-async-window'
            : 'unknown-job';
    }
}
