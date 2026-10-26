# 能力缺口分析 —— 对照成熟 GUI 框架（2026-10-26）

> 目的：给「后续计划」提供取舍依据。
> 判据以**代码现状**为准（每项附 grep/文件证据），不采信记忆里的旧快照。
> 对照对象：Flutter / Qt Widgets / WPF / WinUI 3 / SwiftUI。

## 0. 结论先行

**骨架已达可用线，短板集中在两层：表现力（质感）与业务控件面。**

- **已达可用线**：三层树（Widget/Element/Render）、布局盒（34 个 Render 类）、
  响应式状态（`Cell`/`Subject`）、命令路由 + 快捷键、焦点系统（含 Tab 遍历）、
  IME、AOT 构建（35s）、四档闸门（smoke 1475 / lint 535 文件 / docref 69 / selftest）。
  这套工具链的成熟度**高于多数自研 GUI 框架**，是真正的差异化资产。
- **最大缺口是「用户第一眼看见的东西」**：阴影/渐变原语早就写好但**控件层 0 消费者**；
  动画引擎有但**控件层 0 消费**；无毛玻璃、无混合模式、无窗口半透明。
- **第二缺口是业务控件面**：表格/TabView/日期选择器全缺，这决定"能不能写企业应用"。

一句话取舍：**先补「质感接线」（成本低、感知强），再做「窗口表现力」（成本中、差异化强），
最后按业务需要补控件（成本高、可延后）。**

---

## 1. 表现层：原语齐备、接线为零（最大缺口）

| 能力 | 状态 | 证据 |
|---|---|---|
| 阴影原语 | **半成品** | `src/Rendering/Shadow.php`、`ShadowPainter.php` 存在；`grep Shadow src/Widget/` 只命中 `Widget.php`（注释）⇒ **0 消费者** |
| 渐变 | **半成品** | `src/Rendering/Gradient.php` + `Paint` 支持；控件层 0 消费者 |
| 圆角 | 已有 | `BoxStyle::radius` 被 `Card`/`Button`/`PopupPanel` 等消费 |
| 毛玻璃 / backdrop blur | **缺口** | `grep Blur src/` 只命中阴影与 `Modifier`（阴影半径），无 backdrop filter |
| 混合模式 | **缺口** | `grep BlendMode\|xfermode` **全仓 0 处** |
| Opacity 控件 | **缺口** | `src/Widget/` 无 `Opacity`；只有 `AnimationController` 内部用 alpha |
| Transform（旋转/缩放/斜切） | **缺口** | `src/Widget/` 无 Transform 家族 |
| 动画引擎 | **半成品** | `AnimationController` + `Curve` 完整；`grep AnimationController src/` 只命中 `Application`/`PumpHost`/自身 ⇒ **控件层 0 消费** |
| 隐式动画 / Tween | **缺口** | `grep AnimatedC\|AnimatedO\|Tween` **0 处** ⇒ 无状态过渡动画 |
| 页面转场 / Hero | **缺口** | 无 |
| Hover 视觉 | 已有（弱） | `Widget::hover(fn)` 回调 + 色板；但**只变色、不浮起**（无 elevation 变化） |

**性质**：这一层不是"不会做"，是"做完了没接线"。阴影与渐变的实现已在
`DisplayList`/`RasterCanvas`/`SkiaCanvas` 三端打通并通过像素测试，
只差在控件里调用 —— **投入产出比最高的一块**。

**Top3 短板**：① 控件层 elevation 纪律（阴影）② 隐式动画（状态过渡）③ 毛玻璃 + 混合模式。

---

## 2. 交互 / 输入层：基座好，缺"手感"

