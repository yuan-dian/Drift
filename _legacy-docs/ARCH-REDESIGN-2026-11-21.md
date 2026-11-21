# 架构重设计提案 · 2026-11-21

> 出发点：showcase 34 页 5835 行，开发者心智负担偏重、代码量偏大；要看 UI 与数据的隔离与交互是否有新解法，以及代码分层是否可以重新设计。
> 口径：**不考虑兼容性**，允许推翻既有形状；约束是 AOT 语言事实（`docs/reference/aot-constraints.md`）与"不使用属性钩子"。
> 本文是**提案**，不是裁决。落地前每一项都要先过 §8 的验证判据。

---

## 0. 一句话结论

showcase 的 4065 行实际代码里，约 **18%（~730 行）是框架契约的税**，不是业务也不是演示：写一次值要三行（闭包税）、页面骨架每页手抄（外壳税）、`mixed` 传导到页面变判空（判空税）。三笔税同源——**框架只给了"原语"，没给"组合"**。

补上三层组合原语（`Cell` / `Section`+`PageBody` / `PageStore`）之后：实际代码降约 18%，但真正的收益是**每页要记住的规则从 7 条降到 3 条**，以及补上一个现在没人提但确实存在的缺口——**页面里那段纯函数逻辑无法单测**。

减量里最大的一块（400 行 import）**已实测可行**：`probes/cell_shape/` 在真机 bin 口径上验通了组 use、`Cell` 字段形状、变长参数、`__invoke` 派发四项（§10）。方案不押在未验的语法上。

最激进的一步不是加类，是**改 `State` 的写入协议**：现在的 `setState(callable)` 是"用闭包表达一次赋值"，闭包在这个场景里零作用（同步执行 + 标脏），只贡献三行缩进和一份 `use` 列表。

---

## 1. 取证（2026-11-21 本地 HEAD）

### 1.1 规模与构成

| 项 | 值 |
|---|---|
| `src/` | 451 文件 / 71064 行 |
| `apps/showcase/src/` | 7550 行 |
| `└ Pages/` | **5835 行 / 34 页** |
| 页面行数 | 均值 171 / 中位 132 / P90 298 / 最大 490（`TablePage`） |
| 页面行构成 | 注释 1167 + 空行 603 + **代码 4065** |
| 单页平均代码 | **119.6 行** |

### 1.2 重复度（这就是"税"的量化）

| 形状 | 次数 | 说明 |
|---|---|---|
| `setState(function () use (...): void { $this->x = $v; })` | **55** | 三行模板，`setState` 总计 64 处 |
| `Card::make(ScrollView::make($this->body()))->paddingAll(0.0)` + `private function body()` | **18 页** | 完全同形 |
| `return Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))` | **18** | 与上行成对，一字不差 |
| `private function sectionBar(string $title): Widget { return Text::make($title)->heading(); }` | **9 页** | 逐字复制 |
| `Card::make(Column::make()->spacing(X))->paddingAll(Y)` | **21** | spacing 有 4/6/8/10/12 五种取值 |
| `paddingAll(12.0)` | 35 | 魔法数字，`0.0` 另 30 次 |
| `Text::make('X')->title()` 与 `#[Route(title: 'X')]` 双写 | **35** | 35 处全部字面相同 |
| `use` 语句 | **552**（16.2/页） | `Drift\Widget\*` 占绝对多数 |
| `is_array(` | 24 | 全部在 `AsyncValue` 解包处 |
| `$this->x = ` 裸赋值（不在 `setState` 内） | 104 | 与闭包写法并存 |
| `Text::make(` / `->caption()` | 337 / 204 | — |

### 1.3 能力面的真实使用率

| 项 | 值 |
|---|---|
| 页面用到 `$this->caps->` | **3 / 34 页**（27 页完全不用） |
| 页面用到 `asyncCommand()` | 3 / 34 |
| 页面用 `Each::make()` | 0 / 34（全仓仅 3 处，均在其它 app） |
| 页面里 `foreach (` 手工拼 `$children[]` | **13** |
| 页面覆写 `initState()` | 少数（`onActivate` 更少） |
| 页面自写构造器 | **1 / 34**（只有 `SnackbarPage`，其余继承 `ShowcasePage` 的） |

> 27 页持有（并经 `protected` 可达）一组自己从不调用的能力。**这不是代码量问题**——构造器只在基类写一次，34 页各自不付行数。它是**依赖面**问题：类型上没有"我不用这个"的表达方式，只能靠自觉。修它不省一行代码，只让意图可见（见 §4.3a）。

---

## 2. 诊断：五笔税

### 2.1 闭包税 —— 写一次值要三行

**现场**（`SliderPage`，55 处同形之一）：

```php
private function pickVolume(float $value): void
{
    $this->setState(function () use ($value): void {
        $this->volume = $value;
    });
}
```

**为什么会写成这样。** `State::setState(callable $change)` 同步执行 `$change()` 再 `markDirty()`。闭包在这里承担的语义是"**改值 + 标脏是一个原子动作**"，对标 Flutter 的 `setState(() {...})`。

**但闭包在这个位置零收益。** 它不被延迟、不被缓存、不被重放——调完就丢。它唯一的作用是把一行赋值包成三行，并要求开发者手写 `use ($value)` 列表。代价是实的：

- 55 处 × 2 行 = **110 行纯模板**；
- 每处要记：`use` 列表别漏、`void` 返回类型别漏、闭包里不能 `return`；
- 闭包是**新的作用域**，`$this` 可达但局部变量不可达 ⇒ 复杂一点的写点会写出"捕获了错误的值"。

**更深的代价是分类负担。** 页面上现在有三条改值路径并存：

