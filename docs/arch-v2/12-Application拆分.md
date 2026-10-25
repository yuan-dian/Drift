# 12 · Application 拆分（S1）

> 状态：拆解中 · 2026-10-24 · 第一~八步已落地（收接口 + 五片：TextEditingBridge / ScrollInputRouter / InputRouter / OverlayLayout / OverlayManager）
> 上游：ARCH-REVIEW-2026-10-24 §2.2（唯一的架构性缺陷）/ §3.2 I1+I2 / §7 第一步
> 护栏：smoke 1396 条 + lint 19 条 + docref 3 条 + api-surface diff
> 回答三个问题：**39 处 instanceof 的真实版图是什么？五片怎么切？先收接口还是先搬家？**

---

## 0. 一句话

**先收接口、再拆最小的片、最后拆中枢** —— instanceof 是"知道具体类"的知识泄漏，
接口是接缝；搬家沿着接缝走，每一步都有 smoke 兜底。

---

## 1. 实测纠正（ARCH-REVIEW §2.2 的两个数字错接）

| ARCH-REVIEW 原文 | 实测（2026-10-24） | 纠正 |
|---|---|---|
| "instanceof 39 处" | Application 的 instanceof **总数 = 39** ✓ | 口径没错 |
| "instanceof TextFieldState 39 处意味着 Application 知道 TextField 的内部状态类" | TextFieldState 全仓 **5 处**（Application 仅 2、FocusManager 1、ComboBox 1、注释 1） | **两个数字错接**：39 是总数，TextFieldState 只占 2 |

Application 的 39 处真实版图（按职责域归）：

```
StatefulElement 8 ｜ RenderScrollView 5 ｜ TextFieldState 2 ｜ Scrollable 3（已是接口，合规）
RenderTextField 2 ｜ RenderPopupPanel 2 ｜ ScrollEvent 2 ｜ KeyEvent 2
其余各 1：PointerEvent / Modal / Menu / Segment / Button / ToggleBase /
         WindowDeactivatedEvent / ThemeChangedEvent / CursorShape / Rect
```

**这个纠正改变拆法**：不是"抽一个 TextEditingBridge 全收 39 处"，
而是"按职责域归位，每个域收自己那几种 instanceof"。

---

## 2. 四片设计

| 片 | 收什么（Application 的 131 个方法里） | 约行数 | instanceof 收编 |
|---|---|---|---|
| **`TextEditingBridge`** | `editingText()` / `textSelectBox()` / `beginTextSelection()` / `extendTextSelection()` / `dispatchEditCommand()` / `imeCaretRect()` + `$textSelect` | ~250 | TextFieldState 2 / RenderTextField 2 |
| **`OverlayHost`** | `showMenu` / `closeMenu` / `showModal` / `closeModal` / `placeOverlays` / `placeEntry` / `anchorRect` / `originFor` / `focusTrapRoots` / `topModalEntry` / `syncPanelSignatures` / snackbar 全家（`showSnackbar`…`presentSnackbar`） | ~800 | RenderPopupPanel 2 / Rect / Modal |
| **`InputRouter`** | `dispatchInput()` 184 行 + hover/tooltip（`onHoverHit` / `showTooltip` / `hideTooltip` / `tooltipOwner`）+ `cursorFor` / `scrollThumbAt` / `hitsModalPanel` + 焦点环（`paintFocusRing` / `focusRingTarget` / `revealFocused`） | ~700 | StatefulElement 8 / RenderScrollView 5 / ScrollEvent / KeyEvent / PointerEvent / Modal / Menu / Segment / Button / ToggleBase / WindowDeactivated / CursorShape |
| **`FrameEngine`** | `frame()` 285 行（九段）+ `wantsFrame()` + `panelsLagBehind()` + `pumpTimers()` + `after()` / `cancelAfter()` + `pumpSemantics()` + 帧预算观察（`noteFrameCost`…`stageBudgetText`） | ~450 | 无（中枢，调上面三片） |
| **Application 保留** | 生命周期 / `buildRoot()` / `title()` / `theme()` / `router()` / 装配三片 + 测试与 devtools 观察面（十余处 `@api`） | ~1200 | 0（目标） |

★ 与 `docs/architecture/` 分册对齐（M3）：拆完四片后，每片一个分册有现成落点
（`03-布局与渲染盒` 之外的输入/浮层/帧面）。

---

## 3. 执行顺序（为什么不是"纯搬家"）

ARCH-REVIEW 说"纯搬家，1390 条 smoke 当护栏"。**评估后调整为三阶段**：

1. **先收接口**（每处可独立验证，smoke 随时兜底）—— 搬家前先把 39 处
   instanceof 收敛成接口形态。接口是接缝，搬家沿着接缝走。
2. **再拆最小片**（TextEditingBridge ~250 行，边界最清、与 frame() 零耦合）——
   用最小片验证"Application 持片、片经窄接口回调"的装配模式。
3. **最后拆中枢**（OverlayHost → InputRouter → FrameEngine，被依赖多的晚拆）。

★ **为什么不先搬家**：搬家 = 大块代码迁移，"漏改一个调用点"的错误形态
   在运行期才炸；接口先行让这类错误在**类型系统**里炸（PHP 编译期/首次调用）。
   smoke 兜底的是"行为不变"，接口兜底的是"引用完整"—— 两层护栏比一层好。

---

## 4. 第一步已完成：EditableText 接口（2026-10-24）

**改法**：新建 `src/Widget/EditableText.php`（9 方法：`isEditing` / `handleKey` /
`caretRect` / `moveCaret` / `extendSelectionTo` / `hasSelection` / `selectedText` /
`replaceSelection` / `deleteSelection`），`TextFieldState implements EditableText`。

