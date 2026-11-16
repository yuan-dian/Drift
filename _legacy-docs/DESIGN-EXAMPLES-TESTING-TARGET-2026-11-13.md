# 先立目标，再定处置：示例应用与判据的分层标准

> 2026-11-13 · §0–§8 是只读推演，§9 起是按裁决落地的实施记录
> （承接 `REVIEW-EXAMPLES-TESTING-2026-11-13.md`）。
> 用户要求：结合成熟 GUI 框架，先按标准推演一个正确的目标，再看当前的该怎么修改 ——
> **删除、迁移、还是重写**；随后裁决「按推荐的执行」，即四层 + G1–G4、R3-a、
> M3 单独一棒、S0 → S3 顺序。

## 0. 结论

1. **正确的目标 = 四层所有权 + 「集合封闭性 / 锚点稳定性」两条硬约束**，
   外加 4 条可证伪的目标判据（G1–G4）。成熟框架的共识不是「demo 不许被引用」，
   而是「**判据集合不能由 demo 隐式决定，真机锚点不能随 demo 演进漂移**」。
2. **当前最大的问题不是「测试太多」或「测试放错目录」，而是两处结构性缺口**：
   `golden --check` 没有反向集合校验（**删一页会静默全绿**）；13 个真机探针靠
   窗口标题 / 硬坐标 / UIA 文案定位（改导航顺序就全失效）。前者是「太松」，
   后者是「太紧」——上一轮只看见了「太紧」。
3. **仓内已经实现过一次正确范式**：`tools/Bench/ShowcasePages.php` 的显式清单 +
   `frame-tax-probe.php` 判据⑤ 双向 `array_diff`；`tools/TestApp.php` 是现成的最小场景宿主。
   ⇒ 主体动作是**把已有范式迁移/推广**，不是发明新架构。
4. **三分类裁决**：**删除 0 项**；**迁移 4 项（主体）**；**重写 3 处小改**。
   明确不重写：3459 条 `check()`、78 个夹具、13 个探针的判据语义、34 张基线像素、
   `tools/fakes/` 反同源资产。

## 1. 资料来源与可信度边界（先说清哪些是空的）

| 来源 | 覆盖 | 可信度 |
|---|---|---|
| `D:\TypePHP\Drift3\docs\research`（18 篇） | 架构与吐槽集，**对「示例/测试所有权」是空白** | 逐词检索 `testWidgets` / `flutter_gallery` / `test_fixes` / `dev/integration_tests` / `composeTestRule` / `MUXControlsTestApp` / `tests/auto` / `lancelot` / `ReactTestRenderer` / `PRESUBMIT` / `content_shell` **全部 0 命中** |
| Flutter `dev/integration_tests/README.md` | 一手（GitHub master，2026-11-11 commit） | 逐字引文见 §2 |
| WinUI `docs/testing/test-code-in-WinUI.md` | 一手（microsoft/microsoft-ui-xaml main） | 逐字引文见 §2 |
| React 仓 `fixtures/` 目录 | 一手（facebook/react main） | 目录清单 |
| Qt `qtbase/tests/auto/README` | 一手（qt/qtbase dev） | 逐字引文 |
| Flutter `test_fixes` / Chromium `PRESUBMIT_test.py` | 上一棒 `4142687` 已核实 | 工具自证范式 |

`research/00-README.md:65-67`、`research/12-横向思路提炼.md:139-148`、
`research/13-nanoui研读.md:127`、`research/14-Raven研读.md:198`（均为上述外部资料）
提供四条原则：纯函数段最大化无头测试、软光栅 golden、
**双档验证（解释器跑逻辑 / AOT 跑真机像素）**、基线跨后端逐字节一致。
这些与下述标准同向，但**没有一条**回答「demo 该不该被测」。
⇒ 本稿的框架事实来自外部一手资料，不拿 research 的空缺硬撑。

## 2. 成熟框架怎么做：四种所有权，不是一个二分

| 层 | 面向 | 变更规则 | 一手证据 |
|---|---|---|---|
| **L0 框架判据** | 框架行为 | 用**最小场景**，判据集合不由 examples 决定 | Flutter `packages/flutter/test` + `matchesGoldenFile`；Qt `tests/auto`（README：「autotests and benchmarks based on Qt Test」，且要求固定桌面环境） |
| **L1 判据场景资产** | 判据输入 | 专门造、显式登记；改它 = 改判据 | React `fixtures/`（`dom` / `ssr` / `fizz` / `packaging` / `devtools` … 全是测试专用 app）；WinUI `MUXControlsTestApp` |
| **L2 真集成 / 真机** | 交付物闭环 | 用专门 test app，或**显式标注**「demo 作为消费者样本」；与快 CI 分离 | Flutter `dev/integration_tests`；WinUI Interaction Tests + 仓外 aggregator |
| **L3 面向人的示例** | 用户 / 文档 | 可自由改；改动不得改变 L0/L1 判据集合 | WinUI Gallery、Qt `examples`、Flutter `examples` |

