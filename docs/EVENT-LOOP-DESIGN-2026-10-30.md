# 事件循环与异步：重设计（2026-10-30）

> 本册是**设计**，不是评估。它回答「该建成什么样」，而不是「现在哪里疼」。
> 现状诊断见 [`EVENT-LOOP-2026-10-30.md`](EVENT-LOOP-2026-10-30.md)、
> [`ASYNC-2026-10-30.md`](ASYNC-2026-10-30.md)、
> [`MULTI-WINDOW-2026-10-30.md`](MULTI-WINDOW-2026-10-30.md)。
>
> 按用户授权，本册**允许破坏兼容性**，也**允许重头设计**。
> 所有「不确定就不要假设」的地方，都先写了探针（§2 是实测，不是推断）。

---

## 0. 一句话

**把「等」和「画」彻底解耦，把「IO」赶出 UI 线程 —— 这两件事七个成熟框架都做了，
只是它们的「赶出去」是线程，Drift2 的可以是 C++ 线程或进程。**

具体三个决定：

1. **UI 线程永远不做阻塞 IO**，也不做「等 fd」—— 它只等「消息 + 唤醒」。
2. **`pump()` 拆成 `waitEvents(timeout)` + `pumpEvents()`**，并引入
   `ControlFlow{Poll, Wait, WaitUntil}` 三态 —— 现在恒等于 `WaitUntil(now+15.6ms)`，
   这是退化的轮询。
3. **异步 API 从「回调」升级到 `Future`**，因为「同时请求多个接口」用回调写不出来。

---

## 1. 七个成熟框架怎么做的

调研是必须的：这一节每个单元格都来自源码或官方文档，不是印象。

### 1.1 对照表

| 框架 | 等待原语 | 唤醒通道 | IO 在哪 | 异步结果怎么回 UI |
|---|---|---|---|---|
| **Qt / Win32** | `MsgWaitForMultipleObjectsEx(handles, timeout, QS_ALLINPUT)` | `PostMessage(internalHwnd, WM_QT_SENDPOSTEDEVENTS)` | 独立线程；socket 用 `WSAEventSelect` 转成 `WSAEVENT` 后进等待集合 | `QMetaObject::invokeMethod(Qt::QueuedConnection)` |
| **Chromium** | 三个 pump：`GetMessage`（UI）· `WaitForSingleObject`（Default）· **IOCP**（IO） | `PostMessage(kMsgHaveWork)` / `PostQueuedCompletionStatus` / `Signal` | **独立 IO 线程**（完成端口） | `PostTask(BrowserThread::UI)` |
| **GLib / GTK** | `poll()`（Unix）/ `WaitForMultipleObjectsEx`（Win） | 内部 wakeup pipe | 独立线程 + `GSource` | `g_main_context_invoke` |
| **Cocoa** | `CFRunLoopRunInMode(mode, timeout, true)` | `CFRunLoopWakeUp` | 独立线程 | `dispatch_async(dispatch_get_main_queue())` |
| **Flutter** | 单线程 event loop，**不等 fd** | 平台消息 + 任务队列 | C++ 引擎的 IO thread / raster thread | microtask 队列（`async/await` 状态机） |
| **winit / iced** | `ControlFlow::{Poll, Wait, WaitUntil}` + `EventLoopProxy` | `Proxy::send_event` → 事件循环被唤醒 | 独立线程 / tokio | `UserEvent`，主循环里处理 |
| **WPF** | `Dispatcher` 消息循环 | `BeginInvoke` | 线程池 | `SynchronizationContext.Post` |

### 1.2 三条铁律（七个全部遵守，无例外）

1. **UI 线程绝不做阻塞 IO。** Chromium 的文档把这条写成红线：不仅不能阻塞 UI 线程，
   **连 IO 线程也不能阻塞**，否则 IPC 消息不流动。
2. **跨上下文只「投递」，不共享可变状态 + 锁。** Chromium 明说 "We discourage locking
   and threadsafe objects"；Flutter 的 isolate 干脆不共享堆。
3. **必须有一个唤醒通道。** 后台完成工作时，要能**打断** UI 线程的等待。
   这是 `PostMessage(kMsgHaveWork)` / `PostQueuedCompletionStatus` / `CFRunLoopWakeUp` /
   `EventLoopProxy::send_event` / GLib 内部 wakeup pipe 的**同一个东西**。

> 铁律 3 最容易被漏掉，但它才是「零轮询」的全部秘密：
> 没有唤醒通道，等待就只能是「等一小会儿再问一次」，也就是轮询。

### 1.3 一条分化：等待原语要不要知道 fd

七个框架分两派：

| 派 | 成员 | 特征 |
|---|---|---|
| **等 fd 派** | GLib · Qt · Cocoa · Chromium 的 IO pump | 等待原语能接收 fd / handle 集合，IO 就绪直接变成事件 |
| **不等 fd 派** | **Flutter · WPF · winit** | 等待原语只知道「消息 / 任务」，IO 完全在别处，回来只是一个「任务」 |

**「不等 fd」这一派是后来的，也是更主流的。** 原因：把「等 fd」这件事推给 IO 层，
UI 线程的心智模型就只剩两件事 —— **等消息、跑任务**。Chromium 也在 2010 年代把
「IO 线程 + IOCP」独立出去，正是这个方向。

### 1.4 这个分化直接决定 Drift2 的选型

本仓**拿不到 SOCKET / HANDLE**：

- PHP 用户态拿不到（`(int)$stream` 是资源 id，不是 fd）；
- C 扩展层能拿（`php_stream_cast`），但**桥函数收不到 resource**
  —— `docs/design-v7/08-平台与能力矩阵.md` §9.6 纪律 1：「参数只有标量 + array」，
  `cpp-src/drift_win32.stub.php` 里 40 个桥函数没有一个收 resource。

⇒ **「等 fd 派」在 Drift2 里从根上做不了**。
⇒ 只能走「**不等 fd 派**」：UI 线程只等「窗口消息 + 唤醒通道」，IO 在别的执行体。

**这不是退让，而是选到了更现代的那一派。** 而且它把一个死结（拿不到 SOCKET）
变成了不需要解的问题 —— 只要 IO 不由 UI 线程发起，就永远不需要 UI 线程认识 socket。

---

## 2. 探针：五个不确定点的实测答案

按「不确定就先验」的纪律，本轮写了两个探针（`probes/coroutine_win32/` 重跑 +
`probes/eventloop_design/` 新建）。**下面每一个数字都是跑出来的。**

★ 读法说明：`probes/` 是**本地目录**（`.gitignore:19` 的 `/probes` 忽略它），
探针代码与 `probes/RESULTS.md` 都**不在版本库里**。所以本节把**原始输出与判读
全部内联**，不依赖任何外部文件 —— 你只看这一册就够。

### 2.1 P1 —— 消息泵能不能写成协程（重跑 `probes/coroutine_win32/`）

这个探针 10-18 就写好了，但**从没记过结论**（`probes/RESULTS.md` 只记到探针 L）。
补跑：

```
[N0] php=8.5.11 swoole=yes zts=yes
[N1] hwnd=40775386 alive=yes
[N2] enableCoroutine=true
[N3] ticks=111 avgMs=16.12 maxGapMs=59.21 longGaps(>50ms)=1 ioDoneAt=1271.2ms body=slow-ok
[N4] destroyed alive=no
```

判读：一个 **1.2s 的慢 HTTP** 期间，UI 循环 tick 了 **111 次**，平均间隔 **16.12ms**，
**只有 1 次超过 50ms（59.21ms）**。慢请求正常返回（`body=slow-ok`，`ioDoneAt=1271ms`），
窗口干净销毁。

⇒ **「把消息泵写成一个协程」可行**。

★ 这不是「推翻」[`ASYNC-2026-10-30.md`](ASYNC-2026-10-30.md) §1.2，
而是**把它那条「能做，但未验」变成「已验」**。那里判断「互斥的真正来源是控制权，
不是 hook，也不是卡 IO」是**对的**；它同时列了唯一可行的融合方式
（「把 `frame()` 搬进 `Coroutine\run()`，消息泵改成 `PeekMessage` 非阻塞 +
`Co::sleep(0)` 让出」）并明确标为「未验」。本探针验的就是这一条。

★ 用词纪律：**「未验」与「不成立」是两件事**。上一轮若把它读成「定理」，
那是读法的问题，不是原文的问题。

★ 那 1 次 59ms 尖峰没定位（可能是首个请求的连接建立 + DLL 延迟加载）。
按本仓纪律，**没定位的尖峰不许写成「已解决」**，记为已知行为。

### 2.2 P2 —— AOT 下 `curl_multi` 能不能用（`probes/eventloop_design/`）

```
[E2]  n=5 ok=5 elapsedMs=623 serialWouldBe=3000ms spins=11 verdict=CONCURRENT
[E2b] ticks=20001 avgTickUs=12.2 worstTickMs=2.1 verdict=UI-SAFE
```

- **E2**：5 条各 600ms 的请求，总耗时 **623ms**。串行应 3000ms。
  ⇒ `curl_multi_*` 在 AOT 产物里**完全可用，且真并发**。
- **E2b**：同样的 5 条，改成「每轮 `exec` + `select(0)` 推进」，
  单轮平均 **12.2µs**、最坏 **2.1ms** —— 远小于 16.7ms 的帧预算。

⇒ **两件事同时成立**：
① 「同时请求多个接口」**不需要线程、不需要协程、不需要进程**，`curl_multi` 就够；
② 它**可以在 UI 线程做**，只要切成「每帧推进」，而不是「等它完成」。

