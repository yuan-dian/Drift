<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 控件在**语义树**里的角色（§9 无障碍 / M5）。
 *
 * 语义树是给"按角色找控件"的人（检查器 / 自动化 / 将来的朗读器）用的，不是给
 * 布局用的。它与 `CursorShape` 的差别：那个回答"光标长什么样"，这个回答
 * "这是什么控件"。
 *
 * ★★ **赋值，不是覆写** —— 角色一律由控件在**自己的构造/设值处**给
 *   `Widget::semantics()` 赋值（字段），**绝不**提供 `semantics()` 形态的虚方法
 *   （§9 P3 陷阱：tpc 有全程序去虚化缺陷，"基类给默认实现 + 子类覆写"会被
 *   静默忽略 ⇒ 症状是"所有节点角色为空"且编译期不报错）。
 *
 * ★ **string backed**：`value` 就是序列化形状（`SemanticsTree::toArrays()` 直接
 *   进 devtools 快照的语义段）。**没有桥 ABI 语义** —— 与 `CursorShape` 不同，
 *   这里不含任何平台编号，改 value 不改 ABI。
 *
 * ★★ **加角色的判据**（一次定死，避免"想到哪个加哪个"）：
 *   给角色 = 人能指着说"**这是一个 X**"，**且**它承载下列五类之一：
 *     内容（text/icon/image）· 交互（button/textfield/…）· 集合（list/tree/table）
 *     · 状态（progress）· 壳（appbar/statusbar/dock）
 *   - **纯布局 / 装饰 / 动画包装**（`SizedBox`/`Padding`/`Align`/`Row`/`Column`/
 *     `Stack`/`Card`/`Opacity`/`Spacer`/`Expanded`/`Divider`）**一律不给** ——
 *     它们是**结构**不是语义，给了只会让"按角色找控件"被淹没。
 *   - **叶子内容**（`Text`/`Icon`/`Image`）也**不给**：Drift2 没有 Flutter 式
 *     语义合并，`hitTest()` 取"最深"节点 ⇒ 叶子普遍嵌在交互控件内部，一旦给
 *     `Text` 角色，检查器把光标移到按钮上会选中里面的文字而不是按钮。需要
 *     "文本可被找到"时用 `name`（按钮的 `semName` = label）表达。
 *   - **没有声明者的角色不进这里**（未被消费的分支等价于未实现）—— 当前每个角色
 *     各有一个真实声明控件：`Button` / `TextField` / `ScrollView` / `Modal` /
 *     `Checkbox` / `Radio` / `Switch` / `Progress`。
 *
 * @api
 */
enum SemanticsRole: string
{
    /** 可点击的交互控件（`Button` 及其子孙 `NavButton`/`Menu`/`MenuItem`） */
    case Button = 'button';

    /** 可编辑文本（`TextField`）—— name 取它的 label 或占位符 */
    case TextField = 'textfield';

    /** 可滚动视口（`ScrollView` / `LazyColumn`） */
    case Scroll = 'scroll';

    /**
     * 表格（`Table`，2026-10-26）—— name 取表名。
     *
     * ★ 一个控件只声明**一个**角色（`Widget::semantics()` 只存一个 `semRole`），
     *   所以 `Table` 声明的是本角色而不是 `Scroll`：滚轮路由走的是
     *   `Scrollable` 接口（State 层），与语义树无关，两者不会打架。
     */
    case Table = 'table';

    /**
     * 层级数据树（`TreeView`，2026-10-29）—— name 取树名（`TreeView::name()`）。
     *
     * ★ 与 `Table` 同理：一个控件只声明**一个**角色。树的"可滚动"由内部
     *   `LazyColumn` 承担（那个也是 `Scroll`），但那是**子节点**的角色，
     *   不是本节点的 —— 检查器按 `tree` 找树、按 `scroll` 找滚动区，两者不抢。
     */
    case Tree = 'tree';

    /**
     * 树里的一行（`TreeView`，2026-11-06）—— name 取行标签。
     *
     * ★ 行原本是 `Button`（借用它的点击/焦点），语义上却**不是**按钮：读屏会把
     *   整棵树念成"一堆按钮"，层级与展开态全丢。与 §64 的 `←` 折叠是一体两面 ——
     *   按键能折叠，读屏不知道折叠了什么，能力只做了一半。
     * ★ 换角色会连带把 `InvokePattern` 掐掉（`drift_win32.cc` 的 `simplePattern()`
     *   按 ControlType 分派）⇒ "鼠标点得动、读屏点不动"，两处条件必须同时改。
     */
    case TreeItem = 'treeitem';

    /**
     * 表格里的一行（`Table`，2026-11-07）—— name 取「行 N」（视口内绝对行序，1-based）。
     *
     * ★ 声明者是 `Table` 的**行容器**（`SizedBox`）。这里破例不是放宽
     *   「纯布局一律不给角色」：行是集合族的节点，读屏必须逐行问 `IsSelected`，
     *   而 `Table` 自己只有一个节点，装不下"哪几行被选中"。别把本角色用到通用布局盒。
     * ★ 与 `TreeItem` **不同**：换成 `DataItem` 不会掐掉 Pattern ——
     *   `SelectionItemPattern` 按 `selectState != 0` 分派（与 ControlType 无关），
     *   且 `Select()` 内部 Post `kMsgUiaInvoke` 走 `invokeSemanticsNode()` 合成点击，
     *   也不要求节点有 `InvokePattern`。§65 那条「两处条件同时改」别照抄。
     * ★ 只在**可选中且整行选择**的表里声明；纯展示表 / 格级选择不声明
     *   （`selected: false` 是 1 不是 0 ⇒ 会挂上 Pattern = "能选中却点不动"）。
     */
    case Row = 'row';

    /** 模态对话框（`Modal`）—— name 取标题 */
    case Dialog = 'dialog';

    /** 复选框（`Checkbox`）—— value = `checked` / `unchecked` */
    case Checkbox = 'checkbox';

    /** 单选钮（`Radio`）—— value = 选中时的自己的值，未选中为空 */
    case Radio = 'radio';

    /** 开关（`Switch`）—— value = `checked` / `unchecked` */
    case Switch = 'switch';

    /** 进度（`Progress`）—— value = 「37%」这种百分读数 */
    case Progress = 'progress';

    /** 连续取值（`Slider`）—— value = 「42」这种读数（W8-2） */
    case Slider = 'slider';
}
