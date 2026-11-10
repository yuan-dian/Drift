# LayoutBuilder 设计裁决（2026-11-04）

> 本文只做方案裁决，不含实现。定位一律用**符号名 / 方法名**，不写 `file:line`
> （`docs/00-README.md` §6 纪律：新文档不写行号）。
> 事实来源：本仓只读核对 + `api.flutter.dev` 官方文档 + `D:\TypePHP\Drift3\docs\research`
> 15 份平台调研（含 `12-横向思路提炼.md`、`14-Raven研读.md`）。
> 状态：**已实施**（2026-11-04，见 §9 实施记录）。

## 1. 问题

`LazyListState::viewportEstimate()` 与 `TableState::reflowIfWidthDrifted()` 想按
「本容器实际有多大」决定造多少行、列宽怎么分，但 `BuildContext::$width/$height`
是**应用根**收到的一次窗口级快照，整棵树共用、档内不刷新（`BuildContext` 的
docblock 明写「响应式布局的唯一判据是 `windowSize()`，别拿 `$width` 比大小」）。

于是两处各自实现了一份补丁：先按窗口尺寸造一版，layout 后读真实盒尺寸发现
漂了就标脏、下一帧再 build 一遍。`tests/Feature/Table/WidthDriftTest.php` 钉住的
正是这条「发现漂移 → 重算 → 收敛」路径。

代价不止「慢一帧」：

- 首帧可能画出**被裁的错误布局**（右列超盒），用户看得见一次闪动；
- 补丁散在两个 State 里，第三个按尺寸分支的控件还得再抄一遍；
- `BuildContext` 把「不知道」伪装成了一个具体数字，控件无从分辨。

## 2. 事实基础（只读核对，不是推测）

| 事实 | 证据 |
|---|---|
| 一帧顺序 = `settle(build)` → `layout` → `LayoutSelfCheck` → `paint/present` | `Frame::run()` |
| `layout` 之后**没有**再 build 一轮的通道 | `Frame::run()` ② 段后只有 ②b / ③ / ④ |
| `RenderBox::layout()` 是 `final`，`performLayout()` 只收 `BoxConstraints`，`size` 末尾落盘 | `RenderBox::layout()` |
| 30 个 `performLayout()` 里**零** `build` / `setState` / `markDirty` / `updateChildren` | 全仓扫描 |
| `LayoutSelfCheck` 每 16 帧用**同一约束**再排一次，几何不等即报「布局不是纯函数」 | `LayoutSelfCheck::maybeCheck()`、`RenderBoxContractTest` |
| `Element::settle()` 自带 16 轮收敛；`Frame::needsSettle()` 用 `markedCount` 序号判据 | `Element::settle()`、`Frame::needsSettle()` |
| 帧内 `State::markDirty()` **不**叫醒帧时钟，但会让 `markedCount` 变 ⇒ 帧内二次 `settle()` 是现成通道 | `Pipeline::markDirty()`（`$inFrame` 分支）、`State::markDirty()` |
| 盒每次 `syncBox()` 换新；`inheritRuntimeState()` 只移交运行态 | `Element::syncBox()` |
| `Widget::childContext()` 是唯一 per-Element 环境派生点，目前只被 `ThemeScope` 用来换主题 | `Element::childContext()` |
| `BoxConstraints` 是 `Drift\Geometry` 的 `final readonly` `@api`，有 `equals()`；Widget 层**从未**用过它 | 全仓 `use` 扫描 |
| `RenderBox::performLayout()` 收到的是**内容盒约束**（已过 mods 收紧 / padding 内缩 / align 松下界） | `RenderBox::layout()` ①②③ |

### 成熟框架对照（官方文档 + 各框架公认约束）

