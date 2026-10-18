<?php
declare(strict_types=1);

namespace Drift\App;

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
     * 提交一个后台任务。
     *
     * ★ **`$onDone` / `$onError` 一定在 UI 线程的帧内被调用**，且**不在**
     *   `submit()` 的调用栈上（P0 的语义变更，§17.4.2）—— 所以回调里可以安全地
     *   `$this->rows = …` + `notifyChanged()`：那一段发生在 `settle()` 之前，
     *   **同帧生效**。
     *
     * ★ `$onDone` 是闭包、`$job` 是类 —— 这不是双标（§O-03）：闭包**不跨边界**
     *   （它在回贴之后才被调），跨边界的那一半必须是类。
     *
     * @param callable(mixed): void $onDone 成功回调（收 `Job::run()` 的返回值）
     * @param null|callable(\Throwable): void $onError 失败回调（收 `Job::run()`
     *   抛出的异常）。`null` ⇒ 框架走 `Errors::report()` 的 Fatal 序列
     *   （默认最响的一档：任务失败被吞掉就是"界面永远转圈"，G2 禁止）
     *
     * @api
     */
    public function submit(Job $job, callable $onDone, ?callable $onError = null): void;

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
     * 把**已完成**的结果逐个交给 `$onEach`（仅 UI 线程，`Application::frame()` 每帧调一次）。
     *
     * ★ **swap 语义**：取走的是调用瞬间的快照；`drain()` 期间新入队的留在队列里，
     *   下一帧处理。⇒ 回调里再 `submit()` 不会让本帧无限循环 —— "一帧的工作量有界"
     *   是帧预算（§17.4.3）的前提。
     *
     * @param callable(Completion): void $onEach 处理单个完成事件（在 UI 线程执行）
     *
     * @api
     */
    public function drain(callable $onEach): void;
}
