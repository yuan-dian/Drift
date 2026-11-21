# 响应式重构提案：读点即边界（2026-11-21）

> 承接 `ANALYSIS-frame-cost-2026-11-21.md` §10。那份文档否定的是"`Section` 拆段"这一条
> **具体做法**，不是"要优化"这个方向。本文回答三个问题：
> ① showcase 的心智负担到底来自哪几个机制（可指认，不是"API 丑"）；
> ② 成熟 GUI 框架各自怎么解、踩过什么坑；
> ③ 在**不强制分层**的前提下（业务与 UI 可以同处一类），核心应该改成什么样。
>
> 实测脚本：`.workbuddy-ai/tmp/proto_signal_track.php`（500 行 × 2 文本，20 帧中位）。

---

## 0. 结论（一句话版）

**把"依赖"从人写的 `select` 切片，变成 build 期自动登记的读点；把"边界"从 State 子树，
下沉到 Element 自身。** 一个原语（`Readable`）、一个机制（自动登记）、一个边界（Element），
同时解决重建范围、重绘范围与派生缓存 —— 而不是像 §10 那样用三套补丁分别去修。

`Section` 拆段之所以错，是因为它在**写法层**（业务侧 34 页各改一遍）去补**机制层**的缺口；
响应式是在机制层补，页面爱怎么写怎么写。

---

## 1. 诊断：心智负担的五个来源

showcase 34 页 = **4065 行代码 + 1167 行注释**（注释/代码 0.29）。`TablePage` 322 行代码里
真正的界面声明约 30 行，其余全是下面这五类"框架缺口的手工补偿"：

| # | 来源 | 现场证据 | 每一处的手工成本 |
|---|---|---|---|
| 1 | **写状态要包闭包** | apps 里 77 处 `setState`，TablePage 8 处 | 每次写入 ≈ 5 行：`->onX(fn(T $v) => $this->setState(function () use ($v): void { $this->f = $v; }))` |
| 2 | **派生值要手工记忆化** | TablePage `allRows` / `viewRows` / `selectionNow()` / `sortNow()` | 约 45 行懒初始化 + null 判断；漏一处就是"每次 build 重新格式化 10000 行"（该页注释里记着这个真 bug） |
| 3 | **依赖图要手写切片** | 全仓 26 处 `SubjectBuilder::make(...)->select(...)->builder(...)`，`UsersPage` 三层嵌套 | 每加一个"会变的值"就要多一层 `select`；**漏一层 = 快照**（2026-10-31 真机 bug：刷新按钮永久灰） |
| 4 | **归属要手工上提** | 选择集 / 排序 / 列宽全住页面 State（"受控"） | 控件内部状态外部读不到，于是所有交互态都必须上提、再由回调写回；这一条直接催生 #1 的仪式 |
| 5 | **变化粒度 = State 子树** | 一次点行 ⇒ 510 元素重建（§10 实测 2.99ms） | 想细就必须拆 State；拆到控件内部又拆不动（492 个元素在 `TableElement` 下） |

第 6 条更隐蔽：**`Page extends State`**（"页面本身就是 State"），数据、派生、交互、视图
同处一类 ⇒ 页面无法脱离帧循环单测，也没法把数据层复用到另一个视图。这是"方便"换来的债，
但今天的写法连"方便"都没换到 —— #1~#4 把省下的都花回去了。

> 关键判断：**这五条没有一条是"写法问题"**。换任何写法（MVVM / MVI / hook / 拆 Section）
> 都得手工回答"谁依赖谁""谁该重建""派生值缓存到哪"。所以答案在核心，不在 showcase。

---

## 2. 实测：粒度决定一切，与"用不用信号"无关

原型把同一棵 500 行树按三种粒度组织，每帧只改 1 行的选中态（`SurfaceProfile`，20 帧中位）：

| 变体 | 写法 | settle | paint | 说明 |
|---|---|---|---|---|
| A | 整页一个 State，`setState` | **8.63ms** | 4.56ms | 今天的形态：一次点击重建 1500 元素 |
| B | 信号，但整个行区只包**一个** `Bind` | 8.14ms | 4.18ms | **等于没做** —— 重建面还是整片行区 |
| C | 信号，**每行自己读自己那一位** | **0.89ms** | 4.49ms | 重建 1 行；剩下的 0.89 是 `settlePass` 走全树的地板 |

