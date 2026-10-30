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
| 阴影原语 | **半成品**（2026-10-26 快照；**2026-10-30 已接线**） | `src/Rendering/Shadow.php`、`ShadowPainter.php` 存在；2026-10-26 时 `grep Shadow src/Widget/` 只命中 `Widget.php`（注释）⇒ 当时 **0 消费者**；现由 `WidgetModifiers::shadow()`/`Card::shadow()`/`PopupPanel::elevation()`/`SnackbarPanel`/`TooltipPanel`/`OptionPanel::elevation()` 消费（见 `REVIEW-2026-10-28.md` §28.1） |
| 渐变 | ✅ **已接线**（A1a，10-28） | `Widget::gradient()` + `Gradient::vertical/horizontal/diagonal/radialBox`（盒内比例坐标） |
| 圆角 | 已有 | `BoxStyle::radius` 被 `Card`/`Button`/`PopupPanel` 等消费 |
| 毛玻璃 / backdrop blur | **缺口** | `grep Blur src/` 只命中阴影与 `Modifier`（阴影半径），无 backdrop filter |
| 混合模式 | **缺口** | `grep BlendMode\|xfermode` **全仓 0 处** |
| Opacity 控件 | ✅ **已有**（A1b） | `Opacity` 控件 + `Widget::opacity()`；走 `Modifier` 合成槽 ⇒ 整棵子树一次 `pushLayer` |
| Transform（旋转/缩放/斜切） | ✅ **已有**（A1c，10-29） | `Matrix2D` + `Widget::transform()/rotate()/scale()`；**斜切未提供**（无 `skew` 工厂），无 3D 透视 |
| 动画引擎 | **半成品** | `AnimationController` + `Curve` 完整；`grep AnimationController src/` 只命中 `Application`/`PumpHost`/自身 ⇒ **控件层 0 消费** |
| 隐式动画 / Tween | ✅ **已有**（A1b） | `AnimatedOpacity` / `AnimatedScalar`（后者由 `AnimatedValue` 半衰期模型驱动，不是 `Curve`）；见 `REVIEW-2026-10-28.md` §23 |
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
| 系统集成 | **半成品**（2026-10-30 起） | ✅ 文件对话框 / 提示 / 确认：`Win32Dialog` + 四个桥函数（A5-①，§26）。✅ 拖文件进窗：`WM_DROPFILES` + `DragAcceptFiles` → `FilesDroppedEvent` → 命中/父链冒泡 → `onFilesDropped`（A5-②，§27）。仍缺：托盘图标 `Shell_NotifyIcon` **0**（`Tray` 端口零消费者）；原生菜单 `CreateMenu` **0** |

**Top3 短板**：① 滚动惯性（手感最容易被用户察觉）② 手势识别器家族 ③ 剪贴板多格式。

---

## 3. 窗口 / 架构层：单窗假设是结构性上限

| 能力 | 状态 | 证据 |
|---|---|---|
| 多窗口 | **缺口**（2026-10-30 评估后降级） | `WindowHost` 接口已按"多句柄"设计（`createWindow`/`close(hwnd)`/`place(hwnd)`），这一层写对了；但**障碍不在 `Application`，在 C++ 壳的 45 个进程级全局 + `Platform` 的 7 处无窗口身份签名**。今天建第二个窗口会**静默互相破坏**（9 条破坏链见评估册 §2）⇒ 评估已完成，路径分四台阶，见 [`MULTI-WINDOW-2026-10-30.md`](MULTI-WINDOW-2026-10-30.md) |
| 异步 / 并发 | **半成品**（2026-10-30 评估） | ✅ 零件齐全：`TaskHost` 端口 + `SyncTaskHost`（默认，同步）+ `ProcessTaskHost`（多进程常驻池，崩溃隔离）+ `SlicedJob`（切帧 4ms）+ `IoPoller`（每帧 `select(0)`）。**多线程 / 协程已实测否决**（AOT worker 线程缺 Zend 上下文；Fiber 不让出阻塞 IO），裁决与依据见 [`ASYNC-2026-10-30.md`](ASYNC-2026-10-30.md) §1。真缺四条：① 示例台未注入 `TaskHost` ⇒ 走同步后端，「示例即模板」会把异步教成卡顿；② 「同时请求 N 个接口」（并发等待）无零件无示例 ⇒ 该用单 worker 的 `curl_multi`，而不是 N 次 `submit()`；③ 无取消；④ 无框架级超时 ⇒ worker 卡死时 `pending()` 恒正，帧时钟 15ms 空转烧电池 |
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

