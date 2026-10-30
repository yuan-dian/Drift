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
| `PumpHost` | `assertUiThread` / 时间基准 / `taskHost` | **定时器 id 是窗口局部的** ⇒ 两窗同用 `FrameClock::TIMER_ID` 也安全 |

再加上：`src/` **零静态属性** ⇒ 没有隐藏的跨实例共享态。

**这两条合起来 = 多 `Application` 实例在 App 层几乎没有障碍。**

### 4.2 真问题：应用级状态与窗口级状态混在一个类里

`Application` 现在同时装着两种作用域的东西：

| 作用域 | 现状归属 | 多窗口下的正确归属 |
|---|---|---|
| **窗口级**：`$root` / `$frame` / `$focus` / `$overlayHost` / `$input` / `$scrollInput` / `$textEditing` / `$overlayLayout` / `$pumps` / `$taps` / `$drag` | `Application` 实例字段 | **留实例**（一个实例 = 一个窗口） |
| **应用级**：命令表（`$router`）/ `theme()` / `metrics()` / `taskHost()` / `io()` / `accelerators()` | 也在 `Application` 实例里 | **要提到 AppScope 共享** |

后果（现在就推得出来，不用等落地）：
- **`metrics()` 每实例一份** —— 它要 `TtfFace::fromFile()` 解析真字体 + 注册两套位图面
  （`Application.php:532`）。两窗 = 解析两次、两份字形缓存。
- **`taskHost()` 每实例一份** —— `SyncTaskHost` 默认还好，换成 `ProcessTaskHost(4)` 就是
  两个独立的 4 线程池。
- **`theme()` 每实例一份** —— 切主题要**逐个通知** N 个实例，漏一个就是"一半深色一半浅色"。
- **命令表每实例一份** —— "退出应用"这类全局命令无处安放。

### 4.3 所以正确的拆法是按**作用域**二分，不是按**门面**四分

§7 S4 想拆的是 `WindowHost` / `OverlayHost` / `TaskHost` / `FrameDriver` 四个**门面** ——
那是按"职责大小"切。多窗口要的是按**生命周期**切：

```
AppScope（进程一份）          WindowScope（每窗一份）
├─ CommandRouter              ├─ Element $root
├─ ThemeData                  ├─ Frame
├─ TextMetricsProvider        ├─ FocusManager
├─ TaskHost                   ├─ OverlayManager + OverlayLayout
├─ IoPoller                   ├─ InputRouter + ScrollInputRouter + TextEditingBridge
└─ AcceleratorTable           └─ PumpHost
```

★ `WindowScope` **已经存在了** —— 它就是今天的 `Application`（除了那四项应用级状态）。
所以 M2 的工作不是"拆成四片"，而是"**把应用级那四项抽出去**"。这比 S4 小得多，
而且收益直接就是多窗口。

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
| 桥事件带 `hwnd` 字段；`Win32Platform::pump()` 按 hwnd 投进对应队列 | 增量（新字段） |
| `pump()` 返回 `false` 的判据改成"最后一个窗口销毁" | 壳侧 `WM_DESTROY` 计数 |
| 新增 `WindowHost::setCloseGuard(?callable)` + 桥 `kEvCloseRequested` | 纯增量 |

★ **这条新增的 `closeGuard` 是本轮评估的额外产出**（§5.1）—— 它独立于多窗口也有价值
（单窗口的"未保存提示"同样需要），但多窗口**必须**有它。

### M2 · App 层按作用域二分

抽出 `AppScope`（`CommandRouter` / `ThemeData` / `TextMetricsProvider` / `TaskHost` /
`IoPoller` / `AcceleratorTable`），`Application` 构造时**可注入**一个：

```php
new ShowcaseApplication($scope);   // 多窗口时 N 个实例共享同一个 $scope
```

- **兼容**：`__construct()` 无参时自建一个 `AppScope` ⇒ 所有既有应用零改动。
- **验收**：smoke 全绿（单实例路径不变）+ 新增"两个 `Application` 共享 `AppScope`，
  `metrics()` 返回同一个对象"的断言。
- **这一阶零真机**，是纯 PHP 重构 —— 可以完全在 gate 里验证。

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
