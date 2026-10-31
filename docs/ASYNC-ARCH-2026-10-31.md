# 异步架构复审（2026-10-31）：执行体、协程、拓扑

> 前六轮（[`ASYNC-DX-2026-10-30.md`](ASYNC-DX-2026-10-30.md) §5–§12）都是"沿一个症状
> 往下挖"。这一册换方向：**从架构层问三个问题** ——
> ① 执行体（线程 / 进程 / 协程）选对了吗？② 业务该怎么用？③ 有没有整维的空白？
>
> 结论先说：**骨架与成熟 GUI 同形，不需要重构**；但**"结果拓扑"这一维只有一个形状**，
> 而执行体只有一条路真正端到端跑通 —— 这两处才是缺口，其余都是补丁级。

---

## 0. 一句话

**本仓有一个成熟的"单值异步"实现，以及一个尚未被消费的"多执行体"底座。**
前者够用且干净（状态 / 旧值 / 去重 / 取消四维对齐 Riverpod），后者造好了接口和
C++ 线程池，却只注册了两个量具 op —— 于是**所有真实异步都被迫走最贵的那条路**
（子进程池，单次 spawn 275ms）。协程在这套形状里**不是缺口，是 worker 的内部实现**。

---

## 1. 现状：五层 + 四执行体 + 三形态

### 1.1 五层（横向：一次请求的一生）

| 层 | 类型 | 唯一职责 | 代码量 |
|---|---|---|---|
| 结果 | `Future` | 组合（`then`/`catch`/`all`/`race`/摊平/取消上溯） | 594 行 |
| 状态 | `AsyncValue` | 四态 + 载荷（**两维**：状态与"有没有值"分开） | 369 行 |
| 生命周期 | `AsyncCommand` | 发起 / 去重 / 作废旧请求 / 重试 / 广播 | 355 行 |
| 执行 | `TaskHost` ×3 | 同步队列 / 子进程池 / C++ IO 池 | 1237 行 |
| 时序 | `PumpHost` + `FrameClock` | 帧内交付 + 每帧预算 + 唤醒判据 | 488 + — |

★ 这一层是**收敛过的**：`LoadStatus`、`FutureBuilder`、`applyPage()` 三套平行实现
已在前几轮删净（§11.1 已核）。**没有第二份实现**是这一层最值钱的属性。

### 1.2 四执行体（纵向：谁干活）

| 形态 | 实现 | 真实能力 | 有业务消费者吗 |
|---|---|---|---|
| **A1 同线程切片** | ~~`curl_multi` 每帧推进~~ | **判死**：解释器收尾必崩，进不了闸门 | — |
| **A2 C++ IO 线程** | `ThreadTaskHost` + `cpp-src/core/io_pool.cc` | 池体、唤醒、队列、零空转**全部就绪** | **否**（op 表只有 `sleep` / `echo` 两个量具） |
| **A3 进程** | `ProcessTaskHost` | 跑同一份 AOT 代码、能真杀、崩溃隔离 | **是**（`apps/users` · `apps/showcase` 都是它） |
| **A4 协程** | 探针验过（`probes/eventloop_design/` §13.2） | `Co\Http\Client` + tpc 类共处一类表 —— **可行** | **否**（无范式、无接入） |

★ 这张表是本册最重要的发现：**四态里只有一格有真实业务，而它是四格里最贵的一格。**

### 1.3 三形态（结果拓扑：一次异步交回什么）

| 形态 | 类型支持 | 消费者 |
|---|---|---|
| **单值**（一次请求 → 一个结果） | `AsyncValue` + `AsyncCommand` | ✅ 两个应用 |
| **多值并发**（N 个请求 → 一组结果） | `Future::all` / `race` | ❌ 零件在，**零业务** |
| **流**（持续推送 → 序列） | **无**（`src/Async/` 只有 2 个类） | ❌ **整维空白** |

