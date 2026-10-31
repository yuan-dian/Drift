<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Async\Future;

/**
 * 后台执行端口（§17.2）—— **把"别占 UI 线程"收进一个可替换的端口**。
 *
 * ★ **它是平台能力，不是 UI 能力**（§11.2）：端口的存在让"UI 代码零改动"成为
 *   结构事实 —— Store / 页面只调 `$src->fetchPage()`，换后端是**组合根一行**
 *   （§17.2.3 / §A.2.4）。
 *
 * ```
 *         ┌──────────────── UI 线程（唯一）────────────────┐
 *         │  frame() → drain() → settle → 布局 → 绘制       │
 *         │        ▲                              │        │
 *         │        │ ① 后端把 Completion 入队      │ submit │
 *         │        │ ② 每帧 drain 取走并回调       │        │
 *         └────────┼──────────────────────────────┼────────┘
 *                  │                              ▼
 *         ┌────────┴──────────── TaskHost（平台端口）────────┴────────┐
 *         │  后端 A：线程池 —— ⛔ 当前 tpc 下不可实现（§17.2.2）        │
 *         │  后端 B：同步三态（`SyncTaskHost`，M1–M3 默认，永远可用）    │
 *         └───────────────────────────────────────────────────────────┘
 * ```
 *
 * ★★ **两条不变量**（§17.4.1，2026-10-18 起）：
 *   ① **后端不许直接调 `$onDone`** —— 一律把 `Completion` 入队，由 `drain()` 取出；
 *   ② **`drain()` 只在 UI 线程的帧内被调用**（`Application::frame()` 的第一段）。
 *   这两条把"回调在哪个线程的哪一帧跑"变成结构事实 —— 成熟框架全这么做
 *   （Qt queued connection / WPF Dispatcher / Godot MessageQueue / Dart Port，
 *   见 §17.2.7），**没有一个直接调回调**。
 *
 * ★ **为什么这不是"多加一层"**：`submit(Job, onDone)` 只说了"结果怎么回来"，
 *   没说"在**哪个线程的哪一帧**回来"。同步后端让这个缺口看不出来（队列长度恒为 1），
 *   但任何异步后端都必须先有它 —— 否则 `$onDone` 会在 worker 线程上改 Store
 *   （§17.2 第 ① 处的静默数据竞争）。
 *
 * ★ **后端 A 为什么不可用**（2026-10-16 探针 K，`probes/RESULTS.md` §11）：AOT 生成的
 *   代码里类 / 对象 / 属性访问走 `php_request_cache`，而它**只在模块 RINIT 里被建**
 *   ⇒ 没跑过 RINIT 的 worker 线程上全是空指针解引用，**单个 worker 也 SIGSEGV**。
 *   ★ 2026-10-18 探针 L 追测：lib 模式已导出 `library_request_startup`（直调 RINIT），
 *   但裸线程 / Swoole 线程调它仍 SIGSEGV —— 缺的是 `ts_resource` + EG 初始化
 *   （§17.2.5）。⇒ 后端 A 仍挂起，端口契约**不变**，上游补上即可接入。
 *
 * @api
 */
interface TaskHost
{
    /**
     * 提交一个后台任务，拿到它的 `Future`。
     *
     * ```php
     * $host->submit(new FetchUsersJob($page))
     *     ->then(function (array $raw): void { $this->applyPage(true, $raw, ''); })
     *     ->catch(function (\Throwable $e): void { $this->applyPage(false, [], $e->getMessage()); });
     * ```
     *
     * ★★ **2026-10-30（N3）改形**：原来是 `submit(Job, onDone, onError)`。
     *   回调没有消失，只是搬到了 `Future` 上 —— 而 `Future` 能 `all` / `race`，
     *   回调不能。**两套通道 = 两条时序**，这是必须只留一套的理由。
     *
     * ★ **`then()` / `catch()` 一定在 UI 线程的帧内被调用**，且**不在**
     *   `submit()` 的调用栈上（P0 的语义变更，§17.4.2）—— 所以回调里可以安全地
     *   `$this->rows = …` + `notifyChanged()`：那一段发生在 `settle()` 之前，
     *   **同帧生效**。
     *
     * ★★ **但 `Job::run()` 可能在 `submit()` 的调用栈上跑完**（2026-10-31 复审补：
     *   契约一直漏了这半句）。`SyncTaskHost` 就是如此 —— 它是默认后端（"零配置
     *   也能跑"），而它在 `submit()` 里当场 `run()`。⇒ **`submit()` 的耗时取决于
     *   后端**：同步后端 = 任务的完整耗时（慢网络 ⇒ 这一帧被卡住），进程池 = 一次
     *   `proc_open` + 写管道（亚毫秒），C++ 池 = 入队。
     *   ★ 缓解设施已有：`SyncTaskHost` 超过 50ms 会报一条 `DIAGNOSTIC` 并指路
     *   （`setTaskHost(new ProcessTaskHost(...))`）。但那是**事后报警**，不是
     *   "事前不卡" —— 要让 `submit()` 真的快，就得换后端。
     *
     * ★ `then()` 是闭包、`$job` 是类 —— 这不是双标（§O-03）：闭包**不跨边界**
     *   （它在回贴之后才被调），跨边界的那一半必须是类。
     *
     * ★ **结算前必须挂上 `catch()`**：结算那一刻没有 `catch()` ⇒ 框架走
     *   `Errors::report()` 的 Fatal 序列（任务失败被吞掉就是"界面永远转圈"，
     *   G2 禁止）。提交与挂回调在同一个同步块里，天然满足。
     *
     * @api
     */
    public function submit(Job $job): Future;