| 框架 | 做法 | 明确记载的坑 / 可借鉴处 |
|---|---|---|
| Flutter `LayoutBuilder` | builder 在 **layout time** 调用（官方原文：*the framework calls the builder function at layout time and provides the parent widget's constraints*） | **相同约束不重复调用**；约束过期时用 *last known constraints* 重建；最终尺寸跟随 child。⇒ 借「相等门控 + 过期兜底」，**不借**「layout 期造树」 |
| Flutter `RenderSliver` | `SliverConstraints` **把 scrollOffset 放进约束对象**，只对可见区间建/保 child | 布局仍是 constraints 的纯函数、滚动态可无头测试。⇒ 尺寸/视口信息该**进约束**，不该让 State 回头读盒尺寸 |
| Compose `BoxWithConstraints` | 走 `SubcomposeLayout`，组合延迟到测量期 | 延迟组合与 `IntrinsicSize` 固有测量**时机对立**，查询抛 `IllegalStateException`；测量期重组易成环。⇒ 印证别把建树放进测量期 |
| SwiftUI `GeometryReader` | 子自己读 proposed size | 会吃掉父的 proposed size、返回 flexible size；**Apple 文档自认「破坏布局契约，仅作最后手段」**（Drift3 `02-SwiftUI.md` §4 吐槽 3）。⇒ 这是「布局能力不足」的认证，不是常规 API |
| WPF `Measure/Arrange` | 两遍式：先全量量、再全量布 | 收益是「父先知道大家都要多大再统一分配」；Drift3 `12` §4 明确指出**该收益可在一遍式内用「父一次性收集子尺寸再分配」拿到**，不必付两次树遍历 |
| Qt / WPF 测量期 | Measure 期禁止 `InvalidateMeasure()` | layout cycle ⇒「测量期纯函数」是跨框架共识 |
| Web `ResizeObserver` | **布局后**回调 | 回调里改布局须等下一帧，否则 `ResizeObserver loop completed with undelivered notifications`。⇒ 正是本仓现有补丁的一帧代价 |

### 补充：Drift3 调研带来的三条硬约束

1. **「逃生舱」定性**：`12-横向思路提炼.md` §4 避坑首条 = 「不要提供破坏布局契约的
   逃生舱当常规手段 —— SwiftUI GeometryReader 是布局能力不足的官方认证」。因此
   本轮做 `LayoutBuilder` 的同时，**必须**在 API docblock 与文档里写明它是最后手段，
   并列出「不该用它」的场景（列宽分配、虚拟化行数都属于布局层职责）。
2. **可观测性**：`12` §9 + §2 反复强调「任何自动机制必须可 inspect」「静默失败零容忍」
   （WPF 字符串绑定只在 Output 窗口一行警告是反面教材）。⇒ 帧内收敛**不能静默**：
   轮数、漂移来源、震荡诊断都要能量具 dump。
3. **成本诚实**：`14-Raven研读.md` §1.3 / §3-B 指出本仓「每帧全树 layout、无脏子树
   增量」是明确落后（Raven 12k widget 改一处 0.03–0.05ms），并把增量布局列为
   P1 头号布局任务。⇒ 本方案「帧内二次全树 layout」必须**只**发生在约束真变的
   帧，稳态严格 1 遍（判据 8 守）；增量布局是**另一轮**，不塞进本轮。

另：`12` §3.2 要求「commit 原子 —— 帧末批量生效，不做撕裂中间态」，
恰好是方案 D（收敛完才 paint）相对方案 C 的额外理由。

**结论**：本仓「布局是纯函数」不是保守，是主流共识；Flutter 的零帧体验也不该丢。
两者可兼得 —— 把「造树」留在 `settle()`，把「收敛」放进 `Frame` 的同一帧。
但 `LayoutBuilder` 要按「逃生舱」定位、按「可观测」实施、按「不放大全树布局债」限流。

## 3. 四个候选

| | A · Flutter 式 layout-time builder | B · 影子布局（build 前先算约束） | C · 约束缓存 + 下一帧重建 | **D · 帧内约束收敛** |
|---|---|---|---|---|
| 做法 | `performLayout()` 里调 builder 并挂新 Element | 先跑一遍只算约束的 pass，注入 ctx 再正式 build | 盒记约束，layout 后标脏，下一帧 build | 盒记约束，`Frame` 在 layout 后**同帧**再跑 `settle + layout`，有界收敛后 paint |
| 零帧 | ✅ | ✅ | ❌（保留一帧） | ✅ |
| 布局纯函数 | ❌ 破坏，且 `LayoutSelfCheck` 每 16 帧重排会重入 builder | ⚠️ 影子 pass 的副作用要回滚 | ✅ | ✅（只写一份入参快照） |
| 结构 | 要给 `layout()` 开洞 + 给 layout 期开挂树通道，绕过 `settle()` 收敛与 key 复用 | 需两套 layout 语义；Flex 的子约束依赖兄弟尺寸，影子 pass 拿不到真值 | 最小改动 | 改 `Frame::run()` 一处循环 + 一份 observer 表 |
| 扩展性 | 差：未来任何「跳过 layout / 层缓存」优化都要给它让路 | 差 | 中 | **好**：约束快照是 `RenderBox` 通用能力，`Sliver` / 固有尺寸 / `ResizeObserver` 类需求都能复用 |
| DX | 与 Flutter 同名同形，但 builder 里 `setState()` 是禁区 | 同 | builder 同形，但 resize 后会看到一帧旧布局 | builder 同形、零帧，且 builder 里**允许** `setState()`（它在 build 期） |
| 维护性 | 最差：调试时 layout 期改树，栈里看不出谁动的结构 | 差 | 好 | 中：`Frame::run()` 一处循环 + 有界上限 + 可 dump 的收敛量具 |
| 与既有纪律 | **冲突**（布局纯函数、跳过 layout 优化、AOT 下新增树操作） | 冲突 | 不冲突，但没根治 | 不冲突 |

