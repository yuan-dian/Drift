# UI 线程模型与消息通知：与成熟 GUI 的对比

> 2026-10-30。起因是一个架构提问：**UI 是不是该走独立线程、用消息通知处理？**
> 本文先给五家成熟 GUI 的共同骨架，再逐条核对 Drift 的实现，最后给裁决。
>
> **结论先放**：Drift **已经**是「UI 独立线程 + 消息通知」模型，方向与五家一致，
> **不需要重构**。差距都在**参数级**（预算 / 合并 / 优先级），共四处，按性价比排序见 §6。

---

## 1. 五家的共同骨架

### 1.1 Win32（一切的参照物）

- 一个 UI 线程拥有窗口；`HWND` 有**创建线程归属**，别的线程 `SendMessage` 会被封送到它。
- 循环是 `GetMessage` → `DispatchMessage`；**没有消息就阻塞**，不轮询。
- 跨线程唯一入口是 `PostMessage`（异步，入队）/ `SendMessage`（同步，**阻塞等待** —— 死锁的经典来源）。
- 系统对低优先级消息做**合并**：`WM_MOUSEMOVE` 只保留最新位置、`WM_PAINT` 用脏区合并、
  `WM_TIMER` 不排队（到点重发）。

### 1.2 WPF

- `DispatcherObject` **线程亲和**：别的线程碰控件抛 `InvalidOperationException`（`VerifyAccess`）。
- 跨线程 `Dispatcher.BeginInvoke`（异步）/ `Invoke`（同步，**可死锁**）。
- **`DispatcherPriority` 是分级的**：`Input` > `Loaded` > `Render` > `DataBind` > `Normal` > `Background` ——
  输入永远先于后台回调被处理。
- 渲染有**独立的 render thread**：UI 线程出绘图指令，render thread 提交 D3D。

### 1.3 Qt

- `QObject` 线程亲和（`moveToThread`）；跨线程信号槽自动降级成 `QueuedConnection`（入队，非阻塞）。
- `QCoreApplication::processEvents(flags, maxTime)` —— **显式时间预算**，这是本文最想借的东西。
- `QAbstractEventDispatcher::wakeUp()` 负责叫醒阻塞的事件循环。
- `compressEvent()` 允许对同类事件做合并（Qt 内部对 deferred delete / 部分事件就这么干）。

### 1.4 Flutter

- **四线程**：`Platform`（原生）/ `UI`（Dart isolate，跑 build+layout+paint）/ `Raster`（GPU 提交）/ `IO`。
- isolate 之间**只能传消息**（`SendPort`），不共享内存 —— 想碰 UI 对象在语言层面就不可能。
- `SchedulerPhase` 把一帧切成 `transientCallbacks` → `midFrameMicrotasks` → `persistentCallbacks`
  → `postFrameCallbacks`，每段有明确的语义位置。

### 1.5 Android

- 主线程 `Looper` + `MessageQueue` + `Handler`，`Choreographer` 跟着 VSYNC 驱动帧。
- **`NetworkOnMainThreadException`**：在主线程做网络，系统**直接抛**。
  这是最有价值的一条 —— 它把「业务不许占 UI 线程」从纪律变成了**运行时断言**。

### 1.6 归纳：五条铁律

| # | 铁律 | 违反的后果 |
|---|---|---|
| 1 | **UI 单线程 + 对象线程亲和** | 跨线程改控件 ⇒ 崩溃或画面撕裂 |
| 2 | **跨线程唯一入口 = 消息队列**（异步入队，不是直接调用） | 直接调 ⇒ 重入、在半个帧里跑应用代码 |
| 3 | **业务不得在 UI 线程执行**（网络/磁盘/大计算） | 阻塞消息泵 ⇒ 窗口「未响应」 |
| 4 | **空闲时阻塞等待，不轮询** | 空转烧 CPU/电池 |
| 5 | **唤醒要合并**（闹钟只留一个） | 消息洪泛 ⇒ 空转循环 |

---

## 2. Drift 现在是什么

### 2.1 一条链路

```
            ┌──────────── UI 线程（唯一）────────────┐
 输入 ◄─────┤ EventLoop: waitEvents(wait)            │
            │   ├─ Quit  ⇒ 退                        │
            │   ├─ Timeout/Woken ⇒ 都往下跑一帧       │
            │   ├─ pumpEvents()  → 各窗输入队列        │
            │   └─ 每窗: frame()                     │
            │        ├─ pumpIo → pumpTasks → pumpSliced → pumpTimers → 动画
            │        └─ settle → layout → paint → present   ← 泵全部在此之前
            └────────────────┬───────────────────────┘
                             │ Completion 入队（业务线程只做这一件事）
            ┌────────────────┴───────────────────────┐
            │ ProcessTaskHost（worker 进程）/ ThreadTaskHost（C++ 池）
            │   └─ 干完 PostMessageW(kMsgWake) 叫醒 ◄── WakeReason::Woken
            └────────────────────────────────────────┘
```

