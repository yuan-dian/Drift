# 综合 Review · UI 与业务数据交互 · 2026-11-11

> ★ 本文是**现状评审**（判据与证据链仍有效）。最新落地方案见
> `docs/DESIGN-UI-DATA-DX-FIRST-2026-11-11.md`，本文 §3.1 / §3.2 两条 P0 由它承接。
> 基线 HEAD `5f26dfc`，工作区干净。**只读评审，未改任何代码 / 配置**。
> 范围：UI 控件 ↔ 业务数据交互。四个维度：架构 / 实现 / 扩展性 / 开发者体验。
> 参照：`D:/TypePHP/Drift3/docs/design/22-UI控件与业务隔离.md`（设计规格，非事实源）、
> Flutter / Jetpack Compose / WPF / Qt / Elm / React 的对应机制。
> 既有裁决以 `DEBT-2026-11-02.md` 为准；与它冲突处一律显式标注「需裁决」，不偷偷改口径。
> 定位用类名 / 方法名 / 命令，不写 file:line（本仓文档纪律）。设计草案代码均**不可编译**。

---

## 0. 一句话结论

> **本仓不缺「隔离」，缺的是「数据契约」。**

读通道靠 `Subject` 全量广播 + 每处手写 `select` 闭包，写通道有两条且强度不同，
控件侧每行数据是 `array<mixed>` + 四套闭包取值。这三条都不是「纪律没写」，
而是**通道本身没有形状**，于是正确性只能靠人记得「每个会变的值都要包进 `SubjectBuilder`」——
`UsersPage` 类注释里那条 2026-10-31 真机 bug（刷新按钮永久灰掉）就是它咬人的证据。

Drift3 那份规格的隔离不是为了「代码好看」，而是为了给 **dep-table 编译期依赖推导**
扫清动态分派：全标量 DTO、构建签名不含业务类型、禁闭包、`Cell::set()` 只在编排层。
本仓是**推模式广播**（`Subject::notifyChanged()`），没有 dep-table，
照搬那四条会把成本全留下、收益全丢掉。值得借的只有三件事：

1. **把「读点必须被订阅覆盖」从口传纪律变成可机检的路径判据**（不是标记接口）；
2. **把命令做成穷举**（`match` 无 `default` 或等价静态对账）；
3. **把行/列取值收成一个具名契约类型**，顺带让 AOT 静态直调得着它。

---

## 1. 现状：一读一写，实际上两条写通道

### 1.1 读通道

```text
业务源 (UserSource / FetchUsersJob / Http::get)
   │  Future → AsyncCommand::land() / fail() → emit() → notifyChanged()
   ▼
UserStore extends Subject        ← 唯一的落地口 absorb()，只做赋值 + notifyChanged()
   │  Subject::notifyChanged() 全量广播（version++，无字段级区分）
   ▼
SubjectBuilder::select(callable) ← 每次通知重跑闭包，=== / Equatable 门控
   │  命中 ⇒ markDirty() → Pipeline → Frame::settle() → StatefulElement::rebuild()
   ▼
builder(callable) → Table / Each / TextField / Text
```

- 订阅只有两种入口：`State::watch()`（限 `initState()`，挂订阅袋，`unmount()` 统一退订）
  和 `SubjectBuilder`（`watchSlot('source', …)` 命名槽，换 source 重绑不累加）。
- `AsyncValue` 四态由 `fold(array $arms)` 表达：四个字符串键必须写全，
  `isset` 检查在调用时抛 `InvalidArgumentException`（**调用即检查，不是走到那一态才炸**）。
- `Computed` 是拉取式、显式 `deps` 数组、`version()` 求和判重算；
  `implements Versioned`，全仓只有 `UserStore::visible()` 一处真实用户。
- **没有任何自动依赖收集**：`src/` 里搜不到 dep-table / 自动追踪 / 依赖收集的实现。
  `SubjectBuilder::select()` 的 docblock 写着「闭包体内出现的属性集合 = 真正依赖的字段集
  （框架编译期推导依赖的第一步，可 lint）」——那是**注释里的计划，不是代码**。

### 1.2 写通道（两条，强度不对等）

| | 路径 | 静态判据 | 漏了会怎样 |
|---|---|---|---|
| A · 直接闭包 | `TextField::onChanged(fn(string $x) => $store->setQuery($x))` | 无 | 能跑；但写点散落在 UI，不可枚举、不可录制、不可单测 |
| B · 命令 | `Button::command('users.refresh')` → `CommandRouter`（父表 + 本窗表）→ `CommandHandler::handle(string, CommandArg): bool` → `match` + `default => false` | `dead-command` 双向对账（`command('x')` ↔ `on('x')`，只收字面量） | 没人接 ⇒ `Errors::report(..., DIAGNOSTIC)`，**不中止** |

`CommandRouter` 的两层作用域（全局父表 / 本窗子表）和「重复注册当场报错」是本仓
比 Drift3 强的地方；`CommandHandler` 用**具名类实例而不是闭包**，注释写明理由是
「可枚举 / 可单测 / 静态可达」——已经走在 Drift3 想走的方向上。

### 1.3 控件侧的数据契约

| 事实 | 位置 |
|---|---|
| `Table::make(list<TableColumn>, array $rows)`，行是 `array<mixed>`；`make()` 里 `array_values($rows)` 复制一遍 | `Table::make()` |
| 列取值**两套**：`field(string)` 假设关联数组、`cell(callable)` 收 `fn(mixed $row): Widget` | `TableColumn` |
| 文本投影**第三套**：`copyText(callable)`，只对 `cell()` 列需要；没声明就出空串 | `TableColumn::copyTextOf()` |
| 行身份**第四套**：`keyOf(callable)` 必填，`TableContract::assert()` 挂载期强校验 | `Table::keyOf()` |
| 上报回调：`onSelectionChange(TableSelection)` / `onSortChange(TableSort)` / `onCellEdit(...)` / `onRowTap(mixed $row, int)` | `Table` |
| 受控声明必须接上报（`selection`/`sortable`/`resizable`/`editable`），缺一个挂载期 `Errors::report` | `TableContract::assert()` |
| `Each::make(callable $keyFn)->items(array, callable)`；`UserRow::make(User $u)` 业务实体直接进 Widget | `Each` / `apps/users` |

`TableContract` 这条**挂载期契约守卫是 Drift3 没有的**，它比「靠 lint 扫命名空间」更硬：
声明了能力却没接上报，应用起不来，而不是等用户点一下才发现。这一点必须保住。

### 1.4 AOT 侧的形状（本轮看的真实生成物）

`UsersPage` 的生成物里 `php_drift__state__subjectbuilder__select(...)` 是**静态直调**，
但参数里是 4 个 `php::newClosureWithParameters(...)`，同文件另有
7 处 `typephp_call_method_cached`。也就是说：

- 框架方法本身已经静态化（上游 #183 / #184 的收益兑现了）；
- **数据契约仍然全是闭包值**：`select` / `builder` / `field` / `cell` / `keyOf` /
  `onChanged` / `fold` 的四支，编译器看不到闭包体里读了什么；
- `AsyncValue::fold()` 的注释自己写明「`is_callable` / `($arms['data'])(...)` AOT 下没验证过」，
  所以实现里刻意先取到变量再调用、只敢用 `isset`。

⇒ **「隔离做干净就能自动订阅」这个因果链在本仓不成立**：本仓没有 dep-table，
瘫在中间的恰恰是「闭包体不可见」，而不是「签名里带了业务类型」。

---

## 2. 四维度评估

| 维度 | 现状 | 判断 |
|---|---|---|
| **架构** | 单向数据流成立：`Future` 只在帧内 `settle()` 之前结算、Store 是唯一落地口、`BuildContext` 只读快照、`Pipeline` 一份记账；§18 明确否决 `BuildContext::of()/find()` 与控件自取数据 | 方向对且已机械化。**缺口在「契约」而非「方向」**：读点位置、行取值、命令名册都没有形状，于是三条通道各靠一套人肉纪律 |
| **实现** | 37 条 lint、`TableContract` 挂载期判据、`dead-command` 双向对账、`dead-subscription` / `select-purity` / `stale-derived` / `nested-subscribe` 覆盖订阅面 | 判据密度高，但**多为「标记 / 对账」型**：`dead-subscription` 要的是 `implements SubscribingWidget`，不是「读点在订阅作用域内」；标记一加，裸读照样绿 |
| **扩展性** | 虚拟化窗口只建可视行、`Computed` 缓存派生值、`AsyncCommand` 统一四态与去重取消 | 小数据够用。大数据两个隐忧：① `Subject` 全量广播，N 个订阅者 × 每次通知各重跑一次 `select`；② `select` 返回整份 `array`，`===` 门控是 **O(n) 逐元素比较**，而 `version()` 这个 O(1) 的现成信号没被订阅侧用上 |
| **开发者体验** | dev 态改 PHP 秒级生效、错误信息中文带修法、`TableContract` 当场指名缺哪个 `onXxx()` | **最大摩擦是样板量**：一个列表页 4 层嵌套 `SubjectBuilder` + `fold` 四支 + 每列一个闭包；而 `apps/tutorial` 只教 `State` 局部态，**没有 Store / SubjectBuilder / AsyncCommand / 命令的完整可抄样例**，唯一样例在 `DESIGN-TABLE-2026-10-26.md` 且 API 已漂移（文档里的 `sortBy()` / `onSortChange(?string,bool)` vs 现行 `TableSort` 单值） |

