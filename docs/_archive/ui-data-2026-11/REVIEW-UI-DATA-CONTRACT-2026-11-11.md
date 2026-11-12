# 分析 · UI 与业务数据契约设计稿 · 2026-11-11

> ★ **本文是四轮推演记录**（§8–§11 的裁决依据与 AOT 台账核对仍有效）。
> **结论以 `docs/DESIGN-UI-DATA-DX-FIRST-2026-11-11.md` §11（v2）为准**；开工别按
> 本文的 S0–S3 / D0–D4 顺序。v2 允许破坏兼容性，§10「这一轮不该定形状」的裁决
> 已撤回；但 §8 的 dep-path 探针与 §10.3 的 Element 窗口更正仍是 v2 的实证基础。
> 状态：**只读分析**，未改任何代码 / 配置。基线 HEAD `5f26dfc`。
> 对象：`docs/REVIEW-UI-DATA-2026-11-11.md`、`docs/DESIGN-UI-DATA-CONTRACT-2026-11-11.md`。
> 五个维度：结构 / 实现 / 扩展性 / 开发者体验 / 维护性；裁决不确定处对照成熟框架。
> 本文只回答一个问题：**「三本名册」值不值得按现方案全量铺开，有没有更省的等价形状。**

---

## 0. 结论先行

> **方向对，形状重了，而且有一条收益主张站不住。**

「本仓缺的是数据契约」这个判断成立，设计稿把闭包换成可枚举名册、用「漏一个会响」替代口传纪律，也成立。但按现方案 P0a → P5 六阶段全量铺开之前，有三件事要先改：

1. **撤下「AOT 可直调」这条收益**。`docs/AOT-CONSTRAINTS.md` 的现行台账不支持「用名册换闭包能变快」，理由见第 1 节。方案的价值应只定位在**静态可见 + 可机检**。
2. **把 `Projection` 的 enum 名册从默认降为可选**，默认走 `string` 常量 + 类常量表。这不是「实验失败后的退路」，而是**更简单且收益确定的首选**。
3. **先只做三条 lint 并观察一个迭代**，用真实发现数决定要不要引入三个新契约类型。判死条件见第 5 节。

一句话：**让已有的名字静态可见，比另造一份平行名册便宜。**

> **22:1x 第二推演（§8）把这条再收紧一层**：名字其实连「常量表」都不用写 ——
> 它就在 `select` 闭包体里。实测 37 处订阅闭包有 36 处能静态反推出读路径，
> 业务代码 24 处里只有 1 处是真盲区。§8 给出零新增书写物的形状、判据同源漏洞，
> 以及修正后的推进顺序；本文 §1–§7 的结论全部保留。
>
> **22:3x 第三推演（§9）换了排序判据**：按「开发者体验优先、性能交给后续编译器」
> 重排。结论是**该拿掉一半** —— 只后置「调用开销」，不后置「信息密度」；
> 且这个视角会翻案评审稿否掉的自动追踪（C2），门槛从三条降到一条。
>
> **22:4x 第四推演（§10）把 DX 从「一条判据」提为「唯一目标」重推，结论不一样**：
> 不是哪个形状更优，而是**这一轮不该定形状**。用开发者「写完一行多久知道对错」的
> 视角看，第一交付物是错误信息与可抄样板，不是新的表达方式；C2 的翻案撤回，
> §9.2「现在要留优化素材」翻转成「现在别为将来锁形状」。另更正 §9.5 一处事实前提：
> `enterBuild()` 不是订阅窗口，`StatelessElement::rebuild()` 也没有窗口 —— 而事故页
> 正是 `StatelessWidget`。**涉及 C2 落地判断时以 §10.3 为准。**
>
> **22:5x 补 §11**：给出「原状 → 两个被否候选 → 最新方案」的逐段代码对照与
> 问题对照表。最新方案的页面代码**逐字不变**，变的是「写错时看到什么」。

---

## 1. 新事实：现行 AOT 台账不支持「名册换闭包」的性能理由

设计稿第 11 节把三本名册的共同性质写成「可枚举 + 静态可见 + **AOT 可直调** + 漏一个会响」。第三条在今天的台账下不成立。

### 1.1 形状级成本：接口虚调用和闭包一样慢

`docs/AOT-CONSTRAINTS.md` 的 13 级阶梯（`bin` 口径，AOT / 解释器耗时比）：

| 阶梯 | 形状 | 比值 | 读法 |
|---|---|---|---|
| L06 | readonly 值对象分配 | **1.79** | `Projected` 每次换值都要分配 |
| L09 | 接口方法（双实现） | **1.96** | `Projectable` / `Projection` 那一跳 |
| L10 | 闭包创建 + 调用 | **2.04** | 现在的 `select(fn)` |
| L04 | string 键 hash | 1.25 | `projCache[$key]` 查表 |
| L08 | 同类 final 方法 | 0.30（快 3.3×） | 真正变快的只有这一类 |

L09 与 L10 只差约 4%。而新方案在替换掉闭包之后，还额外引入 `Projected` 对象分配、`projCache` 字符串查表、`match` 分派、`version()` 调用。**净性能收益大概率不是正数**。

同仓既有 V-3 判据也写着：`SubjectBuilder` 的 `select` 闭包每次 `notifyChanged()` 跑一次，且构造时已经缓存 —— 也就是说，「闭包贵」这件事早就被处理过一轮，不是待解决的痛点。

### 1.2 去虚化口径：接口形参恰好是回退路径

`docs/AOT-CONSTRAINTS.md` 第 9 节关于上游 #184 的记录：

- `interface` 特化**只对同文件单实现者**生效，且必须带 `instanceof` 守卫；
- 「不可访问声明 / 子类覆盖 / `__call` / **interface contract 那一跳**」会回退动态分派；
- 官方 Issue 场景 4 就是「单实现 interface 形参 ⇒ `typephp_call_method_cached`」。

设计稿的 `projection(Projection $p): Projected` 有两个叠加的不利条件：`Projection` 由**多个** enum 实现（`UserProjection` 等），调用又走 `Projectable` 接口。两者都不满足「同文件单实现者」。因此 P1 前置实验不只是「没测过」，而是**先验概率偏低**。

### 1.3 即使实验通过，字段值仍不是静态的

设计稿第 12 节已经诚实承认：`Projected::$value` 是 `mixed`，AOT 下仍是 `php::Var`；`Projectable` 多实现时去虚化同样失效。所以「AOT 可直调」最多只覆盖「少一次闭包构造 + 一次分派」，覆盖不了值本身。

**裁决**：把第 11 节台账里的「AOT 可直调」改成「**AOT 收益未经证实，只主张静态可见**」。P2 的 `bin --dry` A/B 判据保留，但它变成**验证「没有变慢」**，不再是「证明变快」。

---

## 2. 五维评估

| 维度 | 判断 | 主要风险 | 建议 |
|---|---|---|---|
| 结构 | 「读 / 行 / 写三通道各自有形状」是对的抽象分层 | 三本名册各带一份**平行真相**：case ↔ Store 字段、`RowSpec::columns()` ↔ `TableColumn`、case ↔ handler 表 | 保留分层，减少名册数量；已有对账器能守，但对账器本身也是新维护面 |
| 实现 | 六阶段、零 API 破坏、灰度 warn，工程上稳 | `reactive-read` 的词法作用域判定是最贵的一条，且设计稿已承认有绕过路径（读包一层普通方法就看不见） | P0a 先落地三条便宜的；`reactive-read` 单独观察 |
| 扩展性 | 加一个 Store 切片能靠 `match` 无 `default` 报漏 | 但「无 default」是**运行期** `UnhandledMatchError`，不是编译期；编译期保证只能来自 lint，而 lint 必须进 `gate` | 把「漏一个会响」的实现责任明确交给 lint，别写成类型系统的功劳 |
| 开发者体验 | **这是当前方案最弱的一维** | 现在加一个读切片 = 写 1 处 `select(fn)`；新方案 = enum 加 case + `projection()` 加 `match` 分支 + 可能的 `TableContract::assert()` + lint 白名单 ≈ 3–4 处 | 量化样板税；`string` 常量形状可压回 2 处 |
| 维护性 | 「代码降级成数据」便于静态对账 | 新增 4 条 lint + 3 个契约类型 + 挂载期断言，都是长期要养的东西；收益（防漏）能否覆盖养护成本，未经实证 | 用观察期证伪：一个迭代内 lint 零发现 ⇒ 不引入三个新类型 |

