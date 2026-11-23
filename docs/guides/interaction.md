# 交互：命中、手势、焦点与浮层

> 本篇解决：一次指针事件怎样从命中走到四相位与手势仲裁，键盘按什么顺序分派，浮层与语义事件怎样接回同一条链。

## 命中测试一次、三方消费

`RenderBox::hitTest(float $x, float $y): ?RenderBox` 返回最深命中盒：越界返回 `null`；子节点**逆序**问（后画的在上，与 `paint()` 顺序相反）；子都不中时返回自己——这就是"容器吞掉事件、禁用 ≠ 穿透"的基础。`Offstage` 跳过 layout、尺寸为 null，这里自然返回 null，不需要特判。

输入路由、语义、光标形状只走这一次树，三方各取所需，不要各自再实现一套遍历。命中拿到的盒用 `RenderBox::absoluteOrigin()` 换算绝对坐标；沿 Element 链自己累加会重复。

`disabled()` 是四处消费的一个声明：命中测试返回自己（不是 null）、焦点链不收进 Tab 序也不进可聚焦表、语义 `enabled = false`、绘制叠 `DISABLED_ALPHA`。它与 `skipFocus()` 不同：后者只挡 Tab、仍然点得动（导航意图），前者连点也取消（业务状态）。

## 按下、移动、抬起、离开四相位

`PointerPhase` 只有四个值，`PointerEvent` 提供 `down()` / `move()` / `up()` / `leave()` 四个工厂和 `isDown()` / `isMove()` / `isUp()` / `isLeave()`：

- `Down`：手势开始，激活就发生在这一相位（按钮"按下即激活"）；点击链也只数这一相位。
- `Move`：更新悬停、拖动与手势竞技场，不推进点击次数。
- `Up`：收口普通手势；点击语义在 Down 那一刻已经定了。
- `Leave`：离开客户区，不带修饰键，消费方只看"离开了"，用来收口悬停和拖到一半的手势。

窗口失活是额外的收口信号：拖到一半切走时 `up` 和 `leave` 都不会来，`WindowDeactivatedEvent` 会 `cancelActiveGestures()` 并重置点击链，但**不关浮层**——失焦不等于放弃。

点击链由 `TapTracker` 记"这一下是第几次点击"，它只有状态没有时钟，时间由 `Application::dispatchInput()` 的第二个参数注入。`PointerEvent::$clickCount` 未标注恒为 1，`isDoubleClick()` 判 `=== 2`。间隔、位移、同按键、修饰键全同这四条规则留在 PHP 侧，不搬进平台壳，否则没有真窗口的测试再也覆盖不到。

## 手势竞技场

`GestureArena`（`src/Input/`）仲裁多个手势竞争同一指针，`GestureRecognizer` 各自申报意向。**Tap 必须居首**：指针 down 先声明 Tap 意向，拖动、长按后到再仲裁；反过来拖到第二步就会断。合成鼠标移动必须真挪物理光标，只投递消息不挪光标会让 `TrackMouseEvent(TME_LEAVE)` 立刻回 `Leave`。

`Esc` 在键盘链之前拦：浮层打开时先关可关闭的顶层，拖动中取消拖动，竞技场未裁决时丢弃这次手势意图并消费该键。

## 键盘五环优先级

`InputRouter::dispatch()` 对 `KeyEvent` 按固定顺序过环，谁先认领谁赢：

| 环 | 内容 | 说明 |
|---|---|---|
| ⓪ | 编辑命令 | `Ctrl+C/X/V` 等，要平台剪贴板，只有 App 层拿得到；四条门控任一不满足整族回落 |
| ① | 焦点链 | `FocusManager::dispatchKey()`：控件的 `keyboard()` 声明者 → `EditableText::handleKey()` |
| ② | 加速键 | `Application::accelerators()->resolve($chord)`，命中后 `dispatch()` 命令 |
| ③ | Enter / Space 激活 | `activateFocused()`，排在加速键之后，应用绑 Enter 可覆盖 |
| ④ | 上下文菜单键 | `Shift+F10` / 菜单键 `Apps`，与右键同一个 `openContextMenu()` |

焦点控件认不得就返回 false，自然落到下一环；反过来靠逐键白名单必然漏键。`keyboard()` 的回调返回 `KeyResult` 三态：`Handled` 吃掉、`Owned` 不吃但键仍归它、`Declined` 交回框架默认语义。只想认领一两个键的控件用 `Declined` 把其余键还给框架——方向键仍能走几何导航。

