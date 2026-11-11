# watermark DX 根本方案裁决：线性异步控制流 —— 兼 Swoole / Swow 新事实核查（2026-11-04）

> 用户输入三条：① 对比成熟 GUI，`apps/watermark` 对真实应用开发者体验仍不好、代码量大、
> 心智负担重，是否有更好的方案；② 可考虑 Swoole / Swow 两个协程框架；
> ③ 给出 2026-05-15「Swoole 已支持原生 Windows」文章链接，并补充本机扩展目录
> `D:\TypePHP\typephp-windows-x64\ext`。
>
> 方法：先核实新事实（含本机实测）→ 逐条对账本仓既有裁决 → 五维评估 → 再定方向。
> 口径：行数为 2026-11-04 仓库实测；Swoole 数据为本机
> `PHP 8.5.11 ZTS / swoole 6.3.0RC1 / iocp enabled` 实跑，不是文档转述。
> 本棒**不改 `src/`**：按纪律「改语义先出方案等裁决」，§5 三道门未验完前不立项实现。

---

## 0. 结论先行

| # | 结论 | 性质 |
|---|---|---|
| 1 | 用户给的不是新信息：`docs/ASYNC-2026-10-30.md` §1.3 **早已记录** 2026-05-15 的 IOCP 原生 Windows 发布；本机 `ext/php_swoole.dll` 就是它，AOT 产物里也已加载过（探针 L ①） | 事实核查 |
| 2 | 但旧文档有**两处事实错误**需勘误：①「Windows 不提供 `Swoole\Event`」——官方矩阵列为**支持**；②「文章说的 AOT 与 tpc 是两套东西」——`swoole.com/aot` 就是 TypePHP / tpc 本身 | 文档反腐 |
| 3 | **结论不变**：卡点从来不是「有没有 Windows 版 / 有没有 `Event`」，而是 `Event::dispatch()` 做不到 0 超时 pump。本轮本机实测：空转 **0.000–0.001ms**，注册 1000ms timer 后**第一次阻塞 1013ms** | 实测（本轮新增） |
| 4 | 融合方案仓库里**已验过**：探针 N 把消息泵搬进 `Coroutine\run()`，1.2s 慢 HTTP 期间 UI tick 111 次 / avg 16.12ms。代价是重写 C++ 消息泵 + `curl_multi` 与 swoole 同产物退出期 SegFault | 已有证据 |
| 5 | **watermark 3457 行的根因不是缺控件，是缺线性控制流**：一条 5 步流水线被拆成「状态机 + 事件回调 + 跨进程序列化」三份。§29 补结算缝净减约 5 行，**没有回答用户的问题** | 诚实结论 |
| 6 | **裁决：不引入 Swoole / Swow。走 PHP 内置 Fiber + 现有非阻塞 IO 层，补一个 `await()`**。仓库自己预留了钩子（`IoPoller` 注释、`probes/RESULTS.md` §12.2 结论「用 Fiber，不用 Swoole 协程」），watermark 就是那个「真有消费者」 | 本轮裁决 |
| 7 | 立项三道门：lint 禁 Fiber 的理由与 AOT 实测**矛盾**（探针 `[I] terminated=yes`）、取消语义、`#[\Native]` 类不能作 Fiber 参数 | 待验，不先动代码 |

> ★ **本棒（第二棒）已把三道门处理完**：门 1 全绿（AOT 真机 14/14）⇒ `Fiber` 解禁、
> `AsyncScope` 落地；门 2 由 `AsyncScope::abort()` + 三组变异钉住；门 3 **不适用**
> （`#[\Native]` 本仓零使用）。详见 §8；PHP 8.6 的 `Io\Poll` RFC 定位见 §9。

「可破坏兼容」是授权不是义务；这一棒连 `src/` 都不碰，因为**方向本身要先被裁决**。

---

## 1. 新事实核查

### 1.1 扩展目录：本机装的就是文章说的那个版本

```
D:\TypePHP\typephp-windows-x64\ext\php_swoole.dll   2,576,896 B   2026-10-17 23:24
```

`php.ini` 加载顺序：`curl → mbstring → openssl → zip → swoole`。
★ 与 `probes/RESULTS.md` 探针 L ① 记录一致：**swoole 依赖 curl，顺序不能反**
（`extension=curl` 在 919 行，`extension=swoole` 在 950 行）。

`php --ri swoole` 关键行：

```
Version => 6.3.0RC1          Built => Oct 17 2026 23:20:32
coroutine => enabled with boost asm context
thread => enabled
curl-native => enabled
iocp => enabled
iocp_socket => enabled
```

⇒ **这是原生 Windows IOCP 版，不是 Cygwin / WSL / 兼容层**。文章描述的
`SW_USE_IOCP` + `SW_USE_IOCP_SOCKET` 两项在本机都已启用。

**Swow：本机未安装**。`ext/` 下无 `php_swow.dll`，`php -m` 无 `swow`。
⇒ 关于 Swow 的任何结论都只能来自二手资料 + 结构推理，**没有本机实测**（§2.3 会标清）。

### 1.2 文章与官方能力矩阵互相矛盾

| 项 | 微信文章（识沃科技，2026-05-15） | 官方 `windows-native-support.md`（`win32` 分支） | 本机实测 |
|---|---|---|---|
| `Swoole\Event` | ❌ 不支持，「事件循环 API，Windows 不提供」 | ✅ **支持（有限制）**：`add/delete/set/write/defer/cycle` + `wait()` 作为事件循环显式入口；迁移指南写明「用原生 Windows `Swoole\Event`」 | ✅ 类存在，`dispatch()` 可调 |
| 底层 | IOCP + Overlapped I/O | IOCP + **AFD readiness polling** 为 `Event` 提供 Winsock 就绪通知 | `iocp_socket => enabled` |
| 协程 / `Co\Http\Client` | ✅ | ✅ | ✅ |
| `Swoole\Process` / `Server` | ❌ | ❌ | — |

★ 官方矩阵原文：**"The public event API is available with native Windows limitations:
`Swoole\Event` … `Swoole\Event::wait()` as an explicit entry point for the Windows event loop"**。

⇒ 微信文章那张「❌ 不支持」表**不精确**，本仓旧文档引用它写的那句「Windows 原生版
不提供 `Swoole\Event`」随之失效。

另外，官方 wiki 对 `dispatch()` 的语义说明正是本仓要的那一句：