| 写法 | 出现在 | 语义 |
|---|---|---|
| `setState(fn...)` | 55 处 | 改值 + 标脏 |
| 裸赋值 `$this->page = $target;` | 104 处 | 改值**不**标脏，靠别处触发 |
| 命令 `$this->rows->run($p)` | 3 页 | 改业务态，命令自己通知 |

`AsyncTablePage.php:157` 的注释写着"重建在帧末 ⇒ 这里改值安全（不是 build 期写）"——**这句话是对的，但它需要一条注释来解释**，这本身就是负担的证据。开发者要在每次赋值时判断"这里该不该标脏"，而判断错了是静默的（改了不显示，或活锁）。

### 2.2 外壳税 —— 每个应用重新发明页面骨架

**现场**（18 页一字不差）：

```php
public function build(BuildContext $ctx): Widget
{
    return Card::make(ScrollView::make($this->body()))->paddingAll(0.0);
}

private function body(): Widget
{
    return Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
        Text::make('滑杆 Slider')->title(),
        // ……
    ]);
}
```

剩下 16 页因为内容差异（表格要 `Expanded`、日历要定高）自己写了一版**近似但不相同**的外壳。于是 34 页里没有两页的外壳是同一个东西，也没有一页的外壳是可以被审的。

**这不该由应用决定。** 页面骨架（滚动宿主 + 页边距 + 标题 + 小节间距）是**产品无关的**——换任何应用，这四个决定都一样。框架把 `Card` / `ScrollView` / `Column` 这些**原语**给对了，但没给"它们该怎么拼"的**组合**，于是每个应用抄一遍，抄出 21 种 `spacing` 取值。

对照：Flutter 有 `Scaffold`、Qt 有 `QMainWindow`、WPF 有 `Window`。这些都不是"布局算法"，是**槽位约定**。本仓的 A5（不做 CSS 层叠）否决的是层叠，不是槽位。

### 2.3 判空税 —— `mixed` 从异步层一路漏到页面

**现场**（`AsyncTablePage`，同一个 `is_array` 判三次）：

```php
// toolbar()
$raw = $value->dataOr(null);
if (\is_array($raw)) { $source = (string) ($raw['source'] ?? ''); }

// bodyOf() 的 data 支
if (\is_array($data) && isset($data['items']) && \is_array($data['items'])) { $rows = $data['items']; }

// pagesOf()
$data = $value->dataOr(null);
if (!\is_array($data)) { return 0; }
```

**根因是 AOT 约束一**：`AsyncValue::$value` 是 `mixed`，因为 PHP 没有泛型、AOT 也不支持。这不是可修的编译器缺陷，是语言事实。

**但"解包在哪一层"是可选的，而本仓已经做对过一次。** `UserStore::absorb()` 在**入口**把 JSON 解成 `list<User>`，之后页面只吃具体类型，零判空。`AsyncTablePage` 把 raw array 直接塞进 `AsyncValue`，于是判空漏到了三个渲染点上。

**所以缺的不是能力，是判据。** 现在没有任何机制说"`AsyncCommand` 的值必须在入口解码"——`AsyncCommand::value(): AsyncValue` 的 `mixed` 是敞开的，谁都可以往下漏。

### 2.4 分层缺一层 —— 纯逻辑掉进 State，于是不可测

**现场**（`TablePage`，490 行）：从第 276 行到末尾，`columnTitleOf` / `sortRows` / `comparators` / `mergeSort` / `compareChain` / `disabledRank` / `generateRows` —— **约 215 行零 Widget 依赖的纯逻辑**，全部 `private static`，住在 `State` 子类里。

它们**本可以直接单测**（给定 rows + sort，断言顺序），现在不行：`State` 要 `attach()` 一个 `Element` 才能跑 `initState`，`private static` 连反射都绕不过（同一个类内才可达）。结果是这段逻辑只能靠"挂整页 + 截图指纹"间接验证——**指纹只证明"没变"，不证明"对"**。

**为什么掉进 State。** 因为本仓只有两层：

| 层 | 生命周期 | 适用判据 |
|---|---|---|
| `State`（页面） | 随 Element | "只影响这一棵子树" |
| `Store`（`Subject`） | 随应用 | "离开页面还该跑完"（异步） |

两条判据都只谈**生命周期**，没有一条谈**可测性**。于是"同步、纯、但有点复杂"的逻辑（排序、过滤、选择集、格式化）在两边都不合 —— 它不需要跨页存活，所以不进 Store；它又不该住在不能测的地方，但没人拦着它进 State。**中间这层是空的。**

`AsyncTablePage` 的 `pagesOf()` / `loadingLabel()`、`LoadMorePage` 的合并逻辑、`ConcurrentFetchPage` 的结果聚合，都是同一类东西。

### 2.5 元数据税 —— 页面读不到自己的路由信息

**现场**：`#[Route(id: 'slider', title: '滑杆 Slider', ...)]` 与 `Text::make('滑杆 Slider')->title()` 双写，35 处全部字面相同。

`RouteCatalog` 启动期反射出 `RouteDescriptor`，之后导航项、页面工厂、自动化身份都查它——**唯独页面自己查不到**。`NavEntry::makePage(object $context)` 只传一个 context，descriptor 在调用点就被丢掉了。

35 处双写不是灾难，但它是**"真相有两个副本"**的形状问题：改标题要改两处，改一处就是漂移，而漂移没有任何判据能发现（金标量具只比渲染结果，不比语义）。

---

## 3. 成熟框架对照：借什么、不借什么

