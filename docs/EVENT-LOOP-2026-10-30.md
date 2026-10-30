# 事件循环与 IO 等待 —— 跨平台评估册

> 2026-10-30 · 评估稿（零代码改动）
>
> 起因：用户问「综合前面的信息，异步要怎么做」，并点名要评估
> 「IOCP 的 handle 能被 `MsgWaitForMultipleObjectsEx` 和窗口消息一起等」。
> 评估过程中用户追加了一条硬约束：**要跨平台，不能只看 Windows**。
> 该约束让结论发生了一次实质位移 —— 见 §10 修订记录。

---

## 0. 结论摘要

四句话：

1. **「IOCP handle 能被 `MsgWaitForMultipleObjectsEx` 一起等」这句话本身是对的**，
   但它**对本仓的 PHP stream 层不成立** —— 不是等不了，是**拿不到那个 SOCKET**。
   要它成立，得先有「C++ 自建 net 层」，那是另一个量级的事（§4）。

2. **真正的瓶颈不在「怎么等」，在「等的时候还在画」**。
   今天只要有 IO watcher，`wantsFrame()` 恒真 ⇒ 15.6ms 定时器常驻 ⇒ **每帧无条件
   重绘 + 整帧 blit**。这是 22% CPU 与 ~493MB/s 内存拷贝的来源（§2）。
   而且这一刀**纯 PHP、全平台一致、零真机风险**。

3. **跨平台的正解是 `waitEvents(timeout)`，不是 IOCP。**
   Windows / X11 / Wayland / Cocoa 的「UI 事件源」形态根本不同（消息队列 / fd /
   run loop），只有「平台自己等、框架只收结果」这一种抽象能覆盖三者。
   这条 `docs/design-v7/08-平台与能力矩阵.md` §9.7 已经裁决过，代码没落地（§3、§6）。

4. **顺序不能反**：必须**先做「泵帧分离」**，再做 `waitEvents`。
   因为 `waitEvents` 在 Windows 上最优超时是 4ms（比现在的 15.6ms 更密），
   没有分离就先换等待机制 ⇒ **CPU 反而涨**（§7）。

---

## 1. 现在到底是怎么跑的

三条事实链，都有行号。

### 1.1 泵：阻塞在 `GetMessageW`，没有超时

`cpp-src/drift_win32.cc:2745` 的 `php_drift_win_pump()`：

```cpp
for (;;) {
    MSG msg;
    BOOL got = GetMessageW(&msg, NULL, 0, 0);   // ← 无限阻塞
    ...
    if (!g_queue.empty()) { return toArray(...); }
    // 不关心的消息 ⇒ 继续等，不回 PHP
}
```

所以：**没有消息时，进程整块睡在这里，PHP 侧一行都不跑**。
`Platform::pump()`（`src/Platform/Platform.php`）的签名就是 `pump(): bool` ——
**没有 timeout 参数**。这是「框架绝不自建循环」的代价：循环归平台，框架只能等。

### 1.2 帧：15.6ms 定时器驱动，且 paint 无条件

`src/App/FrameClock.php:61` 的 `FRAME_MS = 15`（不是 16 —— `SetTimer` 量化到
~15.6ms 的系统 tick，请求 16 会落到第二个 tick 变 32fps）。

`frame()` 被叫醒后走 `src/App/Application.php:723` 起的五泵 + `Frame::run()`。
`src/App/Frame.php:160` 的 `run()` 里，**三个阶段的跳过判据是不对称的**：

| 阶段 | 有没有跳过判据 | 判据 |
|---|---|---|
| `settle`（帧末重建） | ✅ 有 | `needsSettle()`（`State::markedCount()` 比对） |
| `layout` | ✅ 有 | `$didRebuild` / `State::boxEpoch()` / `constraintsChanged()` |
| **`paint` + `present`** | ❌ **没有** | **无条件**：`beginFrame()` → `paintComposed()` → `endFrame()` |

这是本次评估最重要的一个发现。前两段都做了「与上一帧逐位相同就跳过」的优化，
**唯独最贵的第三段没有**。

### 1.3 IO：`stream_select(0)` 每帧问一句

`src/App/Io/IoPoller.php:116` 的 `poll()` 是 `@stream_select($read, $write, $except, 0)`
—— 超时 0，立即返回。单次 0.006–0.02ms（socket），可忽略。

它在 `Application::frame()` 的第一步被调（`pumpIo()`，`src/App/Application.php:754`）。