★ 低层的"流"其实有零件：`IoPoller::watch($stream, $forRead, $cb)` 就是流式的
原始形态。缺的**不是能力，是状态层的那一半** —— 没有 `StreamValue` /
`AsyncStream`，于是"进度、日志、WebSocket、无限滚动"这类需求在状态层无处落脚。

---

## 2. 成熟 GUI 的架构（补上"执行体轴"）

§1.1 of [`EVENT-LOOP-DESIGN-2026-10-30.md`](EVENT-LOOP-DESIGN-2026-10-30.md) 已经比过
等待原语与唤醒通道。这里补**执行体**这一轴 —— 也就是"谁干活、活在哪"：

| 框架 | UI 线程 | 后台执行体 | 协程? | 结果形状 |
|---|---|---|---|---|
| **Flutter** | 单线程 event loop（不等 fd） | Isolate 池 / 平台线程（插件） | Dart `async/await`（**单 event loop 内的状态机**） | `Future` / `Stream` |
| **Qt** | `MsgWaitForMultipleObjectsEx` | `QThreadPool` + 网络线程 | ✗（信号槽） | 信号槽（多值原生） |
| **WPF / .NET** | Dispatcher | `ThreadPool` + IOCP | `async/await`（`SynchronizationContext` 回主线程） | `Task` / `IAsyncEnumerable` / `IObservable` |
| **Chromium** | `MessagePumpForUI` | IO 线程（IOCP）+ compositor | ✗ | `PostTask` 回调 |
| **SwiftUI** | `MainActor` | Swift concurrency 池 | ✅ **语言级**（有 runtime executor） | `AsyncSequence` |
| **GTK** | GLib main loop | `GTask` 线程池 | ✗ | 信号（多值原生） |
| **Godot** | `MessageQueue` | 线程 + `call_deferred` | ✗ | 信号 |
| **本仓** | `waitEvents` + pump | A2 / A3 / A4（**只有 A3 有业务**） | ✗（AOT 禁 `Fiber`/`Generator`） | **只有单值**（`AsyncValue`） |

**读法三条**：

1. **没有一家让业务代码在 UI 线程上协程化**，除了 SwiftUI —— 而 Swift 有语言级
   `MainActor` + runtime executor。这是**语言能力**，不是框架能补的。
   ⇒ 本仓"回调 + 状态"，与 Flutter / Qt / WPF / Chromium **同形**。
2. **除本仓外，每一家的结果形状都不止一种**：Flutter 有 `Stream`，WPF 有
   `IAsyncEnumerable` / `IObservable`，Qt/GTK/Godot 有信号。**只有本仓把"多值"
   交给了底层（`Future::all` 与 `IoPoller`），状态层没有对应物。**
3. 执行体的**数量**不是指标，"每一格有没有消费者"才是。Chromium 三线程各有明确
   负载（UI / IO / raster）；**本仓的 A2 池今天没有负载** —— 这不是设计错，是
   "基础设施先于消费者"的正常阶段，但必须记账（否则下一轮会以为它已经能用）。

---

## 3. 协程：三个卖点，两个已解，一个属别处

用户问"是否可以使用协程、是否需要协程"。先给**硬约束**，再逐条对账。

### 3.1 硬约束（不是偏好，是编译链事实）

| 约束 | 证据 | 后果 |
|---|---|---|
| **AOT 产物禁用 `Fiber` / `Generator`** | `tools/Lint/Rules/NanoHygiene.php` 的 `BANNED_TYPES`，理由注释写明"C++17 无栈切换，无法承载" | **UI 进程里写不出协程** —— 不是不想，是编译目标不支持 |
| **Swoole 协程要吃掉主线程控制流** | `probes/RESULTS.md` §12.3（[J3-a]）+ [`EVENT-LOOP-DESIGN-2026-10-30.md`](EVENT-LOOP-DESIGN-2026-10-30.md) §1.2 | `Coroutine\run()` 与 `GetMessageW` 抢同一根控制流；Windows 版**没有** `Swoole\Event` 可手动 pump |
| **开 hook 是全局替换** | 同上：非协程上下文的 `stream_select` / `fread` 直接抛 `Error` | 一开 hook，`IoPoller` 立刻报废 |
| **`curl_multi` 与 swoole 不能同产物** | `probes/RESULTS.md` §13.2 追记 | 一个产物只能选一个，且崩在**退出阶段**（不影响运行期，所以难查） |

