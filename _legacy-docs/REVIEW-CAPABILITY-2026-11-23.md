# 能力边界 Review 2026-11-23 —— 对比成熟 GUI 框架

> 对照对象：Flutter（三棵树/约束布局/Sliver/DevTools）、Qt（桌面控件/A11y/平台集成）、
> WinUI/WPF（焦点/命令/UIA/窗口）、Compose（状态/重组）。借鉴排坑，不以形似为目标。
> 事实来自源码与官方文档；「缺失」分两种：**明确裁决不做**与**尚未实现**，分开标注。

## 0. 结论先行

1. **核心 UI 编程模型已经对齐成熟框架**：Widget/Element/RenderObject 三棵树、显式 Key、
   BoxConstraints 单向下行、两段式 diff、State 生命周期、依赖采集式响应式——这些不是玩具实现。
   相对 Flutter 的实质差别是**全树重排（无增量布局/damage rect）**，属于主动裁决。
2. **表现层不是短板**：153 个 Widget 文件、约 70+ 面向使用者的控件、Table/Tree/Calendar/DatePicker
   等桌面重型控件齐备；主题系统是全仓最完整的子系统（token/深浅色/高对比/继承/扩展/动态切换）。
3. **差距集中在四个面**：平台覆盖（macOS 无、X11 桌面窗口能力已补齐但无 AT-SPI）、无障碍最后一公里（仅 Win32 UIA
   且 7 个 Pattern）、开发者运行时工具（无检查器、热更是重启）、生态与分发（无 i18n/包管理/安装器）。
4. **与上次 review（11-15）对比**：P0-3 业务状态恢复+导航栈**已闭合**；P0-1 表单闭合一半
   （密码遮蔽与格式化已实现，声明式校验与补全仍缺）；P0-2 UIA Pattern **未闭合**。
5. **总体定位**：核心架构与异步工程化达"可用技术预览"高水位；代差主要在**生态与动态可观测性**，
   不在 UI 编程模型。1.0 门槛的阻塞项是"全匿名构建可达"，不是能力缺失。

## 1. 口径

- 每项按「Drift 现状 / 成熟框架做法 / 优点 / 缺点」四列写；判定只分 `对齐` / `部分` / `缺失`。
- 「有接口」不算能力：必须区分生产可用、平台实现、测试夹具、注释规划。
- 能力位 ≠ 质量位：Canvas 的 CAP_* 只回答"敢不敢发 op"，Raster 的 approx 位是降级产出。
- 平台不对称单列（§4），不混进框架层能力表。

## 2. 能力面逐项对比

### 2.1 编程模型与架构

| 能力项 | Drift 现状 | 成熟框架 | 优点 | 缺点 | 判定 |
|---|---|---|---|---|---|
| 声明式 Widget + 不可变描述 | `Widget` 挂载时 `seal()`，封存后写 Fatal；`->key()` 身份 | Flutter Widget 同构 | 构建期误用立刻暴露，不靠纪律 | 无 Flutter `const` 构造的编译期常量折叠 | 对齐 |
| 三棵树 | `Widget`/`Element`/`RenderObject`，`createElement()` 唯一入口 | Flutter 三树 | RenderObject 与控件解耦，自绘自由 | 无独立 Layer 树，合成层靠 Canvas pushLayer | 对齐 |
| diff / reconcile | 两段式（前缀同步 + `(class,key)` 建桶），16 轮 settle | Flutter `updateChildren` | O(剩余) 而非 O(n²)；Key 语义明确 | 无 Element 复用跨层级移动（同 Flutter 限制） | 对齐 |
| State 生命周期 | `initState/onActivate/didUpdateWidget/build/dispose`，`watch()` 仅 initState | Flutter State | disposer 框架兜底，漏退订难发生 | 无 `didChangeDependencies` 式的环境变更钩子 | 对齐 |
| 布局算法 | `BoxConstraints` + `final RenderBox::layout()`，纯函数 | Flutter 同构，Qt 用 QLayout | 无界用 INF 而非 0；约束漂移可自检 | **全树重排**，无 incremental layout / damage rect（官方边界） | 部分 |
| 环境传递 | `BuildContext` readonly + 逐层传参 | Flutter `InheritedWidget` / Compose CompositionLocal | 依赖显式，无隐式查找 | 深层控件拿不到环境；`Application` 仍是门面式上帝类 | 部分 |