    /**
     * 还有多少条任务**没回贴**（在途 + 排队）。
     *
     * ★ **帧时钟的判据**（§17.4.9）：平台的消息泵是阻塞的（`GetMessageW`），没有输入
     *   事件时它会一直睡 ⇒ 异步结果只能等下一条用户消息捎带（现象："任务 1.2s 就完了、
     *   界面 3s 后才变"）。框架只有能回答"还有没有活"，平台才知道要不要起定时器。
     * ★ 返回 0 ⇒ 平台可以**无限期阻塞**（不空转）；>0 ⇒ 帧时钟按 15ms 跳。
     *   这就是"自适应"的全部机制：**有活才跳，没活就睡**。
     *
     * @api
     */
    public function pending(): int;

    /**
     * 后端**是否必须靠每帧轮询**才能拿到结果。
     *
     * ★★ 这是"能不能真的睡着"的唯一开关。帧时钟的回答只有两种：
     *   `needsPolling() === true` ⇒ 有活在途就按帧节奏醒（15ms 一次，**空转**）；
     *   `needsPolling() === false` ⇒ 有活在途也**无限期阻塞**，等后端自己叫醒。
     *   ⇒ 后者才是"零轮询"，而它成立的前提是后端**真的能叫醒**（A2 的
     *   `PostMessageW` ⇒ `WakeReason::Woken`）。说 `false` 却叫不醒 = 挂死。
     *
     * ★ 为什么放在端口上而不是让 `PumpHost` 判具体类：`PumpHost` 只认 `TaskHost`
     *   （`AppInstanceofConcrete` 那条纪律），而且"我能不能叫醒你"本来就是
     *   后端的**能力声明**，不是调用方的猜测。
     */
    public function needsPolling(): bool;

    /**
     * 把**已完成**的结果逐个交给 `$onEach`（仅 UI 线程，`Application::frame()` 每帧调一次）。
     *
     * ★ **swap 语义**：取走的是调用瞬间的快照；`drain()` 期间新入队的留在队列里，
     *   下一帧处理。⇒ 回调里再 `submit()` 不会让本帧无限循环。
     *
     * ★★ **2026-10-30（P1）加预算**：`$budgetMs` = "这一帧最多花多少毫秒在**交付回调**上"，
     *   超了就收手，剩下的**留在后端**，下一帧继续交。形态是**契约下推**：
     *   泵（`PumpHost`）只给数字不记账，计时与"干到哪为止"由后端自己决定。
     *   ⇒ 帧预算只有一个范式，没有第二套计时器。
     *
     * ★ **为什么必须有界**（`docs/UI-THREAD-MODEL-2026-10-30.md` §4.1）：`Future::all()`
     *   聚合 200 个请求、批量上传 300 个文件，一次回来几百条；每条 `then` 回调里若有
     *   解析 / 算 diff 的活，一帧就是几十毫秒。症状是**"偶发卡顿"且极难归因** ——
     *   掉帧的那一帧和"提交点"隔了几百条回调。改造前唯一防线是「swap 防回调里再
     *   `submit()` 的自我循环」，它防不住这一类。
     *   现在"一帧的工作量"是**结构保证**，不是"希望回调都很快"。
     *
     * ★★ **三条实现契约**（三个后端都要遵守，破一条的症状各不相同）：
     *   ① **队列非空时至少交一条** —— 否则一条 80ms 的回调会让队列永远推进不了
     *      （每帧进去、发现超预算、一条没交、返回 ⇒ 活锁）；
     *   ② **剩余必须算进 `pending()`** —— 否则帧时钟判"没活了"就睡死
     *      （`needsPolling() === false` 的后端尤其致命：`ThreadTaskHost` 会
     *      无限期阻塞等唤醒，而唤醒永远不会来）；
     *   ③ **簿记不受预算影响** —— `ProcessTaskHost` 的 `flush()`（把排队任务发给
     *      worker）与 `reap()`（回收死 worker）必须照常跑。跳过 `flush` ⇒ 任务永不
     *      发出（永挂）；跳过 `reap` ⇒ 死 worker 名下的任务永不结算。
     *      **预算限的是"交多少条"，不是"干多少活"。**
     *
     * ★ ①② 的**实现只有一份**：{@see CompletionQueue::drain()}（2026-10-31）。
     *   改造前三个后端各抄一遍，抄出了 `return` / `break` 两种收尾、以及"回调
     *   抛异常时剩余丢失"（只有 `ThreadTaskHost` 的行队列用了 `finally`）。
     *   ③ 无法收进队列（它不知道后端还有哪些活），所以仍写在三个 `drain()` 里。
     *
     * @param callable(Completion): void $onEach 处理单个完成事件（在 UI 线程执行）
     * @param int|null $budgetMs 本帧交付回调的时间上限（毫秒）；
     *                           `null` = 不限，一次取空（**框架路径永远传正数**，
     *                           `null` 只服务于测试与"结算前最后冲刷"）
     *
     * @api
     */
    public function drain(callable $onEach, ?int $budgetMs = null): void;
}