| 能力 | 状态 | 证据 |
|---|---|---|
| 焦点系统 | **已有** | `FocusManager` 609 行：`focusNext`/`focusPrevious`/Tab 与 Shift+Tab（`:273`）/焦点环/`cursorOf`/`hoverTo` |
| 手势识别 | **半成品** | `src/Input/GestureArena.php`(92 行) + `GestureRecognizer.php`(69 行) 是**通用基架**；无 long-press / pan / pinch / double-tap 具体识别器（点击由按钮内部处理） |
| 拖放 | 半成品 | `src/Widget/Drag/{Draggable,DragTarget}.php` 存在；未验证窗口层接通（拖出窗口边界） |
| 滚动物理 | **缺口** | `grep inertia\|friction\|fling\|bounce src/` **0 处** ⇒ 无惯性、无回弹、无平滑滚动 |
| 滚动条 | 已有 | `RenderScrollView::paint()` 自绘胶囊 thumb（贴右/底，滚动时不随内容偏移） |
| 悬停 | 已有 | `FocusManager::hoverTo` + `Widget::hover` 链路 |
| 剪贴板 | **半成品** | `Win32Clipboard` 只有 `readText`/`writeText`/`hasText` ⇒ 无图像/文件/富文本格式 |
| 文本编辑 | 部分 | `EditableText::selectedText()` 有选区；`RichText` **无选区**（`grep selection` 0 处）⇒ 无跨控件选中复制 |
| 系统集成 | **缺口** | `WM_DROPFILES`/`DragAcceptFiles`（拖文件进窗口）**0**；托盘图标 `Shell_NotifyIcon` **0**；原生菜单 `CreateMenu` **0** |

**Top3 短板**：① 滚动惯性（手感最容易被用户察觉）② 手势识别器家族 ③ 剪贴板多格式。

---

## 3. 窗口 / 架构层：单窗假设是结构性上限

| 能力 | 状态 | 证据 |
|---|---|---|
| 多窗口 | **半成品** | `WindowHost` 接口已按"多句柄"设计（`createWindow`/`close(hwnd)`/`place(hwnd)`）；但 `Application::attach(RenderSurface)` 收**单数表面** ⇒ 一个 Application 一个窗口 |
| 无边框 / 自绘标题栏 | **缺口** | `grep WS_POPUP\|frameless\|borderless` **0 处** ⇒ `Dock`/`AppBar` 是内容区自绘，不是真无边框窗 |
| 窗口半透明 / per-pixel alpha | **缺口** | `grep WS_EX_LAYERED\|LayeredWindow` **0 处** |
| 毛玻璃 / DWM 效果 | **缺口** | `grep DwmSetWindowAttribute\|BackdropType\|Acrylic` **0 处**（也无 Win11 圆角 API） |
| 主题 | **已有** | `ThemeData::light()/dark()/forSystem()/withHighContrast()`；`ThemeScope` 支持局部覆盖；`ThemeChangedEvent` 存在 |
| 动态切换 | 半成品 | 事件与 scope 都在；未见"运行中整体换主题并通知全树"的应用级入口 |
| 状态管理 | **已有** | `src/State/{Cell,Subject,SubjectBuilder,SubscribingWidget,Subscription}.php` ⇒ 细粒度响应式 |
| 数据绑定 / MVVM | 缺口（**可不补**） | `Subject` 已覆盖需求；WPF 式绑定不是必要路径 |
| 命令系统 | **已有** | `CommandRouter`/`CommandArg`/`CommandHandler` + `AcceleratorTable`（含冲突检测、启用禁用） |
| undo / redo | **缺口** | `grep undo\|redo` **0 处** |
| 路由 | 已有 | `src/Routing/{PageHost,PageHostState,RouteTable,NoPage}.php` |
| 脏矩形 / 增量重绘 | **缺口** | `grep dirtyRect\|damage` **0 处** ⇒ 全量重绘（实测 72 节点 1.35ms/帧、453 节点 settle 0.45ms ⇒ 当前规模够用） |
| GPU 后端 | 缺口（接口先行） | `GpuContext` 是「M1 = Skia CPU 光栅」的直通空实现；Skia 走 CPU 光栅 |
| 可访问性（UIA） | **需复核** | 语义树（`SemanticsTree`/`Node`/`Role`）+ `Application::pushSemantics()` + `Win32WindowHost::pushSemantics()` 链路在；但 **C++→PHP 的回调入口 `UiaInvoke.php` 已不在 `src/`**（只剩 `apps/users/build/` 里 10-25 的构建残留）⇒ 「推送」通、「操作回流」半边待确认 |
| 高对比度 | 半成品 | `ColorToken::highContrastValue()` + `ThemeData::withHighContrast()` 有；无系统设置检测/自动切换 |
| i18n / RTL | **缺口** | `grep Directionality\|locale\|translat\|RTL` **0 处** |

