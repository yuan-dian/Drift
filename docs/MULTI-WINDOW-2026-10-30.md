# 多窗口评估（2026-10-30）

> 状态：**评估完成，未动工**。这份只出裁决依据与路径，不写一行业务代码。
> 上游：`REVIEW-2026-10-28` §7 的 S4 行 · `GAP-ANALYSIS-2026-10-26` §3 的多窗口行
> 回答三个问题：**能不能做？卡在哪一层？怎么做最省且可验收？**

---

## 0. 一句话

**能做了，而且比 §7 预想的便宜 —— 因为障碍不在 `Application`，在 C++ 壳与 `Platform` 的
"窗口身份"缺位。`src/` 零静态状态 + S1 已把每片做成实例自包含 ⇒ 多 `Application` 实例
这条路天然可行；§7「多窗口要先拆四个门面」的前提已经不成立。**

但"能做"不等于"现在就做"：它要碰 C++ 壳 + AOT + 多窗口真机，是当前风险最高的方向之一。
下面给出**四个可独立验收的台阶**，其中第一阶（M0）**零行为变化**。

---

## 1. 先纠正 §7 的一个前提

`REVIEW-2026-10-28` §7 S4 的判据写着「**多窗口落地时不用再改 1885 行的类**」，隐含两个前提：

| §7 的隐含前提 | 实测 | 结论 |
|---|---|---|
| 多窗口需要先拆 `Application`（四个门面） | `src/` 全仓**零静态属性**（唯一例外 `ImageLoader::$cache` 是进程级图片缓存，共享反而更好）⇒ 多实例共存没有隐藏共享态 | **前提不成立** |
| `Application` 是"单窗假设"的载体 | S1 九步拆完，`TextEditingBridge` / `ScrollInputRouter` / `InputRouter` / `OverlayManager` / `OverlayLayout` / `PumpHost` **每片都只经 host 读本实例状态** ⇒ 实例即隔离 | **前提不成立** |
| 多窗口 = `Application` 的结构债 | 真债在 `cpp-src/drift_win32.cc` 的 45 个进程级全局 + `Platform` 的 7 处无窗口身份签名 | **债在别处** |

★ 这不是说 S4 没用（1911 行 / 67 个 public 仍然是维护负担），而是说：**S4 不是多窗口的
前置条件**。按 §7 的顺序「先 S4 再 A5/MW」会把一个纯搬砖任务挡在多窗口前面，而它并不
解决多窗口的任何真实障碍。

---

## 2. 实测：今天建第二个窗口会发生什么

**不是"半成品"，是"会静默互相破坏"。** 逐行可查的破坏链：

| # | 位置 | 动作 | 后果 |
|---|---|---|---|
| ① | `cpp-src/drift_win32.cc:2195` | `createWindow` 里 `g_main = hwnd` | 第一个窗口的"主窗"身份被**覆盖** |
| ② | 同上（同函数） | `g_dpi = actualDpi` | 两窗跨不同 DPI 显示器时，`g_dpi` 只剩最后一个 |
| ③ | 同上（同函数） | `g_clientW/H` 重算 | 第一个窗口的客户区尺寸被覆盖 |
| ④ | 同上（同函数） | `CreateCaret(hwnd, …, 1, 1)` | **Win32 的插入符是线程级的**，第二次 `CreateCaret` 会销毁第一个 ⇒ 第一个窗口的 IME 候选框失去锚点 |
| ⑤ | `cpp-src/drift_win32.cc:1710` 与 `cpp-src/drift_win32.cc:1736` | `ScreenToClient(g_main, &pt)` | 第二个窗口上的指针事件用**第一个窗口**的坐标系换算（或反之）⇒ 两个窗口的 hover / 命中全错 |
| ⑥ | `src/Platform/Win32/Win32Platform.php:375` | `applyResize()` 里 `$handle = $this->window()?->handle()` | **无视事件来源**，永远取"最后建的窗"⇒ 窗口 A 的 `WM_SIZE` 会去 resize 窗口 B 的表面 |
| ⑦ | `cpp-src/drift_win32.cc:1358` | `WM_DESTROY` → `PostQuitMessage(0)` | **关任意一个窗口 = 整个进程退出** |
| ⑧ | `src/Platform/Win32/Win32WindowHost.php:50` | `private ?int $handle = null` | 单值字段 + `setMinSize()` / `pushSemantics()` 根本没有句柄参数 |
| ⑨ | `cpp-src/drift_win32.cc:265` | `g_frame` / `g_frameW/H` / `g_ulwBitmap` 进程级 | 两窗交替 present 会互相重分配位图 ⇒ 闪烁 + 偶发错帧 |