★ E2b 的 `doneAtMs=-1` 是探针的判据 bug（`$running` 归零的时机没抓到），
不是能力问题 —— 20 轮上限撞到了，说明循环退出判据写错了。
**结论只取 `avgTickUs`/`worstTickMs` 这两个量**，它们不受该 bug 影响。

### 2.3 P3 —— 开 hook 之后 `stream_select(..., 0, 0)` 还合法吗

这是本仓 `IoPoller::poll()` 的命门（它靠 0 超时做到「每帧问一句就走」）。

```
[E3] rounds=100 okRounds=100 avgUs=65.04 worstUs=6318.09
```

⇒ **在 hook 开启、且身处协程上下文时，`stream_select(0,0)` 100 次全部成功**，
平均 **65µs**，最坏 **6.3ms**（一次尖峰，疑为协程调度或 GC）。

★ 这一条要说得非常准，因为它极容易被读错成「推翻」。原文
（[`ASYNC-2026-10-30.md`](ASYNC-2026-10-30.md) 第 58 行）写的是：
> 「任何**非协程上下文**的 `stream_select` / `fread` 都抛
> `API must be called in the coroutine`（实测 [J3-a]）」

**这句话是准确的，不需要修。** 探针补的是它的**另一半** ——
「协程上下文里不抛」。这一半 10-18 那轮没测，所以此前只能推断。

★ 需要在这里留痕的一点：上一轮讨论中我曾把整句说成「hook 会打坏 `IoPoller`」，
那是**过强的表述**（把「非协程上下文」丢掉了）。它**从未写进任何文档**，
本册不追认、也不把它当作原文的错。这类「口头说过、文档没写」的偏差，
正好是 §7.2「留什么」要防的东西 —— **文档是唯一事实源**。

### 2.4 P4 —— 协程里能不能调 tpc 编译的函数与类

这是 [`ASYNC-2026-10-30.md`](ASYNC-2026-10-30.md) **§2.4** 里标为
「一个仍然悬着的关键未知」的那条**硬前提**。

```
[M5] tpc-fn=42 expect=42
[M6] tpc-class=7 expect=7
[M7] inner=12 total=12 expect=12/12
[M8] wallet=650 expect=650
```

四项全对：全局函数、`new` 类、子协程 + 闭包捕获对象、**跨实例读 private**
（AOT 老坑的同类形态）。

⇒ **协程与 tpc 编译产物共处同一类表、同一堆，完全可用。**
这一条打通之后，§3.2 的 A4 才成立。

### 2.5 P5 —— 一条必须记下的工程约束：`curl_multi` 与 swoole 同产物会崩

两个 pass 的**唯一**差别是 ini 里有没有 `extension=swoole`：

| pass | 结果 |
|---|---|
| 无 swoole | `[E5] done` —— 干净退出 |
| 有 swoole | 所有判据都打印完，**退出阶段 Segmentation fault** |

换顺序也崩（先 `curl_multi` 再 `enableCoroutine` ⇒ 崩得更早）。
唯一不崩的组合是 Probe N 那种 —— 用 `Co\Http\Client`，**不碰 `curl_multi`**。

⇒ **`curl_multi` 与 swoole 不能共存于同一个 AOT 产物**（`swoole=6.3.0RC1`）。
运行期不受影响，崩在清理阶段。

这不是本册要解决的问题，但它**约束了选型**：A1（UI 线程切片，用 `curl_multi`）
与 A4（协程，用 swoole）**互斥**，一个产物里只能选一个。见 §3.2。

### 2.6 汇总

| 编号 | 问题 | 答案 | 影响 |
|---|---|---|---|
| P1 | 消息泵写成协程可行吗 | ✅ 1.2s IO 期间 111 ticks，maxGap 59ms | 把「能做但未验」升为「已验」 |
| P2 | `curl_multi` 在 AOT 下可用吗 | ✅ 5×600ms → 623ms | 「多接口并发」零依赖可解 |
| P2b | 每帧推进单轮多重 | ✅ avg 12.2µs / worst 2.1ms | UI 线程可做小规模并发 |
| P3 | hook 下 `select(0)` 合法吗 | ✅ 100/100，avg 65µs | 补齐「协程上下文不抛」这一半 |
| P4 | 协程能调 tpc 类吗 | ✅ 四项全对 | 协程方案的硬前提解除 |
| P5 | `curl_multi` + swoole | ❌ 退出段错误 | A1 与 A4 互斥 |

---

## 3. 设计

### 3.1 形状：一个循环，两把轴

```
┌───────────────────────────── UI 线程（唯一有窗口消息的线程） ─────────────────────────────┐
│                                                                                          │
│   while (true) {                                    ← 这是「循环」，只有一份               │
│       $why = $platform->waitEvents($this->nextDeadline());   ← 等（可被唤醒打断）          │
│       $platform->pumpEvents();                        ← 派发 OS 事件                       │
│       $this->inbox->drain();                          ← 跑跨上下文投递来的任务            │
│       $this->pumps->pumpAll($now);                    ← 定时器 / 任务 / 切片               │
│       if ($this->needsFrame()) { $this->frame($now); } ← 只在需要时画                      │
│   }                                                                                      │
│                                                                                          │
│   ★ 五个阶段各有独立的「该不该做」判据 —— 现在的病就是第五个没有。                          │
└──────────────────────────────────────────────────────────────────────────────────────────┘
        ▲ 唤醒（SetEvent / PostMessage / pipe 写一字节）
        │ 结果（队列 / JSON / 共享内存）
┌───────┴──────────────────────────────────────────────────────────────────────────────────┐
│ 执行体（按任务选，见轴 A）：C++ IO 线程 · 进程池 · 协程 · 同线程切片                        │
└──────────────────────────────────────────────────────────────────────────────────────────┘
```

**两把轴是正交的**：

- **轴 A · 执行体**：谁干活（A1 切片 / A2 C++ 线程 / A3 进程 / A4 协程）
- **轴 B · 等待与派发**：怎么回到 UI（B1 `waitEvents` / B2 `ControlFlow` / B3 `Inbox` / B4 唤醒）

任何组合都成立。这是本设计的关键性质：**加一种执行体不需要改等待层，反之亦然。**

### 3.2 轴 A：执行体

| 形态 | 实现 | 适用 | 现状 | 真机风险 |
|---|---|---|---|---|
| **A1 同线程切片** | `curl_multi` 每帧推进（P2b：12µs/轮） | ~~少量并发 HTTP~~ | **判为不可行**（§11：解释器收尾必崩，进不了闸门） | — |
| **A2 C++ IO 线程** | 原生线程 + libcurl / IOCP，结果入队列 | 中大规模 IO、需要取消/进度 | **未建**（§5 评估） | 中 |
| **A3 进程** | `ProcessTaskHost` | CPU 密集、崩溃隔离、跑第三方 | ✅ 已落地 | 无 |
| **A4 协程** | `Coroutine\run` + `Co\Http\Client` | 多步流水线（取 A → 取 B → 合并） | 零件在，无范式 | 低（P3/P4 已验） |

**选择顺序（决策树）**：

```
任务是什么？
├─ 纯 HTTP 拉取，数量可控，< 1s 量级  →  A1（零开销，不跨执行体）
├─ 需要真正的后台吞吐 / 取消 / 进度    →  A2（C++ 线程）
├─ 重 CPU（解析大 JSON、图像处理）    →  A3（进程，崩了不传染）
└─ 多步 IO 流水线                     →  A4（协程，写法最直）
```

★ **A1 与 A4 互斥**（P5）：同一产物里要么 `curl_multi` 要么 swoole。
默认选 A1，因为它是零依赖。

### 3.3 轴 A2 详设：C++ IO 线程（本轮新增的推荐形态）

这就是用户提的「启动多个 C++ 线程，把底层库放到 C++ 线程里跑，PHP 只管业务逻辑，
投递数据给 C++ 线程」。**它正是 Chromium 的 IO 线程模型**（§1.1 第二行）。

```
PHP 侧（业务逻辑）                    C++ 侧（IO 在原生层）
─────────────────                    ──────────────────────────────
IoDriver::submit('{...}')  ──────▶   线程池队列（mutex + condvar）
                                           │
                                           ├─ 线程 1: curl_multi / IOCP
                                           ├─ 线程 2: 文件读写
                                           └─ 线程 3: 长轮询
                                           │
                                     完成队列（mutex）
                                           │
                                          SetEvent(wakeEvent)  ← 铁律 3
                                           │
IoDriver::poll(): string   ◀──────   drain 完成队列（JSON 数组）
```

**为什么这个方案能绕开本仓所有已知死结**：

| 死结（[`ASYNC-2026-10-30.md`](ASYNC-2026-10-30.md) / [`EVENT-LOOP-2026-10-30.md`](EVENT-LOOP-2026-10-30.md)） | 为什么 A2 不受影响 |
|---|---|
| PHP 线程 SIGSEGV（缺 `php_request_cache`） | **C++ 线程不进 Zend VM**，不碰 PHP 的任何线程局部状态 |
| Swoole 线程看不到 tpc 编译的类 | 同上：C++ 线程不执行 PHP 代码 |
| 拿不到 SOCKET / HANDLE | **socket 由 C++ 创建并持有**，PHP 侧只传 URL 字符串 |
| `MsgWait` 要 SOCKET 才能等 | 不需要 —— 线程用 `SetEvent` 唤醒，event 是**可等待对象** |
| `curl_multi` 与 swoole 冲突（P5） | A2 不加载 swoole，也不在 PHP 侧用 curl |

**C++ 线程 vs 进程的取舍**：