三条硬结论：

1. **重建成本只取决于"读点在哪一层"**，不取决于用没用信号。把一段包一个 `Bind` = 拆段，
   §10 已经证明拆段对 Table/Calendar 无效。
2. **细粒度只能靠"自动登记"拿到**：B → C 的差别是 500 个不同的读点，人不可能手写 500 个
   `select`（`SubjectBuilder` 的真实上限就是"每页三五个"）。
3. **paint 一个字节都没少**（4.2~4.6ms 不动）—— 重建再细也不省绘制面。所以本方案必须
   与 §10 的 P0/P1（边界失效 + 键局部化）**同批做**，否则又是一次"看起来很美"。

这也解释了为什么 §7 估的"拆段后 settle 5.2→1ms"会落空、而 §10 的 `0.45µs × 全树 +
6.5µs × 重建元素` 能对上：C 的 0.89ms ≈ 0.45µs × 1500 + 6.5µs × 3。

---

## 3. 成熟框架怎么解（以及各自的坑）

| 框架 | 最小失效单元 | 依赖怎么来 | 值得借 | 踩过的坑（本项目要提前挡） |
|---|---|---|---|---|
| **Flutter** | `State` 子树 | 人写（`setState`）+ `Listenable` 通道 | `ValueNotifier`/`Listenable` 作为参数传给控件内部订阅 ⇒ 高频交互态（滚动、动画、选中）**不重建结构** | `setState` 粒度粗；`InheritedWidget` 变一次全家重算；`build` 里创建 controller 泄漏 |
| **SwiftUI** | `body`（视图） | 自动（`@Observable` 读即依赖） | 自动依赖 + 值语义 `@State` ⇒ 写法最省 | 整 `body` 重算、无稳定 key 时列表抖动；`ObservableObject` + `@Published` 全字段广播 |
| **Compose** | recompose scope | 自动（快照读） | ① 快照读写 = 我们的 Cell；② **lambda 参数 = 延迟读**（把 `Int` 换成 `() -> Int`，读取点下移）；③ `derivedStateOf` = Derived | stability（不稳定类型导致整段重组）、过度重组、`remember` 键错、全局快照写事务 |
| **Solid / Svelte** | 单个 DOM 节点 | 自动（读即依赖） | 真细粒度：只有读它的那段代码重跑 | 读发生在跟踪作用域外 ⇒ 静默不更新；订阅随组件销毁必须解绑；调试难（"为什么没更新"） |
| **MobX** | reaction/derivation | 自动（observable） | computed 的懒 + 自动失效 | derivation 循环；内存泄漏（观察者没 dispose）；动作外写导致多次通知 |
| **Qt QML** | 单条绑定 | 自动（属性绑定） | 声明式绑定最直观 | 命令式赋值**破坏绑定**（写一次就永久断链）；绑定环；调试靠猜 |
| **Dear ImGui** | 无（每帧全量） | 无 | 写法极简、无状态同步问题 | **无保留树 ⇒ 局部失效、无障碍、文本选择、布局缓存全都做不了**。这是反例：它省的是开发者的心，花的是每帧全量 |
| **Elm / Redux** | 整树 | 单 store + reducer | 状态可回溯、可测 | 样板重（action/type/reducer 三件套）；中间件生态复杂 —— 正是我们要避免的 |

**提炼出三条公理**（本提案直接建在这三条上）：

- **A1 自动优于手写**：依赖能由"读"推导出来，就不该让人写 `select`。手写依赖图的失效模式是
  **静默陈旧**（快照 bug），这类 bug 测试很难咬住。
- **A2 边界要下沉到"读点所在的最近单元"**，不是页、不是 Section、不是控件 —— 是那次 build。
- **A3 值 vs 通道必须能区分**：结构配置用值（变了要重建），高频交互态用通道（变了只需重画）。
  今天 `Table->selection(TableSelection)` 把后者写成了前者，于是点一行重建 493 个元素。

---

## 4. 提案：读点即边界

### 4.1 原语（约 250 行，`src/Reactive/`）