**DX 这一维值得单独说**：设计稿把「名册」写成架构优势，但对照成熟框架，它更像**没有编译期依赖推导时的补偿机制**。本仓没有 dep-table（第 8 节已确认 §19.2 裁决不动），Compose 那种「读 `State<T>` 自动记录依赖」做不到，才需要显式名字。这个定位要写进文档，否则后来者会误以为名册是先进设计而继续加第四本、第五本。

---

## 3. 成熟框架对照：它们的共同做法不是「另造名册」

| 框架 | 怎么保证「读点被覆盖 / 名字不拼错」 | 对本仓的启示 |
|---|---|---|
| **WPF** | 早期 `INotifyPropertyChanged` 用 `"Name"` 字符串，后来 `nameof()` 让它变编译期可见 | **与「`string` 常量 + 类常量表」几乎同构**；演化方向是把字符串变静态，不是引入 enum 名册 |
| **Qt** | 旧 `SIGNAL()`/`SLOT()` 字符串宏，新语法改用函数指针（编译期检查） | 同上：让已有名字静态可见，零新增平行真相 |
| **Redux / React** | selector 是具名函数；漏依赖靠 ESLint `exhaustive-deps` 守 | **lint 守、不靠名册**的先例，与 P0a 思路一致；`reactive-read` 就是本仓的 `exhaustive-deps` |
| **Jetpack Compose** | `SnapshotStateObserver` 自动追踪 `State<T>` 读取，`derivedStateOf` 自动缓存 | 名册在这里**不需要存在**，因为运行时替你追踪。本仓做不到，故只能显式；别把这个差异包装成优势 |
| **Elm** | 消息是 union type（类似 enum），`Html.map` 显式映射 | 唯一支持「名册」的先例，但消息本来就是代数数据类型，**不是额外引入的第三份真相** |

**共同结论**：成熟框架几乎都选择「让已有的名字静态可见」（`nameof`、函数指针、lint、自动追踪），只有当名字本来就是数据类型（Elm）时才用 enum。本仓三个通道里，读切片名和写命令名本质上是**字符串标识**，不是领域数据类型 ⇒ 用 string 常量更贴近这些先例。

`RowSpec` 是例外：它承载的是**行结构**（列名 → 文本、行 → 身份），确实是数据类型，具名类合理。所以三本名册不该一刀切降级 —— **读 / 写两本降级，`RowSpec` 保留**。

---

## 4. 更优形状：string 常量 + lint 作默认

设计稿第 9 节把「`string` 常量 + 类常量表」写成 P1 前置实验**失败后的退路**。按第 1、3 节的证据，应当把它提为**首选**，enum 名册降为「实验通过才考虑的增强」。

设计草案（未编译，签名对齐现有类）：

```php
final class UserProjection
{
    public const QUERY   = 'query';
    public const VISIBLE = 'visible';
    public const PAGE    = 'page';
    public const RUNNING = 'running';

    /** @return list<string> lint 用：名册与 match 分支必须集合相等 */
    public static function cases(): array
    {
        return [self::QUERY, self::VISIBLE, self::PAGE, self::RUNNING];
    }
}

// SubjectBuilder::project() 收 string；Store 侧 match 用类常量
SubjectBuilder::make($this->store)->project(UserProjection::RUNNING);
```

**省掉了什么**：`Projection` 标记接口、`Projectable` 接口的参数约束、enum 实现接口在 `bin` 口径下的去虚化疑问、`$p->value` 在接口静态类型上的属性查表风险。

**判据层不受影响**：`projection-cases` 原本就是「enum cases ↔ `match` 分支」的集合比较，换成 `cases(): list<string>` ↔ 分支后更简单（两边都是字符串标量）。`command-cases` 同理。`reactive-read` / `write-in-ui` 完全不动。

**代价（诚实写）**：拼错不再被类型系统拦住，只靠 lint；`projection-cases` 因此从「锦上添花」变成**必须进 `gate`**，否则等于没有防线。另外 `match` 无 `default` 仍是运行期兜底，别写成编译期保证。

**保留 `RowSpec`**：第 3 节已说明它是真数据类型，P2 继续按原方案走（含 `Table::of()` 三参数、`TableContract::assert()` 每次 build 调）。

---

## 5. 推进顺序重排：判据先行 + 一个观察期

原方案六阶段全量铺开，成本高、收益未经实证。改成三段，每段带判死条件：

| 段 | 内容 | 判死条件 / 出口 |
|---|---|---|
| **S1（推荐先做）** | P0a 三条 lint：`projection-cases` / `command-cases` / `write-in-ui`，各配变异夹具，**全部进 `gate`**；`reactive-read` 单独按 `--warn` 灰度 | 三条 lint 必须各有「故意漏记 ⇒ 红、正例 ⇒ 绿」的真跑记录。做不到就不许往下走 |
| **S2（观察期）** | 不改 API，只让 lint 跑一个完整迭代（至少一次全量 gate + 一次真机 / 业务页改动） | **若 `write-in-ui` / `projection-cases` 零发现**：说明「漏一个」不是活跃缺陷 ⇒ **只保留 lint，不引入 `Projection` / `CommandName` 两个新类型**，方案收敛为「四条 lint + `RowSpec`」。若有发现 ⇒ 才进 S3 |
| **S3（条件触发）** | `RowSpec` + P2/P3；`Projection` 只在 P1 前置实验证明 `bin` 下真去虚化、且 S2 有名册需求时才上 | P2 的 `bin --dry` A/B 判据改为「**没有变慢**」，`--full` 重建 + `probe-window` 5/5 |

**为什么加观察期**：这套方案卖的是「防漏」，而防漏类判据最容易变成「绿壳」—— 规则写了、没咬到过任何东西，却长期占着维护预算。项目里已有 `dead-subscription` 被「补一个空接口」洗绿的先例（设计稿第 7 节）。用一个迭代问「它真的会响吗」，比先铺三个类型再问便宜得多。

**P5 维持原裁决**：一个 API 都不删，`select()` / `cell()` / `onChanged(callable)` 永久留作逃生口，破坏性窗口为零。这一条设计稿第 12 节改对了，不要回退。

---

## 6. 设计稿需要回改的五处

| 位置 | 现状 | 建议 |
|---|---|---|
| 第 11 节结论台账 | 共同性质含「AOT 可直调」 | 改为「静态可见 + 可机检 + 漏一个会响」；AOT 收益列为**未证实** |
| 第 3 节形状 | `Projection` 接口 + enum 实现 + 接口形参 | 默认改 `string` 常量 + `cases()`；enum 名册降为可选增强 |
| 第 9 节迁移路径 | string 常量是「实验失败的退路」 | 提为首选；P1 前置实验从「必做」改为「上 enum 名册的准入条件」 |
| 第 9 节 P2 验收 | `typephp_call_method_cached` 归零（证明变快） | 改为「不劣于基线」；按第 1.1 节的阶梯，归零本身不现实 |
| 第 10 节失败模式 | 6 条 | 补两条：⑦ 三本名册各带一份平行真相 ⇒ 名册膨胀成第二份 Store API；⑧ lint 零发现却长期保留 ⇒ 绿壳维护税 |

第 12 节自审的 12 条修正（及早求值、4→3 层、不删 API、`match` 是运行期、AOT 口径收窄、`Table::of()` 三参数、不复用 `Computed` 版本语义）**全部成立，保留**。

---

## 7. 诚实边界