**Top3 短板**：① 无边框 + 自绘标题栏（质感门槛）② 窗口半透明/毛玻璃（差异化）③ 多窗口 Application 改造。

---

## 4. 业务控件面：企业应用直接缺的东西

**已有（28 类交互/展示控件）**：
Button / IconButton / TextField / Checkbox / Radio / Toggle / Slider / Segment /
SegmentedControl / Select / ComboBox / Progress / Divider / Spinner / Card /
AppBar / Dock / NavButton / Tooltip / Snackbar / Modal / MenuBar / Menu /
ScrollView / LazyColumn / LazyRow / LazyGrid / Grid / Stack / Image / Icon / RichText

**缺失（按企业场景优先级）**：

| 缺失控件 | 为什么重要 | 依赖 |
|---|---|---|
| **Table / DataGrid** | 企业第一需求（列表 + 列宽拖拽 + 排序 + 多选） | 需列表基座（`LazyColumn` 已有）+ 手势 pan |
| **TabView / TabBar** | 多标签工作区比导航栏更常见 | `SegmentedControl` 可复用 |
| **TreeView** | 文件树 / 组织结构 / 配置树 | 递归 + 缩进 + 展开动画（依赖隐式动画） |
| **Wrap（流式布局）** | 标签云 / 自适应工具栏 | 布局层新增（无 Render 类） |
| **DatePicker / TimePicker** | 表单高频 | `PopupPanel` + `Grid` 已有 |
| Badge / Tag / Avatar | 列表与卡片装饰 | 纯样式 |
| Breadcrumb / Pagination | 后台页面 | 纯组合 |
| 布局补齐 | `ConstrainedBox` / `IntrinsicWidth` / `AspectRatio`（只有 AspectRatio） | 布局层新增 |

**性质**：这些**不是框架能力缺口，是控件库存**。`LazyColumn`/`Grid`/`PopupPanel`/
`ScrollView` 已在，做 Table 主要是"排列 + 手势 + 键盘导航"的组合工作。

---

## 5. 工具链：最强项（应保持，不建议重投入）

| 能力 | 状态 |
|---|---|
| 四档闸门 | smoke **1475 断言** / lint **535 文件 23 规则** / docref 69 / selftest 双向自检 |
| AOT 构建 | `drift-build.php` 35s 出 11MB exe；增量缓存有坑（全量须删 `build/`） |
| 真机探针 | `probe-grab.exe` + 5 个 Python 探针（窗口/滚动条/模态/异步/帧闸门） |
| 视觉回归 | `golden-raster.php`（像素级） |
| 预览 | `drift-preview.php`（无头渲染 + SVG/PNG） |
| 微基准 | `bench-run.php`（readonly 成本、帧阶段耗时） |

**缺**：热重载（改代码 → 35s 重编，是这个仓库迭代手感的最大摩擦）、
运行时控件树检查器（DevTools）、内存/帧率分析器。

---

## 6. 建议路线（按 感知 × 依赖 × 成本 排序）

### P0 —— 质感接线（成本低，感知最强，不碰结构）
1. **阴影接入控件**：给 `Card`/`Button`/`PopupPanel`/`Modal` 定义 elevation 层级并画阴影。
   原语 + 三端实现已就绪，**只差消费者**。
2. **隐式动画**：`AnimatedOpacity`/`AnimatedContainer`（hover 过渡、展开折叠、主题色渐变）。
   `AnimationController` + `Curve` 已在，缺的是"值变化时自动补间"的控件。