### 2.2 布局与控件

| 能力项 | Drift 现状 | 成熟框架 | 优点 | 缺点 | 判定 |
|---|---|---|---|---|---|
| 约束协商 | `BoxConstraints` 全套 API；`RenderFlex` 三阶段 + grow/shrink/basis 三轮冻结 | Flutter Flex 无 basis | CSS Flex 的 basis/shrink 也支持 | 无 `ConstrainedBox` 一等容器（全仓无命中） | 部分 |
| 布局容器 | Row/Column/Flex/Stack/Wrap/Grid/Align/Center/Padding/AspectRatio/LayoutBuilder/Offstage/Expanded/Spacer | Flutter 同名组件 | 覆盖桌面绝大多数排列场景 | `Stack` 无 zIndex、overflow 不真裁；Grid 是组合糖（固定列数、无跨列） | 部分 |
| 基础控件 | Text/RichText/Button/Checkbox/Radio/Toggle/Slider/TextField/TextArea/Image/Icon/Divider/Progress/Card | Flutter Material / Qt Widgets | 15+ 基础控件全覆盖，各有渲染对象 | Switch 因 PHP 保留字叫 `Toggle`（命名成本） | 对齐 |
| 桌面重型控件 | Table（选区/排序/编辑/列宽/双轴滚动）、TreeView、CalendarView、Date/TimePicker、Select/ComboBox（远程搜索）、TabView | Qt QTableView / WinUI DataGrid | Table 能力超过 Qt 常用场景；异步搜索有 Attempt 号防竞态 | 无 Accordion/Carousel/Stepper/Chip/Badge/Drawer/BottomSheet | 部分 |
| 浮层与导航 | Modal/Menu/MenuBar/ContextMenu/Tooltip/Snackbar/PopupPanel；RouteTable + NavigationStack（守卫/参数/持久化/页面保活） | Flutter Navigator 2.0 / WinUI 命令栏 | 浮层统一栈 + dismiss 注入；路由冷启动可复原 | 无 URL/deep-link（桌面场景取舍）；无可关闭标签条 | 对齐 |
| 表单 | `error()/helper()/inputFormatters()/maxLength()`，字段级展示齐 | Flutter Form + TextFormField validator | 密码遮蔽 `obscureText()` 已实现（11-15 P0 部分闭合） | **无 Form/validator/跨字段校验/统一聚合**（全仓无命中）；无自动补全 | 部分 |
| 响应式 | `WindowSize` 四档宽度断点，跨档才重建 | Flutter MediaQuery / LayoutBuilder | 档内不重建的性能取舍已钉测试 | 无 MediaQuery/字号缩放/横竖屏；**无 RTL**（P5 裁决不做） | 部分 |
| 滚动 | ScrollController（offset 级）/LazyColumn/LazyRow/LazyGrid；绝对序号 key + 锚点 + overscan + 约束漂移自检 | Flutter Sliver 家族 | 虚拟化设计细致，diff 只增删两端各一个 Element | **定高必需**（无变长行虚拟化）；无 fling（裁决不做）、无 nested scroll、无 `scrollToIndex` | 部分 |

### 2.3 渲染与图形