```php
interface Readable { public function get(): mixed; }   // 读即登记依赖
final class Cell implements Readable {                 // 可写单元
    public static function of(mixed $v): self;
    public function get(): mixed;                      // Tracker 在栈上时自动登记
    public function set(mixed $v): void;               // 值等则早退；否则标脏订阅者（帧末合并）
    public function update(callable $f): void;         // $f(旧值): 新值
}
final class Derived implements Readable {              // 懒算 + 依赖失效 + 缓存
    public static function of(callable $f): self;      // $f 里的读自动成为它的依赖
}
```

- **不引入新调度器**：标脏直接复用 `State::markDirty()` 那条队列（帧末 settle 统一重建）。
  这是与 Compose 快照最大的不同 —— 我们不需要事务，因为一帧只有一次 settle。
- **值对象优先**：`Cell` 里放 `readonly class`，写 = 换实例 ⇒ 下游 `===` 身份短路（这套
  在 `Modifier::diff()` / `ThemeData::textStyle()` 上已经验证过，settle −40%）。
- **`Derived` 解决 #2**：`TablePage` 的 `allRows`/`viewRows`/`selectionNow`/`sortNow` 四套
  懒初始化 + 45 行 null 判断全删，改成一条 `Derived::of(fn() => sortRows($rows->get(), $sort->get()))`。

### 4.2 自动登记：登记在 Element 上，不新建概念

`build()` 期间把当前 Element 压进 `Tracker` 栈；`Cell::get()` 看到栈非空就登记
`(cell → element)` 双向表；`set()` 遍历订阅者调 `markDirty()`。

```text
Cell::set ──▶ 订阅者列表（Element 粒度）──▶ Element::markDirty ──▶ 帧末 settle 只重建这些
                     ▲                                                  │
                     └──────── build 期间 get() 自动登记 ◀───────────────┘
```

要点：

- **Element 复用（reconcile）时必须重绑**。`updateChildren()` 复用旧 Element 时，旧依赖表
  属于旧 widget 声明，新 build 会重登记 —— 所以登记表在每次 `build()` 开头清空、结尾提交，
  与 `deps` 做差集退订（原型 `BindS::build()` 的写法已验证可行）。
- **卸载必须退订**（A4 的同类风险）。走 `onDispose`，不用人记得。
- **build 期写**：`State` 已有 `E-FLUSH-CYCLE` 检测，`Cell::set` 在 build 期间调用直接复用
  同一条报错，不新增机制。
- **读在回调里不算依赖**（只认 build 期）—— 这条必须写进文档与 lint，否则会出现
  "在 `onPressed` 里读了 cell 却指望它触发更新"的误解。Solid 的坑同款。

### 4.3 参数二象性：`值 | Readable`（A3 的落点）

控件参数接受 `T|Readable`，**内部读**。传值 = 今天的行为（父重建时跟着变）；传 cell =
控件内部订阅，父层不重建。

```php
// 今天：选中集是值 ⇒ 点一行，页面 build → Table 重建 493 元素
Table::make($cols, $rows)->selection($this->selectionNow());

// 之后：选中集是通道 ⇒ 页面不重建；Table 内部只在需要的地方读（行高亮 = 重画，不重建）
Table::make($cols, $this->viewRows)->selection($this->selection);
```

这一条是 Compose "lambda 参数 = 延迟读"的同构写法 —— PHP 没有便宜的函数对象字面量，
但**有对象**，所以用 `Readable` 承载"延迟读"。同样适用：`ScrollView->offset()`、
`TextField->value()`（输入中的文本）、`Slider->value()`、`Animation->value()`。

> **收益上限**：`Table` 的选中态从"值"变成"通道"后，点一行 = 0 元素重建 + 行区重画。
> 这正是 §10 里 `TablePage` 那 493 个元素的大头。**控件内部**仍需按行读（否则回落到
> "整个 Table 重画"），但这一步在控件内做一次，34 页都吃到。

### 4.4 一个边界，两件事：重建 + 重绘

`Element` 同时是脏单元与重绘单元，于是 §10 的 P1（层缓存键局部化）有了**归属**：