★ 四条合起来：**UI 进程侧协程这条路是封死的**，而且封得有据可查（不是"没人做"）。

### 3.2 协程到底解决什么，逐条对账

协程相对回调的卖点只有三个，逐个看本仓有没有：

| 卖点 | 协程怎么写 | 本仓现状 | 判定 |
|---|---|---|---|
| **① 并发 join** | `gather([...])` 或 `await Future.wait` | `Future::all()`（同键同序、失败即整条失败并取消其余） | ✅ **已解，且更灵活**（`all` 能继续 `then` 派生，协程的 `gather` 不能） |
| **② 取消传播** | 结构化并发（`Coroutine::cancel` + 父子树） | `cancel()` 沿派生链上溯 + 聚合体带 `onCancel`（§12） | ✅ **已解**，且落到"真停"（`dropQueued` / `proc_terminate`），比"只是不交付"强 |
| **③ 多步顺序代码** | `$a = await f(); $b = await g($a);` | **无**。要么嵌套 `then`，要么在 `$task` 里手拼 `Future::all` | ❌ **唯一真缺口** |

⇒ **协程的三个卖点，两个本仓已经用别的东西解决了，只剩"多步顺序"这一个。**

### 3.3 那"多步顺序"该不该用协程补？

关键问题是**它发生在哪一侧**：

```
业务代码（页面 State）        打 5 个接口拼一个看板
        │
        ├─ 选项 1：UI 侧协程  ──  ✗ 被封死（§3.1）+ 语义危险
        └─ 选项 2：worker 侧协程 ─ ✅ 可行，且业务面零变化
```

**为什么选项 1 不只是"做不到"，而且是"不该做"**：

UI 侧一旦有 `await`，业务代码就会写出这种形状：

```php
$user = await $this->store->user($id);        // ← 挂起期间帧还在跑
$this->name = $user->name;                     // ← 恢复时这个 State 可能已经 unmount
```

这正是所有 GUI 框架都要防的"**恢复后世界已变**"问题（Compose 用 `LaunchedEffect`
+ `rememberCoroutineScope` 自动取消，Swift 用 `Task.isCancelled`）。本仓的答案
本来是**结构性的**：`AsyncCommand` 在 `unmount()` 时被框架停掉 ⇒ 迟到的结果
永不结算 ⇒ 这整个失败模式**不存在**。**引入协程等于把这个安全属性换回去。**

**为什么选项 2 是对的**（这是本节的核心建议）：

```php
// 业务侧：仍是"提交一个任务"，零变化
$host->submit(new FetchDashboardJob($id));

// 任务侧（跑在 worker 进程里）：协程只是 run() 的内部实现
final class FetchDashboardJob extends NamedJob
{
    public function run(): array
    {
        // worker 没有窗口消息、没有 IoPoller、没有帧循环
        // ⇒ 控制器本来就归它，`Coroutine\run()` 不与任何人抢
        \Swoole\Coroutine\run(function (): void {
            $chan = new \Swoole\Coroutine\Channel(3);
            foreach (['/profile', '/orders', '/stats'] as $path) {
                \Swoole\Coroutine::create(function () use ($path, $chan): void {
                    $cli = new \Swoole\Coroutine\Http\Client('127.0.0.1', 8099);
                    $cli->get($path);          // ← 挂起，不占线程
                    $chan->push([$path, $cli->body]);
                });
            }
            // 3 个请求总耗时 = max(3 条)，写起来是顺序的
        });

        return $this->merged;
    }
}
```

⇒ **裁决：协程在本仓的定位是 `Job::run()` 的实现细节，不是业务 API。**
收益一条不少（顺序写法、连接复用、TLS、超时由 `Co\Http\Client` 自带），
代价只有一条：worker 进程要多带一份 swoole 运行时（且与 `curl_multi` 互斥）。

