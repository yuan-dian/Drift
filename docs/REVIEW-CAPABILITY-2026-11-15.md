# 能力面对齐 review（2026-11-15）

基线：HEAD `5fcf07e`。范围：`src/` 403 文件、`cpp-src/win32/`、`tools/`、8 个 app、`docs/`。
对照：Flutter / Qt / WPF / WinUI / Compose / Chromium / AG Grid，框架侧口径取自官方文档（链接见 §4）。

**本轮不改任何既有文件**（新增的只有本报告）。交付物是这张能力地图与分级清单，不是补丁。

> **后续（同日，两轮）**：
> ① 按 §7 顺序落地 P0-3（业务状态恢复 + 导航栈），见 `DEBT §79`。
> ② 落地 P0-1，但**先复核再动手**：§5 列的四条 P0-1 落点里只有「密码遮蔽」与
>   「输入格式化」是真缺口；「自动补全」已由 `ComboBox` 覆盖、「校验」已由
>   `error()` 受控模型覆盖、「IME 预编辑串」是 `drift_win32.cc` 明文裁决不做。
>   详见 `docs/DESIGN-FORM-P0-1-2026-11-15.md` 与 `DEBT §80`。
>   **§7 的「P0-1 → P0-2 同轮」也已按新事实改为分轮**：P0-1 一行 `SemanticsNode`
>   都没碰，分轮不会接两遍线。
> ③ 落地 P0-2 的**能力声明面 + 事件面**：`simplePattern()` 改分派表、补容器
>   `ISelectionProvider`；语义节点加 `hint` / `automationId` / `acceleratorKey`；
>   `driftUiaApply()` 按 key diff 发属性变更与 TextChanged。原 §5 的
>   「`ITextProvider` 薄实现」是错判（它要 `ITextRangeProvider` 全套字符级 API），
>   `IGrid` / `IScroll` 也缺数据面 ⇒ 三者推迟。详见
>   `docs/DESIGN-UIA-P0-2-2026-11-15.md` 与 `DEBT §81`。
> 七条 P1 仍未动；下表的差距判定在动手前仍应在当天 HEAD 重 grep。

---

## 0. 结论先行

1. **表现层不是短板**。143 个顶层控件（含子目录 153）、Flex/Stack/Wrap/Table/Grid、裁剪/图层/渐变/阴影、
   CustomPaint、Skia+Raster 双后端、自排版+IME 候选窗定位、三树架构 —— 与 Flutter 同构，够用。
   11-14 已判过「控件不够是假象，真问题是能力不可发现」，本轮复核成立。
2. **差距集中在三个面**，都不是「再加几个控件」能解决的：
   - **应用模型**：没有跨会话恢复（只有窗口几何）、没有导航栈/参数/守卫，国际化整片为零；
   - **表单与无障碍最后一公里**：密码框写不出来、校验/格式化/补全全在应用侧、IME 只取提交串，
     UIA 只分派 6 个 Pattern（**11-10 的「Windows 满配」是错判**）；
   - **开发者运行时工具**：没有运行时检查器 / 布局浏览器 / 帧时间线 / 性能 HUD，热更是丢状态的进程重启。
3. **11-10 的横向对比矩阵已有 4 处闭合**（README / CI / CHANGELOG+VERSION / 部分 CLI），
   1 处半闭合、1 处需修正、若干口径过期。**照旧清单往下做会做错事**，§2 是新的起点。
   （该稿小节标题写「十八维」，表内实为 **22 行**；本文按表内行数称「22 维」，差异本身也是口径腐烂样本。）
4. **最值钱的一条是口径修正**：`uia.cc:525-555` 只按 ControlType 分派 6 种 Pattern，
   而微软官方列了约 30 种 provider 接口，且明确 `IValueProvider` 与 `ITextProvider` 应并存。
   这不是「少一个功能」，是**评估结论本身失真**——照着「满配」的判据排优先级会一直漏。

**三条 P0**：表单最后一公里、UIA Pattern 与事件面、业务状态恢复 + 导航栈。

---

## 1. 口径（四条）

- **先核旧结论**：仓库的文档 / DEBT 台账会腐烂（11-14 刚抓到权威文档列不存在的基类）。
  凡「文档说缺 / 说有」一律回代码 grep；§2 与 §8 是这轮的复核产物。
- **区分「缺失」与「已裁决不做」**：`DEBT` 里有一批零消费者能力被明文划掉（fling、嵌套滚动、
  i18n、剪贴板多格式、托盘、增量布局…）。它们仍缺，但**不是这轮的待办**；§6 给出重开条件。
- **分类只有三种**：`缺失`（完全没有，且消费者存在）/ `需增强`（有但不完整）/
  `需优化`（够用但口径、性能、手感可改善）。另有 `已足够` 与 `已裁决` 两类，明确标出别动。
- **判据不能与实现同源**：每条建议都配一条可观察判据（§5），且要能人为变异成红。
  判「密码框」不查 `obscure` 字段设没设，而是查像素与 UIA Value。

---

## 2. 旧结论复核：11-10 → 11-15

来源 `docs/RELEASE-READINESS-2026-11-10.md`。「闭合」指 HEAD 上代码确实在。