- 脏 Element 所在的最近 `repaintBoundary` ⇒ 失效；
- 没有显式边界时，**脏 Element 自己就是隐式边界**（元素数 ≥ 阈值才真的开层，避免细碎层）；
- 键 = `(元素身份, 尺寸)`，不带全局 `layoutEpoch` —— "这一帧换过盒"不再连坐所有边界。

这直接解掉 §10.3 的两个缺陷（视觉维不让边界失效、失效只到最近一层），而且不需要业务写
`->repaintBoundary(true)`。

### 4.5 派生：`Derived` 而不是 `useMemo`

`TablePage` 现在的三份派生（原始行、排序后行、选中摘要）分别用"字段 + null 检查"缓存。
`Derived` 把它们变成一条声明，且**只有真读它的 build 才触发重算**（懒），失效沿依赖图传播。

排序这种"全量重排"怎么办？保持 `Derived`，但注意它的重算是**一次点击一次**（不是每帧）：
`sort` cell 变了才重算，20 帧的滚动帧里一次都不算。这正是今天 `viewRows` 想表达而没表达出来的语义。

---

## 5. 不强制分层（回应"业务与 UI 不一定分离"）

这套机制**不需要 MVVM**。状态可以继续住在页面类里（`Page extends State` 的现有形态），
因为"谁该重建"不再由类的边界决定，而由读点决定。按业务复杂度给三档：

| 档 | 形态 | 适用 | 代价 |
|---|---|---|---|
| **L0 单类**（推荐默认） | `Page` 里放 `Cell` + `Derived` + 视图方法；无 `setState`、无 `select` | showcase 34 页、演示、内部工具 | 数据仍与视图同类，复用性一般 |
| **L1 状态外置** | 把 `Cell`/`Derived` 收进一个普通 `final class XxxModel`（不依赖 Widget），页面持有它 | 有跨页共享 / 需要单测的页面 | 多一个类，但**单测不需要帧循环** |
| **L2 Resource** | `Resource::query(fn() => $api->list($q->get()))`：key 变自动重取、去重、取消；三态 `AsyncValue` | 表单提交、列表检索、轮询 | 需要数据层约定（取消、去重、错误码） |

关键：**L1/L2 是可选升级，不是入口门槛**。今天的问题是 `SubjectBuilder` 把"订阅"变成了
必须显式表达的东西，于是"要不要分层"变成了"要不要订阅"的代理问题。自动登记之后，
这两件事解耦了。

`Resource` 是对 `AsyncCommand` 的补位（现有 9 个 `Subject` 子类、3 个 showcase 异步页）：
`AsyncCommand` 解决"跑一次"，`Resource` 解决"参数变了要重跑、并丢掉旧结果"。
`LoadMorePage` / `AsyncTablePage` / `ConcurrentFetchPage` 里手写的
"页码变了清空 → 重跑 → 合并" 就是它在补的洞。

---

## 6. PHP / TypePHP 能借的力，以及借不到的

**能借（且是这类框架在别的语言里要花大力气才有的）**

| 优势 | 用法 |
|---|---|
| `readonly class` + `#[With]` 代码生成 | 值对象 `withX()` 一行；`Cell` 存 readonly 值 ⇒ `===` 身份短路天然成立 |
| `enum` + `match`（#187 后常量为原生 `switch`） | 视图三态 `AsyncValue::fold()` 已是这个形态，保持 |
| AOT native 字段 | `Cell` 的 `int/double/bool` 不装箱；订阅表用 `int` 键数组（`spl_object_id`），没有哈希字符串 |
| `#[\StdList]` / `#[\StdDict]` | 依赖表、订阅表编译期定元素类型，读循环不产生动态分派 |
| 闭包与被捕获变量 | `Cell::of(fn(): array => ...)` 的派生闭包是普通对象字段，AOT 下可静态调用 |
| 一等可调用 `$this->applySelection(...)`（**待 tpc 验证**） | 消灭 `fn(...) => $this->setState(function () use (...) {...})`；不支持则退 `[$this, 'applySelection']` |
| 无 GC 压力（AOT 对象生命周期随 Element） | 订阅关系是强引用 + 显式退订，不需要 `WeakRef` |

**借不到（要在设计里绕开）**

