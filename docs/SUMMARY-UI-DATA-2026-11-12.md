# 结论记录 · UI 数据订阅十轮推演 · 2026-11-12

> 状态：**本主题唯一权威结论**。前 9 份推演稿（合计 6299 行）已移入
> `docs/_archive/ui-data-2026-11/`，只读、不作开工依据。
> 全程未改 `src/`、未跑 T1/T2；基线 HEAD `5f26dfc`。
> 本文只写结论与证据，不写推演过程；要追溯过程看归档稿。

---

## 0. 一句话结论

**现有 `SubjectBuilder::make($store)->select(fn)->builder(fn)` 是 PHP + AOT 约束下的局部最优。**
十轮推演里所有「改书写形状」的方案 —— 构建期生成 trait、运行期读追踪、
`$ctx->watch` 登记、分区视图模型、整套 Drift3 —— 全部不采纳。
真正该做的只剩两件与形状无关的防线修补。

---

## 1. 最终裁决（照此执行）

| 档 | 内容 | 裁决 |
|---|---|---|
| **A · 做** | ① **修 S1 判据假绿**：`SourceQuery` 新增 `ctorParams()` 一张表，`DeadSubscription` 合并「类体属性 + 构造器提升参数」两个来源；`readBody()` 排除提升参数是有意契约，不改。夹具补一个 `__construct(private readonly Cell $hovered)` 的 Bad 类，`$expected['dead-subscription']` 从 1 改 2，让洞常驻门禁 | 真缺陷、量小、收益确定 |
| **A · 做** | ② **表格线 `dead-field`**：`field('nmae')` 现在只在 `TableColumn::textOf()` 渲染期报；`apps/` 12 处 + `src/` 5 处 `->field(` 无构建期对账。做「`FIELDS` 常量 ↔ `field()` 字面量」两端静态对账，不依赖生成层 | 独立、不碰订阅形状 |
| **B · 可选** | 把「一致性敏感的多字段读点必须进同一个 `select`」写成一条窄纪律，**只覆盖已有的 7 处 `array` 切片**，不动单值切片。仓库已有 4 处正确先例 | 按需，不铺开 |
| **C · 不做** | 全仓 VM 化、`vm-key` / `vm-pure` 两条判据、7 文件迁移、生成 PHP 源码、运行期追踪、Context 登记、整套 Drift3 架构 | 净负或语言边界 |

★ 性能优化方向不是改形状：粗广播 `O(订阅者)` 与重建工作量都**不能**由编译器后置，
调用开销才可以。若将来量出粗广播是帧预算主因，走已登记的细粒度状态原语，
重启条件：Store 字段 > 20 且订阅者 > 20，或 T2 量出粗广播为主因。

---

## 2. 开发者怎么写（现状，照抄即可）

```php
SubjectBuilder::make($this->store)
    ->select(static fn(UserStore $s): bool => $s->page()->isRunning())
    ->builder(function (bool $running): Widget { /* ... */ });
```

`UsersPage` 类注释已写死的纪律：**页面里任何读 Store 的地方都必须在某个 `select` 之内，
否则它就是快照**。2026-10-31 的按钮永久灰事故正是这条被破，现已修好；
`dead-subscription` 是守它的防线，S1 只是这道防线自身对构造器属性提升假绿。

**何时允许把多个字段合成一个 `select`**（C3 原则，四个条件同时满足）：
同频变化、小子树、全标量、一致性敏感。正面先例 4 处 ——
`SignupPage` 的「值 + 该字段错误」两处、`ResultCard` 的「result + polling 事故修法」
与「urls + downloads」。**没有一处是把两个独立切片硬合并**。

反例（不要写成这样）：把 `query`（每字符变）和 `running`（每次刷新变）合成一个 VM。

---

## 3. 十轮推演脉络

