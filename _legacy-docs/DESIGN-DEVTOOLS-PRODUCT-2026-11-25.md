# DevTools 产品重设计：检查器 · 网络 · 时间轴

> 主设计稿。范围比 [DESIGN-DEVTOOLS-VIEW](DESIGN-DEVTOOLS-VIEW-2026-11-25.md) 大：
> 那份只回答"画面为什么缺席"，这份回答**整个 DevTools 面向真实应用应该长什么样**。
> 两份不冲突——视面稿是本稿第二视图的展开。

## 零、一页结论

三件事：

1. **验收场景先定死**：一个不在仓库里的真实应用，起来后不改一行代码，就能在浏览器里看到画面、点中控件、看到它的请求与因果。示例台只能当回归夹具，不能当验收对象。
2. **三个视图共用一个主时间轴**：检查器、网络、时间轴都以**帧**为横轴、以**节点 id** 为纵轴键。这是 Drift 与 Chrome 最大的结构差异——Chrome 的时间单位是毫秒，Drift 的是帧，而帧才是布局/绘制/因果的真实边界。
3. **放大三条独有资产**，不要为了做得"像 Chrome"而稀释它们：网络 → 因果 → 重绘的全链、约束传播链、可断言的基线。

## 一、验收句

> 应用作者在自己的工程目录执行 `drift dev`，应用窗口起来；
> 浏览器打开提示的地址，左边是控件树、中间是应用当前画面、右边是选中控件的属性与约束；
> 点画面上的按钮，树自动展开到它、右侧显示它的 geometry / constraints / 上一次重建的原因；
> 切到"网络"页，能看到刚才那次请求：谁发起的、花了多久、落到哪个节点、引发了哪次重建；
> 全程没有改过应用代码，没有在终端里敲过查询命令。

这句话里每一段都要能打勾，不能"基本能用"。

## 二、视图一：检查器

### 2.1 真窗口层（放大镜）

恢复 main 的做法（详见视面稿第四节）：`afterPaint` 直绘，黄框 hover、蓝框钉住、祖先链淡框、左上 HUD。`Ctrl+Shift+I` 开关。

它解决的是"手边 1 秒内知道这是什么"，不进浏览器也成立。成本 662 行（`Inspector` / `TreeProbe` / `FrameStats`），已存在，只需端口分离。

### 2.2 web 层（工作台）

左树 / 中画布 / 右检查器。四条交互契约（视面稿第五节）。

与真窗口层的关系：**同一个检查器状态，两个出口**。真窗口点中的节点，web 立刻同步选中；web 里选中的节点，真窗口描框。不是两套状态，是一份 `(selectedNode, hoverNode, frame)` 三元组被两个消费者读。

这条决定了真窗口层不能是"独立小工具"——它必须把状态写进会话，否则两边会各点各的。main 的实现没有这条（它就是独立的），所以搬回时要补：`Inspector::press()` 同时 `Recorder::noteHighlight()`。

### 2.3 覆盖层

内容盒 / 内边距 / 外边距三色（Chrome 的绿/黄/橙），hover 祖先链，选中节点**自己画的像素**高亮（P3 之后）。

## 三、视图二：网络

### 3.1 现状盘点

`NetworkRecord`（`src/Debug/Session/NetworkRecord.php:30-44`）已有：`requestId / method / url / host / path / startedAtFrame / settledAtFrame / status / bytes / elapsedMs / initiator / ok / landed / progress`。

这已经比多数 GUI 框架的记录强：**帧对齐** + **落地节点** + **发起调用点**。

缺三样，且**都不是"拿不到"，是"拿到了没接出来"**：

| 缺 | 数据在哪 | 结论 |
|---|---|---|
| 响应头 | `HttpResponse::headers()`（`src/App/Io/HttpResponse.php:84`），`HeaderCollector` 已经用 `CURLOPT_HEADERFUNCTION` 收好了，还会正确处理重定向的多段 | 已在内存，没进记录 |
| 时序分段 | curl 里就有：`CURLINFO_NAMELOOKUP_TIME` / `CONNECT_TIME` / `APPCONNECT_TIME` / `STARTTRANSFER_TIME` / `TOTAL_TIME` / `SIZE_UPLOAD` / `SIZE_DOWNLOAD` / `REDIRECT_COUNT`。`CurlMultiTransport.php:119-121` 只读了 3 个 | 读 3 个 → 读 11 个 |
| 瀑布图 | —— | 纯呈现，没有画面 |