| 限制 | 影响 | 绕法 |
|---|---|---|
| **不能用属性钩子**（本项目硬约束） | 拿不到 Vue/Svelte 那种 `$this->count++` 自动追踪 | 显式 `Cell::set()`；接受"多一个 `->set()`"的语法税 |
| 自定义编译期 attribute 不可用（master 无用户 SPI） | 做不了 Svelte 式编译期改写 | 运行期 tracker（本方案）；**不要**走 `drift-codegen` 自己改写源码，AOT 边界多、维护成本高于收益 |
| `eval/include` 不共享上下文 | 模板字符串 DSL 不可行 | 视图必须是 PHP 方法/类（反而是好事：静态类型 + AOT 直调） |
| `use (&$x)` 与 native 容器不兼容 | 不能"引用捕获一个 cell 变量" | cell 一律是对象字段，不按引用捕获 |
| `final readonly class` 不能有静态属性 | 共享单例只能落非 readonly 类 | 与 `Modifier::empty()` 同款处理 |

---

## 7. 改造后的样子（TablePage 节选）

```php
final class TablePage extends Page
{
    private Cell $rows;        // list<array{id:int,name:string,...}>
    private Cell $selection;   // TableSelection（readonly 值对象）
    private Cell $sort;        // TableSort
    private Cell $widths;      // array<string,float>
    private Derived $viewRows; // 排序后行：懒算、自动失效

    public function initState(): void
    {
        $this->rows = Cell::of(self::generateRows());
        $this->selection = Cell::of(TableSelection::none());
        $this->sort = Cell::of(TableSort::none());
        $this->widths = Cell::of([]);
        $this->viewRows = Derived::of(
            fn(): array => self::sortRows($this->rows->get(), $this->sort->get()),
        );
    }

    public function build(BuildContext $ctx): Widget
    {
        return Card::make(Column::make()->spacing(12.0)->children([
            Text::make('表格（Table）')->title(),
            Text::of(fn(): string => self::selectionLabel($this->selection->get())), // 叶子读点
            Row::make()->children([
                Button::make('清空选择')->onPressed(fn() => $this->selection->set(TableSelection::none())),
                Button::make('清除排序')->onPressed(fn() => $this->sort->set(TableSort::none())),
            ]),
            Expanded::make(
                Table::make(self::columns(), $this->viewRows)   // 传 Readable
                    ->selection($this->selection)                // 通道：选中不重建结构
                    ->columnWidths($this->widths)
                    ->onSelectionChange(fn(TableSelection $n) => $this->selection->set($n))
                    ->onSortChange(fn(TableSort $n) => $this->sort->set($n))
                    ->onCellEdit($this->applyEdit(...))
                    ->contextMenu(self::menu()),
            ),
        ]))->paddingAll(0.0);
    }
}
```

行数变化（估算，按现有代码逐段核对）：

| 段 | 现在 | 之后 | 省在哪 |
|---|---|---|---|
| 字段 + 懒初始化 helper | ~45 | ~12 | `selectionNow/sortNow/rowsNow` + 4 个 null 字段 |
| 8 处 `setState` 闭包 | ~40 | ~8 | 直接 `cell->set()` |
| 排序链 | ~110 | ~110 | **不动**（这是应用知识，控件不该有） |
| 列声明 | ~45 | ~45 | 不动 |
| 说明 / 结构 | ~80 | ~60 | 少一层嵌套 |
| **合计（代码行）** | **322** | **~235** | 语法税之外的部分，能不能再降取决于控件（`Table` 接受通道后回调可以只给一个） |

`FormPage` 更典型：十几个 `private string $name` + `->onChanged(fn($v) => $this->name = $v)`
变成一张 `Cell` 表 + 校验纯函数（`Derived`），字段声明与校验规则同源。

> 注意：**行数不是主要指标**。主要指标是"要人做的决定"少了几类：
> 不用决定谁依赖谁、不用决定缓存放哪、不用决定哪段该拆成 State、不用决定哪些状态上提。

---

## 8. 这是不是核心限制（裁定）

**是。** 三条限制都在核心里，业务侧怎么写都绕不开：