**否决 A**：与 `LayoutSelfCheck` 根本判据正面冲突，且「layout 期建树」正是
Compose / Qt / WPF 三方都踩过的坑。**否决 B**：一遍式约束下父给子的约束依赖
兄弟尺寸，影子 pass 本质上就是完整 layout，两遍成本 + 副作用回滚，语义更差。
**保留 C 作降级**：如果 D 在真机上暴露不可控回归，退回 C（同一套 `LayoutBuilder`
外形，只把收敛改成跨帧）。

**是否还有更优解？** 有两条**部分更优**的路，但都不替代本轮：

- `TableState` 的列宽分配本该由**布局层**按约束一次分配（WPF 两遍的收益在一遍式
  内实现），根本不需要 build 期知道宽度 ⇒ 后续单独一轮把列宽下沉到 Render 层，
  能从根上少一个逃生舱消费者。
- `LazyListState` 的行数本该由 `SliverConstraints` 式「视口信息进约束」解决
  （Flutter 的虚拟化在 layout 期按可见区间建子）⇒ 属于虚拟化机制改造，范围更大。

这两条属于「把布局职责从 State 收回布局层」的架构方向，比发一个通用逃生舱更正确；
但它们要改 `RenderTable` / 虚拟化协议，**不在本轮范围**（见 §8 边界）。本轮提供
`LayoutBuilder` 是给「确实需要按尺寸换整棵子树」的场景（如宽窄两套版式），
并把它明确标成逃生舱，不鼓励拿去做列宽/行数。

## 4. 推荐：D · 帧内约束收敛

### 协议形状

```
Frame::run()
  ① settle(build)          ← LayoutBuilderState 读「上一轮记录的约束」调 builder
  ② layout                 ← RenderBox 记下本次收到的约束（纯数据，不改树）
  ②c 问 observer：约束变了吗？变了 ⇒ markDirty()，回到 ①（有界）
  ②b LayoutSelfCheck       ← 只在最终一轮之后跑
  ③ paint → ④ present     ← 收敛完才上屏（commit 原子，无撕裂中间态）
```

②c 是本方案唯一的**新协议**，但它不是新机制：`Element::settle()` 本来就有
16 轮收敛，`Frame::needsSettle()` 本来就用 `markedCount` 判据，
`State::markDirty()` 帧内本来就只记账不叫醒时钟。三个现成零件接起来即可。

### 关键裁决（逐条，实施时不再反复）

1. **builder 拿哪份约束**：`RenderBox::performLayout()` 收到的**内容盒约束**
   —— 即「本盒传给子盒的同一份」。不是父传来的原始约束。理由：响应式判断要的是
   「子树实际可用空间」，且 builder 选出的子树随后拿到同一份约束，自洽；要原始约束
   的场合开发者自己按 mods 反推。与 Flutter 语义的差别写进 `LayoutBuilder` docblock。
2. **首帧 / 盒还没排过时 builder 看到什么**：**不编造** —— 给一个显式的
   `constraintsKnown() === false` 标记 + 零尺寸占位（不调 builder）。因为收敛在同一
   帧内完成，占位**根本不会被画出来**，却避免把窗口尺寸伪装成本盒约束
   （这正是当前 bug 的根源）。对齐 Flutter 的 *last known constraints*，
   但用「未知」而非「猜一个」。
3. **builder 里能不能 `setState()`**：能（它在 build 期）。`State::markDirty()` 的
   E-FLUSH-CYCLE 守卫照常生效 —— builder 里给**自己**标脏仍报错，这是既有纪律，
   不为 `LayoutBuilder` 开后门。
