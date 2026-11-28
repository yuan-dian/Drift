# 检查器设计分析（提案，未裁决）

> 2026-11-27。回答四个问题：该不该做、展示什么、架构怎么搭、显示与 DX 怎么处理。
> 结论未经用户拍板，**不进 `docs/decisions/`**；裁决后再迁 `docs/concepts/`。

## 0. 结论先给

**该做了，但不是做"第二个 Web"。**

形态定为：**真机侧最小可信叠加 + Web 侧继续做完整面板**。

- 真机只做四件事：布局边界层、选中盒模型四层、拾取模式视觉反馈、角落 HUD。
- Web 保持"唯一面板"地位，补它真缺的两块：盒模型四层可视化、源码位置。
- **有一件前置必须先修**：快照与调试叠加没有分离。现在打开高亮时，底图里就带着那圈描边（11-26 的"橙框停在按钮上"就是它），拖动 resize 帧还会拍到与几何帧号错配的图。不修这条，任何叠加层做上去都会污染证据。

## 1. 现状盘点

### 已经有的（不必重做）

| 能力 | 位置 | 状态 |
|---|---|---|
| 查询面 27 方法 | `devtools/Session/Query.php` | 完整：`boxes` / `boxModel` / `constraints` / `why` / `cascade` / `paint` / `network` / `async` / `tree` / `treeDiff` / `semantics*` / `align` / `find` / `pick` / `highlight` |
| Web 面板 1665 行 | `devtools/View/web/app.js` | 发现 / 搜索 / 真机像素 + 线框 / 帧轴 + 网络 + 异步泳道 / 结构树（按帧回放）/ 两帧对比 / 节点卡 / 暂停单步拾取 / SSE |
| 真机描边 | `src/App/AppShell.php:1856 paintOverlay()` | **只有一条**：2px `ColorToken::Warning` 描边 + 应用自己的 focus ring |
| 活窗口拾取 | `devtools/bin/debug-pick-live.php` | 布防 → 真窗口点击 → `pick` → `highlight`（A58） |
| 高亮三态自报 | `LiveQuery::highlight()` | `askedFrame` / `drawnNode` / `drawnFrame` / `onScreen` |
| 多窗口隔离 | A53 录制器属应用；A59 并列视图 | 已落地 |

### 缺的

| 缺口 | 影响 | 严重度 |
|---|---|---|
| **快照含调试装饰** | 底图不是"应用长什么样"，而是"应用 + 我上一秒点了谁" | 高（证据可信度） |
| 真机无盒模型四层 | 选中后只知道"它在这儿"，不知道 padding/border/margin 各占多少 | 高 |
| 真机无布局边界层 | 看不出哪个容器把内容挤成这样；只能切到 Web 看线框 | 高 |
| 真机无拾取视觉反馈 | 布防后窗口毫无变化，不知道下一次点击归谁 | 中（误操作） |
| 真机无 HUD | 看帧号/耗时/重画数要切 Web | 中 |
| Web 盒模型四层没画 | `boxModel()` 有查询、有数据，前端只画了一个矩形 | 中 |
| Web 缺源码位置 | `props`/`why` 给 `site`，没有"跳到哪里" | 低（AOT 不做，dev 可做） |
| 跨窗口归因 | 多窗只能并列看，不能"这行是哪个窗画的" | 低（需全局节点 id） |

## 2. 为什么是"最小叠加"，不是"第二套检查器"

三条既有裁决已经把这题答了一半：

1. **面板在进程外**（`docs/concepts/evidence-model.md` 边界表）："用框架自己的树控件画检查器 — 不做。观测面会随故障一起塌；高亮走绘制后叠加，面板在进程外。"
2. **盒模型原料全在 `Modifier`**（同上 §盒模型）：四层 = `inset` / `BoxStyle` / pad / size−pad，"只读投影，不新增字段、不改 layout"。
3. **约束链复算优于采样**（同上 §我们的落点）：`tighten → deflate → loosen` 沿父链重算，已在 `constraints()` 落地。

所以真机侧**不该**出现树、面板、搜索框、请求列表。这些 Web 已经做得比真机窗口好，重复投资只会得到两份会漂的实现。