1. **重建粒度 = StatefulElement 子树，依赖必须人写** ⇒ 逼出"大 State + 手写切片"。
2. **控件配置只有"值"一种形态** ⇒ 高频交互态必然触发结构重建（点一行 493 元素）。
3. **paint 全树录制 + 层缓存键带全局 `layoutEpoch`** ⇒ 重建再细也不省绘制面（§10.3 已证）。

**不该做的三件事**（都有数据或上游依据）：

- `Section` 拆段（§10：Table/Calendar ≈ 0 收益）；
- 属性钩子（本项目硬约束，且 AOT 下对 hook 属性取引用不支持）；
- 编译期改写（上游无用户 attribute SPI，Svelte 路线在这里是负资产）。

**showcase 也不是好基线**：它是"每页一个控件 + 大段能力说明"的演示台，不是应用。DX 基准
应该换成 `apps/users`（真实三层）+ 一个 ~300 行的"表格 + 表单"应用样板，量"新增一页要写
多少行、要人做几个决定"。

---

## 9. 落地顺序与验收

| 阶段 | 内容 | 验收 |
|---|---|---|
| **R0**（已实现，见 §11） | `Readable/Cell/Computed/Tracker/Bind` + State 级自动登记（不改任何现有控件的 API） | 单测：登记/退订/分支重绑/卸载解绑/build 期写报错；真实原语复现“读点粒度”基准 |
| **R1** | 参数二象性：`Table->selection()`、`ScrollView->offset()`、`Text::of()` | `TablePage` 点行：重建元素 493 → 个位数；像素基线不变 |
| **R2** | 与 §10 的 P0/P1 合并：脏 Element ⇒ 最近边界失效，键去全局 epoch | §10.3 的陈旧帧探针转绿；段级边界 paint 2.02 → ≈1.00ms |
| **R3** | `Derived` 铺到 `TablePage`/`FormPage`，删手写 memo | 两页行数与 `settle` 前后对比 |
| **R4** | `Resource`（参数化查询 + 取消 + 去重） | `LoadMorePage` / `AsyncTablePage` 手写状态机删除 |
| **R5** | lint 两条：① build 里读了 cell 却没在订阅内（快照）；② `Cell` 写了但从不被读（死状态） | 新代码零告警；等价于今天的 `dead-subscription` |

**风险与对策**

| 风险 | 对策 |
|---|---|
| 订阅泄漏 ⇒ 幽灵重建 | 全部走 `onDispose`；Element 复用重绑；lint 检查订阅数随帧增长 |
| 细粒度反而更慢（每次 1 个元素的 settle 固定成本） | 帧内合并：同一帧多个 cell 变更 ⇒ 脏 Element 去重（现有脏队列已如此） |
| settle 全树遍历地板（1500 元素 0.67ms） | 与 §10 P4（干净子树剪枝）一起做；临界值：脏 Element 少于一屏时剪枝才有意义 |
| 调试变难（"为什么没更新"） | Inspector 展示 scope → deps 图（已有 inspector 应用可挂）；`Cell::debugName` |
| 派生循环（A 读 B、B 读 A） | `Derived` 计算期压栈检测，直接报错（MobX/QML 的坑） |

---

## 10. 一句话对比

| | 今天 | 提案 |
|---|---|---|
| 谁决定重建范围 | 人（State 边界 + `select` 切片） | 框架（读点） |
| 一次点击重建多少 | 510 元素 / 2.99ms | 个位数元素 / ≈0.3ms（+ paint 由 R2 解决） |
| 派生缓存 | 手写字段 + null 检查 | `Derived` |
| 写状态 | `setState(closure)` 5 行 | `cell->set($v)` 1 行 |
| 数据与 UI | 必须同类（Page 即 State） | 同不同类都行，分层可选 |
| 高频交互态 | 值 ⇒ 结构重建 | 通道 ⇒ 只重画 |
| 失效模式 | 忘了订阅 ⇒ 静默陈旧 | 忘了在 build 里读 ⇒ 不更新（lint 可查） |

---

## 11. R0 实现复核（同日，实验已落地）

### 11.1 对提案的四处修正（实施时逐条权衡）

1. **不新增 `Derived`，复用既有 `Computed`。** 给 `Computed` 加 `Computed::of($fn)`（依赖自动
   收集）与可选 `$deps`（老式显式依赖仍可用）。两套几乎同构的懒值机制只会制造第二套
   语义；`sources()` 展平嵌套派生值，让拉取式的派生值也能被 State 订到源上。