- 本文**没跑** P1 前置实验，也没跑任何 `bin --dry` A/B。第 1 节是拿既有 13 级阶梯做**形状级推断**，不是本方案的端到端实测；推断可能被真实产物推翻，所以第 5 节 S3 才留了 A/B 出口。
- 「L09 ≈ L10 ⇒ 换名册不划算」只在**调用形状可比**时成立；若 `Projected` 缓存让 `select` 闭包从「每次 notify 跑一次」降到「版本不变就不算」，那省的是**计算次数**而不是调用成本 —— 这部分收益本文未量化，仍归 P1 实验。
- 第 3 节的框架对照是设计取向的参照，不是基准测试；本仓无 dep-table 的结构差异已被显式标注。
- 本文不推翻设计稿的核心收益：**静态可见、可机检、零破坏性**。只主张把成本压到与收益相称。
- 未改 `src/`，按 T0 档；只跑了文档引用检查。

---

## 8. 第二推演：名册不是必需的，路径本来就在代码里（22:1x 补）

> 本节只回答一个新问题：**「让名字静态可见」是否还需要开发者另写一份名字。**
> 结论是不需要 —— 而且框架自己早就把这个承诺写进 API 契约，只是从未兑现。
> 与 §4（string 常量优先）、§5（观察期）冲突处以本节为准；§1 的 AOT 裁决不变。

### 8.1 触发点：一条写了但没兑现的契约

`SubjectBuilder::select()` 的 docblock 原文：

> 「闭包体内出现的属性集合 = 该订阅者**真正依赖的字段集**（框架编译期推导依赖的第一步，可 lint）」

框架从一开始就打算**从闭包体反推依赖**，而不是让开发者再登记一遍。现在 `Subject` 只有无 key 的 `notifyChanged()`（粗广播），推导结果没有运行时落点，所以这条承诺一直悬着。设计稿没有补它，而是另造 `Projection` 名册 —— 把读路径翻译成 enum case，再在 `projection()` 的 `match` 里翻译回同一行表达式：

```php
// 现状：依赖信息已经在代码里
->select(static fn(UserStore $s): bool => $s->page()->isRunning())

// 设计稿：把这段路径起个别名，再 match 回来
->project(UserProjection::Running)   //  case Running ⇒ $s->page()->isRunning()
```

★ **名册是路径的别名，不是新信息**。这一来一回没有增加编译器知道的东西，只增加了两处要同步维护的书写物。WPF `{Binding Nmae}` 拼错静默的坑，不会因为把字符串换成 enum case 就消失：case 名和 `match` 分支仍然由人对齐。

顺带暴露一个精度问题：上面真实的读点是 `page().isRunning()`，而 `UserProjection::Running` 只有一个名字。手写名册时把它登记成 `page` 还是 `running` 全凭记忆 —— 名册反而比原路径**更模糊**。

### 8.2 实测：业务代码 24 处，只有 1 处是真盲区

用 `token_get_all` 扫全仓 `->select(` 且首参为 `fn` / `function` 的闭包体，提取形参变量上的 `->name` / `->name()`，另记闭包体是否含派生计算（`self::`、独立函数、三元、`new`、捕获变量）。一次性探针在 `build/_dep_probe.php`（未入库，S0 时正式化）。

| 口径 | 数量 | 说明 |
|---|---|---|
| 全仓订阅闭包 | **37** | `apps` 24 / `tests` 13 |
| 纯路径（无任何派生计算） | 26（70.3%） | 直接反推 dep-path |
| 含派生计算但**仍能提出路径** | 10 | 其中 7 处是测试里 `new` 返回值，路径照抓 |
| **能提出至少一条 dep-path** | **36 / 37** | 97.3% |
| **真盲区（一条路径都提不出）** | **1** | `watermark` 的 `self::slice($s)` |
| 按业务代码分组 | `apps` **23 / 24** 可提取 | 真盲区 1 |

三条读法：

1. **「impure」≠「路径不可信」**。`new Value()`、三元、`instanceof` 只是让闭包不再是纯读，但形参上的 `$s->foo()` 照样在 token 里。把这两件事混成一个「可提取率 70%」会严重低估静态反推的覆盖面。
2. **唯一真盲区只有一种形状**：把形参整体交给另一个函数（`self::slice($s)`），读被埋进被调用函数。而 `slice()` 本体只有 `instanceof` + `$source->statusLine()` —— **一层跨函数展开就能覆盖**，不需要名册。
3. 当前探针只取链式调用的第一段（`$s->page()` 而非 `page.isRunning`），这是实现细节，但恰好印证 8.1：路径的完整信息在代码里，手写名册容易丢一半。

### 8.3 更省的形状：dep-path 作唯一概念，名册降为逃生口

**把设计稿的 80/20 反转**：不是「名册主推、闭包逃生」，而是「直接代码主推、名册只给真盲区逃生」。三个通道统一到同一个概念，开发者新增书写物为 0：

| 通道 | 名字从哪来 | 新增类型 / 书写物 |
|---|---|---|
| 读 | `select` 闭包体 → AST 反推 dep-path 集合 | **0** |
| 行 | `field('name')` / `rowKey('id')` 本来就是路径字符串 | 0（`RowSpec` 可留，它是行结构不是名册） |
| 写 | `command('users.refresh')` 已有 `dead-command` 双向对账 | 0 |

对账的两端变成「**AST 反推的读点** ↔ **另一处 AST 反推的消费点**」，不再需要谁手写一份声明。`§4` 的 string 常量 + `cases()` 因此还能再省一层：连常量表都不必写。

**现成零件（这是本节最实的一条）**：设计稿 `§7` 把 `reactive-read` 判为「最贵、最容易假阳，要做闭包体 span / 链式 / 箭头函数 / 嵌套闭包」，但解析器骨架已在仓里：

| 零件 | 已有能力 |
|---|---|
| `SelectPurity::isClosure()` / `closureReturnType()` | 认 `static fn`、切出闭包声明区间 |
| `SourceQuery::matchBracket()` | 括号配对（闭包体 span） |
| `SourceQuery::methodCalls()` | 指定区间内取 `->name()` 调用 |
| `DeadSubscription::subjectCalls()` | 按声明类型取 `build()` 里的 Subject 读 |
| `ClassTable::all()` | 全仓类 / 方法 / 属性事实表 |

⇒ `reactive-read` 不是「从零写词法作用域分析器」，而是**把已有解析器的输出从「有没有 / 类型对不对」换成「读到了哪些路径」**。工程量被高估了大约一个数量级。而且顺手修掉 `dead-subscription` 那个已知的假绿：判据从「类有没有 `implements SubscribingWidget`」换成「读点是否在 `select` 覆盖内」后，补空接口洗不绿 —— 这正是设计稿给 `reactive-read` 定的第 2 条变异用例，不用另造机制。

### 8.4 判据同源漏洞：按现方案会造出三份新的绿壳

设计稿 `§7` 的四条 lint 里，两条是**两端都手写**的集合比较：

- `projection-cases`：enum cases ↔ `match` 分支
- `command-cases`：enum cases ↔ handler 分支
- （`dead-field` / `projection-case` 若拿手写的 `FIELDS` 常量当真相源，同理）

**变异**：给 Store 加一个 `public function status2()`，名册、case、`match` 分支一处都不动 ⇒ 这些 lint 全绿，而新字段没有任何投影覆盖。判据检查的是「两份手写声明是否一致」，不是「声明是否覆盖了真相」。

这与本仓今年三次「判据全绿但能力是假的」同族：CQ-58（只给末段加 `code`）、M6（`substr_count` 数数漏判）、构建指纹漏 `.cc`（没人查输入清单全不全）。**共同点都是对账的一端不是独立事实源。**

修法只有一条：对账必须有一端来自 `ClassTable` / AST 这类**从代码反推、开发者无法手改**的事实。具体可加一条反向判据 `orphan-read-method`：`Store` 的公开读方法集合 ⊆ 所有 `select` / 投影覆盖的路径（或至少报 warn）。