### 2.2 逐条核对五条铁律

| 铁律 | Drift | 代码位置 |
|---|---|---|
| ① UI 单线程 + 亲和 | ✅ `assertUiThread()` 守在 `frame` / `repaintForResize` / `pumpTasks` / `pumpIo` | `PumpHost.php`、`Application.php` |
| ② 消息队列唯一入口 | ✅ 后端**只准**把 `Completion` 入队，`drain()` 只在 UI 线程帧内调 | `PumpHost.php` |
| ③ 业务不在 UI 线程 | ⚠️ 机制齐（进程/线程池），但**默认后端是 `SyncTaskHost`**（在调用者线程跑完） | `AppScope.php` |
| ④ 空闲阻塞不轮询 | ✅ `waitEvents(-1)` 无限等；`needsPolling()===false` 的后端靠 `PostMessageW` 叫醒 | `EventLoop.php:105-116`、`ThreadTaskHost.php:180-183` |
| ⑤ 唤醒合并 | ✅ `wakePump()` 用 `g_wakePosted` 做闸，取走 `kMsgWake` 时才复位 ⇒ 多次入队只压一次闹钟 | `drift_win32.cc:1219-1227`、复位点 `3222` |

★ **2026-10-31 对账**（这张表的行号与结论一起核过一遍）：① 原先还列着
`pumpSliced` / `submitSliced` —— 分片链当天删了（`ASYNC-ARCH-2026-10-31.md` §12），
两处名字与行号一并不成立；③ 的 `Application.php:470` 只是转发，**默认值真正落在
`AppScope.php`**；④ 的两处行号已漂；⑤ 的结论**从 ❌ 翻成 ✅** —— `g_wakePosted`
（`drift_win32.cc:417`）本来就是"闹钟闸"，取走消息时才复位（`3222`），
"每条事件都 `PostMessageW`"这句话在加闸之后就不成立了。

★ 这一类腐坏（行号漂、结论被后续修复推翻）**docref 查不出来**：它只判"越界"
与"文件不存在"，判不出"这一行已经不是那句话了"。⇒ 每轮动完帧循环都该顺手对一次账，
把"指向哪一行"当成断言看待。

★ ③ 是唯一「机制对、默认值错」的一条：Android 用 `NetworkOnMainThreadException` 直接抛，
   Drift 用 `SyncTaskHost` 静默跑完 —— 症状是「异步了还是卡」，方向会把人带偏（见 `ASYNC-2026-10-30.md` §4.1）。

---

## 3. 一处比成熟 GUI 更好的地方

**泵全部在 `settle()` 之前**（`Application.php:851-945`，注释里写死了「必须在 settle 之前」）。

后果：后台结果回调里 `setState()` **同帧生效**。

对比：
- **WPF**：`Dispatcher.BeginInvoke(Normal)` 的回调通常在 layout/render **之后**才跑 ⇒ 改属性只标脏 ⇒ **下一帧**才看见。
- **Flutter**：`setState()` 也是「标记 + 下一帧 rebuild」。

⇒ Drift 少一帧延迟（约 16.7ms）。这个优势不是抄来的，是「四个泵放在 settle 前」这个顺序换来的，
**重构时第一件不能动的事就是它**（注释里三条「必须在 settle 之前」都是这个）。

另一处结构性优势：**没有同步等待 API**。
`src/Async/` 与 `src/App/` 里没有 `wait()` / `await()` / `join()`（已 `grep` 确认），
⇒ WPF 的 `.Result` 死锁、Qt 的 `QThread::wait()` 阻塞 UI 这类错误**在这个框架里写不出来**。
这比「文档警告不要这么做」强得多。

---

## 4. 四处差距（按会咬人排序）

### 4.1 `pumpTasks()` 没有预算 —— 一次 drain 到底

```php
// PumpHost.php:395-416
public function pumpTasks(): void
{
    $tasks->drain(function (Completion $completion): void {
        $this->complete($completion);   // ← 没有时间上限
    });
}
```

对比 `pumpSliced()`：它有 `SLICE_BUDGET_MS = 4`（`PumpHost.php:43`），
`sliced` 那侧每帧干到 4ms 就收手。**同一个类里，一个泵有预算、另一个没有**。

真正的防线只有一条，且只防住了一种情况：

