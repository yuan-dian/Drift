# 后续推进计划 · 2026-11-08

> 基准：Drift `d2fc77a`（§71）；输入为本轮三份材料——
> `docs/REVIEW-drift3-blueprint21-render-arch-2026-11-08.md`、
> `docs/ANALYSIS-watermark-async-ui-components-2026-11-08.md`、
> `docs/REVIEW-drift3-blueprint13-async-resource-2026-11-08.md`，
> 外加 `docs/DEBT-2026-11-02.md` §66.6 / §68.7 / §69.4 / §70.5 / §71.5 的遗留清单。
> **本棒只出计划**：未改产品代码、未跑真机、未提交。棒号按当前 HEAD 顺延（§72 起）。
> 纪律来源：`AGENTS.md`、`MEMORY.md`（变异四律 / T0-T1-T2 / 判据三态）、`DEBT §40.1`（核心能力三条判据）。

---

## 0. 结论与排序总表

**一句话**：接下来该做的不是「补异步 UI 组件」，而是把已经交付但**判据为假绿或为零**的地方修真，再处理一条本轮新查出的、可静态证明的超时不一致；框架层高风险改动（B4）单独排在最后。

| 棒 | 主题 | 核心能力三条判据 | 五维综合 | 成本 / T2 | 结论 |
|---|---|---|---|---|---|
| §72 | 收尾未提交文档 + 本计划入仓 | ③（引用会漂） | 维护性 +++ | 近零 / 无 | **先做** |
| §73 | lint 两条真红线：`readonly` 白名单假绿 + 无尺寸解码门禁 | ①②③ 全中 | 结构 ++ / 实现 ++ / DX ++ | 低 / 无 | **P1** |
| §74 | 超时口径：30s Job 上限 vs 300s 上传 | ①②③ 全中 | 实现 ++ / DX ++ | 中 / 视改法 | **P1，本轮升级** |
| §75 | watermark 下载段收成单个 `AsyncValue` | ①② | DX +++ / 扩展性 ++ | 中 / 无 | **P2** |
| §76 | 剩余隐性规则 lint 化（先筛能做变异的 2 条） | ①② | DX ++ / 维护性 ++ | 中 / 无 | **P2，按判死条件筛** |
| §77 | B4 EOF 死亡双判 | ①② | 结构 ++ / 风险最高 | 高 / **必须 T2** | **P3，独立成棒** |
| — | 窄交互 / 缓存淘汰 / 重裁候选项 | 至多① | — | — | **明确排后或不做**（§6） |

排序依据不是「哪个听起来重要」，是 `DEBT §40.1` / `§70.1` 的三条：**①有消费者 ②错了是静默的 ③决定能不能交付**，再叠加成本、T2 风险、是否重开已判死项、以及「有没有能咬红的变异」。

---

## 1. 候选池全景（来源 / 当前状态 / 证据）

### 1.1 未收尾的现场

| 项 | 状态 | 备注 |
|---|---|---|
| `docs/ANALYSIS-watermark-async-ui-components-2026-11-08.md` | untracked | 437 行，评审「组件能不能减负」 |
| `docs/REVIEW-drift3-blueprint13-async-resource-2026-11-08.md` | untracked | 267 行，评审 13 号蓝图 |
| 21 号评审稿 / §71 方案稿 | 已入仓 `d2fc77a` | 已订正、已交叉 DEBT |

### 1.2 DEBT 遗留（逐条核过当前代码，不是照抄清单）