4. **是否破坏 `BuildContext::windowSize()` 的「唯一响应式判据」**：**是，显式破坏**。
   `windowSize()` 保留给「窗口档位」语义，`LayoutBuilder` 提供「本盒约束」语义，
   两者正交（参考 GAP §11.6 对 `WindowSize` 档位的结论）。`BuildContext` 的
   docblock 与 `$width/$height` 注释同步改，但**不删字段**（仍有消费者）。
5. **相等门控**：用 `BoxConstraints::equals()`。相同约束不重新调 builder、不标脏
   —— 对齐 Flutter「相同约束不重复调用」与 Qt 的相等门控去抖，也是防震荡第一道闸。
6. **有界与震荡**：外层最多 4 轮（正常 2 轮）；超限或检测到 A→B→A 回溯 ⇒
   `Errors::report(..., DIAGNOSTIC)` 并停在当前轮，**不静默、不死循环**。
7. **`LazyListState` / `TableState` 怎么办**：改为直接消费盒上的真实约束，
   **删掉** `reflowIfWidthDrifted()` 这类自持补丁。两份机制并存会互相标脏 ⇒ 空转，
   这是必须一起做的验收项，不是可选优化。
8. **兼容性**：允许破坏，但只破上述第 4 条语义；`RenderBox::layout()` 保持
   `final`、`performLayout()` 签名不变、三树协议不变。
9. **unbounded 约束**：滚动视图 / `Row` / `Column` 内 `maxWidth` 可能是 `INF`，
   按宽度分支会恒真（Flutter 的经典坑类）。builder 侧必须显式用
   `hasBoundedWidth()` / `hasBoundedHeight()` 判「能不能按尺寸分支」；
   **无界时不给假的有界值**，也不把 `INF` 悄悄当「宽屏」。
10. **可观测**：`Pipeline` 量具记 `constraintRounds`（本帧收敛轮数）与
    最近一次漂移来源（observer 类名 + 四个 float 的前后值）。震荡诊断必须带
    「谁在漂」，不能只说「没收敛」。对齐 Drift3 `12` §9「自动机制必须可 inspect」。
11. **逃生舱定位写进 API**：`LayoutBuilder` 的 docblock 与 `docs` 明写
    「最后手段；列宽分配 / 虚拟化行数请用布局层原语」，避免它变成下一个
    `GeometryReader`。
12. **范围边界**：本轮**不做**脏子树增量布局、**不做** `SliverConstraints` 式
    视口约束、**不重构** `RenderTable` 列宽协议。三者另立任务，避免一轮塞两套架构改动。

### 为什么 observer 表放在 `Pipeline` 而不是遍历树

`Frame` 不该 `instanceof` 具体控件（`src/App` 的 `instanceof` 只许落在接口上，
`ARCH-REVIEW` I2）。`LayoutBuilderState` 在 `initState()` 向 `Pipeline` 注册为
`ConstraintObserver`（`Core` 存 `object`，不依赖 `Widget`，与
`noteActiveAnimation()` 同一形状），`dispose()` 注销。`Frame` 只遍历这张通常为空的
小表，问一句「约束变了吗」，不碰树、不全树重建。

## 5. 五维复核

- **结构**：约束是 `RenderBox` 的通用**输出快照**，不是某个控件的私有字段；
  「读约束」与「造子树」仍分属 layout / build 两段，三树边界不糊。
- **实现**：新增类仅 3 个（接口 + Widget/State + 无需新 RenderObject —— 约束记录
  收在 `RenderBox` 基类）；`Frame::run()` 的 ①+② 抽成 `pass()` 循环，改动集中。
- **扩展性**：`RenderBox::constraints()` 一旦是公开能力，后续「按尺寸分支」的
  控件不必再各写一份「上一帧盒尺寸 + 标脏」补丁；`BoxConstraints` 首次成为 Widget
  层公开类型，需同步 `@api` / apidoc 清单 / lint 依赖方向。未来接
  `SliverConstraints` 式视口约束时，这份快照是现成落点。
- **易用性**：`LayoutBuilder::make(fn(BuildContext $ctx, BoxConstraints $c) => Widget)`
  与 Flutter 同名同形；零帧；builder 里 `setState()` 不再是禁区（相对 Flutter 是
  DX 优势）。首帧「未知」是显式标记而非悄悄给个假数字；无界约束要显式判
  `hasBoundedWidth()`，不让 `INF` 变成隐性「宽屏」。
- **维护性**：`LayoutSelfCheck` 判据不动、`settle()` 收敛不动、帧时钟不动；
  循环上限、震荡诊断与收敛量具让「约束反馈成环」变成一条**能定位到谁**的诊断，
  而不是卡死或静默多跑一遍。
