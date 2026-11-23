<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Async\Future;

/**
 * 任务提交策略：把"在哪跑"从 `null` 的位置语义变成调用点看得见的值。
 *
 * 三种策略互斥：`Sync` 在当前栈跑任务本体，`Reject` 明确不跑，`Background` 交给端口。
 * 同步抛错在这里落成 rejected，异常不会射进事件循环。
 *
 * @api
 */
final readonly class TaskDispatch
{
    private const string SYNC = 'sync';
    private const string REJECT = 'reject';
    private const string BACKGROUND = 'background';

    private function __construct(
        private string $mode,
        private ?TaskHost $host = null,
    ) {
    }

    /** 在当前栈跑 `Job::run()`（解释器 / 单测 / 未声明后台端口的应用）。 */
    public static function sync(): self
    {
        return new self(self::SYNC);
    }

    /** 明确拒绝提交；任务本体不会被调用。 */
    public static function reject(): self
    {
        return new self(self::REJECT);
    }

    /** 交给后台端口提交，结果按 `TaskHost` 契约回贴。 */
    public static function background(TaskHost $host): self
    {
        return new self(self::BACKGROUND, $host);
    }

    /** 唯一提交口：三条策略都在这里结算，`null` 不再参与语义判断。 */
    public function submit(Job $job): Future
    {
        if ($this->mode === self::BACKGROUND) {
            return $this->host->submit($job);
        }
        if ($this->mode === self::REJECT) {
            return Future::rejected(new \RuntimeException(
                '本应用没有后台任务端口（AppScope::setTaskHost(null)）：'
                . \get_class($job) . ' 无处可跑'
            ));
        }

        try {
            return Future::resolved($job->run());
        } catch (\Throwable $error) {
            return Future::rejected($error);
        }
    }

    public function host(): ?TaskHost
    {
        return $this->host;
    }

    public function isSync(): bool
    {
        return $this->mode === self::SYNC;
    }

    public function isReject(): bool
    {
        return $this->mode === self::REJECT;
    }

    public function isBackground(): bool
    {
        return $this->mode === self::BACKGROUND;
    }
}