2. **登记落在 `State`，不是裸 `Element`。** 两者在框架里一一配对，而 `markDirty()` 本就住在
   `State`：`StatefulElement::rebuild()` 只包 `build()`（不含 `updateChildren()`），把读集合
   交给 `State::syncReactiveDeps()` 做差集订阅。子节点 mount 时的同步写不会漏订（先订再挂
   子层），子 State 的读也不会冒到父层。
3. **订阅复用 `Subject::subscribeFor()`，不新建调度器。** 自动依赖与 `watch()` 手工订阅
   各持各的句柄；退订只退自己那几条，不用 `unsubscribeAllOf()` 误伤手工订阅。清理挂
   `onDispose()`，卸载整批退订。
4. **保留 `Readable` 接口，但 `Bind` 才是 R0 的读点壳。** 控件参数的 `T|Readable` 二象性
   属于 R1；R0 先用 `Bind::make(fn() => Widget)` 在任意位置开读点，不改 34 页控件 API。
   多一个 Element 约 0.45µs/帧，换“只重建这一格”是划算的。

**复核中抓到并修掉的一处设计缺陷**：初版在 `Cell::set()` 里用 `Tracker::active()` 一律 Fatal
“build 期写”。但框架原有契约**允许跨 State 的 build 期写**（`FlushCycleTest::chainwrite`），
这条 blanket guard 会把合法传播误杀。改为让订阅者的 `State::markDirty()` 自己做
`self::$buildingState === $this` 判断：只挡“自己写自己读的格子”造成的活锁（E-FLUSH-CYCLE），
跨 State 写入照常收敛。夹具 `tools/Smoke/State/CellWritePage.php` 钉前者，
`ReactiveRebuildTest::testCrossStateCellWriteDuringBuildConverges()` 钉后者。

### 11.2 实测：真实原语复现“读点粒度”

脚本 `.workbuddy-ai/tmp/probe_r0_reactive.php`（500 行 × 2 文本，每帧改 1 行选中态，
20 帧中位；`Pipeline::rebuiltCount()` 数是**重建的 State 数**，不是元素数）：

| 变体 | settle | paint | 重建 State |
|---|---|---|---|
| A 整页 State（500 个读点都在页面 build） | **7.78ms** | 3.98ms | 7 |
| B 信号，但行区只包一个 `Bind`（= 拆段） | 7.55ms | 3.82ms | 8 |
| C 每行一个 `Bind`，读点落在行内 | **0.78ms** | 4.14ms | 507 |

（六次运行区间：A 7.34~8.63ms、B 7.52~8.23ms、C 0.73~0.83ms。）
⇒ settle 稳定降到约 1/10，与 §2 的原型（8.63 / 8.14 / 0.89ms）一致；**paint 仍未动**
（§10 P0/P1 的前置没有被 R0 顺手解决）。老页面无新机制的采集开销在同进程抖动内：
`TablePage` 关采集 settle 2.79~3.13ms，开采集 2.87~3.41ms（各 3 次，跨进程 ±10%）。

### 11.3 文件与验收

- 新增：`src/Core/Readable.php`、`src/Core/Tracker.php`、`src/State/Cell.php`、
  `src/Widget/Bind.php`、`src/Widget/BindState.php`、`tools/Smoke/State/CellWritePage.php`。
- 改动：`src/Core/Computed.php`（自动依赖 + `sources()`）、`src/Widget/State.php`
  （`syncReactiveDeps()`）、`src/Widget/StatefulElement.php`（build 期采集）、
  `apps/showcase/src/Pages/ButtonsPage.php`（首个用法样板：3 个 `Cell`，页面里**零一次性闭包**；
  见 §12）。
- 测试：`CellTest`（门控 / 订阅 / 采集 / 诊断名 / build 期写 Fatal）、
  `ComputedAutoDepsTest`（漏写/多写/分支重绑/嵌套/sources）、
  `ReactiveRebuildTest`（页面级读 / Bind 收窄 / 分支退订 / 卸载退订 / 跨 State 写收敛）；
  变异验证：去掉 `syncReactiveDeps()` 后重建面测试变红。
