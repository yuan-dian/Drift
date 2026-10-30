# 多窗口评估（2026-10-30）

> 状态：**评估完成，台阶已陆续落地**（N1 / M1-c / M2 / M0+M1-a，状态以 §6 的 ✅ 标记为准）。
> 本册原稿只出裁决依据与路径、不写业务代码；落地记录是按 §6 追加的，
> 每段带日期与验收数字。
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

## 3. 平台层：7 处缺窗口身份（✅ 全部已补，2026-10-30 M1-a/b）

`WindowHost` 接口**已经按多句柄设计**（`createWindow` / `close(hwnd)` / `place(hwnd)` /
`clientSize(hwnd)` / `position(hwnd)`），这一层当年就写对了。缺的是另外六个端口：

| 端口方法 | 现状（2026-10-30 收口后） | 为什么必须带窗口 |
|---|---|---|
| `Platform::screen()` | ✅ `screen(int $h)`（M1-b） | `scale()` 是 **per-monitor DPI**，两窗跨屏必然不同值 |
| `Screen::viewport()` | ✅ 随 `screen($h)` 一起（M1-b） | 每窗客户区不同 = 根约束不同 |
| `Platform::input()` | ✅ `input(int $h)`，**每窗一条队列**（M1-b） | 事件无归属 ⇒ N 个 `Application` 会**重复消费同一条事件** |
| `Platform::pump()` | ✅ 已拆成 `waitEvents()` / `pumpEvents()`（N1 泵帧分离）；退出判据改成"活窗口表空了才 `Quit`"（M1-c） | `false` 表示退出，但"关哪个窗算退出"没答案 |
| `Platform::imeCaret()` | ✅ 已加 `$windowHandle`（M1-a） | IME 插入符是线程级资源，但**该贴哪个窗口**要指定 |
| `WindowHost::setMinSize()` | ✅ 已加 `$windowHandle`（M1-a） | 每窗可以有不同的最小尺寸 |
| `WindowHost::pushSemantics()` | ✅ 已加 `$windowHandle`（M1-a） | UIA provider 挂在 HWND 上，每窗一棵语义树 |

★ **M1-a 只做了"加参数就够"的那三个**（`imeCaret` / `setMinSize` / `pushSemantics`），
外加一条隐藏的：`dprOf(int $h)` —— `translate()` 里三处"坐标除 dpr"不再问
`Screen::scale()`（那个值属于最后建的窗）⇒ 多窗跨不同 DPI 屏时鼠标坐标不会按错比例换算。

★ **M1-b 做的是"加参数不够"的那三个**（`screen` / `viewport` / `input`）：它们返回
**有状态或需要身份**的对象，做法是"加参数 + 平台侧按句柄缓存"（`input`）或"加参数 +
每次新建"（`screen`，它自己无状态）。**没有**用设计册原稿的 `forWindow()` 视图 ——
理由见 §6 M1 的落地段。

★ **原表漏了三处同族的**（都不在"端口方法"里，但一样是"最后建的窗"）：
`Platform::timers()`（已从接口删除，见 §6）、`Win32TimerHost` 的挂靠窗口
（改用 `liveHandle()`）、`ResizeHookState::$pump`（改成 `callable(int $hwnd)`）。

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
| **⑥** | 窗口几何持久化怎么存？ | (a) 每窗一份文件 (b) 一份文件 N 个槽 | **(b)** ✅ **已落地**（2026-10-30 第八轮）：`new WindowStateStore($path, 'main')`，槽位名=构造参数，格式抬到 v2（`main.x=` / `second.x=`），v1 文件整体视为主窗组。**key 从应用来**——句柄每次运行都变、创建序号会漂移，框架给不出跨运行稳定的 id，只有应用知道"这个窗口是什么" |
| **⑦** | 一个窗口崩了（Fatal）怎么办？ | (a) 全进程中止 (b) 只关这个窗 | **(a)** —— 保持现状。G2 的口径是"绝不静默"，不是"隔离故障"；部分存活会让状态不一致扩散 |
| **⑧** | 关窗前能不能拦？ | 现在**不能**：`WM_CLOSE` 直接 `DestroyWindow`（`cpp-src/drift_win32.cc:1352`） | **必须补**，见下 |

#### ⑥ 的落地形状（2026-10-30，第八轮）

统一成"**槽位名由应用给**"：