| 框架 | 机制 | 借 | 不借 / 它的坑 |
|---|---|---|---|
| **Flutter** | `Widget`/`State` 二分 + `setState` | `setState` 的"改值+标脏原子化"语义 | **二分**：A15 已正确否决（页面挂一次、长期保活，不需要"每帧换新描述"）。坑：跨 `async gap` 用 `BuildContext` —— Drift 对应的是"卸载后重挂"，已由 `State::attach()` Fatal 兜底，**这条做对了** |
| **Flutter** | `ValueNotifier` + `ValueListenableBuilder` | **可写单元自带标脏**（这就是 §4.1 的 `Cell`） | 它的 `addListener` 订阅图无生命周期兜底，泄漏靠人记 |
| **Compose** | `mutableStateOf` + `remember` + 重组作用域 | "读点决定重组粒度" | **重组粒度**：Drift 的 `markDirty()` 标的是整个 `State` ⇒ 整页重建。Compose 能做到"只有读了这个值的作用域重建"，Drift 只有 `SubjectBuilder` 能近似，`State` 内部没有等价物。⚠️ 见 §6 的取舍 |
| **Compose** | 无 `Widget`/`State` 两件套（`@Composable` 函数 + `remember`） | **单件套是可行的**（给 A15 一个外部佐证） | Kotlin 的 `@Composable` 靠编译器插件改签名，PHP 没有等价物 |
| **SwiftUI** | `@State` / `@Binding` 属性包装器 | "值语义 + 写入口收窄" | **实现**：属性包装器在 PHP 里就是属性钩子/魔术方法，**用户已明确禁止**，且 AOT 下 `__get`/`__set` 的生成形状无实测。只借语义，不借机制 |
| **SwiftUI** | `ViewBuilder` 结果构建器 | "声明的是一棵**描述**，不是命令式拼装" | 编译器插件，无法移植 |
| **Elm / Rust** | `struct` update 语义 + 单向数据流 | `AsyncValue` 的不可变 `fold()` 已经是这个路子（**做对了**） | 全局单一 Model + Cmd 队列，对桌面 GUI 过重 |
| **WPF** | `INotifyPropertyChanged` + MVVM | MVVM 的**分层意图**（View / VM / Model） | 坑：每个属性手写 `OnPropertyChanged("Name")` —— 正是 Drift 现在 `setState` 闭包的同一个坑，只是字符串换成闭包。而且字符串绑定（XAML）无编译期检查，Drift 的 `select` 闭包**比它强** |
| **Qt** | 信号槽 + `QObject` 树 | 父子所有权（Drift 的 `onDispose` 清理袋已等价） | 坑：`QObject` 生命周期靠人 `deleteLater`，悬空指针是经典事故 |
| **Avalonia** | Compose 化的 XAML | 槽位式布局（`DockPanel` / `Grid` 的 slot 语义） | — |

**三条可以直接引用的结论：**

1. **"单件套"有外部佐证**（Compose），A15 不是孤例。
2. **"可写单元自带标脏"是主流共识**（`ValueNotifier` / `mutableStateOf` / `@State`），三家用三种机制，但都是"值对象负责通知"，不是"调用点手写三行"。
3. **重组粒度是 Drift 相对 Compose 的真实缺口**，但它跟 A6（整树重排）绑在一起，动它要重开 A6 —— 本文不碰，只在 §6 登记。

---

## 4. 重设计：四层

### 4.0 总览

```
┌─────────────────────────────────────────────────┐
│ L3  PageStore   页面自有业务（随页面销毁、可单测）  │
├─────────────────────────────────────────────────┤
│ L2  Port       窄能力接口 + 入口解码（混合型收口）  │
├─────────────────────────────────────────────────┤
│ L1  Section / PageBody   骨架组合原语             │
├─────────────────────────────────────────────────┤
│ L0  Cell       可写单元（值 + 标脏，一行写完）      │
└─────────────────────────────────────────────────┘
```

四层各自解决 §2 的一笔税：L0 解闭包税、L1 解外壳税、L2 解判空税、L3 补分层空缺。§2.5 的元数据税由 L1 顺带（`PageBody` 从 descriptor 取标题）。

---

### 4.1 L0 — `Cell`：可写单元

**形状。** 一个持有值、自带标脏的**普通对象**（不是魔术方法、不是属性钩子）：

```php
// src/State/FloatCell.php —— 分类型的具体类（PHP 无泛型，只能一类一型）
final class FloatCell
{
    public function __construct(private float $v, private State $owner) {}

    public function v(): float { return $this->v; }

    public function set(float $v): void
    {
        $this->v = $v;
        $this->owner->markDirty();
    }
}
// 同形：IntCell / StringCell / BoolCell / 以及少量值对象 Cell（如 DateCell）
```

> **不建 `Cell` 接口。** 第一版草稿里有一个 `interface Cell { touch(): void; }`，但它**没有消费者**——`Section` 不收 `Cell`，`State` 的工厂返回具体类，没人按接口接收。按 A8 的口径（按真实消费者推进），无消费者的抽象是负债：它锁死了四个类的公共面，却换不来一个多态用法。真出现"要按接口收一堆 Cell"的消费者时再加，那时才知道接口该长什么样。

**页面侧怎么用。** `SliderPage` 的三组字段与三个 setter：

```php
// 现在：3 个字段 + 3 个三行 setter = 15 行
private float $volume = 62.0;
private float $level = 3.0;
private float $narrow = 25.0;
private function pickVolume(float $value): void { $this->setState(function () use ($value): void { $this->volume = $value; }); }
private function pickLevel(float $value): void  { $this->setState(function () use ($value): void { $this->level = $value; }); }
private function pickNarrow(float $value): void { $this->setState(function () use ($value): void { $this->narrow = $value; }); }

// 改后：3 个声明 + 0 个 setter = 3 行
private FloatCell $volume;
private FloatCell $level;
private FloatCell $narrow;

// initState()
$this->volume = $this->floatCell(62.0);
$this->level  = $this->floatCell(3.0);
$this->narrow = $this->floatCell(25.0);

// build()
->value($this->volume->v())
->onChanged($this->volume)      // ★ 直传 Cell 本身（已实测，见下"首类可调用"）
```

**`State` 的四个工厂**（`protected`，各一行，**不用联合类型**）：