### 3.2 关键设计：横轴用帧，不用毫秒

Chrome 的瀑布图横轴是毫秒，因为它要回答"这一秒网络卡在哪"。Drift 要回答的是另一个问题：**"这次请求把界面搞成什么样了"**。

所以横轴用帧：

```
帧      120     121     122     123     124     125
网络    GET /api/items ──────────────────────────────→ 200 · 48ms · → node 312
                            ↑ 结算落在 #124
因果    setState 3 ┐
        rebuild 12 ┴──→ ●●●●●●●●●●●●（从 #124 起）
绘制    重画 47 盒 ────→ ●●●●●●●●●●●●●●●●●●●●●●
慢帧    #124 31.2ms ▲
```

这张图能回答 Chrome 答不了的问题：请求结算落在哪一帧、那一帧重建了多少节点、重画了多少盒、有没有踩慢帧。**这就是本分支最该放大的东西。**

### 3.3 网络 → 因果 → 重绘，一条链

点一条请求，右侧给四段：

1. **它是谁发的**：`initiator`（`file:line`）+ `debug probe --what=source` 翻成方法定义位置。
2. **它落到哪个节点**：`landed`（`requestsLandingOn()` 反查 + 请求记录里的落地集合）。
3. **它改了什么**：落地节点在结算帧之后的 `causa` 边——谁 `setState`、谁 rebuild、父传播到哪。
4. **它画了什么**：该帧 `paint` 记录里受影响的盒、层缓存命中/未命中及原因。

Chrome 的第 3、4 段给不出来（它只能给 JS 调用栈和 Paint flashing 的像素闪烁，给不出"这 12 个节点因为这 3 次 setState 重建"）。

**这条链是网络视图的主要价值，不是瀑布图本身。** 瀑布图是入口，链才是产物。

### 3.4 契约边界：只覆盖 `AsyncHttp`

采集挂在门面（`src/App/Io/AsyncHttp.php:87` `tapNetworkStart`），所以**只有走 Drift HTTP 客户端的请求能被看见**。应用若直接用 `curl_*` / `file_get_contents`，一条都采不到。

这不是缺陷，是行业惯例（Flutter 的 Network 也只覆盖 `dart:io` 经 `HttpOverrides` 的那部分）。但必须做两件事：

1. **在 UI 上写明**：网络页底部常驻一行「仅统计经 Drift HTTP 客户端的请求」。
2. **给手动登记口**：`Recorder::tapNetworkStart/Settle` 已是静态入口，暴露成 `Drift\App\Io\ManualRequest` 一类的三个方法，让应用把第三方 SDK 的请求挂进来（一行包一次）。

不做全局劫持（`stream_wrapper_unregister('http')`）：要么改全局行为、要么性能不可控，而且会与 AOT 产物里已经编译进去的 curl 路径打架。

### 3.5 记录结构的增量

`NetworkRecord` 加两个字段：

- `timings: array<string, float>`（键是 curl 的段名，缺段不写，不拿 0 顶替）
- `requestHeaders: array<string, string>` / `responseHeaders: array<string, string>`

纪律：

- 骨架默认带 `timings`（11 个 float，很小），**headers 与报文同档**（`bodies=true` 才记），否则每个请求多几百字节，500 条窗口立刻撑爆。
- `timings` 是**测量值**：进证据包、不进指纹、不进基线。和 `slow-frame` 同类——列出来，但标 `~`，不参与回归判定。
- `elapsedMs` 保留（HTTP 层看到的），`timings.total` 是 curl 层看到的，两者可能差一点点（排队时间）。差值是信息，不合并。

## 四、视图三：时间轴

横轴帧，一条泳道叠四层：

| 泳道 | 数据 | 来源 |
|---|---|---|
| 请求 | 每条请求一条横条，起点 `startedAtFrame` 终点 `settledAtFrame` | `network` |
| 因果 | 每帧 rebuild 数、setState 数 | `frames` / `cascade` |
| 绘制 | 每帧重画盒数、层缓存未命中数 | `paint` / `repaints` |
| 慢帧 | 超阈值的帧标红 | `findings` 里的 `slow-frame` |

