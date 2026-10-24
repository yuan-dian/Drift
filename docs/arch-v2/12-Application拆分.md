# 12 · Application 拆分（S1）

> 状态：拆解中 · 2026-10-24 · 第一步（收接口）已落地
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

## 5. 剩余路线（每步的验收）

| 步 | 动作 | 验收 |
|---|---|---|
| 2 | ~~8 处 `instanceof StatefulElement` 收进 `FocusManager::focusedState()`~~（✅ 2026-10-24，见 §4b） | smoke 绿 + App 层该 instanceof 归零 |
| 3 | `Scrollable` 已合规；`RenderScrollView` 5 处收成 `Scrollable`（盒侧接口） | 同上 |
| 4 | 拆 `TextEditingBridge`（最小片，验证装配模式） | smoke 绿 + Application 行数 −250 |
| 5 | 拆 `OverlayHost` | 同上 |
| 6 | 拆 `InputRouter` | 同上 |
| 7 | 拆 `FrameEngine`（九段表驱动） | 同上 |
| 8 | **加 lint 规则**：`src/App/` 里 `instanceof` 只许出现在接口上（I2 的闸门，现在加会红 39 处，拆完才绿） | selftest 红绿双向 |

★ 每步独立可提交；`--api` diff 每步审（API 面只许有意变化）。