```php
protected function floatCell(float $initial): FloatCell   { return new FloatCell($initial, $this); }
protected function intCell(int $initial): IntCell         { return new IntCell($initial, $this); }
protected function strCell(string $initial): StringCell   { return new StringCell($initial, $this); }
protected function boolCell(bool $initial): BoolCell      { return new BoolCell($initial, $this); }
```

> ⚠️ 这里**故意不写成** `cell(float|int|string|bool $v): FloatCell|IntCell|...`。联合类型在 AOT 下退化 `php::Var`（约束一），形参与返回值都退化 ⇒ 调用点拿到的是 `php::Var`，赋给 `FloatCell $volume` 字段就是 C2440。四个窄工厂各自静态可推，零退化。代价是 8 行样板（4×2），换来的是类型安全 —— **这正是本仓既有口径"用具体类型加哨兵，不用 mixed"**。

**收益。** 55 处 × 2 行 = **110 行删掉**，且写点从"三行 + 记得写 `use`"变成"一句话"。更重要的是**分类负担消失**：只有一条改值路径（`Cell::set()`），裸赋值那 104 处里凡是"该标脏"的都自然归位，不该标脏的（局部缓存如 `$this->allRows`）**不再是字段**，而是 Store 的缓存或方法内局部量。

**代价（必须承认的三条）：**

1. **读点变长**：`$this->volume` → `$this->volume->v()`。**用局部变量吸收**：`build()` 开头 `$volume = $this->volume->v();`（这也是表驱动该有的写法）。
2. **多 4~5 个类**。但它们每一个都只有 8 行，且不随控件增长。
3. **`Cell` 与 owner 的绑定要在构造时确定**（`$this->floatCell(...)` 里塞 `$this`）。这是"一个 Cell 只标脏它的 owner"，不引入订阅图 ⇒ **比 `ValueNotifier` 的全局 listener 图更简单，也没有泄漏面**。

**首类可调用对象（2026-11-21 已实测，bin 口径）。** `FloatCell::__invoke(float $v): void { $this->set($v); }` 之后，`->onChanged($this->volume)` 直接可用，不必包闭包。探针 `probes/cell_shape/` 用 `private mixed $handler` + `$h($v)` 的形状跑通了真机 AOT（输出 `9` / `2`，见 §10）。

这不是新风险：框架现有 `onChanged` 的形参本来就是 `callable`（约束一：`callable` 退化 `php::Var`），今天传的闭包走的也是同一条动态派发。`__invoke` 对象只是**换了个可调用体的种类**，派发路径一模一样。附带收益是少一次闭包对象分配。

于是 `SliderPage` 的三处回调从：

```php
->onChanged(fn(float $v) => $this->pickVolume($v))   // 再一跳进 pickVolume
```
变成：
```php
->onChanged($this->volume)
```
**一跳到底，且类型由 `__invoke` 签名保证**（`Slider::onChanged` 要 `callable(float): void`，签名不匹配时 `__invoke` 会在调用的那一刻 TypeError —— 比闭包写错参数个数更早、更准）。

**AOT 核对。** `FloatCell` 是普通类、字段 `float` + `State`（非空对象，合约束六的字段层"非空 + 无 `?->`"）。`set()` 内 `$this->owner->markDirty()` 是静态方法调用，无属性表退化。✅ **已由 `probes/cell_shape` 在 bin 口径编译+运行双通过。**

---

### 4.2 L1 — `Section` / `PageBody`：骨架组合原语

#### `Section` —— "标题 + 内容 + 一致内距"这个三件套

21 处 `Card::make(Column::make()->spacing(X))->paddingAll(Y)` 是同一个概念的 21 份手抄。

**形状（工厂式，零 Element 层）：**

```php
final class Section
{
    /** 不是 Widget：直接拼出 Card(...)，不新增 Element 层（见下"为什么不是 Widget"） */
    public static function make(string $title): self;
    public function lead(?string $caption): self;      // 小节说明（`->caption()`）
    public function child(Widget ...$body): self;      // 内容（可多个，纵向堆叠）

    /** ★ 必须收 $ctx：内距/间距从主题取，而主题只在 BuildContext 上 */
    public function toWidget(BuildContext $ctx): Widget;

    // 产出等价于：
    // Card::make(Column::make()->spacing($ctx->metric(MetricToken::SectionGap))
    //     ->children([Text::make($title)->heading(), ...lead, ...body]))
    //   ->paddingAll($ctx->metric(MetricToken::SectionPadding))
}
```

> ⚠️ 上面 `child(Widget ...$body)` 用了变长参数。**已实测可用**（2026-11-21，bin 口径，`probes/cell_shape`）：`string ...$body` + `foreach` 入列 + 展开 `...$this->body` 全部编译并运行通过。这条不再需要退到 `child(Widget $body)` + `children(array $body)` 两个方法。

**为什么不是 `StatelessWidget`。** 本仓 `Card` 的 docblock 已经确立了这条哲学：「它是**一个预设，不是一套存储**」。`Section` 同一个道理——它只做一次拼装，没有状态、不需要 Element 身份。做成 Widget 就多一层 `StatelessElement`，每次整页重建多一次纯函数调用（A6 口径下是可测开销）。**工厂更便宜，也更好审**（`toWidget()` 的产物可以单测断言结构）。

**必须先补主题度量。** 现在 `MetricToken` 只有 10 个 case（`Padding`/`Gap`/`Radius`/`BorderWidth`/字号四个/`RowHeight`/`HeaderHeight`），**没有页面级/小节级的内距与间距**——所以 34 页只能写字面量 `20.0` / `12.0` / `12.0`（`paddingAll(12.0)` 出现 35 次就是证据）。`Section` 落地的前提是加三个 token：`PagePadding` / `SectionGap` / `SectionPadding`。