点任一帧 → 三个视图同步跳到该帧。拖一段 → 看区间聚合（谁在这 30 帧里反复重建）。

**这是把已有查询变成一台机器的关键**：现在 `frames` / `paint` / `network` 分散在 15 个出口里，各自回答一个切片；时间轴把它们按同一个键摆在一起，人才能看出"请求落地那一帧同时是慢帧，因为重画了 47 个盒"。

## 五、真实应用接入：三个门槛

这是整个设计的成败处。前三个视图做得再好，接不上真实应用就是零。

### 门槛一：入口是文件，不是类名

`Target::resolveApp()`（`tools/Debug/Target.php:72-84`）用类名猜应用，候选只有 `Drift\Dev\Smoke\App\*` 与 `Drift\Dev\Smoke\*`。这个抽象本身错了：**真实应用有明确的入口文件**（`apps/<name>/main.php` 或用户自己的 `index.php`），不需要猜。

改法：`drift dev` 接受 `--entry=<path>`，直接 require 那个文件。类名解析保留为回退（示例台、测试用），但不再是唯一路径。

判据：仓库外的一个最小应用（只有一个 `main.php` + 一个窗口）能被 `drift dev --entry` 跑起来并 attach。这是验收句的第一个勾。

### 门槛二：`--attach` 覆盖全部出口

现在只有 `brief` 与 `web` 声明（`tools/Debug/Cli/Commands.php:35,58`）。其余需要接活会话的 11 个出口——`report` / `panel` / `network` / `node` / `find` / `eval` / `pick` / `pick-live` / `props` / `probe` / `highlight`——全部报"未知参数：--attach"（实测 `debug report --attach`、`debug pick --attach` 均如此）。`targets` 扫 `.drift/` 自己能发现会话、`loop` 自己驱动，这两个不需要。

`Source::resolve()` 的逻辑是唯一一份的，缺的只是**声明**——上提到公共选项段，一次全通。

判据：上述 11 个出口全部接受 `--attach`，一条命令扫表验证（现在接受数 2/13）。

### 门槛三：应用侧零改动

main 要应用显式 `enableInspector()`。验收句要求零改动，所以：

- `drift dev --entry` 启动时注入 `DRIFT_DEBUG=1` 到子进程环境；
- `Application::run()` 检测到这个标志就装检查器与录制器。

但 `Application` 在 `src/`，检查器实现在 `src/Inspect/`（进 ignore），录制驱动在 `tools/`（`src/` 不能依赖）——所以需要一个**最小的应用侧钩子**：

```
src/App/DebugHook.php      // 约 30 行，只声明接口：installed() / frame($root, $canvas) / dispatch($event)
```

- `src/` 侧：`Application` 只认 `DebugHook`，`DRIFT_DEBUG=1` 时 `null`（没有实现就什么都不做）；
- `tools/` 侧：`drift dev` 起进程时先注册实现（检查器 + 录制驱动）。

这样应用零改动，发布产物里只有 30 行接口，`check-debug-scope` 仍绿。

**这是本设计与 main 最大的结构差异**：main 把检查器装进 `Application`（应用必须改代码、产物带 662 行），本设计把它做成可注入的钩子（零改动、产物 30 行）。

### 门槛四（软）：网络采集的档位

live 默认是 `RecordOptions::default()`（`tools/Debug/LiveTicker.php:104`）：因果 + **变化几何** + 网络骨架，报文关。

- 网络骨架默认有 ✓
- headers / 报文要 `--bodies` ✓（设计上合理，但 web 里要有个开关，不能只靠命令行）
- 几何是 `Changed` → 视面画面会 "只出两帧"（已知缺陷）→ 见视面稿第六节的"末帧全量"

## 六、放大三条独有资产

新视图最容易犯的错是"照着 Chrome 抄"，抄完发现丢了原本更强的东西。所以显式列出必须保护的：