★ ①–⑤ 是**同一个根因的五个表现**：壳里"当前窗口"不是参数，是全局。
★ ⑥ 是这五条的必然结果，也是**最容易在真机上看见**的那一条。

**结论**：现在连"两个窗口各画各的"都做不到。所以这一件不是"补个 consumer"，
是"给整个壳引入窗口身份"。

---

## 3. 平台层：7 处缺窗口身份

`WindowHost` 接口**已经按多句柄设计**（`createWindow` / `close(hwnd)` / `place(hwnd)` /
`clientSize(hwnd)` / `position(hwnd)`），这一层当年就写对了。缺的是另外六个端口：

| 端口方法 | 现状 | 为什么必须带窗口 |
|---|---|---|
| `Platform::screen()` | 无参，`Win32Screen` 内部读"最后建的窗" | `scale()` 是 **per-monitor DPI**，两窗跨屏必然不同值 |
| `Screen::viewport()` | 无参 | 每窗客户区不同 = 根约束不同 |
| `Platform::input()` | 无参，单一队列 | 事件无归属 ⇒ N 个 `Application` 会**重复消费同一条事件** |
| `Platform::pump()` | `bool`，全局 | `false` 表示退出，但"关哪个窗算退出"没答案 |
| `Platform::imeCaret()` | 无参 | IME 插入符是线程级资源，但**该贴哪个窗口**要指定 |
| `WindowHost::setMinSize()` | 无句柄，靠 `$this->handle` | 每窗可以有不同的最小尺寸 |
| `WindowHost::pushSemantics()` | 无句柄 | UIA provider 挂在 HWND 上，每窗一棵语义树 |

★ **`Screen` 最棘手**：它不是"加个参数"就完事 —— `scale()` 变化要触发**全树重建**
（`DpiChanged`，§9.2）。多窗口下"窗口 A 拖到 4K 屏"只该重建 A 的树。现在 `screen()`
是无参单例，这套语义无从表达。

**结构选择**（见 §6 M1 的裁决）：
- 方案甲：每个窗口相关方法加 `$handle` 参数（`screen($h)` / `input($h)` / `timers($h)`）
- 方案乙：`Platform::forWindow(int $h): WindowPlatform` 返回**窗口视图**，应用只跟视图打交道

乙的结构收益更大（以后加窗口级能力不改签名），甲的实施成本更低（不引入新接口）。
M1 的裁决给的是**乙的最小形态**：只把"必须按窗"的三个收进视图，`pump()` / `fatalBox()` /
`configDir()` 留全局。

---

## 4. App 层：好消息居多，但有一个真问题

### 4.1 S1 已经做对了什么

多实例方案能成立，靠的是 S1 九步的副产品（当时不是为了多窗口做的）：

| 片 | host 口子 | 多实例含义 |
|---|---|---|
| `TextEditingBridge` | `focus()` + `clipboard()` | 焦点是本实例的 ⇒ 隔离 |
| `ScrollInputRouter` | `focus()` | 同上 |
| `InputRouter` | `focus` / `root` / `dispatch` / `accelerators` | 路由只认自己的树 ⇒ 隔离 |
| `OverlayManager` | `focus` / `root` / `theme` / `metrics` / `after` / `cancelAfter` | 浮层栈自持（`OverlayStack` 的注释早就写了"每个 Surface 一个"） |
| `OverlayLayout` | 收 `OverlayManager` | 纯几何，无宿主状态 |
| `PumpHost` | `assertUiThread` / 时间基准 | **定时器 id 是窗口局部的** ⇒ 两窗撞同一个 id 也安全（`taskHost` 口子 M2 已移除，端口改从 `AppScope` 递进来；旧心跳的 `FrameClock::TIMER_ID` N2 已删，见 `Win32Platform::WAIT_TIMER_ID`） |

再加上：`src/` **零静态属性** ⇒ 没有隐藏的跨实例共享态。

**这两条合起来 = 多 `Application` 实例在 App 层几乎没有障碍。**

### 4.2 真问题：应用级状态与窗口级状态混在一个类里

`Application` 现在同时装着两种作用域的东西：