真机叠加的边际价值只有三条，且每一条 Web 都给不了：

- **实时**：Web 看的是快照 + 事后几何，live 时天然滞后；真机是同一帧。
- **不切窗口**：改一像素、转一眼，动线不中断。
- **空间关系**：多窗并排时"这个框属于哪个窗"只有真机看得见。

反过来，叠加层的代价也明确：会进快照（前置要修）、每帧多一次遍历（要开关）、Dpr 下容易糊（要对齐）。

## 3. 该展示哪些信息

按"回答什么问题"组织，不按数据源组织。**同一份数据两个消费者各取所需，不是两次采集。**

### 六个问题维度

| 维度 | 内容 | 来源 |
|---|---|---|
| **A 时间锚** | 帧号、帧耗时、是否静止帧、窗口 `from..to`、是否 folded | `stats()` / `window` |
| **B 身份** | 短类名 / 全名、节点号、祖先路径、文本内容、是否 stateful、本帧是否重建 | `tree()` / `why()` / `typeOf()` |
| **C 几何** | 绝对 `rect`、`size`、`offset`、四层盒、父给约束 vs 子收到约束、是否在视口外 / 被裁剪 | `boxes()` / `boxModel()` / `constraints()` |
| **D 因果** | 重建原因（causes）、状态写入点、父链 cascade、是否重画、层缓存命中 | `why()` / `cascade()` / `paint()` |
| **E 外部** | 落地的网络请求、异步 job（发起者/落地节点）、输入命中链、源码 `file:line` | `align()` / `async()` / `pick().chain` / `props().site` |
| **F 诊断** | 规则发现、严重度、证据口径（阈值/规则集/指纹） | `brief` / `Facts` |

### 分工矩阵

| 维度 | 真机叠加 | Web 面板 | 理由 |
|---|---|---|---|
| A 时间锚 | HUD 一行（帧号 + 耗时 + 重画数） | 完整帧轴 + 泳道 | 真机只要"现在这帧贵不贵" |
| B 身份 | 选中标签（类名 + 尺寸） | 树 + 节点卡 + 面包屑 | 树在真机放不下 |
| C 几何 | **四层盒 + 边界层 + 约束标签** | 四层图 + 数值表 | 这是真机的主场 |
| D 因果 | 不展示 | 完整（why/cascade/paint） | 长文本，窗口里没法读 |
| E 外部 | 不展示 | 完整 | 同上 |
| F 诊断 | HUD 计数（`3 warning`） | 完整列表 + 证据口径 | 真机只报警，不解释 |

**判据式表述**：真机 HUD 的任何数字，必须在 Web 的同一响应里能找到逐值相同的一份。两份各算一次，迟早对不上。

## 4. 架构

### 四层

```
① 数据面（不改）      devtools/Session/*Query  — 27 方法，检查器只消费
② 策略面（新增）      devtools/Inspect/       — 开关状态、选中、绘制策略
③ 绘制原语（最小新增） src/App/AppShell       — 一个闭包字段 + 调用时机
④ 接线（devtools）    Host/LiveTicker + 协议  — 装/摘、inspect 动词
```

### ③ 的关键：`src` 不认识检查器

A72 的结构断言是"`src` 的代码不引用 `Drift\DevTools\`"，所以策略不能进 `src`。形状沿用已有两处先例：

```php
// src/App/AppShell.php
/** @var null|\Closure(Canvas, ?Element, float): void */
private ?\Closure $debugOverlay = null;

public function setDebugOverlay(?\Closure $painter): void { ... }
```

- 闭包签名 `(Canvas $canvas, ?Element $highlight, float $dpr)`：canvas 是唯一能在帧出口拿到的东西；`$highlight` 让策略层不必自己记住选中；`$dpr` 让线宽能对齐物理像素。
- `src` 只负责**时机**：内容画完、快照拍完、屏幕刷新前。
- 不装时是一次静态判空（与 `frameObserver` 同形），AOT 产物只多一个字段。
- 策略实现（`LayoutBounds` / `BoxModel` / `Hud`）在 `devtools/Inspect/`，解释执行，不进任何应用 `sources`。