| 阶段 | 产出稿 | 当时结论 | 最终裁决 |
|---|---|---|---|
| 诊断 | `REVIEW-UI-DATA-2026-11-11.md` | 不缺隔离，缺数据契约；成熟框架六坑 | 保留为坑的索引 |
| 诊断 | `REVIEW-UI-DATA-CONTRACT-2026-11-11.md` | 方向对、形状重；AOT 收益主张站不住 | 结论被撤，实证基础保留 |
| 方案一 | `DESIGN-UI-DATA-CONTRACT-2026-11-11.md` | 「三本名册」 | 作废：能被扫描的不登记 |
| 方案二 | `DESIGN-UI-DATA-DX-FIRST-2026-11-11.md` | DX 优先 + 构建期生成订阅代码 | 被取代 |
| 施工审查 | `REVIEW-UI-DATA-DX-FIRST-2026-11-12.md` | 5 条阻断（对象行 / `readonly` 无值相等 / codegen 接线 / 订阅载体 / 判据同源） | 12 条处置已并入后续稿 |
| 方案三 | `DESIGN-UI-DATA-FINAL-2026-11-12.md` | `ViewHost` + 生成 trait，可实施版 | **用户否决**（语言边界） |
| 换赛道 | `ANALYSIS-UI-DATA-ALTERNATIVES-2026-11-12.md` | 四族方案空间、视图模型切片 | 部分保留（§5 的实测结论） |
| 交叉核对 | `ANALYSIS-ARCH-DRIFT3-VS-2026-11-12.md` | 加回「兼容性可破坏」仍不翻案 | 三层评级与三档清单保留 |
| 收口 | `PLAN-UI-DATA-RECOMMENDED-2026-11-12.md` | 分区全标量 VM，自判「整包不值得推进」 | **最终口径**，A/B/C 拆档 |

九轮得出「现状是局部最优」，第十轮的价值是**用逐项对比把自己上一轮的过度设计判死**，
而不是又造一个方案。

---

## 4. 被否决的方案与决定性理由（防回魂）

| 族 | 代表框架 | 一句话否决理由 |
|---|---|---|
| ① 生成订阅代码（trait） | Svelte / WinUI `x:Bind` | PHP 的 trait 只能在类定义处静态 `use`，没有编译单元接管、没有运行期注入（`class_alias` / `__call` 都不行）⇒ 写 `use XxxView;` 时生成物必然还不存在，`php -l` / lint / IDE 先红。**这是语言能力边界，不是设计选择**，以后不要再提 |
| ② 运行期读追踪 | Compose / MobX / SolidJS / Vue 3 | 追踪窗口外读不追踪、事件回调要 `untrack`；SolidJS 组件只跑一次，本仓 Widget 每帧 `new`，没有常驻 scope；`StatelessElement::rebuild()` 没有订阅窗口 |
| ③ `$ctx->watch` 登记 | Flutter `InheritedWidget.of()` / React Context | 要推翻 §18③ 与 A4「防 build 期订阅」；`BuildContext` 是 `final readonly`、构造点散在 10 处；React 到 19 仍无官方 context selector，而搜索框是每字符高频 |
| ④ 分区全标量视图模型 | Elm / React-Redux / 本仓现状 | 五条判死理由见下；核心是**性能净负**且收益只值「每次广播少一次 `select` 求值」 |
| ⑤ 整套 Drift3 | Drift3 规格 | 规格态（六道闸门 0/6、真机 NOT RUN、git 零提交、`buildInto()` 是未追认工作假设）；渲染范式不同；其一部分刚性是旧 tpc 0.10.0 的 workaround，照搬等于把本仓已解禁能力重新锁回去 |

**④ 的五条判死理由**（与现状逐项对比的实测结果）：

1. **事故已不在**：`apps/*/src/{Pages,Widgets}` 只剩 1 处裸读 `this->store->`，还是事故复盘注释。
   修 S1 恢复防线即可，不需要改书写形状。
