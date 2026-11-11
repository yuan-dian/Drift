# Drift 设计

> **只写「现在是什么」**：当前架构、稳定契约、有意不做的事。
> 不写路线图、开放项、棒次战史与验证读数（这些每改一次代码就腐烂）。
> 历史裁决与探针证据见 [`docs/_archive/DESIGN-v2-2026-10-14.md`](docs/_archive/DESIGN-v2-2026-10-14.md)。
> 定位一律用类名 / 命令，不写 `file:line`。

---

## §0 一页概览

Drift 用 PHP 写声明式 UI，经 TypePHP AOT 编译成**独立 Windows `.exe`**，目标机无需 PHP 运行时。架构与 Flutter 同构（Widget / Element / RenderObject 三棵树 + 单向下行 + 组合根注入），界面全自绘，不用原生控件。

**三条公理**（仲裁设计分歧，至今有效）：

| 公理 | 含义 |
|---|---|
| **G1 编译器优先** | 编译器能判的 ⇒ 构建期判，不留到运行期 |
| **G2 绝不静默** | 机制要么生效、要么响亮失败；静默早返回 = 黑洞 |
| **G3 确定性优先** | 布局/渲染是纯函数：可重放、可快照、可逐字节比对 |

**当前定位**：`v0.1.0-preview.1`（**尚未打 tag**，即未对外发布），仅 Windows 10/11 x64 的技术预览，API 不保证兼容。能力面够写真实业务应用（`apps/watermark`）；卡住 1.0 的是**第二平台与生态**，不是控件数量。档位判定见 [`docs/RELEASE-READINESS-2026-11-10.md`](docs/RELEASE-READINESS-2026-11-10.md)。

**借鉴边界**：走 Flutter 路线（三树 + 显式 Key + 显式 `setState`），表达层学 SwiftUI 的 modifier 链；不借 Vue/WPF 的自动依赖追踪与双向绑定，不借 CSS 层叠与流式布局，不做原生控件包装。

---

## §1 分层与依赖方向

- **目录即分层**：`src/<Namespace>/` 框架；`apps/<app>/` 应用（含 `project.yml`）；`cpp-src/` AOT 壳与桥；`tools/` 全 PHP 工具链；`docs/` 文档。
- `cpp-src/` 按**平台边界**三分：`core/` 平台无关（线程池体）、`win32/` 全部 Win32 实现、`skia/` 渲染后端。接第二平台 = 新增 `cpp-src/<platform>/`，框架层不动。
- **依赖方向 `Core < Widget < App`**：Widget 不依赖 App；`BuildContext` 是 `final readonly` 的只读环境快照（宽高 / 主题 / 字体度量 / 颜色 token），**没有 `of()` 式向上查找**。分层由 `DependencyDirection` lint 规则 fail-closed 守（`Core` 也在层表内）。
- **唯一受控例外**：控件要把焦点交给别的 Element 时走 `Element::requestFocus()` → `Pipeline::focusHost()` 注册的回调槽。这是框架内部通道，不是给控件开访问 App 层的口子。
- **公开面**：`@api` / `@internal` 独立标注行，`php tools/lint.php --api` 出清单（快照 `docs/API-SURFACE.md`）；未标注默认 `@internal`。

---

## §2 运行模型：一帧

三树：`Widget`（不可变描述）→ `Element`（身份 + State 宿主，按 `(type, key)` 调和）→ `RenderObject`（几何 + 绘制）。**写即标脏、帧末统一重建**，不在事件中途重排。

```
平台泵 pumpEvents() → tick（动画 / IO / 任务 / 定时器）
  → settle（脏 Element 重建）→ layout（单遍约束，约束反馈同帧收敛）
  → paint（Canvas op 流）→ present()
```

- `Frame::run()` 用结构版本 / `layoutEpoch` / `paintEpoch` 三组序号跳过无事可做的帧；跳 layout 的前提是布局为纯函数（`LayoutSelfCheck` 守）。
- **UI 单线程亲和**：后台结果以事件回贴；循环归 `Platform`，派发归框架（`Application` + `InputRouter`）。
- **多窗口**：`WindowSet` / `WindowHost` 管窗口集合，每窗口状态住各自的 `WindowSlot`，不是进程级全局。

---

## §3 状态与异步