| 维度 | C++ 线程（A2） | 进程（A3） |
|---|---|---|
| 启动成本 | 线程创建 ~50µs | spawn **275ms**（`probes/RESULTS.md:668`） |
| 内存 | 共享地址空间，一份运行时 | 每个 worker 一份 AOT 运行时（几十 MB） |
| 数据传递 | 队列 + JSON（零拷贝可做） | 管道 + 序列化 |
| 崩溃影响 | **传染主进程** | 隔离 |
| 能否跑 PHP 业务 | **不能**（只能跑 C++） | 能（同一份 AOT 产物） |

⇒ **A2 适合「IO 密集」，A3 适合「CPU 密集或会崩的代码」**。两者不是替代关系。

### 3.4 轴 B1：`waitEvents(timeout)` 取代 `pump()`

★ **这不是本册的新发明** —— `docs/design-v7/08-平台与能力矩阵.md` §9.7 早就裁决过
「`pumpEvents` 与 `waitEvents` 分离」，理由写得很清楚：

| 方法 | 语义 | 谁需要 |
|---|---|---|
| `pumpEvents()` | 非阻塞，取走当前事件 | 移动端（能 poll 不能阻塞）、有帧要画时 |
| `waitEvents(float $timeoutMs)` | 阻塞到有事件或超时 | 桌面（无帧可画时省 CPU） |

原文还有一句直接支持本册的取舍：
> 「v7 第一版只有一个阻塞 `pump()` ⇒ **移动端没法用**。拆开后桌面仍是
> `waitEvents(∞)` 的单循环，**行为不变**。」

⇒ 这正是 §9.7 已经预见的路径；本册初稿据此写了「`pump()` 保留为委托」，
**落地时按用户裁决改成直接删除**（理由见 §7.2 的 2026-10-30 修订）。

现状 `Platform::pump(): bool` 把「等」「派发」「是否该退出」揉在一个调用里，
且**没有超时参数**（`GetMessageW` 无限阻塞）。这是所有病的根。

```php
interface Platform
{
    /**
     * 等待「一条 OS 消息」或「一次唤醒」，最多 $timeoutMs。
     *
     * ★ $timeoutMs 三态：
     *    -1.0  无限等（无事可做时，等价于今天的 pump()）
     *     0.0  不等，只探（下一帧就要画时）
     *     > 0  等到点（有定时任务时）
     *
     * ★ 返回**为什么醒**，而不是「是否继续」——「是否退出」是另一个问题，
     *   归 pumpEvents() 的事件处理。这一拆分是多窗口的前置（退出要按窗口算）。
     */
    public function waitEvents(float $timeoutMs): WakeReason;

    /**
     * 把当前已就绪的事件全部归一化并投给 input()，返回条数。
     * 非阻塞。**不等待**。
     */
    public function pumpEvents(): int;

    /**
     * 从任意上下文唤醒 UI 线程的等待（铁律 3）。
     * 跨进程调用也成立：Win32 走 PostMessage，Unix 走 pipe 写一字节。
     */
    public function wake(): void;
}
```

`WakeReason`：

```php
enum WakeReason
{
    case Message;      // 有 OS 消息
    case Woken;        // 被 wake() 打断（后台完成了）
    case Timeout;      // 超时（该画下一帧了）
    case Quit;         // 收到退出
}
```

★★ **`pump()` 已删除，不留委托**（2026-10-30 落地时改判，见 §7.2）。
初稿写的是「保留为 `waitEvents(-1.0)` 的委托，老组合根零改动」—— 那条在
**技术上**成立（语义确实是无损特例），但它留下一个"看起来还能用"的入口，
而那个入口必然退回恒定心跳（没有超时就无法自适应）。用户的原话是：
「能不保留 2 套的就直接一套新的机制即可，不然后续维护不方便」。
⇒ 四个组合根各改一行（当时是 `EventLoop::run($platform, $app, $handle)`；
2026-10-30 M1-b 之后是 `EventLoop::run($platform, [$handle => $app])`），删掉
`pump()` / `pace()` / `tick()` / `stop()` 四处旧 API，全仓只剩一条路径。

★ **不破 §9.6 规则 4**（「只有 `fileDialog` 一个自泵方法」）：
`waitEvents` 是**主循环的入口**，不是桥函数，也不在桥内部被调用。
桥里仍只有 `fileDialog` 会自泵，`inDialog` 防重入照旧。

### 3.5 轴 B2：`ControlFlow` 三态

这是本轮从 winit 0.30 借来的最有价值的一条。**现在 Drift2 永远等价于
`WaitUntil(now + 15.6ms)`** —— 因为帧定时器在有 IO watcher 时恒常驻。
那是「有节奏的轮询」，不是「事件驱动」。

```php
enum ControlFlow
{
    case Poll;                          // 有事要做，下一轮立刻再跑（动画中）
    case Wait;                          // 无事可做，无限等（直到消息或唤醒）
    case WaitUntil;                     // 有定时任务，等到最早的 deadline
}
```

组合根变成：

```php
while (true) {
    $why = $platform->waitEvents($app->nextTimeoutMs());   // 由 ControlFlow 算出来
    if ($why === WakeReason::Quit) {
        break;
    }
    $platform->pumpEvents();
    $app->tick($platform->now());                          // inbox + timers + frame
}
```

`Application::nextTimeoutMs()` 的实现就是三态的分派：

```php
public function nextTimeoutMs(): float
{
    if ($this->animating) {
        return 0.0;                        // Poll：动画中，别等
    }
    $deadline = $this->pumps->nextDeadline();   // 定时器 / 切片任务
    if ($deadline === null) {
        return -1.0;                       // Wait：真的没事，无限等
    }
    return \max(0.0, $deadline - $this->now());
}
```

★ **收益一句话**：今天「有 IO watcher ⇒ 15.6ms 定时器常驻 ⇒ 每帧无条件重绘
⇒ 22% CPU」。三态之后，「有 watcher 但没数据」走 `Wait`，**CPU 归零**。

### 3.6 轴 B3：`Inbox` —— 跨上下文投递

铁律 2 的落地物。任何执行体（C++ 线程回调、worker 进程消息、定时器）都往这里投，
**UI 线程只在这里取**。

```php
final class Inbox
{
    /** 任意上下文可调；内部会调 Platform::wake() 打断 UI 的等待 */
    public function post(\Closure $task, int $windowId = 0): void;

    /** 只在 UI 线程调；返回执行条数 */
    public function drain(): int;
}
```

三条纪律：

1. **`post()` 里不许做 IO、不许抛异常** —— 它可能在任意上下文被调。
2. **`drain()` 必须限时限量**（比如一次最多 64 条 / 4ms），否则一个疯狂投递的后台
   会把一帧拖死。这是 Chromium `PostTask` + 任务优先级存在的理由。
3. **`windowId = 0` 表示「应用级」**（退出、切主题），非 0 表示绑到某个窗口。
   这是多窗口的作用域二分（AppScope / WindowScope）在投递层的投影，见
   [`MULTI-WINDOW-2026-10-30.md`](MULTI-WINDOW-2026-10-30.md)。

### 3.7 轴 B4：唤醒通道

**形态按平台不同，语义相同**（§1.2 铁律 3）：

| 平台 | 实现 | 跨进程可用 |
|---|---|---|
| Windows | 一个 `HWND_MESSAGE` 隐藏窗口 + `PostMessage(hwnd, WM_APP+1)` | ✅ 同 integrity level |
| Unix（fd 型） | `pipe()` + 写一字节；读端进 `poll()` | ✅ 命名 FIFO |
| macOS | `CFRunLoopSourceSignal` + `CFRunLoopWakeUp` | ✅ `dispatch_source` |

Windows 侧的落地是**最小的 C++ 改动**：

```cpp
// 建窗时顺手建一个 message-only 窗口（不进任务栏、不收输入）
static HWND g_wakeWnd = NULL;
g_wakeWnd = CreateWindowExW(0, L"DriftWakeClass", L"", 0, 0, 0, 0, 0,
                            HWND_MESSAGE, NULL, hinst, NULL);

// 桥函数（一个就够）
bool drift_win_wake(int64_t hwnd) {
    return PostMessageW((HWND)(intptr_t)hwnd, WM_APP + 0x51, 0, 0) != 0;
}
```

然后 `waitEvents()` 用 `MsgWaitForMultipleObjectsEx(0, NULL, timeout, QS_ALLINPUT, ...)`
—— 注意是 **0 个 handle**，因为唤醒走消息，不走 handle。这正是 Flutter/WPF 那一派的形状。

### 3.8 前置：泵帧分离（**已于 2026-10-30 落地**）

> 本节初稿写的方案是「加一个 `needsPaint` 脏位，来源 `Subject::notifyChanged()`」。
> **落地前分析推翻了它** —— 见下面的「四条静默通道」，实现改成了另一条。保留这段
> 推翻过程，因为它比结论更有用。

**顺序不可反**（[`EVENT-LOOP-2026-10-30.md`](EVENT-LOOP-2026-10-30.md) §7.1 已裁）。
`Frame::run` 三段跳过判据不对称：

| 阶段 | 跳过判据 |
|---|---|
| `settle` | ✅ `needsSettle()` |
| `layout` | ✅ `didRebuild` / `boxEpoch` / `constraintsChanged` |
| **`paint` + `present`** | ❌ **无条件** |

#### 为什么不能用 `notifyChanged()`

`markDirty()` 覆盖的只是**重建**那条路。全仓有一整族通道**刻意**绕过重建，
而且它们全都写着"因为 paint 无条件跑，所以不重排也不会画面停住"：

| 通道 | 改的是什么 | 为什么不重建 | 靠什么上屏（改前） |
|---|---|---|---|
| `setCompose()` | 合成 alpha / 平移 | 动画每帧写，重建太贵（`05` §10 #9） | 无条件 paint |
| `setScrollOffset()` | 子盒 `offset` | 重排整棵树 3.65ms（`RenderScrollView` 2026-10-27） | 无条件 paint |
| `setBarOpacity()` | 滚动条淡出 | 同上 | 无条件 paint |
| `tickAnimations()` | hover 过渡进度 | 动画住**盒**上，没有 `State` | 无条件 paint |
| `setBase()` | 浮层落点 | `placeOverlays()` 在 `Frame::run` **之外**跑 | 无条件 paint |