- 门：`drift test` 797 tests / 3016 assertions 全绿；`verify --full` 16/16；
  `codegen` 586 文件 · 0 skip（含 §12 的少闭包形态）。

### 11.4 下一步（顺序不变）

R1 参数二象性（`Table->selection($cell)` / `ScrollView->offset($cell)`）才是 `TablePage`
那 493 个元素的正面解法；R2 必须与 §10 P0/P1 同批，否则 paint 永远压不下去。
`Bind` 是 R0 的临时读点壳，R1 之后控件自己读 `Readable`，页面里就不需要手写 `Bind`。

已知边界（不是缺陷，当前设计取舍）：`Computed` 是拉取式且 State 订的是它的**源**，
所以源变了就重建，即使派生值算出来与上次相等也不会省掉那一遍；要消掉需要给派生值加
推送通道或相等门控（Compose `derivedStateOf` 那一刀），R1 之后的量测再决定值不值。

---

## 12. 少闭包形态（同日追加）

开发者的反馈是"闭包难读、IDE 提示差"。先把闭包按**职责**拆开，再逐类消掉 ——
笼统地说"无闭包"既做不到也没必要。

| 闭包在干什么 | 形态 | 替代 |
|---|---|---|
| 读一个值、拼成显示文本 | `Bind::make(fn() => Text::make(...))` | **`Text::of(Readable, 前缀, 后缀)`**：读点落在文本自己的 State，页面里一个闭包都不写 |
| 把格子写成固定值 | `onPressed(fn() => $cell->set('x'))` | **`Cell::setTo('x')`**：返回可调用值对象 `CellWrite`，调用时才写 |
| 有分支的处理器 | `hover(function(bool $e) { ... })` | **一等可调用指向具名方法**：`->hover($this->hoverHandler(...))` |
| 任意派生表达式 | `Computed::of(fn() => ...)` | `Computed::of($this->derived(...))`，依然是具名方法 |

`ButtonsPage` 已按这四种改完：页面里没有 `fn()` / `function ()`，显示是 `Text::of(...)`，
固定写入是 `setTo(...)`，分支逻辑进 `hoverHandler(bool)` / `pressHandler()` / `hoverText()`，
由 `$this->method(...)` 交给控件。IDE 对具名方法能跳转、能显示参数类型；闭包里那些
`$this` 捕获则两样都没有。

### 12.1 为什么不做成"零闭包"

- **编译期改写不可选**：TypePHP master 只有内置 attribute，没有用户 SPI；属性钩子是本项目
  硬约束。Svelte 那种"模板里写表达式、编译器生成订阅"的路在这里没有落点。
- **一等可调用仍是 Closure**：只是把 inline 匿名函数换成有名字、有签名的方法引用。
  upstream 明确标注 **first-class callable 存进 typed nullable `Closure` 属性是
  Pending / Partial**（运行时生命周期限制）；本框架控件的回调属性恰好多是 `?Closure`，
  所以它是"改善可读性的写法"，不是"消除闭包对象"。
  翻译层已实测：`$this->method(...)` 生成 `php::makeScopedCallable(...)`，0 skip。
- **`Text::of()` 是 R1 的一小块**：它把"参数收 `Readable`"先在 Text 上落地；
  `Table->selection($cell)` 等控件参数二象性仍是 R1 的任务，在那之前热点读点用它或 `Bind`。

### 12.2 新增面与验收

- `src/Widget/ReactiveText.php` + `Text::of()`：样式链与 `Text` 一一对应；`build()` 里
  `get()` 一次，订阅与重建粒度仍由 `State` 基座负责（不是第二套机制）。
- `src/State/CellWrite.php` + `Cell::setTo()`：可调用值对象，`__invoke(): void` 有稳定签名；
  `set()` 的相等门控照旧生效。
- 测试：`CellWriteTest`（延迟写、门控）、`ReactiveTextTest`（只重跑文本、前缀、样式转发）。
- 门：lint 0 error / 0 warn；`drift test` 797 / 3016；`codegen` 586 文件 0 skip；
  `verify --full` 16/16（含像素基线，说明 `ButtonsPage` 改写没有改变画面）。