| 能力项 | Drift 现状 | 成熟框架 | 优点 | 缺点 | 判定 |
|---|---|---|---|---|---|
| Canvas 原语 | 17 个绘制原语 + 10 能力位；矩形/圆角/椭圆/线/路径/渐变/虚线/裁剪/图层/阴影/背景模糊/图片 | Flutter Canvas / Qt QPainter | Core 全覆盖；能力位降级契约有测试守门 | 路径无 cubicTo/arc；渐变无 sweep；SwiftShader 级 mask filter 无 | 部分 |
| 双后端 | RasterCanvas（确定性软光栅）+ SkiaCanvas（CPU raster），provider 两分支 | Flutter Skia/Impeller | 一套命令流两后端，能力位显式声明 approx | **Skia 也是 CPU 光栅**（Ganesh 被裁），无 GPU 后端 | 部分 |
| 后端一致性 | 能力契约表逐位登记 exact/approx/optional；golden 只跑 Raster | Flutter golden 跨后端同图 | 降级路径显式、新增位不登记就红 | 无双后端像素门；两后端画质不等价（alpha 层语义、虚线、阴影） | 部分 |
| 性能量具 | SurfaceProfile 三段计时（默认关）；直贴避免 swizzle + 4MB 跨界 | Flutter DevTools Timeline | 直贴把占 50%+ 的搬运开销压成一次 memcpy（Win32） | 量具默认关且无消费者界面；X11 仍需 `toRgba()` 跨界 | 部分 |
| 混合模式 | 无 `BlendMode`（全仓无命中） | Flutter BlendMode / Qt CompositionMode | 单一 source-over 让确定性简单 | 无法表达滤色/正片叠底等常见效果 | 缺失 |
| 像素确定性 | 一次量化、预生成字形位图、禁 pow/cos、premultiplied 契约 | Flutter golden 相对松散 | 软光栅确定性极强，md5 回归 + 44 份 expected.png | 真机 Skia 无像素级自动回归 | 对齐 |

### 2.4 文本 · 图片 · 效果

| 能力项 | Drift 现状 | 成熟框架 | 优点 | 缺点 | 判定 |
|---|---|---|---|---|---|
| 字体与度量 | 包内 NotoSansSC TTF + 位图兜底；TtfFace 解析 cmap/hmtx/OS2；确定性度量 provider 独立注入 | Flutter SkParagraph / Qt QFont | 中文/拉丁静态排版可用，测量可替换 | 真测量后端（SkParagraph）只有注释规划；变换下文字两后端不等价 | 部分 |
| 断行与富文本 | 贪心断行 + 基本 CJK 禁则；span 级颜色/字号/下划线/背景；省略号回退 | Flutter Paragraph / Qt QTextDocument | 富文本与光标命中（`indexAt/caretX/selectionRects`）完整 | 无完整 UAX#14；**无 BIDI、kerning/ligature**（明确不做）；无彩色 emoji | 部分 |
| 图片 | WIC/SkCodec 多格式解码；LRU 48MB/256 条；解码期 maxWidth 降采样；src/dst 裁剪 | Flutter ImageCache / Qt QPixmap | 内存纪律与缓存可观测（cacheEvictions） | 无动画图（GIF 只首帧）；Raster 缩放只最近邻 | 部分 |
| 阴影/圆角/模糊 | ShadowPainter 按 CAP_SHADOW 降级；圆角两后端完整；BackdropFilter 有生产用例 | Flutter BoxDecoration/BackdropFilter | 降级策略明确，不缺位就换近似画法 | Raster 的 blurBackdrop 空实现、drawShadow 响亮失败；无通用 mask filter | 部分 |
| 动画 | AnimationController（显式）+ AnimatedValue/AnimatedScalar/AnimatedOpacity（隐式），帧时钟接入 | Flutter Animation/Animated* | 曲线刻意用二次式避免 libm 末位差，保确定性 | 仅 4 条曲线、指数衰减，无弹簧/交错；**无页面过渡/Hero/shared element** | 部分 |
| 主题 | 17 语义 token × 明暗/HC/系统色；ThemeScope 嵌套继承；ThemeExtension；系统事件全树重建 | Flutter Theme / WinUI ThemeDictionaries | 全仓最完整的子系统，高对比是独立轴 | 切换无插值动画；无主题 token 的编译期校验 | 对齐 |

### 2.5 输入 · 交互 · 无障碍

