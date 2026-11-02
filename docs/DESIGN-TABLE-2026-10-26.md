# Table / DataGrid 设计裁决（2026-10-26）

> **这份文档不是实现说明，是结构裁决。** 唯一目的：让阶段 2..7 不推翻阶段 1。
>
> 判据来自两处：① 成熟框架里**所有**表格控件都收敛到的结构；② Drift2 自己已经
> 落地并验证过的通道（`intrinsicWidth` / `ownsPress` / `State` 窗口计算 / C5）。

---

## 0. 三个"简单版必然重写"的雷

先列反面，因为它们决定了下面的每一个裁决。

| 简单写法 | 什么时候炸 | 炸的代价 |
|---|---|---|
| `Column[ 表头, LazyColumn(行) ]` | 加**横向滚动**或**冻结列**时 | 整棵渲染树重写。表头要跟着 x 平移，而 `LazyColumn` 是滚动容器，无法注入 x 偏移；且 `RenderScrollView` 源码里有硬守卫——`LazyColumn` 嵌在另一个滚动视口里会**当场 Fatal**（见 §3.4） |
| `cellBuilder($row, int $colIndex)` | 加列宽 / 对齐 / 排序 / 列类型时 | 闭包签名爆炸，所有调用方跟着改。成熟框架**无一**用裸闭包定列 |
| 不做"行态注入"（选中 / 悬停 / 聚焦） | 加选择时 | `cellBuilder` 返回值要逐层包一层高亮，**每列的 cell 都要改一遍** |

一句话：**表头与行必须在同一个横向偏移下**，**列必须是对象**，**行态必须由外层注入而不是 cell 自己管**。这三条是这份文档的全部重量。

---

## 1. 成熟框架怎么做的

| | WPF DataGrid | Qt QTableView | Flutter DataTable | ag-Grid | GPUI `DataTable` |
|---|---|---|---|---|---|
| **数据** | `ItemsSource` + `ICollectionView` | `QAbstractItemModel` | `List<DataRow>` | `rowData` | 索引访问 |
| **列定义** | `DataGridColumn` 对象 | model 的 `columnCount` + `headerData` | `DataColumn` 对象 | `columnDefs` 对象 | 构造函数声明 |
| **列宽** | `DataGridLength`：Pixel / Auto / Star / SizeToCells + Min/Max | `resizeMode`：Interactive / Fixed / Stretch / ResizeToContents | 5 种策略 + `columnWidths` map | `width` / `flex` / `minWidth` / `maxWidth` | 声明 |
| **行虚拟化** | 默认开 | model 驱动（天然） | **无**（官方警告大数据改用 `PaginatedDataTable`） | 开 | 开 |
| **列虚拟化** | `EnableColumnVirtualization` | Qt6 `TableView` 支持 | 无 | 开 | **开** |
| **滚动** | 内部 `ScrollViewer`，双向 | 内部，双向 | **无**（需外套） | 双向 | 双向 |
| **冻结表头** | 有 | 有（header 是独立 widget） | 有（表头不随竖向滚动） | 有 | 有 |
| **排序** | `SortDescriptions`（**数据视图层**） | `QSortFilterProxyModel`（**model 层**） | `onSort` **回调** | 内部 + `comparator` | 内部 |
| **选择** | `SelectedItems` / `SelectionUnit` / `SelectionMode` | `QItemSelectionModel` | 每行一个 `bool selected` | `rowSelection` + `rangeSelection` | 索引集合 |
| **单元格焦点** | `CurrentCell`（view 内部） | `currentIndex`（view 内部） | 无 | `getFocusedCell` | 有 |

**读这张表要读出的东西：**

1. Flutter 的 `DataTable` 是**唯一不做虚拟化、不做滚动**的，而且它自己文档里就写了"大数据请用 `PaginatedDataTable`"。⇒ **不要把 Flutter DataTable 当参考**，它是"简单版必然重写"的活标本。
2. 除 Flutter 外，其余四个**全是双向滚动 + 冻结表头 + 行列两级虚拟化**。这不是巧合，是表格的物理属性（列宽总和 > 视口是常态）。
3. 排序的位置分两派：**数据视图层**（WPF/Qt）或**回调**（Flutter/ag-Grid）。Drift2 是声明式单向数据流，没有 model 层 ⇒ 只能走回调。
4. 选择**全部**在 view 层（`SelectionModel` / `SelectedItems` / `selected`），没有一家放进数据行里。

---

## 2. 六条共性（地基）

| # | 共性 | 证据 | 对 Drift2 的强制力 |
|---|---|---|---|
| 1 | **列定义是对象** | 5 家全是（`DataGridColumn` / `DataColumn` / `columnDefs`） | `TableColumn` 值对象；**不接受** `cellBuilder($row, $i)` |
| 2 | **列宽三层**：策略算初值 → 每列 Min/Max 夹 → 用户拖拽写实际值（实际值最优先） | WPF `DataGridLength` + `MinWidth` + 用户拖拽；Qt `resizeMode` + `Interactive`；ag-Grid `flex`+`width`+`resizable` | `TableColumnWidth` 值对象 + `TableState::$widthOverrides`。**不是**"显式宽度"，也不是"纯 flex" |
| 3 | **排序是外部意图，控件只画箭头** | Flutter `onSort`；WPF 的排序对象在数据视图；ag-Grid 的 `comparator` 也是外部给 | `Table::sortBy()->onSortChange()`，**受控**——与 `Slider::onChange` / `TextField::onChanged` 同一风格 |
| 4 | **选择是 view state** | 5 家全在 view 层 | `TableSelection` 值对象，**受控**（应用持有、`onSelectionChange` 上报）；悬停/焦点格才是 `TableState`（§11.4 修正） |
| 5 | **虚拟化是两级**（行 + 列） | ag-Grid / GPUI / WPF(可选) / Qt6 | 阶段 1 只做行；**但内容宽与 x 偏移通道必须存在**，否则阶段 5 要重写 |
| 6 | **表头独立层，但共享横向偏移** | WPF / Qt / ag-Grid 的 header 都是独立 widget 且随 x 平移 | 表头与行区在**同一个** `offsetX` 下。这一条直接否掉了 `Column[表头, LazyColumn]` |

---

## 3. Drift2 的既有约束（不是我的偏好，是已落地的裁决）

### 3.1 `intrinsicWidth` 通道已现成 ⇒ 列宽自适应不需要测量缓存

`RenderBox::intrinsicWidth(float $height)` 是 `final`，子类只实现 `contentIntrinsicWidth()`。
`docs/ARCH-REVIEW-2026-10-21.md:106` 已记：

> `Measure`/`Arrange` 两遍布局 | WPF / SwiftUI | 已判 no-op（布局位置无关 ⇒ 分离无收益）。
> WPF 两遍式的收益（**表格/网格先知道"大家多宽"**）Drift2 用 `intrinsicWidth` 已覆盖

⇒ `TableColumnWidth::intrinsic()` 可以做，**不需要**造测量缓存或两遍布局原语。

### 3.2 双轴虚拟化已被登记为缺口，判据是"等宽表需求"

`docs/ARCH-REVIEW-2026-10-21.md:648`：

> **双轴虚拟化**（GPUI `DataTable` 的列虚滚）：`LazyColumn` 只有纵轴。等有宽表需求。

⇒ 阶段 5/6（横滚 + 列虚拟化）不是新主意，是既有登记项的兑现。**但它意味着阶段 1 的结构必须为它留位置**，否则"等有需求再做"会变成"要重写才能做"。

### 3.3 `LazyColumn` 的窗口算法**不能直接复用**，只能复用算法本身

`LazyListState` 是 `final class ... extends State implements Scrollable`，类型绑死 `LazyList`；
且它内部 8 个随轴点都是 `$vertical ? A : B` 单轴假设。`Table` 是双向 + 固定表头，
**不是同一个东西**。

⇒ 抄算法，不抄类。唯一值得抽公共的是**纯函数**：

```php
// 现在：LazyListState::build() 第 268-271 行
$first = (int) floor(max(0.0, $offset - $overscan - $start) / $extent);
$last  = (int) ceil(max(0.0, $offset + $viewport + $overscan - $start) / $extent);
$first = max(0, min($first, $count));
$last  = max($first, min($last, $count));
```

抽成 `LazyListState::visibleRange(...)` 之类的**静态纯函数**（零状态、零副作用），
两边调用。**不碰锚点逻辑**——锚点是 `LazyList` 特有的（`keyOf` + 序号夹），Table 用自己的。

### 3.4 框架源码已明令禁止"`LazyColumn` 嵌在滚动视口里"

`RenderScrollView::performLayout()` 第 195-208 行：沿父链找 `RenderScrollView`，
命中就 `Errors::report`。原话：

> `LazyColumn` 不能嵌在另一个滚动视口里 —— 外层视口给它的是无界主轴，
> 窗口会永远只有一屏，而滚动归外层管（G2：不许静默失效）

⇒ **"Table 塞进横向 `ScrollView`"（上轮的方案 c）在框架层被否决了**，不是成本问题，是禁令。
这一条把横滚的实现路径锁死为"Table 自己做双向"。

### 3.5 连续拖拽的正确通道是 `ownsPress` + 捕获，不是 `GestureArena`

`Slider` 的 docblock 已经论证过（三条理由：阈值延迟 / `drop()` 语义污染 / `hoverTo` 污染拖放区）。
列宽拖拽与 `Slider` 同类（位置即语义的连续拖拽）⇒ 走同一条路。
`CursorShape::SizeWE` 已在（第 5 号）。

### 3.6 `State` vs `Store` 的判据

`LazyListState` 的 docblock：

> 偏移放 `State` 而不是 Store：§12.3 的判据是"承载它的 Element 会不会被回收"。
> 被回收的是**格子**，本 State 挂在列表自己身上 ⇒ 放 `State` 成立。
> 反过来，**格子内**的 UI 态会被回收，必须外置。

⇒ Table 的滚动偏移 / 列宽覆盖 / 悬停 / 焦点格，**都住 `TableState`**（Table 自己不随滚动消失）。
★ **选择集是例外，必须受控**（2026-10-26 修正，§11.4）：`State` 是 Element 内部造的，
应用拿不到引用 ⇒ 自持就等于"应用永远读不到选中了谁"。判据从"住哪"升级为
"**应用要不要读它、要不要从外部改它**"。
单元格内的编辑态（正在输入的 TextField）**必须外置**（`Store` 或应用侧）——这与 `LazyList` 同一条纪律，
写进 Table 的类注释。

### 3.7 语义角色的纪律：集合类角色还没有

`SemanticsRole` 当前的判据里写了"集合（list/tree/table）"是给角色的五类之一，
但枚举里**没有** `Table` / `List` case。落地时加 `Table` 一个 case（合规：它会有真实声明控件）。

---

## 4. 五个结构裁决

### 裁决 1：`Table` 自己是**双向滚动容器**，不是 `LazyColumn` 的包装

```
❌ Column[ 表头, LazyColumn(行) ]        ← 加横滚/冻结列必重写；且违反 §3.4
✅ State.build() ──▶ RenderTable( offsetX, offsetY,
                                  header,      // 固定 y，随 x 平移
                                  body )       // 行区：随 x、y 平移
```

- **行虚拟化住 `TableState::build()`**（照 `LazyListState` 的模式：build 期算窗口，
  产出"窗口内的行 Widget 列表"）。渲染盒**不参与**行虚拟化，它只负责"固定表头 + 双向平移 + 裁剪"。
- **首版 `offsetX` 恒 0**，但字段与平移通道存在 ⇒ 阶段 5 加横滚只是"启用一个恒为 0 的量"，
  **不改树、不改盒、不改 State 结构**。
- `RenderTable` 的职责清单（刻意做得很少）：
  1. `performLayout`：表头拿 `tight(内容宽, headerHeight)`；行区拿 `(内容宽, 剩余高)`
  2. `paintContent`：`clip` → 表头 `translate(-x, 0)` → 行区 `translate(-x, -y + headerH)`
  3. `hitTest`：反向平移（与 `RenderScrollView` 同款，别在 paint 期做偏移）
  4. 滚动条：纵向（阶段 1）+ 横向（阶段 5），复用 `ScrollBarFade`
- 内容宽 = `Σ列宽 + columnSpacing × (n−1)`，**在 `TableState` 算好递进来**（纯数字，不依赖布局）。

> **为什么不用 `RenderScrollView` 嵌套两层**：纵向那层会把行区交给外层裁剪，
> 表头就得单独再造一个横向视口 —— 那是两个滚动上下文抢同一个 `x`，偏移迟早不同步。
> 而且 §3.4 的守卫会直接 Fatal。

### 裁决 2：列定义是值对象 `TableColumn`，`cellBuilder` 只收"一行"

```php
TableColumn::make('name', '姓名')            // key（身份）+ title（表头文案）
    ->width(TableColumnWidth::flex(2.0))     // 列宽策略（阶段 1）
    ->minWidth(80.0)->maxWidth(320.0)        // 拖拽/压缩时的夹
    ->align(TextAlign::End)                  // 单元格对齐（**不是** FlexAlign，见下）
    ->cell(fn(mixed $row): Widget => ...)    // 一格；**不收列号**
    ->header(fn(): Widget => ...);           // 可选：自定义表头（图标/按钮）
```

- **`cell` 只收 `$row`**：列号由 `TableColumn` 自己就是"那一列"表达。
  收列号会让同一个闭包承担"我该画哪一列"的职责 ⇒ 一个闭包 N 个分支，正是"简单版"。
- **`key` 必填**：它是选择、排序、列宽覆盖的**唯一身份**。用列序号做身份的话，
  插列/隐藏列会让所有状态错位（`Each` 的 key 纪律同源）。
- **`align` 用 `TextAlign` 而不是 `FlexAlign`**：`FlexAlign` 是交叉轴对齐（含 `Stretch`），
  表格要的是"内容在单元格内的水平对齐"。`TextAlign` 已在（`Text` 用），复用。

### 裁决 3：列宽三层，`TableColumnWidth` 是值对象

```
第 1 层  策略给初值          TableColumnWidth::fixed(120) / flex(2.0) / intrinsic()
第 2 层  每列 Min/Max 夹     TableColumn::minWidth() / maxWidth()
第 3 层  用户拖拽的实际值     TableState::$widthOverrides[key]   ← 最优先
```

`TableColumnWidth` 的形状（**值对象，不是 enum**——它带参数）：

```php
TableColumnWidth::fixed(float $px)
TableColumnWidth::flex(float $weight)     // 剩余空间按权重分
TableColumnWidth::intrinsic()             // 内容自适应（见下方边界）
```

分配算法（**纯函数、在 build 期算，不在布局期**）：

```
① 每列初值：Fixed → px；Flex → 0（占位）；Intrinsic → 该列可见行 + 表头的最大 intrinsicWidth
② 剩余 = 视口宽 − Σ初值 − columnSpacing×(n−1)
③ Flex 列按权重分剩余（剩余 < 0 ⇒ 全 0，交给 C5 的溢出策略）
④ 每列夹 [minWidth, maxWidth]
⑤ widthOverrides 里的列直接取实际值（**仍夹 ④**）
```

**为什么必须在 build 期算**：行 Widget 是 `Row[SizedBox(宽)…]`，列宽得在造行之前就有。
而视口宽只有布局期才知道 ⇒ **用上一帧的盒尺寸，首帧用 `ctx->width`**。
这与 `LazyListState::viewportEstimate()` 逐字同一条模式（包括"首帧用窗口尺寸，它是任何嵌套视口的上界"）。

> **诚实后果**：窗口 resize 时 flex 列宽会滞后一帧。`LazyList` 的窗口同样滞后一帧，
> 真机验收过没有可见抖动。**不为此引入两遍布局**（§3.1 已判 no-op）。

**`intrinsic()` 的诚实边界**：虚拟化下只能量"当前可见行 + 表头"的最大值 ⇒
滚动时列宽会**漂**。所以：

- 默认**不**启用 `intrinsic()`（默认 `flex(1)`？不——默认必须显式给，见下）
- 启用后一旦用户拖过该列，`widthOverrides` 就固定住它，不再漂
- 文档里写死这条：`intrinsic()` 适合"列少 + 数据量小 + 不滚动"的表，宽表请用 `fixed` / `flex`

**默认值**：`TableColumn` 不写 `width()` ⇒ `flex(1.0)`（等分）。理由是"不写也要能看"，
且等分是唯一不需要猜内容的合理兜底。

### 裁决 4：排序受控 + 选择**受控**（2026-10-26 修正，原为"选择在 State"）

**排序**（阶段 3）：

```php
Table::make()
    ->sortBy('name', ascending: true)              // 受控：当前排序（null = 未排序）
    ->onSortChange(fn(?string $key, bool $asc) => ...)  // 点击表头时上报
```

- 控件**不排序**（它不知道 `$row` 的语义），只做三件事：画箭头、点击表头派发意图、三态循环
  （无 → 升序 → 降序 → 无，`ag-Grid` / `WPF` 同款）。
- 应用侧排序后把新 `rows` 传回来 —— 与 `UsersPage` 现有的 `visible()` 过滤同一形态。

**选择**（阶段 2）—— ★ 2026-10-26 修正：**受控**，不住 `TableState`。理由见 §11.4。

```php
final class TableSelection          // 不可变值对象，只装"哪些行被选中"
{
    public static function none(): self
    public static function of(array $keys): self
    public function toggle(string|int $key): self
    public function has(string|int $key): bool
    public function keys(): array           // list<string|int>
    public function count(): int
}
```

- 应用持有，每次 build 用 `->selection($this->selection)` 递进来；
  点击勾选只回调 `onSelectionChange(TableSelection $s)`（与 `Select::value()` /
  `Checkbox` 同一哲学：**控件不自持"选中了谁"**）。
- **纯 UI 态仍住 `TableState`**：`hoverKey`（悬停行）、`anchor`（Shift 连选的锚，
  存 `{row, col}` 下标而不是 key —— 它只在一次手势内有效，2026-10-31 改）、
  `focusedCell`（键盘焦点格）。判据是"应用要不要读它、要不要从外部改它"——
  批量删除/导出要读选择集，没人要读"鼠标现在悬停在第几行"。
- **行态由外层注入**：`RenderTable` 给每行套一层行壳（背景 / 悬停 / 选中 / 聚焦描边），
  `cellBuilder` **完全不知道**自己被选中 —— 这是"加选择不用改每列 cell"的唯一办法。

### 裁决 5：单元格焦点由 `TableState` 管，不进 `FocusManager`

- `FocusManager` 是**单焦点**（一个节点）。表格要"当前格 (rowKey, colKey)"二维位置。
  成熟框架无一例外把 current cell 放在 view 内部（`CurrentCell` / `currentIndex`）。
- 整个表格在 `Tab` 序里占**一个**焦点位；进入后方向键在 `TableState` 里移动
  `focusedRow/focusedCol`，走 `Widget::keyboard()`（W8-2 已开的缝，`Slider` 是第一个消费者）。
- **阶段 1 就要留行态注入点**（同裁决 4 的行壳），否则阶段 2 加焦点要改行构建。

---

## 5. API 草图（阶段 1 的**完整**签名，后续阶段只加不改）