**收敛 3 处**（Application 2 + FocusManager 1）：
- `Application::editingTextFieldState()` → `editingText(): ?EditableText`（含
  `imeCaretRect()` 的同款判据、`$textSelect` 字段、`textSelectBox()` 形参）
- `FocusManager::dispatchKey()` 末环：`$state instanceof EditableText`

**不动 1 处**（记档）：`ComboBox::findFieldState()` 认 TextFieldState —— 那是
**控件认自己 build 出来的子树里的具体控件**（ComboBox 内嵌搜索字段），
与「Grid 认 LazyColumn」同级的组合代价，不是框架核心依赖。留待
`TextArea` / `CodeEditor` 真出现时评估（届时 ComboBox 可能要支持
"搜索字段是任意 `EditableText`"）。

**验证**：smoke 1392/0（行为不变）、lint 0/0、docref 68/0（行号零漂移）、
api-surface diff = **+1 @api（EditableText）/ 0 移除**（纯增量）。

---

## 4b. 第二步已完成：States::of + focusedState（2026-10-24）

**版图**：App 层 8 处分两组：
- **A 组 3 处**（`imeCaretRect` / `editingText` / `textSelectBox`）——「焦点
  ⇒ State ⇒ EditableText」焦点穿透
- **B 组 5 处**（`tickSubtree` / `scrollThumbAt` / `scrollAt` / `revealFocused` /
  `bubble`）—— 子树 / 父链遍历中逐个穿透

**改法**（三处新知识归位，不是把 instanceof 换个写法）：
1. **新建 `src/Widget/States.php`** —— 「Element 有没有 State」的通用穿透，
   单一来源。`FocusManager` 原私有 `stateOf()` 同源，删除并改调本类。
2. **`FocusManager::focusedState(): ?State`**（public）—— 焦点穿透的唯一出口，
   与 `focused()` 并列（调用方要的是"焦点上的 State"而非 Element 本身）。
3. **`Application::nearestScrollable(?Element): ?Scrollable`**（private）——
   滚动路由三处（滚轮 / 键盘可达性 / thumb 命中）共用「最近的可滚动祖先」口径；
   要盒经 `State::element()` 回 Element 再 `findBox()`，穿透知识与盒查找不混。

**顺带消重**：`imeCaretRect()` 前 4 行与 `editingText()` 完全同构 ⇒ 改调
`editingText()`（判据单一来源，不可能一个放行另一个拦住）。

**验收**：smoke 1392/0（含变异测试：`States::of` 打挂 ⇒ smoke 立即抛异常）、
lint selftest 0/0、docref 68/0、api-surface diff = **+1 @api（States）/ 0 移除**。
App 层 `instanceof StatefulElement` **清零**（剩 Element 树自身 3 处 +
ComboBox 控件内部 1 处，均非 App 层认知）。

---

## 4c. 第三步已完成：类型化盒查询（2026-10-24）—— 含原方案修正

**实测版图**：`instanceof RenderScrollView` **7 处**（路线表写的 5 处少了控件
内部两处），分两组：
- **App 层 2 处**（`scrollThumbAt` / `revealFocused`，第 2 步刚重写过）
- **控件内部 3 处**（`ScrollViewState::ensureVisible`、
  `LazyColumnState::ensureVisible` / `viewportEstimate`）

**原方案「收成 `Scrollable` 盒侧接口」评估后不成立**，三条理由：
1. App 层要的盒侧操作各不相同（`thumbRect` / `appliedOffset` /
   `isVertical` / `viewportSize` / 绝对坐标换算）—— 全塞进 `Scrollable`
   会让**滚动语义接口背盒几何**，从 2 方法膨胀到 6+；
2. 即使加 `viewportBox(): ?RenderScrollView`，PHP 无泛型收窄 ⇒ 调用方
   仍要 `instanceof RenderScrollView` 判空 ⇒ App 层认知没消失，只是转移；
3. Widget 层接口认 Layout 具体盒类型，方向暧昧。

**修正方案：类型化盒查询**（与 `States::of()` 同构——查询知识单一来源）：
- `Element::findBoxOf(string $boxClass): ?RenderBox` —— `findBox()` 的类型化特化
- `Element::effectiveBoxOf(string $boxClass): ?RenderBox` —— `effectiveBox()` 的
  类型化特化，给「控件认自己的盒」用
- 调用方 `@var` 声明具体类型（沿用 `dragScrollThumb()` 既有模式）

**收益**：5 处调用点的 closure 样板 + instanceof 双料消除；仓内
`instanceof RenderScrollView` 7 → 2（都在 Element 内，单一来源）；App 层
对 `RenderScrollView` 的认知**清零**（步 8 的 App 层 instanceof  lint 的前置）。

**验收**：smoke 1392/0（变异测试：`findBoxOf` 打挂 ⇒ 2 FAIL）、lint 0/0、
docref 68/0、api-surface diff = **+0 @api / +2 @internal**（两个查询方法
标 `@internal`，与 `findBox()` / `effectiveBox()` 邻居口径一致）。

---

## 4d. 第四步已完成：TextEditingBridge 最小片（2026-10-24）

**目的**：验证「Application 持片、片经窄接口回调」的装配模式 —— 用最小片
踩遍所有坑（生命周期、依赖注入时序、API 位置），后续三片照此复制。

**装配模式（本步要验证的核心）**：
- `TextEditingHost` 接口只两个口子：`focus(): ?FocusManager` +
  `clipboard(): ?Clipboard` —— **片经 getter 不用值**（焦点 attach 时才建、
  剪贴板由组合根挂进来，就绪时序都不确定）
- `Application implements TextEditingHost` + 构造期 `new TextEditingBridge($this)`
  （host = 自己，构造期建无时序问题）
- **API 位置不动、实现搬走**：`Application::imeCaretRect()` 留委托壳 ——
  三个 Bootstrap（组合根）按 `$app->imeCaretRect()` 调，不能断