| 作用域 | 现状归属 | 多窗口下的正确归属 |
|---|---|---|
| **窗口级**：`$root` / `$frame` / `$focus` / `$overlayHost` / `$input` / `$scrollInput` / `$textEditing` / `$overlayLayout` / `$pumps` / `$taps` / `$drag` | `Application` 实例字段 | **留实例**（一个实例 = 一个窗口） |
| **应用级**：命令表（`$router`）/ `theme()` / `metrics()` / `taskHost()` / `io()` / `accelerators()` | 也在 `Application` 实例里 | **要提到 AppScope 共享** |

后果（现在就推得出来，不用等落地）——**四条全部由 M2 消除**（见 §6 的 M2）：

| 项 | 每实例一份的后果 | M2 的去处 |
|---|---|---|
| `metrics()` | `TtfFace::fromFile()` 解析真字体 + 注册三套字形面 ⇒ 两窗解析两次、两份缓存 | `AppScope::metrics()`（惰性，进程一份） |
| `taskHost()` | 换成 `ProcessTaskHost(4)` 就是两个独立的 4 线程池 | `AppScope::taskHost()`（`Application::taskHost()` 改 `final`） |
| `theme()` | 切主题要**逐个通知** N 个实例，漏一个就是"一半深色一半浅色" | `AppScope::theme()` + attach 时的一致性检查 |
| 命令表 | "退出应用"这类全局命令无处安放 | `AppScope::commands()`（**父链**，不是整表共享） |

### 4.2b 第三种作用域：**本该窗口级、现在住在 static**

`Application` 的实例字段之外，还有一族**全局静态计数器**，它们决定"这一帧要不要跑哪一段"：

| 计数器 | 谁在读 | 现在的作用域 | 应该的作用域 |
|---|---|---|---|
| `State::$markedCount` | `Frame::needsSettle()` | 进程 | 窗口 |
| `State::$boxEpoch`（转发 `RenderBox::$layoutEpoch`） | `Frame` layout 判据 | 进程 | 窗口 |
| `Element::$structureVersion` | `Frame::needsSettle()` | 进程 | 窗口 |
| `RenderObject::$paintEpoch` | `Frame` paint 判据 | 进程 | 窗口 |

后果是**保守多画**，不会少画、不会画错：A 窗有脏 ⇒ B 窗的判据也变 ⇒ B 重排 / 重绘一帧
（内容逐像素不变）。单窗口下零影响，所以今天看不见。

★ **为什么不现在改成每窗口一份**：分片的前提是"写的时候知道自己在哪个窗口"，而
`setState()` / `setOffset()` 发生在任意上下文（帧内、任务回调、IO 回调），拿不到窗口身份。
强行引入"当前窗口"的隐式全局，等于用一个全局去修另一个全局。
正确的顺序是 **M0/M1 给窗口身份 → M3 的 `map<HWND, WindowRuntime>` 让"当前窗口"成为
调用栈上的显式参数**，那时这四个计数器才有一致的归属。

★ 所以 **M2 不该动这四个计数器**（它没有窗口身份可用），只把 `AppScope` 抽出来。
这一条记在这里，是为了让将来做 M3 的人知道：**那边有四个计数器等着搬家**，
而不是"又发现一个新问题"。

### 4.3 所以正确的拆法是按**作用域**二分，不是按**门面**四分

§7 S4 想拆的是 `WindowHost` / `OverlayHost` / `TaskHost` / `FrameDriver` 四个**门面** ——
那是按"职责大小"切。多窗口要的是按**生命周期**切：

```
AppScope（进程一份）          WindowScope（每窗一份）
├─ CommandRouter（父表）       ├─ CommandRouter（本窗表，父链指向上面的父表）
├─ ThemeData                  ├─ Element $root
├─ TextMetricsProvider        ├─ Frame
├─ TaskHost                   ├─ FocusManager
├─ IoPoller                   ├─ OverlayManager + OverlayLayout
└─ AcceleratorTable           ├─ InputRouter + ScrollInputRouter + TextEditingBridge
                              └─ PumpHost
```

★ `WindowScope` **已经存在了** —— 它就是今天的 `Application`（除了那六项应用级状态）。
所以 M2 的工作不是"拆成四片"，而是"**把应用级那六项抽出去**"。这比 S4 小得多，
而且收益直接就是多窗口。

★ 唯一**两边都有**的是命令表（父 / 子）—— 见 §6 M2 的"修正二"：整表共享会让
全局命令注册两次（Fatal），整表独占会让它无处安放。

---

## 5. 必须裁决的语义（不裁决就写不出正确的代码）

