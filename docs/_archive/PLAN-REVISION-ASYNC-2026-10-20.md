# 上游线程修复对方案的修订（2026-10-20）

> 依据：`D:\TypePHP\phpx` 提交 `13cf7175`（2026-10-20 12:18）·
> `D:\TypePHP\typephp` 提交 `aeda7e3d` + `.github/integration/swoole-thread/{RESULTS,README}.md` ·
> `AGENTS.md`（b7d03cd9）· 当前 dist `typephp-windows-x64-0.9.1` 实测。
> 状态：**方案修订稿**（未改代码）。

---

## 0. 结论先行

1. **上游阻塞已解除**：phpx `13cf7175` + typephp `aeda7e3d` 让 **Swoole thread 的 PHP 工作请求**在
   Linux / macOS / Windows 三平台、`control / ext / bin / bin-embedded` 四模式全部通过。
   `Swoole\Thread` 的 worker 里可以跑**编译出的类/闭包/异常/原生 GC 根/Queue/Map/Atomic**。
2. **design-v6 的两条 async 前提需要修订**：
   - 「整个进程只有一个线程持有 PHP 运行时」→ 改为「**主请求独占 UI 线程；工作线程各自独立请求，不共享 zval**」。
   - 「PHP 并行走子进程池（唯一已实测手段）」→ **不再是唯一**；`Swoole\Thread` 更便宜（无 123ms spawn），
     子进程池降级为「崩溃隔离 / NTS」。
3. **Drift2 的旧结论作废**：`probes/RESULTS.md` §11–12 的「Swoole\Thread 跑不到 tpc 类」已过时；
   可评估 `ThreadTaskHost`（ZTS only）。
4. **但 dist 不含修复**：`typephp-windows-x64-0.9.1/phpx` 的 mtime 是 2026-10-13，**早于修复**。
   要用线程必须从 `13cf7175` 构建 phpx，并让 `PHPX_HOME` 指向它（Composer 依赖 `dev-master`）。

---

## 1. 上游到底改了什么（事实）

### 1.1 phpx `13cf7175`（2026-10-20）

| 修复 | 内容 |
|---|---|
| **模块注册时机** | 旧：`php_embed_init()` 之后用 `MODULE_TEMPORARY` 注册 ⇒ 错过 Zend 的持久符号快照与请求处理器收集。新：在 embed SAPI 的 startup 里把生成模块交给 `php_module_startup()`，由 Zend 作为**持久模块**管理；主请求 RINIT 延后到标准流/嵌入资源就绪；工作请求走自己的 RINIT/RSHUTDOWN。 |
| **共享字面量引用计数** | 旧：`static php::Str _literal_strings[]` 跨线程共享，各线程复制/析构修改同一非原子 refcount ⇒ `IS_STR_PERSISTENT` 断言。新：persistent 字面量构造成**不可变、永久**字符串（预计算 hash、`IS_STR_INTERNED \| IS_STR_PERMANENT`），普通复制/销毁跳过 refcount。 |
| **macOS 跨 dylib 异常 RTTI** | 给 `_zend_object` 前置声明加 `type_visibility("default")`，libc++abi 才能跨 dylib 匹配 catch。 |
| **嵌入文件包装器状态** | 旧：进程级 `file_wrapper_installed` ⇒ 后续 worker 跳过安装。新：`THREAD_LOCAL`（仅 ZTS 展开为 `thread_local`，NTS 为普通变量）。`lexical_call_scope` 同步改用该宏。 |
| **关闭所有权** | `php_embed_shutdown()` 统一负责 RSHUTDOWN/MSHUTDOWN/符号移除/对象析构；恢复回调支持重复启动。 |

### 1.2 typephp `aeda7e3d` + `RESULTS.md`

| 平台 | PHP（ZTS） | Swoole | 纯 PHP | ext | bin | bin-embedded |
|---|---|---|---|---|---|---|
| Linux x86_64 | 8.4.14 Debug | 6.3.0RC1 | 通过 | 通过 | 通过 | 通过 |
| macOS arm64 | 8.4.20 | 6.3.0RC1 | 通过 | 通过 | 通过 | 通过 |
| Windows 11 x64 | 8.4.26 | 6.3.0RC1 | 通过 | 通过 | 通过 | 通过 |

覆盖：3 轮 × 3 并发 worker = **54 次快照**（一半在 Swoole 协程回调里）+ **9 次请求退出析构检查**；
编译全局/函数静态/原生根/调用缓存隔离；每 worker 独立声明的解释类/函数；内部类/方法调用、
动态调用、闭包、异常；共享 `Queue/Map/Atomic`；主请求状态不变；`bin-embedded` 删除磁盘文件后
worker 仍能读嵌入副本（每请求流包装器）。

### 1.3 其它相关提交