| 11-10 结论 | HEAD 实况 | 判定 | 证据 |
|---|---|---|---|
| 无根 README / 无 CI / 无 CHANGELOG / 无 VERSION | 四者齐备，3 个 workflow，版本四向对账进 V2 | **已闭合** | `README.md`、`CHANGELOG.md`、`VERSION`、`.github/workflows/{ci,deps-build,release}.yml`、`tools/version.php` |
| 无统一 CLI | `bin/drift` 20 个子命令；但 `doctor`/`fetch-deps`/`pack-deps`/`release-deps`/`version` **未收进**，无 `create` | **部分闭合** | `bin/drift:47-74`；实测 `php bin/drift doctor` → exit **2** |
| 无 DevTools | 仍无运行时检查器；`tools/inspect.php` 反而被 S4 删掉，只剩离线 `Inspect/Snapshot.php` 四树 JSON + `golden-report.php` | **未闭合** | `DESIGN.md:110`、`tools/Inspect/Snapshot.php:23-49` |
| 热更 400ms 轮询重启 | 机制原样（`usleep(400000)` + `taskkill /F` + 重建，状态全丢） | **未闭合（已文档化为裁决）** | `tools/drift-dev.php:39-40,444-452`、`DEV-MODE-2026-11-05.md:49-55` |
| 无高对比度 | 11-15 `212e913` 落地 HC 系统跟随 + 用户系统色 + 主题扩展缝 | **已过期（结论反了）** | `src/Theme/SystemAppearance.php`、`ThemeData::forAppearance()`、`Win32Screen.php:80-96`、`Application.php:1643-1655` |
| 无状态恢复 | **只有窗口几何持久化**（x/y/w/h/max，v2 槽位）；业务/表单/滚动/导航状态仍无 | **部分过期，需改口径** | `src/App/WindowStateStore.php:34,85,154`、10-18 已入库 `4018bf2` |
| UIA 「Windows 满配」 | 只 6 个 Pattern、3 类事件、无属性变更事件、无 hint/AutomationId | **错判，需修正** | `cpp-src/win32/uia.cc:525-555,838-891`；§3.4 |
| 控件约 110 个 / 覆盖 60–70% | 顶层 143 / 含子目录 153；11-14 已订正 | **数字过期** | `ls src/Widget/*.php \| wc -l` |
| 18 项闸门 / 21 项 verify | `verify --list` = **20 step**（10 static + 6 behavior + 4 device）；`corpus` 是展示行不进 `--json` | **口径过期** | `tests/Verify/VerifyPlan.php`、`verify.php:199-203` |
| 无 `Future::timeout` | 仍无；只有 Job 级 `sweepTimeouts()` 与 curl timeout，三后端口径不一 | **仍成立** | `src/Async/Future.php`、`src/App/TimedJob.php:29` |
| 无 i18n / RTL | 仍为零（含 bidi 明确要 ICU） | **仍成立，但已裁决** | `src/Text/Utf8.php:13`、`TtfFace.php:39`；`DEBT:3599` |
| 平台只 Win32 / 无安装器签名自动更新 | 仍成立 | **仍成立（发布问题，非能力面）** | `src/Platform/` 仅 `Win32/`；`CHANGELOG.md:49` |
| 剪贴板只 `CF_UNICODETEXT` / 托盘 M2+ | 仍成立 | **仍成立，且已裁决** | `clipboard.cc:42,82,96`、`Win32Platform.php:165-167` |

---

## 3. 能力面总表

记号：● 已足够 / ◐ 需增强 / ○ 需优化 / × 缺失（有消费者）/ ⊘ 已裁决不做。

### 3.1 控件 · 布局 · 绘制

| 能力 | 现 | 证据 | 类 | 框架对照 |
|---|---|---|---|---|
| Flex / Stack / Wrap / 约束 / 内在尺寸 | ● | `src/Widget/{Flex,Stack,Wrap,LayoutBuilder}.php`、`Layout/RenderBox.php:389-445` | 已足够 | Flutter `BoxConstraints` 同构；「配置变了重造盒」是更简单的 AOT 取舍，11-14 已裁决不对齐 `updateRenderObject()` |
| Grid / Table 基础 | ◐ | `Grid.php:30` 自述等宽切块；`Table.php` 排序/选择/编辑/列宽拖拽全有 | 需增强 | AG Grid / `QAbstractItemView`：无跨行列、无自动放置、**无固定列**（全库 `pinned/frozen/sticky` 0 命中） |
| 百分比尺寸 / baseline 对齐 | × | `Align.php:31` 不支持 widthFactor；`FlexAlign.php:9-10` 明写留到 M3 | 缺失 | Flutter `FractionallySizedBox` / `CrossAxisAlignment.baseline` |
| 局部重排 RelayoutBoundary | ⊘ | `RenderObject.php:17` 全树重排；`DESIGN-INCREMENTAL-LAYOUT-VERDICT-2026-11-04.md` 量过三数后裁决不立项 | 已裁决 | Flutter 有边界；12k 节点余量 4.8× 下主动不做 |
| 裁剪 / 图层 / 渐变 / 阴影 / 模糊 | ◐ | `Rendering/Canvas.php:48-117`、`LayerCache.php:52-66`、`WidgetModifiers::repaintBoundary()` | 需增强 | Raster 后端无真模糊阴影、BackdropFilter 退化为不模糊（`Canvas.php:115`）；paint 侧有重绘边界，别把「无 damage rect」读成「完全无局部机制」 |
| 变换 / 离屏缓存 | ◐ | `WidgetModifiers.php:412` 明写 transform 不参与布局；`LayerCache` 是命令录制不是位图 layer | 需增强 | Flutter `Transform` / raster cache |
| SVG / 图片缓存 | ◐ | `ImageLoader.php:101-144` 静态 map + `clearCache()`，无 LRU/字节上限；无 `SvgDecoder`，图标是硬编码 Path | 需增强 | Flutter `ImageCache`；Qt `QSvgRenderer` |

### 3.2 滚动 · 动画