```php
Table::make(
    [TableColumn, ...],                    // 列定义
    list<mixed>,                           // 行：任意值，原样；身份由 keyOf() 给（§11.2 不做 TableRow）
)
    ->rowHeight(44.0)                      // 默认 44；★ 定高是虚拟化的前提（同 LazyColumn::itemExtent）
    ->headerHeight(40.0)                   // 默认 40
    ->keyOf(fn(mixed $row): string|int)    // ★ 必填（选择/锚点/列宽都靠它）；与 Each 同纪律
    ->columnSpacing(0.0)
    ->striped(true)                        // 斑马行（阶段 1，纯绘制）
    ->onRowTap(fn(mixed $row) => ...)      // 阶段 2
    ->selection(TableSelection $s)         // 阶段 2（受控，§11.4）
    ->onSelectionChange(fn(TableSelection $s) => ...)
    ->sortBy(?string $key, bool $asc)      // 阶段 3（受控）
    ->onSortChange(fn(?string $key, bool $asc) => ...)
    ->status(TableStatus $s)               // 三态（§11.6）
    ->totalCount(int $n)                   // 可选：服务端总数（§11.7）
```

★ **`make()` 只收两个位置参数，其余全走链式**（2026-10-26 修正）。原草图画的是
`Table::make(columns:, rows:, rowHeight:, headerHeight:)` 命名参数风格，两个理由否掉它：

1. 本仓全部控件都是「`make()` 位置参数收必需 + 链式收可选」（`LazyColumn::make(56.0)` /
   `Select::make($options)` / `Each::make($keyFn)`），Table 不该是一处例外。
2. 命名参数在 tpc 里**语法上支持**（`CompilerBase.php:3223` 有 `$hasNamedArg`），但
   `src/` 与 `apps/` 里**零个调用先例**——未真机验证过的语法不该写进新控件的构造签名。

而 4 个参数里只有 2 个是必需的（列、行），另 2 个有合理默认 ⇒ 走链式，签名不膨胀。

★★ **`TableState` 不出现在公共 API 上**（2026-10-26 修正）。原草图写的
`->state(TableState $state)` 是**无法实现的空头承诺**：

> `State` 由 `StatefulElement::mount()` 内部 `$this->widget->createState()` 造
> （`StatefulElement.php:38`），外部拿不到引用，本仓也**没有 `GlobalKey`**。
> 想支持注入，得改 Element 的生命周期不变量——代价远超这一条 API 的价值。

命令式需求（滚到某行 / 重置全部列宽 / 聚焦某格）的**预定通道**是 `TableController`
（应用持有，State 在 `mount()` / `dispose()` 时 attach / detach，与 Flutter
`ScrollController` 同款），**但阶段 1 不建**：没有真实消费者时它就是死分支
（§24.2 #25 的教训，`AnimationController` 的 docblock 也写着同一条）。
形态已定 ⇒ 将来加它是"新增一个类 + State 里两行"，**不改本设计任何结构**。

---

## 6. 分阶段（**每阶段都不推翻上一阶段**）

| 阶段 | 内容 | 新增结构 | 为什么不会推翻前面 |
|---|---|---|---|
| **1** | `RenderTable` + `TableState` 窗口 + `Fixed`/`Flex` 列宽 + 表头 + 虚拟行 + 斑马行 | 新盒、新 State、`TableColumn`、`TableColumnWidth` | — |
| **2** | 单选/多选 + 行态壳（悬停/选中/聚焦）+ `Tab` 入表 + 方向键 | `TableSelection`、行壳 | 行壳在阶段 1 就位（裁决 4/5），cell 不动 |
| **3** | 排序（受控三态 + 表头箭头） | `onSortChange`、表头点击 | 表头本来就是可点击目标，只加箭头绘制 |
| **4** | 列宽拖拽（`SizeWE` 手柄 + `ownsPress`）+ 双击恢复 | 手柄子盒、`widthOverrides` 写入口 | 三层列宽在阶段 1 就定型（裁决 3） |
| **5** | 横向滚动（启用 `offsetX` + 横滚动条） | 横滚动条、x 夹取 | `offsetX` 通道阶段 1 就在（裁决 1） |
| **6** | 列虚拟化（只造可见列）✅ 2026-10-27 | 列窗口计算（`Layout\ExtentWindow`） | 内容宽/偏移已在，只需裁列 |
| **7** | 单元格编辑 | `editingKey`、Store 外置 | 行壳 + 焦点格已就位 |

**阶段 1 的验收闸门**（必须全过才允许进阶段 2）：
1. 10000 行只造约一屏（`TableState` 能报出 `createdRows()` 计数）
2. 表头不随竖向滚动移动
3. 窗口 resize 时列宽重算、行宽与表头**逐像素对齐**（这是最容易错的：两处各算一次必然漂）
4. `offsetX = 0` 时 `RenderTable` 的行为与"表头 + 行区简单堆叠"逐像素一致（为阶段 5 留退路证明）

---

## 7. 诚实边界（写进类注释，不许含糊）

| 不做 | 理由 | 反悔成本 |
|---|---|---|
| **变长行高** | 虚拟化要求 `first = floor(offset / extent)` 的 O(1) 换算 | 高：要测量缓存 + 失效策略 |
| **列合并（colspan）** | 破坏"每行同构"，虚拟化下窗口不再是矩形的 | 高：需要独立布局通道 |
| **树形展开（TreeTable）** | 行序由展开态决定，窗口要算"可见行序"而非"数据序" | 高：行序表 + 展开状态 |
| **拖列重排** | 与列宽拖拽是两种手势（一个改宽、一个改序），且列序要进 `TableState` | 中：加 `columnOrder` + 拖拽目标高亮 |
| **分组 / 聚合行** | 行序由分组决定（同上） | 高 |
| **冻结列（左/右固定）** | 需要**三个**横向偏移（冻结左 / 主区 / 冻结右）+ 各自裁剪 | 中：`RenderTable` 的 paint 分三段 |

**行高必须定高**这一条与 `LazyColumn::itemExtent` 完全同源，理由也一样（O(1) 窗口换算）。
企业表格里定高是常态，不是妥协。

---

## 8. 与既有文档的冲突修正

1. **GAP-ANALYSIS P2-13 的判据错误**：
   原文"手势识别器家族（long-press / pan / pinch）—— Table 列宽拖拽与 TreeView 都要它"。
   **前半句错**：列宽拖拽走 `ownsPress` + 捕获（`Slider` 已证），不需要 gesture family。
   TreeView 的"长按弹菜单"才真的需要 long-press。
   ⇒ 顺手修 GAP 这句话。

2. **`Overflow.php` 的 `Wrap` 记录与 GAP P2-11 的冲突**：
   `Overflow` 的 docblock 写"`Wrap` 已删（2026-10-25）——类别错误；推翻条件是'子项数运行时
   才知道 + 必须自动换行'，届时它是**独立布局原语**（两遍布局）"。
   GAP 把 Wrap 列入 P2 时没提这条 ⇒ **Wrap 的成本被低估了**，它不是"加个控件"，
   是新增 `RenderWrap` 原语。这件事独立于 Table，不影响本设计。

3. **`ARCH-REVIEW:648` 的双轴虚拟化登记**：本设计的阶段 5/6 就是它的兑现。
   它当时的结论"等有宽表需求"——现在 `UsersPage` 的列表已经有真实数据量，
   但**还没有宽表**。所以阶段 5 仍应等到有真实宽表再启用（`offsetX` 恒 0 不算"做了"）。

4. **本设计自身的两处自相矛盾**（2026-10-26 数据接驳分析时自查出来）：
   - §4 裁决 4 写"选择住 `TableState`"，而 §5 草图写 `->selection(TableSelection $s)`
     （受控）⇒ 两处矛盾。**受控是对的**，两条硬理由见 §11.4。已改裁决 4。
   - §5 草图写 `->state(TableState $state)`，**无法实现**：`State` 由
     `StatefulElement::mount()` 内部 `createState()` 造，外部无引用通道，本仓也没有
     `GlobalKey`。已删，改为 `TableController` 的预定形态。

5. **`Table` **不做** `fromRows()` / `TableRow` 值对象** —— 这与 `Option::fromRows()`
   刻意不同，不是遗漏。三条理由见 §11.2（信息会丢 / 转换量级差三个数量级 /
   列已经声明了字段）。

6. **首帧的视口宽**（2026-10-26 真机踩到，阶段 1 已修）：
   `BuildContext::$width` 是**窗口**宽（根 context 由 `Application::attach()` 按窗口约束
   造，整棵树共用），而表格的可用宽还要减掉导航栏 / 卡片 padding / 兄弟节点。
   `viewportEstimate()` 首帧没有盒 ⇒ 拿窗口宽当上界 —— 对**高度**无害（多造几行），
   对**宽度**有害：flex 列按偏大的宽分完，行宽超出表盒 ⇒ **右边的列被裁掉**。
   真机现象：showcase 的 5 列表只显示前 3 列，**滚动一下就对**（那一刻的重建赶上了
   盒已存在）—— 这正是"错的只是首帧"的指纹。
   ⇒ 修法：`TableState` 记下 `build()` 用过的宽，`tickFrame()` 比对盒实测宽，差 > 0.5
   就 `markDirty()` 并返回 `true`（要一帧）。为什么必须靠返回值：`wantsFrame()` 没有
   "有脏 State"这条判据（脏标记本该被**同帧** settle 消费，落在 layout 之后的脏没人管），
   而 `tickFrame()` 的返回值是第 ④ 判据、且跑在 `Frame::run()` **之后**（迟到挂载补
   tick）⇒ 读得到本帧刚排出的盒尺寸。
   ★ 副作用是"宽变化时多一帧"，只发生在首帧 / 窗口 resize，**不是每帧**（收敛后返回
   false，帧时钟停摆）。
   ★ 这条同时是所有"build 期需要布局尺寸"的控件的通用形态；`Wrap`（§8.2）会再遇到。

7. **§5 草图的排序签名**（2026-10-26 阶段 3 改）：
   原文 `->sortBy(?string $key, bool $asc)` + `->onSortChange(fn(?string $key, bool $asc))`。
   **三处不能用**：① `(null, true)` 这个组合没有意义 —— "没有排序"不该表达成
   "空列 + 某个方向"；② 调用点读不出真假：`->sortBy('age', false)` 分不清 `false`
   是"降序"还是"不排"；③ 次键（多列排序）**无处表达**，将来加就得改签名。
   ⇒ 改为 `->sort(TableSort $sort)` + `->onSortChange(fn(TableSort $next))`，
   `TableSort` 内部就是**有序键列表**（`thenBy()`），多键在结构上已经成立。

---

## 9. 一句话总结

> `Table` **不是** `LazyColumn` 的包装，是"自己持双向偏移的滚动容器 + build 期算行窗口 +
> 表头与行区共享 x 平移"。列是对象、列宽是三层、排序受控、**选择受控**、焦点格在 State。
> 数据以**原始数组**进（不做 `TableRow` 转换），身份靠 `keyOf()` 必填，变更走
> "重建 + 按 key diff"——不做增量通知、不做 Model/View。
>
> 这五条一旦定死，阶段 2..7 都是**加法**。

---

## 10. 与 showcase `BigListPage` 的关系（2026-10-26 补）

`apps/showcase/src/Pages/BigListPage.php`（页 7）是虚拟化的**唯一生产形态消费者**：
10000 行 `LazyColumn(44.0)` + 200 卡 `LazyRow(168.0)`，两者都靠"自己就是滚动容器"+
交叉轴有界（`Expanded` / `SizedBox`）。同数据换成 `Each` 全量实测 **0.8 s / 帧**
（0.076 ms/行，`_build/bench-list3.php`）。

### 能直接用的（Table 阶段 1 照抄）

| 项 | 出处 | 用法 |
|---|---|---|
| 定高 + O(1) 窗口 | `LazyListState::build()` 第 268-271 行 | `first = floor((offset − overscan − start) / extent)` |
| 视口估算 | `LazyListState::viewportEstimate()` | 首帧用 `ctx->height`（上界），之后用上一帧盒；超窗口 2 倍报 Diagnostic |
| 滚动条淡出 | `ScrollBarFade` | 纵向条阶段 1、横向条阶段 5，共用同一份节律 |
| **横向的窗口算法** | `LazyRow`（同一份 `LazyListState`，轴只是参数） | ⇒ 阶段 5/6 不是新算法，是**同一算法换轴**。这条降低了横滚的实施风险 |

### 不能直接用的（这正是"为什么 Table 必须单盒双向"的实证）

1. `LazyColumn` 与 `LazyRow` 都是**独立滚动容器**，两个一起用只能嵌套 ⇒ 撞
   `RenderScrollView::performLayout()` 的硬守卫（沿父链找滚动视口，命中即 Fatal）。
2. 即使绕过守卫，滚轮路由是"沿父链找**最近**的滚动容器"（`ScrollInputRouter:80`，
   明确"嵌套滚动不做 delta 传递"）⇒ 悬停在表格上滚轮只能滚到其中一个轴，
   另一个轴永远滚不到。这是**体验层的坏**，不是性能问题。
3. 因此：表格的 x 偏移与 y 偏移必须由**同一个盒**持有，两个方向共享同一个裁剪区。

### 验收基线（阶段 1 的量化判据）

- 10000 行 Table 的**行构建数**应与 `LazyColumn` 同量级（十几行），单帧不得出现 0.8 s。
- 若 Table 构建成本显著高于 `LazyColumn`，先查"每行是否多造了一层不必要的包装"，
  而不是先怀疑虚拟化没生效。

---

## 11. 数据接驳：业务数据怎么进 Table（2026-10-26 补）

问题来自一个具体对照：`Select` 支持 `Option::fromRows($rows, 'name', 'id')` 直接喂
DB 行，那 Table 能不能照抄一个 `Table::fromRows()`？

**能直接喂数组，但不该照抄 `fromRows`。** 下面把它拆成三件事：数据从哪来、怎么取值、
变了怎么传播。

### 11.1 三层，各管一段

```
UserStore extends Subject                      ← 业务数据（零 GUI 依赖）
      │  SubjectBuilder::select(fn($s) => $s->visible())     ← 订阅 + 切片
      ▼
list<mixed> $rows                              ← 原始行数组，不做任何转换
      │  Table::rows($rows)->keyOf(fn($r) => $r['id'])       ← 身份
      ▼
Table(列定义 + 虚拟化 + 选择)                   ← 零业务依赖
      │  TableColumn::field('name') / ->cell(fn)             ← 取值
      ▼
单元格 Widget
```

对照三处既有先例，差别一目了然：

| | `Select` | `Each` / `LazyColumn` | **`Table`** |
|---|---|---|---|
| 数据入口 | `Option::fromRows($rows,'name','id')` → `list<Option>` | `items(array, builder)` | `rows(array)` |
| 转值对象？ | **是**（`Option`） | 否（原样 `mixed`） | **否**（原样 `mixed`） |
| 身份 | `Option::$value` | `keyOf()`（可选，仅锚点） | `keyOf()`（**必填**） |
| 订阅 | 无（一次性取） | 无（外层 `SubjectBuilder`） | 无（外层 `SubjectBuilder`） |
| 典型规模 | 几十 | 10³~10⁵ | 10³~10⁵ |

**订阅责任一律不在控件上**（`SubscribingWidget` 的 docblock 是这条的成文法：订阅只能
落在 `SubjectBuilder` / `PageHost` 这类专职控件上，否则 `dead-subscription` 那条 lint
就没有抓手）。Table 对此的贡献只有一条：**它只认数组，不认识 Store**——这正是
"业务层零 GUI 依赖"的镜像。

### 11.2 为什么不照抄 `Option::fromRows()`

`Option` 是 label + value **两个字段**的同质小集合，转成值对象**无损**。Table 三条都不同：

1. **信息会丢**。`TableRow` 要么只装列声明过的字段（那么排序键、编辑时的主键、点行看
   详情要的其余列全没了），要么做成通用 bag（`array<string,mixed>` 换个类名 = 没转）。
   管理后台的表格**几乎必然**要"点一行看详情"，行对象必须原样留着。
2. **量级差三个数量级**。`fromRows` 是一次性转换（几十行，构造期校验字段名）。Table 的
   行是**每次 build 都在**的——上万行 × 每次数据变更，而虚拟化本来只造窗口内十几行，
   全量转换等于把虚拟化的收益原样吃掉。
3. **列已经声明了字段**。`TableColumn::field('name')` 本身就是"这一列取哪个字段"。
   中间再插一层就是**两次字段映射**（`$row['name']` → `TableRow::$cells['name']` → 列取值），
   每一层都能拼错，而两处都要各写一遍缺字段校验。

⇒ **行原样是 `mixed`，字段名声明在列上。** 这与 `Select` 是**同一思路**（声明式字段映射），
只是数据规模决定了这层映射该放在哪儿：小集合放数据侧，大集合放列侧。

### 11.3 列取值：`field()` 与 `cell()`

```php
TableColumn::make('name', '姓名')->field('name')       // 关联数组行（DB fetchAll 原样）
TableColumn::make('status', '状态')->cell(fn(User $u): Widget => Badge::make($u->status))
```

- **`field()` 只认关联数组**（`array_key_exists` + `$row[$field]`）。不认对象：动态属性名
  `$obj->{$field}` 在 AOT 侧的退化形态没验证过（同 `Option::normalize()` 的"不靠 AOT
  类型退化兜底"那条纪律）。对象行用 `cell()`。
- ★★ **`field()` 不是语法糖，它保留字段名，这是硬需求**：`onSortChange(?string $colKey, bool $asc)`
  上报的是列 key，服务端排序 / 过滤要拼的是**字段名**。纯闭包
  `cell(fn($r) => $r['name'])` 此刻字段名已经丢了 ⇒ 应用只能自己再维护一份
  `colKey → 字段名` 映射，那就是两处真相。这条是"列必须自带身份"的**第四个**消费者
  （前三个：选择、列宽覆盖、列虚拟化）。
- **缺字段 ⇒ Fatal**，文案照 `Option::fromRows()` 那套："第几行、缺哪个字段、现有哪几列"。
  **诚实边界**：虚拟化下**只在滚到那一行时才暴露**（`fromRows` 是构造期一次性校验，
  Table 结构上做不到——它不遍历全量行）。

### 11.4 选择**必须受控**（修正裁决 4）

原裁决"选择住 `TableState`"站不住，两条：

1. **应用读不到**。`State` 由 `StatefulElement::mount()` 内部 `createState()` 造
   （`StatefulElement.php:38`），`private ?State $state`，**没有任何外部引用通道**；
   本仓也没有 `GlobalKey`。⇒ "工具栏全选 / 批量删除 / 导出选中"全都无从下手。
2. 自持 + 回调上报 = **两份真相**（`TableState` 里一份、应用 Store 里一份），
   而 `Select` 的类注释已经为这条写过判词：
   > 控件**不自持**"选了谁" —— 自持会让"提交时读到的值"与"屏幕上显示的值"分成两份真相。

**切分判据**（与 `Select` 的"受控值走框架 / 私有态走 signal"逐字同构）：

| 数据 | 归谁 | 通道 | 判据 |
|---|---|---|---|
| **选择集** | 应用 | `selection()` + `onSelectionChange()` | 应用**要读、要改**（批量操作、跨页保留、跳转带选中） |
| 悬停行 / 键盘焦点格 / Shift 锚点 | `TableState` | 纯 UI 态 | 只有控件自己需要，应用读了也没用 |