★ 最后一行值得单独强调：**「可抄样板」住在文档里、不参与编译，也不会被 `docref` 检查代码块**——
这是本仓刚清理过 `AOT-CONSTRAINTS.md` 过期信息之后，同一种文档腐烂的第二个落点。

---

## 3. 高信号问题与更优方案

### 3.1 P0 · 「读了会变的值却没订阅」不可机检，且标记接口能把它洗绿

**证据链**

1. `UsersPage` 类注释记录了 2026-10-31 真机 bug：`StatelessWidget::build()`
   只在子树被造出来时跑一次，原先 `enabled(!$this->store->page()->isRunning())`
   取的是浇筑那一刻的值 ⇒ 首屏在加载 ⇒ **刷新按钮永久灰掉**，数据到了也不恢复。
2. 现在的修法是把每个读点包进 `SubjectBuilder`，于是页面出现 4 层嵌套
   （搜索框 / 刷新按钮 / 四态 / data 支里的派生行）。注释自己下的结论是
   「页面里**任何**读 Store 的地方都必须在某个 `select` 之内，否则它就是快照」——
   这是一条**口传纪律**。
3. `DeadSubscription` 的判据是：`build()` 体内出现对 `Subject`/`Cell` 类型**属性**的
   方法调用 ⇒ 该类必须 `implements SubscribingWidget`，否则报「死订阅」。
   它明确写了诚实边界：「子树不是 token 可见的事实」，只做「本类必须实现」这一层。

**为什么判据全绿**：`UsersPage` 现在把 `$this->store` 作为**实参**传给
`SubjectBuilder::make(...)`，`select` 闭包里读的是形参 `$s`，规则扫的
「`$this-><property>->method()`」一次都不命中 ⇒ 绿。更糟的是反过来：
若有人把那段裸读写回来，然后给 `UsersPage` 补一个空的 `implements SubscribingWidget`
（照着规则文案最自然的修法），**规则立刻变绿，而读的仍是快照**。
⇒ 这是「判据与实现同源」的另一种形状：判据要的是标记，缺陷在位置。

**更优方案（非破坏）**：新增一条 `reactive-read` 路径判据，不要求任何标记接口。

- 静态输入：类表里所有 `Subject` 子类型 + 它们的公开读方法（`query()` / `visible()` /
  `rows()` / `page()` 这类无副作用读取，可由 `@reactive` 标注或「只读方法」启发式给出）。
- 判据：`build()` 及其闭包内，任何对上述方法的调用，**必须词法上位于**
  `SubjectBuilder::select()` 的闭包实参、`builder()` 闭包实参、
  或 `State::watch()` 已订阅的作用域内；否则 error，文案给「把它包进 `select`，
  或改成构造期注入的不变值」。
- 反同源：判据的期望值不能从 `SubscribingWidget` 推导，要从「调用点是否在订阅闭包
  的 token 区间内」推导 —— 这与 `callback-arity` 用「形参个数写死在规则里」是同一招。
- 变异牙检三条：① 把 `UsersPage` 刷新按钮那段挪出 `select` ⇒ 必须红；
  ② 给 `UsersPage` 加 `implements SubscribingWidget` 后重跑 ⇒ **仍然必须红**
  （这条专门防「加标记洗绿」）；③ 把读点放进 `select` ⇒ 绿。
- 顺带把 `dead-subscription` 的定位改清楚：它守的是「订阅责任可见」，
  新规则守的是「读点位置」，两条不合并、不互为豁免。

**兼容性**：零破坏，纯新增 lint + 一条夹具。

---

### 3.2 P0 · 可抄样板过期且不可编译（DX 的真实缺口）

**证据**：`DESIGN-TABLE-2026-10-26.md` 里那段「可直接抄」的
`SubjectBuilder → Table` 样板用了 `->sortBy()` 和
`onSortChange(fn(?string $k, bool $asc): void)`；现行 `Table` 是 `TableSort` 单值上报。
`apps/showcase` 的 `AsyncTablePage` 才是可运行写法，但它是完整应用页、不是教学样例。
`apps/tutorial` 的 Stage 只覆盖 `State` 局部态与组合根接线，`examples/` 目录为空。

**更优方案（结构性，允许破坏）**：把「样板」从文档搬进**参与编译与 gate 的代码**。

- 新增一个 tutorial Stage（或 `apps/tutorial/src/Stages/DataStage.php`），
  用最小 Store + `AsyncCommand` + `SubjectBuilder::select` + `Table` + `command()`
  走完「加载 → 四态 → 搜索 → 选中 → 命令回写」全环；它天然受
  `php -l`、`smoke`、`lint`、`drift-codegen` 覆盖，API 漂移当场红。
- 文档里的代码块只保留**指向它的链接 + 一句「这是唯一可抄样板」**，
  不再复制整段代码；`docref` 管链接，`gate` 管代码。
- 加一条判据：扫根级文档里含 `::make(` 的 PHP 代码块，要求块内每个方法名
  在 `api-doc-check` 的清单里存在（或显式标注 `@historical`）。
  ⇒ 这是把「文档腐烂」变成可证伪的判据，而不是再靠人记得回去改。

**兼容性**：新增文件；文档侧删代码块（不改结论），不碰 `src/`。

---

### 3.3 P1 · 相等门控对 `array<User>` 有盲区，且 O(n) 比较白扔了现成的 `version()`

**证据**

- `SubjectBuilderState::onSourceChanged()` 的门控是 `sameValue()`：
  两边都是 `Equatable` 才按值比，其余一律 `===`。
- `SelectPurity` 把 `array` 列为 `VALUE_SEMANTICS` 放行，理由是
  「`array` 的 `===` 是同键序 + 同类型 + 同值，稳定值语义」。
  **这个理由只对标量数组成立**：PHP 的 `===` 对数组元素里的对象按**身份**比，
  `array<User>` 每次 hydrate 出新对象 ⇒ 逻辑相同也判不等 ⇒ 每次通知都重建整表。
- `sameContent()` 的抖动诊断明确「收窄到对象」：`!is_object($a) || !is_object($b)`
  直接返回 false ⇒ **数组内对象身份变化不会报 `select/unstable`**，静默。
- `Computed implements Versioned`、`version(): int` 是 public，
  `Subject::notifyChanged()` 也 `version++`；但订阅侧一次都没用这个 O(1) 信号，
  每次通知都重跑 `select` 并逐元素比较整份数组。

**更优方案（可分两步，均非破坏）**

1. **`selectVersioned()` / `selectComputed()`**：接受 `Versioned` 派生值，
   直接比 `version()` 判要不要重建；`Computed::get()` 本身已按 deps 版本缓存，
   两层 O(1) 叠起来就把「粗广播 → 整数组比较」压成 O(1)。
   `UserStore::visible()` 是现成消费者，改动只在调用点。
2. **收窄 `select-purity`**：`array` 放行时看元素形态 ——
   `list<scalar>` 直接放行；`array<SomeClass>` 要求元素实现 `Equatable`
   或显式给了 `equals()`；闭包体内 `new` 无值相等契约的类已经报错，
   把「返回数组、数组里装这类对象」也纳入同一条。
   变异：夹具里 `select(fn(): array => [$store->user()])`（`User` 无 `Equatable`）
   必须红；换成 `array<int>` 必须绿。

**边界**：不要改默认 `===`（DEBT §23.3 已裁决：改 `==` 会多判等、静默少重建）。
新增的是**另一条通道**，不是替换门控语义。

---

### 3.4 P1 · 行契约四套闭包 + 业务实体直接进 Widget

**证据**：`field()` / `cell()` / `copyText()` / `keyOf()` 四条独立闭包；
`UserRow::make($u)`、`Each::make(fn(User $u): int => $u->id)`、
`TableColumn::cell(fn(User $u): Widget => Badge::make($u->status))`
都让 `src/Widget/` 层直接持有业务实体类型。`dependency-direction` 只管框架内部分层，
注释明写「业务代码不参与分层检查」，所以 `apps/` 里这样写完全合法，也无判据。

