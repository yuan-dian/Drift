# 评审：证据模型（DevTools 方案）与当前实现

> 对象：`docs/concepts/evidence-model.md`（方案）+ `src/Debug/`、`tools/Debug/`（`exp/debugger` 实现）。
> 方法：把方案里写下的每条承诺逐条对到代码行号。不评"代码写得好不好"，只评"方案说的有没有做到、方案本身该不该改"。

## 结论摘要

方案的中心判断是对的：**证据是产品，面板只是出口**。这条比"再做几个面板"有解释力，也解释了 v1 为什么死。

实现的质量高于一般"调试工具原型"：三挂点在唯一漏斗、查询层单入口、窗口自报、复算优于采样，几条纪律都是真的落在代码里的。

问题集中在两处：

1. **分期顺序被违反。** 方案把 S0（证据包）排在最前，理由是"一份证据能脱离产生它的进程被完整读回"。实现跳过了 S0，直接做完 S1 / S3a / S3b / S3c / S4。后果是"证据可携带"目前只对**驱动脚本**成立，对**事实**不成立——事实仍然只能活在产生它的那个进程里。
2. **方案里三条硬承诺没有落地**：三条指纹分开、发现带规则版本、阈值可配。这三条都不是装饰，它们各自对应一种"红了但归错因"。

另外补三处方案级缺口（成熟工具有、方案没排期）：实时跟随、主动录一段、绘制归因。

---

## 一、方案层：与成熟工具的对照

按 `evidence-model.md:9-17` 的五层能力拆，加上方案的横切项：

| 能力层 | 成熟工具 | 本方案 | 判断 |
|---|---|---|---|
| 常驻观测 | Sentry breadcrumb、Chrome trace | S2 已设计，**未实现**；录制器默认不装 | 差距最大的一项 |
| 多维对齐 | Chrome Performance tracks | 帧号作共享轴 + `align()` 显式帧贯穿三域 | **领先**：tracks 靠共享时间，这里有共享帧号 + 精确因果 |
| 因果链 | Chrome `Initiated by`、React "who scheduled" | 三挂点精确记录，非采样非启发式 | **领先**，且是方案最硬的部分 |
| 自动判断 | Lighthouse 规则集（可配、可关、带版本） | 七组规则已有，阈值 `const`、无版本 | **持平偏弱** |
| 证据可携带 | trace / profile 导出 | S0 未做 | 缺 |
| 构建态分层 | Flutter 的 dev/release 两态 | 方案有（几何先行、属性后置），但 AOT 侧没接 | 设计有，实现无 |

### 1.1 分期倒置的代价

`evidence-model.md:408` 给 S0 的判据是"导出→导入→指纹四值不变；跨进程逐值相同；文件损坏时报错而不是给出半份"。现在能做的只有"同一份脚本在另一个进程重跑"——这不是导入证据，是**重新产生证据**。

区别很实在：重跑要求目标页面在新进程里能重建、依赖能装配、环境一致；导入只要求读一个文件。CI artifact、崩溃现场、用户贴过来的一份证据，三条路都走不通。

而且 S0 比重放便宜：一个版本化的 JSON schema + 一个 reader，不需要新采集、不需要协议改动。**建议把它提到下一个动作**，而不是继续往后排 S5/S6。

### 1.2 "实时"被可判性换掉了

`debug-model.md:159` 把 attach 定义为"宿主子进程，只在客户端发话时动"。这个选择在当时是对的（可判、可重放），但它把成熟 DevTools 最核心的手感换掉了：**应用在跑，切到面板，它在跟**。

Chrome Elements、Flutter Inspector、Android Layout Inspector 全部建立在这件事上。没有它，开发者的实际动作退化成"跑脚本 → 看报告 → 改代码 → 再跑"，一次循环几十秒。

现有代码离它很近：`frames(since=N)` 已经是无状态游标，`AppShell` 已经在逐帧调 `Application::frame()`。缺的不是机制，是"宿主不退出、面板轮询"这个组合。**建议单开一期 S-live，排在 S5 之前**——它比 S6（干预）成本低得多，价值高得多。

### 1.3 缺"主动录一段"

方案只有两个极端：常驻环形缓冲（S2，还没做）和"从头重放到第 N 帧"（`Script`）。

Chrome Performance 的用法是第三种：**点开始、操作、点结束**。它录的是"我刚才做的那件事"，不是"最近 600 帧"。这在排查"这个操作为什么卡"时是决定性的——环形窗口经常已经把起点滚掉了。

