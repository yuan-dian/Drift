# 架构与实现 Review（2026-11-08）

> 基线 `14e3e83`，**只读评审，未改任何代码/配置**。
> 方法：四路分面取证（分层与 API 面 / 状态与异步 / 渲染布局输入 / 工具链 DX）→ 逐条 grep 复核 → 对比成熟 GUI → 区分「真问题 / 有意取舍」。
> 不照抄 `DEBT` 与推进计划：本仓已多次证明清单会腐烂（§73/§74 都是把计划推荐判错后改选）。

## 0. 总评

这是一套**分期清晰、自我记录极其诚实**的首版框架：几乎所有「没做」都在代码注释里写明了理由与 M 编号；`doctor.php` / `gate.php` / T0-T1-T2 / golden 确定性注入 / docref / 变异牙检的水准明显超过一般个人项目。

因此本次**意外缺陷很少**。真正的高信号问题集中在两类：

1. **正确性依赖人肉纪律，而纪律的守卫有盲区**（分层 lint 漏掉 `Core`、增量构建 stale 靠"怀疑"、ABI 无握手、`===` 门控靠应用侧手工维持）；
2. **失败以「静默 / 无行号」为主，且发现点太晚**——这是 DX 的根因，不是零散 bug。

一句话判断：**框架能力面基本齐，欠的是"敢用"与"可诊断"；继续堆控件的边际收益已低于补地基。**

---

## 1. P0 — 地基级（按杠杆排序）

### 1.1 分层纪律的检查盲区，恰好罩住最该被约束的中枢

- `tools/Lint/Rules/DependencyDirection.php:19-24` 的 `LAYERS` 列 14 层，**没有 `Core`**；同文件 `:49-51` 注释自承机制是「表里没有就跳过」的顺带放行。
- 于是 `src/Core/Pipeline.php`（595 行、`private static ?Pipeline $current` 进程级单例、4 组 `object` 登记表、帧调度/量具/动画扫描/脏记账全在里面）**进出两个方向都不受检**。
- `docs/DEBT-2026-11-02.md:22-24` 自承「拆了十步…Application 从 1919 行变成 2061 行，净增 142 行」「拆的是代码位置，不是职责边界」。

**判断**：不是漏配一行，是**重构方向与检查覆盖反向**——每拆一次，复杂度往盲区挪一次。
**对照**：Flutter 的 `PipelineOwner`/`BuildOwner` 是显式注入、受检的对象，不是进程级静态单例。
**建议**：把 `Core` 纳入层表；再把 `Pipeline::current()` 的静态出口收敛成可注入服务。成本中，后续所有重构受益。

### 1.2 静默失败是架构级 DX 缺陷

- 长期记忆 `.workbuddy-ai/memory/MEMORY.md`（17KB）积累了 100+ 条「违反就出静默 bug」铁律；`tools/Lint/Rules/` 只 30 条，覆盖可静态判的子集。
- 最坏形态「三绿一炸」：`docs/architecture/08-控件面.md:102` —— lint / smoke / AOT 全绿，**用户点开那一页才 Fatal**。
- 「无行号」：`docs/tutorial.md:133-137` 漏 `parent::__construct()` ⇒ 真机只报 `Attempt to read property on null`，无文件无行；`tutorial.md:183-185` tpc 不校验方法存在性 ⇒ `Errors.log` 里什么都没有。
- `src/Errors.php:172-186` 的机制违规诊断其实很完整（栈 + 日志环 + 错误框 + 退出码 3），**恰恰没覆盖 DX 类静默退化**：门控失效、进度丢失、订阅无宿主、焦点请求被拒，全都不经过 `Errors`。

**对照**：Flutter debug 断言 + 带栈的红屏 + Widget Inspector；Rust 编译期挡住。
**建议**：把静默升级为 `DIAGNOSTIC` 级可观测（不中止、进 `recentDiagnostics()`），先覆盖 4 个高频：① `select` 连续 N 次判不等 ② 进度订阅挂在已派生 Future ③ `select` 返回类型绕过守卫 ④ 焦点/订阅无宿主。这是**把 100 条口传铁律变成机器纪律**的第一步。