### 2.1 两个必须记住的反例

**反例 A：WinUI 确实把面向用户的 gallery 纳入了自动化测试，而且理由充分。**
`docs/testing/test-code-in-WinUI.md` 逐字：
> The goal of these sample app tests is to have the apps consume WinUI the same way as a
> customer would and so that we execute similar to the real world scenario that a customer
> would experience.
> This is required because we were regularly hitting bugs that were not caught by the main
> functional suite of tests due to the fact that these tests do not consume WinUI the way a
> customer would.

⇒ **「验证与测试只针对核心框架」在成熟框架里也不是标准答案**：完全不消费真实应用，
会漏掉「客户怎么消费框架」这一整类 bug。这独立支持了上一轮对提案的判死。

但同一份文档立刻给出分工与局限：
> The MUXC tests are all hosted in this test app. This test app can be run as a manual test
> app and is also used by test automation.（`MUXControlsTestApp`，**不是** gallery）
> Test automation code for these apps lives under … `ScenarioAppTests` …
> (Not really the most appropriate location for these tests. But it is where they live for now).
> So these sample app tests are no longer exercising true customer scenarios. But they still
> provide value since the scenario is very close to the real world scenario.

⇒ 三层并存：控件判据用**专门宿主**、场景测试用 gallery 但**显式标注**其局限、
真实客户场景放到**仓外 aggregator repo** 且每个 PR 跑。**注意：该文档没有把
`AutomationId` 写成锚点**（明确写的是 UIA tree / MITA `UIObject` / 隐藏具名 UIElement，
如 `IdleStateEnteredCheckBox`、`EntryPointTextBlock`）——即「**测试宿主自带稳定测试接口**」，
不是「靠人可见文案定位」。

**反例 B：Flutter 的集成测试层刻意与常规 CI 解耦。**
`dev/integration_tests/README.md` 逐字：
> Each suite consists of either a complete Flutter app and a `flutter_driver` specification
> that drives tests from the UI …
> Intended for use with devicelab tests.
> Adding code to this directory will not automatically cause it to be run by any already
> existing ci tooling. This directory is intentinally a "choose your own adventure" piece of tooling.

⇒ L2 用**专门的完整 app**，且**不默认进快闸门**。

## 3. 正确的目标状态（本轮要立的「标准」）

### 3.1 七条原则

- **P1 判据主语唯一**：每条断言能回答「我在测谁」（框架 / 应用业务 / 集成产物）。
- **P2 集合封闭性（最关键）**：判据的场景集合必须是**显式、封闭、双向可校验**的，
   不能靠 `glob(examples/)` 隐式扩张或收缩；多一个、少一个都要红。
- **P3 锚点稳定性**：真机探针不得依赖会随 demo 演进的窗口标题文案、硬编码坐标、
   导航项 UIA 文案；用测试宿主自带的稳定语义 id / 具名 UIElement。
- **P4 变更归因**：改 L3 只影响 L3 与显式标注的 L2 场景测试；**不得改变 L0/L1 的判据集合**。
- **P5 消费者保真度**：L2 允许且应当保留一个真实应用样本（反例 A），但角色与局限要写明。
- **P6 基线归测试资产**：golden 基线、页面清单位于测试模块，不住 examples。
- **P7 每层自带牙**：结构约束配变异自证（Flutter `test_fixes`、Chromium
   `PRESUBMIT_test.py`、本仓 `--selftest` 成对判据）。

### 3.2 四条可证伪目标判据（把「标准」变成能验收的东西）

| id | 目标 | 验收方式（变异体） |
|---|---|---|
| **G1** | examples 自由 | 改 demo 文案 / 导航顺序 / 增删页面 ⇒ `composer check:full` 的**判据集合不变**；允许显式标注的 L2 场景判据报红，不允许判据数量变化或静默通过 |
| **G2** | 集合封闭 | `golden --check --pages` 必须**双向**校验「场景集合 == 基线目录集合」；删一页、多一张孤儿基线、多一个页面都要红 |
| **G3** | 锚点稳定 | 改 demo 导航文案 / 顺序 / 顶条高度 ⇒ 真机探针仍能跑完；找不到目标记 `NotMeasured`（码 2），**不许写成「没变」** |
| **G4** | 删除有代价 | 任何删除/迁移动作，先构造「只有它能抓到」的缺陷变体，证明迁走后仍有层报红；做不到 ⇒ 不许删 |