这些不是实现细节，是"多窗口是什么意思"的定义。逐条给候选与推荐：

| # | 问题 | 候选 | 推荐 |
|---|---|---|---|
| **①** | 关一个窗口要不要退出进程？ | (a) 关任意一个就退 (b) 关**最后一个**才退 (c) 关**主窗口**就退 | **(b)** —— 与 Win32 `WM_QUIT` 语义、与用户直觉一致；(c) 需要"主窗"概念，多文档应用里没有天然的"主" |
| **②** | 焦点是全局还是每窗？ | (a) 全局单焦点 (b) 每窗独立焦点 + 全局"前台窗" | **(b)** —— 没有"前台窗"概念，加速键无法定向 |
| **③** | 加速键作用于谁？ | (a) 前台窗口 (b) 最后一个有输入的窗口 | **(a)** —— 且需要在平台层报"哪个窗是前台"（现在**没有**这个事件：`deactivate` 只报失活，不报激活） |
| **④** | 主题切换怎么广播？ | (a) 每实例各查 `Screen::systemTheme()` (b) AppScope 广播 | **(b)** —— 但 `ThemeChangedEvent` 现在投进**单一** `input()` 队列，多窗会重复消费 |
| **⑤** | 模态框的 owner 是哪个窗？ | (a) 调用方所在的窗 (b) 前台窗 | **(a)** —— `Win32Dialog` 已经每次现取 owner，只需让它取"当前窗"而非"最后建的窗" |
| **⑥** | 窗口几何持久化怎么存？ | (a) 每窗一份文件 (b) 一份文件 N 个槽 | **(b)** —— 但 `WindowStateStore::FORMAT_VERSION` 要抬，且"多窗的 key 从哪来"需要答案（句柄不持久，得给窗口一个稳定 id） |
| **⑦** | 一个窗口崩了（Fatal）怎么办？ | (a) 全进程中止 (b) 只关这个窗 | **(a)** —— 保持现状。G2 的口径是"绝不静默"，不是"隔离故障"；部分存活会让状态不一致扩散 |
| **⑧** | 关窗前能不能拦？ | 现在**不能**：`WM_CLOSE` 直接 `DestroyWindow`（`cpp-src/drift_win32.cc:1352`） | **必须补**，见下 |

### 5.1 一个被连带逼出来的缺口：**关闭不可拦截**

`drift_win32.cc` 的 `WM_CLOSE` 分支只有一行 `DestroyWindow(hwnd)`，注释说明这是**故意**
的（退出语义只有一条）。单窗口下没问题 —— 没有"未保存文档"要问。

多窗口一到，这条立刻变成硬伤：**关一个带未保存内容的窗口，必须先问用户**。
而今天 `WindowHost` 没有 `onCloseRequested` 之类的端口，`Application` 也没有
"我可以拒绝关闭"的概念。

★ 这条要在 M1 就一起做，不能拖到 M3 —— 否则多窗口的第一个真实应用就会数据丢失。

---

## 6. 实现路径：四个台阶，每阶可独立验收

设计原则：**每一步都能单独真机验证"没坏"，而不是憋一个 2000 行的大改**。

### M0 · 壳层引入窗口身份（**零行为变化**）

把 `drift_win32.cc` 的 45 个全局收进一张表：

```cpp
struct WindowSlot {
    HWND hwnd;
    int  clientW, clientH, dpi;
    int  minClientW, minClientH;
    bool frameless, pixelAlpha;
    long opacity;
    float imeX, imeY, imeH;
    int  lastPosX, lastPosY, lastClientW, lastClientH;
    bool lastMaximized, lastGeomValid;
    // 帧缓冲 / ULW 资源 / UIA provider / caret 状态
    std::vector<unsigned char> frame;
    HBITMAP ulwBitmap; HDC ulwDc; ...
    std::vector<UiaNode> uiaNodes;
    bool inSizeMove, inResizeHook;
};
static std::map<HWND, WindowSlot> g_windows;
```

- **行为不变**：`g_main` 保留为一个"当前主窗"的别名，所有桥函数仍操作它 ⇒ 单窗口行为
  逐字节相同。
- **验收**：`showcase.exe` 真机跑一遍，所有既有功能（含 A5 的对话框 / 拖文件）不变；
  golden 29+1 页一字节不动（因为不碰渲染）。
- **为什么先做它**：它是后面三步的**唯一前提**，而它自己**零行为风险**。把一个高风险
  方向切成"先做零风险的那一刀"，是这套路径最重要的设计。