### 1.3 `===` 身份门控的契约由应用侧手工维持，lint 却写成框架保证

- `src/State/SubjectBuilder.php:220-227`：仅双方 `Equatable` 才按值比，其余 `===`。要让更新被看见必须换新实例，要让同值不重建必须复用实例 / 实现 `Equatable` / 配 `equals()`——三重手工。
- `tools/Lint/Rules/SelectPurity.php:34-39` 把 `array` 无条件放行，理由是「`===` 是同键序 + 同类型 + 同值」——**该理由对含对象的数组不成立**（PHP 数组对对象元素按身份比）。
- `:52` `IDENTITY_STABLE = ['Drift\Async\AsyncValue']` 声称「框架保证不可变 + 同值复用同一实例」，但 `src/Async/AsyncValue.php:124-134` `withProgress()` 与 `src/State/AsyncCommand.php:354-358` `emit()` 都 `new`；真正维持复用的是**应用侧纪律**（`apps/watermark/src/Store/WatermarkStore.php:110-111` 明写「稳定态复用同一实例，不在 getter 里 new」）。
- `docs/DESIGN-SELECT-PURITY-CONTRACT-2026-11-08.md:314` 自认 `isStableValue()` 与 `sameValue()` 是两份人工对齐、会漂的代码。

> **订正一处误报**：分面探查称「§75 的 `ResultCard::imagesSection()`（`ResultCard.php:151-155`）切片含 `AsyncValue` 对象 ⇒ 门控必然失效」。复核后**不成立**：Store 明确遵守了稳定态复用同一实例的契约，门控生效。真问题是「lint 把应用纪律误写成框架保证」，不是该处已失效。

**对照**：Flutter `ValueNotifier`/`Selector` 默认 `==` 且 `shouldRebuild` 可覆盖；Compose `Snapshot` 自动追踪。Drift 拒绝自动依赖收集是**有意且判死有据**（自动 notify 撕裂读、AOT 无反射/Proxy），不属缺陷。
**建议**：把两份判等收敛成同一份可调用判定；`array` 放行按元素形态收窄（含对象即要求 `equals()`）；`IDENTITY_STABLE` 要么由框架提供工厂，要么降级为显式豁免并在 `select` 处提示。

### 1.4 AOT 增量构建 stale 无判据，正确性靠「人怀疑」

- `AGENTS.md:8`、`tools/drift-build.php:186-188,215` 的口径是「只在里程碑、**或怀疑增量构建 stale 时**才需要 `--full`」——什么时候增量结果不可信，**没有判据**。
- `tools/drift-build.php:65-96` 已有 `hashTree()`（内容哈希 + 输入清单）这种现成基础设施，却没把「新增类 / 属性 id 变化」纳入失效条件。
- `docs/DESIGN-LAYOUTBUILDER-2026-11-04.md:225` 要求「新增类必须 `--full` 且逐个确认 `.cc` 生成」；而 T2 已降级为发版前一次 ⇒ 日常完全不碰 AOT，**stale 只在出包时暴露一次**（后果是 `0xC0000005`）。

**对照**：cargo / rustc 的指纹失效，新增单元自动进依赖图。
**建议**：把「类集合变化」纳入 `hashTree()` 输入；或默认 `--full`、增量改 opt-in。成本 S，消除一整类崩溃。

### 1.5 壳 ↔ PHP ABI 无版本握手

- `cpp-src/drift_win32.stub.php:10-11` 自承：「改一边必须同步另一边：签名不一致时 tpc 链接期会**静默失败** —— 不是编译错误，是运行期行为不对。**这是本桥唯一的硬纪律**」。全仓无 `abiVersion` / `schemaHash` / capability handshake。
- `cpp-src/drift_win32.cc:189-205` 的 `PendingEvent` 是 `HWND + 5 个 int + string`，五个 int 按 `kind` 复用含义（pointer 的 x/y/button/phase/modifier、wheel 的 x/y/delta/axis…）；`stub.php:378-380` 光标枚举是裸整数；`stub.php:266-279` 事件 schema 只是文档表格。

**判断**：AOT 下壳与 PHP 是分开编译的产物，错配表现为「行为不对」，定位成本极高。
**建议**：首包带 `abiVersion + schemaHash`，不匹配**响亮失败**。成本 S，跨发版收益大。

