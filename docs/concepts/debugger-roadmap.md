# 调试器成熟度路线：从证据引擎到 DevTools

> 本页定义调试器从“可判的证据”走到“可用的 DevTools”的目标形态、能力分层、缺口与分期。机制细节见[调试模型](debug-model.md)与[证据模型](evidence-model.md)；本页只回答“还差什么、按什么顺序补、怎么算完成”。

## 结论

当前实现已经跨过多数 GUI 框架没有跨过的那道坎：**因果是精确记录的，不是采样推断的；记录是可重放的，不是一次性快照；证据是可携带的，不是绑在某个面板里的**。这三件事构成了“证据引擎”的底座。

但它还不是一个成熟的 DevTools 产品。差距不在面板数量，而在三条能力线：

1. **生产可用线**：录制开销已有量具与数字，多窗口隔离已落地（录制器属于应用，见 A53），但 AOT 常驻黑匣子与崩溃取证仍未验。
2. **调试体验线**：不能 attach 正在跑的真窗口，没有 select / highlight / 盒叠加；绘制归因已落地（重画集合 + 层缓存命中率），搜索已落地（`find` 回地址），但 Web 仍缺树与时间轴。
3. **生态线**：发现规则已注册表化并带严重度、开关与基线回归，但无插件式外部规则；协议与文档存在多处漂移。

所以下一阶段的中心不是“再加几个视图”，而是**先把采集预算、生命周期与生产可用补齐，再让视图消费同一份证据**。任何新域如果没有预算、没有判据、没有独立消费者，就不进入主线。

## 能力现状

图例：**已落地** = 有代码、有判据、有消费者；**切片** = 主路径可用但缺关键一块；**未做** = 只有设计或只有查询面。

| 能力 | 现状 | 成熟参照 | 判断 |
|---|---|---|---|
| 帧因果（谁写、谁重建、父传播） | 已落地：三挂点 + 精确边 + 结构折叠 | Chrome initiator、Flutter rebuild stats、React Profiler | **领先**：因果不是估的 |
| 重放与指纹 | 已落地：脚本驱动 + 三指纹 + 重开 N 次 | Chrome Recorder、Redux 时间旅行、Tracy | **领先**：重放是重新执行 |
| 证据包 | 已落地：NDJSON + manifest + 版本 + 逐域 counts + 导入校验 | Chrome trace、Perfetto | **领先**：语义化、可复算 |
| 几何与约束链 | 已落地：帧末记录 + 复算 + 三类 miss | Chrome Elements 盒模型、Flutter Layout Explorer | **领先**：给的是传播链，不只是当前值 |
| 属性（Widget / State） | 切片：dev 反射、按需快照、脱敏 | Flutter Inspector、React DevTools | 可用，但只覆盖 dev |
| 网络 | 切片：骨架 + 发起者 + 落地帧 + 报文 | Chrome Network、Flutter Network | 骨架领先；缺瀑布图与 headers/throttle |
| 确定性发现 | 切片：9 条规则 / 10 个编号、三级严重度、可按编号开关、10 个阈值可覆盖、基线回归进 CI | Lighthouse audits、Flutter perf | 缺规则权重 |
| 证据搜索 | 已落地：节点类型 / 网络骨架按字面子串找，产物是地址 | Chrome 全局搜索、Flutter Inspector 搜索 | 未覆盖属性与绘制域 |
| 终端面板 | 切片：时间轴 + 因果树 + why | Flutter DevTools、Chrome | 键盘可用；缺检查器与网络视图 |
| Web 视图 | 切片：几何画布 + 发现 + 节点卡 + 搜索 | Chrome/Flutter 多标签 | 缺树、时间轴 |
| 实时 attach | 未做：只有 headless / 宿主子进程 | Chrome、Flutter、GammaRay | **最大体验缺口** |
| 多窗口 / 多 target | 切片：同进程每个窗口一份录制器、互不串证据；`Discovery` 多条目与跨窗口合并视图仍待做 | 成熟 DevTools 多 target | S2 的前置已清，缺"看得见两个窗口"的消费者 |
| 绘制 / layer / GPU | 切片：重画集合 + 层缓存命中 / 未命中原因 + 60 帧窗口 | Chrome Layers、Flutter Raster、Unity Frame Debugger | 归因已有；缺 layer 树、绘制矩形与 GPU 段 |
| 内存 / 泄漏 | 未做 | Chrome Memory、Flutter Memory、Tracy | 长跑应用必需 |
| 异步 / worker 时间线 | 未做：只有 HTTP 骨架 | Chrome async stacks、Perfetto | 对 Drift 异步模型很关键 |
| 崩溃 / 生产黑匣子 | 未做 | Sentry breadcrumb、Chrome trace | S2 的核心价值 |
| 规则扩展 / 插件 | 切片：`Rule` + `RuleRegistry` + 严重度 + 按编号开关；加载外部规则未做 | ESLint、Lighthouse、Flutter lint | 生态门槛 |
| 源码跳转 / 断点 | 未做（明确边界） | Flutter Debugger、Qt Creator | 不作为近期目标 |
| 无障碍检查 | 未做：UIA 有实现，调试面无语义树 | Chrome A11y、Flutter Semantics | 可后置 |