**搬入片的资产**：`editingText()` / `textSelectBox()` / `beginTextSelection()` /
`extendTextSelection()` / `dispatchEditCommand()` / `imeCaretRect()` + `$textSelect`
字段；顺带新增 `finishSelection()`（把 Application 里 4 处 `$this->textSelect = null`
的裸摸字段收成一处收口），`textSelectBox()` 换用第 3 步的 `findBoxOf()`。

**顺带**：`Application::focus()` 新增 public（host 契约的强制产物）——标
`@internal` 而非 `@api`：唯一消费者是片，不是应用扩展点（若将来组合根
要读焦点是显式升级 @api 的时刻）。

**行数**：Application 3834 → 3663（**−171**；路线表的 ~250 估计含 docblock
净转移，实际净减 171）。

**验收**：smoke 1392/0（两个变异测试：`finishSelection` 打挂 ⇒ 1 FAIL；
`dispatchEditCommand` 打挂 ⇒ 3 FAIL）、lint 0/0、docref 68/0、
api-surface diff = **+0 @api / +3 @internal**（Bridge / Host / focus()）。

---

## 4e. 第五步已完成：ScrollInputRouter + 拆片顺序调整（2026-10-24）

**动工前的评估**（本步的产物不只是代码，还有顺序决策）：原步 5 是拆
OverlayHost，实测 `$this->overlays` 在 Application 里有 ~25 处引用、散布
6 类集成点（帧循环喂树 / 焦点 collect / 输入浮层优先 / 命令后关菜单 /
受控同步 / 重绘判据）—— 先拆它要把 25 处全改窄面调用，单步改动面过大。
滚动输入族边界极干净（只经 host 的 `focus()` 一个口子），且是 InputRouter
主体拆分的前哨。**按路线表自己的原则「被依赖多的晚拆」调整顺序**：
5 = ScrollInputRouter → 6 = InputRouter → 7 = OverlayHost → 8 = FrameEngine
→ 9 = lint 规则。

**搬入片的资产**：`scrollAt()` / `scrollThumbAt()` / `dragScrollThumb()` /
`nearestScrollable()` / `revealFocused()` + `$thumbDrag` 字段。thumb 拖动
的「先于内容接管」逻辑收成 `beginThumbDrag(): bool`（true = 吞掉本次按下）
—— Application 的 down 分支从 8 行缩到 3 行。

**装配模式同第 4 步**：`ScrollInputRouter` 持 `TextEditingHost`（只要
`focus()`），构造期建。

**行数**：Application 3663 → 3487（−176）。

**验收**：smoke 1392/0（变异测试：`nearestScrollable` 打挂 ⇒ **12 FAIL** ——
滚轮 / thumb / 焦点三类测试全覆盖）、lint 0/0、docref 68/0、
api-surface diff = **+0 @api / +1 @internal**（ScrollInputRouter）。

---

## 4f. 第六步已完成：InputRouter 主体（2026-10-24）

**动工前的评估**（两处对原分析的修正）：

1. **`InputHost` 是 8 个口子而不是 7 个** —— 原分析漏了 `root()`。
   `updateDrag()` 要 hitTest ⇒ 要 root；它是公开入口（smoke 直调）、arena 的
   Drag  recognizer 回调也要调它，把 root 透传进三处调用点（含一次闭包捕获）
   比多一个 getter 口子更差。`focus()` / `root()` 同为"现读树状态"（挂载前
   为 null、重建会换），与第 4 步 `TextEditingHost` 立的口径一致。
2. **壳的边界**：`dispatchInput()` 壳内保留三件**应用侧**职责 ——
   ① `lastInputMs` / `keyboardFocusVisible` 记账（`after()` 与焦点环的基准）
   ② 失活收口（`cancelActiveGestures` 必须**先于**"未挂载"守卫 —— 窗口可能
   在拆卸途中失前台，手势清理不依赖树）③ `ThemeChangedEvent` 分发（先钩子再
   下发 context = 帧循环职责，不是输入路由）。路由主体拿到的 root/focus 由
   壳传参（已过守卫）—— 与 `dispatchPointer` 原有签名同形，router 不重复
   持守卫。

**搬入片的资产**（Application 3834 行里的 ~800 行）：`dispatch()` 的
键/指针/滚轮三分支 / `dispatchPointer()` 四相位 / `cancelActiveGestures()` /
`beginGestureArena()` / `draggableOwner()` / `pointerOwner()` /
`openContextMenu()` / `activateFocused()` / `activateFocusedWidget()` /
`hitsModalPanel()` / `dispatchToCapture()` / `cursorFor()` / 捕获三方法
（`capturePointer` / `releasePointer` / `captureNode`）/ 拖放五入口
（`drag` / `beginDrag` / `updateDrag` / `dropDrag` / `cancelDrag`）+
三字段（`$gestureArena` / `$drag` / `$captureNode`）+ `WHEEL_NOTCH_PX`。

**§2 原表的偏离（执行时按"输入路由"边界重判）**：hover/tooltip 四方法与
焦点环（`paintFocusRing` / `focusRingTarget`）**留在 Application** ——
它们由帧循环驱动（悬停延时、afterPaint 画环），不是"喂一条事件决定谁吃"的
路由职责；`revealFocused` 已随第 5 步进 ScrollInputRouter。

**装配模式**：`new InputRouter($this, $this->scrollInput, $this->textEditing)`
—— host 摸应用状态（8 口子），另两片走构造参数做片间组合（不经 host，
第 5 步口径）。**API 位置不动**：`dispatchInput` / `capturePointer` /
`releasePointer`（@api）、`captureNode` / 拖放四入口（@internal）在
Application 留壳 —— smoke ~150 处直调、三个 Bootstrap、TestApp 不断。