> `dispatch()` **仅执行一次 `reactor->wait` 操作**……此函数的目的是兼容一些框架，
> 如 `amp`，它在框架内部自行控制 reactor 的循环，而使用 `Event::wait`，
> Swoole 底层维持了控制权，就无法让出给框架方。

即：**这个概念上就是「每帧 pump 一轮」的 API**。所以旧裁决的推理链里
「没有这个粒度」这一环，需要换一个更准的理由（§2.2）。

### 1.3 旧文档的两处勘误

| 位置 | 原文 | 勘误 |
|---|---|---|
| `docs/ASYNC-2026-10-30.md` §1.3 | 「**`Swoole\Event`（事件循环 API）** ❌ **Windows 不提供** ⇒ 没有手动 pump」 | 官方矩阵列为**支持（有限制）**；本机 `Event::dispatch()` 可调用。改为：**有 API，但语义不满足 0 超时**（§2.2 实测） |
| 同 §1.3 末 | 「它说的 AOT 与 tpc 是**两套东西**」 | **错**。`swoole.com/aot` 是 TypePHP 编译器官网，`github.com/swoole/typephp` 即本仓 `tpc`；官网示例 `./tpc hello.php -O2 -o hello` 与本仓构建链同名同形。应为**同一套** |

★ 第二处尤其要紧：文章第七节「配合 PHP AOT 编译器，利用原生 Windows 线程/协程模型
实现异步非阻塞的桌面 GUI」几乎直指本仓场景。旧文档以「两套东西」把它挡回去了，
这个挡法不成立。但**宣称仍不构成可用性证据** —— 真正要验的组合是
「tpc 产物 + 帧循环 + 协程调度器」，而这一组仓库后来已经验过（§2.4）。

还有一处口径要收紧：`docs/ASYNC-ARCH-2026-10-31.md` §3.1 与
`docs/EVENT-LOOP-DESIGN-2026-10-30.md` §3.1 沿用了「Windows 版**没有**
`Swoole\Event`」的措辞。两处同属**论据过期**，其结论（UI 进程不跑 Swoole 协程）
在 §2.2 的新实测下仍然成立，但理由必须改写，否则下次会被同一篇文章再推翻一次。

---

## 2. 为什么 Swoole / Swow 都不是 watermark DX 的答案

### 2.1 真正的卡点始终是控制权

`docs/ASYNC-2026-10-30.md` §1.2 的判断**是对的**：

- Drift2 帧循环是 `GetMessageW()`（阻塞等窗口消息）→ `Application::frame()`；
- Swoole 协程的挂起/恢复由它自己的 loop 驱动，进入 `Coroutine\run()` /
  `Scheduler->start()` 后主线程控制流归它；
- 两个调度器抢同一根控制流。

「Windows 支持不支持」**从未出现在这条推理链里**。用户给的新事实打击的是
旧文档的**一个论据**，不是**结论**。

### 2.2 本轮实测：`dispatch()` 有 API，但没有 0 超时语义

本机 `swoole 6.3.0RC1`，两条 `php -r`：

| 实验 | 结果 |
|---|---|
| 连续 5 次 `Event::dispatch()`，**无任何注册事件** | `0.270 / 0.001 / 0.000 / 0.000 / 0.001 ms` ⇒ 立即返回 |
| `Timer::after(1000, …)` 后连续 3 次 `dispatch()` | **`1013.146 ms`**（阻塞到 timer 到期并触发）→ `15.127 ms` → `0.001 ms` |

⇒ `dispatch()` 的 timeout **由下一个到期事件决定**，不是调用方可控的 0。
把它挂进 60fps 帧循环（16.7ms 预算）时，**只要有一个 1s 定时器在册，
那一帧就卡 1 秒**。这正是 `probes/RESULTS.md` §12.2 探针 H 记录的
「单帧 57ms」的成因，也是 `IoPoller::poll()` 用 `stream_select(..., 0)`
而不用它的原因 —— 后者**是**调用方可控的 0 超时。

★ 这条实测把旧裁决从「引用一篇有错的文章」升级为「本机可复现的语义事实」：
**不是没有 pump，是 pump 不肯 0 超时返回。** 结论不变，理由变硬了。

### 2.3 Swow：同样的 loop 结构，更高的验证成本

Swow 基于 `libcat`（libuv 衍生）自建事件驱动，Windows 支持早于 Swoole 原生版，
编程模型同样是「协程 + 自有 event loop」。对本仓而言：

| 维度 | 判断 |
|---|---|
| 控制权 | ⛔ **同样的冲突**：`Swow\Coroutine::run()` / event loop 一样要吃主线程；没有证据表明它能被外部帧循环以 0 超时驱动 |
| AOT 兼容 | ❓ **零实测**。本仓探针只验过 `swoole 6.3.0RC1` 能进 tpc 产物（探针 L ①）；`php_swow.dll` 本机都没有，要重走整套探针 L（加载 / 类表 / 线程上下文 / 退出期） |
| 分发 | ⛔ 多一个扩展 DLL，破坏单 exe 少依赖的现状；且 Swow 无 PECL 包，部署要手工带二进制 |
| 收益 | 与 Swoole **同构**：都只解决「IO 让出」，不解决本仓真正的缺口（§3） |

⇒ **Swow 不比 Swoole 更接近目标，只是未验项更多**。按「先有痛感再做 / 无消费者不发明机制」，
不立项。用户提它是合理的方向探索，但两条路都指向同一个已经验过的结论。

### 2.4 仓库已经验过「融合可行」，但代价不划算

`probes/RESULTS.md` §13.1（探针 N，`probes/coroutine_win32/`）把旧文档
§1.2 标为「能做，但未验」的唯一融合方式验掉了：

```
[N3] ticks=111 avgMs=16.12 maxGapMs=59.21 longGaps(>50ms)=1
     msgs=14 ioDoneAt=1271.2ms body=slow-ok
```

1.2s 慢 HTTP 期间 UI tick 111 次、平均 16.12ms、只有 1 次 59ms 尖峰（**未定位**）。
§13.2（探针 P2）还解除了「协程里调 tpc 编译的类」这条硬前提（`tpc-fn=42` /
`tpc-class=7` / `inner=12` / `wallet=650` 四项全对）。

**但代价是硬的四条**：

1. 重写 C++ 消息泵：`GetMessageW()` → `PeekMessage()` 非阻塞 + `Co::sleep(0)` 让出，
   「没活就睡」没了，空闲也要按固定间隔轮询；