### M1 · 平台层补窗口身份 + 关闭可拦截

| 改法 | 破坏面 |
|---|---|
| 新增 `Platform::forWindow(int $h): WindowPlatform`（`screen()` / `input()` / `imeCaret()` / `timers()`） | 纯增量，老方法保留为"主窗"别名 |
| `WindowHost::setMinSize(int $h, int $w, int $h)` / `pushSemantics(int $h, array)` | **签名破坏**（2 处），消费者只有组合根 |
| 桥事件带 `hwnd` 字段；`Win32Platform::pumpEvents()` 按 hwnd 投进对应队列 | 增量（新字段） |
| **不退出**的判据：`waitEvents()` 回 `Quit` 的门槛改成"最后一个窗口销毁" | 壳侧 `WM_DESTROY` 计数 |
| 新增 `WindowHost::setCloseGuard(?callable)` + 桥 `kEvCloseRequested` | 纯增量 |

★ **这条新增的 `closeGuard` 是本轮评估的额外产出**（§5.1）—— 它独立于多窗口也有价值
（单窗口的"未保存提示"同样需要），但多窗口**必须**有它。

#### ★★ 2026-10-30（N2 落地后）M1 多了一件**结构**上的事：`EventLoop` 是单窗的

`EventLoop::run($platform, $app, $windowHandle)` 的签名就是"一个窗口一个应用"：
循环里 `$screen->viewport()`、`$window->setCursor($handle, …)`、`$app->imeCaretRect()`
三处都只认**一个**窗口。多窗口下这三种写法全部失效，但**不能**改成"每个窗口跑一个
`EventLoop`" —— 消息泵只有一个，两个阻塞等待会互相抢消息。

⇒ M1 要把"一窗"变成"一张表"，两种形态二选一：

| 形态 | 形状 | 代价 |
|---|---|---|
| **A. 循环持窗口表** | `EventLoop::run($platform, array<int, Application> $apps)`；每轮对表里每个窗口跑一帧、每窗各自算 `nextWaitMs` 后取**最小** | `EventLoop` 与 `FrameClock` 都要按窗口持状态（`lastTs` / `lastWaitMs` / 卡顿计数）⇒ `FrameClock` 变"每窗一个" |
| **B. 窗口就是应用** | 保留 `EventLoop::run()` 单窗签名，组合根为每个窗口起**一个线程** | 每个线程一套消息队列（`GetMessage` 是线程局部的）⇒ 与今天的"单线程 UI"前提冲突，等于重开一个架构问题 |

**选 A**。它需要的两个前提今天都已经就位：① `AppScope` 已经把"进程一份"的东西
提出去了（M2），剩下留在 `Application` 上的**本来就该是窗口局部的**；
② `WakeReason` 已经是枚举而不是 `bool` —— "关了一个窗"和"该退出应用"在类型层面
就能分开（`WakeReason` 的注释里写着这条理由）。

★ 这也解释了为什么 `EventLoop` 现在**不收** `Application` 的数组：加数组要有
第二个窗口才验得着，而"未被消费的分支 = 未实现"是本仓纪律（同 `WakeReason::Woken`）。

### M2 · App 层按作用域二分（✅ 已落地，2026-10-30）

抽出 `AppScope`（`CommandRouter` / `ThemeData` / `TextMetricsProvider` / `TaskHost` /
`IoPoller` / `AcceleratorTable`），`Application` 构造时**可注入**一个：

```php
$scope = AppScope::make()->setTaskHost(new ProcessTaskHost(4));
$a = new ShowcaseApplication($dialog, $clipboard, $scope);   // 窗 1
$b = new ShowcaseApplication($dialog, $clipboard, $scope);   // 窗 2
```

- **兼容**：`__construct()` 无参时自建一个 `AppScope` ⇒ 所有既有应用零改动
  （smoke 431 条、feature 520 条逐条不变）。
- **这一阶零真机**，是纯 PHP 重构 —— 完全在 gate 里验证。

#### ★ 2026-10-30 修正一：那六个成员里，有两个**不在 `Application` 上**

§4.2 的表格把它们算作 `Application` 的字段，实际是**经 `Application` 转发的门面**：

```php
public function tasks(): ?TaskHost { return $this->pumps->tasks(); }   // 住在 PumpHost
public function io(): ?IoPoller     { return $this->pumps->io(); }      // 住在 PumpHost
```

