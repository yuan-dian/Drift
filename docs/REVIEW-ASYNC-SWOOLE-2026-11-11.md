# 异步方案重新推演：Swoole 已支持 TypePHP 的异步 / 线程 / 协程（2026-11-11）

> 复审，不是实施。**本轮零 `src/` 改动、零裁决正文改动**；结论先给，等拍板再动代码。
> 证据口径：本地 Swoole `6.3.0RC1` 源码 + 本仓探针记录 + 本轮三条本机实测。
> 不写 `file:line`；定位一律用类名 / 文件名 / 命令。

---

## 0. 结论

**不改主方案**：五层（`Future` / `AsyncValue` / `AsyncCommand` / `TaskHost` / `PumpHost`）、
单 UI 线程 + 结果回贴、`TaskHost` 端口、`AsyncScope` 的 Fiber `await()` 全部保留；
不把 Swoole 引入 UI 进程，不用线程替换常驻子进程池，不给业务暴露 `Coroutine\run()`。

理由一句话：**官方增加的是「执行体」供给，而 Drift 的约束是「谁掌握主线程控制流」。**
二者不在同一层。改方案要付的代价是重写 C++ 消息泵 + 放弃 `curl_multi` 这条已验证的路 +
新增一份运行库依赖；换到的收益（IO 并发）在当前负载里只占端到端 0.08%。

**但有三处该动**（见 §5）：订正四条腐烂论据、重开 `curl_multi` 判死、借鉴而不引入
TypeApp 的执行作用域概念。其中第一条是必做，因为旧论据已经与代码相反，
下次复审会被同一篇官方材料再推翻一次。

---

## 1. 口径：谁在说，说了什么

### 1.1 先纠正一处引用错误

我最初核对时命中了 `zoujingli/typeapp` 的《进程、线程与协程》，并把它当"官方口径"引用。
**这是不严谨的**：TypeApp 是 Anyon 的第一方 PHP 应用框架（Apache-2.0），
用 TypePHP 全量 AOT 编译、把 Swoole 当**内置原生运行库**静态链接进单程序；
它是 Swoole 的**下游消费者**，不是 Swoole 官方项目，也不是 `swoole/typephp` 的一部分。
⇒ 它能证明"有人这么集成并跑通了四平台验收"，不能证明"Swoole 官方承诺了什么"。

真正的第一手材料是这三份：

| 来源 | 身份 | 说明什么 |
|---|---|---|
| 本地 `swoole` 源码（`swoole/swoole`，`6.3.0RC1`） | 官方扩展 | `windows-native-support.md` 的能力矩阵、`windows-iocp-curl.md` 的 IOCP 桥设计 |
| `swoole/typephp` + `swoole.com/aot` | 官方编译器 | TypePHP 就是本仓 `tpc`，同一套东西（11-04 已勘误） |
| `probes/RESULTS.md` 探针 L / N / J 系列 | 本仓 AOT 实测 | 加载、线程上下文、协程与消息泵融合、hook 全局替换 |

### 1.2 官方"支持"的四种含义（必须分开）

官方矩阵把能力分成 Supported / Limited / Optional / Unsupported，并明确写着
"要区分一个类被编进扩展，与一个特性在 Windows 上语义可用"。套到本仓：

| 层次 | 官方状态 | 对本仓是否成立 |
|---|---|---|
| 类被注册 | `Swoole\Coroutine`、`Event`、`Co\Http\Client` 在 Windows 原生端口均 Supported | ✅ 是 |
| 语义可用 | `Event::dispatch()` 的 timeout **由下一个到期事件决定**，不是调用方可控的 0 | ❌ 60Hz 帧预算下不可用（本机实测：注册 1000ms timer 后首次阻塞 **1013ms**） |
| 能进 AOT 产物 | 探针 L ①：`swoole=yes 6.3.0RC1 zts=yes`（同目录 ini 要 `curl` 在 `swoole` 之前） | ✅ 是，但**当前 Drift 产物不带它**（运行时 ini 只启用 `mbstring/openssl/curl`） |
| 业务可用 | `Swoole\Thread` 里调 tpc 编译的类 ⇒ 探针 L ③ `Class not found`（干净的 Error，不是崩）；TypeApp 文档自己写「普通 Thread 可用不等于已编译业务线程可用」，需 `startNative` / `NATIVE_ENTRY_ABI=2` 编译适配 | ❌ 当前工具链不成立 |

⇒ **"支持"是真的，"能直接拿来改 Drift 的异步方案"不是**。TypeApp 文档里那句
"上游提供某项能力不能替代应用验收"，正是这条边界的官方表述。

