# 语义与无障碍

> 本篇解决：怎样把界面暴露成一棵可查询的语义树，并说明 Windows UIA provider 如何应答读屏与自动化客户端、事件如何回灌、怎样验证。

语义树回答"按角色找哪个控件、它现在是什么状态、怎么激活它"。它不是给布局用的，也不是每帧都建的东西——没有消费者时整棵树是空的，成本只是一串空判断。

## 语义树怎么建

`SemanticsBuilder::build($root, $focused, $focusable)` 是唯一构建入口，且**没人调就不建**：只在 UIA 请求 / 语义操作时按需构建，不进每帧。它有三个关键点：

- **走 Element 树，不走渲染树**：一个控件可能产出多个盒（装饰 / 手势包装），走渲染树会重复命中同一个控件；走 Element 树则"声明了角色的控件恰好贡献一个节点"。
- **几何向渲染树要**：用 `Element::effectiveBox()`（自己的盒，没有就往下找第一个有盒的子孙），再用 `RenderBox::absoluteOrigin()` 取绝对原点。`renderObject()` 对 `TextField` / `ScrollView` 这类组合控件是 `null`，几何会恒为 0、UIA 侧 `BoundingRectangle` 变 `Rect.Empty`（读屏无法高亮）。
- **加性**：控件不声明角色就不建节点；根节点为 `null` 时返回空树。可聚焦表由框架（`FocusManager`）喂入，不由控件自己声明，否则会和 `skipFocus()` / `disabled()` 打架。

树是**扁平节点数组 + int 索引**（`-1` = 没找到）：节点全是标量字段，父子关系用 `parent` 索引表达，索引可安全放进数组、可作 Key、可跨 AOT 边界传递。节点按**前序**排列（父在子前），所以命中测试取"包含点的最深节点"而不是第一个。查询都是线性扫——树的规模是"有角色的控件数"，不是 Element 数。

`enabled` 取"控件声明"与"运行期 `disabled()` 子树状态"的与：任一为假都报 `IsEnabled=false`，否则客户端会在 Invoke 前放行、然后 provider 收不到焦点。`focused` 回答"现在在不在里面"，`focusable` 回答"能不能 Tab 进去"，两者正交；禁用的控件 report 成不可聚焦。

`descend()` 用 `childCount()` / `childAt()` 索引递归，不递闭包（AOT 下少一层不可见调用）；`disabled()` 是子树级的，禁用容器里的按钮语义上也不可用，读屏必须念出来。`key` 取控件的字符串身份，没有 Key 就是空串——但选中事件、属性变更事件都靠 `key` 在同一节点上认人，靠滚动列表稳定身份的控件记得给 Key。

## 角色与状态

`SemanticsRole` 是 string backed，`value` 就是序列化形状（直接进 devtools 快照），不含平台编号，改 `value` 不改 ABI。加角色的判据是：人能指着说"这是一个 X"，且它承载内容、交互、集合、状态或壳之一。

- 有声明者的角色：`button`（`Button` 及 `NavButton` / `Menu`）、`textfield`、`scroll`、`table`、`tree`、`treeitem`、`row`、`dialog`、`checkbox`、`radio`、`switch`、`progress`、`slider`。
- `Text` / `Icon` / `Image` **不给角色**：Drift 没有 Flutter 式语义合并，叶子嵌在交互控件内部，给了会让"按名字点按钮"变成选中里面的文字。要文本可被找到，用按钮的 `semName`。
- `Padding` / `Row` / `Stack` / `Card` 等纯布局、装饰、动画包装一律不给角色——它们是结构不是语义。

一个控件只声明**一个**角色。例如 `Table` 声明 `table` 而不是 `scroll`（滚动能力走 `Scrollable` 接口，与语义无关）；内部 `LazyColumn` 是子节点，自己贡献 `scroll` 节点，两者不抢。`TreeView` 同理：树本身声明 `tree`，滚动由内部的 `LazyColumn` 承担。