| 能力 | 现 | 证据 | 类 | 框架对照 |
|---|---|---|---|---|
| 滚动条 / 横向 / 跟手拖动 | ● | `ScrollBarHost.php`、`ScrollInputRouter.php:195-275`、`RenderScrollView.php:134` | 已足够 | Qt `QScrollBar` |
| 编程式滚动 | ◐ | `ScrollController` 只有 `jumpTo/jumpToBottom/jumpToTop`，`animateTo` 全库 0 命中 | 需增强 | Flutter `ScrollController.animateTo` |
| 惯性 fling / 弹簧 / 交错 | ⊘ | `ScrollMotion.php:31-35` 明写不做；全库 `Spring`/`stagger` 0 命中 | 已裁决 | Flutter `SpringSimulation` / `Interval`；裁决理由「无触摸输入、滚轮是离散事件」 |
| 虚拟化变长子项 / 保持位置 | ◐ | `LazyList.php:14-16` `itemExtent` 必填；`LazyListState.php:320-373` 有跨重建锚点，但 `keepAlive/restoration` 0 命中 | 需增强 | Flutter `SliverList` + `PageStorage` + `KeepAlive` |
| 隐式动画 / 曲线库 | ◐ | 只有 `AnimatedOpacity` / `AnimatedScalar`；`Curve.php:21-23` 仅 4 条二次多项式（为像素一致性主动收窄） | 需增强 | Flutter `AnimatedFoo` 家族、`Curves` |
| 显式控制器 / 中断 | ● | `AnimationController.php:75-229`、`AnimatedValue::snapTo()` | 已足够 | Flutter `AnimationController` |
| 帧后一次性回调 | × | 全库 `addPostFrameCallback` 0 命中；只有 `State::tickFrame/requestFrame` | 缺失 | Flutter `SchedulerBinding.addPostFrameCallback` |
| Hero / 页面转场 | ⊘ | `DEBT:3601` 零消费者 | 已裁决 | Flutter `Hero` |

### 3.3 输入 · 焦点 · 快捷键

| 能力 | 现 | 证据 | 类 | 框架对照 |
|---|---|---|---|---|
| Tab 序 / 方向键几何导航 / 陷阱 / 恢复 | ● | `WidgetFocus.php:23-81`、`FocusManager.php:126-193,525-621`、`OverlayManager.php:425,455,494-510` | 已足够 | 比多数框架细；无 `FocusScope.of()` 是 §9.1 的明确否决，不是缺口 |
| 快捷键表 | ◐ | `AcceleratorTable.php:47-96` 是 `KeyChord → string 命令名` 扁平表，冲突即 Fatal；菜单 `MenuItem::chord()` 只显示、不与表联动 | 需增强 | Flutter `Shortcuts→Intent→Actions`：按焦点作用域覆盖、`Action.isEnabled`；Qt `QShortcutMap` 三种上下文；WPF `CommandBinding.CanExecute` |
| 通用焦点作用域 | ◐ | 只有模态级 `focusTrap`，无按组遍历策略 | 需增强 | Flutter `FocusTraversalGroup` / `FocusTraversalPolicy` |
| KeyUp / 自动重复 / 物理键码 | × | `KeyEvent.php:20-21` 明写只有按下；`KeyChord` 无 Meta/左右区分 | 缺失 | Qt `QKeyEvent::isAutoRepeat()`、Flutter `Logical/PhysicalKeyboardKey` 双表示 |
| 手势识别器 | ◐ | `GestureArena` 只有 tap + drag 两成员；无长按、无 pinch/zoom、无多指 | 需增强 | Flutter 十余种 recognizer + `GestureArenaTeam` |
| 指针捕获 / 拖放 | ◐ | 壳不调 `SetCapture`（`drift_win32.cc:1132-1135`），拖出窗口即取消被 4 处注释自承；文件拖入走老式 `WM_DROPFILES`，无 OLE `IDropTarget` | 需增强 | WinUI `PointerPoint`、WPF `Mouse.Capture`、Qt `QDrag`+`QMimeData` |
| 触控 / 笔 / 压感 | × | `WM_TOUCH`/`WM_POINTER`/`WM_GESTURE` 全库 0 命中；`PointerEvent` 无 pressure/deviceId | 缺失 | WinUI `PointerPointProperties.Pressure`、WPF `StylusPoint` |
| 事件路由 tunnel/bubble | × | `InputRouter.php:100-144` 固定 ⓪→④ 环；但 `Application::bubble()` 是**命令** DFS、files-dropped 沿父链找最近声明者 | 缺失（口径需精确） | WPF RoutedEvent；不能读成「完全没有任何冒泡」 |

### 3.4 文本 · 表单 · 无障碍 · 系统