- **值缝**：`Subject` 通知 + `SubjectBuilder::select()` 切片订阅；`===` 身份门控决定「同值不重建」。稳定值要么实现 `Equatable`，要么复用同一实例 —— 这是应用侧契约，`SelectPurity` lint 只守其中一部分。
- **动作缝**：字符串名命令 + `CommandRouter` + Element 冒泡链。控件是**受控**的：点击只回调新值，值由应用改。
- **异步**：`TaskHost` 三后端 `SyncTaskHost` / `ThreadTaskHost` / `ProcessTaskHost`；任务单元是 `Job` **类**（不是闭包）；`Future` / `AsyncScope::task()+await()` / `Future::all()`；四态 `AsyncValue`（idle / loading / data / error）走 `fold()`；进度走 `await()` 第二参；超时由任务自己声明上限（`TimedJob`）。
- **失败出口**：`Errors` 三级（Fatal / Recoverable / Diagnostic；Fatal 写日志 → 原生错误框 → 固定退出码，退出前卸下注入的闭包）；`Drift\Diagnostics` 记显式 `[DX]` 退化；异步错误统一成带错误码的 `Async\Failure`（gate `errshape` 守其贯通）。

---

## §4 布局 / 渲染 / 文本

- **布局**：`RenderBox::layout()` 单遍 `final`，子类只写 `performLayout(constraints) → size`；无界用 `INF`，**不拿 0 当哨兵**；每容器声明溢出策略，违反 ⇒ 响亮失败。长列表靠 `LazyColumn` + `ScrollView` 的可见窗口切片。
- **有意不做增量布局**：整树重排，无 `RelayoutBoundary` / damage rect；`gate scale` 的 12k 节点尺子守余量，重裁触发条件记在 `DEBT`。
- **绘制**：`Canvas` 是接口 —— `RasterCanvas`（纯 PHP 软光栅）/ `SkiaCanvas`（默认后端）/ `RecordingCanvas`（把 op 录进 `DisplayList`，供回放、快照、golden）。**主树直接画到 Canvas，录制是其中一种实现**，不是每帧必经阶段。op 分 Core / Extended / Optional 三层，能力用 `capabilities()` 位掩码查；Optional 在不支持的后端**显式失败**，绝不静默丢像素。
- **文本**：`TtfFace` 解析真 TTF 做步进度量（不做 GPOS kerning / bidi / 断行禁则），字形走 Skia 真字体，软后端保留位图路径；IME 走 `TextInputClient`。
- **确定性**：字体 / DPI / 主题 / 时间都注入，不读环境时钟；`DisplayList` 可重放 ⇒ golden 逐字节比对（原语 + 整页两套基线）。

---

## §5 输入 / 焦点 / 浮层 / 语义

- **键盘五环**（`InputRouter::dispatch()`，顺序即优先级）：⓪ 编辑命令 `Ctrl+C/X/V` → ① 焦点链（控件 `keyboard()` 声明者）→ ② 加速键 → ③ 焦点控件激活 → ④ 上下文菜单键（`Shift+F10` / `Apps`）。
- **指针**：一次命中三方消费（事件 / 语义 / 光标形状）；四相位 down/move/up/leave；手势竞技场消歧；拖放阈值。**右键是上下文菜单的主入口**，菜单弹在光标处（`OverlayEntry::$atX/$atY` 点锚），中键不当右键；键盘入口是辅助。
- **焦点**：`FocusManager` 的 roving tabindex + `firstFocusable()`；树 `←` 跳父行走 §1 的焦点通道。
- **浮层**：`OverlayManager` 栈管菜单 / 模态 / Tooltip / Snackbar，第二棵根渲染树；关闭与「不穿透」按主键分流。
- **语义**：`SemanticsTree` 推给 Windows UIA provider（树导航 + 结构 / 焦点事件），无障碍客户端跨进程可读。

---

## §6 平台端口与 ABI

- `Platform` 是**能力端口集合**：`screen` / `window` / `surface` / `input` / `clipboard` / `dialog` / `tray` / `imeCaret` / `waitEvents` / `pumpEvents` / `now` / `configDir` 等；`null` = 该能力不支持（类型层面的合法状态，调用方必须处理）。
- Win32 实现已覆盖：Per-Monitor V2 DPI 与分数缩放、深色主题跟随系统、剪贴板、原生文件对话框、WIC 图片解码、崩溃报告、UIA。
- **ABI**：`cpp-src/**/*.stub.php` 是唯一事实源，`tools/abi-check.php` 三向对签名（stub ↔ C++ 符号 ↔ 替身），`drift-build` preflight 与 gate `abi` / `abiself` 守；输入指纹收 `.php/.cc/.h`，只改 C++ 也会触发全量。**不做运行期版本握手** —— 单体 AOT 一起编译，真实错配路径是构建缓存 stale，已由静态契约收口。这是裁决，不是遗漏。

---

## §7 构建与验证

