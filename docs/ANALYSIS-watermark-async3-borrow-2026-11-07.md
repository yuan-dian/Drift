# Drift3 异步能力 × `apps/watermark`：逐项可借鉴性评估（2026-11-07）

> 口径：**对代码，不对清单**。上一轮（`2026-11-07.md` 18:08 调研）给的是「按件借鉴」的通用结论，
> 其中「`SingleFlight` 业务价值最高、最该先抄」这一条**被本轮推翻**，见 §4.1。
> 全部行号以当前工作树为准；`docs/ANALYSIS-watermark-dx-2026-11-02.md` 与
> `docs/DESIGN-LINEAR-ASYNC-2026-11-04.md` 的行数/文件数已腐烂，订正见 §7。
> 参考对象：`D:\TypePHP\Drift3\docs\blueprint\`（尤其 `20-异步实现缺口与成熟GUI详细对比.md`）
> 与 `D:\TypePHP\Drift3\src\Drift\{Async,Net}\`。

## 0. 一句话结论

**watermark 的并发上限被刻意设成 1**（一条 `flow`、一个 worker、N 张图串行），因此 Drift3
技术含量最高的那批组件——多源 IO 预算轮转、`curl_multi` 分批登记、优先级队列、
`SingleFlight` 合流——在这个业务的形状下**天然零消费者**；真正有价值的反而是它最便宜的
那部分：**常量证据分级、静态门禁、诚实的验证口径，以及「把分散的退出条件收成一处」的结构**。
建议借 6 件（§3），明确不借 8 类（§4），另有 3 件与 Drift3 无关但更该先修（§5）。

## 1. 前提：watermark 的并发形状决定了一切

| 事实 | 证据 |
|---|---|
| 一条流水线 = 一个 `AsyncCommand` 包一个 `AsyncScope::task()` | `WatermarkStore.php:134-143` |
| 五个动作全部是 `exec()` 出 `Future` + `$co->await()` | `:466, :540, :572, :652, :684` |
| 唯一非 `await` 的让出点是 `sleep()` | `:639` |
| `maxWorkers` 用默认 **1**（`apps/watermark/bin/win32/Bootstrap.php:82` 只传 2 参） | `ProcessTaskHost.php:92-93, :84` |
| 上传、下载都是**一个 Job 内串行 N 张**，不是 N 个并发 Future | `UploadBatchJob.php:86-114`、`DownloadResultsJob.php:69-79` |
| Job 内部是同步 HTTP，跑在 worker 子进程 | `ApiClient.php:12-15, :34-158` |
| 同 Flow 在途重复提交被 `duplicated()` 挡掉；换参会 `cancel()` 上一条 | `AsyncCommand.php:137-139, :243-245, :255-263, :270-272` |
| 迟到结果由 `Future::cancel()` 硬契约挡掉，有判据 | `AsyncCommand.php:251-253`、`tests/Feature/Watermark/StoreSettledEffectsTest.php:155-196` |

⇒ **同一时刻最多一个网络请求在途**。这不是缺陷，是 `UploadBatchJob.php:19-21` /
`DownloadResultsJob.php:25-26` 明写的取舍：不要「UI 侧维护 N 条在途 Future 的第二套簿记」。

**已有能力**（上一轮已核对，勿重复造）：多候选取 min 在 `FrameClock::nextWaitMs()` +
`EventLoop` 多窗口取 min；drain 预算/至少交一条/`finally` 保底在 `CompletionQueue`；
取消被宽 catch 吞掉由 `AsyncScope::guardCancelled()`（`AsyncScope.php:121,179,300`）在让出点重抛；
释放链是 `StatefulElement → State::dispose → AsyncCommand::dispose → Future::cancel`。

## 2. 逐项裁决总表

| Drift3 组件 | watermark 有没有对应场景 | 判定 | 关键依据 |
|---|---|---|---|
| `SingleFlight` | 同键并发在途：**不可能** | **不借**（订正） | §4.1 |
| `InterceptorChain` / 401 刷新 | 无 token 刷新；签名主动预刷新 | 不借 | §4.2 |
| `QueryCache` / `FetchPolicy` | `$sign` + `isExpired()` 已是完整缓存 | 不借 | §4.2 |
| `SwitchLatest` / 代次 | `Future::cancel()` 已更强 | 不借 | §4.3 |
| `AsyncValue` / 作用域树 / `Disposable` | 同名不同义 + DEBT 已裁决 | 不借 | §4.4 |
| `CurlIoSource` / `CompositeIoSource` / `ChannelIoSource` | 无 `curl_multi`、无多源、无帧内预算 | 不借 | §4.5 |
| `FrameBudget` / 三档路由 / `AsyncRuntime` | 零证据常量 + 双循环冲突 | 不借 | §4.6 |
| `AsyncResource` / `AsyncTrace` / `ErrorGuard`（代码） | 半成品，无生产接线 | 不借代码 | §4.7 |
| `@design-param` 常量证据分级 | **有**：两个超时常量口径冲突 | **借**（B1） | §3.1 |
| `DeadlineRegistry` 的「唯一登记 + 取 min」结构 | **有**：`while(true)` 无总时限 | 借概念 | §3.2 |
| `ErrorGuard` 的错误分类 + `E-AWAIT-SITE` 门禁 | **有**：五处重复 catch + 手写 catch 顺序 | 借概念 + lint | §3.3 |
| `ProcessPoolIoSource` 的 EOF 死亡双判 | **有**：`reap()` 只看 `proc_get_status` | 借诊断 | §3.4 |
| 事实源分级 /「探针全绿 ≠ 可用」 | **有**：假后端永远成功 | 借纪律 | §3.5 |
| `AsyncTrace::whySuspended()` | **有**：退出后文案残留 | 借概念（低优先） | §3.6 |

## 3. 值得借的六件（按「真实消费者 × 出错是否静默」排序）

### 3.1 B1 · 常量证据分级（零成本，命中本仓最硬的隐患）★ 首推

**场景**：`Config.php:47-57` 五个常量里，`POLL_INTERVAL_MS=10_000` 自标「原文 `setInterval`」、
`UPLOAD_TIMEOUT_S=300` 自标「大图 + 慢上行」，都是抄原网页 / 拍脑袋；
而 `ProcessTaskHost.php:93` 的 `requestTerminateMs=30_000` 没有来源说明。
`UploadBatchJob.php:86-114` 一个 Job 串行传 N 张图 ⇒ **单文件允许 300s，整个 Job 30s 就被
`sweepTimeouts()`（`:239-262`）杀掉**。两个常量口径冲突，当前**零判据**。

**Drift3 做法**：`20 §1.2:67-69` 明列 `16 条/帧`、`4ms`、`32`、`15s`、`IO_BUDGET_US=500`
全是 `@design-param` 零证据设计参数；`EventLoop.php:45-49` 自注「无实测支撑…不得当结论引用」；
`ProcessPoolIoSource.php:55-62`、`FetchPolicy.php:10-11` 同款标注。

**借什么**：不搬常量、不搬类，只借**标注纪律**——给每个跨层生效的时间常量写一行
「来源 / 证据等级 / 谁该比谁大」。**不借什么**：Drift3 那些具体数值。

**落地形状**：Config 五个常量 + `ProcessTaskHost` 的 30s 各补一行来源；加一条判据把
`UPLOAD_TIMEOUT_S` 与 `requestTerminateMs` 的关系显式钉住（要么对齐，要么在
`apps/watermark/bin/win32/Bootstrap.php:82` 显式传第三个参数并写明「N 张图的墙钟预算」）。不改运行时行为，
但把「静默杀任务」变成「有出处、有判据」。

### 3.2 B2 · 轮询退出条件收成一处（借 `DeadlineRegistry` 的结构，不借 110 行代码）

**场景**：`pollLoop()` `:618` `while(true)`，出口只有 `isTerminal()`（`:625`）、
`sleep()===false`（`:639`）、取消；单轮失败 `:659-665` 只写「会继续重试」然后**继续、间隔固定**。
服务端持续 5xx 或长期非终态 ⇒ 无限轮询，界面永远「正在查询结果」。
`tests/Feature/Watermark/PollRhythmTest.php:177-198` 的假后端**每次都成功**，这条路径没有判据。

**Drift3 做法**：`DeadlineRegistry.php:28-36`（同 tag 覆盖）、`:48-66`（遍历取 min）、
`:75-89`（同刻按 seq 稳定排序）把五类 deadline 收成唯一登记处；`cap-invariants` 16/16。

**借什么**：「**把分散的退出条件收成一处**」这个结构。
**不借什么**：它是给帧循环用的（与 `AsyncRuntime` 对接），搬进来就是第二套时钟；而且
`DEBT §33.7`（`:4580`）已裁决「不给 `AsyncScope` 加超时」——**现在有了真实消费者，但仍不该往
框架加**：阶段名、业务时限是应用词汇（`:4577-4579` 同条纪律）。

**落地形状**：Store 里三个局部变量即可——`$deadlineMs`、`$failures`、退避间隔
`min(10s * 2^failures, 60s)`；退出时显式落一条 `statusLine`（不是 `finally` 静默归位）。
**判据必须补**：连续失败 / 超总时限要能报红，假后端要能注入失败。

### 3.3 B3 · 错误策略收敛 + lint 把「纪律」变「判据」（借 `ErrorGuard` 概念 + 门禁思路）

**场景**：五处 `catch (\Throwable)` 各写三条消息（`:467-475, :548-555, :579-585, :659-665,
:685-690`），其中 `fetchResult()` 的语义与其余四处**完全不同**（可恢复、继续轮询）。
`:653-658` 明写「`AsyncCancelled` 必须是第一个 catch」，否则用户点「停止」后循环继续排定时器
——**这条靠人记，调错顺序不会编译错、不会报日志**。

**Drift3 做法**：`ErrorGuard.php:35-52` 逐级 `onError()`、谁处理谁停、全不处理记 uncaught；
但它 `:14-20` 自承「框架生成入口统一 try/catch 本轮不实现」、退出码 4 未接线
（`20 §3.2:187` W8）。真正值钱的是它配套的 `E-AWAIT-SITE` **静态门禁**思路。

**借什么**：① 把「可恢复 / 终态 / 取消」三类分派收成 Store 的一个私有方法，消掉重复文案；
② 扩一条 lint 规则：循环体内 `catch (\Throwable)` 必须先 `catch (AsyncCancelled) { throw; }`。
**不借什么**：Guard 传播树、`FATAL_EXIT_CODE`。本仓已有更合适的通道——
`tools/Lint/Rules/AwaitScope.php` + `tools/selftest.php:176` 的夹具/自检骨架
（`DEBT §55.5` `:7402-7404` 已验证这条 lint 能在真实业务变异上精确报红）。

### 3.4 B4 · 进程池死亡判据对照（借诊断结论，不搬实现）

- **该借**：`ProcessPoolIoSource.php:433-447` 用 `proc_get_status.running ∪ stdout EOF` **双判**，
  注释点名「AOT 子进程 `proc_get_status` 可能误报」。Drift2 `ProcessTaskHost::reap()`
  `:598-626` **只看 `proc_get_status`，且空闲期有 `REAP_IDLE_INTERVAL_US` 节流**；
  `readReplies()` `:515-517` 遇到 `$chunk === ''` 只 `continue`，没有标 EOF 死亡。
  ⇒ 值得补一个 EOF 标记 + 一条「worker 提前退出但 status 仍 true」的变异判据。
- **已等价，别重复造**：「只结算死者名下在途、不重试」`ProcessPoolIoSource.php:448-459`
  vs `ProcessTaskHost.php:616-622` 的 `$item['worker'] !== $index` 过滤——本仓已有。
- **明确不换协议**：Drift3 的 4 字节长度帧 + 逐字节滑窗重同步（`:376-388`）是为了修
  「AOT 下 PHP warning 走 stdout 污染帧流」；Drift2 用 JSON Lines（`:521-530`），
  一行垃圾一行好，`:542-546` 报一条非协议数据后继续 ⇒ **本仓协议反而更抗污染**。
  建议把这个「为什么用行协议」写进注释，免得以后有人照着 Drift3 改成长度帧。
- `SPAWN_PER_FRAME=1` / `pm.max_requests` 帧内限速：`prewarm()`（`apps/watermark/bin/win32/Bootstrap.php:89-91`）
  与 `spawnWorker()` 已串行，单 worker 场景不需要。

### 3.5 B5 · 验证口径与事实源分级（零成本，贯穿纪律）

`20 §7:461-468` 三种误读（借了结构≠实现了、组件级探针全绿≠框架可用、不照搬≠做不到）、
`20 §9:492-496` 自认「本文只是静态盘点、本轮没跑新探针」。
对 watermark 的直接映射：`tests/Feature/Watermark/` 的假后端**从不失败、从不部分失败**
（`PollRhythmTest.php:199-202`、`StoreHostChainTest.php:151`），于是「网络抖动重试」
「部分下载失败」「无 scheduler 退出后 `statusLine` 残留（`:599` vs `:445-449`）」三条
系统性无判据。把这张「假后端永远成功」的盲区列成欠账，比补十个组件更值钱。

### 3.6 B6 · 「为什么没动静」的显式原因码（低优先）

`AsyncTrace.php:28-48` 只是字符串字典（无时长、CLI 未接、`:13-16` 自承挂账），**代码不借**；
但 `whySuspended()` 这个概念对得上 watermark 的一个具体坑：无定时器环境 `sleep()` 返回
`false` ⇒ 只查一次即退出（`:639-643`），而 `:599` 写的「等待处理结果…」不会被 `finally`
改写 ⇒ 界面停在一句永远不会兑现的话。退出路径显式写一条 `statusLine` 即可，成本一行。

## 4. 明确不借（含对既有裁决的复核）

### 4.1 `SingleFlight` —— **订正上一轮「最该先抄」**

`DEBT §55.6`（`:7416`）早已把 `SingleFlight` / 请求拦截器判为「**零消费者**」。本轮对
watermark 复核，结论仍成立且更硬：
1. 单 `flow` + `duplicated()`（`AsyncCommand.php:243-245`）+ `maxWorkers=1` ⇒ **物理上不可能
   两个同键请求在途**；
2. 签名复用靠 `$sign` 字段 + `isExpired()`（`WatermarkStore.php:497`、`Config.php:45`）
   **主动预刷新**，比 SingleFlight 更省——连一次「在途合并」都不需要发生；
3. Drift3 自己的 `SingleFlight::complete()`（`SingleFlight.php:81-86`）在 `src/` 内**零调用方**，
   而类注释 `:13-16` 明写「只去重不重飞会变成永久缓存」。搬进来等于引入它自己承认的
   静默风险，且本仓没有任何消费者会去调 `complete()`。

⇒ 上一轮把它列为「业务价值最高」是**没对 watermark 并发形状**造成的误判，撤回。

### 4.2 请求拦截器 / `QueryCache`
watermark 没有 token 刷新接口；签名有效期是主动判断（`:497`），比「401 后再刷新」少一次
失败请求。`InterceptorChain.php:47-64` 的 `isRetry()` 只计数、**没有重试循环**，刷新跳过靠
`Request::TRANSPORT_REFRESH` 请求标志而非解析 401 响应；`RequestTransport.php:11-13` 是纯接口。
`QueryCache::read()` 的 `$policy` 参数（`:56-66`）**从未被读取**，stale/gc/SWR 全是占位。
⇒ 本仓的 `$sign` + 60s 余量反而是完整可用的「缓存 + 失效」，不借。

### 4.3 `SwitchLatest` / 代次
`SwitchLatest.php:30-36` 只翻代次、**不取消上游**（迟到结果靠 `CompletionQueue` 的代次门拒收）。
Drift2 是 `abandon() → Future::cancel()` 让被弃请求**永不结算**（`AsyncCommand.php:251-253`），
并有 `StoreSettledEffectsTest.php:155-196` 的真判据「取消后迟到结果不落地不下载」。⇒ 更强，不借。

### 4.4 同名不同义 + 本仓既有裁决
`AsyncValue`（Drift2 不可变 + `fold()` vs Drift3 可变 + revision）、`AsyncScope`（Fiber 流水线
vs 作用域树）、`AsyncCancelled` 同命名空间不同义，原样搬入必 Fatal。
`DEBT §55.6`（`:7412-7415`）已裁决结构化作用域树「零消费者，暂不立项」，且明确点出
蓝图 `07` 对「全局单 scope + 手动 dispose」的描述**已过时**。⇒ 不借，也不必重新裁决。

### 4.5 多源 IO 全家桶
watermark 是「单 worker 子进程 + 同步 HTTP」，没有 `curl_multi`、没有多个 `IoSource`、
没有帧内预算轮转。`CurlIoSource` 的 `REGISTER_PER_FRAME=4`、`CompositeIoSource` 的
`intdiv(budgetUs, count)` 环形轮转都针对**同一进程内多 IO 源**。任何一个 `IoSource` 都
`implements Drift\Async\IoSource` 且 `use AsyncRuntime`，而 `AsyncRuntime` 组合 21 个组件
（`20 §2.4:119-123`）⇒ 搬一个源 ≈ 搬半套运行时，还会与 Drift2 的
`EventLoop`/`FrameClock`/`PumpHost`/`TaskHost` 形成**两套并行帧循环**（都独占阻塞等待原语）。
⇒ 不借。若真要提速，正确做法是改 Job 粒度或 `maxWorkers`，但那会撞上
`UploadBatchJob.php:19-21` 明令回避的「第二套异步簿记」，属独立裁决。

### 4.6 零证据常量与待定标档
`FrameBudget` 4ms 三桶、三档路由阈值、`PollSpec::MIN_INTERVAL_MS=100` 全是 `@design-param`；
`20 §3.4 E5:224` 与 `12 §8:331` 的 DWM 真机全量复跑 **INCONCLUSIVE**，
`event-loop-window-aot/RESULT.md:16-22` 相邻两轮 L2 一轮 PASS 一轮 INCONCLUSIVE。
⇒ 搬进来就是新的静默风险，不借。

### 4.7 半成品
`AsyncResource.php:28-43` 是 `slotId=0` 空壳（`20 §5.10:373`）、`AsyncTrace` 无时长/CLI、
`ErrorGuard` 退出码 4 未接线、`RequestTransport` 无真实 HTTP 后端。⇒ 代码不借；
概念已在 B3/B6 择要吸收。

## 5. 与 Drift3 无关、但更该先修的三件

按「真实消费者 × 错了是否静默」排，这三件比上面任何一件 Drift3 借鉴都更急：

1. **下载进度断链**：`DownloadResultsJob.php:78` 发 `JobProgress::report(done/total)` →
   `WatermarkStore.php:684` 的 `await()` **没传第二参**（对比上传 `:540-547` 传了）→
   `ResultCard.php:156-158` 无进度条。N 张图下载阶段只有转圈，聚合值无人消费。
   **自家模板现成**（`DESIGN-LINEAR-ASYNC` 记这做法是 `-8 行`），不用借任何东西。
2. **取消守卫 lint**（B3 ②）：把「靠人记的 catch 顺序」变成静态判据。
3. **假后端注入失败/部分失败**（B2/B5）：现在「会继续重试」这条分支没有任何测试跑过。

## 6. 建议顺序与验证纪律

`B1（常量分级）→ §5.1（下载进度）→ B2（轮询退出 + 判据）→ B3（错误收敛 + lint）→
B4①（EOF 死亡判据）→ B6（退出文案）`，`B5` 作为贯穿纪律写进 DEBT。

每件照项目纪律走：**先对代码再动手 → 变异验证必须真红 → gate 全量 → 动到 C++/壳才跑 T2 真机**。
B1–B3、B6 是纯 PHP + lint/单测，不需要 AOT；B4① 动了 `ProcessTaskHost`，需要
`drift-build watermark` + 真机探针（当前 T2 环境已由 §67 修好，可用）。
**不要**为了「对齐 Drift3」而先造框架级原语：本仓 `DEBT §26.2` / `§55.6` 的
「零消费者不预先发明签名」在 watermark 这个形状下依然是对的。

## 7. 顺带订正：文档清单已腐烂

| 项 | 文档旧值 | 代码实际 |
|---|---|---|
| `WatermarkStore.php` | 830 行（`DESIGN-LINEAR-ASYNC:541`）/ 906 行（`ANALYSIS-watermark-dx:19`） | **770 行** |
| `Jobs/` 文件数 | 「7 个」 | **6 个**（注册表 + 5 个 Job） |
| `tests/Feature/Watermark/` | 「两个测试」 | **7 个** |
| `isRunning()` / 四态「照旧」（`DESIGN-LINEAR-ASYNC:454`） | 声称 watermark 仍在用 | **代码零使用**：UI 全靠 `Phase` + `StatusMessage` 派生 |

`ANALYSIS-watermark-dx-2026-11-02.md:96-98` 已自标其 §2.3 为「已过期快照」。
⇒ 本文档结论一律以当前源码为准，不继承旧清单。


---

## 8. 四维度重评：架构 / 代码实现 / 扩展性 / 开发者体验（21:20 追问）

> §1–§7 是**「有没有真实消费者」的一维裁决**。本节换四个维度重排：问的不是「用得上吗」，
> 而是「**该住在哪一层、改起来多贵、将来会不会变成债、写业务的人要记多少东西**」。
> 结论先给：**排序变了，但没有翻案** —— `SingleFlight` 撤回、多源 IO / 同名不同义 /
> 零证据常量不借，四条障碍是结构性的（并发形状、命名空间冲突、双循环、无证据），
> 与视角无关。变的是「哪件最值钱」。本节含一个方案稿，**未实施，按项目纪律需先裁决**。

### 8.1 架构：该借的不是组件，是「机制下沉 / 业务词汇上层」这条边界

**本仓已有裁决**：`DEBT §33.7`（`:4577-4579`）明写「`Phase` 那 6 个值是**业务词汇**，
框架不该知道」，成熟框架（Kotlin 协程 / Swift async）也不内建流水线阶段名；
`A15` 纪律（`:2657, :2804, :3195, :3564`）是「零消费者不预先发明签名」。
本仓的 `tools/Lint/Rules/DependencyDirection.php`、`InternalLeak.php`、
`StaleCapabilityClaim.php` 已经把这条边界做成静态门禁 —— **Drift3 的
`E-LAYER-VIOLATION` / `api-surface` 分层门禁不是新概念，本仓早有等价物**。

Drift3 恰恰在这条上反复纠偏：`AsyncRuntime.php:63-93` 组合 21 个组件、
`FramePump` 想统一五阶段、`AsyncTrace` 想收编阶段名；结果 `13:155` 的 D1 缺陷
自己承认 `generation` 泄漏到业务面（业务要手写 `new Pending(..., $co->generation(), ...)`），
才裁决「业务面移除，投递收进组装根」。`13:176` 更把「业务写法一个字不改、心智负担 0」
写成可验收的 DX 指标。⇒ **架构上最值得借鉴的是它反复撞墙后得出的边界纪律，不是任何一个类。**

**更优方案（把四件补丁合成一件事）**：§3 的 B1（常量证据）、B2（轮询退出）、
B3（错误分类 / 取消守卫）、B6（挂起原因）在消费者视角是四件独立补丁；架构视角看，
它们是**同一个东西的四个面** —— watermark 把「一条长流程的策略」
（间隔 / 退避 / 总时限 / 失败上限 / 错误分类 / 取消语义 / 超时口径 / 退出文案）
全部**隐式散落**在 `WatermarkStore.php` 770 行的流程代码里。逐件打补丁只会让 Store
涨到 850 行，策略与流程继续纠缠，第二个业务来了还得抄一遍。

⇒ 建议 `apps/watermark/src/Store/FlowPolicy.php`：**应用层的纯值对象 + 决策函数**，
不进 `src/`、不改框架、不加 `@api`。把上述策略参数收成一处，`pollLoop()` 只问
`afterFailure()` / `shouldStop()`，拿回 `waitMs | stop(reasonCode)`；Store 保留执行与呈现。

| 维度 | 为什么比「借 `DeadlineRegistry`」更好 |
|---|---|
| 分层 | `DeadlineRegistry.php:48-66` 是**帧循环级**的（external 候选 / `wantsFrame()` / 对接 `AsyncRuntime`），搬进业务就是第二套时钟；FlowPolicy 是**业务流程级**的 |
| AOT | 纯标量、零 IO、零 Fiber、零闭包 ⇒ 不碰 `:132-133` / `:734-739` / `TaskResult.php:130-135` 那些血泪约束 |
| 裁决兼容 | 尊重 `DEBT §33.7`：阶段名、时限、文案仍是应用词汇，只在 `App\Watermark\Store` 命名空间 |
| A15 兼容 | 不是发明签名，是从现有 770 行**萃取已存在的事实**（10s、300s、30s、三类错误、退出文案代码里都有） |

**代价与判死条件（必须诚实）**：多一层间接；`Store` 与 policy 的边界要裁决 ——
建议 policy **只返回决策 + reason 码，文案仍由 Store 写**，否则业务词汇又漏进策略对象，
正是 `DEBT §33.7` 反对的。单消费者有过度设计嫌疑：**若萃取出来不足 4 个决策点，
就别建类**，用几个常量 + 一个私有方法即可（B2 单独看只有 2 个，必须合并 B1/B3/B6 才够）。

### 8.2 代码实现：验证分层决定成本，而不是「价值」决定成本

`docs/architecture/12-工程与工具.md:26-32, :47-60` 的 T0/T1/T2 是硬约束：

| 候选 | 改动落在 | 需要的验证 | 成本判断 |
|---|---|---|---|
| B1 / B2 / B3 / B6 / 下载进度 | `apps/watermark/`（业务 PHP） | **T0 gate + T1 dev 真机** | 低，无需 AOT |
| B4 EOF 死亡双判 | `src/App/ProcessTaskHost.php`（**框架层**） | **必须 T2**（`drift-build watermark` + 真机探针） | 高，且牵动所有用该 Host 的应用 |

⇒ §6 把 B4 排第 4 是「消费者视角」；实现视角它是**唯一动框架、风险最高**的一件，
**必须独立成棒**，不能混在业务改造里顺手改。

**AOT 形状约束**：`WatermarkStore.php:132-133`（闭包恰好 1 参）、`:734-739`
（`time()*1000` 不用 hrtime）、`TaskResult.php:130-135`（禁 switch / C2440）。
Drift3 的 N4 零闭包、禁 `mixed`、`await(Pending):Pending` 是**它自己的 AOT 口径**
（`12 §0:25`、`13 §1.5:135-141`），照搬会推翻本仓已验的 473 条 smoke。
⇒ FlowPolicy 用普通方法 + 标量返回，决策码用 `int` 不用 enum match 返回值最稳。

**lint 的真实成本（最低）**：`tools/Lint/Rules/` 已有 **29 条规则**，
`AwaitScope.php` + `tools/selftest.php:176-180`（3 个「该响」+ 夹具「不该响」）是现成模板；
`DEBT §55.5`（`:7402-7404`）已证这条通道能在真实业务变异上精确报红。
新增一条「循环内 `catch (\Throwable)` 必须先 `catch (AsyncCancelled) { throw; }`」
≈ 1 个规则类 + 2 个夹具 + 1 组 selftest 断言，gate 自动收录。**成本/收益比全场最高。**

**判据别写成同源**：B2 的新判据不能只数 `pollLoop()` 调了几次 `sleep()`（那是实现同源，
本仓最常见的静默缺口形状）。要量外部可观察：连续失败 N 次后 `statusLine` 变终态、
`Phase` 回 `Idle`、不再有新的 `PollResultJob` 被 `exec()`。
变异必须真红：去掉总时限 ⇒ 红；假后端永远成功 ⇒ 「量具自证」也要红。

### 8.3 扩展性：今天判「不借」是**有条件的**，要写下重裁触发判据

这是四维分析最值钱的产出 —— 把「零消费者」从一句结论变成**可观察的重裁条件**：

| 组件 | 今天不借的理由 | 什么信号出现时重新裁决 |
|---|---|---|
| `SingleFlight` | 单 flow + `maxWorkers=1` ⇒ 无并发在途 | `maxWorkers > 1`，或同一 `$sign` 可能被两条流程同时请求（多窗口 / 任务队列） |
| `CompositeIoSource` / `CurlIoSource` | 无 `curl_multi`、无多 IO 源 | 引入 **UI 线程内并发 HTTP**（而非 worker 子进程同步），或需要按帧预算削峰 |
| `SwitchLatest` / 代次门 | `Future::cancel()` 已硬挡 | 出现「取消不可达」的后端（取消后仍会回注），或允许 N 条流程并存 |
| `AsyncScope` 作用域树 | `DEBT §55.6:7412-7415` 已判 | 出现「一宿主多并发子任务、要按树取消」 |
| `QueryCache` | `$sign` + 60s 余量已完整 | 出现多键 + stale/SWR 语义的查询 |

**演进路径不是搬 Drift3，而是先改自己的粒度**：想让 N 张图并行 ⇒
① `Job` 粒度从「批」改成「单张」⇒ ② 出现**聚合器**需求（N 条 Future 的簿记）
⇒ 那时 `SingleFlight` / `CompletionQueue` 才第一次有真实消费者。
`UploadBatchJob.php:19-21` 明令回避的「UI 侧第二套异步簿记」正是这个聚合器 ——
它不该在应用里手写，而该是**框架能力**。所以扩展性上现在最该做的是零代码的一件事：
把「单 worker = 1」「一个 Job 包整批」这两个**假设及其重裁点**显式写进注释 / 文档，
让将来改的人一眼知道该回来重裁哪几条。

**FlowPolicy 的扩展性**：第二个业务（users 分页 / 断点续传）复用的是**形状**
（策略与流程分离），不是 watermark 的**值**。放应用层 ⇒ 不污染 `src/`、
不进 `API-SURFACE`、不需要 `api-doc-check --write`。这比「在 `AsyncScope` 上加通用超时」
可扩展得多：后者第二个消费者一出现就得改框架签名（`DEBT:3195` 的 A15 老账）。

### 8.4 开发者体验：排序反转 —— B1 对写业务的人零减负

**量化「加一个业务动作要动几处」**（以 `Flow::Poll` 为例，`DEBT:4397` 已说不实现）：
`Flow.php` +1 case → `runFlow()` +1 `elseif`（`:438-444`）→ 入口方法 + `canXxx` 门控
→ `Phase.php` 可能 +1 case → `$this->phase =` 六处之一 → 三条 `StatusMessage`
→ Widget 的 `SubjectBuilder::select` 切片 → 测试。**约 8 处，没有任何一处会提醒你漏了哪一处。**

**开发者必须记住的隐性清单**（真 DX 债，全部无判据）：
1. `catch (AsyncCancelled)` 必须第一个（`:653-658`）
2. 闭包恰好 1 参（`:132-133`）
3. 时间用 `time()*1000`（`:734-739`）、禁 `switch`（`TaskResult.php:130-135`）
4. `sleep()` 返回 false = 没有定时器，要自己处理退出（`:639-643`）
5. `duplicated()` 只认 `===`，参数须是 enum 单例（`Flow.php:9-11`）
6. `localPaths` 键序是完成顺序，须按 `downloadUrls` 取（`:238-240`）
7. **进度回调是 `await()` 的第二参**（`:540-547` 传了、`:684` 漏了）—— §5.1 的下载进度断链就是这条没记住的直接后果
8. 三条消息各自负责哪一段，无类型保证

⇒ **B1（常量证据分级）从第一位掉下来**：它给的是维护者看的注释，对写业务的人**零减负**。
DX 视角真正减负的是：
- **lint 化**（把 1 / 2 / 5 / 7 变成编译期红）—— Drift3 `E-AWAIT-SITE` 的思路，本仓有 29 条规则的现成通道；
- **FlowPolicy**（把 4 / 8 与间隔 / 退避 / 上限收成一处，业务只面对一个 `next()` 决策）；
- **B4 对 DX 收益为零**（开发者感知不到），纯框架健壮性 ⇒ 再次印证它该独立成棒。

**可直接抄的验收口径**（`13:176`）：「任何新能力，若要求业务代码多记一条规则却不给判据，
就不算 DX 改进」。本仓 `DEBT §55.5` 的 lint 变异验证已经证明这条口径可执行。

### 8.5 四维加权后的新排序（与 §6 对比）

| 序 | 事项 | 层 | 验证 | 四维理由 | 能否直接开工 |
|---|---|---|---|---|---|
| 1 | lint 三条：取消守卫顺序 / 进度第二参 / 常量证据标注 | 工具 | T0 | DX 减负最大、成本最低，把「纪律」变「判据」 | ✅ 不涉及语义 |
| 2 | 下载进度接线（§5.1） | 应用 | T0 + T1 | 纯实现遗漏，10 分钟；顺带成为第 1 项 lint 的**真实夹具** | ✅ |
| 3 | `FlowPolicy` 萃取（合并 B1/B2/B3/B6） | 应用 | T0 + T1 | 架构上分离策略与流程，扩展性最好 | ⚠️ **改语义，须先出方案等裁决** |
| 4 | 写下「单 worker=1 / 批 Job」假设 + 重裁触发条件 | 文档 | — | 扩展性欠账，零代码 | ✅ |
| 5 | B4 EOF 死亡双判 | **框架** | **T2** | 唯一动框架、风险最高，独立成棒 | ⚠️ 需 T2 预算 |

**没有翻案的部分**：`SingleFlight` 撤回（§4.1）、多源 IO 全家桶（§4.5）、
同名不同义三件套（§4.4）、零证据常量与半成品（§4.6/4.7）——
四个维度都指向同一个障碍：**watermark 的并发形状是 1，且本仓有自己的一套运行时**。
四维分析改变的是「先做什么」，不是「借什么」。

**事实源纪律**（借 `20:4` / `20:496`）：本节是同一份评估的追加章节，**不是新事实源**；
`08`/`09` 在 Drift3 的地位对应本仓的 `DEBT` 与 `AGENTS.md`。若第 3 项立项，
单独出 `DESIGN-*.md` 方案稿并走裁决，不要在本节里把方案写成既成事实。


---

## 9. 综合评判与落地纪要（2026-11-07，用户「按推荐继续推进」）

> 本节是 §1–§8 的**收敛**，不是新事实源。前三轮分别换了三个镜头：
> ① 能不能整包复用（结构）② 真实业务有没有消费者（价值）③ 该住哪一层、改起来多贵（架构/实现/扩展性/DX）。
> 三个镜头的结论**指向同一处**，所以本轮不再继续分析，直接落地。

### 9.1 三个镜头的共同结论

| 镜头 | 结论 | 是否被后一轮推翻 |
|---|---|---|
| ① 结构 | 同名不同义 + `AsyncRuntime` 21 组件耦合 + 双帧循环 ⇒ 不能整包搬 | 否（四个维度都是结构性障碍） |
| ② 消费者 | watermark 并发上限刻意是 1 ⇒ 多源 IO / 优先级 / 合流去重天然零消费者 | 否；**只订正了 `SingleFlight` 的排序**（从首推撤回） |
| ③ 架构 | 值钱的是「机制下沉 / 业务词汇上层」的边界与「隐性契约变判据」的纪律，不是任何类 | 否；只把顺序重排（lint 与断链前置） |

⇒ **可迁移物为零行代码**，全部是工程纪律：常量证据分级、静态门禁、诚实验证口径、
退出条件收成一处。所以「按推荐推进」的实际含义是**补自家欠账**，不是引入 Drift3 组件。
本轮落地后，仓库里仍然没有任何来自 `D://TypePHP//Drift3` 的代码。

