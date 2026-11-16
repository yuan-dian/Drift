# Drift 全维度体检（架构 / 实现 / 扩展性 / 易用性 / 长期维护）

> 日期：2026-10-24 · 性质：**只读分析，不改代码**
> 输入（全部真跑过命令核对，取数可复算）：
> - `src/` 268 文件 / 31 332 行 PHP（`find src -name '*.php' | xargs wc -l`）
> - `docs/`（`architecture/` 13 册 · `arch-v2/` 12 册 · `design-v7/` 27 册 · `ARCH-REVIEW` / `ARCH-V2-REVIEW` 两份已落地评审）
> - `D:\TypePHP\Drift3\docs\research\` **11 份成熟 GUI 调研 + 12 横向提炼**（逐份读过）
> - `.workbuddy-ai/memory/`（`MEMORY.md` 100 行 + `PITFALLS.md` 510 行 + 11 天日志）
> - 真实源码现场（下面所有 `file:line` 都是本轮当场读到的，不复述旧评审的转述）
>
> 本轮立场与 `docs/ARCH-REVIEW-2026-10-21.md` **不同**：那份是「做之前」的诊断，
> 这份是「**W8 收盘之后**」的体检 —— 那份提出的 A1/A2/A3 三件"结构缺口"，
> **A1（覆盖层栈）与 A3（双后端 drawPath）已落地**，A2 未做。
> 所以本文不重复那三条，只写"**现在这个状态下**"还剩什么。

---

## 0. 一句话结论

**架构主干经受住了 W8 的压力测试，"补丁驱动"已经变回"结构驱动"。
剩下的问题是**四个"边角契约没类型化 / 没收口"**的点 —— 它们正在让 `Application.php`
继续膨胀（2192 → **3846 行**），而膨胀的方式恰好是上一轮认定的"补丁形状"。
不需要重写任何主干，要做的是 4 个收口改造 + 3 项非功能欠账。**

| 维度 | 评分 | 一句话 |
|---|---|---|
| **架构** | ★★★★☆ | 三层树 / 五阶段帧 / 浮层栈 / 双后端 / 端口分离 都立住了，**反向依赖 0 处** |
| **实现** | ★★★★☆ | 错误边界 / 静默失败抑制 / 反向自检做得比多数成熟框架都严；**巨型类**是唯一短板 |
| **扩展性** | ★★★☆☆ | 横向（加控件）通畅，**纵向（新平台 / 新输入模态 / 可访问性）明显欠账** |
| **易用性** | ★★★☆☆ | 三条声明式缝好用；但 **438 个 `@api`/`@internal`** 没有应用可读的 API 索引 |
| **长期维护** | ★★★★☆ | 闸门（smoke 1390 / lint 19 规则 / selftest / T2 真机 md5）是最大资产；**文档漂移**与**知识靠注释堆**是两大隐患 |

**四个该收口的（按性价比）**：

| # | 问题 | 现在的代价 | 收口动作 | 破坏性 |
|---|---|---|---|---|
| **S1** | `Application.php` **3846 行**，`instanceof` 39 处，`@api/@internal` 42 处 —— 它同时是"帧引擎 + 输入分派 + 浮层 + 焦点 + 语义 + 文本编辑 + 动画 + 通知 + 计时"九件事 | 每加一类全局能力（焦点环、`:focus-visible`、盒级光标、光标语义…）都要再动它；W8 里"焦点环 / 光标 / `:focus-visible`"三件连做的就是证据 | 拆四块：`FrameEngine`（泵 + 时钟）、`InputRouter`（分派 + 命中 + 光标）、`OverlayHost`（栈 + 定位）、`TextEditingBridge`（`instanceof TextFieldState` 收成接口） | 中（**纯搬家**，1390 条 smoke 当护栏） |
| **S2** | **控件三件套样板仍在**：`TextFieldBox` / `ScrollViewBox` / `SpinnerState` + 4 个 `*State` 与 Box 仍为 `instanceof RenderBoxWidget` 而非基座 | 上一轮 P2 已判"纯样板"，但 A2 没做 ⇒ 新控件还在抄；`StatefulWidget` 套修饰仍会响亮失败（`Element.php:159-175` 那段注释就是 A2 没做的化石） | 提 `StatefulControl` + `ControlElement`（**上一轮 A2 的处方，可直接抄**），`TextField`/`ScrollView`/`Spinner`/`LazyColumn` 改继承 | 低（等价重构） |
| **S3** | **输入模态是三值 + 三处特判**：`Application` 持 `$inputModality`（键盘/指针），`:focus-visible` 与焦点环分别在 `paintFocusRing()` 与 `focusRingTarget()` 里各判一次；且 `W8-4` 记忆里明确"**long-press 壳侧无 `WM_TOUCH`/`WM_POINTER` ⇒ 做不了**" —— 这是平台端口的**能力边界没进契约** | 下一个"跟模态走的视觉/语义"（hover 高亮、拖放影子、IME 位置…）还得再加一处判据 | 把"模态 × 焦点 → 视觉"做成**纯函数表**（已是 `focusRingTarget()` 的形状，但只覆盖焦点环），并把 `Platform` 端口的**输入能力位**（`CAP_TOUCH`/`CAP_POINTER`/`CAP_IME`）显式进契约 | 中 |
| **S4** | **非功能欠账三件**：① **UIA 桥是"接口就位、实现未接"**（`WindowHost::pushSemantics()` 有，但 `Win32WindowHost` 侧只有数组接受，**没有 provider**）② **i18n/RTL 为 0**（`grep TextDirection/locale` 全仓零命中）③ **`ErrorBoundary` 与 `Errors` 是两套**（前者拦异常、后者报契约，但应用分不清何时用哪个） | 无障碍是"树推了、没人听"；出海/多语言要重排整个 `Text` 栈；错误处理的"什么时候 Fatal / Recoverable"全靠翻文档 | ① ③ 各一个小 spike；② 是**长线**，先定 `TextDirection` 进 `BoxConstraints` 还是进 `RenderBox` 的位置 | ① 中 ② 大 ③ 低 |

★ **不建议做的**（写下来免得再讨论）：
- **`Wrap` / 双轴虚拟化**：`MEMORY.md` 已判"推翻条件是'子项数运行时才知道 + 必须自动换行'"，无此需求。
- **Measure/Place 分离**、**Compose 链式 Modifier**、**GPUI base/component 两层库**：上一轮已否决，理由仍成立。
- **为 `Application` 引入"全静态 Service Locator"**：会把"谁依赖谁"这个可静态检查的性质毁掉，与 `dependency-direction` lint 规则直接冲突。

---

## 1. 现状基线（全部本轮实测）

| 项 | 值 | 命令 |
|---|---|---|
| 源码规模 | 268 文件 / **31 332 行** | `find src -name '*.php' \| xargs wc -l` |
| 各层行数 | `Widget` **12 708** · `App` 6 914 · `Rendering` 5 412 · `Layout` 5 406 · `Text` 3 850 | `wc -l src/*/*.php` |
| 最大单文件 | **`Application.php` 3 846 行**（上一轮 2192，**+75%**） | `wc -l src/App/Application.php` |
| 控件数 | `src/Widget/` **88 文件**（上一轮 63） | `ls src/Widget \| wc -l` |
| 闸门 | smoke **1 390** · lint **484 文件 / 0 err 0 warn / 19 规则** · selftest 全过 · T2 AOT **~200s** | `.workbuddy-ai/memory/MEMORY.md` 10-24 记 |
| `@api` / `@internal` | **332 / 253** | `grep -rc "@api\|@internal" src` |
| `Application` 的 `instanceof` | **39 处** | `grep -c "instanceof" src/App/Application.php` |
| 反向依赖 | `use Drift\App` 在 `src/App/` 之外 = **0** | `grep -rln "use Drift\\\\App" src \| grep -v src/App` |
| 端口 | `Platform` 9 接口 + `Win32/` 11 文件 1 066 行；`drift_win32.cc` 1 858 行 | `ls src/Platform/Win32 \| wc -l` |
| 双后端能力差 | 仅 `CAP_SHADOW`（Skia 独占），`CAP_IMAGE`/`CAP_PATH` 已双向 | `grep unsupportedOp src/Rendering/RasterCanvas.php` = **1 处** |

⇒ **W8 是真正的压力测试**：控件数 63 → 88、`Application.php` 2192 → 3846、
smoke 936 → 1390。**主干没崩**（反向依赖 0、分层守住了），但**横向增长全部堆在
`Application.php`**，这是本轮最该关注的信号。

---

## 2. 架构维度

### 2.1 立住的骨架（五条，每条都有"为什么不能再动"的理由）

| 骨架 | 现场证据 | 为什么不能动 |
|---|---|---|
| **三层树**（Widget 不可变 / Element 身份 / RenderBox 几何） | `Element::syncBox()` 是**全仓唯一盒创建点**（`Element.php:142-158`，注释原话就写着这条） | 任何"第二个盒创建点"都会重现 `Frame` 跳过 layout 那个 bug（`State::$boxEpoch` 的由来） |
| **五阶段帧**（settle → layout → paint → present + 泵前置） | `Application::frame()` 1838 起九段，每段 `SurfaceProfile` 计时 + `FramePhase` trace | 泵必须在 settle 之前（`pumpTasks` 注释明写），顺序一变就是"loading 闪一帧空" |
| **浮层栈 = 绘制序 = 命中序**（`OverlayStack` 唯一真源） | `Application.php:240-247` 明写"01-a/01-b 期间挂七个镜像字段，01-c 已删" | 上一轮 P1 的病根已除；现在只在 `syncPanelSignatures()` 处有一处签名同步 |
| **双后端能力等价** | `RasterCanvas` 仅 `drawShadow` 一处置 `unsupportedOp`；`CAP_IMAGE`/`CAP_PATH`/`CAP_LAYER`/`CAP_CLIP_PATH` 都已双向 | 上一轮 P3 的病根已除；`Memory.md` 明确"两后端要么都支持要么都响亮失败" |
| **平台端口分离** | `Platform` 9 接口 + `Win32/` 收敛；`use Drift\App` 反向 0 | `docs/_archive/CROSS-PLATFORM.md` 2026-10-20 实测"框架 PHP 198 文件零平台耦合" |

★ 这五条**是这份体检最重要的一句话**：**W8 没有一条新控件推翻它们**，
上一轮担心的"补丁会再长"没有发生（除了下面 §2.2 的 Application 膨胀）。

### 2.2 唯一的架构性缺陷：`Application.php` 是"九合一"巨型类

**证据链**（全部本轮读到）：

```
3 846 行 · 19 个 App 类里它一家占 3 846 / 6 914 = 56%
instanceof 39 处（跨层 type-switch）
@api 42 处 · @internal 多处
public frame() 一个方法 285 行（1838-2123），九个泵 + 浮层 + 焦点环 + 语义
```

它**同时**是：
1. **帧引擎**（`frame()` 九段 + `FrameClock` 协作）
2. **输入分派**（`dispatchInput()` 2694-2878，含 `instanceof TextFieldState`、`scrollThumbAt`、`hitsModalPanel`、`cursorFor`）
3. **浮层宿主**（`placeOverlays` / `syncPanelSignatures` / `placeEntry` / `anchorRect` / `focusTrapRoots` / `topModalEntry`）
4. **文本编辑桥**（`editingTextFieldState` / `textSelectBox` / `beginTextSelection` / `extendTextSelection` / `dispatchEditCommand`，全部 `instanceof TextFieldState`）
5. **焦点与视觉**（`paintFocusRing` / `focusRingTarget` / `revealFocused`）
6. **通知**（`showSnackbar` / `shiftSnackQueue` / `presentSnackbar` / `snackEntry`）
7. **语义**（`pumpSemantics` / `pushSemantics` / `semanticsFrameSkip` / `lastStructureVersion`）
8. **计时/延时**（`after()` / `pumpTimers` / `panelsLagBehind`）
9. **测试与 devtools 观察面**（十余处 `@api 测试 / devtools 读它`）

**这就是上一轮 P5 的第二季**：`frame()` 从 8 段补丁变成 9 段（多出 `pumpTimers`），
`wantsFrame()` 判据从 5 条变 7 条（多出 `panelsLagBehind` 与焦点环），
**每一件新跨切面能力都在这里加一段**。现在还能撑，再加两类能力就会重演
`Frame.php` 早期"一帧只能收一个浮层"那种形状。

**为什么这是"架构"而不是"实现"**：
`instanceof TextFieldState` 39 处意味着 **`Application` 知道 `TextField` 的内部状态类**。
这是上一轮 P6「受控值是事后统一」的直接后果 —— 没有"文本编辑"这个抽象，
框架只能特判具体控件。今天只有一个 `TextField`，明天加 `TextArea` / `CodeEditor`
就再抄一遍 39 处。

### 2.3 借成熟框架对照（`D:\TypePHP\Drift3\docs\research\` 11 份）

上一轮已对 11 份做过分类（4 已兑现 / 5 该借 / 5 不借 / 4 反面警示）。本轮只取
**W8 之后能落到具体文件**的四条，不重复那张总账：

| 借鉴点 | 出处 | Drift 现状 | 动作 |
|---|---|---|---|
| **`Widget` 面按"四类正交"分组**（盒样式 / 输入声明 / 语义 / 布局约束） | Compose（借分类法，**不借**链式 API） | `Widget.php` 1 147 行一个平铺的链式 API 面，`cursor()` / `hover()` / `pointer()` / `keyboard()` / `overlayPanel()` / `tooltip()` / `semantics()` / `focusOrder()` 交错排列 | **不改代码**，只在 `docs/architecture/08` 加一张"修饰需求四类对照表"+ lint 规则（漏一类就报） |
| **能力位进端口契约**（`CAP_TOUCH` / `CAP_POINTER` / `CAP_IME`） | UDA `SupportLevel`（只借"**降级要可解释**"这一条） | `W8-4` 记忆："pinch **做不了**（壳无 `WM_TOUCH`/`WM_POINTER`）" —— 这是**文档级**事实，代码里无从表达 | `Platform` 端口加能力位；控件声明"需要 `CAP_TOUCH`"，缺则响亮失败 |
| **观测面 = 产品需求**（"看不见就没法修"） | Flutter DevTools / React DevTools / Vue Devtools（三家都把 Inspector 当一等公民） | `Application` 有十余处 `@api 测试 / devtools 读它`，但 **apps/tools 里 devtools 只有 5 处 grep 命中**（`grep -rn devtools apps tools` = 5）⇒ **观察面是"暴露了但没人用"** | 起一个最小 Inspector（帧时序 + 脏节点 + 浮层栈），消费 `SurfaceProfile` / `FramePhase` / `overlays()` |
| **反面：Provider/AutoDispose 的"订阅地狱"** | Flutter Riverpod | `Subject` / `Subscription` / `watch()` 已经是这一族；`DeadSubscription` / `NestedSubscribe` / `CachedSubscriber` 三条 lint 在守 | **不借**（已是最小形状），但把三条 lint 的"什么算违规"写进 `docs/architecture/07` |
| **反面：Qt 双轨（QWidget + QML）** | Qt | 上一轮已借过（P3 双后端等价），W8 已兑现 | 无需再动，写进"永久判据" |

★ **11 份里有 4 份（Aria / MoUI / UniDesktop / Vue）本轮没有新增借鉴点** ——
不是它们没价值，是上一轮已把能落到文件的都取走了，剩下的都是概念。

---

## 3. 实现维度

### 3.1 做得比成熟框架更严的三点（W8 之后依然成立）

1. **反向自检是全仓纪律**，不是测试可选项。`PITFALLS.md` 10-24 那条"反例自检形状：
   摘掉 `parent::didUpdateWidget()` ⇒ 红 6 / 绿 3"是**逐缺陷**记录的；lint 19 条规则
   自带 `tools/selftest.php`（"该响会响 + 不该响不误响"）。Flutter / Qt 的框架层测试
   都没这么严。
2. **"未消费的分支 = 未实现"** 已经是硬约束（`MEMORY.md` 反复出现），W8-4 的
   `long-press` 直接判"壳侧无能力 ⇒ 不做"，而不是写一个空壳。这比大多数框架
   留一堆 `notImplemented` 强。
3. **静默失败的抑制是系统性的**：`Element::syncBox()` 修饰丢失 ⇒ `Errors::report`
   （响亮）；`didUpdateWidget()` 漏 `parent::` ⇒ 反向自检抓；`frame()` 跳过 layout ⇒
   `State::$boxEpoch` 记账。`Errors` 是**判决器**不是日志（`12` 册明写），定位准确。

### 3.2 该修的实现问题

| # | 问题 | 证据 | 收口 |
|---|---|---|---|
| **I1** | **巨型方法**：`Application::frame()` 285 行、`dispatchInput()` 184 行、`RasterCanvas` 1 816 行 | `wc -l` | `frame()` 拆成 `FrameEngine`（九段表驱动）；`dispatchInput()` 拆成 `InputRouter`；`RasterCanvas` 的扫描线/描边/裁剪已分私有方法（好），**不用动** |
| **I2** | **`instanceof` 分支表没有守卫**：39 处散在 `Application`，`Element::syncBox()` 与 `StatefulElement` 各有特判 | `grep -c instanceof` | 拆 `S1` 时一并收成接口（`EditableText` / `Scrollable`），并加一条 lint：`instanceof` 于 `src/App/` 里只许出现在接口上 |
| **I3** | **两套错误通道**：`ErrorBoundary`（拦异常、画占位）与 `Errors`（报契约、Fatal/Recoverable/Diagnostic）职责分得清但**入口没类型**：应用不知道什么时候 `throw` 什么时候 `Errors::report` | `src/Widget/ErrorBoundary.php` + `src/Errors.php` | 加一张 3 行的判定表进 `docs/architecture/17`：**契约破坏 → `Errors`；用户输入导致的渲染失败 → `ErrorBoundary`；外部 IO → `Job` + `after()`** |
| **I4** | **死分支仍在**：`RenderFlex`/`RenderDock` 的 `Scroll`/`Wrap` 溢出策略仍是"未实现"分支（`RenderFlex.php` / `RenderDock.php`） | `grep 尚未实现 src/Layout` = 5 处 | 与 §0 的"否决清单"一致：**把 `Scroll`/`Wrap` 从 `Overflow` 枚举里删掉**（`MEMORY.md` 已判 `Overflow::Wrap` 是类别错误）—— 删比留好，留着就是"未被消费的分支 = 未实现"的反面 |

---

## 4. 扩展性维度

### 4.1 横向（加控件 / 加页面）：★★★★★

这是 W8 证明得最充分的一点。新增一个叶子控件的最小面：
`Widget` + `State` + `RenderBox` + 语义 + smoke（含反例）+ 真机一图。
`Grid`/`LazyGrid` 是**纯组合**（`MEMORY.md`：`LazyColumn` 套 `Grid`，不新增原语），
`AspectRatio` **零新渲染盒**（`Modifier` 字段），`RichText` 复用 `paintRunDecorations`。
**"加一个控件"的成本已经接近线性**，这是上一轮 P2 没做 A2 但也没崩的原因：
控制件"三件套样板"的绝对量在 88 个控件下也还不到 500 行（`ScrollViewBox` 85 +
`TextFieldBox` 133 + 4 个 State ≈ 300 行）。

★ **但**：下一批叶子（`DatePicker` / `Pagination` / `Badge` / `Chip`）**全是浮层或组合**，
不是新原语 ⇒ 三件套样板不再是瓶颈。真正会再长样板的是 **`TextArea` / `CodeEditor`
这类"有文本状态 + 有编辑行为"的控件**，届时 S2 的 `StatefulControl` 才会显出价值。

### 4.2 纵向（新平台 / 新输入模态 / 可访问性）：★★☆☆☆

这是 W8 **没碰**、而长期必然要碰的一维，也是评分最低的一维：

| 缺口 | 证据 | 为什么是扩展性问题 |
|---|---|---|
| **触摸 / 笔 / 手势** | `MEMORY.md`：pinch **做不了**（壳只收 `WM_MOUSEMOVE`/`WM_MOUSEWHEEL`） | 端口契约里没有"输入能力位" ⇒ 控件无法声明"我需要 CAP_TOUCH"，只能靠文档记"做不了" |
| **无障碍 UIA** | `WindowHost::pushSemantics()` 存在、`Win32WindowHost.php:186` 只有数组接受，**没有 provider** | 树推了没人听 ⇒ 屏幕阅读器拿到空树；"per-app opt-in"（`Application.php:170`）的闭包接线还没消费者 |
| **i18n / RTL** | `grep TextDirection\|locale` 全仓 0 | `Text` 栈 / `RenderFlex` / `Row` 都没方向概念 ⇒ 支持阿拉伯语要重排布局算法 |
| **多窗口** | `WindowStateStore` 249 行是唯一相关物 | 没有"窗口 ↔ 树"的映射契约；`OverlayStack` 是**单窗口**设计 |
| **GPU / 合成层** | `GpuContext` 是留口；3.14ms / 5.3× 余量 | 不阻塞，但**层叠合成**（`CAP_LAYER` 已在 Raster 落地）在 Skia 侧是浅封装，将来要合成器（`Compositor`）时会动 `Frame::run` |

★ **建议的顺序**（与 §0 的 S3/S4 对应）：
1. **输入能力位进 `Platform` 契约**（S3）—— 最便宜，**先把"做不了"从文档变类型**。
2. **UIA provider spike**（S4①）—— 有 `pushSemantics` 在前，补 provider 大概几百行 C++。
3. **`TextDirection` 定位裁决**（S4②）—— 这是个**设计裁决**不是实现：进 `BoxConstraints`
   还是进 `RenderBox`，会决定 `intrinsic*` 要不要跟着改（`P4` 那条"两套布局路径"会重演）。
4. 多窗口 / GPU 留到有真实需求。

---

## 5. 易用性维度

### 5.1 好的部分

- **三条声明式缝**（`pointer($h, ownsPress:)` / `keyboard($h)` / `overlayPanel(\Closure)`）
  形态一致、语义正交，且 `MEMORY.md` 明确"别再往 `activateFocusedWidget()` 加 instanceof"。
- **受控值统一形态**：值住应用 `State`，激活走单一入口；`OptionStatus` 用互斥不可变值
  表达三态（`MEMORY.md`：设了 `loading()` 就没有错误态）—— **这个设计比 Flutter 的
  `isLoading`/`error`/`data` 三字段更类型安全**。
- **组合根接线清晰**：`Menu::onOpen` / `Widget::tooltip()` / `buildRoot()` 递闭包，
  `Widget` 层够不到 `Application` 时**不许** `$this->application()`（已成纪律）。

### 5.2 差的部分

| # | 问题 | 证据 | 收口 |
|---|---|---|---|
| **U1** | **API 面没有"应用可读的索引"**：332 `@api` + 253 `@internal`，但没有工具能列出"我作为应用开发者能用什么" | `grep -rc` | 加一条 lint 规则 `api-surface`：`php tools/lint.php --api` 输出 `@api` 面清单（按层分组），**当文档用** |
| **U2** | **文档与代码漂移**：`docs/arch-v2/11` §0 的"收盘现状表"与 `MEMORY.md` 10-24 那段几乎逐字重复，但**两处都有可能过时**（`11` 写"`LazyRow` 待做"，`MEMORY.md` 未提） | 两处 grep | `docs/arch-v2/11` 的 §0 改成**指向 `src/` 的自检命令**（"控件在不在：`grep -rl "class X" src/`"，`MEMORY.md` 已有这条判据），文档只留"为什么不做" |
| **U3** | **示例台是唯一真实消费者，但没有"API 教程"分层**：`apps/showcase/` 19 个页面全是能力展示，没有"从 0 到 1 写一个应用"的引导 | `ls apps/showcase/src/Pages` | 加 `apps/tutorial/`（hello-world → 计数器 → 异步 → 浮层），**这是新人/未来的你唯一能"照抄"的路径** |
| **U4** | **错误消息好，但没有"怎么修"的链接**：`Element.php:166-175` 的修饰丢失消息给了两条修法，但**没有文档锚点** | `Errors::report` 的文案 | 文案尾部加 `详见 docs/architecture/08 §x.y`，且文档锚点可由 lint 校验 |

---

## 6. 长期维护维度

### 6.1 最大资产：闸门是**可复算**的

smoke 1390 / lint 19 规则 / selftest 15+18 / T2 AOT / 真机 md5 连跑 2–3 次 ——
这套闸门在 11 天里撑住了 **W8 全程**，且 `MEMORY.md` 的判据条条是"**反向自检形状**"
（"摘掉 parent ⇒ 红 6 绿 3"这种）。**这是这份代码库最不该动的东西。**

`ARCH-V2-REVIEW` 用脚本核过 78 处 `file:line` 0 越界、406 处跨册 § 引用 0 断链。
**这个"文档可复算"的纪律也应该进闸门**（上一轮 B 级建议的 `tools/docref.php`，目前仍未做）。

### 6.2 三个长期隐患

| # | 隐患 | 证据 | 收口 |
|---|---|---|---|
| **M1** | **知识全在注释与 `PITFALLS.md`，没有"机制文档"**：`★` 注释从上一轮 974 处涨到目前**约 1 100+**（`MEMORY.md` 11 天日志里每天都有 ★★★）；`PITFALLS.md` 510 行是按主题堆的，**没有从"坑"到"机制"的提炼** | `grep -ro "★" src \| wc -l` | 每季度做一次"坑 → 机制"的提炼（把 3 条同类坑合成 1 条不变量），否则 `PITFALLS.md` 会变成第二个 `HISTORY.md` |
| **M2** | **文档漂移无防**：`arch-v2` 11 686 行 + `design-v7` 19 198 行 + `architecture` 13 册，三套并行；`ARCH-V2-REVIEW` 自己都指出"文档即规格但没防漂移机制" | `wc -l docs/**` | 上一轮 B 级建议的 `tools/docref.php`（`file:line` 越界 + 跨册 § 断链）**建议立刻做**，半天收益；再加一条"文档里的文件行数必须与实际差 ≤ 10%" |
| **M3** | **`Application.php` 3846 行没有"分片所有权"**：19 个 App 类里它一家占 56%；`docs/arch-v2` 的分册是按"主题"分（覆盖层/布局/状态…），代码没按同样方式分片 ⇒ **改一个主题要在一个文件里跳 5 处** | `wc -l src/App/*` | 与 S1 同一动作：拆 `FrameEngine` / `InputRouter` / `OverlayHost` / `TextEditingBridge` 四片，**每片与 `docs/arch-v2` 一册对齐** |

---

## 7. 建议的执行顺序（按性价比）

```
第零步（半天）   M2 tools/docref.php        ← 独立、零冲突、立刻止住文档漂移
第零步（半天）   U1 api-surface lint        ← 把 332 个 @api 变成可打印清单
第一步（1 周）   S1 拆 Application 四片     ← 纯搬家，1390 条 smoke 当护栏；
                                              与 docs/arch-v2 分册对齐（M3 一并解决）
第二步（2–3 天） S2 StatefulControl 基座    ← 上一轮 A2 的处方，等价重构
第三步（3–4 天） S3 输入能力位进 Platform    ← 把"做不了"从文档变类型；连带把
                                              "模态 × 焦点 → 视觉"收成纯函数表
第四步（穿插）   U2 文档改自检 / U3 tutorial / I3 错误通道判定表 / I4 删 Overflow::Scroll|Wrap
第五步（按需）   S4① UIA provider spike    ← 有真实无障碍需求再做
长线（有需求）   S4② TextDirection 裁决 + i18n
```

★ **顺序理由**：
- **M2 排最前**：它是唯一"不做就会继续恶化"的（文档 3 万行且三套并行），
  半天做完，之后所有文档改动都有闸门。
- **S1 排第二**：它是 §2.2 的根因；`S2`/`S3`/`I2` 都会在拆的过程中自然收口
  （拆 `TextEditingBridge` 就消灭 39 处 `instanceof TextFieldState`）。
- **S2 在 S1 之后**：`StatefulControl` 的 `ControlElement` 会与 `Element::syncBox()`
  重叠，先拆 `Application` 再动 `Element`，冲突面小。
- **S3 独立**，随时可做，但**必须在 UIA 之前**（UIA 的节点能力位要复用它）。
- **S4②（i18n）是设计裁决不是实现**，不要在没有真实需求时启动。

---

## 7.1 执行复核（2026-10-25）：**S2 与 S3 的前提已被 W8 消解，I4 的处方要改一半**

第零步（M2 `docref`、U1 `api-surface`）与第一步（S1 拆 `Application`）已完成。
执行第二步前重测了一遍 S2 / S3 的**前提**，结论是两条都不该按原处方做。

### S2 · `StatefulControl` 基座 —— **否决（不是"做不了"，是"已经做完了，只是不叫这个名字"）**

原处方的三条收益，逐条实测：

| 处方里的收益 | 实测 | 证据 |
|---|---|---|
| ① 光标陷阱消失（`setCursor()` 只有**一个**写点） | **已达成** | 写点从 12+ 处收到 `Element::syncBox()` 与 `StatefulElement::syncBox()` 两处，且**都在造盒的同一个方法里** ⇒ "盒为 null 时落光标"这个陷阱在结构上不存在 |
| ② `Element::syncBox()` 的 `instanceof` **分支表**删 4 条 | **已达成** | `Element.php:149` 只剩**一条** `$this->widget instanceof RenderBoxWidget`（接口判定，不是分支表）；`ProxyElement.php:21` 的注释记着"原来这里是 7 个 `instanceof` 分支" |
| ③ 新控件少写两个类 | **已达成** | `createRenderObjectFromState()`（`02` §6.2，2026-10-23）让"有态 + 有盒 + **无子**"两段就够 —— `Spinner` 已改造、`SpinnerBox` 已删 |

**处方本身还基于一个读错了的事实**：它说"`TextFieldBox` / `ScrollViewBox` / `SpinnerState` + 4 个 `*State` 与 Box 仍为 `instanceof RenderBoxWidget` 而非基座"。
但 `TextFieldBox` / `ScrollViewBox` **是 `RenderBoxWidget`（普通 Widget），不是 Element**
—— 它们是"把主题 token 解析成值"的那一层（§15.7 纪律 3），本来就不该由 Element 基座承担。

实测 Element 家族**只有 4 个类**（`Element` / `StatefulElement` / `StatelessElement` /
`OffstageElement`），**没有任何按控件分的 Element 子类** —— 即"三件套样板"的 Element
那一半**已经归零**。剩下两个 `*Box` 是"有子"的特例（`TextField` 有 label、`ScrollView`
有滚动内容），而 `createRenderObjectFromState()` 的语义是"**不产子**"⇒ 套上去会静默
丢掉子层（`TextFieldBox` 类头已写死这条判据）。

⇒ 若照处方做，`StatefulControl` + `ControlElement` 只是给 `StatefulElement` 套一层
没有信息的壳，**净增 120 行、零收益**。真正还长样板的是 `TextArea` / `CodeEditor`
这类"有文本态 + 有编辑行为"的控件，届时该解决的是"有子 + 有态 + 有盒"的组合路径，
不是这个基座。

### S3 · 输入能力位 —— **否决（两半各自都不成立）**

| 处方里的动作 | 实测 |
|---|---|
| 把"模态 × 焦点 → 视觉"做成纯函数表（"现在 `paintFocusRing()` 与 `focusRingTarget()` 各判一次"） | **已经是同一个纯函数**：`focusRingTarget()` 被 3 处消费（`paintedFocus` 赋值 / `wantsFrame()` 判据 / 绘制），`paintFocusRing()` 不再自己判 |
| 输入能力位（`CAP_TOUCH` / `CAP_POINTER` / `CAP_IME`）显式进 `Platform` 契约 | **无消费者**：全仓没有一个控件声明"我需要 `CAP_TOUCH`"（`pinch` / `long-press` 已判"壳侧无 `WM_TOUCH` ⇒ 不做"）。按 A15「**没有消费者不预先发明机制**」，这是"为一个不存在的控件写契约" |

★ 但 S3 的**诊断**是对的、只是**处方**错了：`pinch` 做不了这件事现在只活在
`MEMORY.md` 的一句话里，没有任何**类型**约束它。这条的正确落点不是"加能力位"，
而是**等第一个真实消费者**（触摸控件）出现时再一次性加，否则能力位会先长成一个
没有读者的枚举。**推翻条件写死**：出现任一控件需要 `CAP_TOUCH`/`CAP_POINTER` 时，
按"能力位 + 声明式需求 + `attach()` 时对不上就响亮失败"一次做齐。

### I4 · 删 `Overflow::Scroll|Wrap` —— **只该删 `Wrap`，`Scroll` 要留**

| 枚举值 | 实测消费者 | 判决 |
|---|---|---|
| `Wrap` | **零**（只有 `RenderFlex`/`RenderDock` 两个 `=> Errors::report('尚未实现')` 死分支） | **删**（`04` §4.4 已判它是**类别错误**：`Overflow` 的语义是"舍弃方式"，`Wrap` 是"重排"） |
| `Scroll` | `RenderScrollView::overflow()` 真的返回它，且 `M2Conformance` 拿它做 `!== Report` 的检查 | **留**。但要把话说清：**没有"按值分支"的读者**，`RenderScrollView` 的裁剪是自己 `paintContent()` 里 `pushClip()` 做的，不走 `clipsOverflow()` ⇒ 它是**行为声明**，不是调度开关 |

★ 顺带记一条判据：`overflow()` 与 `clipsOverflow()` 是**两件事**（前者声明策略、后者是
裁剪开关，默认 `false` 且**故意与 `overflow()` 的默认 `Clip` 相反**）。看到
"`overflow()` 返回 `Scroll` 却没覆写 `clipsOverflow()`"**不是**缺陷 —— 裁剪在
`paintContent()` 里自己做，别急着"修"。

### 修正后的下一步

S2 / S3 划掉，第二、三步空出来 ⇒ 直接进**第四步（穿插批）**，顺序按"减面 → 止漂移 → 补引导"：

1. **I4（修正版）**：删 `Overflow::Wrap` + 两个死分支臂 —— 真删除、零行为改动。
2. **U2**：`11` §0 的"收盘现状表"改成**自检命令**（表会过时，命令不会）。
3. **U3**：`apps/tutorial/`（hello-world → 计数器 → 异步 → 浮层）——
   这是唯一能**从外部**测出 API 易用性缺陷的手段（`$this->application()` 那类
   "能编译、能跑、就是崩"的坑，只有从零写一个应用才会撞到）。

## 7.2 U3 执行复核（2026-10-25）：动工前重测「写一个应用到底要多少行」

U3 的处方是"加 `apps/tutorial/`"。动工前先量了一遍**从零写一个应用的真实成本**，
结论是：**处方方向对，但四条预设缺陷里有三条不该改框架**。

### 实测：一个 Hello World 应用 = 4 个文件 / 188 行

| 文件 | 行数 | 应用自己写的部分 |
|---|---|---|
| `main.php` | 26 | 1 行（`Bootstrap::run()`）+ 25 行注释 |
| `project.yml` | 41 | 6 行有效（`name` / `version` / `info` / `include` / `sources`） |
| `bin/win32/Bootstrap.php` | **121** | **1 行**（`new HelloApplication()`） |
| `src/HelloApplication.php` | 22 | `buildRoot()` 的 3 行 |

★ 四个应用的 `Bootstrap` 分别是 **121 / 190 / 157 行**（hello / users / showcase），
归一化后**骨架逐行同构**（`apps/showcase/bin/win32/Bootstrap.php` 自己就写着
"本文件与 hello / users 的 Bootstrap 逐行同构 —— 换应用只换 `new XApplication()` 一行"）。
差异只有三处：① 窗口几何常量 ② 是否读回窗口状态（`WindowStateStore`）
③ 是否在循环里泵 `FrameClock` / `ProcessTaskHost::reap()`。

### 四条候选缺陷的判决

| # | 缺陷 | 判决 | 理由 / 推翻条件 |
|---|---|---|---|
| **F1** | **121 行组合根样板要照抄** | **不改框架** | §5.2 裁决 1 是"框架绝不自建循环"，而**主循环恰好归应用**：`users` 要在循环里 `ProcessTaskHost::reap()`、`showcase` 要泵 `FrameClock` 并在关窗时存窗口状态。抽成框架 helper 就必然长出"每帧钩子 / 关窗钩子 / 启动钩子" —— 那是 A15「没有消费者不预先发明机制」明令禁止的形状（消费者只有 3 个，且各自需要不同的钩子）。⇒ **F1 的正确修法就是 U3 本身**：把 121 行变成**带解释的模板**，而不是把它藏进框架。推翻条件：出现第 4 个"循环里什么都不干"的应用，且样板涨到 200 行以上 |
| **F2** | **`buildRoot()` 递能力用位置参数闭包** | **暂不改** | `ShowcaseRoot::make(\Closure $snackbar, \Closure $after)` —— 两个形参类型都是 `\Closure`，只靠签名区分，**tpc 对闭包 arity 没有系统验证**（`Widget::overlayPanel()` 的 docblock 已写死这条）⇒ 传反位置要到"点了才炸"。但 tutorial 只需要 **1 个**能力、showcase 是 **2 个**，改成具名能力对象在 1–2 个字段时是净增概念。推翻条件：某应用需要 **≥3 个**能力时，改成 `final class HostCapabilities`（具名字段 + 可加性），一次性替换所有 root |
| **F3** | **没有入门路径** | **U3 修** | `docs/00-README.md` §2「读者路径」7 行里**没有**"我想写一个应用"—— 唯一路径是照抄 `apps/hello`。U3 交付 `docs/tutorial.md` + 在 §2 补一行 |
| **F4** | **`overlayPanel()` 只有内部消费者** | **U3 顺带验** | `grep -rn "overlayPanel(" src/ apps/` 除定义外只有 `Select` / `ComboBox`（都是框架自己的控件）⇒ "应用侧能不能用"从未被验证过。tutorial 的浮层段用 `Button::make()->overlayPanel(...)` 成为**第一个外部消费者** |

### 与原处方的一处修正：**段序改成 hello → 计数器 → 浮层 → 异步**

原序是 "hello-world → 计数器 → 异步 → 浮层"。实测三条缝的**接线成本**后要换：

| 段 | 需要组合根接线吗 | 依据 |
|---|---|---|
| hello | 否 | 只写 `buildRoot()` / `title()` |
| 计数器 | 否 | `setState()` 在 `State` 内自洽 |
| **浮层** | **否** | `Widget::overlayPanel(\Closure $factory)` 是**声明式缝**：`dismiss` 由 `Application` 在开面板那一刻注入（`Select` / `ComboBox` 同款）。`apps/showcase/src/Pages/SelectPage.php:43` 原话："不需要组合根接线（与 Snackbar 相反）" |
| **异步** | **是** | `after()` / `TaskHost` 都在 `Application` 上，控件层够不到（§4.3 禁 `Widget → App`）⇒ 只能由 `buildRoot()` 递闭包 |

⇒ 把"零接线"的浮层放第 3 段、"唯一要接线"的异步放最后一段，**最后一段才教接线**，
读者不会在第 3 段就撞墙。这也让 tutorial 里**只出现一个闭包**（`after`），
把 F2 的样板成本压到最低。

### 动工后新增的三条（写应用时真撞到的）

上面四条是**动工前**的预判。写的过程又撞到三条，其中一条是**真框架缺陷**：

| # | 缺陷 | 判决 | 依据 |
|---|---|---|---|
| **F5** | **新增应用必须在仓库根 `composer.json` 登记 `App\<Name>\`**，否则 `php.exe` 侧（smoke / preview / 解释器）找不到类，而 **AOT 侧靠 `project.yml` 的 `sources` 自动收** ⇒ 两边不同源，只有内环炸 | **不改** | `composer.json` 在本仓是**物理边界表**（`tools/autoload.php` 与 `tools/Lint/Project.php` 都读它，`NamespaceMismatch` 按它判）⇒ 自动 glob 会让"登记表"与"实际能加载的类"分叉。**正确做法是把它写进教程第 0 节**（已写） |
| **F6** | **新增中文文案会静默无墨**：内置点阵字模是生成物，覆盖范围 = 上次扫描时全仓字面量里出现过的字符。新字不在表里 ⇒ **不画 tofu、不报错、零宽隐形** ⇒ 一句话少了一截（本次 45 个字，含「和」「十」「北」「京」「市」） | **修（重跑生成器）** | `php tools/font-scan-chars.php && python tools/font-gen-glyphs.py`。★ 实测是**纯新增**：补 45 字、**240 行插入 0 行删除**（既有字形逐字节不动）⇒ smoke 的 md5 断言不受影响。顺带清掉"内置字模缺 164 个中文字符"这条遗留 |
| **F7** | **`RenderButton` 的内禀宽漏了自身内边距** ⇒ `PopupPanel` 按 `child->intrinsicWidth(INF)` 定面板宽时，面板比标签还窄 ⇒ **中文逐字竖排**（实测面板 48 宽、按钮 28 宽、"北京"排两行） | **修（框架缺陷）** | 内边距在本类 `performLayout()` 里 `deflate`/`inflate`，**不进 `mods`** ⇒ 基类 `intrinsicWidth()` 补的 `mods` padding 恒为 0。`RenderMenuItem` 早就补过同一句（注释记着"漏了 insets ⇒ 面板偏窄 ⇒ 行内内容溢出报 C5"），`RenderButton` 是同一个坑，只是**从没有外部消费者**：`PopupPanel` 的既有消费者 `Select`/`Menu` 用的都是 `Option`/`MenuItem`，**裸 `Button` 从没进过面板** |

★ F7 是这份评审里**唯一一条"必须从外部写应用才测得出来"的缺陷** —— 框架内部三条
`intrinsicWidth` 断言（Card / SizedBox / SegmentedControl）都覆盖了自己那条路，
唯独按钮没有；而"按钮进 `PopupPanel`"这个组合此前**一个消费者都没有**。
修法与判据都按既有形状办：`contentIntrinsicWidth/Height` 加 `insets`，
smoke 加一条"内禀宽 === 自然排出来的宽"（摘掉修复 ⇒ 恰好红 1 条，`内禀 16 / 实排 48`）。

### F8 · 最小 `Bootstrap` 里的 `after()` 永远不响 —— **最贵的一条**

| 项 | 内容 |
|---|---|
| **现象** | `apps/tutorial` ④ 段真机：点「开始加载」→ 出现「加载中…」→ **停在那里**。1500ms 后的截图与 400ms 时 **md5 逐字节相同**。而 smoke 全绿（14 条断言全过） |
| **根因** | `pump()` 阻塞在壳的 `GetMessageW`。**没有输入事件时循环一直睡**。`Application::wantsFrame()` 只是"回答要不要跑下一帧"，**没人问就没人答** ⇒ 定时器从不上膛 ⇒ `after()` 排的回调永不触发、`Spinner` 永不转 |
| **为什么闸门抓不到** | smoke 里每一帧都是**显式跑**的（`$app->frame(1200.0, ...)`），永远不需要"有人把循环叫醒"；`wantsFrame()` 那六条判据本身也是对的 —— 缺的是**驱动方**，不是判据 |
| **谁是对的** | `apps/users` 与 `apps/showcase` 的 `Bootstrap` **都**建了 `FrameClock` 并 `pace()`；`apps/hello` 没有（它不用异步/动画，所以从不显形）。而 `hello` 恰恰是**所有新应用的抄写来源** ⇒ 抄它就得到一个"异步永远不动"的应用 |
| **修法** | ① `apps/tutorial` 的循环改用 `FrameClock::tick()`；② **`apps/hello` 同步改**（模板必须是对的，哪怕它自己不用）；③ `docs/tutorial.md` 第 3 节把"把 `tick` 换成 `frame`"列进"删了就静默坏"表 |
| **判决** | **不抽进框架**。`FrameClock` 已在 `src/App/`（不是应用代码），需要应用的只是"每轮问一次 `wantsFrame()`"这一行 —— 抽掉它就得让 `Application` 自己持有平台定时器，直接违反 §5.1「平台引用止于组合根」 |

★ 这条把 U3 的价值说满了：**F7 是"从外部才看得见的渲染缺陷"，F8 是"从外部才看得见的
生命周期缺陷"**，两条都通过了 smoke + lint + docref + selftest 四道闸门。
"smoke 全绿 ≠ 真机正确"这句判据，这一轮又兑现了两次。

---

## 7.3 I3 执行复核（2026-10-25）：处方里的 `docs/architecture/17` 不存在，落点改到 `02`

处方写"加一张 3 行的判定表进 `docs/architecture/17`"。动工时先查了一遍：**没有 17 册**
（现只有 `00`–`13` 十三册，且**没有**"错误处理"专册 —— 错误通道是横切关注点，
它在这套按机制分册的结构里本来就没有自然归属）。

**落点选择**：`02-帧管线与异步.md` 新增 §5「三条错误通道（先问"这是谁的错"）」。理由是
**三条通道的触发点全在帧内**，与 02 的主题同构：

| 通道 | 触发点 | 02 里已有的邻居 |
|---|---|---|
| `Errors::FATAL` | 没人接的异常冒到 `Frame` → Fatal 序列 | §2 `frame()` 的五段顺序 |
| `ErrorBoundary` | 渲染期三层（`rebuild` / `settle` / `performLayout`+`paint`） | §2 的 settle / layout / paint |
| `Job` + `after()` | 异步结果回贴 UI 线程 → `setState` → 同帧 settle | §4 异步三问（已讲 `TaskHost` / `Job`） |

**判定表就 3 行**（契约破坏 → `Errors`；用户输入致子树渲染失败 → `ErrorBoundary`；
外部 IO → `Job`+`after()`），另加 3 条纪律：不要用 `throw` 当错误报告 /
`ErrorBoundary` 不是 `try/catch` 替代（`Job` 回调里的异常它兜不到）/ 占位 UI 本身就是痕迹。

**顺带修掉两处漂移**：
- 02 §7 原写"`wantsFrame` 的**四个**判据" —— 实际注释已标到**第七个**（`animating` /
  `hasAsync` / `hasTimers` / `focusRingTarget()` 变化 / `panelsLagBehind()`）。旧说法
  会让读者以为"补了四个就齐了"，而第六、七条恰好都是**真机上才能发现**的（面板落后一帧、
  焦点环要等下次输入）。
- `00-README` 索引里 02 那行的问题列补上"出错走哪条通道？"，否则表里查不到。

**不加闸门**：这是"给写应用的人看的判断"，`grep` 判不出对错。能判的只有"入口只有三个"
（`Errors::report` / `ErrorBoundary::make` / `Job::run`），已写进 02 §8 验证口径。

---

## 8. 取数口径（怎么复核这份报告）

```bash
# 现状基线
find src -name '*.php' | xargs wc -l | tail -1              # 31332
wc -l src/App/Application.php                               # 3846
ls src/Widget | wc -l                                       # 88
grep -c "instanceof" src/App/Application.php                # 39
grep -rln "use Drift\\\\App" src | grep -v src/App           # 空
grep -rc "@api" src --include="*.php" -r | awk -F: '{s+=$2} END {print s}'      # 332
grep -rc "@internal" src --include="*.php" -r | awk -F: '{s+=$2} END {print s}' # 253

# 双后端能力差
grep -n "unsupportedOp" src/Rendering/RasterCanvas.php       # 仅 1 处（drawShadow）

# 死分支
grep -rn "尚未实现" src/Layout                               # 5 处（RenderFlex/RenderDock）

# 输入能力边界（文档级，代码无表达）
grep -rn "WM_TOUCH\|WM_POINTER\|long-press\|pinch" .workbuddy-ai/memory/

# 无障碍 / i18n
grep -rn "pushSemantics" src/Platform                        # 接口在、provider 未接
grep -rln "TextDirection\|locale" src --include="*.php"      # 0 命中

# 文档规模
wc -l docs/arch-v2/*.md docs/design-v7/*.md docs/architecture/*.md | tail -1
```

★ 与上一轮两份评审的关系：
- `ARCH-REVIEW-2026-10-21.md` 的 **A1 / A3 已落地**（浮层栈、双后端 drawPath），
  **A2 未做**（即本轮的 S2）；其 P5 的 `frame()` 巨型方法**恶化**了（285 行）。
- `ARCH-V2-REVIEW-2026-10-21.md` 的 §5.1「文档即规格但没防漂移」**仍是最大长期风险**，
  即本轮的 M2；其 §5.2「1002 条 smoke 全无头」部分缓解（真机 md5 + 探针已成纪律），
  但**UIA / 无障碍仍无自动验证**。