**`sectionBar` 那 9 份直接消失**：`Section::make('连续 · 0–100')` 已经内含 `->heading()`。

#### `PageBody` —— 18 页同形的外壳

```php
final class PageBody
{
    public static function make(string $title): self;
    public function lead(?string $caption): self;
    public function section(Section ...$sections): self;
    public function toWidget(BuildContext $ctx): Widget;

    // 产出等价于：
    // Card::make(ScrollView::make(Column::make()->spacing($gap)->padding(EdgeInsets::all($pad))
    //     ->children([Text::make($title)->title(), ...lead, ...$sections->toWidget()])))
    //   ->paddingAll(0.0)
}
```

**应用层收口。** `ShowcasePage` 覆写 `build()` 一次，34 页只声明内容：

```php
abstract class ShowcasePage extends Page
{
    final public function build(BuildContext $ctx): Widget
    {
        return $this->pageBody()->toWidget($ctx);
    }

    /** 页面标题：从注入的 descriptor 取，不再与 `#[Route]` 双写 */
    protected function pageTitle(): string { ... }

    abstract protected function pageBody(): PageBody;
}
```

于是 `SliderPage` 从 140 行降到约 85 行（注释保留），且**新页只需回答"我要哪几个小节"**，不必想外壳。

> ⚠️ `final build()` 是针对 **showcase** 的决定，不是框架的。`Page` 基类不动 `build()` 的契约——16 页里 `TablePage`/`CalendarPage`/`GridPage` 这类需要定高 `Expanded` 的，`pageBody()` 仍能表达（`Section->child(Expanded::make(...))`）；真不行的页可以 `extends Page` 绕过（`final` 只锁在 `ShowcasePage` 这一层）。

---

### 4.3 L2 — `Port` 分解 + 入口解码

#### (a) 能力束按"用得到"拆，而不是按"有这些东西"装

现在 `ShowcaseCapabilities` 是 8 方法的 `final readonly class`，34 页 **27 页一个方法都不调**。

**形状：** 保留 `ShowcaseCapabilities` 作为**组合根的实现**，页面侧按需声明窄接口：

```php
interface RowPort     { public function fetchRows(int $page): Future; }
interface DialogPort  { public function openFile(...): ?string; public function saveFile(...): ?string; }
interface NotifyPort  { public function notify(Snackbar $s): void; }
interface ModalPort   { public function showModal(Modal $m): void; }
```

`ShowcaseCapabilities implements RowPort, DialogPort, NotifyPort, ModalPort`（一个类实现多个窄接口，零额外代码）。

**页面侧怎么办。** 先纠正一个容易误判的点：`ShowcasePage` 的构造器**只在基类写了一次**，34 页里只有 `SnackbarPage` 自写构造器（它要 `parent::__construct()`）。所以"每个页面都写构造器"是错觉，**改构造器签名不省一行代码**。

真正的问题是**依赖面**：`protected readonly ShowcaseCapabilities $caps` 让 31 个不需要它的页面也能 `$this->caps->...`，类型上无从表达"我不用这个"。修法不是改构造器，是**收窄可见性 + 显式意图**：

```php
abstract class ShowcasePage extends Page
{
    /** @internal 只在基类构造器里赋值，子类不可直读 */
    private ShowcaseCapabilities $capabilities;

    public function __construct(ShowcaseCapabilities $capabilities)
    {
        $this->capabilities = $capabilities;
    }

    /** 声明"本页要用能力束"：31 页永不调它，写法上就不出现 */
    final protected function port(): ShowcaseCapabilities
    {
        return $this->capabilities;
    }
}
```

- `protected` → `private`，子类只剩 `port()` 一条路 ⇒ **"我用了能力"变成代码里看得见的一句话**；
- 3 页把 `$this->caps->` 改成 `$this->port()->`（机械替换）；
- 字段保持**非空**（合 C2 字段层纪律），不需要 `bool` 标志，也不需要 `?->`；
- 构造器签名不变 ⇒ `NavEntry::makePage()` 不动。

**收益是 0 行代码，1 条意图**。诚实地说，这是四项里最弱的一项——但它零风险、零验证成本，所以留在 P1 里当顺手做。

> 更彻底的做法是让端口**必填**（页面构造器收 `RowPort $rows` 等具体接口），但那要求 `NavEntry::makePage()` 能按页面声明的依赖装配——PHP 无反射注入（AOT 下也没），只能靠 `PageRegistry` 的容器约定。本文不做，登记为后续方向。**先拆接口、不拆装配**：拆了接口之后，将来要改成必填，页面里已经有 `port()` 这个唯一落点。

#### (b) 入口解码：`AsyncValue` 的 `mixed` 收口

`UserStore` 已经做对了，把它变成**判据**：

**判据（新增）**：`AsyncCommand` 的值类型必须是**具体值类**，`fold()` 的 `data` 支里**不得出现 `is_array` / `isset`**。

- 落地形式 a（弱）：lint 规则 `async-raw-payload` —— `fold()` 的 `data` 分支体内出现 `is_array` ⇒ warn。
- 落地形式 b（强）：`AsyncCommand::map(\Closure $decode)` —— 在 `then()` 之后强制过一次解码，`value()` 只出解码后的类型。但 AOT 无泛型，"解码后类型"无法静态表达 ⇒ 强形式做不到。**选 a**。

**收益**：`AsyncTablePage` 的 3 处判空（`toolbar` / `bodyOf` / `pagesOf`）收敛为 1 处解码（`RowsPage::fromJson()`），全仓 24 处 `is_array` 里至少 12 处消失。

---

### 4.4 L3 — `PageStore`：补上中间那层

#### 判据从两头变三头

| 层 | 判据（新） |
|---|---|
| `State`（页面） | 只持 **UI 态**（`Cell`）—— 选了哪一行、展开了没有、当前页签 |
| **`PageStore`（新）** | **纯派生逻辑**：给定输入恒给同样输出，且**不需要跨页存活**。判据一句话：*"这段代码我想不想让它能被 `new` 出来直接断言？"* |
| `Store`（应用级） | 业务数据 + 跨页存活 + 异步生命周期 |

`PageStore` 与 `Store` 形态**完全一样**（都是 `Subject` 子类），差别只是生命周期。这样心智模型只有一个："**业务逻辑住 `Subject` 子类，页面住 `Cell`**"。

#### `State::own()` —— 复用已有的封装形状

`State::asyncCommand()` 已经是"造 Subject + watch + 卸载时 dispose"的一行封装。泛化它——**注意泛化后的代码是一行**，因为 `watch()` 内部已经登记了 `cancel()` 的 disposer：

```php
// State.php 新增
/**
 * 领养一个 Subject：变则标脏本页，卸载时自动退订（`watch()` 的 disposer 已兜底）。
 *
 * @param null|\Closure(): void $release 额外释放（PageStore 内部持有命令/资源时给）
 */