### 9.2 本轮实际落地（§68）

| 项 | 做法 | 判据 |
|---|---|---|
| 下载进度断链 | Store 加 `downloadFraction` / `downloadMessage` + getter，`await()` 补第二参；`ResultCard` 只在下载中挂一条 `Progress`（默认态子节点数不变） | `DownloadProgressTest` 2 用例 / 43 断言，量 store 公开切片 |
| lint 化两条隐性规则 | `AwaitProgress`（Job 报进度 ⇒ await 必须订第二参，跨文件认 Job、认内联/变量两种形状）；`CancelGuard`（含 await/sleep 的 try 里宽 catch 前必须有取消守卫） | 正式 lint 0 error；`--selftest` 各「该响 2 / 不该响 0」；4 条变异全红 |
| 取消守卫补齐 | Store 三处（`FetchSign` / `UploadBatch` / `AddTask`）补 `catch (AsyncCancelled) { throw; }` | `CancelGuard` 规则本身即判据 |
| 轮询退出上界 | `Config::POLL_MAX_ROUNDS=60` / `POLL_MAX_FAILURES=5`，`pollLoop()` 两个计数收在**同一处**；成功即把连续失败归零 | `PollExitTest` 量终态：statusLine / Phase 归位 / 不再提交查询 / 不再排定时器 |
| 常量证据分级 | 新常量标 `@design-param` 零证据；`UPLOAD_TIMEOUT_S=300` 标注与 `ProcessTaskHost::requestTerminateMs=30_000` 的口径冲突且当前零判据 | 文档级，无代码判据（B1 本来就是给维护者看的） |