**更优方案（结构性，建议先做第二通道再决定是否破坏）**

引入一个**具名行契约**（草案名 `RowAdapter` / `RowContract`），把四套闭包收成
一个 `final class` 的具名方法：

```php
// 设计草案，不可编译
final class UserRows implements RowContract      // 应用侧，实体 → 标量/Widget 的唯一转换点
{
    public function __construct(private array $rows) {}

    public function count(): int { /* 行数 */ }

    public function keyAt(int $i): string|int { /* 行身份 */ }

    public function textAt(int $i, string $col): string { /* 显示 + 复制 + 排序，一份实现 */ }

    public function cellAt(int $i, string $col): ?Widget { /* 只有富单元格才返回 Widget */ }
}

Table::make($columns, UserRows::of($store->visible()))
     ->columns(/* 列只声明 key / title / width / sortable / editable */);
```

收益（三条，都能证伪）：

1. **一份数据一个名字**：显示值、复制文本、排序键、行身份不再各写一遍闭包，
   「Ctrl+C 复制出的文本和屏幕上不一样」这类分叉结构上消失。
2. **AOT 静态直调**：`final class` + 具体类型 ⇒ 方法调用可被去虚化；
   现状四个 `\Closure` 在生成物里是 `php::newClosureWithParameters` +
   `typephp_call_method_cached`。用上游 #184 的同款夹具做 A/B：
   把同一张表分别用闭包版与契约版编一次 `bin --dry`，
   断言契约版目标函数里 `typephp_call_method_cached` 计数为 0。
3. **业务实体不再漏进 Widget 闭包**：转换点收在应用侧一个类里，
   剩下的通道全是标量 / `Widget`。这是**从 Drift3 的「投影」借来的那一半**，
   但不要求「全标量 DTO」、不禁止闭包、不动 `BuildContext`。

**迁移路径（允许破坏，但分批）**：`Table::make()` 保持现状一个版本，新增
`makeWith(RowContract $rows, array $columns)`；`TableContract::assert()` 对两条
通道共用同一份「受控声明必须接上报」判据（这条守卫不能丢）。
等 `apps/showcase` / `apps/users` 各有一条真实消费者，再裁决是否废弃闭包版。
⇒ 判据是「第二通道有消费者」，不是「API 看着更整齐」。

---

### 3.5 P1 · 两条写通道强度不对等，且 handler 漏 case 静态看不出

**证据**

- 搜索框走 `onChanged` 直接 `setQuery()`，刷新/删除走 `command()` 注册表；
  同一页面里两种写法并存，没有「什么时候用哪条」的机检口径。
- `UserCommands::handle()` 是 `match ($name) { …, default => false }` ——
  `default` 表示「不是我管，继续冒泡」，这是 `CommandRouter` 的既定语义。
- `dead-command` 对账的是**发出名 ↔ 注册名**，看不到 handler 内部的 `match`。
  ⇒ 注册了 `on('user.archive', $handler)` 但 `handle()` 里漏写 case：
  静态全绿，运行期只落一条 `DIAGNOSTIC`（不中止），用户点一下「没反应」。
- Drift3 用 `match` 无 `default` + `UnhandledMatchError` 做零容忍，
  这是它 §6 表里最便宜、最可搬的一条。

**更优方案（不改冒泡语义）**：加一条 `command-cases` lint。

- 静态输入：`on('x', $handler)` 的字面量名 + `CommandHandler` 实现类
  `handle()` 里 `match` 的 `case 'x'` 字面量集合。
- 判据：每个注册名必须在该 handler 的 case 集合里（跨父链时按就近表判）；
  出现 `default => false` 时额外要求「所有已注册名都显式列出」，
  即 `default` 只能兜「链上别人家的命令」，不能兜「本 handler 漏写的」。
- 变异：删掉 `UserCommands` 的 `'user.del'` case ⇒ 必须红；
  把 `default` 改成 `throw new \UnhandledMatchError()` ⇒ 绿。
- 更进一步（破坏性窗口可选）：命令名从 `string` 换 `enum`（`CommandId`），
  `match` 无 `default` 由类型层穷举；代价是全仓 `command('x')` 调用点全改，
  且 `dead-command` 的字面量扫描要改成 enum case 扫描。**收益不如 lint 那条直接**，
  不建议只为穷举去做破坏。

**同时把口径写进文档**：`onChanged` 直接写 Store 在本仓**是合法的**
（`Subject` 推模式下写点位置不影响下一帧读，Drift3 的「`Cell::set()` 只在编排层」
是为它的 dep-table 服务的，本仓照抄只会多一层无用编排）。
真正该管的是「**会不会有第二个落地口**」：一条 lint 扫
`apps/*/src/Pages|Widgets` 里对 Store 的写方法调用点，
要求同一个 Store 的写方法只从 `command` handler 或该页面自己的输入回调进入，
并给出「要跨页复用就提命令」的文案。这是把两条通道**说清楚**，不是合并它们。

---

### 3.6 P2 · 细粒度变更通知缺失（扩展性的真上限）

`Subject::notifyChanged()` 是整 Store 广播，没有「哪些行变了」的信息；
`Table` / `Each` 只能整份 `rowList()` 重新切片、整份 `===` 比较。
参照 WPF `ObservableCollection` 的 `CollectionChanged`（带 NewItems/OldItems）、
Qt `QAbstractItemModel::dataChanged(topLeft, bottomRight, roles)`、
React `useSyncExternalStore` + selector。

**可选方案**：`notifyChanged()` 增加可选 `ChangeSet`（新增 / 删除 / 更新的行 key），
`Table` 订阅它做增量复用，未给 `ChangeSet` 时退回全量（向后兼容）。

**但这条现在不该做**，两个硬前置：

1. §66 刚立的判据：Table 的行 Widget 用 `->key($i)`（**视口内绝对行序号**）做 diff 复用，
   数据级身份是 `$keyOf($row)`。增量通知按数据 key 说话，而盒按序号复用 ——
   两套身份不先收口，增量会「复用错盒子」，这正是「选错行」那一族静默 bug。
2. 没有大数据消费者。当前两个应用的行数量级是种子数据，
   全量比较的成本远低于引入第二身份体系的维护成本。

⇒ 登记为「等有真实大数据应用再评」，不进推荐顺序。

---

### 3.7 P2 · 自动依赖追踪 / 作用域注入 **需裁决，本轮不建议做**

DEBT §19.2 把「状态订阅自动化（MobX 式）」判为「半（能写，但易错）… 结论：留」，
§18 明确否决 `BuildContext::of()/find()`、全局 `AppScope`、控件自取数据，
并写明「将来若要做，前置裁决是给不给 Element 树一个可继承的作用域 ——
要推翻 §18 的 ③ 条，得先有『为什么这次值得』的证据 —— 现在没有」。

本轮找到了两条**新证据**，但不足以翻案：

- 真机确实咬过「读快照」（§3.1），说明手写 `select` 有真实代价；
- 上游 #183 / #184 让静态类型保留、链式去虚化生效，
  「编译期推导依赖」比 §19.2 裁决时更可行。

**为什么仍不建议**：§3.1 的路径 lint 用**零破坏**就能把同一个 bug 变成可机检，
收益几乎相同、成本差两个数量级；而自动追踪要么改 tpc 插桩、要么运行期给每次属性读
记账（热路径代价），还要先推翻 §18 引入 `InheritedWidget` 式作用域。
Jetpack Compose 的 Snapshot 能做，是因为它有编译器插件 + 整个 `State<T>` 体系；
本仓的 `Subject` 是普通 PHP 对象，硬套只会得到一套「偶尔失灵的魔法」。

⇒ **写进待裁决清单，不作为本轮方案**。若将来要做，先出一份
「作用域注入 vs 路径 lint」的对比裁决稿，再动 `Element`。

---

### 3.8 P2 · 双向绑定 `bind()`：明确不做，给替代

DEBT §19.2 已判「留」；§20 还记录了受控组件的硬语义：
外部 `setText` **不进 undo 栈且清空两个栈**，否则「撤销会退到已被外部数据取代的文本」。
Drift3 也明令禁止双向绑定。WPF `Binding` / SwiftUI `Binding` 看着香，
但会引入第二个写通道，与 §1.2 的「说清楚两条通道」直接冲突。

**替代（DX 等价、语义不变）**：表单脚手架生成受控样板，仍是单向流：

```php
// 设计草案，不可编译
Form::of($store)
    ->field('name',  label: '姓名', read: fn(SignupForm $s): string => $s->name(),
                                    write: 'signup.set-name')   // 走命令，不直接 set
    ->field('email', label: '邮箱', read: fn(SignupForm $s): string => $s->email(),
                                    write: 'signup.set-email')
    ->submit('signup.submit');
```