`TableSelection` 是**不可变值对象**、`===` 可比（框架的 `overlaySignature` 与
`SubjectBuilder` 的门控都靠 `===`；可变对象会让门控永远失效）。它落在
`src/Widget/` 与 `Option` 同级，因为它是公共 API 的一部分。

### 11.5 变更传播：没有增量通知，只有"重建 + 按 key diff"

这是"业务数据怎么和 UI 打交道"的**根**，也是"为什么不做 Model/View"的答案。

| 框架 | 机制 |
|---|---|
| WPF | `INotifyCollectionChanged` + `ObservableCollection`（逐项增删事件） |
| Qt | `dataChanged(topLeft, bottomRight)`（带矩形范围的增量） |
| ag-Grid | rowNode 级事件 |
| **Drift2** | **没有**。`store->notifyChanged()` → 重建 |

代价分析（三步，都跟总行数无关）：

1. `rows` 数组：PHP COW，递给 `Table` 只是引用计数 +1 ⇒ **O(1)**。
2. Widget 造：虚拟化只对**窗口内**行调 `buildRow()` ⇒ **O(可见行)**。
3. Element diff：窗口内按绝对序号复用（`LazyListState::build()` 的 `sizedCell(..., $i)`
   同款），内容没变的行不重绘 ⇒ **O(可见行)**。

⇒ 一次 `notifyChanged()` ≈ O(可见行)。**这就是不需要 `ObservableList` 的理由**，
不是省事。

⚠️ **一条真实陷阱**（现有 `UsersPage` 就是这种形态，必须写进类注释）：

```php
// ❌ 把"选择"也塞进 store 的广播切片 ⇒ 勾一个复选框就重跑 select、返回新数组、
//    === 永不相等 ⇒ 整表子树重建（10000 行的表勾一次要重造一万个 Widget 描述）
->select(fn(UserStore $s): array => $s->visible())

// ✅ 选择只走 Table 的受控属性，不进 store 的广播切片
Table::make(...)->selection($this->selection)->onSelectionChange(...)
```

`SubjectBuilder` 的门控是**两次 `select` 输出的 `===`**（`SubjectBuilder.php:154`），
而它自己的注释就写着"返回每次新建的对象/数组 ⇒ `===` 永不相等 ⇒ 退化成每帧重建"。
Table 改不了这条，只能**减少广播次数**：选择、悬停、排序箭头全走受控/私有态，
不让它们进 store 的切片。

### 11.6 三态：`TableStatus`，与 `OptionStatus` 同构但**不合并**

```php
Table::make(...)->status(TableStatus::loading())
                ->status(TableStatus::empty('还没有用户', '点"新建用户"加一个'))
                ->status(TableStatus::error('加载失败'))
```

- **互斥值对象**（`ready` / `loading` / `empty` / `error` 四选一），`int` 常量 + `===` 链，
  **不用 enum with payload**——tpc 对 `switch` 生成 C2440，这是 `OptionStatus` 与
  `OverlayEntry` 都记过的一条。四态互斥的好处是 `OptionStatus` 写过的那条：
  优先级规则从"运行时约定"变成"类型上不可能违反"。
- **不合并进 `OptionStatus`**：后者的 `row()` 是"在面板里画一行字"，而 Table 的空态
  **必须保留表头**（表头是结构，不是内容）⇒ 输出形状不同，硬合并会多一个布尔开关。
- "rows 恰好为空但状态是 `ready()`"与"应用主动声明 `empty()`"的区别照 `OptionStatus`
  那条：前者画**内置**兜底（Table = 保留表头 + 一行"没有数据"），后者用应用给的文案与提示。

### 11.7 分页 / 远程：内部留薄抽象，公共面只给数组

```php
Table::make(...)
    ->rows($rows)          // 首版唯一入口
    ->totalCount(3400)     // 可选：服务端总数（"共 3400 条"），不影响滚动条
```

内部（`TableState` 取行的唯一两条）：

```php
private function rowCount(): int        // 首版：count($this->rows)
private function rowAt(int $i): mixed   // 首版：$this->rows[$i]
```

窗口计算只经这两个方法 ⇒ 将来加"按索引远程取行"只改这两处 + 一条创建路径，
**不动窗口算法、不动盒、不动公共签名**。

**不做** `rowAt(callable)` / `TableDataSource` 接口：

- 没有真实消费者时它就是死分支（§24.2 #25）。
- 它与 `keyOf(mixed $row)` **冲突**：只给 index 就拿不到 row，要再补一个
  `keyAt(int)` —— 那就是 Model/View 架构了，而 Model/View 的收益（超大数据集的
  窗口式取数）在管理后台表格这个场景里 **10⁵ 行以内用数组就够**。
- 主流里 Model/View（Qt `QAbstractItemModel`）与数组（antd `dataSource` /
  Flutter `DataTable` / WinForms `DataTable`）**长期并存**，选一个即可。
  Drift2 全仓已经是数组派（`LazyList::items(array)`），Table 跟随，不做第二次分叉。

**诚实边界**：滚动条按**已加载**行数算，不是服务端总数。远程分页时应用 append 到数组
（管理后台的典型量级 10³~10⁵，数组无压力）。这与 `LazyList::keyOf()` 的
"框架不能凭空知道"同一条纪律 —— 不猜，写清楚。

### 11.8 应用侧完整写法（可直接抄）

```php
final class UsersPage extends StatelessWidget
{
    public function build(BuildContext $ctx): Widget
    {
        return SubjectBuilder::make($this->store)
            ->select(static fn(UserStore $s): array => $s->visible())   // ★ 只切数据，不含选择
            ->builder(fn(array $rows): Widget => Table::make(
                    [   // 列定义
                        TableColumn::make('name', '姓名')
                            ->field('name')->width(TableColumnWidth::flex(2.0)),
                        TableColumn::make('email', '邮箱')->field('email'),
                        TableColumn::make('status', '状态')
                            ->cell(fn(User $u): Widget => Badge::make($u->status)),
                    ],
                    $rows,                  // ★ 原样行数组，不做转换
                )
                ->rowHeight(44.0)
                ->headerHeight(40.0)
                ->keyOf(static fn(User $u): int => $u->id)              // ★ 必填
                ->selection($this->selection)                            // ★ 受控
                ->onSelectionChange(fn(TableSelection $s) => $this->setState(
                    fn() => $this->selection = $s,
                ))
                ->sortBy($this->sortKey, $this->sortAsc)                 // ★ 受控（只画箭头）
                ->onSortChange(function (?string $k, bool $asc): void {
                    // ★ 排序动作在业务侧：store 重算 visible()，下一帧新数组传回来
                    $this->store->setSort($k, $asc);
                }));
    }
}
```

注意最后一处：**`onSortChange` 里不排序、也不 `setState` 改 rows**——排序是**业务动作**
（可能要走服务端），所以交给 store，由 store 广播带出新数组。控件只负责"点了表头"和"画箭头"
两件事，这与 `Slider::onChange` / `TextField::onChanged` 是同一条纪律。

### 11.9 一句话

> 业务数据住 `Subject`，切片后以**原始数组**进 `Table`；身份靠 `keyOf()`（必填），
> 取值靠列上的 `field()` / `cell()`，选择与排序**受控**，变更走"重建 + 按 key diff"。
> **不做** `TableRow` 值对象、**不做** `ObservableList` 增量通知、**不做** Model/View ——
> 三条都是"数据规模 + 框架既有形状"共同决定的，不是省事。

---

## 12. 阶段 2 落地：选择（受控）+ 行态（2026-10-26）

### 12.1 前置被再次重新识别：不是手势家族，是 `PointerEvent` 的修饰键

§8.1 已经纠正过一次"手势识别器家族是列宽拖拽的前置"。阶段 2 又碰到同型误判：
多选的**真前置**既不是手势家族，也不是 `TableSelection` 值对象，而是

> `PointerEvent` **没有修饰键**。

没有它，Ctrl+点击与 Shift+点击在 `Input` 层**无法表达** ⇒ "多选"只能退化成
"点一下切换"，区间选择做不出来（而这正是表格多选的标准手感）。
本仓的键事件有 `KeyChord`（`ctrl/alt/shift` 三个布尔），指针事件一直是
`x/y/button/phase` 四个量 —— 缺口在数据结构，不在控件。

修法（纯增量，破坏面 = 桥 payload）：

| 层 | 改动 |
|---|---|
| `cpp-src/drift_win32.cc` | `PendingEvent` 加 `e` 槽；`pushPointer()` 内 `event.e = modifierMask()`；`toArray` 的 `kEvPointer` 多输出 `ctrl/alt/shift`（与 `kEvKey` 同一套位编码 1/2/4） |
| `src/Platform/Win32/Win32Platform.php` | 读三个字段传给 `fromBridge()` |
| `src/Input/PointerEvent.php` | 三个 `readonly bool` + 工厂默认参数（既有调用点一字不改）+ `hasModifier()` |

★ 为什么不用鼠标消息的 `wParam`（那里有 `MK_CONTROL` / `MK_SHIFT`）：**没有 Alt**
（那些位被 `MK_MBUTTON` 之类占了），而 Alt+点击在表格/画布类控件里是真实手势。
三个都走 `GetKeyState` 才是一致的口径 —— 壳里只写一处 `modifierMask()`。

★ 诚实边界：`probe-grab` 的模拟点击**不带修饰键**（它只发 `WM_LBUTTONDOWN`），
所以真机验收只能验"点击能选中"，Ctrl/Shift 只在 smoke 里用构造的 `PointerEvent` 验。

### 12.2 行态在**行容器**上合一，cell 不知道自己是第几行

`TableState::rowBackground()` 一处决定：**选中 > 悬停 > 斑马**，都不适用才 `null`
（透出表格底）。

- 选中 = `Accent` + alpha 36（约 14%）压在 `Surface` 上。**不加 `AccentContainer`
  之类的主题 token**：那要同时维护三套主题的三个值，而它们全都等于"把 Accent 按
  同一比例淡下去" —— 那是公式，不是设计决策。
- 悬停 = `Hover`（不透明）。不叠加（选中色 + 悬停色）：叠出来的颜色在两套主题下
  都不可控，而"选中"本来就该压过"悬停"。
- 行级 `pointer()` / `hover()` 声明挂在**行容器**（`SizedBox`）上：派发规则是
  "沿父链找最深的声明者" ⇒ cell 里的按钮自己声明时自然赢，行不抢。

★ **纯展示表不挂这些**（`selection` 与 `onRowTap` 都没声明时）：悬停高亮是
"这里能点"的暗示，给不可点的行只会误导；同时 `keyOf` 也不必逐行调（零成本）。
这一条写在 `$interactive` 上，是**一个**判据，不是三处 `if`。

### 12.3 点击语义（受控：只算下一步，改不改由应用决定）

| 修饰键 | `Single` | `Multiple` |
|---|---|---|
| 无 | 替换为该行 | 替换为该行（`WPF DataGrid` / `ag-Grid` 默认） |
| Ctrl | 同无修饰 | 反选该行 |
| Shift | 同无修饰 | 从**锚点**到该行，**替换**为整个区间 |

- 锚点住 `TableState`（纯 UI 态），Shift 自身不动锚点，无修饰点击把锚点挪过来。
- 锚点存 **key** 不存行号：排序/过滤后行号会漂。代价是 Shift 点击要线性扫一次行
  找下标 —— 点击是低频操作，10000 行 ≈ 亚毫秒。
- 用 **Down** 而不是 Up 触发：列表/表格类控件的既有手感是"按下即选"。

### 12.4 `onRowTap()` 与选择**互不替代**

启用选择的表同样需要"点行打开详情"。所以两个回调并存：先 `onRowTap($row, $index)`，
再算选择并 `onSelectionChange($next)`。只想处理点击不要选择 ⇒ 不声明 `selection()`。

### 12.5 新增的两个响亮失败

1. `selection()` 声明了但 `onSelectionChange()` 没接 ⇒ 挂载期 Fatal。
   理由：受控模型的全部意义是"应用读得到"，不接就等于"点了没反应"，而控件画的
   选中色还停在旧值 ⇒ 每点一次差异放大一层。
2. 沿用阶段 1 的 `keyOf()` 必填 —— 它现在是选择集与锚点的身份。

### 12.6 验收

smoke 12 条新断言（1562 ok / 0 FAIL）：
- 值对象：不可变 / `int 7` 与 `'7'` 是两个 key / `toggle` 反选 / `withAll` 幂等 / `only`
- 点击语义四条（无修饰、Ctrl、Shift 区间、单选忽略 Ctrl）
- 纯展示表不挂 pointer
- 像素：选中行淡 Accent、未选中行是表格底白、悬停行是 `Hover` 底
- 缺上报的 Fatal + 报错文案

★ 断言都做了**变异自检**：把 `SELECTED_ALPHA` 改成 0 ⇒ 像素断言当场红
（"选中 rgb(255,255,255) / 未选 rgb(255,255,255)"）。

### 12.7 阶段 2b（未做，结构已定）

- **键盘焦点格**：整个表格占一个 `Tab` 位，进入后方向键在 `TableState` 里移
  `focusedRow/focusedCol`（走 `Widget::keyboard()`），`Space` 切换选中、
  `Ctrl+A` 全选。裁决 5 已定"不进 `FocusManager`"。
- **内置勾选列 / 全选**：`cell()` 已能表达（闭包捕获 selection）；做成内置会同时牵连
  列宽、表头全选框、阶段 3 的排序箭头与阶段 4 的拖拽手柄 ⇒ 等阶段 4 一起裁决。
- **语义树**：`SemanticsRole::Table` 已声明，但行/格的语义节点要等焦点格就位
  （否则 UIA 里是一张没有行概念的表格）。

### 12.8 同批改判的框架行为：`pointerOwner()` 遇到"自己处理按下"的控件就让位

行级 `pointer(ownsPress: true)` 让"整行可点"成立，但它会**顺手抢走 cell 里按钮的
按下**（行在父链上更近地声明了 `ownsPress`）⇒ 表格里放按钮点不动，而且**不报错**。

修法在 `InputRouter::pointerOwner()`，判据与 `windowDragOwner()` 同构：

```php
for ($node = $element; $node !== null; $node = $node->parent()) {
    $widget = $node->widget();
    if ($widget->pointerOwnsPressValue()) return $node;                           // 自己声明"按下归我" ⇒ 赢
    if ($widget->isFocusable() || $widget instanceof Activatable) return null;    // 更近的"自己处理按下" ⇒ 让位
}
```

★ **这改判了一条既有断言**：smoke 原来断言"祖先 ownsPress ⇒ 子按钮命令不发"
（"Press · 祖先 ownsPress"）。判据改成"子按钮赢"。
理由：那条断言记的是**实现当时的行为**，不是有消费者的裁决 —— 真实消费者只有
`Slider`（自己 ownsPress，第一轮就命中自己，不受影响），而"祖先吞掉子按钮"在
真实控件里没有任何正当场景（模态遮罩走的是 `Modal` 的独立命中链，不是这条路）。

### 12.9 一条 AOT 与 PHP 解释器的行为差异

`pointer()` 回调必须是**两参** `(PointerEvent, Element)`：捕获期派发固定给两个
（`InputRouter::dispatchToCapture()` 的 `$handler($event, $node)`）。

- 解释器（smoke）：闭包只写一个参数**不会报错**（PHP 允许用户函数多传参）。
- AOT 编译产物：当场 `Fatal: expects exactly 1 argument, 2 given`，而它被捕获期
  路径的 `try/catch` 接住 ⇒ 现象是**整页变成错误占位**，不是崩溃。
  ⇒ 每次给 `pointer()` / `hover()` / `keyboard()` 写回调，都按**完整签名**写。

### 12.10 `Ctrl+A` / `Ctrl+C` 在真机上从来没生效过（2026-11-02）

壳的 `keyNameFromVk()` 对 `VK_A`–`VK_Z` 给的是**大写** `"A"`（不看 Shift ——
大小写信息在 `$shift` 位里）。而 `TableState` 里写的是
`KeyChord::make('a', ctrl: true)` —— **小写**。`KeyChord::is()` 是全字段比较
⇒ 这两个分支在真机上永远不命中。

**为什么测试一直绿**：`CellSelectTest` 里构造事件也用小写。
实现与测试同源 ⇒ 双方谁也没有独立判据，这是"假绿"最贵的一种。

**修法两条，都做了**：

1. `KeyChord::make()` 把**单字母**键名归一成大写（`ctrl()` / `alt()` / `shift()`
   改走 `make()`）。大小写信息本来就只在 `$shift` 里，键名留两种写法没有任何
   表达力，只会制造"其中一种永不命中"。
2. `TableState` 两处改成 `KeyChord::ctrl('A')` / `ctrl('C')`，与 `TextKeymap` /
   `TextEditingBridge` / `TextField::claimKeys()` 一致。

★ 这类错误**没有外部判据**能发现（不能靠真机按一遍 Ctrl+A 来守 CI），只能靠
"让两种写法不存在"。同 `M4::$pumpKeys` 的 `placeMenu`：一个名字几种拼法，
其中一种是死的。

---

## 13. 阶段 3 落地：排序（受控三态 + 表头箭头，2026-10-26）

### 13.1 一个值对象，而不是两个参数

`TableSort`（不可变，内部是**有序**的 `list<[列key, 方向]>`）+ `TableSortDirection`
（`Ascending` / `Descending`，没有 `None`）。

| 决定 | 理由 |
|---|---|
| 不用 `(?string $key, bool $asc)` | §8 第 7 条（无意义组合 / 调用点不可读 / 次键无位置）|
| 枚举里没有 `None` | "某列不参与"是 `directionOf()` 返回 `null`，"整表没排序"是 `isEmpty()`。加第三个枚举值会把这两个维度混成一个 |
| `thenBy()` 把已存在的列**移到末尾** | `->by('a')->thenBy('b')->thenBy('a')` 的意图是"主键换成 a、b 变次键"；留在原位等于没换 |
| 内部用 list 不用 map | 优先级是有序的，而 PHP 数组键会把 `'1'` 静默转成 `1`（`TableSelection` 踩过）—— 键干脆不做 map |

**多键是结构，单键是 UI**：`directionOf()` 对每个参与键都返回方向 ⇒ 画箭头的代码
对多键天然正确（每列各画自己的箭头）；阶段 3 的点表头只上报单键。将来做 Shift+点
表头，改的是 `TableState::nextSort()` 里的一行，**不动任何签名**。

### 13.2 控件不排序 —— 与"选择受控"同一条哲学，但理由多两条

数据所有权（应用要读、要能自己改排序）是共有理由。排序独有两条：

1. **变更检测没有便宜的判据**。控件若在 `State` 里缓存排序结果，就得回答"行数组
   变了没" —— 而 PHP 数组 `===` 是**深比较 O(n)**。10000 行每次 build 一次深比较，
   正好把虚拟化省下的钱在数据侧花回去。（受控模式下这个问题**不存在**：应用重排
   后传新数组，控件不缓存任何行。）
2. **比较规则是应用侧知识**。`status` 是本仓 showcase 里的 `cell()` **派生列**
   （不是字段），只有应用知道"停用 = `id % 3 === 0`"；数字按数值、日期按时间戳、
   中文按拼音还是码点、空值排前还是排后，全是应用的决定。控件自己排 ⇒ 这类列
   永远排不了。