```php
// 同一个文件，两组状态
$main      = new WindowStateStore(Paths::userConfig($dir, 'showcase.window'));
$inspector = new WindowStateStore(Paths::userConfig($dir, 'showcase.window'), 'inspector');
```

- **槽位是构造参数**，不是 `restore($window, $handle, 'main')` 的第三个参数：`$handle`
  说"**这次运行**里的哪个窗口"（每次启动都变），槽位说"**跨运行稳定**的哪个窗口"
  （写进文件）。两个"窗口身份"并排出现在同一个签名里，迟早传反 —— 而传反的后果是
  "副窗的几何还原到主窗上"，**不报错、只是窗口乱跳**。
- **文件仍是"一个应用一个"**：文件是**应用级**的持久化单元（`Paths::userConfig` 收的
  `name` 是应用名）。拆成每窗一份 = 窗口名混进应用身份，且退出时要写 N 个文件
  （部分失败 ⇒ 一半新一半旧）。
- **格式抬到 v2**（`main.x=` / `second.x=`），v1 平铺文件**整体**视为主窗组 ——
  这是"完整识别"，不是"读一半"，所以不违反纪律 3。
- **`persist()` 读-改-写**：只换自己那组、把别的组带走。整份覆盖会让第二个窗口
  **抹掉**第一个的几何（下次启动只有一个窗还原、另一个跳回默认位置）。
- 真机判据：`probe-two-windows.py` 的 ⑥（两组并存且**值各自正确** —— 只验"有两个键"
  不够，覆盖式写入的最后一组照样叫 `second`）与 ⑦（外部改文件后两窗**各自**还原）。

★ 这条落地时**连带修掉了一个既有缺陷**，值得单独记：让 `hello` 第一次走上
"持久化 + 长退出路径"之后，关窗崩率从 2/8 变成 8/8。查下来不是持久化的锅 ——
组合根把**应用对象图留到了 PHP shutdown**（`Errors::installFatalBox` 的闭包持
`$platform`、`ResizeHookState::$pump` 的闭包持 `$windows`，两处各持一半），AOT 在
shutdown 段释放时解引用了已销毁的类元数据（`php8ts.dll+0x24b30`，`hello.exe` 在
10-27 就有同一偏移）。退出前卸下两个钩子后 8/8 → **0/8**（`showcase` 6/8 → 0/8、
`users` / `tutorial` 也是 0）。判据随之收紧成"退出码必须 0"，取证见
`REVIEW-2026-10-28` §23.9.3 的结案段。

### 5.1 一个被连带逼出来的缺口：**关闭不可拦截**

`drift_win32.cc` 的 `WM_CLOSE` 分支只有一行 `DestroyWindow(hwnd)`，注释说明这是**故意**
的（退出语义只有一条）。单窗口下没问题 —— 没有"未保存文档"要问。

多窗口一到，这条立刻变成硬伤：**关一个带未保存内容的窗口，必须先问用户**。
而今天 `WindowHost` 没有 `onCloseRequested` 之类的端口，`Application` 也没有
"我可以拒绝关闭"的概念。

★ 这条要在 M1 就一起做，不能拖到 M3 —— 否则多窗口的第一个真实应用就会数据丢失。

#### ★★ 2026-10-30 落地（M1-c）：它先做了，而且**不需要**窗口身份

评估时的顺序是 M0（壳引入窗口身份）→ M1（平台层身份 + 关闭可拦截）。动手时发现
"关闭可拦截"和"窗口身份"**没有依赖关系**，而它们的风险与收益完全不对等：

| | 关闭可拦截 | 窗口身份（M0） |
|---|---|---|
| 改动面 | 壳 4 处 + 3 个新函数 + PHP 6 个文件 | 壳 3216 行里 **≈230 处**引用 |
| 单窗就有价值 | **有**（未保存提示） | 没有（多窗口才见效） |
| 验收判据 | "拒绝之后窗口还在"（可无头 + 可真机） | "单窗像素逐字节不变"（必须真机 md5） |

⇒ 拆成 M1-c（本轮）与 M0/M1-a/b（下一轮）。这不是"先易后难"，是**先做那条
唯一能独立验收的分支** —— 把它捆进 300 行重构里，等于让一个 30 行的正确改动
去背 300 行的风险。

**落地形态（三处裁决，都与设计册原稿不同）**：