### 3.4 那"多步依赖"在 UI 侧怎么写才对？

协程不是唯一答案。本仓已有的零件能组合出**两种**写法，各有适用面：

| 写法 | 形状 | 适用 | 代价 |
|---|---|---|---|
| **`$task` 内部组合**（推荐） | `fn () => $api->user($id)->then(fn ($u) => $this->orders($u['id']))->then(...)` | 多步依赖的**唯一正确位置** | 步骤多了嵌套变深（但仍是**一条** `AsyncCommand`，取消/去重/状态全都继承） |
| **两次 `run()`**（不推荐） | 第一个命令 `data` 后手写 `$second->run($id)` | 两步且第二步**可独立展示**时 | 两个命令 = 两份状态、两次 loading、**两次请求之间的状态不确定**（第一步成功、第二步失败 ⇒ 半个页面） |

★ 第一条的关键性质：**多步发生在 `$task` 内部，所以界面上看到的仍然"一次加载"**
（一个 `loading` → 一个 `data`）。这正是协程在 worker 里做的事，只是写法不如
`await` 直。**"写起来不如协程"和"架构缺协程"是两件事** —— 前者是语法糖，
后者是能力。本仓缺的是前者。

---

## 4. 业务怎么用：五种形态，只有一种跑通

### 4.1 单请求（✅ 已跑通，两个应用都在用）

```php
// ① 造（initState 里一次）
$this->rows = $this->asyncCommand($this->fetchRows);   // \Closure(int): Future

// ② 发（进页面 / 翻页 / 刷新）
$this->rows->run($page);

// ③ 画（fold 四支，漏一支当场抛）
$value->fold([
    'idle'    => fn () => Text::make('尚未请求'),
    'loading' => fn () => Spinner::make(),          // 只覆盖"首屏"
    'error'   => fn ($v) => Text::make($v->message())->danger(),
    'data'    => fn ($v) => $this->table($v->value()),
]);
```

这条链上每一环都验过：去重（同参数在途忽略）、作废旧请求（换参数即 `cancel()`）、
旧值保留（`carriedFrom`）、卸载兜底（`unmount` 统一 stop）。**这是本仓的成熟面。**

### 4.2 多请求并发（零件在、**零业务**）

`Future::all` 有 12 条单测、有取消传播、有失败不吞的判据 —— 但**没有一个应用用它**。

```php
$this->dashboard = $this->asyncCommand(fn (int $id): Future => Future::all([
    'profile' => $host->submit(new ProfileJob($id)),
    'orders'  => $host->submit(new OrdersJob($id)),
]));
```

★ 风险：**一条没有消费者的路径，它的正确性只由测试保证，不由使用保证。**
第十一轮若改 `Future`，`all` 的回归只有单测拦得住（smoke 只有 `allerr` 那条退出码）。
这不紧急，但值得记账。

### 4.3 多步依赖（**空白**）

见 §3.4。今天只能靠 `$task` 内部嵌套 `then` —— 能写，但**仓库里没有一个例子**，
所以"这样写对不对"没有可参照的正确答案。这是文档缺口，不是能力缺口。

### 4.4 流式（**整维空白**）

| 需求 | 今天只能怎么做 | 缺什么 |
|---|---|---|
| 进度（0–100%） | 任务内部不能回贴，只能"最后一次性给 100%" | `TaskHost` 没有 `submitStream()`；`Job` 没有 `progress()` 通道 |
| 日志 / 实时输出 | 无路（`LogPage` 是静态文本） | 同上 |
| WebSocket / SSE | 无路 | `IoPoller` 能读，但读到的字节没有"状态层落点" |
| 无限滚动 | 每页一次 `run($page)` + 手工拼接 | 勉强可行，但 `carriedFrom` 会**用新页替换旧页**（不是追加） |

★★ **最后一行是一个真缺陷**（不是"缺能力"，是"现有语义会做错"）：