行节点有两条不对称的规矩，别照抄：`TreeItem` 从 `Button` 借点击与焦点，但语义上不是按钮——换成行角色会连带掐掉 `InvokePattern`（按 ControlType 分派），改角色时两处条件必须同时改；`DataItem` / `Row` 不一样，`SelectionItemPattern` 按 `selectState != 0` 分派且与 ControlType 无关，`Select()` 复用 Invoke 消息合成点击，所以换角色不要求额外 Pattern。行角色只在"可选中且整行选择"的表里声明，纯展示表 / 格级选择不声明。

`SemanticsNode` 的状态字段用**三态**表达"有没有这个概念"，不是合成一个 bool：

- `selectState` / `expandState`：`0` = 无此概念 / `1` = 未选或折叠 / `2` = 已选或展开。若把 `0` 与 `1` 混成一个 `false`，每个按钮都会对客户端声称"未选中"，UIA 侧就得给所有节点挂 `IsSelected`。
- `scrollAxis`：`0` = 非滚动容器（不给 `IScrollProvider`）/ `1` 水平 / `2` 垂直。与 `selectState` 同构的三态：`0` 与"滚动容器但内容装得下"必须分开——后者有概念但 `Scrollable=false`。配套 `scrollOffset` / `scrollMax` / `scrollViewportW/H` 从容器 State 的 `ScrollMetrics` 现读，只表达主轴。
- `rangeMin` / `rangeMax`：只对 `slider` / `progress` 有意义，供 UIA 的 `IRangeValueProvider` 用。
- `hint` / `automationId` / `acceleratorKey`：静态描述，空串表示"不答"，不是状态。
- `level`：1-based 业务深度，`0` = 未声明，不给 UIA 的 `Level` 属性。

角色必须**赋值**给字段，不要提供同名虚方法让子类覆写：tpc 的去虚化会静默忽略覆写，症状是角色全为空且不报错。

## Windows UIA provider

组合根把 `Application::setSemanticsSink()` 接到 `WindowHost::pushSemantics()`；节点扁平数组经 `drift_win_uia_push()` 推进窗口的 `WindowSlot::uiaNodes`，C++ 侧的 `CProvider`（`IRawElementProviderFragmentRoot`）与 `FragmentAdapter` 据此应答。

**树导航**：`uiaFirstChild` / `uiaLastChild` / `uiaNextSibling` / `uiaPrevSibling` 等 helper 直接从扁平前序数组 + `parent` 索引算父子兄弟，`GetParent` 只走一步不绕圈。SDK 的接口是扁平化的：主类只继承 `FragmentRoot`，`Simple` / `Fragment` 走内嵌对象，避免多接口继承的 `IUnknown` 歧义。

**Pattern** 按角色 / 状态分派（tear-off）：`InvokeAdapter`、`SelectionItemAdapter`、`ExpandCollapseAdapter`、`ValueAdapter`、`RangeAdapter`、`ScrollAdapter`。属性变更要看 Pattern 而不仅是属性——例如只读 `CurrentIsSelected` 而不给 `SelectionItemPattern`，真机读到的全是 `false`。换角色也可能连带掐掉 Pattern（`TreeItem` 换了控制类型仍要走 Invoke），两处条件必须同时核对。

`ScrollAdapter`（`IScrollProvider`）是单轴语义到 UIA 两轴视图的桥：`scrollAxis` 决定主轴，次轴统一报"不可滚"。百分比 / ViewSize 的公式集中在 `uiaScrollPercent` / `uiaScrollViewSize` 一处，属性回答与事件 diff 共用——两处各写一份会漂移。`Scroll()` 把方向/步进折成目标百分比，与 `SetScrollPercent` 共用一条写回路径。