- **成本诚实**：稳态 1 遍 layout；只在约束真变的帧付第 2 遍（判据 8 守）。
  本仓全树 layout 的既有债不因本方案扩大，增量布局另立任务（裁决 12）。

## 6. 判据清单（实施时逐条落地，都要能证伪）

| # | 判据 | 反例 / 变异必须变红 |
|---|---|---|
| 1 | 窄父 + 按宽度分支的 builder ⇒ **同一帧**就画出窄布局（不是下一帧） | 去掉帧内循环 ⇒ 红 |
| 2 | resize 后约束变化 ⇒ 同帧切到宽布局 | 去掉相等门控仍能过 ⇒ 该判据不合格，须补 |
| 3 | 相同约束重复跑帧 ⇒ builder 调用次数**不增** | 去掉 `equals()` 门控 ⇒ 红 |
| 4 | builder 里 `setState()` 正常生效，不触发 E-FLUSH-CYCLE | — |
| 5 | 构造 A→B→A 的震荡 builder ⇒ 报 diagnostic、帧数有界，且诊断带漂移来源 | 去掉上限 / 震荡检测 / 来源 ⇒ 红 |
| 6 | `LayoutSelfCheck` 仍 0 mismatch（布局纯函数未被破坏） | 在 `performLayout()` 里挂树 ⇒ 红 |
| 7 | `TableState` 新链：首帧列宽即按真实盒宽算，`WidthDriftTest` 的原三断言仍绿 | 删掉约束消费 ⇒ 红 |
| 8 | 稳态（无约束变化）只跑 1 遍 layout（`Pipeline::layoutCount()` 判据） | 每帧无条件第二轮 ⇒ 红 |
| 9 | 卸载的 observer 已从表移除（不泄漏） | 漏注销 ⇒ 红 |
| 10 | 量具能 dump 本帧 `constraintRounds` 与漂移来源 | 只收敛不记账 ⇒ 红 |
| 11 | unbounded 约束下 builder 不被误判为「宽屏」；`INF` 不产生有界语义 | 用 `>= 600` 裸比 `maxWidth` ⇒ 红 |
| 12 | AOT 全量构建后新增类都有 `.cc`，真机跑「窄→宽」一次 | 记忆 ★ 新增类闸门 |

## 7. 实施清单

1. `RenderBox`：`lastConstraints` 快照 + `constraints(): ?BoxConstraints`（`@api`）。
2. `Drift\Widget\ConstraintObserver` 接口 + `Pipeline` observer 表与收敛量具
   （注册 / 注销 / 遍历 / `constraintRounds` / 漂移来源）。
3. `LayoutBuilder` + `LayoutBuilderState`：builder 调用、未知占位、相等门控、
   `hasBoundedWidth()` 边界、逃生舱 docblock、注册注销。
4. `Frame::run()`：①+② 抽 `pass()`，②c 有界收敛 + 震荡诊断；序号记账移到最终一轮。
5. `LazyListState` / `TableState` 删除自持补丁，改读真实约束。
6. `BuildContext` / `docs` 注释语义更新；apidoc / lint 依赖方向同步。
7. 判据 §6 全部落地 + 变异验证；T1 → T2（AOT `--full` + 真机看图）。

## 8. 风险、边界与回退

- **一帧内两遍 layout**：只在约束真变的帧发生，稳态 1 遍（判据 8 守）。
  Raven 调研已指出全树 layout 是本仓明确的性能债 ⇒ 本方案不得把它变成常态。
- **新增类触发 AOT 全量**：必须 `drift-build --full`，且逐个确认 `.cc` 生成。
  ★ **2026-11-10 后订正**：这条人肉纪律已判据化 —— 输入指纹变了 / 首次 /
  产物不在 ⇒ `drift-build` **自动全量**（`StalePolicy` + gate `stalepolicy`，见
  `DESIGN.md` §16.2.2）；只有 `--incremental` 才走增量。原条目保留作 2026-11-04 快照。
- **帧内 `markDirty()` 会顺带登记动效活跃表**：`tickFrame()` 默认 `false`，不续帧；
  但队列须被 `takeActiveAnimations()` 正常消费，真机追一帧确认无空转。
- **逃生舱被滥用**：靠 docblock + §3「不该用它」清单 + 判据 11 治理，
  不靠运行时拦截（那是 WPF 字符串绑定静默失败的反面）。
