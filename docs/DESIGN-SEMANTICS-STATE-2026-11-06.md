# 语义状态：让读屏与自动化看见「焦点 / 选中 / 展开 / 层级」

> 2026-11-06 · 方案稿。§64 的直接下游。

## 1. 为什么是这条

§64 收尾后，候选全部是"再加一个控件能力"（`MenuBar` Alt、`Slider` 拖动、
两段式挂起）。按 §40.1 的三条判据重新过了一遍，只有这条同时满足三条：

| 判据 | 本条的取证 |
|---|---|
| 有真实消费者 | 读屏软件；**以及本项目自己的探针** —— §64.7 现场撞上（详见 §2） |
| 错了静默 | 属性不实现 ⇒ 客户端读到 `VT_EMPTY` ⇒ 按默认 `false`，不报错、无日志 |
| 决定"能不能交付" | 无障碍是真实应用的硬门槛；语义面是本仓六面之一，现在缺一半 |

"加一个控件能力"那条线的候选都是**窄**的（一个控件一个键），而语义面是
**整族**的：框架里所有有状态的控件（勾选 / 滑块 / 树 / 表格 / 文本框）
在 UIA 客户端眼里的状态全是空的。

## 2. 缺口取证：一条自己人被骗到的实证

§64.7 写探针时连撞两次：

1. 判据想读 `HasKeyboardFocus` ⇒ **provider 没实现，恒 false**。只好改用
   "折叠后子行从语义树里消失"这种结构判据。
2. 语义树里明明有 `focused` 字段（PHP `SemanticsNode::$focused` 与 C++
   `UiaNode::focused` 都有），C++ 侧也确实在用它 —— 但只用来回答
   `GetFocus()`（Fragment 导航），**没有**在 `GetPropertyValue()` 里暴露。

也就是说：**框架自己知道焦点在哪，却不肯告诉外面。** 这不是"待办"，是半成品。

同类缺口按控件清点：

| 控件 | 状态 | 现状 |
|---|---|---|
| 全部 | 持有焦点 | 数据在，属性缺 |
| 全部 | 可聚焦（Tab 到得到） | 数据在 `FocusManager::$byId`，没有出口 |
| `Checkbox`/`Radio`/`Switch` | 选中 | `TogglePattern` 有，`ToggleState` **属性**缺 |
| `TextField` | 文本 | `ValuePattern` 有，`Value` **属性**缺 |
| `TreeView` 行 | 展开 / 选中 / 层级 | **完全没有**：行是 `Button`，读屏听到"一堆按钮" |
| `Table` 行 | 选中 | 自绘行，语义树里没有行节点（见 §6 边界） |

## 3. 方案：三层，每层只做有声明者的

### 3.1 数据面：`SemanticsNode` 加状态族

三个状态字段 + 一个层级：

```php
public bool $focusable = false;   // 能 Tab 到（不等于"现在有焦点"）
public int  $selectState = 0;     // 0 无此概念 / 1 未选 / 2 已选
public int  $expandState = 0;     // 0 无此概念 / 1 折叠 / 2 展开
public int  $level = 0;           // 0 未声明；UIA `Level` 是 1-based
```

**裁决一：三态用 `int` 编码，不用 `?bool`。**

"没有这个概念"和"false"必须分开 —— UIA 里前者意味着**不提供 Pattern**
（客户端据此知道"这个不能展开"），后者意味着"能展开，现在是收着的"。
混成一个 `false` 会让每个按钮都声称自己能展开。

形状取 `int` 而不是 `?bool`：本仓 `.php → 数组 → C++ Variant` 这条边界是
**AOT 编译器管的**，`null` 跨边界的处置没法在仓内取证；而 `int` 与
`parent` / `depth` 已经在同一条路上跑了几十轮。DX 由 `Widget` 侧的
`?bool` 参数负责，翻译只发生在 `toArrays()` 这一处。

**裁决二：`focusable` 不由控件声明，由框架填。**

"能不能 Tab 到"是 `FocusManager::collect()` 的结论（`$order` / `$byId`
两张表），控件自己说"我能聚焦"会和 `skipFocus()` / `disabled()` 打架。
`SemanticsBuilder::build()` 加第三个参数收一份 id 表 —— 与已有的
`?Element $focused` 同形（也是框架事实，不是控件声明）。

### 3.2 出口面：UIA `GetPropertyValue`

`simpleProperty()` 补六支：

| 属性 | 来源 |
|---|---|
| `UIA_HasKeyboardFocusPropertyId` | `focused` |
| `UIA_IsKeyboardFocusablePropertyId` | `focusable` |
| `UIA_ToggleToggleStatePropertyId` | 复用 `ToggleAdapter::get_ToggleState` 的判据 |
| `UIA_ValueValuePropertyId` | `value` |
| `UIA_IsSelectedPropertyId` / `UIA_SelectionItemIsSelectedPropertyId` | `selectState` |
| `UIA_ExpandCollapseExpandCollapseStatePropertyId` | `expandState` |
| `UIA_LevelPropertyId` | `level` |

> **实施后回填（本条方案被真机打回）**：只答属性**不够**。UIA 客户端侧这些
> 属性是从 Pattern 取的 —— provider 不提供 `ExpandCollapsePattern` /
> `SelectionItemPattern` 时，核心自己兜底成 `LeafNode` / `false`（实测三行
> 展开态全是 3、选中全是 false），PHP 侧的值再对也读不到。
> ⇒ 必须补两个 Pattern，且两者的动作方法落点不同：`Select()` 复用
> `kMsgUiaInvoke`（点行 = 选中），`Expand()/Collapse()` 走新消息
> `kMsgUiaExpand`（行 tap 是选中不是展开；且这是命令不是 toggle）。详见 DEBT §65.4。