**已有（36 类交互/展示控件）**：
Button / IconButton / TextField / Checkbox / Radio / Toggle / Slider / Segment /
SegmentedControl / Select / ComboBox / Progress / Divider / Spinner / Card /
AppBar / Dock / NavButton / Tooltip / Snackbar / Modal / MenuBar / Menu /
ScrollView / LazyColumn / LazyRow / LazyGrid / Grid / Stack / Image / Icon / RichText /
**Table**（2026-10-26）/ **TabView**（2026-10-28）/ **Wrap**（2026-10-28）/
**TreeView**（2026-10-29）

**缺失（按企业场景优先级）**：

| 缺失控件 | 为什么重要 | 依赖 |
|---|---|---|
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
   原语 + 三端实现已就绪，**只差消费者**。（✅ **2026-10-30 已完成**：现由
   `WidgetModifiers::shadow()` / `Card::shadow()` / `PopupPanel::elevation()` 等消费，
   见 `REVIEW-2026-10-28.md` §28.1 盘点表；本行保留 2026-10-26 原判据为历史快照）
2. **隐式动画**：`AnimatedOpacity`/`AnimatedContainer`（hover 过渡、展开折叠、主题色渐变）。
   `AnimationController` + `Curve` 已在，缺的是"值变化时自动补间"的控件。
3. **滚动惯性 + 平滑**：`RenderScrollView` 加 velocity 与 fling 衰减（纯策略层，无结构风险）。
4. **Hover/Pressed 的"浮起"**：阴影 + 色板组合，让交互有物理感。

### P1 —— 窗口表现力（成本中，差异化强）
5. 无边框窗口 + 自绘标题栏（`WS_POPUP` + 拖拽移动 + 双击最大化 + 系统阴影/圆角）。
6. 窗口半透明 / per-pixel alpha（`WS_EX_LAYERED`）。
7. 毛玻璃 —— **已落地**（分两层：应用内 `BackdropFilter` / `Canvas::blurBackdrop`；
   窗口背后 `Backdrop` + `WindowHost::setBackdrop()`。★ 原处方写的是
   `DWMWA_SYSTEMBACKDROP_TYPE`，本机 Win10 19045 上不可用，实走未公开的 accent 策略，
   见 §8 P1-10）。
8. 混合模式 + Opacity 控件（补渲染原语空洞）。

### P2 —— 控件库存（按业务需要推进，不阻塞）
9. Table / DataGrid —— **结构与分阶段见 `docs/DESIGN-TABLE-2026-10-26.md`**（七阶段，每阶段不推翻前一阶段）。
10. TabView / TabBar —— **2026-10-29 已落地 `TabView`**（`src/Widget/TabView.php`，判据
    `tests/Feature/Widget/TabViewTest.php`，见 `REVIEW-2026-10-28.md` §15）。
    ★ **真 `TabBar` 仍未做**：`TabView` 的页签条 v1 直接复用 `SegmentedControl`（等宽胶囊）。
    内容宽度 + 与内容区相连的指示条、关闭按钮、可滚动页签条都还没做 —— 需要的人先提。
11. Wrap + `ConstrainedBox`/`IntrinsicWidth`（布局补齐）。
    ★ **Wrap 不是"加个控件"**：`Overflow.php` 的 docblock 已记它被删的理由（类别错误——
    重排不是舍弃），推翻条件是"子项数运行时才知道 + 必须自动换行"，届时它是**独立布局
    原语**（两遍布局，先量全部子固有宽）。成本按新原语估，不按控件估。