方向键在没人认领时是几何导航：把焦点交给该方向上几何最近的可聚焦节点，候选来自可聚焦表而非 Tab 序。`PageUp` / `PageDown` / `Home` / `End` 归滚动容器，接收者由 `ScrollMetrics` 的主轴与视口读数定，焦点为空时取视口面积最大的滚动容器，不猜树序第一个。

## 焦点链与 roving tabindex

`FocusManager` 每帧在 `Application::frame()` 末段 `collect()` 重建焦点链，保留仍存在的焦点，幂等。控件用 `focusOrder(int)` / `skipFocus()` / `autoFocus()` 声明位置，输入控件在构造器里 `markFocusable()`。浮层是第二棵树，焦点陷阱生效时只在陷阱根内收集并让 Tab 在链内循环。

复合控件用 **roving tabindex**：整块只留"活动格"一个 Tab 站，其余 `skipFocus()`。日历只让活动日进 Tab、时刻表每列一个、树只让活动行一个。规则是"占站 ⇔ 真按得动"——没回调的格子连入口都不占。`skipFocus()` 的格子方向键仍够得着，因为几何导航读可聚焦表不读 Tab 序。方向键只移焦点不激活，选中仍需 Enter/Space；选中是受控值，得由应用改。

打开菜单时焦点直接进面板首项（`FocusManager::firstFocusable()`），因为菜单项是 `Button`、`↑↓/Enter` 全走焦点；`Select` 是 combobox 模型，高亮留在触发框，不走这条。

## 右键上下文菜单与浮层栈

`Widget::contextMenu(ContextMenu)` 挂在子树上，指针右键沿命中链向上找最近的声明者；键盘第 ④ 环从焦点元素出发沿父链找，二者终点都是 `InputRouter::openContextMenu()`。键盘没有指针位置，锚点退化为元素盒。

浮层是栈（`OverlayStack` / `OverlayEntry`）。每条持一份 `OverlayPolicy`，用**具名预设**声明形态，而不是逐个布尔赋值——半套状态（"拦层外但点外不关"）没有合法用途：

- `popup()`：菜单、右键菜单、控件下拉面板。拦层外、无遮罩、点外与 Esc 都关、不困焦点、关掉回锚。三者行为完全一致，故共用一个预设。
- `tooltip()`：不拦、不关、不参与命中。最后一条是硬要求——提示一出现就命中自己 ⇒ 锚点悬停丢失 ⇒ 隐藏 ⇒ 再命中锚点，无限闪。
- `snackbar()`：与提示同，但参与命中（动作按钮要能点）。
- `modal(bool $dismissible)`：盖满 + 遮罩 + 困焦点；点遮罩与 Esc 跟 `dismissible` 走，应用显式关不受它限制。

七个开关彼此正交（拦层外 / 遮罩 / 点外关 / Esc关 / 困焦点 / 参与命中 / 回锚）。`dismissesOnTapOutside()` 只在 `blocksOutsidePointer()` 为真时有意义：穿透的层永远轮不到关闭那一支。`restoreFocus` 由 `restoresFocusOnClose()` 决定要不要在压栈时快照——提示与通知不抢焦点，快照了反而会在关闭时把焦点从用户手里拽走。落位（`prefer` / `atX` / `maxWidth` / `inset`）不在策略里，那是几何。

菜单在**命令分发之后**才关，因为 handler 可能自己开另一个面板；模态不走这条，确认对话框在命令执行时必须还在。

## 语义事件

控件用 `Widget::semantics(Role, name, value, enabled)` 声明语义，这是字段赋值不是虚方法覆写；不声明就不进语义树。`SemanticsBuilder` 遍历树收成语义树，`Application::pumpSemantics()` 在帧末把扁平快照推给 UIA sink，没有 sink 就不建、零开销。`Range` 类控件另用 `semanticsRange(float, float)`，与 `semantics()` 正交。

读屏的"点一下"不是另一条路：`SemanticsInvokeEvent` 进输入队列后，`Application` 在节点中心派发一次合成 tap（必须 `move → down → up` 三件），交回既有输入路由，与鼠标点同路。`SemanticsSetValueEvent` 走 `Application::setSemanticsValue()`：节点几何中心 → `hitTest` → 沿 Element 父链找 `SemanticsValueEditable` → 调控件自己的回写（`onChanged` / `emit`）。`SemanticsExpandEvent` 对应展开折叠。这些事件都带载荷，与主题、失活事件同队同轮循环；壳里只入队，不在 COM 回调中跑 PHP。

## 相关

- [用 Widget 组合界面](./widgets.md)
- [命令与动作](./commands.md)
- [语义与无障碍](./semantics.md)
- [状态与数据](./state-and-data.md)