| 能力 | 现 | 证据 | 类 | 框架对照 |
|---|---|---|---|---|
| 单行/多行 · 选区 · 按词移动 · 复制粘贴 | ● | `TextField`/`TextArea`、`TextDraft.php:20-30,437-445`、`TextKeymap.php:91-147`、`TextEditingBridge.php:164-207` | 已足够 | Qt `QLineEdit`/`QPlainTextEdit` |
| 撤销/重做（文本框内） | ● | `TextDraft.php:39-42,400-435,577-583` 快照栈、上限 100、同类合并 | 已足够 | 比 Flutter 完整（Flutter 靠应用层 delta）；但**无应用级** `QUndoStack` |
| **密码遮蔽** | × | `TextField.php:47-57` 字段仅 value/label/placeholder/error/helper/maxLength/回调；`obscure` 全库 0 命中 | **缺失** | Flutter `obscureText`、WPF `PasswordBox` —— **任何登录框都写不出来** |
| 校验 / 格式化 / 自动补全 | × | `TextField.php:107-118` 自承「只显示应用给的那句，不自己跑校验」；`validator/formatter/completer` 0 命中 | 缺失 | Flutter `TextFormField` + `TextInputFormatter`；WinUI `AutoSuggestBox` |
| IME 预编辑串 | ◐ | 候选窗定位齐全（`drift_win32.cc:1237-1330`），但只取 `GCS_RESULTSTR`、`:1293-1294` 明写不读 `GCS_COMPSTR`；`TextInputClient` 组合区间接口整组无调用者 | 需增强（明确裁决） | Chromium `compositionstart/update`、Qt `QInputMethodEvent` preedit |
| 富文本编辑 | ◐ | `RichText.php` 只读展示 + span 点击；无可编辑富文本 | 需增强 | WPF `RichTextBox` / Qt `QTextEdit` |
| **UIA Pattern 面** | × | `uia.cc:536-553` 只 6 种：Invoke / Value / Toggle / RangeValue / SelectionItem / ExpandCollapse；**无** `ITextProvider` / `IScrollProvider` / `IGridProvider` / `ISelectionProvider` 容器 | **缺失** | 微软官方列约 30 种；DataGrid 控件类型**必须** `IGridProvider`+`ITableProvider`，`IValueProvider` 与 `ITextProvider` 互补 |
| UIA 事件 / 属性 | ◐ | `uia.cc:838-891` 只 raise 结构/焦点/选中三种；`UiaRaiseAutomationPropertyChangedEvent` 0 命中；`SemanticsNode` 无 hint / AutomationId / AcceleratorKey / LabeledBy / sortKey；客户端只订阅 20012 | 需增强 | WPF/WinUI 全事件；官方要求实现 `ITextProvider` 时 TextChanged 与 AutomationPropertyChanged **两者都要** |
| 高对比度 | ● | `SystemAppearance.php`、`ThemeData::withHighContrast()`、`ColorToken.php:94-110` | 已足够（Hover/Pressed 明确返回 null） | WinUI `ThemeDictionaries` |
| 剪贴板多格式 / 托盘 / OS 通知 / 电源会话 | ⊘ | `Win32Clipboard.php:13-17`、`Win32Platform::tray()` 返回 null；`Shell_NotifyIcon`/`WM_POWERBROADCAST` 0 命中 | 已裁决（零消费者） | Qt `QMimeData`、Electron `Tray`/`PowerMonitor` |

### 3.5 应用模型 · 导航 · 状态 · 异步

| 能力 | 现 | 证据 | 类 | 框架对照 |
|---|---|---|---|---|
| 关窗询问保存 | ● | `Window::setCloseGuard()`、`CloseRequestedEvent`、`Bootstrap.php:166` 已启用 | 已足够（11-10 漏记） | WPF `Closing` + `e.Cancel` |
| 多窗口 / DPI / 无边框 / 窗口材质 / 几何记忆 | ● | `WindowSet.php`、`WindowStateStore.php`、`WindowHost::setFrameless()`、`WindowMaterial.php` | 已足够 | WinUI `OverlappedPresenter`、Qt `saveGeometry` |
| **业务状态恢复** | × | 只有窗口几何；`Restoration/saveState/restoreState` 全 `src` 0 命中；`PageHostState::$page` 纯内存 | **缺失** | Flutter `RestorationMixin` + `RestorableProperty` + `restorablePush`；Qt `QMainWindow::saveState()` |
| **导航栈 / 返回 / 参数 / 守卫 / 深链接** | × | `src/Routing/` 仅 4 文件；`RouteTable::build(string $name)` 不收参数；`PageHostState` 用 `Offstage` 全量挂载保活，无栈 | **缺失** | Flutter Navigator 2.0 / Compose Navigation / WPF `NavigationService` |
| 生命周期四态 / 单实例 / 命令行参数 / 崩溃恢复 | × | 只有失活事件；`AppLifecycle`/`CreateMutex`/参数模型 0 命中；`crash_report.cc` 只落本地日志 | 缺失 | Flutter `AppLifecycleListener`、Electron `requestSingleInstanceLock` |
| Subject / Equatable / 状态提升 / 显式 DI | ● | `State/Subject.php`、`Equatable.php`、`App/AppScope.php`；`BuildContext.php:22-23` 否决 `of()/find()` | 已足够 | Flutter `ValueNotifier`；不做自动依赖收集是有据判死 |
| 数据绑定 / 表单状态 / 应用级撤销栈 | ◐ | 只有单向 `watch`/`select`；校验规则住应用；undo 只在 `TextDraft` 内 | 需增强 | WPF `Mode=TwoWay`、Qt `QUndoStack`（命令压缩 / clean state / `QUndoGroup`） |
| Future / 取消 / 错误传播 / 进度 / 三后端 | ● | `Async/Future.php:138-545`、`AsyncScope`、`AsyncCommand`、`CompletionQueue` | 已足够 | C# `Task` + `CancellationToken` |
| `Future::timeout` | × | 无；只有 Job 级 sweep 与 curl timeout，`Failure.php:10` 自承「404/超时靠字符串分辨」 | 缺失 | Dart `Future.timeout()` / C# `Task.WaitAsync` |
| 主题 / 深色 / HC / 扩展缝 | ● | `ThemeData`、`BuildContext::extension()`（11-15 新增） | 已足够 | Flutter `ThemeExtension` |
| i18n / locale / 复数 / RTL / 字体缩放 | ⊘ | 全 `src` 0 命中；`Utf8.php:13`、`TtfFace.php:39` 明写要 ICU | 已裁决（零消费者） | Qt `QLocale`+`QTranslator`、Flutter `Intl`+`Directionality` |
| 错误边界 / 日志 / 用户可见错误 | ● | `ErrorBoundaryElement.php`、`RenderErrorBoundary.php`、`Errors.php:35-40,151-170` | 已足够 | React Error Boundary |
| 错误边界复位 / 远程上报 | ◐ | 注释自承「烧断后不再重建，要恢复只能重新挂载」；`LogSink` 只有 SentrySink 示意 | 需增强 | React `resetKeys`、Electron `crashReporter` |

