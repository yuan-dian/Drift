<?php
declare(strict_types=1);

namespace App\Showcase\Models;

use Drift\Async\Future;

/**
 * 一次"并发取数"的结果 —— 以及怎么把它产出来。
 *
 * ★ 存在的理由：编排（逐条计时 + `allSettled` + 汇总）与解码（六处
 *   `is_array($data['entries'])`）原先都住在页面 `initState` 与 `cards()` 里，
 *   于是"逐条跨页"这种判据只能挂树跑帧才验得到。搬到这里之后它能被直接断言。
 *
 * ★ **一条失败不影响其余**：用 `allSettled()` 而不是 `all()`。`all()` 的语义是
 *   "缺一条结论就不成立"，遇到失败还会**取消**剩下几条 —— 拼看板时那是灾难。
 *
 * ★ 每条**单独计时**：串行与并发的差别只有把"总耗时"与"逐条之和"摆在一起才
 *   看得出来。并发度其实是组合根（池容量）的事，页面只表达"这些互不依赖"。
 */
final readonly class ConcurrentResult
{
    /**
     * @param array<int, ConcurrentEntry> $entries 页码 ⇒ 那一页的结果
     */
    private function __construct(
        private array $entries,
        private float $totalMs,
    ) {
    }

    /**
     * 发起 N 条并发取数 —— 每条单独计时，一条失败不影响其余。
     *
     * @param \Closure(int): Future $fetch
     * @param list<int> $pages
     */
    public static function gather(\Closure $fetch, array $pages): Future
    {
        $spent = [];
        $futures = [];

        foreach ($pages as $page) {
            $startedAt = \hrtime(true);

            $futures[$page] = $fetch($page)->then(
                static function (mixed $value) use (&$spent, $page, $startedAt): mixed {
                    $spent[$page] = (\hrtime(true) - $startedAt) / 1e6;

                    return $value;
                }
            );
        }

        $startedAll = \hrtime(true);

        return Future::allSettled($futures)->then(
            static function (array $entries) use (&$spent, $startedAll): array {
                return [
                    'entries' => $entries,
                    'spent' => $spent,
                    'totalMs' => (\hrtime(true) - $startedAll) / 1e6,
                ];
            }
        );
    }

    /**
     * 唯一的解码口 —— `entries` 与 `spent` 在这里合成"每页一条结果"。
     *
     * ★ **幂等**：取数结果在落地时就解码成 `ConcurrentResult`，所以再喂回来
     *   必须原样返回（同 `PagedRows::from()` 的理由）。
     * ★ 失败的那条**没有耗时**（`then` 没跑到）⇒ 让 `spentMs()` 回 `null`，
     *   页面画「—」。给 0 会让"逐条之和"看起来像真的。
     */
    public static function from(mixed $payload): self
    {
        if ($payload instanceof self) {
            return $payload;
        }

        $data = \is_array($payload) ? $payload : [];
        $rawEntries = \is_array($data['entries'] ?? null) ? $data['entries'] : [];
        $spent = \is_array($data['spent'] ?? null) ? $data['spent'] : [];

        $entries = [];
        foreach ($rawEntries as $page => $raw) {
            $ms = $spent[$page] ?? null;
            $entries[(int) $page] = ConcurrentEntry::of(
                $raw,
                \is_float($ms) || \is_int($ms) ? (float) $ms : null,
            );
        }

        return new self($entries, (float) ($data['totalMs'] ?? 0.0));
    }

    /** 该页的结果；`null` = 没有这一页（请求里没发它） */
    public function entryOf(int $page): ?ConcurrentEntry
    {
        return $this->entries[$page] ?? null;
    }

    /** 总墙钟（从发出到最后一条落地） */
    public function totalMs(): float
    {
        return $this->totalMs;
    }

    /** 逐条耗时之和 —— 与 `totalMs()` 相等即"其实串行" */
    public function sumMs(): float
    {
        $sum = 0.0;
        foreach ($this->entries as $entry) {
            $sum += $entry->spentMs() ?? 0.0;
        }

        return $sum;
    }

    /** 拿到耗时的条数（失败那些没有耗时，不算进来） */
    public function timedCount(): int
    {
        $count = 0;
        foreach ($this->entries as $entry) {
            if ($entry->spentMs() !== null) {
                $count++;
            }
        }

        return $count;
    }
}