⇒ 控件只做三件事：**画箭头、点击派发意图、三态循环**。重排与应用读数据一样，
都在应用侧（showcase 的 `TablePage::comparators()` 就是那 6 行）。

### 13.3 三态循环，以及"点非主键列"要升格

| 当前该列状态 | 点它之后 |
|---|---|
| 不参与排序 | 该列 `Ascending` |
| 主键 `Ascending` | 该列 `Descending` |
| 主键 `Descending` | **取消排序**（`TableSort::none()`） |
| 次键 / 非主键 | 该列**升格为主键** `Ascending` |

最后一行不是细节：阶段 3 的 UI 只上报单键，若在次键上循环，箭头会出现在主键列上
⇒ 用户看到的是"点一下毫无反应"。升格之后的行为才是"点哪列就按哪列排"。

### 13.4 箭头只画在**参与排序**的列上

不做"可排但未排 ⇒ 画个灰色箭头"：那要多一个"未激活态用什么颜色"的决策，而画面上
会同时出现五六个箭头、只有一个是有效状态。改为**有箭头 = 这列在排序**，可排但未排
的列靠指针变手形提示。附带好处：箭头在标题右侧、标题左对齐 ⇒ 箭头出现/消失时
文字位置不动（列宽序列与 `Table.headerRow` 的对齐纪律一致）。

### 13.5 两条响亮失败

1. 有列 `sortable()` 但没接 `onSortChange()` ⇒ 挂载期 Fatal。与 `selection()` 缺上报
   同一条：表头可点却没人接 = 点了没反应，而箭头是控件画的 ⇒ 一直停在旧值。
2. `sort()` 指向的列 key 不存在 ⇒ Fatal 并**列出已有列**。列名拼错时这是唯一能发现的
   地方（箭头永远画不出来，数据也不会变 —— 完全静默）。

★ 反向不报错：`sort()` 指向一列但那一列没 `sortable()`。这是合法的静态展示
（"数据已按 id 排好，不必让用户改"）。

### 13.6 同批修掉的一个真 bug：showcase 每帧造 10000 行

`TablePage` 原先在 `build()` 里调 `rows()` ⇒ **每次 build 都重新格式化 10000 行字符串**
（滚动一格、悬停换行、加选一项都算一次 build）。虚拟化省下的正是这笔钱，结果在数据侧
又花回去了 —— 而且不报错、不卡到"明显坏了"，只是白烧。

改成 `$allRows`（只造一次）+ `$viewRows`（排序后那份），并加 `sortRows()` 在
`onSortChange` 里重排。**这条是加排序时才暴露的**：要写排序才注意到"每帧拿到
的行数组都是新造的"。

### 13.7 验收

smoke 新增 15 条（1577 ok / 0 FAIL）：

- 值对象 2 条：空态/方向/无关列；`thenBy` 追加与"已存在列移到末尾"
- 表头 1 条：可排列挂 `pointer` + `PointingHand`，不可排列不挂（3 列 ⇒ 2 个可点）
- 三态 4 条：首次升序 / 再点降序 / 第三次取消 / 点另一列升格
- 箭头 2 条：方向对（`iconName()`）；没排序时表头**没有** `Icon`
- 串扰 1 条：点表头不改变行选择
- 响亮失败 3 条：缺上报（退出码 3 + 文案）/ 未知列（退出码 3 + 文案）/ 空列 key

★ 变异自检（两条实测为红）：三态改两态 ⇒ "第三次取消"红（诊断 `id↑`）；
箭头改成"可排即画" ⇒ "没排序不画箭头"红。

★ **本块验不出 `ownsPress`**：smoke 直接调 `pointerHandlerValue()`，绕过按下路由 ——
与行点击那次同型。真机点表头 → 箭头出现、行顺序变，才是这条的验收。

真机已验：点表头 ⇒ 箭头出现、排序栏文案跟着变、行序重排；第三次点 ⇒ 回到原始顺序。

### 13.8 真机验收：一个**假 bug**、三条被撤掉的误判，与一个真实的工具陷阱

这一节记的是"排序看起来坏了、查了很久、最后发现代码没错"的全过程。留着它，是因为
下一次做任何"点某处 ⇒ 看某处变化"的真机验收都会再遇到同一个陷阱。

#### 现象

真机点「ID」表头，第一列排出 `1, 10, 100, 1000, 10000, 1001, …`。这是字典序，
而 ID 是整数列 ⇒ 看起来铁定是"比较器没被用上"。顺着这个方向改了三处代码：

| 当时的判断 | 依据 | 实际 |
|---|---|---|
| `\usort` 在 AOT 下不调比较器 | 比较器里的计数器恒为 0 | **错**：`use (&$n)` 引用捕获在 AOT 下不可信，计数不动 ≠ 没调用 |
| 从数组取出的字符串 `===` / `strcmp` 不可靠 | 一段诊断打印出 `strcmp` 返回 1 | **错**：诊断本身写错了，实测 `eq=true sc=0 inarr=true` |
| 行字段取出是 string，要 `(int)` 强转 | 同上 | **错**：`get_debug_type` 一直是 `int` |

#### 真相

点击坐标 `(850, 233)` 实际落在**姓名**列上，不是 ID 列。「用户 1 / 用户 10 /
用户 100…」按 `strcmp` 排出来**就该是** `1, 10, 100, 1000, 10000, 1001…`。
排序代码从头到尾都是对的。

#### 为什么绕了这么久

`probe-grab --click` 的点击坐标与截图像素**不是 1:1**（本机实测约 ×1.64，窗口物理
尺寸大于截图）。用截图里量出来的坐标去点击，每次都偏；偏了之后唯一的信号是"诊断
文件没生成"，于是把它归因成"回调没触发"，再顺着"回调没触发"去查排序逻辑。

#### 正确的做法（已落进 `上报带列序号` 这条断言）

真机做"点 X 看 Y"的验收时，**必须让应用把"它认为点中的是哪个 X"一起上报**。
`onSortChange` 的第二参 `int $columnIndex` 就是干这个的：排序结果不对时，先看
这一列号对不对 —— 列号错了就是点偏了，跟排序算法无关。这个参数设计上的价值
**不是**"应用分派方便"（应用从 `TableSort` 拿 key 也一样），而是**可归因**。

#### 教训

1. **间接证据不能当结论**。"计数器为 0"、"`strcmp` 返回 1"都是间接的，而
   `usort_asc=1,2,3,4,5`、`eq=true` 是直接的。能直接断言结果就别断言过程。
2. **一次只改一个变量**。这轮同时改了"排序算法 + 列分派方式 + 类型强转"，
   任何一个改动生效了也分不清是哪个起的作用。
3. 工具自身的坐标系要先验一遍（那个 ×1.64），再拿它去验产品。

---

### 13.9 阶段 4（列宽拖拽）的前置再核对 —— 阶段 3 刚制造了一个冲突

原来记的前置（`SizeWE` 光标、`ownsPress` 捕获、三层列宽的第 ④ 层）都在。但阶段 3
把 **表头 cell 整格**挂上了 `pointer(ownsPress: true)` 做排序，而拖拽手柄要落在
**列边界那 4px** 上 ⇒ 两者会抢按下。两条路：

1. 在排序的 handler 里按 x 分区（"x > 宽 − 4 ⇒ 开始拖拽，否则排序"）——
   `RenderTable::hitTest()` 的分区思路，但要在 `TableState` 里拿到指针的格内坐标，
   而 `PointerEvent` 给的是根坐标、格宽要自己减。
2. **把手柄做成表头 cell 内的独立子 widget**（右缘一条 4px 的 `SizedBox`，自己
   `ownsPress`）—— 它在父链上**更近**，`pointerOwner()` 天然让它赢（阶段 2 改判的
   判据正好是"更近的声明者赢"）。

⇒ 阶段 4 走第 2 条：排序 handler 一行不改，只往 `headerCell()` 里插一个手柄。
这也解释了为什么阶段 3 不先把"格内 x 分区"这套算术写出来 —— 它会被手柄替代。

---

## 14. 阶段 4 落地：列宽拖拽（受控第 ④ 层，2026-10-27）

### 14.1 第 ④ 层不是新机制，是 `resolveWidths()` 里多一个分支

三层列宽（`① 初值 / ② 分剩余 / ③ 夹`）早就把第 ④ 层的位置写进了注释。**列宽的唯一
真相来源是 `resolveWidths()`**（行 Widget 是 `Row[SizedBox(宽)…]`，宽度必须在造行之
前算好），所以这次没有新增任何"宽度计算"路径 —— 只在第一轮循环里插了 4 行：

```php
$override = $overrides[$column->keyName()] ?? null;
if ($override !== null) { $widths[$i] = $override; $used += $override; continue; }
```

**override 的列不参与 flex 分配**（拖过就变固定宽，Excel / ag-Grid 同款）。

### 14.2 受控，以及为什么回调要三个参数

`Table::columnWidths(array<string,float>)` + `Table::onColumnResize(fn(string $key,
float $width, bool $finished))`。受控的理由与 `selection()` / `sort()` 同源，但**列宽
比它们更硬**：列宽是**用户偏好**，应用必须能存盘（下次开窗恢复）、能"重置"。住在控件
`State` 里就再也读不出来（本仓没有 `GlobalKey`）。

| 决定 | 理由 |
|---|---|
| 按 **key** 存不按列序号 | 插列 / 换序后拖出来的宽度仍落在原来那一列 |
| 值**不在 setter 里夹** | 夹会让"存盘的宽度"与"应用看到的宽度"不一致；夹只有一处（`clampWidth`）|
| 单回调三参数，不做 `onColumnResize` + `onColumnResizeEnd` | 两个回调会造出"必须先接前者才能接后者"的隐式依赖 |
| `$finished` 区分拖动中 / 结束 | 应用可以"拖动中只 `setState`，结束时才写盘"，不必自己猜抬起时机 |
| override 指向**不存在**的列 ⇒ 静默忽略 | 那是**历史偏好**（列删了、存盘值还在）——与 `sort()` 拼错是两回事 |

### 14.3 ★ 路由零改动 —— 但理由与 §13.9 的预判不同

§13.9 预判"手柄靠父链更近天然赢，判据是阶段 2 的'更近的可聚焦/Activatable 让位'"。
**读完 `InputRouter::pointerOwner()` 才发现根本不是那条判据**：

```php
for ($node = $element; $node !== null; $node = $node->parent()) {
    if ($widget->pointerOwnsPressValue()) return $node;   // ← 从命中点往上，第一个就返回
    if ($widget->isFocusable() || $widget instanceof Activatable) return null;
}
```

它是**从命中点往上取第一个 `ownsPress` 声明者**。手柄作为表头格 `Stack` 的子节点，
在父链上比整格更近 ⇒ 天然先被返回。**不需要改 `pointerOwner()`，也不需要给手柄加
"可聚焦"的假身份。**

⇒ 阶段 4 对 `InputRouter` / `GestureArena` / `InputEvent` **零改动**。

### 14.4 记"起始增量"，不记"列宽 = 指针位置"

Slider 能用后者是因为它的值**就是**位置。列宽不行：指针按下的位置在手柄上（列右缘），
按位置直接算会让列宽瞬间缩掉一个手柄宽，视觉上"跳一下"。所以 `TableState` 存四个
临时量：

| 字段 | 用途 |
|---|---|
| `$resizeStartX` | 按下时的指针 x（`null` = 没在拖）|
| `$resizeStartWidth` | 按下时的列宽 |
| `$resizeLastWidth` | 拖动中最后一次上报的值 |
| `$resizeMoved` | 真的 move 过没有 |

**它们不是数据所有权**：拖出来的最终宽度照样交回应用。放在 `State` 里是因为只有它
能在 down 与 move 之间记住起点。

两个由此而来的细节：
- **`leave`（拖出窗口）报"最后那个值"而不是按位置重算**：指针已在窗口外，位置没有
  意义；而应用早就采纳了拖动中的值，回退会让列"跳回去"。
- **纯点一下手柄（没 move）不上报**：那不是"用户改了列宽"，报一次会把应用的
  "有变化才存盘"打成"每次点表头都存盘"。

### 14.5 手柄：`Stack(TopRight)` 的浮层，不是格内第 N 个子节点

```php
$cell = Stack::make()->alignment(StackAlign::TopRight)
    ->children([$cell, $this->resizeHandle($column, $width)]);
```

放进 `Row` 当第 N 个子节点不行：那 5px 会被列宽分配算进去（内容宽被吃掉、而且越拖
越窄）。本仓 `Stack` 只有整体对齐、**没有 `Positioned`**，但 `TopRight` + 手柄显式
高度（`headerHeightPx()`）已经够把它压在右缘并撑满表头高。

**自己不画任何东西**：可见的那条竖线是 `headerDivider()`（每列都有，见 §14.9）；手柄
只负责命中区 + 光标 + 把 hover / 拖动上报给那条线取色。

### 14.6 ★ 同批修掉的一个真 bug：override 只挡住"初值"，没挡住"重算"

第一版实现只在第 ① 轮循环里 `continue`，第 ② 轮"分剩余"仍按 `isFlex()` 判断 ⇒ 被
override 的 flex 列**又被重算一次**。真机表现会是"拖宽一列、松手弹回原值"。

被 smoke 当场抓住（`[60, 120, 60]` 而不是 `[60, 200, 60]`，`120` 正是 `minWidth`
——那是 `clamp(60)` 的结果）。修法是第 ② 轮也跳过 override 的列：

```php
if (!$spec->isFlex() || isset($overrides[$column->keyName()])) continue;
```

**这条值得记下来**：三层列宽是**三个独立循环**，"在第 ① 层生效"不等于"在第 ② 层也
生效"——新增一层优先级时，每一个旧循环都得检查一遍。

### 14.7 验收

smoke **1591 ok / 0 FAIL**（新增 12 条）：

| 断言 | 钉住什么 |
|---|---|
| `resizable()` 默认关 | 不接上报就不出现手柄 |
| 手柄只挂在 `resizable()` 的列上 + `SizeWE` | 不可调列不挂（第 3 列没有）|
| 手柄是浮层，不占列宽 | 宽序列仍是 `[60, 130, 130]` |
| 拖动上报 `(key, 起始宽+增量, finished)` | 拖动中 `false`、抬起 `true`，值相同 |
| 纯按下不拖动 ⇒ 不上报 | "点了手柄"≠"改了列宽" |
| 拖拽过 `clampWidth()` | 左拖停 `minWidth(120)`、右拖停 `maxWidth(260)` |
| `columnWidths()` 压过 flex 分配 | `[60, 200, 60]` |
| override 指向不存在的列 ⇒ 静默 | `[60, 130, 130]` |
| 缺 `onColumnResize()` ⇒ 退出码 3 | 响亮失败 + 指路 |

**★ 两条"真实路由"断言是这一阶段最有价值的产出**：它们在
`TableResizeApplication` 夹具里走 `dispatchInput()`（真 `InputRouter`），验的是
"同一格上，按右缘 ⇒ 只有列宽上报；按标题 ⇒ 只有排序上报"。

理由：其余全部断言都直接调 `pointerHandlerValue()`，**绕过了 `pointerOwner()` 的
父链遍历** —— 而阶段 4 的整个可行性都押在那条遍历上。阶段 3 就是因为验不到这一点，
只能去真机点像素，然后掉进坐标死胡同（§13.8）。

**变异自检（实测为红）**：把 `Stack` 的层叠顺序反过来（手柄在下层、被整格盖住）⇒
4 条红，其中关键那条的诊断直接指出病因：

```
FAIL 真实路由：按在手柄上拖 ⇒ 只走列宽，表头排序收不到
     —— resize=[] sort=[["id",0]] handle=ok
```

### 14.8 边界（阶段 4 不做）

| 不做 | 为什么 / 留给谁 |
|---|---|
| 双击手柄"自适应内容宽" | 要量每列内容的固有宽度（`IntrinsicWidth` 全表扫一遍），阶段 6 的列虚拟化同批做 |
| 拖拽时的竖线高亮 | 要 hover 视觉 + 一个新 token；光标已经够表达 |
| 手柄放宽到 spacing 区域（跨格） | 本仓 `Stack` 没有负偏移 / `Positioned`，5px 命中区是当前结构下的上限 |
| 总宽超视口时的横滚 | 阶段 5 |
| 键盘改列宽（焦点格 + Alt+方向）| 阶段 2b 的键盘焦点格是它的前置 |

### 14.9 真机反馈修正：列边界看不见 ⇒ 每列右缘一条分割线

**反馈原话**：「表头没有分割线，拖动列宽的时候，不好定位。」

#### 为什么会不好定位

阶段 4 的手柄是 **5px 的透明命中区**，唯一的提示是光标变 `SizeWE`。而光标的性质是
**事后**的 —— 它只在鼠标已经压到那 5px 上之后才变。用户想知道"右边这条边界在哪、
我要不要把鼠标再往右挪一点"，此时画面上没有任何东西可看。

#### 裁决：线与手柄分家

| | 分割线 | 手柄 |
|---|---|---|
| 是什么 | **视觉**（列边界在哪） | **交互**（这列能不能拖） |
| 谁有 | 每一列，含最后一列 | 只有 `resizable()` 的列 |
| 宽 | 1px | 5px（盖住线及其两侧） |
| 平时 | `Border` | 透明 |
| 悬停 / 拖动 | `Accent` | 不变（自己不画） |

合起来看：**线看得见、手柄按得到**。不该拖的列有线但不响应 —— 用户由此知道"这列
有边界但不能调"，而不是"这列什么都没有、我也许能拖"。

#### 必须是浮层，不能是 `Row` 的兄弟节点

这是本节的硬约束。行是 `Row[SizedBox(宽)…]`，宽度由 `resolveWidths()` 一次算死
（§14.1）。往 `Row` 里插 1px 的线 ⇒ 表头比数据行多出 `n-1` 个像素 ⇒ **表头与数据
行错位**，而且越靠右错得越多。

⇒ 线走 `Stack(TopRight)`，与手柄同一层叠（`[内容, 线, 手柄?]`），不参与宽度分配。
smoke 里 `宽序列仍 [60,130,130]` 这条断言就是钉这个。

#### 颜色：`Border`，与表头下那条横线同色

`RenderTable::paintOwnChrome()` 早就在表头内侧最后一行画了一条 `Border` 色的横线。
竖线用同一个 token ⇒ 表头成了一个"有完整边框的表头带"，而不是"底线和竖线两种颜色"。

> 实测（1920×1017 截图，DPI 1.25）：表头底 `F3F3F3`(Chrome)、竖线与底线 `D8DEE4`(Border)、
> 五条线的 x 坐标 281 / 859 / 1725 / 1821 / 1909 与五列的右缘逐一对应。

#### 悬停 / 拖动变 `Accent`：这才是"定位"的关键

1px 的淡线解决"看得见边界"，但拖动前用户仍要瞄准。悬停时那条线变 `Accent`
（`#0969DA`，与 `F3F3F3` 的对比度约 4.5:1）⇒ **鼠标靠近哪条线，哪条线就亮起来**，
不需要精确瞄准。

两个状态字段而不是一个：