### 3.6 工具链 · DX · 测试 · 分发

| 能力 | 现 | 证据 | 类 | 框架对照 |
|---|---|---|---|---|
| `verify` V2 / lint / golden / 变异自检 | ● | 20 step、三态退出码、`tools/TestApp.php`（无窗口 `pump()`/`expectBox()`/`pixelAt()`）、`tests/Contracts/run-corpus.php` | 已足够 | 等价 `WidgetTester` + `tester.pump()`，且质量门禁是全仓最领先的一栏 |
| CLI 路由 / 脚手架 / doctor 修复 | ◐ | `bin/drift:47-74` 20 子命令；`doctor`/`fetch-deps`/… 未收进（exit 2）；无 `create`；`doctor` 只读不修 | 需增强 | `flutter doctor` / `flutter create` |
| **运行时 Inspector** | × | `DESIGN.md:110` 自承；`tools/inspect.php` 已被 S4 删除；`Inspect/Snapshot.php:23-49` 已有 elements/boxes/semantics/paint 四树 JSON，`golden-report.php` 已有「点节点↔像素矩形」联动，但都是离线/失败现场 | **缺失** | Flutter Inspector：select-widget mode、flex explorer、**constraints 下行 / sizes 上行**、highlight repaints；Chrome DevTools DOM 高亮 |
| 布局浏览器 / 帧时间线 / 性能 HUD / 状态检查 | × | `Snapshot` 的 `boxes` 段只有 w/h 无约束；`SurfaceProfile`/`FramePhase` 有数据但 10 个命中全是量具/bench，**无消费者**；`grep HUD src/` 0 命中 | 缺失 | Flutter DevTools frame chart + `PerformanceOverlay` |
| 热更状态保留 | ⊘ | `drift-dev.php:39-40` 明写跨进程重启、无 Store/Cell 状态不该保留 | 已裁决 | Flutter Hot Reload / Hot Restart 是两条命令；VS XAML Hot Reload 保树 |
| finders / 手势模拟 | ◐ | `TestApp.php:47-49` 自承不提供 `type()`；只有 tap/tapAt/scroll，无 `find.byText/byType` 组合、无 drag/fling/longPress | 需增强 | Flutter `find.text()` / `tester.drag()` |
| AOT 指纹 / 增量 / 离线包 / CI | ● | `drift-build.php:62,98-103`、`Build/StalePolicy.php`、`deps.lock.json`、`dist/drift-deps-win-x64.zip` | 已足够（增量需显式 opt-in） | Flutter engine.version pin、Gradle 增量 |
| 安装器 / 签名 / 自动更新 / 首个 release | × | `CHANGELOG.md:49` 明列不含；`git tag -l` 为空、`SKIA_REVISION=unpinned` | 缺失（发布问题） | MSIX / Squirrel |
| 首批 release 资产 | ◐ | 51MB 离线包已备好，但在线取包路径从未跑通，`README.md:221-222` 自承尚未发布 | 需增强 | 上轮 §77 已给两条命令，缺账号动作 |

---

## 4. 与成熟框架对照：借鉴什么，排什么坑