G2 尤其重要：**当前删页是静默的**，见 §4.2。

## 4. 现状 gap 分析（重核 HEAD `4142687`）

### 4.1 原则对照

| 原则 | 现状 | gap |
|---|---|---|
| P1 主语唯一 | 绝大多数「用应用测框架」；仅 `tests/Feature/Watermark/` 3 个纯业务值对象 + `smoke.php` 少量文案 | 小 |
| **P2 集合封闭** | `golden-raster.php:610` 活 `glob('apps/showcase/src/Pages/*.php')`；`--check` 只遍历 `$scenes` | **大（结构性）** |
| **P3 锚点稳定** | `probe_win.py:128 FindWindowW(title)`；`probe-window.py:67-73 TITLES` 写死 4 个标题；`probe-press.py:50` `25, 90 + 2*36` 硬坐标；`probe-uia-state.py:156` `"树 Tree"` | **大** |
| P4 变更归因 | 无 OWNERS / lint 强制；`BackendBranch.php:26` 有 `apps/ 不在判据内` 的先例 | 中 |
| P5 保真度 | 13 个探针直接起 `showcase.exe`/`hello.exe`/`users.exe`；`smoke.php` 实例化 3 个示例应用 | **已具备，但角色未标注** |
| P6 基线归属 | `golden/` 在仓根（物理已分离 ✓），但输入来源活耦合 | 中 |
| P7 自带牙 | `--selftest` 成对、变异牙检 6 组（上一棒） | 符合，本仓强项 |

### 4.2 三个决定性发现

**发现 A：正确范式已在仓内实现过一次，只是没推广。**
`tools/Bench/ShowcasePages.php:71-99` 是**纯字面量固化清单**（27 页），头注逐字：
> ★ 清单的代价与对价：手写清单会**漂移** —— 新加一页忘了填，它就从分母里静默消失，
> 而且所有判据照绿（页数 27→26 仍 ≥20）。对价是 `frame-tax-probe.php --pages --check`
> 的判据 ⑤：**磁盘上可用的页 == 本清单**，多一个少一个都红。
> ★ 单一数据源：`names()` 由键派生、`make()` 由同一个表取 —— 两者不可能不同步。

而 `tools/frame-tax-probe.php:366-375` 判据⑤ 正是**双向** `array_diff`：
```php
$missing = \array_values(\array_diff($disk['usable'], $listed));  // 磁盘有、清单无
$extra   = \array_values(\array_diff($listed, $disk['usable']));  // 清单有、磁盘无
```
注释还写明「②（上界）③④（比值）**全都照绿** ⇒ 这条判据是那份代价的对价，不是锦上添花」。
⇒ **P2 的标准、实现、变异理由，仓里全有**，缺的只是 `golden-raster.php` 没照做，
且判据⑤ 目前**不进 gate**（gate 的 `tick`/`scale` 都不带 `--pages`）。

**发现 B：`golden --check` 单向 ⇒ 删页静默全绿（真实缺陷，且是假绿）。**
实测：`apps/showcase/src/Pages/*.php` 34 个 ↔ `golden/page/*/` 34 个，当前一一对应。
但 `tools/golden-raster.php:809-814` 只遍历 `$scenes`，仅判「场景有没有基线」
（`no-baseline` 红）；`_build/golden-manifest.json` 是从 `$items` 派生的**呈现层账本**（`:965-981`），
不是集合校验。⇒ **增页/改名会红，删页场景数减一、旧基线变孤儿，gate 全绿。**
这正是 P2 违反 + 「删判据让 gate 更绿」的具象化。`frame-tax` 判据⑤ 能抓，但它不在 gate。

**发现 C：真机探针的三类锚点全随 demo 演进漂移。**
窗口标题（`probe_win.py:128` + `probe-window.py:67-73`）、硬坐标（`90 + n*36` 系列，
`probe-press.py:50` / `probe-first-input.py:39` / `probe-motion.py:48` /
`probe-scrollbar.py:47` / `probe-modal.py:151`）、UIA 文案（`"树 Tree"` / `"表格 Table"` /
`"Button.php"`）。改标题 ⇒ 20s 超时；改导航数量或顶条高度 ⇒ 坐标全失效；改文案 ⇒ UIA 找不到。
**唯一接近标准的是 `tools/probe-real.php`**：`:32` 自述「驱动器不是判据」，
`:121-132` 调 `inspect.php` 按 path 定位 —— 这正是 WinUI「测试宿主自带稳定接口」的同形做法。

