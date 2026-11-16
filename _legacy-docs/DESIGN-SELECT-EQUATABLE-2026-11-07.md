# 设计裁决：`select` 切片的值相等语义与假绿闸门修真（2026-11-07）

> 本棒来自上一轮 `docs/ANALYSIS-watermark-async3-borrow-2026-11-07.md` §10.5 的推荐方向
> 「收状态广播 / Widget 样板」，但** Implementation 前对代码后改了选点**：原先设想的
> 「加应用层 helper 收敛样板」经四维评估后被否，改做下面这件结构性更值钱的事。
> 口径：全部数字为本轮 `wc` / `grep` 实测；历史裁决以 `DESIGN.md`、`DEBT-2026-11-02.md` 为准。

## 0. 一句话结论

**不加 helper、不换范式、不引入自动依赖收集。** 本棒做两件同源的事：
① 让 `SubjectBuilder` 对「显式声明了值相等契约的值对象」**默认用值相等门控**，
把靠人记的 `->equals(...)` 变成机制；② 修好 `select-purity` 对 `static fn` 形态的
**系统性假绿**，让 24 处真实 `select` 第一次进判据。
两件合起来回答「心智负担」：不是让开发者多学一个 helper，而是让**默认行为正确**、
让**已有判据真的生效**。

## 1. 动手前查到的两个静默缺口（都是「全绿但没测到」）

### 1.1 缺口 A：`select-purity` 对 `static fn` 完全失效

- `tools/Lint/Rules/SelectPurity.php:259-275` 的 `isClosure()` 只认 `T_FN` / `T_FUNCTION`；
  `->select(static fn(...))` 的第一个 token 是 `T_STATIC` ⇒ 直接 `continue`。
- 夹具 `tools/Lint/fixtures/apps/SelectFixture.php` 五行全是**不带 `static`** 的 `fn(...)`
  ⇒ selftest 的 3 条「该响」全绿，规则却从没扫过真实业务形态。
- 本轮实测：全仓 `->select(static fn` **24 处**、`->select(fn` **35 处**；
  `apps/watermark` 的 **15 个 select 全是 `static fn`**。
  ⇒ `php tools/lint.php` 的「1046 文件 · 0 error」在状态订阅这一维**不构成证据**。
- 本仓 AOT 纪律恰恰鼓励 `static fn`（防 `static fn` 里误用 `$this`，
  `.workbuddy-ai/memory/2026-10-16.md:80` 记过一次真事故）⇒ 这不是边角形态，是主流写法。

### 1.2 缺口 B：值对象在默认门控下退化成「每次通知都重建」

- `src/State/SubjectBuilder.php:189-191`：未给显式 `equals()` ⇒ 用 `===`。
  `WatermarkStore` 里 `StatusMessage` 被重新赋值 **30+ 处**，其中同内容的
  `StatusMessage::none()` 就写 4 次（构造器 + 清空选择 / 移除图片 / 清空三处）；
  每次赋值都是新实例 ⇒ **内容没变也判"变了"** ⇒ 白白重建一次子树。
  ★ 口径要说准：没重新赋值时 getter 返回同一实例、`===` 相等 ⇒ 症状不是
  「每次 `notifyChanged()` 都重建」，而是「同内容的新实例会多重建一次」。
- 全仓 4 处 `select` 返回 `StatusMessage`，只有 `apps/watermark/src/Widgets/StatusBar.php:46`
  配了 `->equals(...)`；`UploadCard::message()` 与 `TaskCard::taskMessage()` **漏配**。
- `SubjectBuilder` 自己的 docblock（`:87-89`）把这条写成了「要值语义就显式给 `equals()`」
  —— 即**靠人记**，且漏了不会报、不会崩、只是白白重建。正是 §8.4 列的第 8 条隐性规则。
- 更讽刺的是：`src/State/SubjectBuilder.php:87-89` 已经把坑写进注释，
  `tests/Unit/State/SubjectBuilderTest.php:87-101` 也把「默认值对象恒重建」当**既有行为**
  钉成判据，注释明写「补的是判等策略的注入口」。⇒ 注入口有了，**默认正确**这一步一直没走。

### 1.3 顺带查明的规则自身逻辑漏洞

`tools/Lint/Rules/SelectPurity.php:27-34` 允许 `readonly class`、禁止 `array`，
理由是「`===` 门控下每次返回新对象就永远不相等」。但：