| 框架 | 可借鉴 | 别踩的坑 |
|---|---|---|
| Flutter `Shortcuts`→`Intent`→`Actions` | [官方文档](https://docs.flutter.dev/ui/interactivity/actions-and-shortcuts) 说明了为什么要有 Intent 层：键位定义在高层、动作实现在低层，**同一个键在焦点所在的不同子树映射到不同 Action**，且 `Action.isEnabled` 决定能否触发 | ⚠️ Flutter 自己[改版过 Actions API](https://docs.flutter.dev/release/breaking-changes/actions-api-revision)：把 `isEnabled` 从 Intent 移到 Action、按 Intent 类型映射、允许从 widget 树外调用（撤销架构需要）。**别照最早那版设计**；也别为它引入 `BuildContext::of()`（§9.1 已否决，Drift 可用 `AppScope` + 焦点 Element 作用域） |
| Flutter `RestorationMixin` | [官方](https://docs.flutter.dev/platform-integration/android/restore-state-android)：`RestorableProperty` 注册进 `restoreState()`；`ScrollView`/`TextField` 内建 `restorationId`；导航要换成 `restorablePush` 才恢复栈；[`ScrollableState::restoreState`](https://docs.flutter.dev/flutter/widgets/ScrollableState/restoreState.html) 连滚动偏移都注册 | 只做「存个 JSON」不够：滚动位置、表单值、导航栈要同一套 bucket，否则三个机制各存一份必然漂。Drift 的 `WindowStateStore` 已有版本化 kv + 槽位 + 容错降级，是现成底座 |
| 微软 UIA | [Control Pattern Interfaces](https://learn.microsoft.com/en-us/Windows/Win32/winauto/uiauto-cpinterfaces) 列约 30 个接口；[DataGrid 控件类型](https://learn.microsoft.com/zh-cn/dotnet/framework/ui-automation/ui-automation-support-for-the-datagrid-control-type) 要求 `IGridProvider`+`ITableProvider`；[Text/TextRange](https://learn.microsoft.com/en-us/windows/win32/winauto/uiauto-implementingtextandtextrange) 明确「实现 `ITextProvider` 的控件也应支持 `IValueProvider`，且 `TextChanged` 与 `AutomationPropertyChanged` 两个事件都必须支持」 | 别用「整树重推」冒充属性变更事件：读屏客户端订阅的是事件，不是快照；`probe-uia-state.py:285-325` 目前只订阅 20012，判据本身也漏 |
| Qt Undo Framework | [`QUndoStack`](https://doc.qt.io/qt-6/qundostack.html) 支持命令压缩、宏、clean state（驱动「未保存」标记）、`QUndoGroup`（多文档共一对撤销动作） | Drift 的 `TextDraft` 快照栈已经比 Flutter 完整，**不要重写文本框 undo**；缺的是把它抬到应用级，且要与 P0-3 的「未保存」守卫接上 |
| Flutter DevTools | [Inspector](https://docs.flutter.dev/tools/devtools/inspector)：select-widget mode、flex explorer 显示主轴/交叉轴/flex factor/**约束违规**、highlight repaints；[DevTools](https://docs.flutter.dev/devtools/overview) 把布局、帧时间线、CPU、日志收在一个运行时服务里 | 别再造第二个 `tools/inspect.php` 命令行工具：S4 已因无人用而删掉。要建就建**连着运行中进程**的面板，`Snapshot`/`golden-report` 的联动是现成前端 |
| WPF / WinUI / Qt（输入与系统） | WPF `RoutedCommand`+`CanExecute`、WinUI `PointerPoint` 统一指针通道、Qt `QDrag`+`QMimeData` 跨应用拖放、WPF `DpiChanged` 路由事件 | 别逐个补 `WM_TOUCH`/`WM_GESTURE`/`WM_TABLET`：WinUI 的经验是**一次上 `WM_POINTER`**，多点、压感、捕获一起解决；OLE DnD 也不是 `WM_DROPFILES` 加几个格式能补出来的 |
| AG Grid / `QAbstractItemView` | 固定列、变长行虚拟化、单元格级选中是数据表格基线 | Drift 的 `TableState` 已 2737 行，加固定列应与其重构同轮推进（§78 已判「不拆类」，但新能力别再堆进同一个方法簇） |

---

## 5. 分级清单（每条都带可观察判据）

> 判据沿用 `DEBT §40.1`：① 有消费者 ② 错了是静默的 ③ 决定能不能交付。
> 成本 S/M/L 是粗估，只用于排序。

### P0 — 能力阻断（现在写不出来 / 交付不了）

| # | 能力 | 最小落点 | 判据（不与实现同源） |
|---|---|---|---|
| **P0-1** | **表单最后一公里**：密码遮蔽、输入校验/格式化、自动补全、IME 预编辑串 | `TextField`/`TextArea` 加 `obscureText()` 与 `inputFormatters()`；`TextDraft` 增 composing 区间；`TextInputClient` 那组死接口接线；补全走 `OptionPanel` 浮层 | 无窗口 `TestApp` 输入 `abc`：golden 像素里**不出现** `a` 的字形，而 UIA `Value` 仍是 `abc`；变异「遮蔽时把 UIA Value 也换成圆点」必须红。IME 判据用真机 `probe-window.py` 注入中文候选，检查预编辑串在提交前可见、Esc 取消不留残字 |
| **P0-2** | **UIA Pattern + 事件面**：`ITextProvider`/`IScrollProvider`/`IGridProvider`/`ISelectionProvider`，属性变更事件，语义节点补 hint/AutomationId/AcceleratorKey | `uia.cc:simplePattern()` 按 ControlType 扩展；`UiaNode` 与 `SemanticsTree::toArrays()` 已是数据底座；`probe-uia-state.py` 扩订阅 | 外部 UIA 客户端（纯 ctypes，不 import 框架代码）向编辑框请求 `TextPattern` 并读到 selection/visible range；向表格请求 `GridPattern` 能按 (row,col) 取格；改值后**收到** PropertyChanged 事件。变异「删掉一个 pattern 分派」/「删掉 raise」各红一条 |
| **P0-3** | **业务状态恢复 + 导航栈**：`PageHostState::$page`、表单值、滚动偏移、选中态纳入 `WindowStateStore`；`RouteTable::build` 收参数，补栈/返回/守卫 | 复用 `WindowStateStore` 的版本化 kv 与槽位；与 `setCloseGuard` 的「要不要保存」接上 | **跨进程**：进程 A 填表单→切页→滚到 300→关闭；进程 B 冷启动后四项全复原（不能靠同进程变量或内存单例）。变异「persist 时漏写滚动偏移」必须红 |

> P0-2 的阻断级别取决于目标用户：若含读屏使用者或政企无障碍采购，是硬阻断；纯内部工具可降到 P1。
> **但「UIA 满配」这个结论无论哪种情况都要改**——它影响的是后续所有排期判断。

### P1 — 显著差距（每天可感知）

| # | 能力 | 最小落点 | 判据 |
|---|---|---|---|
| P1-1 | 运行时 Inspector（树浏览器 + 约束 + 帧时间线 + 性能 HUD） | 把 `Inspect/Snapshot.php` 的四树从「落盘」变成「服务」，复用 `golden-report.php` 的节点↔像素联动；`SurfaceProfile`/`FramePhase` 接上消费者 | 连着运行中窗口点一个 `Row` 子节点，面板显示收到的 min/max 约束与最终尺寸；变异「`boxes` 不导出 constraints」红。帧时间线要有至少 60 帧的阶段耗时，不能只有中位数量具 |
| P1-2 | 统一指针通道 `WM_POINTER` + `SetCapture` + OLE DnD + Scale recognizer | 壳层一次换通道，顺带解决多点/压感/拖出窗口；`DragController` 与 `GestureArena` 加 recognizer | 真机探针：拖到窗口外仍能收到 move/up；双指捏合改变缩放；文件从应用拖到资源管理器。变异「不调 SetCapture」红 |
| P1-3 | 快捷键 Intent/Action 层 + 焦点作用域 | `AcceleratorTable` 从 `Chord→string` 升到 `Chord→Intent`，`CommandRouter` 提供 `isEnabled`；菜单 `chord()` 与表联动校验 | 同一 `Ctrl+S` 在表单页与表格页触发**不同**动作；Action 禁用时菜单项灰掉且按键无效；重复绑定跨作用域不误报 Fatal |
| P1-4 | 滚动：`animateTo` + 变长行虚拟化 + 表格固定列 | `AnimatedScalar` 已在，`ScrollLane` 已在；`LazyList` 放宽 `itemExtent`；`TableColumn` 加 pinned 位 | 编程式滚动中途取样 offset 应单调递增而非一步到位；500 条变长行只创建视口内 Element；横向滚到底时首列几何不动 |
| P1-5 | 热更状态保留档（依赖 P0-3 的序列化设施） | 把 P0-3 的 bucket 传给重启后的进程；保留滚动/焦点/输入中内容，另立一道「全量重置」命令 | 改一行 PHP 触发热更后，输入框里的半截文字与滚动位置还在；`--hard-restart` 才清空。变异「spawn 不传 bucket」红 |
| P1-6 | CLI 收口 + 首个 release 资产 + `Future::timeout` | `bin/drift` 加 5 条路由与 `create`；发 `deps-v1` tag；`Future::timeout()` 统一回 `Failure` code | `php bin/drift doctor` / `fetch-deps` exit 0；新机器两条命令装起来；超时是机器可判 code 而不是字符串匹配 |
| P1-7 | OS 集成：多格式剪贴板、系统托盘、OS 通知、电源/会话事件 | `Win32Clipboard`/`Tray` 已有端口骨架 | 复制富文本到 Word 保留格式；锁屏后 `WakeReason` 能区分；`WM_ENDSESSION` 触发保存守卫 |

### P2 — 增强 / 优化（有账但不急）

- **表现力**：弹簧/交错动画、隐式动画家族、SVG 解析、图片 LRU、离屏位图 layer、变换参与布局。
- **控件**：富文本编辑、折叠面板、命令面板、拖拽重排、Tooltip 富内容。
- **可访问性细化**：字体缩放 / `textScaleFactor`、高对比度自定义绘制校验、键盘可达性静态 lint
  （`WidgetFocus.php:28` 自承守卫未落地）。
- **健壮性**：错误边界 `resetKeys`、远程崩溃上报、`Dialog` 三态（现在只有成功/取消）、`DpiChangedEvent`
  显式暴露（`Screen.php:18` 已引用一个不存在的类型）。
- **i18n / RTL**：季度级，要 ICU 数据与资源管线；失效条件见 §6。

---

## 6. 明确不重开（已裁决）与裁决失效条件

**这一节是本报告最容易被下一棒忽略的部分。** 下列能力仍缺，但 `DEBT` 已按「零消费者」明文划掉；
把它们列进待办就是照旧清单盲做。

| 能力 | 裁决原文 | 什么条件下才该重开 |
|---|---|---|
| 滚动惯性 fling / 弹簧 | `DEBT:2861`「无触摸输入，滚轮是离散事件 ⇒ 刻意不做」 | P1-2 引入 `WM_POINTER` 后**前提消失**，速度与惯性才有输入源；届时与 Scale recognizer 同轮评估 |
| 长按 / 多指手势家族 | `DEBT:2860,3602`「鼠标场景无消费者」 | 同上；触控通道落地后一次性评估，不要逐个补 |
| 嵌套滚动接力 / 吸顶 | `DEBT:3603`「没有嵌套容器需要接力」 | 出现真实嵌套滚动的页面（应用侧证据），不是「Flutter 有所以我们也该有」 |
| i18n / RTL / `Directionality` | `DEBT:3599`「五个应用全是中文单语，零消费者 ⇒ 凭空设计签名」 | **对外发布或出现非中文用户**（11-10 已判定届时会升级为阻断）；注意「五个应用」本身已过期，现在是 8 个，但结论不变 |
| 剪贴板多格式 / 托盘 | `DEBT:3606-3607`「图像/文件粘贴、托盘 0 消费者」 | 出现图片编辑器、后台常驻类应用；本报告 P1-7 已把它们按「融入 Windows 基本盘」重新提级，需重新裁决 |
| 增量布局 / 脏矩形 | `DESIGN-INCREMENTAL-LAYOUT-VERDICT-2026-11-04` 三数量过后不立项 | 12k 节点余量 4.8× 被真实场景吃穿；尺子已交出去，别凭感觉重开 |
| `BuildContext::of()` / 自动依赖收集 / `Form.of()` | `DEBT:1221,3126`、`docs/DEBT §9.1` 明文否决 | 不重开。P1-3 的 Intent 作用域要用 `AppScope` + 焦点 Element，不引入向上查找 |
| Hero / 页面转场 | `DEBT:3601` 零消费者 | P0-3 建好导航栈后再谈，转场才有「栈」可以挂 |
| 状态保留热更 | `DEBT:7340`「不做同进程状态保留热更（无 Store/Cell）」 | **P0-3 会造出状态序列化设施 ⇒ 这条裁决的部分前提会失效**；届时按 P1-5 用「重启后恢复」而非「同进程保活」重开，代价低得多 |
| Flutter `updateRenderObject()` / 统一 `make()`/`new` | 11-14 §4 已否决（字段级半同步是倒退 / 41+ 类 × AOT 全量重编） | 不重开 |

---

## 7. 推荐执行顺序（按 消费者 × 依赖 × 成本）

```
P0-3 恢复设施（造底座） ──┬─→ P1-5 热更状态保留
                          └─→ P1-1 Inspector（共用状态/树序列化观测面）
P0-1 表单 ──→ P0-2 UIA（都碰 TextInput / 语义树，同轮避免两遍接线）
P1-2 指针通道 ──→ 解锁 fling / 长按 / 多点的重开评估（§6）
P1-3 快捷键 → P1-4 滚动 → P1-6 CLI/timeout/release → P1-7 OS 集成
```

理由：

- **P0-3 先做**：`WindowStateStore` 是现成底座，且「关窗询问保存」已启用却无处可存 —— 这是**框架内部自相矛盾**，
  比缺任何一个控件都更该先闭合。它还顺带解除 P1-5 的裁决前提。
- **P0-1 与 P0-2 同轮**：两者都改 `TextInput` / `SemanticsNode` / `uia.cc`，分开做要接两遍线，
  且 UIA 的 `ITextProvider` 正好需要 P0-1 的选区与组合区间数据。
- **P1-2 之后才评估 fling/触控**：否则又会变成「按 Flutter 清单逐个补手势」。
- **P1-6 成本最低、最该早做**：`bin/drift doctor` 现在 exit 2，新人第一条命令就撞墙；
  首版 release 是上一轮 §77 已铺好路的账号动作。

---

## 8. 复核后排除的假阳性 / 需精确化的口径

1. **「UIA 满配」是本轮最重要的错判**（已核 `uia.cc:525-555` 原文，只有 6 个分支）。
   同时「无障碍」这一格不能写成「缺」：树导航、结构/焦点/选中事件、Invoke/Value/RangeValue/
   ExpandCollapse/Toggle/SelectionItem、真机 `probe-uia.py` 客户端都在，是**覆盖面**问题。
2. **「无 tunnel/bubble 事件路由」要收窄**：命令 `Application::bubble()` 是深度优先遍历、
   files-dropped 沿父链找最近声明者，属于局部冒泡；缺的是 WPF 那种固定相位的通用 RoutedEvent。
3. **「无状态恢复」应改成「只有窗口几何」**：`WindowStateStore` 10-18 就入库了，
   11-10 报告发布时已在，属漏计而非新增。
4. **「无 damage rect」不能推广到 paint 侧**：`RenderObject.php:18,35` + `LayerCache.php:52-66`
   + `WidgetModifiers::repaintBoundary()` 证明有重绘边界与命令缓存；缺的是位图离屏与整帧上屏优化。
5. **注释/文档腐烂三例**（会影响下一轮判断，本轮未改）：
   - `src/Platform/Clipboard.php:17` 写「M1 不实现，返回 null」，实际 `Win32Platform.php:147-150`
     返回实现且已被 `TextEditingBridge` 使用 —— **文档说缺，代码说有**；
   - `src/Platform/Screen.php:18`、`src/Input/ThemeChangedEvent.php:19` 引用了不存在的 `DpiChanged` 类型；
   - `ScrollMotion.php:15` 仍写「三份实现」，`ScrollLane.php:11` 与同文件 `:37` 写「四个」。
6. **数字口径**：控件 143/153（非 110）；`Application` public 69（非 72 ——
   `docs/00-README.md:50` 与 `14-组合根与装配.md:18` 仍是 72，上一轮只改了 §4 的 118 行）；
   `verify --list` 20 step，`corpus` 是展示行不进 `--json`；`TableState` 2737 行（11-14 登记 2731）。
7. **11-14 的「权威文档列幽灵基类 `BoxWidget`」已修**（`08-控件面.md:5,14,21,615`），
   别再照抄；但 `TooltipPanel.php:16` 的示例注释与 `tools/Preview/M2LayoutPage.php:28` 仍留着旧名，
   是那轮 13 个文件的漏网。
8. **别把「已裁决」误读成「已实现」**：高对比度是**真落地**；fling/i18n/托盘是**真没有**，
   只是现在不该做。两者在 §3 用 ● / ⊘ 分开标了。

---

## 9. 诚实边界

- **只读审计**：没跑 AOT 构建、没跑真机探针、没改任何文件。所有「缺失」判定是
  「接口无方法 + 全库 grep 0 命中 + 消费者存在」三条的合成，不等于绝对不存在；
  真要动手前，每条仍应在开工当天的 HEAD 上重 grep 一次（清单会腐烂）。
- **能力面 ≠ 发布就绪**：平台只有 Win32、无安装器/签名/自动更新，是**发布形态**问题，
  11-10 §5–§7 已判过，本轮只在 §2 登记闭合状态，不重复裁决。
- **框架对照取稳定 API 的官方文档**，但成熟框架的「有」不等于「适合 Drift」：
  单 UI 线程 + AOT + 无 ICU 的前提下，有些能力照搬会引入字段级半同步或第二份真相源（§4 逐条标了坑）。
- **成本是粗估**，不是排期；本报告不给时间承诺。
- **本报告自身会在下次提交后腐烂**。§2 的表是下一轮复核的起点，别把它当永久事实源。