1. **一个入口，不是两个**。改动之前"关窗"有两条路：应用调 `close()` ⇒ 壳
   `DestroyWindow`；用户点 X ⇒ `WM_CLOSE` ⇒ `DestroyWindow`。拦截点只能盖住后者
   ⇒ **保不保护未保存内容取决于用户用哪只手关窗**。
   ⇒ `drift_win_close()` 改成 `PostMessageW(WM_CLOSE)`，新增
   `drift_win_destroy()` 做"执行"。`WindowHost` 上是一对：`close()`（请求，
   可能被拒）/ `destroy()`（执行，只有框架该调）。

2. **异步，不是同步回调**。守卫的典型实现要**弹模态确认框**，而 `MessageBoxW`
   自己会重入消息循环 ⇒ WndProc 被再入、`ResizeHookState::$pump` 那条 PHP 回调
   会在中间被调，等于在"半个帧"里跑应用代码。
   ⇒ `WM_CLOSE` 只入队一条 `kEvCloseRequested` + 叫醒泵（与 `kMsgUiaInvoke` 同形），
   决策落在**帧边界**（`EventLoop` 抽完事件之后）。

3. **`setCloseGuard(hwnd, bool)` 是显式开关，默认关**。不做成"总能被问"：
   没声明守卫的窗口（hello / tutorial / users）保持 2026-10-30 之前的路径，
   零改动、零额外往返。声明了守卫、又忘了覆写 `onCloseRequested()` 的应用
   得到默认 `true` ⇒ 退回"点了就关"，**不会**变成"点了没反应的死窗口"。

**顺带修好的一条语义**（原来就该是这样）：`WM_DESTROY` 的 `PostQuitMessage(0)`
改成"**活窗口表空了才退**"（壳里一个 `std::set<HWND>`）。单窗等价，多窗时它是
§5 裁决 ① 的物质基础 —— 而且它不需要 `WindowSlot` 表。

★ **设计册 M0 原稿"`g_main` 保留为别名"作废**（用户裁决：能一套就一套）：
M0 落地时直接删 `g_main`，不留"表 + 别名"两套。

---

## 6. 实现路径：四个台阶，每阶可独立验收

设计原则：**每一步都能单独真机验证"没坏"，而不是憋一个 2000 行的大改**。

### M0 · 壳层引入窗口身份（✅ 已落地：M1-a，2026-10-30）

★ **原稿的"`g_main` 保留为当前主窗别名"按 §5.1 末段的裁决作废**，落地时 `g_main`
**已彻底删除**，不留"表 + 别名"两套。理由：别名就是过渡态，而过渡态一旦能通过验收
就不会有人回来删它；更要紧的是"读别名"与"读当前窗"在多窗口下**不报错、只画错窗口** ——
最难定位的一类错误（与 §4.2b 同一条判断）。

实际形状（`cpp-src/drift_win32.cc`：46 个全局 / 37 个函数 340 处引用 → 1 张表）：

```cpp
struct WindowSlot {
    // 帧缓冲 / ULW 三件套 / layerBind / frameless + 拖拽高 / pixelAlpha + opacity /
    // 光标 / 客户区尺寸 / dpi / 最小尺寸 / 几何缓存 / ime + caret /
    // 拖拽与 resize hook / 指针跟踪 / UIA 节点 + lastFocusIdx + provider  共 30 余字段
};
std::map<HWND, WindowSlot> g_slots;
WindowSlot &slotOf(HWND hwnd) { return g_slots[hwnd]; }
std::set<HWND> g_liveWindows;    // 跨窗口的两张表留在外面（M1-c 的活窗口表 / 关闭守卫）
std::set<HWND> g_closeGuarded;
```

**三处裁决与原稿不同**：

1. **`slotOf()` 返回引用，不是指针**。`std::map` 节点地址稳定（插入不移动既有节点）
   ⇒ 引用可以跨 `g_slots` 的增长活下去。`driftWndProc` 开头取一次，整条消息用同一个引用。
2. **槽不 erase**。`WM_DESTROY` 只释放资源（帧缓冲 / ULW 表面 / UIA provider），槽留着 ——
   因为 `WindowStateStore::persist()` 跑在**退出路径**上，那时窗口已死透、句柄已失效，
   它还要来问 `clientSize()` / `position()`。删槽 = "记住窗口位置"永远写不出去
   （2026-10-18 真机实测过的静默失败）。代价 ≈ 200 字节/窗。