---

## 2. P1 — 结构性优化（多为契约不对称，改动不大）

| # | 问题 | 证据 | 建议 |
|---|---|---|---|
| 2.1 | `Application` 仍是上帝类：1928 行 / 5 个 `implements`（`:61`）/ 68 public / 13 类职责；`TableState.php` 2731 行，业务逻辑住 Widget 层 | `src/App/Application.php:61-106,617-1927` | 组合根定位正确，但 5 个 Host 接口应收成显式 `RuntimeServices` 注入，而非 Application 自己全实现。DEBT 已自认「再拆只是搬位置」⇒ 缺的是**边界形式化** |
| 2.2 | `SubjectBuilderState` 挂载后固化 source/select/equals，**没有 `didUpdateWidget()`**；`build()` 用新 `builderFn()` 配旧 `select` 缓存值 | `src/State/SubjectBuilder.php:159-212`（全文仅 initState/build/sameValue）；`src/Widget/StatefulElement.php:77-86` | 覆写 `didUpdateWidget()` 重绑订阅。父重建换了 select 闭包时当前是**静默错配** |
| 2.3 | 订阅/异步生命周期兜底不对称：`State` 只兜底 `$bag` + `$commands` 两袋；裸 `subscribeFor()` 无去重、无自动回收、无 `dispose()`；`after()` / 裸 `AsyncScope::task()` 不登记 | `src/Widget/State.php:38-42,230-257,380-387`；`src/State/Subject.php:72-78`；`src/State/Subscription.php:13-17`（自承「文档承诺机制守不住」） | 通用 `disposers` 袋 + `Subject::dispose()` + 订阅去重。当前「页面走了任务还在跑」不报错 |
| 2.4 | 错误传播到 UI 一律压成 string：`AsyncScope::await()` 抛 `\Throwable`，`AsyncCommand::fail()` 只取 `getMessage()` | `src/Async/AsyncScope.php:98-103`；`src/State/AsyncCommand.php:305-308,343-347`；`src/Async/AsyncValue.php:38-40` | AOT 字段限制是有意取舍，但至少给 `code: string` 或 `errorMapper`。当前 UI 无法区分 404 / 鉴权 / 超时 / 断网，重试策略只能字符串匹配 |
| 2.5 | 进度是 Future 旁路、不参与组合：`onProgress()` 不沿 `chain()` 传递，传 `$f->then(...)` 的结果就「不报错，进度条不动」 | `src/Async/Future.php:60-66,161-171`；`src/Async/AsyncScope.php:105-111` 自承「是纪律，不是语法糖」 | 让 sink 沿派生链继承；配合 1.2 的诊断 |
| 2.6 | 缺 `Future::timeout(ms)` 组合子：只有 `TimedJob` 任务声明 + 进程级 `sweepTimeouts()` 墙钟 | `src/App/TimedJob.php:21-30`；`src/App/ProcessTaskHost.php:73-96`；全仓无 `function timeout` | 单次 await 设超时目前要 `race()` + `sleep()` 手搓 |
| 2.7 | 渲染地基三项（作者已标有意取舍，但仍是性能天花板）：无 `RelayoutBoundary`（帧级门控 + 整树 `layout()`）、无 damage rect（整帧 RGBA 上屏）、坐标两族三处手工补 padding、命中测试每次全树 DFS 且无结果链 | `src/Layout/RenderObject.php:16-18`（「全树重排是 11-04 的**主动裁决**」）；`src/App/Frame.php:209`；`src/Rendering/DisplayList.php:14-16`；`src/Platform/Win32/Win32SkiaSurface.php:83`；`src/Layout/RenderBox.php:88-90,231-256,1073-1129` | 单独成期，别和前 6 条混。先做 `toLocal()/toViewport()` 单一函数收口坐标补偿，性价比最高 |

---

## 3. 能力缺失（对照成熟 GUI）