| 返回形态 | `===` 的真实语义 | 规则现状 | 是否自洽 |
|---|---|---|---|
| `array` | 同键序 + 同类型 + 同值，**稳定值语义** | ❌ 禁止 | 否（watermark 7 处有意聚合切片） |
| nullable / `null` | 标量式值比较，**稳定** | ❌ 禁止 | 否（理由「要额外判空」与 `===` 无关） |
| `readonly class` | **按对象身份**，每次 `new` 仍不相等 | ✅ 允许 | 否（fixture `good()` 第三条正是 `new UserRow(...)`） |

⇒ 把判据从「类型白名单」重述为「**`===` 是否有稳定值语义**」是**修正规则自身**，
不是为迁就业务而放宽。`readonly class` 的历史裁决（`docs/_archive/DESIGN-v2-2026-10-14.md:2541`）本棒不重开，
只在文档登记，避免一棒摊太大。

## 2. 不重开的已判死项（先列，防止走回头路）

| 议题 | 裁决出处 | 本棒态度 |
|---|---|---|
| 运行期自动依赖追踪 / 读即订阅 | `docs/_archive/DESIGN-v2-2026-10-14.md:1527-1530`（两次否决）、`ARCH-REVIEW-2026-10-21.md:103`（不可观测） | 不碰；读侧仍是显式 `select()` |
| 编译期依赖表 / AOT 无 build 栈 | `REVIEW-2026-11-01.md:16/29/116`（P2-5） | 不碰 |
| `Cell` / `Computed` 原语 | `MEMORY.md:60`（Cell 已删）、`docs/_archive/DESIGN-v2-2026-10-14.md:1244,1266` | 不复活 |
| `Bound` / 表单双向绑定 | `docs/_archive/DESIGN-v2-2026-10-14.md:1291,1297-1300` | 不碰 |
| `Subject` 改框架级自动广播 / 自动 marshal | `docs/_archive/DESIGN-v2-2026-10-14.md:1129,1159-1160`、`_archive/DECISIONS-2026-10-14.md:666-679` | 写侧仍手动 `notifyChanged()` |
| Store 每字段 Cell 化 / property hooks 全量铺开 | `docs/_archive/DESIGN-v2-2026-10-14.md:1160`「业务层零 GUI 依赖」 | 不做；实测 16 个字段 × 4 行 hook > 省下的 29 行 notify，且引入中间态撕裂读 |
| `FlowPolicy` 一类的收敛 helper | `DEBT-2026-11-02.md:8664-8670` §68.5 已判死 | 不建类 |

**「按 Drift3 方案重写」也不重开**：`docs/ANALYSIS-watermark-async3-borrow-2026-11-07.md` §10
已给出三层结论，本棒零行 Drift3 代码。

## 3. 成熟 GUI 框架的借鉴与排坑

| 框架 | 做法 | 借什么 | 排什么坑 |
|---|---|---|---|
| Flutter / Riverpod | `Selector` 的 `shouldRebuild` 默认走 `==`（类可重载 `operator ==`）；`ValueNotifier` 比 `==` | **值相等是门控的默认语义**，不是每个订阅点手写 | 不能照搬 PHP `==`：标量有隐式转换（`'1' == 1`），必须**显式 opt-in** 且只对对象启用 |
| Kotlin data class / Compose | 自动生成 `equals()`；`derivedStateOf` 用值相等跳过无效重组 | 把「值相等」做成**类型契约** | 自动生成在本仓 AOT 下无 codegen 扩展点（Drift3 `09 C-42` 同坑）⇒ 只能显式接口 |
| SwiftUI `@Observable` / Vue `ref` | 读即订阅、写入自动通知 | **不借**（§2 已判死） | 本仓 AOT 无 build 栈；`ARCH-REVIEW` 判「不可观测」 |
| MobX `runInAction` / Compose Snapshot | 批量写合成一次通知 | 解释了为什么「property hooks 自动 notify」不能单独上：多字段写会**撕裂读** | 本棒不碰写侧，从根上避开 |
| 本仓 `Versioned` | `src/Core/Computed.php:96` `$dep->version()` 是 `list<Versioned>` 上的接口方法调用 | **接口方法分派在 AOT 已有先例**，不是新形状 | 仍需 `use` 完整（本仓有 `instanceof-missing-use` 规则） |

⇒ 借鉴结论：**显式类型契约 + 默认正确 + 可选覆盖**，与 Flutter/Kotlin 同形；
明确排掉 PHP `==` 松散比较、codegen、读即订阅、自动广播四条。