3. **`WM_NCCREATE` 里整槽重置**（`win = WindowSlot{}`）。句柄会被系统**复用**，不重置的话
   新窗会继承上一窗的 frameless / 最小尺寸 / 材质 / 透明度。选 `WM_NCCREATE` 是因为它
   最早（早于 `WM_NCCALCSIZE` / `WM_SIZE`），且必须 `break` 落到 `DefWindowProcW`
   —— 返回 FALSE 会让 `CreateWindowExW` 直接失败。

**另外两条跟着身份一起必须改的**（不改等于没改）：

- **事件必须带来源**。`GetMessageW(NULL, …)` 取的是**本线程任意窗口**的消息 ⇒
  `PendingEvent` 加 `HWND hwnd` 字段，`toArray()` 在 `switch` **之前**统一写 `'hwnd'`。
  `enqueue(HWND, PendingEvent)` 强制首参：不让 `enqueue` 自己猜"当前窗口"—— 那只是
  用一个新的隐式全局去修旧的隐式全局。resize 合并的判据也加了 `queued.hwnd == hwnd`
  （两窗尺寸互不相干）。
- **每个窗口一个 UIA provider**。`CProvider(HWND, int index)`，`makeFragment()` /
  `makeFragmentSimple()` 从 `static` 改成**成员函数** —— 子 provider 必须继承同一个
  `m_hwnd`，否则就是"窗 A 的无障碍树被窗 B 的 provider 读"。四个树遍历 helper 同步参数化
  （收 `const std::vector<UiaNode>&` 而不是读全局）。

**验收（全部通过）**：

| 判据 | 结果 |
|---|---|
| phpunit | **885 tests / 2889 断言**（零变化：纯结构改动） |
| lint | **820 文件 0 error 0 warning** |
| golden | **36 ok / 0 FAIL** —— 像素逐字节不变，这是"单窗行为不变"的硬证据 |
| smoke | **431 ok / 0 FAIL** |
| AOT 重建 | `showcase.exe` 13.3 MB / 40.9s |
| 真机三态 | `exit`（窗口与进程都消失）/ `DRIFT_CLOSE_CONFIRM=1 … stay`（都还在）/ `probe-window`（1920×1016 客户区、42.1% 非白像素、PER_MONITOR_AWARE_V2） |
| resize 探针 | 1916×1006 → 884×581，非白像素 42.0% → 38.9%，画面按新尺寸重排 |

★ 唯一一处**行为变化**是修好的：`applyResize()` 原来拿"窗 A 的 `WM_SIZE` 去 resize
窗 B 的表面"，多窗口下这是最容易在真机上看见的一条。单窗时两者恰好是同一个窗口
⇒ golden 才会一字节不动。**像素不变不是因为没改语义，是因为改动只在多窗时才分叉。**

- **为什么先做它**：它是后面几步的**唯一前提**，而它自己**零行为风险**（单窗下
  `slotOf(唯一句柄)` 与原来的全局等价）。把一个高风险方向切成"先做零风险的那一刀"，
  是这套路径最重要的设计。

### M1 · 平台层补窗口身份 + 关闭可拦截

> **进度（2026-10-30，收口）**：**M1 全部落地** —— M1-c（关闭可拦截，§5.1）、
> M1-a（壳与平台层按句柄参数化，§6 M0）、**M1-b（`screen($h)` / `input($h)` /
> `EventLoop` 窗口表，见本节末）。**