**口径要收窄**：三个 Store 共 48 个 public 方法（`WatermarkStore` 34 / `UserStore` 8 / `AppStore` 6），其中混着写操作与内部辅助。拿全部 public 方法当真相源会全是假阳 ⇒ 必须按「返回非 `void` + 无写副作用」筛 getter，拿不准的降级 warn。**这条不收窄就别进 gate。**

### 8.5 影子记账：C2 的降级版，只体检、不改行为

静态反推的盲区是「间接读」，运行期记账能看见；但评审稿 §11.5 给 C2 的三条翻案条件里，最贵的是热路径开销，最险的是「静默不更新」。**把 C2 从「替代订阅机制」降成「开发期探测器」**，两条风险同时归零：

- 在 `dev` / 测试 harness 里，`build()` 期间给 `Subject` 的读打点；
- 只断言「本次读路径 ⊆ `select` 的 dep-path **上界**」，**不据此自动订阅**、不进 AOT 产物；
- 断言失败报「读点没被订阅覆盖」，行为完全不变。

静态与动态在这里是**互补而非重叠**，正好构成不同源双轨：

| | 看得见 | 看不见 |
|---|---|---|
| 静态反推 | 潜在读集**上界**（含所有条件分支） | 间接调用里的读 |
| 影子记账 | 本次实际读集（含间接调用） | 本次没走到的分支 |

★ 反直觉的一点：Compose `derivedStateOf` 那类「条件分支导致依赖没读全 ⇒ 永远不更新」的坑，静态反推天然免疫（它取的是上界），反而动态记账会踩。所以两者顺序应是**静态作 gate、动态作补充断言**，不是让动态取代静态。

变异牙检（证明两者不同源）：在 `self::slice()` 里加一行读 `$source->polling()`，而 `select` 不覆盖 ⇒ 静态反推抓不到、影子记账必须报红。做不到这条，就别把它算作判据。

### 8.6 修正后的推进顺序（覆盖 §5）

| 段 | 内容 | 判死条件 / 出口 |
|---|---|---|
| **S0（新增，必须先做）** | 把探针正式化成 dep-path 提取器（复用 `SelectPurity` 解析器）；产出「可提取率」+「未覆盖读点清单」；配一条变异（Store 加读方法但名册 / 分支不动 ⇒ 红） | 提取器必须能复现 8.2 的 36/37；做不到就不许往下走 |
| **S1** | `reactive-read` 改用 dep-path 对账；`projection-cases` / `command-cases` 若保留，必须接 `ClassTable` 事实源；`write-in-ui` 照旧 | 每条都有「故意漏记 ⇒ 红」的真跑记录 |
| **S2（观察期）** | 不改 API，让判据跑一个完整迭代 | 判死条件比 §5 更具体：**S0 未覆盖读点清单为空且 S1 零发现** ⇒ 只留判据 + `RowSpec`，不引入 `Projection` / `CommandName` |
| **S3（条件触发）** | `RowSpec` 保留（真数据类型）；名册只在可提取率显著下降、或出现 ≥3 个静态写不动的复杂投影时才上 | 此时名册才有实际价值：它服务的是那 1/24，不是 23/24 |

**P5 零破坏不变**：`select()` / `cell()` / `onChanged(callable)` 永久保留。`§1` 撤 AOT 收益、`§4` string 常量优先、`§5` 观察期、`§6` 五处回改全部保留 —— 本节只是把 §4 再省一层，并把 S0 插到 S1 前面。

### 8.7 诚实边界

- **粗广播不变**：dep-path 只做静态对账，不做细粒度订阅。`Subject::notifyChanged()` 无 key，所以设计稿 `§3.3` 的 `array<User>` 门控盲区、`§3.6` 的增量通知，仍要靠 `RowSpec` / 标量行解决 —— 本节不碰这两处。
- **「0 例裸读」只覆盖 token 可见形态**：`apps` 里 `builder(...use ($store))` 的 5 处捕获都是 `onPressed` 回调里的**写**操作，不是 build 期读；间接读没被本次扫描验证。
- 探针口径有限：只认 `->select(` 首参是 `fn` / `function`；链式只取第一段；`new` 一律记 impure（可能高估派生类）。数字是**静态扫描**，不是行为验证，也没跑 P1 前置实验或 `bin --dry` A/B。
- 8.5 的影子记账只是设计取向，**未落地、未量过任何开销**；它不进生产是前提，若将来有人把它挪进 AOT 路径，评审稿 §11.5 的三条翻案条件立刻重新生效。
- 本节未改 `src/`、未新增正式 lint、未碰 gate。依旧按 T0 档。

---

## 9. DX 优先的推演：把「可逆性」当第一判据（22:3x 补）

> 新前提：**性能后续编译器可能优化，无需修改代码即可享受** ⇒ 是否该把性能从形状裁决里拿掉？
> 结论：**该拿掉一半**。真正的分界不是「性能 vs DX」，而是
> **「将来改它，要不要动调用方代码」**。按这个判据重排后，
> 上一轮否掉的自动追踪（C2）会翻案，而 §8 的 dep-path 从「lint」升级为「安全网」。

### 9.1 这个前提在本仓已经发生过一次，不是假设

`docs/AOT-CONSTRAINTS.md` §9.4 记了 11-11 上游 #183/#184/#185/#186 那批复核：
**同一份 `src/`，只换编译器**，A/B 结果是

| 指标 | 修复前 | 修复后 |
|---|---:|---:|
| `typephp_call_method_cached` | 1593 | **1128（−29.2%）** |
| `php::Var` 出现次数 | 27381 | 24336（−11.1%） |
| 静态直调 | 12293 | 12663（+3.0%） |

当天的改动只落在 `docs/`，**`src/` 一行没动**。也就是说本仓刚亲历过一次
「去虚化收益白拿」：调用开销、动态分派、类型保留这三类，确实属于
「编译器升级 ⇒ 调用方零改动 ⇒ 自动享受」。

⇒ **§1 撤下「AOT 可直调」这条收益主张，在 DX 优先下更彻底：
AOT 快慢根本不该进形状裁决表。** 前几轮把 L09/L10 阶梯、接口形参回退路径拿来
论证「名册换闭包不划算」，其实是拿一个**可后置**的量去决定一个**不可逆**的形状。

### 9.2 但有三类「伪可逆」不能后置

「无需改代码即可享受」有一条隐含前提：**源码里得留着足够的信息给编译器**。
下面三类不满足，一旦押错就得改调用方代码：

| 类别 | 为什么不可逆 | 本仓实例 |
|---|---|---|
| **构建 / 语义硬约束** | 不是「慢」，是**编不过**或**数值静默偏** | `docs/AOT-CONSTRAINTS.md` §1.2：`Stmt_If` 直接构建失败、同一变量换类型赋值 Fatal、`int/int` 静默变整数除法、`switch` 仍按禁用、`match` 是否生成跳转表未测 |
| **类型信息擦除** | `mixed` / `callable` / 真实混合联合仍退化成 `php::Var`（§9.4 官方口径）。**这是源码声明的事实**，编译器无法凭空恢复 ⇒ 要变静态就得改声明 | 设计稿 §12.2 F4 自己承认 `Projected::$value: mixed` |
| **名字体系铺开后的迁移** | enum case / 字符串名一旦被 24 个页面引用，改名就是源码迁移；且名字与 `match` 分支要人工对齐 | 设计稿三名册、`dead-field` 的 `FIELDS` 常量 |

★ **正确表述不是「性能后置」，而是「只后置调用开销，不后置信息密度」。**
这句话反过来给 §8 一个更强的理由：`select(fn($s) => $s->page()->isRunning())`
保留了完整的类型化读路径，编译器将来有素材可优化；
`Projection::Running` 把它擦成一个名字，**连优化的素材都没了**。
⇒ 用户这个视角不但不推翻 §4/§8，反而把「别把代码降级成别名」从
「省样板」升级成「**保住未来的优化空间**」。

顺带修正评审稿 §12.1 的路线排序：R2（字符串字段名声明）把类型信息压成字符串，
R3（自动追踪 / 普通 getter 读）保留原生类型调用。**若性能可后置，
R2 的相对性价比下降，R3 反而该提前评估** —— R2 剩下的价值只是「名字可机检 / 列定义变一张表」，
不再有 AOT 论据加持。