`Form` 内部只做一件事：把 `read` 包进 `SubjectBuilder::select()`、
把 `write` 包成 `command()`。省掉的是 8 行/字段的样板，
**没有新增任何一条数据通道**，也不会踩 §20 的 undo 语义。

---

## 4. Drift3 那份规格：逐条对照

| Drift3 条款 | 本仓对应 | 借不借 | 理由 |
|---|---|---|---|
| 全标量 DTO 投影（`UserRows` 列式存储，只 `idAt/nameAt`） | `Table` 吃 `array<mixed>`，`UserRow` 吃 `User` | **借一半** | 借「转换点唯一」做成 `RowContract`（§3.4）；不强制全标量 —— 本仓 PHP 数组 COW 传整份便宜，且没有过桥限制（`User` 不出进程边界时无需降级） |
| 构建签名不许出现业务实体 / Repository | `UsersPage::__construct(UserStore $store)` | **不借** | 本仓 §18 的正结论是「业务数据只能从组合点注入」，注入的正是 Store；Drift3 这条是为 dep-table 扫动态分派，本仓没有 dep-table |
| UI 不许发 IO / 不许构造 `AsyncScope` | §18 已禁「控件自己去要数据」 | **已有，不重复** | 但本仓靠的是分层 + 组合根，不是 lint 扫 `src/Ui/`（本仓没有 `src/Ui/`）；补一条「页面里出现 `Http::` / `submit(new Job)`」的 lint 只是重复 §18 的既有判据 |
| `Cell::set()` 只在编排层 | `onChanged → $store->setQuery()` 直接写 | **不借** | 推模式广播下写点位置不影响正确性；Drift3 的禁令源于写通道短路会让下一帧读不到。本仓应管的是「落地口唯一」（§3.5），不是写点位置 |
| 命令只发 `int`/`string` | `command(string)` + `CommandArg`（值对象） | **部分已有** | `CommandArg` 是标量承载（`$arg->i`），形状等价。`dead-command` 静态对账比 Drift3 的注册表更硬 |
| `match` 无 `default` 穷举 | `handle()` 有 `default => false` | **借，改成 lint** | 见 §3.5；保留冒泡语义，用静态对账达到同等零容忍 |
| `isolation-lint` + 注入自证 | 37 条 lint + 变异牙检 / `docref` | **借纪律，不借规则** | 本仓的「注入用例必须转红」已经是同一套方法论；照抄它的 `FORBIDDEN_NS` 会管不到真问题（本仓缺陷在读点位置，不在命名空间） |
| `dep-table` 自动订阅 | 无（注释里是计划） | **不借** | 见 §3.7，需先推翻 §18，成本远大于 §3.1 的路径 lint |
| 四态用 `AsyncStateView` 四个命名方法 | `AsyncValue::fold(array $arms)` | **可借，低优先级** | `fold()` 已做「四键必须写全」的调用即检查，运行期够硬；接口四方法的收益在 IDE / 静态穷举，且接口方法在 AOT 下可能动态分派。若要做得先量 A/B |
| `when()` 四态视图 | 页面手写 `fold` 四支 + 嵌套 `SubjectBuilder` | **借** | 见 §3.2 / §7：用 `AsyncView` 把「四态 + 派生列表」合成一个订阅点，直接消掉 `UsersPage` 的两层嵌套 |

★ **最重要的判断**：Drift3 的隔离是**手段**（为编译期依赖推导服务），不是目标。
本仓的 AOT 编译器已经能静态直调框架方法（本轮生成物证据），
真正挡住自动推导的是**闭包体不可见**，而不是签名里的 `UserStore`。
所以本仓该做的是「把闭包换成可分析的具名契约（§3.4）+ 把读点变成路径判据（§3.1）」，
而不是把业务实体赶出 UI。

---

## 5. 成熟 GUI 框架：可借的机制

| 框架 | 机制 | 本仓对应 | 可借的点 |
|---|---|---|---|
| Flutter | `ChangeNotifier` + `ValueListenableBuilder` + `ValueKey` + `DataTable` 的 `DataRow/DataCell` | `Subject` + `SubjectBuilder` + `keyOf()` + `Table` | 现状几乎同构，说明路子没错；Flutter 也靠手写 `selector`，但它的 `InheritedWidget` 本仓 §18 已否决 |
| Jetpack Compose | Snapshot 自动依赖追踪 + `derivedStateOf` 智能重组 | `Computed`（显式 `deps`） | `derivedStateOf` 不用写 deps 是真好，但依赖编译期插桩 ⇒ 见 §3.7，不借 |
| WPF / MVVM | `INotifyPropertyChanged` + `ObservableCollection` 增量 + `ICollectionView`（排序/过滤在 VM）+ `ICommand.CanExecute` + `Binding Path`/`DataTemplate` | `Subject` 粗广播 + `UserStore::visible()` 做过滤 + `AsyncCommand::isRunning()` 管按钮可用 | **借 `ICollectionView` 的职责划分**：排序/过滤属 Store，`Table` 只上报 `TableSort` —— 本仓已是这个形状（`DESIGN-TABLE` 明确「控件只负责点表头和画箭头」），保持；`ObservableCollection` 增量见 §3.6 暂不做；双向 `Binding` 明确不借 |
| Qt | `QAbstractItemModel::data(role)` 统一取值 + `dataChanged` 细粒度信号 + delegate | `field()` / `cell()` / `copyText()` 三套 | **借 `data(role)` 统一取值协议** ⇒ §3.4 的 `RowContract`；delegate 拆分本仓 `cell()` 已有对应，不必再造 |
| Elm / Redux | `Msg` 封闭联合 + `update` 穷举 + `view : Model → Html Msg` 纯函数 | `command(string)` + `handle()` + `build()` | **借「穷举」**（§3.5），不借「全局单一 Store」（本仓多窗口按窗隔离 Store 是 `CommandRouter` 两层表的正结论） |
| React | `useSyncExternalStore` + selector + `memo` + `key` | `SubjectBuilder::select()` + 相等门控 + `keyOf()` | selector 的「按引用相等早退」本仓已有；缺的是细粒度订阅（§3.6） |

---

## 6. 结合 PHP 与 TypePHP 的设计取向

这一节决定方案该往哪边长，避免「照抄别的框架」或「照抄 Drift3」。

**PHP 这边的优势（该用足）**

- 数组是**值语义 + COW**：整份 `list<Row>` 传参几乎免费，`===` 天然是
  「同键序 + 同类型 + 同值」的深比较 ⇒ 标量数组做快照门控非常便宜可靠。
  代价是元素为对象时退化为身份比较（§3.3 的盲区）。
- `readonly final class` 值对象 + 命名参数 + `enum` + `match` 穷举 +
  `token_get_all` 静态分析：契约可以用**类型**表达，不必用字符串和约定。
- 一等公民闭包让 `select()` / `builder()` 的 DX 极好 ——
  这是本仓比 Drift3（N4 禁闭包）舒服的地方，**不要为了「隔离」砍掉它**。

**TypePHP AOT 这边的优势与限制（刚解禁的那一批）**

- 已兑现：#183 链式、#184 typed property / 常量 / 容器 / 接口去虚化、
  `?Obj` 保留 `php::Object` ⇒ **具名 `final class` 方法能静态直调**，
  生成物里 `php_drift__state__subjectbuilder__select(...)` 就是证据。
- 仍是黑盒：`\Closure` / `callable` 存进 Widget 字段后，闭包体读什么编译器看不见；
  `AsyncValue::fold()` 的实现刻意绕开 `($arms['data'])(...)` 正是因为「AOT 下没验证过」；
  闭包 arity 由 AOT 严格校验，本仓为此专门有 `callback-arity` lint。
- `array` 元素是 `php::Var`（`?array` 仍退化），反射 / 动态 include / `eval` 不可用。

⇒ **取向**：**用 PHP 的值语义与 `match` 穷举表达「数据契约」，
用 AOT 的静态直调换掉「热路径上的闭包契约」；语法糖层继续用闭包**。

具体三条判据，凡新方案都要过：

1. **能不能被 `token_get_all` / 类表静态看见？**（看不见 ⇒ 只能靠运行期，
   就得配挂载期守卫，像 `TableContract::assert()` 那样）
2. **业务对象有没有跨进 Widget 的闭包体？**（跨了 ⇒ AOT 里就是 `php::Var` +
   动态分派，且实体语义漏进 UI 层）
3. **新增的是不是第二条数据通道？**（是 ⇒ 先问能不能由已有通道生成样板）

这也解释了为什么 §3.4 建议**新增第二通道而非替换**：闭包 API 的 DX 价值真实存在，
一次性破坏只会把用户赶回手写样板；等具名契约有了消费者，再谈废弃。