再加一条**根本不在树里**的：焦点环（`afterPaint` 回调）与"面板落后一帧"
（`panelsLagBehind()`）—— `wantsFrame()` 的第六 / 第七判据专门为它们存在，
都是"不经过 `setState` 也要画"。

⇒ 把门控建在 `markDirty()` 上，**这六条同时静默失效**：滚动不动、动画静止、
滚动条不淡出、hover 无过渡、浮层不跟锚点、焦点环消失。全部不报错。

#### 落地方案：脏位住渲染树，写点收口在 setter

```php
// RenderObject —— 与 RenderBox::$layoutEpoch 同构：两个序号、两个含义、互不代偿
private static int $paintEpoch = 0;
public static function markNeedsPaint(): void { self::$paintEpoch++; }
public static function paintEpoch(): int { return self::$paintEpoch; }
```

写点只有五个（都在**改字段的那一行旁边**，不依赖上游记得调）：

`RenderBox::setOffset()` / `setBase()` / `applyCompose()`、`RenderScrollView::setBarOpacity()` /
`RenderTable::setBarOpacity()`、`RenderObject::tickAnimations()`。全部**先比值再标**
（`setCompose` 的 `applyCompose()` 本来就有早退）。

`Frame::run` 的判据四条，每条挡一类静默：

```php
$paintEpoch = RenderObject::paintEpoch();   // 必须在 layout 之后读（布局期 setOffset 会推它）
if (!$layoutRan && $paintEpoch === $this->lastPaintEpoch && !$paintRequested) {
    return false;
}
```

| # | 判据 | 挡的是什么 |
|---|---|---|
| ① | `$layoutRan` | 几何变了没重画；顺带覆盖首帧（`!laidOut` ⇒ `constraintsChanged()` 必真） |
| ② | `$paintEpoch` 变 | 上面那五条"不重建不重排"的通道 |
| ③ | `$paintRequested` | 焦点环 / 面板落后 —— **根本不经过树**，只能由 `Application` 说进来 |
| ④ | 初值 `-1` | 首帧（防御性：① 已覆盖，但读序号这条不依赖 ① 将来不变） |

★ **`paint` 抛异常时不记账**（`$painted === false` ⇒ 序号不动）⇒ 下一帧还会画。
失败后把序号记成"已画" = 从此静默停在残缺画面上，正是 G2 明令禁止的半静默。
★ `Application` 侧配套：`$paintedFocus` **只在真画之后**更新，且记的是"跑帧前那个环目标"
（跑帧期间焦点又变了 ⇒ 新目标还没画 ⇒ 判据保持为真 ⇒ 下一帧自然还请求）。

#### 收益实测

`php tools/frame-paint-probe.php`（320×240，静态树）：

| 场景 | paint | skip |
|---|---|---|
| 空闲 60 帧 | **0** | 60 |
| 换根后 1 帧 | 1 | 0 |
| 动画 10 帧（`setCompose`） | 10 | 0 |
| 同值反复写 10 帧 | **0** | 10 |

即：**空闲窗口的整帧 blit 从 64 次/秒降到 0 次**（493MB/s → 0）。后三组是对照组 ——
没有它们，"永远不画"也能让第一组好看。

★ **为什么必须先做它**：三态之后 `Wait` 会让唤醒变稀疏，但**动画期仍然是
`Poll`（每帧都跑）**。如果在 paint 门控之前就把等待改成 `WaitUntil(4ms)`，
唤醒频率从 64Hz 变 250Hz，每帧都跑完整 `Frame::run` ⇒ **CPU 从 22% 涨到约 85%**。

### 3.9 `Future`：异步 API 的形态

这是「易用性」的核心，也是所有框架的分水岭。

三种写法，同一件事（同时取 3 个接口）：

```php
// ① 回调（现状）
$pending = 3;
$all = [];
foreach ($urls as $i => $url) {
    $this->tasks()->submit(new FetchJob($url), function ($r) use (&$all, &$pending, $i, $done) {
        $all[$i] = $r;
        if (--$pending === 0) { $done($all); }
    });
}
// 嵌套两层就要靠 $pending 手工计数，三层就写不动了

// ② Future（建议）
Future::all([$api->get('/users'), $api->get('/orders'), $api->get('/stats')])
    ->then(fn(array $all) => $this->view->setData($all));

// ③ 协程（A4，可选）
$all = Coroutine::run(fn() => [$api->get('/u'), $api->get('/o'), $api->get('/s')]);
```

**①的问题是结构性的**：它不是「写起来啰嗦」，而是**「组合」这个操作不存在**。
`all` / `race` / `then` 在回调世界里都要手工计数，而手工计数就是 bug 温床。

`Future` 的接口（纯 PHP，零真机，不依赖任何扩展）：

```php
interface Future
{
    public function then(\Closure $onFulfilled): Future;
    public function catch(\Closure $onRejected): Future;
    public function cancel(): void;
    public function isDone(): bool;

    /** 全部成功才算成功；总耗时 ≈ max（这是「同时请求多个接口」的语义） */
    public static function all(array $futures): Future;

    /** 第一个完成的赢 */
    public static function race(array $futures): Future;
}
```

★ **`Future::all` 与 Flutter 的 `Future.wait` / .NET 的 `Task.WhenAll` 语义对齐** ——
用户从任何 GUI 框架过来都是零学习成本。

★ **`Future` 不必等于新机制**：`TaskHost::submit()` 返回的 `Job` 可以直接实现 `Future`
（`Job` 已经有 `onDone`），`all()` 在 A1 上就是「一个 `curl_multi` handle 里挂 N 个请求」。

### 3.10 扩展点：四个缝，每个只有一个契约

判断架构好坏最省事的办法：**数一数「加一样东西要改几处」**。

| 缝 | 契约（框架侧，窄） | 加一个要改的 | **不该改的** |
|---|---|---|---|
| **新平台** | `Platform`：`waitEvents` / `pumpEvents` / `wake` / `now` + 六个子端口 | 一个 `XxxPlatform` 类 + 组合根一行 | `src/` 其余全部、任何页面 / 控件 |
| **新执行体** | `TaskHost::submit(): Future` 或 `IoDriver` | 一个 Host 类 + 组合根一行 | 等待层、`Inbox`、`Future`、UI 代码 |
| **新异步范式** | `Future`：`then` / `catch` / `cancel` / `isDone` | 一个实现类 | 所有调用方（只依赖 `then`） |
| **新事件类型** | `InputSource::push(InputEvent)` + `InputRouter` 的命中 / 冒泡 | 一个事件类 + 一条路由分支 | 等待层、`Frame`、控件（除声明者） |

四条缝的共同形状：**契约在框架侧（窄），实现在后端侧（宽）**。
这是 `Platform` docblock 里那条「平台 = 能力端口的集合，不是上帝接口」的延续。

**两条反面判据（什么时候说明缝开错了）**：

- 如果一个**新平台**需要改 `src/App/` 里的任何一行 ⇒ 平台抽象漏了。
- 如果一种**新执行体**需要改 `Frame` 或 `Inbox` ⇒ 「执行体」与「派发」没真正解耦
  （这正是本册两轴正交要保证的性质）。

★ **已有的扩展证据**（不是空谈）：A5-② 拖文件就是按这个形状加的 ——
一个 `FilesDroppedEvent` + 一条 `InputRouter` 路由分支 + 一个 `onFilesDropped()` 声明者，
**没动等待层、没动 `Frame`**。所以这四条缝在今天的代码里**已经成立三条**
（新事件 / 新平台 / 新异步后端），缺的是「新执行体」那条 —— 因为 A2 还不存在。

---

## 4. 桥的扩展：为什么用 JSON 而不是结构体

A2（C++ IO 线程）与 B4（唤醒）都要新桥函数。桥的类型系统是硬约束
（`docs/design-v7/08-平台与能力矩阵.md` §9.6 纪律 1：**只有标量 + array**）。

**结论：全部用 JSON 字符串过桥。**

```php
// cpp-src/drift_win32.stub.php
function drift_io_submit(string $taskJson): int {}   // 返回任务 id，-1 = 拒绝
function drift_io_poll(): string {}                   // 返回 JSON 数组（已完成的结果）
function drift_io_cancel(int $taskId): bool {}
function drift_win_wake(int $wakeHwnd): bool {}
```

三条理由：

1. **不扩类型系统。** `string` 是现成的，`resource`/`struct` 都要改 tpc 的类型映射。
   这正是 §9.6 规则 1 的边界（「参数只有**标量 + `array`**，不传对象」）。
2. **C++ 侧解析成本可控。** 载荷是**我们自己的**格式（不是任意 JSON），
   可以用一个 30 行的特定解析器，不上第三方库。
3. **跨平台天然一致。** 每个平台的 C++ 实现都收发同一个字符串契约。

★ **必须遵守 §9.6 规则 2**：「失败返回 `0`/`false`/`''`，**不抛异常**」——
异常穿过 C++ 栈是 UB。所以：

| 桥函数 | 失败时返回 |
|---|---|
| `drift_io_submit()` | `-1` |
| `drift_io_poll()` | `''`（空串 = 本轮无结果，**不是错误**） |
| `drift_io_cancel()` / `drift_win_wake()` | `false` |

★ **一条纪律**：`drift_io_poll()` 必须能返回**空串**且**非阻塞**
（对应 `pumpEvents()` 的语义）—— 它绝不能自己等，否则就把轮询搬进了 C++。
调用方必须把 `''` 与 `'[]'` 一视同仁（都是「这轮没东西」）。