**关键**：`poll()` 只有在 `frame()` 被运行时才跑。而 `frame()` 什么时候跑，
由 `FrameClock::pace($app->wantsFrame())` 决定 —— 而 `wantsFrame()`
（`src/App/Application.php:1050`）的第一个判据 `hasAsync()`
（`src/App/PumpHost.php:168`）**包含 `io()->watchCount() > 0`**。

⇒ **只要注册了一个 IO watcher，定时器就永不关闭**，进程以 64Hz 空转。

---

## 2. 三层被一个定时器绑死 —— 代价量化

### 2.1 数字

| 项 | 值 | 来源 |
|---|---|---|
| 定时器周期 | 15.6ms（≈64Hz） | `SetTimer` 量化，`FrameClock::FRAME_MS` 注释 |
| 一帧耗时 | 3.4ms（其中 paint 2.57ms = 75%） | 滚动帧实测（`.workbuddy-ai/memory/PITFALLS.md` 性能观察） |
| **空转 CPU** | **3.4 × 64 / 1000 ≈ 22%** | 上两项相乘 |
| 单帧 present 字节 | 1916 × 1006 × 4 ≈ **7.7MB** | 示例台客户区尺寸 |
| **每秒 blit** | **7.7MB × 64 ≈ 493MB/s** | 上项 × 64 |

一个字节都没到的 socket，可以让进程每秒搬 493MB 内存、烧掉 22% 的一个核。

> **2026-10-30 已修（N1 泵帧分离）**：paint 段现在有跳过判据
> （`RenderObject::paintEpoch` + "本帧 layout 跑过" + 应用级叠加请求），
> 判据与落点见 [`EVENT-LOOP-DESIGN-2026-10-30.md`](EVENT-LOOP-DESIGN-2026-10-30.md) §3.8。
> 静态树实测：`php tools/frame-paint-probe.php` ⇒ 空闲 60 帧 **paint=0 / skip=60**。
> 上面那 22% 与 493MB/s 现在只剩**等待层**的一半（定时器仍在空转，但不再重绘），
> 换真唤醒是 N2/N4 的事。★ 真机 CPU 读数**仍未测**。

### 2.2 为什么会这样（结构原因）

不是某一行写错了，是**三个层次的职责被同一个机制承担**：

```
层 A  谁阻塞        ← 平台（GetMessageW）
层 B  什么时候画    ← 应用（wantsFrame）
层 C  IO 就绪了吗   ← IoPoller（stream_select(0)）
        ↑
   三者都由「FrameClock 的 15ms 定时器」驱动
```

三者本来应该是三件独立的事。现在：

- 为了「等 IO」（层 C），必须让循环醒着（层 A 放弃阻塞）；
- 循环醒了就一定会跑 `frame()`（层 B 无条件）；
- `frame()` 里 paint 没有跳过判据 ⇒ **每醒一次就全量重绘**。

**这是「轮询」的典型代价形态：不是 poll 本身贵（0.006ms），是 poll 之后顺带做的事贵。**

### 2.3 一个反直觉的推论

如果只把等待机制从「15ms 定时器」换成「IOCP + MsgWait 真唤醒」，
在**空闲时**确实省下了那 22%。但**有数据流动时反而更忙** —— 每个数据包唤醒一次，
每次都跑完整 `frame()`。所以 §7 会论证：**必须先解耦，再换等待机制**。

---

## 3. `design-v7` 已经裁决过什么 vs 代码现状

这一节是为了避免「重新发明」—— 本仓纪律：**改语义前先出裁决**，而裁决可能已经有了。

| 设计项 | `docs/design-v7/08-平台与能力矩阵.md` §9.7 / `docs/design-v7/09-帧与异步.md` §4.2 | 代码现状 |
|---|---|---|
| 泵拆两个入口 | `pumpEvents()` 非阻塞 + `waitEvents(float $timeoutMs)` 带超时 | ❌ 只有 `pump(): bool`，**无超时** |
| 有 IO 挂起时的超时 | **4ms**（否则 Fiber 既不算就绪也不产生消息 ⇒ `waitEvents(∞)` 睡死） | ❌ 用 15.6ms 定时器代替 |
| IO 挂起原语 | `Io::await(fd, events, timeout)` —— **Fiber 挂起**，注册+挂起一步 | ❌ `IoPoller::watch()` 是**回调式**，无 Fiber |
| 平台 fd 上限 | Windows `FD_SETSIZE = 64`，>64 报 `E-IO-FDSET`，>256 强制走通道 | ❌ 无上限检查 |
| 事件源选择 | `pipe` 不要当事件源（2.37ms/次），用 socket pair | ✅ 已遵守（`src/App/ProcessTaskHost.php:228`） |
| 线程纪律 | 整个进程只有一个线程持有 PHP 运行时 | ✅ 已遵守 |