## 4. 候选方案与四维比较

| 候选 | 结构 | 实现 / AOT | 扩展性 | DX | 维护性 | 裁决 |
|---|---|---|---|---|---|---|
| **A. 应用层 helper**（`Watch::field(...)` 之类，§10.5 原案） | 多一层间接，语义不变 | 零风险 | 只服务 watermark | 多一个要记的 API，且仍要写 `->equals()` | 与 `SubjectBuilder` 两套写法 | ❌ **否**：萃取出的重复只有「三行链式」这一形状，`FlowPolicy` 判死条件（决策点 < 4 不建类）同样命中；且按 `13 §3` 口径「多记一条规则却不给判据」不算 DX 改进 |
| **B. 标记接口 + PHP `==`** | 零方法签名改动 | 无接口方法分派，AOT 最稳 | 任何类 opt-in | 默认正确 | `==` 语义由属性结构决定，嵌套对象/浮点易与手写 `equals()` 分歧 | ❌ 否：隐式、不可读，且与成熟框架的显式 `equals` 相反 |
| **C. `Equatable` 接口 + 运行时 fallback** | 把「值相等」从约定变类型契约；门控策略仍归 `SubjectBuilder` 一处 | 接口方法调用有 `Versioned` 先例；需 T1/T2 复核 | 任何值对象 opt-in 即正确；显式 `equals()` 仍可覆盖 | **默认正确**，删掉手写闭包 | 一个机制一处落点 | ✅ **选** |
| **D. 把默认门控整体改成 `==`** | 一行改动 | 零 AOT 风险 | — | 默认正确 | 标量隐式转换是新的静默坑；会推翻 `SubjectBuilderTest:107-124` 的硬判据 | ❌ 否 |
| **E. property hooks 自动 notify**（`SignupForm` 已验机制） | 写侧自动化 | 16 个 hook ≈ 64 行 > 省下 29 行 | 需配套 batch/snapshot 才不撕裂 | 消除「忘记 notify」 | 新增框架事务原语 | ❌ 否：净收益为负，登记为「若将来出现第二个类似 Store 再重裁」 |

**判死条件（先写，动手后逐条验）**：
1. 任何**未 opt-in** 的现有订阅者行为变化 ⇒ 立刻回滚（`SubjectBuilderTest::testEqualsGatesValueObjects()` ① 必须保持绿）。
2. T2 真机出现接口分派 Fatal / 渲染差异 ⇒ 回退到候选 B 或保持现状。
3. `SelectPurity` 修完若全仓报出 > 3 个需要改业务的 error ⇒ 拆成独立一棒，不混在本棒硬改。
4. 若 `Equatable` 最终只有 1 个实现且无第二个 opt-in 需求 ⇒ 仍保留（机制消费者是 15 个 select 点，不是实现类数量），但**不批量给全仓 20+ 个有 `equals()` 的类加 implements**（A15 零消费者）。

## 5. 最终实施范围（三件）

1. **框架**：新增 `src/State/Equatable.php`（`@api`，`equals(Equatable $other): bool`）；
   `src/State/SubjectBuilder.php` 的 `SubjectBuilderState::initState()` 加一条 fallback：
   未给显式 `equals()` 且新旧值都 `instanceof Equatable` ⇒ 用 `$value->equals($this->selected)`，
   否则仍 `===`。显式 `equals()` 优先级不变；`null → 对象` 退回 `===`。
2. **应用**：`apps/watermark/src/Store/StatusMessage.php` 加 `implements Equatable`
   （`equals()` 收 `Equatable` + `instanceof self` 守卫，class 声明不动）；删
   `apps/watermark/src/Widgets/StatusBar.php` 的显式 `->equals(...)` 闭包；
   `UploadCard::message()` / `TaskCard::taskMessage()` 与 `ResultCard.php` **一行不改**，
   前两处自动获得正确门控，后者由 §5.3 的判据修正放行。
