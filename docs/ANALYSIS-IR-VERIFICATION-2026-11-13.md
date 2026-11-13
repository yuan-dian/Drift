# 中间表示（IR）对接 UI 与渲染：对验证与工具设计的价值评估（2026-11-13）

> 用户想法：引入 IR 对接 UI 与渲染，只要一个通用 IR 验证，很多就不需要真机验证；换渲染层也方便。
> 本文对照成熟跨平台 GUI 框架 + 本仓实证，判断这个想法对 V2 验证体系重建有没有帮助。

## 0. 结论先行

**方向对，但不需要"引入"—— 这个 IR、这套裁决、这套无头后端，仓里全都有了。真正缺的是把它从「众多判据通道之一」升格为「唯一验证契约面」。**

三句话：

1. `src/Rendering/DisplayList.php` 就是绘制 IR：17 个 opcode、生产路径也走录制、`replay(Canvas $canvas)` 可投到任意后端；`RasterCanvas`（纯 PHP 软光栅）/ `SkiaCanvas` / `tools/fakes/` 是现成的可替换后端。项目调研稿 `12-横向思路提炼.md` §6 早已裁决「DrawOp 命令流唯一中间表示 + provider 插拔，软光栅兼做无头 golden 测试」。
2. **「一个 IR 验证管住跨形态正确性」也已经有了**：`tools/Bench/PaintFingerprint.php`（逐命令按值比）+ `GeomFingerprint.php` + `AotEquivalence.php`，专门证明 AOT 产物与解释器画出同一份 IR。这是用户想法**已经被实证有效**的最强证据。
3. 但**验证锚点还压在最下游的像素上**：`golden-raster.php` 守 34 份 `.rgba.gz` 像素基线，`smoke.php` 有 94 处 ink/pixel 判据，13 个真机探针靠标题 / 坐标 / UIA 文案定位。⇒ IR 的价值不在"少做真机"，在**把锚点从像素上移到结构**。

## 1. 现状实证：IR 在哪一层

```
Widget/Element/State
   ↓ build
RenderObject 树（layout → 几何）
   ↓ paint
RecordingCanvas → DisplayList  ← ★ 绘制 IR（17 op，6 槽/条，扁平缓冲）
   ↓ replay(Canvas)
RasterCanvas(纯 PHP) / SkiaCanvas(C++ 桥) / fakes
   ↓
Win32 壳 / 上屏 / UIA
```

| 能力 | 落点 | 状态 |
|---|---|---|
| 绘制 IR | `src/Rendering/DisplayList.php` | 已有，生产路径也走录制（§7.2 裁决 2） |
| 可替换后端 | `RasterCanvas` / `SkiaCanvas` / `tools/fakes/` | 已有，`CanvasCapabilities` 三级能力面 |
| IR 内建自检 | `DisplayList::assertBalanced()` | 已有（save/clip/layer 三种栈配平） |
| 跨形态等价指纹 | `PaintFingerprint` / `GeomFingerprint` / `AotEquivalence` | 已有，AOT 等价性就靠它 |
| **IR 作为 golden 基线** | — | **缺**：`golden-raster.php` 比的是 RGBA md5，不是命令流 |
| **IR schema 版本化** | — | **缺**：换后端时无法判「IR 契约有没有悄悄变」 |

`PaintFingerprint` 的纪律尤其值得 V2 沿用：按值比较不按引用、opcode 名从 `DisplayList` 常量派生（不复制字面量表）、**未知槽类型显性报红**（否则新 op 加进来时两侧都序列化成 `?类名`，判据安静地少覆盖一类）。

## 2. 成熟框架对照

| 框架 | 中间表示 | 验证怎么用它 | 是否用 IR 取代像素/真机 |
|---|---|---|---|
| Flutter | `Layer Tree` → `Scene` → Skia `DisplayList` | widget test 断言 widget/semantics 树；golden 比**光栅化后的像素**，放 Skia Gold 独立服务 | **否**，两者并存 |
| Chromium/Blink | `PaintArtifact` → `cc::DisplayItemList` | `--dump-render-tree` 的结构化文本基线（`expected.txt`）+ reftest 像素比对 | **否**，IR dump 管绘制顺序，reftest 管像素 |
| Qt Quick | `QSGNode` scene graph | `qmltestrunner` 的 `grabWindow()` 像素比对 | **否** |
| Compose | `LayoutNode` + `DrawScope` → `Canvas` | `ComposeTestRule` 语义树断言 + Paparazzi **在 JVM 上无头**渲染 PNG | **否**，但真机/模拟器需求压到极低 |
| Slint | 编译生成的 item tree | `slint_interpreter` + test-driver 无头截图 | **否**，仍有各 backend 平台测试 |
| Yoga | 同一份布局描述 | **跨语言一致性测试**：同一描述在 C++/Java/JS 三端必须给出相同结果 | 最接近「一个 IR 验证」，但它验的是**各端翻译器** |
| MoUI（本仓调研 §6） | DrawOp 命令流唯一 IR | 软光栅兼做**无头 golden 测试**，测试与生产走同一绘制代码 | 明确说「兼做」，不是取代 |

**共识**：IR 是分层标准配置，Drift 的 `DisplayList` 与它们同层；**没有一家用 IR 断言取代像素/端到端**，都是分工并存 —— IR 管「框架算对了什么」，像素/真机管「翻译与平台没搞错」。
**反直觉的一点**：真正把真机需求压到最低的不是 IR，是**后端可无头替换**（Paparazzi / Slint / MoUI 软光栅）。而 Drift 已经有 `RasterCanvas` + `SurfaceProbe` + `fakes/win32.php` —— 我们其实已经在 Paparazzi 那条路上了，只是没把判据锚点挪过来。