**行数**：Application 3487 → 2817（−670）；新增 InputRouter 857 行 +
InputHost 40 行。

**踩坑（修夹具，不给框架加兜底）**：`ThemeApplication`（smoke 夹具）构造器
没调 `parent::__construct()` ⇒ 新片 `$input` 未初始化，frame() 的捕获者
清扫第一帧就 Fatal。选择修夹具而不是在 attach() 里再抄三份 `??=`：
`NavApplication` 的注释立过"不调父构造器 ⇒ `$router` 未初始化"的规矩，
容忍老Fixture的只有 `$overlays ??=` 那一条遗留补丁 —— 容忍面每复制一次，
"子类可以不调父构造器"就多一分既成事实。

**验收**：smoke 1392/0（变异测试：① dispatchPointer 浮层优先段 scrim 分支
打挂 ⇒ **1 FAIL**（点遮罩关模态）② `activateFocusedWidget` 的命令分发打挂
⇒ **6 FAIL**（键盘/指针激活、菜单项、模态按钮、Tooltip 穿透全覆盖））、
lint 0/0、docref 68/0、api-surface diff = **+0 @api / +1 @internal**
（−3 搬入片内 / +2 host 契约口子 `dismissOverlay` `toggleWidgetPanel` /
+2 新片类 `InputHost` `InputRouter`）。

---

## 4g. 第七步已完成：OverlayLayout 前哨（2026-10-24）

**动工前的版图**（`$this->overlays` 在 Application 的 24 处引用，按归属分六类）：

| 类 | 资产 | 归属判断 |
|---|---|---|
| A 栈所有权 | 字段 205 / 构造 377 / attach 兜底 600 / closeMenu 全清 726 / overlays() getter 1027 / pushOverlay 1117 / removeOverlay 1126 / widgetPanelEntry+closeWidgetPanel 2730 | 步 8 OverlayHost 主体 |
| B 栈查询 | isMenuOpen 1039 / focusTrapRoots 1157 / topModalEntry 1187（喂 closeModal / isModalOpen / modal） | 步 8 |
| C 帧循环消费 | applyNewContext 1627 / Frame::run 喂树 1740+1862 / focus collect 1800 / tickAnimations 2210 | 步 8（末三处 1740/1862/2210 是帧循环读栈，步 9 FrameEngine 时再定窄面） |
| D 几何布局 | placeOverlays 1225 / syncPanelSignatures 1321 / panelsLagBehind 2015 / placeEntry 1361 / anchorRect 1446 / originFor 1470 / absoluteOrigin 1572 | **本步（前哨）** |
| E 时序件 | onHoverHit 758 / tooltipOwner 805 / showTooltip 830 / hideTooltip 862 / snackbar 全家 899-1017 / 四字段 tooltip* / 三字段 snack* | 步 8（与 after/cancelAfter 定时器强耦合） |
| F 入口 API | showMenu/closeMenu 702/723 / showModal/closeModal 1068/1094 / toggleWidgetPanel 2681 | 步 8 |

**为什么不一步拆 OverlayHost**（对原路线的修正，同第 5 步的逻辑）：
host 口子预估 10+（focus/root/theme/metrics/after/cancelAfter/overlays/
dismiss/showMenu/toggleWidgetPanel）⇒ 退化成"第二个 Application"；且
`InputHost` 契约的 4 个浮层口子（overlays/dismissOverlay/showMenu/
toggleWidgetPanel）+ 帧循环 6 处消费要同时改指向，单步 diff 1000+ 行，
变异测试无法定位回归点。

**为什么前哨选几何段**：`placeOverlays` 家族与输入/命令/snackbar/tooltip
**零耦合** —— 只读栈、读盒、写盒；host 只需 2 个口子（`overlays()` 读 +
`dismissOverlay()` 收窄）。且它是双重前哨：既是步 8 OverlayHost 的
"帧循环侧消费预演"（settle→清扫→定位的帧内次序整体搬走），又是步 9
FrameEngine 拆 `frame()` 时"每段一次纯策略"的样板。

**与输入侧同构**：ScrollInputRouter ← InputRouter ← Application 壳；
浮层侧 OverlayLayout ← OverlayHost ← Application 壳。路线表 9→10 步。

**两处执行细节**：
① `panelsLagBehind()`（wantsFrame 第六判据）**跟着搬** —— 它与
`syncPanelSignatures()` 是同一判据的两半（一个跑在布局前、一个跑在
判帧时），分开留 = 同一锚点比对逻辑两处实现；
② 清扫走 host 的 `dismissOverlay()`（与 `InputHost` 同形同义）——
前哨只表达"关掉这个条目"，卸载/焦点回锚策略整个住 Application。

**兼容性**：搬走的 7 个方法全是 `private`，@api 面 0 变化；`OverlayStack`
仍经 `overlays()`（@api）暴露，测试/devtools 面不动。

**装配**：`new OverlayLayout($this)`，`OverlayLayoutHost`（2 口子，
@internal）由 Application 实现并转调 `overlays()` / `dismissOverlay()`。

**执行结果**：Application 2817 → 2445 行（−372）；新增 `OverlayLayout.php`
（类，7 方法）+ `OverlayLayoutHost.php`（接口 2 口子）。三个调用点改经片：
`frame()` / `repaintForResize()` 的 `placeOverlays()`、`wantsFrame()` 的
`panelsLagBehind()`。@api 面 **0 变化**。

**踩坑（PSR-4 一符号一文件）**：先把接口与类写进同一个 `OverlayLayout.php`，
smoke 在 259 条处 Fatal（`Interface OverlayLayoutHost not found`）——
`tools/autoload.php` 是纯 PSR-4，`Drift\App\OverlayLayoutHost` 只会去
`src/App/OverlayLayoutHost.php` 找。第 6 步的 `InputHost` 本来就是独立
文件，本轮组装时图省事合在了一起。教训同第 6 步「组装后立即 php -l」：
**组装后先过一次 autoload 可见性**（至少确认每个新符号有同名文件）。