**结论**：`waitEvents` 不是新提案，是**两条已裁决的设计没落地**。
本轮不重复设计，只评估「怎么落地、按什么顺序、跨平台各自怎么实现」。

---

## 4. 「IOCP + `MsgWaitForMultipleObjectsEx`」的精确裁决

用户提的那句话值得逐字拆开，因为它**一半对、一半是坑**。

### 4.1 对的部分

- `MsgWaitForMultipleObjectsEx` 确实是 Windows 上**唯一**能「同时等窗口消息和内核对象」
  的原语（等价于 `WaitForMultipleObjects` + `QS_ALLINPUT` 消息掩码）。
- IOCP（`CreateIoCompletionPort` 返回的）**是一个内核对象**，可以被它等。
- Chromium 的 `MessagePumpForIO` 就是这个结构，工业界验证过。

所以「IOCP handle 能一起等」这句话，**在 C++ 世界里是准确的**。

### 4.2 不对的部分：SOCKET 不是可等待对象

`WaitForMultipleObjects` / `MsgWaitForMultipleObjectsEx` 的 `lpHandles` 只接受这些类型：
change notification / console input / event / memory resource notification /
mutex / process / semaphore / thread / waitable timer。

**SOCKET 不在其中。** 想让它进等待集合，必须两步：
1. `WSAEventSelect(s, hEvent, FD_READ | FD_WRITE | FD_CLOSE)` —— 把 socket 事件
   映射到一个 `WSAEVENT`（event 是可等待的）；
2. 等到之后 `WSAEnumNetworkEvents(s, hEvent, &ev)` 取具体事件位并重置。

⇒ 等的是 **event**，不是 socket。**这一步不能省。**

### 4.3 对本仓的致命卡点

上面第 1 步需要 **SOCKET 数值**。而本仓的 socket 是 PHP 创建的：

```php
$sock = stream_socket_client("tcp://127.0.0.1:9000", ...);   // php_stream，不是裸 SOCKET
```

**PHP 用户态取不到底层 SOCKET**：`(int) $stream` 返回的是**资源 id**（1、2、3…），
不是 fd。`socket_import_stream()` / `socket_export_stream()` 也只在这两个表示之间
互换，不暴露 fd。

**C 扩展层可以取**（`php_stream_cast(stream, PHP_STREAM_AS_SOCKET, ...)` —— 这正是
`stream_select` 内部做的事）。但本仓的桥函数**收不到 stream 资源**：

> `docs/design-v7/08-平台与能力矩阵.md` §9.6 纪律 1：
> 「参数只有**标量 + `array`**，不传对象」—— 对象过界 = `Var` 装箱 + 生命周期不明。

`cpp-src/drift_win32.stub.php` 的 40 个桥函数**没有一个**收 `resource`/`Mixed`；
唯一的「句柄」表达是 `int`（如 `drift_win_present_skia(int $hwnd, int $canvas)`）。

⇒ 三条路都被堵：
- PHP 层拿不到 SOCKET；
- 桥函数传不进 stream；
- 就算硬塞一个 `int`，PHP 侧也没有 API 能产生这个 `int`。

### 4.4 结论：它属于「C++ 自建 net 层」，不是「改一下泵」

要让 IOCP 在本仓成立，唯一路径是：

```
C++ 侧：drift_net_connect() → SOCKET → CreateIoCompletionPort → WSARecv(OVERLAPPED)
PHP 侧：$h = drift_net_connect('127.0.0.1', 9000);  // 得到 int 句柄
        drift_net_read($h);                          // 读字节，不是 stream
```

这会**替换掉 PHP 的整个 net 层**（`stream_socket_client`、`fopen('http://')`、
`curl` 全部失效或需要重写），并且 `ProcessTaskHost` 的 socketpair IPC 也要跟着改。

**量级判断**：这不是「一个台阶」，是「重建一层」。**只有在出现「自建长连接 /
高并发 IO / 或 IPC 通道成为瓶颈」的真消费者时才值得做。今天没有。**