| 改法 | 破坏面 | 状态 |
|---|---|---|
| ~~新增 `Platform::forWindow(int $h): WindowPlatform`~~ ⇒ **改做 `screen(int $h)` / `input(int $h)`** | **签名破坏**（2 处）+ 删 `timers()`；消费者只有 `EventLoop` 与组合根 | ✅ M1-b（形状不同，见下） |
| `WindowHost::setMinSize(int $h, int $w, int $h)` / `pushSemantics(int $h, array)` | **签名破坏**（2 处），消费者只有组合根 | ✅ M1-a |
| `Platform::imeCaret()` 加 `int $windowHandle` | **签名破坏**（1 处），消费者只有 `EventLoop`；caret 是线程级资源，"贴哪个窗口"只有调用方知道 | ✅ M1-a |
| 桥事件带 `hwnd` 字段；`Win32Platform` 按事件自带的 hwnd 投队列（缺字段回落主窗） | 增量（新字段） | ✅ M1-a |
| dpr 按窗口算（`Win32Platform::dprOf(int $h)`）而不是读 `Screen::scale()` | 增量；`translate()` 三处坐标换算已改 | ✅ M1-a |
| **不退出**的判据：`waitEvents()` 回 `Quit` 的门槛改成"最后一个窗口销毁" | 壳侧 `WM_DESTROY` 计数 | ✅ M1-c |
| ~~新增 `WindowHost::setCloseGuard(?callable)` + 桥 `kEvCloseRequested`~~ | **已落地（形状不同）**：`setCloseGuard(int $h, bool)` + `destroy(int $h)`，理由见 §5.1 | ✅ M1-c |
| `EventLoop::run($platform, array<int,Application> $apps)`（键 = 句柄）；`FrameClock` 每窗一个 | **签名破坏**（4 个组合根 + 测试） | ✅ M1-b |
| `Platform::timers()` **删除**；`Win32TimerHost` 改成平台私有、挂 `liveHandle()` | 接口收缩（外部消费者在 N2 之后归零） | ✅ M1-b |
| `ResizeHookState::$pump` 变 `callable(int $hwnd)` | **签名破坏**（4 个组合根各 1 处） | ✅ M1-b |
| 新增 `WindowHost::isAlive(int $h)` + 桥 `drift_win_is_alive()` | 纯增量（1 个方法 + 1 个桥函数） | ✅ M1-b |

#### ★★ M1-b 的三处裁决（都与本册原稿不同）

**① 不做 `forWindow()` 视图，统一"句柄参数化"。** 原稿的判据是"窗口级能力收进视图、
`Platform` 不膨胀"，但它在本轮**已经失效**：M1-a 之后 `WindowHost` 的 14 个方法全部带
`$windowHandle`，而 `surface(int $h)` 从一开始就是参数化。此时再加一个视图接口，本仓就有
**两套**"如何指定窗口"的写法，而两者能表达的能力完全一样 —— 这正是"留一套"要消灭的东西。
替换判据（已写进 `Platform` 的类注释）：

- 返回**值对象 / 无状态查询**（`Screen` 每次现读 DPI 与客户区）⇒ 加参数更省，也不必缓存；
- 返回**有状态对象**（`InputSource` 是一条队列）⇒ 加参数 + 平台侧按句柄缓存 ——
  **句柄本身就是那个状态的 key**，不需要再包一层对象来持它。

**② `Platform::timers()` 删除。** 唯一消费者是 `Win32Platform::waitEvents()` 自己
（`FrameClock` 在 N2 之后不再持 `TimerHost`：它只算策略、不动系统）。接口上的方法必须有
**外部**消费者，否则"支持它"是一句无法验证的话；应用要定时器走 `Application::after()`。
删它还顺带消掉一处"最后建的窗"：`Win32TimerHost` 原来挂 `handle()`，现在挂 `liveHandle()`
（新增桥 `drift_win_live_window()` —— 等消息的闹钟是**进程级**资源，主窗关了不该让它变成
死句柄 ⇒ 否则 `waitEvents()` 退化成无限阻塞、剩下的窗口动画停摆）。

**③ `EventLoop` 的窗口表，以及三个"只有真开两个窗才暴露"的坑。**

`run(Platform, array<int,Application>)`，键 = 句柄；每窗一个 `FrameClock`；
`nextWaitMs` 取**最急**的那个；关掉一个窗从表里摘掉、继续跑剩下的。

- **`-1` 是"无限等"的哨兵，不能参与 `min`**：`min(4000, -1)` = -1 ⇒ 有定时器的窗口被
  空闲窗口拖成永远睡（定时器再也不响，**不报错**）。正确写法是"`-1` 不投票，全部为 -1
  才是 -1"。**单窗口永远碰不到这一支** —— 这是双窗用例直接抓出来的逻辑错误。
- **模态时长读一次、逐窗都扣**：`consumeModalBlockedMs()` 读后即清，写在 `advance()`
  的实参里就会"第一个窗口读走、第二个窗口拿到 0"⇒ 第二个窗口白弹一次 RECOVERABLE。
  同一次原生模态把整个循环挂起了，对每个窗口都是真实发生的阻塞。