```php
// SyncTaskHost.php:94-97
// ★ swap：取快照再清空。`$onEach` 里再 `submit()` 的结果留到下一帧，
//   否则"回调里提交新任务"会让 drain 永不返回（帧预算失效）。
```

它防的是「回调里再提交」的**自我循环**，没防「一次回来 500 条」。

**什么时候会咬**：`Future::all()` 聚合 200 个请求、批量上传 300 个文件、进页面并发拉 100 个片段。
每条 `then` 回调若有解析/算 diff 的活，一帧 80ms 以上 ⇒ 明显掉帧，且**症状是「偶发卡顿」**，极难归因。

**解法**（借 Qt 的 `processEvents(flags, maxTime)`）：给 `drain` 一个 `budgetMs`，
超预算就留到下一帧。机制自洽 —— 剩下的还在队列里 ⇒ `pending()` 仍 > 0 ⇒ 帧时钟继续跳，
不会睡死。

#### 4.1.1 落地形态（2026-10-30，已实施）

签名 `drain(callable $onEach, ?int $budgetMs = null)`，`null` = 一次取空（只给测试与
"结算前最后冲刷"用），`PumpHost` 永远传正数（`TASK_BUDGET_MS = 4`，与
`SLICE_BUDGET_MS` 同量级 —— 两个泵吃的是同一帧的 16.7ms）。

★ **预算是"契约下推"，泵不计时**：这与 `pumpSliced()` → `SlicedJob::step(int $budgetMs)`
是**同一个范式**（`PumpHost` 里至今没有任何计时器）。⇒ 帧预算只有一套写法，
没有第二个时间闸。

★ 三个后端的**剩余载体不同**，这是这一段唯一不能统一的地方：

| 后端 | 超预算后剩余放在哪 | 为什么不能照抄 |
|---|---|---|
| `SyncTaskHost` | 放回 `$this->queue` 的**队头** | 队尾会让"新提交的抢先交付"，`Table` 分页会"翻到第 2 页看到第 1 页" |
| `ProcessTaskHost` | 留在 `$worker['buffer']` + 管道里 | 按 `\n` 切行，停在任何位置都不会切坏多字节；管道天然保序 |
| `ThreadTaskHost` | 存 `$this->backlog` | `drift_io_poll()` 是**取走**语义，原生侧没有"放回去"的接口 |

★ **契约③ 比预算本身更容易写错**：`ProcessTaskHost` 的 `drain()` 是四段
（`failed → readReplies → reap → flush`），预算只约束"交付回调"，**簿记必须照跑**：

- 跳过 `flush()` ⇒ 排队任务永远发不出去 ⇒ 页面永远转圈；
- 跳过 `reap()` ⇒ 死 worker 名下的在途任务永不结算 ⇒ 同样永挂。

`readReplies()` 里还有一条更容易踩的：**stderr 必须无条件读**（在预算检查之前）。
不读的话管道满了 worker 会阻塞在 warning 上 —— 那是**真死锁**，代价远大于
"这一帧少交几条结果"。

★ **`ThreadTaskHost` 必须是动态的 `needsPolling()`**：它有积压时若仍返回 `false`，
`hasPolledAsync()` ⇒ `false` ⇒ 帧时钟给 `-1` ⇒ 无限期阻塞等一个**不会来的**唤醒
（原生池认为自己已经交付完了）⇒ 积压永远交不出去。现在 `needsPolling()` 返回
`$this->backlog !== []`：正常态照旧零轮询，有积压时以帧节奏回来续交。

★ 测试：`tests/Unit/App/DrainBudgetTest.php`（5 条）+ `tools/smoke.php` 的
「⑦ 一帧交付有预算」量具（500 条真实 `Completion`）。量具用**条数**判据而非耗时
（CI 机器上毫秒会抖），三条断言恰好覆盖三个契约，其中
「每帧至少推进一条」把 `$i > 0` 那条从"不可证"变成"可证"。

### 4.2 唤醒消息不合并

```cpp
// drift_win32.cc:1217-1221
g_queue.push_back(event);
if (hwnd != NULL) {
    // 把阻塞在 GetMessageW 上的泵叫醒。载荷在队列里，这条消息只是闹钟。
    PostMessageW(hwnd, kMsgWake, 0, 0);
}
```

`enqueue()` 对 `kEvResize` 做了合并（`1211` 那条注释解释了为什么），
但**闹钟本身没合并**：1000 条事件 ⇒ 1000 条 `kMsgWake`。
第一次 `pumpEvents()` 就把队列取空了，剩下 999 条消息唤醒的是「队列已经空了的循环」——
每条都要走一遍 `waitEvents` 返回 → `pumpEvents` → 逐窗 `frame()`，
帧不脏时虽不重绘，但 `FrameClock` 记账、阶段埋点照付。

