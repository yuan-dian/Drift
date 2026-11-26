<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 一条被 TaskHost 调度的工作的状态跃迁 —— 异步时间线的最小事实。
 *
 * 只记后端知道的标量：提交 / 派发 / 结算的帧号、终态、耗时、发起调用点。
 * 不记参数、URL、返回值：它们既可能泄密，也会让逐帧指纹随无关数据漂移。
 * `landed` 与网络骨架同款，记结算同帧被它写状态的节点 id。
 *
 * 等待点（`AsyncScope::sleep()` / 裸 `Future`）不是这里的一行：它们是“等”，
 * 不是被后端调度的工作；把两者混成一张表，队列深度就没法解释了。
 *
 * @api
 */
final readonly class AsyncRecord
{
    public const string QUEUED = 'queued';
    public const string RUNNING = 'running';
    public const string OK = 'ok';
    public const string FAILED = 'failed';
    public const string CANCELLED = 'cancelled';
    public const string TIMEOUT = 'timeout';

    /**
     * @param list<int> $landed 结算同帧被写状态的节点 id（去重、按发生序）
     */
    public function __construct(
        public int $id,
        public string $backend,
        public string $job,
        public int $worker,
        public int $submittedAtFrame,
        public int $startedAtFrame,
        public int $settledAtFrame,
        public string $state,
        public string $code,
        public float $elapsedMs,
        public string $initiator,
        public array $landed = [],
    ) {
    }

    /** 帧末回填落地节点；记录不可变，所以返回新对象。 */
    public function withLanded(array $landed): self
    {
        return new self(
            $this->id, $this->backend, $this->job, $this->worker,
            $this->submittedAtFrame, $this->startedAtFrame, $this->settledAtFrame,
            $this->state, $this->code, $this->elapsedMs, $this->initiator, $landed,
        );
    }

    /** 排队了多少帧（提交→派发）；同步后端和从未派发的任务都是 0。 */
    public function queueFrames(): int
    {
        if ($this->startedAtFrame <= 0) {
            return 0;
        }

        return \max(0, $this->startedAtFrame - $this->submittedAtFrame);
    }

    /** 到查询帧为止等了多少帧；已结算的任务以结算帧封口。 */
    public function pendingFrames(int $frame): int
    {
        $end = $this->settledAtFrame > 0 ? $this->settledAtFrame : $frame;

        return \max(0, $end - $this->submittedAtFrame);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->select(0);
    }

    /**
     * 带查询帧的投影。`pendingFrames` 依赖"问的这一刻"，而 AOT 的转换方法
     * `toArray()` 必须无参，所以派生量单独走这里（与 `NetworkBodyRecord::select()` 同款）。
     *
     * @return array<string, mixed>
     */
    public function select(int $frame): array
    {
        return [
            'id' => $this->id,
            'backend' => $this->backend,
            'job' => $this->job,
            'worker' => $this->worker,
            'submittedAtFrame' => $this->submittedAtFrame,
            'startedAtFrame' => $this->startedAtFrame,
            'settledAtFrame' => $this->settledAtFrame,
            'queueFrames' => $this->queueFrames(),
            'pendingFrames' => $this->pendingFrames($frame),
            'state' => $this->state,
            'code' => $this->code,
            'elapsedMs' => $this->elapsedMs,
            'initiator' => $this->initiator,
            'landed' => $this->landed,
        ];
    }

    /** 证据包形状：派生量不落盘，读时现算。 */
    public function encode(): array
    {
        return [
            'id' => $this->id,
            'backend' => $this->backend,
            'job' => $this->job,
            'worker' => $this->worker,
            'submittedAtFrame' => $this->submittedAtFrame,
            'startedAtFrame' => $this->startedAtFrame,
            'settledAtFrame' => $this->settledAtFrame,
            'state' => $this->state,
            'code' => $this->code,
            'elapsedMs' => $this->elapsedMs,
            'initiator' => $this->initiator,
            'landed' => $this->landed,
        ];
    }

    /** @param array<string, mixed> $raw */
    public static function decode(array $raw): self
    {
        return new self(
            (int) ($raw['id'] ?? 0),
            (string) ($raw['backend'] ?? ''),
            (string) ($raw['job'] ?? ''),
            (int) ($raw['worker'] ?? 0),
            (int) ($raw['submittedAtFrame'] ?? 0),
            (int) ($raw['startedAtFrame'] ?? 0),
            (int) ($raw['settledAtFrame'] ?? 0),
            (string) ($raw['state'] ?? ''),
            (string) ($raw['code'] ?? ''),
            (float) ($raw['elapsedMs'] ?? 0.0),
            (string) ($raw['initiator'] ?? ''),
            \array_map('\intval', \array_values((array) ($raw['landed'] ?? []))),
        );
    }
}