底座现成：`Script` 已经在记帧时间戳、输入、具名副作用。缺的是一个锚点（`mark('start')`），让导出能从锚点切。**建议与 S0 合并做**：证据包的边界本来就该由人指定。

### 1.4 绘制只有总耗时，没有归因

九域（`evidence-model.md:74-84`）里"段账"记了 paint 的纳秒，但"**谁**在重画"没有。而这是与因果、几何并列的第三个"为什么"：

- 为什么这一帧重建了这么多 → 因果
- 为什么这个盒子这么大 → 约束链
- **为什么这一帧要重画这么多 → 缺**

成熟工具有对应物：Chrome 的 Paint flashing / Layer borders、Flutter 的 Repaint Rainbow、Android 的 GPU overdraw。方案的边界表（第 452-467 行）逐条列了不做的事，但没提绘制归因——它不是被否决的，是**被漏掉的**。

底座也在：`RecordingCanvas` 有绘制命令，`LayerCache` 有命中/未命中。建议列为 S3d，与 S3a 同级（都不需要反射，AOT 可用）。

### 1.5 没有搜索

2000 节点的树，成熟工具全都有搜索框（Chrome 的 `Ctrl+F`、Flutter 的 Widget 搜索）。方案在 A35 里把"选中与折叠"定成视图状态，却没有"按名字定位"。这是终端面板最实际的痛点：`why` 需要节点 id，而人是先看到类名才知道要找哪个。

### 1.6 方案里判断正确、不该动的四条

1. **证据三元组 (Drive, Facts, Findings)** 比"面板套件"有解释力，且直接推出"三样缺一就退化成日志"。
2. **"窗口是常态、逐域自报"** 是 v1 死因的正面判据，也是这套东西区别于抓包客户端的地方。
3. **复算优于采样**（约束链重算、几何签名去重）——省了热路径，还让判据免费。
4. **查询只给事实、判断留投影层**——这条守住了"证据与意见不长在同一处"。

---

## 二、实现层：方案承诺 vs 代码

### 2.1 确定缺陷（有行号）

**① 三条指纹合并成了一条**
方案 `evidence-model.md:55-68` 要三条分开，理由是故障归属：驱动相同而事实不同 ⇒ 引擎不确定；外部条件不同 ⇒ 比较本身没意义；**合成一条，两种情况都是红，而修复动作完全相反**。
实现 `src/Debug/Session/Digest.php:56-74`：`of()` 只产 `frames` / `nodes` / `digest`，最后一项是前两项的哈希。网络与输入**完全不参与**，也没有 `digest(External)`。
后果：一次"服务端慢了导致重建数变了"的记录，与"引擎不确定"的记录，指纹都是"不同"，读报告的人无法区分。

**② `Finding` 没有规则版本**
方案"实现校订"第 2 条（`evidence-model.md:437`）明确写"发现是派生视图，带规则名与规则版本，不进指纹"。
实现 `tools/Debug/View/Finding.php:29-36`：`rule` / `title` / `address` / `metric` / `threshold` / `recheck` 六个字段，**没有 version**。
后果：改一次阈值或规则方向，历史发现与新发现的比对失去依据，而报告看不出来。

**③ 阈值是不可配的常量**
`tools/Debug/View/Brief.php:55-61`：`SLOW_MS` / `FANOUT_NODES` / `REPEAT_PERCENT` / `RESHAPE_PX` / `RESHAPE_PERCENT` / `RESHAPE_MIN_SIZE` 全是 `const`。
方案反复说"可关、可配阈值"（第 356-358 行）。现在的状态是：换个项目、换个刷新率，规则要么全静音要么全响，只能改代码。

**④ `Query` 的写副作用违反 L1 只读**
方案 `evidence-model.md:283-288`：L1 证据层"只读、可导出；不改写、不补充推断，**写入只发生在采集层**"。
实现 `src/Debug/Session/Query.php:60-101`：`props()` 读活树后 `$this->recorder->rememberProps()` 写回 Recorder；`Query.php:148-178`：`pick()` 为命中的活树节点发号。
`Query` 的类注释（第 18-21 行）诚实地声明了这两个例外，但**方案文档里没有**。要么把例外写进方案的 L1 约束，要么把写动作收回采集层。两处不一致，将来一定有人按方案去假设"查询无副作用"。