### 前置：把"内容"与"调试装饰"分开

现在 `paintOverlay()` 的顺序是：

```
应用内容 → debugHighlight 描边 → focusRing → frameObserver 拍照
```

快照因此必然带调试装饰。修法是把这一函数拆成两段，并在中间拍照：

```
应用内容 → focusRing（应用自己的，算内容）→ frameObserver 拍照
        → debugHighlight + 检查器叠加（只在屏幕，不进快照）
```

顺带修掉 `repaintForResize()` 那条路径（`src/App/AppShell.php:894`）：它也调 `paintOverlay`，但没有 `beginFrame/endFrame`，拍出来的图会带**上一帧**的帧号 —— 拖动窗口时底图与几何错配。修法同一条：那条路径不拍照。

**判据**：开 / 关高亮两次快照，PNG 逐字节相同；拖动 resize 帧不产生快照文件。

### 协议与出口

- 新增一个动词 `inspect`，参数 `on`（逗号分隔：`bounds,box-model,hud,pick`）与 `off`。**不新增第二个动词**（`highlight` 管选中，`inspect` 管叠加开关）。
- `host` 侧 `InspectState` 是唯一真相；`Application::setDebugOverlay()` 由 `LiveTicker` 在 bind 时装、detach 时摘。
- 出口数仍然只有 `Registry` 一份（A78），加了 `inspect` 就同步生成块与 `--help`。
- **`inspect` 不进证据、不进指纹、不进重放**（同 A51 对高亮的处置）。

## 5. 显示效果

### 颜色与线宽

- 四层盒沿用 Chrome 惯例但**不硬编码**：margin 橙 / border 黄 / padding 绿 / content 蓝。颜色定义在 `devtools/Inspect/InspectPalette.php`，`src` 侧一个色值都不知道 —— 正好由闭包注入解决。
- 线宽按 DPR 对齐：目标"屏幕上 1 物理像素"，即逻辑宽度 `1.0 / $dpr`。现有 `Paint::stroke(..., 2.0)` 在高 DPI 下是 2.x 物理像素，偏粗；选中主框可以 2 物理像素，辅助层 1。
- 半透明用 `alpha` 而非浅色：深色底图上浅色会消失。

### 淡画：复用 Web 已经踩过的判据

Web 上出现过"145 个盒里 36 个宽容器框叠成满屏横线"，结论是：**容器框降透明度、选中/悬停祖先链豁免**。真机同样适用，且判定应当只有一份：

- 容器（本帧有子盒）普通态 alpha 0.25；
- 叶子/可命中控件 alpha 0.5；
- 选中链（选中 + 祖先 + 子孙一层）alpha 1.0；
- 视口外的盒**不画**（`rect` 与视口求交，为空的跳过）—— 这条 Web 上还没做，真机更要紧。

**注意**：判定逻辑现在写在 `devtools/Session/Query.php::boxes()`（输出 `container` 与 `chain`）。真机叠加直接消费同一份输出，不要在 overlay 里再算一遍"谁是容器"。若为了"活树即时"必须自己算，就把它提成一个共享策略类，两处调用同一个。

### HUD

- 位置：右下角，**实底**（`ColorToken::Pane` + 90% alpha），深底浅字或浅底深字由主题决定；不透明才能保证可读。
- 四行以内：`#1234  3.2ms  7 repaint` / `SegmentedControl · 320×48` / `pad 12 · border 1 · margin 0` / `⚠ 2`。
- 可折叠（`Tab` 切换），不遮内容时默认展开，内容被遮时自动缩成一行。
- 字体走主题的 `TextStyle`，不新建字体栈。

### 性能

- 关闭时：零遍历、零绘制（静态判空）。
- 打开时：只遍历活树一次，只画与视口相交的盒；不做每帧缓存重建（`effectiveBox()` 已是 O(1)）。
- 不在 `layout()` / `paint()` 里插钩子（会破坏纯函数判据 `LayoutSelfCheck`）。
- 帧率影响要么量出来、要么不声称。先按"叠加层打开时帧耗时增幅 < 1ms"当目标，用现成的 `recorder-overhead.php` 口径量。