---

## 7. 方案骨架（设计草案，不可编译）

三条通道收口后的目标形状，用来判断「改完到底少了什么」：

```php
// ① 读：一个订阅点管四态 + 派生行，消掉 UsersPage 的两层嵌套
AsyncView::of($store->page())                       // AsyncCommand 的值对象
    ->project(fn(UserStore $s): RowContract => UserRows::of($s->visible()))
    ->states(
        loading: SkeletonList::make(8),
        error:   fn(string $e): Widget => ErrorBanner::make($e)->retry('users.refresh'),
        idle:    EmptyHint::make('输入关键词开始搜索'),
        // data 支由 AsyncView 自动把 project() 的结果交给表格
    )
    ->data(fn(RowContract $rows): Widget => Table::makeWith($rows, self::columns()));

// ② 写：命令是唯一「可枚举」的回写，输入类回调只做纯 UI 态
TextField::make()->value($q)->onChanged('users.set-query')   // 字符串 ⇒ 走命令，不写 Store
Button::primary('刷新')->command('users.refresh');

// ③ 契约：行身份 / 显示 / 复制 / 排序一份实现，且是 final 类 ⇒ AOT 静态直调
final class UserRows implements RowContract { /* count / keyAt / textAt / cellAt */ }
```

注意 ② 是把 §1.2 的两条通道**收敛成一条**（`onChanged` 也走命令名），
这是唯一一处建议的**兼容性破坏**：所有 `onChanged(fn(...) => …)` 要改成
`onChanged('命令名')` 或保留旧重载。它的收益是命令名册完整、可录制、可单测、
`dead-command` 覆盖全部写点；代价是迁移面覆盖所有表单控件。
⇒ **建议排在 §3.1 / §3.5 的 lint 落地之后单独开窗口**，不要与别的破坏性改动混批
（这正是 `PLAN-REVIEW-FIX-2026-11-11.md` 里「破坏性窗口独立排」的同一条纪律）。

---

## 8. 优先级与迁移路径

| 序 | 动作 | 破坏性 | 前置 | 验收判据 |
|---|---|---|---|---|
| 1 | `reactive-read` 路径 lint（§3.1） | 无 | 无 | 三条变异：挪出 `select` 红 / 补标记接口仍红 / 放回 `select` 绿 |
| 2 | 可编译的 tutorial 数据 Stage + 文档代码块收口（§3.2） | 无 | 无 | `gate --fast` 含它；文档块方法名过 `api-doc-check` |
| 3 | `selectVersioned()` + `select-purity` 收窄（§3.3） | 无 | 1 | `array<User>` 夹具红、`array<int>` 夹具绿；`UserStore::visible()` 切过去行为不变 |
| 4 | `command-cases` lint（§3.5 前半） | 无 | 2 | 删 `'user.del'` case 红；`default` 改抛错绿 |
| 5 | `RowContract` 第二通道 + AOT 静态直调 A/B（§3.4） | 新增 API | 3 | `bin --dry` 夹具断言契约版目标函数 0 处 `typephp_call_method_cached`；`TableContract` 对两通道同时生效 |
| 6 | `AsyncView` 合成订阅点（§4 最后一行） | 新增 API | 3、5 | `UsersPage` 迁移后 `SubjectBuilder` 嵌套层数 4 → 1，golden 零变化 |
| 7 | `onChanged` 收敛到命令（§7 ②） | **破坏性窗口** | 4 | 全仓写点扫描零遗漏；`dead-command` 覆盖全部 |

第 8 步之后才谈 `ChangeSet` 增量（§3.6）与自动依赖追踪（§3.7），
两者都要先有「大数据应用」与「作用域裁决稿」这两个前置。

**本轮只交付评审，不落地任何一步。** 用户可按序号指定要开工的那一棒。

---

## 9. 明确不做 / 待裁决

**不做（与既有裁决一致）**

- 不引入 `BuildContext::of()/find()` / `InheritedWidget` / 全局 `AppScope`（DEBT §18）。
- 不做双向绑定 `bind()`（DEBT §19.2 + §20 的 undo 语义），只做表单脚手架。
- 不做 MobX / Compose 式自动依赖追踪（DEBT §19.2「留」；§3.7 给出不翻案的理由）。
- 不强制全标量 DTO 投影、不禁止闭包、不把业务实体赶出页面签名（§4 的错配分析）。
- 不合并两条写通道之外的第三条：`State` 局部 UI 态（滚动位置 / 选中集 / 编辑草稿）
  仍归 `State`，不上 Store —— 这是 §12.3 的既有判据，本轮复核后不变。

**待裁决**

| # | 问题 | 为什么现在不能拍 |
|---|---|---|
| Q1 | 要不要给 Element 树可继承作用域（推翻 §18 ③） | §3.1 的 lint 已能用零破坏覆盖同一个 bug，需要「为什么值得」的新证据 |
| Q2 | 命令名 `string` → `enum` | 穷举收益已由 `command-cases` lint 拿到，破坏面却覆盖全仓 |
| Q3 | `fold(array)` → `AsyncStateView` 接口四方法 | 需先量 AOT 接口方法分派；运行期判据已经够硬 |
| Q4 | `Table` 行身份：`->key($i)`（视口序号）与 `$keyOf($row)`（数据身份）何时收口 | §66 刚立两套身份的判据；增量通知（§3.6）必须以它为前置 |

---

## 10. 结论台账

| 项 | 现行结论 |
|---|---|
| 本仓缺的是隔离吗 | **不是**。§18 的「组合点注入 + 控件不自取数据」已机械化；缺的是**读点位置 / 行取值 / 命令名册三处契约** |
| Drift3 那份规格能整份搬吗 | **不能**。它的禁令服务于 dep-table 编译期依赖推导，本仓是 `Subject` 推模式，照搬只留成本 |
| 最值钱的一条 | `dead-subscription` 是**标记接口判据**：补一个空接口就能把「读快照」洗绿 ⇒ 需要 `reactive-read` 路径判据（§3.1） |
| DX 最值钱的一条 | 可抄样板住在文档里、API 已漂移、不参与编译 ⇒ 搬进 tutorial Stage（§3.2） |
| AOT 相关的真缺口 | 框架方法已静态直调，**闭包契约仍是黑盒** ⇒ 具名 `RowContract` 有可量的去虚化收益（§3.4） |
| 允许破坏的那一处 | `onChanged` 收敛到命令（§7 ②），独立排破坏性窗口 |
| 与既有裁决冲突处 | 只有 §3.7（自动追踪）与 §19.2 / §18；本轮**明确不翻案**，登记为 Q1 |
| 验证口径 | 本轮只读评审，未跑 build / gate / 真机；每条方案都给了「怎么咬红」的变异方向，落地时按 T0/T1/T2 分级 |

---

## 附 · 本轮核对过的事实锚点

- 读通道：`Subject::notifyChanged()` / `SubjectBuilderState::onSourceChanged()` /
  `sameValue()` / `sameContent()` / `Computed::get()` / `State::watch()` /
  `StatefulElement::unmount()` / `PumpHost::complete()` / `Frame::run()` / `Frame::settle()`。
- 写通道：`CommandRouter::on()` / `CommandRouter::dispatch()` /
  `CommandHandler::handle()` / `DeadCommand` / `UserCommands::handle()`。
- 控件契约：`Table::make()` / `Table::keyOf()` / `TableColumn::field()` /
  `TableColumn::cell()` / `TableColumn::copyTextOf()` / `TableContract::assert()` /
  `Each::items()` / `AsyncValue::fold()`。
- 业务侧：`UsersPage::build()` / `UserStore`（`absorb()` / `setQuery()` / `visible()` /
  `fetch()`）/ `UserRow::make()` / `AsyncTablePage::columns()`。
- 生成物：`apps/users/build` 里 `UsersPage` 的 `.cc`（`php::newClosureWithParameters`、
  `typephp_call_method_cached`、`php_drift__state__subjectbuilder__select` 直调）。
- 既有裁决：DEBT §18（组合点注入）、§19.2（自动订阅 / `bind()` 判「留」）、
  §20（外部 `setText` 清空 undo 栈）、§23.3（`equals()` 注入口的教训）、
  §66（Table 行两套身份）。

---

## 11. 追问：闭包之外还有什么（2026-11-11 21:15 补）

> 本节是对 §3.4 的**扩展与修正**：把闭包换成具名 `final class` 只是「把代码换成对象」，
> 既不是唯一解，也不是最优解。真正消灭闭包的路子是把**代码降级成数据**。
> 与 §3.4 / §4 / §8 冲突处以本节为准；旧判断保留在原文，不回改。

### 11.1 先拆开「闭包」：三个位置，三种答案