## 目标架构

```text
L3 消费者     CLI 13 出口        Web 视图         AI / CI          IDE（未来）
                    \              |               |               /
L2 投影        Brief / Findings  Inspector     Timeline     Network     Loop     Paint     Memory
                    \              |               |               /
L1 证据        FrameStore   NodeRegistry   GeometryStore   NetworkStore   PropStore   Script   Digest   Evidence
                    \              |               |               /
L0 采集        frame  state-write  rebuild  input  network  geometry  props  pick  paint  memory  async
```

四条不变量沿用[裁决索引](../decisions/README.md)：

- **一份数一个名字**：每个域只有一个写入点、一个查询入口、一份投影；不在消费者里重算。
- **四层不越层**：消费者只读投影，投影只读证据，证据只由采集写。
- **窗口是常态**：任何交给消费者的证据都要逐域自报 from / to / capacity / dropped。
- **诊断器不产出改法**：规则只给地址、度量、阈值、复算方式；“怎么改”归 AI / CI，且必须引用地址。

## 关键设计

### 1. 采集预算：`RecordOptions` 取代“装了全录”

**问题**：改造前只要安装录制器，`AsyncHttp` 就会抓请求/响应体（最多 200 × 64KB），几何也会每帧先序全树并 `new GeometryRecord()` 再比签名。前者与“报文按需记”的文档相反，后者是 O(节点数)/帧，不是文档说的 O(变化量)。因果分析不需要为这两个域付全价。

**设计**：引入只读值对象 `RecordOptions`，由调用方声明要哪些域：

| 域 | 默认 | 说明 |
|---|---|---|
| frames / causes | 开 | 因果主路径，不可关 |
| geometry | `changed` | `changed` 只存变化；`all` 每帧全量；`off` 不采 |
| network skeleton | 开 | 方法 / URL / 状态 / 字节 / 帧号 / 发起者 |
| network bodies | **关** | 显式开启才抓；未开启时 `networkBody` 报 `bodies-not-captured`，不返回空 |
| props | 关 | 查询时按需读，仍走独立窗口 |
| paint / memory / async | 关 | 新域一律默认关，先证明预算 |

`Recorder::install()` / `DebugSession::open()` 收 `RecordOptions`；旧的位置参数由 `fromLegacy()` 一处归一，避免每个夹具各自翻译。`RecordOptions` 随 `Evidence` manifest 导出，读者才能判断“这份证据为什么没有报文”。

**已落地判据**：常驻档不抓正文、不采几何、不记网络骨架；默认档只抓骨架；`networkBody` 的 `bodies-not-captured` 与“抓了但为空”分开；manifest 逐项带出预算，开启 bodies 后导出→导入仍逐字节稳定。几何在 `changed` 档直接用盒字段算签名，未变节点不再造记录；`settle` 与 `paint` 都整段跳过的全静止帧不进几何采样。这里不用 `layoutEpoch` 当唯一跳过信号：滚动偏移是就地推盒，不换代却会改几何。

### 2. 节点生命周期：`NodeRegistry` 要有回收