### 1.3 官方自己给的三条降级纪律（反而支持"不改"）

TypeApp 的运行时约定明确：进程 / 线程 / 协程只是**隔离层次**不同，
"降级只改变隔离层次，不改变业务的截止、取消、资源额度和真实收尾要求"；
线程与协程"不能被描述成进程的等价替代"；协程"不能把阻塞的任意 PHP 代码变成异步代码"。
⇒ 官方也没有把执行体升级当作业务架构升级。Drift 的 `TaskHost` 端口就是
"换执行体而 UI 代码零改动"的那个抽象——新能力若真有价值，落点是**第 4 个后端**，
不是重做方案。

---

## 2. 现状（2026-11-11 复核，不是文档转述）

| 面 | 现在是什么 | 证据 |
|---|---|---|
| 控制流 | `GetMessageW` / `waitEvents` 掌握主线程，帧内 `Application::frame()` 五阶段 | `DESIGN.md` |
| 线性写法 | **`AsyncScope::task()+await()` 已落地**（PHP 内置 Fiber），watermark 五步流水线是一段顺序代码 | `WatermarkStore` 五处 `$co->await()` |
| AOT 约束 | `NanoHygiene::BANNED_TYPES` **只剩 `Generator`**；`Fiber` 已于 11-04 解禁 | 本轮 grep |
| 状态层 | `AsyncValue` 四态 + `AsyncCommand`（去重 / 作废旧请求 / 真取消 / 卸载兜底 / `append()`） | `src/Async`、`src/State` |
| 执行体 | 默认 `SyncTaskHost`；三个真实应用用 `ProcessTaskHost` 常驻池 + `prewarm()`，worker 是同一份 AOT exe 的 `--drift-worker` | 三个 `Bootstrap` |
| IO 就绪 | `IoPoller` 用 `stream_select(..., 0)` 每帧问一次，零线程零扩展 | `src/App/Io` |
| 成本账 | 预热后单条任务 25.5µs；60Hz 端到端 33.4ms ⇒ 传输占 **0.076%** | `bench-taskhost-rtt.php`、`ASYNC-ARCH` 的账 |
| 运行库 | `drift-build` 生成的运行时 ini 只启用 `mbstring / openssl / curl`，**无 swoole** | `tools/drift-build.php` |

★ **本轮本机实测**：`PHP 8.5.12`、`php.ini` 中 `swoole=no`、`curl=YES`；
`curl_multi_init()` + `curl_multi_close()` ⇒ **`EXIT=0`**。
⇒ 旧稿"这个运行时上 `curl_multi` 一调就崩"在**当前环境不成立**（见 §5.2）。

---

## 3. 四维分析

### 3.1 架构

| | 现状 | 引入 Swoole 协程 / 线程 |
|---|---|---|
| 调度器 | 一个：Win32 消息泵。空闲可真睡（`waitEvents(-1)`） | 两个。`Coroutine\run()` / `Scheduler::start()` 吃主线程；`Event::dispatch()` 不肯 0 超时返回 |
| 融合代价 | — | 探针 N 验过可行（1.2s 慢 HTTP 期间 111 tick / avg 16.12ms），但要把 `GetMessageW` 改成 `PeekMessage` + `Co::sleep(0)` ⇒ **空闲也轮询**，且 1 次 59ms 尖峰未定位、落在 T2 真机验证盲区 |
| 确定性（G3） | 时间 / 主题 / DPI 全部注入，`DisplayList` 可逐字节 golden | Swoole Timer 用真实时钟；协程调度序不确定 ⇒ 与"可重放 / 可快照"直接冲突 |
| 卸载安全 | `AsyncCommand` 在 `unmount()` 停掉 ⇒ 迟到的结果永不结算，**"恢复后世界已变"不存在** | UI 侧 `await` 会把这个结构性安全属性换回去。TypeApp 需要整套 `ExecutionScope`（closing / Deadline / 子作用域）才补得回来 ⇒ 等于重写异步层 |

★ 成熟 GUI 对照（Flutter / Qt / WPF / Chromium / SwiftUI）：**没有一家让业务代码在 UI
线程上跑另一套协程调度器**，SwiftUI 是语言级 `MainActor` + runtime executor。
"执行体在哪"各家不同，"只有一个 loop 掌握 UI 线程"各家相同。

### 3.2 实现

四条硬代价，全部有本仓或本地实测：

1. **`Event::dispatch()` 阻塞到下一个事件**（本轮口径：11-04 本机 1013ms / 空转 0.001ms）。
   官方 wiki 也写明它"仅执行一次 `reactor->wait`"，timeout 不是入参。
