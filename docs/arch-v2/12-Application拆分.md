# 12 · Application 拆分（S1）

> 状态：拆解中 · 2026-10-24 · 第一~六步已落地（收接口 + 三片：TextEditingBridge / ScrollInputRouter / InputRouter）
> 上游：ARCH-REVIEW-2026-10-24 §2.2（唯一的架构性缺陷）/ §3.2 I1+I2 / §7 第一步
> 护栏：smoke 1392 条 + lint 19 条 + docref 3 条 + api-surface diff
> 回答三个问题：**39 处 instanceof 的真实版图是什么？四片怎么切？先收接口还是先搬家？**

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

## 5. 剩余路线（每步的验收）

| 步 | 动作 | 验收 |
|---|---|---|
| 2 | ~~8 处 `instanceof StatefulElement` 收进 `FocusManager::focusedState()`~~（✅ 2026-10-24，见 §4b） | smoke 绿 + App 层该 instanceof 归零 |
| 3 | ~~`Scrollable` 已合规；`RenderScrollView` 5 处收成 `Scrollable`（盒侧接口）~~（✅ 2026-10-24，方案已修正，见 §4c） | 同上 |
| 4 | ~~拆 `TextEditingBridge`（最小片，验证装配模式）~~（✅ 2026-10-24，见 §4d） | smoke 绿 + Application 行数 −250 |
| 5 | ~~拆 `OverlayHost`~~ → **已调整为 `ScrollInputRouter`**（滚动输入族，2026-10-24 拆片顺序调整 + 完成，见 §4e） | 同上 |
| 6 | ~~拆 `InputRouter`（dispatchInput / dispatchPointer 主体 + 手势状态 + 光标）~~（✅ 2026-10-24，见 §4f） | 同上 |
| 7 | 拆 `OverlayHost`（原步 5 推迟到这里：被帧/焦点/输入/命令四方依赖，"被依赖多的晚拆"） | 同上 |
| 8 | 拆 `FrameEngine`（九段表驱动） | 同上 |
| 9 | **加 lint 规则**：`src/App/` 里 `instanceof` 只许出现在接口上（I2 的闸门，现在加会红 39 处，拆完才绿） | selftest 红绿双向 |

★ 每步独立可提交；`--api` diff 每步审（API 面只许有意变化）。

★ **为什么调整 5/6/7 的顺序**（2026-10-24 第 5 步动工前的评估）：
OverlayHost 是**横切**关注点 —— `$this->overlays` 在 Application 里有 ~25 处
引用、散布 6 类集成点（帧循环喂树 / 焦点 collect / 输入浮层优先 / 命令后关
菜单 / 受控同步 / 重绘判据），先拆它要把 25 处引用全改成窄面调用，单步
改动面过大。而滚动输入族边界极干净：只经 host 的 `focus()` 一个口子，
且是 InputRouter 主体拆分的天然前哨。原路线表自己的原则「被依赖多的
晚拆」正指向这个顺序（Overlay 被依赖最多 ⇒ 最后拆）。