> ★ 诚实补一句：`curl_multi`（`docs/ASYNC-2026-10-30.md` §2 推荐的 L3 方案）
> **内部就是 libcurl 的 `curl_multi_socket_action` + IOCP**（那篇文章自己写的）。
> 所以「用 curl_multi」在效果上已经拿到了 IOCP 的收益，**只是它由 libcurl 管，
> 不由我们的泵管**。这是当前性价比最高的 IOCP 用法。

---

## 5. 跨平台对照：三种形态，不是一种

用户要求跨平台，这一节是评估的重心。**结论先给：Windows 是异类，Unix 系天然统一，macOS 是第三种形态。**

### 5.1 五种后端 × 五个维度

| 后端 | UI 事件源 | 可等待原语 | IO 能否并入同一次等待 | 定时器精度 | 结论 |
|---|---|---|---|---|---|
| **Win32** | `MSG` 队列（**不是 fd**） | `MsgWaitForMultipleObjectsEx` | ⚠️ 需 `WSAEventSelect` 把 socket 转成 event（§4.2） | `SetTimer` **~15.6ms 量化** | 唯一异类 |
| **Linux / X11** | `ConnectionNumber(dpy)` → **一个 fd** | `poll()` | ✅ **天然**：UI fd 与 socket fd 同一数组 | `timerfd` **亚毫秒** | 理想形态 |
| **Linux / Wayland** | `wl_display_get_fd()` → **一个 fd** | `poll()` | ✅ **天然**，同 X11 | `timerfd` 亚毫秒 | 理想形态 |
| **macOS / Cocoa** | `NSEvent` 队列 + `CFRunLoop` | `CFRunLoopRunInMode` | ✅ `CFSocket` / `dispatch_source` 作为 run loop source | `DispatchSourceTimer` **亚毫秒** | 第三种形态 |
| **Android**（设计目标） | `ALooper` | `ALooper_pollAll(timeout, &fd, ...)` | ✅ **天然**：`ALooper` 本来就是「消息 + fd」混合 | `ALooper` 支持亚毫秒 | 理想形态 |

### 5.2 三种形态的本质

```
形态一：一切皆 fd        Linux / Wayland / Android / (X11)
        └─ 「等 UI」和「等 IO」是同一件事：poll(fd 数组)

形态二：消息队列 + 内核对象  Windows
        └─ 只能靠 MsgWaitForMultipleObjectsEx 把两者拼进一个调用

形态三：Run Loop          macOS / iOS
        └─ UI 事件源是 run loop 的「模式」，IO 靠往里加 source
```

**这三种形态无法用同一个底层调用覆盖** —— 这就是为什么抽象必须放在
「平台提供 `waitEvents(timeout)`」这一层，而不是「框架统一用 `stream_select`」。

### 5.3 一个诱惑，以及为什么不能选

最省事的想法是：**让 PHP 的 `stream_select` 等一切**，把 UI 事件源也包装成 stream。

- Unix：**可行**。X11 的 `ConnectionNumber()` 就是 fd，Wayland 的
  `wl_display_get_fd()` 也是，包装成 `php://fd/N` 就能进 `stream_select`。
- Windows：**不可行**。消息队列不是 fd，没有「消息 fd」这种东西。
  唯一能凑的是「自己开一个 socketpair 当唤醒通道」，但**另一端要有线程往里写** ——
  而本仓没有可用线程（`docs/ASYNC-2026-10-30.md` §1 的线程天花板）。

⇒ **PHP 层统一 = Windows 上做不到**。所以抽象必须下沉到平台层。

### 5.4 跨平台能力不对称（必须诚实写出来）

| 能力 | Unix 系 | Windows |
|---|---|---|
| 零轮询（IO 就绪立刻唤醒） | ✅ 可直接做到 | ❌ 需要 C++ net 层（§4.4） |
| 4ms 级等待精度 | ✅ | ⚠️ `MsgWait` 本身支持毫秒超时，但唤醒源仍是轮询 |
| 亚毫秒定时器（动画） | ✅ `timerfd` / `DispatchSourceTimer` | ❌ `SetTimer` 15.6ms 是硬地板 |
| 一次等待的 fd 上限 | ✅ `poll` 无硬上限（epoll 更高） | ⚠️ `FD_SETSIZE=64`（`stream_select`） |