**⑤ `shapeCache` 不随 `reset()` 清，帧号重用会拼出假树**
`Query.php:35` 声明 `$shapeCache`，`:1113` 到 600 就整体丢弃——但 `Recorder.php:1035-1060` 的 `reset()` 清了 frames / geometry / props，**没有清它**（它不在 Recorder 里）。
`reset()` 把 `frameId` 归零，下一帧从 1 重新开始。此时 `cascade(1)` 命中 reset 前 #1 的骨架（children / roots / subtree），却用**新帧**的 `causeOf` 与 `signature` 拼装——旧结构配新因果，出来一棵自洽、可读、全错的树。
当前只有 `tests/Feature/Debug/GeometryTest.php:219` 调 `reset()`，所以是潜在缺陷不是现网缺陷。但 `reset()` 的注释写着"测试与**重新开始录**用"，这个 API 一旦被界面用到就立刻显形。这与 A40 批过的"未来帧 clamp"是同一类：**帧号重用 + 缓存键，就是陈旧数据**。

**⑥ `frames(since)` 落后到窗口外时不报丢帧**
`Query.php:282-297`：`since` 分支返回纯 `list`，没有 window。若消费者游标落后于环形窗口起点，它拿到的是"窗口内更新的帧"，与"这段本来就没有重建"给出同一个空数组。
这直接违反方案第 267-275 行的硬规则（"任何交给消费者的证据都必须自报裁剪"）。修法很轻：返回值带上 `window` 与 `dropped = max(0, window.from - since - 1)`。

**⑦ 孤立因果边没有过滤，两处计数对不上**
`Recorder.php:280-292`：`endFrame()` 把 `openCauses` **全量**写进 `FrameRecord`，不校验 `toId` 是否出现在 `openRebuilt`。
`noteStateWrite()` 不受 `open` 约束（帧外 setState 也要记，这是对的），所以会出现：边记了，但该元素在下一帧 settle 前被卸载 → `rebuilt` 里没有它。
于是 `explain()` 的 `byKind['state']`（`Query.php:344-356`，数全部 state 边）会大于 `cascade()` 的 `writes`（`Query.php:438-452`，只取 rebuilt 里的）。两个数字都是真的，差的那一条没有解释——正是方案自己要防的"自洽地说谎"。

**⑧ S0 未做而 S1/S3/S4 已完成**
见 1.1。这里只补一条实现事实：`src/Debug/Session/` 下没有任何"证据包"的编解码（`pack`/`unpack` 只用于 `GeometryRecord` 的约束与 `InputScript` 的事件），也没有 import 入口。

### 2.2 结构性落差（不是 bug）

**`Brief.php` 1023 行承担三件事**：七组规则（`slowFindings` / `stageFinding` / `fanoutFindings` / `groupFinding` / `repeatFindings` / `reshapeFindings` / `geometryGapFinding`）、投影（`project`）、排版（`header` / `detail` / `omissions`）。
方案 L2 的定义是一个纯函数 `project(facts, budget) → (view, omitted[])`。实现把规则与排版塞进同一个类，结果是：加一条规则要改 1000 行的文件，而"规则集"这个概念（可关、可配、带版本）在代码里没有对应物。建议拆 `Rules/`（一条规则一个类，带 `name` + `version` + `thresholds`）与 `Projection`。

**`Panel.php` 893 行承担视图状态 + 终端渲染 + 查询编排**。方案允许 L3 有视图状态（A35），但把 `loadCascade()` / `refreshFrames()` 这类查询编排放进来，会让 S5 的 Web 消费者必须再写一遍。建议把"取数"抽成 `Feed`（给 Panel 与 Web 共用），Panel 只留行表与按键。

**`tools/debug-*.php` 入口没有端到端判据**。`BriefTest` / `PanelTest` 等有 6407 行单测，但八个入口脚本没有一条"跑起来、退出码 0、输出含地址"的判据。本仓有前科：showcase 的 27 页因为形参写错从来没跑起来，而没有任何测试发现。

### 2.3 正面（免得后续改错）

- 三挂点在唯一漏斗（`State::markDirty` / `Element::settlePass` + `update` / `Application::frame`），未安装时一次静态判空——成本承诺是真的。
- `geometryLookup()` 单入口、未来帧返回 `null` 不 clamp（`Query.php:1020-1035`），A40 那类"自洽全错"被结构性挡住。
- `parentOf()` 从记录长出来、`why` 与 `cascade` 共用拓扑（`FrameRecord.php:73-83`），没有第二份父子关系。
- `Finding` 六元组里五个齐全，地址形如 `frame:2 node:341` 与查询参数一一对应——"说得出地址"与"问得回去"是同一件事。
- `Wire` 的 `assertPlain` + `PRESERVE_ZERO_FRACTION` + 8MB 上限（`Wire.php:43-122`），边界纪律扎实，包括"对象编成 `{}` 而内容丢失"这种最难查的形态。
- Panel 的行键 `t:<debugId>` / `f:<帧号>` + 祖先回退 + 状态栏说明（A35），拿位置当身份这个坑被结构性避开。
- 几何按变化存 + 签名去重，把代价压到 O(变化量) 而不是 O(帧×节点)，且 `reset()` 专门清了签名（`Recorder.php:1051-1058`，注释写明了为什么）。