**关于「总时限」的一处改判**：§3.2 / §8 写的是「业务轮询总时限」，落地改成**总轮数上限**。
理由：墙钟要么注入时钟（改 `WatermarkStore` 构造签名，违反 A15），要么用秒级 `time()`
（AOT 下 `hrtime` 有本仓明确的血泪约束），两者都让判据不可驱动；轮数是确定性的，
`60 × 10s ≈ 10 分钟` 在注释里写清换算，业务语义不丢。判据也证明这条路有牙 ——
见 9.4 的 M5。

### 9.3 `FlowPolicy` 判死：按自己写下的条件**否掉**

§8.1 提了 `apps/watermark/src/Store/FlowPolicy.php`，并写明判死条件
「**萃取出来不足 4 个决策点，就别建类**」。动手前对代码数了一遍：

| 现有 `pollLoop()` 里的分支 | 是不是「策略决策点」 |
|---|---|
| 固定间隔 `POLL_INTERVAL_MS` | 是（1） |
| 查询失败 ⇒ 继续重试 | 是（2） |
| `isTerminal()` ⇒ 退出/下载 | 否，业务数据判定 |
| `sleep()` 返回 false ⇒ 退出 | 否，运行时能力探测 |

**萃取只有 2 个**。本轮新增的两个上界是**补缺口**，不是萃取；而且各自只是「一个整数 +
一次比较」，用两个 `Config` 常量 + 一段收在同一处的判断就够了。为一个只有 2 个决策点的
流程建一个策略类，正是 `A15`（零消费者不预先发明签名）与 `DEBT §33.7`
（业务词汇不上框架）反对的形状。⇒ **本轮不建 `FlowPolicy`，也不改框架签名**。