**解法**：一个 `g_wake_posted` 布尔，投过一次就置位，`pumpEvents()` 取空后清位。
Win32 自己对 `WM_PAINT`/`WM_MOUSEMOVE` 就是这么干的（§1.1 第四条）。

#### 4.2.1 落地形态（2026-10-30，已实施）—— **上面那条解法是不完整的**

★ 实施时发现：**只加一个布尔会挂死**。原因是现行 `php_drift_win_pump()` 隐含一个
前提 —— "N 条事件必配 N 条 `kMsgWake`"。它的循环只在 `GetMessageW` 返回之后才看
队列（`if (!g_queue.empty()) { front; erase; return; }`）⇒ 一旦闹钟被合并成 1 条，
第一次泵取走 1 条事件后，剩下的 N-1 条就**再也没有消息来叫醒**，`GetMessageW`
阻塞在它们身上（现象：界面冻住，事件却明明还在队列里，且不报错）。

所以 P2 是**两处一起改**，缺一不可：

1. **`pump()` 开头先查队列**：`if (!g_queue.empty()) { 取一条; return; }`
   —— 让"队列"成为真相，"消息"退化成纯闹钟。这一条单独就值：某个窗口的闹钟投失败
   （窗口已销毁 / 消息队列满）不再能冻住事件交付。
2. **闹钟合并**：`wakePump(hwnd)` 统一五处投递点，`g_wakePosted` 为真就不再投。
   **复位点在 `GetMessageW` 拿到 `kMsgWake` 时**（不是"队列取空后"）——
   那条消息离开系统队列的那一刻，就是"可以再投一条"的时刻。

★ 还有一个失败路径必须写对：`PostMessageW` 返回 0 时（窗口已销毁 / 消息队列满）
**不能置位**。置了位就再也不投 ⇒ 队列里的事件永远没人来取。不置位才能下次重试。

★ 顺带被这条改动修正的一个隐患：`GetMessageW` 返回 `WM_QUIT` 时，改造前会
**丢掉队列里剩下的积压**直接返回 quit；改造后队列非空的判据排在最前，积压先交付、
再 quit。

### 4.3 没有优先级

Drift 的固定顺序是 `io → tasks → sliced → timers → 动画 → settle`。
WPF 有 `DispatcherPriority`、Flutter 有 `SchedulerPhase`。

**但现在不建议做**：优先级要解决的问题是「后台回调饿死输入」，
而 Drift 的输入走的是**独立队列**（`$platform->input($handle)` 在 `frame()` 之前就 poll 完了），
本来就不跟泵抢。**没有消费者就不发明**（与 §4.5「没有进度」同一条纪律）。

真要有一天做，落点也在 `pumpTasks` 的 drain 循环里（先看输入队列非空就提前收手），
不是造一个通用的优先级队列。

### 4.4 默认后端是同步的

`Application::taskHost()` 未装配时给 `SyncTaskHost` ⇒ 慢任务在 UI 线程跑完。
这条已经在 `ASYNC-2026-10-30.md` §4.1 / §11.2 记过，此处只补一句架构视角的对照：

**Android 的选择是对的**：`NetworkOnMainThreadException` 让错误**响亮**。
Drift 的选择（永远可用的后端）让错误**安静**。
两者都有道理（框架不能让「没装配」直接崩），但代价必须写在**调用点的注释里** ——
`submit()` 的 docblock 已经写了（`Application.php`），这是当前能做到的最好形态。

---

## 5. 裁决：不重构

| 候选重构 | 判断 |
|---|---|
| 改成「每窗口一个 UI 线程」 | **否**。Win32 本身就是单 UI 线程多窗口；多线程跑帧会让 `AppScope` 的共享件（字体缓存、主题、加速键表）全部要加锁 |
| 把 UI 挪到独立线程（业务跑主线程） | **否**。方向反了：GUI 的 UI 必须独占一个线程，业务才是该挪走的那个（已经是了） |
| 引入通用消息总线 / 事件循环抽象 | **否**。现在是 `Completion` 队列 + 五个泵，语义清楚；抽象成「消息」会丢掉「回调必须在 settle 前」这个约束 |
| 加优先级 | **暂缓**（§4.3，无消费者） |
| **给 drain 加预算** | **是 → 已做**（§4.1.1）。★ 成本估计错了：不是"3 行 + 一个常量"，三个后端的**剩余载体**各不相同（队头 / 管道 buffer / 本地 backlog），实际是 4 个文件 + 三处契约 |
| **合并唤醒消息** | **是 → 已做**（§4.2.1）。★ 同样估错了：只加一个布尔**会挂死**，必须同时让"队列非空"成为 `pump()` 的最高优先级判据 |