public function own(Subject $subject, ?\Closure $release = null): void
{
    $this->watch($subject, function (): void { $this->markDirty(); });

    if ($release !== null) {
        $this->onDispose($release);
    }
}
```

> 为什么不用"给 `Subject` 加 `close()`"：`Subject` 现在没有 `close`/`dispose`，加一个就成了**公共 API 面的扩张**（且 `AsyncCommand` 已有 `dispose()`，两套语义并存）。用可选闭包参数，`own()` 保持纯组合、零新类型、零新基类方法。

页面侧：

```php
final class TablePage extends ShowcasePage
{
    private TableDemo $demo;          // extends Subject，随页面销毁

    public function initState(): void
    {
        $this->demo = TableDemo::seed();     // ★ 数据生成也在 demo 里，页面不再持有 generateRows()
        $this->own($this->demo);
    }

    protected function pageBody(): PageBody
    {
        return PageBody::make($this->pageTitle())
            ->section(Section::make('排序')->child($this->sortBar($this->demo->sort())))
            // ……
    }
}
```

`TablePage` 的 490 行 → 约 **230 行**（215 行纯逻辑迁到 `TableDemo`，页面只留渲染）。`TableDemo` 可 `new` 可单测：

```php
$demo = new TableDemo([['id' => 3], ['id' => 1]]);
$demo->sortBy('id');
self::assertSame([['id' => 1], ['id' => 3]], $demo->rows());   // 不用挂树、不用造 Element
```

#### 为什么这不是"又一个范式"

- 它**没有新基类**（`Subject` 就是基类；`own()` 是 `watch()` 的一行封装 + 可选释放闭包）。
- 它与 `Store` 的差别只是**谁创建、谁释放**——跟 `asyncCommand()` 与 `Store` 里的 `AsyncCommand` 的关系一模一样。
- A14 已经确立"不造中间档"的教训（Dialog/Feedback/AsyncTable 调用形状不是 reduce，所以不铺开 Reducer）。这里不同：`PageStore` **不是新范式，是既有范式换个生命周期**。

---

## 5. 对既有裁决的影响

| 裁决 | 现行结论 | 本文是否动摇 | 说明 |
|---|---|---|---|
| **A2** 自动依赖追踪 | 不做；显式 `setState` | **部分** | `Cell` 仍然是**显式**写入（`->set()` 是显式方法调用，不是属性拦截），没有"自动"追踪任何东西。A2 字面成立；但"显式"的**形状**从闭包变成方法调用 —— 需重述 A2 的措辞 |
| **A3** 双向绑定 | 不做；受控 | **不动摇** | `Cell` 仍是受控的：控件回调新值，`Cell::set()` 落值。没有 `$widget->bind($field)` |
| **A6** 增量布局 | 不做；整树重排 | **不动摇（但缺口登记）** | Compose 的重组粒度优势在 `State` 层没有等价物（见 §6） |
| **A15** 单类页面 | `Page extends State` | **不动摇** | 本文全部在 A15 之上加**组合原语**，不碰"页面就是一个类" |
| **C2** `?Obj` 字段层 | 用"非空 + bool"或哨兵，避 `?->` | **不动摇** | `Cell::$owner` 是非空对象字段；`ShowcasePage::$capabilities` 也保持**非空**（`protected`→`private` 收窄，不引入可选性）。全文不新增任何可空字段 |
| **A9** 盒 chrome 单点 | 只有 `BoxChrome::paint()` | **不动摇** | `Section` 不画任何 chrome，全部委托 `Card` |

**需要新写进 `docs/decisions/README.md` 的三条（落地时）：**

| # | 主题 | 结论 | 失效条件 |
|---|---|---|---|
| A16 | 可写单元 `Cell` | UI 态用 `Cell`（`v()`/`set()`），一个 Cell 只标脏它的 owner；不做属性拦截、不做全局订阅图 | 读点变长导致真实代码可读性下降，或 `__invoke` 在 AOT 下不可用且闭包写法无法接受 |
| A17 | 骨架原语 | `Section`/`PageBody` 是**工厂**（非 Widget），零 Element 层；内距/间距从 `MetricToken` 取，不写字面量 | 出现需要不同骨架语义的页面形态（如全屏画布、分栏），工厂无法用参数表达 |
| A18 | `PageStore` 分层 | 纯派生逻辑住 `PageStore`（`Subject` 子类，`State::own()` 领养），判据是"想不想让它能被 `new` 出来断言" | 出现大量"纯逻辑但必须读 Widget 上下文"的场景，导致 `PageStore` 退化成半个 State |

---

## 6. 明确不做（避免重复讨论）

1. **不做属性钩子 / 属性包装器 / `__get`/`__set`。** 用户已明确禁止；且 AOT 下魔术方法的生成形状无实测（约束六只说 `?->` 静默，没说 `__get`）。
2. **不做 Compose 式重组作用域。** `State::markDirty()` 标整个 State ⇒ 整页重建。要做到"只有读了某个 Cell 的子树重建"，就得让 `Cell` 变成可订阅的 `Subject` 并配 `CellBuilder` 控件——那是把 `SubjectBuilder` 的机制复制第三份，且立刻撞上 A6（整树重排）。**若将来要动，先重开 A6，不是先加类。**
3. **不做泛型 / 类型化 `AsyncValue<T>`。** PHP 与 AOT 都没有。所有"收口"靠值类 + lint，不靠类型参数。
4. **不做 `of()` / `find()` 式向上查找。** A1 已否决，`Section` 需要的主题度量从 `BuildContext` 拿（`BuildContext` 就是持有 `theme()` 的），不需要查找。
5. **不做 `Scaffold` 式固定槽位。** `PageBody` 只有"标题 / 说明 / 小节序列"三个位置，不做 `floatingActionButton` / `drawer` / `bottomNavigationBar` 那套（没有消费者，A8 的教训）。
6. **不重写 `showcase` 为"声明式 DSL"。** 有人会想做到"页面 = 一份数组"。不做：34 页里有 13 页需要 `Expanded` / 定高 / 手势接线，声明式 DSL 会逼出 escape hatch，而 escape hatch 一旦存在，DSL 就只剩形式。

---

## 7. 分阶段落地（每阶段可独立验证、可独立回滚）

### P0 —— 零风险、纯收益

| 动作 | 收益 | 验证 |
|---|---|---|
| ~~探针 `probes/group_use`~~ **已于 2026-11-21 完成**（`probes/cell_shape`） | 组 use 在 bin 口径可用 ⇒ 34 页 import 从 16 行降到 3 行（**~400 行**） | ✅ 真机 AOT 编译 + 运行通过，见 §10 |
| `MetricToken` 加 `PagePadding` / `SectionGap` / `SectionPadding` | 消掉 65 处字面量内距/间距 | 金标像素**必须不变**（值填成今天用的 20/12/12） |
| lint `async-raw-payload`（`fold` 的 `data` 支里 `is_array` ⇒ warn） | 暴露 24 处判空，先把它们列出来 | lint 自测 + 全仓跑一遍计数 |
| 补 `docs/start/app-anatomy.md`：顶层执行代码是编译期 Fatal（`stray code`） | 探针副产品，见 §10 | 文档评审 |

### P1 —— 组合原语（3~5 天量级）

| 动作 | 收益（行数按 5835 口径） |
|---|---|
| `Cell` 四个具体类 + `State::floatCell()` 等四个工厂 | 55 处三行闭包 → 55 行，**净删 ~110 行** |
| `Section` / `PageBody` 工厂 | 18 页外壳（`build()`+`body()` 声明与包裹）、9 页 `sectionBar`、21 处 `Card+Column` 手抄 → 各 1 行，**净删 ~186 行** |
| `ShowcasePage::$capabilities` 收窄为 `private` + `port()` | **0 行**，换来"用了能力"在代码里可见（3 页改调用点） |

**P1 完成后必须重跑**：`verify --full` 16/16（34 页像素逐字节一致）、PHPUnit 全量、lint 0 error、doccheck。**任何一页像素变了都说明拼装不等价，就地回滚**。

**P1 行数账**：5835 − 110 − 186 = **5539**（−296 行，其中 4065 行实际代码里 −296 ⇒ **−7.3%**）。

### P2 —— 分层与收口（5~8 天量级）

| 动作 | 收益 |
|---|---|
| `State::own()` + `TableDemo` / `LoadMoreDemo` / `FetchDemo` | `TablePage` 490→~230、`LoadMorePage` 272→~150、`ConcurrentFetchPage` 253→~150（**净删 ~330 行，但这是"搬家"不是"删税"**——215+120+100 行逻辑搬进新类，总行数不减） |
| 三个 demo 各配一组单测（纯 `new`，不挂树） | **补上今天完全没有的那层测试**（P2 的唯一真收益） |
| `Port` 窄接口拆分 | 接口先行，装配不动（见 §4.3a） |
| 标题从 descriptor 取 | 35 处双写 → 0，**净删 ~35 行** |

> P2 的诚实说法：它**几乎不减行数**（搬走的逻辑要写回来，还得配测试）。它的价值是**可测**与**可读**，不是**更短**。把它当"减代码量"来卖就是自欺。

### P3 —— 仍待验证项（不进主线）

- ~~`FloatCell::__invoke` 在 AOT 下的派发形状~~ **已于 2026-11-21 验通**（§10），`onChanged($this->volume)` 可用，P3 这条移除。
- `Section` 若实测发现工厂式拼装不便（比如需要 Element 身份做 diff），退回 `StatelessWidget` 版本，用 `FrameTaxMeasure` 量差值再定。**这条还没验**：探针验的是"形状能编译"，不是"拼装出来的树对不对"。

---

## 8. 验证判据（每阶段独立成立）

| # | 判据 | 口径 |
|---|---|---|
| V1 | **像素不变** | `php bin/drift verify --full` 16/16，34 页逐字节一致。这是"重构没改行为"的唯一硬证据 |
| V2 | **代码量（分两档）** | P0+P1 后 `wc -l apps/showcase/src/Pages/*.php`：5835 → **≤ 5540**（−5%）；组 use 已验通，再降到 **≤ 5140**（−12%）。**不以 P2 为减量指标**（P2 是搬家） |
| V3 | **规则数** | 新页要记住的规则：现在 7 条（build 外壳 / body / sectionBar / setState 三行 / 内距字面量 / 标题双写 / caps 是否该用）⇒ 目标 **3 条**（`sections()` / `Cell` / `port()`）。人工清点，写进 `docs/guides/` 的"新页清单" |
| V4 | **判空收敛** | `grep -rc "is_array(" apps/showcase/src/Pages \| ...`：24 → **≤ 12**（P0 的 lint 先给出准确清单，再定靶） |
| V5 | **可测面** | `TableDemo` / `LoadMoreDemo` / `FetchDemo` 各 ≥ 4 条单测，且**不 mount 任何页面**（P2 的唯一硬指标） |
| V6 | **AOT 不等价** | `php tools/drift-codegen.php` 零失败 + 真机 `showcase.exe` 构建通过 + `probe-window` 5/5 |
| V7 | **帧税不退化** | `tools/Bench/PageFrame.php` 对 34 页逐页取帧，与 11-21 基线对比，**单帧不得劣化超过 5%** |

> V1 与 V2 会打架：删 12% 代码还要求像素逐字节一致，唯一的可能是**删掉的全是拼装模板**。这正是本文的主张，也正是它可被证伪的地方——如果删到 5140 行发现某页像素变了，说明那一页的"外壳"不是模板，而是**产品决定**，那就该留在页面里（本文的诊断在那一页上错了）。

---

## 9. 结论

**showcase 的问题不是写得不好，是框架只给了积木没给图纸。** 34 页用了 5835 行去重复回答四个问题：外壳长什么样、小节怎么包、值怎么改、标题写在哪。这四个问题的答案在任何一个应用里都一样，本该框架回答一次。

四层里真正有争议的只有 **L0 `Cell`**——它是唯一改动"写入协议"的东西。但它改的方向与 A2/A3 都不冲突（仍是显式、仍是受控），只是把"用闭包表达一次赋值"换成"用对象表达一次赋值"。**闭包在这个位置从来不起作用**，这是本文最硬的一条实证：`State::setState()` 的实现就是 `$change(); $this->markDirty();`，两行，中间没有任何延迟或重放。

其余三层（`Section`/`PageBody` / `Port` / `PageStore`）都是**加法**，不动既有语义，风险集中在"拼装是否等价"这一点上——而这一点由 V1（像素逐字节一致）守得死死的。

---

## 10. 附：本文假设的实测（2026-11-21，bin 口径）

本文有三项假设押在"tpc 认不认这个语法"上，如果不认，方案的核心减量（400 行 import + 55 处回调）就落空。所以先建了探针跑真机，**不靠推测**。

**探针**：`probes/cell_shape/`（不入库：`/probes/*` 是忽略目录，只有白名单里的长期判据入库）。

```bash
bash probes/cell_shape/build.sh          # tpc -m bin -O2，真 cl.exe
PHPRC=D:/TypePHP/Drift2/probes/.ini ./cell_shape_probe.exe
```

**四组假设与结果**（输出逐行对应）：

| # | 假设 | 探针形状 | 结果 |
|---|---|---|---|
| ① | 组 use 可用 | `use Probe\Parts\{Alpha, Beta, Gamma};` | ✅ 三个类都解析到了 |
| ② | 构造器属性提升 + 非空对象字段 | `private FakeState $owner`（不是 `?`） | ✅ `$this->owner->markDirty()` 正常累加 |
| ③ | 变长参数 + 展开 | `child(string ...$body)` + `...$this->body` | ✅ 三项按序入列 |
| ④ | `__invoke` 经 `callable` 形参派发 | `private mixed $handler` + `$h($v)` | ✅ `->onChanged($cell)` 后 `fire()` 真的落到 `__invoke` |

```
2.5                  ← ② Cell::v() 读到 2.5
1                    ← ② dirty 计数（set 一次）
t:alpha,beta,gamma   ← ① 组 use + ③ 变长参数 + 展开
9                    ← ④ __invoke 派发成功
2                    ← ④ dirty 累加（set + invoke 各一次）
```

**口径说明（重要，别被"通过"误导）：**

- 这次跑的是 **bin 口径**（`-m bin -O2` 出 exe），与应用发布口径一致 —— 不是 `tools/drift-codegen.php` 的 lib 口径。后者对同一份源码可以生成不同形状，所以**不能用 codegen 通过代替**。
- 验的是**语法与生成形状能不能编译并跑对**，**不是**"拼出来的树对不对"。`Section` 产出的 `Card(...)` 与手抄的是否逐字节等价，只有 V1（`verify --full`）能回答。
- 探针不引 `src/`：问的是语言能力，混进框架会把"框架能不能编译"和"语法认不认"搅在一起。

**副产品：一条未登记的约束。** 探针第一版把执行语句写在顶层，tpc 直接 Fatal：

```
Fatal error: All execution code must be within a function, found stray code in .../main.php:91
```

`docs/start/app-anatomy.md` 已经写了"入口是全局 `main()`"，但**没写"除声明外任何顶层执行代码都是编译期 Fatal"**（不是运行期，不静默，也不是 `php -l` 能发现的问题）。已列入 P0 的文档补充项。

---

## 相关

- `docs/reference/aot-constraints.md`（约束一 `mixed` 退化、约束六 `?->`、约束五回调 arity）
- `docs/decisions/README.md`（A1 / A2 / A3 / A5 / A6 / A8 / A14 / A15）
- `_legacy-docs/REVIEW-架构综合复核-2026-11-16.md`（同族口径：先取证再下判断）
- `apps/showcase/src/Pages/SliderPage.php`（闭包税 + 外壳税 + `sectionBar` 税三合一的样本）
- `apps/showcase/src/Pages/TablePage.php`（分层缺口的样本：215 行私有静态纯逻辑）
- `apps/showcase/src/Pages/AsyncTablePage.php`（判空税的样本 + 裸赋值标本）
- `apps/users/src/Store/UserStore.php`（入口解码与"业务住 Store"的正面样本）