**验收**：smoke 1392/0、lint 502 文件 0/0、docref 68/0、selftest 通过；
api-surface diff = **+0 @api / +2 @internal**（新片类 `OverlayLayout` +
host 接口 `OverlayLayoutHost`；`syncPanelSignatures` 从 Application 的
@internal 列表平移过来，数量不变）。变异测试三处该红都红：
① `originFor` 的 BELOW 翻上分支打挂 ⇒ **1 FAIL**（锚点贴底不翻）
② `ABOVE_CENTER` 优先级对调 ⇒ **2 FAIL**（Tooltip 垂直落位 + 连带穿透用例）
③ `syncPanelSignatures` 整体打挂 ⇒ **11 FAIL**（Select 三态/跨树 5 条 +
ComboBox 跨树/远程 4 条 + **wantsFrame 回落**）—— 最后一条正是
「panelsLagBehind 与 syncPanelSignatures 必须同住」的活证据：seenAnchor
不更新 ⇒ 判帧恒真 ⇒ 自旋。分开留就会以更隐晦的方式红。

---

## 4h. 第八步动工前分析：OverlayManager 主体（2026-10-24）

**边界**：§4g 版图里的 A（栈所有权）+ B（栈查询）+ E（时序件）+ F（入口
API）整类搬走，约 640 行；**C 类（帧循环 5 处读栈）明确排除**——
`applyNewContext()` / `Frame::run` 喂树两处 / `focus->collect()` 的
popupRoots / `tickAnimations()` 的浮层子树都在帧循环里，等步 9 拆
FrameEngine 时定窄面（"被依赖多的晚拆"的同一原则）。

**为什么不再分刀**（评估过"时序件单独一刀"）：E 与 F 互相渗透 ——
`closeMenu()` 全清后必须复位 snackbar/tooltip 状态位并取消 snackTimer
（F 碰 E）、`pushOverlay()` 被 showTooltip / presentSnackbar /
toggleWidgetPanel 三处复用（E 碰 A）⇒ 分刀会造出两片 + 片间组合，
边界互相穿孔。且合计 ~640 行一步可扛（InputRouter 857 行有先例）。

**命名**：类 `OverlayManager`，接口 `OverlayHost`。双先例对齐 ——
`FocusManager`（App 层管焦点的片）与 `InputHost` / `TextEditingHost`
（接口 = 宿主要提供的口子集合）。不叫 OverlayHostImpl / OverlayController。

**host 6 口子**（Application 实现）：`focus()`（push 快照 / remove 回锚）/
`root()`（未挂载守卫 + menuContext）/ `theme()` + `metrics()`（menuContext）/
`after()` + `cancelAfter()`（snackbar/tooltip 定时器）。与 InputHost 的
focus/root 两口子重叠但各自独立声明（同 TextEditingHost 的重叠口径）——
不让 OverlayHost extends InputHost：那会把 dispatch/accelerators 等
4 个浮层不需要的口子带进来。

**两处结构改进**（相对 §4g 原计划）：

1. **`InputHost` 8 → 4 口子，InputRouter 改持 OverlayManager**。原计划
   "InputHost 的 4 个浮层口子改指 host"若只改 Application 侧实现，就得到
   "InputRouter → InputHost → Application → OverlayManager" 的壳中壳 ——
   focus/root/dispatch/accelerators 是应用状态（该留 InputHost），而
   overlays/dismissOverlay/showMenu/toggleWidgetPanel 是**另一片的职责**
   （该直连）。InputRouter 构造签名加第四参 `OverlayManager $overlayHost`，
   11 处 `$this->host->浮层调用` 改 `$this->overlayHost->...`（片间组合走
   构造参数，同第 5/6 步口径）。
2. **OverlayLayout 的 host 从 Application 改指 OverlayManager，删
   `OverlayLayoutHost` 接口**。布局片只需要"读栈 + 按条目关"，这正是
   OverlayManager 的两个 public 方法 ⇒ 第 7 步那层 Application 中转
   （Application 实现 OverlayLayoutHost 再转调）纯属多余。这是对第 7 步
   成果的精化，不是回退。

**兼容性破坏清单**（四项，都验过零外部影响）：

| 破坏 | 验证 |
|---|---|
| 删 `protected OverlayStack $overlays` 字段 | 全仓 grep：`$this->overlays` 仅 Application 类内 14 处，夹具/探针/示例台零直摸 |
| 删 `OverlayLayoutHost` 接口 | 唯一实现者是 Application（grep 确认） |
| 删 `Application::dismissOverlay()` / `toggleWidgetPanel()` 两个 @internal 壳 | 唯一消费者是 InputRouter（改指）；ComboBox.php 只有注释提及 |
| 删 attach() 的 `$this->overlays ??=` 兜底 | 全仓 33 个 Application 子类无不调父构造器（ThemeApplication 已于第 6 步修） |

`overlays()` getter（@api）与 13 个 @api 入口**位置不动**，内部转调
`OverlayManager`；`PANEL_MAX_WIDTH` / `TOOLTIP_DELAY_MS` 零外部引用，
随方法搬走。

**装配顺序**：textEditing → scrollInput → **overlayHost** → input（收
overlayHost）→ overlayLayout（收 overlayHost）。帧循环的 5 处读栈经
`$this->overlayHost->overlays()->all()`；`onHoverHit` 注册闭包直调
`$this->overlayHost->onHoverHit($hit)`（Application 不保留该方法）。

### 4h-落地（2026-10-24 完成）