| 字段 | 时机 | 为什么不能合并 |
|---|---|---|
| `dividerHoverKey` | 指针停在手柄上 | —— |
| `dividerDragKey` | 从按下到抬起 | 拖动中指针常已离开手柄（拖过头 / 拖出表头），`hover` 早已 false。合成一个字段会让**拖动中的高亮闪断** |

两者的"状态没变就不 setState"照抄 `noteRowHover()`：鼠标横向扫过 N 列会连续
enter/leave N 次，每次都重建整表的话扫一遍就是 N 帧。

#### 只画表头，不画数据行

数据行已经有斑马纹表达"行分隔"，再加竖线是两种分隔方式叠加（`§13.6` 箭头那次
同一条判据：一次只给一个视觉焦点）。而且 GitHub / Linear / Notion 的数据表普遍
只在表头画竖线。⇒ 数据行不加线，写进边界表。

#### 验收

smoke **1598 ok / 0 FAIL**（新增 7 条）：

| 断言 | 钉住什么 |
|---|---|
| 每列右缘都有线（含末列） | 末列那条是表格右边框，漏了就成了"开口的框" |
| 线 1px 宽、全表头高、不占列宽 | 浮层不是兄弟节点（`宽序列仍 [60,130,130]`）|
| 像素：线是 `Border` 色 | 线真的画出来了，且与表头底线同色 |
| 悬停手柄 ⇒ 该线变 `Accent` | 取色链路接上了 |
| 悬停只染那一列 | 相邻列仍是 `Border` |
| 拖动中 hover=false ⇒ 线仍 `Accent` | `dividerDragKey` 与 `dividerHoverKey` 分家的理由 |
| 抬起后回到 `Border` | 高亮能清掉，不会"拖完一直蓝着" |

**变异自检（实测为红）**：把 `dividerColor()` 的判定短路成恒 `Border` ⇒ 2 条红，
诊断直接给出残留色：

```
FAIL Table · 悬停手柄 ⇒ 那一列的线变 `Accent` —— rgb(216,222,228,255)
FAIL Table · 拖动中指针已离开手柄（hover=false）⇒ 线保持 `Accent` —— rgb(216,222,228,255)
```

#### 顺带修掉的一处测试脆弱点

`$tableHandleOf` 原来按**下标**取 Stack 的第二个子（`[内容, 手柄]`）。加了分割线之后
第二层变成线，`取"手柄"当场取到了分割线`，3 条既有断言红。改成按**形状**取
（`cursorShape() === SizeWE` / `mods()->fillColor() !== null`）—— 叠层数量会随功能
增加而变，下标不是身份。

#### 边界（本节不做）

| 不做 | 为什么 |
|---|---|
| 数据行也画竖线 | 与斑马纹重复表达"行分隔"，视觉噪音 |
| 悬停时线加粗到 2px | 线宽变化会让它的左边缘移 1px，视觉上"线在抖"；变色已经够醒目 |
| 拖动时显示宽度数值气泡 | 要一个浮动层 + 文本测量，`Stack` 没有 `Positioned`（§14.5 同一条限制）|
| 分割线可配置（thickness / 颜色）| `Table` 已有 20+ 个配置项，这条先不给；要定制时再收成 `TableStyle` |

## 15. 阶段 5 落地：横向滚动（双轴条，2026-10-27）

### 15.1 真问题不在表格，在"一个盒一根条"

阶段 1 给 `RenderTable` 留了横向通道（`scrollX` 进夹取、`appliedOffsetX()`），当时
判词是"阶段 5 只需 `TableState` 允许 `offsetX ≠ 0`，本盒一行不改"。**对了一半**：
布局与绘制确实没改，但滚动条的**接口形状**卡住了：

```php
interface ScrollBarHost {           // 阶段 1~4
    public function thumbRect(): ?Rect;
    public function maxScroll(): float;
    public function isVertical(): bool;   // ← 一个盒只能有一根条
}
```

`isVertical()` 把"轴"写进了每个方法的隐含前提，`ScrollBarHost` 因此无法表达"第二根条"；
`Scrollable::scrollBy($delta)` 同理（只有"主轴"）。横向落地必须先拆这个形状。

### 15.2 结构裁决：轴成为值

| 改动 | 形状 | 理由 |
|---|---|---|
| `Drift\Geometry\Axis` | 纯枚举 `Horizontal/Vertical` | 不带方法：本仓 20 个 enum 全无方法，AOT 无先例；换算放 `ScrollBarGeometry` |
| `ScrollBarHost` | `scrollAxes(): list<Axis>` + 每个几何方法带 `Axis` | 单轴视口返回一个轴、表格返回两个，路由层一次循环，不需要知道谁是主轴 |
| `Scrollable` | 保留 `scrollBy()`，新增 `scrollByAxis(Axis, float)` | `scrollBy()` 是**主轴**语义（`ScrollView` 本来就可能是横向），改签名会让每个调用点被迫知道控件朝向 |
| `ScrollInputRouter` | 命中按 `scrollAxes()` **反序**、拖动按轴取分量 | 反序 = 后画的在上层（与 `RenderBox` 逆序问子盒同一条惯例） |

`ScrollBarGeometry` 顺带收三个换算：`extent()` / `crossExtent()` / `project()`。
**不收的话就是三处各写一遍三元式** —— 漏一处就是"条按宽画、按高命中"。

### 15.3 表格侧：两根条与一个死区

- `TableState` 持 `$offsetX`（与 `$offsetY` 分开：夹取上限来自不同的量，纵向坐标还是行区）。
- `build()` 用 `contentW − 视口宽` 夹上界后传给 `TableBox`；`TableBox` 早已接 `offsetX`，一行未改。
- `RenderTable::scrollAxes()` 返回 `[Horizontal, Vertical]`（**绘制**顺序），画条时正序、
  命中时反序 ⇒ 右下角 6×6 交叠归竖条，"拖竖条把表横向拽走"结构上不可能。

**顺带修掉的真 bug（阶段 1 遗留，阶段 4 后才可能发生）**：`State` 里存的偏移是
"用户增量累加值"，而夹取只发生在盒里。列被拖窄 / 窗口变宽之后，State 值会长期大于
新上限 ⇒ 之后每一笔增量都先消耗这段**死区**，表现是"拖着条、画面不动"。
修法：`build()` 把夹取结果**写回字段**（不经 `setState` ⇒ 不标脏、不多一帧）。

### 15.4 验收

smoke **1608 ok / 0 FAIL**；lint 564 文件 0 error / 0 warn。新增断言集中在：

| 断言 | 证的是什么 |
|---|---|
| `maxScroll(Horizontal) = 880` 且 `maxScroll(Vertical) = 0` | 两轴上限互不串味 |
| `scrollByAxis(Horizontal, 120)` ⇒ 表头与行区子盒 `offsetX` 都是 −120 | 横滚时表头跟着走（这是"表格必须是一个盒"的那条） |
| 横条 `top = 231`（H − 6 − 3）、纵向不溢出不画竖条 | 几何与 `ScrollBarGeometry` 同源 |
| `scrollAxes = [Horizontal, Vertical]` 且两根条同时存在 | 双轴清单与绘制顺序 |
| **真实路由**：拖横条 20px ⇒ `offsetX ≈ 39.07`（20 × 280 / 143.33），`offsetY = 0` | 拖动按轴映射 |
| **真实路由**：角落按下 ⇒ 纵向回退、横向仍在末端 | 命中反序（后画的在上层） |
| 纵向视口收到 `Horizontal` / 横向视口收到 `Vertical` ⇒ 不动 | 单轴实现"问错轴就忽略" |

**变异自检（实测为红）**：

```
① scrollAxes 返回 [Vertical, Horizontal]     ⇒ 2 红（清单顺序 + 角落归属）
② dragThumb 改回 scrollBy($delta)            ⇒ 1 红（x=0 y=39.07 —— 横向拖动喂给了纵向）
③ 删掉 build() 里的偏移写回                  ⇒ 1 红（角落用例，死区导致"拖了不动"）
```

③ 是这次最值得记的一条：**它证明了那个死区 bug 是真的**，不是理论洁癖。

### 15.5 边界（本节不做）

| 不做 | 为什么 |
|---|---|
| 横向滚轮（`WM_MOUSEHWHEEL` / Shift+滚轮）| `ScrollEvent` 只有一条 delta、壳的 wheel 也不带修饰位 ⇒ 要改 `cpp-src/drift_win32.cc` 并重编扩展。单独一步（5b）做，不与框架改造混在一起 |
| 横向的 `ensureVisible` | 表内还没有可聚焦的格子（阶段 2b），当前没有消费者 |
| 键盘横向滚（Shift+方向键等）| 同上，等焦点格落地后与方向键一起设计 |
| 横条的角落挖角（在条上抠掉竖条宽）| 6×6 交叠靠绘制顺序压住即可，挖角要改 `ScrollBarGeometry` 的轨道定义（条长会跟着变），不值 |

## 16. 阶段 2b 落地：键盘光标格（2026-10-27）

阶段 5 的边界表里挂着两条"等阶段 2b"：**横向 `ensureVisible`** 与**键盘**。
这一节把它们一起收掉。

### 16.1 粒度：一个 Tab 位，还是每格一个

否决"每格一个焦点节点"，两条理由各自都够：

1. 1000 行 × 5 列 = **5000 个 Tab 位** —— Tab 键从此不可用（用户要按 5000 次才出表）。
2. 行虚拟化会**回收不可见行** ⇒ 焦点节点跟着消失。焦点丢在一个已经不存在的
   Element 上，`FocusManager::focused()` 拿着一个死节点，下一次按键无处可去。

所以：**整张表 = 一个焦点节点**（`Table` 构造器里 `markFocusable()`），
**光标格 = `TableState` 里的两个整数**。这与既有裁决"表格是一个控件"同源。

### 16.2 光标用下标，选择用 key —— 两种身份，不合并

| | 存什么 | 为什么 |
|---|---|---|
| 选择集 / Shift 锚点 | **key**（行身份） | 回答"哪些**数据**被选中"。排序后选中的还是那几条 |
| 光标格 | **下标**（行号 / 列号） | 回答"眼睛在哪"。排序后**行内容变了，光标留在原位置**（Excel / 资源管理器同款） |

合一个会立刻出问题：`Ctrl+↓` 扩选要求"光标移动但选择不动"，而"移动光标"
若等于"改选择"，这个手势结构上写不出来。反过来"选择变了光标就跳"会让排序后
光标飞到别处。

代价：列隐藏 / 重排之后光标跟"第 j 个可见列"，而不是"原来那一列"。
这条**是想要的**——光标是位置，不是身份。

### 16.3 键盘怎么进来，以及**怎么让位**

`Table` 构造器声明 `keyboard()`，闭包经 `Element` 找 `TableState`：

```php
$this->keyboard(function (KeyEvent $event, Element $node): bool {
    $state = States::of($node);
    return $state instanceof TableState && $state->handleFocusKey($event);
});
```

**两个必须的细节**：

- **两参签名**（`KeyEvent, Element`）。只写一参在 AOT 下当场 Fatal
  （"expects exactly 1 argument, 2 given"），而它被捕获期的 try/catch 接住 ⇒
  现象是整页变错误占位，不是崩溃（阶段 2 踩过）。
- **`handleFocusKey()` 第一行先问 `$hasFocus`**。`keyboard()` 沿父链找**最近声明者**
  （2026-10-24 为 ComboBox 开的缝），于是**单元格里的输入框 / 滑块**按方向键时，
  事件会先飘到表的闭包来。焦点不在表上（`didChangeFocus(false)` 给的真相）时
  必须返回 `false` —— 输入框里 `→` 是移光标，不是移格子。

键位：`↑↓←→` 移格、`Home/End` 到首末行。**不吃 Enter / Space**：表格没有
"激活格"语义，提前吃掉就把"空格翻转选中"这类应用自定义语义堵死（阶段 7 的
编辑由 `TableColumn::editable()` 决定，那时才需要）。

### 16.4 画在哪：`TableState` 算矩形，`RenderTable` 画

两条候选：

- **在 `cellShell` 里叠 `Stack` + `border()`** —— 跟随虚拟化天然成立，但
  `Modifier::border` 是 `stroke`：以路径为中心向两侧各画一半 ⇒ 框向相邻格溢 1px，
  "内描边"名不副实；且 cell 里可能是任意 Widget，描边要压在**行背景之上**却
  包在 cell 之内，层次会打架。
- **`TableState` 算一个内容坐标 `Rect` → `TableBox` 传 → `RenderTable` 画**（采用）。
  列宽只有 `resolveWidths()` 一处真相，盒里**不做**"第几列在哪 x"的算术
  （阶段 4 的"表头比行多 n−1 像素"就是从第二份几何来的）。

描边是**四条实心矩形**（`Paint::fill`），不用 `stroke`：四条 fill 全落在格子矩形
内部，像素位置可控 —— smoke 就是按像素断言它的（顶边在 `y=32..33`，`y=34` 已是
内容底）。格子比 4px 还窄时厚度按半宽收敛，否则左右两条叠成一片实心（看起来像选中）。

坐标转换只有一句：`屏幕 = (内容 − 偏移) + 表头高`。**画在 `translate` 之外**：
跟着行区的 translate 画还得再补 `sliceOrigin`（这扇窗的起点），那是第二处会
算错的地方。

**失焦不画框**（`focusRect = null`），光标格保留 —— "焦点在别处却有焦点框"是撒谎，
而保留光标才能"Tab 出去再回来停在原处"。

### 16.5 顺带补上阶段 5 挂着的那条：横向 `ensureVisible`

`ensureVisible()` 现在是**两轴各算一次**：

```php
$dy = $box->revealDelta($target, $margin, $this->offsetY);   // 视口 = 行区（减表头）
$dx = $box->revealDeltaX($target, $margin, $this->offsetX);  // 视口 = 全宽
```

`revealDeltaX()` 是**镜像不是复用**：两轴的视口定义不同（纵向要减表头高）。硬塞进
一个函数就得多一个"要不要减表头"的布尔参数 —— 那正是 `ScrollBarHost::isVertical()`
被拆掉的形状。

时机：键盘移动后**当场**算（用上一帧列宽），不等帧末 build —— 否则先闪一帧空白。
列宽只在 build 里算得出来 ⇒ `$widthsInBuild` 缓存一份（与 `$widthUsedInBuild` 同一条纪律）。

### 16.6 验收

smoke **1621 ok / 0 FAIL**；lint 564 文件 0 error / 0 warn。新增 12 条断言：

| 断言 | 证的是什么 |
|---|---|
| 焦点链里只有表自己（`order` = 1） | 一个 Tab 位，不是 5000 |
| 初始 `focusedCell = (0,0)` 且 `focusRect = null` | 没焦点不画框 |
| Tab ⇒ 焦点落表、第一格 `300×40` 的框 | 焦点真的进来了，框尺寸来自列宽/行高 |
| `↓` ⇒ 行 +1（框顶 = 40）、`→` ⇒ 列 +1（框左 = 300） | 真 `FocusManager` 链 → `keyboard()` → State |
| 右边界再按 `→` ⇒ 吃掉键、不越界 | 边界不把键漏给上层 |
| `End` ⇒ 末行 99 且 `offsetY = 3792`、框底 ≤ 行区底 | 纵向 `ensureVisible` |
| `→` 到第 2 列 ⇒ `offsetX = 280` | **横向** `ensureVisible` |
| 失焦 ⇒ 不画框、光标格保留 | 两条判据分开 |
| 表失焦时 `handleFocusKey` 返回 false | 焦点在子控件上时让位 |
| 像素：顶边在 `y=32..33`、左竖边贴 `x=0` | 框真的画出来了 |
| 像素：`y=34` 已是内容底色 | 2px 就是 2px（不是一条色带） |

**变异自检（实测为红）**：

```
① 去掉 markFocusable()                 ⇒ 9 红（焦点链空、Tab 不进来、框全无）
② ↑↓ 对调（行方向写反）                 ⇒ 3 红
③ 焦点框漏加表头高（画到表头上）          ⇒ 4 红（像素 top 变白）
④ ensureVisible 只做纵向                ⇒ 1 红（offsetX=0）
⑤ 不看 hasFocus（失焦也画框）           ⇒ 2 红
⑥ 描边加粗到 20px                      ⇒ 1 红（y=34 变成 Accent）
⑦ 去掉 handleFocusKey 的 hasFocus 守卫   ⇒ 1 红（表失焦时仍吃掉方向键）
```

### 16.7 边界（本节不做）

| 不做 | 为什么 |
|---|---|
| `Ctrl` / `Shift` + 方向键扩选 | 要先把"光标移动 vs 选择变化"的合成规则定死（阶段 7 的编辑会先给出需求） |
| `PageUp` / `PageDown` | `KeyChord` 没有这两个 helper，加它们要动平台层 VK 映射 —— 与 5b 同一条风险线 |
| 点哪格聚焦哪格（只做到"点行 ⇒ 光标行",列不动） | 算列要根坐标减行区原点再累加列宽；"点行 ⇒ 光标行=该行"语义上够（资源管理器同款）。列定位留给阶段 7（双击某格编辑时需要更准的列，由 cell 自己声明） |
| 表头高亮当前列 | "当前列"的第二种表达；等编辑态对表头有语义需求时一起做 |
| 光标格的语义树节点 | `SemanticsRole::Table` 已是集合角色，格级 a11y 要先把 grid 角色的行列信息设计出来 |

## 17. 阶段 5b 落地：横向滚轮（轴成为事件的一部分，2026-10-27）

阶段 5 的边界表里挂着"横向滚轮要改 C++ 壳"，这节把它收掉 —— 横向滚动
（条 / 滚轮 / 触控板 / Shift+竖轮）至此四条入口一致。

### 17.1 改的不是"加一条通道"，是"通道的形状"

`ScrollEvent` 从 2026-10-17 起只有一条 `$delta`，壳的 wheel 事件也只有一格量。
横向滚轮要么**新造一条并行通道**（`ScrollEventX` / `scrollAtX()`），要么把轴
塞进同一个事件。选后者，理由与阶段 5 拆 `isVertical()` 同源：**两条并行通道
会让每个消费方写一遍 `if (横向) ... else ...`**，而那份 `if` 就是将来的
bug 温床（漏一处 ⇒ 横向手势落到纵向逻辑上）。

### 17.2 轴的语义：**用户意图**，不是容器属性

| | 谁回答 | 例子 |
|---|---|---|
| "用户要滚哪根" | `ScrollEvent::$axis` | 触控板横滑 ⇒ Horizontal |
| "这个容器有哪根" | `ScrollBarHost::scrollAxes()` | 纵向 `ScrollView` ⇒ `[Vertical]` |
| "这根现在滚不滚得动" | `maxScroll($axis)` | 内容不溢出 ⇒ 0 |

三者**必须分开**：合成一个就会写出"横向手势落在纵向列表上 ⇒ 上下滚"这种
手势事故（用户横划触控板，页面自己上下跑）。

### 17.3 分派规则：竖向是默认手势，横向是明确意图

```
请求轴 ∈ 容器轴清单        ⇒ 滚该轴
否则，请求竖向 且 容器单轴  ⇒ 回落到它唯一那根（横向 LazyRow 照样能滚）
否则                       ⇒ 什么都不做
```