- **壳直接销毁的窗口要主动摘掉**（真机探针抓到的，见下）：没有声明关闭守卫的窗口是壳在
  `WM_CLOSE` 里直接 `DestroyWindow` 的，框架收不到任何事件；而 `WM_QUIT` 前面还排着
  `WM_KILLFOCUS` / `WM_ACTIVATE` ⇒ "关窗之后、quit 之前"那一轮会对着死句柄跑一帧 ⇒
  客户区 0×0 ⇒ **布局硬校验 Fatal**（真机：点 X 之后闪一下错误框、进程停在框上不退，
  而且**时好时坏**）。判据必须是显式存活查询（`WindowHost::isAlive()`），
  **不能**用"客户区 0×0"——**最小化也是 0×0**，那是合法状态。

★ 验收三件：

- 无头：`EventLoopTest` 三条新用例（按句柄路由 / 关一个留一个 / 死窗口不跑帧 /
  空闲窗口不拖长睡），全部配了**变异**验证"拆掉就红"。
- 真机：`apps/hello` 的 `DRIFT_TWO_WINDOWS=1` + `tools/probe-two-windows.py` 五条判据
  （两窗都在 / 客户区各自正确 / 两窗都有内容 / **关一个另一个还在、进程还在** /
  关最后一个 ⇒ 进程退出）。
- 结构：`hello` 的 `openWindow()` 抽成方法是因为**它有第二个调用点** —— 两处手抄 25 行，
  "第二窗少了上膛那一步"只会发生在最没人跑的那条路上。

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


### M3 · 组合根 · 第二个窗口 · 测试（✅ 已落地，2026-10-30）

原稿五条，落地时**两条变了、一条作废**：

| 原稿 | 实际 | 为什么变 |
|---|---|---|
| 组合根改成 `map<HWND, WindowRuntime>` | **`WindowSet` + `Window`（`src/App/`）** | "map" 只是数据结构，真正要消灭的是**四个组合根各写 30 行同一段**。抽成框架类之后，组合根只剩"开几个窗、装什么应用" |
| 示例台加「多窗口演示」页（点按钮开窗） | **`DRIFT_SECOND_WINDOW=1` + `InspectorApplication`** | 运行中开窗要"在帧边界执行"（否则 `attach()` 在某条事件回调中间跑一整帧 = 重入）⇒ 归 `Inbox`（事件循环册 N4）。未消费的分支不预先发明 |
| `ResizeHookState::$pump` 单槽改 `map<HWND, callable>` | **作废** | M1-b 已把它改成 `callable(int $hwnd)`。闭包内按句柄分派就够 —— 再包一层 map 是两套写法表达同一件事 |
| `tools/Smoke/Window/` 双窗骨架 | **`tests/Feature/App/WindowSetTest.php`（6 条）** | `FakeWindowHost` 撑不起"建窗→还原→表面→attach→循环→写回"整条链；改用真 `Win32Platform('soft')` + 假桥（与 `EventLoopTest` 同口径：**桥可假，策略不可假**） |
| 真机验收 | ✅ `probe-two-windows.py hello` / `showcase` | 探针**参数化**成一份（见下） |

#### 为什么是 `WindowSet`，而不是"第四个组合根再抄一遍"

`tutorial/Bootstrap.php` 的注释自己写着：*"121 行里只有 2 行是本应用自己的…抄的时候整段抄"*。
而抄的那 30 行里含**三条"漏掉即静默"的次序契约**（还原在 `resize` 之前 / `resize` 用读回的
客户区 / `attach` 前表面非零）与**一条致命的**（两个静态钩子必须卸，否则关窗 8/8 崩）。

```php
$set = new WindowSet($platform, Paths::userConfig($platform->configDir(), 'x.window'), static function (): void {
    ResizeHookState::$pump = null;      // ← 组合根装的两个钩子
    Errors::installFatalBox(null);      //   由 run() 的 finally 统一卸
});
$set->open('main', '标题', 960, 640, static function (): Application { return new XxxApplication($scope); })
    ->setMinSize(420, 320)->setCloseGuard(true)->onSemantics();
$set->run();                            // EventLoop + 逐窗 persist + 清表 + GC + $release
```

三处裁决：