| # | 欠账 | 当前实证状态 |
|---|---|---|
| L1 | `readonly class` 的 `===` 白名单矛盾（§69.4「单独立项」） | `tools/Lint/Rules/SelectPurity.php` 的 `typeProblem()` 对 `readonly` 类直接放行；夹具 `SelectFixture.php` 里 `readonly class UserRow` 是 Good 形态。但 PHP `readonly class` 实例 `===` 仍是身份比较 ⇒ `new readonly` 被放行时每帧重建，**lint 假绿**。全仓 `select` 声明类返回类型仅 4 处（`AsyncValue` / 3 处 `StatusMessage`），影响面小 |
| L2 | `UPLOAD_TIMEOUT_S` vs `requestTerminateMs` 口径冲突（§68.7） | **本轮升级**：`apps/watermark/bin/win32/Bootstrap.php` 用 `new ProcessTaskHost($cmd, $registry)` 只传两参 ⇒ `requestTerminateMs = 30_000`；`Config::UPLOAD_TIMEOUT_S = 300.0`；`sweepTimeouts()` 在 `flush()` 里每帧跑，超 30s 就 `dropWorker(..., true)` 杀进程并结算超时。`UploadBatchJob` 串行传 N 张图 ⇒ **不是「口径待对齐」，是可静态证明的十倍不一致**。`Config.php` 的 `@design-param` 注释自己已写明这一半，只差裁决与判据 |
| L3 | B4 `ProcessTaskHost::reap()` EOF 死亡双判（§68.7） | `reap()` 仍只看 `proc_get_status()['running']`，空闲期有 `REAP_IDLE_INTERVAL_US` 节流；`readReplies()` 遇 `$chunk === ''` 只 `continue`，没标 EOF 死亡。动 `src/App/ProcessTaskHost.php`，牵动所有用该 Host 的应用 |
| L4 | §66.6 第 3 条：另两条 C++ 变异 | 前置不成立：探针无排序 / 滚窗场景，改了也测不出（§70.5 明确记为「变异打空，不造假证据」） |
| L5 | §66.5 第 2 条：`Select()` 合成点击落行中心被抢 | 真机边界，未验 |
| L6 | `MenuBar` Alt + ←→（§50.1） | 反复延后 10+ 棒，要模态状态容器；`MenuBar` 是纯 `StatelessWidget` |
| L7 | 两段式挂起（虚拟化父行滚出视口时 ←） | 新机制，需独立裁决 |
| L8 | 候选 E：property hooks 全量自动 notify | §69.4 判「净收益为负」（16 字段 × 4 行 hook > 省下 29 行 `notifyChanged()`，且多字段写撕裂读） |

### 1.3 两篇新文档带来的候选

| # | 候选 | 状态 |
|---|---|---|
| N1 | 下载段五字段收成一个 `AsyncValue`（分析稿 §7.1） | 方向建议，未做实现前分析、未验证回归 |
| N2 | 剩余 6 条隐性规则 lint 化（分析稿 §7.2） | 8 条里 §68 收了 2 条（进度订阅 `AwaitProgress` / 取消守卫 `CancelGuard`），剩 `catch` 顺序之外的 5–6 条 |
| N3 | 状态广播 / Widget 样板（分析稿 §7.3） | §69 开头未减行数；「应用层 helper」已在 §69 判死 |
| N4 | 无尺寸解码门禁（13 号评审 §5） | 33 条 lint 无一碰 `Image`；`ImageLoader::load($path, 0)` 无诊断；当前所有 `asset()` 调用点都给了 `width()` ⇒ **预防性，非修 bug** |
| N5 | 同名不同义命名登记（13 号评审 §6） | `IoPoller::poll()` vs `WatermarkStore::pollLoop()` vs `InputSource::poll()` vs `pollConstraintDrift()`；`API-SURFACE.md` 只登记了 `IoPoller::watch/unwatch` |
| N6 | `ImageLoader` 缓存淘汰（13 号评审 C 组） | 诊断对（`private static array` 只有 `clearCache()` 全清、无上限；`PREVIEW_WIDTH=560` ⇒ `maxWidth=1120` ≈ 6.7MB/张），药方（挂组合根）在本仓无落点 |

### 1.4 明确不重开的判死项（避免计划里混进来）

`Image::url()` / `NetworkImage` / Audio / Video 组件、重开 §18 可继承作用域、自动依赖收集、`Cell` / `Computed` / `Bound`、`Subject` 框架级自动广播、Store 每字段 Cell 化、拆 N 个独立资源组件、加静态全局 `TaskHost`；以及 21 号评审判死的单树 / 增量布局 / 清位式 DirtySet / 重造 `DrawOp`。

---

## 2. 评估框架：五维 + 三条判据 + 四条否决线

### 2.1 五维定义（本轮口径）