| 能力项 | Drift 现状 | 成熟框架 | 优点 | 缺点 | 判定 |
|---|---|---|---|---|---|
| 指针 | 四相位 + 双击/三击（500ms/4px，PHP 判定）；滚轮/悬停；DragController + GestureArena | Flutter HitTest/GestureArena | 点击链可确定性测试；arena 设计与 Flutter 同构 | **无 OS 级 SetCapture**（拖出窗口即取消）；仅 tap/drag 两种 recognizer | 部分 |
| 键盘 | FocusManager（Tab 序/几何导航/陷阱/翻页）；AcceleratorTable 冲突即 Fatal；编辑键齐全 | WinUI/WPF 焦点环 + 命令 | 六环派发顺序唯一真相且钉测试；三态让位（Handled/Owned/Declined）精细 | **无 KeyUp、无长按自动重复**；无全屏语义 | 部分 |
| 文本输入 | TextField/TextArea + TextDraft（撤销栈/选区/码位）；纯文本剪贴板；IME 候选窗定位（IMM32/XIM 细致） | Qt QInputMethodEvent / Chromium composition | 框选四落点、中文按连续汉字选词；候选框照顾第三方输入法 | **IME 预编辑串明确不做**（TextInputClient 死接口）；剪贴板仅纯文本，无富文本/图片 | 部分 |
| 手势 | tap/drag + 应用内拖放 | Flutter GestureDetector / Qt QGesture | arena 消歧正确 | **触摸/笔/长按/swipe/fling/pinch 全仓无命中**（P2 裁决不做） | 缺失 |
| 语义树 | 13 角色、加性按需构建、full/coalesce 两档泵送、索引快照竞态处理 | Flutter Semantics / UIA / AT-SPI | 数据化、可测、结构变化立即 full 推送 | 13 角色偏少；无语义合并；无 TextPattern/Grid 等富 pattern | 部分 |
| 原生 A11y | Win32 UIA 1057 行：7 个 Pattern、结构/焦点/属性/文本事件、UIA 动作转投 | WinUI/Qt 全 Pattern | Win32 侧可用且有外部客户端探针 | **AT-SPI 未接（X11 no-op）、VoiceOver 无平台**；UIA 仍缺 IText/IGrid/IScrollProvider（11-15 P0 未闭合） | 部分 |
| 系统能力 | 原生文件对话框（Win32 IFileDialog / X11 zenity-yad）；应用内 Modal/Snackbar/Tooltip | Qt QFileDialog / WinUI 通知 | 模态阻塞时长可记账 | **系统托盘 Win32 返回 null、平台通知全仓无端口**；X11 助手依赖外部程序 | 部分 |
| 窗口 | 多窗口、几何持久化、最小化/最大化、自绘标题栏（Win32 完整） | WinUI/Qt 窗口体系 | 每窗独立浮层栈，句柄即身份 | **无全屏**；X11 无边框/光标已真接（本轮）；DPI 走 X11Dpr 每屏一值、主题变化已推 ThemeChangedEvent | 部分 |

### 2.6 状态 · 异步 · 应用模型

| 能力项 | Drift 现状 | 成熟框架 | 优点 | 缺点 | 判定 |
|---|---|---|---|---|---|
| 状态原语 | Cell（读即订阅）+ Computed（无 glitch）+ Bind（局部子树）+ 自动依赖采集 | Riverpod / Compose State | 无 Provider 沿树查找与运行时 NotFound；相等门控默认按值 | Store 是应用约定无框架强制；无 DI 容器；无跨字段一致快照原语（A18） | 部分 |
| 异步 | EventLoop 三态等待；Future（then/all/race/取消）；Fiber AsyncScope；线程/进程/同步三种 TaskHost；超时 sweep | Dart isolate / Qt concurrent | 取消能上溯根任务、迟到结果忽略、fanout-cancel 诊断；工程化超过多数 GUI 框架 | `--nano` 不支持 Fiber（本仓不做 nano） | 对齐 |
| 任务与恢复 | CompletionQueue 有预算 drain；RestorationStore 版本化；WindowStateStore 多窗槽位 | WinUI 会话恢复 | 冷启动复原 + 导航栈持久化（11-15 P0-3 已闭合） | 表单/滚动/选中态未全部纳入恢复桶 | 部分 |
| 路由 | RouteCatalog 注解（启动期一次反射）+ RouteTable + NavigationStack + PageHost 保活 | Flutter Navigator 2.0 | 守卫/参数/返回/序列化复原齐全 | 无声明式路由、无 deep-link | 对齐 |