| 位置 | 闭包 | 本质 | 能变成数据吗 | 推荐替代 |
|---|---|---|---|---|
| ① 订阅 | `SubjectBuilder::select()` / `builder()` | 一段**计算**（读什么、何时重画） | 常见形态能，复杂形态不能 | 枚举投影名（B3）作可选通道；复杂计算继续留闭包 |
| ② 行取值 | `cell()` / `keyOf()` / `copyText()` | 一行 → 单元格/身份/文本的**映射** | **能，而且几乎全都能** | 字段名 + 值对象格式描述（B1+B2+B4） |
| ③ 回写 | `onChanged()` / `onSelectionChange()` | 用户意图 → 业务 | **已经能** | `command('x')` 字符串名，本仓写通道已是这个形状 |

⇒ 上一轮 §3.4 只处理了 ②，而且选了「换成对象」这一族；③ 本仓早就解决了一半
（`Button::command()`），① 则被判定「保留闭包作语法糖」。这一轮的问题是：
**有没有连 ① 也不用写、且 AOT 完全看得见的形态。**

### 11.2 方案空间：按「用户是否提供可执行代码」分族

| 族 | 形态 | AOT 可见性 | DX | 本仓现成零件 | 结论 |
|---|---|---|---|---|---|
| **A · 仍是代码** | A1 具名 `final class` + 具名方法（§3.4 的 `RowContract`） | 好：具体类型可静态直调 | 中（要写一个类） | `ClassTable` / #184 去虚化 | **保留作逃生口**，不作主推 |
| | A2 invokable object `__invoke` / first-class callable `$o->m(...)` | 差：运行时仍是 `Closure` | 好 | 无 | 不解决黑盒，**不选** |
| | A3 `[$obj, 'method']` callable 数组 | 更差：数组元素是 `php::Var` | 差 | 无 | **不选** |
| **B · 只给数据/声明** | **B1 字段名字符串**：`field('name')` / `rowKey('id')` / `select('query')` | **最好**：框架内静态取值器 + 字符串常量 | **最好**：列定义变成一张表 | `field(string)` 已有；`command(string)` 已是同构 | **主推** |
| | **B2 值对象格式描述**：`CellSpec::badge('status', …)` / `CellSpec::date(…)`，框架 `match` 穷举渲染器 | 最好 | 好 | `AsyncValue::fold()` 的穷举纪律可复用 | **主推**（富单元格） |
| | **B3 枚举投影名**：`select(Projection::Visible)`，框架 `match` 到 Store 固定读方法 | 最好：编译期穷举 | 好 | `match` 已用 15 处且 T2 通过 | **主推**（订阅的常见形态） |
| | **B4 全标量行 DTO**：行本身是 `array<string,scalar>` 或 `final readonly class Row` | 最好：标量无装箱 | 好 | PHP 数组值语义 + COW | **主推**（顺带解掉 §3.3 的 `array<User>` 门控盲区） |
| **C · 干脆不写** | C1 构建期代码生成：写 `TableSpec` ⇒ 生成 `final class UserRows` + 订阅样板 | 最好：产物是静态 PHP | 最好 | 有 `StalePolicy` / 指纹 / `--dry` 门禁；但 `drift-codegen` **现在只翻译、不生成 PHP** | **最有长期价值，先别做** |
| | C2 运行期读记账：`rebuild()` 窗口内记录读过的 Subject 字段 ⇒ 自动订阅 | 中（多一次记账） | 最好：不用写 `select` | `Pipeline` 已是帧记账唯一落点 | **唯一能彻底消灭 ① 的方案**，见 §11.5 翻案条件 |
| | C3 tpc 编译期插桩推依赖 | 最好 | 最好 | 依赖上游、无本仓控制权 | 不选 |
| **D · 明确不选** | 魔术方法 `__get` / PHP 8.4 property hooks | 未验证 | — | **`src/` 里零使用** | 不选：AOT 未验，且「不报错只是没效果」风险最高 |
| | `InheritedWidget` / 全局作用域 | — | — | §18 否决 | 与闭包不同维度（数据**从哪来** vs 契约**长什么样**），别混 |
| | 双向绑定 | — | — | §20 / §19.2 裁决 | 不选 |

### 11.3 裁决：最优解不是「换对象」，是「降级成数据」

把 B 族推到主位的四条硬理由：

1. **标量是编译器和 lint 都能看见的符号**。tpc 对标量 + 具名 `final` 方法能静态直调，
   对 `\Closure` 永远是黑盒（生成物里 `php::newClosureWithParameters` +
   `typephp_call_method_cached`）。Drift3 的「命令只发 `int`/`string`」之所以成立，
   不是因为它讨厌对象，而是因为标量可枚举 —— **这条原则该推广到读通道**，
   而不是推广它「禁业务实体」那条。
2. **顺带修掉 §3.3**：标量数组的 `===` 是稳定的值比较，`array<User>` 才按对象身份比。
   B4 让相等门控从「可能误判」变成「结构上不会误判」，比补 `equals()` 更根治。
3. **机检零件现成**：`ClassTable` / `token_get_all` 已在 `select-purity`、
   `dead-command`、`dead-subscription` 里跑通；字段名存在性对账是 `dead-command`
   的同款（「声明名 ⊆ 实际可读字段」），不需要新机制。
4. **心智模型统一**：读用名字、写用名字，两边都是可枚举符号。
   本仓 `command('users.refresh')` 已经有 `dead-command` 双向对账，
   读通道补一个 `dead-field` 就对称了。

**修正后的配方**（替换 §3.4 的 `RowContract` 主推地位）：

```php
// 设计草案，不可编译
Table::make([
    Column::make('name',  '姓名')->field('name')->sortable(),
    Column::make('email', '邮箱')->field('email'),
    Column::make('state', '状态')->format(CellSpec::badge('status', [
        'active' => BadgeTone::Success, 'locked' => BadgeTone::Warning,
    ])),
    Column::make('since', '注册')->format(CellSpec::date('createdAt', 'Y-m-d')),
], $rows /* array<string,scalar> */)
   ->rowKey('id')                       // 字符串字段名，不是 keyOf(callable)
   ->onSelectionChange('users.select'); // 名字，不是闭包

SubjectBuilder::make($store)
   ->select(Projection::Visible)        // 枚举，不是 select(callable)
   ->builder(…);                        // 只有「造子树」这一段仍是代码，且可以生成
```

★ `RowContract`（A1）**不删**，降级成「富单元格 / 复杂派生」的逃生口：
声明式协议覆盖不了的那 20%，仍由具名 `final class` 承担，AOT 照样静态直调。
⇒ 这是 80/20，不是「全声明 or 全代码」的二选一。

### 11.4 C1：构建期生成，为什么最有长期价值却该最后做

形状：开发者写 `TableSpec`（值对象或 yml，纯数据）⇒ `drift-codegen` 生成
`final class UserRows`（`count/keyAt/textAt/cellAt`）+ 订阅样板 ⇒ 生成物是**静态 PHP**，
`php -l` / lint / 单测 / tpc 全都看得见，零闭包、零动态分派、零手写样板。

代价（四条，都不是免费的）：

- `tools/drift-codegen.php` 现在的 `file_put_contents` 只写假 `cl.exe` 与产物清单，
  **没有生成 PHP 源码的通道** ⇒ 要新建一层，不是拼零件；
- 构建指纹必须收 `TableSpec`（本轮刚因指纹漏输入修过一次，同类洞不能再开）；
- 生成物进不进 git、调试栈跳到生成代码、IDE 跳转断链，都是新的维护面；
- 它本质上是「把 B 族的样板自动化」，**B 还没落地就先做 C1，等于给未验证的
  形状写生成器**（DEBT §23.3 那条「框架缺能力 vs 缺 API」的同款教训）。

⇒ 顺序：先 B 有真实消费者，再让 C1 把 B 的样板生成掉。别反过来。

### 11.5 C2：唯一能彻底消灭订阅闭包的方案，及翻案条件

**具体形状**（不推翻 §18：数据仍由组合点注入，不加 `InheritedWidget`）：

- `Subject` 增加字段读取协议 `read(string $key): mixed`（或 `get*` 具名方法 +
  一个 `FIELDS` 常量登记可订阅字段）；
- `Pipeline` 在 `StatefulElement::rebuild()` 期间开一个**读记账窗口**
  （`Pipeline` 本就是帧记账唯一落点，不新增 static 中枢）；
- 窗口内每次 `Subject::read()` 记下 key，重建结束把「本次读过的 key 集合」
  写成该 Element 的订阅，下次这些 key 的 `notifyChanged()` 自动标脏；
- `Subject::notifyChanged()` 增加可选 `?string $changedKey`，未给 ⇒ 全量（向后兼容）。