- **竖向回落**保的是既有手感：竖轮是用户唯一的默认手势，落到"只能横着滚"
  的区域上，他不可能是想让它不动（Windows 横向 ListView 同款）。
- **横向不回落**：横轮 / Shift+竖轮是明确表达"我要横向"，纵向容器跟着动
  就是手势事故。
- 判据用 `scrollAxes()`（**结构性**）而不是 `maxScroll()`（**状态性**）：
  后者在"盒还没布局 / 上限恒 0"时会把事件吞掉，而滚轮必须先于布局正确工作。

### 17.4 壳侧的三个决定

| 决定 | 为什么 |
|---|---|
| `PendingEvent.e` 复用为轴标记（0=纵 1=横），**不新增 `kEvWheelH`** | `e` 槽在 wheel 上是空的（pointer 用它放修饰位）；加一个 kind 要多一个序列化分支 + 多一处"别忘了新 kind"的清单 |
| 序列化只在**横向**时输出 `axis` 字段 | 纵向是缺省（PHP 侧 `?? vertical`）⇒ 老壳 / 老断言行为不变 |
| Shift+竖轮在**壳里**翻成横轴 | 与纵向取反同一条纪律："平台惯例 → 框架语义"只发生在一处；否则每个消费方都要再判一次 Shift |

**符号**：纵向取负（用户明确要"滚轮跟随视口"，与 Windows 默认相反，2026-10-19
已在壳里定下），横向**不取负**（原生正 = 向右滚 = 看右边，与框架横向正向一致）。
两者不同号不是笔误，是两条各自独立的手感裁决。

### 17.5 顺带修掉的：无横向溢出时的白重建

`TableState` 的偏移是**增量累加值**，上界夹取在 build 里做。于是"横向装得下"
的表每来一格横轮都会改一次 `offsetX`、标一次脏、再被夹回 0 —— 触控板每秒
几十格就是几十次白重建。

修法：`scrollByAxis(Horizontal)` 在 `contentWInBuild <= widthUsedInBuild` 时
直接早退。这两个数是 build 手边本来就有、顺手留下的，**不参与任何坐标计算**
（列宽真相仍只有 `resolveWidths()` 一处）。

### 17.6 验收

smoke **1626 ok / 0 FAIL**；lint 564 文件 0 error / 0 warn。新增 5 条断言，
另有 1 条既有断言（`LazyRow` 竖轮）**语义被改写**——它原来说的是"`Scrollable`
只递标量"，正是本节拆掉的形状：

| 断言 | 证的是什么 |
|---|---|
| 横轴一格 ⇒ `offsetX = 48`、`offsetY = 0` | 轴来自事件，不由容器猜 |
| 接着竖轴一格 ⇒ `offsetY = 48` 且 `offsetX` 仍是 48 | 两轴不串味（同一次命中） |
| 横向装得下时横轴滚轮**不标脏** | `contentWInBuild` 早退真的生效 |
| 横轴落在纵向视口 ⇒ 不动 | 横向不回落（横划不许把页面上下带走） |
| 竖轮落在"纵向装得下、横向溢出"的表上 ⇒ 两轴都不动 | 双轴容器不回落（回落只对单轴） |
| **LazyRow**：竖轮一格 ⇒ 48 | 竖向回落保住了既有手感 |

**变异自检（实测为红）**：

```
① 忽略事件轴、退回 scrollBy()（主轴语义）      ⇒ 3 红（横轴落到 y 上 / 横向落在纵向视口也动）
② 删掉竖向回落                                ⇒ 1 红（LazyRow 竖轮不动）
③ 删掉 contentWInBuild 早退                    ⇒ 1 红（无横向溢出也标脏）
⑤ 回落时用猜的纵向而不是容器自己的轴            ⇒ 1 红（横向 LazyRow 竖轮不动）
```

> ④「把 `count($axes) === 1` 放宽成 `>= 1`」**测不出**：双轴容器上请求轴总在
> 清单里，走不到回落分支。那段守卫是给"将来三轴 / 更奇组合"的防御，不假装
> 有断言在守它 —— 记在这里免得后人以为漏了。

### 17.7 边界（本节不做）

| 不做 | 为什么 |
|---|---|
| 滚动链（滚到边界再传给外层容器）| 是独立语义（`04` §6.2 已裁决"只滚最近的"），改它要重新定义"边界事件"，与"横向能滚"无关 |
| 触控板的**惯性**滑动 | Win32 把惯性折进连续的 `WM_MOUSEHWHEEL`，壳侧不需要知道；如果将来要用 `WM_GESTURE` 的精确阶段，那是另一条线 |
| `PageUp` / `PageDown` | `KeyChord` 没有这两个 helper，加它们要动平台层 VK 映射 —— 与本节不相干，留给键盘那一步 |

## 18. 阶段 7a 落地：单元格编辑（2026-10-27）

阶段 7 拆成 7a（键盘进编辑 + 文本状态机）与 7b（指针落插入符、双击进编辑、
`cell()` 列的自定义编辑器、`Tab` 在格间走）。本节只做 7a。

### 18.1 先说结论：这不是"给表格加个输入框"

四个"照抄就会静默出错"的点，决定了本节的结构：

| 坑 | 后果（不报错、无日志） |
|---|---|
| 复制一份文本编辑状态机 | UTF-8 边界 / 选区锚点 / "文本一变就折叠选区"三条不变量出现**两处真相** ⇒ 退格删掉半个汉字 |
| 往虚拟化的行里塞 `TextField` | ① 它自带 `markFocusable()`，行被回收 ⇒ 焦点丢在虚空；② 本仓无 `GlobalKey` / `autoFocus` / `requestFocus`，**没有"程序化聚焦后代"的通道** |
| 用**行下标**记住"在编辑哪一行" | 排序 / 过滤会在会话中途换行序 ⇒ 把 A 的修改写进 B |
| 把草稿存在**行**里 | 虚拟化回收行 Element ⇒ 草稿跟着蒸发，滚出去再滚回来就没了 |

### 18.2 文本状态机下沉：`TextDraft` 是唯一实现

`TextFieldState` 与单元格编辑**只剩"文本状态机"这一半相同**：焦点来源、通知
对象、渲染方式、收口键全都不同。相同的那一半抽成 `src/Text/TextDraft.php`，
`TextFieldState` 改成**委托**（668 → 约 400 行）。

> ★ **2026-11-02**：当时说的"一半"其实略小 —— 除状态机之外，**键位映射也相同**
> （`handleKey()` 与 `editKey()` 里十一行逐字重复），那一半当天漏了，见 §18.7 的注。

**重构是行为保持的**：`TextFieldState` 重写后 smoke **1626 逐字不变**——既有
14 条 `TextField` 断言充当守护。

#### `TextChange` 三态而不是 `bool`

```php
enum TextChange { case None; case Caret; case Text; }
```

| 值 | 含义 | 调用方动作 |
|---|---|---|
| `None` | 什么都没变（同位置移动插入符 / 空退格 / 无选区删除） | 早退，**不标脏** |
| `Caret` | 只有插入符 / 选区变了 | 重画 |
| `Text` | 文本变了 | 重画 + **通知外部** |

合成一个 `bool` 会逼调用方比字符串才能区分"要不要回调 `onChanged`"，而
"同位置移动"又会被误判成变化（白回调一次 `onChanged`）。

`TextDraft` 的三条不变量写在类注释里：**插入符恒落字符边界**、**选区是
（锚点，延伸点）不是（起，止）**、**文本一变就折叠选区**（`applyText()` 是
唯一落点，别的路径都过它）。

### 18.3 表格自己实现 `EditableText`，不塞 `TextField`

`TableState` 直接实现 `EditableText`（`caretRect()` / `handleKey()` /
`moveCaret()` / `extendSelectionTo()` / `hasSelection()` / `selectedText()` /
`replaceSelection()` / `deleteSelection()`）。焦点节点仍**只有表一个**：

- 不用给行里塞可聚焦控件 ⇒ 行被回收时不会把焦点带走（阶段 2b 的同款裁决）；
- IME 定位、剪贴板环（Ctrl+C/X/V）、`--chars` 的字符路由**一行不改**就能用
  ——它们只认"当前 `EditableText`"，不认它是输入框还是表格。

`caretRect()` 要跨一层换算（编辑盒在行里、行在表里）：

```php
[$tableX, $tableY]   = RenderBox::absoluteOrigin($tableBox);
[$editorX, $editorY] = RenderBox::absoluteOrigin($editorBox);
return new Rect(
    $editorX - $tableX - $tableBox->contentOriginX() + $local->left,
    …
);
```

**手抄父链累加都是 bug**（`padding` 会双重平移）—— 这里减法用的是同一个
`contentOrigin*()`，与"子盒 offset 住内容盒坐标"那条不变量同源。

### 18.4 会话认 key 不认下标：第三种分工

到阶段 7 为止，"身份"与"位置"的分工有三层，各管一件事：

| 概念 | 用什么 | 为什么 |
|---|---|---|
| 光标格 | **下标**（`focusedRow` / `focusedCol`） | 光标是位置，方向键就该按位置走 |
| 选择 | **key 集合**（`TableSelection`） | 选择是身份，排序后还该选中同一批数据 |
| **编辑会话** | **row key + 列 key** | 编辑跨多帧，中途排序/过滤会换行序 ⇒ 下标会指向另一条数据 |

变异自检 M1（把提交里的 key 换回下标）实测**5 条红**，其中一条直接报出
`log=[[2,…]]` —— 排序后写回了错误的那一行。

### 18.5 草稿住 `TableState`，不住行里

`$draft`（`TextDraft`）与 `$editingRef`（`{row, col}`）都是 `TableState` 的字段。
表自己不随滚动消失 ⇒ 滚出去再滚回来，草稿还在。

配套两个字段：

| 字段 | 为什么必须有 |
|---|---|
| `$draftOrigin` | 提交时"值没变就不回调"（`G2`：宁可少报，不可错报）；也用于 `Esc` 的语义核对 |
| `$revealScrollInFlight` | `ensureVisible()` 内部的滚动**不是用户滚动**。不区分的话"进编辑 → 把格子滚进视口 → 立刻提交"，编辑根本进不去（`scrollBy()` 里提交，`ensureVisible()` 里又触发它）⇒ 用 `try/finally` 圈住 |

### 18.6 `InlineEditorBox`：视觉契约不同，不是参数不同

`TextFieldBox` 是**输入井**（`Pane` 底 + 边框 + 圆角 + 主题内边距）。它在表单里
是对的，在单元格里会得到"双重内边距 + 文字横跳几个像素"。表格编辑要的是
**Excel / ag-Grid 那种**：文字一个像素都不动，只多插入符与选区。

⇒ 新建 `InlineEditorBox`：`BoxStyle::none()` + `EdgeInsets::zero()`，只喂
`RenderTextField`。判据（真机）：`进入编辑` 与 `未进入编辑` 的整窗截图
**只差插入符那一列**。

> **★★ `caretWidth` 必须 > 0。** 首版传了 `0.0`，后果是"进入编辑后看不见插入符"
> —— 1920×1017 整图 diff **恰好是 0**、不报错、不写日志。只有真机放大看图才
> 发现。现在取 `MetricToken::BorderWidth`（与输入井同一个 token），smoke 加了一条
> `caretRect()->width > 0` 的断言（变异回 `0.0` ⇒ 1 红，实测 `w=0.00`）。

### 18.7 键位：进得去、出得来、有退路

| 键 | 不在编辑 | 编辑中 |
|---|---|---|
| `Enter` | 进编辑（**仅当该列 `editable()`**） | 提交 + 光标下移（Excel 的"回车往下走"） |
| `F2` | 进编辑 | 提交（不移动） |
| `↑` / `↓` | 移格 | 提交 + 移格 |
| `Esc` | — | 取消（丢弃草稿，不回调） |
| `←` / `→` / `Home` / `End` / `Backspace` / `Delete` / `Ctrl+A` | 移格 / 首末行 | 归草稿（**与 `TextField` 同一张表**） |
| `Ctrl+←` / `Ctrl+→` / `Ctrl+Shift+←` / `Ctrl+Shift+→` | 无（放行） | 归草稿：按**词**跳 / 按**词**扩选 |

> ★ **2026-11-02 更正**：上一版这里写的是"与 `TextField` **逐字同义**"。逐字同义
> 意味着同一张表有两个副本 —— 加一个键位要改两处，漏一处就是"输入框支持、
> 表格不支持"且不报错。现在下沉为 `src/Input/TextKeymap.php`（§18.2 只下沉了
> 文本状态机，键位映射那一半漏了）。两张表合成一张之后，
> `tests/Unit/Input/TextKeymapTest.php` 一条用例同时守住两个控件。
> 收口键（`Enter` / `F2` / `↑` / `↓` / `Esc`）**仍各留各的**：它们问的是
> "这次编辑怎么收场"，两个控件的答案本来就不同。

**不可编辑列上 `Enter` 必须放行（返回 `false`）**：吞掉就等于堵死应用自定义
语义（"空格翻转选中"是同一条纪律）。变异自检 M2（跳过 `isEditable` 门控）⇒ 1 红。

### 18.8 收口：五条路，都通向"提交"

| 触发 | 语义 |
|---|---|
| `Enter` / `F2` / `↑` / `↓` | 显式提交 |
| `Esc` | 显式取消 |
| 失焦（`didChangeFocus(false)`） | 提交（各平台"点到别处就落库"） |
| 用户滚动（`scrollBy()`） | 提交（格子可能已滚出视口） |
| 点到**别的行** | 提交（同一行**不**提交：行容器 `ownsPress` 抢走了按下，拿不到"格内/格外"的可靠判据，误提交比"再按一次 Esc"更烦人） |
| 编辑行被删 / 行序变化后 key 找不到 | 会话**自动结束**（`build()` 里查活） |

### 18.9 挂载期的两条 Fatal（不是静默降级）

`assertInteractivityDeclared()` 新增两条：

| 情况 | 消息要给的东西 |
|---|---|
| 有 `editable()` 列但没接 `onCellEdit()` | "回车后什么都没发生" + 修法（接 `onCellEdit`） |
| `cell()` 列声明了 `editable()` | "`cell()` 只给一个任意 Widget，没有可读文本" + 修法（改用 `field()`） |

两条都走**子进程**断言（`Errors::report` → `abort()` 是 `exit(3)`，不是异常，
进程内 `try/catch` 接不住）。

### 18.10 验收

smoke **1648 ok / 0 FAIL**（+22）；lint **569 文件 0 error / 0 warn**（+5 个新
文件）；docref **70 ok / 0 FAIL**；selftest exit 0。

新增断言 22 条：`TextDraft` 5 条（按码位退格 / 插入符吸附 / 同位置移动返回
`None` / 全选幂等 / 有选区时键入整段替换）+ 表格 13 条（不可编辑列放行 `Enter` /
进编辑 / 编辑盒左缘 = 100+8 / **插入符有宽度** / 键入进草稿 / `Enter` 提交且光标
下移 / 提交后画新值 / `Esc` 取消 / 同值不回调 / 失焦提交 / 滚动提交 / 行序变了
仍写回原数据 / 编辑行被删会话结束）+ 子进程 4 条（两条 Fatal 的退出码与文案）。

**变异自检（实测为红）**：

| 变异 | 结果 |
|---|---|
| M1 提交报**行下标**而不是 row key | **5 红**（含"行序变了仍写回原数据"报出 `log=[[2,…]]`） |
| M2 跳过 `isEditable()` 门控 | **1 红**（"不可编辑列上 Enter 不吃键"报 `ate=y editing=y`） |
| M3 去掉"用户滚动 ⇒ 提交" | **1 红**（`log=[]`） |
| M4 去掉 `commitEditing()` 的调用 | **5 红** |
| M5 `caretWidth` 改回 `0.0` | **1 红**（`w=0.00`） |

**真机取证**（showcase AOT，1920×1017 客户区，逐像素 diff）：

| 步骤 | 判据 |
|---|---|
| 点行 → `→` → `Enter` | 整窗 diff **= 17，且只在 x=329 一列**（插入符 1×17）⇒ **进入编辑文字一个像素都不动**，只多插入符 |
| 键入 `X` | diff=88（X 出现 + 插入符右移），格子显示"用户 2X" |
| `Enter` 提交 | 格子落库"用户 2X"，焦点框移到下一行 |
| 再进编辑 + 键入 `Y` | 第 3 行显示"用户 3Y"+ 插入符（**光标确实下移了**） |
| `Esc` | 回到"用户 3"，草稿丢弃 |

> **★★ 抓图前必须踢一脚消息泵。** 壳的循环是 `while (pump()) { 需要就画一帧 }`，
> `pump()` 阻塞在 `GetMessageW`：上一拍改的状态如果没等来新消息，画面还是旧的。
> 实测 `Enter` 之后第一张图与"没进编辑"逐像素相同（差点误判"进不去编辑"）。
> 探针里的做法是抓图前 `--cursor=1000,60`（`SetCursorPos` 必然产生一条
> `WM_MOUSEMOVE`），再 `--settle=500` 抓图。

### 18.11 边界（7b 及以后）

| 不做 | 前置 / 为什么 |
|---|---|
| 双击进编辑 | **`PointerEvent` 没有时间戳、没有点击计数**（`clickCount` 全仓 0 处）⇒ 判不了"两次点击的间隔"。前置是**手势时钟**（与 GAP-ANALYSIS P2-13 同源） |
| 指针落插入符 / 拖动框选 | 同上：要"按下—移动—抬起"的手势状态机（`RenderTextField::offsetAt()` 已就位，缺的是手势） |
| `Tab` 在格间走 | `Tab` 现在归焦点系统（"离开表格"），要改就得先决定"表内 Tab 环"与"跨控件 Tab"的优先级 —— 独立裁决 |
| `cell()` 列的自定义编辑器 | 需要"应用提供一个 Widget + 读回值"的协议（`onCellEdit` 现在只递 `string`） |
| 编辑中的撤销 | 全仓无 undo 栈（P3） |
| `Ctrl+方向键` 扩选 | `KeyChord` 侧支持，但"扩选到什么"需要"选区是身份"的表格级语义（选择已有，编辑内选区还没有跨格模型） |

## 19. 阶段 7b 落地：手势时钟 + 双击进编辑 + 指针落插入符（2026-10-27）

阶段 7a 把"编辑会话"做出来了，但只有键盘能进去（`Enter` / `F2`）。本节补上
指针那半边，并顺手把"手势族缺时钟"这个**横跨整个框架的前置**做掉。

### 19.1 先做的不是双击，是"时间"

`PointerEvent` 从 2026-10-17 起就没有时间戳、没有点击计数。双击的三个判据
（间隔 / 距离 / 同一次按下属于哪一串）全都要时间 ⇒ 没有时钟就没有双击。

`Application::dispatchInput(InputEvent, ?float $timestampMs)` 早就收壳注入的时间
（`after()` 用它当基准），只是指针事件没消费它。所以本节的"时钟"不是新造一个
定时器，而是**把已有的那条时间线接到点击链上**。

### 19.2 判据放 PHP，不放壳（否决 `CS_DBLCLKS` 那条路）

Win32 自己会判双击（`CS_DBLCLKS` + `WM_LBUTTONDBLCLK`）。走那条路的好处是
"尊重系统的双击时间设置"、PHP 侧零状态。否决它的理由只有一条，但是决定性的：