**一句话**：骨架与五家一致，差距是参数不是结构。重构只会把已经对的东西重新发明一遍，
而**风险全在「泵必须在 settle 之前」这条看不见的约束上**。

★ 两次成本估计都偏小，偏差的来源是同一个：**只看了"加什么"，没看"谁是真相"**。
drain 的真相是"剩余放在哪"（三个后端各不同），唤醒的真相是"队列还是消息"
（现行代码把消息当成了真相，所以合并会挂）。

---

## 6. 优化清单

| 优先级 | 做什么 | 落点 | 成本 | 判据 | 状态 |
|---|---|---|---|---|---|
| P1 | `drain(callable, ?int $budgetMs)`，超预算留到下帧 | `TaskHost` + 三个后端 + `PumpHost` | 中（`@api` 破坏性变更） | 500 条 Completion，断言分多帧且每帧推进 | **已完成**（`DrainBudgetTest` 5 条 + smoke 量具） |
| P2 | `kMsgWake` 合并 + `pump()` 先查队列 | `drift_win32.cc`：`wakePump()` / `g_wakePosted` / `php_drift_win_pump` | 小 | 投 1000 条事件，`waitEvents` 返回次数 ≪ 1000 | **已完成**（判据**未实测**，见 §7） |
| P3 | 默认后端策略：**用时才报**诊断 | `SyncTaskHost::submit()` | 小 | 80ms 任务报一条、1ms 任务不报 | **已完成**（`SyncTaskHostSlowTaskTest`） |
| P4 | 优先级 | — | — | **不做**（无消费者） | 不做 |

★ P3 的落点从"启动期警告"改成了"**慢任务时**报一条 DIAGNOSTIC"。理由：启动期警告
对每个没装后端的应用都白报一次，而其中大多数根本没跑过慢任务 —— 噪音多了就被无视。
判据换成**事实**（真跑了 50ms ≈ 连掉三帧），与 Android 的
`NetworkOnMainThreadException` 同一个思路：用时才说，不装时不说。
★ 只报一次（`$slowReported`）：刷屏的诊断等于没有诊断。

### 6.1 P1 的破坏面（已落地的清单）

`TaskHost::drain()` 是 `@api`，加参数打破了三处实现：

- `src/App/SyncTaskHost.php` / `ProcessTaskHost.php` / `ThreadTaskHost.php`（正牌后端）
- `tools/smoke.php`（两处匿名夹具）、`tests/Feature/App/ApplicationSubmitTest.php`（一处）

匿名夹具忽略预算（它们只是 `pending()` / `needsPolling()` 的替身），这是可以的 ——
契约约束的是**真后端**。

★ P1 是**破坏性接口变更**（`TaskHost` 是 `@api`），三个后端 + smoke 夹具都要跟。
  按项目纪律，动它之前要先单独出方案等裁决。

---

## 7. 这一份没做的事

- ~~**没测「一次 drain 500 条」的实际掉帧**~~ → **已补量具**（2026-10-30）：
  `tools/smoke.php` 的「⑦ 一帧交付有预算」真跑 500 条 `Completion`。
  ★ 但它量的是**分帧是否发生**，不是"改造前后掉帧差多少"（那要在真机上用
  `SurfaceProfile` 抓 60fps 抖动，没有做）。
- **没评估「多窗口共享 UI 线程」的帧公平性**。`EventLoop` 是逐窗串行跑帧
  （`$apps` 循环），窗口多了会有「谁先谁后」的偏斜，但 M1 阶段窗口数 ≤ 2，没有消费者。
- **P2 的判据仍未实测**：改是改了（`wakePump` + `g_wake_posted` + `pump()` 先查队列），
  但"投 1000 条事件后 `waitEvents` 返回几次"**没有计数器**。今天能拿出的证据只有
  代码论证 + 编译通过 + 真机不崩。要真验，得在 `Win32Platform::waitEvents` 里加一个
  计数（`$this->wakeCount++`），驱动一次高频 resize 后读它 —— 这一步没做。
- **`ThreadTaskHost` 的新路径（`backlog` / 动态 `needsPolling()`）没有运行时测试**。
  它依赖 AOT 产出的 `drift_io_poll()`，解释器下没有这个函数 ⇒ `phpunit` 与 `smoke`
  都覆盖不到，只有 lint 与语法检查。这是本轮改动里**验证最薄**的一块。