**代价**：每次字段读多一次记账；`build()` 里条件分支导致「这次没读到的字段」
会被退订（正确但要写明）；最大风险是新增一类**静默不更新**——
记账漏一处比手写 `select` 漏一处更难查，因为它没有「少了哪行代码」这个线索。

**翻案条件**（三条要同时成立，才值得推翻 DEBT §19.2 的「留」）：

1. B3 枚举投影名落地后，仍有 ≥3 个真实页面因「订阅形状复杂」写不动；
2. 有一条能证伪的变异牙检：「故意在 `read()` 里漏记一个 key」⇒ 必须报红
   （不然就是又一个假绿判据）；
3. 量过读记账的热路径开销，确认不比 `select` 的整数组 `===` 更贵（§3.3）。

⇒ 现在三条一条都不成立，**维持不翻案**。它是本轮唯一「连 ① 都不用写」的方案，
登记在 Q1，等证据。

### 11.6 对前文的增量判据

| 判据 | 钉住什么 | 变异方向 |
|---|---|---|
| `dead-field`（`dead-command` 同款） | `field('x')` / `rowKey('x')` / `format('x')` 的字段名在行契约里真实存在 | 把 `field('nmae')` 打错一个字母 ⇒ 报红 |
| `cell-spec-exhaustive` | `CellSpec` 渲染器的 `match` 无 `default`（借 §3.5 的穷举纪律） | 删一个 case ⇒ 报红 |
| AOT A/B（`bin --dry`） | 声明式版目标函数里 `typephp_call_method_cached` 计数为 0；闭包版作为对照 | 把声明式版换回 `cell(callable)` ⇒ 计数必须 > 0 |
| `projection-case` | B3 枚举的每个 case 都在 `Subject` 的 `FIELDS` 里有对应读方法 | 新增枚举 case 不补读方法 ⇒ 报红 |

### 11.7 修正后的结论（覆盖 §10 对应行）

| 项 | 修正后结论 |
|---|---|
| 「闭包之外更好的方案」 | **有**：不是把闭包换成具名对象，而是把**代码降级成数据**（字段名 / 枚举投影名 / 值对象格式描述 / 全标量行） |
| §3.4 的 `RowContract` | 从主推**降级为富单元格逃生口**；主推改为 B1+B2+B4 声明式列协议 |
| ① 订阅闭包 | 常见形态改 B3 枚举投影名；复杂计算保留闭包；C2 是唯一彻底解，但需三条翻案证据 |
| 长期最优 | C1 构建期代码生成，但必须排在 B 有真实消费者之后 |
| 明确不选 | A2/A3（仍是闭包）、魔术方法 / property hooks（AOT 未验且 `src/` 零使用）、`InheritedWidget`（不同维度）、双向绑定（已裁决） |

---

## 12. 成熟 GUI 框架用什么方案，以及它们踩过的坑（21:17 补）

> §5 给过一张简表；本节是**针对「闭包 / 数据契约」这个问题的深化**，冲突以本节为准。
> ★ 判据先立：**「写起来像闭包」≠「运行时黑盒」**。
> SwiftUI 的 `@ViewBuilder`、Svelte 的模板、Compose 的 `@Composable` 在源码里都长得像闭包，
> 但编译期展开成具体类型 / 静态更新语句 ⇒ 编译器看得见。
> 本仓该问的不是「源码里有没有 `fn`」，而是
> **「tpc 生成物里是不是 `php::Var` + `typephp_call_method_cached`」** —— 这才是 AOT 口径。

### 12.1 三条路线：整个行业二十年是从 R1 往 R3 走

| 路线 | 代表 | 用户写什么 | 谁做「行 → 单元格 / 数据 → UI」的映射 | 本仓对应 |
|---|---|---|---|---|
| **R1 运行期委托** | Flutter 的 `builder:`、React 的 selector / `render`、WPF 的 `IValueConverter` 部分、本仓现状 | 闭包 / 委托 | 运行时调用 | **现状**：生成物里 `php::newClosureWithParameters` + 动态分派 |
| **R2 声明式数据 + 框架取值器** | WPF `Binding Path`、Qt `data(role)`、Elm `Html Msg`、SwiftUI result builder、WinUI `x:Bind` 的生成物 | 字段名 / 枚举 / 值对象 | **框架内静态代码** | **§11 的 B 族（主推）** |
| **R3 编译期生成 / 自动追踪** | Svelte 模板编译、React Compiler、Vue 模板编译、Compose Snapshot、WinUI `x:Bind` | 模板 / 普通字段读 | 编译器插桩或生成代码 | §11 的 C1（长期）/ C2（不翻案） |

★ **对 AOT 最关键的一句**：tpc 恰恰**最吃 R2/R3、最不吃 R1** —— 编译器要看得见才能
静态直调。本仓停在 R1 不是「跟 Flutter 一样」，而是「用了 Flutter 的形状，
却没有 Dart 那种对闭包友好的运行时和廉价重建」（§2 的帧税三数就是反证）。
⇒ 跳到 R2 不是激进，是补上这一课；C1 是够得着的 R3，因为本仓已经有
`drift-codegen` 这道 codegen 门、`StalePolicy` 和输入指纹。

### 12.2 逐框架：方案与它自己踩过的坑