### 2.7 开发者工具 · 测试 · 分发

| 能力项 | Drift 现状 | 成熟框架 | 优点 | 缺点 | 判定 |
|---|---|---|---|---|---|
| 静态检查 | 34 条 lint 规则 + 双向夹具自检 + 变异咬红要求 | Flutter analyzer + lint | 远超一般新框架；区分 error/warn；规则删改有判据守门 | 规则多意味着学习曲线陡 | 对齐 |
| 验证链 | `verify --full` 17/17；22 项 plan（static/behavior/device/corpus）；golden + IR 快照 + 像素基线 | Flutter integration_test + golden | 分层 T0/T1/T2，dev 绿不等于 AOT 绿；像素基线钉住重构 | golden 只覆盖 Raster，真机 Skia 无像素回归 | 对齐 |
| 热更 | `drift-dev` 每 400ms mtime+size，**跨进程重启**，靠 RestorationStore 恢复 | Flutter hot reload（保留状态） | 改 PHP 秒级生效；状态有恢复路径 | 状态不迁移；AOT 产物无热重载 | 部分 |
| 运行时检查器 | 无；`Inspect/Snapshot.php` 是 headless JSON，`InspectorApplication` 名不符实 | Flutter DevTools / Qt Designer | 四树快照可作底子 | **可视化树浏览/约束检查/帧时间线/性能 HUD 全无**（官方边界） | 缺失 |
| 脚手架/包管理 | 无 `create`、无第三方组件生态 | Flutter `flutter create` + pub.dev | — | 生态空白，组件全靠自造 | 缺失 |
| 构建/发布 | TypePHP AOT 单语言交付；deps-v2 双平台 599 条目校验；7 条 CI 工作流；同 SKIA_REVISION 真编 | Qt deploy / Flutter build | 依赖可校验、来源 pin；CI 分层严谨 | **macOS 无通道**；无安装器/签名/自动更新；全匿名构建可达尚未端到端重跑 | 部分 |
| 文档/裁决 | 28 篇 docs + 48 条裁决（A/N/P/E 系列），每条带失效条件 | Flutter 文档（裁决记录较少） | 决策可追溯，避免重复讨论 | 文档量大，新人入口靠导航页 | 对齐 |
| i18n / RTL | P5 明确裁决不做，`src/` 全仓无实现 | Flutter intl + Directionality | 决策清晰、失效条件写明 | 多语言交付需 ICU 数据与资源管线，季度级工作 | 缺失（已裁决） |

## 3. 平台覆盖矩阵

| 能力 | 框架层 | Win32 | X11 | macOS |
|---|---|---|---|---|
| 指针/键盘/焦点/菜单/浮层 | 完整 | 完整 | 完整 | 未接 |
| 文件拖放 | 完整 | WM_DROPFILES | 无 | — |
| 光标形状 | 完整 | 8 种 | 8 种（标准 cursor font） | — |
| 自绘标题栏/无边框 | 完整 | 完整 | Motif hints + EWMH 拖拽 | — |
| 高 DPI | 抽象完整 | per-monitor 重读 | 每屏一值（Xft.dpi / X11Dpr） | — |
| 主题切换事件 | 完整 | ThemeChangedEvent | ThemeChangedEvent（XSettings 轮询触发） | — |
| 原生 A11y | 语义树完整 | UIA（7 Pattern） | AT-SPI 未接 | 未接 |
| 原生对话框 | 完整 | IFileDialog | zenity/yad（可缺失） | 未接 |
| 托盘/系统通知 | 端口存在 | tray=null / 无通知 | yad / 无通知 | 未接 |
| 渲染 | 双后端 | Skia 直贴（零跨界） | 需 toRgba 跨界 | 未接 |