2. **74% 是套壳**：apps 真订阅 23 处，返回 `array` 的仅 7 处（其中 3 处本就是 `visible()` /
   `selected()` / `uploadedKeys()` 这类天然数组），其余 16 处是单值
   （`string` 7 / `bool` 4 / `StatusMessage` 3 / `float` / `AsyncValue` / `mixed` 各 1）。
   全仓 VM 化 = 把单值形参换成单键数组，零收益。`StatusMessage` 实现 `Equatable`，单值对象切片本就稳定。
3. **违反自己的合并原则**：`query` 每字符变、`running` 每次刷新变，两者频率差一个量级却共用一次广播。
   合成后每敲一个字符都要重算 `isRunning()` 并重建整行（约 7 个 widget）；
   现状 `running` 的 `===` 门控直接挡住、只重建 `TextField`。
   `Element::reuseChild()` 恒真 ⇒ 这是真重建工作量，不是编译器能补的调用开销。
4. **两条判据都不成立**：`vm-key` 防的「字符串键拼错」是 VM 形状自己引入的（现状用形参，IDE 可跳转/重命名）；
   `vm-pure` 扫 `builder` 区间，但事故读点在 `build()` 顶层 ⇒ 守错层。
5. **成本 > 收益**：2 条 lint + 3 夹具 + 一张属性表 + 7 文件迁移 + tutorial，换一次 `select` 求值。

**「兼容性可破坏」不能翻案**：只免掉「迁移旧调用点」，另有四条与兼容性无关的成本
（PHP 语言边界 / 渲染范式 / 验证重做 / 状态原语），主要成本在验证而不在迁移。

---

## 5. 保留的实测结论（十轮推演的净收益）

- **S1 是唯一真缺陷**：`SourceQuery::readBody()` 命中 function 就整块跳过方法体，
  构造器属性提升一个都不收；最小仓库对照：提升写法 `dead-subscription` 0 条 / 传统写法 1 条。
  `readBody()` 的排除是**有意契约**，所以修法是新增 `ctorParams()` 而不是改它。
- **夹具漏等价书写形态**：现有 `DeadSubscriptionFixture` 两个类都用传统属性声明，
  正向测试一条都抓不到 ⇒ 25 项照样全绿。修 S1 必须同时补提升写法的 Bad 类，
  让洞常驻门禁（这是同族教训的第二次：第一次是规则改了夹具没同步，这次是等价形态漏了）。
- **PHP 数组 `===` 实测**：纯标量同值 `true` / 含每次 `new` 的对象 `false` /
  含同一实例 `true` / 键序不同 `false`。⇒ 只要 VM 全标量，现有 `sameValue()` 的 `===`
  就是正确值门控，**框架零改动**；`sameList()` 是多余的，且与 `SelectPurity`
  「array 判稳定值语义并放行」的既有契约冲突。`sameContent()` 明确排除数组，
  所以「全标量」不能只靠诊断兜底。
- **`AsyncValue` 必须走 `fold()`**：类注释明令不要拿 `stateName()` 做分支；
  `AsyncValue` 不实现 `Equatable`，塞进数组会白重建 ⇒ 四态必须保留对象本身。
- **口径纠正**：`SubjectBuilder::make` 在 apps 是 23 处 / 7 个文件，showcase 30+ 页面零订阅；
  `select 57` 的口径含非订阅入口，不能拿来当迁移面。迁移单位是「订阅宿主类」不是「页面」。
- **量具不能冒充**：`frame-tax-probe --check` 判的是合成静止树的固定税，
  `probe-frame-gates.php` 给真机门控计数，**都答不了「输入一个字符重建多少节点」**，
  要量就得临时加一次性 instrumentation。
- **跨仓引用必须核现状**：Drift3 的 `dep-table` 真实消费者是 CI 结构漂移检测，
  失效路由只是工程建议、未接线；其 tpc 0.10.0 读数与本仓 `AOT-CONSTRAINTS` 台账
  已解禁的能力不可直接外推（但「interface 多实现注册表仍回退动态分派」仍成立）。
  `CommandRouter` 的 `default => false` 有 DIAGNOSTIC + `dead-command` 兜底，不是静默失败。