### 4.3 「改 apps 会红什么」速查（上一轮盘点的精确化）

| 改动 | 会红 | 是否静默风险 |
|---|---|---|
| 改文案 | `pages` 全量（像素逐字节）、`smoke` 多处、UIA Name 类探针、`docref` file:line 越界 | 否（红得太宽） |
| 改导航数量/顺序 | `smoke:3543` 仅文字命令 <22 才红；坐标类探针全失效；`frame-tax --pages` ⑥ | **是**（探针失效可能被当没测到） |
| 增删/改名页面 | 增/改名 `pages` no-baseline 红；**删页 gate 静默** | **是（发现 B）** |
| 改标题 | `smoke:3332/3532`；全部 `App(..., "Drift 示例台")` 窗超时；`probe-uia.py:624` 默认值 | 否（但代价大） |
| 删整个 app | `smoke` Fatal；`pages` 场景归零可能空过；`genversion` 少 1 份不红 | **是** |

`--fast`（CI PR、`ci.yml:91`）跳过 `feature`/`pages`/`smoke`，⇒ 上述大半只在 nightly 暴露。

## 5. 删除 / 迁移 / 重写：逐项裁决

### 5.0 先钉死一条：删除 0 项判据

上一轮已判死「删掉示例应用相关测试」；本轮从标准再确认一次：
- 78 个夹具是 L1 判据输入（React `fixtures/` 同构），3 个示例应用是 L2 消费者样本
  （WinUI 反例 A 支持），都不属于「示例应用自己的测试」；
- 唯一看起来该删的 `smoke.php:3332/3532` 文案断言，守的是 §4.5
  「应用只写 `buildRoot/title/commands`」这一**框架契约面** ⇒ 该**重写**为结构性判据，不是删；
- `golden/page/` 当前无孤儿基线（34↔34），无数据可清。

⇒ **本轮不删除任何判据、任何测试、任何探针。** 唯一允许「删」的形态是：
某条断言在 G4 变异下咬不红、且被更强判据完全覆盖 —— 需逐条举证，本轮无候选。

### 5.1 迁移（主体，4 项）

| # | 对象 | 动作 | 依据 | 成本 / 分级 |
|---|---|---|---|---|
| **M1** | `golden-raster.php:610` 的活 glob | 场景来源迁到**已有的** `ShowcasePages` 式显式清单（新建 `tools/Golden/PageScenes.php` 或复用 `Bench/ShowcasePages.php`，并让 `names()/make()` 同源），`golden-raster.php` 只读清单 | 发现 A；P2/P6；`ShowcasePages` 头注「AOT 是静态编译，反射构造任意类不通」 | 中 / T0（不动 `src/`） |
| **M2** | 判据⑤ 双向 `array_diff` | 从 `frame-tax-probe.php` **推广**为共享的 `SceneSetCheck`，供 `golden --pages` 与 `--pages --check` 各调一次；并把集合判据接进 `gate` 的 `pages` 项 | 发现 A、B；P2/P7 | 中 / T0 |
| **M3** | `tests/Feature/Watermark/` 3 个纯值对象契约 | 被测代码（`ApiClient::extractTaskId` / `SignInfo` / `TaskResult`）**上移或标为公开契约面**，测试随代码归 L0；**测试语义一行不改** | P1；上一轮 §5-A；三者注释均写明「错了会静默」+ 变异体 | 中 / T0（属语义变更，需单独裁决） |
| **M4** | `apps/users/tests/fixtures/memory_source.php` | **只迁归属说明，不迁路径**：在文件头 / 工具文档写明它是 `drift-preview` 离线数据夹具、非应用自测，且因 AOT 不认 `Stmt_Return` 必须留在 `sources` 扫描范围外 | P4；`apps/users/project.yml:44-46` 的 `ignore` 是整体替换，挪路径要重写 ignore，风险 > 收益 | 小 / T0 |