| 缺失能力 | 成熟框架 | Drift2 现状 | 性质 |
|---|---|---|---|
| **树内环境 / 依赖注入** | Flutter `InheritedWidget` / Compose `CompositionLocal` / WPF 继承属性 | `BuildContext` 是 `final readonly` 固定 5 字段、明确无 `of()/find()`（`src/Widget/BuildContext.php:21-22,36`）；但 `src/Theme/ThemeScope.php:17-19,62-65` **逐字实现了**「父链解析、最内层赢」且 `src/Widget/Widget.php:205-214` 的 `childContext()` 就是通用钩子，却标 `@internal` | **真缺失，且现成扩展点被锁死**：`ThemeScope.php:58-61` 自承「为让 Widget 不认识 Theme，这里不加第二个协议例外」。深层控件只能 prop drilling。否决 provider soup 是合理取舍，但「连类型安全的 `ProvidedValue<T>` 都没有」是能力缺口 |
| 通用事件路由 | WPF tunnel/bubble + `Handled` | 集中式 `InputRouter` + 沿父链找声明者；`hitTest()` 只返回单个最深盒，无 `HitTestResult` 链 | 真缺失。导致光标/悬停/焦点/滚轮/拖放各写一遍查找（`InputRouter.php:261-274,582-639`） |
| `object` 槽位模式未抽象 | DI 容器 / service registry | §64 焦点通道后，`Pipeline` 里同形状共 4 处（`:93,:289,:337,:394`），每加一个跨层能力都要「字段 + get/set + 解释注释 + 消费侧 `instanceof`」；`src/Widget/Element.php:71-78` 不匹配就**静默什么都不做** | 用 `object` 擦除守分层是必要的，复制 4 次无抽象是最糟的中间态 |
| 运行时组件树检查器 | Flutter DevTools / React DevTools | 无；`src/Rendering/DisplayList.php:12` 自标「可快照（**将来的** Inspector）」 | 有意裁剪，但配合 1.2 是 DX 最痛一段 |
| 状态保留热更 | Vite HMR | `drift-dev.php:216-217,430-452` 400ms 轮询 → `taskkill /F` 跨进程重启、状态全丢；且「重启后新窗口可见」判据**是红的**（`docs/DEV-MODE-2026-11-05.md:92-95`） | 混合：状态保留已判死（有意），**新窗口可见无窗口级断言是真缺口** |
| 统一 CLI / 脚手架 | `flutter create/run/doctor` / `cargo new` | 33 个顶层 `php tools/xxx.php` 入口、无 `bin`、无根 README；`docs/tutorial.md:10-26` 一个 app 五文件 188 行其中 **121 行固定样板**，还要手改根 `composer.json` PSR-4 | 真缺失。但 `tools/doctor.php` 已做得很好（逐项 + `fix` + `affects` + `--json/--strict`，等价 `flutter doctor`） |
| CI / 覆盖率 / 性能回归门禁 | 标配 | 无 `.github` 等任何 CI；`tools/phpunit-run.php:40` 写死 `--no-coverage`；`gate.php --json` 为自动化设计却无消费方；`bench-run.php` 手动、不进 gate | **真缺失且成本最低**。`gate.php:150-153` 自己的话「闸门不在 gate 里 = 红了没人知道」正适用于闸门本身 |
| 状态持久化 / 快照 / 时间旅行 | Redux DevTools | 只有窗口几何（`src/App/WindowStateStore.php:26-31`） | 有意裁剪（AOT 无反射/动态装配，PLAN §6 已判死） |
| 高对比度 / 触控 / 系统托盘 / 富剪贴板 | — | 无 `HIGHCONTRAST` 处理；无 `WM_TOUCH/POINTER/PEN`；`src/Platform/Win32/Win32Platform.php:165-168` 托盘 `return null; // M2+`；剪贴板仅 `CF_UNICODETEXT` | 明确标注的 M1/M2 裁剪，不是意外 |

**已实现，勿误报为缺失**：多窗口（`drift_win32.cc:1874-1877`）、Per-Monitor DPI V2 + 多显示器（`:2535-2582`）、暗色主题（`SystemTheme.php:10`）、UIA 语义树、文件拖放、IME 结果提交 + 候选框定位（组字串不读是**明确裁决**）、keyed 列表 diff（`Element.php:499-581`）、局部标脏重建、Offstage 保活。

---

## 4. DX 专项