**结论**：Win32 是完整桌面实现；X11 已补齐桌面窗口能力（光标 / 无边框拖拽 / 高 DPI / 主题事件 / 外观跟随 / 对话框 / 托盘 / 剪贴板 / XIM 定位），通过 T2 端到端门，仅 AT-SPI、文件拖放、材质、per-monitor DPI 仍缺；macOS 无平台目录。
「跨平台 GUI 框架」的定位目前约 2 个平台——macOS 落地与 X11 A11y 仍是能力边界里最硬的一条。
★ 本轮已落地上表光标 / 无边框 / 高 DPI / 主题事件四行；AT-SPI 与 XDND 文件拖放需新增系统库依赖（D-Bus/atspi、XDND 协议栈）且 Xvfb 无 a11y bus 无法真机验证，留作独立一步。

## 4. 与 11-15 review 的增量对比

| 上次 P0 | 当时状态 | 现在 | 证据 |
|---|---|---|---|
| P0-1 表单最后一公里 | 密码框写不出来、无格式化、无补全 | **部分闭合** | `TextField::obscureText()`、`inputFormatters()` 已落地；仍无 Form/validator、补全、IME 预编辑 |
| P0-2 UIA Pattern 与事件面 | 只分派 6 种 Pattern，"满配"是错判 | **未闭合** | `uia.cc` 仍 7 种（Invoke/Value/Toggle/RangeValue/Selection/SelectionItem/ExpandCollapse），无 Text/Grid/Scroll |
| P0-3 业务状态恢复 + 导航栈 | 仅有窗口几何 | **已闭合** | `NavigationStack`（push/replace/pop/args/guard/toArray）+ `RestorationStore` + `WindowStateStore` |

上次 P1 中已闭合：`Future::timeout` 统一 Failure code、CLI 路由收口与 release 资产、deps-v2 双平台发布。
仍未闭合：运行时 Inspector、WM_POINTER/SetCapture/OS DnD、变长行虚拟化、热更状态保留档、多格式剪贴板与系统通知。

## 5. 分级差距（按"消费者 × 依赖 × 成本"排序，非时间表）

**P0 — 影响定位或交付**
1. 第三平台或 X11 A11y：无障碍只覆盖 Win32，对"跨平台"定位是硬伤。AT-SPI 比 macOS 成本低。
2. UIA Pattern 补齐（IText/IGrid/IScroll + PropertyChanged）：读屏与政企采购的阻断项（承接 11-15 P0-2）。
3. 全匿名构建可达：ROADMAP 1.0 门槛未封闭的最后一项，属交付而非能力。

**P1 — 每天可感知**
4. 运行时检查器（四树服务化 + 约束/帧时间线/性能 HUD）：静态检查强、动态观测弱的不对称。
5. 声明式表单校验（Form + validator + 跨字段聚合）：应用侧样板仍多。
6. 变长行虚拟化 + `scrollToIndex`：定高限制在真实数据场景很早就会碰到。
7. IME 预编辑串（TextDraft composing 区间 + TextInputClient 接线）：中文输入体验最后一公里。
8. OS 集成：多格式剪贴板、托盘（Win32）、系统通知、全屏语义、KeyUp/长按重复。

**P2 — 增强**
9. GPU 后端：先做字形批处理 + 变换栈抽取，再 Win32 D3D present spike（见咨询记录）。
10. 弹簧/交错动画、页面过渡、路径 cubicTo/arc、混合模式、动画图。
11. 流布局 zIndex/裁剪、Grid 自动列数、ConstrainedBox。

**明确不重开**（除非失效条件触发）：i18n/RTL（P5）、触摸与笔（P2）、fling/nested scroll、
增量布局/damage rect、为形似改 API、空壳 provider。见 `docs/decisions/README.md` 与 `ROADMAP.md`。

## 6. 诚实边界

- 本轮是**只读事实 review**，未改仓库、未提交；数据以 HEAD `fccc546` 为准。
- "缺失"仅指当前代码无实现；已裁决项列出失效条件，不代表建议立刻开工。
- 能力位、文档声明与测试夹具均不等于生产可用，表中已逐项标注口径。
- 排序是判断不是承诺；启动任何一项前仍需按仓库纪律先过裁决索引。