## 6. 开发者体验

| 事项 | 设计 |
|---|---|
| 入口 | `php bin/drift debug inspect --on=bounds,box-model,hud`；`--off` 全关 |
| 粒度 | 四个独立开关，因为全开一定糊（边界 + 四层 + HUD 同时上，内容就看不见了） |
| 默认 | 全关。拾取模式由 `pick-live` 负责，不混进 `inspect` 默认行为 |
| 真机快捷键 | 可选：`Ctrl+Shift+I` 切 HUD（`AcceleratorTable` 已存在）。**默认不注册** —— 应用可能自己用这个组合，冲突要在 attach 时检测并让位 |
| 复位 | `inspect --off` + `highlight 0` 两条；会话结束（detach）自动摘叠加层并清高亮 |
| 与 Web 联动 | 已通（选中镜像、拾取双向），**不重做**；新增的只是 Web 顶部显示真机当前开了哪几层 |
| 可发现性 | 走查指南加一行症状："看不出是谁把内容挤成这样 → `debug inspect --on=bounds`，或 Web 看线框" |
| 失败可读 | 三种"没画出来"分开：`no-box`（复合控件）/ `off-viewport`（在视口外）/ `evicted`（已回收）。沿用 `highlight` 的 `onScreen`/`askedFrame`/`drawnFrame` 三态，不合并成一句"没找到" |

### 一条容易做错的

**给真机加"悬停即高亮"是错的方向**。每次 `mousemove` 写一次 `highlight`，会在被调应用里制造一串真实绘制 —— 那是污染，不是调试。但**点击拾取应当是模式**（A58 修订）：点一次进入，之后每次点击都换选中，`Ctrl+Shift+I` / Esc 退出；这与"悬停跟随"不是一回事 —— 点击是离散动作，进入模式后本来就该每次归调试器。应用自己绑了同一组合时快捷键让位，只留 CLI。模式期间 HUD 首行标 `PICK`，Web 顶栏镜像"拾取中"。

## 7. 分棒

| 棒 | 内容 | 判据（能咬红的形式） | 规模 |
|---|---|---|---|
| **0 前置** | 快照与调试叠加分离；resize 帧不拍照 | 开/关高亮快照 PNG 逐字节相同；拖动 resize 不产快照 | S |
| **1 骨架** | `AppShell` 可插拔 overlay 闭包 + `devtools/Inspect/` 骨架 + `inspect` 动词 | 不装时零开销（帧耗时与未装逐值相同）；装了不改结构（digest 不变、像素变） | M |
| **2 盒模型** | 四层色块 + 尺寸/类名标签，替代单条描边 | 四层各自矩形的面积比等于 `boxModel()` 数值；选中链外不画 | M |
| **3 边界层** | 全体盒边界、容器淡画、视口裁剪、选中链豁免 | 容器 alpha < 叶子 alpha；视口外盒数为 0；链上 alpha 满 | M |
| **4 HUD** | 帧号/耗时/重画数/选中摘要/发现计数 | HUD 每个数字与 `/api/state` 同帧逐值相同 | S |
| **5 Web 补齐** | 盒模型四层图 + 开关镜像 + 源码位置（dev） | 同一节点的四层数值，Web 与真机标签逐值相同 | S |

每棒的变异方向都要能回答"什么改动让它报红"，否则判据不成立（E2）。

## 8. 不做，以及为什么

| 不做 | 理由 |
|---|---|
| 真机里画结构树 / 面板 | 已有裁决：观测面会随故障一起塌；面板在进程外 |
| 实时属性编辑 | Widget 不可变、AOT 无反射；dev 做会造成两套行为 |
| 悬停即高亮 | 每次 mousemove 写高亮 = 在应用里制造真实绘制 |
| 源码跳转（AOT） | 无源码映射；dev 形态可做（反射取文件名），排在棒 5 之后 |
| 跨窗口归因 | 需要全局节点 id，是独立裁决题，不塞进检查器 |
| 设计稿叠加（Android Layout Inspector 那种） | 没有真实用例；先有"能不能对上"的需求再做 |