### 9.3 第一判据：改它要不要动调用方代码

按可逆性给本方案的决策分层，而不是按「重要性」：

| 层 | 内容 | 改它要动调用方吗 | 裁决时点 |
|---|---|---|---|
| **不可逆** | API 形状、开发者要写几处、心智模型、名字体系 | **要** | **现在一次定对** |
| 半可逆 | 信息密度（形状自带，但可以靠"多留原始代码"保住） | 改形状才要 | 现在定，作为形状的约束 |
| **可逆** | 调用开销、去虚化、分配次数、缓存策略 | **不要** | 交给 tpc / 框架内部，后续白拿 |
| **不可后置** | 正确性可观测性（漏订阅会不会静默） | — | 现在就要有判据，与性能无关 |

⇒ 这条分层直接解释了为什么前几轮的论证顺序是拧的：
`§1`（性能不该用来否形状）→ `§4`（少写名字）→ `§8`（零新增书写物）
其实都是**不可逆层**的事；而 AOT 阶梯属于**可逆层**，本不该有否决权。

### 9.4 DX 量化：四个形状，改动处数差一个数量级

DX 优先得落到可数的东西上。以「页面新增一个读切片」为单位：

| 形状 | 要动几处 | 心智模型 | 名字体系 | 编译器将来有素材吗 |
|---|---:|---|---|---|
| 现状 `select(fn)` | **1** | 「只有 `select` 里读的才订阅」+ 相等门控 / 纯度纪律 | 0 | 有（闭包体是黑盒，但路径在） |
| 设计稿三名册 | **3–4** | case ↔ `match` ↔ `assert()` ↔ lint 白名单要同步 | 3 份 | **少**（压成别名） |
| §8 dep-path | **1（不变）** | 不变 | 0 | 有 |
| **C2 自动追踪 + 安全网** | **0** | **「读就是订阅」一句话** | 0 | **最多**（普通具名 getter） |

`apps` 24 处 select 里，`UsersPage` 一个页面就 4 处、`UploadCard` 5 处 ——
这是真实样板，不是假想。C2 的收益不是「少写几行」，是**把一条要背的规则变成不用记**。

★ 反直觉的一点：三个 Store 都是 `final class`，getter 是普通 `public function`（`UserStore`
8 个 / `WatermarkStore` 34 个）。tpc 对 `final` 具名方法是最吃的形状（阶梯 L08 比解释器快 3.3×），
对 `\Closure` 才是 `php::newClosureWithParameters` + 动态分派。
⇒ **C2 用普通方法读，在 AOT 口径下很可能比 `select(fn)` 更友好，而不是更差**。
前几轮「闭包是黑盒 ⇒ 要降级成数据」只说对了一半：黑盒的是**闭包**，
不是**方法调用**。这是把性能后置之后才看得见的选项。

### 9.5 翻案 C2：三条门槛降成一条

评审稿 §11.5 给 C2 的三条翻案条件，按 9.3 的分层逐条重裁：

| 原条件 | DX 优先下 | 理由 |
|---|---|---|
| ① ≥3 个真实页面「订阅形状复杂写不动」 | **改写成「样板税是否值得消除」⇒ 成立** | 24 处 select / 单页 4–5 处；「写不动」不该是门槛，「写得烦」才是 |
| ② 「故意漏记一个 key 必须报红」的变异牙检 | **保留并升级，不可后置** | 漏记导致**静默不更新**，是正确性缺陷，编译器优化救不了；设计稿 §12.2 F12 已承认静态 lint 有「读包一层方法」的绕过路径 |
| ③ 量过读记账热路径开销，不比 `select` 贵 | **删除 / 后置** | 正是「可后置的调用开销」，拿它卡一个不可逆的 API 形状是分层错位 |

于是形状收敛成（设计草案，不可编译）：

```php
// 页面作者：直接读，零 select、零名册、零常量表
Text::make($this->store->query())

// 框架：State::enterBuild() / leaveBuild() 已把 build() 窗口框好，
//       窗口内 Subject getter 的 touch(key) 记账 ⇒ 自动订阅，退出时写回 Element
// 判据：静态反推潜在读集上界 + dev 影子断言（本次读 ⊆ 上界）
// 性能：交给 tpc；Store 是 final，getter 是普通方法
```

**零件几乎全是现成的**：`StatefulElement::rebuild()` 已有 `enterBuild()` / `leaveBuild()`
包住 `build()`（评审稿把落点写在 `Pipeline`，这里更精确）；`Subject` 已有 `version()`；
§8 的 dep-path 提取器与影子记账也已设计完。

★ **角色反转**：§8 的 dep-path 原本是「替代名册的 lint」，在 C2 下变成
**自动追踪的安全网** —— 静态给「潜在读集上界」（天然免疫条件分支退订坑），
动态给「本次实际读集」（能抓间接调用），两者不同源，正好把 ② 那条牙检钉死。
上一轮那两个零件不用重做，只是换了个主人。

**必须写清的五条代价**（否则翻案就是纸上谈兵）：

1. **`Subject::notifyChanged()` 现在无 key、是粗广播** ⇒ 要按 key 通知，这是**框架语义变更**，
   不是纯内部优化；Store 作者得改通知调用点。建议 `notifyChanged(?string $key = null)`，
   不给 key 时退回全量（向后兼容）。
2. **Store 作者每 getter 一行 `touch('query')`**（`WatermarkStore` 34 个）⇒ 负担从页面作者
   转到 Store 作者。必须配一条「getter 数 == touch 数」的对账，且它也要有变异牙检。
3. **条件分支会退订本次没读到的字段**（正确但反直觉），Compose `derivedStateOf` 的坑；
   静态上界判据正是为此存在。
4. **不用 property hooks**：`src/` 零使用、AOT 未验（评审稿 D 族已否）；显式 `touch()` 绕开。
5. **调试不可见是 C2 的原罪**：Compose 靠 compiler metrics、Vue 靠 devtools、MobX 靠 `trace()`。
   本仓没有这些 ⇒ 影子断言与静态上界**不是加分项，是准入条件**。

成熟框架这边的共同答案也是「读即订阅」（Compose Snapshot / Vue / MobX / SolidJS Signals），
它们共同踩的坑正是「读发生在追踪窗口外 ⇒ 静默不更新 + 没有线索」。
React 反过来用显式依赖数组 + `exhaustive-deps` lint —— 本仓现状的 `select` 更像 React，
但 React 至少已经有那条 lint，本仓的还停在纸面上。
**别照抄 WinUI `x:Bind` 默认 `OneTime`**：DX 优先下最怕「默认不更新」，C2 的默认必须是不写也订阅。

### 9.6 与前面各节的关系、以及新的推进顺序

| 前文结论 | 本轮裁决 |
|---|---|
| §1 撤「AOT 可直调」 | **升级**：AOT 快慢整体移出形状裁决，只留硬约束分界（9.2） |
| §4 string 常量优先 | **仍成立但不必走**：DX 优先下连常量表都不写 |
| §5 观察期 | **保留**，判死条件改为「C2 安全网能否咬红」 |
| §8 dep-path / 影子记账 | **保留且升级**：从 lint → 自动追踪的安全网，投资不浪费 |
| P5 一个 API 都不删 | **重新裁决后仍成立**：不删 `select()`，只是让它**可省略**。先加自动追踪通道，`select` 保留作显式降级口 ⇒ DX 最优与零破坏可以同时拿到 |