**反馈回路**：改一行业务 PHP → 400ms 轮询 → `taskkill /F` → 重启（状态全丢）。碰了 `docs/architecture/12-工程与工具.md:47-58` §3.1 清单（`switch` / 两支 `?:` / 属性读当实参 / 整数除法 / 编译期常量）则 dev 全绿也不算数，要 T2 `--full` ~3min + 真机看图。`docs/ANALYSIS-watermark-dx-2026-11-02.md:45-52` 自评为「同一个行为在三个世界里各验一遍」的**世界税**。

**新人门槛**：无一键 setup；真实依赖散在 `tools/Doctor/Environment.php`（MSVC `14.51.36231`、SDK `10.0.26100.0`、`PHPX_HOME`、`tpc` 源码树、Skia 预编译包「**分发方式仍是开放项**」）+ 字体 + pillow。`docs/00-README.md:47` 自承「跨平台能力矩阵暂缺」。`composer.json` 只声明 `php >=8.4`。

**自定义控件心智负担**：官方五步接线（`CUSTOM-WIDGET-GUIDE-2026-11-05.md:53-66`）+ 9 条静默坑（`:163-178`）+ 30 条 lint + AOT 硬律，**分散三处无单一 checklist**；`RenderProxyBox` 可把 5 个抽象方法降到 1 个，是好设计但藏在实现里。

**项目级真值断链（定位矛盾）**：`AGENTS.md:3` 自称「项目级真值来源」、`:34` 把完整必守判据指向 `.workbuddy-ai/memory/`，但该目录被 `.gitignore` 忽略（`git ls-files .workbuddy-ai | wc -l` = **0**，`PITFALLS.md` 401KB）。对任何新 clone / 第二开发者 / CI 都是断链。记忆不进 git 是合理范围，但**既然定位为项目级真值，结论就应沉淀进 `docs/`**（如 `docs/AOT-CONSTRAINTS.md` 已做的那样）。

**文档**：`DEBT-2026-11-02.md` 9180 行无 TOC、按棒次追加，`AGENTS.md:15` 自标会腐烂；根 `docs/` 41 份日期过程稿仅 10 份有角色标注；`API-SURFACE.md` 只是 `@api` 清单快照，无签名/参数/示例。`tools/gate.php:17-34` 注释列 17 项、`--list` 实为 18 项——工具自身的注释已开始漂。
**澄清**：`docs/architecture/00-README.md` 的索引质量很高；golden **不脆弱**（`tools/golden-raster.php:237-242` 注入确定性字体/主题/Scale，不跨后端比像素），真缺口是**覆盖面**（真窗口 DPI 与 Skia 后端无像素基线）。

**澄清（非问题）**：`apps/` 每个 140–190MB、共 16831 文件是本地未追踪的 tpc 构建缓存（`apps/showcase/src/` 仅 424K，`git ls-files apps` = 135），clone 后不存在；根目录 95 个编译产物已被 `.gitignore` 忽略，是 DX 问题（无 `clean` 命令）而非仓库卫生问题。

---

## 5. 推荐顺序（按杠杆，非按改动量）

| 序 | 事项 | 成本 | 是否需 T2 | 判死条件 |
|---|---|---|---|---|
| 1 | 增量构建 stale 判据化（类集合进 `hashTree()` / 默认 `--full`） | S | 否 | 构造「新增类不 `--full`」能让它红 |
| 2 | 最小 CI 接 `gate.php --fast --json` + 打开覆盖率开关 | S | 否 | CI 上能复现本地红 |
| 3 | ABI `abiVersion + schemaHash` 握手 | S | 是（碰壳） | 故意错配能响亮失败 |
| 4 | 静默 → `DIAGNOSTIC` 可观测（先 4 个高频） | M | 否 | 每条都能构造出触发且被记录的场景 |
| 5 | 契约对称化三件套：`Core` 入层表 / `didUpdateWidget()` / `disposers` 袋 | M | 否 | 变异能咬红；删除仍全绿即死规则 |
| 6 | `childContext()` 泛化成类型安全 `ProvidedValue<T>` | M | **是**（新类 + AOT） | 先裁决 `BuildContext` 是否保持 `final readonly`、AOT 回调签名是否容得下；参考 Flutter `InheritedWidget` 的 `updateShouldNotify` 而非 provider soup |
| 7 | 渲染地基（统一坐标函数 → damage rect → RelayoutBoundary） | L | 是 | 单独成期，别与 1–6 混；每项先有性能判据再动 |