**数字**：Application **2444 → 1919 行**（−525；比预估 −600 少，因为
15 个委托壳约 130 行留在壳上）；`OverlayManager` 714 行（新片）；
`--api` diff = **+0 @api / +5 @internal**（OverlayManager 类 + OverlayHost
接口 + onHoverHit / focusTrapRoots / dismissOverlay / toggleWidgetPanel
四口子 − OverlayLayoutHost 接口）；smoke **1396 ok / 0 FAIL**（含本步
新增 4 条，见下）、lint 503 文件 0/0、docref 68/0、selftest 过。

**执行要点**：

- 28 方法 + 7 字段 + 2 常量整块搬走（Python 脚本定位 + 14 处 `$this->`
  机械改写走 host 口子，逐条打印 diff 人工过目）；`$tooltipTimer` /
  `$snackTimer` 实为 `?int`（不是 TimerHandle），两个常量零外部引用 ⇒
  随方法降为 `private const`。
- **4 个口子在片上开 public @internal**（原来 private）：`onHoverHit`
  （宿主 attach 的 hover 回调直调）、`focusTrapRoots`（帧循环
  `focus->collect()` 直调）—— 同 `dismissOverlay` 第 6 步先例
  「片间契约口子，不是扩展点」。
- 委托壳 15 个集中在类末尾（带分组注释「API 位置不动，实现搬走」）；
  **内部调用点直调片不经壳**（帧循环 / hover 回调 / 失活收口），同第 7 步
  `frame()` 直调 `overlayLayout` 的口径。
- 顺手修了 4 处第 6 步遗留的过期文档引用（`06-帧管线.md` 里 drag 回调 /
  thumbDrag / LEAVE 的 Application 行号，随第 6/8 步搬到 InputRouter /
  ScrollInputRouter）。

**变异测试**（5 发，4 响 1 不响 → 补用例后 5/5 响）：

| # | 打挂 | smoke |
|---|---|---|
| 1 | `removeOverlay()` 焦点回锚段（`if (false && ...)`） | **2 FAIL**（焦点回锚失效） |
| 2 | `showTooltip()` 的 `hitTest = false` → `true` | **2 FAIL**（提示层吃掉点击 / 悬停目标被抢） |
| 3 | `showSnackbar()` 排队分支（`if (false && snackEntry !== null)`） | **2 FAIL**（FIFO 失效，连发只显示最后一条） |
| 4 | `menuContext()` 复用主树 context（恒走 `new BuildContext`） | 0 FAIL ⇒ **记录为覆盖缺口**：三个入口在 `root === null` 时已早退，`?? new BuildContext` 回退分支不可达，是防御性死代码 |
| 5 | `closeMenu()` 删 `tooltipShown = false` 复位 | 旧 smoke 0 FAIL ⇒ **暴露真实缺口**，补 4 条用例（含现场恢复段）后 **1 FAIL** |

变异 5 补的 4 条 smoke 用例（`smoke.php` Tooltip 段）：closeMenu 全清后
栈空 / 悬停 b 不立即弹（反例）/ 再满 500ms 弹 b 的提示 / move 回 a 恢复
现场。**「全清后相邻切换仍要等 500ms」正是 §4h「E 碰 D」立论的活证据**
—— 之前 smoke 完全没有覆盖它，变异抓到后才知道缺。

**执行期对分析的两处修正**（都在"更保守"方向）：

1. §4h 破坏清单原计划「删 `Application::dismissOverlay()` /
   `toggleWidgetPanel()` 两个 @internal 壳」—— 落地改为**保留壳转调**
   （15 个 public 壳一个不少）。理由：壳的边际成本是两行转发，而删壳让
   「@internal 也是 API」的口子出现真实断裂（ComboBox 之外的探针 / 未来
   片都可能摸）；与 5 个 @api 壳「位置不动」保持同一口径。
2. §4h「13 个 @api 委托壳」口径不准 —— 实际 public 方法是 15 个
   （5 @api + 8 无标签 + 2 @internal），全部留壳。`--api` 计数不变
   （51 = 51）即证据。

---

## 5. 剩余路线（每步的验收）

| 步 | 动作 | 验收 |
|---|---|---|
| 2 | ~~8 处 `instanceof StatefulElement` 收进 `FocusManager::focusedState()`~~（✅ 2026-10-24，见 §4b） | smoke 绿 + App 层该 instanceof 归零 |
| 3 | ~~`Scrollable` 已合规；`RenderScrollView` 5 处收成 `Scrollable`（盒侧接口）~~（✅ 2026-10-24，方案已修正，见 §4c） | 同上 |
| 4 | ~~拆 `TextEditingBridge`（最小片，验证装配模式）~~（✅ 2026-10-24，见 §4d） | smoke 绿 + Application 行数 −250 |
| 5 | ~~拆 `OverlayHost`~~ → **已调整为 `ScrollInputRouter`**（滚动输入族，2026-10-24 拆片顺序调整 + 完成，见 §4e） | 同上 |
| 6 | ~~拆 `InputRouter`（dispatchInput / dispatchPointer 主体 + 手势状态 + 光标）~~（✅ 2026-10-24，见 §4f） | 同上 |
| 7 | ~~拆 `OverlayLayout` 前哨（几何布局族：placeOverlays/syncPanelSignatures/panelsLagBehind/placeEntry/anchorRect/originFor/absoluteOrigin，见 §4g）~~（✅ 2026-10-24） | smoke 绿 + Application 行数 −372 |
| 8 | ~~拆 `OverlayManager` 主体（栈所有权 A + 栈查询 B + 时序件 E + 入口 API F；InputHost 8→4 口子、OverlayLayout 改指本片）~~（✅ 2026-10-24，动工前分析见 §4h，落地见 §4h-落地） | smoke 绿 + Application 行数 −600（实际 −525） |
| 9 | ~~拆 `FrameEngine`（九段表驱动；帧循环读栈的 1740/1862/2210 定窄面）~~ → **已调整为拆 `PumpHost`（B 异步泵，A/C 留壳；2026-10-25 落地，见 §4i 与 §4i-落地）** | smoke 绿 + lint 0 + 0 兼容破坏 |
| 10 | **加 lint 规则**：`src/App/` 里 `instanceof` 只许出现在接口上（I2 的闸门，现在加会红 39 处，拆完才绿） | selftest 红绿双向 |