| 维度 | 问的问题 |
|---|---|
| **架构** | 是否守住 `Core < Widget < App`、§18「业务数据从组合点注入」、单一记账 / 单一事实源？会不会引入第二套异步簿记或第二套状态通道？ |
| **实现** | 改动面落在 `apps/` / `tools/` 还是 `src/`？有没有能咬红的变异？是否触碰 AOT 已知雷区（覆盖签名、`?Obj`、`mixed` 擦除、闭包参数个数）？ |
| **扩展性** | 是「给当前业务打补丁」还是「给第二类消费者留出入口」？会不会把单点抽象过早固化？ |
| **开发者体验** | 减的是「代码量」还是「心智负担」？漏写时是**当场报错**还是**运行期静默走偏**？新规则会不会变成又一条要人记的隐性契约？ |
| **维护性** | 判据与实现是否同源？文档 / 注释 / 清单会不会随下一棒腐烂？有没有三态 SKIP / 诚实边界？ |

### 2.2 三条核心判据（`DEBT §40.1` / `§70.1`）

```text
① 有消费者（不是"将来可能有人用"）
② 错了是静默的（行为测试全绿、界面悄悄走偏）
③ 决定能不能交付（对外承诺的一半）
```

### 2.3 四条否决线（任一命中 ⇒ 不进本轮）

```text
V1 重开已判死项且拿不出新证据（§23.2 / §18 / Cell / 增量布局）
V2 找不到"能让它报红"的变异 ⇒ 恒绿空壳判据（MEMORY 变异四律）
V3 与实现同源（C++ 加计数器、读 oldSelect、判据复用同一个写点而不自证）
V4 无消费者就发明框架级原语或签名（DEBT §26.2 / §55.6）
```

### 2.4 成熟 GUI 框架给出的边界（本轮已核实，作为计划的排坑依据）

| 框架机制 | 本仓借到什么 | 明确不借什么 |
|---|---|---|
| Flutter `ResizeImageKey` / `ImageCache` 三态 / `ScrollAwareImageProvider` | `targetWidth` 进缓存键 = 本仓 `resolved@maxWidth` 的权威旁证；「默认由框架包、不靠业务 opt-in」 | 全局单例缓存（本仓已有但属技术债）、离屏取消（无作用域树） |
| Coil `AsyncImagePainter.State` / SwiftUI `AsyncImagePhase` | 四态 + 占位/失败槽；缓存命中当场 READY、不闪 loading | N 个资源组件管批量进度（会把状态机从 Store 散进 Widget 树） |
| Coil `ImageRequest` 未 `remember` 重组重发 | 稳定 `cacheKey()` 的必要性 | 把「0 = 原尺寸」定为受禁默认（本仓是合法布局语义，`ImageTest` 钉着） |
| WPF `AutomationPeer.RaiseAutomationEvent` / FlaUI `EventLibrary` | 事件是第三种可观察量，判据必须走客户端订阅（已落地 §70） | — |
| React Query `useQuery` vs `useMutation` / Riverpod `AsyncNotifier` | **资源加载归组件、命令编排归 ViewModel** ⇒ 直接支撑「不造 NetworkImage」 | 把下载拆成第二个 `AsyncCommand` |
| ExoPlayer / AVPlayer / MediaFoundation | 音视频是播放器状态机，与资源组件不同类 | 为零消费者设计播放器 API |
| Kotlin data class / Compose `derivedStateOf` | 值相等契约（§69 `Equatable`） | 自动依赖收集 |
| MobX / Compose Snapshot 批量写 | 反证「自动 notify 会撕裂读」 | 候选 E property hooks |

### 2.5 本轮方法论教训（写进计划，防止下一次重犯）

- **评审 21 号漏查 `tools/smoke.php` 与 `src/Core/Pipeline.php`**，把已闭环的 `pairChecks` 判成「唯一真缺」⇒ 凡称「唯一 / 真缺」，必须先全仓扫消费者，且列出扫过的文件清单。
- **变异打空**：§71 第一版平方扫描因同序 `m=0` 就 `break` 而全绿；§70 两条 C++ 变异因缺排序 / 滚窗场景测不出 ⇒ 判死条件必须「先证明存在变异能咬红」。
- **活文档腐烂**：Drift3 13 号的「现状栏」被后续轮次推翻、21 号拿 10-23 旧坑表做排除法 ⇒ 引用外部文档前交叉最新 RESULT / 代码。

---

## 3. 逐候选五维打分