重裁触发条件：出现第二个长流程业务、或需要失败退避 / 错误分类 / 分阶段文案时，
策略决策点 ≥ 4 ⇒ 那时再萃取，且仍放应用层。这一条写在这里，避免下一棒把
「没建类」误读成「没做策略收敛」。

### 9.4 判据有效性：一次「假绿」的订正

第一轮变异里 **M5（把 `POLL_MAX_ROUNDS` 改成 99999）没咬住**：测试用
`Config::POLL_MAX_ROUNDS` 推导循环次数与期望值 ⇒ 常量一改，判据跟着放大到 100019 条
断言仍然全绿。这正是本仓反复踩的**实现同源**形状。修法：测试里把期望写成
`EXPECTED_ROUNDS = 60` / `EXPECTED_FAILURES = 5` 两个私有常量，另加一条
「`Config` 值必须等于期望」的量具断言 —— 配置变更必须显式同步判据，不能自动跟随。
修完 6 条变异全红（漏第二参 / 失败不累加 / 失败上限失效 / 轮数不累加 /
轮数上限失效 / 假后端不再失败），恢复后 `OK (4 tests, 125 assertions)`。

### 9.5 并发假设与重裁触发条件（零代码，但必须钉住）

当前「不借」是**有条件的**。watermark 的两个结构假设：