★ 每步独立可提交；`--api` diff 每步审（API 面只许有意变化）。

★ **为什么调整 5/6/7 的顺序**（2026-10-24 第 5 步动工前的评估）：
OverlayHost 是**横切**关注点 —— `$this->overlays` 在 Application 里有 ~25 处
引用、散布 6 类集成点（帧循环喂树 / 焦点 collect / 输入浮层优先 / 命令后关
菜单 / 受控同步 / 重绘判据），先拆它要把 25 处引用全改成窄面调用，单步
改动面过大。而滚动输入族边界极干净：只经 host 的 `focus()` 一个口子，
且是 InputRouter 主体拆分的天然前哨。原路线表自己的原则「被依赖多的
晚拆」正指向这个顺序（Overlay 被依赖最多 ⇒ 最后拆）。

★ **为什么 Overlay 自己也要分两刀**（2026-10-24 第 7 步动工前的评估，
§4g）：第 5 步的评估只说"Overlay 最后拆"，第 7 步摸排 24 处引用后发现
"最后拆"内部还有先后 —— 几何布局族（D 类）零耦合、host 只 2 口子，先拆；
栈所有权 + 时序件 + 入口 API（A/B/E/F）与输入契约 4 口子、帧循环 6 处
消费纠缠，后拆。同一条原则递归应用到自己身上。

---

## 4i. 第九步动工前分析：帧循环的切法（2026-10-25）

**目标**：把 `Application::frame()` 里「settle 之前」的四个异步泵搬出组合根。

### 版图（摸排结论，Application 现 1919 行）

帧循环族**三簇，不是一簇**：

| 簇 | 方法 | 状态字段 |
|---|---|---|
| **A 九段编排**（帧的骨架） | `frame()` / `repaintForResize()` / `wantsFrame()` | `frameSerial` / `animating` / `paintedFocus` / `lastTimestampMs` |
| **B 异步泵**（settle 之前的四泵） | `pumpIo` / `pumpTasks` / `pumpSliced` / `pumpTimers` + `submitSliced` / `complete` / `after` / `cancelAfter` | `sliced` / `tasks` / `io` / `timers` / `nextTimerId` |
| **C 收尾段**（帧末三件） | `tickAnimations` / `tickSubtree` / `tickLateMount` / `pumpSemantics` / `pushSemantics` / `applyNewContext` | `semantics*` 六字段 |
| **D 观测记账** | `noteFrameCost` / `overBudget` / `frameCostMs` / `worstFrameMs` | `overBudgetStreak` / `frameCostMs` / `worstFrameMs` |

### 三个候选方案

**方案一（路线表原案）：拆一个 `FrameEngine` 包住 A+B+C+D 整段**。
- 缺点：① **它就是第二个 Application**——把 frame() 原样搬一遍，只换类名，
  边界没有变窄；② C 簇读 `$this->animating` / `focus` / `root` 等宿主状态
  ⇒ host 口子 10+，与前 8 步「host 只摸应用状态、2-6 个口子」背离；
  ③ `frame()` 是 `@api`（组合根入口）位置不能动，拆走也得留壳。

**方案二（中间态）：A 留 Application（帧编排 = 组合根职责），只拆 B 异步泵
→ `PumpHost`**。
- 优点：B 簇**边界最干净**——四泵 + submitSliced/complete/after/cancelAfter
  只依赖 `tasks`/`io`/`sliced`/`timers` 四个自持状态 + `assertUiThread`，
  **不读 root/focus/overlays**（真正零耦合）。而 C 簇读 root/overlays/focus/
  animating，耦合面大。
- 这是「被依赖多的晚拆」的同一原则：B 簇被 frame/wantsFrame/OverlayManager
  (after/cancelAfter)/应用(submitSliced/io) 消费，但**它不依赖别人**。
- host 口子 1 个：`assertUiThread(string)`（读宿主 `uiThreadId`）。

**方案三（最激进）：连 A 也拆（frame 编排 → FrameEngine），Application 只剩
壳 + 状态**。
- 缺点：同方案一的①，且 A 簇读全部三簇状态 ⇒ host 口子 12+，得不偿失。

### 裁决：方案二（B 异步泵拆 `PumpHost`，A/C 留壳）

**为什么不是「九段表驱动」**：路线表写的「九段表驱动」是把 frame() 九段的
**顺序**抽成数据表。但九段每段的入参/出参/副作用各不相同（pumpIo 无参、
tickAnimations 返回 bool、placeOverlays 要 constraints、focusCollect 要
root+overlays、pumpSemantics 要 sink）⇒ 表驱动要给每段造 `Closure` 适配器，
PHP 里是**间接层没换来灵活性**（九段是固定次序，顺序写死在代码里反而
readable）。真正可复用的是「四泵」这个**稳定子结构**，不是「九段」这个
**一次性顺序**。

⇒ **拆 `PumpHost`（B 簇）是结构收益最大的一刀**：它把「异步结果何时被消费」
这个时序契约（**必须在 settle 之前**）收进一个类，而这正是 frame() 里四泵
共同满足的同一条不变量。

### PumpHost 边界

**搬走**：方法 `pumpIo` / `pumpTasks` / `pumpSliced` / `pumpTimers` /
`complete` / `submitSliced` / `after` / `cancelAfter`（8 个）；字段 `sliced` /
`tasks` / `io` / `timers` / `nextTimerId`（5 个）；常量 `SLICE_BUDGET_MS`。