| 候选 | 架构 | 实现 | 扩展性 | DX | 维护性 | 否决线 | 综合 |
|---|---|---|---|---|---|---|---|
| §72 文档收尾 | ± 不动结构 | ++ 零风险 | ± | + | **+++** 防引用腐烂 | — | **先做** |
| L1 `readonly` 白名单假绿 | ++ 不动框架 | **++** 变异干净（`UserBox` 改 readonly 必须红） | + 给 `Equatable` 补最后一块 | **++** 漏写当场报 | ++ 夹具自证 | — | **9 / P1** |
| N4 无尺寸解码门禁 | ++ 不动框架 | ++ 零 T2 | + 防未来 | + 预防非修 bug | ++ | V2 需证明 | **7 / P1** |
| L2 超时口径 | ++ 组合根可解 | + 视改法；改 `src/` 才 T2 | ++ 口径统一后所有 Job 受益 | **++** 大图上传能否交付 | ++ 需判据防回归 | — | **9 / P1** |
| N1 单 `AsyncValue` | **++** 守住单一 `AsyncCommand` | ++ 两文件约 100 行、零框架 | + 只服务 watermark | **+++** `fold()` 漏支当场抛 | ++ | V1（若拆第二个命令） | **9 / P2** |
| N2 隐性规则 lint 化 | ++ 不改语义 | + 逐条验变异 | ++ 现成 33 规则通道 | ++ 不改语义减负担 | ++ | **V2 逐条筛** | **7 / P2** |
| N5 命名登记 | ± | ++ 近零 | ± | + | ++ 防腐烂 | — | **5 / 搭车** |
| L3 B4 EOF 双判 | ++ 修框架漏洞 | **−** 动 `src/`、必须 T2、牵动全应用 | ++ 所有后台任务受益 | + | ++ | — | **6 / P3** |
| N6 缓存淘汰 | **−** 无组合根可挂 | − 要自己定上限 / LRU | + | + | + | V4（无消费者） | **排后** |
| L4 两条 C++ 变异 | ++ | **−−** 前置场景缺失 | ± | ± | ++ 已诚实挂账 | **V2 前置未成立** | **不做** |
| L6/L7 窄交互 | − 要新状态容器 | − | ± | + 窄 | ± | V4 | **不做** |
| L8 候选 E | − 撕裂读 | − 净收益为负 | ± | ± | − | V4 | **不做** |
| `Image::url()` 类 | **−−** 撞 §18 | − 两道跨进程税 | − 单点抽象 | ± | − | **V1 + V4** | **不做** |

> 打分是相对排序，不是绝对分；「−」不代表永远不做，只代表本轮不进队列。

---

## 4. 分棒路线图

### §72 · 收尾与计划入仓（P0，近零成本）

**范围**：把两个 untracked 文档 + 本计划提交；DEBT 追加 §72 记录「评审 13 号的结论与 N4/N5 候选登记」；`.workbuddy-ai/memory/2026-11-08.md` 已有战史，无需再动。
**改动面**：仅 `docs/`。
**验证**：`docref` ≥135 ok / 0 FAIL、`gate --fast` 15/15（只改文档，不必全量 gate）。
**退出条件**：`git status --short` 为空。
**诚实边界**：这一步不产生任何能力，只防「后续棒引用一份不在仓库里的分析」。
**顺带搭车 N5**：在 `IoPoller` 类 docblock 已有的「三者分工」段补一句区分「IO 就绪 / 业务周期 / 输入出队」，成本近零；不进 `API-SURFACE.md`（`poll()` 本就不是 `@api`）。

---

### §73 · lint 两条真红线（P1，零 T2）

**为什么先做**：它是 §69「假绿修真」主线没收的尾，三条判据全中——全仓 24 处 `select` 是消费者；`new readonly` 被放行时每帧重建且**无任何报错**；`Equatable` 契约能不能信取决于这条白名单。且变异极干净。

**两件独立判死条件**：