---

## 5. 对「C++ 线程 + PHP 投递」的评估

用户的补充信息：*「启动多个 C++ 线程，把底层库放到 C++ 线程里面运行。PHP 只管业务逻辑，
然后投递数据给 C++ 线程」*。

### 5.1 它就是成熟框架的主流答案

对照 §1.1：**Chromium 的 `MessagePumpForIO`（IOCP，独立线程）+ `PostTask` 回 UI**
就是这件事的 C++ 版本；**Qt 的 `QNetworkAccessManager` 内部网络线程**也是。
区别只在 Drift2 的「业务逻辑」是 PHP，所以「投递」要过一次桥。

**这个方案的四个真正优势**（相对于本仓现状）：

1. **绕开 PHP 线程的全部限制。** C++ 线程不执行 PHP 代码 ⇒ 不需要
   `php_request_cache`、不需要 TSRM 上下文、不会 SIGSEGV（
   [`ASYNC-2026-10-30.md`](ASYNC-2026-10-30.md) §1.5 那三枪打的都是「PHP 线程」）。
2. **绕开「拿不到 SOCKET」。** socket 由 C++ 创建和持有，PHP 只传 URL 字符串。
   这是 §1.4 那个死结的**唯一正解**。
3. **IOCP 可用。** Windows 上最高效的 IO 模型，不需要 `WSAEventSelect` 转换
   （Qt 的 `MsgWait` 路线要转，见 §1.1 第一行）。
4. **跨平台只改 C++。** IOCP / epoll / kqueue 三套原生实现，PHP 侧接口一模一样。

### 5.2 三个绕不开的技术点

诚实账，这三条都得做：

| # | 技术点 | 难点 | 判据 |
|---|---|---|---|
| ① | **线程安全队列** | 无锁或细粒度锁；`poll()` 要「一次取走全部」 | 2 线程 × 10 万次投递/收取，0 丢失、0 重复 |
| ② | **唤醒的时序正确性** | 「投递后唤醒」与「唤醒后发现队列空」的竞态 | 注入 1000 次随机延迟，0 丢唤醒 |
| ③ | **AOT 主循环与线程的退出顺序** | 主线程退出时线程池还在跑 ⇒ 访问已释放的 Zend 内存 | 关窗后 0 残留线程、0 崩溃 |

★ ③ 是**最危险的**：`ProcessTaskHost` 的 `shutdown()` 已经踩过同类坑
（[`ASYNC-2026-10-30.md`](ASYNC-2026-10-30.md) §4.6）。C++ 线程比进程更难收 ——
进程可以 kill，线程只能 join，而 join 要有超时。

### 5.3 与现有 `ProcessTaskHost` 的关系

**不是替代，是补位。** 两者的分工在 §3.2 的表里：A2 吃 IO 密集，A3 吃 CPU 密集。

**接口层可以统一**：`TaskHost` 已经是接口，`submit()` 返回 `Job`。
新增 `NativeTaskHost`（A2）实现同一个接口，组合根换一行即可：

```php
$app->useTaskHost(new NativeTaskHost());      // IO 密集
$app->useTaskHost(new ProcessTaskHost([$argv[0], '--drift-worker']));  // CPU 密集
```

★ 但 `Job` 的载荷要统一成 **JSON**（§4），否则两条后端的数据形状会分叉。

### 5.4 建议的定位

**A2 是「值得做，但排在 N3 之后」的一件。** 理由：

- 它需要新的 C++ 线程基础设施 + 3 个桥函数 + 退出顺序裁决 ⇒ **不是一个小台阶**；
- 而 **A1（`curl_multi` 每帧推进）已经能解决「同时请求多个接口」**（P2b 实测 12µs/轮），
  A3（进程）已经能解决「重任务不卡 UI」（实测 attach 116ms）；
- ⇒ **A2 的紧迫性来自「吞吐 + 取消 + 进度」，不来自「能不能」**。

所以路线图里它排在 N4，前面先用 A1 + A3 把范式立住。

---

## 6. Swoole 线程（0.9.5 不含，git 已提交）

用户补充：*「官方已经确认 swoole 的线程已经可以使用了，只是 0.9.5 这个版本还不包含，
git 上已经提交了」*。

**设计上的处理：留口，不等它。**

- **留口在哪**：`TaskHost` 是接口，`Job` 是载荷单元。
  将来加 `SwooleThreadTaskHost` 只需实现同一个接口 —— **框架其余部分零改动**。
- **为什么不等**：本仓纪律「未被消费的分支 = 未实现」。等一个未发布的能力
  会把整条路线堵死；而 A2（C++ 线程）与 A3（进程）**今天就能做**，
  且它们的接口正好是将来 `SwooleThreadTaskHost` 要实现的同一个。
- **一个必须记住的前提**：即使 swoole 线程可用，它**仍然进 Zend VM**。
  那意味着 `Probe M` 那类 `php_request_cache` 的问题会不会重现，**要重新验**，
  不能因为「官方说可以」就直接采信（本仓纪律：宣称不构成可用性证据）。

★ 记录一条未来判据：若 swoole 线程可用且能跑 tpc 编译的类，那
`SwooleThreadTaskHost` 会比 `ProcessTaskHost` 快 5000 倍（线程 50µs vs spawn 275ms），
**那时 A3 的默认后端应该换掉**。但在验过之前，这只是假设。

---

## 7. 兼容性：破什么、留什么

用户授权「可以有兼容性的破坏」。但本册只破**有结构性收益**的四处，其余全部保留。

### 7.1 要破的

| # | 现状 | 改成 | 为什么值得破 |
|---|---|---|---|
| ① | `Platform::pump(): bool` | `waitEvents(float): WakeReason` + `pumpEvents(): int` | 把「等」「派发」「是否退出」三件事拆开。`pump()` 无法表达「等 4ms」和「被唤醒」的区别，这是所有病的根 |
| ② | `TaskHost::submit(): Job`，`Job` 只能挂一个 `onDone` | `submit(): Future`（`Job implements Future`） | 「同时请求多个接口」在回调模型里**不可组合**（§3.8）。这是能力缺口，不是风格问题 |
| ③ | `Frame::run` 的 `paint` 无条件 | 加**三重判据**（`layoutRan` / `paintEpoch` / `paintRequested`） | 22% CPU 空转 + 493MB/s 无效 blit（[`EVENT-LOOP-2026-10-30.md`](EVENT-LOOP-2026-10-30.md) §2.1）；**已落地**，见 §3.8 |
| ④ | `Application` 一个实例管一个窗口 | 拆 `AppScope`（进程一份）/ `WindowScope`（每窗一份） | 多窗口前置；顺带解掉 metrics 重解析、taskHost 重复建池等四条浪费 |

### 7.2 要留的

| 现状 | 为什么留 |
|---|---|
| `Job::onDone()` | `Future::then` 的单消费者特例 ⇒ 保留 |
| `SlicedJob` / `IoPoller` | A1 的零件就是它们，**不推翻，往上加一层 `IoDriver`** |

#### ★★ 2026-10-30 修订：`Platform::pump()` **不留**，直接删

初稿把它列进"要留的"，理由是"`pump()` ≡ `waitEvents(-1.0)`，语义无损"。
**技术上没错，工程上是错的**：

- 它**表达不了超时**。留着它，任何一处（旧组合根、示例、第三方壳）都能继续用
  它写循环，而那样的循环必然退回恒定心跳 —— 也就是 N2 要消灭的那个东西。
- 更糟的是它**看起来还能用**：不报错、不告警、行为"差不多"（只是空闲烧 22% CPU、
  `after(400)` 要 27 次唤醒）。这正是本仓最讨厌的失效形态。
- 维护成本是双份的：`FrameClock` 得同时解释"心跳"与"等待"两条时间线，
  读者要判断眼前这段代码走的是哪条。

⇒ 落地时按用户裁决（「能不保留 2 套的就直接一套新的机制即可」）**全部删除**：

| 删掉 | 原来做什么 | 现在谁做 |
|---|---|---|
| `Platform::pump(): bool` | 等 + 派发 + 是否退出 | `waitEvents()` + `pumpEvents()` + `WakeReason` |
| `FrameClock::pace(bool)` / `isArmed()` | 开关 15ms 心跳定时器 | `FrameClock::nextWaitMs()`（纯策略）+ 平台的等待 |
| `FrameClock::tick(...)` | 跑帧 + 上膛 | `FrameClock::advance(...)`（跑帧 + 三笔账） |
| `FrameClock::stop()` | 退出时拆心跳 | 无需：等待超时是**一次性的**，`waitEvents()` 自己 `KillTimer` |
| `FrameClock::TIMER_ID` | 心跳定时器 id | `Win32Platform::WAIT_TIMER_ID`（平台私有） |

★ **代价是一处静态闸门要改判据**：`app-bootstrap-frame-clock`（"组合根必须建
`FrameClock` 并 `tick()`"）换成 `app-bootstrap-loop`（"组合根必须走
`EventLoop::run()`"）。旧判据在旧机制下是对的，新机制下它只会逼人建一个用不上的
对象 —— 闸门与机制必须同源。
| `ProcessTaskHost` | A3 的正式实现，不动 |
| `FrameClock` | 变成 `ControlFlow` 的一个来源（`animating` 为真时返回 `Poll`），不删 |

★ **判断标准**：能无损表达成新机制的，就留（`pump` / `onDone`）；
表达不了的才破（`pump` 没有超时、`onDone` 不能组合）。**不为了「干净」而破。**