2. `docs/ASYNC-ARCH-2026-10-31.md` §14.1：`curl_multi_init()` 在本运行时一次即
   `exit 139` ⇒ A1 已判死；而探针 P2 的 P5 又发现 `curl_multi` 与 swoole
   **不能共存于同一 AOT 产物**（运行期正常、退出期 Segmentation fault）；
3. `Runtime::enableCoroutine()` 的全局 hook 会把 `stream_select` / `fread` 换成协程版
   （实测 `[J3-a]`：非协程上下文直接抛 `API must be called in the coroutine`）；
   走 `Co\Http\Client` 不开 hook 可避开，但 `IoPoller` 与整套 `src/App/Io/` 就白做了；
4. `docs/AOT-CONSTRAINTS.md`：`#[\Native]` 类不能进 PHP 数组 / 不能作 Fiber 参数。

⇒ **即使把 UI 进程整个协程化，watermark 的 3457 行也不会自动变短**：
它要换掉的是 5 个 `AsyncCommand` 与 5 套 `absorb`，而协程只是让 IO 不卡，
**不提供状态机、去重、取消、跨进程序列化**这些框架义务。

> ★ **§10 做了这件事**，用的是 `Fiber`（`AsyncScope`）而不是 Swoole 协程 ——
> 换掉的正是那 5 个命令与 5 套 `absorb`，而状态机/去重/取消仍由 `AsyncCommand`
> 承担（`AsyncScope` 只是把 5 步写成一段顺序代码，返回的还是普通 `Future`）。
> 上一句的"协程不提供框架义务"依然成立：它提供的是**控制流**，其余一样没少。

---

## 3. DX 根因：一条线性流水线被拆成三份

### 3.1 三个锚点（2026-11-04 实测）

| | 原始网页 | 成熟 GUI（Flutter / SwiftUI / React / Qt） | Drift2 `apps/watermark` |
|---|---|---|---|
| 体量 | **713 行** HTML+CSS+JS，核心逻辑约 400 行 | 同量级或更少（框架自带资源原语） | **3457 行**源码（`Store 980` 其中 `WatermarkStore.php 830` / `Api 694` / `Jobs 477` / `Widgets 1105` / `root 201`） |
| 5 步流水线 | 一段线性 `async/await` | `async/await` / `.task {}` / `FutureBuilder` / `QNetworkAccessManager` + signal | `5 AsyncCommand` + `5 NamedJob` + `5 absorb` + `15 SubjectBuilder::make` |
| 选图 / 图片 | `<input type=file>` / `<img src=url>` 原生 | `FilePicker` + `Image.file/network` 一等公民 | 手写选择器、图片字节落临时文件、`fromArray()` 跨进程搬运 |
| 轮询 | 一个 `setInterval` | `Timer.periodic` / `Task.sleep` 在协程里就是一行 `sleep` | 定时器 + `polling` 标志 + 终结态门控 + 双排 bug 修复注释 |

⇒ 多出来的约 2700 行，**大头不是业务，是把网页天然 ergonomics 在框架里重写了一遍**。
其中「线性控制流缺失」是唯一跨 `Store / Jobs / Widgets` 三层同时收税的那一项。

### 3.2 拆成了哪三份

同一条业务流水线「选图 → 取签名 → 上传 → 建任务 → 轮询 → 下载」：

| 份 | 落点 | 开发者要手写的东西 |
|---|---|---|
| ① 状态机 | `5 × AsyncCommand` | 四态、去重、取消、旧请求作废、进度通知；每一步一个命令 |
| ② 事件回调 | `5 × absorb` + `15 × SubjectBuilder::make` | 「这一步完了触发下一步」的接线，且必须自己判断「这一趟是不是结算」 |
| ③ 跨进程序列化 | `5 × NamedJob` + 注册表 + `fromArray()` | 每个步骤一个可 JSON 边界的类、`name()` + `args()` 重建、返回值只能数组 |

成熟 GUI 里这三份分别是：**语言运行时（`async/await`）**、**框架状态原语**、
**平台的 IO/资源层**。Drift2 只把第 ③ 份的一部分（`JobRunner` / `TaskHost`）下沉了，
①② 仍在应用层，于是**每一步都要写三遍**。

### 3.3 诚实结论：§29 的结算缝不算改善 DX

`AsyncCommand::subscribeSettledFor()` 消掉的是「`cancel() → data` 假阳性」这一整类
静默 bug，`WatermarkStore.php` 净减约 5 行、删掉两处 ★★ 大段 bug 注释。
**它交付的是正确性，不是 DX。**

用户对「3457 行 / 心智负担重」的判断依然成立。若把这一棒当成 §27② 的交付，
就又犯了「拿机制小修冒充能力下沉」的错 —— 这正是本裁决要挡的。

---

## 4. 更优方案：Fiber + 现有非阻塞 IO 层

### 4.1 仓库自己预留了这个钩子（三处原文）

| 位置 | 原文 |
|---|---|
| `probes/RESULTS.md` §12.2 | 「**结论：协程可以用，但用 Fiber，不用 Swoole 协程**。Swoole 的协程绑定它自己的 reactor（`Event::dispatch()` / `Event::wait()`），与 Win32 消息泵是两个 loop；它没有『0 超时 pump reactor』的 API。**Fiber 是用户态协程，`suspend()` 立即返回，调度权在 UI 帧循环手里**。」 |
| `src/App/Io/IoPoller.php` 头部 | 「**第一版刻意不做的事**（避免『没有消费者就发明机制』）：**不做通用 Fiber 调度器**：`Awaitable::read()` 那种『顺序代码』包装**等真有消费者再加**」 |
| `docs/EVENT-LOOP-DESIGN-2026-10-30.md` §3.2 | 「协程的三个卖点，两个本仓已经用别的东西解决了（并发 join = `Future::all`、取消传播 = 派生链上溯），**只剩『多步顺序代码』这一个**」 |

⇒ 三处合起来是一条完整的路：**watermark 就是那个「真有消费者」**，
而缺的那一格恰好是 `await()`。它不需要 Swoole / Swow，不需要第二个 loop，
不需要新扩展，也不会破坏单 exe。

★ 关键前提本仓已经把 IO 层做成了非阻塞：`IoPoller::poll()` 每帧
`stream_select(..., 0)`、`ProcessTaskHost` 用 socket 描述符（0.008ms/次）、
`Future` 只在帧内 `settle()` 之前结算。旧裁决「Fiber 不会让出阻塞 IO」
的前提是「IO 是阻塞的」—— **在 Drift2 里这个前提不成立**，Fiber 的短板
（没有配套 IO 层）恰好被现成的 `IoPoller` / `CompletionQueue` 补上了。