12. DatePicker / TimePicker + Badge/Tag/Avatar。
    ★ **2026-10-29 部分落地**：月历本体 `CalendarView`（`src/Widget/CalendarView.php`，
    配 `YearMonth` / `LocalDate` 两个值对象，示例台第 25 项 `CalendarPage`，
    见 `REVIEW-2026-10-28.md` §17）；同日补**日 / 月 / 年三档视图**（点标题循环，
    跨年翻月不用按 17 次箭头，见 §18）。
    ★ **仍未做（这一条的剩余部分）**：① `range` 区间选择（契约留白：
    `selected()` 与将来的 `range()` 互斥并存）；② `min/max` 与"禁用某几天"——
    **前置已落地**：`Widget::disabled()`（2026-10-29，见 `REVIEW-2026-10-28.md` §20；
    同日 §21 把四处既有 `enabled()` 收敛成它的别名，禁用统一不进 Tab 序），
    现在"某几天灰而不可点"写得出来了，缺的只是 `CalendarView` 上的一层日期谓词。

    ★ **2026-10-29 同日补全（原第 ③ 条已落地）**：`TimeView`（`src/Widget/TimeView.php`，
    时/分/秒三列 `LazyColumn`，配 `LocalTime`；默认两列，`withSeconds()` 出第三列）、
    `DatePicker`（触发框 + 浮层，值 `LocalDate`）、`DateTimePicker`（值 `LocalDateTime`，
    面板里 `Row[CalendarView, TimeView]` 并排）、`LocalDateTime` 值对象。
    示例台第 27 项 `PickerPage`，判据 `tests/Feature/Widget/TimeViewTest.php` +
    `DatePickerTest.php`，见 `REVIEW-2026-10-28.md` §19。
    ★ **裁决：日期与时刻是两个控件，不是同一个 `type` 分支**（理由见 §19.1）。
    ★ 顺带补的两条框架缝：`Widget::overlayPanel($f, $maxWidth)`（面板宽上限可自报，
    默认 320 装不下并排两块）、`LazyList::viewportExtent()`（浮层里 `BuildContext`
    主轴无界 ⇒ 虚拟化会静默退化成"全量造 60 格"）。
    ★ **快捷选项归应用**（2026-10-29 拿 layui `laydate` 的 `shortcuts` 对照后的裁决）：
    它的 `value` 是函数 ⇒ **控件读时钟** ⇒ golden 每天变、用例不可复现，且"近 30 天
    按哪个时区算"是业务知识。应用拿 `selected()` 自己放按钮即可。
    ★ **点标题弹月/年网格**已落地（曾是这条最刚需的缺口）—— 但它做成了**原地换内容**
    而不是 laydate 那种嵌套浮层：浮层里再开浮层，`OverlayManager` 的属主清扫会变成
    两层，收益却只是同一块区域的另一种画法。
13. 手势识别器家族（long-press / double-tap / pinch）。
    ★ **不是 Table 列宽拖拽的前置**（2026-10-26 修正）：列宽拖拽与 `Slider` 同类
    （位置即语义的连续拖拽），走 `pointer(ownsPress: true)` + 框架捕获，`Slider` 的
    docblock 已三条理由否掉 `GestureArena` 那条路。手势族的真实欠债是**时间维度**
    ——`GestureArena::claims()` 只有位移没有时钟，long-press / double-tap 都要它。

**已落地但不在上面编号内**：`TreeView`（2026-10-29，`src/Widget/TreeView.php` +
`TreeNode`/`TreeRow`，判据 `tests/Feature/Widget/TreeViewTest.php`，示例台第 24 项
`TreePage`，见 `REVIEW-2026-10-28.md` §16）。按 §A3 排期（TabView → TreeView → DatePicker）
插入，落点是"文件树 / 组织结构 / 配置树"。
★ **仍未做**：展开/折叠的布局动画、行进键盘导航（上下移动 + 左右折叠展开）、多选与半选态、
环检测（应用给了带环的 `children` 会无限递归 —— 当前契约是"应用不许给环"）。

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

