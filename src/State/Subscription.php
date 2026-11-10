<?php
declare(strict_types=1);

namespace Drift\State;

/**
 * 一次订阅的句柄（§11.3 RT-1）。
 *
 * 退订有两条路，**互不替代**：
 * ① `Subscription::cancel()` —— 退这一条；
 * ② `Subject::unsubscribeAllOf($owner)` —— 退这个属主的全部。
 *
 * ★ `State` 走的是第三条、也是**框架侧**的那条：`State::watch()` 登记进清理袋，
 *   `StatefulElement::unmount()` 统一 `dropDisposers()`。之所以不让用户
 *   "在 `dispose()` 里自己退"，是因为 `dispose()` 是**可覆写**的 —— 忘了
 *   `parent::dispose()` 恰恰是 A4 列的失败模式，把兜底放在用户侧就是
 *   "文档承诺了、机制守不住"（A16）。
 *
 * @api
 */
final class Subscription
{
    private ?Subject $subject = null;
    private int $id = 0;

    private function __construct()
    {
    }

    /** @internal 只有 `Subject::subscribeFor()` 造得出真句柄 */
    public static function of(Subject $subject, int $id): self
    {
        $subscription = new self();
        $subscription->subject = $subject;
        $subscription->id = $id;

        return $subscription;
    }

    /** 退订。幂等 —— 退第二次什么都不做（不报错：回收路径会重复调） */
    public function cancel(): void
    {
        $subject = $this->subject;
        if ($subject === null) {
            return;
        }

        $this->subject = null; // 先摘自己，防重入
        $subject->unsubscribeId($this->id);
    }

    /** 还在订阅中吗（冒烟与测试用；业务代码一般不需要） */
    public function isActive(): bool
    {
        return $this->subject !== null;
    }
}