```php
// 无限滚动：第 2 页到了，`carriedFrom()` 让 data 变成"第 2 页的 20 行"
// ⇒ 界面从"前 20 行"变成"第 21–40 行"，前 20 行**消失**
$this->items->run($this->page);
```

`AsyncValue` 的两维模型（状态 × 有没有值）默认"新值取代旧值"，这对**翻页**是对的
（`keepPreviousData`），对**追加**是错的。今天要写无限滚动，只能绕开命令
（自己维护 `$rows` 数组 + 在 `then` 里 `array_merge`）。

⇒ 这是 §11.7 那条"换实体请用另一个命令实例"的**同族问题**：
`carriedFrom()` 是"同一命令内总是保留旧值"，它没有"**合并**"这个概念。

### 4.5 分片计算（零件在、**零消费者**）

`SlicedJob` 有契约、有预算、有 5 条冒烟 —— 但**没有一个页面用它**。
`BigListPage` 的一万行走的是**虚拟化**（`LazyColumn` 只造视口内的十几行），
根本不需要分片。所以它今天是一把没有对应锁的钥匙。

★ 老实说：这个形态**可能是多余的**。它的设计目标是"过滤一万行 / 排序 / 解析大
JSON"—— 而这三种在今天的应用里分别是：本地过滤（`users` 的搜索，几百条，同步）、
无排序 UI、无大 JSON。**按本仓纪律"未被消费的分支 = 未实现"，`SlicedJob` 应
记为"未实现"，而不是"已支持"。**

---

## 5. 五个缺口（按性价比排序）

| # | 缺口 | 症状 | 补的成本 | 收益 |
|---|---|---|---|---|
| **①** | **`ProcessTaskHost` 无预热** | 进页面首条任务等 **275ms**（spawn）+ 116ms（attach），用户看到的是"点了没反应" | **10 行**（`prewarm(int $n)`）+ 1 条判据 | 首屏从"275ms 空白"变成"0 空白" |
| **②** | **A2 池无真实 op** | 想取一个 URL，只能起一个完整 AOT 进程（几十 MB） | 1 个 C++ 分支（`libcurl`）+ 1 个桥函数 + 1 个 `NativeTaskHost` | "轻 HTTP"从 275ms 降到 **~50µs**；内存从几十 MB 降到 0 |
| **③** | **无"追加"语义** | 无限滚动会**丢掉前面的行**（§4.4 最后一行） | `AsyncValue::mergedWith()` 或 `AsyncCommand::merge()` | 修的是一个**会做错事**的语义，不是缺功能 |
| **④** | **多步依赖无范式** | 能写但没例子，写法靠猜 | 一个 showcase 页 + 一段文档 | 消除"猜"的成本 |
| **⑤** | **`ProcessTaskHost` 轮询而非唤醒** | 1.2s 任务 = **80 次空唤醒**（Thread 版已修成 0 次） | 大（要把 spawn 挪到 C++ 侧才拿得到 HANDLE） | 只省 CPU / 功耗；不影响正确性 |

★ 顺序的理由：①②是"**让正确的路变便宜**"，③是"**修一个会算错的地方**"，
④是文档，⑤是优化且成本最高。

**为什么 ① 排第一**：它不是新能力，是**兑现已有承诺**。`ProcessTaskHost` 的类注释
写着"懒启动 + 常驻：需要时才起，之后所有任务复用它" —— 但"第一次"总是要付
275ms。预热只是把这一次挪到 `attach()` 之后、用户点之前（应用启动那几百毫秒
本来就没人看）。

---

## 6. DX 对照：同一件事，八家怎么写