而 `PumpHost::attach()` 用的是**每窗口一份**的口径（旧版自己 `new IoPoller()`）。

⇒ M2 是**两个类各切一刀**，不是只动 `Application`：

| 切哪 | 剩下什么 | 切走什么 |
|---|---|---|
| `Application` | `$root` / `$frame` / `$focus` / `$overlay*` / `$input*` / `$textEditing` / `$pumps` | `theme` / `metrics` / `accelerators` / **`taskHost()` 的覆写权** |
| `PumpHost` | `timers`（**窗口局部**的 id 空间）/ `sliced` | `tasks` / `io`（改成 `attach()` 传进来） |

★ **`io` 的后果不是浪费，是错**：两个 `IoPoller` 各自 `stream_select` 同一批 fd ⇒
同一个 socket 被 watch 两次、回调跑两次（下单付两次款那种）。`tasks` 只是多建一个池
（浪费），`io` 是**双投递**。

#### ★ 2026-10-30 修正二：`CommandRouter` **不能整表共享**（初稿写错了）

初稿把 `CommandRouter` 直接列进 `AppScope`。动手时数依赖发现它和别的五项性质不同 ——
它同时有**两种作用域**：

| 表 | 谁注册 | 例子 | 能不能共享 |
|---|---|---|---|
| 全局命令 | 组合根 | `app.quit` | 只能注册**一次**（第二窗再注册 ⇒ `on()` 的同名检查当场 Fatal） |
| 本窗命令 | 本窗 `commands()` | `users.refresh` | **绝不能**共享：handler 持**本窗的 Store**，别的窗派发到它就是改别人家的数据 |

⇒ 整表共享会让"退出应用"被注册两次（Fatal），整表独占会让全局命令无处安放。
落地方案是**父链**（与冒泡链 / 焦点链同一形状）：`CommandRouter::make($parent)`，
派发时**就近优先**、本窗同名命令覆盖全局并留一条 `DIAGNOSTIC`。

★ 由此还得到一个反直觉的结论：**加速键表可以整表共享**，因为它是"组合键 → 命令名"
的声明式映射 —— 真正的窗口隔离发生在下一环（命令派发），所以同一个 `Ctrl+S`
在两个窗口里各自落到自己的 handler。**共享的是映射，不是行为。**

#### ★ 2026-10-30 修正三：`taskHost()` 从"扩展点"改成 `final`

§17.2 原来把 `protected function taskHost()` 当应用扩展点（"换后端只改这一行"）。
多窗口一到它就是**静默 bug 的入口**：两个窗口各问出自己那份 ⇒ 两套队列。

`apps/users` 第一版就踩过（`UserStore` 拿注入的 `ProcessTaskHost`、`frame()` 用默认
`SyncTaskHost` drain ⇒ worker 早回了结果、UI 永远 `Loading`，**不报错**），当时只能
靠一段注释防。

⇒ M2 把覆写点搬进 `AppScope`（`setTaskHost()`），`Application::taskHost()` 改 `final`：
**保留覆写 = 加载期致命错误**（`Cannot override final method`）。这是本轮唯一的
破坏性变更，正面撞上的只有两个内部文件（`apps/users`、`tools/Smoke/App/TaskApplication`）。

★ 教训写进坑位册：**"扩展点"一旦和生命周期冲突，正确做法是把它 `final` 掉再给新口子**，
不是留一个"两个都对"的旧口子 —— 那是最坏的第三种状态（不报错、有时对、有时错）。

#### 落地清单（改了哪些文件）

| 文件 | 改什么 |
|---|---|
| `src/App/AppScope.php` | **新增**：六个槽 + 惰性默认 + `enter()` 主题一致性检查 |
| `src/App/Application.php` | 构造收 `?AppScope`；`theme`/`metrics`/`accelerators` 委托；`taskHost()` 改 `final`；`attach()` 把端口从 scope 递给 `PumpHost` |
| `src/App/PumpHost.php` | `attach(?TaskHost, ?IoPoller)`：**取引用**而不是自己建 |
| `src/App/PumpHostHost.php` | 删掉 `taskHost()` 口子（4 → 3 个） |
| `src/Commands/CommandRouter.php` | 父链（`make($parent)` / `handlerInChain` / `namesInChain` / `hasInChain`） |
| `apps/users/src/UsersApplication.php` | 构造里 `AppScope::make()->setTaskHost($host)`；删 `taskHost()` 覆写与 `$host` 字段 |
| `tools/Smoke/App/TaskApplication.php` | `withTasks=false` → `withoutTasks()`（在 scope 上说"不用后台任务"） |
| `tests/Feature/App/AppScopeTest.php` | **新增 6 个用例**：五项共享 + 五条反证 + 命令父链 + 主题不一致留痕 + `final` 反射断言 |