★ **2026-10-30 补（M2 落地后）**：`IoPoller` 的**主人**变了 —— 它不再由每个
`Application` 的 `PumpHost::attach()` 现建，而是 `AppScope::io()`（一个进程一个，
见 [`MULTI-WINDOW-2026-10-30.md`](MULTI-WINDOW-2026-10-30.md) §6 M2）。
这对下面几节有直接影响：
- **A1 / A2 的等待层（`IoDriver`）应该住在 `AppScope`**，与轮询器同一个主人 ——
  否则"等哪些 fd"这件事又要问某个窗口，而多窗口下没有"哪个窗口说了算"。
- **`waitEvents()` 的 `select` 集合是进程级**，这也是为什么它不能是
  `Application` 的方法。
- §3.8 的 N1 泵帧分离（✅ 已落地）与这条正交：N1 管"这一帧要不要画"，
  这条管"这个进程有没有 fd 可等"。

### 7.3 一次破坏的成本估算

| 破坏 | 影响的文件 | 迁移方式 |
|---|---|---|
| ① `pump` 拆分 | `Platform` + `Win32Platform` + 各 `Bootstrap.php` + `tests/` + 一条 lint 规则 | **实做：4 个组合根各 1 行 + 2 个测试改判据 + 1 条规则换判据**（`pump()` 删除，见 §7.2）。★ 初稿估的是"零迁移"，那只是把旧 API 留着的代价 —— 不留就必然要改调用点，而全仓只有 4 个 |
| ② `Future` | `TaskHost` / `Job` / `ProcessTaskHost` / `apps/users` | `Job implements Future` ⇒ 老代码不动 |
| ③ paint 门控 | `Frame.php` + 既有 golden | **行为变化**，golden 需重跑确认「首帧仍逐像素相同」 |
| ④ 作用域二分 | `Application` + 组合根 | 多窗口时一次做完 |

★ ③ 是唯一会动 golden 的一处。判据：**首帧必须逐像素相同**（门控只影响
「不必要的那一帧」，不影响「第一帧」）。

---

## 8. 路线图

每台阶**都能独立验收**，且尽量零真机（按用户本轮授权，真机可搁置）。

| 台阶 | 内容 | 碰什么 | 真机 | 收益 |
|---|---|---|---|---|
| **N1** ✅ | **泵帧分离**：`paint` 三重判据 + `RenderObject::paintEpoch` | `src/App/Frame.php` + `src/Layout/` 五个 setter | ❌ | **已落地**：空闲 60 帧 paint=0（原 60）；493MB/s blit 归零 |
| **N2** | **`waitEvents` + `ControlFlow`**：拆 `pump()`，加 `nextTimeoutMs()` | `Platform` + `Win32Platform` + `Application` | ❌（`waitEvents` 可用假平台测） | 换掉退化轮询；为唤醒铺路 |
| **N3** ✅ | **`Future` + `all/race`**（`IoDriver`/A1 判为不可行，见 §11） | `src/Async/` 新目录 + `TaskHost` + 进程池 | ❌ | 「多接口并发」可写（池 + `all`）；写法对齐 Flutter/.NET |
| **N4** | **`Inbox` + 唤醒通道（B4）** | C++ 壳 + 桥 + `Platform` | ✅ 必须 | 零轮询；后台真能打断等待 —— ★ **2026-10-30 改判：等 N5**（`Inbox` 今天没有生产者，见 §11 ②） |
| **N5** | **C++ IO 线程（A2）** | C++ 壳（线程池 + 队列 + JSON 桥） | ✅ 必须 | 吞吐 / 取消 / 进度 |
| **N6** | **多窗口**（依赖 `MULTI-WINDOW-2026-10-30.md` 的 M0/M1） | 壳 + Platform | ✅ 必须 | 见该册 |

★ **N1 → N2 → N3 全是零真机**，且 N3 的 `Future` 是纯 PHP —— 这三步可以在
不碰 GUI 的前提下把「异步」这件事做完整。

★ **N4 是分水岭**：从它开始必须真机。按用户授权，遇到多次尝试无法解决的真机问题，
**停下分析、记录搁置、继续后面的**。

---

## 9. 判据

每台阶的验收，可直接写进 smoke / phpunit。

### 9.1 N1（泵帧分离）—— **已验**

- [x] 同一场景下 paint 从「每帧 +1」降到「只在脏时 +1」
      （`tools/frame-paint-probe.php`：空闲 60 帧 → **paint=0 / skip=60**）
- [x] **首帧仍逐像素相同**（golden 29 页 + 整页 8 项 gate 一字节不变）
- [x] 静态树无变化时，`Frame::paintSkipCount()` 连续 +N 而 `RenderBox::paintCount()` 不动
- [x] 五条"不重建不重排"通道各自有"必须画"的断言
      （`Feature/App/FrameTest` 三条 + `Feature/Table/ScrollTest` 一条 + `Unit/Frame/ComposeSkipLayoutTest` 一条）
- [x] **变异验证**：删掉 `setOffset()` / `tickAnimations()` 里的 `markNeedsPaint()`
      ⇒ 4 条测试红（其中 2 条是既有测试：`LayerCacheTest`、`ScrollViewScrollTest`
      直接报"画面没变"）

### 9.2 N2（`waitEvents` + `ControlFlow`）—— **已验**（2026-10-30）

- [x] 三态可测，**但判据换了三条**（初稿的判断有两处是错的，见 §3.5 与
      `FrameClock::nextWaitMs()` 的注释）：
  - 动画中 **不是** `0`，是 `FRAME_MS`＝15。本仓没有 vsync ⇒ `0` = 能跑多快跑多快
    = 300fps 空转，而动画相位是 `f(timestamp)` ⇒ 只会烧 CPU，不会更顺。
  - 有 IO watcher 但没数据 **不能**走"无限等"：`IoPoller` 只能在帧里被问，
    无限等 = 把 socket 饿死（N4 有唤醒通道后才可能变）。
  - 无定时器 ⇒ `-1` ✔；有定时器 ⇒ 剩余毫秒 ✔（这两条初稿是对的）
- [x] **不用假平台，用真 `Win32Platform` + 假桥**：`WakeReason` 的三个 case 由
      剧本化 `drift_win_pump()` 注入（`Message` / `Timeout` / `Quit`），
      因为 `waitEvents()` 里"取一条 → 是不是我那个闹钟 → 暂存/退出"的**分支逻辑
      只存在于此**，自己写个假平台等于绕开被测代码。
- [~] `pump()` 委托等价 —— **不适用**：`pump()` 已删除（§7.2 修订）。
      替代判据：四个组合根都必须走 `EventLoop::run()`（`app-bootstrap-loop` 闸门
      + 一条反例夹具，`lint --selftest` 该响 1）。

新增的证据（`tools/phpunit-run.php feature`）：

| 用例 | 钉住的事 | 变异验证 |
|---|---|---|
| `FrameWaitTest::testTimerSleepsUntilDue` | `after(400)` ⇒ 等 **400**（旧心跳 27 次 × 15ms） | 把定时器分支删掉 ⇒ 红（报 `wait=0.0`） |
| `FrameWaitTest::testAnimationKeepsFrameRhythm` | 动画 + 远处定时器 ⇒ 仍 15（近者优先） | 同上 |
| `FrameWaitTest::testStallOnlyMeasuredOnRhythmRounds` | 等到点之后的 400ms 间隔**不算**卡顿 | — |
| `EventLoopTest::testMessageIsDispatchedThenOneFrameRuns` | `pumpEvents()` 在 `poll()` 之前 + IME 报两次 | 挪到之后 ⇒ 队列不空；删帧末 IME ⇒ 报 0 次 |
| `EventLoopTest::testTimerWaitIsOneSleepNotManyWakes` | 每轮等待时长都是 400 | 见上 |
| `EventLoopTest::testDueTimerFiresInsideFrameAndThenSleepsAgain` | Timeout 那轮也跑帧（否则定时器永不触发） | 加 `if ($why !== Timeout)` ⇒ 红（`fired=0`） |
| `EventLoopTest::testCursorFromDispatchReachesWindowHost` | 光标形状真喂到 `WindowHost` | 删 `setCursor` ⇒ 红（`[]`） |

#### ★ 2026-10-30 追加：`CloseRequestedEvent` 是 ⑤ 的唯一例外

可拦截关闭落地时（`MULTI-WINDOW` §5.1）发现环路里多了一条**不进 `dispatchInput()`
的事件**：它没有命中目标（不是"点在某个控件上"），也不该被浮层拦下 —— 它是窗口级
生命周期，与 `WakeReason::Quit` 同族。⇒ ⑤ 里在派发之前先认它：

```php
if ($event instanceof CloseRequestedEvent) {
    if ($app->closeRequested()) { $window->destroy($windowHandle); return; }
    continue;   // 拒绝：窗口原样留着
}
```

★ **`return` 不是 `break`**：销毁之后 `waitEvents()` 下一轮会拿到 `WM_QUIT`，
而在那之前跑一帧就是对着死句柄求客户区（0×0 紧约束 ⇒ 布局硬校验 Fatal）。
判据落在"帧数没涨"上，变异成 `break` ⇒ 红（`frames=2`）。

| 用例 | 钉住的事 | 变异验证 |
|---|---|---|
| `EventLoopTest::testCloseRequestedIsHonouredAndStopsTheLoop` | 同意 ⇒ `destroy()` + 不跑帧 + 只问一次 | `if (true)` ⇒ 红（`asked=0`）；`break` ⇒ 红（`frames=2`） |
| `EventLoopTest::testCloseRequestedRefusedLeavesWindowAlone` | 拒绝 ⇒ 不 `destroy()`、循环继续 | `if (true)` ⇒ 红（`[["destroy",1]]`） |
| `EventLoopTest::testCloseRequestedDoesNotEnterDispatchChain` | 不进 `input()` 队列残留 | — |