| 段 | 内容 | 出口 |
|---|---|---|
| **D0** | 把 9.2 的可逆性分层与硬约束清单写进设计台账，防止以后又把「编不过」误判成「慢」 | 清单能复现 §1.2 的每一类 |
| **D1** | dep-path 提取器 + 影子断言（就是 §8 的 S0，不重复投资） | 复现 36/37；漏记变异必须红 |
| **D2** | C2 **灰度通道**：`notifyChanged(?string $key)` + getter `touch()` + 窗口记账；页面可选不写 `select` | getter 数 == touch 数对账必进 gate |
| **D3** | 观察期：拿真实页面量「省了几处书写」与「有没有静默不更新」 | 安全网零发现 ⇒ 保留双通道；有发现 ⇒ 只扩判据，不回退 DX |
| **D4** | 性能 A/B 只验「**没有变慢**」，且必须用 **`bin` 口径** | 今日台账已证 `lib` 不去虚化，用错口径会把已解禁判成没修 |

### 9.7 诚实边界

- 9.4 的「普通 getter 比闭包对 tpc 更友好」是**形状级推断**（依据 L08 vs L10 与 Store 为
  `final`），未跑 `bin --dry` A/B；D4 才是它的验伪口。
- 9.5 的 C2 形状**未落地、未量过任何开销**；`touch()` 的可行性只核过 getter 形态与
  `enterBuild()` / `leaveBuild()` 窗口，没动 `Subject`。
- 「性能后置」有边界：`mixed` 字段、真实混合联合、`callable` 仍是 `php::Var`，
  这类**源码级信息擦除**编译器补不回来；API 形状必须自己留素材。
- 本节未改 `src/`、未新增 lint、未碰 gate，也未复跑 T1/T2（按 T0 档）。

---

## 10. 第四推演：把 DX 当唯一目标，而不是一条判据（22:4x 补）

> §9 做的是「在既有裁决表里插入一条 DX 判据，重排优先级」。本节换的是**问题本身**：
> 从「框架怎么知道依赖」换成「开发者写完这一行，多久知道自己写对了」。
> 结论不一样：**不是选哪个形状，而是这一轮根本不该定形状。**

### 10.1 四份文档都在回答框架作者的问题

设计稿、`§8`、`§9` 给出的全部候选 —— 三本名册、`string` 常量表、dep-path、
C2 自动追踪 —— 争的是同一件事：**依赖该以什么形式被表达**。这是框架作者的视角。

开发者面对的是另一件事。`UsersPage` 里 2026-10-31 那次真机事故现在的形态是：

```text
写错 → 全部静态判据绿 → 真机起窗 → 肉眼发现「刷新」按钮永久灰
     → 翻类注释里那段 2026-10-31 记录 → 才想起「读 Store 必须在 select 之内」
```

反馈时延按「次构建」计，而守则住在**注释**里。该页解释这条隐规则的注释行数比
对应代码还多；`DeadSubscription` 又能被补一个空 `implements SubscribingWidget`
洗绿（评审稿 §3.1）。⇒ **DX 的瓶颈不是「要写几处」，是「写错时有没有人立刻说」。**

三本名册把时延压到「lint 红」，C2 把错误变成不可能发生 —— 方向都对，但两者都把
重心放在**表达形式**上，于是都要先付一笔形状成本（新名册 / 改 Element 层）。
DX 优先该先问：不换形状，能不能先把这段时延压掉？答案是能，而且设施现成。

### 10.2 实证：反馈链路三处缺口，都是零新概念可补的

| 缺口 | 本轮实证 | 现成设施 / 现成设计 |
|---|---|---|
| **误用没有任何即时报错** | 扫 `src/State/` 与 `StatefulElement.php`，只有 `SubjectBuilder` 一条 `select/unstable` 抖动诊断；**没有「裸读 / 快照读」类诊断** | `Diagnostics::degraded(key, msg)` 已有 `[DX] ` 前缀、`reported()` / `reset()`、`tools/diagnostic-check.php`（gate 的 `dxdiag` / `dxself`）、单测、以及「中文带修法」文案先例（`select/unstable`） |
| **没有完整可抄样例** | `apps/tutorial` 五个 Stage，精确 grep `SubjectBuilder\|AsyncCommand\|TableContract` **零命中**；`examples/` 为空 | 评审稿 §3.2 已设计 `DataStage`（最小 Store + 四态 + 搜索 + 命令回写），天然受 `php -l` / lint / codegen 覆盖 |
| **文档代码块会漂移且不参与编译** | 评审稿 §2 / §3.2 已证：`sortBy()`、`onSortChange(?string,bool)` vs 现行 `TableSort` 单值 | 评审稿 §3.2 已设计「扫根级文档 PHP 代码块，方法名须在 api-doc 清单里」的判据 |

★ 关键是这三项在**四份文档的所有推进顺序里一次都没出现**：设计稿 P0a–P5、
§8 的 S0–S3、§9 的 D0–D4，全在围绕评审稿 §3.1 的**下游解法**打转。

而评审稿 §2 白纸黑字写的是：「**最大摩擦是样板量**」+「可抄样板住在文档里、不参与
编译，也不会被 `docref` 检查代码块」（§3.2 是 P0）。从「开发者写页面很累」滑到
「框架怎么静态看见依赖」，是这一整轮设计的**目标漂移**；`RowSpec` 作为唯一真消
样板的契约，反而被排到 P2/P3。

### 10.3 更正 §9.5 的事实前提（涉及 C2 落地判断时以本节为准）

§9.5 写：「`StatefulElement::rebuild()` 已有 `enterBuild()` / `leaveBuild()` 包住
`build()`……**零件几乎全是现成的**」。本轮读码发现两层不成立：

1. `State::enterBuild()` 只做 `self::$buildingState = $this`，服务的判据是
   `markDirty()` 的 `E-FLUSH-CYCLE`（build 里给自己标脏），**不是订阅追踪窗口**。
2. `StatelessElement::rebuild()` 是 `updateChildren($this->expand())`，`expand()`
   里直接 `$widget->build($this->context)` —— **没有任何窗口**。
   而 2026-10-31 的事故页 `UsersPage` 正是 `StatelessWidget`。

⇒ C2 要覆盖最痛的那个页面，必须在 `StatelessElement::rebuild()`（框架最热路径）
和 `StatefulElement::rebuild()` 两处新引入一个追踪窗口概念。这不是「灰度通道、
零件现成」，是**动 Element 层**。

另一条量级判断也要修正：Store 的 getter 是应用自己写的普通 `public function`
（`UserStore extends Subject`，但 `query()` / `rows()` 不经过 `Subject` 任何拦截点），
所以 §9.5 代价第 2 条「每个 getter 一行 `touch()`」（`WatermarkStore` 34 个）
**不是可省的优化，是方案的必需成本**，且负担从页面作者转给了 Store 作者。

可救的一点是窄口径：`page()` 返回 `AsyncCommand extends Subject`，`isRunning()`
是框架方法 —— 事故那一句 `$s->page()->isRunning()` 完整落在框架类上。
⇒ 框架侧 `Subject` 子类的读方法具备打点条件，但**不等于零改动**，不能按
§9.5 的「零件现成」估成本。

### 10.4 撤回 C2 翻案：零书写 ≠ 零认知

§9.4 把 DX 压成「改动处数」单指标，C2 以 0 处胜出。完整的 DX 至少是
**书写成本 + 学习成本 + 排错成本**。C2 把第一项压到 0，却把依赖变成隐式、
条件分支会静默退订、调试时看不到订阅了什么；§9.5 自己称「调试不可见是 C2 的
原罪」，却只把它列为**第 5 条代价**，没让它重回裁决表。DX 优先下它该排第一。

成熟框架没有一家是「零书写 + 零诊断」：

| 框架 | 自动 / 显式 | 配套的可观测性 |
|---|---|---|
| Compose | 自动 | compiler metrics、Layout Inspector |
| Vue / MobX | 自动 | devtools 依赖图、`trace()` |
| SolidJS | 自动 | Signals 调试视图 |
| React | 显式依赖数组 | `exhaustive-deps` lint（**和声明成对出现**） |

只抄「自动」前半，等于把 2026-10-31 那类事故从「还有一段注释可查」变成
「连线索都没有」。⇒ **C2 从「DX 最优解」降为「诊断量出真实误用之后的候选」**，
D2 不能排第二棒。§9.5 的安全网设计（静态上界 + 动态读点、不同源）依然成立，
只是不必绑在 C2 上 —— 它正是 10.6 的观察口。