| 资产 | 别的工具 | Drift | 在新设计里的位置 |
|---|---|---|---|
| 网络 → 因果 → 重绘全链 | Chrome 只给 initiator 栈 | 已有 `landed` + 因果边 + 重画集合 | 网络视图右侧四段（3.3） |
| 约束传播链 | Flutter 只给当前约束与尺寸 | 逐跳复算，标出归不到基类默认的跳 | 检查器右栏 |
| 可断言的基线 | 都没有 | 七态对比 + 确定性分级 + CI 退 3 | 时间轴上"标 `~` 的不可复现行"、"导出基线"按钮 |

第三条尤其要小心：网络 `timings`、帧耗时都是不可复现的，**新视图把它们画得越显眼，越容易被误当证据**。所以时间轴上凡是不可复现的量，一律用虚线/`~` 标记，与 `BaselineText` 的口径保持一致（`tools/Debug/View/BaselineText.php`）。

## 七、分期与判据

| 期 | 内容 | 判据（可证伪） |
|---|---|---|
| P0 | 入口文件 + `--attach` 全覆盖 + `DebugHook` 零改动 | 仓库外最小应用能起来并 attach；11 个缺失出口全部接受 `--attach`；发布产物只多 30 行 |
| P1 | 检查器合回（端口分离，662 行） | 真窗口 hover/钉住/祖先链/HUD 正确；产物搜不到 `Inspector`；同坐标活树命中 = 记录命中 |
| P2 | 网络补全（timings + headers + web 页） | 一条真请求的 11 个时序段与 curl 直读逐值相等；缺段写缺、不写 0；`bodies=false` 时 headers 不出现 |
| P3 | 网络 → 因果 → 重绘链 | 点一条请求，四段都能定位到同一次 setState 与同一条 paint 记录；改坏 `landed` 反查即红 |
| P4 | 画面（IR → SVG） | 同帧双路 replay 的 `toRgba()` 逐字节相等；19 种 op 全覆盖 |
| P5 | 归属与时间轴 | `data-node` 元素数 = 该节点真画命令数；scrub 到第 N 帧三区 frame 号一致 |

每期配源码级变异自检（改坏一处必须咬红），按项目惯例。

**顺序理由**：P0 是一切的前提（接不上就是零）；P1 最快见效且代码现成；P2/P3 是用户点名要的，且数据大半已在内存；P4 最贵，放后面。

## 八、不做

- **全局 HTTP 劫持**：改全局行为、性能不可控、与 AOT 已编译的 curl 路径打架。只做门面覆盖 + 手动登记。
- **编辑热改**（改属性实时生效）：AOT + 不可变树，成本收益比极差，且会让调试器变成第二个真相源。
- **像素保真承诺**：视面与真机必有抗锯齿/字体差异。golden 归 Raster，视面归 SVG。
- **毫秒级时间轴**：Chrome 用毫秒因为它没有帧概念；Drift 的每个事实都帧对齐，用毫秒会把同一帧的因果拆开。
- **堆转储**：PHP 无此 API，已由 `memory` 的存活跨度定格。
- **为"像 Chrome"而重排信息架构**：Chrome 的五栏是浏览器的五栏，不是 Drift 的五栏。

## 九、验收清单

对着真实应用逐条打勾，全绿才算完成：

- [ ] 仓库外应用 `drift dev --entry=...` 能起来，浏览器能打开
- [ ] 画布显示应用当前画面（不是空框）
- [ ] 画面上点一下 → 左树展开、右栏出数据
- [ ] 左树点一下 → 画布上该节点高亮
- [ ] 右栏显示的每个数，都能在对应 CLI 查询里找到同一个值（前端不自己算）
- [ ] 网络页列出刚才的请求，带时长条
- [ ] 点请求 → 看到它落到哪个节点、引发哪次重建、重画了多少盒
- [ ] 时间轴拖到请求结算那一帧，三区同步
- [ ] 全程没有改过应用代码
- [ ] 发布产物 `check-debug-scope` 绿，且字符串搜不到 `Inspector`

## 十、一句话

Chrome 的 DevTools 是给它自己的网络栈和 DOM 写的；Drift 的 DevTools 应该写给**帧**——因为字节码世界里，一次请求的后果不是"页面重排了一下"，而是"第 124 帧重画了 47 个盒、其中 5 个边界缓存未命中"。前者只能看，后者能算。