- **两边各自自洽**：本仓 `UserStore` 粗广播是有意设计 ⇒ 不需要依赖表、代价 `O(订阅者)`；
  Drift3 细粒度 ⇒ 必须有依赖表，而它没落地。粗广播与依赖表是同一枚硬币的两面。

---

## 6. 文档处置

**主目录可以删，且已经清空**：9 份推演稿全部是未提交的过程稿，顶部各自标着
「已被取代 / 保留为证据链 / 不再作为开工依据」，最终裁决是整包不采纳。
让它们并排留在 `docs/` 根部，只会重现仓库索引里点名的「多套并行、无防漂移」风险。

| 文件 | 行数 | 角色 | 去向 |
|---|---|---|---|
| `REVIEW-UI-DATA-2026-11-11.md` | 784 | 现状评审、六坑索引 | 归档 |
| `REVIEW-UI-DATA-CONTRACT-2026-11-11.md` | 880 | 四轮推演、AOT 台账核对 | 归档 |
| `DESIGN-UI-DATA-CONTRACT-2026-11-11.md` | 629 | 三本名册（已作废） | 归档 |
| `DESIGN-UI-DATA-DX-FIRST-2026-11-11.md` | 1295 | v1 + v2.1（被取代） | 归档 |
| `REVIEW-UI-DATA-DX-FIRST-2026-11-12.md` | 392 | v2 评审 12 条处置 | 归档 |
| `DESIGN-UI-DATA-FINAL-2026-11-12.md` | 1101 | `ViewHost` + trait（已否决） | 归档 |
| `ANALYSIS-UI-DATA-ALTERNATIVES-2026-11-12.md` | 437 | 四族方案空间、7 处 array 先例 | 归档 |
| `ANALYSIS-ARCH-DRIFT3-VS-2026-11-12.md` | 311 | 跨仓三层评级、三档清单 | 归档 |
| `PLAN-UI-DATA-RECOMMENDED-2026-11-12.md` | 470 | 最终 A/B/C 拆档裁决 | 归档 |
| **合计** | **6299** | | `docs/_archive/ui-data-2026-11/` |

**为什么是归档而不是物理删除**：

1. 这 9 份**从未提交**，物理删除后 git 里连回溯点都没有，6299 行实测数据一次归零；
2. 仓库索引的既定惯例是「归档 ≠ 删除」，历史裁决稿都放 `docs/_archive/` 只读不更新；
3. `docref` 明确排除 `docs/_archive/`（受检文档与裸名索引都不收），
   归档后这批稿子之间的取代链引用不会在主目录留下断链告警；
4. 结论已全部收进本文，日常开发只看这一份即可，归档不干扰阅读。

若确认连回溯都不需要，可一次性物理清掉（不可逆）：

```bash
rm -rf docs/_archive/ui-data-2026-11/
```

`docs/AOT-PERF-ATTRIBUTION-2026-11-12.md` 是另一个主题（AOT 性能归因），
不属于这批推演，保留在主目录不动。

---

## 7. 验证记录

| 项 | 结果 |
|---|---|
| `php tools/docref.php` | 116 ok / 0 FAIL（顺手修掉 `AOT-PERF-ATTRIBUTION` 一处 `project-name` 自称错误） |
| `gate --fast` | 25 项全过 |
| `git diff --check` | clean |
| `src/` 改动 | 无；未跑 T1/T2 |

---

## 8. 防回魂清单

以后再有人提「统一视图模型 / 合并切片 / 自动生成订阅表」，先问三件事：

1. **事故形态还在吗**？`build()` 顶层裸读 Store 全仓还剩几处（本次是 1 处注释）。
2. **单值切片占比多少**？本次 23 处里 16 处是单值，套数组壳零收益。
3. **要合并的字段是否同频**？不同频合并 = 每字符重建整行，性能净负。

外加一条硬边界：**PHP 没有编译单元接管，构建期生成 PHP trait 不要再提**。