**这意味着「同一份应用源码在各平台表现不同」是既成事实**，不是可以靠抽象抹平的。
正确做法是：**抽象保证语义一致（`watch` 之后一定能收到回调），性能差异如实登记进能力矩阵**
（`docs/design-v7/08-平台与能力矩阵.md` 的 `SupportLevel` 三态正是为此存在）。

---

## 6. 跨平台抽象：`waitEvents(timeout)`

### 6.1 接口形状（增量，不破坏 `pump()`）

```php
interface Platform
{
    // 今天就有，保留 —— 等价于 waitEvents(-1)，桌面单循环的默认形态
    public function pump(): bool;

    // 新增：带超时的等待。返回 false = 退出；超时返回 true（循环继续）
    public function waitEvents(float $timeoutMs = -1.0): bool;

    // 新增：非阻塞取走当前事件（移动端 / 有帧要画时用）
    public function pumpEvents(): bool;
}
```

★ 为什么是**增量**而不是改建 `pump()`：`pump()` 已经在 5 个示例应用和 smoke 里被调，
改建是纯破坏；而 `waitEvents(-1)` 与 `pump()` **语义完全等价**，可以让
`pump()` 保留为薄委托。兼容性破坏留给**真的需要时**，不为一次性便利滥用。

### 6.2 五后端实现草案

| 后端 | `waitEvents($ms)` 实现 |
|---|---|
| **Win32** | `MsgWaitForMultipleObjectsEx(0, NULL, $ms, QS_ALLINPUT, MWMO_INPUTAVAILABLE)` + 收到消息后 `PeekMessage` 循环排空。<br>★ `$ms = -1` 时传 `INFINITE`，与今天 `GetMessageW` 等价。<br>★ **注意**：这里只等消息，不等 IO（拿不到 SOCKET）⇒ 有 IO 挂起时必须传有限超时。 |
| **X11** | `poll([ConnectionNumber(dpy)] + [所有注册的 IO fd], $ms)`；X fd 就绪 ⇒ `XPending`/`XNextEvent`；IO fd 就绪 ⇒ 回调。<br>★ **这就是零轮询**：一个 `poll` 同时等两者。 |
| **Wayland** | 同上，UI fd 换 `wl_display_get_fd()`；就绪后 `wl_display_dispatch` / `wl_display_flush`。 |
| **Cocoa** | `CFRunLoopRunInMode(kCFRunLoopDefaultMode, $ms/1000, true)`；IO 用 `CFSocketCreateWithNative` 或 `dispatch_source_create` 挂进 run loop。 |
| **Android** | `ALooper_pollAll($ms, &outFd, &outEvents, &outData)` —— 天然返回「哪个 fd 就绪」。 |

### 6.3 与 `IoPoller` 的关系

`IoPoller` 的**应用 API 不变**（`watch` / `unwatch` / 回调）—— 它是跨平台一致的。
变的只是「什么时候调 `poll()`」：

- 今天：定时器叫醒 ⇒ `frame()` ⇒ `poll()`
- Unix 上将来：`waitEvents` 返回「IO fd 就绪」⇒ 只调 `poll()`，**不跑 `frame()`**
- Windows 上将来：`waitEvents(4ms)` 超时 ⇒ 调 `poll()`，**不跑 `frame()`**

★ **关键在最后四个字**：`poll()` 和 `frame()` 必须**解耦**。这就是 §7。

---

## 7. 但顺序必须反过来：先做「泵帧分离」

### 7.1 为什么不能先做 `waitEvents`

`docs/design-v7/09-帧与异步.md` §4.2 要求「有挂起 IO ⇒ `waitEvents(4ms)`」。
如果照做，Windows 上的唤醒频率会从 **64Hz 涨到 250Hz**，而每次唤醒仍然跑完整
`frame()`（paint 无跳过）⇒ **CPU 从 22% 涨到 ~85%**。

**在没解耦之前换等待机制 = 性能倒退。** 这是本次评估最反直觉的一条。

### 7.2 要做的事：给 `Frame::run` 的第三段补跳过判据

现在 `src/App/Frame.php:160` 的三段判据不对称（§1.2）。第三段要补的判据是
**「本帧有没有像素变化」**，条件比 `wantsFrame()` 更严格：