- `4af4f6a8`：恢复 bootstrap 启动 + CI 按 CPU 并发（**仅 CI**；用户机仍串行）。
- `b7d03cd9`：`AGENTS.md` 新增**构建资源安全**纪律（串行、`--job 1`、内存硬限制）。
- `89fe80aa`：lib 模式的消费者请求生命周期与虚分派（对 AOT lib 形态相关）。

### 1.4 明确的限制（必须写进方案）

- **不代表所有 Swoole API 或长时间压力测试已验证**（RESULTS 原话）。
- **NTS 只做了语法/预处理检查，不是线程测试** ⇒ 用 `Swoole\Thread` 必须 **ZTS**。
- Windows 是 **PHP SDK 8.4.24 头文件 + 8.4.26 运行**的跨补丁验证，不等于完全匹配。
- `bin` 入口必须用 `Thread::getInfo()['is_main_thread']` 判断主线程；
  **不能用 `getArguments()` 是否为空**（worker RINIT 早于 Swoole 填参数）。
- Swoole 必须经 **INI** 启用（`PHPRC` / `PHP_INI_SCAN_DIR`）；`php -d extension=swoole` 不会传给子进程。
- worker 之间只能过 **标量/数组/Queue/Map/Atomic**；`Node`/`Native`/`Closure` 不过界。
- **无崩溃隔离**：worker 段错误 ⇒ 整个进程死（与子进程池相反）。

---

## 2. design-v6 需要修订的条目

| 册 / 决策 | 原文 | 修订 |
|---|---|---|
| **08 §3.3 不变式** | 「整个进程只有一个线程持有 PHP 运行时」 | 「**主请求独占 UI 线程；工作线程各自独立请求，不共享 zval**」——Swoole 的 request 隔离是这条的机制基础 |
| **08 §3.3 表** | `PHP 任意代码（同进程多线程）→ ❌ 阻塞在上游` | → **✅ ZTS 下已实测**（三平台四模式；NTS 未测） |
| **11 D-16** | 「Fiber 全主线程 + 结构化取消 + Node 不进 Fiber」 | 默认不变；**新增 opt-in `Async::thread`**；`Node`/`Native` 仍不跨线程 |
| **16 §5.6** | 「通道（C++ 常驻库宿主）是移出主线程的主要手段」 | 保留；新增 **PHP 并行的 thread 档**（ZTS + Swoole） |
| **16 §5.8** | 「子进程池是『多份 PHP 并行』的唯一已实测手段」 | **不再是唯一**；thread 更便宜（无 123ms spawn）；子进程池保留给**崩溃隔离 / NTS** |
| **13 §9 V-7** | 「NTS 是可选构建形态」 | 仍可选；但 **NTS 未做线程测试** ⇒ 用 `Swoole\Thread` 必须 ZTS；NTS 用于「无线程」发布 |
| **10 §6.1** | 构建四坑 | 增加**资源安全纪律**（AGENTS.md：串行、`--job 1`、内存硬限制） |

---

## 3. 修订后的异步决策树（四问）

```
问 1：这段活「在算」还是「在等」？
  在算 ─────────────────────────────► 问 2
  在等 ─────────────────────────────► 问 3
  只是「稍后做」（日志/埋点/清理）──► Async::defer

问 2：能用 C++ 库表达吗？
  有现成 C++ 库（解码/压缩/哈希/加密/查询）──► Async::post（通道；NTS/ZTS 都行）
  只能 PHP，但项与项无依赖 ─────────────────► Async::chunk（主线程 Fiber）
  只能 PHP，且重到不能 chunk ───────────────► 问 2b

问 2b：ZTS + Swoole 可用吗？
  可用 ────────────────────────────────────► Async::thread（Swoole\Thread worker）
  不可用（NTS / 不引 Swoole / 需崩溃隔离）──► 子进程池

问 3：等的是裸 fd，且每次就绪后处理 ≤4ms？
  是 ──────────────────────────────────────► Io::await（主线程非阻塞）
  否（HTTP/TLS/DB/gRPC 协议栈）─────────────► Async::post（C++）或 Async::thread（PHP）
```

**判据补充**：
- **线程档的额外判据**：这段 PHP 代码是否「可信」？不可信（插件、用户脚本）⇒ 子进程池（要崩溃隔离）。
- **线程档的额外判据**：是否需要长期持有状态（连接池/索引/缓存）？需要 ⇒ thread（每线程一个实例）优于子进程（每次重建）。
- **默认仍不引 Swoole**：框架的零依赖路径（Fiber + chunk + defer + 子进程）必须独立成立。

---

## 4. 新增能力：`Async::thread` 的形状（草案）

```php
// worker 入口是脚本文件（Swoole\Thread 不吃闭包）
$job = Async::thread('worker.php', args: [$queue, $map, $taskId]);
$result = $job->await($timeoutMs);          // 让出；完成只 post 一个唤醒

// worker.php（编译进 exe 的具名入口）
function main(): void {
    if (!Swoole\Thread::getInfo()['is_main_thread']) {
        return;                              // 主线程不做 worker 的活
    }
    [$queue, $map, $id] = Swoole\Thread::getArguments();
    $queue->push(['id' => $id, 'ok' => true, 'data' => compute($id)], 1);
}
```