**P1-5 无边框 + 自绘标题栏 —— Stage 1b 已落地**（`WindowHost::setFrameless` /
`beginDrag` / `minimize` / `maximizeToggle`）：

- **走 `WM_NCCALCSIZE` 而不是 `WS_POPUP`**：客户区=整窗（标题栏消失），但窗口
  样式仍是 `WS_OVERLAPPEDWINDOW` ⇒ **贴边吸附 / 快照布局 / 最大化动画全部由系统
  照旧**。`WS_POPUP` 会丢掉这些原生行为。真机判据：拖顶条到屏幕顶边 ⇒ 原生
  Aero Snap 最大化（`rect=-8,-8,1936,1056`）。
- **拖动归应用内容，不归壳**：`Widget::windowDrag()` 是声明式能力，`InputRouter`
  在按下相位消费它，**途中遇到"自己处理按下"的控件（可聚焦 / `Activatable` /
  `pointer()`）就让位** ⇒ 顶条可拖、顶条上的按钮照常点击。壳里的
  `WM_NCHITTEST` 拖动带保留（`dragHeight`，默认 0）当"不画顶条的应用"的兜底 ——
  壳看不见控件树，用它做顶条会把按钮点击一起吞掉。
- **三个窗口按钮是普通命令按钮**（`window.minimize` / `window.maximize` /
  `window.close`，`AppBar::windowButtons()`）：共享代码零平台引用，组合根注册
  handler（`apps/users/bin/win32/WindowCommands.php`）。三者都 `Flat` ——
  与 Windows/macOS 一致，常驻红底会把注意力从内容上拽走。
- 真机判据（`DRIFT_WINDOW_FRAMELESS=1`）：外框 == 客户区（无标题栏）；拖顶条
  +100/+136 ⇒ 位移逐像素精确；点按钮不拖窗；关闭真退出；最小化 → 还原几何与
  画面完好；最大化客户区 == 工作区（`1920×1040`，任务栏 40px 未被盖）；拖右下角
  +80/+60 ⇒ 尺寸精确。
- **撞出并修掉一个真缺陷**：最小化后窗口是经典的 160×28 小方块。**有标题栏时**
  客户区高度被扣成 0，`Application::frame()` 的零视口保护正好兜住；**无边框窗**
  客户区 = 整窗 = 160×28 ⇒ 保护不触发 ⇒ 按 160×28 布局、顶条只给标题文字
  136×16（自然高 21.6）⇒ C6 Fatal。修法：壳对 `IsIconic` 直接报客户区 `0×0`
  （最小化的窗口没有"能画的客户区"，与 `position()` 返 null 同一条语义）。
  ★ smoke 全绿也抓不到 —— 这是平台口径，只有真机能验。
- **尚未做**：P1-7 毛玻璃（技术前置已就位：无边框后客户区=整窗，
  `UpdateLayeredWindow` 的位图才与窗口矩形对齐）；`HTMAXBUTTON` 快照布局
  （Win11 悬停最大化按钮弹布局菜单）；关闭按钮 hover 变红（要 Pressed/Hover 色阶
  接到 `Danger`）。

**P1-8 画布合成：毛玻璃（背景模糊）—— Stage 2a 已落地**
（`Canvas::blurBackdrop()` + `CAP_BACKDROP` + `BackdropFilter` 控件）：

- **改了执行序，理由写在这里**：原计划 Stage 2 直接做窗口**逐像素** alpha
  （`UpdateLayeredWindow` + DWM 亚克力）。重估后先做**背景模糊**，三条依据：
  ① Stage 1a 的 `LWA_ALPHA` 已经能给"整窗均匀半透明"，ULW 多出来的只有
  **逐像素**控制，而逐像素透明的**唯一价值**是"面板透、文字不透" ——
  没有模糊时那就是一块廉价透明板，可读性反而更差；
  ② 亚克力（`DWMWA_SYSTEMBACKDROP_TYPE`）只是**模糊源**，alpha 才是**开口**；
  没有开口，亚克力一点都看不见 ⇒ 反过来，模糊原语才是承重件；
  ③ 应用内背景模糊**不碰平台上屏路径** ⇒ 与 ULW 的风险彻底隔离，且
  Win10/Win11 通用（不依赖 DWM 合成策略）。