**M1 的关键取舍**：固化之后，这些页面就是**框架的测试资产**，不再是「demo 的副本」——
需要在文件头写明来源与冻结日期，并放弃「与 showcase 保持同步」的承诺。
这不是损失：`ShowcasePages.php` 头注已经这么做了。成熟框架里 golden 用最小 widget
（Flutter）、性能量具用独立 benchmark 场景，都不与 gallery 同步。

### 5.2 重写（3 处，均为小改，不重写判据表达）

| # | 对象 | 动作 | 停止线 |
|---|---|---|---|
| **R1** | `tools/golden-raster.php --check` | 加**反向扫描**：`golden/page/` 里有基线但不在场景集合 ⇒ 红；配合 M2 形成双向 G2 | 变异体：删 1 个 Page 文件必须红、加 1 个孤儿基线目录必须红；34↔34 基线像素哈希不变 |
| **R2** | `smoke.php:3332/3532` 的 `title() === '字面量'` | 改成结构性判据（`title()` 非空 + 应用未覆写框架生命周期方法），文案不再是判据 | §4.5 契约的变异体（给应用加一个覆写）仍要红 |
| **R3** | 13 个探针的锚点表 | 窗口标题 / 硬坐标 / UIA 文案 → 收敛到一份**测试契约**（稳定语义 id），由应用或测试宿主显式声明，探针只读契约 | G3：改导航文案与顺序后探针仍跑完；找不到目标记 `NotMeasured` 码 2，**不许写「没变」**；**必须 T2** |

**R3 的两种落法（需裁决）**：
- **R3-a（推荐，改动小）**：不新造 app，在 `apps/showcase` 里加一份 `probe-contract`
  声明稳定 id，探针读它。保留 WinUI「gallery 作为消费者样本」的角色，
  但把锚点从「人可见文案/坐标」换成「测试契约」。符合本仓「能不新建类就不新建」。
- **R3-b（更标准但更贵）**：新建 `tools/Smoke/App/ProbeHostApplication` 专门测试宿主，
  把真机探针全部迁过去（WinUI `MUXControlsTestApp` / Flutter `dev/integration_tests` 模式）。
  代价：新增类需 `drift-build --full` 并确认 `.cc` 生成、新 exe、全部 T2 重验；
  且会丢掉 P5「真实消费者」这一层 —— **不建议先做**。

### 5.3 明确不重写（护栏）

- 3459 条 `check()` 的表达（重写 = 变异史作废，`PITFALLS.md:5742`）；
- 78 个夹具的场景定义、13 个探针的**判据语义**、34 张基线的像素值；
- `tools/fakes/{skia,win32}.php` 反同源资产（`abi-check.php:99` 变异牙检）；
- `tools/TestApp.php` 的 DSL —— 它是现成的 L0 最小场景宿主，应当**复用**而非重写。

## 6. 分阶段路线（每阶段都有可证伪出口）

| 阶段 | 内容 | 出口判据 | 分级 |
|---|---|---|---|
| **S0 目标判据落地** | 把 G1–G4 写成 4 条可执行探针（先红后修），尤其 G2 的「删页必须红」 | 4 条探针在当前 HEAD 上按预期报红/报绿；`--selftest` 变异体咬红 | T0，零产品代码 |
| **S1 集合封闭** | M1 + M2 + R1 | 删页红、加孤儿红、34↔34 基线哈希不变、`check:full` 21 项全过 | T0 |
| **S2 契约归位** | R2 + M3 + M4 | §4.5 契约变异仍红；3 个值对象测试的变异体仍红；`docref` 0 FAIL | T0（M3 需裁决） |
| **S3 锚点稳定** | R3-a | 改文案/导航顺序后 13 个探针跑完；`NotMeasured` 码 2 语义不变 | **必须 T2** |
| **S4 可选** | R3-b 专门测试宿主 | 新类 `.cc` 生成、AOT 启动无 Fatal、全套 T2 重验 | T2，高成本 |

S0 是**判据先行**：先把「正确目标」变成会报红的量具，再动实现。这符合本仓
「每条新约束配自证变异」与 V2「答不出怎样的变异能让它报红就不上仓」。

## 7. 五维评估与停止线

| 维度 | 删除（上一轮提案） | 本稿：迁移为主 + 3 处重写 |
|---|---|---|
| 结构 | 破坏 L1/L2，T2 无载体 | 四层不变，仅切断活耦合 |
| 实现 | 失去 AOT/真机唯一入口 | 零行为改动；S1 反而补上一个静默盲区 |
| 扩展性 | 新平台失去现成探针模板 | 测试契约可复制到 Android / Headless 后端 |
| DX | 短期 gate 更快，长期改框架不敢验 | 改 demo 不再撞无谓红；改判据有集合校验兜底 |
| 维护性 | 3459 条变异史作废 + 假绿 | 变异史保留；复用仓内已有范式，新增面小 |