**问题**：`nodes` / `boxless` / `geometryDigest` 只增不减。长跑 + 动态节点会持续上涨，导出还带着已卸载节点；这与“环形窗口”承诺不一致。

**设计**：节点登记只增加 `lastSeenFrame`；按“仍在帧窗口内被引用”或“超过 N 帧未见”回收元数据。**不引入 `generation`**：会话戳 + id 不复用已经承担代际，再造一个代际号只多一份要对齐的状态。被回收的地址查询返回 `node-evicted`，与 `unknown-node` 分开。

**已落地判据**：节点登记记 `lastSeen`，每 60 帧回收一次长期未见且不被帧 / 几何 / 属性 / 网络引用保住的元数据；id 不复用，回收地址进 `evicted` 证据域，离线查询仍答 `node-evicted`。`nodes` 域只写活动节点，`unknown-node` 与“已回收”分开。

### 3. 开销预算：没有量具就不加域

**问题**：S2 的验收线是“默认开启帧耗时增幅 < 2%”，但仓库没有 recorder on/off 的 benchmark；现在无法证明“可常驻”。

**设计**：加一个固定场景的 benchmark（约 2000 个渲染节点的合成列表树，标签逐帧变，避免判等复用把静止帧量成零成本），同脚本分别测：

- off
- frames-only
- geometry(changed)
- full（含网络骨架与 bodies）

每次新增域必须给出数字，并写进该域的判据。默认常驻只允许 frames-only；full 只允许显式开启。

**已落地判据**：`php tools/recorder-overhead.php --check` 同脚本跑四档、逐帧取中位。分母取 60fps 帧预算（16.67ms）而不是接近零的静止微基准：后者会把固定 2–3µs 放大成几十个百分点，和"能不能常驻"不是同一个问题。frames-only 的实测预算占用约 0.01%；geometry 与 full 的先后落在噪声范围内，不做单调性承诺。数字由量具现跑，不抄进判据当常量。

### 4. S3d 绘制归因：把“慢”拆到节点与层

**问题**：现在只能回答 `paint` 段花了多少，不能回答“这一帧重画了哪些节点、哪些层缓存没命中”。对 GUI 框架，这是性能调试的核心一问。

**设计**：新增 `PaintRecord`（每帧）：

- 本帧重画的节点 id 集合（来自 `markPaintChanged()` 的唯一写点）
- layer cache 命中 / 未命中计数
- 每个未命中层的节点 id 与原因（首次 / 尺寸变 / 内容变 / 变换变）
- 绘制耗时按节点聚合（或按层聚合，粒度需裁决）

查询面加 `paint(frame)`；投影加 `repaint-storm` / `layer-miss` 发现；面板与 Web 加绘制页。**不引入反射**，AOT 可用；绘制记录进 facts 指纹前必须先证明确定性，否则单列 `digest(Paint)`。

**判据**：重画集合 ⊆ 结构树；与 `markPaintChanged()` 调用点对得上；缓存命中率跨帧可复算；AOT 真机可出同一份记录。

**已落地**（与上面的设计有三处出入，都是实现时才看清的）：

- **不引入反射，也不新增 `digest(Paint)`**：绘制事实不参与指纹。它是**预算相关**的观测量（开不开 `paint` 域不改变应用行为），进指纹会让"同一个应用、两份不同的预算"算出不同指纹，而那正是指纹要排除的东西。
- **命中只记次数，不记节点**：命中意味着整棵子树一条 `paint()` 都没跑，逐个记下来会把"省掉的节点"记成"画过的节点"——报告会把收益算成成本。因此 `painted` 里只有真跑过 `paint()` 的盒。
- **绘制耗时不做按节点聚合**：`paint` 段耗时在 `FrameRecord::$stageNs` 里已有一份（整段），按节点再切一次要在 `paint()` 里插钩子，热路径代价与本域"默认关、可常驻"的定位冲突。规则改用"重画节点数 × 段耗时"两个已有数字并排看。