| 框架 | 数据契约方案 | 踩过的坑（有名字、有官方修法） | 对本仓的落点 |
|---|---|---|---|
| **WPF / XAML** | `Binding Path` 字符串路径、`DataTemplate` 按类型选模板、`ItemsSource` + `ObservableCollection`、`ICollectionView` 把排序/过滤放 VM、`ICommand.CanExecute`、`INotifyPropertyChanged` 按属性名广播 | ① **`{Binding Nmae}` 拼错不报错**，只在 Output 窗口打一条 binding trace，界面静默空白；属性名字符串改名不联动（后来加 `[CallerMemberName]`、Fody 织入）；② **`ICommand` 漏 `RaiseCanExecuteChanged()` ⇒ 按钮永久灰**；③ `TextBox` 双向绑定默认 `LostFocus` ⇒ 「改了没提交」；④ `ObservableCollection` 跨线程改抛异常 | ①⇒ **`dead-field` lint + B3 枚举投影名**；②⇒ **与 `UsersPage` 刷新按钮永久灰是同一个 bug 形状**，靠 `reactive-read` + `AsyncCommand::isRunning()` 订阅；③⇒ §20 受控语义不照抄；④⇒ §3.6 增量通知暂不做的旁证 |
| **WinUI `x:Bind`** | 把 WPF 的运行时字符串 **`Binding` 改成编译期生成强类型代码** | 修掉了「拼错静默」，但 `x:Bind` **默认 `OneTime`**，漏写 `Mode=OneWay` ⇒ 数据变 UI 不变，又是一种静默 | ★ **B1 → C1 演进的最直接历史证据**：字符串声明先解决「代码变数据」，再由生成解决「名字可校验」。本仓两步都要，顺序不能反 |
| **Qt Model/View** | `QAbstractItemModel::data(index, role)` **统一取值协议**（一个具名方法 + role 枚举）、`QSortFilterProxyModel`、`QStyledItemDelegate` 绘制/编辑分离、`dataChanged(topLeft, bottomRight, roles)` 细粒度信号 | ① `beginInsertRows`/`endInsertRows` **必须成对且顺序正确**，漏了视图索引错乱甚至崩；② `data()` 里做耗时计算 ⇒ 每次 paint 都问一遍，卡顿（必须缓存/预投影）；③ 自定义 `role` 是裸 int 从 `Qt::UserRole` 起，容易撞号；④ **代理模型「源 index vs 代理 index」混用是经典 bug** | ①⇒ §3.6 不现在做增量通知；②⇒ `Computed` / 投影层；③⇒ 枚举优于裸名（B3）；④⇒ **与本仓 §66「视口序号 `->key($i)` vs 数据身份 `$keyOf($row)`」完全同构**，两套身份不能收得过快 |
| **Flutter** | `Widget` 不可变配置 + `build()` 纯函数；`ValueListenableBuilder` / `StreamBuilder` 仍用 `builder:` 闭包；`DataRow`/`DataCell` 是**值对象**；`ValueKey`/`ObjectKey` 行身份；`ChangeNotifier` 粗广播；`Selector.shouldRebuild` 判等 | ① **`Selector` / `ChangeNotifier` 用 `==` 判等，可变对象 ⇒ 不重建**（官方要求不可变数据或 `Equatable`）；② `builder` 里发起 IO / 副作用 ⇒ 每次重建重复执行；③ `ListView.builder` 里给每行建 notifier ⇒ 复用错状态；④ 早期 `InheritedWidget.of()` 重建太粗 ⇒ 才有 `Provider.select` / Riverpod | ①⇒ **本仓 `sameValue()` 的 `===` 与 §3.3 `array<User>` 盲区**；②⇒ §18 与 `UsersPage` 快照 bug；③⇒ `nested-subscribe` lint 已经在守；④⇒ R2 是「粗广播 → 细粒度」的低成本版 |
| **Jetpack Compose** | `State<T>` + **Snapshot 运行期读记账**（自动依赖追踪）、`derivedStateOf`、智能重组跳过、`@Stable`/`@Immutable` 契约、`ViewModel` + `StateFlow`、`LazyColumn { items(key = …) }` | ① **`@Stable` 标在可变类上 ⇒ 重组被跳过、UI 不更新且不报错**；② **「读发生在追踪窗口之外」（`remember{}`、lambda 里读）⇒ Snapshot 记不到依赖 ⇒ 静默不更新**，官方靠 compiler metrics / 强跳过模式查；③ `derivedStateOf` 依赖没读全 ⇒ 永远不更新；④ `key` 用 index ⇒ 插入行状态错位 | ★ **Compose 就是 C2 的成熟版，也证明了 C2 的调试成本**：本仓没有编译器插件与 metrics，硬上 C2 等于只拿到坑、拿不到工具 ⇒ §11.5 的三条翻案条件；①⇒ `select-purity` 正在防这个；④⇒ §66 |
| **SwiftUI** | `@ViewBuilder` **编译期 result builder** 展开成具体泛型 `some View`；`@StateObject`/`@ObservedObject`/`@Published`；`ForEach` 要 `Identifiable` | ① `ForEach` 用 `\.self`/index 作 id ⇒ 状态与动画错乱；② `@Published` 粗广播整个 View 重算，iOS 17 的 `Observation` 框架才做细粒度；③ `@StateObject` vs `@ObservedObject` 选错 ⇒ 状态被重建清空；④ 分支类型不一致要用 `AnyView` 擦除 ⇒ 退化为动态 | ★ **「看起来像闭包」≠「黑盒」的最佳反例**：result builder 编译期展开 ⇒ 支持 §12 开头那条判据；②⇒ §3.6；③⇒ 本仓「盒在重建时换新 / `inheritRuntimeState()`」同族；④⇒ 避免 `php::Var` 合流 |
| **React / Redux** | `useSyncExternalStore` + selector（仍是闭包）、`React.memo` 引用相等、`reselect` 记忆化、`key`、Zustand/Jotai 原子订阅、Preact Signals / SolidJS 自动追踪 | ① **`useSelector(s => ({a: s.a, b: s.b}))` 每次返回新对象 ⇒ store 一动就重渲染**（Redux 文档专门警告，修法是 `shallowEqual` / `reselect`）；② **`useCallback` 依赖数组漏一项 ⇒ stale closure**（捕获旧值）；③ `key={index}` ⇒ 列表复用错状态；④ React Compiler（原 Forget）自动记忆化补运行期纪律 | ①⇒ **与本仓「`select` 每次 new 等价对象 ⇒ `===` 永不相等 ⇒ 每帧重建」是同一个 bug**（`SubjectBuilderState` 的 `JITTER_ALERT` 就是为此写的）；②⇒ **`UsersPage` 刷新按钮读快照同族**；④⇒ C1 |
| **Elm / TEA** | `Model` 单一不可变状态、`Msg` 封闭联合、`update` 穷举 `case`、`view : Model -> Html Msg`（**视图是值，没有回调取值器**）、`Cmd`/`Sub` | ① `Msg` 随应用规模爆炸；② 没有组件局部态，每个小组件都要套一层 TEA ⇒ 样板多；③ `Cmd` 订阅要手写 | ★ **B 族「代码降级成数据」的极致**：消息是数据、视图是数据。借它的穷举（§3.5）与「视图是值」，**不借单一全局 Store**（本仓多窗口按窗隔离是 `CommandRouter` 两层表的正结论） |
| **Svelte / Vue** | 模板**编译期生成**细粒度更新语句（`if (changed.b) set_data(...)`），运行时无 vdom diff；Vue 模板编译成 render function | 生成代码调试栈、编译期与运行期语义差、模板表达能力上限 | ★ **R3 最纯粹的形态**，也是 C1 的教科书；代价与本仓 `StalePolicy` / 生成物维护面完全一致 |

### 12.3 跨框架重复出现的六个坑（每个都能在本仓找到对应）

| # | 坑 | 出现在 | 本仓现状 / 该补什么 |
|---|---|---|---|
| 1 | **名字拼错静默**：字符串路径、属性名、命令名写错，界面空白但不报错 | WPF `Binding` / `INotifyPropertyChanged` / 本仓 `command()` | `dead-command` 已守写通道；读通道要补 **`dead-field`**（§11.6），并用 B3 枚举把常见形态从字符串升级成可穷举符号 |
| 2 | **selector 返回新对象 ⇒ 白重建 / 或不重建** | Redux `useSelector`、Flutter `Selector`、本仓 `===` 门控 + `array<User>` | `JITTER_ALERT` 与 `select-purity` 已在守**顶层对象**；缺的是数组内对象 ⇒ §3.3 + B4 标量行 |
| 3 | **行身份用 index ⇒ 复用错状态** | Flutter `ValueKey`、Compose `LazyColumn key`、SwiftUI `ForEach id`、React `key`、Qt 代理索引 | `TableContract::assertKeyOf()` **已守**；§66 两套身份是同一个问题的另一半，**别为了增量通知过早收口**（§3.6） |
| 4 | **可用态漏刷新 ⇒ 按钮永久灰** | WPF `ICommand.CanExecute` 要手动 `RaiseCanExecuteChanged` | **`UsersPage` 2026-10-31 真机 bug 就是它**；`AsyncCommand::isRunning()` 已有不变式，缺的是「读点必须在订阅里」的判据（§3.1） |
| 5 | **细粒度通知的仪式成本** | Qt `begin/endInsertRows` 必须成对、WPF `ObservableCollection` 跨线程、Compose Snapshot 写入约束 | `ChangeSet` 现在不做是对的（§3.6）；要做先解决 §66 身份收口 |
| 6 | **自动追踪「读在窗口外」⇒ 静默不更新** | Compose Snapshot、React stale closure | 这正是 C2 最大风险 ⇒ §11.5 翻案条件第 ② 条要求「故意漏记一个 key 必须报红」的变异牙检 |

### 12.4 借鉴与排坑的结论

**可借（按性价比）**

1. **Qt 的 `data(role)` 统一取值协议** —— 一行一个角色、框架调具名方法、UI 不提供回调 ⇒ B1+B4。
2. **WPF → WinUI 的演进顺序** —— 先「字符串声明」解决代码变数据，再「编译期生成」解决名字可校验；**别反过来先写生成器**（§11.4）。
3. **Elm 的「视图是值」+ `Msg` 穷举** —— 读通道与写通道都用符号，两边都可枚举（§3.5 / B3）。
4. **SwiftUI result builder 的判据** —— 判断标准落在生成物形态，不落在源码长得像不像闭包。
5. **Compose / Svelte 作为「长期方向」的锚** —— C1/C2 不是幻想，是别人已经走通的路；但要先有工具（codegen 门 / 变异牙检）再上路。

**明确排掉的坑**

- **别照抄 Flutter 的闭包密度**：它有 Dart 运行时和廉价重建，本仓有帧税和 AOT 黑盒。
- **别照抄双向绑定**：WPF `LostFocus`、SwiftUI `Binding` 都会引入第二写通道（§20 已裁决）。
- **别因为「字符串声明会拼错」就退回闭包**：WPF 的答案是加 lint 再升级到强类型生成，不是把回调请回来。
- **别把 `x:Bind` 默认 `OneTime` 那类「默认不更新」搬进来**：受控声明必须接上报（`TableContract::assert()`）本仓已经做对了，保持。
- **别在 §66 两套身份没裁决前做增量通知**：Qt 代理索引混用是前车之鉴。

**一句话**：成熟框架的共识不是「用不用闭包」，而是
**「让编译器／框架看见数据，别让运行时才第一次知道」**。
R1 → R2 的那一跳（声明式列协议）本仓四个零件已有三个，是最便宜的一跳；
R2 → R3 要等 B 族有消费者与变异判据，别抢跑。

> **落地方案（v2）**：`docs/DESIGN-UI-DATA-DX-FIRST-2026-11-11.md` **§11**。
> 本稿 §11 的方案空间（B/C 族）与 §12 的六个框架坑是 v2 的主要依据；
> 下方旧指向「三本名册设计稿」已作废 —— 用户授权允许破坏兼容性后，v2 改走
> 「构建期生成订阅」而非手写名册。本文 §7 / §8 仍是评审阶段草案。