### 10.5 性能可后置 ⇒ 延迟定形状本身有价值（§9.2 翻转）

§9.2 的结论是「只后置调用开销，不后置信息密度」，据此把「保住编译器将来的
优化素材」当成**现在定形状**的硬约束，并说它强化了 §8。

但迁移成本取决于**铺开面**：名册铺得越广、C2 改的 Element 路径越核心，将来为
编译器调整形状越贵。反过来，**不上任何新形状 = 零铺开面 = 零迁移成本**，
将来想怎么定都行。既然性能收益会自动来（§9.1 已证），现在为它锁定形状，
是「付出确定的迁移成本，换不确定的将来便利」，期望收益为负。

⇒ 用户这个前提在 DX 优先下指向「**现在别定形状**」，而不是「定一个留素材的
形状」。§9.2 的硬约束分界（编不过 / 类型擦除 / 名字迁移）全部保留，但它管的是
「不能押错」，不是「现在必须押」。

### 10.6 不一样的结论：第一棒做测量仪器，不做新形状

把评审稿两条 P0 与 §8 的探针成果按 DX 优先重排，得到三件**零破坏、零新名册、
零框架语义变更**的工作：

| 段 | 内容 | 出口 / 判据 |
|---|---|---|
| **E0** | `reactive-read` lint（评审稿 §3.1 已设计：读点须词法上落在订阅作用域内；三条变异牙检含「补空接口仍须红」） | 误用反馈从「真机起窗」拉到「gate 红」；§8 的 dep-path 探针 36/37 是现成零件，唯一盲区 `StatusBar::slice()` 需一层跨函数展开 |
| **E1** | tutorial `DataStage`（评审稿 §3.2） | 完整可抄样例进编译、进 gate；文档只留链接 |
| **E2** | 根级文档代码块 API 存在性判据（评审稿 §3.2） | 防 `sortBy()` 那类再次漂移 |

三者**互相不同源**：E0 抓源码里的读点位置、E1 抓「有没有可运行样例」、
E2 抓「文档里的 API 是否还活着」。再配上 §8.5 的 dev 影子断言（抓真实读点），
就凑齐了 §9.5 想给 C2 的那套安全网，但**不需要先动 `Subject` 语义**。

★ 更关键的是：这三件是**后面所有方案的测量仪器**。E0 上线后一个迭代的命中数，
才是「要不要做三名册 / dep-path 对账 / C2」的真值，而不是靠「24 处 select 看着烦」
这种主观估计。

**判死条件**（比 §5 / §8 的「lint 零发现」强）：E0 必须通过三条牙检 +
`UsersPage` 历史 bug 回放夹具证明「能咬红」，在这个前提下：
零命中 ⇒ 漏订阅不是真痛点 ⇒ 三名册、dep-path 对账、C2 **全部不做**，只留
lint + tutorial（省下整个方案）；有命中 ⇒ 命中即真实样本，按分布决定局部修法
还是全仓通道。§5 的「lint 零发现」弱在没有牙检，无法区分「真没发生」和
「规则没覆盖」—— 又是 CQ-58 那一族。

### 10.7 与前文结论的逐条对照

| 前文 | §9 裁决 | **§10 裁决** |
|---|---|---|
| 三本名册 | 不必走 | **整个方案暂缓**，等 E0 数据 |
| §4 string 常量 | 仍成立但不必走 | 同上，且与 E0 不冲突 |
| §8 dep-path / 影子记账 | C2 的安全网 | **E0 的零件 + 独立观察口**，不绑定 C2 |
| C2 自动追踪 | **翻案，D2 灰度** | **撤回翻案**；降为 E0 有命中后的候选（10.3 / 10.4） |
| §9.2 后置开销、留信息密度 | 强化 §8 | **翻转**：延迟定形状本身有价值，别为将来锁形状 |
| P5 一个 API 都不删 | 仍成立，`select` 可省略 | **仍成立且更强**：连「可省略」都不做 |
| 观察期 | D3（靠后） | **E3 提到第一**，判死条件换成「带牙检的 E0 零命中」 |
| §3.2 可抄样板 / 文档判据 | 未进入任何顺序 | **E1 / E2，与 E0 并列第一棒** |

一句话：§9 是在问「哪个形状最 DX」，§10 的答案是
**「先把错误信息和可抄样板补齐，形状等数据说话」**。DX 优先与性能可后置这两个
前提叠起来，指向的不是更激进的重构，而是**更克制**。

### 10.8 诚实边界

- 本节只读推演，未改 `src/`、未新增 lint、未碰 gate、未提交。
- `StatelessElement` 无 build 窗口、`enterBuild()` 用途、tutorial 零命中、
  `examples/` 为空、`AsyncCommand extends Subject`，均为本轮 grep / 读码实证，
  未跑运行期验证。
- E0 的可行性依赖评审稿 §3.1 的规则设计，该规则**尚未落地**；§8 的 36/37 是
  静态探针结果，不等于 lint 能覆盖全部真误用。
- 「零铺开面 = 零迁移成本」是决策论推断，未量化；§9.1 的上游 A/B 只证明
  「调用开销可后置」，不证明「形状改动将来一定便宜」。
- 未跑 P1 前置实验、`bin --dry` A/B、T1 / T2；按 T0 档。

---

## 11. 代码对照：原状 → 被否候选 → 最新方案（22:5x 补）

> **读法**：原状片段逐字取自 HEAD `5f26dfc`，为聚焦形状略去了说明性注释；
> 其余代码块**全是设计草案，未落地、未编译**。草案只对 `Rule` / `Diagnostic`
> 的真实签名对齐，`readPoints()` 一类接口现不存在（§10.8 的诚实边界同样适用）。

### 11.1 原状：正确与写错只差一层括号

`apps/users/src/Pages/UsersPage.php:89-94` —— 正确写法，读点包在 `select` 里：

```php
SubjectBuilder::make($this->store)
    ->select(static fn(UserStore $s): bool => $s->page()->isRunning())
    ->builder(fn(bool $running): Widget => Row::make()->spacing(8)->children([
        Button::primary('刷新')->enabled(!$running)->command('users.refresh'),
    ])),
```

同页类注释记录的 2026-10-31 事故形态 —— 读点露在 `build()` 顶层：

```php
Button::primary('刷新')->enabled(!$this->store->page()->isRunning()),
```

差别只有「有没有包进 `select`」，后果却是：

- 编译、`php -l`、`lint`（1072 文件 / 0 error）全过，**真机起窗才看到按钮永久灰**；
- 守则不住在类型里，住在同页那段比代码还长的类注释里；
- `DeadSubscription` 扫的是 `$this-><property>->method()`，正确写法读的是形参 `$s`
  ⇒ 一次都不命中；反过来给页面补个空的 `implements SubscribingWidget`，**规则立刻变绿而读的仍是快照**。

⇒ 要解决的不是「这行怎么写更短」，是「写错这一行时有没有人立刻说」。

### 11.2 被否候选 A：三本名册（设计稿）

```php
// ① 名册：每 Store 一个 enum
enum UserProjection: string implements Projection
{
    case Query = 'query';
    case Running = 'running';
    case Page = 'page';
    case Visible = 'visible';
}

// ② match：无 default，漏一个运行期抛
public static function read(Projection $p, UserStore $s): mixed
{
    return match ($p) {
        UserProjection::Query   => $s->query(),
        UserProjection::Running => $s->page()->isRunning(),
        UserProjection::Page    => $s->page()->value(),
        UserProjection::Visible => $s->visible(),
    };
}

// ③ 页面：闭包换成名字
SubjectBuilder::make($this->store)
    ->project(UserProjection::Running)
    ->builder(...);

// ④ 挂载期对账
TableContract::assert(...);
```