另外补了一个设计里没写的查询 `repaints(frames, limit)`：绘制窗口 60 帧、单帧最多 4096 个节点，让投影层逐帧调 `paint()` 是 60 次查询、24 万个节点号，远端会话还要 60 个往返。聚合在查询层做一次，规则读一张几十行的表。

**落点**：`Core\PaintObserver`（中立接口，`Layout` 不反向依赖 `Debug`）→ `Pipeline` 转接 → `Debug\Session\PaintRecord` / `Recorder`；查询 `paint` / `repaints`；投影 `repaint-storm` / `layer-miss`；消费者是 `debug-brief`（发现 + 明细 + 裁剪账）、`/api/paint` `/api/repaints` 与 Web 节点卡的"本帧绘制"行。

**判据**（`tests/Feature/Debug/PaintTest.php` + `BriefTest`）：重画集合 ⊆ 结构树（每个节点有类型与路径）；集合大小不超过真画次数（命中不是"画过"）；聚合可由逐帧复算；`paint-not-captured` 与"这帧没画"分开；窗口外不 clamp；窗口 60 帧有界且 `from`/`to` 跟着走；证据往返逐值相同；两条规则分别对应"缺边界"与"边界失效"。

### 5. 实时 attach、select / highlight 与多窗口

**问题**：当前只能对 headless / 宿主子进程取证；用户不能在正在跑的真窗口上点一个控件、看它的约束链。多窗口此前直接拒绝（`DebugSession::open()` 见已有录制器就抛）；现在每个窗口一份录制器，互不串证据。

**设计**：

- **live attach（dev-only）**：在帧循环里做非阻塞轮询，或把读记录放到独立线程；AOT 发布态仍不做（没有空闲帧循环，暂停会改事实）。
- **select / highlight**：`pick` 已经是查询面；反向流 `highlight(node)`（`0` = 清除）**已落地**（2026-11-25，A50/A51/A52）—— 它在绘制后叠加，不写结构、不改布局。三处要点：① 注解是**脚本里的一步**（`Step::HIGHLIGHT`），否则"同一份脚本跑两遍"会让"一次高亮了一次没高亮的"拿到同一个驱动指纹；② 叠加层**自带失效判据**（`paintRequested` + `continueFrame` + `setDebugHighlight()` 排帧），否则 `Frame` 会整帧跳过而不显示；③ 判据是**双向**的 —— `Digest::causal()`（只含 `rebuilt` / `causes`）逐帧不变 **且** 像素必变，另加"环画在这个节点的边框上、盒心不是描边色"与"稳态不再重画"。原始表述"`Digest::ofFrame()` 前后逐值相同"会恒红：它含 `skip`，而高亮正是要求这一帧必须画。**仍未做的**：`pick` 接到活窗口点击（要 live attach）、面板 / Web 侧的悬停即高亮（连续流要节流合并，见 A50 的失效条件）。
- **多窗口**（2026-11-25 已落地，A53）：**录制器属于应用**，进程级单例改掉 —— 帧内"当前录制器"由 `Recorder::enter()/leave()` 的栈回答（`null` 也压一层，否则第二个窗口的帧会落到别人的录制器上）。判据是"交错跑 == 单独跑"：两个窗口的帧键、节点表、两份指纹逐一相同。**仍未做**：`Discovery` 的数组条目真正生效、跨窗口的合并视图（那属于投影）。

这三件事都触碰 A33 的旧表述（六个方法、不做 live attach）。实施前先重开 A33，把“dev 形态做 / 发布态不做”和“九个方法按角色分组”写清楚。

**判据**：attach 到运行中的窗口后能 pause / step / query；highlight 不改变**结构因果**指纹（`Digest::causal()`）且像素必变；两个窗口的节点 id、帧号、会话令牌不串。

### 6. 规则注册表、严重度与基线回归

**问题**：12 条发现规则硬编码在 `Brief`；`Finding` 没有 severity；文档说“规则可关”，代码没有开关；没有 baseline，无法回答“这次比上次慢了吗”。

**设计**：

