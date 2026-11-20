<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use App\Showcase\Pages\Base\StatefulShowcasePage;

use App\Showcase\ShowcaseCapabilities;
use Drift\Async\Future;
use Drift\Widget\State;
use App\Showcase\Pages\States\ConcurrentFetchPageState;

/**
 * 并发取数—— **`Future::allSettled()` 的消费者**。
 *
 * ## 它回答的那个问题
 *
 * 「同时等 N 个接口」用 `maxWorkers = 3` + `Future::allSettled([...])`。
 * 不走单 worker `curl_multi`：本运行时 `curl_multi_init()` 一句就 `exit 139`
 * （`ASYNC-ARCH-2026-10-31.md` §14.1）。不走 5 worker：多两份运行时开销，
 * 结果拼接也没有现成先例；本页只证明并发聚合。
 *
 * ## 页面做的事（三行）
 *
 * ```php
 * $futures = ['1' => $fetch(1), '2' => $fetch(2), '3' => $fetch(3)];
 * return Future::allSettled($futures);      // ← 本页存在的理由
 * ```
 *
 * ★ **并发不在这里发生**：页面只是"一次发三条"，三条真的同时在跑是因为组合根
 *   把池开到了 3（`ProcessTaskHost(..., 3)`）。在默认池（`maxWorkers = 1`）上
 *   同一段代码会**串行**跑完 —— 总耗时是三条相加，而且**不报错**。
 *   ⇒ 这是本页最要紧的一条：**并发度是组合根的事，页面只表达"这些互不依赖"**。
 *   页脚那行把两个数都摆出来（总耗时 / 逐条之和），串行时它们相等，一眼能看出来。
 *
 * ★ **为什么用 `allSettled()` 而不是 `all()`**：拼看板最怕"一个接口挂了整页变错误页"。
 *   `all()` 的语义是"缺一条结论就不成立"（它还会**取消**剩下几条），适合多步依赖
 *   里的前一步；本页要的是"三块数据各自算数" ⇒ `allSettled()`。差别只有这一处，
 *   但选错时的症状是"另外两条白跑了、页面上什么也没有"，看起来像一次普通失败。
 *
 * ## 为什么是"三页数据"而不是三个不同接口
 *
 * mock 后端只有 `/api/rows` 一个取数接口，硬造三个接口只会让示例多出两份与
 * 主题无关的代码。三条任务**互不依赖**这件事，与它们打的是不是同一个 URL 无关 ——
 * 判据在测试里是"三条同时在途"，在界面上是"总耗时 ≈ 最慢那条"。
 *
 * ## 一个诚实的边界
 *
 * 池容量 3 ⇒ 最多三条真并行。**同时等 5 个接口**要么把池开到 5（5 份 AOT 运行时，
 * 每份几十 MB），要么让服务端一次答完（聚合接口 / BFF）。本仓不提供"一个 worker
 * 内并发等 5 条连接"的能力 —— 那条路（`curl_multi`）在本运行时上是死的，见上文。
 */
final class ConcurrentFetchPage extends StatefulShowcasePage
{
    public static function make(ShowcaseCapabilities $caps): self
    {
        return new self($caps);
    }

    public function createState(): State
    {
        return new ConcurrentFetchPageState($this->caps);
    }
}
