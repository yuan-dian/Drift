<?php
declare(strict_types=1);

namespace App\Showcase\Stores;

use Drift\State\Effects;
use Drift\State\Machine;
use Drift\State\Seq;

/**
 * 远程搜索的 attempt 仲裁：只让最后一次查询的结果落地。
 *
 * ★ 为什么单独是一个 Store 而不是 ComboBoxPage 的字段：仲裁是**领域规则**
 *   （"过期响应不许覆盖新的"），与画哪一页无关。放进页面就得靠跑帧才能测，
 *   拿出来才能脱离窗口单测（见 `RemoteSearchStoreTest`）。
 *
 * ★ 状态是 `int` attempt（0 = 没有在途）——不是 enum with payload / 子类：
 *   tpc 对 `switch` 出 C2440，子类是去虚化缺陷面；这里只用 `===` 链。
 *   "没有在途"用 0 而非 nullable，避免 AOT 下 `?Attempt` 退化 `php::Var`。
 */
final class RemoteSearchStore
{
    /** 没有在途查询 —— 到达事件在这个状态下一律丢弃 */
    private const int IDLE = 0;

    private Machine $machine;

    public function __construct()
    {
        $this->machine = new Machine(self::IDLE, static function (mixed $attempt, mixed $event, Seq $seq): Effects {
            $e = $event;
            if ($e->kind === RemoteSearchEvent::QUERY) {
                // 每次查询领一个新号 ⇒ 之前的在途答案天然过期。
                return new Effects($seq->next());
            }
            // ★ attempt fence：只有当前在途的那一次才能落地，落地即回 Idle 并发命令。
            //   比对只在这一处；调用方从"有没有命令"判断是否落地，不再自己比对一遍。
            if ($e->kind === RemoteSearchEvent::ARRIVE
                && $attempt !== self::IDLE
                && $e->attempt === $attempt) {
                return new Effects(self::IDLE, ['land']);
            }

            return new Effects($attempt);
        });
    }

    /**
     * 发起一次查询，返回它的 attempt 号。
     *
     * ★ 调用方必须把这个号带回 `arrive()` —— 号是"这是哪一次查询的答案"的
     *   唯一凭据。页面排定时器时把号捕获进闭包即可。
     */
    public function query(): int
    {
        $this->machine->dispatch(RemoteSearchEvent::query());

        return $this->machine->state();
    }

    /**
     * 一次响应到达，携带发起时的 attempt。
     *
     * @return bool 该不该把结果画上去（`false` = 过期 / 已落地，调用方必须丢弃）
     */
    public function arrive(int $attempt): bool
    {
        $fx = $this->machine->dispatch(RemoteSearchEvent::arrive($attempt));

        return $fx->commands !== [];
    }
}