2. **hook 是全局替换**：`Runtime::enableCoroutine(SWOOLE_HOOK_ALL)` 后，非协程上下文的
   `stream_select` / `fread` 抛 `API must be called in the coroutine` ⇒ `IoPoller` 报废。
   走 `Co\Http\Client` 不开 hook 可避开，但那就绕开了 `src/App/Io/` 整套。
3. **与 `curl_multi` 共现崩**：Drift3 `swoole-curl-aot` 用 tpc 0.9.6 编 AOT bin 三组对照：
   `AOT+swoole+curl_multi` ⇒ `139`、`AOT+curl-only` ⇒ `0`、CLI 共现 ⇒ `139`，
   且用户代码打印完 `RESULT=DONE` 才崩在 shutdown ⇒ **只能编译期互斥**。
   引入 Swoole 等于关掉另一条路。
4. **编译业务线程不可用**：探针 L ③ `Class not found`；线程创建 29.7–35.6ms、
   解释执行比 AOT 慢 **55–95×**；还丢掉进程池的崩溃隔离、真 `proc_terminate`、跨进程 JSON 契约。

再加一条构建链：新增 `php_swoole.dll`（本机 2.5 MB）或静态进 exe、
`StalePolicy` 输入指纹要纳入扩展版本、部署 ini 顺序依赖（`curl` 必须先在 `swoole`）、
碰壳层必跑 T2。收益端：当前 3 worker 常驻池 + 25.5µs 往返已经把"后台执行"这层压到
用户感知的 0.08%，**没有待解决的性能缺口**。

### 3.3 扩展性

- `DESIGN.md` 把「第二平台」列为 1.0 的真正卡点。Swoole 的 Windows 与 Unix 能力矩阵
  **不对称**：Windows 不注册 `Swoole\Process` / 经典 Server / `Table` / `Atomic`，
  Unix 有。引入它，平台差异会从 `cpp-src/<platform>/` 扩散到 PHP 异步层。
- 反观现状：`TaskHost` 是端口，`IoPoller` 是标准库 `stream_select`，`AsyncScope` 是 PHP 内置
  Fiber —— 三者都不绑平台、不绑扩展。接第二平台时不易被拖住。
- 真要给 Swoole 一个位置，正确形状是**第 4 个 `TaskHost` 实现（opt-in）**，
  由组合根 `setTaskHost()` 装配；而不是让 `Future` / `AsyncCommand` / `PumpHost` 认识它。

### 3.4 开发者体验

| 需求 | 现状 | 换成 Swoole 后 |
|---|---|---|
| 多步顺序代码 | `$co->await(...)` 顺序写 | 仍是顺序写，**不会更短** |
| 心智负担 | Future / 四态 / 命令一套 | 再加 Fiber vs Co 协程、hook 全局开关、`ExecutionScope`、线程上下文、扩展版本 |
| 真缺口 | 流 / 进度 / 追加语义、`deadline` 与子作用域 | **一点没补** —— 这些是状态层，不是执行体 |

★ 最容易误判的一点：协程的三个卖点里，`Future::all/allSettled` 解决了并发 join、
`cancel()` 沿链上溯解决了取消传播，唯一剩下的"多步顺序"已由 `AsyncScope` 在 11-04 补上。
⇒ **"官方支持协程"对 Drift 的 DX 边际收益接近零**。

---

## 4. 三条不变量（结论不因"官方支持"而翻转）

1. **控制权**：UI 线程必须掌握消息泵；Swoole 的 loop 要么抢它、要么每帧阻塞到下一个事件。
2. **卸载安全**：`AsyncCommand` 的结构性取消是框架义务，不能换成"业务自己记得判 `isCancelled`"。
3. **确定性**：G3 要求布局 / 渲染可重放；引入自带真实时钟与调度序的运行时会破坏 golden 基线。

这三条都不是"当前版本做不到"，是**架构选择**；上游加能力不会改变它们。

---

## 5. 该改的三处（不是改主方案）

### 5.1 必做：订正四条腐烂论据

旧稿的**结论**仍然成立，但**论据**已有四条与当前代码相反：

| 旧论据（仍写在正文里） | 当前事实 | 该换成 |
|---|---|---|
| 「AOT 禁用 `Fiber`/`Generator`（C++17 无栈切换）」 | `BANNED_TYPES` 只剩 `Generator`；`AsyncScope` 已跑在 AOT 产物里 | 「AOT 支持 Fiber；UI 侧协程的障碍是控制权，不是编译目标」 |
| 「Windows 版没有 `Swoole\Event`」 | 官方矩阵列为 Supported（有限制），本机 `dispatch()` 可调 | 「有 API，但 `dispatch()` 不肯 0 超时返回」 |
| 「UI 侧协程会把卸载安全换回去」 | 仍成立 | 保留，但别再与上面两条绑在一起论证 |
| 「`curl_multi` + swoole 崩是运行时自身」 | 共现才崩（§2 本机 `EXIT=0`；Drift3 C-16 对照） | 「swoole 与 `curl_multi` 共现崩在 shutdown」 |