## 9. 风险

| 风险 | 处置 |
|---|---|
| 叠加层进快照 | 棒 0 前置修掉，之后每棒都保留这条判据 |
| 帧率下降 | 开关 + 视口裁剪 + 不插钩子；用量具说话，不声称 |
| DPI 下线条糊 | 线宽按 `1/dpr` 对齐，真机 1.5x / 2x 各验一次 |
| HUD 遮内容 | 默认只在有选中时出现，可折叠，被遮区域自动缩减 |
| 两份"谁是容器"的判定漂移 | 消费 `boxes()` 的 `container`/`chain`，或提成共享策略类 |
| 检查器状态泄漏到下次会话 | `detach()` 时摘 overlay + 清 highlight，且判据覆盖 |

## 10. 最短可交付

如果只做一棒，做**棒 0 + 棒 2**：
修掉快照污染（证据可信度），并把单条描边升级成盒模型四层（回答"它多大、padding 多少"）。
这两件加起来不到两天量级，且立刻能用；边界层与 HUD 是"看得更爽"，不是"看得见"。

---

## 11. 评审意见（2026-11-27，逐条对照代码）

正文不改，意见单独记，采纳时再合并。

### 结论

方向成立：不做第二个 Web、棒 0 当前置、颜色与策略压在 `devtools/` 三处判断都对。
但**按现在的写法直接开工，棒 1 会卡住**：闭包签名要的三样东西有两样拿不到；A51 留下的
"第二个叠加层"口子被这次触发而方案没接；HUD 的数字在调用点上与它自己的判据互相矛盾。

### P0：不改就会做错方向

**P0-1 闭包签名 `(Canvas, ?Element, float $dpr)` 里，`$dpr` 和"整棵树"都没有来源。**

- `AppShell` 没有 dpr 字段（`grep -i dpr src/App/AppShell.php` 零命中）；dpr 只存在于
  `Scale`，而 `Scale` 是 `RasterCanvas` 构造时传进去的（`src/Platform/Win32/Win32RasterSurface.php:75-80`：`new RasterCanvas(..., Scale::of($this->dpr))`）。
- `Canvas` 接口**不暴露** scale / dpr（`src/Rendering/Canvas.php` 27–120 行只有 save/translate/clip/draw*/pushLayer），
  所以闭包里连"从 canvas 反查 dpr"这条路也走不通。
- 边界层要遍历**全体盒**，闭包只拿到 `?Element $highlight` —— 沿它的父链只能上溯，画不出兄弟与旁支，等于棒 3 做不了。

改法：签名收成 `(Canvas $canvas): void`，策略层持有 `Application` 与 `Query`，自己
`root()` 遍历、自己从 canvas 之外的来源取 scale（`Platform::scale($handle)`，或让
`Application` 透一个 `dpr()`）。`src` 因此不必为调试造任何 DTO —— 这比"传三样"更守
A72 的位置划界。`$highlight` 也可以去掉：策略层有 `Query` 就能问"当前选中谁"。

**P0-2 A51 的被推翻条件正好被这次触发，方案没接。**

A51（`docs/decisions/README.md:108`）写明的推翻条件是：

> 出现第二个需要"画了没"记账的叠加层（那时把这对字段收成一个 `OverlayFact` 而不是再加一组）

检查器叠加层就是第二个。棒 1 必须先做两件事，否则就是裁决里点名的"再加一组"：

1. `devFacts['overlayDrawn']` + `Recorder::$overlayDrawn` 这组字段收成一份 `OverlayFact`（按层归属：highlight / bounds / box-model / hud）。
2. **失效判据要并进两处**，正文只字未提：
   - `src/App/AppShell.php:719-723`（`paintRequested`）
   - `src/App/AppShell.php:834-839`（`continueFrame` 的调帧判据）

   漏第二处的后果 A51 已经写过一遍："设了高亮但屏幕上什么都没有" —— 开 `inspect` 后
   树没变、`Frame::run()` 整帧跳过、闭包根本不跑，用户会以为命令没生效。这条是既有
   bug 的复刻，不是新风险。