### 4.2 目标形状（**草案，未实现**）

现在（`WatermarkStore` 里 5 条链各写一遍）：

```php
$this->signCommand->subscribeSettledFor($this, fn () => $this->relayAfterSign());
$this->taskCommand->subscribeSettledFor($this, fn () => $this->pollAfterSubmit());
$this->pollCommand->subscribeSettledFor($this, fn ($v) => $this->scheduleAfterPoll($v));
// + 5 个 make / 5 个 absorb / 5 个 NamedJob / 轮询标志 / 接力标志 / lastResult …
```

目标（一条线性函数，`AsyncScope` 负责挂起与恢复）：

```php
public function run(array $files): void
{
    $this->scope->run(function () use ($files): void {
        $sign  = $this->scope->await($this->exec(new FetchSignJob()));
        $keys  = $this->scope->await($this->exec(new UploadBatchJob($sign, $files)));
        $task  = $this->scope->await($this->exec(new AddTaskJob($keys)));

        while (true) {
            $this->scope->await($this->sleep(2000));          // 不是阻塞 sleep
            $result = $this->scope->await($this->exec(new PollResultJob($task)));
            if ($result->isTerminal()) {
                break;
            }
        }

        $this->scope->await($this->exec(new DownloadResultsJob($result->urls)));
    });
}
```

`5 AsyncCommand` / `5 absorb` / 15 处订阅接线 / 轮询与接力标志 / 两处 ★★ 注释
整类消失。按 `原参考实现` 的比例，`Store` 有望从 980 行降到 200–300 行量级。

**必须点破的两个实现细节（否则是画饼）**：

1. **`await()` 不能在主帧调用栈里被直接调用**。`Fiber::suspend()` 返回到
   `Fiber::start()` 的调用点，所以框架必须在帧之外由 `AsyncScope::run()` 启动 Fiber，
   控制权回到 `Application::frame()`；`Future::resolve()` 在帧内触发 `resume()`。
   这与 Revolt / amp 的事件循环模型同构，**也意味着 `AsyncScope` 必须住在
   `Pipeline` 一侧**（一份应用一个），不能是 `Store` 的私有字段。
2. **取消**要自己造：PHP Fiber 没有原生取消，但 `Fiber::throw()` 可在恢复点注入异常。
   `AsyncScope::cancel()` 在下一次 `resume` 时抛 `CancelledError`，让 Fiber 走
   `finally` 收尾 —— 这与本仓 `Future::cancel()` 沿派生链上溯的语义要**对齐**，
   不能出现「scope 停了但 worker 还在跑」的僵尸。

### 4.3 五维评估

| | A 引入 Swoole 协程（UI 进程协程化） | B 引入 Swow | C 继续补机制（§29 路线） | **D Fiber + `await()`** |
|---|---|---|---|---|
| **结构** | 两个 loop 抢控制流，必须重写 C++ 消息泵；`IoPoller` 被架空 | 同 A，且引入第二套扩展 ABI | 状态机 / 回调 / 序列化三份仍在应用层 | 复用现有单 loop（帧循环）、`IoPoller` / `CompletionQueue` / `Future` 全部保值；`await()` 只是 `Fiber::suspend/resume` 的一层薄壳 |
| **实现** | 改 C++ 壳 + 全套 `src/App/Io/` + 构建链多带 DLL；P5 退出期崩溃待解 | 同 A + 重走整套 AOT 探针 | 每个机制各补一个缝 | 新增 1 个 `AsyncScope` + `Future` 上一个结算钩子；**零扩展、零 C++ 改动、零新 loop** |
| **扩展性** | 与 `curl_multi` 互斥、hook 全局污染、`#[\Native]` 约束 | 未知项更多 | 每加一类副作用要多一个缝 | `await()` 可覆盖任意 `Future`，将来叠 `awaitAll()` / `withTimeout()` 不改底层；`AsyncCommand` 可退化为「给 UI 看的加载态」而非业务骨架 |
| **易用性（DX）** | 写法变线性，但要理解协程 + 消息泵 + hook 三套心智 | 同 A | 仍要理解 5 命令 4 态 + 终结态门控 | **业务就是顺序代码**；新人从 `原参考实现` 迁移几乎逐行对应；取消 / 去重 / 状态由 scope 一处承担 |
| **维护性** | C++ 消息泵改动落在 T2 真机验证盲区；探针 N 那 1 次 59ms 尖峰未定位 | 无本仓实测，长期不可维护 | 注释密度继续上升 | 判据可纯 PHP 单测（挂起/恢复/取消/异常/跨帧），变异路径清晰；`AsyncCommand` 的四态判据原样保留作护栏 |

★ D 不是「更轻的协程」，是**换了一层**：Swoole/Swow 卖的是「IO 让出」，
本仓已经有了；缺的只是「顺序控制流」，而那是 PHP 内置 Fiber 就能给的。

### 4.4 成熟框架：借什么、排什么坑

**借**：

- JS `async/await` / Dart `Future` / Swift `.task` —— 线性控制流是语言/运行时给的，
  不是框架状态机；
- Revolt / amp（`EventLoop` + `Suspension`）—— 「Fiber 由外部 loop 驱动」是
  成熟形态，恰好匹配 `Application::frame()`；
- Kotlin 结构化并发 / Swift `Task` 取消 —— `AsyncScope` 持有子 Fiber 生命周期，
  取消沿 scope 传播，避免出现孤儿协程。

**排坑**：

- **不抄 Swoole `Runtime::enableCoroutine()`**：全局 hook 与本仓 0 超时 `stream_select`
  直接冲突（实测 `[J3-a]`）；
- **不抄 `Coroutine\run()` 当入口**：本仓唯一 loop 是帧循环（§2.1）；
- **不抄 Flutter `FutureBuilder` 当业务骨架**：那会把状态机又搬回 UI 层，
  正是 watermark 现在的样子；
- **不抄 Vue `v-model` / WPF `Binding`**：`docs/_archive/DESIGN-v2-2026-10-14.md` §11.4.2 四层论据、§18 三次否决；
- **不造 `pollUntil` / 步骤链 DSL**：`docs/DEBT-2026-11-02.md` §24.1 三条结构性理由不变 ——
  `await()` 是控制流原语，不是把某条业务链 DSL 化。

---

## 5. 立项三道门（先验再动，本棒不实现）

