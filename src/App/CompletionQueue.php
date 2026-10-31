<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * **已完成、还没交付给 UI 线程的结果队列**（2026-10-31）—— `drain()` 的预算算法只此一份。
 *
 * ## 为什么它是一个类（不是三个后端里各写一遍）
 *
 * `TaskHost::drain()` 的契约里有**三条规则**（§17.4.2 的 ①②③）。改造前它们被
 * **抄在三个后端里**：`SyncTaskHost::$queue`、`ProcessTaskHost::$failed`、
 * `ThreadTaskHost::$failed` —— 三段几乎逐字相同的 `foreach + hrtime + array_merge`。
 * 差别只有一处：超预算之后 Sync 写 `return`、另外两个写 `break`（它们后面还有
 * 别的段要跑）。这类"同一句话抄三遍、每遍漏一点"的东西，正是本仓要消灭的形状：
 * 三者中只有 `ThreadTaskHost::deliverRows()` 用了 `finally` 保住剩余的行，
 * 另外两处一旦 `$onEach` 抛异常，**剩下的几条 Completion 就永久消失**
 * （那几条 `Future` 永不结算 ⇒ 页面永远转圈，且没有任何日志）。
 *
 * ⇒ 规则收进这里，三个后端只回答"结果从哪来"。契约因此是**结构**，不是三份注释。
 *
 * ## 三条规则（对应 `TaskHost::drain()` 的 ①②③）
 *
 * | # | 规则 | 写错的表现 |
 * |---|---|---|
 * | ① | 队列非空时**至少交一条** | 一条 80ms 的回调 ⇒ 每帧进去、发现超预算、一条没交 ⇒ **活锁** |
 * | ② | 剩余**放回队头**，且仍被 `count()` 数到 | 放队尾 ⇒ 结果乱序（分页显示上一页的数据）；不放回也不计数 ⇒ 帧时钟判"没活了"就睡死 |
 * | ③ | 本类**只管交付**，不管簿记 | `flush()` / `reap()` / `sweepTimeouts()` 必须由后端无条件跑 —— 预算限的是"交多少条"，不是"干多少活" |
 *
 * ★ ③ 不是本类能保证的（它不知道后端还有什么活），写在这里是因为它和 ①② 是
 *   同一段代码的邻居：`drain()` 里"这一段受预算约束"的唯一落点就是这里。
 *
 * @internal 只有三个 `TaskHost` 后端用；不给应用侧直接用（应用拿结果靠 `Future`）
 */
final class CompletionQueue
{
    /**
     * @var list<Completion>
     *
     * ★ `list` 而不是 `array`：顺序是契约的一部分（先进先交）。
     */
    private array $items = [];

    public function push(Completion $completion): void
    {
        $this->items[] = $completion;
    }

    /** 还有多少条**已就绪、未交付**（`TaskHost::pending()` 的一部分） */
    public function count(): int
    {
        return \count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * 逐条交给 `$onEach`，直到钱花完。
     *
     * @param callable(Completion): void $onEach 交付一条（在 UI 线程执行）
     * @param int $deadline `hrtime(true)` 纳秒截止点；`0` = 不限（一次交空）
     *
     * @return bool 是否**全部交完**（`false` = 超预算，剩余已放回队头）
     */
    public function drain(callable $onEach, int $deadline): bool
    {
        if ($this->items === []) {
            return true;
        }

        // ★ swap：取快照再清空 —— `$onEach` 里再 `push()` 的留在队列里（下一帧交），
        //   否则"回调里提交新任务"会让 drain 永不返回，帧预算失效。
        //   与 `SyncTaskHost::drain()` / `PumpHost::pumpSliced()` 同一手法。
        $ready = $this->items;
        $this->items = [];

        $done = 0;

        try {
            foreach ($ready as $i => $completion) {
                // ★ 规则①：`$i > 0` ⇒ 队列非空时至少放行一条（否则活锁）。
                //   诚实边界：`$deadline` 是**调用方**在开头算的 ⇒ 首条检查时它恒在
                //   未来，这条分支在单队列场景**不可证**（`DrainBudgetTest` 抓不到它
                //   的变异）。它保护的是"跨段"场景（`ThreadTaskHost` 里积压段已经
                //   把预算烧光，新批次开场即过期）—— 没有它就是"这一批一条都不交"。
                if ($deadline > 0 && $i > 0 && \hrtime(true) >= $deadline) {
                    return false;
                }

                // ★ **先记账再交付**：`$onEach` 一旦被调用就算"交出去了"（它的第一件事
                //   通常是 `Future::resolve()`/`reject()`），此后抛异常也不能把这一条
                //   放回队列重交 —— 那会变成"同一条结算两次"（第二次是空操作，但顺序
                //   会乱：重交的那条插到后面几条前面）。⇒ 剩余从**下一条**开始。
                $done = $i + 1;

                $onEach($completion);
            }
        } finally {
            // ★ 规则②：剩余放回**队头**。它们比"`$onEach` 里新 push 的"更早完成，
            //   顺序反了就是"翻到第 2 页却看到第 1 页的数据"。
            // ★ `finally` 而不是"只走正常路径才放回"：`$onEach` 抛异常时剩下的
            //   **不能丢** —— 丢了那几条 `Future` 永不结算。`ThreadTaskHost`
            //   为同一件事单独写过一次 `finally`，那正是"抄三遍、漏两遍"的现场。
            if ($done < \count($ready)) {
                $this->items = \array_merge(\array_slice($ready, $done), $this->items);
            }
        }

        return true;
    }
}