3. **判据**：
   - `tools/Lint/Rules/SelectPurity.php`：`isClosure()` 支持 `static fn` / `static function` 前缀；
     判据按「`===` 是否有稳定值语义」重述 —— `array` 显式放行（常量只留这一项，
     「不该响」由 `SelectFixture::staticShape()` 的 Good 形态钉住）、nullable 只判底层类型、
     `mixed` 归入「仓库外类型不判」的既有诚实边界、类类型额外允许 `Equatable` 实现者
     与 `IDENTITY_STABLE` 例外登记（`Drift\Async\AsyncValue`：不可变 + 同值复用同一实例）；
   - `tools/Lint/fixtures/apps/SelectFixture.php` 补 `static fn` 的 Bad/Good 夹具，
     `tools/selftest.php` 更新 `select-purity` 期望条数；
   - `tests/Unit/State/SubjectBuilderTest.php` 补 3 用例：自动值门控生效 / 显式 `equals()` 仍优先 /
     非 `Equatable` 仍 `===`（守住判死条件 1）；
   - `tests/Feature/Watermark/` 补一条「三处 `StatusMessage` 切片在内容不变的通知下不重建」
     的外层判据（量可观察的重建次数，不与实现同源）；
   - 变异至少 5 条真红：删 `Equatable` fallback / 删 `StatusMessage` 的 `implements` /
     把 `equals()` 改成恒 true / 还原 `StatusBar` 显式闭包不算变异（属等价） /
     把 `isClosure` 的 `static` 分支去掉 / 把 `array` 放回黑名单。

**明确不做**：不动 `Subject` 广播端、不动 `select` 的读侧追踪、不改 `readonly class` 白名单、
不给全仓 20+ 值对象批量 `implements`、不建 helper 类、不引 Drift3 代码。

## 6. 验证分层

按 `docs/architecture/12-工程与工具.md` 的 T0/T1/T2：本棒动 `src/State/`（框架 PHP）
与 `tools/Lint/`，**未碰 C++ / 壳 / 跨进程** ⇒ T0（php -l / lint / selftest）+ T1
（feature / unit / golden / smoke）；框架新增接口方法分派属「新增类」，
按 `AGENTS.md:29` 需 T2（`drift-build` + 真机冒烟）复核一次。
全套 `php tools/gate.php` 18/18，`api-doc-check --write` 刷 `API-SURFACE.md`（新增 `@api` 面）。

## 7. 重裁触发条件

- 出现第二个「每次 `new` 的值对象」被 `select` 消费且不实现 `Equatable` ⇒ 考虑把
  `select-purity` 扩成「类类型必须 `implements Equatable` 或调用点显式 `equals()`」。
- 出现第二个多字段 Store 且手工 `notifyChanged()` ≥ 20 处 ⇒ 重开候选 E（property hooks +
  批量写事务），届时先解决撕裂读。
- `readonly class` 白名单与 `===` 身份比较的矛盾单独立项，不在本棒顺手改。

## 8. 落地纪要（§69）

按 §4 的判死条件逐条复核，**没有触发任何一条回滚**：

| 判死条件 | 结果 |
|---|---|
| ① 未 opt-in 的现有订阅者行为不变 | `SubjectBuilderTest::testEqualsGatesValueObjects()` ① 保持绿；`Value` 类仍每次标脏 |
| ② T2 接口分派 Fatal / 渲染差异 | `drift-build watermark --full` + 真机 5 判据 0 FAIL ⇒ 未触发 |
| ③ `SelectPurity` 修完报出 > 3 个需改业务的 error | 只报 1 个（`UsersPage` 的 `AsyncValue`），且是**规则误报** ⇒ 用 §24.3 例外登记处理，业务零改动 |
| ④ `Equatable` 只有 1 个实现 | 保留机制（消费者是 15 个 `select` 点，不是实现类数量）；**没有**给全仓 20+ 个有 `equals()` 的类批量 `implements` |

**两处动手后订正**（写在这里防止下一棒误读）：

1. §1.2 初稿把症状写成「每次 `notifyChanged()` 都重建」，实为「该字段被重新赋成
   同内容新实例时多重建一次」。`WatermarkStore` 里这种赋值 30+ 处（`none()` 同内容 4 次），
   仍然值得修，但不能把症状说大。
2. `VALUE_SEMANTICS` 初稿列了 `array/mixed/null/true/false`，其中后四项本就走
   「仓库外类型不判」的既有诚实边界，登记了也不会让判据多一颗牙 ⇒ 只留 `array`，
   「array 不该响」由 `SelectFixture::staticShape()` 的 Good 形态钉住。

**实际验证**：变异 7 条全红（含「array 判成无值语义」「例外登记删掉」两条判据级变异）；
gate 18/18（160.0s，unit 587 / 1811 断言、golden 整页 34/34、smoke 473、
lint 1048 文件 0/0、selftest `select-purity` 8/0、docref 130 ok / 0 FAIL）；
`API-SURFACE.md` 已刷（`@api` 502 → 503）。