- **与 Drift3 路线的张力（需知悉，不擅自改顺序）**：`14-Raven研读.md` §3-B 把
  「脏子树增量布局」列为 Drift P1 头号布局任务，而本仓 GAP §11.6 的下一棒推荐是
  `LayoutBuilder`。两者正交但共享「布局协议」这块地盘；本轮按用户指定的推荐顺序
  推进 `LayoutBuilder`，**把增量布局留作独立一轮**，并在实施时避免把收敛循环
  写成未来增量布局的障碍（约束快照是纯数据、不依赖全树遍历顺序）。
- **回退**：若真机暴露不可控回归，保留 `LayoutBuilder` 外形与 `RenderBox::constraints()`，
  仅把 ②c 的收敛降级为「标脏 + `requestFrame()` 下一帧」= 方案 C，
  判据 1 / 2 相应改为「一帧内收敛」的诚实版。

## 9. 实施记录（2026-11-04）

按 §7 清单落地，与设计的偏差只有两处，都写在这里：

| 项 | 实际做法 |
|---|---|
| 新增类 | 3 个：`ConstraintObserver`（接口）、`LayoutBuilder`、`LayoutBuilderState`。**没有**新 `RenderObject` |
| 约束快照 | `RenderBox::lastConstraints` 在 `performLayout()` 调用**之前**落，记的就是"本盒传给子盒的那一份"（裁决 1）；`null` = 不知道 |
| 壳 | `LayoutBuilder::build()` 返回 `SizedBox::make($built)` —— 让 `effectiveBox()->constraints()` 的落点与 builder 返回什么无关（**偏差 1**：设计里没写这层壳，但不加就会读到子树内部某层盒的约束） |
| 观察者表 | 住 `Pipeline`（`object` 列表 + 身份去重），`Frame` 侧 `instanceof ConstraintObserver` 过滤 |
| 收敛循环 | `Frame::run()` 的 ①+② 抽成 `for` 循环，上限 `MAX_CONSTRAINT_ROUNDS = 4`，`LayoutSelfCheck` 移到循环**之后** |
| 震荡检测 | 同一句漂移描述在本帧内重复出现即判震荡（比"轮数用光"更早刹住），诊断里写明"检测到震荡" |
| `LazyListState` / `TableState` | 视口改读 `constraints()`（与两个 `Render*` 自己算视口用的是同一份数）；`reflowIfWidthDrifted()` 与 `tickFrame()` 自查删除，改由 `pollConstraintDrift()` 当帧收敛（**偏差 2**：`WidthDriftTest` 的驱动从 `tickFrame()` 换成 `pollConstraintDrift()`，三条断言的语义不变） |
| 判据 | §6 的 12 条落在 `tests/Feature/Widget/LayoutBuilderTest.php`（13 条）+ `WidthDriftTest`（3 条）；6 组变异全部变红 |

**顺带修掉的既有 bug**：`golden/page/TablePage` 的旧基线是**按 bug 烤的** ——
表格按窗口宽 900 分列，而它实际只有 860，右侧"状态"列被裁掉一截。约束反馈落地后
首帧就按 860 分，`actual.png` 里 5 列完整可见 ⇒ 刷基线（只动了这一个场景）。

### 9.1 真机（T2）

`drift-build --full` 后 `hello.exe` 13.1 MB / `showcase.exe` 14.8 MB，真机判据
"窄窗 → 宽窗各一次"（`_build/lb-probe2.php`，客户端 684×601）：

```text
LayoutBuilder：按本盒真实约束换版式（拖窄窗口看它换）
  684 宽 ⇒ 橙色 60px 块 + 「窄：本盒可用不足 520 ⇒ 小色块」
 1409 宽 ⇒ 青色 160px 块 + 「宽：本盒可用 520 以上 ⇒ 大色块」
close rc=0 · exit=0
```

★ 顺带暴露并修掉的**另一处既有 bug**（与本设计无关，但挡在 T2 路上）：
AOT 版启动即 `0xC0000005`，栈落在 `BuildContext::__construct → WindowSize::fromWidth`。
根因是 tpc 把 **enum 内任何 `self::X`** 一律编成 `php::getEnumCase()`
（`typephp/src/Parser/ClassConstantFetchTrait.php`），而 `WindowSize` 的三个断点是
`public const`（非 case）⇒ `getEnumCase` 抛"不是 enum case"⇒ 崩。
`php -l` / lint / smoke / phpunit **全绿**，只有真 exe 能抓到。修法：断点写成字面量，
enum 里不声明非 case 常量（细则见 `PITFALLS.md`）。