```text
73-A  readonly 白名单假绿（L1）
      改法候选（实现前先裁决）：
        B1 收紧为「readonly 且体内不 new」才放行 —— 复用现有 new 检测，改动最小
        B2 从白名单移除，一律要求 Equatable —— 破坏性，先量影响面
      变异：把 SelectFixture 的 Bad 形态 `UserBox` 改成 `readonly class`
            ⇒ 当前会放行（假绿），改后必须报红；恢复必须绿。
      另补 Good 形态：「返回同一 readonly 实例」仍不许响（避免矫枉过正）。

73-B  无尺寸解码门禁（N4）
      lint 识别 `Image::make()->asset(...)` 链上缺 `width()` ⇒ warn（非 error，
      因为 `width<=0` 是合法的「按内容定尺寸」语义，ImageTest 钉着）；
      ImageLoader::load($path, 0) 入口补一条 DIAGNOSTIC（现有两条只治
      「找不到 / 解不出」，没有「解了但按原图」）。
      变异：删掉某处 `asset()` 链上的 `width()` ⇒ 必须响；恢复必须绿。
      找不到能咬红的变异 ⇒ 按 V2 不上仓。
```

**改动面**：`tools/Lint/Rules/SelectPurity.php` 或新增一条规则、`tools/Lint/fixtures/`、`tools/selftest.php`、`src/Rendering/ImageLoader.php`（仅加诊断，不改解码语义）。
**验证分层**：T0 `php -l`；T1 `lint.php` / `--selftest` / 单测 / `gate --fast`；**无需 T2**（不动 AOT 形状、不新增类）。
**退出条件**：基线全绿、每条变异真红、`gate` 18/18、DEBT §73 + 方案稿入仓。
**诚实边界**：73-B 是**预防**，当前全仓零违规（showcase 5 处、watermark 2 处 `SIDE=110` / `PREVIEW_WIDTH=560`、测试 2 处都给了宽度）；不能宣称「修了 OOM」。73-A 若选 B2 需先量「全仓 select 返回 readonly 的调用点」并给出迁移路径。

> **2026-11-08 §72/§73 实施订正（保留原文，不静默改写）**：详见
> `docs/DESIGN-SELECT-PURITY-CONTRACT-2026-11-08.md`。
> ① 本节的 B1/B2 只描述了 `typeProblem()` 一条通道；实测 `newTargets()` 也放行
>    `readonly` ⇒ 有**两条**通道，照做会修一半漏一半。根因是 lint 放行条件与
>    `SubjectBuilderState::sameValue()` 的三条正门不对齐 ⇒ 改选 **B2+**：
>    抽 `isStableValue()` 供两通道共用。移除白名单后全仓 1048 文件仍 0 error，
>    §69 已把真实值对象全部迁到 `Equatable` / `IDENTITY_STABLE`，破坏兼容性零成本。
> ② **73-B 按 V2 判死**：`lint.php` 只有 error 计入退出码（warn 无牙）、
>    `width<=0` 是合法布局语义、`asset()` 链跨行且当前零违规 ⇒ 找不到能咬红的
>    真实变异，不上仓；更优替代（结构计数判据）登记为候选。
> ③ **§74 路 A 不成立**：`items` 无最大数量上界，算不出有界超时；更优方向是
>    「无进度看门狗」，但要动 `src/App/ProcessTaskHost.php` 并与 B4 EOF 双判
>    合并一次 T2 ⇒ 顺延为下一棒头号候选。
> 其余排序（§72 先做、§75/§76/§77 顺序）不变。

---

### §74 · 超时口径裁决（P1，本轮从「排后」升级）

**为什么升级**：§70.1 把它排后的理由是「只有标注、无判据，需真机量一批才裁得动」。本轮回查发现**静态链已闭合**：

```text
Bootstrap 只传 2 参  ⇒  requestTerminateMs = 30_000（默认）
Config::UPLOAD_TIMEOUT_S = 300.0  ⇒  单文件 HTTP 允许 300s
UploadBatchJob 串行传 N 张图     ⇒  整个 Job 墙钟远超 30s
flush() 每帧调 sweepTimeouts()   ⇒  30s 到就 dropWorker(..., true)
症状：界面报「后台任务超时」，而 worker 侧 HTTP 还在等（静默走偏）
```

⇒ 三条判据全中，且**不需要先量一批才敢说存在不一致**；真机要量的是「裁决后行为对不对」，不是「有没有这回事」。

**实现前必须先裁决的三条路**（不许直接把 30s 改成 300000ms 把保护废掉）：

| 路 | 做法 | 风险 |
|---|---|---|
| A | 组合根显式传 `requestTerminateMs`，按「批大小 × 单文件上限 + 余量」算 | 批大小在上报前未知；上限过大会让真卡死的任务永不回收 |
| B | 收窄 `UPLOAD_TIMEOUT_S`，让 HTTP 侧与 Job 上限自洽 | 大图 + 慢上行可能真传不完，改变业务能力 |
| C | 给单条 Job 一个「声明式上限」口（Job 自己说我要多久） | 动 `src/App/ProcessTaskHost.php` ⇒ **必须 T2**，且与 §77 撞同一个文件 |