| 需求 | Flutter | Qt | .NET WPF | 本仓 |
|---|---|---|---|---|
| 单请求 + 转圈 | `FutureBuilder`（`snapshot.connectionState`） | `QNetworkReply::finished` + 手工状态 | `await` + `IsBusy` 属性 | `asyncCommand` + `fold()` |
| 旧值保留 | ✗（换 future 就丢） | 手写 | 手写 | ✅ 自动（`carriedFrom`） |
| 并发 join | `Future.wait` | `QFuture::waitForFinished` | `Task.WhenAll` | `Future::all` |
| 多步依赖 | `await` 顺序写 | 嵌套 lambda / 状态机 | `await` 顺序写 | **`$task` 内嵌套 `then`**（无例子） |
| 进度 | `Stream` + `StreamBuilder` | 信号 | `IProgress<T>` | **无** |
| 取消 | `CancelableOperation`（只不交付） | `QNetworkReply::abort()` | `CancellationToken` | ✅ `cancel()` → **真停**（摘队列 / 杀 worker） |
| 超时 | `Future.timeout`（**客户端**，停不掉活） | `QNetworkRequest::setTransferTimeout` | `CancellationTokenSource.CancelAfter` | ✅ **后端墙钟**（`requestTerminateMs`） |
| 重试 | ✗ | 手写 | Polly | 手动 `retry()` |
| 卸载自动停 | `State.dispose` 手写 | 父子对象树 | `CancellationTokenSource` 手写 | ✅ **框架兜底**（`unmount`） |
| 换后端 | 改 `compute` / `Isolate.run` | 改线程池 | 改 `TaskScheduler` | ✅ **组合根一行**（`setTaskHost`） |
| 分片计算 | `compute`（真另起 isolate） | `QtConcurrent::run` | `Task.Run` | `SlicedJob`（**同线程切片**，零消费者） |

★ 三处本仓**更强**（不是"类似"，是更强）：
1. **取消真能停** —— Flutter 的 `CancelableOperation` 只是"不要结果了"，HTTP 照跑；
   本仓的 `dropQueued()` / `proc_terminate()` 是真的不干活。
2. **超时在后端** —— 客户端计时停在"我不等了"，worker 还在烧 CPU；本仓是真的杀。
3. **卸载自动停** —— 前两家要手写 `dispose`，漏了就是"离开页面后 worker 白跑"。

★ 三处本仓**更弱**：
1. **没有流** —— 进度 / 日志 / 推送整维空白。
2. **多步没有范式** —— `await` 一行 vs 嵌套 `then` 三行。
3. **默认后端会卡 UI** —— `SyncTaskHost::submit()` **在调用栈上跑完 job**；
   Flutter 的 `Future(...)` 至少是"下一个 microtask"。★ 缓解设施已经有了：
   超过 50ms 报一条 `DIAGNOSTIC` 并指路（`setTaskHost(new ProcessTaskHost(...))`），
   但**这是"事后报警"，不是"事前不卡"**。

---

## 7. 裁决

### 7.1 架构合理，不需要重构

三条依据：

1. **形状与主流同形**：单 UI 线程 + 后台执行体 + 投递回 UI + 唤醒通道 —— Flutter /
   Qt / WPF / Chromium 全是这个形状，且三条铁律本仓都守（UI 不做阻塞 IO、
   跨上下文只投递、有唤醒通道）。
2. **五层各一份**，没有平行实现 —— 这是前六轮收敛的成果，复审确认没退化。
3. **状态模型是两维**（状态 × 有没有值），与 Riverpod `copyWithPrevious` /
   React Query `keepPreviousData` 同一个判断，不是自创。

### 7.2 需要补的只有三件（其余记账，不动）

| 优先级 | 做什么 | 为什么现在做 | 为什么不做更多 |
|---|---|---|---|
| **P1** | `ProcessTaskHost::prewarm()` | 10 行换掉首屏 275ms 空白，零风险 | — |
| **P2** | 无限滚动的"追加"语义 | 今天会**算错**（丢前面的行） | 只加一个 `merge()`，不加 `Stream` |
| **P3** | 多步依赖的范文（`$task` 内组合） | 消除"写法靠猜" | 只写文档 + 一个例子，不加 API |

### 7.3 明确**不做**的（附理由，避免下一轮重新论证）