> 判据一旦搬进 C++，**间隔 / 距离 / 按键 / 修饰键四条规则就再也测不到了**。

而它们恰恰是最容易"改一行就静默坏掉"的那类规则（双击进编辑、将来的双击选词、
三击选段全挂在上面）。`TapTracker` 让这四条规则在 smoke 里用**显式时间戳**驱动
——不睡一秒、不赌机器快慢：

```php
$taps = new TapTracker();
$taps->annotate(PointerEvent::down(10.0, 10.0), 1000.0);   // count = 1
$taps->annotate(PointerEvent::down(11.0, 12.0), 1200.0);   // count = 2 ⇒ 双击
```

代价是"不跟随用户的系统设置"：阈值是常量（500ms / 4px，与 Windows 默认值一致），
但它们是**构造参数** ⇒ 将来组合根要从注册表读 `GetDoubleClickTime()` 只需要
`new TapTracker($osMs, $osSlop)`，控件一行不改。

### 19.3 四条规则

| 规则 | 判据 | 破了会怎样 |
|---|---|---|
| 间隔 | `now − 上次 ≤ intervalMs` | 慢速双击被当成两次单击（编辑器反复开关）|
| 距离 | `|dx|,|dy| ≤ slopPx` | 手抖几像素就断链（真机上极常见）|
| 按键 | 同一个 `button` | 右键链与左键链互相污染 |
| 修饰键 | `ctrl/alt/shift` 全同 | **Ctrl+点两次**被当成双击 ⇒ 多选时突然进编辑 |

另外两条边界写进代码：**只数按下**（`Move`/`Up` 原样返回、不推进链）、
**`Leave` 断链**（指针离开客户区再回来不算连击，Windows 同款）；
**时间倒退**（假钟 / 校时）也断链 —— 否则"负间隔恒小于阈值"会把任意两次点击
串成连击。

### 19.4 事件是值：`withClickCount()`

`PointerEvent` 是 `readonly`，标注返回**新实例**。唯一的生产者是
`Application::dispatchInput()`：

```php
if ($event instanceof PointerEvent && $timestampMs !== null) {
    $event = $this->taps->annotate($event, $timestampMs);
}
```

★ **没有时间戳就不标注**（保持默认 `1`）：手工造事件（夹具 / 解释器 / 桥的异常
路径）不需要关心它，既有 30+ 处 `PointerEvent::down(...)` 一字不改。

★ 住在 `Application` 而不是 `InputRouter`：路由层是"无时钟"的策略层，时间只有
壳递进来的这一条 ⇒ 时间与"用时间算出来的东西"放在同一处，路由层签名不动。

### 19.5 指针 → 格：两条换算方向**相反**

同一份几何（表原点、内容原点、`offsetX`、列宽前缀和）要服务两个方向的查询，
而它们差一个符号 —— 这是本节最容易写错的地方，故两条都写进注释：

```
盒子 → 内容：absoluteOrigin() 已经含了滚动平移 ⇒ 减原点、减内边距即可
指针 → 内容：指针是**视口**坐标、不受滚动影响 ⇒ 减原点、减内边距之后还要 **加回 offsetX**
```

抄错一条的现象是"滚过之后点的格子和落下的插入符差一个滚动量"。

列间缝隙归**左**格（视觉上它是那一格的右内边距）。列宽取上一帧的
（`$widthsInBuild`）—— 与键盘 `ensureVisible()` 同一个理由：几何只在 build 里
可得，而指针动作必须当场响应。

### 19.6 插入符落在指针处：**建盒之前**就要算出偏移

双击那一刻编辑盒还不存在（它下一帧才建出来），所以"指针 x ⇒ 第几个字符之间"
必须自己算。三条约束：

1. **换算只能有一份**：`RenderTextField::offsetAt()` 抽成静态
   `offsetAtX($text, $style, $x, $metrics, $scale)`，实例方法委托它。
   两份实现的表现是"点下去落这儿、盒子画在那儿"，差半个字，极难归因。
2. **样式只能有一份**：`InlineEditorBox::editorStyle($ctx)`（建盒与算偏移共用）
   —— 量宽用的样式与实际排版不一致 ⇒ 插入符落在相邻字符上。
3. **对齐方式要算进去**：文字起点不是恒等于内边距。左对齐 = `paddingX`，
   右对齐 = `宽 − paddingX − 文字宽`，居中 = `(宽 − 文字宽) / 2`。
   漏了这条的现象是"右对齐的列（数字列）点哪儿都落末尾"。

度量从哪来：`build()` 里存一份 `$ctx->metrics()` 与 `editorStyle($ctx)`
（同 `$widthsInBuild` 的口径：**几何只在 build 里可得**）。还没 build 过 ⇒
落末尾（宁可"点哪都到末尾"，也不猜一个可能差半个字的偏移）。

### 19.7 可编辑列 ⇒ 行必须可点（否则静默失效）

`$interactive`（行要不要挂 `pointer()` / `hover()`）原本是"可选中 **或** 有 tap
回调"。加了双击之后多一条：**有可编辑列**。不补这一条的话，一个既没开选择、
也没接 `onRowTap` 的表声明了 `editable()` —— 双击怎么点都进不去，而且**不报错**，
只有 Tab + Enter 能用。这是"声明了就得生效"的底线。

### 19.8 验收

smoke **1663 ok / 0 FAIL**（+15）；lint **570 文件 0 error / 0 warn**；
docref **70 ok**；selftest exit 0。

| 新增断言（15 条） | 证的是什么 |
|---|---|
| `TapTracker` 9 条：间隔内 ⇒ 2 / 超间隔 ⇒ 1 / 超 slop ⇒ 1 / 换按键 ⇒ 1 / 换修饰键 ⇒ 1 / `Leave` 断链 / 三击 ⇒ 3 且不算双击 / 时间倒退 ⇒ 1 / 只有按下才标注 | 四条规则各自可证伪（不是"跑一遍没崩"）|
| 表格 6 条：单击落光标格（行 **+ 列**）/ 双击进编辑 / 隔 600ms 不进编辑 / 双击不可编辑列不进编辑但落格 / 双击字的左半边 ⇒ 插入符在字前 / 右半边 ⇒ 在字后 | 手势时钟真的接在**真实路由**上（`dispatchInput` → 命中 → 行容器 `ownsPress` → `noteRowTap`）|

**变异自检（实测为红）**：

| 变异 | 结果 |
|---|---|
| M1 去掉 `dispatchInput()` 里的 `annotate()` | **3 红**（双击不进编辑 + 两条插入符落点）|
| M2 `columnAtX()` 不定位列（恒用 `focusedCol`）| **4 红**（单击落格 + 双击 + 两条插入符）|
| M3 `pointerCaretOffset()` 恒返回末尾 | **1 红**（左半边那条报 `x=122.00` = 108+14）|
| M4 `isDoubleClick()` 改成 `>= 2` | **1 红**（三击那条）|

**真机取证**（showcase AOT，1920×1017）：`--dblclick` 是本节新加的探针能力
（两次 `--click` 隔着 `--settle` 与两个进程 ⇒ 永远落在连击窗口之外，双击在真机
上根本无从验证）。取证链：点导航进表格页 → 双击第 2 行「姓名」格的**文字中间**
→ 截图看插入符落在指针处 → 打字（新字插在中间而不是末尾）→ `Enter` 提交。

### 19.9 边界

| 不做 | 前置 / 为什么 |
|---|---|
| ~~拖动框选（按下—移动—抬起）~~ | **已做**：行级见 §21，格级见 §23（阶段 10）。当初记的"要新载荷"是误判 —— 病根不是缺载荷，是存储形状只有"行 key 集"一种 |
| `Tab` 在格间走 | `Tab` 现在归焦点系统（"离开表格"），要改先得裁决"表内 Tab 环 vs 跨控件 Tab"的优先级 |
| 三击选一段 / 双击选一个词 | **已做（阶段 9）**：见 §22。边界算法下沉到 `Text\TextBoundary`（纯函数），表格只做手势分流 |
| 长按（press-and-hold） | 前置**已就位**（帧时钟 `after()` 存在），缺的是"按住 N 毫秒仍未抬起"的状态机，与指针捕获一起做 |

---

## 20. 阶段 6 落地：列虚拟化（只造可见列，2026-10-27）

### 20.1 什么时候才裁

**列宽固定且总宽 > 视口 + 2×`cacheExtent`** 才裁。`flex` 列会被压进视口 ⇒ 永远填满
⇒ 每列都可见 ⇒ 裁与不裁结果逐位相同（这是 `flex` 的语义，不是虚拟化失效）。
所以这项能力只对"列多且定宽 / 有 `minWidth` 撑开"的表有实际作用。

### 20.2 结构：窗口计算抽成 `Layout\ExtentWindow`

行窗口是**闭式**（行高固定 ⇒ `first = floor(offset / rowH)`）；列宽可变
（`Fixed` / `Flex` / 拖拽覆盖）⇒ 必须**累加**才知道第 j 列从哪开始。形状不同，
抽不成同一个概念，但"求可见窗口"本身与 Table 无关 ⇒ 单独一个纯函数类。

★ 没放进 `TableState`：那里已经 2196 行，而且这条逻辑**该能脱离 Table 单测**
（smoke 里有 5 条只喂数组的断言，坏了不必顺着 State 往下查）。

### 20.3 两个必须写对的细节

1. **前缀偏移用 `padding(left:)`，不能用 spacer 顶**。被裁掉的前缀列要在行内补回
   `colLeading`，否则横向越滚越偏。若插一个 `SizedBox` 占位，`Row` 的 `spacing`
   会给"占位 ↔ 第一个格"之间**再**加一个 gap ⇒ 每个窗口都多偏 `spacing`。
   行与表头**各写了一遍**这处逻辑（同一个入参）—— 这是"两处各算一次必然漂"
   （阶段 1 闸门第 3 条）的易漂点，smoke 有一条断言专门守着它。
2. **横滚不再是"永不标脏"**。阶段 5b 的注释说"横向永不改行窗口 ⇒ 只推偏移、
   不标脏"——那是**列没裁**时的结论。裁列之后横滚会换一批列，必须与竖向同规矩：
   `windowCovers(Axis)` 两轴各判一次，盖得住才 0 重建。漏判的现象是"视口边缘
   露出一截没造的空白"，且不报错。

### 20.4 收益（AOT bench，1000 行 × 20 列，列宽 120 固定，视口 1280，cache 200）

每行只造 13 列（视口 1280 + 两倍 cache 200 ÷ 120 ≈ 14，落在 13~14）。

| | 节点 | paint | 帧总 |
|---|---|---|---|
| 不裁列（变异） | 1469 | 5.56 ms | 5.59 ms |
| 裁列（现状） | **972** | **3.64 ms** | **3.66 ms** |

两次连跑差值 <1%（远小于 ±15% 噪声）⇒ −34% 节点 / −35% paint 可信。

### 20.5 变异自检（4 组，全红）

| 变异 | 抓到它的断言 |
|---|---|
| M1 不裁列 | `createdColumns === 3`（→8）、换列后仍 3（→8）、跨带重建数（→0）|
| M2 横滚无条件标脏 | 横滚 100px ⇒ 重建 0（→1）|
| M3 行不补前缀偏移 | 列位不漂（`x2` = −292，正是少补 300）|
| M4 表头不补前缀偏移 | 表头与数据行对齐（`head` = −292 vs `cell` = 8）|

★ M1 第一版变异**没抓到 ①**：我把变异写在 `createdColumns = $colEnd - $colFirst`
**之后**，于是计数仍是真值、只有"窗口变没变"那条红。变异要改**输入**，不要改
**已经被读走的输出**。

### 20.6 边界

- **flex 列不裁**（见 20.1）：这不是缺陷，是"压缩即填满"的必然。
- **光标格 / 编辑格落在窗口外**：靠既有的 `ensureVisible()`（`revealDeltaX`）拉回来，
  与竖向同一套；`cellRect()` / `caretRect()` 用全列宽度算内容坐标，不受窗口影响。
- **命中测试不变**：`columnAtX()` 用全列宽度，视口外的列点不到。


---

## 21. 阶段 8 落地：选择语义外提 + 行级拖动框选（2026-10-28）

### 21.1 先做的决定：**不**按关注点拆成五个类

`TableState` 到阶段 6 结束已经 2279 行。直觉是"按 关注点拆开"（选择 / 编辑 /
列宽拖拽 / 键盘导航 / build）。核完之后**否决**了这条路：

| 想拆的块 | 行数 | 为什么否决 |
|---|---|---|
| 编辑会话 | ~380 | 抽出去就得把 `EditableText` 的 12 个方法变成转发；`draft` 的生命周期仍由 State 驱动（`didChangeFocus` / 滚动提交 / build 期存活检查）。转发层是纯负担 |
| 列宽拖拽 + 表头 | ~330 | 拆它只是"文件短"，下一步用不到。等真要做"列拖拽排序"时再拆才不白拆 |
| build + cell 装配 | ~550 | 必须留（它就是编排） |

判据改成：**只抽"已经天然是纯函数、而且下一步要在这里加东西"的块**。落点是两块：

1. `Layout\ColumnWidths::resolve()` —— 四层分配规则原本就是纯算术（63 行），
   只是碰巧住在 State 里。它有三个"写错了不报错"的坑（override 要退出 flex 分配、
   override 静默忽略、clamp 之后不回头补偿剩余），此前**零单测**。
2. `TableRowPicker::pick()` —— 选择语义。它是三种输入的**唯一出口**（鼠标点击 /
   Shift 连选 / **拖动框选**），留在 State 里意味着拖动那条路要复制一份判断。

★ `TableState` 2279 → 约 2280（净不降，因为同时加了拖动）。**行数不是目标**，
"下一步的新逻辑落在新类上、不落在旧类上"才是。

### 21.2 拖动框选 = 连续的 Shift 点击

这是本阶段最重要的结构结论：**拖动不需要新的选择语义**。`pick()` 的 Shift 分支
就是"锚点到目标**替换**为区间"，拖动逐帧算出来的正是同一件事 ⇒ 手势层只负责
"锚点是哪行 / 现在拖到哪行"，语义层一行不改。

于是"拖动中逐帧上报"也省了：选区受控，逐帧上报等于让应用每帧 `setState`。
拖动中的高亮由 `$dragRow` 自己画（`rowBackground()` 里压过受控选中集），
**松手才上报一次**。

### 21.3 手势不需要新挂点

现状：行容器 `pointer(ownsPress: true)` 已经建立捕获。`InputRouter` 的捕获派发
把随后的 move / up **直达同一个回调**，而 `$event->x/$y` 始终保持**根坐标**
（"要局部坐标就自己减 `absoluteOrigin()`"）。

⇒ "拖到了第几行"可以自己反算（`pointerRowAt()`），不必要求指针底下正好是目标行
的盒子 —— 拖动时它是**按下那一行**。曾经想"把 down 上移到行区容器"，那是多余的
改造：会牵动"格内按钮让位"那条既有约定，而收益为零。

反算的唯一坑：行区内容坐标的 `y = 0` 是第 0 行顶部，**必须减表头高**
（`RenderTable::headerHeight()`，不是 build 期估的 `headerHeightPx()` ——
表头可能被约束压过，量出来的才是真布局）。漏减的现象是"选中的行整体偏下"，
不报错。变异实测：漏减 ⇒ 4 条断言红（区间变成 `[3,4,5,6]`）。

### 21.4 自动滚动是 **move 驱动**

拖到行区上下边界之外就 `scrollBy(±半行)`，由 move 事件驱动而不是定时器。
定时器版能做到"指针停在边缘不动也持续滚"，代价是多一条常驻的每帧路径
（`Frame` 的 ticker 收口）—— 而实际拖动时指针本来就在动。本阶段不换。

★ 第一步 move 只"进拖"、不滚：`extendDrag()` 在 `dragRow` 从 `null` 变非 `null`
时直接 `return`。否则刚过阈值那一刻就滚一下，观感是"手一抖画面跳半行"。

### 21.5 拖动中途断开怎么办

捕获有四条收口路径（抬起 / 卸载 / 失焦 / 浮层外按下），只有"抬起"会派 up。
另外三条收不到 up ⇒ `$dragRow` 残留、高亮一直挂着。

处理：`didChangeFocus(false)` 里清。剩下的（浮层外按下）最坏到**下一次按下**
就被重置，视觉瑕疵而不是正确性问题 —— 不为它引入"全局按键表"。

### 21.6 验收

- **17 条新单测**：`ColumnWidths` 7 条（四层规则逐条）、`TableRowPicker` 10 条
  （无修饰 / Ctrl / Shift / 反向 / 无锚点 / 单选 / 锚点失效 / 无 key / 无 mode / 孔洞）。
- **8 条拖动断言**（真路由，`dispatchInput` 走命中 → 行容器捕获）：
  区间正确、拖动中不上报、拖动中高亮跟手、反向拖、阈值、单选、松手不残留、边缘自动滚。
- **变异 5 组**：override 参与 flex 重算 / override 计入 flex 之和 / Ctrl 挪锚点 /
  区间改累加 / 松手不当 Shift / 行定位不减表头 / 去掉自动滚动 —— 全部见红。
- 闸门：smoke **1705 → 1731 ok / 0 FAIL**；lint 581 文件 0 error；golden 逐像素不变
  （拖动只在 `$dragRow` 非空时改底色，静态渲染路径逐位同前）。

### 21.7 边界

- ~~**只做行级**~~。**已被 §23（阶段 10）推翻**：当时以为格级选区要"新载荷 +
  按格绘制 + 重裁 `onSelectionChange`"，实际只要把唯一存储从"行 key 集"换成
  `list<TableRange>` —— 行集降为投影，行级 API 与回调一字不改。
- **拖出视口外的行要滚到才选得到**：区间是按行下标算的，`pointerRowAt()` 会夹到
  首/末行，所以"拖到边缘滚 → 继续拖"是正确的；但没有"一键选到末尾"。
- **表头不参与拖动**：拖进表头区会夹到第 0 行（不会滚，见 `dragScrollAt()` 的上边界）。

---

## 22. 阶段 9 落地：词 / 段边界 + 双击选词 / 三击选段（2026-10-28）

### 22.1 结论先行：**边界不属于表格**

`19.9` 把这件事记成"缺的是词 / 段的边界（要分词）"。真正的判断不是"怎么分词"，
而是**这段逻辑该住在哪一层**：

| 落点 | 是什么 | 为什么不是别处 |
|---|---|---|
| `src/Text/TextBoundary.php`（新） | 纯函数：`wordAt()` / `paragraphAt()` | 与 `Layout\ExtentWindow` 同一条判据 —— **"求某个区间"与宿主无关**。放进 `TableState` 就只有表格有选词（`TextField` 永远等不到）；放进 `TextDraft` 则"建盒之前算插入符"那条指针路径拿不到它 |
| `TextDraft::selectRange/selectWordAt/selectParagraphAt` | 把区间落成"锚点 + 活动端 + 插入符" | 字节偏移吸附、顺序归一、活动端朝向，都是三段状态机的不变量，必须留在**唯一的那份**实现里 |
| `TableState::handleMultiClick()` | 手势分流（见 22.3） | 它只决定"第几下算什么"，不认识词 |

★ 选词**没有**在表格里写一行边界计算。以后 `TextField` 补了"指针 → 插入符"的通路，
同一套 `TextDraft::selectWordAt()` 直接可用。