### 9.3 N3（`Future`）—— **已验**（2026-10-30）

- [x] **「多接口并发」改由进程池 + `Future::all` 承载**（原判据写的是 `curl_multi` 5 条
      600ms < 1200ms；A1 判为不可行，见 §11）。现在的判据在 `tools/smoke.php` 的 `N3 ·` 五条：
      - 3 条 200/100/300ms 同时进 3 个 worker（`inFlight=3 / workers=3 / queued=0`）
      - 结果按**入参**顺序 `[200,100,300]`（≠ 完成序 `[100,200,300]`）
      - `then()` **只跑一次**
      - 一条失败 ⇒ 整条失败（错误原文带出）
      - 失败时 `then()` 一次都不跑
      - ★ **变异验证**：把 `all()` 的顺序重排改成"完成序" ⇒ 精确报 `{"1":100,"0":200,"2":300}`，红
- [x] `Future::race` 返回最先完成的那个，其余被 `cancel()`（`FutureTest` 单测，
      "落选者被 cancel" 与 "迟到结果被忽略" 两条都断）
- [x] `all` 中任一条失败 ⇒ 整条失败，且**其余被取消**（同上，单测 + smoke 各一层）
- [x] `Job::onDone` 老路径行为不变 —— 该参数已删除（§7.1），替代判据：`submit()` 返回
      `Future`，回调时序与旧 `$onDone` **同级**（`P0 · then() 里改 Store ⇒ 同帧重建` 仍绿）
- [x] **`cancel()` 是真能力，不是"只丢结果"**（2026-10-30 补，见 §11）。判据 5 条：
      - 分片取消后**不再 `step()`**（`steps()` 停在取消那一刻；正对照先证明它在推进）
      - 分片取消后**当场摘出队列**（`slicedCount() === 0`）
      - **帧内取消**：一个分片完成时的回调取消同帧里排在后面的另一个 ⇒ 它**一步都不跑**
        （`pumpSliced()` 取快照后钩子摘不掉，靠泵里的 `isCancelled()` 复查）
      - 进程池**排队中**的取消 ⇒ `queuedCount()` 减 1，那条任务**从未执行**（sink 空）
      - 进程池**在途**的取消 ⇒ 记账**不变**（`pending()` 不减，否则帧时钟落闸、回包读不到、
        worker 写满管道阻塞成僵尸），只是 `then()` 一次都不跑
      - ★ **变异验证 4 条**：摘钩子 ⇒ 红在"当场摘出队列"；摘泵内复查 ⇒ 红在"帧内取消
        （steps=1）"；不取消旧取数 ⇒ 红在"迟到旧响应"（实测输出 `["第一页"]`）；不摘队列
        ⇒ 红在"排队中取消（queued=1 pending=2）"
- [x] **陈旧响应**：`UserStore::load()` 连发两次 ⇒ 先发的慢、后发的快 ⇒ 迟到的旧结果
      **不覆盖**新数据（状态仍是 `Ready`，不是被搅成 `Loading`/`Error`）

### 9.4 N4（唤醒）—— **等 N5**（2026-10-30 改判，见 §11 ②）

- [ ] 后台 `post()` 后，UI 线程的 `waitEvents(-1)` **在 5ms 内返回**（1000 次 0 失败）
- [ ] 无任何投递时，`waitEvents(-1)` **不空转**（CPU 采样 < 1%）
- [ ] 投递 10 万次不丢（队列判据见 §5.2 ①）

★ 前两条判据都需要一个"从**别的上下文**投递"的生产者，而今天唯一的候选是 N5 的
C++ 线程。⇒ 与 N5 合并验收，不单独开工（理由见 §11 ②）。

### 9.5 N5（C++ IO 线程）

- [ ] `drift_io_poll()` 空队列返回 `[]` 且非阻塞（单次 < 10µs）
- [ ] 线程池 4 线程 × 各 100 条请求，结果 0 丢失、0 重复
- [ ] 关窗后 0 残留线程（`GetProcessId` 的线程枚举），0 崩溃（§5.2 ③）

---

## 10. 这一册没做的事

- **没写一行 `src/` 代码**。本册是设计；`probes/` 下的探针是**验证**，不是实现。
- **没验 A2 的线程基础设施**（线程池 / 队列 / 唤醒时序）。§5.2 那三条是**待验项**，
  不是已验项。
- **没定位 P1 那个 59ms 尖峰**、也没定位 P3 那个 6.3ms 尖峰。真实存在，原因未知，
  按纪律不写成「已解决」。
- **没决定 `AppScope` / `WindowScope` 的字段划分**（那是多窗口册 M2 的活）。
- **没验 Swoole 线程**（0.9.5 不含，git 已提交但未发布）。
- **没做 Linux / macOS 的对应验证**（本仓只有 Win32；但 §1.3 的三形态结论对它们成立）。

★ 最强的一条提醒：**本册的路线图是基于「A1 + A3 已经够用」的判断**。
如果真出现「必须 A2 才能撑住的吞吐」，那 N4/N5 的次序要重排 —— 但那是**被需求逼出来的**，
不是提前猜的。

---

## 11. 修订记录

### 2026-10-30 · N3 补齐：`cancel()` 从"契约"变成"能力"；N4 的 `Inbox` **推迟**

**两件事，一件做了，一件判了"现在不做"。**

#### ① 做了：`Future::cancel()` 之前是**空操作**

`Future::cancel()` 的 docblock 写着「`ProcessTaskHost` 用它杀在途 worker 任务」，
而 `grep` 结果：**没有任何后端给 `Future::pending()` 传过钩子**。于是
`cancel()` 在真实任务上只做了一件事 —— 把 Future 标成"结果不要了"，
任务本身**照跑不误**。分片尤其刺眼：`$sliced` 是 `list`，按 `SlicedJob` 对象
反查不成立（同一个 job 可以提交两次）⇒ 每帧照样白烧那 4ms 预算。

这不只是"少个功能"。它在 `apps/users` 上表现为一个**真实的数据竞争**：
`load()` 可以被连点（刷新 / 翻页 / 注册后重载），两个请求同时在途时
"先发的慢、后发的快"是常态 ⇒ 迟到的旧结果盖掉新数据，而状态是 `Ready`，
**用户看不出任何异常**。成熟框架都在这一层收口（RxJS `switchMap` /
`useEffect` cleanup / `CancellationTokenSource`），本仓的收口就是 `cancel()`。

改法（三处，都不新增机制）：

| 位置 | 改法 | 能停到什么程度 |
|---|---|---|
| `PumpHost::submitSliced()` | `$sliced` 改成**按任务号键控**，钩子 `unset($this->sliced[$id])` | **真停**（本进程本线程，摘掉就没人 step 它了） |
| `ProcessTaskHost::submit()` | 提前占号，钩子把**还没发出去**的排队项摘掉 | 排队中**真停**；已发给 worker 的**停不了**（杀进程要重拉、还丢在途状态）⇒ 只做"不交付" |
| `UserStore::load()` | 记住根 `Future`，新请求起手先 `cancel()` 旧的 | 迟到的旧响应被 `Future::resolve()` 的取消位挡住 |

★ **`pumpSliced()` 里还要复查一次 `isCancelled()`** —— 钩子管**跨帧**，
泵里的复查管**帧内**：`pumpSliced()` 先取快照再清空队列 ⇒ 同帧里被取消的那一项
**已经不在 `$this->sliced` 里**，钩子的 `unset` 找不到它。两处都要有，缺一条
就是"同帧取消不生效"（变异验证：摘掉复查 ⇒ `steps=1`）。

★ **在途取消不能减 `pending()`** —— 它一落闸，帧时钟就停止上膛，管道里的回包
永远读不到 ⇒ worker 写满管道后阻塞成僵尸。**取消影响"交付"，不影响"记账"。**

#### ② 判了不做：N4 的 `Inbox`（§3.6）现在**不该建**

设计册把 N4 定为「`Inbox` + 唤醒通道」。读完实现之后，`Inbox` 的**同线程那一半**
（把 `after()` / `submitSliced()` 收进一个队列）看起来很有吸引力 ——
它能让"新机制自动被 `wantsFrame()` / `nextWaitMs()` / `frame()` 看见"。
但三条理由都指向"现在不做"：

1. **没有生产者。** 今天唯一可能的跨上下文投递者是 N5 的 C++ 线程，它还不存在。
   `ProcessTaskHost` 是**拉**模型（每帧读管道），`WM_TIMER` 自己会醒 ——
   `post()` 从"别的上下文"调进来这件事，今天一次都不会发生。
   为不存在的生产者造机制，就是 A1 的老路（写完 400 行才发现没闸门能碰它）。
2. **`post()` 跨线程而无唤醒通道 = 静默挂死。** 空闲时 `waitEvents(-1)` 无限等，
   投进去的活永远不跑，而且**不报错**。所以"同线程一半"和"C++ 一半"不能分开交付；
   分开了就是给下一个调用方埋一个 hang。
3. **形状要由真需求逼出来**，不是提前猜（§10 自己的话）。等 N5 落地，投递者全都在了，
   `Inbox` 的形状被逼死，一次做对；现在做等于做两遍。而且同线程那一半还撞上
   `FramePhase` 的既有裁决 ——「能靠"一次写对 + dev 断言"守住的，不要造运行时机制」，
   并且会把 `pumpSliced` / `pumpTimers` 两个**诊断粒度**并成一个。

⇒ **N4 的整体状态从"必须真机"改为"等 N5"**：`Inbox` 与唤醒通道一起做，
因为它们是同一个契约的两半（`post()` 必须能叫醒等待者）。

#### 本轮判据（全部零真机）