落点：`ASYNC-ARCH-2026-10-31.md`（硬约束表与"明确不做"表）、`ASYNC-2026-10-30.md`、
`ASYNC-DX-2026-10-30.md`、`EVENT-LOOP-DESIGN-2026-10-30.md`、
`IoPoller` 类注释。`DESIGN-LINEAR-ASYNC-2026-11-04.md` 已经做过勘误，
**建议按 DESIGN 的治理办法：在旧稿顶部加勘误条 + 指向 11-04 稿，不逐字改写历史正文**，
避免把历史推理改造成假的。

### 5.2 建议重开：`curl_multi` 判死

旧裁决把 A1 判死写成"解释器收尾必崩"。本轮本机 `curl_multi` 退出码 0，
Drift3 的 AOT 对照给出真因（swoole 共现），而其 `curl-scale-aot` 还证了 AOT 下
`Fiber + curl_multi` 可用到 256 并发。⇒ 判死理由**至少不再是普适事实**。
但按本仓纪律「未被消费的分支 = 未实现」：今天 `Http` 在 worker 里跑阻塞 `curl`、
`IoPoller` 用 `select(0)`，两条都够用 ⇒ **先记账，不立项**。
真要重开，先补一枪「当前 AOT 产物 + 无 swoole + `curl_multi` 真请求」的探针。

### 5.3 可借鉴（不引入依赖）：执行作用域

TypeApp 的 `ExecutionScope`（`active → closing → closed`、Deadline / TaskBudget、
子作用域复制关联信息但不继承资源）填补的正是 `AsyncScope` 目前只有"取消"、
没有"预算 / 截止 / 子树"的空档。这是**概念**，不是依赖：Drift 已有
`AsyncCancelled` + `guardCancelled()` + `unmount` 兜底，补一层预算不需要 Swoole。
按纪律等真实消费者（长轮询 / 批量并发取消）出现再动。

---

## 6. 重开门槛（让裁决可证伪）

满足任意一条、且**先出可跑的探针判据**，才重新讨论引入 Swoole：

1. Swoole 提供可控 0 超时的 reactor pump，或能把 IOCP 句柄交给
   `MsgWaitForMultipleObjectsEx` 与窗口消息合流（不再是两个 loop）；
2. tpc 提供编译类进入 `Swoole\Thread` 的正式入口，且出现 CPU 密集型真实消费者；
3. 出现"单进程几百条并发长连接"这类业务，常驻进程池与 `curl_multi` 都撑不住；
4. 第二平台落地时，Swoole 成为该平台唯一可用的非阻塞 IO 供给。

在此之前，Swoole 的定位保持 11-04 裁决：**worker 侧 `Job::run()` 的可选实现细节，
不是业务 API，也不是 UI 进程的执行体**。

---

## 7. 本轮证据与诚实边界

| 项 | 结果 |
|---|---|
| 新增 / 改动产品代码 | **0**（本文件是新增评审稿，未改 `src/`、未改裁决正文） |
| 本机实测 | `php 8.5.12` / `php.ini` 中 `swoole=no` / `curl=YES`；`curl_multi_init+close` `EXIT=0`；`drift-build` 运行时 ini 只有 `mbstring/openssl/curl` |
| 外部材料 | Swoole 官方源码 `docs/windows-native-support.md`、`docs/windows-iocp-curl.md`；TypeApp `runtime.md` / `platforms.md` / `roadmap.md`（**第三方集成案例，非官方声明**） |
| 引用但**未复跑** | `Event::dispatch()` 的 1013ms 读数（11-04 本机实测，本轮 `swoole=no` 未重跑）；探针 L / N / J 系列；Drift3 C-16 / `curl-scale-aot` 三组对照（外部仓库结论，未在本仓复跑） |
| 未跑 | `gate`、T0/T1/T2、真机窗口、变异牙检 —— 纯静态复审不需要，但 §5.2 那一枪要动代码时必跑 |
| 口径风险 | 「当前环境 `swoole=no`」与 11-04 记录的「`php.ini` 加载 swoole」不同 ⇒ 两个时点的 ini 不同，**不能据此说旧记录造假**，只能说旧归因不具备普适性 |
