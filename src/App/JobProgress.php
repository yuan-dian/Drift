<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * 后台任务的**进度上报口**（2026-11-02）—— `Job::run()` 里调它。
 *
 * ```php
 * final class UploadImageJob implements Job
 * {
 *     public function run(): mixed
 *     {
 *         $body = MultipartBody::of(...);
 *         $res  = Http::request('POST', $url, [
 *             'multipart' => $body,
 *             'onProgress' => static function (float $f): void {
 *                 JobProgress::report($f);   // ← 只有这一行
 *             },
 *         ]);
 *
 *         return $res->json();
 *     }
 * }
 * ```
 *
 * ## 为什么是静态门面，而不是 `Job` 上的方法
 *
 * `Job` 是 **interface**（见它的类注释：凡是要跨边界的东西都是类）。给它加一个
 * `report()` 会让**每一个**任务都实现一个多数用不到的方法；加 `ReportsProgress`
 * 子接口 + `instanceof` 则要求 worker 认识两套形状，而 `instanceof` 漏 `use`
 * 恒 false 是本仓踩过的坑（`MEMORY`）。
 *
 * 静态门面把这件事变成**一次 bind**：
 *
 * | | 谁调 | 什么时候 |
 * |---|---|---|
 * | `bind($sink)` | `ProcessWorker`（**唯一**调用方） | 每个任务跑之前，`finally` 里置回 null |
 * | `report($f)` | 任务自己 | 任意时刻，多少次都行 |
 *
 * ★ **静态为什么在这里成立**：单个 worker 进程是**串行**的（`fgets` 一行、跑完、
 *   回一行 —— 它没有线程，见 `ProcessTaskHost` 的类注释）。同一时刻只有一个任务在
 *   跑 ⇒ 一个 sink 就够，不存在"两个任务抢同一个槽"。
 *   ★ 反过来说：**如果哪天 worker 变成多线程，这个门面就是第一个要改的地方**。
 *   记在这里，免得那天靠猜。
 *
 * ## 没有 sink 时是**空操作**，不报错
 *
 * 同步后端（`SyncTaskHost`）在 UI 线程上跑 `Job`，那时没有人需要进度 ——
 * 报错只会让"同一个 Job 换个后端就跑不了"。这与 `Errors::report()` 在无监听者时
 * 静默是同一条取舍。
 *
 * @api
 */
final class JobProgress
{
    /**
     * 进度上报的**最小粒度**（1%）。
     *
     * ★ 为什么必须节流：`Http` 的上传进度回调来自 curl 的
     *   `CURLOPT_XFERINFOFUNCTION`，它**每收到一块就调一次**（几十 KB 一块）——
     *   一个 100 MB 的文件能触发上千次。每次都要 `json_encode` + 写管道 +
     *   `fflush`，那是把 worker 的 CPU 花在"报告自己在干活"上。
     *
     * ★ 为什么 1%：进度条的可见精度就是这个量级（`Progress` 宽 300px ⇒ 1% = 3px）。
     *   更细的粒度不会让用户看到更多，只会让管道更忙。
     */
    public const MIN_STEP = 0.01;

    /** 当前 sink（`null` = 没人在听） */
    private static ?\Closure $sink = null;

    /** 上一次真正报出去的值（`-1.0` = 还没报过） */
    private static float $last = -1.0;

    /**
     * 装/卸 sink。**只有 `ProcessWorker` 该调它**。
     *
     * ★ 每次 `bind()` 都重置节流状态：新任务的第一条进度（哪怕值很小）必须能出去，
     *   否则"上一个任务报到 0.9、这个任务从 0.05 开始"会被节流吞掉。
     *
     * @param null|\Closure(float): void $sink
     *
     * @internal
     */
    public static function bind(?\Closure $sink): void
    {
        self::$sink = $sink;
        self::$last = -1.0;
    }

    /**
     * 上报进度（`0.0`~`1.0`，越界会被夹住）。
     *
     * ★ **`1.0` 永远不被节流吞掉**：任务跑到 100% 是这个方法最后一次被调用的典型
     *   形态，而它与上一次（比如 0.995）的差可能小于 `MIN_STEP`。吞掉它，界面上
     *   进度条就永远停在 99% —— 一个只在"最后一步很短"的任务上出现的 bug。
     *
     * @api
     */
    public static function report(float $fraction): void
    {
        $sink = self::$sink;
        if ($sink === null) {
            return; // 同步后端 / 单测：没人在听，静默
        }

        $f = $fraction;
        if ($f < 0.0) {
            $f = 0.0;
        } elseif ($f > 1.0) {
            $f = 1.0;
        }

        // ★ 倒退一律吞掉（进度条不能往回跳）。同值也吞（重复上报没有信息量）。
        if ($f <= self::$last) {
            return;
        }
        if ($f < 1.0 && $f - self::$last < self::MIN_STEP) {
            return;
        }

        self::$last = $f;
        $sink($f);
    }
}