| 项 | 理由 |
|---|---|
| **UI 侧协程 / `async`·`await`** | AOT 禁 `Fiber`/`Generator`（C++17 无栈切换）；Swoole 抢主线程控制权；且它会把"卸载安全"换回"恢复后世界已变"（§3.3） |
| **给业务暴露 `Coroutine\run()`** | 业务面不该知道 worker 怎么跑。协程是 `Job::run()` 的实现细节 |
| **`StreamValue` / `AsyncStream`** | 零消费者。按纪律"未消费的分支 = 未实现"；真有进度需求时，先在 A2 加 op（②），形状那时再定 |
| **A1（`curl_multi` 每帧推进）复活** | 已判死（解释器收尾必崩，进不了闸门） |
| **`SlicedJob` 重构** | 它没错，只是没消费者。等真需求（大 JSON 解析）来了再说；现在动它是在给不存在的场景优化 |
| **`run()` 返回 `?Future`** | §11.7 已裁决：返回的是会被后续 `run()` 取消的那条 ⇒ `then` 可能永不触发 |
| **自动重试 / 缓存 / `staleTime`** | 策略属业务；缓存键策略是 TanStack Query 那一层的课题 |
| **A2 的进程句柄唤醒**（缺口 ⑤） | 要拿 `HANDLE` 就得把 spawn 挪到 C++ 侧，等于重写 `ProcessTaskHost`；收益只是省 CPU |

---

## 8. 一条该写进纪律的隐含决策

复审里发现一处**没被文档化的规则**：命令住在哪？

| 住哪 | 例子 | 判据 |
|---|---|---|
| 页面 `State` | `AsyncTablePage`（分页表格） | 数据**只属于这一页**，离开就该丢 |
| 应用 `Store` | `UserStore`（`subscribeFor`） | 数据**跨页共享**，或页面重建后不该重取 |

今天这条规则靠直觉。两种住法的**生命周期差异是硬的**：

- `State::asyncCommand()` ⇒ `unmount` 时框架 `dispose()` ⇒ **离开页面，请求就停**
  （对，因为没人要了）；
- `Store` + `subscribeFor` ⇒ 生命周期 = 应用 ⇒ **离开页面，请求照跑**（对，因为
  下一个页面还要它）。

★ 判据可以压成一句：**"如果离开这个页面后，这个请求还该跑完吗？"**
该 ⇒ Store；不该 ⇒ State。这一句应该进 `State::asyncCommand()` 的注释。

---

## 9. 本轮验证与边界

| 项 | 结果 |
|---|---|
| 静态复审范围 | `src/Async/*`（2 个类）· `src/State/AsyncCommand.php` · `src/App/{TaskHost,SyncTaskHost,ThreadTaskHost,ProcessTaskHost,PumpHost,CompletionQueue,Job,SlicedJob}.php` · `src/App/Io/IoPoller.php` · `src/Widget/{State,StatefulElement}.php` · `apps/{users,showcase}` 全部异步消费点 |
| 交叉核对 | `docs/ASYNC-DX-2026-10-30.md` §5–§12 · `docs/EVENT-LOOP-DESIGN-2026-10-30.md` §1–§9 · `docs/ASYNC-2026-10-30.md` §1–§2.5 · `probes/RESULTS.md` §12.3 / §13.2（**本地目录，不随仓库分发**，结论已内联在本册 §3.1） |
| 代码改动 | **本轮零代码改动**（这是复审，不是实施；三件待补见 §7.2） |

★ 诚实边界：本册的"成熟框架怎么写"来自 §1.1 那张已经核过的表（源码 / 官方文档），
本轮的增量是**执行体轴**与**结果形状轴**两列 —— 这两列里 Flutter 的 `Stream`、
WPF 的 `IAsyncEnumerable`、Qt 的信号是**框架文档级事实**，不是本仓实测。
本册**没有**新的真机数据（没有跑 `users.exe`、没有量 spawn 耗时 —— 275ms 沿用
`probes/RESULTS.md` §12 ⑧ 的旧数）。