**总停止线**（任一不满足即回滚该阶段）：G1–G4 探针未按预期咬红；
`golden` 基线哈希变化；`composer check:full` 21 项不全过；`docref` 有 FAIL；
S3 真机 stdout 出现 `Warning`；T2 探针出现「没测到写成没变」。

## 8. 待裁决与订正

**待用户裁决**：
1. 是否认可「四层所有权 + G1–G4」作为目标（**这是本轮唯一需要先定的东西**）；
2. R3 选 R3-a（推荐）还是 R3-b；
3. M3 是否算语义变更、要不要单独一棒；
4. 是否按 S0 → S1 → S2 → S3 顺序开工，或只做 S0+S1 先止住「删页静默」。

**对上一轮评估稿的订正**（`REVIEW-EXAMPLES-TESTING-2026-11-13.md` §3.2）：
原句把「`golden-raster.php:610` 活 glob」与「`aot-equivalence.php` /
`dual-mode-equivalence.php` 的 27 页分母」并列表述，容易被读成后者也是活引用。
**实际后者是 `tools/Bench/ShowcasePages.php:71-99` 的固化清单**，且 `frame-tax-probe.php`
判据⑤ 已做双向比对（只是不进 gate）。上一轮「删掉真应用验证会失去 T2 入口」的结论不变，
但「27 页是活引用」这一具体描述应作废。

**§0–§8 成稿时未改任何代码**：当时工作区仅新增本稿与上一轮评估稿两份未提交文档；
两份稿连同本稿 §9 一起入仓。评估稿 §3.2 的「27 页是活引用」已就地加勘误条。

---

## 9. 实施记录 · S0 判据先行（2026-11-13 第一棒）

### 9.1 范围裁决：S0 不把 G1–G4 全做成探针

§6 把 S0 写成「4 条可执行探针（先红后修）」，但按字面落地会自相矛盾：

| 目标 | 能否在 S0（T0、零产品代码）自动化 | 兑现位置 |
|---|---|---|
| G1 examples 自由 | 只能兑现「集合不由 demo 隐式决定」这一半；「改文案/导航顺序不撞红」要靠 R2 / R3 | S0（冻结清单）+ S2 / S3 |
| **G2 集合封闭** | **能，且决定 S1 是否正确** | **S0** |
| G3 锚点稳定 | 不能：§6 自己标了「必须 T2」 | S3 |
| G4 删除有代价 | 是流程纪律，不是常驻判据；只能由本棒变异牙检举证 | S0 一次性脚本 + 每棒停止线 |

⇒ S0 收窄为 **G2 的量具 + 自证 + 端到端变异举证**，并把 G4 的举证做实。
这不是降低目标：§4.2 的「删页静默全绿」是唯一已被实测的结构性假绿，
先把它钉死，S1 的迁移才有验收线。

### 9.2 落地

| 文件 | 职责 |
|---|---|
| `tools/Golden/PageScenes.php` | 34 页**冻结清单**（L1 判据资产）。只存名字，类与构造器接线仍住 `golden-raster.php`；入仓后改 `apps/showcase` 不再改变判据集合 |
| `tools/Support/SceneSet.php` | `SceneSet::compare()` 纯函数，双向 `array_diff`（`missing` / `orphan`），零文件系统依赖 |
| `tools/scene-set-check.php` | 三向对账：冻结清单 ↔ `apps/showcase/src/Pages/*.php` ↔ `golden/page/*/`。含空集合哨兵、清单重复/乱序哨兵；`--selftest` 14 条合成变异、`--json` |
| `tools/Support/CheckRegistry.php` | `groups()` 增加 `sceneset` / `scenesetself` 一对；顶层项数不变 |
| `docs/CONTRIBUTING.md` | 「5 对」改成不带数字（组内条数以执行器输出为准） |
| `_build/mutate-s0.php` | 一次性变异牙检，**不入库** |

**为什么不新增顶层 gate 项**：上一棒 `4142687` 已裁决「成对静态对账收成 `tooling` 组」，
`group-run.php` 的磁盘自证会自动反查有没有漏登记这一对；且 `tooling` 是 `slow=false`
（§6 的 M2 原想把集合判据接进 `pages`，而 `pages` 在 `--fast` 被跳过 ⇒ PR 上删页仍静默）。
收进组里既守住同一份清单，又让 PR 闸门立刻有牙。