#### 验收

- gate 8 项全过；smoke **431 ok / 0 FAIL**；feature **520 tests / 1853 assertions**。
- 新增断言的关键几条：`metrics()` / `tasks()` / `io()` / `accelerators()` 在共享 scope
  下**是同一个实例**，且**不注入时必须是两个**（反证 —— 否则 `===` 可能只是测试写错了）；
  在 A 窗 `watch()` ⇒ B 窗 `watchCount()` 也是 1（一张表，不是两份）。
- 单窗路径零诊断：不共享时 `Errors::recentDiagnostics()` 为空（共享不该带来新噪音）。

#### 已知的、留给 M3 的两件事（不是遗漏）

1. **多窗口共享 `IoPoller` ⇒ 每窗每帧各 poll 一次**。同一帧里第二窗的 `select(0)`
   通常查不到新数据（回调已经消费掉了），所以不会双投递；但"同一逻辑帧只 poll 一次"
   需要**跨窗口的帧序号**，那只有组合根知道（M3 的 `map<HWND, WindowRuntime>`）。
   今天硬造一个（比如按时间戳比对）在 `repaintForResize()` 复用上一帧时间戳时会**误跳过**，
   比不修更糟。
2. **主题只共享"值"，不共享"通知"**。广播本来就不用新机制：平台的
   `ThemeChangedEvent` 是逐窗口投递的（M1 的 hwnd 分发落地后成立）⇒ 每个窗口自己
   会收到、自己 `applyNewContext()`。自己再维护一张监听表只会多一份"窗口关了要摘
   监听器"的生命周期负担，而窗口表在 M3 才有。


### M3 · 组合根 · 第二个窗口 · 测试

- 组合根从"一个 (app, surface, clock)"改成 `map<HWND, WindowRuntime>`。
- 示例台加一个真实消费者：**「多窗口演示」页**（点按钮开第二个窗口，两窗内容不同、
  各自焦点、关一个另一个还在）。
- `ResizeHookState::$pump` 单槽改成 `map<HWND, callable>`（否则两个窗口同时拖动会互相抢）。
- 新的 smoke 骨架：`tools/Smoke/Window/` 下的"双窗"场景（不需要真机，用 `FakeWindowHost`
  + 两个 `Application` 就够）。
- 真机验收：**唯一必须真机的一步**。

---

## 7. 代价与风险（诚实账）

| 项 | 代价 | 说明 |
|---|---|---|
| C++ 壳改动 | **高** | 3216 行里约 45 个全局要收拢；两个平台（软光栅 + Skia）共用同一壳 ⇒ 一次改动两边都要验 |
| AOT 重建 | 每次 110–170s | M0/M1/M3 各要至少一轮；M2 不用 |
| 真机验证 | **高** | 多窗口**无法 headless 验证**（两个 HWND 的真实交互、焦点、DPI、IME 都要真机）；这是本项目历史上最贵的验证形态 |
| 测试 | 中 | smoke 现在是单实例骨架；`FakeWindowHost` 要支持多句柄（现在只记一个） |
| 兼容性破坏 | 中 | `setMinSize` / `pushSemantics` 两处签名；`Screen` 语义扩展；`WindowStateStore` 文件格式抬版本 |
| 回归面 | 大 | 单窗口是**每一个既有应用和每一条 smoke** 的路径 ⇒ M0/M1 必须做到"单窗行为逐字节不变" |

★ **最大风险不是技术，是验证**：多窗口的失败形态（焦点跑到另一个窗、IME 贴错窗、
DPI 混用）大多**不报错**，只表现为"怪"。这类问题在本仓历史上有过多次
（圆角静默 no-op、分层窗静默回落 GDI），每次都是靠真机探针才钉住。

---

## 8. 替代方案（诚实对照）

在决定做之前，先问：**"多窗口"是不是真需求？**