### 22.2 词 = 同类字符连续段（没有词典）

| 类 | 成员 | 例 |
|---|---|---|
| 拉丁词 | ASCII 字母数字 `_` + 非宽的非 ASCII 字母 | `abc` `user_id` `café` |
| CJK | `Utf8::isCjk()` | `张三丰` |
| 空白 | `Utf8::isWhitespace()` | 空格 / 制表 / 全角空格 |
| 其它 | 其余（含全角标点） | `，` `-` `(` |

- **中文没有分词**：连续汉字是一个词。§8.3 早就记过"不做完整 UAX #14，那要 ICU 数据"，
  这里同理 —— 猜错比不猜更糟（"双击选中了半个词"没法解释给用户）。
- 汉字与拉丁**分成两类**：否则 `用户1` 会连成一串，而 `用户 1` 与 `用户1` 的手感
  会莫名其妙地不一致。
- 取侧规则只有**两条**：右邻是词字符 ⇒ 选右边的段；否则选左边的段（左边没有就选右）。

★★ **"三条优先级"是假的**：第一版写了"① 左邻是词字符且右邻不是 ⇒ 选左；② 右邻是
词字符 ⇒ 选右；③ 兜底"。变异把 ① 关掉，**32 条用例全绿** —— 因为 ③ 的兜底就是"选左"，
① 是死规则。已删，注释改成两条。**判据不为真的注释比没有注释更贵。**

### 22.3 手势分流：分的是"在不在编辑"，不是"第几下"

连击计数是**累加**的（`TapTracker` 不封顶），同一格上的第 2/3/4 下都会走到
`noteRowTap()`。真正的分岔是**当下有没有在编辑**：

| | 还没编辑 | 已在**这一格**编辑 |
|---|---|---|
| 双击 | 进编辑 + 落插入符（阶段 7b，**不变**） | **选中词** |
| 三击及以上 | 进编辑 + **选中整段** | **选中整段** |

- **不可编辑的列** ⇒ `beginEdit()` 返回 false，什么都不做（与单击同一条纪律）。
- 偏移**从指针重算**（`pointerCaretOffset()`，与"双击落插入符"同一个函数）：
  用草稿当前的插入符会选到上一次点击所在的词，离得近时看不出错。

★★ **顺手拔掉一条静默丢字的路径**：旧实现无条件 `beginEdit()`，而它第一句是
`commitEditing()` ⇒ "编辑中双击"会把用户刚打的字当成**提交**发出去；应用若没写回
行数据，`editableText()` 又取回旧文本 ⇒ **双击一下，输入的内容消失**，不报错、不写日志。
现在"同一格"直接走选区，草稿都不重建。变异实测（把 `isEditingCell()` 短路掉）：
`editLog=[[2,"name","X用户 2"]]` 且盒里只剩 `用户 2` —— 丢字路径与判据都可见。

### 22.4 验收

- **32 条** `TextBoundaryTest`（词 21 + 段 10 + 一条"逐字节扫全文，永不切出半个字"）
- **5 条** `TextDraftTest`（活动端朝向、幂等、段 = 整串、端点吸附与换序、空区间折叠）
- **4 条** `WordSelectTest`（编辑中双击选词 / 三击选段 / 未编辑三连击直接进编辑 /
  **回归：编辑中双击不提交**）—— 几何断言落在 `selectionRect()` 的**宽度**上
  （`用户`=28.00 vs 整串=39.00，差一个词就能分辨）
- 闸门：smoke **448 ok / 0 FAIL** · phpunit **660 tests / 2044 断言** ·
  lint **738 文件 0-0** · docref **70 ok** · gate 8 项 70.2s

**变异自检 8 组（7 红 / 1 绿 → 删死代码）**

| 变异 | 结果 |
|---|---|
| M1 编辑中也重开编辑器（回到旧写法） | **2 红**（editLog 有提交 + 草稿被重建） |
| M2 三击阈值 `>=3` → `>=4` | **2 红**（width 28.00 vs 39.00） |
| M3 关掉取侧规则 ① | **全绿** ⇒ 该规则是死的，已删 |
| M4 关掉唯一一条取侧规则 | **3 红**（`用户1` 选成整串） |
| M5 活动端落到选区起点 | **1 红**（`6` vs `11`） |
| M6 不向同类扩展 | **17 红**（每个词退化成单字） |
| M7 不吞 `\r\n` 的 `\r` | **1 红**（段落 end 多一个字节） |
| M8 偏移改用草稿末尾（不用指针） | **1 红**（width 8.00 / left 31.00）|

### 22.5 边界

- **编辑中单击不挪插入符**：`noteRowTap` 的单击分支只落光标格，编辑盒里的插入符
  不动。要做先得给"编辑中的行容器"开一条 move 路径 —— 与"格内按钮让位"那条约定
  相交，单独一轮做。
- **`TextField` 没有双击选词**：`TextField` 至今**没有指针通路**（只有键盘），
  所以"双击选词"对输入框还不存在。补的时候只接指针 → `offsetAt()` → `selectWordAt()`。
- **选区不能拖动扩展**：`TextDraft::extendSelectionTo()` 已就位，缺的是"拖出格子在
  行容器上继续收 move"的收口（与行级拖动框选共用捕获，但要区分是谁的拖动）。

---

## 23. 阶段 10：**格级矩形选区** —— 一次把"两套语义"合成一套（2026-10-31）

### 23.1 结论先行：缺的不是功能，是**存储形状**

§19.9 把格级选区记成"要新载荷 + 按格绘制，与『选中集 = 行 key 集』的语义分叉"。
那句话是对的诊断，但开的药方是"再加一套载荷" —— 那正是本仓最忌讳的
「同一件事两条路」。真正要改的是**存储**：

| | 改前 | 改后 |
|---|---|---|
| 唯一存储 | `array<归一化 key, 原 key>`（行集） | `list<TableRange>`（选区列表） |
| 整行选区 | 就是那个 map | 一个 `cols === null` 的 `TableRange` |
| 格矩形 | **表达不出来** | 一个带列端点的 `TableRange` |
| 行级 API | `has` / `keys` / `count` / `toggle` … | **一字不改**，改成 range 上的投影 |
| 回调 | `onSelectionChange(TableSelection)` | **同一个**（没有第二个回调） |

行集不再是"存储"，而是"投影"：`keys()` = 各 range 的行 key 摊平去重。
所以"点一行"和"框一片格"是同一个模型的两种取值，不存在两套状态需要同步。

★ 形状参考 Qt 的 `QItemSelection`（range 列表）与 WPF 的
`SelectionUnit × SelectionMode`（两个正交轴）：**单位**（Rows / Cells）与**数量**
（Single / Multiple）分开，而不是合成一个四值枚举 —— 以后加"选列"只是多一个
`TableSelectionBehavior` case，不必把现有四种组合再翻一倍。

### 23.2 点法语义只有一份实现

`TableRowPicker`（行级）与新的格级逻辑合并成 **`TableGridPicker`**，一个
`pick()` 按 `behavior` 分支：

| 修饰键 | `Rows`（默认） | `Cells` |
|---|---|---|
| 无 | 替换为**该行** | 替换为**该格** |
| `Ctrl` | **反选**该行（复选框语义） | **累加**该格（Excel 语义） |
| `Shift` | 锚点行 → 目标行，**替换**为区间 | 锚点格 → 目标格，**替换**为矩形 |
| 单选 | 忽略修饰键 | 忽略修饰键 |

★ `Ctrl` 两种行为不同是**有意的**：整行是"勾选"（可反选），格是"加一块"。
让格矩形能反选就得支持"矩形挖洞"，挖出来的非矩形选区比这个功能本身贵得多 ——
Excel 里 `Ctrl+点` 也是加，不是反选。

★ 四条输入（点击 / `Shift+点击` / 拖动 / `Shift+方向键`）**全部**过这一个
`pick()`；`Ctrl+A` 走同文件的 `selectAll()`（一条 range，不是逐行累加）。

### 23.3 渲染：加在**格**这一层，`cellBuilder` 仍然不知道

`paddedCell()` 多收一个 `?Color $tint`，有值才 `->background()`。于是：

- `cell()` 闭包**签名不变**（§4 裁决 4 的"加选择不用改每列 cell"仍然成立）；
- 底色铺满"这一格"（内边距与对齐都在它里面），不会溢到列间缝隙；
- `Rows` 行为下 `cellTint()` 恒 `null` ⇒ **Widget 树与改前逐位相同**
  （这是 golden 零变化的依据）。

### 23.4 复制：控件只算文本，往哪写由应用决定

```php
Table::make($cols, $rows)
    ->selection($this->selection)
    ->selectionBehavior(TableSelectionBehavior::Cells)
    ->onSelectionChange(fn(TableSelection $n) => $this->setState(fn() => $this->selection = $n))
    ->onCopy(fn(string $tsv) => $this->app->clipboard()?->writeText($tsv));
```

文本来源：`TableColumn::copyText()` 优先 → 其次 `field()` 的字段值 → 都没有
（纯 `cell()` 闭包列）⇒ 空串。**框架不猜"怎么把一个 Widget 变成文本"**。

★ 为什么不自己写剪贴板：`Clipboard` 在 `Platform` 层，控件拿不到宿主
（`State` 上没有 `application()`）。与选择 / 排序 / 列宽同一条哲学。
★ 没声明 `onCopy()` ⇒ `Ctrl+C` **不吃**这个键（让给应用自己的快捷键），
不是静默吞掉。

### 23.5 键盘：`Shift+方向键` 扩选，`Ctrl+A` 全选

★ 坑：`KeyChord::is()` 是**全字段**比较 ⇒ 带 `Shift` 的方向键**不会**命中
`KeyChord::right()`。修法是先摘 `Shift` 位再比（只摘 `Shift`：`Ctrl/Alt+方向键`
今天没有语义，摘掉会让它们从"不认"变成"当普通方向键"）。

★ 无修饰的移动**不动选区**：选区是受控的，键盘挪光标顺手清空会让
"选中一批 → 按键找位置"变成"每次都得重新选"。`Shift+方向键` 第一次按下时
把锚点落成**移动之前**那一格 —— 否则"从这一格往下拉"会差一格。

### 23.6 验收

| 项 | 结果 |
|---|---|
| `TableGridPickerTest` | 21 例（Rows 那 9 条从 `TableRowPickerTest` 原样搬来 + 格矩形 / Ctrl 累加 / Ctrl+A 12 例） |
| `CellSelectTest`（feature，8 例） | 点一格 / **像素按格染** / Shift 成矩形 / 拖动一次上报 / Shift+箭头 / Ctrl+A / Ctrl+C TSV / Rows 回归 |
| `phpunit` | unit 412 / feature 598 全绿 |
| `smoke` | 459 ok / 0 FAIL（零变化） |
| `lint` | 867 文件 0 error 0 warn |

★ 像素判据：`rasterOf()` 采样"选中格中心"与"同行未选中格中心"，断言前者变了、
后者仍是 `#FFFFFFFF` —— 只断"有底色"的话，"整行都染"也会绿。

### 23.7 边界

| 不做 | 为什么 |
|---|---|
| 格矩形 `Ctrl+点` 反选 | 要在矩形里挖洞（非矩形选区），收益不抵复杂度；Excel 也是加 |
| 选列（`TableSelectionBehavior::Columns`） | 枚举留了位，但没有业务消费者 |
| `Ctrl+方向键` 跳到边缘 | Excel 语义，与"扩选"是两件事，单独一轮 |
| 剪贴板多格式（HTML / CSV 变体） | 只有 `writeText`；要做先定"多格式"在 `Clipboard` 上长什么样 |
| 示例台页 | 本阶段只到"能力 + 判据"；页 24 的 Rows 行为与文案未动（golden 零变化） |

---

## 24. 阶段 11：**一帧的几何**收成显式快照（2026-10-31）

### 24.1 结论先行：这不是"搬家"，是**把七处各算一遍改成一处**

`TableState` 到阶段 10 已 2936 行。真问题不是行数，是**同一批几何被存了七份、
"空"被表达了三种**：

| 字段 | 它回答的问题 | "还没布局过"怎么写 |
|---|---|---|
| `$widthsInBuild` | 上一帧的列宽 | `[]` |
| `$widthUsedInBuild` | 上一帧用的视口宽 | `null` |
| `$contentWInBuild` | 上一帧的内容宽 | `null` |
| `$windowStart` / `$windowEnd` | 行窗口的内容坐标端点 | `0.0` / `0.0` |
| `$colWindowStart` / `$colWindowEnd` | 列窗口的内容坐标端点 | `0.0` / `0.0` |

三种空值意味着：**每读一处都得先想"这个空是哪个意思"**。而它们的消费者
（指针映射 / 键盘 reveal / 窗口判定 / 表头构建）各自把"列宽前缀和 + 间距"
重算一遍 —— `MEMORY.md` 里"坐标两族别抄错"就是这么长出来的。

改后：**一次 `build()` 算出一个 `TableGeometry`**，`null` 就是"没算过"（唯一表达）。

| | 改前 | 改后 |
|---|---|---|
| 上一帧几何 | 七个字段 | 一个 `?TableGeometry` |
| "还没布局过" | `[]` / `null` / `0.0` 三种 | `null` 一种 |
| 夹取（两轴） | 散在 `build()` 里 | `TableGeometry` 构造期，**结果写回快照** |
| 开窗（行/列） | `build()` 里两段内联 | 同上 |
| `colAt` / `cellRect` / `rowAt` | `TableState` 私有方法，靠 `$widthsInBuild` | `TableGeometry` 的方法，无外部依赖 |
| 单测 | **只能靠 UI 特征测试间接验** | 21 条纯单测 |

### 24.2 为什么先做几何，而不是先拆表头 / 编辑会话

三条候选切口（表头 ~340 行、编辑会话 ~490 行、几何 ~200 行）里，几何最小，
但**是另两个的共同底座**：表头要吃列宽 + 列窗口 + `colLeading`，编辑会话要吃
`cellRect()` 算插入符。先把几何收成一份，后两个切口就只是"把消费者搬走"，
不必顺带改算术。**先做底座，后做上层** —— 反过来会搬两次。

### 24.3 坐标系写进类注释（这个类最容易抄错的地方）

- `offsetX/offsetY`、`colLeading`、`cellRect()->top` 全在**内容坐标**里；
  纵向的内容坐标是**行区**的（0 点 = 表头下沿，表头是钉住的 chrome）。
- `contentH` **含**表头，`bodyH` **不含** —— 这不是笔误：滚动上限按 `bodyH`
  （表头不滚），滚动条按 `contentH`（整表多长）。合成一个数，未滚动时行区会
  画到表头下面一整条。
- 行窗口按行区算，列窗口按全宽算（表头与行区共享 x）—— **两个定义不同，
  不是同一个式子的两个实例**。

### 24.4 一个副作用：`TableState` 里"适配器"与"算术"分了家

留下的 `columnAtX()` / `currentFocusRect()` / `pointerRowAt()` 现在都只剩
"取快照 + 问它"（3～6 行），语义在 `TableGeometry`。而 `cellRect()` 这个
**纯算术**直接从 `TableState` 删掉了（它原本接收 `$widths` 当参数，是个
"假装是方法的自由函数"）。

★ 反过来说：**指针 → 内容坐标的换算没有进 `TableGeometry`**，而且不该进。
它读的是 `RenderTable::headerHeight()`（**盒实测**），而快照里的 `headerHeight`
是 `Table::headerHeightPx()`（**声明值**）—— 表头被约束压过时两者不等。
把实测值塞进快照，就等于让"上一帧的几何"和"这一帧的布局"混成一个数
（`MEMORY.md`：名义条件 ≠ 实际生效条件）。

### 24.5 判据：几何第一次能纯单测

`tests/Unit/Widget/TableGeometryTest.php` 21 例。挑几条最值钱的：

| 断言 | 它钉住的语义 |
|---|---|
| `contentH = 表头 + 行数×行高` 且 `bodyH = 视口 − 表头` | 两个定义不同 |
| 视口比表头矮 ⇒ `bodyH === 0.0` | 不是负数 |
| 列宽和 120、视口 400 ⇒ `contentW === 400` | 内容宽至少铺满 |
| `offsetY` 上界 = 总行高 − **行区高** | 表头不参与滚动 |
| 缓冲带 0 ⇒ 窗 6 行；缓冲带 200 ⇒ 窗 11 行 | 缓冲带真的让窗变大 |
| `colAt(60.0) === 0`（`spacing = 10`） | 缝隙归左格 |
| `colAt(139.9) === 1` 但 `colAt(140.0) === null` | 尾部 gap 归最后一列，之后不算任何列 |
| `rowAt(-999) === 0` / `rowAt(9999) === 9` | 夹到首末行（拖动要靠它） |
| `coversRows(232, 208) === true` 而 `233` 为 `false` | 正好盖住算盖住 |
| `cellRect(2, 1)->top === 80`（`rowH = 40`、表头 32） | y 是行区坐标，不含表头 |

★ 写这批用例时**三条期望值我算错了**（列窗口末端、尾部 gap、`coversColumns`
的窗口宽）—— 说明判据有牙：不是把实现抄一遍就能过，得真的推。

### 24.6 变异自检（3 组，全红）

| 变异 | 抓到它的断言 |
|---|---|
| `coversRows` 的 `<=` 改成 `<` | 1 条（"正好到端点算盖住"） |
| `contentW` 去掉 `max(视口宽, …)` | 1 条（"列宽和 120 < 视口 400 ⇒ 撑到 400"） |
| `offsetY` 上界用 `视口高` 代替 `行区高` | **2 条**（专项 + 数据提供器里"滚到底"那行） |

### 24.7 验收

| 项 | 结果 |
|---|---|
| `TableState` | 2936 → **2855** 行（−81），字段 −7 |
| `TableGeometry` | 324 行（含注释） |
| `TableGeometryTest` | 21 例；unit 412 → **433** |
| `phpunit` | unit 433 / feature 598 全绿 |
| `smoke` | 459 ok / 0 FAIL（**零变化**） |
| `lint` | 868 文件 0 error 0 warn |
| `docref` | 78 ok / 0 FAIL |
| `gate` | 8 项 104.5s 全绿（**golden 逐像素零变化** ← 重构正确性的主证据） |

★ golden 零变化是**设计出来的**：几何算法的每一步都逐字搬过去（同一个
`ExtentWindow`、同一个 `max(0, …)` 顺序），所以像素不可能动。反过来说，
如果 golden 变了，那就是我"顺手改了一点算术"—— 那必须单独论证。

### 24.8 边界

| 没做 | 为什么 / 下一步 |
|---|---|
| 表头子系统外提（~340 行，6 个手势字段） | 它的底座刚就位。下一个切口；`Qt` 的 `QHeaderView` 是先例 |
| 编辑会话外提（~490 行） | 判据最脆（IME / 插入符 / 提交时机），需要真机配合；排在表头之后 |
| `LazyListState` 的同类字段（`$windowStart/$windowEnd` 等） | 它是单轴，形状与表不同；先看表这套稳不稳，再决定要不要抽公共的 |
| 把 `TableGeometry` 记进 `API-SURFACE` | `@internal`，与 `TableGridPicker` 同口径（那个也没记） |