```
needsPaint =
    $didRebuild                                  // settle 重建过（结构/状态变了）
 || $this->laidOut && 本帧跑过 layout            // 布局变了 ⇒ 位置/尺寸可能变
 || 浮层集合变化（层数 / 位置 / 内容）
 || 焦点环目标变化（paintedFocus !== focusRingTarget）
 || 显式 invalidate（Canvas 动画 / 图片异步解码完成）
 || surface 尺寸变化
```

★ 这几条**不是新发明**：`wantsFrame()` 里已经有其中五条（`src/App/Application.php:1050`）。
区别是 `wantsFrame()` 回答「要不要醒」，`needsPaint` 回答「醒了要不要画」——
**前者为真而后者为假，正是「为了等 IO 而空转重绘」的那种帧。**

### 7.3 风险与判据

| 风险 | 判据 / 缓解 |
|---|---|
| 该画没画 ⇒ 画面僵住 | smoke 加一条：**watcher 存在 + 无脏位 ⇒ `paintCount` 不增长**；**有脏位 ⇒ 必须增长**（双向） |
| 焦点环漏画 | 焦点环已进 `wantsFrame()`，同判据搬过来即可 |
| 浮层（Popup / Tooltip / Snackbar）漏画 | 浮层布局段已有 `panelsLagBehind()`，复用 |
| 真机看不出差别 | **这是好事**：本刀的目标就是「看不出差别，但 CPU 降下来」；判据用 `FrameClock::stallCount()` + 任务管理器 CPU |

★ **验收方式**：不用真机截图对比（画面应逐像素相同），用 **CPU 占用 + `paintCount`**。
这两条都是可自动化/可观测的，**不需要反复真机取证**。

---

## 8. 路线图

四步，按「成本 / 收益 / 跨平台一致性」排序。**每一步都能独立收口，不半途而废。**

### L1 · 泵帧分离（建议下一步就做）

- **做什么**：§7.2 的 `needsPaint` 判据 + `Frame::run` 第三段跳过。
- **改哪里**：`src/App/Frame.php`（主）、`src/App/Application.php`（判据来源）、
  `tools/smoke.php`（双向判据）。
- **跨平台**：**完全一致**（纯 PHP，与平台无关）。
- **收益**：22% → 接近 0（有 IO watcher 但无数据时）。
- **风险**：低。零 C++ 改动、零真机必需（CPU 判据可观测）。
- **前置**：无。

### L2 · `waitEvents(timeout)` + `pumpEvents()`

- **做什么**：§6.1 的接口 + Win32 实现（`MsgWait`）+ `FrameClock` 改用它。
- **改哪里**：`src/Platform/Platform.php`、`src/Platform/Win32/Win32Platform.php`、
  `cpp-src/drift_win32.cc`（新增一个桥 `drift_win_wait(timeoutMs)`）、`FrameClock`。
- **跨平台**：接口一致，实现各自；此时只有 Win32 后端，其它平台写在能力矩阵里。
- **收益**：24h 空闲时 CPU 真正归零（今天靠定时器也是 0，但**有 watcher 时不是**）；
  为 L3 铺路。
- **风险**：中。桥函数新增 = 要改 `stub.php` + `cc` + 构建；但**行为等价**
  （`waitEvents(-1)` ≡ `pump()`），真机只需验「没坏」。

### L3 · IO 并入等待（Unix 先行，Windows 待定）

- **做什么**：
  - **X11 / Wayland**：`waitEvents` 把 UI fd 与 IO fd 放进同一个 `poll` ⇒ **零轮询**。
  - **Windows**：**维持有限超时轮询**（15ms 或 4ms），并在能力矩阵里登记
    `zeroPollIo: Partial(Windows)`。
- **前置**：L2 完成；以及一个**新平台后端**（Linux/macOS）真的存在。
- **收益**：Unix 上从「15.6ms 轮询」到「事件驱动」。
- **风险**：中。新后端本身就是大工程，这一条**跟着后端走，不单独做**。

### L4 · C++ 自建 net 层（IOCP）

- **做什么**：§4.4 的 `drift_net_*`。
- **前置**：**出现真消费者** —— 自建长连接 / 高并发 IO / IPC 通道成为瓶颈，三者之一。
- **今天不做的理由**：零消费者（`IoPoller` 在 `apps/` 里至今**零使用**，
  唯一调用方是 `tools/smoke.php:4257`）。按仓储纪律「未被消费的分支 = 未实现」，
  给零消费者的通道重建 net 层是纯粹的投机。

### 8.1 与另两册的关系