⇒ **推荐 A 或 B，先做实现前分析**；若走 C，与 §77 合并成一棒（同文件、同 T2），不要分两次重建 AOT。

> **2026-11-08 实施订正（保留原文，不静默改写）**：详见
> `docs/DESIGN-JOB-TIMEOUT-2026-11-08.md` 与 `DEBT` §74。
> ① §74 已落地，但采纳的是**第四条路**：`TimedJob` 任务自声明上限。
>    三条原路都站不住 —— A 被反证（`WatermarkStore::$items` 无数量上界，算不出有界值）、
>    B 削业务能力、「无进度看门狗」因 `JobProgress` 只有 1% **值**节流、而 worker 阻塞在
>    `run()` 里发不出独立心跳 ⇒ 慢速大文件 30s 可能零包，会误杀它本来要救的任务。
>    新方案零破坏（名字 / getter 不变）、已过 T2。
> ② **§77 按 V2 判死**：`build/probe-eof-timing.php` 6 轮实测，管道 EOF 与
>    `proc_get_status()['running']=false` 在同一轮询内到达（差 0.01–0.02ms），
>    而 `readReplies()` 与 `reap()` 又同在一次 `drain()` 里 ⇒ 找不到能让「只判 `running`」
>    变红的真实场景。只订正了两处「EOF 已被当场发现」的腐烂注释，不实现双判；
>    §4 路线图里 §77 一行作废。
> ③ 下一棒顺序不变：§75（单个 `AsyncValue`）→ §76（隐性规则 lint 化，逐条 V2 筛）。

**判据（必须独立于实现）**：写死期望值 + 量具断言，不许从常量推导期望（§68 踩过「把 `POLL_MAX_ROUNDS` 改 99999 判据跟着放大仍全绿」的坑）。变异：把 `requestTerminateMs` 改回 30_000 必须红；把 `UPLOAD_TIMEOUT_S` 改小到与 Job 上限一致应绿。
**验证分层**：只改 `apps/watermark` ⇒ T0 + T1；若动 `ProcessTaskHost` ⇒ **T2**（`drift-build watermark --full` + `probe-window.py watermark`）。
**退出条件**：口径写进 `Config.php` 的 `@design-param`（把「待对齐」改成已裁决）、判据进 gate、DEBT §74。
**诚实边界**：本轮只做了静态链核对，**未跑真机上传**，不能宣称「用户实际遇到了超时」。

---

### §75 · watermark 下载段收成单个 `AsyncValue`（P2）

**为什么排 P2**：它是用户「心智负担」问题的最便宜答案，但属于**业务层重构**，不改变框架能否交付；且 §73 / §74 是「判据为假绿 / 为零」，比「代码写得多」更靠前。

**范围**（分析稿 §7.1）：把 `localPaths` / `failedDownloads` / `downloadFraction` / `downloadMessage` / `isDownloading` 五个字段收成一个不可变值，`ResultCard::imagesSection()` 的手写 `if(local)` / `if(failed)` / `Spinner` / `Progress` 分支走 `AsyncValue::fold()` 四态。约 100 行、两文件、零框架改动、零 T2。
**硬约束（写死，否则重开旧裁决）**：`WatermarkStore` 用**一个** `AsyncCommand` 包整条 Flow，是 `Phase` 六态刻意收敛的结果 ⇒ **不能把下载拆成第二个 `AsyncCommand`**；`AsyncValue` 只当**值**用，进度仍走 `await()` 第二参。§69 的 `select-purity` 已给 `AsyncValue` 登记 `IDENTITY_STABLE`，`SubjectBuilder::select` 门控 lint 上通。
**判据**：复用 `tests/Unit/Async/AsyncValueTest.php` 那一层；新增外层可观察判据（量四态切换时 UI 分支是否齐全），**不与 `fold()` 实现同源**。变异：删掉 `fold()` 的某一支必须红。
**验证分层**：T0 + T1 + 全量 gate；**无需 T2**（只动 `apps/`）。
**诚实边界**：减的是 A 类「漏写一支就界面空白」，**不减行数大头**——分析稿 §1 量过，Widgets 1127 行 / Store 1053 行里静态控件树与 getter 样板才是主体（F/B 类）。