- **A：单 worker = 1** —— `apps/watermark/bin/win32/Bootstrap.php:82` 构造
  `ProcessTaskHost` 只传两参，`maxWorkers` 取默认 1；配合单 `Flow` +
  `AsyncCommand::duplicated()`，物理上不可能有两个同键请求在途。
- **B：一个 Job 包整批** —— `UploadBatchJob` / `DownloadResultsJob` 内部串行处理 N 张图，
  `UploadBatchJob.php:19-21` 明令不要在 UI 侧再造一套 Future 簿记。

| 组件 | 今天不借 | 什么信号出现时**必须回来重裁** |
|---|---|---|
| `SingleFlight` / 合流去重 | A + `$sign` 主动预刷新 | `maxWorkers > 1`，或同一 `$sign` 可能被两条流程同时请求（多窗口 / 任务队列） |
| `SwitchLatest` / 代次门 | `Future::cancel()` 已硬挡 | 出现「取消不可达」后端（取消后仍会回注），或允许 N 条流程并存 |
| `CompositeIoSource` / `CurlIoSource` / 优先级队列 | 无 `curl_multi`、无多 IO 源 | 引入 **UI 线程内并发 HTTP**（而非 worker 子进程同步），或需要按帧预算削峰 |
| `AsyncScope` 作用域树 | `DEBT §55.6` 已判 | 一宿主多并发子任务、要按树取消 |
| `QueryCache` / SWR | `$sign` + 60s 余量已完整 | 出现多键 + stale-while-revalidate 语义 |
| `FlowPolicy` 萃取 | 9.3：只有 2 个决策点 | 第二个长流程业务，或需要退避 / 错误分类 / 分阶段文案 |