一个读切片从 **1 处变成 3–4 处**书写物。`page()->isRunning()` 压成 `Running`
后，原本完整类型化的读路径被擦成别名（§9.2）。更要紧的是 ① 和 ② **两端都手写**：
给 `UserStore` 加一个 `status2()` 而名册、`match` 一处不动，三条 lint 全绿而新字段
没有任何投影覆盖（§8.4 的同源漏洞）。

### 11.3 被否候选 B：C2 自动追踪（§9 曾翻案，§10.4 撤回）

```php
// 页面：零 select，直接读
Button::primary('刷新')->enabled(!$this->store->page()->isRunning()),

// Store：每个 getter 都要打点（WatermarkStore 34 个）
public function query(): string
{
    $this->touch('query');
    return $this->query;
}

// Element：StatelessElement::rebuild() 现在没有窗口，得补
public function rebuild(): void
{
    Tracker::enterBuild($this);
    try {
        $this->updateChildren($this->expand());
    } finally {
        Tracker::leaveBuild($this);
    }
}
```

页面确实省到 0 处，但成本转给了 Store 作者（34 行 `touch()`）和框架核心路径
（两个 Element 的 `rebuild()`）。§10.3 已证：现有的 `State::enterBuild()` 不是
订阅窗口，`StatelessElement::rebuild()` 压根没有窗口 —— 而事故页正是 Stateless。
加上依赖隐式、条件分支静默退订、调试看不到订阅了什么 ⇒ **写时 0 处、错时无线索**。

### 11.4 最新方案：页面代码逐字不变，变的是「写错时看到什么」

这是与前面所有候选最不一样的一点：**没有新的页面写法**。
11.1 那段正确代码原样保留，`select()` 不删、不可省略、不改名（P5 零破坏）。
新增的三件都是「仪器」，先给最关键的 E0。

**E0 · 误用当场红**（`tools/Lint/Rules/ReactiveRead.php` 草案）。输出沿用现有
`Diagnostic::format()` 的 `file:line  severity  rule  message`：

```text
apps/users/src/Pages/UsersPage.php:88  error  reactive-read       读点 $this->store->page()->isRunning() 不在订阅作用域内；本页是 StatelessWidget，build() 只跑一次 ⇒ 该值永久停在首屏。修法：包进 SubjectBuilder::select(...)，或改为构造期注入的不变值
```

规则骨架（签名对齐 `tools/Lint/Rule.php`）：

```php
final class ReactiveRead implements Rule
{
    public function name(): string { return 'reactive-read'; }
    public function serves(): string { return 'A16'; }        // 占位，公理编号待裁决
    public function summary(): string
    {
        return '读 Subject 派生值必须词法上落在订阅作用域内';
    }

    public function check(Project $project): array
    {
        $found = [];
        foreach ($project->files() as $file) {
            // 草案接口：由 §8 的 dep-path 提取器（实测 36/37）补上，现不存在
            foreach ($file->readPoints() as $hit) {
                if ($hit->insideSelectClosure || $hit->insideBuilderScope) {
                    continue;
                }
                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $file->path(),
                    $hit->line,
                    '读点不在订阅作用域内 …… 修法：包进 SubjectBuilder::select(...)'
                );
            }
        }
        return $found;
    }
}
```

变异牙检夹具（`tools/Lint/fixtures/apps/`，沿用现有夹具机制）：

```php
// 夹具①：把读点挪出 select ⇒ 必须红
final class ReactiveReadFixture extends StatelessWidget
{
    public function build(BuildContext $ctx): Widget
    {
        return Button::primary('刷新')->enabled(!$this->store->page()->isRunning());
    }
}

// 夹具②：同一行 + implements SubscribingWidget ⇒ 仍然必须红
//         专门防「补空接口洗绿」，这是 DeadSubscription 现存的假绿路径
```

牙检是判死条件的前提：只有证明规则**能**咬红，后面「一个迭代零命中 ⇒
三名册 / C2 全不做」才成立（§10.6）。§8.5 的 dev 影子断言作为第二观察口，
抓静态看不到的间接读，与 lint 不同源。

**E1 · 可抄样板进编译**（新增 `DataStage`，骨架）：

```php
final class DataStage extends StatelessWidget
{
    public function __construct(private readonly UserStore $store)
    {
        parent::__construct();
    }

    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->spacing(8)->children([
            SubjectBuilder::make($this->store)
                ->select(static fn(UserStore $s): string => $s->query())
                ->builder(fn(string $q): Widget => TextField::make()->value($q)
                    ->onChanged(fn(string $x) => $this->store->setQuery($x))),

            SubjectBuilder::make($this->store)
                ->select(static fn(UserStore $s): AsyncValue => $s->page()->value())
                ->builder(fn(AsyncValue $v): Widget => $v->fold([
                    'loading' => static fn(AsyncValue $v): Widget => Spinner::make(),
                    'error'   => static fn(AsyncValue $v): Widget => Text::make('加载失败')->error(),
                    'data'    => static fn(AsyncValue $v): Widget => Text::make('已加载'),
                    'idle'    => static fn(AsyncValue $v): Widget => Text::make('尚未加载')->caption(),
                ])),
        ]);
    }
}
```

这个文件天然受 `php -l`、`lint`、`drift-codegen`、`smoke` 覆盖 ⇒ **API 漂移当场红**。

**E2 · 文档代码块不再腐烂**。现状 `docs/DESIGN-TABLE-2026-10-26.md:230-234`：

```php
Table::make()
    ->sortBy('name', ascending: true)
    ->onSortChange(fn(?string $key, bool $asc) => ...);
```

现行 `Table` 是 `->sort(TableSort $sort)` + `onSortChange(fn(TableSort $next): void)`。
这段「可直接抄」的样板已经失效，却没有任何判据会红。E2 扫根级文档里含
`::make(` 的 PHP 代码块，要求方法名在 api-doc 清单里存在（或显式标注
`@historical`）；改后文档只留一句「唯一可抄样板见 tutorial 的 DataStage」
加一个链接，不再复制代码。

### 11.5 解决了哪些问题

| 问题 | 原状 | 三名册 | C2 | **E0 / E1 / E2** |
|---|---|---|---|---|
| 写错时的反馈 | 真机起窗 + 肉眼 | lint（两端手写，可同源漏） | 隐式，无线索 | **gate 红 + 中文带修法** |
| 新增一个读切片 | 1 处 | 3–4 处 | 0 处（Store +34 行） | **1 处，不变** |
| 页面作者要学的新概念 | — | 3 份名册 + `assert()` | 读即订阅 + 退订语义 | **无** |
| 可抄样板 | 文档里、已漂移、不编译 | 未涉及 | 未涉及 | **Stage 进编译进 gate** |
| 补空接口洗绿 | 能洗绿 | 能洗绿 | — | **夹具②专门防它** |
| 要改 `Subject` / Element 核心 | — | 挂载期契约 | 两处都要改 | **都不改** |
| 将来为编译器调整形状 | 迁移 1 处写法 | 迁移 3 份名册 | 迁移框架语义 | **零铺开面** |

一句话：前两个候选都在**替换 11.1 那行代码**，最新方案**保留它**，
只把「写错没人说、照抄会过期」这两件事补上。DX 收益来自反馈链路与可抄性，
不来自新的表达形式；性能收益按用户前提交给后续编译器，页面一行都不用改。

### 11.6 诚实边界

- 11.1 与 E2 的「现状」片段来自 HEAD `5f26dfc` 的真实文件与行号；
  11.2 / 11.3 / 11.4 的新代码**全部是草案，未落地、未编译**。
- `readPoints()` / `insideSelectClosure` / `Tracker` 现不存在；
  `serves(): 'A16'` 是占位编号。夹具的期望值登记方式沿用 `tools/Lint/fixtures/`
  现有机制，本节未展开。
- E0 的 36/37 是静态探针结果，不等于规则覆盖全部真误用；
  `StatusBar::slice()` 那处仍需一层跨函数展开。E0 那段 CLI 输出的**行号是格式示意**，
  不是 2026-10-31 事故的真实行号。
- 本节未改 `src/`、未新增 lint、未碰 gate；按 T0 档。