**P0-3 HUD 的"逐值相同"判据在调用点上不可能成立。**

`paintOverlay()` 跑在帧中间（`endFrame()` 之前，见 `AppShell.php:1881-1886` 注释"画布在
`endFrame()` 里就被释放"），而它拿到的帧号是**正在录**的帧（`$this->debugRecorder?->frameId()`）；
`stats()` 的 `lastCostMs` / `lastRebuilt` 是**上一完成帧**的（`src/Debug/Session/Recorder.php:1325-1336`）。
两个数字差一帧 —— 这正是 B5 修过一次的"时刻口径取了两端"。

因此 §7 棒 4 的判据（"HUD 每个数字与 `/api/state` 同帧逐值相同"）不可执行。两种收法：

- HUD 明写"上一完成帧"，帧号也用 `lastFrameId()`，判据改成"与 `/api/state` 的**同一帧号**配对"；
- 或者 HUD 不显示耗时，只显示"本帧帧号 + 累计重画"，这两个在同一时刻定义下成立。

顺带：像素上的数字没法"逐值"读。要验证就必须让策略层同时暴露一份结构化
`hudValues()`，判据比结构化值；指望 OCR 等于没判据。

### P1：内部不自洽

**P1-1 §3 开头的"同一份数据两个消费者各取所需，不是两次采集"对真机叠加不成立。**
Web 读**记录**（`GeometryQuery` 的 history），真机要读**活树**（`effectiveBox()`）。
`boxes()` 输出的 `container` / `chain` 是记录口径，静止帧下会滞后（几何只在 `Changed`
落账）；活树自算又和 Web 分叉。§5 把它降成一句"注意"，应该升成待决：真机叠加到底
消费 `boxes()` 还是自算。可共享的只有**谓词与权重表**（纯函数：`container→0.25`、
`in-chain→1.0`），不是数据读取 —— 这两件事在方案里被混成"判定只有一份"。

**P1-2 §4"`inspect` 不进证据、不进指纹、不进重放"与 §6"Web 顶部显示真机开了哪几层"冲突。**
镜像要读 `/api/state`（`devtools/View/Web.php:116`），而 state 就是证据面。要拆成两句：
**不进指纹 / 不进重放**（正确，同 A51 对高亮的处置）；**进 state 的 operational 段**
（否则 Web 无从镜像）。

**P1-3 §7 棒 5 含"源码位置（dev）"，§8 说源码跳转"排在棒 5 之后"。** 二者取一。
按 evidence-model「源码跳转按构建态分开」那段，dev 形态只要一行反射，放进棒 5 是合理的；
那就把 §8 那行的"排在棒 5 之后"改成"不进 AOT，dev 随棒 5 落地"。

**P1-4 §6"`Ctrl+Shift+I` 冲突时检测并让位"被写成"可选"，但检测本身是工作量。**
`AcceleratorTable` 是**应用声明**的表（`src/App/AcceleratorTable.php`），调试器要读它的
内容才知道有没有冲突。要么明确"attach 时读表，命中就不注册"，要么这行删掉只留命令行
（命令行已经够用，快捷键是锦上添花里最贵的那个）。

### P2：判据不可执行 / 事实勘误

| 位置 | 问题 | 改法 |
|---|---|---|
| 棒 1 判据 | "帧耗时与未装逐值相同" —— 浮点计时不可能逐值相同 | 改"未装时 `paintOverlay` 的绘制调用数与改动前逐值相同"，或"未装时像素逐字节相同" |
| 棒 2 判据 | "四层面积比等于 `boxModel()` 数值" —— 面积比反推不出四层 | 改"四层外接矩形**逐边差**等于 `margin`/`padding`/`border` 数值"（字段见 `GeometryRecord::toArray()`，`src/Debug/Session/GeometryRecord.php:180-198`） |
| 棒 0 判据 | "开/关高亮两次快照 PNG 逐字节相同" 会被节流吞掉：`Shot::MIN_GAP_MS = 250.0`（`devtools/Host/Shot.php:37`），且 `capture()` 还有序号去重 | 判据里把 `gapMs` 设 0，或直接对同一个 `RasterCanvas` 走两次绘制取像素 |
| §5 性能 | `tools/recorder-overhead.php` 量的是**录制器**开销，不是叠加层绘制 | 用已有的 `FrameStage` 分段计时 / `stats()` 帧耗时做开/关对比，并写明样本量 |
| §1 表 | "查询面 27 方法" → 实际 28 个 `public function`（`devtools/Session/Query.php`） | 改 28，或干脆不写数字（A78 的教训：散文里不要抄枚举事实） |
| §5 线宽 | "`2.0` 在高 DPI 下是 2.x 物理像素"方向对（canvas 带 Scale、坐标是逻辑单位），但**闭包拿不到 dpr** | 见 P0-1；不解决来源，"按 `1/dpr`"无法实现 |