---

### §76 · 剩余隐性规则 lint 化（P2，按 V2 逐条筛）

**范围**：分析稿 §1 A 类 8 条里，§68 已收「进度是 `await()` 第二参」与「`catch (AsyncCancelled)` 顺序 / 取消守卫」两条，剩 `闭包恰好 1 参` / `time()*1000` / `sleep()` 返回 false / `duplicated()` 只认 `===` / `localPaths` 键序 / 三条消息无类型保证。
**做法**：逐条按「1 规则类 + 2 夹具 + 1 组 selftest 断言」走现成 33 条 lint 通道。**先筛再写**：每条先回答「能构造出怎样的变异让它报红」，答不出的直接不上仓（V2），不做「看起来该有」的规则。
**建议只做 2 条最有把握的**：`闭包恰好 1 参`（AOT 形状约束，有 `WatermarkStore` 具体行号）与 `duplicated()` 只认 `===`（有明确失败形态）；其余留在候选池。
**验证分层**：T0 + T1 + `--selftest`；无 T2。
**诚实边界**：lint 化是「不改语义减负担」，但**每加一条规则就多一条要人记的东西**；若某条规则的豁免比命中多，按本仓纪律撤掉而不是加白名单。

---

### §77 · B4 EOF 死亡双判（P3，必须 T2，独立成棒）

**为什么最后**：它是唯一动 `src/App/ProcessTaskHost.php` 的改动，牵动所有用该 Host 的应用（showcase / users / watermark / smoke / 单测），且 AOT 下 `proc_get_status` 可能误报——收益真、风险也最高。评估稿 §3.4 明确「必须独立成棒，不能混在业务改造里顺手改」。**排在 §74 之后**还有一个实际理由：若 §74 选路 C，两者同文件同 T2，应合并一次重建。

**范围**：`reap()` 从「只看 `proc_get_status()['running']`」改成 `running ∪ stdout EOF` 双判；`readReplies()` 遇 `$chunk === ''` 标 EOF 死亡；顺带把「为什么用 JSON Lines 而非长度帧」写进注释（本仓行协议更抗 stdout 污染，防以后照 Drift3 改）。
**明确不搬**：Drift3 的 4 字节长度帧 + 逐字节滑窗重同步；「只结算死者名下在途、不重试」本仓已有（`$item['worker'] !== $index` 过滤）。
**判据 / 变异**：构造「worker 提前退出但 `proc_get_status` 仍返回 true」的场景，当前必须全绿（假绿）、改后必须红；恢复后绿。
**验证分层**：**T2 强制**——`drift-build watermark --full`（约 310s）+ `probe-window.py watermark` 5 判据 0 FAIL；全量 gate 18/18；`tests/Unit/App/` 的 ProcessTaskHost 相关用例全过。
**退出条件**：DEBT §77 记录真机结果与变异逐条结果。

---

## 5. 一页执行清单（给下一棒的开工顺序）

```text
0. 先对代码，别照抄本计划 —— 本计划基于 2026-11-08 HEAD d2fc77a，
   每棒开工前重核一次相关文件（§66.6 第 1 条就吃过"照抄清单"的亏）。
1. §72 文档收尾 + N5 命名登记             → git status 空
2. §73 lint 两条真红线（先出方案稿）      → 基线绿 / 每条变异真红 / gate 18
3. §74 超时口径裁决（先出方案稿）          → 三选一裁决 + 量具断言 + 视改法定 T2
4. §75 单个 AsyncValue（先出方案稿）       → fold 漏支变异真红
5. §76 隐性规则 lint 化（逐条 V2 筛）      → 只做能咬红的 2 条
6. §77 B4 EOF 双判（独立 T2）             → 与 §74 选路 C 时合并
每棒统一收尾：gate 18/18 + --fast 15/15 + docref + DEBT 章节 + 战史 + 提交一项。
```

**每棒共同的判死前置**（写进各自方案稿，不在此重复论证）：
① 基线必须先绿；② 变异必须先 `php -l`、串行改同一文件、`finally` 恢复；③ 判据不与实现同源；④ 改了没红先怀疑**变异打空**，不造假证据；⑤ 新增框架类 / 改 `src/` / 碰壳层 ⇒ T2；⑥ `docs/API-SURFACE.md` 由 `api-doc-check.php --write` 刷新，不手改。