- **双轨**：`php tools/drift-dev.php <app>` = dev 壳 + 动态加载业务 PHP（改 PHP 秒级生效）；`php tools/drift-build.php <app>` = 全 AOT 独立 exe。出哪个 exe 才编哪个 app，dev 壳所有应用共用。
- `StalePolicy` 裁决 SKIP / FULL / INCREMENTAL：**输入变了 / 首次 / 产物不在 ⇒ 自动全量**（门不能信缓存）；`--incremental` 需显式 opt-in，`--dry` 只看决策。
- **验证按运行形态分层**（详见 [`docs/architecture/12-工程与工具.md`](docs/architecture/12-工程与工具.md)）：
  - **T0 无头**：`php tools/gate.php` —— lint / docref / phpunit / smoke / golden / dev 壳红线，确定性、可 CI（`--fast` 跳慢项）。
  - **T1 dev 真机**：`python tools/probe-window.py <app> --dev` —— 真窗口、真 C++ 桥、真 DPI / 像素 / 关窗退出码。
  - **T2 AOT 真机**：`drift-build --full` + `probe-window.py <app>` —— 唯一能抓 AOT 编译期语义。**碰壳层 / 跨进程取数 / 新增类 / 新枚举 ⇒ dev 全绿也必须跑 T2**。
- **判据纪律**：判据与实现不同源；每条新约束配自证变异（gate 里成对注册：`abi`/`abiself`、`cppbridge`/`cppbridgeself`、`stalepolicy`/`staleself`、`errshape`/`errself`…），**改了没咬红先怀疑变异打空**。起窗脚本一律自己关窗。
- **CI**：PR 跑 T0 快子集，nightly 加慢项；T2 job 需自托管 runner，当前关闭。等价量具 `aot-equivalence.php` / `dual-mode-equivalence.php` 不进 gate，改 AOT 相关代码时手动跑。

---

## §8 边界：当前缺失与有意不为

| 类别 | 内容 |
|---|---|
| 平台 | 仅 Windows；第二平台后端未做（`Platform` 接口仍在演进，接之前先稳定它） |
| 交互 | 无触控 / 笔 / 高对比度；无 i18n |
| 架构 | `Application` 仍是上帝类；`BuildContext` 无 `ProvidedValue<T>` 式环境注入（深层控件靠逐层传值）；无增量布局 / damage rect / 运行时检查器 |
| 工程 | 无统一 CLI 与 `create` 脚手架；无第三方组件生态与包管理；无安装器 / 签名 / 自动更新 |
| 明确裁决不做 | 自动依赖追踪、`v-model` 式双向绑定、原生控件包装、CSS 层叠 / 流式布局、`--nano` 后端、运行期 ABI 握手 |

这不是待办清单：每项在 `DEBT` 或各专题设计稿里都有裁决与重裁触发条件，条件不变就不重开。

---

## §9 文档地图

| 想知道 | 看 |
|---|---|
| 现在代码怎么写的（分册 + 符号锚点） | [`docs/architecture/00-README.md`](docs/architecture/00-README.md) |
| 设计意图 / 稳定契约（本文） | `DESIGN.md` |
| 从零写一个应用 | [`docs/tutorial.md`](docs/tutorial.md) + `apps/tutorial/` |
| AOT 编译约束与坑 | [`docs/AOT-CONSTRAINTS.md`](docs/AOT-CONSTRAINTS.md) |
| dev 态双模边界 | [`docs/DEV-MODE-2026-11-05.md`](docs/DEV-MODE-2026-11-05.md) |
| 自定义控件 | [`docs/CUSTOM-WIDGET-GUIDE-2026-11-05.md`](docs/CUSTOM-WIDGET-GUIDE-2026-11-05.md) |
| 必守判据与分档边界 | [`AGENTS.md`](AGENTS.md)、[`docs/CONTRIBUTING.md`](docs/CONTRIBUTING.md) |
| 欠账 / 判死项 / 下一棒 | [`docs/DEBT-2026-11-02.md`](docs/DEBT-2026-11-02.md)、[`docs/PLAN-NEXT-STEPS-2026-11-08.md`](docs/PLAN-NEXT-STEPS-2026-11-08.md)（**会腐烂，开工前先 grep 复核代码**） |
| 发布档位与差距 | [`docs/RELEASE-READINESS-2026-11-10.md`](docs/RELEASE-READINESS-2026-11-10.md)、[`CHANGELOG.md`](CHANGELOG.md) |
| 历史裁决 / 探针证据 | [`docs/_archive/DESIGN-v2-2026-10-14.md`](docs/_archive/DESIGN-v2-2026-10-14.md)、`docs/arch-v2/` |

**改文档的纪律**：新文档不写 `file:line`（行号每次改代码都漂）；发现历史行号越界就摘掉行号、留文件名，不要按当前代码「改对」—— 那等于把历史改写成假的。