**委托壳留 Application**（API 位置不动）：`after` / `cancelAfter` /
`submitSliced` / `io()` / `slicedCount()`（5 个 public @api / @internal）。

**host 口子**：`assertUiThread(string $where)`。四泵都断言 UI 线程身份，
它读宿主 `uiThreadId`（同 `InputHost` 第 6 步口径：host = 宿主状态的只读口）。

**frame() 改动**：四泵调用改 `$this->pumps->pumpXxx()`；`wantsFrame()` 第五
判据（定时器在等）改读 `$this->pumps->hasTimers()`（新 @internal getter）；
`$this->animating` / `frameSerial` 的写入留 Application（C 簇消费它们）。

### 为什么 C 簇（语义 / 动画）**不拆**

`tickAnimations` / `pumpSemantics` 读 `root` / `overlays` / `focus` /
`animating` / `frameSerial` / `overBudget` 六个宿主状态 ⇒ host 口子 6 个，
收益递减（语义泵只有 2 个方法，tickSubtree 是 20 行递归）。拆它是「为拆而拆」。
留壳里，等真正的 FrameEngine 需求（如帧分段重排）出现再说。

### 兼容性破坏（预期 0）

- 委托壳 5 个照留（API 位置不动）；`Application::BUDGET_MS` /
  `OVER_BUDGET_FRAMES` / `STAGE_BUDGET_MS` **不动**（smoke.php:11172/11211
  直接引用它们做超预算用例）。
- `SLICE_BUDGET_MS` 随 `submitSliced` 默认值搬走（零外部引用）。
- `OverlayHost` 的 `after`/`cancelAfter` 口子**不动**（Application 仍是宿主，
  经委托壳转调 PumpHost）。

### 顺手修：FrameClock.php 的过期措辞（第 7 步遗留）

`FrameClock::tick()` 注释说「`Application::wantsFrame()` 把任务在途 /
分片没跑完 / 有流在监听三件事收成一个是非题」—— 这三件事的**状态**在第
4/5/8 步拆进了 `PumpHost`（tasks/sliced/io），判据实际经 `PumpHost` 与
`OverlayLayout::panelsLagBehind` 问。措辞改成「wantsFrame() 经 `PumpHost`
与 `OverlayLayout` 问那几件事」，避免读者以为 Application 自持那些状态。
（docref 只查行号越界、不查措辞漂移 ⇒ 主动 grep 语义位置。）

### §4i-落地（2026-10-25）

**实际落地**（与上面裁决一致，只拆 B 簇进 `PumpHost`）：

- 新建 `src/App/PumpHost.php`（`@internal final class`：8 方法 + 5 字段 + `SLICE_BUDGET_MS` + `hasTimers()`/`hasAsync()`）与 `src/App/PumpHostHost.php`（4 口子：`assertUiThread` / `lastTimestampMs` / `lastInputMs` / `taskHost`）。
- `Application`：删 8 方法（`pumpIo`/`pumpTasks`/`pumpSliced`/`pumpTimers`/`complete`/`submitSliced`/`after`/`cancelAfter`）+ 5 字段（`tasks`/`sliced`/`timers`/`nextTimerId`/`io`）+ `SLICE_BUDGET_MS`；`implements` 加 `PumpHostHost`；`taskHost()` 由 `protected` 升 `public`（接口协变要求）；加 `lastTimestampMs()`/`lastInputMs()` 两个 host getter；构造器建 `$this->pumps = new PumpHost($this)`，`attach()` 里 `$this->pumps->attach()`。
- `frame()` 四泵改 `$this->pumps->pumpXxx()`；`wantsFrame()` 的 sliced/io/tasks 三判据收成 `$this->pumps->hasAsync()`、定时器判据收成 `$this->pumps->hasTimers()`。
- 5 个 public 方法（`after`/`cancelAfter`/`submitSliced`/`io()`/`slicedCount()`）留一行委托壳（**API 位置不动**）。
- `apps/users/src/UsersApplication.php` 与 `tools/Smoke/App/TaskApplication.php` 的 `taskHost()` 覆写由 `protected` 升 `public`。
- 顺手修 `FrameClock.php` 过期措辞（上面「顺手修」条已记）。

**行数**：`Application.php` 约 1919 → 1640（−~280，含委托壳与 host getter）；新增 `PumpHost.php` 385 行、`PumpHostHost.php` ~30 行。

**验证链全绿**：

- `php tools/lint.php`：505 文件 · 0 error · 0 warn。
- `php tools/smoke.php`：1396 ok / 0 FAIL（拆片路径全部经 Application 委托壳，smoke 64 处调用无感知）。
- `php tools/docref.php`：68 ok / 0 FAIL（另修两处过期行号引用：`ARCH-V2-REVIEW-2026-10-21.md` 的 `:1718` 与 `01-覆盖层.md` 的 `:1790-1794` → `InputRouter` 关闭语义）。

**变异测试（该响会响）**：

- 变异 A（吞掉 `complete()` 的 `onError` 调用）→ 2 FAIL（`P0 · 应用层失败走 onError`、`P1 · step 抛异常 ⇒ onError 且出队），证明「异常不吞」被覆盖。
- 变异 B（删 `pumpTimers()` 的回调触发）→ 10 FAIL（含「先摘再调」`inner` 下一帧、`Tooltip` 延时弹出全链路），证明「定时器能不能响」被覆盖。
- 两处变异还原后均回到 1396/0。

**兼容性破坏**：0（委托壳照留；`BUDGET_MS`/`OVER_BUDGET_FRAMES`/`STAGE_BUDGET_MS` 不动；`SLICE_BUDGET_MS` 随默认值搬走且无外部引用）。
