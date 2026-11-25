<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 一次会话的**身份与状态**：令牌、当前状态、状态的原因。
 *
 * ## 为什么三个字段在一个对象里
 *
 * 它们总是一起被读（观察者一次 `state` 查询要拿全）、一起被改（推进到 `finished`
 * 只改后两个）。拆成三个字段挂在会话上，意味着"读的时候它们可能不同步"——而
 * `state` 与 `reason` 不同步正是最难查的那种错：「暂停了但不知道为什么」。
 *
 * ## 为什么不是 `readonly`
 *
 * 状态本来就会变。做成不可变就得每次 `with()` 出新对象，而持有旧引用的地方
 * （`QueryRouter` 的闭包）会看到上一份状态——那比可变更危险。
 *
 * ## `token` 为什么必须有
 *
 * 帧号与节点号都是**每会话重发**的。观察者若只记 `frame=590`，宿主重启后会拿
 * 新会话的第 590 帧——同名不同物。令牌是唯一能判「时间线换了」的东西，所以
 * 它跟着状态一起走，而不是单独一个字段让调用方记得去比。
 *
 * 离线会话（`QueryRouter::offline()`）的令牌是空串：它不是"某个进程里的会话"，
 * 而是一份已经结束的记录的读视图，拿它跟活会话比只会误导。
 *
 * @api
 */
final class SessionState
{
    /** `running` / `paused` / `finished` / `offline`。`crashed` 留给常驻黑匣子（S2）。 */
    public string $state = 'running';

    /** 为什么停：`at`（跑到了 `--at`）/ `requested`（有人在客户端按了暂停）/ null。 */
    public ?string $reason = null;

    public function __construct(public readonly string $token)
    {
    }

    /**
     * 一次驱动结束时的状态，只有这一个规则。
     *
     * 参数是调用方知道的事实（「我因为 `--at=N` 停了」），不是它猜的状态名——
     * 让它直接写 `state` 等于把 `crashed` 也开放给不配判的人。
     *
     * @return array{state: string, reason: ?string}
     */
    public static function afterDrive(?int $at): array
    {
        return $at === null
            ? ['state' => 'finished', 'reason' => null]
            : ['state' => 'paused', 'reason' => 'at'];
    }

    /** @return array{session: string, state: string, reason: ?string} */
    public function toArray(): array
    {
        return ['session' => $this->token, 'state' => $this->state, 'reason' => $this->reason];
    }
}