1. **状态文件收"完整路径"而不是文件名**（`Paths::userConfig(...)` 由组合根算）。
   文件名版本让测试无法指向临时目录 —— 只能去写 `%APPDATA%`。而"文件放哪"本来就是
   组合根的决定。
2. **`$release` 是构造参数，不是 `run()` 的参数**：装可以分散（要尽早），卸必须集中在
   最末尾且只有一处，并且**不可能被异常路径跳过**（`finally`）。
3. **`ResizeHookState` 的接线仍留在组合根**（一行）。`src/App` 不许引用
   `Drift\Platform\Win32`，所以"业务逻辑进 `WindowSet::repaintForResize()`、接线留组合根"。

#### 两个新坑（都只在 AOT 真机上现形）

**① AOT 对"多余的实参"不宽容 —— 直接访问冲突。**
`ShowcaseApplication::__construct($dialog, $clipboard)` 只声明两个参数，组合根按 M2 的
新形态传了三个（多了 `$scope`）⇒ exe 启动即 `0xC0000005`，而 phpunit / lint / gate 全绿。
PHP 解释器会忽略多余实参，**tpc 编译产物不会**。

**② `Widget` 子类覆写构造器必须调 `parent::__construct()`。**
漏调 ⇒ `$mods` 未初始化 ⇒ AOT 报
`The parameter object must be object, got null`，栈顶落在 `Widget::mods()` / `Element::syncBox()`，
**看不出是哪一层忘了调父构造器**。这与 `Application` 子类那个坑同族，但报错更难看懂。
⇒ `app-subclass-ctor-parent` 的基类表加了 `Drift\Widget\Widget`，并**补了第一个反例夹具**
（这条规则此前一条夹具都没有，恒是"该响 0"）。

★ 取证过程的诚实记录：两次崩溃都是 `php8ts.dll+0x24b30`（已知的 shutdown 段偏移），
我据此先做了"清表 + `gc_collect_cycles()`"（怀疑 GC 环），**没有修好**。真因是上面第 ② 条
—— 崩在 `attach()` 里，只是崩的类型恰好是访问冲突。教训：**偏移能告诉你"在哪一段"，不能
告诉你"为什么"**；`0x24b30` 在这两处是巧合，不是同一个 bug。
（`gc_collect_cycles()` 那一步留下了：它不是这次崩溃的根因，但"组合根的树是环"这件事是真的，
主动放手比等 GC 阈值更确定。）

#### 探针参数化（一份验两个应用）

七条判据与被验应用无关，只有 "exe / 环境变量 / 标题 / 尺寸 / 槽位名 / restore 尺寸" 是参数：

```
python tools/probe-two-windows.py hello
python tools/probe-two-windows.py showcase
```

★ showcase 的 `restore` 尺寸（1000×700 / 480×420）比 hello 大，原因是它有一个**既有**的
布局溢出（见下），不是本轮引入的。

#### 顺带发现的既有缺陷 —— 已修（同日，见架构册 `04` §1.6.1）

showcase 在**客户区宽 ~700** 时 `RenderFlex` 溢出 ⇒ 布局硬校验 Fatal：
`子项共需 504.0，可用只有 416.0（差 88.0）`。而组合根给它的 `setMinSize(420, 320)`
**承诺了 420 宽可用** ⇒ 用户把窗口拖窄到 420~788 就会弹 Fatal 框；窄尺寸又会被
`WindowStateStore` 记下来 ⇒ **下次启动照样 Fatal，不删配置文件打不开**。

★ 修法不是"抬最小宽度"，而是**改判据的划界**：窗口帧（`Application::frame()` /
`repaintForResize()`）里溢出只裁剪 + 一条 diagnostic，纯布局（测试 / 快照 / golden）
仍然中止。理由与对照表见 `04` §1.6.1（Flutter release / Qt / WPF / CSS 全是裁剪）。

★ 量出来的事实（`inspect.php` 逐页扫，h=900 隔离宽度）：showcase 页面的**宽度下限是
600 逻辑像素**（`ImagePage` 最高，`LayoutPage` / `AlignPage` 580），壳里左导航占 284
⇒ **客户区要 ~788 才完全不裁**。420 那个数字是手写的，与内容无关 —— 这次没有跟着
抬它（抬到 788 会让小屏用不了），代价是窄窗下内容被裁；要根治得让页面用 `Wrap`
重排，属内容侧后续项。

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