- **契约**：`Canvas::blurBackdrop(float $sigma, Rect $rect)` —— **一次性操作，
  不是 push/pop 对**。做成一对的话"层里到底有什么"有歧义（子内容画在层里会被
  一起模糊），而不进栈深度计数也就不会污染 `DisplayList::assertBalanced()`。
- **Skia 配方**：`saveLayer(kInitWithPrevious)` + **立刻** `restore()` ⇒ 层里只有
  背景，子内容天然清晰。**边缘用 `SkTileMode::kClamp`**（Flutter 同款）而不是
  "把层 bounds 放大 3σ"：放大版靠外层裁剪收口，而 `saveLayer` 的种子内容会被
  当前裁剪一起裁掉 ⇒ 放大出来那一圈是空的，褪色暗边原样还在（第一版就这么错的）。
- **Raster 是空实现**（`CAP_BACKDROP` 置位但近似为"不模糊"）：与 `CAP_LAYER`
  的 alpha 乘数栈同一档"Extended 允许降级近似"。**没有**走 `CAP_SHADOW` 的
  响亮失败 —— 模糊是质感，不是层次信息，缺了面板仍然读得出"浮在内容之上"。
- **控件**：`BackdropFilter::make($child)->sigma(16)`（`RenderBackdropFilter`
  extends `RenderProxyBox`）。顺序钉死为「裁到内容盒 → 模糊 → 画子」，
  三条不变量都有 smoke 覆盖（含带内边距时"矩形跟内容盒走而不是边框盒"）。
- **真机判据**（`apps/showcase` 第 22 页「毛玻璃 Glass」，默认首页点导航可达）：
  ① 面板内的 12 个色块边缘**完全消失**、面板外的色块边缘**仍是硬的**、
  面板上的文字**清晰**（`_build/glass-panel.png`）；
  ② sigma 4 / 12 / 28 三连梯度肉眼可分级（`_build/glass-sigma.png`）；
  ③ 无 `Errors.log`。
- **撞出并修掉的两处应用侧溢出**（真机第一次跑就 Fatal，smoke 的 showcase 夹具
  同款复现）：`Grid` 在松约束下铺满可用宽度 ⇒ 三个 `Stack` 各占整行、`Row`
  "共需 5004 / 可用 1660"；以及行高由内容撑时小尺寸格子"共需 105.2 / 可用 70"。
  修法都是给容器定尺寸（`SizedBox` / `Grid::cellHeight`），不是给叶子定尺寸。
- **尚未做**：窗口**逐像素** alpha（`UpdateLayeredWindow` + 半透明根层清屏色，
  让面板能透出**桌面**而不只是应用内容）；`Opacity` 独立控件（`->opacity()`
  修饰已覆盖，独立控件只是便利性）；混合模式；圆角模糊（要 `Path::addRoundRect`
  或圆角裁剪，当前模糊区是矩形）。

**P1-9 窗口逐像素 alpha —— Stage 2b 已落地**
（`RenderSurface::setBackground()` + `drift_win_set_pixel_alpha` + `UpdateLayeredWindow`）：

- **契约放在表面上，不在窗口上**：`setOpacity()` 改的是**合成器**怎么合成已画好的
  像素（`LWA_ALPHA`，像素一个不变）；底色改的是**画出来是什么像素**。两者分属不同
  层，底色一旦挪进 `WindowHost`，`SurfaceProvider` 这层"后端可换"就漏了。
  ⇒ `RenderSurface::setBackground(Color)` 是**接口方法**（7 个实现同步）。
  `a < 255` ⇒ 该窗口逐像素透明。