- `Rule` 抽象类（原计划的 `FindingRule` 接口）：`id()` / `ids()` / `version()` / `severity()` / `summary()` / `evaluate(Facts, Thresholds): list<Finding>`；`RuleRegistry` 唯一声明，顺序即输出顺序。
- `Finding` 增加 `severity`（info / warning / error）；`--rule=` 启用、`--without=` 关闭、`--min-severity=` 过滤呈现；未知规则名报错并列出可用编号。
- `--baseline=<文件>`：按同一组规则比较两份发现的**度量**；只有可复现的度量进 CI 退出码，耗时类照列不计。
- `Thresholds` 的全部字段都要有 CLI 入口；当前只有 slow / fanout / repeat 三项。

**已落地**（规则注册表 + 严重度 + 开关）：

- 九个规则类住在 `tools/Debug/View/Rules/`，`Brief` 从 852 行降到约 330 行，只剩编排、覆盖度与兜底。`RuleRegistry::verify()` 在**运行时**校验"规则声明的编号 ↔ `Finding::RULE_VERSIONS`"双向闭合 —— 此前靠单元测试守，测试不跑就不守。
- `--rules` 打印目录（编号 / 严重度 / 版本 / 说明），由注册表生成，不另写一份文档。
- 三处与计划不同的取舍：
  1. **抽象类而非接口**：`ids()` / `version()` / `finding()` 需要默认实现，PHP 接口给不了。`finding()` 让严重度由规则盖章，不靠每条 `new Finding()` 各写一遍 —— 两边不一致时报告看不出来，只有 `--min-severity` 会漏行。
  2. **`--rule` / `--without` 而非 `--rules` / `--disable`**：`--rules` 被"列目录"占用了；`--rule` / `--without` 与 `find --include/--exclude` 的习惯一致，也避免"带 s 的开关做选择"这种容易记反的形状。
  3. **过滤发生在判据之后，并新增 `suppressed` 兜底**：`--min-severity` 只少看几行，判据照跑。发现被过滤光时报告说"N 条被过滤掉了"而不是"没问题" —— 这两者在实现上都表现为"发现列表为空"，结论却相反，是本域最容易写错的一条。
- `Thresholds` 的 CLI 入口仍只有三项，这是**有意不做**：那三项是表头显示、直接影响"这算不算慢"的判断；其余七项改变的是规则内部的敏感度，用 `--rule=` 把范围缩小到一条规则之后，读者要的是"这条规则说了什么"，不是"把它的阈值调到刚好不报"。`with()` 在库层已支持全部十项，证据包也带着它们。
- **未做**：七项阈值的 CLI flag（有意不做，见上）、插件式加载外部规则（需要先定 `Rule` 的跨包契约）。

**已落地**（基线回归，`Baseline` + `BaselineText`）：

- 基线是**精简清单**不是证据：`{format, label, digest, thresholds, selection, excluded, findings:[{rule,version,severity,address,title,metric,value}]}`。可进版本控制、可手改、可按行 review；`format` 不认识就报错，不猜。
- 读写分开：`--save-baseline=<文件>` 写、`--baseline=<文件>` 读、`--assert` 有确定性回归退 3、`--tolerance=<百分比>` 默认 5。不做"文件不存在就写" —— 一次 CI 跑的副作用改掉基线，报告还会说通过。
- **确定性分级**是核心：`Rule::deterministic()` 默认 `true`，`slow-frame` / `stage-dominance` 覆盖为 `false`。两类都算状态、都列行，只有确定性规则计入 `regressions`。本机实测帧耗时抖动 ±7%，拿它当断言等于每天制造一次假红。
- 兜底结论（`clean` / `inconclusive` / `suppressed`）不进基线：它们的 `value` 为 `null`，是结论不是度量；`--save-baseline` 过滤并记 `excluded`，解释"干净应用的基线为什么是空数组"。
- 口径变化（阈值 / 规则选择 / 指纹）只报不平、不改行状态：那三项变了之后 new/fixed 本来就该出现，再叠加"整体不可比"会让读者两个信号都读不懂。
- 同 `(规则, 地址)` 重复要计数带出（`duplicates`），不静默保留第一条 —— 它是"地址不够唯一"的监视器。
- Web 不接基线：观察台看"现在什么样"，基线是 CI 的事；`--json` 带 `baseline` 顶层键给机器消费者。