### P3：节奏

**P3-1 §10"棒 0 + 棒 2 不到两天"跳过了棒 1。** 四层盒要画在闭包里，闭包要 `OverlayFact`，
所以棒 2 硬依赖棒 1。最短可交付是 **0 + 1 + 2**，或者把棒 1 的"骨架"并进棒 0 一起做
（它们改的是同一段代码、同一组判据，分两棒反而要改两遍）。

**P3-2 时机。** `exp/debugger` 上 A73–A80 一长串提交全部未 push、CI 未验。棒 0 该做 ——
它是**证据链的 bug**（底图带调试装饰、resize 帧帧号错配），不是新功能。棒 1 之后的
叠加层建议等这批落地、CI 绿了再开，否则未验证面继续往上堆，出问题时二分成本极高。

### 认可的部分（不改）

- "不做第二个 Web"与 `docs/concepts/evidence-model.md` 边界表原文一致，引文准确（"观测面会随故障一起塌；高亮走绘制后叠加，面板在进程外"）。
- 棒 0 当前置、且它是证据可信度问题的判断，比"检查器功能"本身重要 —— resize 帧拍出旧帧号图那条也抓得准（`repaintForResize()` 调 `paintOverlay` 时 `record=false` 只关掉了 `devFacts` 记账，**没关掉 `frameObserver`**，所以确实会拍）。
- §6"悬停即高亮是错的方向"理由成立（每次 `mousemove` 写高亮 = 在被调应用里制造真实绘制），与 A58 两段式一致。
- 颜色定义在 `devtools/Inspect/`、`src` 一个色值都不知道 —— 与 A72 的位置划界同形。
- `--on` 用可重复参数比逗号列表更贴现有 CLI：`Opt::append()` 已经存在（`devtools/Cli/Opt.php`），
  逗号列表等于在 schema 外再造一种语法，`--help` 也生成不出来。

## 12. 落地记录（2026-11-28）

棒 0 / 1 / 2 已按 §11 的 P3-1 合成一轮落地。裁决编号 A81，入口 `debug inspect`，策略层在
`devtools/Inspect/`（`Inspector` / `BoxLayers` / `Palette`）。与提案的差异都是实现时才发现
更合适的形状，不是漏做：

- **参数名 `--on` → `--layer`**（`Opt::append()` 不变，`--layer=a,b` 与 `--layer=a --layer=b`
  同义）。`--on` 读起来像"打开一层"，而语义是**覆盖整组**：不给就是全关。名字与语义
  反着来，是下一轮误读的种子。
- **`OverlayFact` 对象 → 按层映射**。A51 的原话是"把这对字段收成一个 `OverlayFact`"，
  但壳只需要"这一帧哪几层画了"几个布尔；造值对象意味着 `src` 要么引用它（A72 破界）、
  要么为它再传一遍数组。落地形状是 `devFacts['overlays'] = ['highlight' => true,
  'inspect' => true]`，账本侧同名映射。**没有** `OverlayFact` 类。
- **P0-1（闭包拿不到 dpr / root）**：闭包仍只收 `Canvas`，策略层自己从注入的
  `Application` 拿 `treeRoots()` 与根盒尺寸当视口 —— "画哪棵树、裁到多大"不需要壳告诉它。
  dpr 没用上：线宽按逻辑像素（`Canvas` 带 Scale，坐标本来就是逻辑单位），要按物理像素
  折算时再读 Scale。