| # | 门 | 为什么要验 | 现状 |
|---|---|---|---|
| 1 | **lint 禁 Fiber 的理由与实测矛盾** | `tools/Lint/Rules/NanoHygiene.php` 的 `BANNED_TYPES = ['Generator','Fiber']`，注释写「C++17 无栈切换，无法承载」；`docs/ASYNC-ARCH-2026-10-31.md` §3.1 据此断言「编译目标不支持」。但 `probes/taskhost_swoole/app/main.php` 是 **tpc 编译的 AOT 入口**，里面 `new \Fiber(...)` + `suspend()` + `resume('io-ready')` 实测 `[I] … terminated=yes` | ❗**必须对账**：要么禁令理由是过时的 / 只针对特定构造，要么探针路径没走 tpc 编译。这是 D 方案的生死线 |
| 2 | **取消语义对齐** | `AsyncScope::cancel()` 必须让在途 `Future` 真停（`dropQueued` / `proc_terminate`），不能只是「不交付结果」 | 现有 `Future::cancel()` 已有派生链上溯，需验 `Fiber::throw()` 注入点 |
| 3 | **`#[\Native]` 类不能作 Fiber 参数** | `docs/AOT-CONSTRAINTS.md` 明文约束 | 验 `await(Future)` 的 `Future` 及值对象是否触碰该约束；必要时 `await` 只收纯 PHP 类型 |

★ 三道门与 §28 的「立项三道门（真实消费者 / AOT 口径 / 成对）」同构：
**真实消费者 = watermark 已存在**；AOT 口径 = 第 1、3 门；成对 = `await()` 必须与
`AsyncScope::cancel()` 一起交付，否则只是把静默 bug 从回调搬到协程。

---

## 6. 边界与不做什么

- 本棒**不改 `src/`、不改 `apps/`、不引入任何扩展**；只做事实核查与方向裁决。
- 不推翻 `docs/ASYNC-2026-10-30.md` 的结论（UI 进程不跑 Swoole 协程），
  但**改写其论据**并勘误两处事实（§1.3）。
- 不重开已判死议题：`bind()` / `Bound`、自动依赖收集、`pollUntil`、
  codegen、`Image::url()`、增量布局。
- 不把「宣称」当证据：文章第七节的桌面 GUI 说法、官方矩阵的「Supported」
  都只算输入；本仓结论只认本机实测与 AOT 探针。
- ~~`AsyncCommand` / `subscribeSettledFor()` **不删除**：即使 `await()` 落地，
  它仍是「给 UI 看的加载态」的合适原语，且 `src/Widget/State::asyncCommand()`
  与 `apps/users` 都在用。~~
  > ★ **2026-11-04 晚修正（§11）**：`AsyncCommand` 保留；`subscribeSettledFor()` 删了。
  > 上面那句的证据是错的 —— `State::asyncCommand()` 只造命令、`apps/users` 用
  > `subscribeFor()`，**两者都没调过结算缝**。
- 「Fiber 不让出阻塞 IO」这条**仍然成立**，只是本仓 IO 已非阻塞 ⇒ 前提不成立。
  文档里必须两句一起写，否则下次又会被单独引用成否决理由。

## 7. 验证计划（下一棒开工时）

1. **门 1 探针**：最小 tpc 工程，`Fiber` 包住跨帧 `suspend/resume` + tpc 编译类，
   验 `php -l` / 构建 / 真机退出码 0 / stdout 零 Warning；顺带确认 lint 规则该改还是该留。
2. **门 2/3 探针**：`AsyncScope::cancel()` 在 `Future` 在途时能否真停 worker；
   `await()` 参数含 `#[\Native]` 值对象时是否触发 AOT 约束。
3. 三道门全绿后，才在 `src/Async/` 落 `AsyncScope` + `Fiber` 调度壳，
   **先给 `apps/users` 用**（消费者更简单），再迁 `apps/watermark`。
4. 判据：挂起/恢复各一次、跨帧、取消注入异常、异常穿透不被吞、
   scope 销毁后不留孤儿 Fiber；变异至少 3 组（含「取消只标记不真停」）。
5. 全链：`lint` / `phpunit` / `smoke` / `docref` / `apidoc` / `gate` / AOT 真机 T2。

---

## 8. 第二棒：三道门的结果与 `AsyncScope` 落地（2026-11-04）

### 8.1 门 1：`Fiber` 在 AOT `-m bin` 里的十四条边界

探针工程 `probes/fiber_scope/`（`tpc --project … -O2 -m bin`，334 个翻译单元，
链接 `php8ts.lib` / `php8embed.lib` —— 与全仓应用同一种模式）。

| # | 边界 | 结果 |
|---|---|---|
| F1 | 跨帧多轮 `suspend/resume`（5 轮，值往返） | ✅ |
| F2 | Fiber 里操作 **tpc 编译出来的类** | ✅ |
| F3 | `throw()` 注入 ⇒ `finally` 执行、异常反弹、协程终结 | ✅ |
| F4 | 从**回调栈**（包三层函数）里 `resume()` | ✅ |
| F4b | 同一 Fiber 跨 4 趟回调持续让出 | ✅ |
| F5 | 两条 Fiber 交替推进 | ✅ |
| F6 | Fiber 内嵌套 Fiber | ✅ |
| F7 | 递归 60 层后 `suspend` | ✅ |
| F8 | `suspend/resume` 双向传**对象** | ✅ |
| F9 | 协程内 catch 注入的异常后**继续**让出 | ✅ |
| F10 | `Fiber::getCurrent()`（协程内返回自身、主栈 `null`） | ✅ |
| **F11** | **真实 `Drift\Async\AsyncScope` 三步流水线跨栈推进** | ✅ |
| **F12** | **取消打到在途 `Future` + `finally` + 协程终结** | ✅ |
| **F13** | **异常原样穿过协程边界（类型与消息不变）** | ✅ |

真机 `exit=0`，`PASS 14 / FAIL 0`。

**变异自检**（判据必须有牙）：改错 F1 期望值 ⇒ 精准 `[FAIL] F1` + `exit=1`，
其余 13 条仍 PASS。⇒ 这份探针不是恒绿壳。

★ F10 顺带否掉了一个隐患：`await()` 的"只能在协程内调"护栏若依赖
`Fiber::getCurrent()` 而它返回 `null`，护栏就是空壳 —— 实测可用，护栏成立。

★ F11–F13 是**跑要上线的实现**，不是另写一份样例：探针 `sources` 直接含 `../../src`。