- **模式是"声明"不是"每帧从像素里猜"**：`drift_win_set_pixel_alpha(hwnd, bool)` 由
  表面在改底色时调一次。理由：模式切换要 `SetWindowLongPtr` + `SWP_FRAMECHANGED`
  （DWM 重建表面），是重操作；从像素猜会让"某帧碰巧全不透明"触发无谓往返，还会让
  模式抖动成为默认行为。
- ★★ **`set_pixel_alpha` 不动窗口样式**（第一版动了，是个坑）：只置位标志，真正挂
  `WS_EX_LAYERED` 推迟到**第一次 present**。`WS_EX_LAYERED` 挂着而 LWA / ULW 一个都
  没调过时窗口**可能完全不显示**，而 `setBackground()` 发生在首帧之前 ⇒ 那会闪一下。
- **LWA 与 ULW 互斥**，切换的**唯一**合法方式是摘掉再挂上 `WS_EX_LAYERED`
  ⇒ 壳里有个三态 `g_layerBind`（none / lwa / ulw）。`set_opacity` 在逐像素模式下
  **只记值不调 API**（调 `SetLayeredWindowAttributes` 会把 ULW 的位图当场踢掉），
  透明度由 `BLENDFUNCTION.SourceConstantAlpha` 每次 present 带上 ⇒ **两种模式语义一致**。
- ★★★ **`UpdateLayeredWindow` 的 `pptDst` / `psize` 不能都传 NULL**（本机实测）：
  两个都 NULL 时它**返回成功但画面一个像素都不变** —— 现象是"窗口完全不可见、
  `Errors.log` 干净、`g_frame` 里像素全对（alpha 全非零、内容像素正确）"。
  MSDN 说"位置/尺寸不变时可以为 NULL"，那条路在本机 Win10/11 上不生效。
  给的值就是**当前窗口矩形** ⇒ 既不动位置也不改尺寸（与 `WM_SIZE` 不打架）。
  这个坑花掉了整轮排查（`--screen` 抓屏幕 + `--region` 抓桌面基准才定位到）。
- **失败必回落 GDI**：`presentLayered()` 返回 bool；`WM_PAINT` / `WM_SIZE` 的 GDI
  贴屏判据是**绑定态**（`g_layerBind != kBindUlw`）而不是"声明过" —— 否则 ULW 一失败
  就是"分层窗 + 没人喂内容" = 窗口凭空消失。
- **前提是无边框**（Stage 1b）：客户区=整窗，位图才与窗口矩形一一对齐。有标题栏时
  位图会整体上移一个标题栏 ⇒ `layeredPixelReady()` 要求 `g_frameless`，否则回落 GDI
  （应用侧只是"透明没生效"，不是黑屏）。
- **预乘口径终于统一**：`RasterCanvas` 的缓冲本来就是**预乘**（混合公式
  `dst = src·a + dst·(1−a)`），Skia 的 `N32` 也是预乘 ⇒ 两个后端都**只换 R↔B、
  不再乘 alpha**（再乘一次会让半透明区二次压暗）。`toRgba()` 的 docblock 已按事实改写
  —— 底色不透明时预乘 == 直通，所以 M1 阶段这个区别从未显形。
- **消费者**：`users` 组合根读 `DRIFT_WINDOW_BACKGROUND=#RRGGBBAA`（默认不变）。
- **真机判据**（`_build/ulw-shot.php` + 探针新增 `--screen` / `--region` / `--color`）：
  ① `#FF00FF`（不透明洋红）⇒ 屏幕上洋红占 **90.3%**（证明底色真到了屏幕、抓图抓得到）；
  ② `#FF00FF00`（全透明洋红）⇒ 洋红 **0%**，应用自己的顶条/按钮/文字在，**IDE 从
  空白处透出来**（`_build/ulw-transparent.png`）；
  ③ `#FF00FF80` ⇒ 50% 洋红压住整窗、背后代码隐约可见（`_build/ulw-half.png`）；
  ④ `DRIFT_WINDOW_OPACITY=0.55` 叠加 ⇒ 两种透明度语义同时生效
  （`_build/ulw-opacity.png`）；
  ⑤ ULW 下改尺寸（`--move` 到 700×520）⇒ 重排正确、透明保持、**无 runaway 缩小**
  （`psize` == 窗口尺寸，不会形成反馈环，`_build/ulw-resize.png`）。