| 需求形态 | 多窗口是否是正解 | 替代 |
|---|---|---|
| 一个应用开多个文档，来回切 | **不是** | `TabView`（已落地，§15）+ 标签栏拖拽重排 |
| 两个视图并排对照（如改前/改后） | **是** | 无替代 —— 分屏只能靠真窗口 |
| 把某块内容拖到另一个显示器全屏看 | **是** | 无替代 |
| 弹一个独立的小工具窗（调色板 / 查找替换） | **是** | 浮层可以凑合，但拖出窗口不行 |
| 主窗 + 模态对话框 | **不是** | `Dialog` 已落地（A5-①，§26） |

⇒ **判断：多窗口的需求是真的，但它的第一消费者是"并排对照 / 独立工具窗"，
不是"多文档"**（后者 TabView 已覆盖）。这个区分很重要 —— 它决定了示例台该演什么，
也决定了 M3 的最小消费者应该是"开一个独立小窗"而不是"开一个完整文档窗"。

---

## 9. 建议

**建议做，但按台阶做，且第一阶先做 M0。**

理由三条：

1. **M0 零行为变化** —— 它把"多窗口"从"一次性大改"变成"一个零风险预备 + 三个可验收台阶"。
   不做 M0，后面每一阶都要在一个"全局污染"的壳上打补丁。
2. **M2 零真机** —— 作用域二分是纯 PHP 重构，gate 就能验完。它是四个台阶里唯一
   可以完全脱离真机风险的一阶，却也是**长期结构收益最大**的一阶（它解掉 §4.2 的四条）。
3. **M1 里的 `closeGuard` 独立有价值** —— 单窗口的"未保存提示"今天也做不到，
   它是多窗口的必要条件，但不是多窗口的专属。

**顺序上的一个反直觉建议**：如果只想先拿一部分收益、暂不承担真机风险，
**可以先做 M2**（作用域二分），跳过 M0/M1/M3。它不产生多窗口，但：
- 解掉"metrics 重复解析 / taskHost 重复建池 / 主题广播要逐个通知"这三条真实浪费
- 为将来任何形态的多窗口（含多标签共享任务池）打好底
- 全程 gate 可验，零真机

**不建议现在做的事**：
- 不要为多窗口提前拆 S4 的四个门面（§1 已证明它不是前置条件）
- 不要在 M0 里顺手"改进"壳的其他部分（M0 的唯一目标是"行为不变地把全局收进表"）
- 不要先做 A5-③ 托盘来"凑齐平台集成"（托盘零消费者，与多窗口无关）

### 9.1 与事件循环册的顺序关系（2026-10-30 补）

两份册子有一处**真实的交叠**，必须先说清楚，否则会做反：

> `Frame` 是**每窗口一份**的（§4.2 的口径），而它读的四个计数器是**进程级**的（§4.2b）。

⇒ **N1（泵帧分离）落地后，多窗口下是"保守多画"而不是"画错"** —— 可以放心先做。
但反过来**不行**：如果先做 M3 的多窗口、而 paint 还是无条件的，那就是 **N 个窗口
各自每秒 60 次整帧 blit**，把 N1 的收益乘 N 地浪费掉。

★ 所以推荐次序没变，只是理由更硬了：
**N1（✅ 已落地）→ M2（✅ 已落地）→ N2/N3（等待与 Future，零真机）→ M0/M1 → M3**。
M2 排在 N2 前面的理由：它是纯 PHP 重构、零真机，而 N2 改的是 `Platform` 接口
（`pump()` 拆分）—— 两者不冲突，但 M2 先做能让 N2 少碰一个已经稳定的 `Application`。

★ **M2 落地后又多了一条理由**：`IoPoller` 现在**真的只有一个**，且它的主人是
`AppScope`。N2 的 `waitEvents()` 要等"有没有 fd 可等"，那个问题从此有一个明确的
归属地（问 scope，而不是问某个窗口）—— 这正是 M2 先做省下的返工。

---

## 10. 这一份没做的事

- **没写任何代码**，没改 `drift_win32.cc`、没改 `Platform`。
- **没跑两窗探针**：§2 的破坏链是**代码级推断**（每条都有行号可查），不是真机实测。
  第一件真机动作应该在 M0 之后做，而不是现在 —— 现在跑只能证明"确实坏了"，
  而这一点代码已经说清楚了。
- **没定 `WindowStateStore` 多窗的 key 方案**（§5 的 ⑥）—— 那是 M1 的细节，
  且它依赖"窗口有没有稳定 id"这个更上游的裁决。
- **没评估 Linux / macOS 的对应改造**（本仓只有 Win32 后端，跨平台是"各编译一次"，
  真做别的平台时要重新评估 —— 但本份的"窗口身份"结论对它们同样成立）。