## 3. 精确判断：IR 能替掉什么，替不掉什么

把 13 个真机探针按「守的是哪一段」拆开：

| 类别 | 探针 | IR 能帮上吗 |
|---|---|---|
| 纯平台边界 | `probe-window` / `probe-two-windows` / `probe-io-pool` / `probe-uia` / `probe-uia-state` / `probe-close` | **完全帮不上**：窗口消息泵、进程/多实例状态、C++ IO 线程池、UIA 无障碍桥都在 IR 下游甚至 IR 之外 |
| 框架语义为主 | `probe-first-input` / `probe-press` / `probe-motion` / `probe-modal` / `probe-scrollbar` / `probe-tree-left` | **能上移大半**：两相位叠色、悬停浮起、按下态、滚动条形状位置、Modal 命中都在 IR / 几何树里看得见；但「真机输入时序」「窗口消息可达」那一半仍要保留 |
| 诊断工具 | `probe-scroll-diag` | 不适用 |

⇒ 用户说的「很多就不需要真机验证」**部分成立，但成立的理由要换**：不是"IR 让真机变多余"，而是"**用真机去验框架语义本来就是锚点放错了层**"。修正后真机只剩两类：① 平台边界；② AOT 产物启动与跨形态等价（后者用 `PaintFingerprint` + 一次启动烟测，不需要逐功能真机点按）。

## 4. 三个必须写进设计的坑

1. **IR 判据与实现同源**。IR 快照若直接从 `DisplayList` 序列化，"录制器写错了"会让期望与实现一起错、判据全绿 —— 这正是本项目最怕的形状（`判据与实现同源`）。必须保留**独立路径**的少量像素锚点做三方验证，并沿用 `PaintFingerprint` 的「未知槽显性红」纪律。像素 golden 虽然笨且慢，它的价值恰恰是**独立**：它真的把 RGBA 算了出来。
2. **IR 表达不了的东西别硬塞**。图片解码结果（WIC 通道序）、字体光栅化、DPI 缩放、窗口合成、输入时序、UIA 树都不在绘制 IR 里。尤其 **UIA 守的是「语义 IR」不是「绘制 IR」**：Flutter 有独立 semantics tree、Chromium 有独立 a11y tree，Drift 的 `probe-uia-state.py` 对应那条线，绘制 IR 替代不了。「一个通用 IR」必须先说清是哪一个。
3. **同一 IR 在不同后端能力不同**。`CanvasCapabilities` 有 Core / Optional / Extended 三级，`RasterCanvas` 与 `SkiaCanvas` 支持的 op 不一样。⇒「一个通用 IR 验证」必须配**每个后端一份 replay 契约测试**（Yoga 模式），否则换渲染层时会静默降级。换后端方便的前提是 **IR schema 版本化 + 能力矩阵诚实标注**，这两样本仓都缺。

## 5. 对 V2（全删重建）设计的具体帮助

这是本评估最有用的部分。V2 不该"新增 IR"，该做五件事：

| # | 动作 | 直接收益 |
|---|---|---|
| V2-1 | **行为判据主面换成 IR 快照**：34 页 `.rgba.gz` 像素基线 → 34 份结构化命令流快照 | 差异能定位到「第 87 条 `PUSH_CLIP` 的矩形 1200×40 / 1200×44」，而不是「整页不同」；基线体积、耗时、可归因性同时改善；锚点不再依赖标题/坐标/UIA 文案 |
| V2-2 | **像素 golden 缩成边界锚点**：只留少量专门证明 `DisplayList::replay()` → `RasterCanvas`/`SkiaCanvas` 翻译正确、以及 WIC 通道序这类 IR 表达不了的 | 保住独立路径，防「IR 同源假绿」 |
| V2-3 | **真机 T2 缩成两条**：一次 AOT 启动烟测 + 纯平台边界探针（6 个）；框架语义类逐功能点按全部上移到 IR / 几何树断言 | 6 个探针的工作量塌缩；AOT 等价性继续复用 `PaintFingerprint`/`GeomFingerprint` |
| V2-4 | **IR 契约面显式化**：给 `DisplayList` 加 schema 版本 + 每后端一份 replay 契约测试；`CanvasCapabilities` 当能力矩阵 | 换渲染层真正可验收，而不是「换完跑一遍看看」 |
| V2-5 | **缺陷题库 S1 新加一类「IR 层缺陷」**：op 顺序错、裁剪没弹、两色传反、纯平移/非平移变换分叉；每条必须配「IR 快照能报红」的反证 | 题库覆盖从"集合/主题"扩到绘制语义，V2 覆盖率对比才有意义 |

**顺序建议**：V2-1/2/3 进 S2（建 V2 时一起做），V2-4 单列一棒（动 `src/Rendering`，属 T1 + AOT），V2-5 立刻并进 S1 扩题库（纯数据，零风险）。

## 6. 裁决

- ✅ **采纳**：把 `DisplayList` 升格为**唯一绘制验证契约面**，并把验证锚点从像素上移到结构。这对 V2 的工具数量、耗时、锚点稳定性帮助很大，且不需要发明新东西。
- ❌ **不采纳**：新造一层 IR（会与 `DisplayList` 形成两份事实源）、用 IR 断言全面取代像素/真机（同源假绿 + 平台边界无人守）、把 UIA 语义也塞进绘制 IR。
- 用户直觉里最有价值的一句是「**后续想换渲染层也更方便**」：这一点现有 `replay(Canvas)` 已经兑现了架构能力，缺的只是 **IR schema 版本化 + 每后端 replay 契约测试**这两道验收。