| 册 | 主题 | 与本册的接口 |
|---|---|---|
| `docs/MULTI-WINDOW-2026-10-30.md` | 多窗口 | 本册的 `waitEvents` 是**多窗口 M0/M1 的同层工作**（都在壳的消息泵）；两册可合并成一次 C++ 改动，但**不必互相等待** |
| `docs/ASYNC-2026-10-30.md` | 异步 / 并发 | 本册解的是「IO 等待的底座」；那册解的是「任务怎么表达」。L3 并发请求走 `curl_multi`（那册），**与本册的 `IoPoller` 无关** |

★ **一句话理清三者**：
- 慢任务不卡 UI → `TaskHost`（已落地，`docs/ASYNC-2026-10-30.md`）
- 同时请求多个接口 → `curl_multi`（待验，同上）
- **等 IO 时不要空转重绘 → 本册 L1**
- 多窗口 → 另一册

---

## 9. 判据与未验项

### 9.1 L1 的验收判据（可直接写进 smoke）

1. `watchCount() == 1` 且**无任何状态变更** ⇒ 连续 10 帧后 `paintCount` **不变**。
2. `watchCount() == 1` 且 `setState()` ⇒ 下一帧 `paintCount` **必须 +1**。
3. `watchCount() == 0` ⇒ 行为与今天**逐位相同**（既有 431 条 smoke 就是这条判据）。
4. `Frame::run` 的 `settle` / `layout` / `paint` 三段跳过判据**互不干扰**
   （任一段为真都不得导致另一段该跑没跑）。

### 9.2 未验项（诚实）

- **没跑真机 CPU 对比**：22% 是「3.4ms × 64Hz」的推算，不是任务管理器读数。
  L1 落地时应实测一次（这是**可量化**的，不是"感觉变快了"）。
- **没验 `MsgWaitForMultipleObjectsEx` 在本仓 AOT 产物下的行为**：
  `MWMO_INPUTAVAILABLE` 与 `PeekMessage` 的配合有细节（消息已在队列里时不等待）。
  L2 的第一件事就是这个探针。
- **没验 macOS / Linux 后端**：本仓只有 Win32（`src/Platform/Win32/`），
  §5 的其它四列是**按平台 API 语义填的**，不是实测。
- **没量化 `present` 的真实成本**：493MB/s 是「尺寸 × 帧率」的算术，
  实际带宽受 DWM / 显卡影响。`SurfaceProfile` 能给出真实分段耗时。

### 9.3 这一册没做的事

- **没改任何代码**：本册是裁决依据。`src/`、`cpp-src/`、`apps/` 一字未动。
- **没重新设计 `Io::await`（Fiber 版）**：那是 `docs/design-v7/09-帧与异步.md` §4.2 的活，
  本册只指出它没落地，没评估它该不该落地（那取决于有没有「顺序代码写异步」的消费者）。
- **没给 `waitEvents` 定默认超时值**：4ms 是设计册的数，但它在 Windows 上会导致
  250Hz 唤醒 ⇒ **必须等 L1 落地后**重新评估这个数字。

---

## 10. 修订记录

- **2026-10-30 初稿（Windows 视角）**：按「IOCP + MsgWait」的可行性评估，
  结论是「技术上成立，但拿不到 SOCKET」。
- **2026-10-30 修订（用户追加「要跨平台」之后）**：这次约束让结论发生三点实质位移：

  1. **抽象位置变了**：从「评估 Windows 的等待原语」变成「设计跨平台的
     `waitEvents(timeout)`」。Windows 的特殊性从「主战场」降级为「三种形态之一」，
     并且是**最不利的那一种**（消息队列不是 fd、定时器 15.6ms 硬地板、
     `FD_SETSIZE=64`）。
  2. **优先级变了**：在只考虑 Windows 时，「换等待机制」看起来是主要收益；
     考虑跨平台后，**「泵帧分离」才是唯一一个全平台一致、零平台依赖的收益点**，
     而且它是 `waitEvents` 的**前置条件**（§7.1：不分先后会性能倒退）。
  3. **诚实边界变了**：§5.4 必须承认「同一份源码在各平台表现不同」不可抹平 ——
     这不是抽象失败，是能力矩阵存在的理由。

★ 这条修订值得记一笔：**先看最有特色的那个平台，会把「它的特殊性」误当成「问题的全貌」**。
反过来，先看最具共性的形态（Unix 的一切皆 fd），再去理解异类，才看得出
「真正的抽象应该切在哪一层」。