**裁决三：Toggle/Value 的"属性"与"Pattern"共用同一份判据。**

现在 `ToggleAdapter::get_ToggleState()` 里有一段"radio 看 value 空不空、
其它看 checked/unchecked"的分支。属性面要同一份逻辑 ⇒ 抽成一个
`toggleStateOf(const UiaNode &)`。不抽的话两处会漂移，症状是
"读屏念已选中、脚本调 Pattern 说未选中"——没人会同时验两条。

### 3.3 消费者：树的行

`TreeView` 的行现在是 `Button::make($row->label)`，语义上是 `button`。
改成：

```php
$label->semantics(SemanticsRole::TreeItem, $row->label);
$label->semanticsState(
    selected: $row->id === $selected,
    expanded: $row->hasChildren ? $row->expanded : null,
    level: $row->depth + 1,
);
```

**裁决四：新增 `SemanticsRole::TreeItem`，不借用 `button`。**

`SemanticsRole` 的纪律是"每个角色各有一个真实声明控件"——这里正是。
借用的代价是读屏把树念成"按钮列表"，层级与展开全部丢失，而 §64 刚给
树做完 `←` 折叠/跳父行 —— 按键能折叠，读屏不知道折叠了什么，能力等于
只做了一半。

**裁决五：换角色会连带掐掉 `InvokePattern`，两个条件必须同时改。**

`simplePattern()` 按 ControlType 分派（"控件是什么决定能问什么"），
`InvokePattern` 只给 `UIA_ButtonControlTypeId`。行的角色一变，控制类型
跟着变 `UIA_TreeItemControlTypeId` ⇒ **读屏双击行不再等于点击**。
`TreeItem` 在 UIA 里本来就该支持 Invoke，把条件加上即可；漏掉的话
现象是"鼠标点得动、读屏点不动"，且不报错。

## 4. 为什么不选其它候选

| 候选 | 判定 |
|---|---|
| 两段式挂起（父行滚出视口时 `←`） | 窄：一个消费者、一个键；且是新的跨帧机制，需独立裁决 |
| `MenuBar` Alt | 窄；Alt 单独键走 `WM_SYSKEYDOWN`，与 Alt+Tab 配对要单独裁决 |
| `Slider` 拖动放大拇指 | 纯视觉；"拖动期几何归谁"（§48.7）没裁决 |
| 给 `apps/users` 加整页金样 | 与 §63 同族但收益递减：users 在 smoke 里已有 30+ 条行为判据；像素金样抓不到语义错误 |
| `probe-uia.py` 可见性判据错容器 | 单点修复，顺手做（§6） |

## 5. 验证计划

| 层 | 判据 |
|---|---|
| PHP 单测 | `SemanticsStateTest`：三态编码不混淆、焦点/可聚焦分离、树行三角色齐全 |
| C++ | AOT 重建后真机；探针读**属性**（不是结构推断） |
| 变异 | 三态退化成 bool ⇒ 红；`focusable` 不看 `byId` ⇒ 红；行不换角色 ⇒ 红 |
| 回归 | feature / lint / gate 18 项；整页金样应**零变化**（语义不改绘制） |

## 6. 边界（如实记）

- ~~**`Table` 的选中行仍看不见**：行是 `RenderTable` 自绘的，语义树里没有行
  节点。要补得先裁决"语义树要不要收渲染层的行"，是新结构问题，不在本轮。~~
  **2026-11-07 更正（§66 勘察）**：这条边界的前提是错的。
  `TableState::buildBody()` 每行都造 `SizedBox->key($i)` 包 `Row` → `cellShell`；
  `RenderTable::paint()` 只画 chrome / 焦点框 / 滚动条，行区由
  `paintChildAt($canvas, $body)` 委托子盒绘制。行有 Element、有 RenderBox、
  有几何，`SemanticsBuilder` 走 Element 树本来就收得到 ⇒ **不存在**
  「语义树收不收渲染层的行」的结构裁决。§66 只是在行容器链末尾接了
  `SemanticsRole::Row` + `semanticsState(selected: ...)`，并在 C++ 侧补了
  `UIA_SelectionItem_ElementSelectedEventId`（选中不改节点数，原结构事件不报）。
- **`level` 只对树行有意义**：别的角色给 `0`（不提供 `Level` 属性）。
- **探针侧 `probe-uia.py --click` 的可见性判据错容器**（用客户区代替元素的
  滚动视口）：本轮顺手修，因为新探针要靠它点树行。
- **§66 的 T2 真机未跑成**（2026-11-07）：`showcase.exe` 启动即
  `0xC0000409`，AOT 启动期 Fatal `PageHostState must be registered before
  PageHost`（外部工具链的类注册顺序；回退到 HEAD 全量重建同样复现，
  详见 `DEBT` §66.4）。因此 `ElementSelected` 事件与表格行 ⑨–⑫ 四条 UIA
  判据**都还没有真机证据**。上面「§66 补了事件」只代表代码已落地，
  不代表验过；恢复 T2 后第一件事是补事件判据，见 `DEBT` §66.6。