- **★ 圆角在 ULW 下"仍然生效"这个结论是错的（已证伪，纠正 `059731d`）**：
  原先抓窗口**左上角** 6×6 看到壁纸就下了结论 —— 但无边框窗的左上角是**应用自己的
  顶条**，那个断言无法证伪"方角"（壁纸本来就不该出现在那里，出现说明的是别的事）。
  重测**左下 / 右下各 8×8**（方角与圆角的差异区）：不透明洋红底下两块都是
  `color_pct=100.00` ⇒ **方角**。结论：**Win10 上 `DWMWCP_ROUND` 是静默 no-op**
  （API 返回 `S_OK`、不报错、也不裁角），`059731d` 里那句"分层窗照样裁角、
  不需要自己画 alpha 掩码"**作废**。要圆角得自己在 ULW 的位图里做 alpha 掩码
  （或 Win11 上另验）。
- **★ 判据教训**：验圆角要抓的是**角上的圆弧区**（圆角成立时那块本该没有任何窗口像素）。
  无边框窗四角全是**应用自己画的内容**，左上角尤其容易取样点根本不在窗口里
  ⇒ 那次"抓到壁纸"其实什么都没证明。**先确认取样点在窗口矩形内**，再谈结论。
- **尚未做**：多窗口时 `g_frame` / `g_ulwBitmap` / `g_layerBind` 这几个进程级全局
  要改成 `map<HWND, …>`（Stage 3 已把"模糊桌面"补上，见下）。

### P1-10 窗口背后材质（DWM 亚克力）—— Stage 3 已落地

**为什么先探针、后接口**：`WS_EX_LAYERED` + `UpdateLayeredWindow` 与 DWM 背景效果都在
"替换窗口表面"这一层，能否叠加**没有任何文档**。结论未知之前写进公共接口，会得到一个
永远填不上的方法 ⇒ 先做 `drift_win_probe_backdrop()` 验，验成了才升格成契约。

**这台机器是 Windows 10 22H2（build 19045）**，这直接改写方案：`DWMWA_SYSTEMBACKDROP_TYPE`
要 Win11 22621+，**实测该属性返回失败、画面零变化** ⇒ Win10 上只剩**未公开**的
`user32!SetWindowCompositionAttribute`（accent 策略）。所以最终实现只有 accent 策略一条路，
Win11 分支**不进代码**（验不了的路径不发货）。

**契约**：`Drift\Platform\Backdrop`（enum：`Blur = 1` / `Acrylic = 2`，`value` 就是桥的
ABI 编号，`0` 留给"关"）+ `WindowHost::setBackdrop(int $h, ?Backdrop $b)`。
`null` = 关（与 `CursorShape` 同一套约定：`null` 是"不声明"，枚举 case 是明确的决定，
所以**没有** `None` 这个 case）。

**真机判据**（`_build/backdrop-shot.php` + 探针新增 `--detail`）：
`detail` = 相邻像素平均绝对差 —— 模糊**不改平均色**、只压高频，所以它是这件事唯一
可证伪的指标（"看着糊"不是判据，同一天我已经靠目测把方角当成了圆角）。

判据取**相对同一块矩形的桌面基准**（背景窗口先激活、被测窗口后建 ⇒ 三次跑同一背景）：

| 配置 | `detail` | 占基准 |
|---|---|---|
| 桌面基准（窗口不在） | **4.80** | 100% |
| `off`（对照） | **4.65** | 97% —— 只是被应用内容盖掉一点 |
| `acrylic` | **1.57** | **33%** |
| `blur` | **0.87** | **18%** |

两次独立运行读数**逐位相同**（4.65 / 1.57 / 0.87）。改尺寸（900×560 → 700×420）后
模糊仍在（无需重设）；与 `setOpacity(0.6)` 叠加两者同时生效、不冲突。
**结论：`UpdateLayeredWindow` 与 accent 策略可以叠加** —— 这是整件事的唯一决定性
未知数，答案是"能"。