**契约**：
- 过界：标量 / 数组 / `Queue` / `Map` / `Atomic`；`Node`/`Native`/`Closure` 一律不过界。
- 生命周期：worker 常驻（池化），启动时 init，退出时 drain；线程数默认 `min(核数-1, 4)`。
- 背压：每池 `pending` 上限（默认 64），超限返回 `false` 由应用降级。
- 取消：未调度可撤销；运行中只能「放弃」，结果回来丢弃、不写 Store。
- 超时：`await($timeoutMs = 0)`，0 = 永不；超时抛 `AsyncTimeout`。
- 崩溃：worker 段错误 ⇒ **进程致命**（这条不隔离；不可信代码走子进程池）。
- 唤醒：只 post 一个信号，不把结果推给 PHP；结果由 Fiber 恢复后自己取。

**收益**：
- 无 123ms spawn；worker 可常驻池化。
- 完整 PHP（编译类/闭包/异常/原生 GC 根）可在 worker 跑。
- `Queue/Map/Atomic` 做结果通道，比子进程的 JSON 协议更直接。

**代价**：
- ZTS 运行时（更大、TSRM）。
- 应用要引 Swoole（框架默认不引）。
- 生命周期/关闭更复杂（`php_embed_shutdown` 统一负责，但 worker join/超时仍要框架管）。

---

## 5. 对 Drift2 的影响（回植 / 实验）

| 项 | 旧结论 | 新结论 |
|---|---|---|
| `Swoole\Thread` 跑 tpc 类 | ❌ 跑不到（`probes/RESULTS.md` §11–12） | ✅ ZTS 下已实测（编译类/闭包/异常/原生 GC 根） |
| `TaskHost` 的并行档 | 只有 `ProcessTaskHost`（spawn 123ms） | 可加 **`ThreadTaskHost`**（ZTS only，无 spawn 成本） |
| 默认异步 | `SyncTaskHost` | 不变；`ThreadTaskHost` 是 opt-in |
| 崩溃隔离 | `ProcessTaskHost` 有 | thread 没有 ⇒ `ProcessTaskHost` 保留 |

**建议的最小探针（Drift2 侧）**：
1. 用修复版 phpx（`13cf7175`）构建一个最小 AOT exe：主线程建 `Swoole\Thread\Queue`，
   起 2 个 worker，worker 里调用**编译出的类方法**并 push 结果；
2. 判据：worker 正常退出、主请求状态不变、结果正确、无 `IS_STR_PERSISTENT` 断言；
3. 通过后再评估 `ThreadTaskHost`，不通过就维持 `ProcessTaskHost`。

**前置条件**：dist `0.9.1` 的 phpx 是 10-13 的，**不含修复** ⇒ 需从 `13cf7175` 构建 phpx，
设 `PHPX_HOME`；Composer 依赖 `dev-master`。

---

## 6. 对「重构 vs 回植」判断的影响

- **线程修复降低了 design-v6 在 async 上的架构优势**：原本「PHP 并行只能子进程」是重构的一个理由；
  现在 `Swoole\Thread` 更便宜，**回植 Drift2 也能补上**（`ThreadTaskHost`）。
- **但不改变**「一棵树 / 无 diff / 布局三策略 / 文本三拆」的地基优势——那些仍只有重构能拿到。
- **结论**：线程修复让「回植 Drift2」的性价比**更高**，而不是更低；如果最终选重构，
  D-16/08/16 必须按本修订写，否则会按旧结论把「PHP 并行」错误地限制在子进程。

---

## 7. 下一步（待裁决）

1. **design-v6 是否采纳本修订**（D-16 / 08 §3.3 / 16 §5.6/§5.8 / 13 V-7）？
2. **Drift2 是否先做 §5 的最小线程探针**？（需先构建修复版 phpx）
3. **是否更新 `probes/RESULTS.md` §11–12**（旧结论已过时）？
4. **`Async::thread` 放进 design-v6 的哪个阶段**？建议 P1 不做，P2 作为 opt-in。

---

## 8. 证据

- phpx `13cf7175`（`git show 13cf7175`）：`include/phpx.h`、`src/core/base.cc`、
  `src/misc/typephp_opcode_table.cc`、`src/misc/typephp_runtime.cc`、`tests/src/string.cpp`。
- typephp `aeda7e3d` + `.github/integration/swoole-thread/RESULTS.md` / `README.md`。
- `AGENTS.md`（b7d03cd9）：构建资源安全纪律。
- 当前 dist：`typephp-windows-x64-0.9.1/phpx` mtime **2026-10-13**（早于修复）；
  `php.exe -v` = PHP 8.5.11 **ZTS**（线程运行时形态具备，但 phpx 需更新）。