**演进路径不是搬 Drift3，而是先改自己的粒度**：Job 从「批」改成「单张」⇒ 出现 N 条
Future 的**聚合器**需求 ⇒ 那时 `SingleFlight` / `CompletionQueue` 才第一次有真实消费者。
`UploadBatchJob.php:19-21` 回避的那个「UI 侧第二套异步簿记」，本质上就该是框架能力，
不该在应用里手写。⇒ 改 A/B 之前，先回来读这张表。

### 9.5.1 全套回归

`gate.php` **18/18**（129.0s）：lint / selftest / devshell / docref / apidoc /
probe-audit / pixels / frame-tax / m2 / unit / feature / golden 整页 34/34 /
smoke 473 条全过。feature **889 → 893**（新增 4 用例），`OK (893 tests, 3464 assertions)`；
`api-doc-check` 一致，无需刷 `API-SURFACE.md`。
本棒只动 `apps/` 与 `tools/Lint/`，按 T0/T1 分层不需要 T2，未跑 AOT 真机。
途中 docref 曾因本稿前几轮的 3 处 Bootstrap 组合根裸文件名引用报 error（同名文件有 5 个候选），
已改成全路径 —— 顺带把旧稿的文档欠账清了。

### 9.6 仍欠（如实登记）

- **§66 的 `ElementSelected` 事件 ⑬ 判据与两条 C++ 变异**（不 raise / 按 index 比对）
  仍欠；§67 已恢复 AOT 环境，随时可补，顺序见 `DEBT §66.6`。