**踩到的点**：
- ★★★ **桌面模糊只有"逐像素 alpha"这一条观察通道**：不透明窗口的每个像素都由应用画满，
  DWM 画在**背后**的模糊被整块盖住 ⇒ 设了也看不出来（不是失败，是看不见）。所以
  `setBackdrop` 的正常用法必须配带 alpha 的底色，这条写进了 stub 与接口的 docblock。
- ★★★ **`detail` 是相对量，背景不受控时判据不成立**：桌面切到空白页时基准只有 ~1.5，
  "模糊后"也是 1.5 ⇒ 看起来像"模糊没生效"。两次误判都是这个原因。
  ⇒ 取证脚本必须**先激活一个高细节的背景窗口、再启动被测窗口**（顺序反了就要靠
  `SetForegroundWindow` 抢前台，而它有前台锁 ⇒ 时灵时不灵：同一条命令一次抓到窗口、
  一次抓到桌面，`detail` 与基准**完全相等**）。
- ★★ **`GradientColor` 的 alpha 在高频上看不出强度**：`0` = 关；`0x01 / 0x20 / 0x99 / 0xFF`
  四档同背景连测 `detail` 一致。亮度是否随 alpha 变**未做受控测量** ⇒ "强度"这个旋钮
  **不发货**。RGB 在同背景的前后两次抓图里改红改黑画面一致 ⇒ 本机观察不到色调效果。
  **所以不接受色调参数**，固定 `0x99000000`。
- ★ **轻模糊不需要色调**：`BLURBEHIND` 在 `GradientColor = 0` 时照样生效。
- ★ `0`（关）只验了"新建窗口上调用不出错、画面与不设一致"；**运行期从有到无的切换未验**
  （本仓暂无切换的消费者）。

**待做**：Win11 的 `DWMWA_SYSTEMBACKDROP_TYPE`（本机验不了）/ 圆角模糊（ULW 位图里做
alpha 掩码）/ 多窗口化（`g_layerBind` 等要按 HWND 分槽）。

---

## 更新 2026-10-26 晚：P1 三入口收敛为 `WindowMaterial`

上文描述的三个外观入口（`RenderSurface::setBackground()` 的逐像素 alpha /
`WindowHost::setOpacity()` 的整窗 alpha / `WindowHost::setBackdrop()` 的背后材质）
是**收敛前**的形态，保留作历史。现况：

- **新 API**：`WindowMaterial`（`src/Platform/WindowMaterial.php`，不可变：`background` /
  `opacity` / `backdrop`，`wantsPixelAlpha()` 由底色 alpha 推出）+ `WindowMaterialCapabilities`
  （位掩码能力，位值即 ABI）。`WindowHost::applyMaterial(int, WindowMaterial)` 一次交完整快照；
  `materialCapabilities(int)` 回答"平台能不能"。
- **删**：`setOpacity()` / `setBackdrop()` 与桥 `drift_win_set_opacity` / `drift_win_set_pixel_alpha` /
  `drift_win_set_backdrop`。合成 `drift_win_apply_material(hwnd, bool pixelAlpha, float opacity, int backdropKind)`
  + `drift_win_material_caps(): int`。
- **耦合从"注释"变"契约"**：① 逐像素 alpha 要无边框 ⇒ `applyMaterial()` 硬 Fatal；
  ③ 整窗 alpha（LWA）与逐像素（ULW）互斥 ⇒ 壳一次快照按值调和，与调用顺序无关；
  ② 背后材质要 alpha 底色才有观察通道 —— 仍由应用组装时保证。
- **像素仍归表面**：`Color` 不过桥。应用侧两步（`$surface->setBackground(...)` +
  `$window->applyMaterial(...)`），示例见 `WindowMaterial` 类注释。
- **行为变更**：带 alpha 的 `DRIFT_WINDOW_BACKGROUND` 缺 `DRIFT_WINDOW_FRAMELESS=1` 现在
  当场 Fatal（原为静默回落 GDI）。