---

## 6. 明确不做 / 排后（本轮判死，附重裁触发条件）

| 项 | 判据 | 重裁触发条件 |
|---|---|---|
| `Image::url()` / NetworkImage / Audio / Video 组件 | §23.2 四条堵点 + 两道跨进程税；单点抽象（V4） | 分析稿 §8 六条前置；**新增路径 B**：框架内资源层 + 组合根 + 帧泵回注，且 Drift3 出现真实 Image 控件、过 `tpc --dry` / AOT、跨进程字节有真机判据 |
| 重开 §18 可继承作用域 / 自动依赖收集 / `Cell` / `Computed` / `Bound` / `Subject` 自动广播 | 历史判死；MobX / Compose Snapshot 反证自动 notify 撕裂读 | 出现「为什么这次值得」的新证据，且不与 `Phase` 六态收敛冲突 |
| 拆 N 个独立资源组件 / 第二个 `AsyncCommand` | React Query `useQuery` vs `useMutation`；`Phase` 五个 `isRunning()` 互不排斥的旧坑 | 出现批量资源**彼此独立、无总进度、无统一取消**的业务形状 |
| 单树 / 增量布局 / 清位式 DirtySet / 重造 DrawOp | 21 号评审 + `DESIGN-INCREMENTAL-LAYOUT-VERDICT-2026-11-04.md`「不立项」（12,003 节点三条触发条件均未成立、AOT 余量 4.8×） | 渲染五阶段 / 资源加载器落地、六机制全闭合、全量 `tpc --dry` PASS、真机 jank 定标、Drift3 出现真实应用 |
| L4 §66 另两条 C++ 变异 | 探针无排序 / 滚窗场景 ⇒ V2 变异打空 | `tools/probe-uia-state.py` 补排序或滚动场景后，sender 名字判据才第一次有消费者 |
| L6 `MenuBar` Alt / L7 两段式挂起 | 窄、要模态状态容器 / 新机制（V4） | 出现真实键盘用户场景 + 容器裁决 |
| L8 候选 E property hooks / N3 状态广播样板 | 净收益为负 / 应用层 helper 已判死 | **出现第二个同类 Store** 且解决批量写撕裂读 |
| N6 `ImageLoader` 缓存淘汰 | 诊断对、药方（挂组合根）本仓无落点（V4） | 与 `Image::url()` 的内存前置一起重裁：先有字节上限 / LRU 判据 |
| 并发假设「单 worker = 1 / 一个 Job 包整批」 | 评估稿 §9.5 六条重裁条件 | 改这两个假设前必须回来重裁，别照抄今天的「零消费者」结论 |

---

## 7. 诚实边界

- 本文件是**计划，不是实施记录**：未改产品代码、未跑 gate / 真机 / 变异、未提交。所有「预计成本 / 是否需 T2」是基于当前代码的判断，每棒开工前必须重新核对。
- §74 的「十倍不一致」是**静态链核对**（构造参数 → 默认值 → `flush()` 调用 `sweepTimeouts()` → `UPLOAD_TIMEOUT_S` 用法），未跑真机上传；真机要量的是裁决后行为，不是「有没有这回事」。措辞已刻意不写成「用户实际遇到了超时」。
- §73 的 73-B 是预防性门禁，当前全仓零违规；若找不到能咬红的变异，按 V2 不上仓。
- 五维打分是相对排序工具，不是绝对结论；「−」只表示本轮不进队列。
- 未代 Drift3 拍板；13 号 / 21 号的接口规格是它自己的裁决范围。未复跑 Drift3 的 135 条断言与 `tpc --dry`。
- 棒号 §72–§77 是**规划编号**，实际编号以落地时 DEBT 尾部为准；若中间插入其它工作，顺延即可。
- 与既有文档的关系：本计划不推翻 `DEBT §70.1` 的排序，只**新增两条信息**使其可更新——① 13 号评审带来的 lint 候选；② §74 的静态链证据把超时从「需真机量一批才敢说」提到「口径本身可静态证明」。若后续裁决推翻这两条，应回写本计划而不是静默改写。