- **B4 EOF 死亡双判**（`ProcessTaskHost::reap()` 只看 `proc_get_status`）动 `src/` 框架层，
  按 `12-工程与工具.md` 的 T0/T1/T2 分层**必须 T2**（`drift-build watermark` + 真机探针），
  本轮不动、也不混在业务改造里顺手改 ⇒ 独立成棒。
- `UPLOAD_TIMEOUT_S` 与 `requestTerminateMs` 的口径冲突**只是标注，没有判据**，
  需要真机量一批串行上传才能裁决谁说了算。
- `DownloadResultsJob` 的进度是「张数」而非字节；大图下载时进度条会跳，属可接受的简化。


---

## 10. 「按 Drift3 方案重写 watermark 会不会好很多」（2026-11-07 22:10 追问）

> 前九节问的是「能不能借 / 借哪件」，本节问的是**换一套范式整个重写**值不值。
> 口径仍以当前工作树实测为准；Drift3 侧以其自己的事实源为准
> （`D:\TypePHP\Drift3\docs\blueprint\10-真实业务DX对照-watermark.md`、
> `16-异步方案与实现Review.md`、`19-L2执行层落地与综合裁决.md`、
> `20-异步实现缺口与成熟GUI详细对比.md`），不继承 11-02 / 11-04 的旧快照数字。

### 10.0 结论（三层，别混成一句）

1. **设计意图上：是，而且 Drift3 就是照着这个痛点设计的。** `08:806` 记的原始诉求正是
   「设计的时候需要考虑开发者体验，不要有太多的心智负担」；`10` 直接拿 watermark 当
   反推样本，把「代码量大、心智负担重」归因为**能力传递 / 状态广播 / 跨边界值对象 /
   结果资源**四件事，而不是控制流。`Fiber + await + 作用域取消 + 完成队列唯一回注`
   与「隐性契约变静态门禁」这两条，方向准确，本仓已独立收敛到同构结论（§9.1 ①）。
2. **当前落地状态上：不是，且现在根本无法执行。** Drift3 自己 `19 §1` 的综合裁决是
   「六机制只闭合 3.5/6，`apps/users` 级形态 L0 才够用 ⇒ **先写小业务、别直接挑战
   watermark**」。`10 §6` 的「8 行编排」明确标注**设计草案、未实现**，同节自写
   「在 §4 六个机制落地之前，任何行数承诺都是估算」。
3. **归因上：换异步模型最多碰到约 1/4 的代码。** 3714 行里约 50% 是控件树与 HTTP/值对象，
   与异步模型无关；Drift3 的 `Ui\View` 至今是**空标记接口**，这部分连落点都还没有。

### 10.1 代码量归因（本轮 `wc -l` 实测，3714 行 / 29 个 PHP 文件）

| 分组 | 行数 | 占比 | 换异步模型能消掉吗 |
|---|---:|---:|---|
| `apps/watermark/src/Widgets/`（8 个：UI 声明 + 状态切片） | 1127 | 30.3% | **不能**，控件树 / 事件 / 四态呈现是业务固有；Drift3 `Ui` 仅 3 文件 95 行 |
| `apps/watermark/src/Store/`（5 个，含 833 行 Store） | 1053 | 28.3% | 只能部分：833 行里 :155–:293 是 25 个 getter、:294–:451 是 6 个 UI 命令入口，:452 之后才是流程（约 380 行） |
| `apps/watermark/src/Api/`（5 个：HTTP + 值对象编解码 + Config） | 716 | 19.3% | 只能部分：`NamedJob` 13 / `toArray` 9 / `fromArray` 8 的跨边界样板可消；签名、错误码、结果解析不能 |
| `apps/watermark/src/Jobs/`（6 个，含 Registry） | 477 | 12.8% | **能**，但前提是「不跨进程 + 框架给 Progress/Poll/AsyncResource」 |
| 宿主 / 根（4 个） | 341 | 9.2% | 取决于能力注入是否落地 |

⇒ 直接受异步模型影响的是 `Jobs` 477 + Store 流程段约 380 ≈ **857 行 / 23%**。
即便全消掉，也还剩约 **2857 行**。`10 §0` 的原话可作交叉印证：
「不补下面 §4 的六个机制，Drift3 重写 watermark 仍会重新长回几千行」。

当前样板符号读数（本轮 grep，与 `10 §1` 的 11-05 快照一致，未腐烂）：
`SubjectBuilder` 29 行 / `notifyChanged` 29 次 / `NamedJob` 13 / `toArray` 9 /
`fromArray` 8 / `Closure` 16 / `JobProgress::report` 3。

### 10.2 那「8 行」草案到底省了什么

`10 §6` 的目标形状（`pickFiles` → `await(uploadBatch)` → `await(post)` → `poll(...)` →
`AsyncResource::images(...)`，约 8 行）对照本仓 770 行 Store + 5 Job + Registry +
scheduler 闭包，对比是**真的**，但要扣掉三块：

