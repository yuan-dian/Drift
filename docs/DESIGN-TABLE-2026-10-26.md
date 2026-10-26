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
- **纯 UI 态仍住 `TableState`**：`hoverKey`（悬停行）、`anchorKey`（Shift 连选的锚）、
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
| **6** | 列虚拟化（只造可见列） | 列窗口计算 | 内容宽/偏移已在，只需裁列 |
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