**事件**：`driftUiaApply()` 在覆盖节点数组前先抓旧快照，再 diff 出：节点数变了发 `ChildrenInvalidated`；焦点变了发 `AutomationFocusChanged`；选中态从非 `2` 变 `2` 发 `ElementSelected`；`name` / `value` / `enabled` / `focusable` 变了发对应属性变更事件，文本控件还要补 `TextChanged`；滚动容器的偏移变了发 `Scroll{Horizontal,Vertical}ScrollPercent`（UIA 没有独立的"滚动位置事件"，就是这两个属性变更事件）。按 `key` 认同一节点，结构变化交给 `ChildrenInvalidated`，不重复比较。读屏客户端订阅的是事件，不是快照。

## 客户端可见性与动作回灌

读屏 / 自动化（`InvokePattern`、`Select()`、`SetScrollPercent`、语音控制）触发动作时，**provider 只投一条窗口消息**（`kMsgUiaInvoke` / `kMsgUiaExpand` / `kMsgUiaSetValue` / `kMsgUiaScroll`），不进 PHP：COM 的 RPC 线程重入 PHP 会让进程静默消失；即便移到窗口线程派发里跑，也可能出现"命令执行了、界面不动"。消息进 `EventLoop` 后，紧接着就是 `FrameClock::advance()`，激活引起的重建同一轮就落盘。

动作统一在 `Application::semanticsTarget($index)` 寻址：`index` 是推给平台那份快照的前序下标，越界 / 零面积 / 未命中返回 `null`，壳据此回 `UIA_E_ELEMENTNOTAVAILABLE`，不假装成功。Invoke 最终是在节点中心派发一次**合成 tap**，把"点哪个控件"交回既有输入路由——读屏点与鼠标点走同一条路。`setSemanticsScroll()` 沿命中链找实现 `ScrollMetrics` 的容器 State，把百分比换算成偏移后 `scrollTo()`；要滚的轴不是主轴（或内容装得下）⇒ 返回 `false`，壳回 `UIA_E_NOTSUPPORTED`。

语义推送分两档：结构或焦点变了走 `full`、立即推（增量表达不了"多了一个节点"，焦点延迟会让人以为键盘失灵）；动画中走 `coalesce`、攒够上限才推一次。快照下标在两次推送之间可能指到别的节点（与任何 UI 自动化一样的竞态），结构变化会立刻整棵重推，窗口很短。

## 测试与诊断

- `SemanticsTree::toArrays()` 是 devtools 快照的语义段；`text()` 是人读转储。加语义字段只改 `SemanticsNode` 与 `toArrays()` 两处。
- `toArrays()` 里的三态字段各自独立成键，不要合成一个 bool——C++ 侧据此决定给不给对应 Pattern。
- `SemanticsTree::hitTest()` 与 `RenderBox::hitTest()` 同口径（最深、跳过零面积），检查器据此定位"光标下那个控件"。
- 探针脚本按**语义名**定位并点击真机窗口，而不是截图量像素：名字是应用自己声明的，改布局不会失效；矩形来自 `get_BoundingRectangle`；找不到就响亮失败。语义动作事件也有专用探针。
- 语义树规模是"有角色的控件数"，句柄 / 索引都是标量，可以安全地做断言与快照对比；判断状态一律读三态字段，不要拿 `name` / `value` 的字符串去分支（措辞会改、拼错也不报错）。

诊断顺序：

1. 先看有没有消费者：没接 `setSemanticsSink()` 时树根本不建，探针必然两手空空。
2. 接了却读不到节点：查控件是否声明了角色、是否给了 `width/height` 撑出有效几何（`BoundingRectangle` 为 `Rect.Empty` 是几何恒 0 的典型症状）。
3. 节点在但读屏点不动：查 Pattern 是否按角色 / 状态分派了，`enabled` 是否为真，Invoke 消息是否进了输入队列。
4. 值改了但客户端没反应：查属性变更事件是否按 `key` 在同一节点上发出；文本控件要同时有 `ValueValue` 与 `TextChanged` 两个事件。

## 相关

- [自定义控件](./custom-widgets.md)
- [平台能力端口](./platform-ports.md)
- [诊断与错误码](../reference/diagnostics.md)
- [测试](./testing.md)