- 8 行是**编排**，不含状态广播、`when()` 四态、错误文案、控件树。本仓 Store 前半段的
  25 个 getter + 29 次 `notifyChanged`，在 Drift3 里对应 `AsyncStateView` 四个命名方法 +
  `Cell` 依赖登记 —— **是换写法，不是消失**；而 `Ui/View.php` 空标记接口说明这部分
  还没被设计成可运行形态。
- **L0 覆盖率有实测，不支持乐观值**：`probe/l0-coverage` 14/14，strict 40% /
  medium 60% / loose 80%，按 JS 行数加权 25.4% / 54.7% / 68.3%，**任何口径都不到
  90%**；`08 §5.2` 已据此把「L0 覆盖 90% 单请求」撤回。watermark 是 Drift3 扫过的
  8 个 app 里最难的样本；`apps/users`（无周期、无进度）才够 L0。
- 六机制的当前闭合度（`16 §5.2`）：周期 ✅、进度 ✅、跨进程 ✅（裁决 A「不跨进程」，
  代码面无需做）、**结果资源 🟡**（`AsyncResource` 惰性句柄，`slotId=0`，不下载不解码）、
  **能力注入 🟡**（`pickFiles` 只出现在 `AsyncContext` 注释里）、lint ✅ 但 AST 化与
  完整脏集 diff 缺。**最贵的两块恰好压在两个 🟡 上**：`DownloadResultsJob` 156 行 +
  `ResultCard` 292 行存在的唯一理由是「`Image` 不读 URL」，等资源加载器（`20 §8`
  顺位 3，且依赖顺位 2 的渲染五阶段才能取 `targetWidth`）；`WatermarkApplication`
  的闭包搬能力等 C7（`20 §3.3` 明列无实现）。

### 10.3 心智负担：从「隐性且静默」变成「显性且有牙」，不是消失

§8.4 列过本仓 8 条靠人记的隐性规则（catch 顺序 / 闭包恰好 1 参 / `time()*1000` /
`sleep()` 返回 false / `duplicated()` 只认 `===` / `localPaths` 键序 / 进度是 `await()`
第二参 / 三条消息无类型保证），共同特征是**错了不报、静默走偏**。

Drift3 的答法确实更好：U3 把 `Thread`/`Process`/`Coroutine`/`Fiber`/`cancel`/
`generation`/`unsubscribe`（+ 第八词 `try/catch`）做成 `E-ASYNC-LEAK-CTRL`，
另有 `E-AWAIT-SITE` / `E-ASYNC-READ-SHAPE` / `E-DEP-EDGE-MISSING` /
`E-LAYER-VIOLATION` / `@drift-layer` / `api-surface.json` 词汇冻结；
`13 §3` 甚至把「业务写法一个字不改、心智负担 0」写成可验收口径。
⇒ **这是真进步**，也正是 §68 已经在借、且零迁移成本的那一条（本仓 30 条 lint 规则）。

但必须同时说清两点：

- **规则总数没减少**，只是从「记在脑子里」搬进 lint 与框架生成代码。`08 §5.1` 说
  「框架自动做的 10 件事」，`20 §2.5` / `16 §3.2` 却记着：
  `setCurrentScope()` 要框架手动调、**没有「页面 build 自动绑作用域」的生成代码**；
  `->render(` 在 src 0 命中、`AsyncRuntime::deliverPayload()` 零生产调用方、
  默认 `NullChannelPort` 可数降级。业务少写的那些，本该由生成代码补上，而生成代码
  正是没接的一段。
- **迁移不是改框架，是业务代码全量重写**：`probe/api-surface-lint` 拿本仓 watermark
  只读取样 28 文件 / **27 命中**（其中 20 处业务 `try/catch`）。按 U3 的禁用词口径，
  现有写法几乎逐条要改。

### 10.4 「按 Drift3 方案重写」当前不可执行（机械证据）

- `D:\TypePHP\Drift3\src` 79 文件 / 6458 行；`Ui` **3 文件 95 行**，`View` 空标记接口；
  **没有 `apps/` 目录**，即没有任何真实应用跑在它上面。
- `20 §6` 16 维差距总表：❌ 结构性缺失 **5** 维（帧内 ⑤–⑨ 段位、渲染管线 / 脏集 /
  DrawOp、布局、输入 / 焦点 / a11y、平台抽象），🟡 设计对齐但实现空 **6** 维，
  🔬 卡证据 3 维；`20 §7` 特别点名「组件级探针全绿 ≠ 框架可用」。
- 能力面：真 `RequestTransport` 无生产实现（`20 C8`）、全量 `tpc --dry` **NOT RUN**、
  DWM 真机 **INCONCLUSIVE**、组合根默认仍是 `NullChannelPort` 降级链。
- 而本仓 watermark 是**已经能跑的 AOT 应用**（§67 恢复的 T2 环境、gate 18/18、
  golden 34/34、smoke 473）。用一套「逻辑层已验、渲染与生产接线塌陷」的框架去替换
  一个可运行应用，不是重构，是把风险从应用层搬到尚未成型的框架层。

### 10.5 推荐方向（按「减负 / 成本」排序，均在本仓内、零迁移）

1. **继续把隐性规则 lint 化**（§68 已开的通道）：把 §8.4 剩下 6 条逐条变判据。
   这是 Drift3 最值得借、且唯一「不改语义就能减心智负担」的一件，成本 = 1 规则类 +
   2 夹具 + 1 组 selftest 断言。
2. **收状态广播 / Widget 样板**：1127 行 Widgets + 29 行 `SubjectBuilder` +
   29 次 `notifyChanged` 才是代码量的**最大单一来源**。思路与 Drift3 的
   `when()` 四态 + 显式登记边同构，但可以纯在 Drift2 应用层做 helper/派生，
   不需要 `AsyncValue`、不需要换运行时。**这是回答「代码量大」的真正着力点。**
3. **跨进程序列化样板**单独立项裁决：13 `NamedJob` + 9/8 编解码 + 5 项 Registry 登记
   是 Jobs + Api 值对象的大头。Drift3 的答案是裁决 A「不跨进程，HTTP 走 `curl_multi`」，
   但本仓 worker 子进程的目的是「同步 HTTP 不卡 UI 帧」；取消它就得先有 UI 线程内
   非阻塞 HTTP（本仓无），属**独立大裁决**，不能靠「照 Drift3 重写」顺带解决。
4. **不迁、不建第二套运行时**：§9.1 的「零行代码可迁移」对本问依然成立；
   `Core < Widget < App` 与 AOT 形状约束不因换设计而免。

**重裁触发条件**（写下来，避免下棒把「现在不迁」误读成「永远不迁」）：
`20 §8` 的顺位 2（⑤–⑨ 渲染五阶段）与顺位 3（资源加载器）落地 +
`16 §5.2` 六机制全闭合 + 全量 `tpc --dry` PASS + 真机 jank 判据定标 +
Drift3 侧出现至少一个真实应用。到那时再用 `apps/users` 级小业务做迁移试验，
**仍不要拿 watermark 当第一个样本**（`19 §1` 的裁决）。