### 9.3 变异牙检（`_build/mutate-s0.php`，11 条全过）

| 变异 | 期望 | 实测 |
|---|---|---|
| M1 删 `AlignPage.php` | `scene-set-check` 报 `disk:missing` | 红 ✓ |
| M1 同状态跑 `golden --check --pages --json` | **静默全绿**（缺口实证） | exit 0 ✓ |
| M2 造 `golden/page/GhostPage/` 孤儿基线 | 报 `baseline:orphan` | 红 ✓ |
| M2 同状态跑 `golden --check --pages --json` | **静默全绿**（缺口实证） | exit 0 ✓ |
| M3 从冻结清单删一项 | 同时报 `disk:orphan` / `baseline:orphan` | 红 ✓ |
| M4 加一个未登记页文件 | 报 `disk:orphan` | 红 ✓ |
| M5 把 `SceneSet::compare()` 的 `orphan` 计算摘掉 | `--selftest` 必须红 | 红 ✓ |
| M5 对照：同状态正常对账 | 实盘无孤儿 ⇒ 绿 | 绿 ✓ |
| 还原后基线 ×2 | 全绿 | 绿 ✓ |

**G4 的举证**：M1 / M2 这两个「只有新判据能抓到」的缺陷变体，在真实
`golden --check --pages` 下都 exit 0；去掉新判据，删页与孤儿基线就没有任何层报红。
M5 + 对照证明**自证是聚合层的唯一守门人**（摘掉 orphan 后实盘仍绿，只有自证会红）。

### 9.4 与三分类方案的关系（S1 范围随之调整）

- **M1 仍需做，但动机变了**：G2 已由 `scene-set-check` 兑现，M1 现在只为
  **G1 的另一半**——让 `golden-raster.php:610` 的活 `glob` 不再决定判据集合，
  改成读 `PageScenes::names()`，缺类/缺接线硬失败而不是 `continue` 静默跳过。
- **M2 已提前落地**：共享双向比对以 `SceneSet::compare()` 的形式存在并进了 gate。
  `frame-tax-probe.php` 判据⑤ 可在 S1 改为调它（判据语义一行不改）。
- **R1 降级为纵深防御**：`golden --check` 自身的反向扫描仍要做，但不再是
  「唯一能发现删页的地方」；删掉它不会让 gate 失去这层能力，符合 G4。
- **不做**：不重写 3459 条 `check()`、78 个夹具、13 个探针判据语义、34 张基线像素、
  `tools/fakes/` 反同源资产（§5.3 护栏不变）。

### 9.5 停止线核对

| 项 | 结果 |
|---|---|
| 冻结清单 / 磁盘 / 基线 | 34 / 34 / 34，三向封闭 |
| `tooling` 组 | 12 项全过（5 对原有 + `sceneset` 一对），组自证通过 |
| 顶层判据清单 | 21 项不变；`composer checks` / `gate --list` 一致 |
| `docref` | 123 ok / 0 FAIL |
| 改动文件行尾 | 全部 LF（`CR=0`） |
| 基线像素 | 未刷任何一张；M1 / M2 的 golden 运行只写 `_build/golden-manifest.json` |

### 9.6 遗留

- `PageScenes`（34 页全集）与 `Bench/ShowcasePages.php`（27 页子集）是两份手写清单，
  **尚未做包含关系对价**；S1 应补一条 `ShowcasePages::names() ⊆ PageScenes::names()`，
  否则两份清单可以同时漂而不红。
- G3 的探针锚点、R2 的文案断言、M3 的值对象契约、M4 的夹具归属说明均按
  S2 / S3 顺序推进；本棒一个都未动。
- 上一棒 `DESIGN-TOOLING-GROUP-2026-11-12.md` 里的「组内 10 条」是成稿时口径，
  现为 12 条；该稿是实施记录，不改历史数字。

---

## 10. 实施记录 · S1 集合封闭（2026-11-13 第二棒）

### 10.1 范围

按 §9.4 调整后的落点，S1 做三件事，**不动任何 `src/`、不动判据语义、不刷基线像素**：