落地时抓到的两件事：① `ConstraintRewriteRule` 的宽 / 高两条发现原本共用 `frame:N node:M` 地址，基线配对会把它们撞成一条 ⇒ 地址改为带轴（`frame:1 node:5 w`）；② CLI 判据原本断言"耗时类必然出现偏离"，而实测只抖了 2.6%（未超容差）—— 判据依赖外部抖动就是偶发假红，改为断言契约（`~` 行存在 + 自报"不计断言"），并补齐呈现层"耗时类一律列行、一律自报"。

**判据**（`tests/Unit/Debug/BriefTest.php` + `BaselineTest.php` + `tests/Feature/Debug/BaselineCliTest.php`）：注册表声明的编号集合 == 真实产出的集合；每条发现的 severity == 所属规则的 severity；`--rule=` 只跑一条且兜底自报"只跑了 1/9"；`--without` 关掉一个变体不连带关掉兄弟；`--min-severity` 只去掉低级别；过滤光了报 `suppressed` 而不是 `clean`；未知编号抛错并列出可用编号；`--rules` 目录与注册表同源；基线七种状态各一条、容差边界（等于不报、略超才报）、0 → 正数算变差、耗时类不进 `regressions`、重复键计数、坏文件五种各自报错、`--assert` 无基线退出 2、有回归退出 3、无回归退出 0。

### 7. 搜索：产物是**地址**，不是位置

**问题**：证据里有几百上千个节点、几百条请求、十几条发现，而"哪个东西提到了 `rows`"以前只能靠人翻。这不是缺面板，是缺一次能收敛的查询：没有它，"点一个东西看看"这个动作只能从别人给的地址开始。

**设计**：

- `find(pattern, domain, limit, caseSensitive)`：`domain` 取 `all` / `node` / `request`。命中项一律是 `{domain, address, text}`，`address` 是**能被别的查询直接解析**的形式（`node:19` → `boxModel`，`request:3` → `network`）。不返回行号、屏幕位置、片段偏移 —— 那些在证据重新导出后就失效了。
- **只搜证据，不搜活树**：两个域都能从记录回答，所以导出的包、远端会话、离线包搜出来的结果逐值相同。搜活树会让"搜到的节点下次没了"变成常态，而搜索的第一用途恰恰是"照着结果去追问"。
- **匹配自身类型，不匹配路径**：落地时先写成匹配完整路径（`PageShell > Column > Expanded > Table > …`），实测 `Table` 命中 **346/364** 个节点 —— 路径里含祖先类型，等于把整棵子树都算命中，结果列表退化成"打印一切"。改成匹配节点自身类型后是 2 条，路径只用来显示在哪。要列某容器的子树是 `cascade(frame)` 的问题，不是搜索的问题。
- **不做正则、不打相关度**：正则有灾难性回溯与跨实现语义差异，相关度无法复算。`Padding|Text` 按字面找。大小写默认不敏感，`--case-sensitive` 显式打开。
- **三种"没结果"必须分开**：`scanned`（扫了多少候选）/ `matched`（命中总数）/ `returned`（截断后给了多少）三数对账；`evicted`（已回收节点搜不到，不等于没有过）与请求域的 `window` 随答案自报。少了 `scanned`，"0 条结果"会被读成"没有匹配"，而它更可能是"这个域根本没有候选"（没采 / 窗口空）—— 两种情况下一步动作相反。

**判据**（`tests/Feature/Debug/FindTest.php` + `FindCliTest.php` + `WebTest`）：命中集合 == 自身类型匹配的节点集合（独立数一遍节点表交叉核对）；不命中整棵子树；每个地址能被 `boxModel` / `network` 解析；`--limit` 只裁返回且标截断、逐域之和 == 总数；空窗口 `scanned=0` 与"搜了没命中"可分；`Padding|Text` 不命中（换成 `preg_match` 会红）；`/api/find` 缺参数与未知域回 400 而不是空结果。

### 8. 后续域：内存、异步、无障碍

这三个域都重要，但不与 S3d / S2 同期做；先按“预算 + 独立窗口 + 独立查询”的形状评估：