3. **滚动惯性 + 平滑**：`RenderScrollView` 加 velocity 与 fling 衰减（纯策略层，无结构风险）。
4. **Hover/Pressed 的"浮起"**：阴影 + 色板组合，让交互有物理感。

### P1 —— 窗口表现力（成本中，差异化强）
5. 无边框窗口 + 自绘标题栏（`WS_POPUP` + 拖拽移动 + 双击最大化 + 系统阴影/圆角）。
6. 窗口半透明 / per-pixel alpha（`WS_EX_LAYERED`）。
7. 毛玻璃（`DwmSetWindowAttribute` + `DWMWA_SYSTEMBACKDROP_TYPE`，Win11 原生；降级为纯色）。
8. 混合模式 + Opacity 控件（补渲染原语空洞）。

### P2 —— 控件库存（按业务需要推进，不阻塞）
9. Table / DataGrid（分两步：先只读表格，再列宽拖拽 + 多选）。
10. TabView / TabBar。
11. Wrap + `ConstrainedBox`/`IntrinsicWidth`（布局补齐）。
12. DatePicker / TimePicker + Badge/Tag/Avatar。
13. 手势识别器家族（long-press / pan / pinch）——Table 列宽拖拽与 TreeView 都要它。

### P3 —— 架构长期项（不影响当前可用性）
14. i18n / RTL。
15. 剪贴板多格式 + 富文本选区。
16. 脏矩形 / 增量重绘（**当前性能数据不支持优先做**：453 节点 settle 0.45ms）。
17. 多窗口 Application 改造（`attach` 收表面数组 / 每窗一树）。
18. undo / redo（命令栈）。
19. **UIA 回调入口复核**（`UiaInvoke.php` 缺位 ⇒ 无障碍可能半残，属合规风险）。
20. 热重载 / DevTools（迭代效率，可随 P1 并行）。

---

## 7. 不建议做的事

- **MVVM/数据绑定**：`Subject`+`Cell` 已经是更好的形态，WPF 式绑定是回头路。
- **GPU 后端**：CPU 光栅在 453 节点 0.45ms settle 的水平上，GPU 收益远小于成本；
  等真实性能瓶颈出现再说（`GpuContext` 接口已留好）。
- **脏矩形**：同上，先用基准数据说话。
- **原生控件**（`CreateMenu` 等系统菜单）：自绘 `MenuBar` 已有，混用原生控件会割裂主题。

---

## 8. 落地记录（2026-10-26 续）

**P1-6 窗口级透明 —— Stage 1a 已落地**（`WindowHost::setOpacity` ⇄ `drift_win_set_opacity`）：

- **懒挂** `WS_EX_LAYERED` + `SetLayeredWindowAttributes(LWA_ALPHA)`（**全局** alpha），
  与既有 `WM_PAINT` + `SetDIBitsToDevice` 上屏**共存**，零回归 —— 只有应用真要
  半透明时才加 LAYERED，其余窗口保持纯 GDI（不承担分层窗的合成怪癖）。
- 建窗即加 DWM 圆角（`DWMWA_WINDOW_CORNER_PREFERENCE=ROUND`；旧系统/旧 SDK 静默忽略）。
- 消费者：`users` 组合根读 `DRIFT_WINDOW_OPACITY`（**默认不变**，opt-in）。
- 真机判据：`DRIFT_WINDOW_OPACITY=0.8` ⇒ 整窗透出背后桌面（`_build/stage1a-full-trans.png`），
  不设 ⇒ 与改动前逐像素一致。
- **尚未做**：P1-5 无边框 + 自绘标题栏；**P1-7 毛玻璃**（要 P1-6 的**逐像素** alpha：
  换 `UpdateLayeredWindow` + `DWMWA_SYSTEMBACKDROP_TYPE` 亚克力，并让主题画半透明表面）；
  P1-8 混合模式 + `Opacity` 控件。