`tools/smoke.php` 新增 6 条 `N3 ·`，**4 条变异逐条验证过**（摘钩子 / 摘泵内复查 /
不取消旧取数 / 不摘队列 ⇒ 各自红在**预期的**那条上，不是"随便一改就红"）。
其中"陈旧响应"那条用**手动结算的 `TaskHost`** 而不是真 HTTP ——
判据要的是"**顺序**"（旧的后到），不是"耗时"，真并发测这一条只能靠 sleep 排序，
机器一忙就假红。

### 2026-10-30 · A1（PHP 侧 `curl_multi`）判为**不可行** —— 工具链缺陷，不是设计问题

**结论先行**：`IoDriver`（A1）**不落地**。「多接口并发」的答案落在**已有的进程池（A3）
+ `Future::all`** 上。A2（C++ IO 线程）的定位因此**变了**：它不再只是"更快的形态"，
而是「**PHP 侧的 curl 不可信**」这一条硬约束下唯一能让 HTTP 并发既快又可测的路。

**证据**（`D:\TypePHP\typephp-windows-x64\php.exe`，PHP 8.5.11 ZTS x64）：

| # | 动作 | 结果 |
|---|---|---|
| 1 | `php -r '$mh = curl_multi_init();'` | **exit 139**（段错误在**模块收尾段**，`register_shutdown_function` 的回调已经跑完之后） |
| 2 | 加 `curl_multi_close()` + `exit(0)` | 仍 **139** |
| 3 | `unset()` 全部引用、只 `init` 不 `exec` | 仍 **139** |
| 4 | `php -r 'echo 1;'` / `curl_exec()`（easy 通道） | 0 ✔ |
| 5 | `curl_global_init()` / `curl_global_cleanup()` | **函数不存在** |

`php -i` 里同时有 `cURL Information => 8.22.0` 与 `curl-native => enabled` /
`curl-version => 8.12.1` —— 一个进程里两份 libcurl，`curl_multi_init()` 之后收尾顺序就崩。
第 5 条说明这**不是官方 curl 扩展**（官方必然有 `curl_global_*`），所以不能指望"以后会修"。

**AOT 侧是好的**（所以问题不是"curl_multi 不能用"，是"**解释器**不能用"）：

```
[E2]  n=5 ok=5 elapsedMs=622.5 serialWouldBe=3000ms verdict=CONCURRENT
[E2b] ticks=20001 avgTickUs=11.3 worstTickMs=4.38 verdict=UI-SAFE
退出码 = 0
```

（本轮重跑，5 个并发 mock 各自延迟 600ms；数值与 §2.2 的 623ms 一致 ⇒ §2.2 的结论仍然成立。）

**为什么"只在 AOT 可用"也不接受**（三条，按严重程度）：

1. **它是个陷阱**：任何**测试**（smoke / phpunit）只要触到 `fetch()`，进程就 `exit(139)`。
   一个"调用即污染测试进程"的 API 比没有这个 API 更糟 —— 下一个人会花半天找自己代码的 bug。
2. **进不了闸门**：`gate.php` 全跑在 php.exe 上。一个住在 `src/` 里、却没有任何一条闸门能碰
   的能力会腐烂（本仓纪律：**未被消费的分支 = 未实现**）。
3. **两套 HTTP 的路**：`ProcessTaskHost` 已经在跑 HTTP。再加一条只在编译产物里可用的路
   ⇒ 用户要记两套时序 —— 与「能替代就只留一套」直接冲突。

**替代方案（已落地，零新机制）**：`Future::all` + `ProcessTaskHost(pool: 3)`。
判据在 `tools/smoke.php` 的 `N3 ·` 五条，并做了变异验证（把 `all()` 的顺序重排改成"完成序"
⇒ 精确报 `{"1":100,"0":200,"2":300}`，红）。

**代价（诚实记账）**：进程池每 worker 一套 AOT 运行时（几十 MB）+ 首次 spawn 275ms。
所以 A3 是**过渡**，不是终局 —— 但 A2 的理由不再是"更快"，而是"**PHP 侧的 curl 不可信**"。

**没做的事**：没有为了让 A1 可用去改 `php.ini` / 换扩展 / 换 php 发行版 ——
那是改工具链，超出本册范围，且会让"解释器"与"AOT 产物"的运行时**不一致**（那更危险）。

---

### 2026-10-30 · N2 落地：等待与派发拆开，旧心跳整套删除

实做的东西（`src/` + `apps/` + `tests/`）：

| 新增 / 改动 | 说明 |
|---|---|
| `Platform::waitEvents(float): WakeReason` | 三态超时（-1 / 0 / >0）+ 「为什么醒」 |
| `Platform::pumpEvents(): int` | 把暂存的原始事件归一化投进 `input()` |
| `src/Platform/WakeReason.php` | 三 case（`Message` / `Timeout` / `Quit`）。**第四 case `Woken` 没写**：没有生产者就是没实现 |
| `src/App/EventLoop.php` | 组合根只留一行；等 / 派发 / 跑帧 / 光标 / IME / 退出各写一次 |
| `FrameClock` 重写 | 只剩 `nextWaitMs()`（纯策略）+ `advance()`（跑帧 + 三笔账） |
| `Application` 拆三谓词 | `wantsFrameRhythm()`（连续帧）/ `needsFrameNow()`（一次性欠账）/ `nextDeadlineMs()`（到点） |

三处**与初稿不同**的判断：

1. **`pump()` 删除，不留委托**（§7.2 修订）。初稿说"语义无损 ⇒ 留着零迁移"，
   落地方发现留着它 = 留一个必然退回恒定心跳的入口。
2. **动画中给 `FRAME_MS` 而不是 `0`**（§9.2）。`0` 在本仓等于"能跑多快跑多快"，
   没有 vsync 兜着 ⇒ 300fps 空转。
3. **卡顿观测要判"上一轮是怎么等的"**（`FrameClock::advance()`）。不判就会把
   一次 400ms 的**正常**长等待记成卡顿 —— 日志被 Tooltip / Snackbar 刷满，
   真问题反而被埋掉。

★ **Win32 的 `waitEvents` 是兜底实现，不是终局**：`GetMessageW` 没有超时参数，
这一版用 `SetTimer(WAIT_TIMER_ID)` 起一个一次性闹钟再 `KillTimer` 兜住。
粒度 = 系统 tick（~15.6ms），与旧心跳同粒度 ⇒ 不构成回归，但它**不是** N4 的样子
（`MsgWaitForMultipleObjectsEx` + 唤醒事件才是）。
★ 顺带修掉一个真缺陷：`KillTimer` 不收回已排进队列的 `WM_TIMER`，所以下一轮可能
看到一个早到的 `timer` —— 那本来就无害（帧幂等 + N1 已把空帧压到零），现在被显式
记在 `waitEvents()` 的注释里，免得后人把它当 bug"修"成清队列。

### 2026-10-30 · N1 落地，§3.8 的方案被自己推翻

初稿 §3.8 写的是「加 `needsPaint`，来源 `Subject::notifyChanged()`」。**动手前先数依赖**，
发现全仓有六条通道**刻意**绕过重建（`setCompose` / `setScrollOffset` / `setBarOpacity` /
`tickAnimations` / `setBase` / 焦点环+面板落后），而它们全部**显式依赖"paint 无条件跑"**
（代码注释里就写着这句话）。按初稿做，六条同时静默失效。

⇒ 改成：脏位住**渲染树**（`RenderObject::paintEpoch`，与 `layoutEpoch` 同构），
写点收口在**改字段的那一行旁边**（五个 setter），再加一条由 `Application` 说进来的
`$paintRequested`（覆盖树外的焦点环 / 面板落后）。

★ 教训：**"加一个脏位"听起来是加法，其实是把一条隐藏耦合显式化。**
动手前该做的不是设计脏位，是**列出所有"没有脏位也正确"的路径** —— 那些正是脏位会打断的。

★ 由 [`MULTI-WINDOW-2026-10-30.md`](MULTI-WINDOW-2026-10-30.md) 带来的一个已知代价：
`$paintEpoch` 与 `markedCount` / `boxEpoch` / `structureVersion` 一样是**进程级**的 ⇒
多窗口下 A 窗滚动会让空闲的 B 窗也重画一帧（保守多画，不会少画）。
按窗口分片要先有窗口身份（该册 M0/M1）⇒ 记入 M2 的债，不在这里提前做。

### 2026-10-30 · 初稿

基于：

- 七个框架的源码/文档调研（§1）；
- 五个探针实测（§2）：把
  [`ASYNC-2026-10-30.md`](ASYNC-2026-10-30.md) 里两条**标着「未验」的前提**
  （「泵能否写成协程」§1.2、「协程能否调 tpc 类」§2.3）变成已验，
  并补上「协程上下文里 `select(0)` 不抛」这另一半；
  另发现一条工程约束（P5：`curl_multi` 与 swoole 同产物退出崩）；
- 用户补充的两条信息：**C++ 线程方案**（§5）、**Swoole 线程已在 git**（§6）。

★ 一条方法教训，值得单独记：**前两轮评估错在「只看了 Windows 一种形态就下结论」**。
这一轮先做七框架横向对照，才看出「等 fd 派 / 不等 fd 派」这个真正的分水岭 ——
而 Drift2 的约束（拿不到 SOCKET）恰好指向后者。**结论的正确性，取决于对照集的宽度。**

★ 一条自我修正，同样值得记：初稿 §2.1 / §2.3 用了「推翻既有结论」的措辞，
核对原文后发现**原文本身是准确的**（它明确区分了「协程上下文」与非协程上下文，
也明确把那两条标为「未验」）。措辞已改为「把未验升为已验 / 补齐另一半」。
**「推翻」是个很重的词，用错它和犯错一样伤可信度。**