- **内存**：节点 / State 存活数、订阅数、图片缓存、worker 内存；不做堆转储（PHP/AOT 限制），只做可复算的计数与泄漏趋势。
- **异步**：Future / worker job 的时间线、队列深度、结算延迟、取消；网络只是其中一个子域。
- **无障碍**：语义树 + UIA 属性；已有 UIA 实现，缺的是查询面与投影。

每个域进入主线前必须回答：采集点在哪、窗口多大、进不进 digest、AOT 是否可用、消费者是谁。

## 计划列表

规模用 S / M / L 表示，不是工期承诺。每一批的“完成”都包含代码、判据、文档三件事。

| 批次 | 任务 | 交付与判据 | 依赖 | 规模 |
|---|---|---|---|---|
| **P0** | 文档真相校正 | 把 S0 标为已落地；`debug-model` 的方法数改为九个并补 `finish`；Web 轮询描述改为当前实现；阈值“全部可配”改为实际三项；架构图去掉诊断器里的“根因建议 / 基线回归”，把“黑匣子”标为 S2；`ROADMAP` 余项补 S3d。`doccheck` 通过，全文不再出现互相矛盾的现状句 | 无 | S |
| **P0** | 重开 A33 | 把“六个方法”改为按角色分组的九个；把 live attach 条款改为“dev 形态做、发布态不做”；写明失效条件 | 无 | S |
| **P1** | `RecordOptions` 与报文按需 | 已落地：因果-only 不抓 body、不采几何、不记网络骨架；`bodies-not-captured` 可区分；manifest 记录选项；导出往返稳定 | P0 | M |
| **P1** | 几何采样降本 | 已落地：签名直接由盒字段计算，不再每帧 new 记录；全静止帧跳过采样；几何窗口仍 60 帧并自报 | P1 | M |
| **P1** | 节点表回收 | 已落地：`lastSeen` + 周期性回收；`node-evicted` 与 `unknown-node` 分开；回收地址随包走 | P1 | M |
| **P1** | 录制开销量具 | 已落地：2000 节点固定场景；off / frames / geometry / full 四档；frames-only 帧预算 < 2%；`--check` 咬预算 | P1 | M |
| **P2** | S3d 绘制归因 | 已落地：`PaintRecord` + `paint(frame)` / `repaints()` + `repaint-storm` / `layer-miss`；重画集合 ⊆ 结构树、命中不算"画过"；独立 60 帧窗口；证据包带 `paint` 域；简报 / Web / `/api/paint` 有入口；AOT 可用（无反射） | P1 | L |
| **P3** | live attach（dev） | 运行中真窗口可 attach、pause、step、query；AOT 仍拒绝并说明原因 | P0、P1 | L |
| **P3** | select / highlight | `pick` 接到活窗口点击；高亮绘制后叠加；highlight 前后**结构因果**指纹相同且像素必变 | P3 | M |
| **P3** | 多窗口隔离 | 已落地（A53）：录制器绑到应用 + 帧内压栈；交错跑与单独跑逐值相同、第二窗口不推进第一会话的帧号与节点表、无主兜底保住老夹具；`Discovery` 多条目仍待做 | P3 | L |
| **P4** | S2 常驻黑匣子 | AOT 标量环形缓冲；慢帧快照；默认开销 < 2%；崩溃文件可检出截断 | P1、P3 | L |
| **P4** | 崩溃取证 | PHP 可捕获异常 + 可恢复致命错误落盘；C++ 崩溃另立平台期，不混入本批 | P4 | M |
| **P5** | 规则注册表 | 已落地：`Rule` + `RuleRegistry` + `Severity`；`--rule` / `--without` / `--min-severity` / `--rules`；未知规则报错；`suppressed` 兜底；运行时双向闭合；九条规则各一个类，`Brief` 只留编排 | P2 | M |
| **P5** | 基线回归 | 已落地：`--baseline` / `--save-baseline` / `--assert` / `--tolerance`；确定性分级（耗时类不计断言）；坏文件五种各自报错；有回归退 3 | P5 | M |
| **P5** | 证据搜索 | 已落地：`find(pattern, domain, limit, caseSensitive)` + `debug find` 出口 + `/api/find`；产物是地址且可被追问；三数对账；截断 / 空窗口 / 已回收分开自报；不做正则 | P2 | S |
| **P6** | 内存域 | 节点 / State / 订阅 / 图片缓存计数；泄漏趋势；独立窗口与查询 | P4 | L |
| **P6** | 异步域 | Future / worker job 时间线；队列深度；结算延迟；取消 | P4 | L |
| **P6** | 无障碍域 | 语义树 + UIA 属性查询；与现有 UIA 实现共用一份树 | P4 | M |
| **P7** | 产品化文档 | 任务型《调试器使用指南》；从 brief 到定位的 5 分钟路径；架构图与 CLI 手册同源 | P0、P5 | M |
| **P7** | 协议版本与能力发现 | 协议版本、能力列表、错误码表；老客户端遇到未知能力显式报错 | P3 | M |