### 8.2 门 3 不适用：`#[\Native]` 零使用

`grep -rn '#\[\\\\Native\]' src/` **零命中**。这条约束没有消费者 ⇒ 按
「没有消费者的规则只是审自己」的纪律，不验、不立项，只在
`docs/AOT-CONSTRAINTS.md` 那一行补一句"目前无消费者"。

★ 这条量账本身的结论比门本身值钱：**三道门里有一道是空的**，
如果不开工前核一遍，会为一个不存在的问题设计规避方案。

### 8.3 lint 裁决：`Fiber` 解禁，`Generator` / `yield` 保留

| 事实 | 出处 |
|---|---|
| nano 在 M0–M5 **明确不做**，重估时机"不设" | `docs/_archive/DESIGN-v2-2026-10-14.md` §16.7.5（2026-10-14） |
| 禁令定位是「**零成本**保留将来可切的可选性」 | `docs/_archive/DESIGN-v2-2026-10-14.md` §16.6.3 |
| 但 `Fiber` 这一条**不再零成本** —— 它挡住 `AsyncScope` | 本册 §3 |
| `--nano` 不支持 Fiber ⇒ 本仓一直走 `-m bin` | `docs/design-v7/11-编译器约束映射.md` §5.4 **早就写着** |

⇒ `BANNED_TYPES` 从 `['Generator','Fiber']` 改为 `['Generator']`。
**代价如实记账**：放弃"将来切 nano 消除多文件部署"这条可选性 —— 而它在
`docs/_archive/DESIGN-v2-2026-10-14.md` §10.2 代价 2 里**本来就是被接受的那笔账**。

★ 同一棒把这条规则从"**没有夹具**（`selftest` 里是空数组）"升级成双向判据：
`apps/NanoBad.php`（5 条 error：`\Generator` / `yield`×2 / `eval` / `getenv`）+
`apps/NanoGood.php`（`Fiber` 三种形态不报、`getenv` 作方法名不报）。
`lint --selftest` 现在是 `nano-hygiene 该响 5 / 不该响 0`。

### 8.4 `AsyncScope` 的 API 与三条不变量

```php
AsyncScope::task(callable $body, ?callable $delay = null): Future
AsyncScope::await(Future $future): mixed      // 成功取值，失败原样抛
AsyncScope::sleep(int $milliseconds): void    // 需要 $delay
AsyncScope::isCancelled(): bool
```

| 不变量 | 为什么 |
|---|---|
| **返回的就是一条普通 `Future`** | 去重 / 重试 / 取消 / 结算订阅 / `isRunning()` 全部照旧 ⇒ `MultiStepCommandTest` 的四条契约原样保住 |
| **`await()` 用 `then()->catch()` 一条链** | 分两次挂会派生出一条没人接的 Future，它一失败就走 Fatal 序列（`Future::rejectCore()` 的"链末端没人接"判据） |
| **取消必须 `Fiber::throw()`** | 只 `cancel()` 不投异常 ⇒ 协程永久挂在 `suspend()`，连同捕获的闭包一起泄漏，**界面上完全看不出来** |

`$delay` 走闭包注入而不是直接拿 `TimerHost`：`src/Async` **不许依赖 `Drift\Platform`**（§4.3）。

### 8.5 门 2：取消语义的判据

`tests/Unit/Async/AsyncScopeTest.php`（6 用例 / 25 断言）+
`tests/Feature/State/AsyncScopeCommandTest.php`（3 用例 / 15 断言，挂真实帧循环）。

三组变异（`build/_mutate_asyncscope.php`，不进 git）：

| 变异 | 表现 |
|---|---|
| A 取消不传播（不 cancel 在途那条） | ✅ 精准报红「打到**在途的那条**」 |
| B 取消不投异常（协程不终结） | ✅ 精准报红「finally 执行了」 |
| C `wake()` 不查 `isSuspended()` | ✅ **进程崩**（`FiberError` 搅乱 Fiber 状态，PHPUnit 连汇总都打不出） |

★ C 的失败形态值得记：它不是"某条断言红"，是**进程级崩溃**。
`isSuspended()` 那个判断看着像防御性冗余，去掉就直接崩 —— 这类"删了才知道"的行必须留注释。

### 8.6 与既有范式的分工（不是替代）

| 写法 | 何时用 | 判据文件 |
|---|---|---|
| `$task` 内部 `then()` 组合 | 2–3 步、无循环、中间值不上界面 | `MultiStepCommandTest` |
| 两次 `run()` | 两步各自独立展示 | 同上（表里） |
| **`AsyncScope`** | **有循环（轮询）/ 有重试 / 每步都要更新界面** | `AsyncScopeCommandTest` |

★ 三者都满足「一条 `Future` 交给一个 `AsyncCommand`」。
★ ~~`subscribeSettledFor()` 与 `AsyncCommand` **不删除**~~ —— `AsyncCommand` 确实留下来了，
`subscribeSettledFor()` 则在同日晚随 watermark 迁移完成而删除（零消费者，见 §11）。

### 8.7 下一棒（尚未做）

`apps/watermark` 迁移。**本棒只交付原语与判据，没有动 watermark 一行** ——
迁移要改 5 个 `AsyncCommand` + `WatermarkStore` 的编排段 + `Widgets` 的 15 处
`SubjectBuilder`，是一次独立重构，硬塞进本棒会让两件事都做不透。

---

## 9. 外部输入定位：PHP 8.6 的 `Io\Poll` RFC（用户提供）

> `https://wiki.php.net/rfc/poll_api` —— 已实现（Implemented），目标 **PHP 8.6**，
> 作者 Jakub Zelenka，投票 Yes 33 / No 1 / Abstain 4（2026-06 通过）。

### 9.1 RFC 给了什么