| 编号 | 改动 | 兑现 |
|---|---|---|
| M1 | `tools/golden-raster.php` 的场景发现由活 `glob('apps/showcase/src/Pages/*.php')` 改读 `PageScenes::names()`；清单里的页找不到就 `exit(2)` | G1 的一半：判据集合不再由 demo 文件系统隐式决定 |
| M2 | `tools/frame-tax-probe.php` 判据⑤ 的两行 `array_diff` 换成 `SceneSet::compare()`；判据消息一字未改 | 去掉第二份实现，`tooling` 组的集合判据与 `--pages` 量具同源 |
| R1 | `tools/golden-raster.php --check` 加孤儿基线反向扫描（`--canvas` 不适用，跳过） | 降级后的纵深防御：`golden` 自己也看得见删页 / 改名遗留 |

**额外补的一条对价**：§9.6 遗留的第一条在本棒兑现。仓里有两份手写清单 ——
`PageScenes`（34 页）与 `Bench/ShowcasePages.php`（27 页 AOT 子集）。只校验各自与磁盘相等，
两份仍能同时漂。`tools/scene-set-check.php` 因此加第四向校验
`ShowcasePages::names() ⊆ PageScenes::names()`，空清单也算红；`--selftest` 补 3 条合成变异。

### 10.2 零依赖守卫的契约变化

`scene-set-check.php` 是「不装 PHPUnit 也必须能跑」的静态对账。S1 让它直接 require
`Bench/ShowcasePages.php` 读 `names()`，这是**新约束**：那份清单必须在没有 autoload 时也能被加载。
`use` 语句与 `static fn(): Widget => new XxxPage()` 都是延迟的，当前成立；一旦有人改成加载期
`new`，守卫会当场 Fatal。约束写在被约束方（`ShowcasePages.php` 文件头）而不是只在调用方，
并把调用方头部「只读两个集合 + 一份冻结清单」的旧描述改正。

### 10.3 变异牙检（`_build/mutate-s1.php`，15 条全过）

| 变异 | 期望 | 实测 |
|---|---|---|
| 基线三条（对账 / 自证 / `golden --check --pages`） | 全绿 | 绿 ✓ |
| M1 删 `AlignPage.php` | 对账报 `disk:missing`；**`golden` 硬失败 exit 2** | 红 / 红 ✓ |
| M2 造 `golden/page/GhostPage/` | 对账报 `baseline:orphan`；**`golden` 报 `orphan-baseline`** | 红 / 红 ✓ |
| M3 从冻结清单删一项 | 对账报 `disk:orphan`；`golden` 报孤儿基线 | 红 / 红 ✓ |
| M4 AOT 清单多一项 | 对账报 `bench:not-in-golden` | 红 ✓ |
| M5 摘掉 R1 并同时造孤儿基线 | 对账仍红；**`golden` 静默全绿** | 红 / 绿 ✓ |
| 还原后基线三条 | 全绿 | 绿 ✓ |

**与 S0 的对照才是本棒的验收线**：M1 / M2 这两个完全相同的缺陷变体，在 S0 时
`golden --check --pages --json` 都是 exit 0；S1 后分别变成 exit 2 与 exit 1。
M5 则证明 R1 是 `golden` 这条路径的**唯一**守门人 —— 摘掉它，孤儿基线又静默，
符合 §9.4 把 R1 定位为「纵深防御、不是 gate 唯一能发现的地方」的裁决：
`tooling` 组的 `sceneset` 仍在，`--fast` 也会红，只是 `golden` 自己瞎了。

### 10.4 停止线核对

| 项 | 结果 |
|---|---|
| `scene-set-check --selftest` | 17 条合成变异全过（S0 的 14 条 + AOT 对价 3 条） |
| 实盘对账 | 清单 34 / 磁盘 34 / 基线 34 / AOT 27，四向封闭 |
| `tooling` 组 | 12 项全过，组自证通过；顶层 gate 仍 21 项 |
| `composer check` / `check:full` | 18 项全过（50.4s）/ 21 项全过（154.9s），EXIT=0 |
| `docref` | 123 ok / 0 FAIL |
| lint | 1083 文件 · 0 error · 0 warn |
| 基线像素 | 未刷任何一张，34 页逐字节比对照绿 |
| 改动文件 | 5 个，全 LF，`git diff --check` 干净 |

### 10.5 未兑现 / 留给后续

- **G1 仍只兑现一半**：改 demo 的页集合不再影响判据，但改文案、导航顺序仍会撞
  `tools/smoke.php` 的字面量断言与 13 个真机探针 ⇒ S2 的 R2、S3 的 R3-a。
- **G3 / G4 不变**：G3 必须 T2；G4 由每棒变异牙检举证，不是常驻判据。
- **M3 单独一棒**：3 个纯值对象契约上移属语义变更，不与 S2 的其他改动混提交。