### 建议顺序的理由

- **P0 先做**：当前文档把已完成的 S0 标成未做，会直接导致下一轮重复劳动；A33 的六方法表述会让新消费者按错的协议写代码。
- **P1 先于 S3d**：绘制域会新增每帧记录；如果采集预算与节点生命周期没先收口，S3d 会把开销问题放大。
- **P3 先于 S2**：S2 的“多窗口隔离”与“常驻”需要 live attach 探出的会话/窗口形状；先做 S2 会返工。
- **P5 可以并行**：规则注册表只依赖 S3d 的投影形状，不依赖 live attach；如果人力允许，与 P3 并行。
- **P6 最后**：内存/异步/无障碍都是新域，必须等预算与回收机制稳定，否则每个域都会自带一套泄漏。

## 验收门

不是“功能列表做完”，而是三扇门：

| 门 | 必须同时满足 | 对应批次 |
|---|---|---|
| **生产可用** | AOT 常驻默认开销 < 2%；崩溃文件可检出截断；多窗口隔离；慢帧可回看 | P1、P3、P4 |
| **调试体验** | 真窗口 attach；点选 + 高亮；因果树 / 时间线 / 绘制页可在同一会话里联动 | P2、P3 |
| **生态** | 规则可注册 / 可关 / 有严重度；基线回归进 CI；协议有能力发现；使用指南可独立走通 | P5、P7 |

三扇门都过，才谈“成熟 DevTools”。只过第一扇，是“可用的生产诊断器”；只过第二扇，是“好用的开发检查器”；三扇都过，才是别人愿意长期依赖的工具。

## 不做

- **完整 PHP 断点调试器**：与证据模型是两条产品线；没有真实需求前不做。
- **像素级视觉回归**：归 golden 基线，不混进符号证据。
- **实时属性编辑**：控件不可变，改了就与运行时不是同一份证据。
- **远程多用户 / TLS 调试面**：当前只绑 loopback；要开远程另立安全设计。
- **GPU 驱动级 / 系统级采样**：S3d 只做框架绘制与 layer，不做 Perfetto 级系统追踪。
- **替代 Sentry / APM**：崩溃取证只服务本框架的现场，不做通用告警平台。

## 待裁决

以下事项会改变现行裁决或兼容面，实施前需要单独拍板：

1. **A36 的“规则可关”**：当前只有阈值可配，没有规则开关；是补开关，还是把“可关”改成“可配阈值”。
2. **绘制域的指纹**：`PaintRecord` 进 facts 指纹，还是单列 `digest(Paint)`；取决于 layer cache 是否跨机器确定。

A33（九方法 / dev attach）、S0 状态与报文默认关闭已分别在 P0 / P1 收口，多窗口模型（每个窗口一份录制器）已在 A53 定下，都不再列为待裁决。

## 相关

- 一帧怎么记、因果方向与边界：[调试模型](debug-model.md)
- 证据的形态、投影与消费者：[证据模型](evidence-model.md)
- 现行裁决与失效条件：[裁决索引](../decisions/README.md)
- 命令入口与参数：[命令速查](../reference/cli.md)
- 验证分层与真机纪律：[测试与验证](../guides/testing.md)
- 对外兼容承诺与 1.0 门槛：[路线图](../../ROADMAP.md)