- **配色不硬编码**：四层与三档边界全部从 `ThemeData` token 派生（`Warning` / `Accent` /
  `Success` / `Info`），深浅主题不用各调一遍。Chrome 的橙/黄/绿在深色面板上会糊成一片，
  这是不照抄的理由。
- **交账不绑选中号**：`Recorder::endFrame()` 原来要求 `highlightNode > 0` 才记"上屏了"。
  边界层不需要选中，照抄会让"屏幕上有框、账上没画过"。改为逐层判，`node` 允许是 0
  （意思是"这一层与选中无关"）。
- **P1-2 已解**：`inspect` 只进 `state.inspector` / `state.inspectorDrawn`，不进包、
  不进指纹、不进重放。
- **P2 判据改法已采纳**：四层判据是"同一帧与 `boxModel()` 逐值相同"（含
  `content 原点 = 绝对原点 + padding`），不是面积比；快照判据走同一个 `RasterCanvas`
  两次绘制取像素，不碰 `Shot::MIN_GAP_MS` 的节流。
- **仍未做**（与 §8 一致）：快捷键 `Ctrl+Shift+I`（P1-4 的结论是命令行已够用，快捷键是
  锦上添花里最贵的那个）、悬停即高亮、源码位置。

### 棒 4（HUD，2026-11-28）

`hud` 作为第三层落地：右下角实底面板，按排查动线分五行 —— 身份（`#节点 类型`）、
尺寸与绝对位置（`608 × 16.8 @ 0,270`）、帧耗时与 `build/layout/paint` 分段、
父级与父给约束（`parent #503 Padding → w tight 608`）、只列非零的间距层。
`ColorToken::Pane` + 90% alpha，标题用 `Text`、正文 `Muted`、`PICK` 用 `Warning`、
`⚠` 用 `Danger`。

- **数字与 `stats()` 同一份**：`paint()` 跑在帧中间，正在录的帧还没有 `costMs`，所以
  帧号、耗时、重画数全取上一完成帧（`lastFrameId()` + `stats()`）。`hudValues()` 既是
  绘制输入，也是 `inspect` 回执与测试的判据 —— 评审 P0-3 的"差一帧"靠同源消除，
  不是靠注释约定。
- **没选中不画**：`hud` 与 `box-model` 同一条前置，声明在 `Inspector::NEEDS_SELECTION`
  一处；输出层只负责把命中的层名念出来。`Hud::paint()` 返回 false，记账停在上一帧。
- **第四行（发现计数）未接**：`Facts::gather()` + `Brief::project()` 每帧跑会把规则
  引擎拖进热路径，而记录里没有轻量计数入口。真机 HUD 的职责是报警，完整列表在 Web，
  空着比每帧重算一份可信。
- **未做**：折叠（`Tab` 切换）与"遮住内容自动缩成一行"。前者要输入钩子，后者要命中
  测试，都不属于策略层能独立完成的范围；先固定三行。

### 棒 5（Web 补齐，2026-11-28）

Web 侧不另造几何：选中节点的四层矩形由 `/api/node` 的 `boxModel` 数值推导，公式与
真机 `Inspector::paintBoxModel()` 同构（margin 外扩 / border 跨线 / padding 内缩 /
content 填充）。节点卡片补 `margin` / `border` / `padding` 三行，格式与真机 HUD
同一条读法（四边相同给一个数，否则 `l/t/r/b`）。

- **不扩证据 schema**：曾考虑让 `boxModel` 直接返回四个 rect，省掉 JS 的三行公式；
  但 `boxModel` 在证据面里（进包、可回放），为"怎么画"加字段会让证据膨胀，而收益
  只是三行公式。否决。
- **开关镜像**：`/api/state` 的 `inspector` 已在（A81），Web 顶部镜像成
  `真机叠加 bounds · hud`；不提供控制 —— 开关只有 `debug inspect` 一处。
- **源码位置**：仍不做（§8）。
- **判据**：数值同源（Web 与真机都读 `boxModel`），格式规则同一条；
  `check-web-routes.php` 覆盖新 id；前端只有 `node --check`，没有单测。