**开工纪律沿用本仓 V2**：每条先回答「能构造出怎样的变异让它报红」，答不出的不上仓；判据不与实现同源；变异打空先自查；改了没红不要写成通过。

> ★ **2026-11-10 后订正**：§5 第 5 项三件套已全部落地 —— `Core` 入层表 + 分层 lint
> fail-closed（第一棒）、`SubjectBuilderState::didUpdateWidget()` 与 `State` 统一
> `disposers` 清理袋（第二棒）。§2.2 / §2.3 描述的是 2026-11-08 基线，保留评审快照。
>
> ★ **2026-11-10 后订正（续）**：§5 第 4 项「静默 → `DIAGNOSTIC` 可观测」已落地第一批
> —— `Drift\Diagnostics` 统一 `[DX]` 前缀 + 按 key 只报一次，接入 `select/unstable`
> 与 `progress/derived-chain` 两个高频点；`gate` 增 `dxdiag` / `dxself` 对账「每个
> 接入点都有判据」。§1.2 的另两类（③ `select` 返回类型绕过守卫、④ 焦点/订阅无宿主）
> **未接**，理由见 `DESIGN.md` §16.2.1 的诚实边界：③ 需在 AOT 无反射下先把两份判等
> 收敛成同一份，④ 在纯单测 `mount()` 下无宿主是正常形态，加诊断会把 78 处「零诊断」
> 断言一次搞红。§1.2 描述的是 2026-11-08 基线，保留评审快照。
>
> ★ **2026-11-10 后订正（续二）**：§5 第 1 项「增量构建 stale 判据化」已落地，
> 但落地形态与本表建议不同 —— `hashTree()` **本就**含文件清单（新增/删 `.php`
> 必然改指纹），真正缺的是「指纹变了之后没触发清缓存」；照抄「把类集合塞进
> `hashTree()`」一行行为都不会变。现由 `Drift\Dev\Build\StalePolicy` 把默认翻成
> 「门不能信缓存」（对齐 T1 `drift-codegen` 已验证的裁决），`--incremental` 显式
> opt-in，gate 增 `stalepolicy` / `staleself` 两道不同源判据。详见 `DESIGN.md`
> §16.2.2。§1.4 描述的是 2026-11-08 基线，保留评审快照。
>
> ★ **2026-11-10 后订正（续三）**：§2.4「错误传播到 UI 一律压成 string」已落地，
> 但**落地形态比本表建议更彻底**。实证发现丢码的起点不是 `AsyncCommand::fail()`，
> 而是**跨进程边界**：`ProcessWorker` 把异常压成 `'类: 消息'`，`ProcessTaskHost` /
> `ThreadTaskHost` 还原成 `new \RuntimeException($msg)`（code 恒 0）。只在
> `AsyncValue` 加一个 `code` 字段，跨进程任务（本仓主要异步后端）永远拿不到分类
> ⇒ 判据全绿而能力是假的。现由 `Drift\Async\Failure` 作载体 + `codeOf()` 单一
> 提取点，贯通 worker 载荷 → 两个还原端 → `fail()` → `AsyncValue::code()` /
> `Future::allSettled()` 五段；`AsyncCommand::mapError()` 是应用侧分类插槽（框架
> 不硬编码 HTTP 语义，同 RTK Query `transformErrorResponse`）。gate 增
> `errshape` / `errself`。详见 `DESIGN.md` §16.2.3。§2.4 描述的是 2026-11-08
> 基线，保留评审快照。

## 6. 诚实边界

- 全程只读，未跑 gate / 未做变异 / 未验证任何修复方案的可行性；上表「成本」是静态判断。
- 四路探查中「单窗口限制」「DPI/多显示器/暗色缺失」「golden 脆弱」「apps 体积是仓库膨胀」「§75 门控必然失效」五条经复核为**误报**，已在正文订正或澄清。
- 未评估 C++ 壳的内存安全 / 线程模型，仅看 ABI 契约面。