---

## 三、修订后的计划

方案的分期表已经改成"已落地 / 未做"两张（`docs/concepts/evidence-model.md`）。这里给的是可执行批次：一个批次内的项共享同一个判据来源，不跨批混做。

### B0 收口（不新增能力，只修"会读错"的地方）

| # | 改动 | 落点 | 判据 |
|---|---|---|---|
| 1 | `frames(since)` 返回 `window` 与 `dropped` | `Query.php:282` | 游标落后窗口时 `dropped > 0`，且与 `window.from` 自洽 |
| 2 | `shapeCache` 随 `Recorder::reset()` 一起清（或让 `DebugSession` 重建 `Query`） | `Query.php:35`、`Recorder.php:1035` | reset 后 `cascade(1)` 不返回 reset 前的骨架 |
| 3 | `Finding` 加 `ruleVersion` | `Finding.php:29` | 每条发现都带版本；改阈值后旧发现的版本不变 |
| 4 | 阈值从 `const` 提到参数 / 配置 | `Brief.php:35-61` | 同一份事实换阈值，结论按预期变化且可复算 |
| 5 | 孤立因果边留痕：新增 `incomplete` 值（`orphan-cause`），不静默丢弃 | `Recorder.php:280` | `explain.byKind.state` 与 `cascade.writes` 的差可被解释 |
| 6 | L1 的两个写例外写进方案（**已改**） | `evidence-model.md` 四层表 | 文档与代码口径一致 |
| 7 | 八个 `tools/debug-*.php` 入口的端到端判据 | `tools/debug-*.php` | 每条入口跑通、退出码 0、输出含地址 |

### B1 S0 证据包

版本化 schema（`drift-evidence/v1`）+ 导出 / 导入 + **三指纹分离**（Drive / Facts / External）。

判据：导出→导入→三指纹逐值不变；文件损坏或版本不认识时报错而不是给出半份；跨进程逐值相同。

这是"证据可携带"的唯一缺口，也是 CI artifact、崩溃现场、别人贴证据三条路的前置。**顺带把主动录制锚点 `mark()` 做进来**——证据包的边界本来就该由人指定，而环形窗口经常已经把起点滚掉。

### B2 S-live

宿主常驻 + 面板按 `frames(since)` 轮询 + 跟随规则（新帧真有重建才移动光标，否则明说"#3 没有重建"）。

判据：帧号连续无空洞；面板读到的帧与宿主当前帧同源；宿主帧耗时增幅 < 5%。

### B3 S3d 绘制归因

每帧的重画集合与 layer 缓存命中率，进 `Recorder` 的独立窗口（像几何那样按变化存）。

判据：重画集合 ⊆ 结构树且与 `markPaintChanged()` 的调用点对得上；缓存命中率跨帧可复算；不需要反射。

### B4 S5 出口

Web 视图（**复用** `Brief` 的投影函数，不重写规则）+ 搜索（按类名 / 节点 id 跳转）+ 三端一致性判据。

同时做 `Brief` 的拆分：七组规则各成一个带 `name` + `version` + `thresholds` 的类，`Projection` 只管排版。这件事放在这里的理由：Web 端要用同一套规则，不拆就得复制一份。

### B5 之后

S6 干预（仅 dev，判据是结构指纹不变）、S2 常驻黑匣子（含多窗口隔离、AOT 开销测量）。两者都高风险，且 S2 可以复用 S-live 的"常驻 + 窗口"形状。

---

## 四、一句话

方案不用推翻，**它的中心判断是对的，而且是这套东西唯一值得继续做的理由**。要修的是三件事：把 S0 补回来（否则"可携带"只是 slogan）、把三条指纹与规则版本落地（否则"红了"归不了因）、把 S-live 排进来（否则开发期的手感永远差一代）。

实现不需要重写。它的问题不是写坏了，是**方案里写下的几条硬承诺还没有对应的代码**，而缺的每一条恰好都是"报告看起来正常、结论是错的"那一类。