| 项 | 内容 |
|---|---|
| 命名空间 | `Io\` / `Io\Poll\` |
| 主类 | `Context::add(Handle, Event[], mixed $data)` / `wait(?int $s, int $us, ?int $max)` / `getBackend()` |
| **0 超时非阻塞** | ✅ `wait(0, 0)` 立即返回 —— 与本仓 `stream_select(…, 0)` 语义一致 |
| Windows | **`WSAPoll`**（不是 IOCP） |
| 句柄 | 初始只有 `StreamPollHandle`；`SocketPollHandle` / `CurlPollHandle` / `TimerHandle` 都是 future scope |
| 定位 | **只是轮询 API，不是事件循环** —— 无定时器 / 信号 / 子进程，需配 AMPHP / ReactPHP |

### 9.2 对本仓：**不能替代 `AsyncScope`，而且短期用不了**

1. **它不解控制流。** `Io\Poll` 回答"谁就绪了"，不回答"下一步是哪一行"。
   `await()` / `AsyncScope` 解决的是后者 —— 两者正交，正是 §1.6 的三层分工：
   **TaskHost 管"在别处跑" / IoPoller 管"就绪没" / AsyncScope 管"下一步"**。
2. **短期不可用。** AOT 产物链的是 `php8ts.lib` / `php8embed.lib`（本机 **8.5.11**），
   换 8.6 要等 tpc 升级；`Io\Poll` 是新的 Zend 内建类，届时也要确认 tpc 能把它编进产物。
3. **当前没有性能痛点。** `IoPoller` 的 fd 数是"worker 数 + 少数 socket"（个位数），
   `select` 的 1024 上限与 O(n) 都够用；`probes/RESULTS.md` §12.4 实测
   socket 上 `stream_select(0)` 只要 **0.008ms**。

### 9.3 但它印证了一件事，值得记账

**PHP 官方的选择与本仓一致**：不搞"协程 + hook 全进程接管"（那是 Swoole / Swow 的路线），
而是提供**一个可在任意帧边界调用的非阻塞轮询原语**，把控制权留给宿主循环。
RFC 的作者动机段甚至点名了 ZTS / FrankenPHP 这类"宿主自己掌握主线程"的场景 ——
**桌面 GUI 的 `GetMessageW()` 循环正是同一类宿主**。

⇒ 等 tpc 上 8.6 时，**只换中间那层**：`IoPoller` 内部用 `Io\Poll\Context` 替代
`stream_select`，收益是"无 1024 上限 + 持久注册 + `HangUp` 自动检测"。
`AsyncScope` / `TaskHost` / 应用代码**一行不用改** —— 这正是把三者拆开的价值。

## 10. 第三棒：`apps/watermark` 迁移裁决（2026-11-04）

上一棒交付了原语（`AsyncScope` + 判据），**没动应用一行**。这一棒把唯一真实消费者
迁过去 —— 迁移本身就是原语的验收：如果应用里还剩两套簿记，说明原语的形状不对。

### 10.1 现状量账（迁移前）

`WatermarkStore.php` **830 行**，其中「异步簿记」这一块约 190 行：

| 形状 | 数量 |
|---|---|
| `AsyncCommand` 字段 | 5（sign / upload / task / poll / download） |
| 值订阅（`subscribeFor`） | 5 |
| 结算订阅（`subscribeSettledFor`） | 3（接力上传 / 立刻查询 / 排下一拍） |
| `absorb*` 落地方法 | 5 |
| 纯接力/排期方法 | 3（`relayAfterSign` / `pollAfterSubmit` / `scheduleAfterPoll`） |
| 跨命令标志 | 2（`uploadAfterSign` / `polling`） |
| 定时器手工记账 | 1（`scheduleNextPoll` + 回调里的二次守卫） |

### 10.2 三个发现（都指向同一件事：形状在制造税）

**发现 1：`uploadValue()` / `pollValue()` 是死接口。**

全仓 `grep` 零消费者（含测试）。它们的注释写着「页面用 `fold()` 画四态」——
那句话从来没有对应的代码。按本仓纪律「未被消费的分支 = 未实现」，迁移时直接删，
不是破坏兼容性，是清理。

**发现 2：五个命令互不排斥，界面却假设它们互斥。**

`canSubmit()` 判的是 `!$taskCommand->isRunning()`，而轮询中 `taskCommand` 并不在跑
⇒ **轮询期间「提交任务」按钮是活的**。手点一下就会起第二条轮询，两条 `absorbPoll`
互相覆盖 `lastResult`。同理 `canUpload()` 只看 `uploadCommand`，取签名期间按钮也活着。

五个 `isRunning()` 拼不出一个「现在在忙」—— 因为「在忙」本来就是**一个**状态。

**发现 3：`uploadAfterSign` 是命令分离的化石。**

「签名过期 ⇒ 先取签名 ⇒ 取完再上传」这一段在原文里是 `await` 的两行；
到了订阅形状里必须拆成「设标志 → 在别的命令的结算回调里读标志 → 调下一个命令」。
它不是业务复杂度，是**命令边界**逼出来的。

### 10.3 目标形状

```php
// 唯一一条命令，参数是"跑哪个入口"
$this->flow = AsyncCommand::make(fn (mixed $entry): Future => AsyncScope::task(
    function (AsyncScope $co) use ($entry): void { $this->runFlow($entry, $co); },
    $this->scheduler,   // Application::after() 注入的延时函数
));
```

| 入口 | 用户动作 | 顺序代码 |
|---|---|---|
| `Flow::Prewarm` | 首屏预热 | 取签名，失败不说话 |
| `Flow::Upload` | 点「上传」 | 签名过期才取 → 上传（进度上界面） |
| `Flow::Submit` | 点「提交任务」 | 建任务 → `pollLoop()` |
| ~~`Flow::Poll`~~ | ~~重试查询~~ | 无消费者，不实现 |

```php
private function pollLoop(AsyncScope $co, string $taskId): void
{
    while (true) {
        try { … $co->await(new PollResultJob(...)) … }
        catch (\Throwable $e) { /* 查询失败继续重试，不停轮询 */ }

        if ($result->isTerminal()) { …下载…; return; }
        if (!$co->sleep(Config::POLL_INTERVAL_MS)) { return; }
    }
}
```

★ **「每轮只排一个定时器」在这段形状里结构上不可能违反** —— 定时器从
`AsyncScope::sleep()` 内部生成，应用手里没有能"再排一个"的地方。这条曾经咬过两次的
纪律，从此不需要判据守着（判据保留，改成"守 `sleep` 返回 false 时不死循环"）。

### 10.4 对原语的唯一改动：`sleep()` 返回 `bool`

`sleep()` 无延时函数时**不睡**（现状：`Errors::report` + `return`）。对顺序代码来说
这是「零延时重试」—— `while` 会立刻转成请求风暴。旧形状里这个语义由
`scheduleNextPoll()` 的 `if ($scheduler === null) return;` 承担，循环自己就停住了。

裁决：**返回「到底睡了没有」**，调用方决定零延时意味着什么。

```php
public function sleep(int $milliseconds): bool   // false = 本环境没有定时器，没睡
```

不改成抛异常：`sleep()` 拿不到定时器是**能力缺失**，不是代码写错；抛出去会让
「单测/解释器」这种合法场景变成 error 态（界面报「查询失败」，而其实什么都没失败）。

### 10.5 行为改变（如实记账）

| # | 改变 | 理由 |
|---|---|---|
| ① | 取签名期间「上传」按钮变灰（原来可点，靠 `uploadAfterSign` 接力） | 预热只在构造器跑一次、窗口还没出现 ⇒ 实际影响接近零；换来 `uploadAfterSign` 整个消失 |
| ② | 轮询中「提交任务」按钮变灰（原来可点，会起第二条轮询） | 修的是真 bug（发现 2） |
| ③ | 没有定时器的环境下 `isPolling()` 变 `false`（只查一次） | 旧形状报告"正在轮询"是假的 —— 那一拍根本没人排 |
| ④ | 删除 `uploadValue()` / `pollValue()` / `pollOnce()` | 零消费者（发现 1） |

### 10.6 判据

| 文件 | 迁移后 |
|---|---|
| `StoreHostChainTest` | **零改动**必须绿 —— 五连顺序是迁移不变量，改了就说明行为漂了 |
| `StoreSyncPathTest` | **零改动**必须绿 —— 失败必须落成 `uploadMessage`（`catch` 里写，不是靠订阅） |
| `PollRhythmTest` | 前 3 条零改动；第 4 条改判「不假装在轮询」 |
| `StoreSettledEffectsTest` | 改造：从「结算趟数」改为「进度通知不推进流水线」 |

★ 前两个文件零改动是最强的证据：它们钉的是**行为**，不是实现。


### 10.7 迁移反过来改了原语（两条，都是写原语时看不见的）

§10.4 记的 `sleep(): bool` 是第一条。第二条更重：

**取消哨兵会被业务 `catch (\Throwable)` 吞掉。**

`pollLoop()` 里为了「查一次失败不该停轮询」必须 `catch (\Throwable)` —— 而用户点
「停止」投进来的 `AsyncCancelled` 被它一起接住 ⇒ 循环继续 ⇒ **又排一个定时器**。
这条是判据抓出来的（`停止轮询后迟到的查询结果不落地也不再下载` 报「停止之后又排了一拍」）。

修法两层：

| 层 | 做法 |
|---|---|
| 原语 | `guardCancelled()`：`await()` / `sleep()` 入口检查 `cancelled` ⇒ 每个**让出点**都重新检查 |
| 应用 | `catch (AsyncCancelled $e) { throw $e; }` 放在 `catch (\Throwable)` **之前** |

★ 并且借此把 `abort()` 的「最多三次注入」删成**单次**。原因是变异自检暴露的：
有了 `guardCancelled`，三次注入是**冗余**的 —— 「去掉 guard」和「去掉应用侧 catch」
两组单点变异**都测不出来**，因为另一层兜住了。
两条互相掩盖的防线不是纵深，是**判据失效**。一次注入 + 让出点闸门是**确定**的；
三次注入是**猜**（"试着投三次，投不进就算了"）。

★ 这条修正的判据是协程**是否真的终结**：被吞掉的取消若就此挂在一个新 `await` 上，
`finally` 不跑、Fiber 栈连同捕获的闭包一起泄漏 —— 界面上完全看不出来。

完整量账见 `docs/DEBT-2026-11-02.md` §32。

## 11. 第四棒：收面（2026-11-04 晚）

原地语落地、应用迁移之后，这一棒是**减法**。两件事，一个主题：
**把调用方必须记住的纪律收进原语，把没人用的缝删掉。**

### 11.1 删掉 `subscribeSettledFor()` —— 它随旧形状一起消失

`§10` 把 watermark 的 5 个命令收成 1 个之后，`subscribeSettledFor()` 在
`src/` 与 `apps/` **零命中**，只剩自己的 256 行判据。

它解决的是「用**订阅**写多步流程时，这一趟通知是不是结算判不出来」——
`cancel()` 造的 `AsyncValue::data($old)` 与 `land()` 造的 `data($new)` 形状完全相同。
而 `AsyncScope` 里**没有订阅**：`await()` 返回就是结算。

⇒ **病和症状一起消失了。** 这条缝连同 `SettledSignal`、`emit()` 的那个 `bool`
形参、256 行判据一起删除。

★ 前次「不删」的裁决理由是「`State::asyncCommand()` 与 `apps/users` 都在用」——
那句话把「用 `AsyncCommand`」当成了「用结算缝」，而两者都没调过它。
**一个错误的事实撑住了一个 API 整整一天。**

★ 判据没有减少覆盖：`cancel()` 的值语义由 `AsyncCommandTest` 的
`testCancelRestoresLastData` / `testDisposeSilencesLateSettlement` 守着。

### 11.2 `await()` 接住进度

`apps/watermark` 的 `uploadFiles()` 里原来有一段**教程式注释**：

```php
// ★ 进度必须挂在**根** Future 上，且在 `await()` **之前**：
//   `onProgress()` 不沿 chain 传递；而 await 之后挂可能已经结算了。
```

**一段注释在教调用方怎么用原语，就是原语缺东西的信号。** 两个错法（挂晚了、
挂到派生链上）都**不报错**，只表现为进度条不动。

```php
public function await(Future $future, ?callable $onProgress = null): mixed
```

顺序由原语保证，调用方只写"进度到了做什么"。watermark 那边 -8 行。

它**不能**解决"挂派生链收不到"—— 那是 `Future` 的契约（进度不沿 `then()` 播），
文档里写明了要传 `JobRunner::exec()` / `host->submit()` 那条没派生过的。

### 11.3 一条边界，写在这里免得下次又想错

**`AsyncScope` 的适用范围是「有第二行要写」。**

§10 之后曾列过一个候选：把 `apps/users` 的 `UserStore` 也迁过去。量账否掉了 ——
它**只有 1 个命令**（取一页 + 四态），迁过去会变成
`AsyncCommand::make(fn => AsyncScope::task(fn ($co) => $co->await(...)))`：
多一层 `Fiber`、多一次 `suspend`/`resume`，**能力一点没多**。

| 场景 | 用哪个 |
|---|---|
| 一次请求 + 四态给界面画 | `AsyncCommand` —— **终点，不是半成品** |
| 两步以上 `then()` 串联，中间值不上界面 | `AsyncCommand` + `then()` |
| 有循环 / 有重试 / 每步都要更新界面 | `AsyncScope` |

完整的账在 `docs/DEBT-2026-11-02.md` §33。