**实际执行次序**（与上面推荐次序不同，判据只有一条：先做**能独立验收**的那个）：

```
N1 ✅ → M1-c ✅（关闭守卫，不需要窗口身份）→ M2 ✅ → M0 + M1-a ✅（壳/平台窗口身份）
      → N2 ✅ → M1-b ✅（`screen($h)` / `input($h)` / `EventLoop` 窗口表）
      → ⑥ 窗口状态槽位 ✅（`WindowStateStore($path, $slot)`）
      → M3 ✅（`WindowSet` 组合根 / 第二个真实应用窗 / 探针参数化）
```

**M3 之后还悬着的**（都不在本册的依赖图里，得等别的册子）：

```
运行中开窗（点按钮弹新窗）← 需要事件循环册 N4 的 `Inbox`（帧边界执行）
跨 DPI 双屏拖动          ← 需要双屏硬件
```

（原本还有一条"showcase 窄窗弹 Fatal"，同日已修 —— 见 §6 M3 末尾。）

M1-b 与 ⑥ 之间没有停顿：⑥ 是本册 §5 唯一还悬着的裁决，而它**恰好只需要一个
真机宿主**——M1-b 刚把 `hello` 变成双窗应用（`DRIFT_TWO_WINDOWS=1`），
两窗用不同的槽位就是它的第一个真实消费者。**先做有消费者才能验的东西**，
这条次序判据在本轮比"按依赖图排"更省事。

M1-c 插到 M2 前面，是因为它和窗口身份**没有依赖**，而它的验收判据（"拒绝之后窗口还在"）
比窗口身份的（"单窗像素逐字节不变"）便宜一个数量级 —— 让一个 30 行的正确改动去背
340 处引用的风险，是这套路径最容易犯的错（§5.1 对照表就是这条裁决的依据）。

M2 排在 N2 前面的理由：它是纯 PHP 重构、零真机，而 N2 改的是 `Platform` 接口
（`pump()` 拆分）—— 两者不冲突，但 M2 先做能让 N2 少碰一个已经稳定的 `Application`。

★ **M2 落地后又多了一条理由**：`IoPoller` 现在**真的只有一个**，且它的主人是
`AppScope`。N2 的 `waitEvents()` 要等"有没有 fd 可等"，那个问题从此有一个明确的
归属地（问 scope，而不是问某个窗口）—— 这正是 M2 先做省下的返工。

---

## 10. 这一份没做的事

> ★ **本节记的是评估册写作当时（2026-10-30 上午）的边界**。同日晚间的 M1-c / M2 /
> M0+M1-a / M1-b / `WindowStateStore` 多槽位已经全部动了代码（`drift_win32.cc` 从 3328 行
> 到约 3200 行，`Platform` 三处签名变化），状态一律以 §6 各阶段的 ✅/⬜ 标记为准。
> **下面三条"没做"全部已落地。**

- **没写任何代码**（写作当时），没改 `drift_win32.cc`、没改 `Platform`。
  ⇒ 之后 M0/M1-a/b/c 落地，`drift_win32.cc` 的 46 个 per-window 全局收进 `WindowSlot` 表。
- **没跑两窗探针**：§2 的破坏链是**代码级推断**（每条都有行号可查），不是真机实测。
  ⇒ M1-b 落地后补了 `tools/probe-two-windows.py`（七条判据），真机连跑通过；
  §2 那九条破坏链里**有两条被真机抓到**（`-1` 哨兵、壳直接销毁的窗口），
  见 §6 末尾 M1-b 的"三个坑"。
- **没定 `WindowStateStore` 多窗的 key 方案**（§5 的 ⑥）—— 它依赖"窗口有没有稳定 id"
  这个更上游的裁决。`WindowSlot` 表给它留了位置（槽不 erase 正是为了 exit 路径上还能读到
  几何），但 key 本身还没定。
  ⇒ **已定：槽位名由应用给**（`new WindowStateStore($path, 'main')`）。句柄和创建序号
  都不是跨运行的稳定 id，框架层给不出这个 key —— 那不是"没做完"，是**不属于框架**。
- **没评估 Linux / macOS 的对应改造**（本仓只有 Win32 后端，跨平台是"各编译一次"，
  真做别的平台时要重新评估 —— 但本份的"窗口身份"结论对它们同样成立）。
